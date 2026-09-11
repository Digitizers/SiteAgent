<?php
/**
 * Snapshot engine for SiteAgent.
 *
 * Generalizes the plugin-zip rollback (class-aura-worker-rollback.php) into
 * capture-before-write snapshots for the surfaces the Governed Power Tools
 * touch: individual files, WordPress options, and post-meta keys (the shape
 * Elementor page/kit data lives in — `_elementor_data`, `_elementor_page_settings`,
 * kit-scoped globals). Each snapshot is a small JSON record (plus a payload copy
 * for non-trivial kinds) under wp-content/aura-backups/snapshots/, so the Aura
 * gateway can preview and reverse a power action the same way it already reverses
 * page/resource snapshots.
 *
 * Table snapshots are intentionally deferred (they need $wpdb + row-cap policy);
 * the record shape reserves the 'db_table' kind for that later work.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Snapshots {

	/**
	 * Door envelope kinds the retention sweep may prune (Ruling R6). The
	 * pre-existing kinds (`file|option|post|meta|posts` without a door label)
	 * are never pruned — they are taken by the power tools, whose retention is
	 * the operator's to decide.
	 */
	const DOOR_KINDS = array( 'page', 'component', 'design_system', 'creation', 'creation_restore' );

	/**
	 * Directory where snapshots are stored.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Constructor — ensures the snapshot directory exists and is protected.
	 */
	public function __construct() {
		$this->dir = WP_CONTENT_DIR . '/aura-backups/snapshots/';
		if ( ! file_exists( $this->dir ) ) {
			wp_mkdir_p( $this->dir );

			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
			$wp_filesystem->put_contents( $this->dir . '.htaccess', 'Deny from all', FS_CHMOD_FILE );
			$wp_filesystem->put_contents( $this->dir . 'index.php', '<?php // Silence is golden.', FS_CHMOD_FILE );
		}
	}

	/**
	 * Generate a sortable, unique snapshot id.
	 *
	 * @return string
	 */
	private function new_id() {
		// Timestamp prefix keeps ids newest-first sortable; the suffix is a CSPRNG
		// value (not a predictable uniqid) so payload filenames can't be guessed
		// on a host where the .htaccess deny is ignored (nginx).
		try {
			$rand = bin2hex( random_bytes( 12 ) );
		} catch ( \Exception $e ) {
			$rand = substr( md5( uniqid( '', true ) ), 0, 24 );
		}
		return 'snap_' . gmdate( 'Ymd_His' ) . '_' . $rand;
	}

	/**
	 * Write a snapshot record (and optional payload) to disk.
	 *
	 * @param array       $meta    Record metadata (kind, target, created, ...).
	 * @param string|null $payload Optional raw payload to store alongside.
	 * @return array|false The stored record, or false if any write failed (so the
	 *                     caller can fail closed — a power tool must not proceed
	 *                     believing it has a rollback point when it doesn't).
	 */
	private function persist( $meta, $payload = null ) {
		$id                  = $this->new_id();
		$meta['id']          = $id;
		$meta['created_gmt'] = gmdate( 'Y-m-d H:i:s' );
		// WHICH BLOG TOOK IT (Ruling P15). Every blog on a multisite shares
		// this one directory, and an envelope's ids — post ids, option names —
		// mean nothing outside the blog they were read on. The stamp is what
		// lets a read be withheld and a restore be refused; without it, one
		// subsite's credentials read and overwrite another subsite's content.
		$meta['blog_id']     = self::current_blog_id();

		if ( null !== $payload ) {
			$payload_path = $this->dir . $id . '.payload';
			$n            = @file_put_contents( $payload_path, $payload ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unwritable/full-disk directory is an expected, handled failure (return false below), not a warning to surface; the byte count is checked, not the return alone.
			if ( false === $n || $n !== strlen( $payload ) ) {
				if ( file_exists( $payload_path ) ) {
					wp_delete_file( $payload_path ); // a partial payload restores garbage; leave nothing
				}
				return false;
			}
			$meta['payload_path'] = $payload_path;
		}

		$json = wp_json_encode( $meta );
		if ( false === $json ) {
			return false;
		}
		$meta_path = $this->dir . $id . '.json';
		$n         = @file_put_contents( $meta_path, $json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same as the payload write above: an expected, handled failure.
		if ( false === $n || $n !== strlen( $json ) ) {
			if ( file_exists( $meta_path ) ) {
				wp_delete_file( $meta_path );
			}
			if ( isset( $meta['payload_path'] ) && file_exists( $meta['payload_path'] ) ) {
				wp_delete_file( $meta['payload_path'] );
			}
			return false;
		}
		$meta['meta_path'] = $meta_path;

		return $meta;
	}

	/**
	 * This blog's id — 1 on a single site, where core's own default is 1 and
	 * the function may not exist at all on very old cores.
	 *
	 * @return int
	 */
	private static function current_blog_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	}

	/**
	 * Is this envelope this blog's to act on?
	 *
	 * FALSE only when the stamp is PRESENT and names another blog. A legacy
	 * envelope carries no stamp and cannot be placed — and refusing to
	 * restore every capture taken before the stamp existed would break the
	 * undo of every write on the site. The READ path is stricter (see
	 * Aura_Tool_Snapshot_Get): on a multisite an unplaceable envelope has its
	 * payload withheld, because handing over content that may be another
	 * blog's is a leak, while restoring your own old capture is not.
	 *
	 * @param array $record The envelope.
	 * @return bool
	 */
	public static function belongs_to_current_blog( array $record ) {
		return ! isset( $record['blog_id'] ) || (int) $record['blog_id'] === self::current_blog_id();
	}

	/** The one refusal both the API and restore() answer a foreign envelope with. */
	const FOREIGN_BLOG_ERROR = 'Snapshot belongs to another site.';

	/**
	 * Capture a file's current contents before it is modified.
	 *
	 * @param string $path Absolute path to the file.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_file( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) || ! is_file( $path ) ) {
			return array( 'success' => false, 'error' => 'File not found: ' . (string) $path );
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return array( 'success' => false, 'error' => 'Unable to read file: ' . $path );
		}

		$record = $this->persist(
			array(
				'kind'   => 'file',
				'target' => $path,
				'bytes'  => strlen( $contents ),
			),
			$contents
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Replace an EXISTING file's content with the engine owning the whole
	 * overwrite (SiteAgent#99): its old bytes are captured by snapshot_file(),
	 * the new content is staged beside it with the mode it has, and the stage
	 * is renamed over it — atomic, so a reader sees the old file or the new,
	 * never a truncated or growing one; a short stage write touches nothing.
	 * Runs under the same per-target lock create_file() takes, so it can never
	 * write into an inode a concurrent create is still filling.
	 *
	 * A symlink is refused: rename() would replace the LINK, and the snapshot
	 * would have captured its destination. Path validation is the caller's.
	 *
	 * @param string $path    Absolute path of an existing regular file.
	 * @param string $content Complete new content.
	 * @return array { success: bool, snapshot?: array, bytes?: int, error?: string }
	 */
	public function overwrite_file( $path, $content ) {
		if ( ! is_string( $path ) || '' === $path || ! is_string( $content ) ) {
			return array( 'success' => false, 'error' => 'overwrite_file: path and content must be strings.' );
		}
		if ( ! self::path_present( $path ) ) {
			return array( 'success' => false, 'error' => 'File not found: ' . $path );
		}
		if ( is_link( $path ) ) {
			return array( 'success' => false, 'error' => 'Target is a symlink: ' . $path );
		}
		if ( ! is_file( $path ) ) {
			return array( 'success' => false, 'error' => 'Target is not a regular file: ' . $path );
		}
		$out = $this->with_target_lock(
			$path,
			function () use ( $path, $content ) {
				$snap = $this->snapshot_file( $path );
				if ( empty( $snap['success'] ) ) {
					return $snap;
				}
				$perms = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file is there (checked above); a refusal is answered below.
				if ( false === $perms ) {
					return array( 'success' => false, 'error' => 'Unable to read the mode of: ' . $path );
				}
				$tmp = $this->stage( dirname( $path ), basename( $path ), $content );
				if ( is_array( $tmp ) ) {
					return $tmp; // nothing at the target changed; the snapshot is an extra restore point
				}
				// stage() set the create mode; the replacement keeps the file's OWN.
				if ( ! @chmod( $tmp, $perms & 0777 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Our own staged file; a refusal is answered, not surfaced.
					$this->discard_stage( $tmp );
					return array( 'success' => false, 'error' => 'Unable to set permissions on the staged file: ' . $tmp );
				}
				if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Clobbering IS the point here, atomically; $wp_filesystem->move() may copy+delete.
					$this->discard_stage( $tmp );
					return array( 'success' => false, 'error' => 'Unable to replace the target: ' . $path );
				}
				return array(
					'success'  => true,
					'snapshot' => self::redact( $snap['snapshot'] ),
					'bytes'    => strlen( $content ),
				);
			}
		);
		return $this->locked_answer( $out, $path );
	}

	/** A staged file this old with no create in flight is a crash's leftover. */
	const STAGE_MAX_AGE = 3600; // one hour — a literal, so the class needs no WordPress constant at load

	/** At most this many stray staged entries are examined per create. */
	const STAGE_SWEEP_CAP = 50;

	/** A mkdir() lock directory older than this was left by a crashed holder. */
	const LOCK_STALE_AFTER = 300; // five minutes — a literal, so the class needs no WordPress constant at load

	/** with_lock()'s answer when no lock can be CREATED (the snapshots directory refuses), as opposed to one that is held. */
	const LOCK_UNAVAILABLE = "\0aura-lock-unavailable";

	/**
	 * Create a NEW file with the engine owning the whole create, so no caller
	 * can leave the record and the file disagreeing (P4.6 piece 2, spec §5).
	 *
	 * Stage → record → publish. The complete content is written to a temporary
	 * file of this call's own name beside the target (same filesystem, never a
	 * `.php` name — a stray one is data, never served); the record
	 * `{ kind: file, existed: false, target, expected_sha256, staged }` is
	 * persisted; the target is published with link(), which is atomic and
	 * refuses to clobber. There is no point at which the target exists without
	 * its record, or holds bytes other than the complete content. Both other
	 * orders were rejected in review: any order in which the TARGET exists
	 * before the record is complete has a window.
	 *
	 * Path validation is the CALLER's: this method trusts `$path` and does no
	 * jail check of its own — the Power Pack's `resolve_target()` owns that.
	 *
	 * @param string $path    Absolute path to create.
	 * @param string $content Complete file content.
	 * @return array { success: bool, snapshot?: array, error?: string, detail?: string }
	 */
	public function create_file( $path, $content ) {
		if ( ! is_string( $path ) || '' === $path || ! is_string( $content ) ) {
			return array( 'success' => false, 'error' => 'create_file: path and content must be strings.' );
		}
		$dir  = dirname( $path );
		$name = basename( $path );
		if ( ! is_dir( $dir ) ) {
			return array( 'success' => false, 'error' => 'Directory not found: ' . $dir );
		}
		$this->sweep_stale_stages( $dir );

		// Everything from here runs holding THIS PATH's lock (SiteAgent#99):
		// a second engine writer — another create, or an overwrite_file() that
		// found the path taken — waits, then sees a complete file or nothing.
		// Without it, in write mode, an in-place overwrite shared our inode and
		// passed the inode check. Order: the target lock is taken before the
		// record lock (the re-check below); the sweeper takes record locks only.
		$out = $this->with_target_lock(
			$path,
			function () use ( $path, $dir, $name, $content ) {
				return $this->create_file_locked( $path, $dir, $name, $content );
			}
		);
		return $this->locked_answer( $out, $path );
	}

	/**
	 * The create proper — stage, record, publish, verify — under the target lock.
	 *
	 * @param string $path    Target path.
	 * @param string $dir     Its directory.
	 * @param string $name    Its basename.
	 * @param string $content Complete content.
	 * @return array As create_file().
	 */
	private function create_file_locked( $path, $dir, $name, $content ) {
		// Cheap early answer; the AUTHORITATIVE no-clobber check is the publish's.
		if ( file_exists( $path ) ) {
			return array( 'success' => false, 'error' => 'exists' );
		}

		// 1. Stage. Nothing is visible at the target yet.
		$tmp = $this->stage( $dir, $name, $content );
		if ( is_array( $tmp ) ) {
			return $tmp;
		}

		// 2. Record, with no payload: the record undoes CONTENT AT A PATH, by
		// hash (the §2 ruling), so the bytes are never stored twice.
		$sha    = hash( 'sha256', $content );
		$record = $this->persist_create_record(
			array(
				'kind'            => 'file',
				'target'          => $path,
				'existed'         => false,
				'expected_sha256' => $sha,
				'staged'          => $tmp,
				'bytes'           => strlen( $content ),
			)
		);
		if ( false === $record ) {
			$this->discard_stage( $tmp );
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		// The record's bytes reach the disk BEFORE the target is published
		// (Codex #94 round-4 P2): persist() writes with file_put_contents(),
		// which never syncs, so a power loss after link() could leave the
		// target on disk and the record not. What PHP cannot do is sync a
		// DIRECTORY entry (a directory cannot be opened as a stream), so the
		// record's and the target's directory entries are outside this
		// guarantee — a crash in that window is the same class the staged-name
		// sweep and prune_older_than() already recover from.
		if ( ! $this->sync_create_record( $record['meta_path'] ) ) {
			return $this->abandon_create( $tmp, $record['id'], 'Unable to sync the snapshot record to disk; nothing created.' );
		}
		// The record is RE-READ before anything is published (Codex round-1
		// P1): a short metadata write is the one failure persist() reported as
		// success, and a target published over an undecodable record has no
		// rollback point — the exact invariant this method exists to keep.
		$back = $this->get( $record['id'] );
		if ( ! is_array( $back )
			|| 'file' !== ( $back['kind'] ?? '' )
			|| ( $back['target'] ?? null ) !== $path
			|| ( $back['existed'] ?? null ) !== false
			|| ( $back['expected_sha256'] ?? null ) !== $sha
			|| ( $back['staged'] ?? null ) !== $tmp
		) {
			return $this->abandon_create( $tmp, $record['id'], 'Snapshot record did not read back complete (disk full?); nothing created.' );
		}

		// 3. Publish: atomic, no-clobber.
		$published = $this->publish( $tmp, $path );
		if ( 'partial' === $published ) {
			// Partial bytes sit at the target and could not be emptied (Codex
			// #97 round-3 P2). The record is voided in place and marked
			// interrupted — restore can never delete that file — and the stage
			// is KEPT: it is the reconciler's signal and the operator's copy of
			// what should have landed. Nothing is discarded that a repair needs.
			return $this->interrupted_create( $record['id'], $tmp, 'unsupported_filesystem', $this->last_publish_detail );
		}
		if ( true === $published ) {
			// The target holds exactly the recorded bytes, or the create is not
			// a success (SiteAgent#99): the inode check proves the ENTRY is ours,
			// not that nothing else wrote into it. Same outcome as a partial —
			// the file is a fact, the record must never let a restore delete it.
			$landed = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
			if ( ! is_string( $landed ) || ! hash_equals( $sha, $landed ) ) {
				return $this->interrupted_create( $record['id'], $tmp, 'interrupted', 'the target does not hold the staged bytes after the publish (a concurrent writer on the same file?)' );
			}
		}
		if ( true !== $published ) {
			$out = $this->abandon_create( $tmp, $record['id'], (string) $published );
			if ( isset( $this->last_publish_detail ) && '' !== $this->last_publish_detail ) {
				$out['detail'] = $this->last_publish_detail;
			}
			return $out;
		}

		// 4. The staged name is now a second link to the same bytes; drop it.
		// Failure here is a warning, not an error: the sweep in the next create
		// and prune_older_than() remove what is left.
		$this->discard_stage( $tmp );

		// A publish that ran past STAGE_MAX_AGE (a large file on slow storage)
		// looks interrupted to a concurrent sweep, which voids the record while
		// we are still writing (Codex #97 round-5 P2). The bytes landed and the
		// inode was verified, so the truth is ours to restore: put the hash
		// back and lift the void. If even that fails the create still happened
		// — say so instead of pretending it did not.
		$out      = array( 'success' => true, 'published' => $this->last_publish_mode );
		$id       = $record['id'];
		$repaired = $this->with_record_lock(
			$id,
			function () use ( $id, $sha, $record ) {
				$current = $this->get( $id );
				if ( ! is_array( $current ) ) {
					// Retired by a sweep that saw no target while we were paused
					// before the claim (Codex #97 round-8 P2): the publish did
					// land, so the record is written back as it was.
					return $this->reinstate_record( $id, $sha, $record );
				}
				if ( ! empty( $current['voided'] ) ) {
					return $this->reinstate_record( $id, $sha );
				}
				return true;
			}
		);
		if ( true !== $repaired ) {
			$out['warning'] = null === $repaired
				? 'the record could not be locked after a long publish; a concurrent sweep may have voided it — restore may refuse it'
				: 'the record was voided or retired by a concurrent sweep during a long publish and could not be repaired; this create has no rollback record';
		}

		// The PERSISTED record keeps `staged` — prune_older_than()'s sweep reads
		// it from list_snapshots() — but the RETURNED one carries no local path
		// at all (Codex #94 round-6 P3): step 4 deleted the staged file, and
		// `meta_path` is this site's directory, not a fact about the snapshot.
		$out['snapshot'] = self::redact( $record );
		return $out;
	}

	/** The last publish() failure's PHP message, for the caller's `detail`. */
	private $last_publish_detail = '';

	/** How the last publish() landed: 'link' or 'rename'; '' when it did not. */
	private $last_publish_mode = '';

	/**
	 * Write the complete content to a temporary file this call owns, beside
	 * the target (same directory, so link() is on one filesystem), under an
	 * opaque name. `xb` is an exclusive create: the name is ours or the call
	 * fails. A short write leaves nothing behind. `$name` is accepted for the
	 * seam's signature and deliberately not used.
	 *
	 * Protected so a test can model a short write or a refused create.
	 *
	 * @param string $dir     Target's directory.
	 * @param string $name    Target's basename.
	 * @param string $content Complete content.
	 * @return string|array The staged path, or { success: false, error }.
	 */
	protected function stage( $dir, $name, $content ) {
		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		// OPAQUE: nothing of the target's name is in it. `.agent.php.aura-create-x`
		// still carries `.php`, and Apache's multi-extension AddHandler semantics
		// execute such a file (Codex round-1 P1); `.aura-create-<hex>` has no
		// extension any handler is registered for.
		$tmp = $dir . '/.aura-create-' . $suffix;

		$fh = @fopen( $tmp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An exclusive create is the point: the name is ours or the call fails, and the failure is reported, not thrown.
		if ( false === $fh ) {
			return array( 'success' => false, 'error' => 'Unable to stage file beside the target: ' . $dir );
		}
		$len     = strlen( $content );
		$written = 0;
		while ( $written < $len ) {
			$n = fwrite( $fh, substr( $content, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- $wp_filesystem has no exclusive-create, fsync'd write.
			if ( false === $n || 0 === $n ) {
				break;
			}
			$written += $n;
		}
		$flushed = fflush( $fh );
		// PHP 8.1+: the bytes reach the disk before the record names them. A
		// refused fsync() is a refusal to publish (Codex #94 round-3 P2): a crash
		// after a record that names bytes the disk never took would leave a
		// missing or partial target under a "success".
		$synced = function_exists( 'fsync' ) ? (bool) fsync( $fh ) : true;
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $written !== $len || ! $flushed ) {
			return $this->discard_short_stage( $tmp );
		}
		if ( ! $synced ) {
			$this->discard_stage( $tmp );
			return array( 'success' => false, 'error' => 'Unable to sync the staged file to disk: ' . $tmp );
		}

		// fopen() takes the process umask, which on some hosts is group- or
		// world-writable. link() preserves the mode, so whatever is set here is
		// what the published target ends up with — and a mode that could not be
		// set is a refusal, never a publish with whatever fopen() left (Codex
		// #94 round-1 P1): nothing is staged, nothing is recorded, nothing at
		// the target.
		if ( ! $this->secure_stage( $tmp ) ) {
			$this->discard_stage( $tmp );
			return array( 'success' => false, 'error' => 'Unable to set permissions on the staged file: ' . $tmp );
		}
		return $tmp;
	}

	/**
	 * A stage that did not land completely: remove it and answer the error.
	 *
	 * @param string $tmp Staged path.
	 * @return array { success: false, error }
	 */
	protected function discard_short_stage( $tmp ) {
		$this->discard_stage( $tmp );
		return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $tmp );
	}

	/**
	 * Set the staged file's mode to what the published target must carry.
	 * False when chmod() is unavailable on this host or refuses — the caller
	 * then discards the stage rather than publishing an unsecured file.
	 *
	 * Protected so a test can model a host where the mode cannot be set.
	 *
	 * @param string $tmp Staged path.
	 * @return bool
	 */
	protected function secure_stage( $tmp ) {
		if ( ! function_exists( 'chmod' ) ) {
			return false;
		}
		return (bool) @chmod( $tmp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- A refusal is an answer this method returns, not a warning to surface; $wp_filesystem is not initialised on this path, and the mode of a file this call exclusively created is not a filesystem abstraction concern.
	}

	/**
	 * Publish the staged bytes at the target: no-clobber, and never a partial
	 * file where it can be avoided. With link() that is one atomic call.
	 * Where link() is disabled (most managed hosts put it in disable_functions
	 * for web PHP — Cloudways does, SiteAgent#96) the target is CLAIMED with
	 * fopen( 'xb' ) — it refuses an existing path and hands back an inode this
	 * call owns — and the bytes are written INTO that handle. Ownership is by
	 * inode, not by pathname: a racer can unlink our entry, never be
	 * overwritten by us, and the write is verified against the entry's inode
	 * before it is called published. What the link()-less publish gives up is
	 * the empty→complete jump: a reader in the milliseconds of the write can
	 * see a growing file. rename() is NOT a substitute for either: it
	 * clobbers whatever holds the path when it runs (Codex #97 round-1 P1).
	 *
	 * Protected so a test can model a race (the target appears first) or a
	 * filesystem that refuses hard links.
	 *
	 * @param string $tmp  Staged path.
	 * @param string $path Target path.
	 * @return true|string true, 'exists' (the target was there first),
	 *                     'unsupported_filesystem' (the publish could not land; see `detail`),
	 *                     or 'partial' (link()-less only: a short write whose partial
	 *                     bytes could not be emptied — the caller keeps recovery state).
	 */
	protected function publish( $tmp, $path ) {
		$this->last_publish_detail = '';
		$this->last_publish_mode   = '';
		if ( ! $this->link_available() ) {
			return $this->publish_by_write( $tmp, $path );
		}
		error_clear_last();
		$ok = @link( $tmp, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EEXIST is an expected answer, not a warning to surface; it is classified below.
		if ( $ok ) {
			$this->last_publish_mode = 'link';
			return true;
		}
		$err                       = error_get_last();
		$this->last_publish_detail = is_array( $err ) && isset( $err['message'] ) ? (string) $err['message'] : '';
		// The target is there and it is not our bytes → someone landed first.
		// (If it IS our bytes, a previous attempt's link landed and its stray
		// staged file is what we are; still 'exists' — this call did not create it.)
		if ( file_exists( $path ) ) {
			return 'exists';
		}
		// Any other refusal: the filesystem cannot give us an atomic, no-clobber
		// publish. Fail closed — nothing was written at the target.
		return 'unsupported_filesystem';
	}

	/**
	 * The link()-less publish: exclusive-create the target and write the
	 * staged bytes into the handle we own. See publish().
	 *
	 * @param string $tmp  Staged path.
	 * @param string $path Target path.
	 * @return true|string As publish().
	 */
	private function publish_by_write( $tmp, $path ) {
		$mode = $this->create_mode();
		if ( 0 !== ( $mode & 0111 ) ) {
			// fopen() creates from 0666 and a umask only removes bits: a site whose
			// FS_CHMOD_FILE carries execute bits (0755 hosts exist) would get a
			// silently lesser file. Refuse, as the put-back does (Codex #97 round-5).
			$this->last_publish_detail = sprintf( 'link() is disabled on this host and FS_CHMOD_FILE (%o) has execute bits that fopen() cannot recreate', $mode );
			return 'unsupported_filesystem';
		}
		$src = @fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Our own staged file; a refusal is answered below.
		if ( false === $src ) {
			$this->last_publish_detail = 'the staged bytes could not be read back';
			return 'unsupported_filesystem';
		}
		$landed = $this->write_exclusively( $path, $src );
		fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( true === $landed ) {
			$this->last_publish_mode = 'write';
			return true;
		}
		if ( 'exists' === $landed || 'partial' === $landed ) {
			return $landed;
		}
		$this->last_publish_detail = 'link() is disabled on this host and ' . $landed;
		return 'unsupported_filesystem';
	}

	/**
	 * Create $path exclusively and stream $src into it — the one primitive
	 * behind the link()-less publish and put-back. fopen( 'xb' ) refuses an
	 * existing path (no clobber, ever) and returns an inode this call owns;
	 * after the write the directory entry is re-read and must still be that
	 * inode, or the bytes went to an entry a racer already unlinked and the
	 * path is not ours to report on. Nothing here ever addresses the path by
	 * name once it is claimed — not even for the mode, which is set at
	 * creation through the process umask (PHP exposes no fchmod(); a chmod()
	 * by pathname could dress a racer's replacement file — Codex #97 round-2).
	 *
	 * @param string   $path Target path.
	 * @param resource $src  Readable handle with the bytes.
	 * @param int|null $mode Mode for the new entry; null = FS_CHMOD_FILE (0644).
	 *                       fopen() creates from a base of 0666 and a umask can only
	 *                       REMOVE bits, so execute bits cannot be produced here: a
	 *                       fresh create lands with `mode & 0666`, and a caller that
	 *                       must keep execute bits refuses before calling (put-back).
	 * @return true|string true; 'exists' (the path was taken — before the claim,
	 *                     or by a racer who unlinked our entry and took it during
	 *                     the write); 'partial' (the write was short AND the entry
	 *                     could not be emptied — partial bytes remain at the path
	 *                     and the caller must keep its recovery state); or a
	 *                     sentence saying what refused. On a short write that
	 *                     could be emptied our own entry is left EMPTY at the path
	 *                     (truncated, never unlinked by pathname) and the sentence
	 *                     names it. `last_publish_detail` carries the sentence for
	 *                     'partial' too.
	 */
	private function write_exclusively( $path, $src, $mode = null ) {
		$mode = ( null === $mode ? $this->create_mode() : (int) $mode ) & 0666; // callers refuse execute bits before reaching here
		$was  = umask( 0777 & ~$mode ); // the mode is decided AT creation, on our inode only
		$fh   = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'x' is the no-clobber claim; EEXIST is the expected refusal, classified below.
		umask( $was );
		if ( false === $fh ) {
			return self::path_present( $path ) ? 'exists' : 'the target could not be claimed';
		}
		$mine = fstat( $fh );
		// A default POSIX ACL on the directory makes the kernel ignore the umask:
		// the entry can come out MORE permissive than asked (0666 for a 0600
		// file). The handle's real mode is checked before a byte is written;
		// a mismatch is a refusal, and the empty entry we own stays behind
		// (Codex #97 round-8 P1).
		$real = is_array( $mine ) ? ( (int) $this->mode_of( $fh, $mine ) & 0777 ) : -1;
		if ( $real !== $mode ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return sprintf( 'the created entry has mode %o, not the %o asked for (a default ACL?); an empty file remains at %s', $real, $mode, $path );
		}
		$written = $this->write_all( $fh, $src );
		$synced  = $written && fflush( $fh ) && ( function_exists( 'fsync' ) ? (bool) fsync( $fh ) : true );
		$this->during_write( $path );
		$now        = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The entry may be gone; that is an answer.
		$still_ours = is_array( $mine ) && is_array( $now ) && $now['ino'] === $mine['ino'] && $now['dev'] === $mine['dev'];
		if ( ! $written || ! $synced ) {
			// Our inode, our bytes, incomplete: empty it rather than leave a
			// truncated file that reads as content. The empty entry stays — it
			// is removed only by inode-verified paths, never by name. When even
			// the emptying is refused, the partial bytes are a fact the caller
			// must keep recovery state for (Codex #97 round-3 P2).
			$emptied = $this->truncate_to_empty( $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( ! $emptied ) {
				$this->last_publish_detail = 'the write into the claimed target was short and the entry could not be emptied' . ( $still_ours ? '; partial bytes remain at ' . $path : '' );
				return 'partial';
			}
			return 'the write into the claimed target was short' . ( $still_ours ? '; an empty file remains at ' . $path : '' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $still_ours ) {
			// The entry we created was unlinked (and maybe replaced) while we
			// wrote: our bytes are in an inode nobody can reach, and whatever
			// holds the path now was not touched by us.
			return self::path_present( $path ) ? 'exists' : 'the target was removed during the write';
		}
		return true;
	}

	/**
	 * Empty our own inode after a short write. Seam: a test models a
	 * filesystem that refuses the truncation.
	 *
	 * @param resource $fh Open handle we own.
	 * @return bool
	 */
	protected function truncate_to_empty( $fh ) {
		return (bool) ftruncate( $fh, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate
	}

	/**
	 * The real mode of an inode we just created. Seam: a test models a
	 * directory whose default ACL overrides the umask.
	 *
	 * @param resource $fh   Open handle.
	 * @param array    $stat Its fstat().
	 * @return int
	 */
	protected function mode_of( $fh, array $stat ) {
		return (int) $stat['mode'];
	}

	/**
	 * The mode a fresh create should carry: FS_CHMOD_FILE, or 0644 without it.
	 * Seam: a test models a host that configures execute bits.
	 *
	 * @return int
	 */
	protected function create_mode() {
		return defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644;
	}

	/**
	 * Copy $src into $fh, chunk by chunk, or say so. Bounded memory whatever
	 * the size (a changed file put back on restore can be anything the site
	 * grew it to — Codex #97 round-2 P2). Seam: a test models a short write.
	 *
	 * @param resource $fh  Open destination handle.
	 * @param resource $src Open readable source handle.
	 * @return bool
	 */
	protected function write_all( $fh, $src ) {
		while ( ! feof( $src ) ) {
			$chunk = fread( $src, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk ) {
				return false;
			}
			$len = strlen( $chunk );
			$off = 0;
			while ( $off < $len ) {
				$n = fwrite( $fh, substr( $chunk, $off ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				if ( false === $n || 0 === $n ) {
					return false;
				}
				$off += $n;
			}
		}
		return true;
	}

	/**
	 * Seam between the write and the ownership check. Nothing in production;
	 * a test models a racer acting on the pathname here.
	 *
	 * @param string $path Target path.
	 */
	protected function during_write( $path ) {
	}

	/**
	 * How a create publishes on this host: 'link' (one atomic hard link),
	 * 'write' (exclusive create, bytes written into the owned handle — a
	 * reader can see the file grow), or null when neither can land a create:
	 * no link() AND a configured create mode with execute bits, which fopen()
	 * cannot recreate (Codex #97 round-6 P2), or no chmod() at all — the stage
	 * cannot be secured and every create refuses (round-7 P2). Reported by
	 * audit_agent_code so the fleet knows which sites can create at all, and how.
	 *
	 * @param int|null $mode The create mode; null = FS_CHMOD_FILE (0644).
	 * @return string|null
	 */
	public static function publish_mode( $mode = null ) {
		if ( ! function_exists( 'chmod' ) ) {
			return null; // secure_stage() refuses every create without it (Codex #97 round-7 P2)
		}
		if ( function_exists( 'link' ) ) {
			return 'link';
		}
		$mode = null === $mode ? ( defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644 ) : (int) $mode;
		return 0 === ( $mode & 0111 ) ? 'write' : null;
	}

	/**
	 * Whether link() can be called on this host — a host may put it in
	 * disable_functions, where calling it warns and returns null.
	 *
	 * Protected so a test can model such a host.
	 *
	 * @return bool
	 */
	protected function link_available() {
		return function_exists( 'link' );
	}

	/**
	 * Refuse a create after its record was written. Discards the staged file
	 * and retires the record — every exit after persist_create_record() goes
	 * through here, because the record of a create that did NOT happen must
	 * not survive as a restorable one (Codex #94 round-5 P2, round-7 P2): a
	 * later creator that lands the same bytes at the target — concurrent
	 * identical creates are the common race — would pass the hash check and
	 * lose its file to a restore of this orphan. The record is removed, and
	 * when the unlink is refused it is VOIDED in place so restore refuses it;
	 * only when even that fails is `stale_record` reported.
	 *
	 * @param string $tmp   The staged file.
	 * @param string $id    The record id.
	 * @param string $error The refusal.
	 * @return array { success: false, error: string, stale_record?: string }
	 */
	private function abandon_create( $tmp, $id, $error ) {
		$this->discard_stage( $tmp );
		$out = array( 'success' => false, 'error' => $error );
		if ( ! $this->void_create_record( $id ) ) {
			$out['stale_record'] = $id;
		}
		return $out;
	}

	/**
	 * A publish that left bytes at the target which are NOT the recorded
	 * content, and cannot be taken back: the record is voided in place and
	 * marked `interrupted` (restore refuses it), the stage is KEPT as the
	 * operator's copy of what should have landed, and the record is named.
	 *
	 * @param string $id     Record id.
	 * @param string $tmp    Staged path (kept).
	 * @param string $error  The answer's error code.
	 * @param string $detail Why.
	 * @return array { success: false, error, detail, stale_record }
	 */
	private function interrupted_create( $id, $tmp, $error, $detail ) {
		$out = array( 'success' => false, 'error' => $error, 'detail' => (string) $detail, 'stale_record' => $id );
		if ( $this->void_record_in_place( $id, array( 'interrupted' => true ) ) ) {
			$out['detail'] .= '; record ' . $id . ' voided and marked interrupted, staged bytes kept at ' . $tmp;
		}
		return $out;
	}

	/**
	 * Run $work holding the lock for one target PATH — shared by create_file(),
	 * overwrite_file() and restore() of a created file, so two engine writers
	 * never touch one path at once (SiteAgent#99). Named by the path's sha1
	 * under the snapshots directory; waits target_lock_tries() × 20 ms.
	 *
	 * @param string   $path Target path.
	 * @param callable $work Runs under the lock.
	 * @return mixed $work's return, or null when the lock could not be taken.
	 */
	private function with_target_lock( $path, $work ) {
		return $this->with_lock( 'path-' . sha1( (string) $path ), $work, $this->target_lock_tries() );
	}

	/**
	 * Turn with_target_lock()'s two failures into answers; pass a result through.
	 *
	 * @param mixed  $out  with_target_lock()'s return.
	 * @param string $path The path, for the detail.
	 * @return array
	 */
	private function locked_answer( $out, $path ) {
		if ( null === $out ) {
			return array( 'success' => false, 'error' => 'locked', 'detail' => 'another write to this path is in progress: ' . $path );
		}
		if ( self::LOCK_UNAVAILABLE === $out ) {
			return array( 'success' => false, 'error' => 'Unable to create a lock under the snapshots directory (unwritable?); nothing can be persisted there, so nothing was written.' );
		}
		return $out;
	}

	/**
	 * How many 20 ms attempts a target lock is worth: five seconds — a write is
	 * milliseconds, and a request that waits longer is waiting on a hung one.
	 * Seam: a test shortens it.
	 *
	 * @return int
	 */
	protected function target_lock_tries() {
		return 250;
	}

	/**
	 * Seam: flush the create record to disk. A test models a kernel that
	 * refuses the sync.
	 *
	 * @param string $meta_path The record file.
	 * @return bool
	 */
	protected function sync_create_record( $meta_path ) {
		return $this->sync_file( $meta_path );
	}

	/**
	 * Remove a staged file. Best effort: the sweep and prune cover a miss.
	 *
	 * @param string $tmp Staged path.
	 */
	protected function discard_stage( $tmp ) {
		if ( is_string( $tmp ) && '' !== $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * Persist the create record. Seam: a test models a record that persist()
	 * reported written but that does not read back.
	 *
	 * @param array $meta The record.
	 * @return array|false
	 */
	protected function persist_create_record( array $meta ) {
		return $this->persist( $meta );
	}

	/**
	 * Flush one file's bytes to disk. True when fsync() is unavailable (PHP
	 * < 8.1 — the write already returned and there is nothing more this
	 * runtime can ask of the kernel); false when the file cannot be opened
	 * or the kernel refuses. Directory entries are NOT covered: PHP cannot
	 * open a directory as a stream.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function sync_file( $path ) {
		if ( ! function_exists( 'fsync' ) ) {
			return true;
		}
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A handle only, to fsync a file this class just wrote; the failure is answered, not surfaced as a warning.
		if ( false === $fh ) {
			return false;
		}
		$ok = fsync( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return (bool) $ok;
	}

	/**
	 * Retire the record of a create that did not happen. Deletes the record
	 * file; when that is refused, rewrites it without `expected_sha256` and
	 * with `voided: true`, so restore_created_file() answers "carries no
	 * expected hash" instead of deleting whatever holds the target now.
	 *
	 * @param string $id Snapshot id.
	 * @return bool True when the record is gone or voided; false when it is
	 *              still restorable and the caller must say so.
	 */
	private function void_create_record( $id ) {
		$meta_path = $this->dir . basename( (string) $id ) . '.json';
		$this->delete_record_file( $id );
		if ( ! file_exists( $meta_path ) ) {
			return true;
		}
		return $this->void_record_in_place( $id );
	}

	/**
	 * Rewrite a record without `expected_sha256` and `staged`, with
	 * `voided: true` (plus $extra), so restore_created_file() answers
	 * "carries no expected hash" instead of deleting whatever holds the target.
	 *
	 * @param string $id    Snapshot id.
	 * @param array  $extra Extra keys to stamp (e.g. `interrupted`).
	 * @return bool True when the record is voided (or undecodable — restore
	 *              cannot act on that either); false when the rewrite failed.
	 */
	protected function void_record_in_place( $id, array $extra = array() ) {
		$meta_path = $this->dir . basename( (string) $id ) . '.json';
		$record    = $this->get( $id );
		if ( ! is_array( $record ) ) {
			return true; // undecodable: restore cannot act on it either
		}
		unset( $record['expected_sha256'], $record['staged'] );
		$record['voided'] = true;
		foreach ( $extra as $k => $v ) {
			$record[ $k ] = $v;
		}
		$json = wp_json_encode( $record );
		if ( false === $json ) {
			return false;
		}
		$n = @file_put_contents( $meta_path, $json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The record file this class owns; a refusal is answered to the caller, not surfaced as a warning.
		return false !== $n && $n === strlen( $json ) && $this->sync_file( $meta_path );
	}

	/**
	 * Run $work holding this record's lock, or say the lock could not be had.
	 * The sweeper's hash-then-void and the publisher's check-then-reinstate
	 * are read/write sequences on the same record; without one lock across
	 * each, a void can commit after the publisher's check (Codex #97 round-6
	 * P2). The lock is a sibling `<id>.lock` file under flock(); it is taken
	 * non-blocking with a bounded retry so no request ever hangs on it —
	 * a caller that cannot get it treats its section as contended and says
	 * so (the sweeper leaves the stage for the next pass; the publisher
	 * reports a warning).
	 *
	 * @param string   $id   Snapshot id.
	 * @param callable $work Runs under the lock; its return is returned.
	 * @return mixed $work's return, or null when the lock could not be taken.
	 */
	private function with_record_lock( $id, $work ) {
		return $this->with_lock( basename( (string) $id ), $work, 50 );
	}

	/**
	 * Run $work holding a named lock under the snapshots directory: flock()
	 * on `<name>.lock` where the host allows it, else a `<name>.lock.d`
	 * directory — mkdir() is atomic on every filesystem PHP runs on and
	 * needs no flock() (SiteAgent#99: the unlocked fallback let a sweep
	 * retire a record a paused publisher then completed). A lock directory
	 * older than LOCK_STALE_AFTER was left by a crashed holder and is broken.
	 * Both are taken non-blocking with a bounded retry so no request hangs.
	 *
	 * @param string   $name  Lock name (a record id, or `path-<sha1>`).
	 * @param callable $work  Runs under the lock; its return is returned.
	 * @param int      $tries 20 ms attempts before giving up.
	 * @return mixed $work's return; null when the lock is held by someone else;
	 *               LOCK_UNAVAILABLE when no lock can be created at all.
	 */
	private function with_lock( $name, $work, $tries ) {
		$base = $this->dir . basename( (string) $name );
		if ( ! $this->lock_available() ) {
			return $this->with_mkdir_lock( $base . '.lock.d', $work, $tries );
		}
		// The flock file STAYS after release: unlinking a lock file races (a
		// waiter that opened the old inode and a newcomer that creates a new one
		// would both hold "the" lock). It is empty, one per record or path, and
		// a record's goes with the record.
		$fh = @fopen( $base . '.lock', 'cb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A lock file this class owns; a refusal is answered, not surfaced.
		if ( false === $fh ) {
			return self::LOCK_UNAVAILABLE;
		}
		$held = false;
		for ( $i = 0; $i < $tries && ! $held; $i++ ) {
			$held = flock( $fh, LOCK_EX | LOCK_NB );
			if ( ! $held ) {
				usleep( 20000 );
			}
		}
		if ( ! $held ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return null;
		}
		try {
			return $work();
		} finally {
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * The flock()-less lock: a directory that exists while the section runs.
	 *
	 * @param string   $dir   Lock directory.
	 * @param callable $work  Runs under the lock.
	 * @param int      $tries 20 ms attempts.
	 * @return mixed $work's return, or null when the lock could not be taken.
	 */
	private function with_mkdir_lock( $dir, $work, $tries ) {
		$held = false;
		for ( $i = 0; $i < $tries && ! $held; $i++ ) {
			$held = @mkdir( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- EEXIST is the expected refusal: someone holds it.
			if ( $held ) {
				break;
			}
			if ( ! is_dir( $dir ) ) {
				return self::LOCK_UNAVAILABLE; // refused for another reason (permissions): no lock can be created here
			}
			$at = @filemtime( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The holder may release between the two calls; that is an answer.
			if ( false !== $at && $at < time() - self::LOCK_STALE_AFTER ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A crashed holder's leftover; whoever's mkdir wins next holds it.
				continue;
			}
			usleep( 20000 );
		}
		if ( ! $held ) {
			return null;
		}
		try {
			return $work();
		} finally {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Release; a directory that is already gone was broken as stale, which is the same state.
		}
	}

	/**
	 * Whether flock() can be called on this host. Seam: a test models a host
	 * that disables it.
	 *
	 * @return bool
	 */
	protected function lock_available() {
		return function_exists( 'flock' );
	}

	/**
	 * Lift a void a concurrent sweep put on a record whose publish did land:
	 * the expected hash goes back, `voided` and `interrupted` go.
	 *
	 * @param string     $id       Snapshot id.
	 * @param string     $sha      The published content's sha256.
	 * @param array|null $original The record as persisted, for one that was
	 *                             retired (its file is gone) and must be
	 *                             written back whole.
	 * @return bool
	 */
	private function reinstate_record( $id, $sha, $original = null ) {
		$meta_path = $this->dir . basename( (string) $id ) . '.json';
		$record    = $this->get( $id );
		if ( ! is_array( $record ) ) {
			if ( ! is_array( $original ) ) {
				return false;
			}
			$record = $original;
			unset( $record['meta_path'], $record['payload_path'] );
		}
		unset( $record['voided'], $record['interrupted'] );
		$record['expected_sha256'] = $sha;
		$json                      = wp_json_encode( $record );
		if ( false === $json ) {
			return false;
		}
		$n = @file_put_contents( $meta_path, $json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The record file this class owns; a refusal is answered to the caller.
		return false !== $n && $n === strlen( $json ) && $this->sync_file( $meta_path );
	}

	/**
	 * A record as it may leave the engine: without any local filesystem path.
	 * `staged` (a create in flight), `payload_path` and `meta_path` are this
	 * site's paths, not facts about the snapshot; every listing or lookup
	 * that crosses the wire passes its records through here (Codex #94
	 * round-5 P3 — `GET /aura/v2/snapshots` returned list_snapshots() verbatim).
	 *
	 * @param array $record A stored record.
	 * @return array
	 */
	public static function redact( array $record ) {
		unset( $record['staged'], $record['payload_path'], $record['meta_path'] );
		return $record;
	}

	/**
	 * Remove a record file by id whether or not it decodes — delete() reads
	 * the record first and cannot remove one that failed to read back.
	 *
	 * @param string $id Snapshot id.
	 */
	private function delete_record_file( $id ) {
		$meta_path = $this->dir . basename( (string) $id ) . '.json';
		if ( file_exists( $meta_path ) ) {
			wp_delete_file( $meta_path );
		}
		$lock = $this->dir . basename( (string) $id ) . '.lock';
		if ( file_exists( $lock ) ) {
			wp_delete_file( $lock );
		}
		if ( is_dir( $lock . '.d' ) ) {
			@rmdir( $lock . '.d' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A stale mkdir lock of a record that is going; a refusal is harmless.
		}
	}

	/**
	 * The record that names this staged file, if any (a create that died
	 * between its record and its publish). Reads the records only when an
	 * over-age stage is actually found, which is rare.
	 *
	 * @param string $staged Staged path.
	 * @return array|null
	 */
	private function record_for_stage( $staged ) {
		foreach ( $this->list_snapshots() as $rec ) {
			if ( 'file' === ( $rec['kind'] ?? '' ) && ( $rec['staged'] ?? null ) === $staged ) {
				return $rec;
			}
		}
		return null;
	}

	/**
	 * A stage that outlived its hour means the publish never finished. If the
	 * target holds the expected bytes the publish DID land and only the stage
	 * cleanup failed — the record stays restorable. If the target holds other
	 * bytes, either the link()-less write was interrupted (an empty or
	 * partial file this create left) or something else took the path after we
	 * died; the record is VOIDED so a restore can never delete that file,
	 * and marked `interrupted` so the listing says why (Codex #97 round-1 P1).
	 * An absent target means the claim never happened: the record is retired
	 * (removed, else voided) so its hash can never match a later file there.
	 *
	 * The stage is the signal that reconciliation is still owed: when the
	 * record could not be voided (snapshot directory unwritable, disk full)
	 * the stage must STAY so the next sweep tries again — deleting it would
	 * leave the record restorable for good (Codex #97 round-2 P2).
	 *
	 * @param array|null $rec The record naming the stage, or null.
	 * @return bool True when the stage may be deleted.
	 */
	protected function reconcile_stage( $rec ) {
		if ( ! is_array( $rec ) || empty( $rec['id'] ) ) {
			return true;
		}
		$id     = (string) $rec['id'];
		$target = (string) ( $rec['target'] ?? '' );
		if ( '' === $target ) {
			return true;
		}
		// Hash and void under the record's lock: a publisher landing right now
		// re-checks its record under the same lock, so the void can never
		// commit after that check (Codex #97 round-6 P2). Contended → keep the
		// stage; the next pass decides.
		$done = $this->with_record_lock(
			$id,
			function () use ( $id, $target ) {
				$current  = $this->get( $id );
				$expected = is_array( $current ) ? (string) ( $current['expected_sha256'] ?? '' ) : '';
				if ( '' === $expected ) {
					return true; // already voided or retired
				}
				if ( ! self::path_present( $target ) ) {
					// The process died between the record and the claim: nothing
					// was ever created, so the record of a create that did not
					// happen goes — a live hash would match an unrelated file
					// that lands at this path later and let a restore delete it
					// (Codex #97 round-7 P2). Removed, else voided in place.
					return $this->void_create_record( $id );
				}
				$actual = is_file( $target ) ? hash_file( 'sha256', $target ) : false;
				if ( is_string( $actual ) && hash_equals( $expected, $actual ) ) {
					return true; // published; only the stage cleanup was lost
				}
				return $this->void_record_in_place( $id, array( 'interrupted' => true ) );
			}
		);
		return true === $done;
	}

	/**
	 * Remove `.aura-create-*` leftovers older than STAGE_MAX_AGE in one
	 * directory — a crash between staging and the record leaves a stray that
	 * no record names, and the next create in that directory is the one
	 * place that looks there. Bounded, and never a `.php` name by construction.
	 *
	 * @param string $dir Directory to sweep.
	 */
	private function sweep_stale_stages( $dir ) {
		$cut   = time() - self::STAGE_MAX_AGE;
		$found = glob( $dir . '/.aura-create-*' );
		if ( ! is_array( $found ) ) {
			return;
		}
		$n = 0;
		foreach ( $found as $stray ) {
			if ( ! is_file( $stray ) ) {
				continue;
			}
			// Counted AFTER the is_file() check, so the cap bounds the files
			// this sweep actually examines rather than whatever glob() listed.
			if ( ++$n > self::STAGE_SWEEP_CAP ) {
				break;
			}
			$at = filemtime( $stray );
			if ( false !== $at && $at < $cut ) {
				if ( $this->reconcile_stage( $this->record_for_stage( $stray ) ) ) {
					wp_delete_file( $stray );
				}
			}
		}
	}

	/**
	 * Capture a WordPress option's current value before it is changed.
	 *
	 * @param string $name Option name.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_option( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return array( 'success' => false, 'error' => 'Invalid option name.' );
		}

		// Uncollidable sentinel: a fresh object can never equal a stored option
		// value, so an option whose value happens to be a magic string isn't
		// mistaken for "absent" (which restore would wrongly delete).
		$sentinel = new stdClass();
		$value    = get_option( $name, $sentinel );
		$existed  = ( $value !== $sentinel );

		$record = $this->persist(
			array(
				'kind'    => 'option',
				'target'  => $name,
				'existed' => $existed,
			),
			$existed ? serialize( $value ) : ''
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Capture a post's current content before it is edited (Gutenberg/block edits).
	 *
	 * @param int $post_id Post ID.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array( 'success' => false, 'error' => 'Invalid post id.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'success' => false, 'error' => 'Post not found: ' . $post_id );
		}

		$record = $this->persist(
			array(
				'kind'    => 'post',
				'target'  => $post_id,
			),
			(string) $post->post_content
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Capture one or more post-meta keys before they are rewritten.
	 *
	 * This is the surface Elementor page/kit data lives in — `_elementor_data`,
	 * `_elementor_page_settings`, and the kit-scoped globals repositories all
	 * store a single serialized value under one meta key. Each requested key is
	 * captured with its existence flag (so restore can re-delete a key that was
	 * absent at capture time rather than resurrecting an empty one) and its
	 * primary value. It targets single-valued meta keys — the shape every
	 * Elementor storage key uses — not multi-row meta.
	 *
	 * @param int          $post_id Post ID.
	 * @param string|array $keys    Meta key, or list of meta keys, to capture.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_meta( $post_id, $keys ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array( 'success' => false, 'error' => 'Invalid post id.' );
		}
		if ( ! get_post( $post_id ) ) {
			return array( 'success' => false, 'error' => 'Post not found: ' . $post_id );
		}
		// Reject revision/autosave IDs. get_post_meta reads the revision's own
		// meta, but update_post_meta/delete_post_meta on a revision can affect the
		// parent — so a snapshot taken against a revision could later clobber or
		// wipe the parent page's Elementor data. Elementor data lives on the
		// parent post, so callers must pass the parent id.
		if ( wp_is_post_revision( $post_id ) ) {
			return array( 'success' => false, 'error' => 'Refusing to snapshot a revision/autosave; pass the parent post id.' );
		}

		if ( is_string( $keys ) ) {
			$keys = array( $keys );
		}
		if ( ! is_array( $keys ) || empty( $keys ) ) {
			return array( 'success' => false, 'error' => 'No meta keys given to snapshot.' );
		}

		$captured = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				return array( 'success' => false, 'error' => 'Invalid meta key.' );
			}
			$existed          = metadata_exists( 'post', $post_id, $key );
			$captured[ $key ] = array(
				'existed' => $existed,
				// get_post_meta returns the stored (unslashed) value; restore
				// re-slashes before writing so the value round-trips exactly.
				'value'   => $existed ? get_post_meta( $post_id, $key, true ) : '',
			);
		}

		$record = $this->persist(
			array(
				'kind'   => 'meta',
				'target' => $post_id,
				'keys'   => array_map( 'strval', array_keys( $captured ) ),
			),
			serialize( $captured )
		);

		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Capture a SET of posts (existence + selected meta) so a multi-post write can
	 * be fully rolled back. On restore this recreates a post the write DELETED —
	 * with its ORIGINAL id, via `import_id`, so id references elsewhere (e.g. an
	 * Elementor class_id → post_id map) stay valid — DELETES a post the write
	 * CREATED, and restores the captured meta on a surviving/recreated post.
	 *
	 * Built for Elementor v4 global classes (per-class CPT posts: a create adds a
	 * post, a delete removes one). The cascade — the affected pages' `_elementor_data`
	 * that a class delete rewrites — is snapshotted separately by the caller via
	 * snapshot_meta(); this primitive owns only the class posts themselves.
	 *
	 * @param int[]        $post_ids  Post IDs to capture (each may or may not exist).
	 * @param string|array $meta_keys Meta key(s) to capture per existing post.
	 * @param array        $opts      Optional door metadata:
	 *                                `cpts`       — post types this capture is the WHOLE set of,
	 *                                               so restore also deletes a post of those types
	 *                                               that the write ADDED (Ruling R3);
	 *                                `kind_label` — the door kind (`page|component|design_system|
	 *                                               creation_restore`) stored as `door_kind`;
	 *                                `door`       — `{ seq, ability }` for the audit trail.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_posts( $post_ids, $meta_keys, $opts = array() ) {
		if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
			return array( 'success' => false, 'error' => 'No post ids given to snapshot.' );
		}
		if ( is_string( $meta_keys ) ) {
			$meta_keys = array( $meta_keys );
		}
		if ( ! is_array( $meta_keys ) ) {
			$meta_keys = array();
		}
		foreach ( $meta_keys as $k ) {
			if ( ! is_string( $k ) || '' === $k ) {
				return array( 'success' => false, 'error' => 'Invalid meta key.' );
			}
		}

		$captured = array();
		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $pid ) {
			if ( $pid <= 0 ) {
				return array( 'success' => false, 'error' => 'Invalid post id.' );
			}
			if ( wp_is_post_revision( $pid ) ) {
				return array( 'success' => false, 'error' => 'Refusing to snapshot a revision/autosave id: ' . $pid );
			}
			$post = get_post( $pid );
			if ( ! $post ) {
				$captured[ $pid ] = array( 'existed' => false );
				continue;
			}
			$meta = array();
			foreach ( $meta_keys as $key ) {
				$existed      = metadata_exists( 'post', $pid, $key );
				$meta[ $key ] = array(
					'existed' => $existed,
					'value'   => $existed ? get_post_meta( $pid, $key, true ) : '',
				);
			}
			$captured[ $pid ] = array(
				'existed' => true,
				'fields'  => array(
					'post_type'      => $post->post_type,
					'post_status'    => $post->post_status,
					'post_title'     => $post->post_title,
					'post_name'      => $post->post_name,
					'post_parent'    => (int) $post->post_parent,
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'menu_order'     => (int) $post->menu_order,
					// Preserve identity/scheduling so a recreate is faithful (a fresh
					// wp_insert_post would otherwise stamp "now" + the current user).
					'post_author'    => $post->post_author,
					'post_date'      => $post->post_date,
					'post_date_gmt'  => $post->post_date_gmt,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
				),
				'meta'    => $meta,
			);
		}

		$meta = array(
			'kind'    => 'posts',
			'targets' => array_map( 'intval', array_keys( $captured ) ),
			'keys'    => array_values( array_map( 'strval', $meta_keys ) ),
		);
		if ( ! empty( $opts['cpts'] ) && is_array( $opts['cpts'] ) ) {
			$meta['cpts'] = array_values( array_map( 'strval', $opts['cpts'] ) );
		}
		if ( ! empty( $opts['kind_label'] ) ) {
			$meta['door_kind'] = (string) $opts['kind_label'];
		}
		if ( ! empty( $opts['door'] ) && is_array( $opts['door'] ) ) {
			$meta['door'] = $opts['door'];
		}

		$record = $this->persist( $meta, serialize( $captured ) );
		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * A creation: there was nothing to capture BEFORE, so the envelope names
	 * what the write made. Kind `creation`; its restore is a governed trash
	 * (never a delete — see the `creation` case in restore()).
	 *
	 * @param int[]  $post_ids  The ids the write created.
	 * @param string $post_type Their post type.
	 * @param array  $door      `{ seq, ability }` for the audit trail.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_creation( array $post_ids, $post_type, array $door ) {
		$record = $this->persist(
			array(
				'kind'             => 'creation',
				'door_kind'        => 'creation',
				'created_post_ids' => array_values( array_map( 'intval', $post_ids ) ),
				'post_type'        => (string) $post_type,
				'door'             => $door,
			)
		);
		if ( false === $record ) {
			return array( 'success' => false, 'error' => 'Failed to persist snapshot (disk full or unwritable).' );
		}
		return array( 'success' => true, 'snapshot' => $record );
	}

	/**
	 * Restore a captured `{ key => { existed, value } }` meta map onto a post —
	 * deleting keys absent at capture, re-slashing and writing the rest, verifying
	 * each (both delete_post_meta and update_post_meta return false ambiguously).
	 * Shared by the `meta` and `posts` restore kinds.
	 *
	 * @param int   $post_id  Target post id (must already exist).
	 * @param array $captured Captured meta map.
	 * @return array { success: bool, error?: string }
	 */
	private function restore_meta_map( $post_id, $captured ) {
		foreach ( $captured as $key => $info ) {
			$key = (string) $key;
			if ( empty( $info['existed'] ) ) {
				delete_post_meta( $post_id, $key );
				if ( metadata_exists( 'post', $post_id, $key ) ) {
					return array( 'success' => false, 'error' => 'Failed to remove meta key: ' . $key );
				}
				continue;
			}
			$ok = update_post_meta( $post_id, $key, wp_slash( $info['value'] ) );
			if ( false === $ok && get_post_meta( $post_id, $key, true ) !== $info['value'] ) {
				return array( 'success' => false, 'error' => 'Failed to restore meta key: ' . $key );
			}
		}
		return array( 'success' => true );
	}

	/**
	 * Undo a create_file(): remove the target ONLY while it still holds the
	 * bytes the agent wrote (the §2 ruling — byte identity, no inode: a file
	 * deleted and re-created with the same bytes IS the content this record
	 * exists to remove; different bytes are never deleted). The check and the
	 * delete are one file: the path is claimed by an atomic rename first, so
	 * a replacement arriving in between is never the file that gets deleted.
	 *
	 * `.aura-restore-*` files are NEVER swept: one is either a claim in
	 * flight or a changed file kept aside on purpose (user data).
	 *
	 * @param array $record The `existed: false` file record.
	 * @return array { success: bool, error?: string }
	 */
	private function restore_created_file( array $record ) {
		$target = isset( $record['target'] ) ? (string) $record['target'] : '';
		if ( '' === $target ) {
			return array( 'success' => true ); // already gone
		}
		$out = $this->with_target_lock(
			$target,
			function () use ( $record, $target ) {
				return $this->restore_created_file_locked( $record, $target );
			}
		);
		return $this->locked_answer( $out, $target );
	}

	/**
	 * The restore proper, under the target's lock (SiteAgent#99).
	 *
	 * @param array  $record The record.
	 * @param string $target Its target.
	 * @return array As restore_created_file().
	 */
	private function restore_created_file_locked( array $record, $target ) {
		if ( ! self::path_present( $target ) ) {
			return array( 'success' => true ); // already gone
		}
		// is_file() follows a symlink, so a DANGLING one reads as absent to
		// file_exists() and as "not a file" here — it is a directory entry that
		// can go live the moment its destination appears, never "already gone"
		// (Codex #94 round-2 P2). It is reported, never deleted.
		if ( ! is_file( $target ) ) {
			return array( 'success' => false, 'error' => 'Target is not a regular file: ' . $target );
		}
		$expected = isset( $record['expected_sha256'] ) ? (string) $record['expected_sha256'] : '';
		if ( '' === $expected ) {
			return array( 'success' => false, 'error' => 'Snapshot record carries no expected hash.' );
		}

		if ( ! $this->link_available() ) {
			// Without link() an executable cannot be put back (fopen() cannot
			// create one), so claiming it by rename() and then refusing left an
			// edited 0755 file aside with its path absent (SiteAgent#99). Such a
			// file is verified IN PLACE first: other bytes → refused, untouched;
			// the agent's bytes → the claim below, and only a change inside that
			// window can still leave it aside.
			$perms = @fileperms( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file is there (checked above); a refusal falls through to the claim.
			if ( false !== $perms && 0 !== ( $perms & 0111 ) ) {
				$inplace = hash_file( 'sha256', $target );
				if ( ! is_string( $inplace ) || ! hash_equals( $expected, $inplace ) ) {
					return array(
						'success' => false,
						'error'   => 'file_changed_since',
						'detail'  => 'the file is executable and link() is unavailable, so it was verified in place and left untouched',
					);
				}
			}
		}

		// CLAIM the pathname before verifying anything (Codex #91 round-1 P1):
		// hash-then-unlink had a window in which another process could replace
		// the target and this call would delete bytes it never verified.
		// rename() is atomic and keeps the inode, so what we hash below is
		// exactly what we may delete, and whatever lands at the path after this
		// line is not ours and is never touched.
		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		$claim = dirname( $target ) . '/.aura-restore-' . $suffix; // opaque, never a .php name
		if ( ! @rename( $target, $claim ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- The claim IS the point; $wp_filesystem->move() may copy+delete, which is neither atomic nor inode-preserving.
			return self::path_present( $target )
				? array( 'success' => false, 'error' => 'Unable to claim file for restore: ' . $target )
				: array( 'success' => true ); // it vanished between exists() and the claim: already gone
		}
		$this->after_claim( $claim, $target );

		$actual = hash_file( 'sha256', $claim );
		if ( is_string( $actual ) && hash_equals( $expected, $actual ) ) {
			// The agent's bytes, verified on the file we hold. Remove them.
			wp_delete_file( $claim );
			if ( file_exists( $claim ) ) {
				// The agent's bytes are still on disk under the claim name; say
				// where, as the file_changed_since branch below does.
				return array( 'success' => false, 'error' => 'Failed to delete file: ' . $claim, 'moved_aside' => $claim );
			}
			return array( 'success' => true );
		}

		// Not the agent's bytes: put the file back, without clobbering anything
		// that took the path meanwhile. link() is no-clobber; a refusal means the
		// path is taken — the changed file stays beside it under its claim name
		// and the answer says where. Nothing is ever deleted on this branch.
		$out = array( 'success' => false, 'error' => 'file_changed_since' );
		if ( $this->link_available() ) {
			if ( @link( $claim, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EEXIST is the expected refusal, classified below.
				wp_delete_file( $claim ); // the second name to the same inode; the file is back at its path
				if ( file_exists( $claim ) ) {
					// The file is back, but its claim name could not be removed and
					// `.aura-restore-*` is never swept: say where it is, as the
					// matching-hash branch does (Codex #94 round-7 P2).
					$out['moved_aside'] = $claim;
				}
			} else {
				$out['moved_aside'] = $claim;
			}
			return $out;
		}
		// A host without link() (SiteAgent#96) puts the file back the way
		// publish() lands one: exclusive-create the path and write the claimed
		// bytes into the handle we own — no clobber, ever. A refused claim
		// means the path is taken; a refused write leaves our empty entry there.
		// Either way the changed file stays aside and the answer says where.
		if ( true !== $this->put_back_by_write( $claim, $target ) ) {
			$out['moved_aside'] = $claim;
			return $out;
		}
		// The claim is a COPY's source, not a second name to the same inode: a
		// process that opened the file before the claim can still be writing
		// to it. The copy at the path is only the file if the claim reads the
		// same after the copy as the target does; otherwise the claim stays,
		// named, and the answer says the copy may be behind (Codex #97
		// round-8 P1). Writes after this check are the residual of a
		// link()-less host and are why the claim name is reported at all.
		$a = hash_file( 'sha256', $claim );
		$b = hash_file( 'sha256', $target );
		if ( ! is_string( $a ) || ! is_string( $b ) || ! hash_equals( $a, $b ) ) {
			$out['moved_aside'] = $claim;
			$out['detail']      = 'the file changed while it was being put back; the copy at its path may be behind the file kept aside';
			return $out;
		}
		wp_delete_file( $claim ); // the bytes are back at their path; the claim copy goes
		if ( file_exists( $claim ) ) {
			$out['moved_aside'] = $claim;
		}
		return $out;
	}

	/**
	 * The link()-less put-back: exclusive-create the target and stream the
	 * claimed file into it. See write_exclusively().
	 *
	 * @param string $claim  The claimed (renamed) file.
	 * @param string $target The original path.
	 * @return true|string true when the bytes are back at their path.
	 */
	private function put_back_by_write( $claim, $target ) {
		$src = @fopen( $claim, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The file this call holds under its claim name.
		if ( false === $src ) {
			return 'the claimed file could not be read';
		}
		$st   = fstat( $src );
		$mode = is_array( $st ) ? ( (int) $st['mode'] & 0777 ) : null;
		if ( null !== $mode && 0 !== ( $mode & 0111 ) ) {
			// fopen() cannot create an executable (base 0666; a umask only removes
			// bits) and nothing here addresses the path by name, so an executable
			// cannot come back as it was without link(): it stays aside, named
			// (Codex #97 round-4 P2).
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return sprintf( 'the file is executable (mode %o) and that cannot be recreated without link()', $mode );
		}
		$out = $this->write_exclusively( $target, $src, $mode ); // the file comes back with the mode it had (Codex #97 round-3 P2)
		fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * Whether a directory entry exists at the path — a regular file, a
	 * directory, or a symlink whether or not its destination exists.
	 * file_exists() alone answers false for a dangling symlink.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function path_present( $path ) {
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Seam between claiming the target and verifying it. Nothing in
	 * production; a test models a concurrent writer landing at the path here.
	 *
	 * @param string $claim  The claimed (renamed) file.
	 * @param string $target The original path.
	 */
	protected function after_claim( $claim, $target ) {
	}

	/**
	 * Load a snapshot record by id.
	 *
	 * @param string $id Snapshot id.
	 * @return array|null
	 */
	public function get( $id ) {
		$meta_path = $this->dir . basename( (string) $id ) . '.json';
		if ( ! file_exists( $meta_path ) ) {
			return null;
		}
		$data = json_decode( file_get_contents( $meta_path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Unserialize a snapshot payload without ever instantiating a class.
	 *
	 * The payload files are written by this plugin, but they sit on disk and are
	 * the one untrusted-if-tampered input on the restore path — a plain
	 * unserialize() would let a crafted payload build arbitrary objects and fire
	 * __wakeup()/__destruct() gadget chains. `allowed_classes => false` turns any
	 * serialized object into a __PHP_Incomplete_Class instead, closing that
	 * surface. Everything this engine actually captures — option values, the
	 * meta/post arrays it builds, Elementor's JSON-string payloads — is scalars
	 * and arrays, so nothing legitimate is lost. A payload that *did* contain an
	 * object was either tampered or a pathological object-valued option; the
	 * callers detect the resulting incomplete class (an object is not an array,
	 * and is_stripped_object() catches the top-level option case) and fail closed
	 * rather than write it back.
	 *
	 * @param string $raw Serialized payload bytes.
	 * @return mixed Unserialized value, or false on malformed input.
	 */
	private function unserialize_payload( $raw ) {
		return unserialize( (string) $raw, array( 'allowed_classes' => false ) );
	}

	/**
	 * Maximum array nesting the stripped-object walk will descend before it
	 * treats the payload as unsafe. Real snapshot payloads (option values, the
	 * shallow meta/post maps, Elementor's JSON-string blobs) are nowhere near
	 * this deep, so a legitimate payload never reaches it; the cap exists only
	 * to bound a tampered one.
	 */
	private const MAX_WALK_DEPTH = 64;

	/**
	 * True when a value is — or contains, at any depth — an object stripped by
	 * allowed_classes => false.
	 *
	 * The check must recurse: allowed_classes => false strips every serialized
	 * object to __PHP_Incomplete_Class, but a tampered payload can nest one
	 * inside an otherwise-valid array (e.g. serialize( array( 'x' => $gadget ) )).
	 * A top-level-only check passes that array straight through and update_option
	 * / update_post_meta persists the incomplete class as a "successful" restore.
	 * Walking the whole structure is what lets every kind fail closed on it.
	 *
	 * The walk is depth-bounded: unserialize() faithfully rebuilds a serialized
	 * reference cycle (e.g. `a:1:{i:0;R:1;}`) into a genuinely self-referential
	 * array, and an unbounded recursion over one would exhaust the stack and fatal
	 * the restore request rather than fail closed. Hitting the cap is itself
	 * treated as "unsafe" (return true) so a pathological payload is rejected, not
	 * restored.
	 *
	 * @param mixed $value Unserialized value.
	 * @param int   $depth Current recursion depth (internal).
	 * @return bool
	 */
	private function contains_stripped_object( $value, $depth = 0 ) {
		if ( $value instanceof \__PHP_Incomplete_Class ) {
			return true;
		}
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_WALK_DEPTH ) {
				// Too deep to be a real payload — a reference cycle or a crafted
				// deeply-nested array. Refuse it rather than recurse into a fatal.
				return true;
			}
			foreach ( $value as $item ) {
				if ( $this->contains_stripped_object( $item, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Restore state from a snapshot.
	 *
	 * @param string $id Snapshot id.
	 * @return array { success: bool, error?: string }
	 */
	public function restore( $id ) {
		$record = $this->get( $id );
		if ( null === $record ) {
			return array( 'success' => false, 'error' => 'Snapshot not found.' );
		}
		if ( ! self::belongs_to_current_blog( $record ) ) {
			// A DESIGNATED refusal, not a failure: the envelope is intact and
			// restorable — on the blog that took it (Ruling P15). Its ids
			// address that blog's tables, so writing it here would overwrite
			// whatever happens to share those numbers.
			return array(
				'success' => false,
				'code'    => 'aura_foreign_blog',
				'error'   => self::FOREIGN_BLOG_ERROR,
			);
		}

		switch ( $record['kind'] ) {
			case 'file':
				if ( isset( $record['existed'] ) && false === $record['existed'] ) {
					return $this->restore_created_file( $record );
				}
				$payload_path = $record['payload_path'] ?? '';
				if ( ! $payload_path || ! file_exists( $payload_path ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
				}
				$ok = file_put_contents( $record['target'], file_get_contents( $payload_path ) );
				return ( false === $ok )
					? array( 'success' => false, 'error' => 'Failed to write file: ' . $record['target'] )
					: array( 'success' => true );

			case 'option':
				if ( empty( $record['existed'] ) ) {
					delete_option( $record['target'] );
					return array( 'success' => true );
				}
				$payload_path = $record['payload_path'] ?? '';
				$raw          = ( $payload_path && file_exists( $payload_path ) ) ? file_get_contents( $payload_path ) : '';
				$value        = $this->unserialize_payload( $raw );
				// Fail closed rather than write back an incomplete class: the payload
				// held a serialized object (tampered, or a rare object-valued option),
				// which allowed_classes => false intentionally refused to rebuild.
				// Recurses so a nested object inside an array is caught too, not just
				// a top-level one.
				if ( $this->contains_stripped_object( $value ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload contains an object and cannot be safely restored.' );
				}
				update_option( $record['target'], $value );
				return array( 'success' => true );

			case 'post':
				// Fail closed if the payload is gone — writing '' would WIPE the
				// page instead of restoring it (matches the file case).
				$payload_path = $record['payload_path'] ?? '';
				if ( ! $payload_path || ! file_exists( $payload_path ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
				}
				$content = file_get_contents( $payload_path );
				$result  = wp_update_post(
					array(
						'ID'           => (int) $record['target'],
						'post_content' => $content,
					),
					true
				);
				return is_wp_error( $result )
					? array( 'success' => false, 'error' => $result->get_error_message() )
					: array( 'success' => true );

			case 'meta':
				$payload_path = $record['payload_path'] ?? '';
				if ( ! $payload_path || ! file_exists( $payload_path ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
				}
				$captured = $this->unserialize_payload( file_get_contents( $payload_path ) );
				// A tampered payload that serialized an object is stripped to an
				// incomplete class. Reject both a top-level one (not an array) and one
				// nested inside a captured meta value, before any of it is written.
				if ( ! is_array( $captured ) || $this->contains_stripped_object( $captured ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload corrupt.' );
				}
				$post_id = (int) $record['target'];
				// If the page/kit was deleted after the snapshot was taken, writing
				// meta would add orphaned wp_postmeta rows for a non-existent object
				// and falsely report a successful restore. Fail closed.
				if ( ! get_post( $post_id ) ) {
					return array( 'success' => false, 'error' => 'Target post no longer exists; cannot restore.' );
				}
				// Delete keys absent at capture, re-slash + write the rest, verify each
				// (both delete_post_meta and update_post_meta return false ambiguously).
				return $this->restore_meta_map( $post_id, $captured );

			case 'posts':
				return $this->restore_posts_record( $record );

			case 'creation_restore':
				// The pre-restore capture of a creation restore: a `posts` envelope
				// under another name, restored exactly the same way. (Every envelope
				// this plugin writes for that kind carries `kind: posts` and only the
				// `door_kind` label; the case is here so a record that names the door
				// kind directly is not answered "unsupported".)
				return $this->restore_posts_record( $record );

			case 'creation':
				// Core's wp_trash_post() DELETES when the trash is off (post.php), so
				// on such a site "undo the creation" is not reversible at all. Refuse
				// BEFORE touching anything rather than destroy the page silently.
				if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) {
					return array(
						'success' => false,
						'code'    => 'aura_trash_disabled',
						'error'   => 'this site has the trash disabled — the created page cannot be undone reversibly; delete it by hand',
					);
				}
				$trashed = array();
				$already = array();
				foreach ( (array) ( $record['created_post_ids'] ?? array() ) as $pid ) {
					$pid  = (int) $pid;
					$post = get_post( $pid );
					if ( ! $post ) {
						continue; // gone already: nothing to undo
					}
					if ( 'trash' === $post->post_status ) {
						$already[] = $pid;
						continue;
					}
					// wp_trash_post()'s return is not proof (a filter can short-circuit
					// it); the status is.
					wp_trash_post( $pid );
					$after = get_post( $pid );
					if ( ! $after || 'trash' !== $after->post_status ) {
						return array( 'success' => false, 'error' => 'Failed to trash created post: ' . $pid );
					}
					$trashed[] = $pid;
				}
				return array( 'success' => true, 'trashed' => $trashed, 'already' => $already );

			default:
				return array( 'success' => false, 'error' => 'Unsupported snapshot kind: ' . $record['kind'] );
		}
	}

	/**
	 * The `posts` restore: put every captured id back (recreating one the write
	 * deleted, reverting one it changed), then — for a SET-typed capture — remove
	 * every post of the captured types that was not in the set.
	 *
	 * @param array $record The envelope.
	 * @return array { success: bool, error?: string }
	 */
	private function restore_posts_record( array $record ) {
		$payload_path = $record['payload_path'] ?? '';
		if ( ! $payload_path || ! file_exists( $payload_path ) ) {
			return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
		}
		$captured = $this->unserialize_payload( file_get_contents( $payload_path ) );
		// A tampered payload that serialized an object is stripped to an
		// incomplete class. Reject both a top-level one (not an array) and one
		// nested inside a captured post/meta value, before any of it is written.
		if ( ! is_array( $captured ) || $this->contains_stripped_object( $captured ) ) {
			return array( 'success' => false, 'error' => 'Snapshot payload corrupt.' );
		}
		// An EMPTY capture is not a record of an empty set — snapshot_posts()
		// refuses an empty id list and writes one entry per id, so a payload
		// that deserializes to nothing is truncated or partly written. Refusing
		// is the only honest verdict: reporting success would answer 200 and
		// settle the door entry `ok` having rolled nothing back, and for a
		// set-typed capture it would additionally read as "every class and
		// style on the site was added by the write".
		if ( empty( $captured ) ) {
			return array( 'success' => false, 'error' => 'Snapshot payload corrupt.' );
		}
		foreach ( $captured as $pid => $info ) {
			$pid    = (int) $pid;
			$exists = (bool) get_post( $pid );
			$was    = ! empty( $info['existed'] );

			if ( ! $was ) {
				// Absent at capture. If the write CREATED it, delete to roll
				// back; if still absent, nothing to do. Verify by existence —
				// wp_delete_post's return is unreliable (a pre_delete_post
				// filter can short-circuit it to a truthy value without
				// deleting), so a truthy return doesn't prove removal.
				if ( $exists ) {
					wp_delete_post( $pid, true );
					if ( get_post( $pid ) ) {
						return array( 'success' => false, 'error' => 'Failed to delete created post: ' . $pid );
					}
				}
				continue;
			}

			$fields = is_array( $info['fields'] ?? null ) ? $info['fields'] : array();

			if ( ! $exists ) {
				// Present at capture, deleted by the write — recreate it with
				// its ORIGINAL id (import_id) so id references stay valid.
				$insert              = $fields;
				$insert['import_id'] = $pid;
				$new                 = wp_insert_post( wp_slash( $insert ), true );
				if ( is_wp_error( $new ) ) {
					return array( 'success' => false, 'error' => 'Failed to recreate post ' . $pid . ': ' . $new->get_error_message() );
				}
				if ( (int) $new !== $pid ) {
					return array( 'success' => false, 'error' => 'Recreated post got id ' . (int) $new . ', expected ' . $pid . ' (id already taken).' );
				}
			} elseif ( ! empty( $fields ) ) {
				// Present at capture AND still present — but the write may have
				// changed its fields (e.g. a "delete" that trashed it: status →
				// 'trash', row kept). Revert the captured fields, not just meta.
				$update       = $fields;
				$update['ID'] = $pid;
				$upd          = wp_update_post( wp_slash( $update ), true );
				if ( is_wp_error( $upd ) ) {
					return array( 'success' => false, 'error' => 'Failed to restore fields of post ' . $pid . ': ' . $upd->get_error_message() );
				}
			}

			$meta = is_array( $info['meta'] ?? null ) ? $info['meta'] : array();
			$res  = $this->restore_meta_map( $pid, $meta );
			if ( empty( $res['success'] ) ) {
				return $res;
			}
		}
		// A SET-typed capture (design_system): a post of those types that was
		// NOT in the capture was ADDED by the write — remove it, or the restored
		// order meta points at rows that should not exist.
		if ( ! empty( $record['cpts'] ) && is_array( $record['cpts'] ) ) {
			$keep = array_map( 'intval', array_keys( $captured ) );
			$all  = get_posts(
				array(
					'post_type'   => $record['cpts'],
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);
			foreach ( $all as $extra ) {
				if ( in_array( (int) $extra, $keep, true ) ) {
					continue;
				}
				// Verified by existence, not by the return — see above.
				wp_delete_post( (int) $extra, true );
				if ( get_post( (int) $extra ) ) {
					return array( 'success' => false, 'error' => 'Failed to remove added post: ' . (int) $extra );
				}
			}
		}
		return array( 'success' => true );
	}

	/**
	 * List stored snapshots, newest-first.
	 *
	 * @return array
	 */
	public function list_snapshots() {
		$out   = array();
		$files = glob( $this->dir . 'snap_*.json' );
		if ( ! $files ) {
			return $out;
		}
		foreach ( $files as $file ) {
			$data = json_decode( file_get_contents( $file ), true );
			if ( is_array( $data ) ) {
				$out[] = $data;
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $b['id'], $a['id'] );
		} );
		return $out;
	}

	/**
	 * Delete door envelopes older than $days (Ruling R6).
	 *
	 * Keyed on `door_kind`, never on `kind`: a door capture of a page IS a
	 * `posts` envelope, and the power tools' own `posts`/`file`/`option`
	 * captures must survive this sweep untouched.
	 *
	 * ON MULTISITE, ONLY THIS BLOG'S (Ruling P39). Every blog shares the one
	 * directory this class is configured with, and the reconciler — plus its
	 * six-hourly PRUNED_AT throttle — runs independently per blog off that
	 * blog's own `/status` poll. Unscoped, each polled subsite swept the whole
	 * NETWORK's envelopes and deleted other subsites' rollback points, which
	 * both violates the blog ownership `belongs_to_current_blog()` enforces on
	 * every read and restore, and undoes the undo of writes on sites that were
	 * never polled.
	 *
	 * A LEGACY record — one written before the `blog_id` stamp existed — cannot
	 * be placed, so only the MAIN site prunes it: some blog has to, or it would
	 * live for ever, and the main site is the one choice that cannot be made
	 * twice. This matches `belongs_to_current_blog()`'s reading of an
	 * unstamped record as "not provably foreign" without letting every subsite
	 * act on it.
	 *
	 * The per-blog full scan remains — it is throttled to once per six hours
	 * per blog. Per-blog STORAGE (a directory per blog, so the scan is
	 * naturally scoped) is the follow-up this leaves open.
	 *
	 * @param int      $days  Age in days; anything older goes.
	 * @param string[] $kinds The `door_kind` values to prune (see DOOR_KINDS).
	 * @return int How many were deleted.
	 */
	public function prune_older_than( $days, array $kinds ) {
		$cut       = time() - (int) $days * DAY_IN_SECONDS;
		$n         = 0;
		$network   = function_exists( 'is_multisite' ) && is_multisite();
		$main_site = ! $network || ! function_exists( 'is_main_site' ) || is_main_site();
		foreach ( $this->list_snapshots() as $rec ) {
			if ( $network && ! self::prunable_here( $rec, $main_site ) ) {
				continue;
			}
			// A create_file() that died between its record and its publish
			// leaves the staged bytes beside the target (spec §5 step 4). The
			// record stays — restore on it is "already gone" — but the bytes go.
			// Reached only for THIS blog's records (the check above).
			if ( 'file' === ( $rec['kind'] ?? '' ) && isset( $rec['existed'] ) && false === $rec['existed'] && ! empty( $rec['staged'] ) ) {
				$staged = (string) $rec['staged'];
				if ( is_file( $staged ) ) {
					$at = filemtime( $staged );
					if ( false !== $at && $at < time() - self::STAGE_MAX_AGE ) {
						if ( $this->reconcile_stage( $rec ) ) {
							wp_delete_file( $staged );
						}
					}
				}
			}
			if ( ! in_array( (string) ( $rec['door_kind'] ?? '' ), $kinds, true ) ) {
				continue;
			}
			// The stamp persist() wrote is a UTC wall clock with no zone on it.
			$at = strtotime( (string) ( $rec['created_gmt'] ?? '' ) . ' UTC' );
			if ( false === $at || $at >= $cut ) {
				continue;
			}
			if ( $this->delete( $rec['id'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Is this record THIS blog's to prune? Multisite only — the caller has
	 * already established that (Ruling P39).
	 *
	 * @param array $record    The envelope.
	 * @param bool  $main_site Is the current blog the network's main site?
	 * @return bool
	 */
	private static function prunable_here( array $record, $main_site ) {
		if ( ! isset( $record['blog_id'] ) ) {
			return (bool) $main_site; // legacy, unplaceable: one blog prunes it, not every blog
		}
		return (int) $record['blog_id'] === self::current_blog_id();
	}

	/**
	 * Delete a snapshot (record + payload).
	 *
	 * @param string $id Snapshot id.
	 * @return bool
	 */
	public function delete( $id ) {
		$record = $this->get( $id );
		if ( null === $record ) {
			return false;
		}
		if ( ! empty( $record['payload_path'] ) && file_exists( $record['payload_path'] ) ) {
			wp_delete_file( $record['payload_path'] );
		}
		$this->delete_record_file( $id ); // the record AND its lock (SiteAgent#98)
		return true;
	}
}
