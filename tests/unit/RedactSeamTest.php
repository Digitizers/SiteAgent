<?php
/**
 * The read seam (#419 v2, spec §1.1): `rest_pre_echo_response`, which core
 * applies only to a SERVED request, on its final data — after `_envelope`
 * and after `_embed`. Every HTTP method; system routes, people and the
 * public untouched; a response with no match returned as it was.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactSeamTest extends TestCase {

	private const N8N = 'https://n8n.example.com/webhook/abc';

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Rules::$rest_request_override = true;
		Aura_Worker_Rules::$cookie_auth_override  = false;
		$GLOBALS['_logged_in']                    = true;
		Aura_Worker_Redact::init();
	}

	protected function tearDown(): void {
		Aura_Worker_Rules::$rest_request_override = null;
		Aura_Worker_Rules::$cookie_auth_override  = null;
	}

	private function echoed( $data, string $method, string $route ) {
		return apply_filters( 'rest_pre_echo_response', $data, null, new WP_REST_Request( $method, $route ) );
	}

	/** A wp/v2 page with Elementor's data exposed as meta. */
	private function page( string $webhook ): array {
		$tree = array(
			array(
				'id'       => 'frm1',
				'elType'   => 'widget',
				'settings' => array( 'form_name' => 'Contact', 'webhooks' => $webhook ),
			),
		);
		return array(
			'id'    => 7,
			'title' => array( 'rendered' => 'Contact' ),
			'meta'  => array( '_elementor_data' => wp_json_encode( $tree ) ),
		);
	}

	private function webhook_of( array $page ): string {
		return json_decode( $page['meta']['_elementor_data'], true )[0]['settings']['webhooks'];
	}

	private function redacted_count(): int {
		return Aura_Worker_Rules::count_24h( Aura_Worker_Redact::REDACTED_COUNTER );
	}

	// --- registration ------------------------------------------------------

	public function test_the_read_seam_is_rest_pre_echo_response_and_runs_last(): void {
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_echo_response', array( 'Aura_Worker_Redact', 'filter_echo' ) ) );
	}

	public function test_nothing_is_hooked_on_a_seam_an_internal_dispatch_or_an_unembedded_body_passes(): void {
		// rest_post_dispatch runs before _envelope and _embed (serve_request()),
		// and rest_request_after_callbacks runs for every internal
		// rest_do_request(): a redactor on either would leak embedded
		// resources or redact internal reads (spec §1.1).
		$hooked = array();
		foreach ( array( 'rest_post_dispatch', 'rest_request_after_callbacks', 'rest_pre_serve_request' ) as $tag ) {
			foreach ( $GLOBALS['_filters'][ $tag ] ?? array() as $entry ) {
				$cb = is_array( $entry ) && array_key_exists( 'callback', $entry ) ? $entry['callback'] : $entry;
				if ( is_array( $cb ) && 'Aura_Worker_Redact' === $cb[0] ) {
					$hooked[] = $tag . ' → ' . $cb[1];
				}
			}
		}
		// An empty list is the assertion (the loop above may see no hooks at
		// all); the positive control proves the scan reads where init() writes.
		$this->assertSame( array(), $hooked, 'Aura_Worker_Redact must not hook these seams' );
		$this->assertNotFalse( has_filter( 'rest_pre_echo_response', array( 'Aura_Worker_Redact', 'filter_echo' ) ) );
	}

	public function test_the_plugin_wires_the_redactor_in(): void {
		( new Aura_Worker() )->init();
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_echo_response', array( 'Aura_Worker_Redact', 'filter_echo' ) ) );

		$main = (string) file_get_contents( SA_PLUGIN_DIR . '/digitizer-site-worker.php' );
		$this->assertStringContainsString( "require_once AURA_WORKER_DIR . 'includes/class-aura-worker-redact.php';", $main );
	}

	// --- who ---------------------------------------------------------------

	/** @return array<string,array{0:string,1:string,2:bool}> method, route, logged in */
	public static function redacted_callers(): array {
		return array(
			'gateway tools/execute (token-only)' => array( 'POST', '/aura/mcp/tools/execute', false ),
			'wp/v2 read'                         => array( 'GET', '/wp/v2/pages/7', true ),
			'mcp/v1 client'                      => array( 'POST', '/mcp/mcp-adapter-default-server', true ),
			'a write echo'                       => array( 'POST', '/wp/v2/pages/7', true ),
			'a delete echo'                      => array( 'DELETE', '/wp/v2/pages/7', true ),
		);
	}

	/** @dataProvider redacted_callers */
	public function test_an_agent_read_is_redacted( string $method, string $route, bool $logged_in ): void {
		$GLOBALS['_logged_in'] = $logged_in;
		$out = $this->echoed( $this->page( self::N8N ), $method, $route );
		$this->assertSame( 'aura-redacted:v1:field', $this->webhook_of( $out ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function system_routes(): array {
		return array(
			'status'    => array( 'GET', '/aura/v1/status' ),
			'rules'     => array( 'POST', '/aura/v2/rules' ),
			'snapshots' => array( 'GET', '/aura/v2/snapshots' ),
			'updates'   => array( 'GET', '/aura/v1/updates' ),
		);
	}

	/** @dataProvider system_routes */
	public function test_a_system_route_is_untouched( string $method, string $route ): void {
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, $method, $route ) );
	}

	public function test_a_snapshot_answer_on_a_system_route_keeps_its_payload(): void {
		$answer = array( 'found' => true, 'record' => array( 'id' => 's1' ), 'payload' => base64_encode( 'not serialized at all' ) );
		$this->assertSame( $answer, $this->echoed( $answer, 'GET', '/aura/v2/snapshots' ), 'never failed closed off the audience' );
	}

	public function test_a_person_in_wp_admin_is_untouched(): void {
		Aura_Worker_Rules::$cookie_auth_override = true;
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, 'GET', '/wp/v2/pages/7' ) );
	}

	public function test_the_anonymous_public_is_untouched(): void {
		$GLOBALS['_logged_in'] = false;
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, 'GET', '/wp/v2/pages/7' ) );
	}

	/** @return array<string,array{0:string}> */
	public static function lookalike_routes(): array {
		return array(
			'execute-foo' => array( '/aura/mcp/tools/execute-foo' ),
			'mcpx'        => array( '/aura/mcpx/tools/execute' ),
		);
	}

	/** @dataProvider lookalike_routes */
	public function test_a_lookalike_route_is_not_treated_as_tools_execute( string $route ): void {
		$GLOBALS['_logged_in'] = false; // what makes tools/execute special: no login needed
		$page = $this->page( self::N8N );
		$this->assertSame( $page, $this->echoed( $page, 'POST', $route ) );
	}

	// --- what core hands the seam -----------------------------------------

	public function test_an_embedded_resource_is_redacted(): void {
		// response_to_data() has already expanded `_embedded` when this runs.
		$body = array(
			'id'        => 9,
			'_links'    => array( 'up' => array( array( 'href' => 'https://example.com/wp-json/wp/v2/pages/7', 'embeddable' => true ) ) ),
			'_embedded' => array( 'up' => array( $this->page( 'https://hooks.zapier.com/hooks/catch/1/' ) ) ),
		);
		$out = $this->echoed( $body, 'GET', '/wp/v2/pages/9' );
		$this->assertSame( 'aura-redacted:v1:field', $this->webhook_of( $out['_embedded']['up'][0] ), 'a key match replaces the whole value, whatever the host' );
		$this->assertSame( $body['_links'], $out['_links'] );
	}

	public function test_an_envelope_response_is_redacted(): void {
		$body = array( 'body' => $this->page( self::N8N ), 'status' => 200, 'headers' => array( 'Allow' => 'GET' ) );
		$out  = $this->echoed( $body, 'GET', '/wp/v2/pages/7' );
		$this->assertSame( 'aura-redacted:v1:field', $this->webhook_of( $out['body'] ) );
		$this->assertSame( 200, $out['status'] );
	}

	public function test_a_response_with_no_match_is_the_same_value_and_counts_nothing(): void {
		$page = $this->page( '' );
		$this->assertSame( $page, $this->echoed( $page, 'GET', '/wp/v2/pages/7' ) );
		$this->assertSame( 0, $this->redacted_count() );
		$this->assertSame( array(), array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_redacted' === $a['tag'];
		} ) ) );
	}

	public function test_a_null_body_passes_through(): void {
		$this->assertNull( $this->echoed( null, 'DELETE', '/wp/v2/pages/7' ) );
	}

	// --- counters ------------------------------------------------------------

	public function test_one_redacted_response_bumps_the_counter_once_and_reports_its_replacements(): void {
		$body = array( 'a' => $this->page( self::N8N ), 'b' => 'https://hooks.slack.com/services/T/B/X' );
		$this->echoed( $body, 'GET', '/wp/v2/pages/7' );

		$this->assertSame( 1, $this->redacted_count(), 'one per response, however many replacements' );
		$fired = array_values( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_redacted' === $a['tag'];
		} ) );
		$this->assertCount( 1, $fired );
		$this->assertSame( array( 2, '/wp/v2/pages/7' ), $fired[0]['args'] );

		$this->echoed( $body, 'GET', '/wp/v2/pages/7' );
		$this->assertSame( 2, $this->redacted_count() );
	}

	public function test_a_refused_placeholder_write_is_counted_through_its_action(): void {
		$this->assertSame( 10, has_filter( 'aura_worker_placeholder_refused', array( 'Aura_Worker_Redact', 'record_placeholder_refused' ) ) );
		$entries = array_values( array_filter( $GLOBALS['_filters']['aura_worker_placeholder_refused'] ?? array(), static function ( $e ) {
			return is_array( $e ) && array( 'Aura_Worker_Redact', 'record_placeholder_refused' ) === ( $e['callback'] ?? null );
		} ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( 1, $entries[0]['accepted_args'], 'the route only — the second parameter is the test clock' );

		$this->assertSame( 0, Aura_Worker_Rules::count_24h( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER ) );
		do_action( 'aura_worker_placeholder_refused', '/wp/v2/pages/7' );
		$this->assertSame( 1, Aura_Worker_Rules::count_24h( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER ) );
		$this->assertSame( 0, $this->redacted_count(), 'a refusal is not a redaction' );
	}

	public function test_bump_counter_refuses_an_unknown_prefix(): void {
		Aura_Worker_Rules::bump_counter( 'someone_elses_h' );
		$this->assertSame( 0, Aura_Worker_Rules::count_24h( 'someone_elses_h' ) );
		Aura_Worker_Rules::bump_counter( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER );
		$this->assertSame( 1, Aura_Worker_Rules::count_24h( Aura_Worker_Redact::PLACEHOLDER_REFUSED_COUNTER ) );
	}
}
