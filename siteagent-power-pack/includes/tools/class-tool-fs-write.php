<?php
/**
 * Power tool: write a file (governed).
 *
 * Safety model (defense in depth, not a single gate):
 *  1. Execution is OFF unless the operator sets `AURA_POWER_ALLOW_FS_WRITE` in
 *     wp-config — installing the pack does not enable writes.
 *  2. requires_approval=true — never runs inline; a human approves it through the
 *     Aura gateway, seeing the target path and a diff first (supports_preview).
 *  3. Jailed: the resolved parent directory must sit inside wp-content;
 *     wp-config.php is refused.
 *  4. Snapshot-first: an existing file is snapshotted (Aura_Worker_Snapshots)
 *     before it is overwritten, so the write is reversible.
 *
 * @package Aura_Worker_Power_Pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Power_Tool_Fs_Write extends Aura_Tool_Base {

	/**
	 * Hard ceiling on written content size (2 MB).
	 */
	const MAX_BYTES = 2097152;

	public function get_name() {
		return 'write_file';
	}

	public function get_description() {
		return 'Write a file within wp-content (jailed, snapshot-first, approval-gated). Refuses wp-config.php and paths outside wp-content. Disabled unless AURA_POWER_ALLOW_FS_WRITE is set in wp-config.';
	}

	public function get_parameters() {
		return array(
			'path'    => array(
				'type'        => 'string',
				'description' => 'Absolute path to the file to write (must resolve inside wp-content).',
				'required'    => true,
			),
			'content' => array(
				'type'        => 'string',
				'description' => 'New file contents.',
				'required'    => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'path'        => array( 'type' => 'string' ),
			'bytes'       => array( 'type' => 'integer' ),
			'created'     => array( 'type' => 'boolean' ),
			'snapshot_id' => array( 'type' => 'string' ),
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => true,
			'requires_approval' => true,
			'supports_preview'  => true,
		);
	}

	/**
	 * Resolve + jail-check a target path. Returns the safe absolute path or a
	 * WP_Error-style array { error }.
	 *
	 * The file itself may not exist yet, so we resolve its PARENT directory and
	 * require that to sit inside wp-content.
	 *
	 * @param string $path Requested path.
	 * @return array { ok: bool, path?: string, error?: string, exists?: bool }
	 */
	private function resolve_target( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return array( 'ok' => false, 'error' => 'Empty path.' );
		}
		if ( false !== strpos( $path, "\0" ) ) {
			return array( 'ok' => false, 'error' => 'Refused: null byte in path.' );
		}

		$root = realpath( WP_CONTENT_DIR );
		if ( false === $root ) {
			return array( 'ok' => false, 'error' => 'Content directory not found.' );
		}
		$root_prefix = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;

		$parent = realpath( dirname( $path ) );
		if ( false === $parent ) {
			return array( 'ok' => false, 'error' => 'Parent directory does not exist.' );
		}

		$parent_in_jail = ( $parent === $root ) || ( 0 === strpos( $parent, $root_prefix ) );
		if ( ! $parent_in_jail ) {
			return array( 'ok' => false, 'error' => 'Refused: path is outside wp-content.' );
		}

		$abs = $parent . DIRECTORY_SEPARATOR . basename( $path );

		// Reject a symlink at the final component FIRST — is_link() uses lstat, so
		// it catches a DANGLING symlink (target not yet created) that file_exists()
		// would report as absent, sending it down the new-file path to be written
		// through — a jail escape.
		if ( is_link( $abs ) ) {
			return array( 'ok' => false, 'error' => 'Refused: target is a symlink.' );
		}

		if ( file_exists( $abs ) ) {
			// Existing target must be a real regular file inside the jail — never
			// wp-config.php (resolved, any case) or a path resolving out of jail.
			$real = realpath( $abs );
			if ( false === $real ) {
				return array( 'ok' => false, 'error' => 'Cannot resolve target path.' );
			}
			$real_in_jail = ( $real === $root ) || ( 0 === strpos( $real, $root_prefix ) );
			if ( ! $real_in_jail ) {
				return array( 'ok' => false, 'error' => 'Refused: target resolves outside wp-content.' );
			}
			if ( 'wp-config.php' === strtolower( basename( $real ) ) ) {
				return array( 'ok' => false, 'error' => 'Refused: wp-config.php cannot be written.' );
			}
			return array( 'ok' => true, 'path' => $real, 'exists' => true );
		}

		// New file — guard the requested basename (case-insensitive).
		if ( 'wp-config.php' === strtolower( basename( $path ) ) ) {
			return array( 'ok' => false, 'error' => 'Refused: wp-config.php cannot be written.' );
		}
		return array( 'ok' => true, 'path' => $abs, 'exists' => false );
	}

	/**
	 * Whether a path has a web-executable extension (flagged for the approver).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_executable_target( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'php', 'phtml', 'php5', 'pht', 'phar' ), true );
	}

	/**
	 * A minimal unified-diff-ish preview (line counts + first differing lines).
	 *
	 * @param string $old Old contents ('' if new).
	 * @param string $new New contents.
	 * @return array
	 */
	private function diff_summary( $old, $new ) {
		$old_lines = '' === $old ? array() : explode( "\n", $old );
		$new_lines = explode( "\n", $new );
		return array(
			'old_line_count' => count( $old_lines ),
			'new_line_count' => count( $new_lines ),
			'added'          => max( 0, count( $new_lines ) - count( $old_lines ) ),
			'removed'        => max( 0, count( $old_lines ) - count( $new_lines ) ),
			'old_bytes'      => strlen( $old ),
			'new_bytes'      => strlen( $new ),
		);
	}

	/**
	 * Preview the write without touching disk.
	 *
	 * @param array $params Parameters.
	 * @return array
	 */
	public function dry_run( $params ) {
		$target = $this->resolve_target( isset( $params['path'] ) ? $params['path'] : '' );
		if ( ! $target['ok'] ) {
			return array( 'error' => $target['error'] );
		}
		$new = (string) ( isset( $params['content'] ) ? $params['content'] : '' );
		$old = $target['exists'] ? (string) file_get_contents( $target['path'] ) : '';
		return array(
			'path'              => $target['path'],
			'created'           => ! $target['exists'],
			'diff'              => $this->diff_summary( $old, $new ),
			// Flag for the approver: writing executable PHP into a web-served dir
			// can become a persistent, unauthenticated web shell.
			'executable_target' => $this->is_executable_target( $target['path'] ),
			'enabled'           => defined( 'AURA_POWER_ALLOW_FS_WRITE' ) && AURA_POWER_ALLOW_FS_WRITE,
		);
	}

	public function execute( $params ) {
		if ( ! ( defined( 'AURA_POWER_ALLOW_FS_WRITE' ) && AURA_POWER_ALLOW_FS_WRITE ) ) {
			return array( 'error' => 'Filesystem write is disabled. Set AURA_POWER_ALLOW_FS_WRITE in wp-config to enable.' );
		}

		$target = $this->resolve_target( isset( $params['path'] ) ? $params['path'] : '' );
		if ( ! $target['ok'] ) {
			return array( 'error' => $target['error'] );
		}

		$content = (string) ( isset( $params['content'] ) ? $params['content'] : '' );
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return array( 'error' => 'Content exceeds the ' . self::MAX_BYTES . '-byte limit.' );
		}

		// Snapshot-first (reversible) when overwriting an existing file. Fail
		// CLOSED: if we can't capture a backup, do not overwrite — the "reversible"
		// guarantee must hold, not silently degrade.
		$snapshot_id = '';
		if ( $target['exists'] ) {
			if ( ! class_exists( 'Aura_Worker_Snapshots' ) ) {
				return array( 'error' => 'Snapshot engine unavailable; refusing to overwrite without a backup.' );
			}
			$snaps = new Aura_Worker_Snapshots();
			$snap  = $snaps->snapshot_file( $target['path'] );
			if ( empty( $snap['success'] ) ) {
				$reason = isset( $snap['error'] ) ? $snap['error'] : 'unknown error';
				return array( 'error' => 'Snapshot failed (' . $reason . '); refusing to overwrite without a backup.' );
			}
			$snapshot_id = $snap['snapshot']['id'];
		}

		$written = file_put_contents( $target['path'], $content );
		if ( false === $written ) {
			return array( 'error' => 'Failed to write file: ' . $target['path'] );
		}

		return array(
			'path'        => $target['path'],
			'bytes'       => $written,
			'created'     => ! $target['exists'],
			'snapshot_id' => $snapshot_id,
		);
	}
}
