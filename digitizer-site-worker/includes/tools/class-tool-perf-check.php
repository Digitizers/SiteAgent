<?php
/**
 * MCP Tool: perf_check
 *
 * Read-only performance posture scan. Reports reliably-checkable, no-AI-cost
 * findings — caching layers, PHP version, autoload weight, plugin count, memory
 * limit, expired transients. Makes no changes.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Perf_Check extends Aura_Tool_Base {

	public function get_name() {
		return 'perf_check';
	}

	public function get_description() {
		return 'Runs a read-only performance posture scan: persistent object cache, OPcache, a page-cache plugin, PHP version, autoload weight, active plugin count, PHP memory limit, and expired transients. Returns scored findings. Makes no changes.';
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
			'metrics'  => 'array — { autoload_bytes, active_plugins, memory_limit, expired_transients }',
		);
	}

	/**
	 * This is a read-only tool: it never mutates the site.
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
		global $wpdb;

		$findings = array();

		// 1. Persistent object cache.
		$object_cache = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$findings[]   = array(
			'check'   => 'object_cache',
			'status'  => $object_cache ? 'ok' : 'warning',
			'message' => $object_cache
				? 'A persistent object cache is in use.'
				: 'No persistent object cache (Redis/Memcached) detected — a common performance win.',
		);

		// 2. OPcache.
		$opcache_on = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status     = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$opcache_on = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		}
		$findings[] = array(
			'check'   => 'opcache',
			'status'  => $opcache_on ? 'ok' : 'warning',
			'message' => $opcache_on ? 'PHP OPcache is enabled.' : 'PHP OPcache does not appear to be enabled.',
		);

		// 3. Page-cache plugin.
		$cache_plugins = array(
			'WP_ROCKET_VERSION',
			'W3TC',
			'WPFC_MAIN_PATH',          // WP Fastest Cache.
			'LSCWP_V',                 // LiteSpeed Cache.
		);
		$page_cache = defined( 'WPCACHEHOME' ); // WP Super Cache.
		foreach ( $cache_plugins as $const ) {
			if ( defined( $const ) ) {
				$page_cache = true;
				break;
			}
		}
		$findings[] = array(
			'check'   => 'page_cache',
			'status'  => $page_cache ? 'ok' : 'warning',
			'message' => $page_cache
				? 'A page-cache plugin is active.'
				: 'No page-cache plugin detected — full-page caching usually helps anonymous traffic.',
		);

		// 4. PHP version.
		$php     = phpversion();
		$php_ok  = version_compare( $php, '8.1', '>=' );
		$php_old = version_compare( $php, '8.0', '<' );
		$findings[] = array(
			'check'   => 'php_version',
			'status'  => $php_ok ? 'ok' : ( $php_old ? 'fail' : 'warning' ),
			'message' => $php_ok
				? "PHP $php is current."
				: "PHP $php is older; 8.1+ is recommended for performance.",
		);

		// 5. Autoload weight. WP 6.6+ stores several "autoload on" values
		// (yes, on, auto-on, auto), so build the IN clause from core's list
		// instead of hard-coding 'yes' (which would under-report on new sites).
		// Guarded by function_exists because the plugin still supports WP 6.2.
		//
		// Both queries below are deliberate uncached direct calls: a perf check
		// reports the database's state right now, and neither the autoload sum nor
		// the expired-transient count has a core API. The `IN (...)` list is %s
		// placeholders built from a counted array and passed to prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$autoload_values = function_exists( 'wp_autoload_values_to_autoload' )
			? wp_autoload_values_to_autoload()
			: array( 'yes' );
		$placeholders    = implode( ', ', array_fill( 0, count( $autoload_values ), '%s' ) );
		$autoload_bytes  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ($placeholders)",
				$autoload_values
			)
		);
		$autoload_mb    = $autoload_bytes / 1048576;
		$findings[]     = array(
			'check'   => 'autoload_weight',
			'status'  => $autoload_mb > 3 ? 'fail' : ( $autoload_mb > 1 ? 'warning' : 'ok' ),
			'message' => sprintf( 'Autoloaded options total %s.', size_format( $autoload_bytes ) )
				. ( $autoload_mb > 1 ? ' Over 1MB autoloaded on every request slows things down.' : '' ),
		);

		// 6. Active plugin count.
		$active_plugins = count( (array) get_option( 'active_plugins', array() ) );
		$findings[]     = array(
			'check'   => 'plugin_count',
			'status'  => $active_plugins > 40 ? 'warning' : 'ok',
			'message' => "$active_plugins active plugins." . ( $active_plugins > 40 ? ' A large plugin count can add overhead.' : '' ),
		);

		// 7. PHP memory limit.
		$mem_bytes  = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT );
		$mem_ok     = $mem_bytes >= 256 * 1048576;
		$findings[] = array(
			'check'   => 'memory_limit',
			'status'  => $mem_ok ? 'ok' : 'warning',
			'message' => sprintf( 'WP memory limit is %s.', WP_MEMORY_LIMIT ) . ( $mem_ok ? '' : ' 256M+ is recommended.' ),
		);

		// 8. Expired transients.
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$findings[] = array(
			'check'   => 'expired_transients',
			'status'  => $expired > 100 ? 'warning' : 'ok',
			'message' => "$expired expired transients." . ( $expired > 100 ? ' Clean them up to reduce autoload/DB bloat.' : '' ),
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
			'metrics'      => array(
				'autoload_bytes'     => $autoload_bytes,
				'active_plugins'     => $active_plugins,
				'memory_limit'       => WP_MEMORY_LIMIT,
				'expired_transients' => $expired,
			),
			'generated_at' => gmdate( 'c' ),
		);
	}
}
