<?php
/**
 * Tests for Aura_Power_Tool_Fs_Write — jailed, snapshot-first file writing.
 *
 * This test arms the tool via its wp-config constant so the real write path,
 * jail, and snapshot-first behaviour are exercised.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

// Arm fs-write execution for this test run (process-global constant; only the
// fs-write tool reads it, and only these tests execute it).
if ( ! defined( 'AURA_POWER_ALLOW_FS_WRITE' ) ) {
	define( 'AURA_POWER_ALLOW_FS_WRITE', true );
}

final class FsWriteTest extends TestCase {

	private Aura_Power_Tool_Fs_Write $tool;
	private string $outside;

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0777, true );
		$this->tool    = new Aura_Power_Tool_Fs_Write();
		$this->outside = tempnam( sys_get_temp_dir(), 'sa_wout_' );
	}

	protected function tearDown(): void {
		$this->rrmdir( WP_CONTENT_DIR );
		@unlink( $this->outside );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	public function test_annotations_are_destructive_and_approval_gated(): void {
		$ann = $this->tool->get_annotations();
		$this->assertTrue( $ann['destructive'] );
		$this->assertTrue( $ann['requires_approval'] );
		$this->assertTrue( $ann['supports_preview'] );
	}

	public function test_writes_a_new_file_inside_jail(): void {
		$file   = WP_CONTENT_DIR . '/new.txt';
		$result = $this->tool->execute( array( 'path' => $file, 'content' => "hello\n" ) );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertTrue( $result['created'] );
		$this->assertSame( "hello\n", file_get_contents( $file ) );
		$this->assertSame( '', $result['snapshot_id'] ); // new file → nothing to snapshot.
	}

	public function test_overwrite_snapshots_first(): void {
		$file = WP_CONTENT_DIR . '/edit.txt';
		file_put_contents( $file, "v1\n" );

		$result = $this->tool->execute( array( 'path' => $file, 'content' => "v2\n" ) );

		$this->assertFalse( $result['created'] );
		$this->assertSame( "v2\n", file_get_contents( $file ) );
		$this->assertNotSame( '', $result['snapshot_id'] );

		// The snapshot restores the original content.
		$snaps = new Aura_Worker_Snapshots();
		$snaps->restore( $result['snapshot_id'] );
		$this->assertSame( "v1\n", file_get_contents( $file ) );
	}

	public function test_refuses_wp_config(): void {
		$result = $this->tool->execute( array( 'path' => WP_CONTENT_DIR . '/wp-config.php', 'content' => 'x' ) );
		$this->assertStringContainsString( 'wp-config.php', $result['error'] );
	}

	public function test_refuses_path_outside_jail(): void {
		$result = $this->tool->execute( array( 'path' => $this->outside, 'content' => 'x' ) );
		$this->assertStringContainsString( 'outside wp-content', $result['error'] );
	}

	public function test_dry_run_reports_diff_without_writing(): void {
		$file = WP_CONTENT_DIR . '/dry.txt';
		$preview = $this->tool->dry_run( array( 'path' => $file, 'content' => "a\nb\nc\n" ) );

		$this->assertTrue( $preview['created'] );
		$this->assertSame( 4, $preview['diff']['new_line_count'] ); // 3 lines + trailing.
		$this->assertFileDoesNotExist( $file );
	}

	public function test_refuses_symlink_target_escaping_the_jail(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		// A symlink INSIDE wp-content pointing OUTSIDE it — the parent passes the
		// jail check, but the resolved target must be refused.
		$link = WP_CONTENT_DIR . '/escape';
		file_put_contents( $this->outside, "outside\n" );
		symlink( $this->outside, $link );

		$result = $this->tool->execute( array( 'path' => $link, 'content' => "pwned\n" ) );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'symlink', $result['error'] );
		// The outside file must be untouched.
		$this->assertSame( "outside\n", file_get_contents( $this->outside ) );
	}

	public function test_refuses_dangling_symlink_target(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		// Symlink inside wp-content whose target does NOT exist yet. file_exists()
		// would report false; the write must still be refused (not created outside).
		$ghost = sys_get_temp_dir() . '/sa_ghost_target_' . getmypid() . '.txt';
		@unlink( $ghost );
		$link = WP_CONTENT_DIR . '/dangling';
		symlink( $ghost, $link );

		$result = $this->tool->execute( array( 'path' => $link, 'content' => "pwned\n" ) );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'symlink', $result['error'] );
		$this->assertFileDoesNotExist( $ghost ); // target was never created.
	}

	public function test_dry_run_flags_executable_target(): void {
		$preview = $this->tool->dry_run( array( 'path' => WP_CONTENT_DIR . '/uploads/shell.php', 'content' => '<?php' ) );
		// Parent doesn't exist yet → returns an error, so create the dir first.
		mkdir( WP_CONTENT_DIR . '/uploads', 0777, true );
		$preview = $this->tool->dry_run( array( 'path' => WP_CONTENT_DIR . '/uploads/shell.php', 'content' => '<?php' ) );
		$this->assertTrue( $preview['executable_target'] );
	}
}
