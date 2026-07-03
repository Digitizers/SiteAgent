<?php
/**
 * Integration: the MCP execute handler enforces approval grants for every
 * mutating (non read-only) tool on the token/gateway path.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * A trivial read-only tool (read_only=true) that runs cleanly in the stub
 * environment — used to assert the grant gate leaves reads alone.
 */
class SA_Fake_Read_Tool extends Aura_Tool_Base {
	public function get_name() {
		return 'test_read_double';
	}
	public function get_description() {
		return 'A fake read-only tool.';
	}
	public function get_parameters() {
		return array();
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => false,
			'supports_preview'  => false,
		);
	}
	public function execute( $params ) {
		return array( 'ok' => true );
	}
}

/**
 * A read-only but approval-required tool — like db_query (read_only=true,
 * requires_approval=true). Must STILL require a grant despite being read-only.
 */
class SA_Fake_Read_Approval_Tool extends Aura_Tool_Base {
	public function get_name() {
		return 'test_read_approval';
	}
	public function get_description() {
		return 'A dangerous read that needs approval.';
	}
	public function get_parameters() {
		return array();
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function get_annotations() {
		return array(
			'read_only'         => true,
			'destructive'       => false,
			'requires_approval' => true,
			'supports_preview'  => false,
		);
	}
	public function execute( $params ) {
		return array( 'ok' => true );
	}
}

final class GrantEnforcementTest extends TestCase {

	private string $secret;
	private string $site_hash;
	private Aura_Worker_MCP $mcp;

	protected function setUp(): void {
		sa_reset_state();

		$keypair      = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $keypair );
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $keypair ) );

		$this->site_hash = hash( 'sha256', 'raw-site-token' );
		$GLOBALS['_options']['aura_worker_site_token'] = $this->site_hash;

		$this->mcp = new Aura_Worker_MCP( new Aura_Worker_Security() );
	}

	private function request( string $tool, array $params, ?string $grant = null ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'tool', $tool );
		$req->set_param( 'params', $params );
		if ( null !== $grant ) {
			$req->set_header( 'X-Aura-Approval-Grant', $grant );
		}
		return $req;
	}

	private function mint( string $tool, array $params ): string {
		$payload = array(
			'v'             => 1,
			'tool'          => $tool,
			'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( $params ) ),
			'site'          => $this->site_hash,
			'nonce'         => bin2hex( random_bytes( 16 ) ),
			'iat'           => time(),
			'exp'           => time() + 300,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$sig  = sodium_crypto_sign_detached( $json, $this->secret );
		$b64  = static fn( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		return $b64( $json ) . '.' . $b64( $sig );
	}

	public function test_power_tool_denied_without_grant(): void {
		$res = $this->mcp->execute_tool( $this->request( 'test_power_double', array( 'target' => 'homepage' ) ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertFalse( $res->get_data()['success'] );
		$this->assertStringContainsString( 'Approval grant', $res->get_data()['error'] );
	}

	public function test_power_tool_denied_with_invalid_grant(): void {
		$res = $this->mcp->execute_tool( $this->request( 'test_power_double', array( 'target' => 'homepage' ), 'garbage.grant' ) );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_power_tool_denied_when_grant_is_for_other_params(): void {
		$grant = $this->mint( 'test_power_double', array( 'target' => 'homepage' ) );
		// Same grant, but the request asks to act on a DIFFERENT target.
		$res = $this->mcp->execute_tool( $this->request( 'test_power_double', array( 'target' => 'wp-config.php' ), $grant ) );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_power_tool_runs_with_valid_grant(): void {
		$grant = $this->mint( 'test_power_double', array( 'target' => 'homepage' ) );
		$res   = $this->mcp->execute_tool( $this->request( 'test_power_double', array( 'target' => 'homepage' ), $grant ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['success'] );
	}

	public function test_read_tool_needs_no_grant_even_when_enforced(): void {
		// A genuinely read-only tool (read_only=true) is unaffected by grant
		// enforcement — a stolen token can still READ.
		$res = $this->mcp->execute_tool( $this->request( 'test_read_double', array() ) );
		$this->assertNotSame( 403, $res->get_status() );
		$this->assertTrue( $res->get_data()['success'] );
	}

	public function test_non_power_write_also_needs_a_grant(): void {
		// The broadened scope: ANY mutating tool (not just power) needs a grant.
		// test_double_tool is a non-power write (read_only=false, no approval).
		$res = $this->mcp->execute_tool( $this->request( 'test_double_tool', array( 'target' => 'homepage' ) ) );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_non_power_write_runs_with_valid_grant(): void {
		$grant = $this->mint( 'test_double_tool', array( 'target' => 'homepage' ) );
		$res   = $this->mcp->execute_tool( $this->request( 'test_double_tool', array( 'target' => 'homepage' ), $grant ) );
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_readonly_but_approval_tool_needs_a_grant(): void {
		// A read_only tool that ALSO requires approval (like db_query) must not be
		// exempted by the read-only carve-out — a stolen token can't dump the DB.
		$res = $this->mcp->execute_tool( $this->request( 'test_read_approval', array() ) );
		$this->assertSame( 403, $res->get_status() );
		// ...and runs with a valid grant.
		$grant = $this->mint( 'test_read_approval', array() );
		$res2  = $this->mcp->execute_tool( $this->request( 'test_read_approval', array(), $grant ) );
		$this->assertSame( 200, $res2->get_status() );
	}

	public function test_mutating_tool_runs_without_grant_when_not_provisioned(): void {
		// Back-compat: with no gateway public key, enforcement is off.
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		$res = $this->mcp->execute_tool( $this->request( 'test_power_double', array( 'target' => 'homepage' ) ) );
		$this->assertNotSame( 403, $res->get_status() );
	}
}
