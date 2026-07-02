<?php
/**
 * Tests for Aura_Worker_Rollback — plugin zip backup + restore roundtrip.
 *
 * Runs against a real filesystem sandbox under the system temp dir
 * (WP_CONTENT_DIR / WP_PLUGIN_DIR from bootstrap) using a real ZipArchive.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RollbackTest extends TestCase {

	private string $plugin_slug = 'testplugin';

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::markTestSkipped( 'ext-zip not available.' );
		}
	}

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_PLUGIN_DIR . '/' . $this->plugin_slug, 0777, true );
		file_put_contents( WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/main.php', "<?php // v1\n" );
	}

	protected function tearDown(): void {
		$this->rrmdir( WP_CONTENT_DIR );
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

	public function test_constructor_protects_backup_dir(): void {
		new Aura_Worker_Rollback();

		$this->assertDirectoryExists( WP_CONTENT_DIR . '/aura-backups/' );
		$this->assertFileExists( WP_CONTENT_DIR . '/aura-backups/.htaccess' );
		$this->assertStringContainsString(
			'Deny from all',
			file_get_contents( WP_CONTENT_DIR . '/aura-backups/.htaccess' )
		);
	}

	public function test_backup_creates_zip_and_lists_it(): void {
		$rollback = new Aura_Worker_Rollback();
		$result   = $rollback->backup_plugin( $this->plugin_slug );

		$this->assertTrue( $result['success'] );
		$this->assertFileExists( $result['backup_path'] );

		$backups = $rollback->list_backups( $this->plugin_slug );
		$this->assertCount( 1, $backups );
		$this->assertSame( $this->plugin_slug, $backups[0]['plugin_slug'] );
	}

	public function test_backup_of_missing_plugin_fails(): void {
		$rollback = new Aura_Worker_Rollback();
		$result   = $rollback->backup_plugin( 'does-not-exist' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_restore_roundtrip_recovers_original_content(): void {
		$rollback = new Aura_Worker_Rollback();
		$file     = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/main.php';

		// Back up v1, then mutate the plugin to v2.
		$backup = $rollback->backup_plugin( $this->plugin_slug );
		$this->assertTrue( $backup['success'] );
		file_put_contents( $file, "<?php // v2 broken\n" );
		$this->assertStringContainsString( 'v2', file_get_contents( $file ) );

		// Restore returns the directory to v1.
		$restore = $rollback->restore_plugin( $this->plugin_slug, $backup['backup_path'] );
		$this->assertTrue( $restore['success'] );
		$this->assertStringContainsString( 'v1', file_get_contents( $file ) );
		$this->assertStringNotContainsString( 'v2', file_get_contents( $file ) );
	}

	public function test_restore_from_missing_backup_fails(): void {
		$rollback = new Aura_Worker_Rollback();
		$result   = $rollback->restore_plugin( $this->plugin_slug, WP_CONTENT_DIR . '/aura-backups/nope.zip' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}
}
