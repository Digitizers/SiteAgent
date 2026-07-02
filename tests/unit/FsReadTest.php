<?php
/**
 * Tests for Aura_Power_Tool_Fs_Read — jailed, read-only file access.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class FsReadTest extends TestCase {

	private Aura_Power_Tool_Fs_Read $tool;
	private string $outside;

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0777, true );
		$this->tool    = new Aura_Power_Tool_Fs_Read();
		$this->outside = tempnam( sys_get_temp_dir(), 'sa_out_' );
		file_put_contents( $this->outside, "secret outside jail\n" );
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

	public function test_annotations_are_read_only_no_approval(): void {
		$ann = $this->tool->get_annotations();
		$this->assertTrue( $ann['read_only'] );
		$this->assertFalse( $ann['requires_approval'] );
	}

	public function test_reads_a_file_inside_wp_content(): void {
		$file = WP_CONTENT_DIR . '/note.txt';
		file_put_contents( $file, "hello jail\n" );

		$result = $this->tool->execute( array( 'path' => $file ) );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertStringContainsString( 'hello jail', $result['content'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_respects_max_bytes_and_flags_truncation(): void {
		$file = WP_CONTENT_DIR . '/big.txt';
		file_put_contents( $file, str_repeat( 'A', 100 ) );

		$result = $this->tool->execute( array( 'path' => $file, 'max_bytes' => 10 ) );

		$this->assertSame( 10, $result['returned_bytes'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_missing_file_errors(): void {
		$result = $this->tool->execute( array( 'path' => WP_CONTENT_DIR . '/nope.txt' ) );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_wp_config_is_refused_even_inside_jail(): void {
		$file = WP_CONTENT_DIR . '/wp-config.php';
		file_put_contents( $file, "<?php // fake secrets\n" );

		$result = $this->tool->execute( array( 'path' => $file ) );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'wp-config.php', $result['error'] );
	}

	public function test_path_outside_jail_is_refused(): void {
		$result = $this->tool->execute( array( 'path' => $this->outside ) );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'outside the allowed roots', $result['error'] );
	}

	public function test_directory_is_not_readable_as_file(): void {
		mkdir( WP_CONTENT_DIR . '/sub' );
		$result = $this->tool->execute( array( 'path' => WP_CONTENT_DIR . '/sub' ) );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'regular file', $result['error'] );
	}
}
