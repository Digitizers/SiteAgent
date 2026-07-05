<?php
/**
 * Tests for Aura_Worker_Snapshots — capture-before-write for files + options,
 * the reversal substrate the Governed Power Tools (Track G) build on.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SnapshotsTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0755, true );
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

	public function test_constructor_protects_snapshot_dir(): void {
		new Aura_Worker_Snapshots();
		$this->assertFileExists( WP_CONTENT_DIR . '/aura-backups/snapshots/.htaccess' );
	}

	public function test_file_snapshot_and_restore_roundtrip(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/target.php';
		file_put_contents( $file, "<?php // original\n" );

		$snap = $snaps->snapshot_file( $file );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'file', $snap['snapshot']['kind'] );

		file_put_contents( $file, "<?php // clobbered\n" );
		$restore = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertTrue( $restore['success'] );
		$this->assertStringContainsString( 'original', file_get_contents( $file ) );
		$this->assertStringNotContainsString( 'clobbered', file_get_contents( $file ) );
	}

	public function test_file_snapshot_of_missing_file_fails(): void {
		$snaps  = new Aura_Worker_Snapshots();
		$result = $snaps->snapshot_file( WP_CONTENT_DIR . '/nope.php' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_option_snapshot_and_restore_roundtrip(): void {
		update_option( 'my_setting', array( 'mode' => 'safe', 'n' => 1 ) );
		$snaps = new Aura_Worker_Snapshots();

		$snap = $snaps->snapshot_option( 'my_setting' );
		$this->assertTrue( $snap['success'] );
		$this->assertTrue( $snap['snapshot']['existed'] );

		update_option( 'my_setting', array( 'mode' => 'danger' ) );
		$snaps->restore( $snap['snapshot']['id'] );

		$this->assertSame( array( 'mode' => 'safe', 'n' => 1 ), get_option( 'my_setting' ) );
	}

	public function test_restoring_absent_option_snapshot_deletes_the_option(): void {
		// Option does not exist when snapshotted.
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_option( 'later_created' );
		$this->assertFalse( $snap['snapshot']['existed'] );

		// It gets created afterwards; restoring the snapshot must remove it again.
		update_option( 'later_created', 'value' );
		$snaps->restore( $snap['snapshot']['id'] );

		$this->assertSame( 'DEFAULT', get_option( 'later_created', 'DEFAULT' ) );
	}

	public function test_option_valued_like_the_old_sentinel_is_not_treated_as_absent(): void {
		// An option whose value is literally "__aura_absent__" must still be seen
		// as existing (the sentinel is now an uncollidable object).
		update_option( 'edge_opt', '__aura_absent__' );
		$snaps = new Aura_Worker_Snapshots();

		$snap = $snaps->snapshot_option( 'edge_opt' );
		$this->assertTrue( $snap['snapshot']['existed'] );

		update_option( 'edge_opt', 'changed' );
		$snaps->restore( $snap['snapshot']['id'] );
		// Restored to the original value, NOT deleted.
		$this->assertSame( '__aura_absent__', get_option( 'edge_opt' ) );
	}

	public function test_restore_unknown_snapshot_fails(): void {
		$snaps  = new Aura_Worker_Snapshots();
		$result = $snaps->restore( 'snap_does_not_exist' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_list_and_delete(): void {
		$snaps = new Aura_Worker_Snapshots();
		update_option( 'opt_a', 'a' );
		$snaps->snapshot_option( 'opt_a' );
		$this->assertCount( 1, $snaps->list_snapshots() );

		$id = $snaps->list_snapshots()[0]['id'];
		$this->assertTrue( $snaps->delete( $id ) );
		$this->assertCount( 0, $snaps->list_snapshots() );
	}
}
