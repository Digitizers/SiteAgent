<?php
/**
 * PHPUnit bootstrap for SiteAgent (digitizer-site-worker) unit tests.
 *
 * SiteAgent's classes are plain (global-namespace) PHP that lean on a small set
 * of WordPress functions. This bootstrap provides just enough WordPress surface
 * — configurable option/transient stores, a WP_Error, a WP_REST_Request stub,
 * and a real-filesystem WP_Filesystem shim — for the tool base, tool registry,
 * security layer, and rollback engine to run without a WordPress install.
 *
 * State the tests drive (reset in each test's setUp()):
 *   $GLOBALS['_options']       — get_option/update_option/delete_option store
 *   $GLOBALS['_transients']    — get/set/delete_transient store
 *   $GLOBALS['_caps']          — current_user_can control (null = allow all)
 *   $GLOBALS['_logged_in']     — is_user_logged_in() return
 *   $GLOBALS['_admins']        — get_users() administrator IDs
 *   $GLOBALS['_current_user']  — last wp_set_current_user() id
 *   $GLOBALS['_did_actions']   — do_action() call log
 *
 * @package Aura_Worker\Tests
 */

// ---------------------------------------------------------------------------
// Constants + paths
// ---------------------------------------------------------------------------

define( 'SA_TESTS_DIR', __DIR__ );
define( 'SA_PLUGIN_DIR', dirname( __DIR__ ) . '/digitizer-site-worker' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Filesystem sandbox for the rollback engine. Kept under the system temp dir so
// tests never touch a real wp-content. Individual tests clean sub-paths.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/sa-wp-content' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
}
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// ---------------------------------------------------------------------------
// Mutable state used by the stubs
// ---------------------------------------------------------------------------

$GLOBALS['_options']      = array();
$GLOBALS['_transients']   = array();
$GLOBALS['_caps']         = null;   // null = allow all (current_user_can).
$GLOBALS['_logged_in']    = false;
$GLOBALS['_admins']       = array();
$GLOBALS['_current_user'] = 0;
$GLOBALS['_did_actions']  = array();
$GLOBALS['_filters']      = array();

// ---------------------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------------------

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $string ): string {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		$json = json_encode( $data, $options, $depth );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $json : false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// --- Option store ----------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['_options'] ) ? $GLOBALS['_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['_options'][ $option ] );
		return true;
	}
}

// --- Transient store --------------------------------------------------------

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return array_key_exists( $key, $GLOBALS['_transients'] ) ? $GLOBALS['_transients'][ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['_transients'][ $key ] );
		return true;
	}
}

// --- Auth / capabilities ----------------------------------------------------

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, ...$args ): bool {
		if ( null === $GLOBALS['_caps'] ) {
			return true;
		}
		return in_array( $cap, (array) $GLOBALS['_caps'], true );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['_logged_in'];
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id, string $name = '' ) {
		$GLOBALS['_current_user'] = $id;
		return $id;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, string $cap, ...$args ): bool {
		// Administrators in $GLOBALS['_admins'] hold every capability.
		return in_array( (int) $user, array_map( 'intval', $GLOBALS['_admins'] ), true );
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = array() ): array {
		return $GLOBALS['_admins'];
	}
}

// --- Hooks ------------------------------------------------------------------

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, ...$args ): void {
		$GLOBALS['_did_actions'][] = array( 'tag' => $tag, 'args' => $args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['_filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		foreach ( $GLOBALS['_filters'][ $tag ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

// --- Filesystem (used by the rollback engine) -------------------------------

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $dir ): bool {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		return @unlink( $file );
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			$wp_filesystem = new SA_Test_Filesystem();
		}
		return true;
	}
}

// ---------------------------------------------------------------------------
// Stub classes
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message( string $code = '' ): string {
			return $this->message;
		}

		public function get_error_data( string $code = '' ) {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub — carries headers + a route.
	 */
	class WP_REST_Request {
		private array $headers = array();
		private string $route  = '/aura/v1/status';

		public function set_header( string $key, $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( string $key ) {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function set_route( string $route ): void {
			$this->route = $route;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'SA_Test_Wpdb' ) ) {
	/**
	 * Minimal $wpdb stub. get_results() returns whatever a test placed in
	 * $GLOBALS['_db_rows'] and records the SQL it was asked to run.
	 */
	class SA_Test_Wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		public string $last_query = '';

		public function get_results( $query, $output = OBJECT ) {
			$this->last_query = (string) $query;
			return $GLOBALS['_db_rows'];
		}
	}

	$GLOBALS['_db_rows'] = array();
	$GLOBALS['wpdb']     = new SA_Test_Wpdb();
}

if ( ! class_exists( 'SA_Test_Filesystem' ) ) {
	/**
	 * Real-filesystem shim standing in for WP_Filesystem in the rollback tests.
	 * Only the methods the rollback engine calls are implemented.
	 */
	class SA_Test_Filesystem {
		public function put_contents( string $file, string $contents, $mode = false ): bool {
			return false !== file_put_contents( $file, $contents );
		}

		/**
		 * Recursively delete a path. Mirrors $wp_filesystem->delete( $dir, true, 'd' ).
		 */
		public function delete( string $path, bool $recursive = false, $type = false ): bool {
			if ( is_file( $path ) || is_link( $path ) ) {
				return @unlink( $path );
			}
			if ( ! is_dir( $path ) ) {
				return false;
			}
			$items = array_diff( scandir( $path ), array( '.', '..' ) );
			foreach ( $items as $item ) {
				$this->delete( $path . '/' . $item, true, false );
			}
			return @rmdir( $path );
		}
	}
}

// ---------------------------------------------------------------------------
// Load the classes under test
// ---------------------------------------------------------------------------

require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-base.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-tools.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-security.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-rollback.php';
require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-snapshots.php';

// Companion Power Pack tool classes (extend Aura_Tool_Base above).
define( 'SA_POWER_PACK_DIR', dirname( __DIR__ ) . '/siteagent-power-pack' );
require_once SA_POWER_PACK_DIR . '/includes/tools/class-tool-fs-read.php';
require_once SA_POWER_PACK_DIR . '/includes/tools/class-tool-db-query.php';

/**
 * Reset all mutable stub state. Call from each test's setUp().
 */
function sa_reset_state(): void {
	$GLOBALS['_options']      = array();
	$GLOBALS['_transients']   = array();
	$GLOBALS['_caps']         = null;
	$GLOBALS['_logged_in']    = false;
	$GLOBALS['_admins']       = array();
	$GLOBALS['_current_user'] = 0;
	$GLOBALS['_did_actions']  = array();
	$GLOBALS['_filters']      = array();
	$GLOBALS['_db_rows']      = array();
	if ( isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb']->last_error = '';
		$GLOBALS['wpdb']->last_query = '';
	}
	$_SERVER['REMOTE_ADDR']   = '203.0.113.10';
}
