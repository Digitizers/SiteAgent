<?php
/**
 * Snapshot engine for SiteAgent.
 *
 * Generalizes the plugin-zip rollback (class-aura-worker-rollback.php) into
 * capture-before-write snapshots for the surfaces the Governed Power Tools
 * touch: individual files and WordPress options. Each snapshot is a small JSON
 * record (plus, for files, a payload copy) under wp-content/aura-backups/
 * snapshots/, so the Aura gateway can preview and reverse a power action the
 * same way it already reverses page/resource snapshots.
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
		return 'snap_' . gmdate( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/**
	 * Write a snapshot record (and optional payload) to disk.
	 *
	 * @param array       $meta    Record metadata (kind, target, created, ...).
	 * @param string|null $payload Optional raw payload to store alongside.
	 * @return array The stored record (including its id and paths).
	 */
	private function persist( $meta, $payload = null ) {
		$id                  = $this->new_id();
		$meta['id']          = $id;
		$meta['created_gmt'] = gmdate( 'Y-m-d H:i:s' );

		if ( null !== $payload ) {
			$payload_path         = $this->dir . $id . '.payload';
			file_put_contents( $payload_path, $payload );
			$meta['payload_path'] = $payload_path;
		}

		$meta_path = $this->dir . $id . '.json';
		file_put_contents( $meta_path, wp_json_encode( $meta ) );
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

		$sentinel = '__aura_absent__';
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
