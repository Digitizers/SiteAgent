<?php
/**
 * The write guard (#419 v2, spec §3). The placeholder is one-way: an agent
 * write that carries it would overwrite a real webhook with a dead string,
 * so it is refused (409) before anything runs. Not a security boundary —
 * a guard against the accident.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactWriteGuardTest extends TestCase {

	private const MARK = 'aura-redacted:v1:make';

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true;
		$GLOBALS['_rest_app_password']            = 'uuid-agent';
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function before( WP_REST_Request $req ) {
		return apply_filters( 'rest_request_before_callbacks', null, array(), $req );
	}

	private function json_request( string $method, string $route, string $raw_body ): WP_REST_Request {
		$req = new WP_REST_Request( $method, $route );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( $raw_body );
		return $req;
	}

	private function assertRefused( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'refused before any callback runs — nothing is written' );
		$this->assertSame( 'aura_redacted_placeholder', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'Omit that field so the stored value is kept', $result->get_error_message() );
	}

	private function refused_count(): int {
		return Aura_Worker_Rules::count_24h( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER );
	}

	/** @return array<string,array{0:string}> */
	public static function sources(): array {
		return array(
			'query' => array( 'query' ),
			'body'  => array( 'body' ),
			'json'  => array( 'json' ),
			'url'   => array( 'url' ),
		);
	}

	/** @dataProvider sources */
	public function test_a_placeholder_in_any_parameter_source_is_refused( string $source ): void {
		$req    = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$params = array( 'title' => 'x', 'nested' => array( 'hook' => 'before ' . self::MARK ) );
		switch ( $source ) {
			case 'query':
				$req->set_query_params( $params );
				break;
			case 'body':
				$req->set_body_params( $params );
				break;
			case 'json':
				$req->set_header( 'Content-Type', 'application/json' );
				$req->set_body( (string) wp_json_encode( $params ) );
				break;
			case 'url':
				$req->set_url_params( array( 'id' => self::MARK ) );
				break;
		}

		$this->assertRefused( $this->before( $req ) );
		$this->assertSame( 1, $this->refused_count() );
		$fired = array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_placeholder_refused' === $a['tag'];
		} ) );
		$this->assertSame( array( '/wp/v2/pages/7' ), $fired[0]['args'] );
	}

	public function test_each_source_is_walked_on_its_own_not_through_the_merged_params(): void {
		// get_param() would answer the query's clean value; the body's copy of
		// the same key carries the placeholder.
		$req = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$req->set_param( 'title', 'clean' );
		$req->set_query_params( array( 'title' => 'clean' ) );
		$req->set_body_params( array( 'title' => self::MARK ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_placeholder_inside_elementor_data_under_meta_is_refused(): void {
		$tree = wp_json_encode( array( array( 'id' => 'f', 'settings' => array( 'webhooks' => 'aura-redacted:v1:field' ) ) ) );
		$req  = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'meta' => array( '_elementor_data' => $tree ) ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_json_escaped_placeholder_in_the_body_is_refused(): void {
		$req = $this->json_request( 'POST', '/wp/v2/pages/7', '{"title":"aura\\u002dredacted:v1:make"}' );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_json_escaped_placeholder_inside_elementor_data_is_refused(): void {
		// The carrier's raw text never contains "aura-redacted:"; only its decode does.
		$carrier = '[{"settings":{"webhooks":"aura\\u002dredacted:v1:field"}}]';
		$this->assertStringNotContainsString( 'aura-redacted:', $carrier );
		$req = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'meta' => array( '_elementor_data' => $carrier ) ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_an_import_template_of_an_exported_tree_is_refused(): void {
		$call = array(
			'jsonrpc' => '2.0',
			'id'      => 4,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'import-template',
				'arguments' => array(
					'post_id'  => 7,
					'template' => array( 'content' => array( array( 'id' => 'f', 'elType' => 'widget', 'settings' => array( 'webhooks' => 'aura-redacted:v1:field' ) ) ) ),
				),
			),
		);
		$req = $this->json_request( 'POST', '/mcp/elementor-mcp-server', (string) wp_json_encode( $call ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_placeholder_in_a_text_block_carrier_is_refused(): void {
		$body = array( 'content' => array( array( 'type' => 'text', 'text' => '{"webhooks":"aura\\u002dredacted:v1:field"}' ) ) );
		$req  = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( $body ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_snapshot_shaped_payload_is_checked_without_being_unserialized(): void {
		// Ruling R4: agent bytes are scanned, never unserialized.
		$payload = base64_encode( serialize( array( 7 => array( 'meta' => array( '_elementor_data' => array( 'value' => '[{"settings":{"webhooks":"aura-redacted:v1:field"}}]' ) ) ) ) ) );
		$req     = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'found' => true, 'record' => null, 'payload' => $payload ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_the_gateway_is_guarded_too(): void {
		$GLOBALS['_logged_in']         = false; // token-only
		$GLOBALS['_rest_app_password'] = null;
		$req = new WP_REST_Request( 'POST', '/aura/mcp/tools/execute' );
		$req->set_body_params( array( 'tool' => 'set_seo_meta', 'params' => array( 'title' => self::MARK ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_person_may_write_the_literal_string(): void {
		Aura_Worker_Rules::$cookie_auth_override = true;
		$GLOBALS['_rest_app_password']           = null;
		$req = new WP_REST_Request( 'POST', '/wp/v2/pages/7' );
		$req->set_body_params( array( 'content' => 'How to spot aura-redacted:v1:make in an export' ) );
		$this->assertNull( $this->before( $req ) );
		$this->assertSame( 0, $this->refused_count() );
	}

	/** @return array<string,array{0:string}> */
	public static function safe_methods(): array {
		return array( 'GET' => array( 'GET' ), 'HEAD' => array( 'HEAD' ), 'OPTIONS' => array( 'OPTIONS' ) );
	}

	/** @dataProvider safe_methods */
	public function test_a_read_is_never_checked( string $method ): void {
		$req = new WP_REST_Request( $method, '/wp/v2/pages' );
		$req->set_query_params( array( 'search' => self::MARK ) );
		$this->assertNull( $this->before( $req ) );
	}

	public function test_a_system_route_is_never_checked(): void {
		$req = new WP_REST_Request( 'POST', '/aura/v2/rules' );
		$req->set_body_params( array( 'note' => self::MARK ) );
		$this->assertNull( $this->before( $req ) );
	}

	public function test_an_update_widget_that_omits_webhooks_passes(): void {
		// The fork shallow-merges `settings` into the stored element, so
		// leaving `webhooks` out keeps the stored URL (spec: what changed from v1).
		$call = array(
			'jsonrpc' => '2.0',
			'id'      => 6,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'update-widget',
				'arguments' => array( 'post_id' => 7, 'element_id' => 'frm1', 'settings' => array( 'form_name' => 'Contact us' ) ),
			),
		);
		$req = $this->json_request( 'POST', '/mcp/elementor-mcp-server', (string) wp_json_encode( $call ) );
		$this->assertNull( $this->before( $req ) );
		$this->assertSame( 0, $this->refused_count() );
	}

	public function test_a_write_that_merely_mentions_redaction_passes(): void {
		$req = new WP_REST_Request( 'PATCH', '/wp/v2/pages/7' );
		$req->set_body_params( array( 'content' => 'aura redacted: v1 is our naming scheme' ) );
		$this->assertNull( $this->before( $req ) );
	}

	public function test_a_refused_write_spends_no_unredacted_grant(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		sa_install_gateway_key();
		$tool  = 'unredacted-read:mcp/elementor-mcp-server#export-page';
		$args  = array( 'post_id' => 7, 'note' => self::MARK );
		$grant = sa_sign_ruleset(
			array(
				'v'             => 1,
				'tool'          => $tool,
				'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $args ) ),
				'site'          => sa_token_hash(),
				'nonce'         => bin2hex( random_bytes( 16 ) ),
				'iat'           => time(),
				'exp'           => time() + 300,
			)
		);
		$call = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'export-page', 'arguments' => $args ) );
		$req  = $this->json_request( 'POST', '/mcp/elementor-mcp-server', (string) wp_json_encode( $call ) );
		$req->set_header( 'X-Aura-Unredacted-Grant', $grant );

		$this->assertRefused( $this->before( $req ) );
		$this->assertSame( true, Aura_Worker_Grant::verify( $grant, $tool, $args ), 'the nonce is still unspent' );
	}

	public function test_a_placeholder_used_as_a_key_is_refused(): void {
		$req = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'meta' => array( 'hooks' => array( 'aura-redacted:v1:zapier' => true ) ) ) ) );
		$this->assertRefused( $this->before( $req ) );
		$this->assertTrue( Aura_Worker_Redact::holds_placeholder( (object) array( 'aura-redacted:v1:make#2' => 1 ) ) );
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( array( 'webhooks' => 1, 0 => 'x' ) ) );
	}

	public function test_holds_placeholder_is_a_pure_walk(): void {
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( array( 'a' => array( 1, true, null, 2.5 ) ) ) );
		$this->assertTrue( Aura_Worker_Redact::holds_placeholder( array( 'a' => array( 'b' => (object) array( 'c' => 'AURA-REDACTED:v1:zapier' ) ) ) ) );
		$this->assertFalse( Aura_Worker_Redact::holds_placeholder( '{"x":"aura\\u002dredacted:"}', 0 ), 'a plain string is not a carrier: its raw text is what counts' );
	}

	public function test_an_earlier_refusal_passes_through_and_nothing_is_counted(): void {
		$req     = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'title' => self::MARK ) ) );
		$earlier = new WP_Error( 'aura_rule_blocked', 'blocked', array( 'status' => 403 ) );
		$this->assertSame( $earlier, apply_filters( 'rest_request_before_callbacks', $earlier, array(), $req ) );
		$this->assertSame( 0, $this->refused_count() );
	}

	public function test_a_snapshot_answer_under_content_is_checked(): void {
		// `content` that is not a list is not MCP's text-block carrier — the
		// read side walks it plainly, so the guard must see its snapshot answer.
		$payload = base64_encode( serialize( array( 'x' => 'aura-redacted:v1:field' ) ) );
		$req     = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'content' => array( 'found' => true, 'record' => null, 'payload' => $payload ) ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_snapshot_answer_under_elementor_data_is_checked(): void {
		$payload = base64_encode( serialize( array( 'x' => 'aura-redacted:v1:field' ) ) );
		$req     = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'meta' => array( '_elementor_data' => array( 'found' => true, 'record' => null, 'payload' => $payload ) ) ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_json_escaped_placeholder_inside_a_snapshot_payload_is_refused(): void {
		// Still scanned as bytes (R4): JSON escapes are undone on the bytes, nothing is unserialized.
		$payload = base64_encode( serialize( array( 7 => array( 'meta' => array( '_elementor_data' => array( 'value' => '[{"settings":{"webhooks":"aura\\u002dredacted:v1:field"}}]' ) ) ) ) ) );
		$this->assertStringNotContainsString( 'aura-redacted:', (string) base64_decode( $payload ) );
		$req = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'found' => true, 'record' => null, 'payload' => $payload ) ) );
		$this->assertRefused( $this->before( $req ) );
	}

	public function test_a_clean_snapshot_payload_passes(): void {
		$payload = base64_encode( serialize( array( 7 => array( 'meta' => array( '_elementor_data' => array( 'value' => '[{"settings":{"webhooks":"https:\\/\\/example.com\\/x"}}]' ) ) ) ) ) );
		$req     = $this->json_request( 'POST', '/wp/v2/pages/7', (string) wp_json_encode( array( 'found' => true, 'record' => null, 'payload' => $payload ) ) );
		$this->assertNull( $this->before( $req ) );
	}
}
