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

		if ( null !== $payload ) {
			$payload_path = $this->dir . $id . '.payload';
			if ( false === file_put_contents( $payload_path, $payload ) ) {
				return false;
			}
			$meta['payload_path'] = $payload_path;
		}

		$json = wp_json_encode( $meta );
		if ( false === $json ) {
			return false;
		}
		$meta_path = $this->dir . $id . '.json';
		if ( false === file_put_contents( $meta_path, $json ) ) {
			return false;
		}
		$meta['meta_path'] = $meta_path;

		return $meta;
	}

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
	 * @return array { success: bool, snapshot?: array, error?: string }
	 */
	public function snapshot_posts( $post_ids, $meta_keys ) {
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

		$record = $this->persist(
			array(
				'kind'    => 'posts',
				'targets' => array_map( 'intval', array_keys( $captured ) ),
			),
			serialize( $captured )
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

		switch ( $record['kind'] ) {
			case 'file':
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
				update_option( $record['target'], unserialize( $raw ) );
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
				$captured = unserialize( file_get_contents( $payload_path ) );
				if ( ! is_array( $captured ) ) {
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
				$payload_path = $record['payload_path'] ?? '';
				if ( ! $payload_path || ! file_exists( $payload_path ) ) {
					return array( 'success' => false, 'error' => 'Snapshot payload missing.' );
				}
				$captured = unserialize( file_get_contents( $payload_path ) );
				if ( ! is_array( $captured ) ) {
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
				return array( 'success' => true );

			default:
				return array( 'success' => false, 'error' => 'Unsupported snapshot kind: ' . $record['kind'] );
		}
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
		wp_delete_file( $this->dir . basename( (string) $id ) . '.json' );
		return true;
	}
}
