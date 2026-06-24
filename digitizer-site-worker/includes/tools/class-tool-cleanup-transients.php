<?php
/**
 * MCP Tool: cleanup_transients
 *
 * Deletes expired transients from the options table (DB hygiene).
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Cleanup_Transients extends Aura_Tool_Base {

	public function get_name() {
		return 'cleanup_transients';
	}

	public function get_description() {
		return 'Deletes expired transients left behind in the options table to reduce autoload bloat. Only removes transients that have already expired — active transients and other options are untouched.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'expired_before' => 'integer — expired transient timeouts before cleanup',
			'expired_after'  => 'integer — expired transient timeouts after cleanup',
			'deleted'        => 'integer — expired transients removed',
		);
	}

	public function execute( $params ) {
		global $wpdb;

		$before = $this->count_expired( $wpdb );

		if ( function_exists( 'delete_expired_transients' ) ) {
			delete_expired_transients( true );
		}

		$after   = $this->count_expired( $wpdb );
		$deleted = max( 0, $before - $after );

		return array(
			'expired_before' => $before,
			'expired_after'  => $after,
			'deleted'        => $deleted,
			'generated_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * Count expired transient timeout rows in the options table.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @return int
	 */
	private function count_expired( $wpdb ) {
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		); // phpcs:ignore WordPress.DB
	}
}
