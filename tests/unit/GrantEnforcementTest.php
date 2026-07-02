<?php
/**
 * Integration: the MCP execute handler enforces approval grants for
 * requires_approval tools on the token/gateway path.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

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
		// A non-approval tool is unaffected by grant enforcement.
		$res = $this->mcp->execute_tool( $this->request( 'test_double_tool', array( 'target' => 'homepage' ) ) );
		$this->assertNotSame( 403, $res->get_status() );
	}

	public function test_power_tool_runs_without_grant_when_not_provisioned(): void {
		// Back-compat: with no gateway public key, enforcement is off.
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		$res = $this->mcp->execute_tool( $this->request( 'test_power_double', array( 'target' => 'homepage' ) ) );
		$this->assertNotSame( 403, $res->get_status() );
	}
}
