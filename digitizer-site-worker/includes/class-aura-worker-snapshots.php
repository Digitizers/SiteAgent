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
				foreach ( $captured as $key => $info ) {
					$key = (string) $key;
					if ( empty( $info['existed'] ) ) {
						// The key was absent when captured — remove it, don't
						// resurrect an empty value.
						delete_post_meta( $post_id, $key );
						continue;
					}
					// Meta writers expect slashed input (WP unslashes before
					// storing); get_post_meta returned unslashed, so re-slash.
					update_post_meta( $post_id, $key, wp_slash( $info['value'] ) );
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
