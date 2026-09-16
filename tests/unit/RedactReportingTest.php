<?php
/**
 * What read redaction reports (#419 v2, spec §4): the two 24h counts in
 * audit_rules, beside warned/blocked, and `/status` → `redaction: { v: 1 }`
 * so Aura can tell a 2.18.0 site from one that needs an upgrade.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactReportingTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::init();
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function audit(): array {
		$res = ( new Aura_Worker_Tools() )->execute_tool( 'audit_rules', array() );
		$this->assertTrue( $res['success'] );
		return $res['result']['enforcement'];
	}

	public function test_both_counters_are_counted_and_reported(): void {
		do_action( 'aura_worker_redacted', 3, '/wp/v2/pages/7' );
		do_action( 'aura_worker_redacted', 1, '/mcp/elementor-mcp-server' );
		do_action( 'aura_worker_placeholder_refused', '/mcp/elementor-mcp-server' );

		$e = $this->audit();

		$this->assertSame( 2, $e['redacted_24h'], 'responses, not replacements' );
		$this->assertSame( 1, $e['placeholder_refused_24h'] );
		$this->assertSame( 0, $e['blocked_24h'], 'the rule counters are separate' );
		$this->assertSame( 0, $e['warned_24h'] );
	}

	public function test_the_counts_come_from_the_real_seams(): void {
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true;

		$page = array( 'meta' => array( '_elementor_data' => wp_json_encode( array( array( 'settings' => array( 'webhooks' => 'https://hook.eu1.make.com/abc' ) ) ) ) ) );
		apply_filters( 'rest_pre_echo_response', $page, null, new WP_REST_Request( 'GET', '/wp/v2/pages/7' ) );

		$write = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$write->set_body_params( array( 'title' => 'aura-redacted:v1:make' ) );
		apply_filters( 'rest_request_before_callbacks', null, array(), $write );

		$e = $this->audit();
		$this->assertSame( 1, $e['redacted_24h'] );
		$this->assertSame( 1, $e['placeholder_refused_24h'] );
	}

	public function test_the_window_is_24_hours(): void {
		$now = 1_800_000_000;
		Aura_Worker_Redact::record_redacted( 1, '/wp/v2/pages/7', $now - DAY_IN_SECONDS - 2 * HOUR_IN_SECONDS );
		Aura_Worker_Redact::record_redacted( 1, '/wp/v2/pages/7', $now - HOUR_IN_SECONDS );
		$this->assertSame( 1, Aura_Worker_Rules::count_24h( Aura_Worker_Redact::REDACTED_COUNTER, $now ) );
	}

	public function test_status_reports_the_redaction_fragment_as_an_object(): void {
		$api  = new Aura_Worker_API( new Aura_Worker_Security() );
		$body = $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();

		$this->assertArrayHasKey( 'redaction', $body );
		$this->assertIsObject( $body['redaction'] );
		$this->assertSame( 1, $body['redaction']->v );
		$this->assertSame( '{"v":1}', wp_json_encode( $body['redaction'] ) );
	}
}
