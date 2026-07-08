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

	/** Register a post so the get_post() existence check in snapshot_meta passes. */
	private function seedPost( int $id ): void {
		$GLOBALS['_posts'][ $id ] = (object) array( 'ID' => $id, 'post_content' => '' );
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

	public function test_meta_snapshot_and_restore_roundtrip(): void {
		// Simulates _elementor_data: a single serialized value under one key.
		$this->seedPost( 42 );
		update_post_meta( 42, '_elementor_data', '[{"id":"a","elType":"container"}]' );
		$snaps = new Aura_Worker_Snapshots();

		$snap = $snaps->snapshot_meta( 42, '_elementor_data' );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'meta', $snap['snapshot']['kind'] );
		$this->assertSame( array( '_elementor_data' ), $snap['snapshot']['keys'] );

		update_post_meta( 42, '_elementor_data', '[{"id":"b","elType":"widget"}]' );
		$restore = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertTrue( $restore['success'] );
		$this->assertSame( '[{"id":"a","elType":"container"}]', get_post_meta( 42, '_elementor_data', true ) );
	}

	public function test_meta_snapshot_of_absent_key_restores_to_absent(): void {
		// Key does not exist when snapshotted (a page never built with Elementor).
		$this->seedPost( 7 );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_meta( 7, '_elementor_data' );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( array( '_elementor_data' ), $snap['snapshot']['keys'] );

		// A later write adds the key; restoring must remove it again, not leave ''.
		update_post_meta( 7, '_elementor_data', 'built-later' );
		$snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( metadata_exists( 'post', 7, '_elementor_data' ) );
	}

	public function test_meta_snapshot_captures_multiple_keys(): void {
		$this->seedPost( 9 );
		update_post_meta( 9, '_elementor_data', 'tree' );
		update_post_meta( 9, '_elementor_page_settings', 'settings' );
		$snaps = new Aura_Worker_Snapshots();

		$snap = $snaps->snapshot_meta( 9, array( '_elementor_data', '_elementor_page_settings' ) );
		$this->assertTrue( $snap['success'] );

		update_post_meta( 9, '_elementor_data', 'clobbered' );
		delete_post_meta( 9, '_elementor_page_settings' );
		$snaps->restore( $snap['snapshot']['id'] );

		$this->assertSame( 'tree', get_post_meta( 9, '_elementor_data', true ) );
		$this->assertSame( 'settings', get_post_meta( 9, '_elementor_page_settings', true ) );
	}

	public function test_meta_restore_reports_failure_when_write_fails(): void {
		// A failed meta write must NOT report a successful rollback (Codex R1 P2):
		// governance would record a restore that never happened while the value
		// stays clobbered.
		$this->seedPost( 11 );
		update_post_meta( 11, '_elementor_data', 'original' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_meta( 11, '_elementor_data' );

		// Clobber, then force the restore write to fail with the value NOT matching.
		update_post_meta( 11, '_elementor_data', 'clobbered' );
		$GLOBALS['_sa_state']['update_post_meta_return'][11]['_elementor_data'] = false;

		$restore = $snaps->restore( $snap['snapshot']['id'] );
		$this->assertFalse( $restore['success'] );
		$this->assertStringContainsString( 'Failed to restore meta key', $restore['error'] );
	}

	public function test_meta_restore_succeeds_when_value_already_matches(): void {
		// update_post_meta also returns false when the stored value already equals
		// the target (a no-op) — that is NOT a failure and must report success.
		$this->seedPost( 12 );
		update_post_meta( 12, '_elementor_data', 'original' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_meta( 12, '_elementor_data' );

		// Value is already 'original'; force the falsey (no-op) return.
		$GLOBALS['_sa_state']['update_post_meta_return'][12]['_elementor_data'] = false;

		$restore = $snaps->restore( $snap['snapshot']['id'] );
		$this->assertTrue( $restore['success'] );
		$this->assertSame( 'original', get_post_meta( 12, '_elementor_data', true ) );
	}

	public function test_meta_restore_reports_failure_when_delete_fails(): void {
		// Absent-at-capture key that was added later: if the rollback delete is
		// vetoed/fails, the added meta stays and we must NOT report success
		// (Codex R2 P2 — sibling of the update read-back check).
		$this->seedPost( 13 );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_meta( 13, '_elementor_data' ); // absent at capture

		update_post_meta( 13, '_elementor_data', 'added-later' );
		$GLOBALS['_sa_state']['delete_post_meta_return'][13]['_elementor_data'] = false;

		$restore = $snaps->restore( $snap['snapshot']['id'] );
		$this->assertFalse( $restore['success'] );
		$this->assertStringContainsString( 'Failed to remove meta key', $restore['error'] );
	}

	public function test_meta_snapshot_rejects_revision_ids(): void {
		// Snapshotting a revision id is unsafe: get_post_meta reads the revision's
		// own meta but update/delete can hit the parent, so restore could clobber
		// the parent page (Codex R3 P2). Reject up front.
		$GLOBALS['_posts'][ 200 ] = (object) array(
			'ID'          => 200,
			'post_type'   => 'revision',
			'post_parent' => 100,
		);
		$snaps  = new Aura_Worker_Snapshots();
		$result = $snaps->snapshot_meta( 200, '_elementor_data' );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'revision', $result['error'] );
	}

	public function test_meta_restore_fails_when_target_post_deleted(): void {
		// Page deleted after the snapshot: restoring meta would create orphaned
		// wp_postmeta rows and falsely report success (Codex R3 P2). Fail closed.
		$this->seedPost( 21 );
		update_post_meta( 21, '_elementor_data', 'original' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_meta( 21, '_elementor_data' );

		unset( $GLOBALS['_posts'][ 21 ] ); // page deleted
		$restore = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertStringContainsString( 'no longer exists', $restore['error'] );
	}

	public function test_meta_snapshot_of_missing_post_fails(): void {
		$snaps  = new Aura_Worker_Snapshots();
		$result = $snaps->snapshot_meta( 0, '_elementor_data' );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid post id', $result['error'] );
	}

	public function test_meta_snapshot_requires_at_least_one_key(): void {
		$this->seedPost( 5 );
		$snaps  = new Aura_Worker_Snapshots();
		$result = $snaps->snapshot_meta( 5, array() );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No meta keys', $result['error'] );
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

	// --- snapshot_posts (multi-post collection: existence + meta) -----------

	private function seedClassPost( int $id, string $data ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'             => $id,
			'post_type'      => 'e-global-class',
			'post_status'    => 'publish',
			'post_title'     => 'class-' . $id,
			'post_name'      => 'class-' . $id,
			'post_parent'    => 0,
			'post_content'   => '',
			'post_excerpt'   => '',
			'menu_order'     => 0,
			'post_author'    => 7,
			'post_date'      => '2026-01-02 03:04:05',
			'post_date_gmt'  => '2026-01-02 03:04:05',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
		update_post_meta( $id, '_elementor_global_class_data', $data );
	}

	public function test_posts_snapshot_reverts_meta_on_surviving_post(): void {
		$this->seedClassPost( 501, '{"v":1}' );
		$snaps = new Aura_Worker_Snapshots();

		$snap = $snaps->snapshot_posts( array( 501 ), '_elementor_global_class_data' );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'posts', $snap['snapshot']['kind'] );

		update_post_meta( 501, '_elementor_global_class_data', '{"v":2}' );
		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$this->assertSame( '{"v":1}', get_post_meta( 501, '_elementor_global_class_data', true ) );
	}

	public function test_posts_snapshot_deletes_a_created_post_on_restore(): void {
		// 502 does NOT exist at capture; the "write" creates it → restore deletes it.
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_posts( array( 502 ), '_elementor_global_class_data' );
		$this->assertTrue( $snap['success'] );

		$this->seedClassPost( 502, '{"created":true}' );
		$this->assertNotNull( get_post( 502 ) );

		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$this->assertNull( get_post( 502 ), 'A post created after the snapshot is deleted on rollback.' );
	}

	public function test_posts_snapshot_recreates_a_deleted_post_with_same_id(): void {
		$this->seedClassPost( 503, '{"orig":true}' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_posts( array( 503 ), '_elementor_global_class_data' );
		$this->assertTrue( $snap['success'] );

		wp_delete_post( 503, true );
		$this->assertNull( get_post( 503 ) );

		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$restored = get_post( 503 );
		$this->assertNotNull( $restored, 'A deleted post is recreated on rollback.' );
		$this->assertSame( 503, (int) $restored->ID, 'Recreated with its ORIGINAL id (so id references stay valid).' );
		$this->assertSame( 'e-global-class', $restored->post_type );
		$this->assertSame( '2026-01-02 03:04:05', $restored->post_date, 'Recreate preserves the original date, not "now".' );
		$this->assertSame( 7, (int) $restored->post_author, 'Recreate preserves the original author.' );
		$this->assertSame( '{"orig":true}', get_post_meta( 503, '_elementor_global_class_data', true ) );
	}

	public function test_posts_snapshot_absent_then_absent_is_noop(): void {
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_posts( array( 504 ), '_elementor_global_class_data' );
		$this->assertTrue( $snap['success'] );
		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$this->assertNull( get_post( 504 ) );
	}

	public function test_posts_snapshot_mixed_set_roundtrip(): void {
		// 510 exists (meta will change), 511 absent (will be created), 512 exists
		// (will be deleted). One snapshot, one restore reverts all three.
		$this->seedClassPost( 510, '{"a":1}' );
		$this->seedClassPost( 512, '{"c":1}' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_posts( array( 510, 511, 512 ), '_elementor_global_class_data' );
		$this->assertTrue( $snap['success'] );

		update_post_meta( 510, '_elementor_global_class_data', '{"a":2}' ); // modified
		$this->seedClassPost( 511, '{"b":1}' );                            // created
		wp_delete_post( 512, true );                                        // deleted

		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$this->assertSame( '{"a":1}', get_post_meta( 510, '_elementor_global_class_data', true ), '510 meta reverted' );
		$this->assertNull( get_post( 511 ), '511 (created) deleted' );
		$this->assertNotNull( get_post( 512 ), '512 (deleted) recreated' );
		$this->assertSame( '{"c":1}', get_post_meta( 512, '_elementor_global_class_data', true ), '512 meta restored' );
	}

	public function test_posts_snapshot_reverts_field_change_on_surviving_post(): void {
		// A "delete" that TRASHES (status change, row kept) or any field edit must be
		// reverted — not just meta.
		$this->seedClassPost( 520, '{"v":1}' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_posts( array( 520 ), '_elementor_global_class_data' );
		$this->assertTrue( $snap['success'] );

		wp_update_post( array( 'ID' => 520, 'post_status' => 'trash', 'post_title' => 'renamed' ) );
		$this->assertSame( 'trash', get_post( 520 )->post_status );

		$this->assertTrue( $snaps->restore( $snap['snapshot']['id'] )['success'] );
		$post = get_post( 520 );
		$this->assertSame( 'publish', $post->post_status, 'A trashed/field-changed surviving post has its fields reverted.' );
		$this->assertSame( 'class-520', $post->post_title );
	}

	public function test_posts_snapshot_rejects_empty_ids(): void {
		$snaps = new Aura_Worker_Snapshots();
		$this->assertFalse( $snaps->snapshot_posts( array(), '_x' )['success'] );
	}
}
