<?php
/**
 * Tests for Aura_Worker_Grant — signed single-use approval grants (G-grants).
 *
 * Uses a REAL Ed25519 keypair (libsodium) so the verifier is exercised against
 * genuine signatures, not a stub.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class GrantTest extends TestCase {

	/** @var string Ed25519 secret (signing) key. */
	private string $secret;

	/** @var string sha256 of the site's raw token (what the plugin stores). */
	private string $site_hash;

	protected function setUp(): void {
		sa_reset_state();

		$keypair      = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $keypair );
		$pub          = sodium_crypto_sign_publickey( $keypair );

		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( $pub );

		$this->site_hash = hash( 'sha256', 'raw-site-token' );
		$GLOBALS['_options']['aura_worker_site_token'] = $this->site_hash;
	}

	/**
	 * Mint a signed grant. $over overrides individual payload fields; pass
	 * 'unset' => [keys] to omit fields, and 'secret' to sign with another key.
	 */
	private function mint( array $over = array() ): string {
		$params = array_key_exists( 'params', $over ) ? $over['params'] : array( 'code' => '1+1' );

		$payload = array(
			'v'             => 1,
			'tool'          => $over['tool'] ?? 'execute_php',
			'params_sha256' => $over['params_sha256'] ?? hash( 'sha256', Aura_Worker_Grant::canonical_json( $params ) ),
			'site'          => $over['site'] ?? $this->site_hash,
			'nonce'         => $over['nonce'] ?? bin2hex( random_bytes( 16 ) ),
			'iat'           => $over['iat'] ?? time(),
			'exp'           => $over['exp'] ?? time() + 300,
		);
		foreach ( ( $over['unset'] ?? array() ) as $k ) {
			unset( $payload[ $k ] );
		}

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$sig  = sodium_crypto_sign_detached( $json, $over['secret'] ?? $this->secret );

		return $this->b64url( $json ) . '.' . $this->b64url( $sig );
	}

	private function b64url( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	// --- enforcement toggle ---------------------------------------------------

	public function test_not_enforced_without_pubkey(): void {
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		$this->assertFalse( Aura_Worker_Grant::is_enforced() );
	}

	public function test_enforced_with_pubkey(): void {
		$this->assertTrue( Aura_Worker_Grant::is_enforced() );
	}

	// --- happy path -----------------------------------------------------------

	public function test_valid_grant_verifies(): void {
		$grant = $this->mint();
		$this->assertTrue( Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) ) );
	}

	public function test_valid_grant_with_empty_params(): void {
		$grant = $this->mint( array( 'params' => array() ) );
		$this->assertTrue( Aura_Worker_Grant::verify( $grant, 'execute_php', array() ) );
	}

	// --- rejections -----------------------------------------------------------

	public function test_missing_grant_rejected(): void {
		$this->assertIsString( Aura_Worker_Grant::verify( '', 'execute_php', array( 'code' => '1+1' ) ) );
	}

	public function test_malformed_grant_rejected(): void {
		$this->assertIsString( Aura_Worker_Grant::verify( 'not-a-grant', 'execute_php', array() ) );
	}

	public function test_wrong_tool_rejected(): void {
		$grant  = $this->mint( array( 'tool' => 'db_query' ) );
		$result = Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) );
		$this->assertSame( 'tool mismatch', $result );
	}

	public function test_params_tamper_rejected(): void {
		// Grant minted for '1+1' but the call asks to run different code.
		$grant  = $this->mint( array( 'params' => array( 'code' => '1+1' ) ) );
		$result = Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => 'unlink("/etc/passwd")' ) );
		$this->assertSame( 'params mismatch', $result );
	}

	public function test_wrong_site_rejected(): void {
		$grant  = $this->mint( array( 'site' => hash( 'sha256', 'someone-elses-token' ) ) );
		$result = Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) );
		$this->assertSame( 'site mismatch', $result );
	}

	public function test_forged_signature_rejected(): void {
		// Sign with a DIFFERENT key than the provisioned public key.
		$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$grant = $this->mint( array( 'secret' => $other ) );
		$this->assertSame( 'signature verification failed', Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) ) );
	}

	public function test_tampered_payload_rejected(): void {
		// Change a real field (tool) in the signed payload → the original
		// signature no longer matches, so verification must fail.
		$grant         = $this->mint();
		list( , $s )   = explode( '.', $grant );
		$payload       = wp_json_encode(
			array(
				'v'             => 1,
				'tool'          => 'db_query', // was execute_php
				'params_sha256' => hash( 'sha256', Aura_Worker_Grant::canonical_json( array( 'code' => '1+1' ) ) ),
				'site'          => $this->site_hash,
				'nonce'         => bin2hex( random_bytes( 16 ) ),
				'iat'           => time(),
				'exp'           => time() + 300,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$tampered = $this->b64url( $payload ) . '.' . $s;
		$this->assertSame( 'signature verification failed', Aura_Worker_Grant::verify( $tampered, 'db_query', array( 'code' => '1+1' ) ) );
	}

	public function test_tampered_signature_rejected(): void {
		// Flip a byte in the signature (length preserved) → verification fails.
		$grant       = $this->mint();
		list( $p, $s ) = explode( '.', $grant );
		$sig         = base64_decode( strtr( $s, '-_', '+/' ), true );
		$sig[0]      = ( "\x00" === $sig[0] ) ? "\x01" : "\x00";
		$tampered    = $p . '.' . rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
		$this->assertSame( 'signature verification failed', Aura_Worker_Grant::verify( $tampered, 'execute_php', array( 'code' => '1+1' ) ) );
	}

	public function test_expired_grant_rejected(): void {
		$grant  = $this->mint( array( 'iat' => time() - 1000, 'exp' => time() - 500 ) );
		$result = Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) );
		$this->assertSame( 'grant expired', $result );
	}

	public function test_not_yet_valid_grant_rejected(): void {
		$grant  = $this->mint( array( 'iat' => time() + 500, 'exp' => time() + 800 ) );
		$result = Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) );
		$this->assertSame( 'grant not yet valid', $result );
	}

	public function test_overlong_ttl_rejected(): void {
		$grant  = $this->mint( array( 'iat' => time(), 'exp' => time() + 100000 ) );
		$result = Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) );
		$this->assertSame( 'grant lifetime too long', $result );
	}

	public function test_nonce_is_single_use(): void {
		$nonce = bin2hex( random_bytes( 16 ) );
		$grant = $this->mint( array( 'nonce' => $nonce ) );
		$this->assertTrue( Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) ) );
		// Second use of the SAME grant is refused.
		$this->assertSame( 'grant already used', Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) ) );
	}

	public function test_bad_nonce_rejected(): void {
		$grant  = $this->mint( array( 'nonce' => 'not-hex!!' ) );
		$result = Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) );
		$this->assertSame( 'bad nonce', $result );
	}

	public function test_no_pubkey_reports_not_provisioned(): void {
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		$grant = $this->mint();
		$this->assertSame( 'no grant key provisioned', Aura_Worker_Grant::verify( $grant, 'execute_php', array( 'code' => '1+1' ) ) );
	}
}
