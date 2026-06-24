<?php
/**
 * MCP Tool: clear_caches
 *
 * Flushes the object cache, opcode cache, and any detected page-cache plugins.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Clear_Caches extends Aura_Tool_Base {

	public function get_name() {
		return 'clear_caches';
	}

	public function get_description() {
		return 'Flushes caches: the WordPress object cache, PHP opcache, and any detected page-cache plugins (W3 Total Cache, WP Super Cache, WP Rocket, LiteSpeed, Autoptimize). Returns the list of caches that were cleared.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'cleared' => 'array — identifiers of the caches that were flushed',
			'count'   => 'integer',
		);
	}

	public function execute( $params ) {
		$cleared = array();

		if ( function_exists( 'wp_cache_flush' ) && wp_cache_flush() ) {
			$cleared[] = 'object_cache';
		}

		if ( function_exists( 'opcache_reset' ) ) {
			// @ — opcache may be disabled or restricted; ignore failures.
			if ( @opcache_reset() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$cleared[] = 'opcache';
			}
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$cleared[] = 'w3_total_cache';
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$cleared[] = 'wp_super_cache';
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$cleared[] = 'wp_rocket';
		}

		// Autoptimize.
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			call_user_func( array( 'autoptimizeCache', 'clearall' ) );
			$cleared[] = 'autoptimize';
		}

		// LiteSpeed Cache (no-op if the plugin isn't active).
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$cleared[] = 'litespeed_cache';
		}

		// Let any other cache layer hook in.
		do_action( 'aura_worker_clear_caches' );

		return array(
			'cleared'      => $cleared,
			'count'        => count( $cleared ),
			'generated_at' => gmdate( 'c' ),
		);
	}
}
