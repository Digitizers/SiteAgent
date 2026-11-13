<?php
/**
 * MCP Tool: audit_public_backups (SA#156)
 *
 * Read-only audit for full-site backups and database dumps left inside the
 * webroot. During the 2026-11-13 fleet round, three sites served
 * All-in-One WP Migration exports (`wp-content/ai1wm-backups/*.wpress`) to
 * anyone who knew the file name: the whole database, users and config.
 *
 * Reports names, sizes and times only — never contents — and asks the site's
 * own URL whether each file is served (a HEAD request, no redirects), because
 * a protective `.htaccess` proves nothing under nginx. Bounded like every
 * other audit walk.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Audit_Public_Backups extends Aura_Tool_Base {

	/** Max files returned. */
	const MAX_FILES = 200;

	/** Max directory entries visited before the walk reports truncation. */
	const MAX_ENTRIES = 5000;

	/** Max HTTP checks per run; files past it are reported with `public: null`. */
	const MAX_HTTP_CHECKS = 20;

	/** Per-check timeout, seconds. */
	const HTTP_TIMEOUT = 3;

	/** Max depth below a dedicated backup directory. */
	const MAX_DEPTH = 2;

	/** Backup/dump extensions. Lowercase; `.sql.gz` etc. are matched by suffix. */
	const BACKUP_SUFFIXES = array( '.wpress', '.sql', '.sql.gz', '.sql.zip', '.sql.bz2', '.tar', '.tar.gz', '.tgz', '.zip', '.gz', '.bak', '.daf', '.jpa' );

	/** Directories plugins keep backups in, relative to WP_CONTENT_DIR. */
	const BACKUP_DIRS = array( 'aura-backups', 'ai1wm-backups', 'updraft', 'backups-dup-lite', 'backups-dup-pro', 'backup-db', 'backups', 'backupwordpress', 'wpvividbackups', 'backup', 'uploads/backwpup' );

	public function get_name() {
		return 'audit_public_backups';
	}

	public function get_description() {
		return 'Read-only audit for full-site backups and database dumps inside the webroot (All-in-One WP Migration .wpress, UpdraftPlus, Duplicator, *.sql and archives at the site root or in wp-content). Reports name, size and modified time only, and whether the site itself serves each file over HTTP (HEAD, no redirects). Makes no changes and never reads file contents.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'files'    => 'array — { path (relative to the site root), size, mtime, public: true|false|null, http_status: int|null } — public is true when the site served it (2xx), false on 401/403/404/410, null when not checked or unclear',
			'coverage' => 'object — { locations, total_seen, returned, truncated, cap, http_checked, http_cap, unreadable_dirs } bounded-coverage contract; truncated means a LOWER BOUND, never "clean"',
		);
	}

	/**
	 * Read-only: never mutates the site. The HEAD requests go to the site's
	 * own URL only.
	 */
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}

	public function execute( $params ) {
		$files      = array();
		$entries    = 0;
		$truncated  = false;
		$cap        = '';
		$unreadable = array();
		$locations  = $this->locations();

		foreach ( $locations as $loc ) {
			if ( $truncated ) {
				break;
			}
			$this->walk( $loc, $files, $entries, $truncated, $cap, $unreadable );
		}

		$checked = 0;
		foreach ( $files as $i => $f ) {
			if ( $checked >= static::MAX_HTTP_CHECKS ) {
				break;
			}
			$checked++;
			$status                    = $this->http_status( $f['url'] );
			$files[ $i ]['http_status'] = $status;
			$files[ $i ]['public']      = self::served( $status );
		}
		foreach ( $files as $i => $f ) {
			unset( $files[ $i ]['url'] );
		}

		return array(
			'files'    => array_values( $files ),
			'coverage' => array(
				'locations'       => count( $locations ),
				'total_seen'      => $entries,
				'returned'        => count( $files ),
				'truncated'       => $truncated,
				'cap'             => $truncated ? $cap : '',
				'http_checked'    => $checked,
				'http_cap'        => static::MAX_HTTP_CHECKS,
				'unreadable_dirs' => $unreadable,
			),
		);
	}

	/**
	 * Whether an HTTP status means the file is served: true for 2xx, false
	 * for an answer that refuses it, null for anything else (a redirect may
	 * lead to a login page or to the file; an error proves nothing).
	 *
	 * @param int|null $status HTTP status, or null for no answer.
	 * @return bool|null
	 */
	public static function served( $status ) {
		if ( null === $status ) {
			return null;
		}
		if ( $status >= 200 && $status < 300 ) {
			return true;
		}
		if ( in_array( $status, array( 401, 403, 404, 410 ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Whether a file name is a backup or dump.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function is_backup_name( $name ) {
		$lower = strtolower( (string) $name );
		foreach ( static::BACKUP_SUFFIXES as $suffix ) {
			if ( strlen( $lower ) > strlen( $suffix ) && substr( $lower, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where to look. Each location: absolute dir, its URL and the depth to
	 * walk. Only backup-named files count anywhere — a backup directory's own
	 * `index.php` or `.htaccess` is not a backup.
	 *
	 * @return array<int, array{ dir: string, url: string, depth: int }>
	 */
	protected function locations() {
		$root    = rtrim( (string) ABSPATH, '/\\' );
		$content = rtrim( (string) WP_CONTENT_DIR, '/\\' );
		// ABSPATH is the INSTALL directory, so its URL is site_url(), not
		// home_url(): a WordPress in a subdirectory serving the homepage from
		// the domain root would otherwise ask the wrong URL, read a 404 and
		// report a downloadable dump as private (Codex r1 on #157).
		$install = $this->install_url();
		$c_url   = function_exists( 'content_url' ) ? rtrim( (string) content_url(), '/' ) : $install . '/wp-content';

		$out = array(
			array( 'dir' => $root, 'url' => $install, 'depth' => 0 ),
			array( 'dir' => $content, 'url' => $c_url, 'depth' => 0 ),
		);
		foreach ( static::BACKUP_DIRS as $rel ) {
			$out[] = array( 'dir' => $content . '/' . $rel, 'url' => $c_url . '/' . $rel, 'depth' => static::MAX_DEPTH );
		}

		/**
		 * Filters the locations the public-backup audit walks.
		 *
		 * @param array $out Locations: { dir, url, depth }.
		 */
		return (array) apply_filters( 'aura_worker_public_backup_locations', $out );
	}

	/**
	 * The URL of the WordPress install directory (ABSPATH), no trailing
	 * slash. A seam, so a test can make it differ from the home URL.
	 *
	 * @return string
	 */
	protected function install_url() {
		$url = function_exists( 'site_url' ) ? site_url( '/' ) : home_url( '/' );
		return rtrim( (string) $url, '/' );
	}

	/**
	 * Bounded walk of one location. Symlinks are never followed.
	 *
	 * @param array  $loc        Location.
	 * @param array  $files      Accumulator (by reference).
	 * @param int    $entries    Entry counter (by reference).
	 * @param bool   $truncated  Truncation flag (by reference).
	 * @param string $cap        Tripped-cap label (by reference).
	 * @param array  $unreadable Unreadable dirs (by reference).
	 */
	private function walk( array $loc, array &$files, &$entries, &$truncated, &$cap, array &$unreadable ) {
		$base = rtrim( (string) $loc['dir'], '/\\' );
		if ( ! is_dir( $base ) || is_link( $base ) ) {
			return; // a backup directory the site does not have is not a finding
		}
		$root  = rtrim( (string) ABSPATH, '/\\' );
		$stack = array( array( '', 0 ) );

		while ( ! empty( $stack ) ) {
			list( $rel, $depth ) = array_pop( $stack );
			$abs                 = '' === $rel ? $base : $base . '/' . $rel;
			$handle              = @opendir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $handle ) {
				$unreadable[] = self::relative( $abs, $root );
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ++$entries > static::MAX_ENTRIES ) {
					$truncated = true;
					$cap       = 'max_entries';
					closedir( $handle );
					return;
				}
				$path     = $abs . '/' . $entry;
				$rel_next = '' === $rel ? $entry : $rel . '/' . $entry;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( $depth < (int) $loc['depth'] ) {
						$stack[] = array( $rel_next, $depth + 1 );
					}
					continue;
				}
				if ( ! is_file( $path ) || ! self::is_backup_name( $entry ) ) {
					continue;
				}
				if ( count( $files ) >= static::MAX_FILES ) {
					$truncated = true;
					$cap       = 'max_files';
					closedir( $handle );
					return;
				}
				$files[] = array(
					'path'        => self::relative( $path, $root ),
					'size'        => (int) @filesize( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					'mtime'       => (int) @filemtime( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					'public'      => null,
					'http_status' => null,
					'url'         => rtrim( (string) $loc['url'], '/' ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel_next ) ) ),
				);
			}
			closedir( $handle );
		}
	}

	/**
	 * A path relative to the site root, or the path itself when outside it.
	 *
	 * @param string $path Absolute path.
	 * @param string $root Site root, no trailing slash.
	 * @return string
	 */
	private static function relative( $path, $root ) {
		$path = (string) $path;
		return 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : $path;
	}

	/**
	 * The HTTP status the site answers for one of its own URLs, or null. A
	 * seam, so a test can answer without a network.
	 *
	 * @param string $url URL.
	 * @return int|null
	 */
	protected function http_status( $url ) {
		$res = wp_remote_head(
			$url,
			array(
				'timeout'     => static::HTTP_TIMEOUT,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code > 0 ? $code : null;
	}
}
