<?php
/**
 * MCP Tool: scan_security
 *
 * Read-only security posture scan. Reports reliably-checkable hardening
 * findings without making any changes.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Scan_Security extends Aura_Tool_Base {

	public function get_name() {
		return 'scan_security';
	}

	public function get_description() {
		return 'Runs a read-only security posture scan: file-editing lockdown, debug exposure, SSL, default "admin" account, default table prefix, open registration, and outdated PHP. Returns scored findings. Makes no changes.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'score'    => 'integer — 0-100, higher is better',
			'passed'   => 'integer — number of checks passed',
			'total'    => 'integer — number of checks run',
			'findings' => 'array — { check, status: ok|warning|fail, message }',
		);
	}

	public function execute( $params ) {
		global $wpdb;

		$findings = array();

		// 1. File editing disabled in the dashboard.
		$file_edit_disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$findings[]         = array(
			'check'   => 'file_editing',
			'status'  => $file_edit_disabled ? 'ok' : 'warning',
			'message' => $file_edit_disabled
				? 'Theme/plugin file editing is disabled (DISALLOW_FILE_EDIT).'
				: 'Dashboard file editing is enabled. Consider defining DISALLOW_FILE_EDIT.',
		);

		// 2. Debug display off in production.
		$debug_display = defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
		$findings[]    = array(
			'check'   => 'debug_display',
			'status'  => $debug_display ? 'fail' : 'ok',
			'message' => $debug_display
				? 'WP_DEBUG output is being displayed. Disable WP_DEBUG_DISPLAY on production.'
				: 'Debug output is not displayed publicly.',
		);

		// 3. SSL on the site URL.
		$is_ssl     = 'https' === wp_parse_url( get_site_url(), PHP_URL_SCHEME );
		$findings[] = array(
			'check'   => 'ssl',
			'status'  => $is_ssl ? 'ok' : 'fail',
			'message' => $is_ssl ? 'Site URL uses HTTPS.' : 'Site URL is not HTTPS.',
		);

		// 4. Default "admin" username.
		$admin_exists = (bool) get_user_by( 'login', 'admin' );
		$findings[]   = array(
			'check'   => 'default_admin_user',
			'status'  => $admin_exists ? 'warning' : 'ok',
			'message' => $admin_exists
				? 'A user named "admin" exists — a common brute-force target.'
				: 'No default "admin" username.',
		);

		// 5. Default table prefix.
		$default_prefix = ( 'wp_' === $wpdb->prefix );
		$findings[]     = array(
			'check'   => 'table_prefix',
			'status'  => $default_prefix ? 'warning' : 'ok',
			'message' => $default_prefix
				? 'Database uses the default "wp_" table prefix.'
				: 'Database uses a custom table prefix.',
		);

		// 6. Open user registration.
		$can_register = (bool) get_option( 'users_can_register' );
		$findings[]   = array(
			'check'   => 'open_registration',
			'status'  => $can_register ? 'warning' : 'ok',
			'message' => $can_register
				? 'Open user registration is enabled.'
				: 'Open user registration is disabled.',
		);

		// 7. PHP version.
		$php_ok     = version_compare( phpversion(), '8.0', '>=' );
		$findings[] = array(
			'check'   => 'php_version',
			'status'  => $php_ok ? 'ok' : 'warning',
			'message' => $php_ok
				? 'PHP ' . phpversion() . ' is supported.'
				: 'PHP ' . phpversion() . ' is outdated; 8.0+ is recommended.',
		);

		$total  = count( $findings );
		$passed = 0;
		foreach ( $findings as $f ) {
			if ( 'ok' === $f['status'] ) {
				++$passed;
			}
		}

		return array(
			'score'        => $total > 0 ? (int) round( ( $passed / $total ) * 100 ) : 100,
			'passed'       => $passed,
			'total'        => $total,
			'findings'     => $findings,
			'generated_at' => gmdate( 'c' ),
		);
	}
}
