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
	 * @param string $path  Absolute path to the file.
	 * @param array  $extra Extra meta keys merged into the record before it is
	 *                      persisted (e.g. `write_seq` from an engine writer).
	 *                      The record's identity keys (`kind`, `target`,
	 *                      `bytes`, `existed`) are reserved: any of the same
	 *                      name in $extra is ignored, never overriding what
	 *                      this method establishes.
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_file( $path, array $extra = array() ) {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) || ! is_file( $path ) ) {
			return array( 'success' => false, 'error' => 'File not found: ' . (string) $path );
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return array( 'success' => false, 'error' => 'Unable to read file: ' . $path );
		}

		// The identity keys win: a caller-supplied 'kind', 'target', 'bytes'
		// or 'existed' in $extra must never override what this method
		// establishes — an 'existed' => false, for instance, would make a
		// future restore() dispatch to restore_created_file(), which DELETES
		// the file. This method never sets 'existed' itself, so the reserved
		// key is simply dropped from $extra rather than given a value here.
		$record = $this->persist(
			array_merge(
				array_diff_key( $extra, array_flip( array( 'kind', 'target', 'bytes', 'existed' ) ) ),
				array(
					'kind'   => 'file',
					'target' => $path,
					'bytes'  => strlen( $contents ),
				)
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
		$refused = $this->refuse_at_path( $path, true );
		if ( null !== $refused ) {
			return $refused;
		}
		$out = $this->with_target_lock(
			$path,
			function () use ( $path, $content ) {
				$refused = $this->refuse_at_path( $path, true ); // again, under the lock
				if ( null !== $refused ) {
					return $refused;
				}
				// NO FENCE KEY YET (Codex #101 round-6 P1): `replaced_with_sha256`
				// is stamped only once the write has landed — see Task 2. A
				// record that carries it before then is a record that asserts
				// something untrue for as long as the write might fail.
				$extra = array();
				$seq   = $this->next_write_seq( $path );
				if ( null !== $seq ) {
					$extra['write_seq'] = $seq;
				}
				$snap = $this->snapshot_file( $path, $extra );
				if ( empty( $snap['success'] ) ) {
					return $snap;
				}
				$replaced_ok = $this->replace_in_place( $path, $content );
				if ( true !== $replaced_ok ) {
					// Nothing to retire (Codex #101 round-6 P1). The record was
					// persisted WITHOUT `replaced_with_sha256`, so a write that
					// did not land leaves an UNFENCED record: restore refuses it
					// with `aura_snapshot_unfenced` and Aura mirrors it
					// unfenced and never offers it. The previous revision
					// stamped the hash up front and deleted-or-voided the record
					// on failure, which left the dangerous state reachable
					// whenever BOTH the delete and the void were refused — a
					// failure this shape cannot have, because the unsafe record
					// is never written in the first place.
					return array( 'success' => false, 'error' => $replaced_ok );
				}
				// THE FENCE IS STAMPED ONLY ONCE THE WRITE HAS LANDED. A stamp
				// that fails (a swept record, an unwritable directory) leaves
				// the record unfenced, which is the SAFE side: the write is a
				// fact and is reported as a success, and the record simply
				// cannot be restored from Aura.
				$sha    = hash( 'sha256', $content );
				$record = self::redact( $snap['snapshot'] );
				if ( $this->stamp_replaced_hash( $snap['snapshot']['id'], $sha ) ) {
					$record['replaced_with_sha256'] = $sha;
				}
				return array(
					'success'  => true,
					'snapshot' => $record,
					'bytes'    => strlen( $content ),
				);
			}
		);
		return $this->locked_answer( $out, $path );
	}

	/**
	 * Stamp the overwrite fence onto a record whose write has landed.
	 *
	 * @param string $id  Snapshot id.
	 * @param string $sha sha256 of the content that was written.
	 * @return bool True when the record now carries the fence.
	 */
	protected function stamp_replaced_hash( $id, $sha ) {
		$done = $this->with_record_lock(
			$id,
			function () use ( $id, $sha ) {
				$meta_path = $this->dir . basename( (string) $id ) . '.json';
				$record    = $this->get( $id );
				if ( ! is_array( $record ) || ! empty( $record['voided'] ) ) {
					return false; // retired while we were writing: leave it unfenced
				}
				$record['replaced_with_sha256'] = (string) $sha;
				$json                           = wp_json_encode( $record );
				if ( false === $json ) {
					return false;
				}
				// NEVER TRUNCATE THE RECORD IN PLACE (Codex #102 round-1 P2).
				// A caller treats a failed stamp as a safely UNFENCED record —
				// which assumes the record still exists. file_put_contents()
				// truncates first, so a short write or a full disk here left an
				// undecodable .json: the rollback record lost outright and its
				// payload orphaned, strictly worse than unfenced. Stage beside it
				// and rename over it, the way every other write in this class
				// lands. stage() fsyncs the bytes before it returns.
				$perms = @fileperms( $meta_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable mode falls back to the create default below.
				$mode  = false === $perms ? $this->create_mode() : ( $perms & 0777 );
				$tmp   = $this->stage( rtrim( $this->dir, '/' ), basename( $meta_path ), $json, $mode );
				if ( is_array( $tmp ) ) {
					return false;
				}
				if ( ! @rename( $tmp, $meta_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Replacing the record with its complete successor IS the point, atomically.
					$this->discard_stage( $tmp );
					return false;
				}
				return $this->sync_file( $meta_path );
			}
		);
		return true === $done;
	}

	/**
	 * A path that belongs to ANOTHER writer and could not be put back, set by
	 * remove_own_entry() and folded into the answer by publish_restored_bytes()
	 * (Codex #102 round-9 P1). Distinct from `moved_aside`, which names OUR own
	 * claim: this is somebody else's live file, displaced by a claim of ours.
	 *
	 * @var string
	 */
	private $stranded = '';

	/** drop_linked_claim(): the claim was redundant and is gone. */
	const CLAIM_DROPPED = 'dropped';

	/** drop_linked_claim(): the claim still names the file and is kept, to be reported. */
	const CLAIM_KEPT = 'kept';

	/** drop_linked_claim(): a racer took the path inside the window PHP cannot close, and the name removed was the file's last. Unrecoverable — but never silent. */
	const CLAIM_LOST = 'lost';

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
		$sha  = hash( 'sha256', $content );
		$meta = array(
			'kind'            => 'file',
			'target'          => $path,
			'existed'         => false,
			'expected_sha256' => $sha,
			'staged'          => $tmp,
			'bytes'           => strlen( $content ),
		);
		$seq  = $this->next_write_seq( $path );
		if ( null !== $seq ) {
			$meta['write_seq'] = $seq;
		}
		$record = $this->persist_create_record( $meta );
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
			$this->after_publish( $path );
			$landed = $this->hash_regular_file( $path );
			if ( ! is_string( $landed ) || ! hash_equals( $sha, $landed ) ) {
				if ( 'link' === $this->last_publish_mode ) {
					// The stage is a second NAME of the altered inode, not a copy
					// (Codex #100 round-1 P2): the intended content is staged
					// afresh so what is called kept is what should have landed.
					$this->discard_stage( $tmp );
					$fresh = $this->stage( $dir, $name, $content );
					$tmp   = is_string( $fresh ) ? $fresh : null;
				}
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
	 * @param string   $dir     Target's directory.
	 * @param string   $name    Target's basename.
	 * @param string   $content Complete content.
	 * @param int|null $mode    The mode the stage ends with; null = the create mode.
	 * @return string|array The staged path, or { success: false, error }.
	 */
	protected function stage( $dir, $name, $content, $mode = null ) {
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

		// Born owner-only: the bytes are nobody else's to read while they are
		// being written (Codex #100 round-5 P1 — a 0600 secret staged at the
		// 0644 default was readable by any local account until the chmod
		// below). Widened to the wanted mode only once complete.
		$was = umask( 0177 );
		$fh  = @fopen( $tmp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An exclusive create is the point: the name is ours or the call fails, and the failure is reported, not thrown.
		umask( $was );
		if ( false === $fh ) {
			return array( 'success' => false, 'error' => 'Unable to stage file beside the target: ' . $dir );
		}
		// A default POSIX ACL on the directory makes the kernel ignore the umask
		// (the same fact write_exclusively() guards): the entry can be born
		// wider than 0600. Tighten and VERIFY on the handle before the first
		// byte; a mode that will not tighten is a refusal, nothing staged
		// (Codex #100 round-7 P1).
		if ( ! $this->stage_is_private( $fh, $tmp ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->discard_stage( $tmp );
			return array( 'success' => false, 'error' => 'Unable to make the staged file private before writing it (a default ACL?): ' . $tmp );
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
		if ( ! $this->secure_stage( $tmp, $mode ) ) {
			$this->discard_stage( $tmp );
			return array( 'success' => false, 'error' => 'Unable to set permissions on the staged file: ' . $tmp );
		}
		return $tmp;
	}

	/**
	 * Whether a freshly created stage is owner-only — tightened with chmod()
	 * when it is not, and read back through the handle to be sure.
	 *
	 * @param resource $fh  The open handle.
	 * @param string   $tmp Its path.
	 * @return bool
	 */
	private function stage_is_private( $fh, $tmp ) {
		$stat = fstat( $fh );
		if ( ! is_array( $stat ) ) {
			return false;
		}
		if ( 0600 === ( (int) $this->mode_of( $fh, $stat ) & 0777 ) ) {
			return true;
		}
		if ( ! function_exists( 'chmod' ) || ! @chmod( $tmp, 0600 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Our own exclusively created file; a refusal is answered, not surfaced.
			return false;
		}
		clearstatcache( true, $tmp );
		$stat = fstat( $fh );
		return is_array( $stat ) && 0600 === ( (int) $this->mode_of( $fh, $stat ) & 0777 );
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
	 * @param string   $tmp  Staged path.
	 * @param int|null $mode The mode to set; null = the create mode.
	 * @return bool
	 */
	protected function secure_stage( $tmp, $mode = null ) {
		if ( ! function_exists( 'chmod' ) ) {
			return false;
		}
		return (bool) @chmod( $tmp, null === $mode ? ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) : (int) $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- A refusal is an answer this method returns, not a warning to surface; $wp_filesystem is not initialised on this path, and the mode of a file this call exclusively created is not a filesystem abstraction concern.
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
			$this->heartbeat_locks();
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
	 * Put a claimed file back at its path with a hard link: no-clobber, and
	 * the same inode, so a writer holding the file keeps writing to the file
	 * that is back at its path.
	 *
	 * Protected so a test can model a host where link() EXISTS but the
	 * filesystem or a policy refuses it (Codex #102 round-1 P1) — which is a
	 * different fact from link_available() being false.
	 *
	 * @param string $claim  The claimed file.
	 * @param string $target Its original path.
	 * @return bool
	 */
	protected function link_into_place( $claim, $target ) {
		return (bool) @link( $claim, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EEXIST is the expected refusal, classified by the caller.
	}

	/**
	 * Remove a claim that was just linked back — ONLY while the path still
	 * names the file we linked.
	 *
	 * link() leaves TWO names for one inode, and the delete that follows is a
	 * second step on a name. A writer who replaces the target in between
	 * leaves the CLAIM as the last remaining name, so deleting it destroys the
	 * very file the put-back existed to protect — and silently, because the
	 * answer would report it safely back (Codex #102 round-5 P1). Proving
	 * identity before addressing a path by name is the rule remove_own_entry()
	 * already follows; this is the same rule on the other side of the link.
	 *
	 * @param string $claim  The claim, now a second name for the file.
	 * @param string $target The path it was linked to.
	 * @return bool True when the path still holds our file (the claim was then
	 *              removed, best-effort — the caller reports a name that stays).
	 */
	private function drop_linked_claim( $claim, $target ) {
		// PHP CACHES stat() PER PATH, and callers reach here having already
		// asked is_link()/is_dir() about this very name — so a cached entry
		// here predates the link() that just ran, and its nlink still reads 1.
		// Every check below is about state that changed a statement ago.
		clearstatcache( true, $claim );
		clearstatcache( true, $target );
		$ours = @stat( $claim ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		$now  = @stat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $ours ) || ! is_array( $now ) || $now['ino'] !== $ours['ino'] || $now['dev'] !== $ours['dev'] ) {
			return self::CLAIM_KEPT; // the path stopped being our file: the claim is its LAST name
		}
		// THE LINK COUNT IS THE CLOSER CHECK (Codex #102 round-6 P1). This
		// claim is only redundant while the inode still carries the target's
		// name too. Reading nlink off the stat we already hold is nearer the
		// unlink than a second lookup by name, so it narrows the window that
		// cannot be closed — a racer who has already unlinked the target is
		// caught here, and the file keeps its last name.
		if ( isset( $ours['nlink'] ) && (int) $ours['nlink'] < 2 ) {
			return self::CLAIM_KEPT;
		}
		$this->before_claim_drop( $claim, $target );
		wp_delete_file( $claim );
		if ( self::path_present( $claim ) ) {
			return self::CLAIM_KEPT; // the name could not be removed; the caller says where it is
		}
		// PHP CANNOT MAKE A CHECK AND AN UNLINK ONE OPERATION — that is the
		// residual the plan documents as (a), and no ordering of calls closes
		// it. What IS fixable is the SILENCE: losing this race used to answer a
		// clean put-back for a file that no longer exists anywhere. Re-read the
		// path, and when it stopped being the file we linked, say so.
		clearstatcache( true, $target );
		$after = @stat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $after ) || $after['ino'] !== $ours['ino'] || $after['dev'] !== $ours['dev'] ) {
			return self::CLAIM_LOST;
		}
		return self::CLAIM_DROPPED;
	}

	/**
	 * Seam between proving the claim is redundant and removing it. Nothing in
	 * production; a test models a racer replacing the path in the one window
	 * PHP leaves open here.
	 *
	 * @param string $claim  The claim.
	 * @param string $target The path it was linked to.
	 */
	protected function before_claim_drop( $claim, $target ) {
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
		$out  = array( 'success' => false, 'error' => $error );
		$done = $this->with_record_lock(
			$id,
			function () use ( $id ) {
				return $this->void_create_record( $id );
			}
		);
		if ( true !== $done ) {
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
		// Under the record's lock (Codex #100 round-6 P2): a delete() that ran
		// between the read and the rewrite would otherwise be undone by the
		// rewrite. Contended → the record stays as it is, and the answer says
		// so; its hash does not match the file (that is why we are here), so a
		// restore refuses it either way.
		$voided = $this->with_record_lock(
			$id,
			function () use ( $id ) {
				return $this->void_record_in_place( $id, array( 'interrupted' => true ) );
			}
		);
		if ( true === $voided ) {
			$out['detail'] .= '; record ' . $id . ' voided and marked interrupted, '
				. ( is_string( $tmp ) ? 'staged bytes kept at ' . $tmp : 'the intended content could not be kept' );
		} elseif ( null === $voided ) {
			$out['detail'] .= '; record ' . $id . ' could not be locked and was left as it is — its hash does not match the file, so restore refuses it';
		}
		return $out;
	}

	/**
	 * Seam between the publish and the verification of what landed. Nothing
	 * in production; a test models a writer on the published inode here.
	 *
	 * @param string $path Target path.
	 */
	protected function after_publish( $path ) {
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
		return $this->with_lock(
			'path-' . sha1( (string) $path ),
			function () use ( $path, $work ) {
				$this->after_target_lock( $path );
				return $work();
			},
			$this->target_lock_tries()
		);
	}

	/**
	 * Seam: the moment the target lock is held, before the work. Nothing in
	 * production; a test models what changed at the path while we waited.
	 *
	 * @param string $path Target path.
	 */
	protected function after_target_lock( $path ) {
	}

	/**
	 * The per-target write sequence: a value that strictly increases for
	 * successive writes to ONE path, so two writes to one file can be ordered
	 * after the fact (Aura#520 §3.1). Aura orders a rollback by it, because
	 * nothing it observes does: it runs its render validation after the site
	 * answers and stamps the action's finish time only then, so the earlier of
	 * two concurrent writes can finish later.
	 *
	 * Callers hold the target lock, so the read-modify-write below is
	 * serialised per path. The value is `max(previous + 1, now)` in
	 * microseconds: monotonic whatever the wall clock does, and still roughly
	 * chronological across different paths.
	 *
	 * @param string $path The target path.
	 * @return int|null The sequence, or null when it cannot be established —
	 *                  the record then carries none and Aura fails closed.
	 */
	protected function next_write_seq( $path ) {
		if ( PHP_INT_SIZE < 8 ) {
			return null; // microseconds do not fit in a 32-bit int
		}
		// A crash between stage() and rename() below leaves a `.aura-create-*`
		// in the SNAPSHOTS directory, and nothing else ever sweeps it: the
		// create sweeper only visits a create target's own directory (Codex
		// #101 round-3 P2). reconcile_stage() answers true for a stray with no
		// record, so these are removed once older than STAGE_MAX_AGE, at most
		// STAGE_SWEEP_CAP per call.
		$this->sweep_stale_stages( rtrim( $this->dir, '/' ) );

		$sidecar = $this->dir . 'path-' . sha1( (string) $path ) . '.seq';
		$prev    = 0;
		if ( file_exists( $sidecar ) ) {
			$raw = @file_get_contents( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A sidecar this class owns; an unreadable one is an answer (null), not a warning.
			if ( ! is_string( $raw ) || 1 !== preg_match( '/^[0-9]+$/', trim( $raw ) ) ) {
				return null; // present but unreadable: never invent an order
			}
			$digits = ltrim( trim( $raw ), '0' );
			// DECIMAL IS NOT THE SAME AS IN RANGE (Codex #101 round-1 P2). A
			// value above PHP_INT_MAX saturates on the cast, `$prev + 1` then
			// becomes a FLOAT, the record gets a non-integer `write_seq`, and
			// the sidecar is rewritten in exponent notation — unreadable on the
			// next write. Compare as decimal strings before casting anything,
			// and refuse the value that cannot be incremented as an int.
			$max = (string) PHP_INT_MAX;
			if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) >= 0 ) ) {
				return null;
			}
			$prev = (int) $digits;
		}
		$now = $this->now_micros();
		$seq = $prev >= $now ? $prev + 1 : $now;

		// Written BEFORE the record, by the same stage-and-rename every other
		// write here uses: a reader never sees a half-written sequence.
		$tmp = $this->stage( rtrim( $this->dir, '/' ), basename( $sidecar ), (string) $seq, 0600 );
		if ( is_array( $tmp ) ) {
			return null;
		}
		if ( ! @rename( $tmp, $sidecar ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Replacing the sidecar IS the point, atomically.
			$this->discard_stage( $tmp );
			return null;
		}
		return $seq;
	}

	/**
	 * Seam: the clock, in microseconds. A test steps it backwards.
	 *
	 * @return int
	 */
	protected function now_micros() {
		return (int) round( microtime( true ) * 1000000 );
	}

	/**
	 * What overwrite_file() and the existing-file restore refuse at a path:
	 * nothing there, a symlink (a rename would replace the LINK, and the
	 * snapshot would hold its destination's bytes), not a regular file.
	 * Asked before the lock for a cheap answer and AGAIN under it — the path
	 * can change while this request waits (Codex #100 round-4 P2).
	 *
	 * @param string $path         Path.
	 * @param bool   $must_exist   Whether an absent path is a refusal.
	 * @return array|null { success: false, error } or null when acceptable.
	 */
	private function refuse_at_path( $path, $must_exist ) {
		if ( ! self::path_present( $path ) ) {
			return $must_exist ? array( 'success' => false, 'error' => 'File not found: ' . $path ) : null;
		}
		if ( is_link( $path ) ) {
			return array( 'success' => false, 'error' => 'Target is a symlink: ' . $path );
		}
		if ( ! is_file( $path ) ) {
			return array( 'success' => false, 'error' => 'Target is not a regular file: ' . $path );
		}
		return null;
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
			return array(
				'success' => false,
				'code'    => 'aura_path_locked',
				'error'   => 'locked',
				'detail'  => 'another write to this path is in progress: ' . $path,
			);
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
		if ( $this->delete_record_file( $id ) ) {
			return true;
		}
		return $this->void_record_in_place( $id ); // the lock file is still in place for this
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
		unset( $record['expected_sha256'], $record['staged'], $record['replaced_with_sha256'] ); // the overwrite fence goes with the create's (Codex #101 round-5 P1)
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
		$lock = $base . '.lock';
		$fh   = null;
		for ( $i = 0; $i < $tries; $i++ ) {
			$fh = @fopen( $lock, 'cb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A lock file this class owns; a refusal is answered, not surfaced.
			if ( false === $fh ) {
				return self::LOCK_UNAVAILABLE;
			}
			if ( flock( $fh, LOCK_EX | LOCK_NB ) ) {
				// The lock is the INODE, and a holder may unlink the file under
				// the lock (delete_record_file()). A waiter that opened the old
				// inode would then "hold" a lock nobody else can see while a
				// newcomer holds the new one (Codex #100 round-1 P1). Prove the
				// file at the path is still the one held, else start over.
				$mine = fstat( $fh );
				$now  = @stat( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer: reopen.
				if ( is_array( $mine ) && is_array( $now ) && $now['ino'] === $mine['ino'] && $now['dev'] === $mine['dev'] ) {
					break;
				}
				flock( $fh, LOCK_UN );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$fh = null;
			usleep( 20000 );
		}
		if ( null === $fh ) {
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
		try {
			$token = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$token = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		$mine = $dir . '/' . $token;
		// The lock is taken by RENAMING a prepared directory onto the lock path:
		// the token is already inside, so a lock directory is never empty for
		// anyone to rmdir(), and rename() onto an existing non-empty directory
		// fails — the atomic no-clobber take (Codex #100 round-3 P1: a breaker
		// that had judged the old instance dead could otherwise wipe a fresh
		// holder's token in the moment before it was written).
		$prep = $dir . '.tmp-' . $token;
		if ( ! @mkdir( $prep, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A refusal is an answer: no lock can be created here.
			return self::LOCK_UNAVAILABLE;
		}
		$identity = $this->holder_identity();
		$wrote    = @file_put_contents( $prep . '/' . $token, $identity ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Inside a directory this call just created.
		if ( false === $wrote || $wrote !== strlen( $identity ) ) {
			// A short or refused token write (disk full) leaves a file the
			// rmdir() would trip on: the whole preparation goes (Codex #100
			// round-5 P2).
			self::discard_preparation( $prep );
			return self::LOCK_UNAVAILABLE;
		}
		$held = false;
		for ( $i = 0; $i < $tries && ! $held; $i++ ) {
			$held = @rename( $prep, $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- EEXIST/ENOTEMPTY is the expected refusal: someone holds it. Atomic, and never onto a non-empty directory.
			if ( $held ) {
				break;
			}
			if ( 0 === $i ) {
				// Contended: while waiting, sweep preparations a kernel-killed
				// request left behind (a live one exists for microseconds, so
				// any older than LOCK_STALE_AFTER is nobody's) — Codex #100
				// round-5 P2.
				foreach ( (array) @glob( $dir . '.tmp-*', GLOB_ONLYDIR ) as $stray ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone meanwhile is an answer.
					$at = @filemtime( $stray ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone meanwhile is an answer.
					if ( $stray !== $prep && false !== $at && $at < time() - self::LOCK_STALE_AFTER ) {
						self::discard_preparation( $stray );
					}
				}
			}
			if ( ! is_dir( $dir ) ) {
				// Not there after the refusal: either the holder released between
				// the two calls (the ordinary race — try again, Codex #100
				// round-2 P2) or no lock can be created under the snapshots
				// directory at all.
				if ( ! is_dir( $this->dir ) || ! is_writable( $this->dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The snapshots directory this class owns; $wp_filesystem is not initialised on this path.
					self::discard_preparation( $prep );
					return self::LOCK_UNAVAILABLE;
				}
				continue;
			}
			$dead = $this->dead_lock_tokens( $dir );
			if ( null !== $dead ) {
				// Reclaim ONLY the instance inspected: its tokens go, then the
				// directory — which fails the moment any other holder's token is
				// inside, so a breaker that lost the race takes nothing from the
				// winner. The next rename() to land holds it.
				foreach ( $dead as $stale ) {
					@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- A dead holder's token, the one inspected.
				}
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Refused (non-empty) when a live holder's token is inside, which is exactly right.
				continue;
			}
			usleep( 20000 );
		}
		if ( ! $held ) {
			self::discard_preparation( $prep );
			return null;
		}
		// The token names THIS holder — its process, so a breaker can ask the
		// kernel whether it is alive — and a directory holding another holder's
		// token cannot be rmdir()ed, so a holder that was broken as stale and
		// comes back cannot take the replacement's lock away with it.
		self::$mkdir_locks_held[ $dir ] = $mine;
		if ( ! self::$release_on_shutdown && function_exists( 'register_shutdown_function' ) ) {
			// A fatal error skips every finally; a shutdown function still runs.
			// Only a process the kernel killed leaves a directory behind — and
			// that one the liveness check below can see is dead.
			self::$release_on_shutdown = true;
			register_shutdown_function( array( __CLASS__, 'release_mkdir_locks' ) );
		}
		try {
			return $work();
		} finally {
			self::release_mkdir_lock( $dir );
		}
	}

	/**
	 * Remove a preparation directory and whatever token it holds.
	 *
	 * @param string $prep The `.lock.d.tmp-*` directory.
	 */
	private static function discard_preparation( $prep ) {
		foreach ( (array) @glob( $prep . '/*' ) as $inside ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone meanwhile is an answer.
			@unlink( $inside ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Our own (or an abandoned) token.
		}
		@rmdir( $prep ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own (or an abandoned) preparation.
	}

	/** mkdir() lock directories this process holds right now: dir => token file. */
	private static $mkdir_locks_held = array();

	/** Whether release_mkdir_locks() is registered for shutdown. */
	private static $release_on_shutdown = false;

	/**
	 * Release one held mkdir() lock: the token, then the directory.
	 *
	 * @param string $dir Lock directory.
	 */
	private static function release_mkdir_lock( $dir ) {
		if ( ! isset( self::$mkdir_locks_held[ $dir ] ) ) {
			return;
		}
		$mine = self::$mkdir_locks_held[ $dir ];
		unset( self::$mkdir_locks_held[ $dir ] );
		@unlink( $mine ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Our token; gone already if we were broken as stale.
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Release; refused (non-empty) when a replacement holder's token is inside, which is exactly right.
	}

	/**
	 * Release every mkdir() lock this process still holds — the shutdown
	 * path, reached after a fatal error that skipped the finally blocks.
	 */
	public static function release_mkdir_locks() {
		foreach ( array_keys( self::$mkdir_locks_held ) as $dir ) {
			self::release_mkdir_lock( $dir );
		}
	}

	/**
	 * Tell every mkdir() lock this process holds that its holder is alive.
	 * Called from the write loop: extra evidence, cheap, for a host where
	 * the kernel cannot be asked (no /proc, no posix). No-op under flock().
	 */
	private function heartbeat_locks() {
		foreach ( array_keys( self::$mkdir_locks_held ) as $dir ) {
			@touch( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Our own lock directory; a refusal only shortens the grace.
		}
	}

	/**
	 * What a token records about its holder: the process id, and on Linux the
	 * process start time from /proc, so a recycled pid is not mistaken for the
	 * holder. Seam: a test writes a token for a process that is not there.
	 *
	 * @return string "pid:starttime:host" (starttime empty where unknown).
	 */
	protected function holder_identity() {
		// A hardened host may disable these beside flock() (Codex #100 round-8
		// P1): pid 0 reads as "unknown holder" to holder_alive(), so the lease
		// (heartbeat + age) governs instead of a fatal.
		$pid   = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
		$start = $pid > 0 ? $this->proc_start_time( $pid ) : null;
		return $pid . ':' . ( null === $start ? '' : $start ) . ':' . self::host_identity();
	}

	/**
	 * Which machine (and boot) a pid belongs to. wp-content can be shared
	 * between hosts or containers (NFS, a mounted volume): a pid from another
	 * PID namespace means nothing here, and asking the local kernel about it
	 * would call a live remote holder dead (Codex #100 round-5 P1). The
	 * hostname plus Linux's boot_id, hashed.
	 *
	 * @return string
	 */
	private static function host_identity() {
		$host = function_exists( 'gethostname' ) ? (string) gethostname() : '';
		$boot = @file_get_contents( '/proc/sys/kernel/random/boot_id' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Not a URL; absent off Linux, which is an answer.
		return substr( sha1( $host . '|' . ( is_string( $boot ) ? trim( $boot ) : '' ) ), 0, 16 );
	}

	/**
	 * A process's start time from /proc/<pid>/stat (field 22), or null where
	 * /proc does not answer — absent process OR unreadable record; the caller
	 * never reads null as "gone". Seam: a test models an unreadable /proc.
	 *
	 * @param int $pid Process id.
	 * @return string|null
	 */
	protected function proc_start_time( $pid ) {
		$stat = @file_get_contents( '/proc/' . (int) $pid . '/stat' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Not a URL; absent off Linux, which is an answer.
		if ( ! is_string( $stat ) ) {
			return null;
		}
		$after = strrpos( $stat, ')' ); // the comm field may hold spaces and parentheses
		if ( false === $after ) {
			return null;
		}
		$fields = preg_split( '/\s+/', trim( substr( $stat, $after + 1 ) ) );
		return isset( $fields[19] ) ? (string) $fields[19] : null; // field 22 overall = index 19 after state
	}

	/**
	 * The tokens of a mkdir() lock directory left by a holder that is gone —
	 * empty when the lock is live. Dead means: SILENT for LOCK_STALE_AFTER
	 * (no heartbeat, no creation) AND not provably alive — the token's
	 * process is not running, or is a different process under a recycled
	 * pid. Where the kernel cannot be asked (no /proc and no posix_kill) age
	 * alone decides, and the heartbeat is what keeps a slow holder's
	 * directory young (Codex #100 round-2 P1: blocking I/O — fsync(),
	 * hash_file() — cannot heartbeat, so liveness must come from the kernel
	 * where it can). The caller reclaims exactly these tokens (round-3 P1).
	 *
	 * @param string $dir Lock directory.
	 * @return string[]|null Token paths to reclaim (possibly none); null = not dead.
	 */
	private function dead_lock_tokens( $dir ) {
		$at = @filemtime( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The holder may release between the two calls; that is an answer.
		if ( false === $at || $at >= time() - self::LOCK_STALE_AFTER ) {
			return null;
		}
		$tokens = (array) @glob( $dir . '/*' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Released meanwhile is an answer.
		if ( array() === $tokens ) {
			// Aged and empty: a lock directory is never taken empty (the token
			// arrives with it), so this is a dead holder whose token is gone —
			// or a breaker mid-reclaim. Either way rmdir() alone decides.
			return array();
		}
		foreach ( $tokens as $token ) {
			$alive = $this->holder_alive( (string) @file_get_contents( $token ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A token this class wrote; unreadable is "unknown".
			if ( true === $alive ) {
				return null; // a slow holder, not a dead one
			}
		}
		return $tokens; // every token's holder is gone, or cannot be asked about: age decides — these, and only these, are reclaimed
	}

	/**
	 * Ask the kernel whether a token's holder is still running.
	 *
	 * Seam: a test models a host that cannot read another process's record.
	 *
	 * @param string $identity "pid:starttime:host" as holder_identity() wrote it.
	 * @return bool|null true alive, false dead, null unknowable here.
	 */
	protected function holder_alive( $identity ) {
		$parts = explode( ':', $identity, 3 );
		$pid   = (int) $parts[0];
		if ( $pid <= 0 ) {
			return null;
		}
		if ( isset( $parts[2] ) && '' !== $parts[2] && $parts[2] !== self::host_identity() ) {
			// Another machine's process: this kernel cannot be asked about it.
			// Unknown — the lease (heartbeat + age) is what governs a remote
			// holder, and a shared wp-content without flock() has nothing better.
			return null;
		}
		$start = isset( $parts[1] ) ? (string) $parts[1] : '';
		$now   = $this->proc_start_time( $pid );
		if ( is_string( $now ) ) {
			return '' === $start || $now === $start; // /proc answered: alive, unless a recycled pid (different start time)
		}
		// /proc did not answer for this pid: the process may be gone, or its
		// record unreadable here (open_basedir, hidepid, another user's pool).
		// Only a positive answer counts (Codex #100 round-4 P1): posix_kill(0)
		// says alive; EPERM says alive-and-not-ours; ESRCH says gone; anything
		// else — and no posix at all — is unknown, and age decides.
		if ( function_exists( 'posix_kill' ) ) {
			if ( @posix_kill( $pid, 0 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Signal 0 sends nothing; the errno below is the answer.
				return true;
			}
			$errno = function_exists( 'posix_get_last_error' ) ? (int) posix_get_last_error() : 0;
			if ( 1 === $errno ) { // EPERM: it exists, under another user
				return true;
			}
			if ( 3 === $errno ) { // ESRCH: no such process
				return false;
			}
			return null;
		}
		// No posix. /proc can establish absence only where it shows other
		// processes at all (hidepid hides them): pid 1 is always there.
		if ( is_dir( '/proc/1' ) && ! @file_exists( '/proc/' . $pid ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Under open_basedir this is a refusal, read as unknown below.
			return is_readable( '/proc/1/stat' ) ? false : null;
		}
		return null;
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
	 * the record first and cannot remove one that failed to read back. Callers
	 * hold the record's lock.
	 *
	 * @param string $id Snapshot id.
	 * @return bool True when the record file is gone (and its lock with it).
	 */
	private function delete_record_file( $id ) {
		$meta_path = $this->dir . basename( (string) $id ) . '.json';
		if ( file_exists( $meta_path ) ) {
			wp_delete_file( $meta_path );
		}
		if ( file_exists( $meta_path ) ) {
			// The record is still there: whatever the caller does next (void it
			// in place) happens under a lock that must stay reachable by name,
			// so the lock file STAYS (Codex #100 round-6 P2).
			return false;
		}
		// The flock file goes with the record — safe ONLY because every caller
		// holds this record's lock, with_lock() re-checks the inode after
		// acquiring (Codex #100 round-1 P1), and nothing about this record is
		// written after this point. A mkdir() lock directory is not touched
		// here: its holder releases it, and a crashed holder's is broken by
		// the next taker.
		$lock = $this->dir . basename( (string) $id ) . '.lock';
		if ( file_exists( $lock ) ) {
			wp_delete_file( $lock );
		}
		return true;
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
				$actual = $this->hash_regular_file( $target );
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
	 * Put an existing-file snapshot's bytes back at its path: under the
	 * target's lock, by the same stage-and-rename the engine overwrites with
	 * — never an in-place write into an inode a concurrent create or
	 * overwrite is filling (Codex #100 round-1 P1). A symlink at the path is
	 * refused: a rename would replace the link itself.
	 *
	 * @param string $target Path.
	 * @param string $bytes  The snapshot's payload.
	 * @param array  $record The record, for its overwrite fence.
	 * @return array { success, error? }
	 */
	private function restore_existing_file( $target, $bytes, array $record = array() ) {
		if ( '' === $target ) {
			return array( 'success' => false, 'error' => 'Snapshot record carries no target.' );
		}
		// THE RECORD IS JUDGED BEFORE THE PATH IS (Codex #102 round-2 P2). A
		// voided or unfenced record can never be restored, whatever is at the
		// path now — so answering `aura_file_changed_since` for a symlink or a
		// directory there told the caller to look again at a file, when the
		// fact it needs is that THE RECORD is unusable. These checks read the
		// record only and need no lock; the locked function re-applies them as
		// the authority once it holds one.
		$unusable = $this->record_refusal( $record );
		if ( null !== $unusable ) {
			return $unusable;
		}
		$refused = $this->refuse_at_path( $target, false );
		if ( null !== $refused ) {
			return $this->restore_refusal( $refused );
		}
		$out = $this->with_target_lock(
			$target,
			function () use ( $target, $bytes, $record ) {
				return $this->restore_existing_file_locked( $target, $bytes, $record );
			}
		);
		return $this->locked_answer( $out, $target );
	}

	/**
	 * A path refusal, on the RESTORE path, is a designated "changed since"
	 * refusal: the file the record was taken from is not what is at the path
	 * now. `refuse_at_path()` itself is left alone — it is shared with the
	 * write paths, where a symlink is not a changed-since fact and must not
	 * become a 409 (Codex #101 round-1 P1). The wording is kept exactly as it
	 * is; only the code is added.
	 *
	 * @param array $refused refuse_at_path()'s answer.
	 * @return array
	 */
	private function restore_refusal( array $refused ) {
		$refused['code'] = 'aura_file_changed_since';
		return $refused;
	}

	/**
	 * The refusal a RECORD earns on its own, before anything at the path is
	 * looked at: retired because its write never landed, or carrying no fence
	 * to prove the file is unchanged. Both are permanent properties of the
	 * record — no state of the target makes either restorable — so they are
	 * decided first and never dressed up as a changed-since fact (Codex #102
	 * round-2 P2).
	 *
	 * @param array $record The file record.
	 * @return array|null The refusal, or null when the record is usable.
	 */
	private function record_refusal( array $record ) {
		if ( ! empty( $record['voided'] ) ) {
			// Retired because its write never landed (Codex #101 round-5 P1):
			// a designated refusal, not an unfenced record.
			return array(
				'success' => false,
				'code'    => 'aura_snapshot_voided',
				'error'   => 'The rollback record for this write was retired because the write did not land.',
			);
		}
		if ( '' === ( isset( $record['replaced_with_sha256'] ) ? (string) $record['replaced_with_sha256'] : '' ) ) {
			return array(
				'success' => false,
				'code'    => 'aura_snapshot_unfenced',
				'error'   => 'This snapshot does not record what replaced the file, so a restore cannot prove the file is unchanged; nothing was written.',
			);
		}
		return null;
	}

	/**
	 * The fenced restore proper, under the target's lock (Aura#520 §3.1).
	 *
	 * An in-place hash settles every answer that writes nothing. To WRITE, the
	 * path is claimed by rename() and the claimed file is re-verified — the
	 * lock holds SiteAgent's writers only, so the bytes that are verified and
	 * the bytes that are replaced must be one inode (Codex #101 round-1 P1).
	 *
	 * @param string $target The path.
	 * @param string $bytes  The snapshot's payload — what goes back.
	 * @param array  $record The record, for its fence.
	 * @return array { success, already?, code?, error?, detail?, moved_aside? }
	 */
	private function restore_existing_file_locked( $target, $bytes, array $record ) {
		$unusable = $this->record_refusal( $record );
		if ( null !== $unusable ) {
			return $unusable; // re-applied under the lock; the caller checked it first
		}
		$replaced = (string) $record['replaced_with_sha256'];
		$refused  = $this->refuse_at_path( $target, false ); // again, under the lock
		if ( null !== $refused ) {
			return $this->restore_refusal( $refused );
		}
		$current = $this->hash_regular_file( $target );
		if ( ! is_string( $current ) ) {
			return $this->changed_since( 'the file is gone, or is no longer a regular file' );
		}
		if ( hash_equals( hash( 'sha256', $bytes ), $current ) ) {
			return array( 'success' => true, 'already' => true ); // already back; nothing claimed, nothing written
		}
		if ( ! hash_equals( $replaced, $current ) ) {
			return $this->changed_since( 'the file no longer holds the content this write left there' );
		}
		// An executable on a host without link() cannot be republished at all
		// (fopen() cannot create execute bits — SiteAgent#96/#97), and claiming
		// it would strand it aside. The in-place hash above IS its verification;
		// it is replaced the 2.17.2 way, with that window as the documented
		// residual. Every other case claims first.
		$perms = @fileperms( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file is there (hashed above); a false reads as "no execute bits".
		if ( ! $this->link_available() && false !== $perms && 0 !== ( $perms & 0111 ) ) {
			$done = $this->replace_in_place( $target, $bytes );
			return true === $done
				? array( 'success' => true )
				: array( 'success' => false, 'error' => 'Failed to write file: ' . $target . ' (' . $done . ')' );
		}
		return $this->publish_restored_bytes( $target, $bytes, $replaced );
	}

	/** The changed-since refusal, in one place. */
	private function changed_since( $detail, array $extra = array() ) {
		return array_merge(
			array(
				'success' => false,
				'code'    => 'aura_file_changed_since',
				'error'   => 'file_changed_since',
				'detail'  => $detail,
			),
			$extra
		);
	}

	/**
	 * Claim the path, re-verify what we hold, and publish the old bytes into
	 * it — no-clobber, so a file that arrives after the claim is never
	 * replaced. The claimed file is put back on every refusal, by the same
	 * primitives restore_created_file_locked() uses.
	 *
	 * @param string $target   The path.
	 * @param string $bytes    The payload to put back.
	 * @param string $replaced The hash the file must still have.
	 * @return array
	 */
	private function publish_restored_bytes( $target, $bytes, $replaced ) {
		$this->stranded = ''; // one restore at a time; never carry another's
		$out            = $this->publish_restored_bytes_inner( $target, $bytes, $replaced );
		if ( '' !== $this->stranded && is_array( $out ) ) {
			// Every exit of the inner call passes through here, so no branch can
			// forget to report a displaced file (Codex #102 round-9 P1).
			$out['stranded'] = $this->stranded;
		}
		$this->stranded = '';
		return $out;
	}

	/**
	 * publish_restored_bytes()'s body. Wrapped only so that a file belonging to
	 * ANOTHER writer, displaced by one of our claims and unable to go back, is
	 * named on whichever answer this returns.
	 *
	 * @param string $target   The path.
	 * @param string $bytes    The snapshot's payload.
	 * @param string $replaced The fence.
	 * @return array
	 */
	private function publish_restored_bytes_inner( $target, $bytes, $replaced ) {
		// STAGE FIRST, while the target is still live (Codex #101 round-2 P2):
		// staging writes the whole payload, and doing it after the claim left
		// the pathname absent for the length of that write. The mode is read
		// from the live file for the same reason.
		$mode = @fileperms( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file is there (hashed by the caller); a false falls back to the create mode.
		$tmp  = $this->stage( dirname( $target ), basename( $target ), $bytes, false === $mode ? null : ( $mode & 0777 ) );
		if ( is_array( $tmp ) ) {
			return array( 'success' => false, 'error' => (string) $tmp['error'] ); // nothing claimed, nothing moved
		}

		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		$claim = dirname( $target ) . '/.aura-restore-' . $suffix; // opaque, never a .php name
		if ( ! @rename( $target, $claim ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- The claim IS the point: atomic, inode-preserving.
			$this->discard_stage( $tmp );
			return self::path_present( $target )
				? array( 'success' => false, 'error' => 'Unable to claim file for restore: ' . $target )
				: $this->changed_since( 'the file was removed while the restore was being prepared' );
		}
		$this->after_claim( $claim, $target );

		// A RACED NON-FILE IS PUT STRAIGHT BACK (Codex #101 round-7 P1). A
		// writer can replace the verified file with a DIRECTORY — or a symlink
		// — between the in-place hash and the rename above, and the rename
		// moves whatever is there to the claim name. put_claim_back() can only
		// hard-link or copy a REGULAR file, so it would leave a directory
		// stranded under an opaque name with the target missing, which is the
		// opposite of the exact-match contract: a replacement we did not write
		// is left exactly where it is. rename() is the only primitive that
		// moves such an entry back whole.
		if ( is_link( $claim ) || ! is_file( $claim ) ) {
			$this->discard_stage( $tmp );
			// PUT IT BACK ONLY WHILE THE PATH IS STILL FREE (Codex #101
			// round-8 P1). rename() CLOBBERS on POSIX, and PHP has no atomic
			// no-clobber move for a directory or a symlink — link() cannot
			// name a directory and follows a symlink to its destination. So a
			// second writer who took the path after our claim would be
			// destroyed by an unconditional put-back. When the path is taken,
			// the entry stays under its claim name and the answer says where.
			return $this->put_back_no_clobber( $claim, $target )
				? $this->changed_since( 'something that is not a regular file took the path while the restore was being prepared' )
				: $this->changed_since( 'something that is not a regular file took the path and could not be put back', array( 'moved_aside' => $claim ) );
		}

		// THE authoritative check: the file we hold, not the name we read.
		$actual = hash_file( 'sha256', $claim );
		if ( ! is_string( $actual ) || ! hash_equals( $replaced, $actual ) ) {
			$this->discard_stage( $tmp );
			return $this->put_claim_back( $claim, $target, 'the file changed as the restore claimed it' );
		}

		// AND THE CLAIMED INODE OWNS THE MODE (Codex #101 round-4 P1). The mode
		// read before the claim is a guess by the time we publish: a chmod that
		// lands in between leaves the CONTENT untouched, so the hash above still
		// passes, and republishing at the older mode would discard a
		// restriction someone just applied. Re-read it from the file we hold
		// and carry it onto the stage.
		$claimed_mode = @fileperms( $claim ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- We hold this file; a false keeps the mode the stage already has.
		if ( false !== $claimed_mode ) {
			$claimed_mode &= 0777;
			if ( ! $this->link_available() && 0 !== ( $claimed_mode & 0111 ) ) {
				// It gained execute bits while we held it, and fopen() cannot
				// recreate those: put it back rather than republish it lesser.
				$this->discard_stage( $tmp );
				return $this->put_claim_back( $claim, $target, 'the file became executable while the restore ran, and this host has no link()' );
			}
			if ( ! $this->secure_stage( $tmp, $claimed_mode ) ) {
				$this->discard_stage( $tmp );
				return $this->put_claim_back( $claim, $target, 'the mode the file now has could not be set on the replacement', false );
			}
		}

		// THE MODE MUST SURVIVE A link()-LESS PUBLISH (Codex #101 round-3 P1).
		// publish() reaches write_exclusively() with NO mode, which creates from
		// FS_CHMOD_FILE (0644) — restoring a 0600 file on a host without link()
		// would hand it to every local account. link() itself preserves the
		// stage's mode, and the stage was created with the target's own mode
		// above, so only the write path needs this. publish()'s signature is
		// deliberately NOT changed: seven test subclasses override
		// publish( $tmp, $path ), and PHP rejects an override that drops a
		// parameter the parent declares.
		$published = $this->link_available()
			? $this->publish( $tmp, $target ) // link() preserves the stage's mode, set from the claim just above
			: $this->publish_restored_by_write( $tmp, $target, false === $claimed_mode ? ( false === $mode ? null : ( $mode & 0777 ) ) : $claimed_mode );
		if ( 'raced' === $published ) {
			// The write landed, but not intact: a concurrent writer edited the
			// target while it was being written (Codex #102 round-2 P1). The
			// path holds neither the old file nor cleanly the restored one, so
			// putting the claim back would clobber that writer's edits. Keep
			// the claim, say where it is, and refuse.
			$this->discard_stage( $tmp );
			return $this->changed_since(
				'another writer changed the file while the restore was writing it; the file it replaced is kept aside',
				array( 'moved_aside' => $claim )
			);
		}
		if ( true !== $published ) {
			$this->discard_stage( $tmp );
			// 'exists' OR (in link mode only) the path is occupied by anything
			// at all (Codex #101 round-4 P2): publish()'s own 'exists' vs
			// 'unsupported_filesystem' classification trusts file_exists(),
			// which reads false for a DANGLING symlink — a racer that took the
			// path with one would otherwise fall to the "our own failure"
			// branch below and answer a 500 for what is a designated
			// changed-since refusal. path_present() is the check this engine
			// already uses everywhere else for exactly this gap.
			//
			// The widened check is gated on link_available() because only the
			// LINK branch's failure is guaranteed to have created nothing at
			// the target: link() either lands (no failure reaches here) or
			// refuses without writing a byte. The link()-less write branch can
			// fail AFTER claiming the path — a short write, an ACL mode
			// mismatch, a failed remove_own_entry() — and leave its OWN empty
			// or partial entry there; without the gate, self::path_present()
			// would then be true for OUR OWN debris and misreport it as a
			// racer's edit (409) instead of our own failed write (500),
			// making put_claim_back()'s "our own failure" shape unreachable on
			// that branch — the exact thing the rule five lines below forbids.
			if ( 'exists' === $published || ( $this->link_available() && self::path_present( $target ) ) ) {
				// Something took the path while we held the file: never clobber it.
				return $this->changed_since( 'another file took the path while the restore was in flight', array( 'moved_aside' => $claim ) );
			}
			// OUR OWN FAILURE IS NOT A CHANGED-SINCE REFUSAL (Codex #101
			// round-5 P1). The write path above has already cleared any entry
			// it created, so the put-back can land; the answer is an execution
			// failure (no code → 500), because nothing about the file changed
			// — this restore simply could not write. `put_claim_back()`'s
			// refusal shape is asked for only when a RACER took the path.
			$out           = $this->put_claim_back( $claim, $target, 'the old bytes could not be published', false );
			$out['error']  = 'Failed to write file: ' . $target . ' (' . (string) $published . ')';
			return $out;
		}
		$this->discard_stage( $tmp ); // in link mode the stage is a second name of the published inode

		// THE CLAIM IS NOT DELETED ON TRUST (Codex #101 round-2 P1). A writer
		// that opened the target before the claim holds a descriptor on THIS
		// inode and may have written through it since the hash above; the
		// claim is now the only pathname those bytes have. Re-hash, and keep
		// the file — named — when it moved. The restore itself still
		// succeeded: the old bytes are at the path.
		$out   = array( 'success' => true );
		$after = hash_file( 'sha256', $claim );
		if ( ! is_string( $after ) || ! hash_equals( $actual, $after ) ) {
			$out['moved_aside'] = $claim;
			$out['detail']      = 'the replaced file was written to while the restore ran and was kept aside rather than deleted';
			return $out;
		}
		wp_delete_file( $claim );
		if ( file_exists( $claim ) ) {
			$out['moved_aside'] = $claim; // `.aura-restore-*` is never swept: say where it is
		}
		return $out;
	}

	/**
	 * The link()-less publish of a RESTORE: exclusive-create the target and
	 * stream the staged bytes into the handle we own, with the mode the file
	 * had. publish()'s own write path cannot be used here — it creates from
	 * FS_CHMOD_FILE and would widen a private file (Codex #101 round-3 P1).
	 * Execute bits never reach this path: that case is answered in place,
	 * before anything is claimed.
	 *
	 * @param string   $tmp    The staged payload.
	 * @param string   $target The path.
	 * @param int|null $mode   The mode the target had, or null for the default.
	 * @return true|string As publish(): true, 'exists', 'partial', or a sentence.
	 */
	private function publish_restored_by_write( $tmp, $target, $mode ) {
		$src = @fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Our own staged file; a refusal is answered below.
		if ( false === $src ) {
			return 'the staged bytes could not be read back';
		}
		$mode = ( null === $mode ? $this->create_mode() : (int) $mode ) & 0666; // execute bits are refused before this path
		$was  = umask( 0777 & ~$mode );
		$fh   = @fopen( $target, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'x' is the no-clobber claim; EEXIST is the expected refusal.
		umask( $was );
		if ( false === $fh ) {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::path_present( $target ) ? 'exists' : 'the target could not be created';
		}
		$mine = fstat( $fh );
		$real = is_array( $mine ) ? ( (int) $this->mode_of( $fh, $mine ) & 0777 ) : -1;
		if ( $real !== $mode ) {
			$this->remove_own_entry( $fh, $target, $mine );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return sprintf( 'the created entry has mode %o, not the %o asked for (a default ACL?)', $real, $mode );
		}
		$ok = $this->write_all( $fh, $src ) && fflush( $fh ) && ( function_exists( 'fsync' ) ? (bool) fsync( $fh ) : true );
		fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $ok ) {
			// THE RESTORE CLEARS ITS OWN DAMAGE (Codex #101 round-5 P1). The
			// create path deliberately leaves its empty entry at the target —
			// it has nothing else to put there. A RESTORE does: it is holding
			// the healthy file under the claim, and that file cannot go back
			// while an empty or half-written entry of ours occupies the path.
			// The entry is emptied and then unlinked, by name, only while the
			// name still resolves to the inode we created.
			$this->truncate_to_empty( $fh );
			$removed = $this->remove_own_entry( $fh, $target, $mine );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $removed
				? 'the write into the restored target was short; the path was cleared so the file could be put back'
				: 'partial';
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$now = @stat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $mine ) || ! is_array( $now ) || $now['ino'] !== $mine['ino'] || $now['dev'] !== $mine['dev'] ) {
			return self::path_present( $target ) ? 'exists' : 'the target was removed during the write';
		}
		// AN INODE CHECK IS NOT A CONTENT CHECK (Codex #102 round-2 P1). Unlike
		// the link() publish, which lands a COMPLETE stage in one atomic call,
		// this branch streams into an inode that is visible at the path the
		// whole time — so a writer holding that path can change a region we
		// have already written while we are still writing later ones, and
		// ino/dev still matches, because the ENTRY was never replaced. Calling
		// that a success would report a file the restore never produced AND
		// let the caller delete the claim, which is the only copy of what the
		// restore was undoing. Verify the bytes we meant to land.
		$want = hash_file( 'sha256', $tmp );
		$got  = $this->hash_regular_file( $target );
		if ( ! is_string( $want ) || ! is_string( $got ) || ! hash_equals( $want, $got ) ) {
			return 'raced';
		}
		return true;
	}

	/**
	 * Unlink an entry THIS call created, by name and only while the name still
	 * resolves to that inode — never a racer's replacement (the rule
	 * write_exclusively() states: nothing addresses the path by name once it
	 * is claimed, except under an inode check).
	 *
	 * Protected so a test can model a refusal.
	 *
	 * @param resource   $fh     Our open handle.
	 * @param string     $target The path.
	 * @param array|false $mine  fstat() of our handle.
	 * @return bool True when the path is clear of our entry.
	 */
	protected function remove_own_entry( $fh, $target, $mine ) {
		$now = @stat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $mine ) || ! is_array( $now ) || $now['ino'] !== $mine['ino'] || $now['dev'] !== $mine['dev'] ) {
			return false; // not ours any more
		}
		$this->before_entry_removal( $target );

		// CLAIM BEFORE DELETING (Codex #101 round-6 P1). stat-then-unlink is
		// two steps on a NAME: a writer who replaces the path in between loses
		// the file we would then unlink, which is exactly the no-clobber rule
		// this path exists to keep. rename() is atomic and inode-preserving, so
		// what is deleted is the entry we moved, and a racer's file — if that
		// is what we moved — is put straight back.
		$aside = $this->aside_name( $target );
		if ( ! @rename( $target, $aside ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- The claim IS the point: atomic, inode-preserving.
			return false;
		}
		$moved = @stat( $aside ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $moved ) || $moved['ino'] !== $mine['ino'] || $moved['dev'] !== $mine['dev'] ) {
			// We moved somebody else's file. Put it back with a primitive that
			// REFUSES an occupied path rather than one that clobbers it
			// (Codex #101 round-8 P1, #102 round-3 P1): a SECOND writer can
			// take the target after our claim, and rename() would destroy that
			// file too. When the path is taken, the entry stays under its
			// `.aura-restore-*` name, which this engine never sweeps, rather
			// than clobbering a file that is not ours. Either way this is "not
			// cleared", and the caller keeps its own claim aside and says so.
			if ( ! $this->put_back_no_clobber( $aside, $target ) ) {
				// IT COULD NOT GO BACK, SO SAY WHERE IT IS (Codex #102 round-9
				// P1). A writer's EXECUTABLE file taken on a link-less host is
				// the reachable case: the copy cannot recreate execute bits, so
				// the file stays under a `.aura-restore-*` name nothing sweeps.
				// Discarding that path left a live file silently displaced —
				// and unlike our own claim, this one is not ours to have moved.
				$this->stranded = $aside;
			}
			return false;
		}
		wp_delete_file( $aside );
		return ! self::path_present( $aside );
	}

	/**
	 * Seam between verifying our entry and claiming it for deletion. Nothing
	 * in production; a test models a racer replacing the path here.
	 *
	 * @param string $target The path.
	 */
	protected function before_entry_removal( $target ) {
	}

	/**
	 * Seam between finding the path free and putting a non-regular entry back.
	 * Nothing in production; a test models a racer taking the path here.
	 *
	 * @param string $aside  The entry held aside.
	 * @param string $target The path.
	 */
	protected function before_non_file_put_back( $aside, $target ) {
	}

	/**
	 * Put an entry this engine moved aside back where it came from, without
	 * replacing anything that took the path meanwhile.
	 *
	 * `rename()` CLOBBERS on POSIX, so check-then-rename is a race: a writer
	 * arriving between the two destroys that writer's entry (Codex #102
	 * round-3 P1). Two of the three shapes have a primitive that refuses an
	 * existing path outright, and those are used instead of the check:
	 *
	 * - a SYMLINK is recreated with symlink(), which fails EEXIST — verified:
	 *   rename( symlink, existing file ) replaces the file, symlink() onto an
	 *   existing path does not.
	 * - a REGULAR FILE is linked back with link(), the same no-clobber
	 *   primitive put_claim_back() uses — and on a host WITHOUT link(), by the
	 *   exclusive-create copy put_claim_back() falls back to. It never reaches
	 *   the rename on either kind of host.
	 * - a FIFO is never copied — fopen() on one blocks until the other end is
	 *   opened, and there is nothing to copy out of it anyway — but it IS
	 *   recreated, with posix_mkfifo(), which refuses an existing path. A FIFO
	 *   is a node rather than content, so recreating it is exact.
	 * - a SOCKET or DEVICE NODE has no primitive at all and is kept aside,
	 *   named. Stranding one costs little, unlike a directory.
	 * - a DIRECTORY has no such primitive in PHP, and rename() stays. The
	 *   exposure there is narrower than it looks: rename() of a directory
	 *   FAILS onto a regular file and onto a non-empty directory, so the only
	 *   entry it can replace is an EMPTY directory a racer created inside the
	 *   window. Stranding somebody's whole directory under a name nothing
	 *   sweeps is the worse harm, so the check-then-rename is kept for this
	 *   shape alone, and it is the residual the plan documents.
	 *
	 * @param string $aside  The entry held aside.
	 * @param string $target Where it belongs.
	 * @return bool True when the path holds it again.
	 */
	protected function put_back_no_clobber( $aside, $target ) {
		if ( is_link( $aside ) ) {
			$dest = @readlink( $aside ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable link is answered false below.
			if ( ! is_string( $dest ) || ! @symlink( $dest, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EEXIST is the refusal this call is chosen FOR.
				return false;
			}
			// THE ENTRY ASIDE IS ONLY REDUNDANT WHILE THE PATH HOLDS WHAT WE
			// PUT THERE (Codex #102 round-7 P2 — the rule the hard-link branch
			// learned in rounds 5 and 6, which this one did not carry). A
			// writer who replaces the path after the symlink() lands leaves
			// this the only copy, and deleting it loses that writer's entry
			// while the answer reports it safely back.
			//
			// Identity for a symlink is its DESTINATION, not an inode: the link
			// we just created is a different inode from the one held aside, by
			// construction, so readlink() is the comparison that means anything
			// here. The check and the unlink are still two steps — the same
			// residual (a) window, failing safe.
			$this->before_claim_drop( $aside, $target );
			clearstatcache( true, $target );
			if ( ! is_link( $target ) || @readlink( $target ) !== $dest ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable link is not ours; the entry stays aside.
				return false; // the claim stays, and the caller names it
			}
			wp_delete_file( $aside ); // the link is back at its path; its held name goes
			return true;
		}
		if ( ! is_dir( $aside ) ) {
			// A REGULAR FILE NEVER REACHES THE RENAME (Codex #102 round-4 P1).
			// Gating only the link() attempt left a link-less host — which is
			// most managed hosts — falling through to the clobbering rename for
			// exactly the shape that has a second no-clobber primitive. Both
			// are tried, and the entry stays aside if neither lands.
			if ( $this->link_available() && $this->link_into_place( $aside, $target ) ) {
				return self::CLAIM_DROPPED === $this->drop_linked_claim( $aside, $target );
			}
			// ONLY A REGULAR FILE IS COPIED (Codex #102 round-8 P2). A FIFO,
			// socket or device node can be claimed too — an external writer can
			// replace the verified file with one between the hash and the claim
			// — and fopen( 'rb' ) on a FIFO BLOCKS until another process opens
			// the other end. The copy would hang this request while it holds the
			// target lock and the real path is absent, which is far worse than
			// any answer it could give. There is nothing to copy out of such an
			// entry in any case, so it falls through to the rename below, which
			// moves any shape in one call.
			if ( ! is_file( $aside ) ) {
				// A FIFO, socket or device node. Measured rather than assumed:
				// rename( FIFO, existing file ) REPLACES the file, while
				// posix_mkfifo() onto an existing path refuses. So a FIFO has a
				// no-clobber primitive after all and takes it; nothing else
				// does, and those are kept aside rather than renamed over a
				// writer who took the path (Codex #102 round-10 P1).
				//
				// A FIFO is a NODE, not content — recreating it at the path is
				// exact, not an approximation — and the cost of keeping a
				// socket or device node aside is small, unlike a directory,
				// which is why the balance lands differently here.
				$type = @filetype( $aside ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable type is answered below.
				if ( 'fifo' === $type && function_exists( 'posix_mkfifo' ) ) {
					$perms = @fileperms( $aside ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A false takes the create default.
					$mode  = false === $perms ? $this->create_mode() : ( $perms & 0777 );
					// posix_mkfifo() TAKES THE UMASK, exactly as mkdir() and
					// fopen() do (Codex #102 round-11 P2). A 0666 FIFO under the
					// common 0022 umask would come back 0644 — silently removing
					// the write access its clients need — and we would then
					// delete the original and report it restored exactly. Set
					// the umask around the call, the way stage() and
					// publish_restored_by_write() already do, and VERIFY.
					$was  = umask( 0777 & ~$mode );
					$made = @posix_mkfifo( $target, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EEXIST is the refusal this call is chosen FOR.
					umask( $was );
					if ( ! $made ) {
						return false; // the path is taken; the node stays aside, named
					}
					// AND THE NODE WE MADE IS THE ONE WE ACT ON (Codex #102
					// round-12 P2). Everything below addresses the path by NAME
					// — a chmod, and an unlink on the failure path — and this
					// engine's rule is that nothing does that after a claim
					// except under an inode check. Without one, a racer who
					// replaced our node with a FIFO of their own would be
					// chmod'd by us and accepted, the original deleted and
					// reported restored; or the cleanup would unlink theirs.
					clearstatcache( true, $target );
					$mine = @stat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Already gone is an answer.
					if ( ! is_array( $mine ) ) {
						return false; // the original stays aside, named
					}
					$this->before_node_verify( $target );
					if ( ! $this->node_wears_mode( $target, $mode, $mine ) ) {
						// A default ACL can widen it past the umask. The node we
						// made is not the node we held, so it goes and the
						// original stays aside rather than being replaced by a
						// lesser one — the rule publish_restored_by_write()
						// follows for the same reason.
						$this->remove_own_node( $target, $mine );
						return false;
					}
					wp_delete_file( $aside );
					return true;
				}
				return false; // no no-clobber primitive exists for it: keep it, named
			}
			// fopen( 'xb' ) refuses an occupied path, so the copy can never
			// replace a writer who took it — the same way put_claim_back()
			// puts a file back on such a host. An executable cannot be
			// recreated this way (fopen() creates no execute bits), so one
			// stays aside rather than going back lesser: the residual the plan
			// documents for a link-less host.
			if ( true !== $this->put_back_by_write( $aside, $target ) ) {
				return false;
			}
			// The copy's source can still be being written by whoever held it.
			// Only call it back when the two read the same.
			$a = hash_file( 'sha256', $aside );
			$b = $this->hash_regular_file( $target );
			if ( ! is_string( $a ) || ! is_string( $b ) || ! hash_equals( $a, $b ) ) {
				return false; // the copy may be behind; the entry stays aside, named
			}
			wp_delete_file( $aside );
			return true;
		}
		// A DIRECTORY, and only a directory: the checked rename, with the
		// window documented above.
		$this->before_non_file_put_back( $aside, $target );
		if ( self::path_present( $target ) ) {
			return false;
		}
		return (bool) @rename( $aside, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- No no-clobber move exists for this shape; a refusal is answered.
	}

	/**
	 * Put a claimed file back at its path and answer changed-since. link() is
	 * no-clobber; without it the claim is copied back by exclusive create. A
	 * put-back that cannot land leaves the file aside, named.
	 *
	 * @param string $claim   The claimed file.
	 * @param string $target  Its path.
	 * @param string $detail  Why the restore is being abandoned.
	 * @param bool   $refusal True → a designated changed-since refusal (409);
	 *                        false → an execution failure (500), for a write of
	 *                        OURS that failed while the file itself never
	 *                        changed (Codex #101 round-5 P1).
	 * @return array
	 */
	private function put_claim_back( $claim, $target, $detail, $refusal = true ) {
		$answer = function ( array $extra = array() ) use ( $detail, $refusal ) {
			return $refusal
				? $this->changed_since( $detail, $extra )
				: array_merge( array( 'success' => false, 'error' => $detail, 'detail' => $detail ), $extra );
		};
		if ( $this->link_available() ) {
			if ( $this->link_into_place( $claim, $target ) ) {
				$dropped = $this->drop_linked_claim( $claim, $target );
				if ( self::CLAIM_LOST === $dropped ) {
					return $answer( array( 'detail' => $detail . '; a concurrent writer replaced the path as the file was being put back, and the copy held aside could not be preserved' ) );
				}
				return self::CLAIM_KEPT === $dropped
					? $answer( array( 'moved_aside' => $claim ) )
					: $answer();
			}
			// A REFUSED LINK IS NOT ALWAYS A TAKEN PATH (Codex #102 round-1 P1).
			// EEXIST means a racer holds the path and the file must stay aside.
			// But link() can also be refused by the filesystem or a policy while
			// the path is still FREE, and answering `moved_aside` there leaves
			// the site's file under a name nothing sweeps with NOTHING at its
			// real path — a failed restore taking the file offline. Tell the two
			// apart with the predicate this engine already trusts, and fall
			// through to the copy when the path is free.
			if ( self::path_present( $target ) ) {
				return $answer( array( 'moved_aside' => $claim ) );
			}
		}
		if ( true !== $this->put_back_by_write( $claim, $target ) ) {
			return $answer( array( 'moved_aside' => $claim ) );
		}
		$a = hash_file( 'sha256', $claim );
		$b = $this->hash_regular_file( $target );
		if ( ! is_string( $a ) || ! is_string( $b ) || ! hash_equals( $a, $b ) ) {
			return $answer( array( 'moved_aside' => $claim, 'detail' => $detail . '; the copy at its path may be behind the file kept aside' ) );
		}
		wp_delete_file( $claim );
		return file_exists( $claim )
			? $answer( array( 'moved_aside' => $claim ) )
			: $answer();
	}

	/**
	 * Replace whatever is at $path with $content, atomically: stage beside it
	 * (with the file's own mode when one is there), rename over it. Callers
	 * hold the target lock. Nothing at the path changes on any failure.
	 *
	 * @param string $path    Path (an existing regular file, or absent).
	 * @param string $content Complete content.
	 * @return true|string true, or why not.
	 */
	private function replace_in_place( $path, $content ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			return 'Directory not found: ' . $dir;
		}
		$perms = is_file( $path ) ? @fileperms( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An absent target keeps the create mode.
		$tmp   = $this->stage( $dir, basename( $path ), $content, false === $perms ? null : ( $perms & 0777 ) ); // born 0600, ends with the file's own mode
		if ( is_array( $tmp ) ) {
			return (string) $tmp['error'];
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Clobbering IS the point here, atomically; $wp_filesystem->move() may copy+delete.
			$this->discard_stage( $tmp );
			return 'Unable to replace the target: ' . $path;
		}
		return true;
	}

	/**
	 * The restore proper, under the target's lock (SiteAgent#99).
	 *
	 * @param array  $record The record.
	 * @param string $target Its target.
	 * @return array As restore_created_file().
	 */
	private function restore_created_file_locked( array $record, $target ) {
		// A record voided because its write never landed (Codex #101 round-7
		// P2) is unset of `expected_sha256` by void_record_in_place() — so
		// "carries no expected hash" already IDENTIFIES a voided record, and
		// is checked here BEFORE the already-gone shortcut below. That
		// ordering is the fix: the shortcut used to run first, so a retired
		// record whose target had since been removed reported a cheerful
		// success and a rollback counted it as undone. Gating on a `voided`
		// flag instead (rather than on the hash itself) would leave a record
		// with no `expected_sha256` that is NOT flagged `voided` — the same
		// dangerous shape — free to fall through to the shortcut and answer
		// the same cheerful success this check exists to close off. The
		// wording is UNCHANGED from the pre-existing refusal below; only the
		// code is added.
		$expected = isset( $record['expected_sha256'] ) ? (string) $record['expected_sha256'] : '';
		if ( '' === $expected ) {
			return array(
				'success' => false,
				'code'    => 'aura_snapshot_voided',
				'error'   => 'Snapshot record carries no expected hash.', // unchanged wording; only the code is added
			);
		}
		if ( ! self::path_present( $target ) ) {
			return array( 'success' => true, 'already' => true ); // already gone
		}
		// is_file() follows a symlink, so a DANGLING one reads as absent to
		// file_exists() and as "not a file" here — it is a directory entry that
		// can go live the moment its destination appears, never "already gone"
		// (Codex #94 round-2 P2). It is reported, never deleted.
		if ( ! is_file( $target ) ) {
			return array(
				'success' => false,
				'code'    => 'aura_file_changed_since',
				'error'   => 'Target is not a regular file: ' . $target,
			);
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
				$inplace = $this->hash_regular_file( $target );
				if ( ! is_string( $inplace ) || ! hash_equals( $expected, $inplace ) ) {
					return array(
						'success' => false,
						'code'    => 'aura_file_changed_since',
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
				: array( 'success' => true, 'already' => true ); // it vanished between exists() and the claim: already gone
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
		$out = array( 'success' => false, 'code' => 'aura_file_changed_since', 'error' => 'file_changed_since' );
		if ( $this->link_available() ) {
			if ( $this->link_into_place( $claim, $target ) ) {
				$dropped = $this->drop_linked_claim( $claim, $target );
				if ( self::CLAIM_KEPT === $dropped ) {
					// Either a racer replaced the target after our link — the
					// claim is the file's LAST name and must not be deleted
					// (Codex #102 round-5 P1) — or the file is back and only its
					// claim name could not be removed, and `.aura-restore-*` is
					// never swept (Codex #94 round-7 P2). Both say where it is.
					$out['moved_aside'] = $claim;
				} elseif ( self::CLAIM_LOST === $dropped ) {
					$out['detail'] = 'a concurrent writer replaced the path as the file was being put back, and the copy held aside could not be preserved';
				}
				return $out;
			}
			// EEXIST, or a host that refuses hard links with the path still free?
			// The same distinction put_claim_back() draws (Codex #102 round-1 P1):
			// only the first is a taken path. Falling through to the write copy
			// keeps a refused link from leaving this path empty.
			if ( self::path_present( $target ) ) {
				$out['moved_aside'] = $claim;
				return $out;
			}
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
		$b = $this->hash_regular_file( $target );
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
	protected function put_back_by_write( $claim, $target ) {
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
	 * sha256 of a path, but ONLY while that path is a regular file.
	 *
	 * hash_file() opens what it is given, and opening a FIFO BLOCKS until
	 * another process opens the other end — with the target lock held and
	 * nothing claimed, that hangs the request outright, and PHP has no
	 * non-blocking open to prevent it (Codex #102 round-9 P2, the same hazard
	 * round 8 closed on the copy path). Checking the type immediately before
	 * the open, on a cleared stat cache, is as close as PHP allows; the
	 * remaining window is the residual (a) shape — a check and an open that
	 * cannot be made one operation.
	 *
	 * A non-regular path answers false, which every caller already reads as
	 * "not the content we are looking for".
	 *
	 * @param string $path The path.
	 * @return string|false
	 */
	/**
	 * Is the node at $path a FIFO wearing exactly $mode? Read back rather than
	 * assumed: the umask is not the only thing that can change a created node's
	 * permissions — a default POSIX ACL ignores it entirely, which is the same
	 * fact stage() guards against for regular files.
	 *
	 * @param string $path The path.
	 * @param int    $mode The mode it must wear.
	 * @param array  $mine stat() of the node this call created.
	 * @return bool
	 */
	private function node_wears_mode( $path, $mode, array $mine ) {
		$now = $this->same_node( $path, $mine );
		if ( false === $now ) {
			return false;
		}
		if ( ( $now['mode'] & 0777 ) === ( $mode & 0777 ) ) {
			return true;
		}
		if ( ! function_exists( 'chmod' ) || ! @chmod( $path, $mode ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Our own node, proved above; a refusal is answered.
			return false;
		}
		$after = $this->same_node( $path, $mine );
		return false !== $after && ( $after['mode'] & 0777 ) === ( $mode & 0777 );
	}

	/**
	 * stat() of $path, but only while it still names the node $mine describes.
	 * The mode comes back with it, so a caller reads the type and the
	 * permissions off the same observation that proved the identity rather
	 * than off a later, separately raceable one.
	 *
	 * What device+inode CANNOT tell apart: a node unlinked and immediately
	 * recreated at the same path, because the kernel is free to hand back the
	 * inode number it just freed — Linux does, APFS does not. There is no
	 * stronger identity available to PHP, and this is the same comparison
	 * remove_own_entry() and drop_linked_claim() rest on, so the limit is the
	 * engine's throughout rather than this method's.
	 *
	 * @param string $path The path.
	 * @param array  $mine stat() of the node this call created.
	 * @return array|false
	 */
	private function same_node( $path, array $mine ) {
		clearstatcache( true, $path );
		$now = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $now ) || $now['ino'] !== $mine['ino'] || $now['dev'] !== $mine['dev'] ) {
			return false;
		}
		return $now;
	}

	/**
	 * Seam between creating a node and verifying it. Nothing in production; a
	 * test models a racer replacing the path here.
	 *
	 * @param string $target The path.
	 */
	protected function before_node_verify( $target ) {
	}

	/**
	 * Remove a FIFO this call created, while the path still holds one. Used
	 * when the node could not be given the mode it must have: a lesser node at
	 * the path is worse than none, because the original is still held aside
	 * and can be reported.
	 *
	 * @param string $path The path.
	 */
	private function remove_own_node( $path, array $mine ) {
		if ( false === $this->same_node( $path, $mine ) ) {
			return; // not ours any more; nothing here is ours to remove
		}
		// CLAIM BEFORE DELETING (Codex #102 round-13 P2, the rule
		// remove_own_entry() already follows). stat-then-unlink is two steps on
		// a NAME, so a writer who replaces the path in between would lose the
		// node we then unlink. rename() is atomic and inode-preserving: what is
		// deleted is the node we moved, and if what we moved turns out to be
		// somebody else's, it goes straight back through the same no-clobber
		// put-back every other shape uses.
		$aside = $this->aside_name( $path );
		if ( ! @rename( $path, $aside ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- The claim IS the point: atomic, inode-preserving.
			return;
		}
		if ( false !== $this->same_node( $aside, $mine ) ) {
			wp_delete_file( $aside );
			return;
		}
		if ( ! $this->put_back_no_clobber( $aside, $path ) ) {
			// The same obligation remove_own_entry() carries (Codex #102
			// round-14 P2): a socket, a device node or a link-less executable
			// cannot be put back, and discarding the path would leave another
			// writer's LIVE entry gone from its real name with nothing saying
			// where it went.
			$this->stranded = $aside;
		}
	}

	/**
	 * A fresh opaque name beside $path, for an entry this engine holds. Nothing
	 * of the target's own name is in it: `.agent.php.aura-restore-x` would
	 * still carry `.php`, which Apache's multi-extension AddHandler semantics
	 * will execute.
	 *
	 * @param string $path The path it sits beside.
	 * @return string
	 */
	private function aside_name( $path ) {
		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		return dirname( $path ) . '/.aura-restore-' . $suffix;
	}

	private function hash_regular_file( $path ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) || 'file' !== @filetype( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An absent path is an answer, not a warning.
			return false;
		}
		return hash_file( 'sha256', $path );
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
				$bytes = file_get_contents( $payload_path );
				if ( false === $bytes ) {
					return array( 'success' => false, 'error' => 'Snapshot payload unreadable.' );
				}
				return $this->restore_existing_file( (string) $record['target'], $bytes, $record );

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
		// Under the record's own lock (SiteAgent#98, Codex #100 round-1 P1): a
		// sweeper voiding or a publisher reinstating this record is never
		// overlapped by its deletion. Contended → not deleted; say so.
		$done = $this->with_record_lock(
			$id,
			function () use ( $record, $id ) {
				if ( ! empty( $record['payload_path'] ) && file_exists( $record['payload_path'] ) ) {
					wp_delete_file( $record['payload_path'] );
				}
				return $this->delete_record_file( $id ); // the record, and its lock file only once the record is gone
			}
		);
		return true === $done;
	}
}
