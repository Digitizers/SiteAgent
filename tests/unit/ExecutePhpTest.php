<?php
/**
 * Tests for Aura_Power_Tool_Execute_Php — hard shell-out denial, advisory scan,
 * and the default-off execution gate. The tool is left disabled (constant unset)
 * so eval() is never reached in CI.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ExecutePhpTest extends TestCase {

	private Aura_Power_Tool_Execute_Php $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Power_Tool_Execute_Php();
	}

	public function test_annotations_destructive_and_approval_gated(): void {
		$ann = $this->tool->get_annotations();
		$this->assertTrue( $ann['destructive'] );
		$this->assertTrue( $ann['requires_approval'] );
		$this->assertTrue( $ann['supports_preview'] );
	}

	public function test_execution_disabled_without_constant(): void {
		$result = $this->tool->execute( array( 'code' => 'return 1 + 1;' ) );
		$this->assertStringContainsString( 'disabled', $result['error'] );
	}

	public function test_execute_refuses_shell_functions_even_if_enabled_path_reached(): void {
		// Even the disabled guard aside, dry_run must hard-deny shell-outs.
		foreach ( array( 'system("ls");', 'exec("id");', 'shell_exec("whoami");', 'passthru("ls");', 'proc_open("x", [], $p);' ) as $code ) {
			$preview = $this->tool->dry_run( array( 'code' => $code ) );
			$this->assertNotNull( $preview['hard_denied'], $code );
		}
	}

	public function test_dry_run_hard_denies_backticks(): void {
		$preview = $this->tool->dry_run( array( 'code' => 'echo `id`;' ) );
		$this->assertNotNull( $preview['hard_denied'] );
		$this->assertStringContainsString( 'backtick', $preview['hard_denied'] );
	}

	public function test_dry_run_flags_risky_constructs_as_warnings(): void {
		$preview = $this->tool->dry_run( array(
			'code' => 'file_put_contents("x", base64_decode($y)); eval($z);',
		) );
		$this->assertNull( $preview['hard_denied'] );
		$this->assertContains( 'file_put_contents', $preview['warnings'] );
		$this->assertContains( 'base64_decode', $preview['warnings'] );
		$this->assertContains( 'eval', $preview['warnings'] );
	}

	public function test_dry_run_flags_superglobals(): void {
		$preview = $this->tool->dry_run( array( 'code' => 'return $_GET["id"];' ) );
		$this->assertContains( 'superglobal', $preview['warnings'] );
	}

	public function test_clean_code_has_no_flags_and_would_not_run_while_disabled(): void {
		$preview = $this->tool->dry_run( array( 'code' => 'return get_option("blogname");' ) );
		$this->assertNull( $preview['hard_denied'] );
		$this->assertSame( array(), $preview['warnings'] );
		$this->assertFalse( $preview['would_run'] ); // constant not set.
	}

	public function test_flags_indirect_call_bypasses(): void {
		// $f('system') hides a shell-out from the name-based deny-list — surface it.
		$preview = $this->tool->dry_run( array( 'code' => '$f = "sys"."tem"; $f("id");' ) );
		$this->assertContains( 'variable-function-call', $preview['warnings'] );
	}

	public function test_flags_include_and_ffi(): void {
		$inc = $this->tool->dry_run( array( 'code' => 'include $path;' ) );
		$this->assertContains( 'include/require', $inc['warnings'] );

		$ffi = $this->tool->dry_run( array( 'code' => '$x = FFI::cdef("...");' ) );
		$this->assertContains( 'FFI', $ffi['warnings'] );
	}
}
