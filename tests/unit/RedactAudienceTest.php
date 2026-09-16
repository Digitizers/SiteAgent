<?php
/**
 * Who gets redacted (#419 v2, spec §1.2): agents, identified by how they
 * authenticated — never a person in wp-admin, never the anonymous public,
 * never the Aura server's own system routes.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactAudienceTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
		$GLOBALS['_logged_in']                    = false;
		$GLOBALS['_rest_app_password']            = null;
	}

	private function audience( string $method, string $route ): bool {
		return Aura_Worker_Redact::is_audience( new WP_REST_Request( $method, $route ) );
	}

	/** @return array<string,array{0:string,1:string,2:bool,3:bool}> method, route, logged in, expected */
	public static function cases(): array {
		return array(
			'gateway execute, token-only'        => array( 'POST', '/aura/mcp/tools/execute', false, true ),
			'gateway execute, logged in'         => array( 'POST', '/aura/mcp/tools/execute', true, true ),
			'gateway execute, uppercase (R7)'    => array( 'POST', '/AURA/MCP/TOOLS/EXECUTE', false, true ),
			'gateway execute, trailing newline'  => array( 'POST', "/aura/mcp/tools/execute\n", false, true ),
			'gateway execute, trailing newline, uppercase' => array( 'POST', "/AURA/MCP/TOOLS/EXECUTE\n", false, true ),
			'local MCP client on mcp/v1'         => array( 'POST', '/mcp/mcp-adapter-default-server', true, true ),
			'Elementor MCP server'               => array( 'POST', '/mcp/elementor-mcp-server', true, true ),
			'agent on wp/v2 (read)'              => array( 'GET', '/wp/v2/pages/7', true, true ),
			'agent on wp/v2 (write)'             => array( 'POST', '/wp/v2/pages/7', true, true ),
			'another plugin route'               => array( 'GET', '/wc/v3/orders', true, true ),
			'lookalike namespace, logged in'     => array( 'GET', '/aura/mcpx/tools/execute', true, true ),
			'status'                             => array( 'GET', '/aura/v1/status', true, false ),
			'rules'                              => array( 'POST', '/aura/v2/rules', true, false ),
			'snapshots'                          => array( 'GET', '/aura/v2/snapshots', true, false ),
			'gateway tools/list'                 => array( 'POST', '/aura/mcp/tools/list', false, false ),
			'gateway context, logged in'         => array( 'GET', '/aura/mcp/context', true, false ),
			'lookalike execute-foo'              => array( 'POST', '/aura/mcp/tools/execute-foo', false, false ),
			'lookalike execute-foo, logged in'   => array( 'POST', '/aura/mcp/tools/execute-foo', true, false ),
			'lookalike namespace, anonymous'     => array( 'GET', '/aura/mcpx/tools/execute', false, false ),
			'anonymous public wp/v2'             => array( 'GET', '/wp/v2/posts', false, false ),
			'anonymous MCP'                      => array( 'POST', '/mcp/elementor-mcp-server', false, false ),
		);
	}

	/** @dataProvider cases */
	public function test_the_audience( string $method, string $route, bool $logged_in, bool $expected ): void {
		$GLOBALS['_logged_in'] = $logged_in;
		$this->assertSame( $expected, $this->audience( $method, $route ) );
	}

	/**
	 * Fix round 1, IMPORTANT #2 (#419): the gateway execute route is out of
	 * scope here — it is never a wp-admin surface, so a cookie session there
	 * doesn't apply and is covered separately by
	 * test_a_cookie_flag_is_ignored_on_the_gateway_execute_route(). This test
	 * is about a FOREIGN route, where the cookie flag still governs.
	 */
	public function test_a_cookie_session_is_never_the_audience(): void {
		$GLOBALS['_logged_in']                   = true;
		Aura_Worker_Rules::$cookie_auth_override = true;
		$this->assertFalse( $this->audience( 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_an_application_password_is_never_a_cookie_session(): void {
		$GLOBALS['_logged_in']                   = true;
		Aura_Worker_Rules::$cookie_auth_override = true;
		$GLOBALS['_rest_app_password']           = 'uuid-1';
		$this->assertFalse( Aura_Worker_Rules::cookie_authenticated() );
		$this->assertTrue( $this->audience( 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_outside_rest_nothing_is_the_audience(): void {
		$GLOBALS['_logged_in']                    = true;
		Aura_Worker_Rules::$rest_request_override = false;
		$this->assertFalse( Aura_Worker_Rules::serving_rest() );
		$this->assertFalse( $this->audience( 'POST', '/aura/mcp/tools/execute' ) );
		$this->assertFalse( $this->audience( 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_a_non_request_is_not_the_audience(): void {
		$GLOBALS['_logged_in'] = true;
		$this->assertFalse( Aura_Worker_Redact::is_audience( null ) );
		$this->assertFalse( Aura_Worker_Redact::is_audience( array( 'route' => '/wp/v2/pages' ) ) );
	}

	/**
	 * Fix round 1, IMPORTANT #1 (#419): core's own matcher —
	 * WP_REST_Server::match_request_to_handler() —
	 * `preg_match( '@^' . $route . '$@i', $path )` has no `D` modifier, so a
	 * trailing newline in the path (e.g. `?rest_route=/aura/mcp/tools/execute%0A`)
	 * still dispatches to tools/execute. is_gateway_execute_route() must
	 * agree with core exactly, not just on the clean route.
	 */
	public function test_the_gateway_route_helper_matches_the_way_core_dispatches(): void {
		$this->assertTrue( Aura_Worker_Redact::is_gateway_execute_route( '/aura/mcp/tools/execute' ) );
		$this->assertTrue( Aura_Worker_Redact::is_gateway_execute_route( '/AURA/MCP/TOOLS/EXECUTE' ) );
		$this->assertTrue( Aura_Worker_Redact::is_gateway_execute_route( "/aura/mcp/tools/execute\n" ) );
		// Core would NOT dispatch either of these to tools/execute — the
		// helper must not claim them either.
		$this->assertFalse( Aura_Worker_Redact::is_gateway_execute_route( "/aura/mcp/tools/execute\n\n" ) );
		$this->assertFalse( Aura_Worker_Redact::is_gateway_execute_route( "/aura/mcp/tools/execute\nx" ) );
	}

	/**
	 * Fix round 1, IMPORTANT #2 (#419): the gateway execute route is
	 * SiteAgent-token-authenticated and never a wp-admin surface, so
	 * is_audience() must not let a stray auth cookie (core's
	 * $wp_rest_auth_cookie without a verified nonce, or any other reason
	 * cookie_authenticated() might be true) hide it from redaction. A
	 * foreign route, unlike the gateway route, still respects the cookie
	 * flag — a logged-in cookie session there is still never the audience.
	 */
	public function test_a_cookie_flag_is_ignored_on_the_gateway_execute_route(): void {
		$GLOBALS['_logged_in']                   = true;
		Aura_Worker_Rules::$cookie_auth_override = true;
		$this->assertTrue( $this->audience( 'POST', '/aura/mcp/tools/execute' ) );
		$this->assertFalse( $this->audience( 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_the_wrappers_report_what_the_private_checks_decide(): void {
		Aura_Worker_Rules::$rest_request_override = true;
		$this->assertTrue( Aura_Worker_Rules::serving_rest() );
		Aura_Worker_Rules::$cookie_auth_override = false;
		$this->assertFalse( Aura_Worker_Rules::cookie_authenticated() );
		Aura_Worker_Rules::$cookie_auth_override = true;
		$this->assertTrue( Aura_Worker_Rules::cookie_authenticated() );
	}
}
