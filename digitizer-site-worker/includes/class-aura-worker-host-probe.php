<?php
/**
 * Host write probe for SiteAgent (SA#95).
 *
 * Some hosts (WP Engine is the proven case) refuse to let PHP create,
 * overwrite, rename-to or delete a `.php` file, while every other extension
 * works. An upgrader running there fails partway, and a restore that starts
 * by deleting the plugin directory removes every non-PHP file before it hits
 * the first `.php` one. So before Aura mutates plugin files, the site asks the
 * filesystem the one question that matters: can PHP write AND delete a `.php`
 * file in the directory the upgrader works in?
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Host_Probe {

	/** Option holding the last verdict: `{ php_writes, checked_at }`. Autoload off. */
	const OPTION = 'aura_worker_host_probe';

	/** A `.php` file was created and deleted. */
	const OK = 'ok';

	/** A `.txt` file could be created, a `.php` one could not be created or deleted. */
	const BLOCKED = 'blocked';

	/** Not even a `.txt` file could be created: the directory itself is the problem. */
	const UNWRITABLE = 'unwritable';

	/**
	 * Run the probe, record the verdict, and answer it.
	 *
	 * Never throws. A probe that cannot finish answers the most it proved:
	 * `unwritable` until a `.txt` file was created, `blocked` until a `.php`
	 * file was created AND deleted — refusing costs nothing on the site,
	 * while a guess of `ok` would let a restore start a delete it cannot end.
	 *
	 * @return string One of OK, BLOCKED, UNWRITABLE.
	 */
	public function run() {
		$created = array();
		$verdict = self::UNWRITABLE;
		try {
			$verdict = $this->probe( $created );
		} catch ( Throwable $e ) {
			// $created says how far the probe got (see probe()).
			$verdict = isset( $created['txt_written'] ) ? self::BLOCKED : self::UNWRITABLE;
		} finally {
			unset( $created['txt_written'] );
			$this->cleanup( $created );
		}
		$this->record( $verdict );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'aura_worker_host_probe_ran', $verdict );
		}
		return $verdict;
	}

	/**
	 * The probe proper. Every path it may create is registered in $created
	 * BEFORE the write, so the caller's cleanup reaches it whatever happens.
	 *
	 * @param array $created Out: paths to clean up; `txt_written` once the
	 *                       `.txt` write was proven.
	 * @return string
	 */
	private function probe( array &$created ) {
		$dir = WP_CONTENT_DIR . '/upgrade/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$txt       = $dir . 'aura-php-probe-' . bin2hex( random_bytes( 8 ) ) . '.txt';
		$created[] = $txt;
		if ( ! $this->write_file( $txt, "Aura write probe\n" ) || ! $this->exists( $txt ) ) {
			return self::UNWRITABLE;
		}
		$created['txt_written'] = true;
		$this->delete_file( $txt );

		$php       = $dir . 'aura-php-probe-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$created[] = $php;
		if ( ! $this->write_file( $php, "<?php // Aura write probe\n" ) || ! $this->exists( $php ) ) {
			return self::BLOCKED;
		}
		$this->delete_file( $php );
		return $this->exists( $php ) ? self::BLOCKED : self::OK;
	}

	/**
	 * Remove whatever the probe created: once through the filesystem layer,
	 * once more with PHP's own unlink, then give up quietly.
	 *
	 * @param string[] $paths Paths the probe may have created.
	 */
	private function cleanup( array $paths ) {
		foreach ( $paths as $path ) {
			if ( ! $this->exists( $path ) ) {
				continue;
			}
			try {
				$this->delete_file( $path );
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Tried once; the retry below is the second attempt.
			}
			if ( $this->exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	/**
	 * Whether a path is there, judged fresh.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function exists( $path ) {
		clearstatcache( true, $path );
		return file_exists( $path );
	}

	/**
	 * Store the verdict. Best-effort: a probe whose record cannot be written
	 * still answers.
	 *
	 * @param string $verdict The verdict.
	 */
	private function record( $verdict ) {
		try {
			update_option(
				self::OPTION,
				array(
					'php_writes' => $verdict,
					'checked_at' => time(),
				),
				false
			);
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// The verdict stands without its record.
		}
	}

	/**
	 * The WP_Filesystem the upgrader itself would use, or null when no
	 * transport initialises (the same guard Aura_Worker_Rollback's constructor
	 * applies).
	 *
	 * @return object|null
	 */
	protected function filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$ready = WP_Filesystem() && is_object( $wp_filesystem )
			&& method_exists( $wp_filesystem, 'put_contents' )
			&& method_exists( $wp_filesystem, 'delete' );
		return $ready ? $wp_filesystem : null;
	}

	/**
	 * Write one file. A seam: the unit suite cannot fake a real permission.
	 *
	 * @param string $path Path.
	 * @param string $body Contents.
	 * @return bool Whether the write reported success.
	 */
	protected function write_file( $path, $body ) {
		$fs = $this->filesystem();
		if ( null !== $fs ) {
			return (bool) $fs->put_contents( $path, $body, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		}
		return false !== @file_put_contents( $path, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Delete one file. A seam, like write_file(). Its return is not trusted;
	 * callers look again.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	protected function delete_file( $path ) {
		$fs = $this->filesystem();
		if ( null !== $fs ) {
			return (bool) $fs->delete( $path );
		}
		return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * The last recorded verdict, for `/status`. Reads the option only — never
	 * probes. Anything malformed reads as not recorded.
	 *
	 * @return array{ php_writes: string|null, checked_at: int|null }
	 */
	public static function recorded() {
		$row     = get_option( self::OPTION, null );
		$verdict = is_array( $row ) && isset( $row['php_writes'] ) && in_array( $row['php_writes'], array( self::OK, self::BLOCKED, self::UNWRITABLE ), true )
			? $row['php_writes']
			: null;
		$at      = null !== $verdict && isset( $row['checked_at'] ) && is_int( $row['checked_at'] ) ? $row['checked_at'] : null;
		return array(
			'php_writes' => $verdict,
			'checked_at' => $at,
		);
	}

	/**
	 * The refusal a plugin-file mutation answers for a verdict, or null for OK.
	 * Anything that is not OK or BLOCKED is treated as UNWRITABLE.
	 *
	 * @param string $verdict The probe's verdict.
	 * @return array|null { success, code, error, in_progress, php_writes }
	 */
	public static function refusal( $verdict ) {
		if ( self::OK === $verdict ) {
			return null;
		}
		if ( self::BLOCKED === $verdict ) {
			return array(
				'success'     => false,
				'code'        => 'aura_php_writes_blocked',
				'error'       => __( 'This host does not let PHP write or delete .php files, so plugins cannot be updated or restored through Aura here. Nothing on the site was changed. Update plugins from the host\'s own tools or from wp-admin.', 'digitizer-site-worker' ),
				'in_progress' => false,
				'php_writes'  => self::BLOCKED,
			);
		}
		return array(
			'success'     => false,
			'code'        => 'aura_upgrade_dir_unwritable',
			'error'       => __( 'SiteAgent could not write a file to this site\'s upgrade directory (wp-content/upgrade), so plugins cannot be updated or restored through Aura here. Nothing on the site was changed. Check that directory\'s permissions, or update plugins from the host\'s own tools or from wp-admin.', 'digitizer-site-worker' ),
			'in_progress' => false,
			'php_writes'  => self::UNWRITABLE,
		);
	}
}
