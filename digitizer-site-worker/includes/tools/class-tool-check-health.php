<?php
/**
 * MCP Tool: check_health
 *
 * Read-only post-action health gate — wraps Aura_Worker_Health so an agent can
 * verify a site is still serving traffic (HTTP, PHP errors, white screen, DB)
 * before and after mutating operations.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Check_Health extends Aura_Tool_Base {

	public function get_name() {
		return 'check_health';
	}

	public function get_description() {
		return 'Runs the live health checks (home-page HTTP status, recent PHP fatals, white-screen, DB connection) and returns whether the site is healthy. Read-only — ideal as a health gate around updates.';
	}

	public function get_parameters() {
		return array();
	}

	public function get_returns() {
		return array(
			'healthy' => 'boolean — true when every check passed',
			'checks'  => 'array — per-check { status: pass|fail, detail }',
		);
	}

	public function execute( $params ) {
		$file = dirname( __DIR__ ) . '/class-aura-worker-health.php';
		if ( ! class_exists( 'Aura_Worker_Health' ) && file_exists( $file ) ) {
			require_once $file;
		}
		if ( ! class_exists( 'Aura_Worker_Health' ) ) {
			throw new Exception( 'Health module is unavailable.' );
		}

		$health = new Aura_Worker_Health();
		$report = $health->run_health_check();
		$report['generated_at'] = gmdate( 'c' );

		return $report;
	}
}
