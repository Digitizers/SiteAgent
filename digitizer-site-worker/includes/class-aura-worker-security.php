<?php
/**
 * Security handler for SiteAgent.
 *
 * Implements three layers of authentication:
 * 1. WordPress Application Password (Basic Auth)
 * 2. Aura Site Token (X-Aura-Token header)
 * 3. IP Whitelist (optional)
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Security {

	/**
	 * Max failed token attempts per client IP before requests are throttled.
	 */
	const MAX_TOKEN_FAILURES = 10;

	/**
	 * Window (seconds) over which failed token attempts are counted.
	 */
	const TOKEN_FAILURE_WINDOW = 900; // 15 minutes.

	/**
	 * Hash a raw site token for storage / comparison.
	 *
	 * Tokens are stored as a SHA-256 hash so a database leak does not expose a
	 * usable bearer credential. The Aura dashboard holds the only raw copy.
	 *
	 * @param string $raw Raw token value.
	 * @return string 64-char lowercase hex SHA-256 digest.
	 */
	public static function hash_token( $raw ) {
		return hash( 'sha256', (string) $raw );
	}

	/**
	 * Whether a stored value is already a SHA-256 hash (vs a legacy raw token).
	 *
	 * @param string $value Stored token value.
	 * @return bool
	 */
	private function is_hashed( $value ) {
		return (bool) preg_match( '/^[0-9a-f]{64}$/', (string) $value );
	}

	/**
	 * Validate an incoming REST API request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_request( $request ) {
		// Layer 1a: Check IP whitelist (if configured).
		$ip_check = $this->check_ip_whitelist();
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		// Layer 1b: Check domain whitelist (if configured).
		$domain_check = $this->check_domain_whitelist( $request );
		if ( is_wp_error( $domain_check ) ) {
			return $domain_check;
		}

		// Layer 2: Verify Aura site token.
		$token_check = $this->check_aura_token( $request );
		if ( is_wp_error( $token_check ) ) {
			return $token_check;
		}

		// Layer 2.5: Token-only authorization. A valid token alone is sufficient
		// to manage the site (the standard site-management model). If no WP user
		// is authenticated on this request (i.e. no application-password Basic
		// auth), run as the connecting administrator so the Layer-3
		// current_user_can() route gates pass. Requests that DID send an
		// app-password keep their own user context untouched.
		if ( ! is_user_logged_in() ) {
			$run_as = $this->resolve_connect_user();
			if ( ! $run_as ) {
				return new WP_Error(
					'aura_not_configured',
					__( 'SiteAgent has no administrator to run as. Reconnect from the Aura dashboard.', 'digitizer-site-worker' ),
					array( 'status' => 500 )
				);
			}
			wp_set_current_user( $run_as );
		}

		// Layer 3: WordPress capability is checked by each route's
		// permission_callback (current_user_can()), now satisfied by the run-as
		// admin above for token-only requests.
		return true;
	}

	/**
	 * Check if the request IP is in the allowed list.
	 *
	 * @return bool|WP_Error True if allowed, WP_Error if blocked.
	 */
	private function check_ip_whitelist() {
		$allowed_ips = get_option( 'aura_worker_allowed_ips', '' );

		// If no IPs configured, allow all.
		if ( empty( trim( $allowed_ips ) ) ) {
			return true;
		}

		$allowed = array_filter( array_map( 'trim', explode( "\n", $allowed_ips ) ) );
		$client_ip = $this->get_client_ip();

		if ( ! in_array( $client_ip, $allowed, true ) ) {
			return new WP_Error(
				'aura_ip_blocked',
				__( 'Your IP address is not authorized.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if the request origin domain is in the allowed list.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if allowed, WP_Error if blocked.
	 */
	private function check_domain_whitelist( $request ) {
		$allowed_domains = get_option( 'aura_worker_allowed_domains', '' );

		// If no domains configured, allow all.
		if ( empty( trim( $allowed_domains ) ) ) {
			return true;
		}

		$allowed = array_filter( array_map( 'trim', explode( "\n", strtolower( $allowed_domains ) ) ) );

		// Check Origin header first, then Referer as fallback.
		$origin  = $request->get_header( 'Origin' );
		$referer = $request->get_header( 'Referer' );

		$request_host = '';
		if ( ! empty( $origin ) ) {
			$parsed = wp_parse_url( $origin );
			$request_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		} elseif ( ! empty( $referer ) ) {
			$parsed = wp_parse_url( $referer );
			$request_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		}

		// No Origin/Referer header — this is a server-to-server request (e.g. from the
		// Aura dashboard). Allow it through; the token check still protects the endpoint.
		if ( empty( $request_host ) ) {
			return true;
		}

		if ( ! in_array( $request_host, $allowed, true ) ) {
			return new WP_Error(
				'aura_domain_blocked',
				__( 'Your request origin domain is not authorized.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Verify the Aura site token from request headers.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	private function check_aura_token( $request ) {
		$provided_token = (string) $request->get_header( 'X-Aura-Token' );
		$stored_token   = get_option( 'aura_worker_site_token', '' );

		if ( empty( $stored_token ) ) {
			return new WP_Error(
				'aura_not_configured',
				__( 'SiteAgent is not configured. Please set a site token.', 'digitizer-site-worker' ),
				array( 'status' => 500 )
			);
		}

		// Throttle brute-force attempts before doing any comparison.
		$throttle = $this->check_token_throttle();
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		$valid = false;
		if ( '' !== $provided_token ) {
			if ( $this->is_hashed( $stored_token ) ) {
				// Modern path: stored value is a SHA-256 hash of the token.
				$valid = hash_equals( $stored_token, self::hash_token( $provided_token ) );
			} else {
				// Legacy path: stored value is a raw token from an older version.
				// Compare raw, then opportunistically migrate to a stored hash.
				$valid = hash_equals( $stored_token, $provided_token );
				if ( $valid ) {
					update_option( 'aura_worker_site_token', self::hash_token( $provided_token ) );
				}
			}
		}

		if ( ! $valid ) {
			$this->record_token_failure();
			return new WP_Error(
				'aura_invalid_token',
				__( 'Invalid or missing Aura token.', 'digitizer-site-worker' ),
				array( 'status' => 401 )
			);
		}

		// Successful auth clears the failure counter for this IP.
		delete_transient( $this->token_failure_key() );

		return true;
	}

	/**
	 * Resolve the administrator to run token-only requests as.
	 *
	 * Prefers the stored connecting admin; falls back to the first administrator.
	 * The returned user MUST hold manage_options so a token can never grant more
	 * than an administrator already has.
	 *
	 * @return int User ID, or 0 if no suitable administrator exists.
	 */
	private function resolve_connect_user() {
		$stored = (int) get_option( 'aura_worker_connect_user_id', 0 );
		if ( $stored > 0 && user_can( $stored, 'manage_options' ) ) {
			return $stored;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		if ( ! empty( $admins ) ) {
			return (int) $admins[0];
		}

		return 0;
	}

	/**
	 * Transient key for tracking failed token attempts from the current IP.
	 *
	 * @return string
	 */
	private function token_failure_key() {
		return 'aura_worker_tokfail_' . md5( $this->get_client_ip() );
	}

	/**
	 * Block the request if this IP has exceeded the failed-attempt threshold.
	 *
	 * @return bool|WP_Error True if under the limit, WP_Error (429) if throttled.
	 */
	private function check_token_throttle() {
		$failures = (int) get_transient( $this->token_failure_key() );
		if ( $failures >= self::MAX_TOKEN_FAILURES ) {
			return new WP_Error(
				'aura_too_many_attempts',
				__( 'Too many failed authentication attempts. Try again later.', 'digitizer-site-worker' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Increment the failed-attempt counter for the current IP.
	 */
	private function record_token_failure() {
		$key      = $this->token_failure_key();
		$failures = (int) get_transient( $key );
		set_transient( $key, $failures + 1, self::TOKEN_FAILURE_WINDOW );
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string Client IP.
	 */
	private function get_client_ip() {
		// Only trust REMOTE_ADDR — proxy headers (X-Forwarded-For, CF-Connecting-IP)
		// are client-controlled and trivially spoofed. Managed hosts and reverse proxies
		// should be configured to set REMOTE_ADDR correctly at the server layer.
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
	}

	/**
	 * Permission callback for REST routes requiring admin access.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_admin_permission( $request ) {
		// First validate Aura-specific security layers.
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Then check WordPress capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to perform this action.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for plugin update routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_update_plugins_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to update plugins.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for core update routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_update_core_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! current_user_can( 'update_core' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to update WordPress core.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for theme update routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_update_themes_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! current_user_can( 'update_themes' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to update themes.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for read-only routes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if authorized.
	 */
	public function check_read_permission( $request ) {
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aura_insufficient_permissions',
				__( 'You do not have permission to view this data.', 'digitizer-site-worker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
