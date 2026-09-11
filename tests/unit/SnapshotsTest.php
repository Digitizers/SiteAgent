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

	public function test_posts_snapshot_reports_a_failed_delete_of_a_created_post(): void {
		// A pre_delete_post short-circuit returns truthy without deleting → restore
		// must detect the post is still there (verify by existence) and report failure
		// rather than lie about a clean rollback.
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_posts( array( 530 ), '_elementor_global_class_data' ); // 530 absent at capture
		$this->assertTrue( $snap['success'] );

		$this->seedClassPost( 530, '{"created":true}' );          // the "write" creates it
		$GLOBALS['_sa_state']['wp_delete_post_noop'][530] = true;  // deletion short-circuited

		$res = $snaps->restore( $snap['snapshot']['id'] );
		$this->assertFalse( $res['success'], 'A created post that could not be deleted fails the rollback.' );
		$this->assertNotNull( get_post( 530 ) );
	}

	public function test_posts_snapshot_rejects_empty_ids(): void {
		$snaps = new Aura_Worker_Snapshots();
		$this->assertFalse( $snaps->snapshot_posts( array(), '_x' )['success'] );
	}

	// --- object-injection hardening on the restore path ---------------------
	//
	// Payload files are written by the plugin, but they are the one on-disk,
	// tamperable input restore() consumes. A plain unserialize() there would
	// build arbitrary objects and fire __wakeup()/__destruct() gadget chains, so
	// every payload is unserialized with allowed_classes => false. These tests
	// pin the security property (no class is ever instantiated) and prove the
	// scalar/array payloads the engine actually uses still round-trip.

	/** Overwrite a snapshot's payload file with attacker-chosen bytes. */
	private function tamperPayload( array $snapshot, string $bytes ): void {
		$this->assertNotEmpty( $snapshot['payload_path'] ?? '' );
		file_put_contents( $snapshot['payload_path'], $bytes );
	}

	public function test_object_payload_never_instantiates_a_class_on_restore(): void {
		Aura_Snapshot_Gadget::$woke = false;

		update_option( 'gadget_opt', 'benign' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_option( 'gadget_opt' );

		// Attacker replaces the payload with a serialized gadget object.
		$this->tamperPayload( $snap['snapshot'], serialize( new Aura_Snapshot_Gadget() ) );

		$res = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( Aura_Snapshot_Gadget::$woke, 'allowed_classes=false must prevent __wakeup() from ever firing.' );
		$this->assertFalse( $res['success'], 'An object payload is refused, not written back.' );
		$this->assertStringContainsString( 'object', $res['error'] );
		// The live option is untouched — no incomplete class leaked into storage.
		$this->assertSame( 'benign', get_option( 'gadget_opt' ) );
	}

	public function test_meta_object_payload_is_rejected_as_corrupt(): void {
		Aura_Snapshot_Gadget::$woke = false;

		$this->seedPost( 88 );
		update_post_meta( 88, '_elementor_data', 'orig' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_meta( 88, '_elementor_data' );

		$this->tamperPayload( $snap['snapshot'], serialize( new Aura_Snapshot_Gadget() ) );

		$res = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( Aura_Snapshot_Gadget::$woke );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'corrupt', $res['error'] );
	}

	public function test_posts_object_payload_is_rejected_as_corrupt(): void {
		Aura_Snapshot_Gadget::$woke = false;

		$this->seedClassPost( 601, '{"v":1}' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_posts( array( 601 ), '_elementor_global_class_data' );

		$this->tamperPayload( $snap['snapshot'], serialize( new Aura_Snapshot_Gadget() ) );

		$res = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( Aura_Snapshot_Gadget::$woke );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'corrupt', $res['error'] );
	}

	public function test_option_nested_object_payload_is_rejected(): void {
		// A gadget hidden INSIDE an array: allowed_classes=false strips it to an
		// incomplete class, the top level stays an array, so a top-level-only guard
		// would store it as a successful restore. The recursive check must catch it.
		Aura_Snapshot_Gadget::$woke = false;

		update_option( 'nested_opt', 'benign' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_option( 'nested_opt' );

		$this->tamperPayload( $snap['snapshot'], serialize( array( 'x' => new Aura_Snapshot_Gadget() ) ) );

		$res = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( Aura_Snapshot_Gadget::$woke );
		$this->assertFalse( $res['success'], 'A nested object payload must not restore.' );
		$this->assertStringContainsString( 'object', $res['error'] );
		// Nothing corrupt leaked into storage.
		$this->assertSame( 'benign', get_option( 'nested_opt' ) );
	}

	public function test_meta_nested_object_payload_is_rejected(): void {
		// snapshot_meta serializes array( key => array( 'existed'=>.., 'value'=>.. ) ),
		// so a gadget in a leaf value is the realistic nesting for this kind.
		Aura_Snapshot_Gadget::$woke = false;

		$this->seedPost( 91 );
		update_post_meta( 91, '_elementor_data', 'orig' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_meta( 91, '_elementor_data' );

		$payload = array( '_elementor_data' => array( 'existed' => true, 'value' => new Aura_Snapshot_Gadget() ) );
		$this->tamperPayload( $snap['snapshot'], serialize( $payload ) );

		$res = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( Aura_Snapshot_Gadget::$woke );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'corrupt', $res['error'] );
		// The live meta was not overwritten with a stripped class.
		$this->assertSame( 'orig', get_post_meta( 91, '_elementor_data', true ) );
	}

	public function test_cyclic_array_payload_fails_closed_without_fataling(): void {
		// unserialize() rebuilds a serialized reference cycle into a genuinely
		// self-referential array; the stripped-object walk must not recurse into
		// it forever (stack exhaustion / DoS) but reject it fast.
		update_option( 'cyclic_opt', 'benign' );
		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_option( 'cyclic_opt' );

		// a:2:{s:1:"k";i:1;s:4:"self";R:1;} — element 'self' points back at the array.
		$cyclic = 'a:2:{s:1:"k";i:1;s:4:"self";R:1;}';
		$this->tamperPayload( $snap['snapshot'], $cyclic );

		$start = microtime( true );
		$res   = $snaps->restore( $snap['snapshot']['id'] );
		$elapsed = microtime( true ) - $start;

		$this->assertFalse( $res['success'], 'A cyclic payload must be refused, not restored.' );
		$this->assertStringContainsString( 'object', $res['error'] );
		$this->assertLessThan( 1.0, $elapsed, 'The walk must terminate, not spin on the cycle.' );
		$this->assertSame( 'benign', get_option( 'cyclic_opt' ) );
	}

	public function test_scalar_and_array_option_payloads_still_round_trip(): void {
		// The hardening must not regress the real payloads: scalars and nested
		// arrays are exactly what options and Elementor meta hold.
		$snaps = new Aura_Worker_Snapshots();

		update_option( 'scalar_opt', 'a string' );
		$s1 = $snaps->snapshot_option( 'scalar_opt' );
		update_option( 'scalar_opt', 'changed' );
		$this->assertTrue( $snaps->restore( $s1['snapshot']['id'] )['success'] );
		$this->assertSame( 'a string', get_option( 'scalar_opt' ) );

		update_option( 'array_opt', array( 'n' => 1, 'deep' => array( 'x', 'y' ) ) );
		$s2 = $snaps->snapshot_option( 'array_opt' );
		update_option( 'array_opt', array( 'n' => 2 ) );
		$this->assertTrue( $snaps->restore( $s2['snapshot']['id'] )['success'] );
		$this->assertSame( array( 'n' => 1, 'deep' => array( 'x', 'y' ) ), get_option( 'array_opt' ) );
	}

	// --- create_file (P4.6 piece 2) -----------------------------------------

	public function test_create_file_creates_and_its_record_restores_by_unlinking(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/new.php';

		$res = $snaps->create_file( $file, "<?php // agent\n" );

		$this->assertTrue( $res['success'], $res['error'] ?? '' );
		$this->assertFileExists( $file );
		$this->assertSame( "<?php // agent\n", file_get_contents( $file ) );
		$rec = $res['snapshot'];
		$this->assertSame( 'file', $rec['kind'] );
		$this->assertFalse( $rec['existed'] );
		$this->assertSame( hash( 'sha256', "<?php // agent\n" ), $rec['expected_sha256'] );
		$this->assertArrayNotHasKey( 'payload_path', $rec, 'a created file has no payload — the record undoes content at a path' );
		$this->assertArrayNotHasKey( 'staged', $rec, 'the returned record carries no local path' );
		$this->assertArrayNotHasKey( 'meta_path', $rec, 'nor the record file\'s own path' );
		$this->assertFileDoesNotExist( $snaps->get( $rec['id'] )['staged'], 'the staged file is removed after publish' );
		$this->assertSame( 0644, fileperms( $file ) & 0777, 'the created file does not inherit the umask' );

		$restore = $snaps->restore( $rec['id'] );
		$this->assertTrue( $restore['success'] );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_created_file_edited_after_the_create_is_refused_on_restore(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/new.php';
		$rec   = $snaps->create_file( $file, "a\n" )['snapshot'];
		file_put_contents( $file, "b\n" );

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertFileExists( $file, 'different bytes are never deleted' );
	}

	public function test_created_file_deleted_and_recreated_with_identical_bytes_is_unlinked_on_restore(): void {
		// The §2 ruling, asserted on purpose: identical content at the path IS
		// what this record exists to remove, whoever re-created it. No inode.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/new.php';
		$rec   = $snaps->create_file( $file, "same\n" )['snapshot'];
		unlink( $file );
		file_put_contents( $file, "same\n" );

		$this->assertTrue( $snaps->restore( $rec['id'] )['success'] );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_a_dangling_symlink_at_the_created_path_is_reported_never_treated_as_gone(): void {
		// Codex #94 round-2 P2: file_exists() answers false for a dangling
		// symlink, so the restore used to report success and leave a directory
		// entry that goes live the moment its destination appears.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/new.php';
		$rec   = $snaps->create_file( $file, "x\n" )['snapshot'];
		unlink( $file );
		symlink( WP_CONTENT_DIR . '/does-not-exist.php', $file );

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertStringContainsString( 'not a regular file', $restore['error'] );
		$this->assertTrue( is_link( $file ), 'the symlink is reported, never deleted' );
	}

	public function test_restoring_a_created_file_that_is_already_gone_succeeds(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/new.php';
		$rec   = $snaps->create_file( $file, "x\n" )['snapshot'];
		unlink( $file );

		$this->assertTrue( $snaps->restore( $rec['id'] )['success'] );
	}

	public function test_restore_claims_the_path_before_verifying_so_a_file_that_arrives_after_the_claim_is_never_touched(): void {
		// Codex #91 round-1 P1: hash-then-unlink had a window in which another
		// process could replace the target and lose unverified bytes. The
		// restore renames the target to an opaque claim first (atomic, same
		// inode), verifies THAT file, and deletes only it.
		$file  = WP_CONTENT_DIR . '/race.php';
		$snaps = new class( $file ) extends Aura_Worker_Snapshots {
			private $file;
			public function __construct( $file ) { parent::__construct(); $this->file = $file; }
			protected function after_claim( $claim, $target ) {
				file_put_contents( $this->file, "a concurrent writer's new file\n" ); // lands at the path AFTER our claim
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];

		$restore = $snaps->restore( $rec['id'] );

		$this->assertTrue( $restore['success'], 'the agent bytes were verified on the claimed file and removed' );
		$this->assertSame( "a concurrent writer's new file\n", file_get_contents( $file ), 'the newcomer at the path is untouched' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'no claim file left behind' );
	}

	public function test_restore_puts_a_changed_file_back_and_answers_file_changed_since(): void {
		$file  = WP_CONTENT_DIR . '/edited.php';
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited by a human\n" );

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayNotHasKey( 'moved_aside', $restore );
		$this->assertSame( "edited by a human\n", file_get_contents( $file ), 'put back at its path, same bytes' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ) );
	}

	public function test_a_changed_file_whose_path_was_retaken_in_the_window_is_kept_aside_and_named(): void {
		// The one outcome that cannot be undone silently: the claimed file is
		// NOT the agent's bytes, and something else took the path while it was
		// claimed. Nothing is deleted; the changed file stays beside the target
		// under its claim name, and the answer says where.
		$file  = WP_CONTENT_DIR . '/retaken.php';
		$snaps = new class( $file ) extends Aura_Worker_Snapshots {
			private $file;
			public function __construct( $file ) { parent::__construct(); $this->file = $file; }
			protected function after_claim( $claim, $target ) {
				file_put_contents( $this->file, "newcomer\n" );
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertMatchesRegularExpression( '/\/\.aura-restore-[0-9a-f]{16}$/', $restore['moved_aside'] );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ), 'the changed bytes are kept, not deleted' );
		$this->assertSame( "newcomer\n", file_get_contents( $file ), 'the newcomer is untouched' );
		$this->assertStringNotContainsString( '.php', basename( $restore['moved_aside'] ) );
	}

	public function test_create_file_refuses_an_existing_target_before_touching_anything(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/taken.php';
		file_put_contents( $file, "theirs\n" );

		$res = $snaps->create_file( $file, "mine\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'exists', $res['error'] );
		$this->assertSame( "theirs\n", file_get_contents( $file ) );
		$this->assertSame( array(), $snaps->list_snapshots(), 'no record for a create that did not happen' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ), 'no staged file left' );
	}

	public function test_a_lost_race_whose_record_cannot_be_unlinked_is_voided_so_it_never_restores_over_the_winner(): void {
		// Codex #94 round-5 P2: the winner landed the SAME bytes, so a
		// restorable orphan record would pass its hash check and delete the
		// winner's file. When the record's unlink is refused it is voided in
		// place instead (no expected_sha256), and restore refuses it.
		$file  = WP_CONTENT_DIR . '/race.php';
		$snaps = new class( $file ) extends Aura_Worker_Snapshots {
			private $race;
			public function __construct( $race ) { parent::__construct(); $this->race = $race; }
			protected function publish( $tmp, $path ) {
				file_put_contents( $this->race, "mine\n" ); // the winner wrote identical bytes first
				$GLOBALS['_wp_delete_file_fail'] = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $this->list_snapshots()[0]['id'] . '.json'; // and our record's unlink is refused
				return parent::publish( $tmp, $path );
			}
		};

		$res = $snaps->create_file( $file, "mine\n" );
		unset( $GLOBALS['_wp_delete_file_fail'] );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'exists', $res['error'] );
		$this->assertArrayNotHasKey( 'stale_record', $res, 'the record was voided, not left restorable' );
		$recs = $snaps->list_snapshots();
		$this->assertCount( 1, $recs );
		$this->assertTrue( $recs[0]['voided'] );
		$this->assertArrayNotHasKey( 'expected_sha256', $recs[0] );
		$restore = $snaps->restore( $recs[0]['id'] );
		$this->assertFalse( $restore['success'] );
		$this->assertSame( "mine\n", file_get_contents( $file ), 'the winner\'s file is untouched' );
	}

	public function test_a_record_whose_sync_is_refused_and_whose_unlink_is_refused_is_voided_never_restorable(): void {
		// Codex #94 round-7 P2: the sync-refusal exit removed the record with
		// an unchecked unlink; when that unlink is refused too, a fully
		// restorable `existed: false` record with the expected hash survived.
		// A later creator landing the same bytes at the target would then lose
		// its file to a restore of this orphan. Every post-record exit now voids.
		$file  = WP_CONTENT_DIR . '/unsynced.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function sync_create_record( $meta_path ) {
				$GLOBALS['_wp_delete_file_fail'] = $meta_path; // and the record's unlink is refused
				return false; // the kernel refuses the sync
			}
		};

		$res = $snaps->create_file( $file, "same\n" );
		unset( $GLOBALS['_wp_delete_file_fail'] );

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'sync', $res['error'] );
		$this->assertArrayNotHasKey( 'stale_record', $res, 'the record was voided, not left restorable' );
		$this->assertFileDoesNotExist( $file, 'nothing created' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
		$recs = $snaps->list_snapshots();
		$this->assertCount( 1, $recs );
		$this->assertTrue( $recs[0]['voided'] );
		$this->assertArrayNotHasKey( 'expected_sha256', $recs[0] );

		file_put_contents( $file, "same\n" ); // another creator lands the same bytes later
		$restore = $snaps->restore( $recs[0]['id'] );
		$this->assertFalse( $restore['success'] );
		$this->assertSame( "same\n", file_get_contents( $file ), 'the later creator\'s file is untouched' );
	}

	public function test_a_changed_file_relinked_but_whose_claim_cannot_be_removed_is_reported(): void {
		// Codex #94 round-7 P2: the file was put back at its path, but the
		// claim name (a second hard link to the same inode) could not be
		// removed. `.aura-restore-*` is never swept, so the answer must name
		// it, as the matching-hash branch already does — otherwise each such
		// restore leaves one more unreported directory entry.
		$file  = WP_CONTENT_DIR . '/relinked.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function after_claim( $claim, $target ) {
				$GLOBALS['_wp_delete_file_fail'] = $claim;
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );

		$restore = $snaps->restore( $rec['id'] );
		unset( $GLOBALS['_wp_delete_file_fail'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertSame( "edited\n", file_get_contents( $file ), 'put back at its path' );
		$this->assertArrayHasKey( 'moved_aside', $restore, 'the leftover claim is named' );
		$this->assertMatchesRegularExpression( '/\/\.aura-restore-[0-9a-f]{16}$/', $restore['moved_aside'] );
		$this->assertFileExists( $restore['moved_aside'] );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ), 'the same inode under its claim name' );
	}

	public function test_redact_strips_every_local_path_and_the_api_listing_uses_it(): void {
		// Codex #94 round-5 P3: GET /aura/v2/snapshots returned list_snapshots()
		// verbatim, staged included.
		$snaps = new Aura_Worker_Snapshots();
		$snaps->create_file( WP_CONTENT_DIR . '/r.php', "x\n" );
		$stored = $snaps->list_snapshots()[0];
		$this->assertArrayHasKey( 'staged', $stored, 'the persisted record keeps it for the sweep' );

		$public = Aura_Worker_Snapshots::redact( $stored );

		$this->assertArrayNotHasKey( 'staged', $public );
		$this->assertArrayNotHasKey( 'meta_path', $public );
		$this->assertArrayNotHasKey( 'payload_path', $public );
		$this->assertSame( $stored['id'], $public['id'] );
		$this->assertStringContainsString( "array_map( array( 'Aura_Worker_Snapshots', 'redact' ), \$snapshots->list_snapshots() )", file_get_contents( SA_PLUGIN_DIR . '/includes/class-aura-worker-api.php' ), 'the REST listing passes every record through redact()' );
	}

	public function test_target_appearing_between_stage_and_publish_is_exists_with_nothing_left_behind(): void {
		$file  = WP_CONTENT_DIR . '/race.php';
		$snaps = new class( $file ) extends Aura_Worker_Snapshots {
			private $race;
			public function __construct( $race ) { parent::__construct(); $this->race = $race; }
			protected function publish( $tmp, $path ) {
				file_put_contents( $this->race, "theirs\n" ); // the race: someone lands the target first
				return parent::publish( $tmp, $path );
			}
		};

		$res = $snaps->create_file( $file, "mine\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'exists', $res['error'] );
		$this->assertSame( "theirs\n", file_get_contents( $file ), 'link() never clobbers' );
		$this->assertSame( array(), $snaps->list_snapshots(), 'the record is deleted when publish is refused' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
	}

	public function test_record_persist_failure_leaves_no_staged_file_and_no_target(): void {
		$file  = WP_CONTENT_DIR . '/np.php';
		$snaps = new Aura_Worker_Snapshots();
		// Make the snapshot directory unwritable AFTER construction, so
		// persist() fails while staging (beside the target) still works.
		chmod( WP_CONTENT_DIR . '/aura-backups/snapshots', 0555 );
		if ( is_writable( WP_CONTENT_DIR . '/aura-backups/snapshots' ) ) {
			chmod( WP_CONTENT_DIR . '/aura-backups/snapshots', 0755 );
			$this->markTestSkipped( 'running as a user that ignores directory modes (root)' );
		}
		try {
			$res = $snaps->create_file( $file, "x\n" );
		} finally {
			chmod( WP_CONTENT_DIR . '/aura-backups/snapshots', 0755 );
		}

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'persist', $res['error'] );
		$this->assertFileDoesNotExist( $file );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
	}

	public function test_a_record_that_does_not_read_back_complete_is_never_published(): void {
		// Codex round-1 P1: persist() used to accept any file_put_contents()
		// result but literal false, so a short .json write (disk full during
		// the metadata write) "succeeded" and the target was published with
		// no usable rollback point. create_file() re-reads the record before
		// publish; a record that does not decode to the same facts refuses.
		$file  = WP_CONTENT_DIR . '/unverified.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function persist_create_record( array $meta ) {
				$record = parent::persist_create_record( $meta );
				if ( false !== $record ) {
					// Model the short write persist() did not see: truncate the .json it wrote.
					file_put_contents( $record['meta_path'], substr( (string) file_get_contents( $record['meta_path'] ), 0, 10 ) );
				}
				return $record;
			}
		};

		$res = $snaps->create_file( $file, "x\n" );

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'read back', $res['error'] );
		$this->assertFileDoesNotExist( $file, 'no target without a verified record' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
		$this->assertSame( array(), $snaps->list_snapshots(), 'the truncated record is removed' );
	}

	public function test_short_write_while_staging_leaves_no_staged_file_no_record_no_target(): void {
		$file  = WP_CONTENT_DIR . '/short.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content ) {
				// Model a full disk: the exclusive create succeeded, the bytes did not land.
				$tmp = $dir . '/.aura-create-shortwrite';
				file_put_contents( $tmp, substr( $content, 0, 1 ) );
				return $this->discard_short_stage( $tmp );
			}
		};

		$res = $snaps->create_file( $file, "complete content\n" );

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'Short write', $res['error'] );
		$this->assertFileDoesNotExist( $file );
		$this->assertSame( array(), $snaps->list_snapshots() );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
	}

	public function test_a_staged_file_whose_mode_cannot_be_set_is_never_published(): void {
		// Codex #94 round-1 P1: a mode that could not be set is a refusal, never
		// a publish with whatever fopen() and the umask left behind.
		$file  = WP_CONTENT_DIR . '/nochmod.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function secure_stage( $tmp ) {
				return false;
			}
		};

		$res = $snaps->create_file( $file, "x\n" );

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'permissions', $res['error'] );
		$this->assertFileDoesNotExist( $file, 'nothing at the target' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ), 'the stage is discarded' );
		$this->assertSame( array(), $snaps->list_snapshots(), 'no record for a create that did not happen' );
	}

	public function test_link_refused_is_unsupported_filesystem_with_nothing_at_the_target(): void {
		$file  = WP_CONTENT_DIR . '/nolink.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function publish( $tmp, $path ) {
				return 'unsupported_filesystem';
			}
		};

		$res = $snaps->create_file( $file, "x\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'unsupported_filesystem', $res['error'] );
		$this->assertFileDoesNotExist( $file );
		$this->assertSame( array(), $snaps->list_snapshots() );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
	}

	public function test_staged_name_is_never_a_php_name_and_lives_beside_the_target(): void {
		$file  = WP_CONTENT_DIR . '/sub/agent.php';
		mkdir( WP_CONTENT_DIR . '/sub' );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $seen = '';
			protected function publish( $tmp, $path ) {
				$this->seen = $tmp;
				return parent::publish( $tmp, $path );
			}
		};

		$snaps->create_file( $file, "x\n" );

		$this->assertSame( WP_CONTENT_DIR . '/sub', dirname( $snaps->seen ), 'same directory, so link() is on one filesystem' );
		$this->assertMatchesRegularExpression( '/^\.aura-create-[0-9a-f]{16}$/', basename( $snaps->seen ), 'an opaque name: no part of the target in it' );
		$this->assertStringNotContainsString( '.php', basename( $snaps->seen ), 'no .php ANYWHERE in the name — Apache AddHandler multi-extension semantics would execute .x.php.y (Codex round-1)' );
	}

	public function test_a_stray_staged_file_older_than_an_hour_is_swept_by_the_next_create_in_that_directory(): void {
		$snaps = new Aura_Worker_Snapshots();
		$stray = WP_CONTENT_DIR . '/.aura-create-deadbeefdeadbeef';
		file_put_contents( $stray, 'partial' );
		touch( $stray, time() - 2 * HOUR_IN_SECONDS );
		$fresh = WP_CONTENT_DIR . '/.aura-create-cafecafecafecafe';
		file_put_contents( $fresh, 'partial' );

		$snaps->create_file( WP_CONTENT_DIR . '/other.php', "x\n" );

		$this->assertFileDoesNotExist( $stray, 'older than an hour: pruned' );
		$this->assertFileExists( $fresh, 'a young staged file may belong to a create in flight' );
	}

	public function test_prune_older_than_removes_a_stale_staged_file_named_by_a_record_and_keeps_the_record(): void {
		// Crash after the record and before publish: record + staged file,
		// target absent. The sweep removes the staged bytes; the record stays
		// (restore on it is "already gone" → success).
		$file  = WP_CONTENT_DIR . '/crash.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function publish( $tmp, $path ) {
				throw new RuntimeException( 'simulated crash after record' );
			}
		};
		try {
			$snaps->create_file( $file, "x\n" );
		} catch ( RuntimeException $e ) {
			// expected
		}
		$recs = $snaps->list_snapshots();
		$this->assertCount( 1, $recs );
		$staged = $recs[0]['staged'];
		$this->assertFileExists( $staged );
		touch( $staged, time() - 2 * HOUR_IN_SECONDS );

		$pruned = $snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );

		$this->assertSame( 0, $pruned, 'file records are never pruned' );
		$this->assertFileDoesNotExist( $staged );
		$this->assertCount( 1, $snaps->list_snapshots() );
		$this->assertTrue( $snaps->restore( $recs[0]['id'] )['success'] );
	}

	public function test_on_a_network_the_staged_sweep_touches_only_this_blogs_records(): void {
		// Codex #91 round-4 P2: every blog shares the snapshot directory. A
		// prune on blog 2 must not delete a staged file recorded by blog 1.
		$file  = WP_CONTENT_DIR . '/crash.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function publish( $tmp, $path ) {
				throw new RuntimeException( 'simulated crash after record' );
			}
		};
		$GLOBALS['_is_multisite']     = true;
		$GLOBALS['_current_blog_id']  = 1;
		try {
			$snaps->create_file( $file, "x\n" );
		} catch ( RuntimeException $e ) {
			// expected
		}
		$staged = $snaps->list_snapshots()[0]['staged'];
		touch( $staged, time() - 2 * HOUR_IN_SECONDS );

		$GLOBALS['_current_blog_id'] = 2;
		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileExists( $staged, 'blog 2 must not sweep blog 1\'s staged bytes' );

		$GLOBALS['_current_blog_id'] = 1;
		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileDoesNotExist( $staged, 'the owning blog sweeps it' );
	}

	public function test_snapshot_get_never_returns_the_staged_path(): void {
		require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-snapshot-get.php';
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->create_file( WP_CONTENT_DIR . '/g.php', "x\n" )['snapshot'];

		$out = ( new Aura_Tool_Snapshot_Get() )->execute( array( 'id' => $rec['id'] ) );

		$this->assertTrue( $out['found'] );
		$this->assertArrayNotHasKey( 'staged', $out['record'] );
		$this->assertArrayNotHasKey( 'payload_path', $out['record'] );
		$this->assertNull( $out['payload'] );
		$this->assertFalse( $out['record']['existed'] );
	}

	public function test_a_delete_that_fails_after_the_claim_says_where_the_bytes_are(): void {
		// The claim succeeded, so the target is gone from its path; the unlink
		// of the claimed file then failed. The answer must name the claim, as
		// the file_changed_since branch does — otherwise the agent's bytes sit
		// somewhere nothing ever tells the operator about.
		$file  = WP_CONTENT_DIR . '/undeletable.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function after_claim( $claim, $target ) {
				$GLOBALS['_wp_delete_file_fail'] = $claim; // model a directory this process may write but not unlink from
			}
		};
		$rec = $snaps->create_file( $file, "<?php // agent\n" )['snapshot'];

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertStringContainsString( 'Failed to delete file', $restore['error'] );
		$this->assertArrayHasKey( 'moved_aside', $restore, 'the caller learns where the bytes are' );
		$this->assertMatchesRegularExpression( '/\/\.aura-restore-[0-9a-f]{16}$/', $restore['moved_aside'] );
		$this->assertFileExists( $restore['moved_aside'] );
		$this->assertSame( "<?php // agent\n", file_get_contents( $restore['moved_aside'] ), "the agent's bytes, still on disk" );
		$this->assertFileDoesNotExist( $file, 'the claim moved it off the path' );
	}

	public function test_a_host_without_link_publishes_by_exclusive_create_and_write_and_the_record_restores(): void {
		// SiteAgent#96: Cloudways puts link() in disable_functions for web PHP,
		// so the create path was dead on most of the fleet. Without link() the
		// target is claimed with fopen('x') — no clobber, an inode this call
		// owns — and the bytes are written into that handle (Codex #97 round-1
		// P1: rename() over a placeholder is not no-clobber; this is).
		$file  = WP_CONTENT_DIR . '/nolinkfn.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
		};

		$res = $snaps->create_file( $file, "<?php // by write\n" );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'write', $res['published'] );
		$this->assertSame( "<?php // by write\n", file_get_contents( $file ) );
		$this->assertSame( defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644, fileperms( $file ) & 0777, 'the mode is set at creation (umask), never by a pathname chmod' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ), 'the stage is discarded after the publish' );
		$this->assertArrayNotHasKey( 'staged', $res['snapshot'] );
		$restore = $snaps->restore( $res['snapshot']['id'] );
		$this->assertTrue( $restore['success'] );
		$this->assertFileDoesNotExist( $file, 'the record of a written create restores by unlinking, like a linked one' );
	}

	public function test_link_publishes_report_link(): void {
		$file = WP_CONTENT_DIR . '/bylink.php';
		$res  = ( new Aura_Worker_Snapshots() )->create_file( $file, "x\n" );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 'link', $res['published'] );
	}

	public function test_without_link_a_target_that_appears_before_the_claim_is_exists_with_nothing_left_behind(): void {
		$file  = WP_CONTENT_DIR . '/nolink-race.php';
		$snaps = new class( $file ) extends Aura_Worker_Snapshots {
			private $race;
			public function __construct( $race ) { parent::__construct(); $this->race = $race; }
			protected function link_available() {
				return false;
			}
			protected function persist_create_record( array $meta ) {
				file_put_contents( $this->race, "theirs\n" ); // lands between the early check and the claim
				return parent::persist_create_record( $meta );
			}
		};

		$res = $snaps->create_file( $file, "mine\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'exists', $res['error'] );
		$this->assertSame( "theirs\n", file_get_contents( $file ), 'fopen(x) refused: the newcomer is untouched' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
		$this->assertSame( array(), $snaps->list_snapshots(), 'the record of a create that did not happen is gone' );
	}

	public function test_without_link_a_racer_that_unlinks_our_entry_and_takes_the_path_during_the_write_is_never_overwritten(): void {
		// Ownership is by inode: the racer's file holds the path, our bytes went
		// to an entry that no longer exists, and this call reports exists.
		$file  = WP_CONTENT_DIR . '/nolink-during.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function during_write( $path ) {
				unlink( $path );
				file_put_contents( $path, "theirs\n" );
			}
		};

		$res = $snaps->create_file( $file, "mine\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'exists', $res['error'] );
		$this->assertSame( "theirs\n", file_get_contents( $file ), 'the racer\'s file is intact' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
		$this->assertSame( array(), $snaps->list_snapshots() );
	}

	public function test_without_link_a_short_write_fails_closed_and_names_the_empty_entry_it_left(): void {
		$file  = WP_CONTENT_DIR . '/nolink-short.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				fwrite( $fh, fread( $src, 3 ) ); // a partial write, then the disk says no
				return false;
			}
		};

		$res = $snaps->create_file( $file, "<?php echo 'never';\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'unsupported_filesystem', $res['error'] );
		$this->assertStringContainsString( 'short', $res['detail'] );
		$this->assertStringContainsString( $file, $res['detail'], 'the empty entry is named' );
		$this->assertFileExists( $file );
		$this->assertSame( '', file_get_contents( $file ), 'never a truncated file that reads as content' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
		$this->assertSame( array(), $snaps->list_snapshots() );
	}

	public function test_publish_mode_names_link_or_write_and_null_when_the_create_mode_has_execute_bits(): void {
		$this->assertSame( function_exists( 'link' ) ? 'link' : 'write', Aura_Worker_Snapshots::publish_mode() );
		$this->assertSame( function_exists( 'link' ) ? 'link' : 'write', Aura_Worker_Snapshots::publish_mode( 0644 ) );
		$this->assertSame( function_exists( 'link' ) ? 'link' : null, Aura_Worker_Snapshots::publish_mode( 0755 ), 'without link() a create mode with execute bits cannot land at all' );
	}

	public function test_an_interrupted_link_less_write_is_reconciled_by_the_sweep_the_record_voided_and_the_file_never_deleted(): void {
		// Codex #97 round-1 P1: a process killed after the exclusive create and
		// before the write completes leaves the target empty or partial with a
		// restorable record beside it. The over-age stage is the signal; the
		// sweep voids the record (restore refuses) and marks it interrupted.
		$file  = WP_CONTENT_DIR . '/interrupted.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				fwrite( $fh, fread( $src, 5 ) );
				throw new RuntimeException( 'simulated kill mid-write' );
			}
		};
		try {
			$snaps->create_file( $file, "<?php // whole file\n" );
		} catch ( RuntimeException $e ) {
			// expected
		}
		$recs = $snaps->list_snapshots();
		$this->assertCount( 1, $recs );
		$this->assertFileExists( $recs[0]['staged'] );
		$this->assertSame( '<?php', file_get_contents( $file ), 'the partial file the kill left' );
		touch( $recs[0]['staged'], time() - 2 * HOUR_IN_SECONDS );

		( new Aura_Worker_Snapshots() )->create_file( WP_CONTENT_DIR . '/another.txt', "y\n" ); // the next create in that directory sweeps

		$this->assertFileDoesNotExist( $recs[0]['staged'] );
		$recs = $snaps->list_snapshots();
		$this->assertCount( 2, $recs );
		$rec = $recs[0]['id'] === $recs[1]['id'] ? null : ( 'interrupted.php' === basename( $recs[0]['target'] ) ? $recs[0] : $recs[1] );
		$this->assertTrue( $rec['voided'] );
		$this->assertTrue( $rec['interrupted'] );
		$this->assertArrayNotHasKey( 'expected_sha256', $rec );
		$this->assertFalse( $snaps->restore( $rec['id'] )['success'] );
		$this->assertSame( '<?php', file_get_contents( $file ), 'never deleted by a restore' );
	}

	public function test_a_stage_is_kept_when_the_interrupted_record_cannot_be_voided_so_the_next_sweep_retries(): void {
		// Codex #97 round-2 P2: the stage is the signal that reconciliation is
		// owed. If the record cannot be rewritten (snapshot dir full), deleting
		// the stage would leave the record restorable for good.
		$file  = WP_CONTENT_DIR . '/interrupted-novoid.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $void_ok = true;
			protected function link_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				fwrite( $fh, fread( $src, 5 ) );
				throw new RuntimeException( 'simulated kill mid-write' );
			}
			protected function void_record_in_place( $id, array $extra = array() ) {
				return $this->void_ok ? parent::void_record_in_place( $id, $extra ) : false;
			}
		};
		try {
			$snaps->create_file( $file, "<?php // whole file\n" );
		} catch ( RuntimeException $e ) {
			// expected
		}
		$recs   = $snaps->list_snapshots();
		$staged = $recs[0]['staged'];
		touch( $staged, time() - 2 * HOUR_IN_SECONDS );
		$snaps->void_ok = false;

		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );

		$this->assertFileExists( $staged, 'kept: the record is still restorable, the sweep must try again' );
		$snaps->void_ok = true;
		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileDoesNotExist( $staged );
		$this->assertTrue( $snaps->list_snapshots()[0]['voided'] );
	}

	public function test_a_record_voided_by_a_concurrent_sweep_during_a_long_write_is_reinstated_when_the_publish_lands(): void {
		// Codex #97 round-5 P2: a publish past STAGE_MAX_AGE looks interrupted to
		// a sweep in another request, which voids the record mid-write. The
		// publisher, whose bytes landed and whose inode verified, repairs it.
		$file  = WP_CONTENT_DIR . '/long-write.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function during_write( $path ) {
				foreach ( $this->list_snapshots() as $rec ) {
					if ( ( $rec['target'] ?? '' ) === $path ) {
						$this->void_record_in_place( $rec['id'], array( 'interrupted' => true ) ); // what the sweep did
					}
				}
			}
		};

		$res = $snaps->create_file( $file, "<?php // slow\n" );

		$this->assertTrue( $res['success'] );
		$this->assertArrayNotHasKey( 'warning', $res );
		$rec = $snaps->get( $res['snapshot']['id'] );
		$this->assertArrayNotHasKey( 'voided', $rec );
		$this->assertArrayNotHasKey( 'interrupted', $rec );
		$this->assertSame( hash( 'sha256', "<?php // slow\n" ), $rec['expected_sha256'] );
		$this->assertTrue( $snaps->restore( $rec['id'] )['success'], 'restorable again' );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_without_link_a_create_mode_with_execute_bits_is_refused_not_stripped(): void {
		// Codex #97 round-5 P2: FS_CHMOD_FILE 0755 exists on some hosts; fopen()
		// cannot recreate the execute bits, so the create refuses like the
		// put-back does instead of landing a lesser file.
		$file  = WP_CONTENT_DIR . '/exec-mode.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function create_mode() {
				return 0755;
			}
		};

		$res = $snaps->create_file( $file, "x\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'unsupported_filesystem', $res['error'] );
		$this->assertStringContainsString( 'execute bits', $res['detail'] );
		$this->assertFileDoesNotExist( $file );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );
		$this->assertSame( array(), $snaps->list_snapshots() );
	}

	public function test_with_link_a_create_mode_with_execute_bits_is_honoured(): void {
		$file  = WP_CONTENT_DIR . '/exec-mode-link.sh';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function create_mode() {
				return 0755;
			}
			protected function secure_stage( $tmp ) {
				return (bool) @chmod( $tmp, $this->create_mode() );
			}
		};
		if ( ! function_exists( 'link' ) ) {
			$this->markTestSkipped( 'link() unavailable.' );
		}
		$res = $snaps->create_file( $file, "#!/bin/sh\n" );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 'link', $res['published'] );
		$this->assertSame( 0755, fileperms( $file ) & 0777 );
	}

	public function test_the_sweeper_leaves_a_stage_alone_while_the_record_is_locked_and_the_publisher_warns_when_it_cannot_lock(): void {
		// Codex #97 round-6 P2: the sweeper's hash-then-void and the
		// publisher's check-then-reinstate run under one per-record lock.
		// Modelled by holding that lock from the outside: neither side hangs;
		// the sweeper keeps the stage for the next pass, the publisher lands
		// and says the record could not be verified.
		$file  = WP_CONTENT_DIR . '/locked.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				fwrite( $fh, fread( $src, 5 ) );
				throw new RuntimeException( 'simulated kill mid-write' );
			}
		};
		try {
			$snaps->create_file( $file, "<?php // whole file\n" );
		} catch ( RuntimeException $e ) {
			// expected
		}
		$recs   = $snaps->list_snapshots();
		$staged = $recs[0]['staged'];
		touch( $staged, time() - 2 * HOUR_IN_SECONDS );
		$lock = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $recs[0]['id'] . '.lock';
		$held = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $held, LOCK_EX | LOCK_NB ) );

		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );

		$this->assertFileExists( $staged, 'contended: the stage stays for the next pass' );
		$this->assertArrayNotHasKey( 'voided', $snaps->get( $recs[0]['id'] ) );
		flock( $held, LOCK_UN );
		fclose( $held );

		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileDoesNotExist( $staged );
		$this->assertTrue( $snaps->get( $recs[0]['id'] )['voided'] );

		// The publisher side: land a create while its record's lock is held.
		$file2  = WP_CONTENT_DIR . '/locked2.php';
		$plain  = new class extends Aura_Worker_Snapshots {
			public $lock_holder = null;
			protected function link_available() {
				return false;
			}
			protected function during_write( $path ) {
				foreach ( $this->list_snapshots() as $rec ) {
					if ( ( $rec['target'] ?? '' ) === $path ) {
						$this->lock_holder = fopen( WP_CONTENT_DIR . '/aura-backups/snapshots/' . $rec['id'] . '.lock', 'cb' );
						flock( $this->lock_holder, LOCK_EX | LOCK_NB );
					}
				}
			}
		};
		$res = $plain->create_file( $file2, "x\n" );
		flock( $plain->lock_holder, LOCK_UN );
		fclose( $plain->lock_holder );
		$this->assertTrue( $res['success'] );
		$this->assertStringContainsString( 'could not be locked', $res['warning'] );
		$this->assertSame( "x\n", file_get_contents( $file2 ) );
	}

	public function test_a_completed_publish_whose_stage_cleanup_was_lost_keeps_its_record(): void {
		$file  = WP_CONTENT_DIR . '/lost-cleanup.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function discard_stage( $tmp ) {
				// the process died right after the publish: the stage stays
			}
		};
		$rec = $snaps->create_file( $file, "x\n" )['snapshot'];
		$recs = $snaps->list_snapshots();
		$this->assertFileExists( $recs[0]['staged'] );
		touch( $recs[0]['staged'], time() - 2 * HOUR_IN_SECONDS );

		( new Aura_Worker_Snapshots() )->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );

		$this->assertFileDoesNotExist( $recs[0]['staged'] );
		$again = ( new Aura_Worker_Snapshots() )->get( $rec['id'] );
		$this->assertArrayNotHasKey( 'voided', $again, 'the target holds the expected bytes: published, restorable' );
		$this->assertTrue( ( new Aura_Worker_Snapshots() )->restore( $rec['id'] )['success'] );
	}

	public function test_without_link_a_changed_file_is_put_back_by_exclusive_create_and_write(): void {
		// SiteAgent#96: a host without link() used to strand every edited file
		// under its .aura-restore-* name. The put-back now claims the path with
		// fopen('x') and writes the claimed bytes into the handle it owns; the
		// claim copy is then removed.
		$file  = WP_CONTENT_DIR . '/nolinkback.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
		};
		$rec = $snaps->create_file( $file, "a\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayNotHasKey( 'moved_aside', $restore );
		$this->assertSame( "edited\n", file_get_contents( $file ), 'put back at its path, same bytes' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'the claim copy is gone' );
	}

	public function test_without_link_a_changed_file_whose_path_was_retaken_is_kept_aside_and_named(): void {
		$file  = WP_CONTENT_DIR . '/nolinkback-taken.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
			protected function after_claim( $claim, $target ) {
				file_put_contents( $target, "newcomer\n" ); // the path is retaken in the window
			}
		};
		$rec = $snaps->create_file( $file, "a\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertSame( "newcomer\n", file_get_contents( $file ), 'fopen(x) refused: the newcomer is untouched' );
		$this->assertMatchesRegularExpression( '/\/\.aura-restore-[0-9a-f]{16}$/', $restore['moved_aside'] );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ), 'the changed bytes are kept, not deleted' );
	}

	public function test_without_link_a_changed_file_comes_back_with_the_mode_it_had(): void {
		// Codex #97 round-3 P2: a 0600 file must not come back 0644.
		$file  = WP_CONTENT_DIR . '/nolinkback-mode.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
		};
		$rec = $snaps->create_file( $file, "a\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );
		chmod( $file, 0600 );
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayNotHasKey( 'moved_aside', $restore );
		$this->assertSame( "edited\n", file_get_contents( $file ) );
		$this->assertSame( 0600, fileperms( $file ) & 0777, 'the claim\'s mode, not FS_CHMOD_FILE' );
	}

	public function test_without_link_a_short_write_whose_partial_bytes_cannot_be_emptied_keeps_the_record_voided_and_the_stage(): void {
		// Codex #97 round-3 P2: ftruncate() refused too — partial bytes stay at
		// the path. abandon_create() must NOT throw the recovery state away:
		// the record is voided in place (restore can never delete the file),
		// marked interrupted, and the staged bytes are kept.
		$file  = WP_CONTENT_DIR . '/nolink-partial.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				fwrite( $fh, fread( $src, 5 ) );
				return false;
			}
			protected function truncate_to_empty( $fh ) {
				return false;
			}
		};

		$res = $snaps->create_file( $file, "<?php echo 'never';\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'unsupported_filesystem', $res['error'] );
		$this->assertStringContainsString( 'partial bytes remain', $res['detail'] );
		$this->assertSame( '<?php', file_get_contents( $file ), 'the partial bytes, untouched by name' );
		$recs = $snaps->list_snapshots();
		$this->assertCount( 1, $recs );
		$this->assertSame( $recs[0]['id'], $res['stale_record'] );
		$this->assertTrue( $recs[0]['voided'] );
		$this->assertTrue( $recs[0]['interrupted'] );
		$this->assertArrayNotHasKey( 'expected_sha256', $recs[0] );
		$this->assertCount( 1, glob( WP_CONTENT_DIR . '/.aura-create-*' ), 'the staged bytes are kept for the repair' );
		$this->assertFalse( $snaps->restore( $recs[0]['id'] )['success'] );
		$this->assertSame( '<?php', file_get_contents( $file ), 'never deleted by a restore' );
	}

	public function test_without_link_an_executable_changed_file_is_kept_aside_because_its_mode_cannot_be_recreated(): void {
		// Codex #97 round-4 P2: fopen() creates from 0666 and a umask only
		// removes bits — a 0755 file would come back 0644. Refuse the put-back
		// instead: the file stays aside under its claim name, nothing lands at
		// the path.
		$file  = WP_CONTENT_DIR . '/nolinkback-exec.sh';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
		};
		$rec = $snaps->create_file( $file, "#!/bin/sh\n" )['snapshot'];
		file_put_contents( $file, "#!/bin/sh\necho edited\n" );
		chmod( $file, 0755 );
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayHasKey( 'moved_aside', $restore );
		$this->assertFileDoesNotExist( $file, 'nothing lands at the path with a lesser mode' );
		$this->assertSame( 0755, fileperms( $restore['moved_aside'] ) & 0777, 'the claim keeps the executable mode' );
		$this->assertSame( "#!/bin/sh\necho edited\n", file_get_contents( $restore['moved_aside'] ) );
	}

	public function test_without_link_a_short_put_back_write_keeps_the_file_aside_and_leaves_our_empty_entry(): void {
		$file  = WP_CONTENT_DIR . '/nolinkback-short.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
			protected function write_all( $fh, $src ) {
				return $this->allow_link ? parent::write_all( $fh, $src ) : false;
			}
		};
		$rec = $snaps->create_file( $file, "a\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayHasKey( 'moved_aside', $restore );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ), 'the changed bytes are kept' );
		$this->assertSame( '', file_get_contents( $file ), 'our empty entry, never a truncated one' );
	}
}

/**
 * A stand-in object-injection gadget: if a plain unserialize() ever rebuilt it,
 * __wakeup() would flip the static flag. allowed_classes => false must keep that
 * flag false. Defined at file scope so serialize()/unserialize() can name it.
 */
final class Aura_Snapshot_Gadget {
	public static bool $woke = false;

	public function __wakeup(): void {
		self::$woke = true;
	}
}
