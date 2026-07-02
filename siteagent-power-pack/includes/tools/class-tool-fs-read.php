<?php
/**
 * Power tool: read a file's contents.
 *
 * Read-only, but jailed hard: the resolved real path must sit inside
 * wp-content (or ABSPATH only when explicitly allowed), wp-config.php is refused
 * regardless, and output is byte-capped. This is the safe read half of the
 * Governed Power Tools — no state change, so it runs without approval, but the
 * jail keeps it from exfiltrating secrets outside the content tree.
 *
 * @package Aura_Worker_Power_Pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Power_Tool_Fs_Read extends Aura_Tool_Base {

	/**
	 * Default byte cap for returned contents (128 KB).
	 */
	const DEFAULT_MAX_BYTES = 131072;

	/**
	 * Hard byte ceiling regardless of requested max (2 MB).
	 */
	const HARD_MAX_BYTES = 2097152;

	public function get_name() {
		return 'read_file';
	}

	public function get_description() {
		return 'Read a UTF-8 text file from within wp-content (jailed). Refuses wp-config.php and paths outside the allowed roots; output is byte-capped.';
	}

	public function get_parameters() {
		return array(
			'path'          => array(
				'type'        => 'string',
				'description' => 'Absolute or wp-content-relative path to the file.',
				'required'    => true,
			),
			'max_bytes'     => array(
				'type'        => 'integer',
				'description' => 'Max bytes to return (default 131072, hard cap 2097152).',
				'required'    => false,
			),
			'allow_abspath' => array(
				'type'        => 'boolean',
				'description' => 'Allow reading anywhere under ABSPATH, not just wp-content. Default false.',
				'required'    => false,
			),
		);
	}

	public function get_returns() {
		return array(
			'path'           => array( 'type' => 'string' ),
			'size'           => array( 'type' => 'integer' ),
			'returned_bytes' => array( 'type' => 'integer' ),
			'truncated'      => array( 'type' => 'boolean' ),
			'content'        => array( 'type' => 'string' ),
		);
	}

	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	/**
	 * Resolve the allowed root real paths (wp-content, plus ABSPATH if allowed).
	 *
	 * @param bool $allow_abspath Whether ABSPATH is permitted.
	 * @return string[]
	 */
	private function allowed_roots( $allow_abspath ) {
		$roots = array( realpath( WP_CONTENT_DIR ) );
		if ( $allow_abspath && defined( 'ABSPATH' ) ) {
			$roots[] = realpath( ABSPATH );
		}
		return array_values( array_filter( $roots ) );
	}

	/**
	 * Whether $real sits inside one of the allowed roots.
	 *
	 * @param string   $real  Resolved real path.
	 * @param string[] $roots Allowed root real paths.
	 * @return bool
	 */
	private function within_roots( $real, $roots ) {
		foreach ( $roots as $root ) {
			if ( $real === $root ) {
				return true;
			}
			if ( 0 === strpos( $real, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}
		return false;
	}

	public function execute( $params ) {
		$path          = (string) $params['path'];
		$allow_abspath = ! empty( $params['allow_abspath'] );

		$max_bytes = isset( $params['max_bytes'] ) ? absint( $params['max_bytes'] ) : self::DEFAULT_MAX_BYTES;
		if ( $max_bytes < 1 || $max_bytes > self::HARD_MAX_BYTES ) {
			$max_bytes = self::HARD_MAX_BYTES;
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return array( 'error' => 'File not found: ' . $path );
		}

		if ( 'wp-config.php' === basename( $real ) ) {
			return array( 'error' => 'Refused: wp-config.php cannot be read.' );
		}

		if ( ! $this->within_roots( $real, $this->allowed_roots( $allow_abspath ) ) ) {
			return array( 'error' => 'Refused: path is outside the allowed roots.' );
		}

		if ( ! is_file( $real ) ) {
			return array( 'error' => 'Not a regular file: ' . $real );
		}

		$size    = filesize( $real );
		$content = file_get_contents( $real, false, null, 0, $max_bytes );
		if ( false === $content ) {
			return array( 'error' => 'Unable to read file: ' . $real );
		}

		return array(
			'path'           => $real,
			'size'           => (int) $size,
			'returned_bytes' => strlen( $content ),
			'truncated'      => $size > strlen( $content ),
			'content'        => $content,
		);
	}
}
