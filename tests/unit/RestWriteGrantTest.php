<?php
/**
 * The direct REST write endpoints (updates, batch, self-update, rollback) run
 * as admin off a valid X-Aura-Token. Aura_Worker_Grant::require_for() gates them
 * with the same single-use signed grant the MCP path uses, and self-update is
 * additionally bound to an allowlisted source.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RestWriteGrantTest extends TestCase {

	private string $secret;
	private string $site_hash;

	protected function setUp(): void {
		sa_reset_state();
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium is not available.' );
		}
		$keypair      = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $keypair );
		$GLOBALS['_options']['aura_worker_grant_pubkey'] = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
		$this->site_hash = hash( 'sha256', 'raw-site-token' );
		$GLOBALS['_options']['aura_worker_site_token'] = $this->site_hash;
	}

	private function req( ?string $grant = null ): WP_REST_Request {
		$r = new WP_REST_Request();
		if ( null !== $grant ) {
			$r->set_header( 'X-Aura-Approval-Grant', $grant );
		}
		return $r;
	}

	private function mint( string $action, array $params ): string {
		$payload = array(
			'v'             => 1,
			'tool'          => $action,
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

	private function assertDenied( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aura_grant_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	// --- require_for -------------------------------------------------------

	public function test_write_denied_without_grant(): void {
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req(), 'wp.update.plugin', array( 'plugin' => 'akismet/akismet.php' ) )
		);
	}

	public function test_write_denied_with_invalid_grant(): void {
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req( 'garbage.grant' ), 'wp.update.plugin', array( 'plugin' => 'akismet/akismet.php' ) )
		);
	}

	public function test_write_denied_when_grant_is_for_a_different_action(): void {
		$grant = $this->mint( 'wp.update.core', array() );
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.update.plugin', array( 'plugin' => 'akismet/akismet.php' ) )
		);
	}

	public function test_write_denied_when_grant_is_for_a_different_plugin(): void {
		$grant = $this->mint( 'wp.update.plugin', array( 'plugin' => 'akismet/akismet.php' ) );
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.update.plugin', array( 'plugin' => 'evil/evil.php' ) )
		);
	}

	public function test_write_allowed_with_valid_grant(): void {
		$grant = $this->mint( 'wp.update.plugin', array( 'plugin' => 'akismet/akismet.php' ) );
		$this->assertTrue(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.update.plugin', array( 'plugin' => 'akismet/akismet.php' ) )
		);
	}

	public function test_core_update_binds_empty_params(): void {
		$this->assertDenied( Aura_Worker_Grant::require_for( $this->req(), 'wp.update.core', array() ) );
		$grant = $this->mint( 'wp.update.core', array() );
		$this->assertTrue( Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.update.core', array() ) );
	}

	public function test_batch_binds_safety_options(): void {
		// A grant for create_backup=true must NOT authorize a create_backup=false run.
		$grant = $this->mint( 'wp.update.batch', array(
			'plugins'       => array( 'akismet/akismet.php' ),
			'chunk_size'    => 5,
			'create_backup' => true,
		) );
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.update.batch', array(
				'plugins'       => array( 'akismet/akismet.php' ),
				'chunk_size'    => 5,
				'create_backup' => false,
			) )
		);
		// The exact payload passes.
		$grant2 = $this->mint( 'wp.update.batch', array(
			'plugins'       => array( 'akismet/akismet.php' ),
			'chunk_size'    => 5,
			'create_backup' => true,
		) );
		$this->assertTrue(
			Aura_Worker_Grant::require_for( $this->req( $grant2 ), 'wp.update.batch', array(
				'plugins'       => array( 'akismet/akismet.php' ),
				'chunk_size'    => 5,
				'create_backup' => true,
			) )
		);
	}

	public function test_single_use_grant_cannot_be_replayed(): void {
		$grant = $this->mint( 'wp.rollback', array( 'plugin' => 'akismet' ) );
		$this->assertTrue(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.rollback', array( 'plugin' => 'akismet' ) )
		);
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.rollback', array( 'plugin' => 'akismet' ) )
		);
	}

	public function test_rollback_binds_the_backup_path(): void {
		// A rollback grant approved for one backup must not be spent to restore a
		// different one (the handler passes a caller-supplied backup_path through).
		$grant = $this->mint( 'wp.rollback', array( 'plugin' => 'akismet', 'backup_path' => '/backups/akismet-1.zip' ) );
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.rollback', array( 'plugin' => 'akismet', 'backup_path' => '/backups/akismet-2.zip' ) )
		);
		$grant2 = $this->mint( 'wp.rollback', array( 'plugin' => 'akismet', 'backup_path' => '/backups/akismet-1.zip' ) );
		$this->assertTrue(
			Aura_Worker_Grant::require_for( $this->req( $grant2 ), 'wp.rollback', array( 'plugin' => 'akismet', 'backup_path' => '/backups/akismet-1.zip' ) )
		);
	}

	public function test_snapshot_actions_need_a_grant(): void {
		// Snapshot create/restore are destructive writes reachable by a stolen
		// token via a direct POST, so they are gated plugin-side too.
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req(), 'wp.snapshot.restore', array( 'id' => 'snap-1' ) )
		);
		$grant = $this->mint( 'wp.snapshot.restore', array( 'id' => 'snap-1' ) );
		$this->assertTrue(
			Aura_Worker_Grant::require_for( $this->req( $grant ), 'wp.snapshot.restore', array( 'id' => 'snap-1' ) )
		);
		$this->assertDenied(
			Aura_Worker_Grant::require_for( $this->req(), 'wp.snapshot.create', array( 'kind' => 'file', 'target' => 'wp-content/x' ) )
		);
	}

	public function test_no_pubkey_is_a_bypass(): void {
		// Back-compat: a site with no provisioned key runs token-only (no grant).
		unset( $GLOBALS['_options']['aura_worker_grant_pubkey'] );
		$this->assertTrue(
			Aura_Worker_Grant::require_for( $this->req(), 'wp.update.plugin', array( 'plugin' => 'akismet/akismet.php' ) )
		);
	}

	// --- self-update source allowlist -------------------------------------

	/**
	 * @dataProvider self_update_urls
	 */
	public function test_self_update_source_allowlist( string $url, bool $allowed ): void {
		$api = ( new ReflectionClass( Aura_Worker_API::class ) )->newInstanceWithoutConstructor();
		$m   = new ReflectionMethod( Aura_Worker_API::class, 'is_allowed_self_update_url' );
		$m->setAccessible( true );
		$this->assertSame( $allowed, $m->invoke( $api, $url ) );
	}

	public static function self_update_urls(): array {
		return array(
			'official release'     => array( 'https://github.com/Digitizers/SiteAgent/releases/download/v2.7.0/digitizer-site-worker.zip', true ),
			// Non-release repo artifacts must be rejected even for the right repo.
			'branch archive zip'   => array( 'https://github.com/Digitizers/SiteAgent/archive/refs/heads/main.zip', false ),
			'dot-dot traversal'    => array( 'https://github.com/Digitizers/SiteAgent/releases/download/../../../../attacker/evil/releases/download/v1/evil.zip', false ),
			'encoded traversal'    => array( 'https://github.com/Digitizers/SiteAgent/releases/download/%2e%2e/%2e%2e/attacker/evil/v1/evil.zip', false ),
			'tag archive zip'      => array( 'https://github.com/Digitizers/SiteAgent/archive/refs/tags/v2.7.0.zip', false ),
			// Only .zip installs; never a tarball.
			'release tarball'      => array( 'https://github.com/Digitizers/SiteAgent/releases/download/v2.7.0/x.tar.gz', false ),
			// CDN hosts are never a zip_url input (WP follows GitHub's redirect
			// internally), so they are intentionally NOT allowlisted.
			'github asset cdn'     => array( 'https://objects.githubusercontent.com/github-production-release-asset/abc.zip', false ),
			'codeload'             => array( 'https://codeload.github.com/Digitizers/SiteAgent/zip/refs/tags/v2.7.0', false ),
			'wrong repo on github' => array( 'https://github.com/attacker/evil/releases/download/v1/evil.zip', false ),
			'foreign host'         => array( 'https://evil.example.com/digitizer-site-worker.zip', false ),
			'http not https'       => array( 'http://github.com/Digitizers/SiteAgent/releases/download/v2.7.0/x.zip', false ),
			'empty url'            => array( '', false ),
		);
	}
}
