<?php
/**
 * Aura's own page snapshot — the one unredacted read (#419 v2, spec §1.3).
 * A signed `X-Aura-Unredacted-Grant`, honoured on exactly two request
 * shapes, exempts exactly that response; anything wrong with it on a
 * recognised shape is a loud 403, never a silent redaction.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactGrantTest extends TestCase {

	private const N8N    = 'https://n8n.example.com/webhook/abc';
	private const SERVER = 'elementor-mcp-server';
	private const EXPORT = 'unredacted-read:mcp/elementor-mcp-server#export-page';

	protected function setUp(): void {
		sa_reset_state();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		sa_install_gateway_key();
		sa_token_hash();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true; // Aura's Application Password
		$GLOBALS['_rest_app_password']            = 'uuid-aura';
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function grant( string $tool, array $params, array $over = array() ): string {
		return sa_sign_ruleset(
			array_merge(
				array(
					'v'             => 1,
					'tool'          => $tool,
					'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $params ) ),
					'site'          => sa_token_hash(),
					'nonce'         => bin2hex( random_bytes( 16 ) ),
					'iat'           => time(),
					'exp'           => time() + 300,
				),
				$over
			)
		);
	}

	/** A JSON-RPC request to an MCP adapter server. */
	private function rpc( string $route, $body, string $method = 'POST' ): WP_REST_Request {
		$req = new WP_REST_Request( $method, $route );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
		return $req;
	}

	private function export_call( array $arguments = array( 'post_id' => 7 ), string $server = self::SERVER ): WP_REST_Request {
		return $this->rpc(
			'/mcp/' . $server,
			array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page', 'arguments' => $arguments ) )
		);
	}

	private function execute_call( string $tool, array $params ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/aura/mcp/tools/execute' );
		$req->set_param( 'tool', $tool );
		$req->set_param( 'params', $params );
		return $req;
	}

	/** What export-page answers through the adapter: a text carrier plus structuredContent. */
	private function export_body(): array {
		$export = array(
			'json' => array(
				array( 'id' => 'frm1', 'elType' => 'widget', 'settings' => array( 'webhooks' => self::N8N ) ),
			),
		);
		return array(
			'jsonrpc' => '2.0',
			'id'      => 5,
			'result'  => array(
				'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $export ) ) ),
				'structuredContent' => $export,
			),
		);
	}

	private function before( WP_REST_Request $req ) {
		return apply_filters( 'rest_request_before_callbacks', null, array(), $req );
	}

	private function echoed( WP_REST_Request $req, $body ) {
		return apply_filters( 'rest_pre_echo_response', $body, null, $req );
	}

	private function assertRefused( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'the tool must not run' );
		$this->assertSame( 'aura_unredacted_grant_invalid', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_the_check_is_registered_after_the_rules_guard(): void {
		$this->assertSame( 6, has_filter( 'rest_request_before_callbacks', array( 'Aura_Worker_Redact', 'before_callbacks' ) ) );
	}

	// --- the MCP JSON-RPC row --------------------------------------------

	public function test_a_valid_export_page_grant_exempts_that_response_and_only_that_one(): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( self::EXPORT, array( 'post_id' => 7 ) ) );
		$body = $this->export_body();

		$this->assertNull( $this->before( $req ), 'verified: the tool runs' );
		$this->assertSame( $body, $this->echoed( $req, $body ), 'the capture is served unredacted' );

		$again = $this->export_call();
		$this->assertNull( $this->before( $again ) );
		$this->assertNotSame( $body, $this->echoed( $again, $body ), 'a second request in the same process is redacted' );

		$this->assertNotSame( $body, $this->echoed( $req, $body ), 'the exemption is spent with its response' );
	}

	public function test_an_absent_arguments_binds_as_an_empty_object(): void {
		$req = $this->rpc( '/mcp/' . self::SERVER, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page' ) ) );
		$this->assertSame( array( 'tool' => self::EXPORT, 'params' => array() ), Aura_Worker_Redact::grant_shape( $req ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( self::EXPORT, array() ) );
		$this->assertNull( $this->before( $req ) );
	}

	/** @return array<string,array{0:string,1:array,2:array}> grant tool, grant params, grant overrides */
	public static function wrong_grants(): array {
		return array(
			'other arguments' => array( self::EXPORT, array( 'post_id' => 8 ), array() ),
			'other tool'      => array( 'unredacted-read:mcp/elementor-mcp-server#get-element-settings', array( 'post_id' => 7 ), array() ),
			'other server'    => array( 'unredacted-read:mcp/other-server#export-page', array( 'post_id' => 7 ), array() ),
			'gateway row'     => array( 'unredacted-read:aura/mcp#export-page', array( 'post_id' => 7 ), array() ),
			'no prefix'       => array( 'export-page', array( 'post_id' => 7 ), array() ),
			'other site'      => array( self::EXPORT, array( 'post_id' => 7 ), array( 'site' => hash( 'sha256', 'another-site' ) ) ),
			'expired'         => array( self::EXPORT, array( 'post_id' => 7 ), array( 'iat' => time() - 1000, 'exp' => time() - 700 ) ),
		);
	}

	/** @dataProvider wrong_grants */
	public function test_a_grant_that_does_not_bind_this_call_is_refused( string $tool, array $params, array $over ): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( $tool, $params, $over ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_grant_signed_by_another_key_is_refused(): void {
		$req = $this->export_call();
		$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$payload = array(
			'v' => 1, 'tool' => self::EXPORT, 'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( array( 'post_id' => 7 ) ) ),
			'site' => sa_token_hash(), 'nonce' => bin2hex( random_bytes( 16 ) ), 'iat' => time(), 'exp' => time() + 300,
		);
		$req->set_header( 'X-Aura-Unredacted-Grant', sa_sign_ruleset( $payload, $other ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_replayed_grant_is_refused(): void {
		$grant = $this->grant( self::EXPORT, array( 'post_id' => 7 ) );
		$first = $this->export_call();
		$first->set_header( 'X-Aura-Unredacted-Grant', $grant );
		$this->assertNull( $this->before( $first ) );

		$second = $this->export_call();
		$second->set_header( 'X-Aura-Unredacted-Grant', $grant );
		$this->assertRefused( $this->before( $second ) );
	}

	/** @return array<string,array{0:string,1:mixed,2:string}> route, body, method */
	public static function unrecognised_shapes(): array {
		$call = array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page', 'arguments' => array( 'post_id' => 7 ) ) );
		return array(
			'a JSON-RPC batch'      => array( '/mcp/elementor-mcp-server', array( $call ), 'POST' ),
			'tools/list'            => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'method' => 'tools/list' ) ), 'POST' ),
			'initialize'            => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'method' => 'initialize' ) ), 'POST' ),
			'two segments'          => array( '/mcp/a/b', $call, 'POST' ),
			'a wp/v2 route'         => array( '/wp/v2/pages/7', $call, 'POST' ),
			'not POST'              => array( '/mcp/elementor-mcp-server', $call, 'DELETE' ),
			'arguments not object'  => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'params' => array( 'name' => 'export-page', 'arguments' => array( 7 ) ) ) ), 'POST' ),
			'no tool name'          => array( '/mcp/elementor-mcp-server', array_merge( $call, array( 'params' => array( 'arguments' => array( 'post_id' => 7 ) ) ) ), 'POST' ),
		);
	}

	/** @dataProvider unrecognised_shapes */
	public function test_on_any_other_shape_the_header_is_ignored_and_the_response_redacted( string $route, $body, string $method ): void {
		$grant = $this->grant( self::EXPORT, array( 'post_id' => 7 ) );
		$req   = $this->rpc( $route, $body, $method );
		$req->set_header( 'X-Aura-Unredacted-Grant', $grant );

		$this->assertNull( $this->before( $req ), 'ignored, not refused' );
		$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ) );

		// Ignored means not spent: the same grant still works where it belongs.
		$real = $this->export_call();
		$real->set_header( 'X-Aura-Unredacted-Grant', $grant );
		$this->assertNull( $this->before( $real ) );
		$this->assertSame( $this->export_body(), $this->echoed( $real, $this->export_body() ) );
	}

	public function test_a_site_without_a_usable_gateway_key_ignores_the_header(): void {
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( 'too short' ); // configured, unusable
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', 'anything.at-all' );

		$this->assertNull( $this->before( $req ), 'the tool runs' );
		$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ), 'and its answer is redacted' );
	}

	public function test_an_unbound_site_answers_its_own_refusal(): void {
		sa_set_marker();
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( self::EXPORT, array( 'post_id' => 7 ) ) );
		$res = $this->before( $req );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aura_site_unbound', $res->get_error_code() );
	}

	public function test_a_cookie_session_never_spends_a_grant(): void {
		Aura_Worker_Rules::$cookie_auth_override = true;
		$GLOBALS['_rest_app_password']           = null;
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', 'not-even-a-grant' );
		$this->assertNull( $this->before( $req ), 'not the audience: nothing to exempt, nothing refused' );
	}

	public function test_an_earlier_refusal_passes_through_untouched(): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', 'garbage' );
		$earlier = new WP_Error( 'rest_invalid_param', 'bad', array( 'status' => 400 ) );
		$this->assertSame( $earlier, apply_filters( 'rest_request_before_callbacks', $earlier, array(), $req ) );
	}

	// --- the gateway row --------------------------------------------------

	public function test_a_valid_gateway_grant_exempts_that_tools_execute_response(): void {
		$req = $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( 'unredacted-read:aura/mcp#snapshot_get', array( 'id' => 'snap_1' ) ) );
		$body = array( 'success' => true, 'result' => $this->export_body() );

		$this->assertNull( $this->before( $req ) );
		$this->assertSame( $body, $this->echoed( $req, $body ) );
	}

	public function test_the_gateway_row_binds_the_params_the_executor_runs(): void {
		$req = $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) );
		$this->assertSame(
			array( 'tool' => 'unredacted-read:aura/mcp#snapshot_get', 'params' => array( 'id' => 'snap_1' ) ),
			Aura_Worker_Redact::grant_shape( $req )
		);
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( 'unredacted-read:aura/mcp#snapshot_get', array( 'id' => 'snap_2' ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_the_gateway_row_does_not_need_a_logged_in_user(): void {
		$GLOBALS['_logged_in']         = false; // token-only, as the gateway runs
		$GLOBALS['_rest_app_password'] = null;
		$req = $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( 'unredacted-read:mcp/elementor-mcp-server#snapshot_get', array( 'id' => 'snap_1' ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	// --- core-style route matching (Task 2 ruling) ---------------------------

	/** @return array<string,array{0:string}> routes core dispatches to the adapter server */
	public static function core_matched_mcp_routes(): array {
		return array(
			'one trailing newline' => array( "/mcp/elementor-mcp-server\n" ),
			'upper case'           => array( '/MCP/Elementor-MCP-Server' ),
		);
	}

	/** @dataProvider core_matched_mcp_routes */
	public function test_the_mcp_row_is_recognised_the_way_core_dispatches_it( string $route ): void {
		$call = array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page', 'arguments' => array( 'post_id' => 7 ) ) );
		$req  = $this->rpc( $route, $call );
		$this->assertSame( array( 'tool' => self::EXPORT, 'params' => array( 'post_id' => 7 ) ), Aura_Worker_Redact::grant_shape( $req ), 'the server name binds in its registered (lower) case' );

		$req->set_header( 'X-Aura-Unredacted-Grant', 'garbage' );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_route_core_would_not_dispatch_there_is_not_the_mcp_row(): void {
		$call = array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page' ) );
		$this->assertNull( Aura_Worker_Redact::grant_shape( $this->rpc( "/mcp/elementor-mcp-server\n\n", $call ) ) );
		$this->assertNull( Aura_Worker_Redact::grant_shape( $this->rpc( "/mcp/elementor-mcp-server\nx", $call ) ) );
		$this->assertNull( Aura_Worker_Redact::grant_shape( $this->rpc( '/mcp/elementor_mcp_server', $call ) ) );
	}

	/** @return array<string,array{0:string}> */
	public static function core_matched_gateway_routes(): array {
		return array(
			'one trailing newline' => array( "/aura/mcp/tools/execute\n" ),
			'upper case'           => array( '/AURA/MCP/Tools/Execute' ),
		);
	}

	/** @dataProvider core_matched_gateway_routes */
	public function test_the_gateway_row_is_recognised_the_way_core_dispatches_it( string $route ): void {
		$req = $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) );
		$req->set_route( $route );
		$this->assertSame(
			array( 'tool' => 'unredacted-read:aura/mcp#snapshot_get', 'params' => array( 'id' => 'snap_1' ) ),
			Aura_Worker_Redact::grant_shape( $req )
		);
		$req->set_header( 'X-Aura-Unredacted-Grant', 'garbage' );
		$this->assertRefused( $this->before( $req ) );
	}

	// --- binding details -----------------------------------------------------

	public function test_an_empty_or_null_arguments_object_binds_as_empty_params(): void {
		foreach ( array( '{}', 'null' ) as $arguments ) {
			$req = new WP_REST_Request( 'POST', '/mcp/' . self::SERVER );
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"export-page","arguments":' . $arguments . '}}' );
			$this->assertSame( array( 'tool' => self::EXPORT, 'params' => array() ), Aura_Worker_Redact::grant_shape( $req ), $arguments );
		}
	}

	public function test_a_body_that_is_not_json_is_not_the_mcp_row(): void {
		$req = $this->export_call();
		$req->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$this->assertNull( Aura_Worker_Redact::grant_shape( $req ) );
	}

	public function test_a_gateway_call_without_a_string_tool_is_not_the_gateway_row(): void {
		$req = $this->execute_call( 'snapshot_get', array() );
		$req->set_param( 'tool', array( 'snapshot_get' ) );
		$this->assertNull( Aura_Worker_Redact::grant_shape( $req ) );
	}

	public function test_a_non_array_executor_params_binds_as_empty(): void {
		$req = $this->execute_call( 'snapshot_get', array() );
		$req->set_param( 'params', 'snap_1' );
		$this->assertSame( array( 'tool' => 'unredacted-read:aura/mcp#snapshot_get', 'params' => array() ), Aura_Worker_Redact::grant_shape( $req ) );
	}

	// --- refusal, key and exemption edges ------------------------------------

	/** @return array<string,array{0:string}> */
	public static function malformed_headers(): array {
		return array(
			'one part'       => array( 'abc' ),
			'three parts'    => array( 'a.b.c' ),
			'bad signature'  => array( 'eyJ2IjoxfQ.AAAA' ),
		);
	}

	/** @dataProvider malformed_headers */
	public function test_a_malformed_header_on_a_recognised_shape_is_refused_before_the_tool_runs( string $header ): void {
		foreach ( array( $this->export_call(), $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) ) ) as $req ) {
			$req->set_header( 'X-Aura-Unredacted-Grant', $header );
			$this->assertRefused( $this->before( $req ) );
			$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ), 'a refused grant exempts nothing' );
		}
	}

	public function test_a_whitespace_only_header_is_absent(): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', "  \t " );
		$this->assertNull( $this->before( $req ) );
		$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ) );
	}

	public function test_a_site_with_no_gateway_key_at_all_ignores_the_header(): void {
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		foreach ( array( $this->export_call(), $this->execute_call( 'snapshot_get', array( 'id' => 'snap_1' ) ) ) as $req ) {
			$req->set_header( 'X-Aura-Unredacted-Grant', 'anything.at-all' );
			$this->assertNull( $this->before( $req ), 'the tool runs' );
			$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ), 'and its answer is redacted' );
		}
	}

	public function test_a_verified_grant_spends_its_nonce_once_even_when_the_response_is_never_echoed(): void {
		$grant = $this->grant( self::EXPORT, array( 'post_id' => 7 ) );
		$first = $this->export_call();
		$first->set_header( 'X-Aura-Unredacted-Grant', $grant );
		$this->assertNull( $this->before( $first ) );
		$this->assertNotEmpty(
			array_filter( array_keys( $GLOBALS['_options'] ), static function ( $k ) {
				return 0 === strpos( (string) $k, Aura_Worker_Grant::NONCE_PREFIX );
			} ),
			'the nonce is reserved'
		);

		// The exemption is keyed to $first itself: a copy is another request.
		$copy = clone $first;
		$this->assertNotSame( $this->export_body(), $this->echoed( $copy, $this->export_body() ) );
		$this->assertSame( $this->export_body(), $this->echoed( $first, $this->export_body() ) );
	}

	public function test_reset_for_tests_forgets_every_exemption(): void {
		$req = $this->export_call();
		$req->set_header( 'X-Aura-Unredacted-Grant', $this->grant( self::EXPORT, array( 'post_id' => 7 ) ) );
		$this->assertNull( $this->before( $req ) );

		Aura_Worker_Redact::reset_for_tests();
		$this->assertNotSame( $this->export_body(), $this->echoed( $req, $this->export_body() ), 'reset_for_tests() forgets every exemption' );
	}
}
