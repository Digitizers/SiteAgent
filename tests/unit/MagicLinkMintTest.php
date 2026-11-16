<?php
/**
 * Aura#782: the magic-link mint carries the previous-binding proof.
 *
 * Aura decides the client and the plan limit BEFORE it sends /connect, so a
 * site that moved to a new domain was judged a brand-new site, and an
 * organization at its site cap was refused before the connect's proof could
 * arrive. The site now sends the same proof when it mints the link.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class MagicLinkMintTest extends TestCase {

	private Aura_Worker_Magic_Link $ml;

	protected function setUp(): void {
		sa_reset_state();
		$this->ml                    = new Aura_Worker_Magic_Link();
		$GLOBALS['_wp_http_calls']   = array();
		$GLOBALS['_sa_uuid_fixed']   = 'mint-magic-1';
		$GLOBALS['_http_response']   = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'magic_link' => 'https://dash.example/connect/mint-magic-1' ) ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_sa_uuid_fixed'], $GLOBALS['_http_response'] );
	}

	/** @return array The JSON body the mint POSTed to Aura. */
	private function mint(): array {
		try {
			$this->ml->ajax_create_magic_link();
			$this->fail( 'the handler always answers through wp_send_json_*' );
		} catch ( SA_Json_Response $r ) {
			$this->assertTrue( $r->success, 'the mint succeeded' );
		}
		$calls = $GLOBALS['_wp_http_calls'];
		$this->assertCount( 1, $calls );
		$this->assertStringEndsWith( '/api/onboarding/magic-link', $calls[0]['url'] );
		return json_decode( $calls[0]['args']['body'], true );
	}

	public function test_a_bound_site_sends_proof_of_its_current_binding_at_mint(): void {
		$current = hash( 'sha256', 'live-raw-token' );
		update_option( 'aura_worker_site_token', $current );

		$body = $this->mint();

		$expected = hash_hmac( 'sha256', "aura-repoint-v1\nmint-magic-1", $current );
		$this->assertSame( $expected, $body['previous_binding_proof'] ?? null );
		$this->assertStringNotContainsString( $current, wp_json_encode( $body ), 'the hash itself is never sent' );
		$this->assertSame( $expected, get_transient( 'aura_magic_proof_mint-magic-1' ), 'kept for the connect answer' );
	}

	public function test_an_unbound_site_sends_no_proof(): void {
		$body = $this->mint();
		$this->assertArrayNotHasKey( 'previous_binding_proof', $body );
		$this->assertFalse( get_transient( 'aura_magic_proof_mint-magic-1' ) );
	}

	public function test_the_connect_answer_carries_the_mint_proof_unchanged(): void {
		$current = hash( 'sha256', 'live-raw-token' );
		update_option( 'aura_worker_site_token', $current );
		$body = $this->mint();
		unset( $GLOBALS['_sa_uuid_fixed'] ); // the connect's door rotation needs fresh ids

		$secret    = get_transient( 'aura_magic_mint-magic-1' )['connect_secret'];
		$timestamp = time();
		$pubkey    = base64_encode( str_repeat( 'k', 32 ) );
		$req       = new WP_REST_Request();
		$req->set_param( 'magic_id', 'mint-magic-1' );
		$req->set_param( 'token', 'next-raw-token' );
		$req->set_param( 'dashboard_url', 'https://dash.example' );
		$req->set_param( 'timestamp', $timestamp );
		$req->set_param( 'grant_pubkey', $pubkey );
		$req->set_param( 'signature', Aura_Worker_Magic_Link::sign_connect_payload( $secret, 'mint-magic-1', 'next-raw-token', 'https://dash.example', $timestamp, $pubkey ) );

		$res = $this->ml->handle_connect( $req );
		if ( is_wp_error( $res ) ) {
			$this->fail( $res->get_error_code() . ': ' . $res->get_error_message() );
		}

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $body['previous_binding_proof'], $res->get_data()['previous_binding_proof'] ?? null );
	}

	public function test_a_failed_mint_drops_the_kept_proof(): void {
		update_option( 'aura_worker_site_token', hash( 'sha256', 'live-raw-token' ) );
		$GLOBALS['_http_response'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );
		try {
			$this->ml->ajax_create_magic_link();
			$this->fail( 'expected a JSON answer' );
		} catch ( SA_Json_Response $r ) {
			$this->assertFalse( $r->success );
		}
		$this->assertFalse( get_transient( 'aura_magic_proof_mint-magic-1' ) );
	}
}
