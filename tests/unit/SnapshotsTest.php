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

	public function test_a_bare_file_snapshot_captures_but_no_longer_restores(): void {
		// 2.17.3: a record that does not say what replaced the file cannot
		// prove the file is unchanged, so its restore is refused (Aura#520
		// §2 Q3, fail closed). overwrite_file() is the fenced path.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/target.php';
		file_put_contents( $file, "<?php // original\n" );

		$snap = $snaps->snapshot_file( $file );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'file', $snap['snapshot']['kind'] );

		file_put_contents( $file, "<?php // clobbered\n" );
		$restore = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'aura_snapshot_unfenced', $restore['code'] );
		$this->assertStringContainsString( 'clobbered', file_get_contents( $file ) );
	}

	public function test_file_snapshot_of_missing_file_fails(): void {
		$snaps  = new Aura_Worker_Snapshots();
		$result = $snaps->snapshot_file( WP_CONTENT_DIR . '/nope.php' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_snapshot_file_extra_cannot_override_the_records_identity(): void {
		// Item 3 (final review) and Codex #102 round-23 P2: $extra reaches a
		// PUBLIC method and is merged into the persisted record, so a caller
		// could otherwise overwrite 'kind', 'target', 'bytes' or 'existed' —
		// an 'existed' => false would make a future restore() dispatch to
		// restore_created_file(), which DELETES the file — or hand a direct
		// snapshot a 'replaced_with_sha256' the engine never established,
		// turning a fail-closed record into a restorable one.
		//
		// The guard is an ALLOWLIST for that reason: the blocklist it replaced
		// had already missed the fence. Only CALLER_META_KEYS lands.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/identity.php';
		file_put_contents( $file, "<?php // original\n" );

		$snap = $snaps->snapshot_file(
			$file,
			array(
				'existed'   => false,
				'kind'      => 'page',
				'target'    => '/not/the/real/path.php',
				'bytes'     => 999999,
				'replaced_with_sha256' => hash( 'sha256', "<?php // original\n" ),
				'write_seq' => 7,
			)
		);

		$this->assertTrue( $snap['success'] );
		$rec = $snaps->get( $snap['snapshot']['id'] );
		$this->assertSame( 'file', $rec['kind'], 'kind cannot be overridden' );
		$this->assertSame( $file, $rec['target'], 'target cannot be overridden' );
		$this->assertSame( strlen( "<?php // original\n" ), $rec['bytes'], 'bytes cannot be overridden' );
		$this->assertArrayNotHasKey( 'existed', $rec, 'existed is dropped, never set to the caller\'s value' );
		$this->assertArrayNotHasKey( 'replaced_with_sha256', $rec, 'only the engine\'s own post-write stamp may fence a record' );
		$this->assertSame( 7, $rec['write_seq'], 'an allowlisted key in $extra still lands' );

		// And the record really is fail-closed: unfenced, so a restore refuses.
		$restore = $snaps->restore( $snap['snapshot']['id'] );
		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'aura_snapshot_unfenced', $restore['code'] );
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

	public function test_a_created_file_already_gone_answers_already(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/created.php';
		$rec   = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		unlink( $file );

		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertTrue( $out['already'] );
	}

	public function test_a_directory_at_the_created_path_answers_the_changed_code(): void {
		// Codex #101 round-1 P1: this refusal had no code, so the REST layer
		// answered 500 for a designated changed-since refusal.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/created-dir.php';
		$rec   = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		unlink( $file );
		mkdir( $file, 0755 );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertStringContainsString( 'not a regular file', $out['error'] );
		$this->assertDirectoryExists( $file, 'the directory is untouched' );
	}

	public function test_a_created_file_edited_since_answers_the_changed_code(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/created-edited.php';
		$rec   = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		file_put_contents( $file, "<?php // edited\n" );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertSame( 'file_changed_since', $out['error'] );
	}

	public function test_a_voided_record_answers_the_voided_code(): void {
		$snaps = new class extends Aura_Worker_Snapshots {
			public function void( $id ) {
				return $this->void_record_in_place( $id, array( 'interrupted' => true ) );
			}
		};
		$file = WP_CONTENT_DIR . '/voided.php';
		$rec  = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		$this->assertTrue( $snaps->void( $rec['id'] ) );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_snapshot_voided', $out['code'] );
		$this->assertFileExists( $file, 'a voided record never deletes the file' );
	}

	public function test_a_held_path_answers_the_locked_code(): void {
		// target_lock_tries() is the seam: one 20 ms attempt, and the lock is
		// already held by a handle this test keeps open.
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function target_lock_tries() {
				return 1;
			}
		};
		$file = WP_CONTENT_DIR . '/held.php';
		file_put_contents( $file, "<?php // v0\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // v1\n" )['snapshot'];

		$lock = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock';
		$fh   = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $fh, LOCK_EX | LOCK_NB ) );

		$out = $snaps->restore( $rec['id'] );

		flock( $fh, LOCK_UN );
		fclose( $fh );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_path_locked', $out['code'] );
		$this->assertSame( 'locked', $out['error'] );
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
			protected function stage( $dir, $name, $content, $mode = null ) {
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
			protected function secure_stage( $tmp, $mode = null ) {
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

	public function test_prune_older_than_removes_a_stale_staged_file_and_retires_the_record_of_a_create_that_never_claimed_its_target(): void {
		// Crash after the record and before publish: record + staged file,
		// target absent. The sweep removes the staged bytes AND retires the record.
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

		$this->assertSame( 0, $pruned, 'file records are never pruned by age' );
		$this->assertFileDoesNotExist( $staged );
		// Codex #97 round-7 P2: the record of a create that never claimed its
		// target is retired with the stage — a live hash would match an
		// unrelated file created at that path later and let a restore delete it.
		$this->assertSame( array(), $snaps->list_snapshots(), 'the record of a create that never happened is gone' );
		file_put_contents( $file, "x\n" ); // someone else creates the same bytes there later
		$this->assertFalse( $snaps->restore( $recs[0]['id'] )['success'] );
		$this->assertFileExists( $file, 'never deleted' );
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
			protected function secure_stage( $tmp, $mode = null ) {
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

	public function test_without_flock_the_sweep_still_reconciles_and_the_publisher_does_not_warn(): void {
		// Codex #97 round-7 P2: flock() in disable_functions must not fatal after
		// a publish nor stall reconciliation forever — the sections run unlocked.
		$file  = WP_CONTENT_DIR . '/noflock.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $kill = true;
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				if ( $this->kill ) {
					fwrite( $fh, fread( $src, 5 ) );
					throw new RuntimeException( 'simulated kill mid-write' );
				}
				return parent::write_all( $fh, $src );
			}
		};
		try {
			$snaps->create_file( $file, "<?php // whole file\n" );
		} catch ( RuntimeException $e ) {
			// expected
		}
		$recs = $snaps->list_snapshots();
		touch( $recs[0]['staged'], time() - 2 * HOUR_IN_SECONDS );
		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileDoesNotExist( $recs[0]['staged'] );
		$this->assertTrue( $snaps->get( $recs[0]['id'] )['voided'] );

		$snaps->kill = false;
		$res = $snaps->create_file( WP_CONTENT_DIR . '/noflock2.php', "x\n" );
		$this->assertTrue( $res['success'] );
		$this->assertArrayNotHasKey( 'warning', $res );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/aura-backups/snapshots/*.lock' ), 'no lock files without flock()' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/aura-backups/snapshots/*.lock.d', GLOB_ONLYDIR ), 'every mkdir lock is released' );
	}

	public function test_without_flock_a_mkdir_lock_serialises_the_sweep_and_a_stale_one_is_broken(): void {
		// SiteAgent#99 (a): the flock()-less fallback ran unlocked, so a sweep
		// could retire a record a paused publisher then completed. The lock is
		// now a mkdir() directory — atomic on every filesystem — and a lock
		// directory older than LOCK_STALE_AFTER is a crashed holder.
		$file  = WP_CONTENT_DIR . '/mkdirlock.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
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
		$lock = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $recs[0]['id'] . '.lock.d';
		$this->assertTrue( mkdir( $lock, 0700 ), 'a live holder' );
		file_put_contents( $lock . '/tok', '4194301:1' ); // a holder's token — fresh, so its liveness is not even asked (round-3: an EMPTY directory is never a holder; the token arrives with the lock)

		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileExists( $staged, 'contended: the stage stays for the next pass' );
		$this->assertArrayNotHasKey( 'voided', $snaps->get( $recs[0]['id'] ) );
		$this->assertDirectoryExists( $lock, 'a fresh lock is never broken' );

		touch( $lock, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 ); // the holder died long ago
		$snaps->prune_older_than( 30, Aura_Worker_Snapshots::DOOR_KINDS );
		$this->assertFileDoesNotExist( $staged, 'a stale lock is broken and the sweep proceeds' );
		$this->assertTrue( $snaps->get( $recs[0]['id'] )['voided'] );
		$this->assertDirectoryDoesNotExist( $lock, 'released' );
	}

	public function test_a_second_create_of_the_same_absent_path_waits_for_the_first_and_then_sees_it_exists(): void {
		// SiteAgent#99 (b): two writers racing one new path in write mode — the
		// second's in-place overwrite shared the first's inode and passed its
		// inode check. A per-target lock spans claim, write and verification.
		$file   = WP_CONTENT_DIR . '/raced.php';
		$second = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function target_lock_tries() {
				return 3; // a test does not wait 5 s
			}
		};
		$first = new class( $second, $file ) extends Aura_Worker_Snapshots {
			public $other;
			public $path;
			public $seen = null;
			public function __construct( $other, $path ) {
				parent::__construct();
				$this->other = $other;
				$this->path  = $path;
			}
			protected function link_available() {
				return false;
			}
			protected function during_write( $path ) {
				$this->seen = $this->other->create_file( $this->path, "second\n" ); // arrives while the first is still writing
			}
		};

		$res = $first->create_file( $file, "first\n" );

		$this->assertTrue( $res['success'] );
		$this->assertSame( "first\n", file_get_contents( $file ) );
		$this->assertFalse( $first->seen['success'] );
		$this->assertSame( 'locked', $first->seen['error'], 'the second writer is refused while the first holds the path' );
		$this->assertStringContainsString( $file, $first->seen['detail'] );
		$this->assertCount( 1, $first->list_snapshots(), 'one record, the first writer\'s' );

		$later = $second->create_file( $file, "second\n" );
		$this->assertSame( 'exists', $later['error'], 'after the first lands, the second sees a complete file' );
		$this->assertSame( "first\n", file_get_contents( $file ) );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/aura-backups/snapshots/path-*.lock.d', GLOB_ONLYDIR ), 'target locks are released' );
	}

	public function test_a_target_whose_bytes_were_altered_during_the_write_is_reported_interrupted_not_published(): void {
		// SiteAgent#99 (b): after the write the target must hold exactly the
		// recorded bytes. A writer on the same inode (an in-place overwrite that
		// did not go through the engine) is caught by the hash, not the inode.
		$file  = WP_CONTENT_DIR . '/altered.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function during_write( $path ) {
				file_put_contents( $path, "// tail\n", FILE_APPEND ); // same inode, other bytes
			}
		};

		$res = $snaps->create_file( $file, "<?php // whole\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'interrupted', $res['error'] );
		$this->assertArrayHasKey( 'stale_record', $res );
		$rec = $snaps->get( $res['stale_record'] );
		$this->assertTrue( $rec['voided'] );
		$this->assertTrue( $rec['interrupted'] );
		$this->assertCount( 1, glob( WP_CONTENT_DIR . '/.aura-create-*' ), 'the staged bytes are kept for the operator' );
		$this->assertStringContainsString( 'staged bytes kept at', $res['detail'] );
		$this->assertFalse( $snaps->restore( $rec['id'] )['success'], 'never deleted by a restore' );
		$this->assertFileExists( $file );
	}

	public function test_overwrite_file_snapshots_the_old_bytes_and_replaces_the_target_atomically_keeping_its_mode(): void {
		// SiteAgent#99 (b): the Power Pack's overwrite was file_put_contents() on
		// the live inode. The engine now owns it: snapshot, stage beside the
		// target with its mode, rename over it — under the same target lock a
		// create takes.
		$file = WP_CONTENT_DIR . '/existing.php';
		file_put_contents( $file, "<?php // old\n" );
		chmod( $file, 0600 );
		$snaps = new Aura_Worker_Snapshots();

		$res = $snaps->overwrite_file( $file, "<?php // new\n" );

		$this->assertTrue( $res['success'] );
		$this->assertSame( strlen( "<?php // new\n" ), $res['bytes'] );
		$this->assertSame( "<?php // new\n", file_get_contents( $file ) );
		$this->assertSame( 0600, fileperms( $file ) & 0777, 'the target keeps the mode it had' );
		$this->assertArrayNotHasKey( 'staged', $res['snapshot'] );
		$this->assertArrayNotHasKey( 'payload_path', $res['snapshot'] );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ), 'no stage left behind' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/aura-backups/snapshots/path-*.lock.d', GLOB_ONLYDIR ), 'the target lock is released' );

		$this->assertTrue( $snaps->restore( $res['snapshot']['id'] )['success'] );
		$this->assertSame( "<?php // old\n", file_get_contents( $file ), 'the snapshot restores the old bytes' );
	}

	public function test_overwrite_file_with_a_short_stage_write_leaves_the_target_untouched(): void {
		$file = WP_CONTENT_DIR . '/kept.php';
		file_put_contents( $file, "<?php // old\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
			}
		};

		$res = $snaps->overwrite_file( $file, "<?php // new\n" );

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'Short write', $res['error'] );
		$this->assertSame( "<?php // old\n", file_get_contents( $file ), 'a short stage write never reaches the target' );
	}

	public function test_overwrite_file_refuses_a_missing_target_a_directory_and_a_symlink(): void {
		$snaps = new Aura_Worker_Snapshots();
		$this->assertSame( 'File not found: ' . WP_CONTENT_DIR . '/nope.php', $snaps->overwrite_file( WP_CONTENT_DIR . '/nope.php', 'x' )['error'] );
		$this->assertStringContainsString( 'not a regular file', $snaps->overwrite_file( WP_CONTENT_DIR, 'x' )['error'] );
		$real = WP_CONTENT_DIR . '/real.txt';
		file_put_contents( $real, "real\n" );
		$link = WP_CONTENT_DIR . '/alias.txt';
		symlink( $real, $link );
		$res = $snaps->overwrite_file( $link, "x\n" );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'symlink', $res['error'] );
		$this->assertSame( "real\n", file_get_contents( $real ), 'nothing written through the link' );
		$this->assertTrue( is_link( $link ), 'the link itself is untouched' );
		$this->assertSame( array(), $snaps->list_snapshots(), 'nothing snapshotted' );
	}

	public function test_overwrite_file_is_refused_while_a_create_holds_the_target(): void {
		$file = WP_CONTENT_DIR . '/held.php';
		file_put_contents( $file, "old\n" );
		$lock = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function target_lock_tries() {
				return 3;
			}
		};
		$snaps->snapshot_option( 'aura_probe' ); // the snapshots directory exists before the lock file is opened
		$held = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $held, LOCK_EX | LOCK_NB ) );

		$res = $snaps->overwrite_file( $file, "new\n" );

		flock( $held, LOCK_UN );
		fclose( $held );
		$this->assertSame( 'locked', $res['error'] );
		$this->assertSame( "old\n", file_get_contents( $file ) );
	}

	public function test_without_link_an_edited_executable_is_verified_in_place_and_left_at_its_path(): void {
		// SiteAgent#99 (c): the claim by rename() preceded the hash, and the
		// write-mode put-back refuses exec bits — an edited 0755 file was left
		// aside with its path absent. It is hashed in place first now.
		$file  = WP_CONTENT_DIR . '/tool.sh';
		$maker = new Aura_Worker_Snapshots(); // link() is available here: the create lands
		$rec   = $maker->create_file( $file, "#!/bin/sh\necho a\n" )['snapshot'];
		chmod( $file, 0755 );
		file_put_contents( $file, "#!/bin/sh\necho edited\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
		};

		$res = $snaps->restore( $rec['id'] );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'file_changed_since', $res['error'] );
		$this->assertArrayNotHasKey( 'moved_aside', $res );
		$this->assertSame( "#!/bin/sh\necho edited\n", file_get_contents( $file ), 'untouched at its path' );
		$this->assertSame( 0755, fileperms( $file ) & 0777 );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'nothing moved' );

		// Unedited: verified in place, then claimed and removed as before.
		$file2 = WP_CONTENT_DIR . '/tool2.sh';
		$rec2  = $maker->create_file( $file2, "#!/bin/sh\necho b\n" )['snapshot'];
		chmod( $file2, 0755 );
		$this->assertTrue( $snaps->restore( $rec2['id'] )['success'] );
		$this->assertFileDoesNotExist( $file2 );
	}

	public function test_delete_removes_the_record_lock_with_the_record_and_is_refused_while_it_is_held(): void {
		// SiteAgent#98: delete() left `<id>.lock` behind. Codex #100 round-1
		// P1: it may go only under the record's lock.
		$file  = WP_CONTENT_DIR . '/locked-then-deleted.php';
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->create_file( $file, "x\n" )['snapshot'];
		$lock  = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $rec['id'] . '.lock';
		$this->assertFileExists( $lock, 'the publisher\'s re-check under the record lock leaves the lock file' );
		$held = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $held, LOCK_EX | LOCK_NB ) );

		$this->assertFalse( $snaps->delete( $rec['id'] ), 'held by a sweeper or publisher: not deleted' );
		$this->assertIsArray( $snaps->get( $rec['id'] ) );
		flock( $held, LOCK_UN );
		fclose( $held );

		$this->assertTrue( $snaps->delete( $rec['id'] ) );
		$this->assertFileDoesNotExist( $lock );
		$this->assertNull( $snaps->get( $rec['id'] ) );
	}

	public function test_a_waiter_that_opened_a_lock_file_since_unlinked_does_not_hold_the_lock(): void {
		// Codex #100 round-1 P1: the flock is on the inode; once a holder unlinks
		// the file under the lock, a waiter's inode is nobody's lock. with_lock()
		// re-checks the inode after acquiring and starts over.
		$file  = WP_CONTENT_DIR . '/unlinked-lock.php';
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->create_file( $file, "x\n" )['snapshot'];
		$lock  = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $rec['id'] . '.lock';
		$old   = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $old, LOCK_EX | LOCK_NB ) );
		unlink( $lock ); // what delete_record_file() does under the lock
		$new = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $new, LOCK_EX | LOCK_NB ), 'the NEW inode is free' );
		flock( $old, LOCK_UN );
		fclose( $old ); // the old inode is free — a naive taker would think it holds the lock now

		$this->assertFalse( $snaps->delete( $rec['id'] ), 'the lock at the path is held (the new inode); the old one is not the lock' );
		flock( $new, LOCK_UN );
		fclose( $new );
		$this->assertTrue( $snaps->delete( $rec['id'] ) );
	}

	public function test_without_flock_a_slow_holder_heartbeats_and_is_not_broken_by_age(): void {
		// Codex #100 round-1 P1: stale means silent, not old. The write loop
		// touches every held lock directory per chunk; a holder that has been
		// writing for longer than LOCK_STALE_AFTER is still alive.
		$file  = WP_CONTENT_DIR . '/slow.bin';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $seen_token = null;
			public $seen_fresh = null;
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $this->target_for_test ) . '.lock.d';
				touch( $dir, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 ); // as if the holder had been at it for a long time
				return parent::write_all( $fh, $src );
			}
			protected function during_write( $path ) {
				$dir              = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $path ) . '.lock.d';
				$this->seen_fresh = filemtime( $dir ) > time() - 60;
				$this->seen_token = count( glob( $dir . '/*' ) );
			}
			public $target_for_test = '';
		};
		$snaps->target_for_test = $file;

		$res = $snaps->create_file( $file, str_repeat( 'a', 3 * 65536 + 1 ) );

		$this->assertTrue( $res['success'] );
		$this->assertTrue( $snaps->seen_fresh, 'the heartbeat moved the lock directory\'s mtime' );
		$this->assertSame( 1, $snaps->seen_token, 'the holder\'s token is inside' );
		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock.d' );
	}

	public function test_without_flock_a_silent_lock_whose_holder_is_alive_is_not_broken_and_a_dead_holders_is(): void {
		// Codex #100 round-2 P1: blocking I/O cannot heartbeat, so a silent
		// directory is broken only when its holder is not provably alive.
		if ( ! is_dir( '/proc' ) && ! function_exists( 'posix_kill' ) ) {
			$this->markTestSkipped( 'no way to ask the kernel about a process here' );
		}
		$file  = WP_CONTENT_DIR . '/liveness.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $identity = null;
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function target_lock_tries() {
				return 3;
			}
			public function identity_for_test() {
				return $this->holder_identity();
			}
		};
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock.d';
		$snaps->snapshot_option( 'aura_probe' ); // the snapshots directory exists

		// A silent directory held by THIS process (alive): never broken.
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/tok', $snaps->identity_for_test() );
		touch( $dir, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );
		$res = $snaps->create_file( $file, "x\n" );
		$this->assertSame( 'locked', $res['error'], 'the holder is alive, however silent' );
		$this->assertDirectoryExists( $dir );
		$this->assertFileDoesNotExist( $file );
		unlink( $dir . '/tok' );
		rmdir( $dir );

		// The same directory held by a process that is gone: broken, the create lands.
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/tok', '4194301:1' ); // a pid at the top of the range, with a start time no live process has
		touch( $dir, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );
		$res = $snaps->create_file( $file, "x\n" );
		$this->assertTrue( $res['success'], 'a dead holder\'s lock is broken' );
		$this->assertDirectoryDoesNotExist( $dir, 'released by the new holder' );
	}

	public function test_without_flock_a_lock_released_between_the_refusal_and_the_look_is_retried_not_declared_unavailable(): void {
		// Codex #100 round-2 P2: mkdir() refused (EEXIST) and then the directory
		// is gone — the holder released. That is contention, not an unwritable
		// snapshots directory.
		$file  = WP_CONTENT_DIR . '/released.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $releases = 0;
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function target_lock_tries() {
				return 3;
			}
		};
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock.d';
		$snaps->snapshot_option( 'aura_probe' );
		mkdir( $dir, 0700 );
		// The holder "releases" the instant the directory is looked at: is_dir()
		// is the first thing after the refusal, so model the release by making
		// the directory vanish for it — a directory that cannot be entered
		// reads as absent on is_dir() for a non-root user, then is restored.
		chmod( WP_CONTENT_DIR . '/aura-backups/snapshots', 0755 );
		rmdir( $dir ); // trivially: released before the call — the first mkdir() wins
		$res = $snaps->create_file( $file, "x\n" );
		$this->assertTrue( $res['success'] );
		$this->assertStringNotContainsString( 'Unable to create a lock', (string) ( $res['error'] ?? '' ) );
	}

	public function test_without_flock_a_breaker_that_lost_the_race_cannot_reclaim_the_new_holders_lock(): void {
		// Codex #100 round-3 P1: two breakers judge the same dead instance; the
		// first reclaims and re-acquires, the second must take nothing from it.
		// The lock is taken by renaming a prepared directory with the token
		// already inside, so it is never empty; the loser's rmdir() is refused.
		$file  = WP_CONTENT_DIR . '/two-breakers.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $second_breaker_rmdir = null;
			public $tokens_during        = null;
			public $preps_during         = null;
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function during_write( $path ) {
				$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $path ) . '.lock.d';
				// The second breaker, late: it unlinks the token it inspected
				// (already gone) and tries to remove "the dead directory".
				@unlink( $dir . '/dead-token' );
				$this->second_breaker_rmdir = @rmdir( $dir );
				$this->tokens_during        = count( glob( $dir . '/*' ) );
				$this->preps_during         = glob( WP_CONTENT_DIR . '/aura-backups/snapshots/path-*.lock.d.tmp-*' );
			}
		};
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock.d';
		$snaps->snapshot_option( 'aura_probe' );
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/dead-token', '4194301:1' ); // a holder that is not there
		touch( $dir, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );

		$res = $snaps->create_file( $file, "x\n" );

		$this->assertTrue( $res['success'], 'the first breaker reclaimed and re-acquired' );
		$this->assertFalse( $snaps->second_breaker_rmdir, 'the late breaker\'s rmdir() is refused: the new holder\'s token is inside' );
		$this->assertSame( 1, $snaps->tokens_during, 'exactly the new holder\'s token' );
		$this->assertSame( array(), $snaps->preps_during, 'the preparation directory was renamed, not copied' );
		$this->assertDirectoryDoesNotExist( $dir, 'released' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/aura-backups/snapshots/path-*.lock.d*' ), 'nothing left behind' );
	}

	public function test_without_flock_a_holder_whose_proc_record_cannot_be_read_is_not_declared_dead(): void {
		// Codex #100 round-4 P1: open_basedir / hidepid make /proc/<pid>/stat
		// unreadable for a LIVE process. Unreadable is unknown; only the kernel
		// (posix_kill) may say dead.
		if ( ! function_exists( 'posix_kill' ) ) {
			$this->markTestSkipped( 'no posix_kill() to fall back to here' );
		}
		$file  = WP_CONTENT_DIR . '/hidepid.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function target_lock_tries() {
				return 3;
			}
			protected function proc_start_time( $pid ) {
				return null; // every record unreadable, ours included
			}
		};
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock.d';
		$snaps->snapshot_option( 'aura_probe' );
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/tok', getmypid() . ':' ); // this process, no start time known
		touch( $dir, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );

		$res = $snaps->create_file( $file, "x\n" );

		$this->assertSame( 'locked', $res['error'], 'posix_kill says the holder is alive; /proc silence is not death' );
		$this->assertDirectoryExists( $dir );
		unlink( $dir . '/tok' );
		rmdir( $dir );

		file_put_contents( WP_CONTENT_DIR . '/aura-backups/snapshots/.keep', '' );
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/tok', '4194301:' ); // a process that is not there: ESRCH
		touch( $dir, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );
		$this->assertTrue( $snaps->create_file( $file, "x\n" )['success'], 'ESRCH is a positive answer: dead, broken' );
	}

	public function test_overwrite_and_restore_recheck_the_path_under_the_lock(): void {
		// Codex #100 round-4 P2: the symlink / regular-file checks ran before the
		// lock; while this request waited, the path could become a symlink and
		// the rename would replace the link. The checks run again under the lock.
		$file = WP_CONTENT_DIR . '/swapped.php';
		$real = WP_CONTENT_DIR . '/swap-destination.php';
		file_put_contents( $file, "<?php // v1\n" );
		file_put_contents( $real, "destination\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $swap = false;
			protected function after_target_lock( $path ) {
				if ( $this->swap ) {
					unlink( $path );
					symlink( WP_CONTENT_DIR . '/swap-destination.php', $path ); // what happened while we waited
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // v2\n" )['snapshot']; // a regular file: fine

		$snaps->swap = true;
		$res = $snaps->overwrite_file( $file, "<?php // v3\n" );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'symlink', $res['error'] );
		$this->assertTrue( is_link( $file ), 'the link is not replaced' );
		$this->assertSame( "destination\n", file_get_contents( $real ), 'nothing written through it' );
		$this->assertCount( 1, $snaps->list_snapshots(), 'no snapshot of the destination was taken' );

		unlink( $file );
		file_put_contents( $file, "<?php // v4\n" ); // a regular file again for the pre-lock check
		$res = $snaps->restore( $rec['id'] ); // the seam swaps the link back in under the lock
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'symlink', $res['error'] );
		$this->assertTrue( is_link( $file ) );
		$this->assertSame( "destination\n", file_get_contents( $real ) );
	}

	public function test_a_replacement_is_staged_owner_only_and_widened_to_the_targets_mode_after_the_bytes_are_in(): void {
		// Codex #100 round-5 P1: a 0600 secret staged at the 0644 default was
		// readable by any local account while its bytes were written.
		$file = WP_CONTENT_DIR . '/secret.php';
		file_put_contents( $file, "<?php // v1\n" );
		chmod( $file, 0600 );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $calls = array();
			protected function secure_stage( $tmp, $mode = null ) {
				$this->calls[] = array(
					'dir'    => dirname( $tmp ),
					'before' => fileperms( $tmp ) & 0777, // the bytes are all in at this point
					'asked'  => $mode,
				);
				return parent::secure_stage( $tmp, $mode );
			}
			/**
			 * The stage beside the TARGET. An overwrite also stages the
			 * write_seq sidecar and the fence-stamped record, and a create
			 * stages the sidecar too — all of those live in the snapshots
			 * directory, and their ORDER around the content's call has moved
			 * more than once. Name the call by where it is, never by its
			 * position (Codex #102 round-1).
			 */
			public function content_call() {
				foreach ( $this->calls as $c ) {
					if ( WP_CONTENT_DIR === $c['dir'] ) {
						return $c;
					}
				}
				return null;
			}
		};

		$this->assertTrue( $snaps->overwrite_file( $file, "<?php // v2\n" )['success'] );
		$content = $snaps->content_call();
		$this->assertIsArray( $content, 'the content stage was recorded' );
		$this->assertSame( 0600, $content['before'], 'born owner-only' );
		$this->assertSame( 0600, $content['asked'] );
		$this->assertSame( 0600, fileperms( $file ) & 0777 );

		$snaps->calls = array();
		chmod( $file, 0644 );
		$this->assertTrue( $snaps->overwrite_file( $file, "<?php // v3\n" )['success'] );
		$content = $snaps->content_call();
		$this->assertSame( 0600, $content['before'], 'owner-only until complete, even for a 0644 target' );
		$this->assertSame( 0644, $content['asked'] );
		$this->assertSame( 0644, fileperms( $file ) & 0777, 'widened to the file\'s own mode at the end' );

		// A create's stage is born 0600 too and ends at the create mode.
		$snaps->calls = array();
		$snaps->create_file( WP_CONTENT_DIR . '/fresh.php', "x\n" );
		$content = $snaps->content_call();
		$this->assertSame( 0600, $content['before'] );
		$this->assertNull( $content['asked'], 'the create mode' );
		$this->assertSame( 0644, fileperms( WP_CONTENT_DIR . '/fresh.php' ) & 0777 );
	}

	public function test_without_flock_a_holder_on_another_host_is_unknown_not_dead(): void {
		// Codex #100 round-5 P1: wp-content shared between hosts — a foreign pid
		// means nothing to this kernel. Fresh → contended; only the lease (age
		// without heartbeat) ever breaks it, never a local liveness verdict.
		$file  = WP_CONTENT_DIR . '/foreign.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function target_lock_tries() {
				return 3;
			}
			public function alive_for_test( $identity ) {
				return $this->holder_alive( $identity );
			}
		};
		$this->assertNull( $snaps->alive_for_test( '4194301:1:0000000000000000' ), 'a pid that is not here, on a host that is not this one: unknown' );
		$this->assertNull( $snaps->alive_for_test( getmypid() . ':1:0000000000000000' ), 'even our own pid number, when the host differs' );

		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock.d';
		$snaps->snapshot_option( 'aura_probe' );
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/tok', '4194301:1:0000000000000000' );
		$this->assertSame( 'locked', $snaps->create_file( $file, "x\n" )['error'], 'fresh: held' );
		touch( $dir, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );
		$this->assertTrue( $snaps->create_file( $file, "x\n" )['success'], 'silent past the lease: broken — the only policy a remote holder can have' );
	}

	public function test_without_flock_an_abandoned_preparation_is_swept_on_contention(): void {
		// Codex #100 round-5 P2: a kernel-killed request can leave a
		// `.lock.d.tmp-*` behind; a live one exists for microseconds.
		$file  = WP_CONTENT_DIR . '/prep-sweep.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function lock_available() {
				return false;
			}
			protected function target_lock_tries() {
				return 3;
			}
			public function holder_identity_for_test() {
				return $this->holder_identity();
			}
		};
		$dir  = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock.d';
		$snaps->snapshot_option( 'aura_probe' );
		$old  = $dir . '.tmp-deadbeef';
		mkdir( $old, 0700 );
		file_put_contents( $old . '/deadbeef', 'partial' );
		touch( $old . '/deadbeef', time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );
		touch( $old, time() - Aura_Worker_Snapshots::LOCK_STALE_AFTER - 60 );
		$fresh = $dir . '.tmp-cafebabe';
		mkdir( $fresh, 0700 ); // someone mid-acquire right now
		mkdir( $dir, 0700 );
		file_put_contents( $dir . '/tok', $snaps->holder_identity_for_test() );
		$this->assertSame( 'locked', $snaps->create_file( $file, "x\n" )['error'] );

		$this->assertDirectoryDoesNotExist( $old, 'the abandoned preparation went' );
		$this->assertDirectoryExists( $fresh, 'a fresh one is somebody\'s' );
		rmdir( $fresh );
		unlink( $dir . '/tok' );
		rmdir( $dir );
	}

	public function test_the_interrupted_transition_takes_the_record_lock_and_leaves_the_record_alone_when_it_cannot(): void {
		// Codex #100 round-6 P2: the post-publish mismatch voided the record
		// without its lock; a delete() in between would be undone by the rewrite.
		$file  = WP_CONTENT_DIR . '/held-interrupted.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $holder = null;
			protected function link_available() {
				return false;
			}
			protected function during_write( $path ) {
				file_put_contents( $path, "// tail\n", FILE_APPEND ); // the mismatch
				foreach ( $this->list_snapshots() as $rec ) {
					if ( ( $rec['target'] ?? '' ) === $path ) {
						$this->holder = fopen( WP_CONTENT_DIR . '/aura-backups/snapshots/' . $rec['id'] . '.lock', 'cb' );
						flock( $this->holder, LOCK_EX | LOCK_NB ); // someone holds the record right now
					}
				}
			}
		};

		$res = $snaps->create_file( $file, "<?php // whole\n" );
		flock( $snaps->holder, LOCK_UN );
		fclose( $snaps->holder );

		$this->assertSame( 'interrupted', $res['error'] );
		$this->assertStringContainsString( 'could not be locked', $res['detail'] );
		$rec = $snaps->get( $res['stale_record'] );
		$this->assertArrayNotHasKey( 'voided', $rec, 'left as it was — not rewritten under someone else\'s lock' );
		$this->assertFalse( $snaps->restore( $rec['id'] )['success'], 'and its hash refuses the altered file anyway' );
		$this->assertFileExists( $file );
	}

	public function test_a_record_whose_unlink_is_refused_keeps_its_lock_file_while_it_is_voided_in_place(): void {
		// Codex #100 round-6 P2: unlinking the lock file while the record stays
		// (and is rewritten) would let a newcomer lock a fresh inode meanwhile.
		$file  = WP_CONTENT_DIR . '/kept-lock.php';
		$snaps = new class( $file ) extends Aura_Worker_Snapshots {
			private $race;
			public function __construct( $race ) {
				parent::__construct();
				$this->race = $race;
			}
			protected function publish( $tmp, $path ) {
				file_put_contents( $this->race, "mine\n" ); // a winner landed first
				$GLOBALS['_wp_delete_file_fail'] = WP_CONTENT_DIR . '/aura-backups/snapshots/' . $this->list_snapshots()[0]['id'] . '.json'; // and our record's unlink is refused
				return parent::publish( $tmp, $path );
			}
		};

		$res = $snaps->create_file( $file, "mine\n" );
		unset( $GLOBALS['_wp_delete_file_fail'] );

		$this->assertSame( 'exists', $res['error'] );
		$recs = $snaps->list_snapshots();
		$this->assertTrue( $recs[0]['voided'] );
		$this->assertFileExists( WP_CONTENT_DIR . '/aura-backups/snapshots/' . $recs[0]['id'] . '.lock', 'the lock file stays with a record that stays' );

		$this->assertTrue( $snaps->delete( $recs[0]['id'] ) );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/aura-backups/snapshots/' . $recs[0]['id'] . '.lock', 'and goes only once the record is gone' );
	}

	public function test_a_stage_born_wider_than_0600_is_tightened_before_the_first_byte_or_refused(): void {
		// Codex #100 round-7 P1: a default POSIX ACL ignores the umask, so the
		// stage can be born group- or world-readable. It is tightened and
		// verified on the handle before any content is written.
		$file = WP_CONTENT_DIR . '/acl-stage.php';
		file_put_contents( $file, "<?php // v1\n" );

		// (a) the ACL hands back 0666 once; chmod() tightens it; the write proceeds.
		$once = new class extends Aura_Worker_Snapshots {
			public $asked = 0;
			protected function mode_of( $fh, array $stat ) {
				++$this->asked;
				return 1 === $this->asked ? 0100666 : (int) $stat['mode'];
			}
		};
		$this->assertTrue( $once->overwrite_file( $file, "<?php // v2\n" )['success'] );
		// The mock's "wide once" is spent by the FIRST stage() an overwrite
		// takes — the write_seq sidecar's — which is therefore tightened and
		// re-read (2 calls). Every later stage reads tight on the first try
		// (1 call each), and an overwrite takes two more: the content, and
		// the fence-stamped record (Codex #102 round-1 P2 made that one a
		// stage-and-rename instead of a truncating write). 2 + 1 + 1 = 4.
		// This number tracks how many times an overwrite stages; a change to
		// that is a deliberate change to this line, not a surprise.
		$this->assertSame( 4, $once->asked, 'read wide, tightened, read again — spent by the write_seq sidecar\'s own stage() call, which runs first' );
		$this->assertSame( "<?php // v2\n", file_get_contents( $file ) );

		// (b) the ACL wins even after chmod(): refused before a byte is staged; the target untouched.
		$always = new class extends Aura_Worker_Snapshots {
			protected function mode_of( $fh, array $stat ) {
				return 0100666;
			}
		};
		$res = $always->overwrite_file( $file, "<?php // v3\n" );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'private before writing', $res['error'] );
		$this->assertSame( "<?php // v2\n", file_get_contents( $file ) );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ), 'nothing staged' );

		// A create goes the same way.
		$res = $always->create_file( WP_CONTENT_DIR . '/acl-create.php', "x\n" );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'private before writing', $res['error'] );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/acl-create.php' );
	}

	public function test_an_identity_with_no_pid_is_an_unknown_holder_governed_by_the_lease(): void {
		// Codex #100 round-8 P1: getmypid()/gethostname() disabled beside flock()
		// must not fatal; a pid of 0 is "unknown", and age decides.
		$snaps = new class extends Aura_Worker_Snapshots {
			public function alive_for_test( $identity ) {
				return $this->holder_alive( $identity );
			}
		};
		$this->assertNull( $snaps->alive_for_test( '0::' ) );
		$this->assertNull( $snaps->alive_for_test( '0::abcdef0123456789' ) );
		$this->assertNull( $snaps->alive_for_test( '' ) );
	}

	public function test_without_flock_a_broken_holder_cannot_release_the_replacement_lock(): void {
		// Codex #100 round-1 P1: the directory holds its owner's token, so a
		// rmdir() by a holder that was broken as stale fails on the replacement's.
		$dir = WP_CONTENT_DIR . '/aura-backups/snapshots/path-x.lock.d';
		mkdir( WP_CONTENT_DIR . '/aura-backups/snapshots', 0755, true );
		mkdir( $dir, 0700 );
		touch( $dir . '/replacement-token' );

		$this->assertFalse( @rmdir( $dir ), 'non-empty: the old holder\'s release is refused' );
		$this->assertDirectoryExists( $dir );
		unlink( $dir . '/replacement-token' );
		rmdir( $dir );
	}

	public function test_restoring_an_existing_file_snapshot_replaces_by_stage_and_rename_under_the_target_lock(): void {
		// Codex #100 round-1 P1: the existing-file restore wrote in place with
		// file_put_contents(), outside the path lock every other engine writer
		// takes. It stages and renames now, under the lock.
		$file = WP_CONTENT_DIR . '/rolled.php';
		file_put_contents( $file, "<?php // v1\n" );
		chmod( $file, 0600 );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function target_lock_tries() {
				return 3;
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // v2\n" )['snapshot'];
		$lock = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock';
		$held = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $held, LOCK_EX | LOCK_NB ) );

		$res = $snaps->restore( $rec['id'] );
		$this->assertSame( 'locked', $res['error'] );
		$this->assertSame( "<?php // v2\n", file_get_contents( $file ), 'nothing written while the path is held' );
		flock( $held, LOCK_UN );
		fclose( $held );

		$this->assertTrue( $snaps->restore( $rec['id'] )['success'] );
		$this->assertSame( "<?php // v1\n", file_get_contents( $file ) );
		$this->assertSame( 0600, fileperms( $file ) & 0777, 'the file keeps its mode' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-create-*' ) );

		// A short stage write on the restore leaves the target as it was. The
		// record must be a FRESH overwrite, so the fence passes and the restore
		// actually reaches stage() (Codex #101 round-1 P2).
		$fresh = $snaps->overwrite_file( $file, "<?php // v3\n" )['snapshot'];
		$short = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
			}
		};
		$res = $short->restore( $fresh['id'] );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'Short write', $res['error'] );
		$this->assertSame( "<?php // v3\n", file_get_contents( $file ), 'the target is put back as it was' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'the claim is not left behind' );

		// A symlink at the path is refused rather than replaced.
		unlink( $file );
		file_put_contents( WP_CONTENT_DIR . '/elsewhere.php', "real\n" );
		symlink( WP_CONTENT_DIR . '/elsewhere.php', $file );
		$res = $snaps->restore( $rec['id'] );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'symlink', $res['error'] );
		$this->assertTrue( is_link( $file ) );
		$this->assertSame( "real\n", file_get_contents( WP_CONTENT_DIR . '/elsewhere.php' ) );
	}

	public function test_with_link_a_target_altered_after_the_publish_is_interrupted_and_the_intended_content_is_re_staged(): void {
		// Codex #100 round-1 P2: in link mode the stage is a second name of the
		// published inode, so an in-place writer altered it too. The intended
		// content is staged afresh before it is called kept.
		$file  = WP_CONTENT_DIR . '/linked-altered.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function after_publish( $path ) {
				file_put_contents( $path, "// tail\n", FILE_APPEND ); // the same inode as the stage
			}
		};

		$res = $snaps->create_file( $file, "<?php // whole\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'interrupted', $res['error'] );
		$this->assertStringContainsString( 'staged bytes kept at', $res['detail'] );
		$kept = glob( WP_CONTENT_DIR . '/.aura-create-*' );
		$this->assertCount( 1, $kept );
		$this->assertSame( "<?php // whole\n", file_get_contents( $kept[0] ), 'the kept copy is the intended content, not the altered inode' );
		$this->assertSame( "<?php // whole\n// tail\n", file_get_contents( $file ) );
		$this->assertTrue( $snaps->get( $res['stale_record'] )['interrupted'] );
	}


	public function test_without_link_a_created_entry_whose_real_mode_differs_from_the_asked_one_is_refused(): void {
		// Codex #97 round-8 P1: a default POSIX ACL makes the kernel ignore the
		// umask; a 0600 file could come out 0666. The real mode of the owned
		// handle is checked before a byte is written.
		$file  = WP_CONTENT_DIR . '/acl.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $asked = 0;
			protected function link_available() {
				return false;
			}
			protected function mode_of( $fh, array $stat ) {
				// The stage's own check (2.17.2) reads the real mode; the target
				// claim is what this test models as ACL-widened.
				return 0 === $this->asked++ ? (int) $stat['mode'] : 0100666; // what a default ACL would hand back
			}
		};

		$res = $snaps->create_file( $file, "secret\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 'unsupported_filesystem', $res['error'] );
		$this->assertStringContainsString( 'mode 666, not the 644 asked for', $res['detail'] );
		$this->assertSame( '', file_get_contents( $file ), 'not a byte was written into the too-permissive entry' );
		$this->assertSame( array(), $snaps->list_snapshots() );
	}

	public function test_without_link_a_changed_file_still_being_written_is_kept_aside_after_the_copy(): void {
		// Codex #97 round-8 P1: the claim is a copy's source; a writer holding
		// the inode open can add bytes after we reached EOF. The claim goes
		// only when it still reads the same as the target.
		$file  = WP_CONTENT_DIR . '/still-writing.log';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $edit_at_seam = '';
			protected function before_create_claim( $target ) {
				// The change lands AFTER the in-place verification, which is the
				// only window where a link-less host still reaches the claim and
				// put-back machinery this test is about (Codex #102 round-28).
				if ( '' !== $this->edit_at_seam ) {
					file_put_contents( $this->edit_at_seam, "edited\n" );
					$this->edit_at_seam = '';
				}
			}
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
			protected function during_write( $path ) {
				foreach ( glob( WP_CONTENT_DIR . '/.aura-restore-*' ) as $claim ) {
					file_put_contents( $claim, "late line\n", FILE_APPEND ); // the open writer strikes after EOF
				}
			}
		};
		$rec = $snaps->create_file( $file, "a\n" )['snapshot'];
		$snaps->edit_at_seam = $file;
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayHasKey( 'moved_aside', $restore );
		$this->assertStringContainsString( 'may be behind', $restore['detail'] );
		$this->assertSame( "edited\nlate line\n", file_get_contents( $restore['moved_aside'] ), 'the late bytes live in the kept file' );
		$this->assertSame( "edited\n", file_get_contents( $file ), 'the copy at the path is what was read' );
	}

	public function test_a_record_retired_by_a_sweep_while_the_publish_was_in_flight_is_written_back(): void {
		// Codex #97 round-8 P2: a sweep that saw no target retired the record
		// while the publisher was paused before its claim; the publish then
		// lands and must not report success with no rollback record.
		$file  = WP_CONTENT_DIR . '/retired-inflight.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			protected function during_write( $path ) {
				foreach ( glob( WP_CONTENT_DIR . '/aura-backups/snapshots/*.json' ) as $meta ) {
					unlink( $meta ); // what the retirement did
				}
			}
		};

		$res = $snaps->create_file( $file, "x\n" );

		$this->assertTrue( $res['success'] );
		$this->assertArrayNotHasKey( 'warning', $res );
		$rec = $snaps->get( $res['snapshot']['id'] );
		$this->assertIsArray( $rec, 'the record is back' );
		$this->assertSame( hash( 'sha256', "x\n" ), $rec['expected_sha256'] );
		$this->assertTrue( $snaps->restore( $rec['id'] )['success'] );
		$this->assertFileDoesNotExist( $file );
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
			public $edit_at_seam = '';
			protected function before_create_claim( $target ) {
				// The change lands AFTER the in-place verification, which is the
				// only window where a link-less host still reaches the claim and
				// put-back machinery this test is about (Codex #102 round-28).
				if ( '' !== $this->edit_at_seam ) {
					file_put_contents( $this->edit_at_seam, "edited\n" );
					$this->edit_at_seam = '';
				}
			}
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
			protected function after_claim( $claim, $target ) {
				file_put_contents( $target, "newcomer\n" ); // the path is retaken in the window
			}
		};
		$rec = $snaps->create_file( $file, "a\n" )['snapshot'];
		$snaps->edit_at_seam = $file;
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

	public function test_without_link_an_executable_changed_inside_the_claim_window_is_kept_aside_because_its_mode_cannot_be_recreated(): void {
		// Codex #97 round-4 P2: fopen() creates from 0666 and a umask only
		// removes bits — a 0755 file would come back 0644. Refuse the put-back
		// instead: the file stays aside under its claim name, nothing lands at
		// the path. Since 2.17.2 an executable is verified in place BEFORE the
		// claim (SiteAgent#99), so this residual is reached only by a change
		// inside the claim window — modelled with the after_claim seam.
		$file  = WP_CONTENT_DIR . '/nolinkback-exec.sh';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
			protected function after_claim( $claim, $target ) {
				file_put_contents( $claim, "#!/bin/sh\necho edited\n" ); // the editor's save lands on the claimed inode
			}
		};
		$rec = $snaps->create_file( $file, "#!/bin/sh\n" )['snapshot'];
		chmod( $file, 0755 );
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayHasKey( 'moved_aside', $restore );
		$this->assertFileDoesNotExist( $file, 'nothing lands at the path with a lesser mode' );
		$this->assertSame( 0755, fileperms( $restore['moved_aside'] ) & 0777, 'the claim keeps the executable mode' );
		$this->assertSame( "#!/bin/sh\necho edited\n", file_get_contents( $restore['moved_aside'] ) );
	}

	public function test_without_link_a_short_put_back_write_keeps_the_file_aside_and_clears_our_empty_entry(): void {
		$file  = WP_CONTENT_DIR . '/nolinkback-short.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $edit_at_seam = '';
			protected function before_create_claim( $target ) {
				// The change lands AFTER the in-place verification, which is the
				// only window where a link-less host still reaches the claim and
				// put-back machinery this test is about (Codex #102 round-28).
				if ( '' !== $this->edit_at_seam ) {
					file_put_contents( $this->edit_at_seam, "edited\n" );
					$this->edit_at_seam = '';
				}
			}
			public $allow_link = true;
			protected function link_available() {
				return $this->allow_link;
			}
			protected function write_all( $fh, $src ) {
				return $this->allow_link ? parent::write_all( $fh, $src ) : false;
			}
		};
		$rec = $snaps->create_file( $file, "a\n" )['snapshot'];
		$snaps->edit_at_seam = $file;
		$snaps->allow_link = false;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayHasKey( 'moved_aside', $restore );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ), 'the changed bytes are kept' );
		// The empty entry this call created is CLEARED rather than left at the
		// live path (Codex #102 round-26 P1). Leaving it is right for the create
		// path, which has nothing else to put there; a put-back holds the real
		// file under its claim, so an empty file here would take the site's file
		// offline and block the next no-clobber attempt — fopen( 'xb' ) would
		// refuse it. This is the rule publish_restored_by_write() already keeps:
		// a restore clears its own damage.
		$this->assertFalse( self::path_exists( $file ), 'the path is left free, not occupied by our empty entry' );
	}

	/** file_exists() plus is_link(), so a dangling link still counts as present. */
	private static function path_exists( $path ) {
		clearstatcache( true, $path );
		return file_exists( $path ) || is_link( $path );
	}

	public function test_write_seq_is_recorded_and_strictly_increases_per_target(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/seq.php';

		$first = $snaps->create_file( $file, "<?php // v1\n" );
		$this->assertTrue( $first['success'] );
		$a = $snaps->get( $first['snapshot']['id'] )['write_seq'] ?? null;
		$this->assertIsInt( $a, 'a create records write_seq' );

		$second = $snaps->overwrite_file( $file, "<?php // v2\n" );
		$this->assertTrue( $second['success'] );
		$b = $snaps->get( $second['snapshot']['id'] )['write_seq'] ?? null;
		$this->assertIsInt( $b, 'an overwrite records write_seq' );
		$this->assertGreaterThan( $a, $b, 'the second write to this path sorts after the first' );
	}

	public function test_write_seq_increases_even_when_the_clock_goes_backwards(): void {
		// The sidecar holds the previous value, so a clock that steps back
		// cannot make the later write sort before the earlier one.
		$snaps = new class extends Aura_Worker_Snapshots {
			public $now = 2000000000000000; // microseconds
			protected function now_micros() {
				return $this->now;
			}
		};
		$file = WP_CONTENT_DIR . '/clock.php';
		file_put_contents( $file, "<?php // v0\n" );

		$first    = $snaps->overwrite_file( $file, "<?php // v1\n" );
		$snaps->now = 1000000000000000; // the clock steps BACK an hour's worth
		$second   = $snaps->overwrite_file( $file, "<?php // v2\n" );

		$a = $snaps->get( $first['snapshot']['id'] )['write_seq'];
		$b = $snaps->get( $second['snapshot']['id'] )['write_seq'];
		$this->assertSame( $a + 1, $b, 'the sidecar, not the clock, orders the second write' );
	}

	public function test_an_unreadable_sequence_sidecar_leaves_the_record_without_write_seq(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/garbage.php';
		file_put_contents( $file, "<?php // v0\n" );
		$sidecar = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.seq';
		file_put_contents( $sidecar, "not-a-number\n" );

		$out = $snaps->overwrite_file( $file, "<?php // v1\n" );

		$this->assertTrue( $out['success'], 'the write still lands' );
		$this->assertArrayNotHasKey( 'write_seq', $snaps->get( $out['snapshot']['id'] ), 'no sequence is invented' );
	}

	public function test_a_sequence_sidecar_above_php_int_max_leaves_the_record_without_write_seq(): void {
		// Codex #101 round-1 P2: a decimal value above PHP_INT_MAX saturates on
		// the cast, `$prev + 1` becomes a float, and the sidecar would be
		// rewritten in exponent notation. The range check refuses it as an
		// unreadable order rather than inventing one — the write itself still
		// succeeds.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/toobig.php';
		file_put_contents( $file, "<?php // v0\n" );
		$sidecar = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.seq';
		file_put_contents( $sidecar, str_repeat( '9', 25 ) ); // far above PHP_INT_MAX

		$out = $snaps->overwrite_file( $file, "<?php // v1\n" );

		$this->assertTrue( $out['success'], 'the write still lands' );
		$this->assertArrayNotHasKey( 'write_seq', $snaps->get( $out['snapshot']['id'] ), 'no order is invented for a value that cannot be incremented as an int' );
	}

	public function test_a_sequence_sidecar_that_cannot_be_written_leaves_the_record_without_write_seq(): void {
		// The sidecar's OWN write can fail (stage() answering an array, or its
		// rename() failing) without touching the content write at all — the
		// write still succeeds, it simply records no order.
		$file = WP_CONTENT_DIR . '/seq-write-fails.php';
		file_put_contents( $file, "<?php // v0\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				// Fail ONLY the sidecar's own stage() call — a '.seq' name —
				// and delegate to the real implementation for the content's,
				// or this test would prove nothing about the sidecar seam.
				if ( '.seq' === substr( $name, -4 ) ) {
					return array( 'success' => false, 'error' => 'forced sidecar stage failure' );
				}
				return parent::stage( $dir, $name, $content, $mode );
			}
		};

		$out = $snaps->overwrite_file( $file, "<?php // v1\n" );

		$this->assertTrue( $out['success'], 'the write still lands' );
		$this->assertArrayNotHasKey( 'write_seq', $snaps->get( $out['snapshot']['id'] ), 'no order is invented when the sidecar itself cannot be written' );
	}

	public function test_a_stale_sequence_stage_is_swept_from_the_snapshots_directory(): void {
		// Codex #101 round-3 P2: a crash between stage() and rename() left a
		// `.aura-create-*` in the snapshots directory that nothing swept.
		$snaps = new Aura_Worker_Snapshots();
		$dir   = WP_CONTENT_DIR . '/aura-backups/snapshots';
		$stray = $dir . '/.aura-create-deadbeefdeadbeef';
		file_put_contents( $stray, '123' );
		touch( $stray, time() - 7200 ); // older than STAGE_MAX_AGE

		$file = WP_CONTENT_DIR . '/sweeps.php';
		file_put_contents( $file, "<?php // v0\n" );
		$this->assertTrue( $snaps->overwrite_file( $file, "<?php // v1\n" )['success'] );

		$this->assertFileDoesNotExist( $stray, 'the stale sidecar stage is swept' );
	}

	public function test_a_direct_snapshot_file_records_no_write_seq(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/direct.php';
		file_put_contents( $file, "<?php // v0\n" );

		$snap = $snaps->snapshot_file( $file );

		$this->assertTrue( $snap['success'] );
		$this->assertArrayNotHasKey( 'write_seq', $snaps->get( $snap['snapshot']['id'] ) );
	}

	// --- 2.17.3: the fenced overwrite restore (Task 2) -----------------------

	public function test_an_overwrite_restore_writes_only_while_the_file_holds_what_the_write_left(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/fenced.php';
		file_put_contents( $file, "<?php // original\n" );

		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];
		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertArrayNotHasKey( 'already', $out );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'the claim is cleaned up' );
	}

	public function test_an_overwrite_restore_of_a_file_edited_since_is_refused_and_claims_nothing(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/edited.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		file_put_contents( $file, "<?php // a human edited this\n" );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertSame( 'file_changed_since', $out['error'] );
		$this->assertSame( "<?php // a human edited this\n", file_get_contents( $file ), 'nothing was written' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'a refusal never moves the file aside' );
	}

	public function test_an_external_write_after_the_claim_is_never_clobbered(): void {
		// Codex #101 round-1 P1: the lock holds only SiteAgent's own writers.
		// after_claim() models the editor that lands the instant we claim.
		$snaps = new class extends Aura_Worker_Snapshots {
			public $fired = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->fired ) {
					$this->fired = true;
					file_put_contents( $claim, "<?php // edited under us\n" ); // the claimed inode changes
				}
			}
		};
		$file = WP_CONTENT_DIR . '/raced.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertSame( "<?php // edited under us\n", file_get_contents( $file ), 'the edit is back at its path, not overwritten' );
	}

	public function test_without_link_a_restored_file_keeps_its_restrictive_mode(): void {
		// Codex #101 round-3 P1: publish()'s write path creates from
		// FS_CHMOD_FILE (0644), so a 0600 file restored on a link()-less host
		// would become readable by every local account.
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
		};
		$file = WP_CONTENT_DIR . '/private.php';
		file_put_contents( $file, "<?php // original\n" );
		chmod( $file, 0600 );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		clearstatcache();
		$this->assertSame( 0600, fileperms( $file ) & 0777, 'a private file is never widened by a restore' );
	}

	public function test_an_overwrite_whose_write_never_lands_leaves_an_unfenced_record(): void {
		// Codex #101 round-5/round-6 P1: the record must never assert bytes that
		// did not land. It carries no fence until the write succeeds, so a
		// failed write needs no retirement at all.
		$file  = WP_CONTENT_DIR . '/never-landed.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
			}
		};

		$res = $snaps->overwrite_file( $file, "<?php // written\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ), 'the target never changed' );
		foreach ( ( new Aura_Worker_Snapshots() )->list_snapshots() as $rec ) {
			$this->assertArrayNotHasKey( 'replaced_with_sha256', $rec, 'no record claims a write that did not land' );
		}
	}

	public function test_a_stamp_that_fails_leaves_the_record_unfenced_and_the_write_successful(): void {
		// The write is a fact; the bookkeeping is not. An unfenced record is
		// the safe side: Aura never offers it for restore.
		$file  = WP_CONTENT_DIR . '/unstamped.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stamp_replaced_hash( $id, $sha ) {
				return false;
			}
		};

		$res = $snaps->overwrite_file( $file, "<?php // written\n" );

		$this->assertTrue( $res['success'], 'the write landed and is reported as such' );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ) );
		$this->assertArrayNotHasKey( 'replaced_with_sha256', $res['snapshot'] );
		$out = $snaps->restore( $res['snapshot']['id'] );
		$this->assertSame( 'aura_snapshot_unfenced', $out['code'] );
	}

	public function test_a_racer_that_takes_the_path_during_cleanup_keeps_its_file(): void {
		// Codex #101 round-6 P1: stat-then-unlink is two steps on a NAME. The
		// entry is claimed by rename() and the moved inode re-checked, so a
		// racer's replacement is never the file that gets deleted.
		$file  = WP_CONTENT_DIR . '/cleanup-race.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function link_available() {
				return false;
			}
			public $writes = 0;
			protected function write_all( $fh, $src ) {
				if ( 0 === $this->writes++ ) {
					return false; // force the cleanup path
				}
				return parent::write_all( $fh, $src );
			}
			protected function before_entry_removal( $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					unlink( $target );
					file_put_contents( $target, "<?php // a racer's file\n" );
				}
			}
		};
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		// The damaged entry is OURS (this host has no link()), so a racer
		// taking the path during our own failed cleanup is still OUR OWN
		// failure to publish, not a changed-since refusal — no `code`, a 500
		// (Codex #101 round-4 P2 fix: the widened publish-failure check is
		// gated on link_available(), so it never reads OUR OWN debris as a
		// racer's edit on this host).
		$this->assertArrayNotHasKey( 'code', $out, 'our own failure is a 500, not a changed-since 409' );
		$this->assertSame( "<?php // a racer's file\n", file_get_contents( $file ), "the racer's file is never deleted" );
	}

	public function test_without_link_a_short_restore_write_clears_its_entry_and_puts_the_file_back(): void {
		// Codex #101 round-5 P1: write_exclusively() leaves an empty or partial
		// entry at the path, which put_claim_back()'s exclusive create cannot
		// replace — the healthy file stayed aside and the answer claimed
		// "changed since" while the site was actually broken.
		$file  = WP_CONTENT_DIR . '/short-restore.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			public $writes = 0;
			protected function write_all( $fh, $src ) {
				// ONLY THE PUBLISH IS SHORT (Codex #101 round-7 P2):
				// put_back_by_write()'s recovery copy dispatches through this
				// same method, so failing every call would break the put-back
				// the assertions below depend on.
				if ( 0 === $this->writes++ ) {
					fwrite( $fh, '<?php // half' ); // a short write
					return false;
				}
				return parent::write_all( $fh, $src );
			}
		};
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertArrayNotHasKey( 'code', $out, 'our own failure is a 500, not a changed-since 409' );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ), 'the file we claimed is back at its path' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'nothing left aside' );
	}

	public function test_a_partial_restore_write_that_cannot_be_cleared_keeps_the_file_aside_and_says_so(): void {
		// The other half of round-5 P1: when our damaged entry cannot be
		// removed, the healthy file stays under its claim name and the answer
		// names it, rather than reporting a tidy refusal.
		$file  = WP_CONTENT_DIR . '/stuck-restore.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			public $writes = 0;
			protected function write_all( $fh, $src ) {
				if ( 0 === $this->writes++ ) {
					return false; // the publish is short
				}
				return parent::write_all( $fh, $src ); // the put-back copy is real
			}
			protected function remove_own_entry( $fh, $target, $mine ) {
				return false; // the entry cannot be unlinked
			}
		};
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		// Nothing about the file changed — this restore simply could not
		// write (Codex #101 round-5 P1): our own failure is a 500, not a
		// changed-since 409.
		$this->assertArrayNotHasKey( 'code', $out, 'our own failure is a 500, not a changed-since 409' );
		$this->assertArrayHasKey( 'moved_aside', $out );
		$this->assertFileExists( $out['moved_aside'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $out['moved_aside'] ), 'the healthy file is the one kept' );
	}

	public function test_a_chmod_refused_on_the_claimed_mode_is_our_own_failure_not_changed_since(): void {
		// Item 1 (final review): chmod() refusing to set the CLAIMED file's own
		// mode onto its replacement is OUR OWN failure — nothing about the
		// target changed — so put_claim_back() must be called with
		// $refusal = false. Before the fix the defaulted call routed this
		// through changed_since(), answering a designated 409 for what is our
		// own execution failure (a 500).
		$file  = WP_CONTENT_DIR . '/mode-refused.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $calls = 0;
			protected function secure_stage( $tmp, $mode = null ) {
				// Let the content stage — the FIRST secure_stage() call inside
				// publish_restored_bytes() — succeed; fail only the SECOND
				// call, which re-stages the mode read from the claimed file.
				if ( 0 === $this->calls++ ) {
					return parent::secure_stage( $tmp, $mode );
				}
				return false;
			}
		};

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertArrayNotHasKey( 'code', $out, 'our own chmod failure is a 500, not a changed-since 409' );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ), 'the file is put back with the bytes it held under the claim' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'no claim file left behind' );
	}

	public function test_a_link_refused_while_the_path_is_free_puts_the_file_back_by_copy(): void {
		// Codex #102 round-1 P1: link() can EXIST and still be refused — by the
		// filesystem, or by a host policy. Answering `moved_aside` there left
		// the site's file under a name nothing sweeps with NOTHING at its real
		// path: a failed restore taking the file offline. With the path free,
		// the put-back falls through to the copy.
		$file = WP_CONTENT_DIR . '/link-refused-free.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $calls = 0;
			protected function link_into_place( $claim, $target ) {
				return false; // the filesystem refuses hard links
			}
			protected function secure_stage( $tmp, $mode = null ) {
				// Abandon the restore after the claim, so the put-back runs.
				if ( 0 === $this->calls++ ) {
					return parent::secure_stage( $tmp, $mode );
				}
				return false;
			}
		};

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ), 'the file is back at its path, not stranded under the claim' );
		$this->assertArrayNotHasKey( 'moved_aside', $out, 'nothing was left aside' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'the claim copy is gone' );
	}

	public function test_a_link_refused_while_the_path_is_retaken_keeps_the_file_aside(): void {
		// The other half of the same distinction: a refused link WITH the path
		// taken is EEXIST, and the newcomer must never be clobbered — the file
		// stays aside and the answer says where.
		$file = WP_CONTENT_DIR . '/link-refused-taken.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $calls = 0;
			protected function link_into_place( $claim, $target ) {
				return false;
			}
			protected function after_claim( $claim, $target ) {
				file_put_contents( $target, "newcomer\n" ); // the path is retaken in the window
			}
			protected function secure_stage( $tmp, $mode = null ) {
				if ( 0 === $this->calls++ ) {
					return parent::secure_stage( $tmp, $mode );
				}
				return false;
			}
		};

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( "newcomer\n", file_get_contents( $file ), 'the newcomer is untouched' );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the caller learns where the file is' );
		$this->assertMatchesRegularExpression( '/\/\.aura-restore-[0-9a-f]{16}$/', $out['moved_aside'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $out['moved_aside'] ) );
	}

	public function test_a_create_restore_puts_the_file_back_by_copy_when_link_is_refused(): void {
		// The create restore carries its own put-back tail, and the same
		// refused-link-with-a-free-path case stranded the file there too
		// (Codex #102 round-1 P1).
		$file  = WP_CONTENT_DIR . '/link-refused-create.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_into_place( $claim, $target ) {
				return false;
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited\n" ); // not the agent's bytes: the file is put back, never deleted

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertSame( "edited\n", file_get_contents( $file ), 'put back at its path, same bytes' );
		$this->assertArrayNotHasKey( 'moved_aside', $restore );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'the claim copy is gone' );
	}

	public function test_a_writer_that_edits_the_target_during_a_link_less_restore_keeps_the_claim(): void {
		// Codex #102 round-2 P1: an INODE check is not a CONTENT check. The
		// link()-less publish streams into an inode that is visible at the path
		// the whole time, so a writer holding that path can change a region
		// already written while later ones are still being written — and
		// ino/dev still matches, because the ENTRY was never replaced. Calling
		// that success reported a file the restore never produced AND deleted
		// the claim, the only copy of what the restore was undoing.
		$file  = WP_CONTENT_DIR . '/raced-write.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function link_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				$ok = parent::write_all( $fh, $src );
				if ( '' !== $this->armed ) {
					// An external writer, BY NAME: the same inode, truncated and
					// rewritten. Nothing is replaced, so the inode check passes.
					file_put_contents( $this->armed, "tampered by another writer\n" );
				}
				return $ok;
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps->armed = $file; // arm only for the restore's own write
		$out          = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		// NOT a designated refusal: the restore DID write before the racer's
		// edit was detected, and a `code` means the site refused with nothing
		// written (Codex #102 round-16 P1).
		$this->assertArrayNotHasKey( 'code', $out, 'we wrote, so this is our own failure — a 500, not a 409' );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the claim is kept, not deleted' );
		$this->assertFileExists( $out['moved_aside'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $out['moved_aside'] ), 'the pre-restore file survives' );
		$this->assertSame( "tampered by another writer\n", file_get_contents( $file ), "the other writer's bytes are not clobbered" );
	}

	public function test_a_racer_that_replaces_the_target_after_the_link_keeps_the_claim(): void {
		// Codex #102 round-5 P1: link() leaves TWO names for one inode, and the
		// delete that follows is a second step on a name. A writer who replaces
		// the target in between leaves the CLAIM as the last remaining name —
		// so deleting it destroys the very file the put-back existed to
		// protect, silently, because the answer reported it safely back.
		$file  = WP_CONTENT_DIR . '/relinked.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function link_into_place( $claim, $target ) {
				$ok = parent::link_into_place( $claim, $target );
				if ( $ok && '' !== $this->armed ) {
					unlink( $target );                            // a racer replaces the path
					file_put_contents( $target, "newcomer\n" );   // after our link, before our delete
				}
				return $ok;
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited\n" ); // not the agent's bytes: it is put back, never deleted
		$snaps->armed = $file;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayHasKey( 'moved_aside', $restore, "the claim is the changed file's last name; it is kept and named" );
		$this->assertFileExists( $restore['moved_aside'] );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ), 'the user data survives' );
		$this->assertSame( "newcomer\n", file_get_contents( $file ), "the racer's file is untouched" );
	}

	public function test_a_fifo_at_the_target_is_never_opened_for_hashing(): void {
		// Codex #102 round-9 P2: hash_file() opens what it is given, and opening
		// a FIFO blocks until another process opens the other end — here with
		// the target lock held and NOTHING claimed, so the request hangs
		// outright. The type is checked immediately before the open instead.
		if ( ! function_exists( 'posix_mkfifo' ) ) {
			$this->markTestSkipped( 'posix_mkfifo() is unavailable on this host' );
		}
		$file = WP_CONTENT_DIR . '/fifo-target.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		unlink( $file );
		$this->assertTrue( posix_mkfifo( $file, 0600 ), 'a FIFO now holds the path' );

		$out = $plain->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'], 'a non-regular path is a changed-since fact, not a hang' );
		$this->assertSame( 'fifo', filetype( $file ), 'the FIFO is untouched' );

		unlink( $file );
	}

	public function test_a_displaced_file_that_cannot_go_back_is_named_in_the_answer(): void {
		// Codex #102 round-9 P1: on a link-less host a racer's EXECUTABLE file
		// taken by our cleanup claim cannot be recreated — the copy refuses
		// execute bits — so it stays under a `.aura-restore-*` name nothing
		// sweeps. Discarding that path left somebody else's live file silently
		// displaced. It is reported separately from `moved_aside`, which names
		// OUR OWN claim: these are two different files.
		$file  = WP_CONTENT_DIR . '/displaced.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function link_available() {
				return false;
			}
			protected function write_all( $fh, $src ) {
				parent::write_all( $fh, $src );
				return false; // our own write fails, so the entry is cleared
			}
			protected function before_entry_removal( $target ) {
				if ( '' !== $this->armed ) {
					// A racer replaces our entry with an EXECUTABLE file of
					// theirs, after the inode check and before the claim.
					unlink( $target );
					file_put_contents( $target, "#!/bin/sh\necho theirs\n" );
					chmod( $target, 0755 );
				}
			}
		};
		$rec          = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];
		$snaps->armed = $file;

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertArrayHasKey( 'stranded', $out, "the other writer's file is named" );
		$this->assertMatchesRegularExpression( '/\/\.aura-restore-[0-9a-f]{16}$/', $out['stranded'] );
		$this->assertSame( "#!/bin/sh\necho theirs\n", file_get_contents( $out['stranded'] ), 'their bytes, intact' );
		$this->assertNotSame( $out['stranded'], isset( $out['moved_aside'] ) ? $out['moved_aside'] : null, 'distinct from our own claim' );
	}

	public function test_without_link_a_changed_created_file_is_refused_without_being_touched(): void {
		// Codex #102 round-28 P2: on a link-less host the put-back is a COPY
		// into a new inode. The bytes and the mode survive it; ownership, ACLs,
		// xattrs and timestamps do not. So claiming a changed file and putting
		// it back materially modified it — while answering
		// `aura_file_changed_since`, whose whole contract is that nothing was
		// written. It is verified IN PLACE instead, and the inode must be the
		// same one afterwards.
		$file  = WP_CONTENT_DIR . '/untouched-refusal.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited by the user\n" );
		clearstatcache( true, $file );
		$before = stat( $file );

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'aura_file_changed_since', $restore['code'] );
		$this->assertSame( "edited by the user\n", file_get_contents( $file ) );
		// The inode first: it is the assertion that distinguishes "verified in
		// place" from "claimed, copied back, and reported as untouched", and a
		// copy passes every content check while failing this one.
		clearstatcache( true, $file );
		$after = stat( $file );
		$this->assertSame( $before['ino'], $after['ino'], 'the same inode: it was never claimed and copied back' );
		$this->assertSame( $before['dev'], $after['dev'] );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'and nothing was ever claimed' );
		$this->assertStringContainsString( 'left untouched', (string) ( $restore['detail'] ?? '' ) );
	}

	public function test_a_created_file_restore_reports_a_file_its_cleanup_displaced(): void {
		// Codex #102 round-27 P1: the create restore reached remove_own_entry()
		// only once the put-back gained its identity-safe cleanup one round
		// earlier — and until now the fold that reports a displaced file lived
		// inside publish_restored_bytes() alone, which this path never calls.
		// So the file was recorded and then silently dropped from the answer.
		$file  = WP_CONTENT_DIR . '/created-displaced.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $edit_at_seam = '';
			protected function before_create_claim( $target ) {
				// The change lands AFTER the in-place verification, which is the
				// only window where a link-less host still reaches the claim and
				// put-back machinery this test is about (Codex #102 round-28).
				if ( '' !== $this->edit_at_seam ) {
					file_put_contents( $this->edit_at_seam, "edited\n" );
					$this->edit_at_seam = '';
				}
			}
			public $armed = '';
			protected function link_available() {
				return false; // the host where the copy fallback is reached
			}
			public $fail_write = false;
			protected function write_all( $fh, $src ) {
				$ok = parent::write_all( $fh, $src );
				return $this->fail_write ? false : $ok; // short only for the put-back's copy
			}
			protected function before_entry_removal( $target ) {
				if ( '' !== $this->armed ) {
					// A racer replaces our entry with an EXECUTABLE file of
					// theirs, which the copy fallback cannot recreate.
					unlink( $target );
					file_put_contents( $target, "#!/bin/sh\necho theirs\n" );
					chmod( $target, 0755 );
					$this->armed = '';
				}
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		$snaps->edit_at_seam = $file;
		$snaps->armed      = $file;
		$snaps->fail_write = true;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertArrayHasKey( 'stranded', $restore, "the other writer's file is named on THIS path too" );
		$this->assertMatchesRegularExpression( '/\/\.aura-restore-[0-9a-f]{16}$/', $restore['stranded'] );
		$this->assertSame( "#!/bin/sh\necho theirs\n", file_get_contents( $restore['stranded'] ), 'their bytes, intact' );
	}

	public function test_a_put_back_refused_by_a_wide_acl_leaves_the_path_free(): void {
		// Codex #102 round-26 P1: a default ACL can make the exclusively created
		// recovery copy come out wider than asked, which write_exclusively()
		// refuses — and it used to leave its empty entry at the LIVE path. The
		// claim then held the real file while the site's path held nothing but
		// an empty file, and the next no-clobber attempt was blocked, because
		// fopen( 'xb' ) refuses an existing path. A failed restore took the file
		// offline.
		$file  = WP_CONTENT_DIR . '/acl-putback.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $edit_at_seam = '';
			protected function before_create_claim( $target ) {
				// The change lands AFTER the in-place verification, which is the
				// only window where a link-less host still reaches the claim and
				// put-back machinery this test is about (Codex #102 round-28).
				if ( '' !== $this->edit_at_seam ) {
					file_put_contents( $this->edit_at_seam, "edited\n" );
					$this->edit_at_seam = '';
				}
			}
			public $wide = false;
			protected function link_available() {
				return false; // the host where the copy fallback is reached
			}
			protected function mode_of( $fh, array $stat ) {
				// The ACL hands back a wider mode than the umask asked for, but
				// only once the put-back's own copy is being created.
				return $this->wide ? 0100666 : (int) $stat['mode'];
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		$snaps->edit_at_seam = $file;
		chmod( $file, 0600 ); // so the put-back asks for 0600 and the ACL's 0666 differs
		$snaps->wide = true;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertArrayHasKey( 'moved_aside', $restore, 'the changed file is kept and named' );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ) );
		$this->assertFalse( self::path_exists( $file ), 'and the live path is left free, not holding an empty file of ours' );
	}

	public function test_the_target_is_verified_after_the_claim_hash_not_before_it(): void {
		// Codex #102 round-25 P1: the claim's own hash reads a whole separate
		// inode and for a large file is not a moment. With the target check
		// ahead of it, an edit landing WHILE that hash ran was never seen — so
		// "the last thing verified before the claim is released" was not true
		// of the target at all.
		//
		// The ordering itself is what is pinned here, by a probe rather than by
		// reading the source: the seam removes the CLAIM. If the claim has
		// already been hashed by the time the seam fires, the restore is
		// unaffected and answers a clean success. If it has not, the hash fails
		// and the answer carries `moved_aside`. The two orders are therefore
		// distinguishable from the outside.
		$file = WP_CONTENT_DIR . '/order-matters.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $fired = false;
			protected function before_claim_release( $target ) {
				if ( $this->fired ) {
					return;
				}
				$this->fired = true;
				foreach ( glob( dirname( $target ) . '/.aura-restore-*' ) as $held ) {
					unlink( $held ); // the claim goes, at the seam's instant
				}
			}
		};

		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $snaps->fired, 'the seam ran' );
		$this->assertTrue( $out['success'], 'the restore landed' );
		$this->assertArrayNotHasKey(
			'moved_aside',
			$out,
			'the claim was already hashed before the seam, so losing it changes nothing — which is the ordering under test'
		);
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
	}

	public function test_a_racer_editing_the_target_before_the_claim_is_released_is_caught_without_link(): void {
		// Codex #102 round-24 P1: the final content check was gated on
		// link_available(), so on a link-less host — most managed hosts — the
		// only target check adjacent to the claim's release did not run. The
		// write branch does hash its own result, but BEFORE returning, and a
		// racer editing the target between that hash and the release would see
		// the restore answer success for their bytes while the claim, the only
		// held copy of the pre-restore file, was discarded.
		$file = WP_CONTENT_DIR . '/nolink-late-edit.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function link_available() {
				return false;
			}
			protected function before_claim_release( $target ) {
				if ( '' !== $this->armed ) {
					file_put_contents( $target, "edited by another writer\n" );
					$this->armed = '';
				}
			}
		};
		$snaps->armed = $file;

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'], 'the bytes at the path are not the ones restored' );
		$this->assertArrayNotHasKey( 'code', $out, 'we wrote, so this is our own failure — a 500, not a 409' );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the claim is kept rather than discarded' );
		$this->assertSame( "<?php // written\n", file_get_contents( $out['moved_aside'] ), 'the pre-restore file survives' );
		$this->assertSame( "edited by another writer\n", file_get_contents( $file ), "the other writer's bytes are untouched" );
	}

	public function test_a_racer_editing_the_target_in_place_after_a_linked_publish_is_not_a_success(): void {
		// Codex #102 round-19 P1: an INODE check is not a CONTENT check — the
		// rule round 2 established for the link()-less branch, reaching this one
		// at last. A writer editing the target IN PLACE keeps the inode, so
		// every identity test passes while the bytes at the path are theirs, and
		// the restore reported success for content it never produced.
		$file = WP_CONTENT_DIR . '/inplace-after-publish.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function publish( $tmp, $path ) {
				$out = parent::publish( $tmp, $path );
				if ( true === $out && '' !== $this->armed ) {
					// IN PLACE: same inode, different bytes.
					file_put_contents( $path, "edited by another writer\n" );
					$this->armed = '';
				}
				return $out;
			}
		};
		$snaps->armed = $file;

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'], 'the bytes at the path are not the ones restored' );
		$this->assertArrayNotHasKey( 'code', $out, 'we wrote, so this is our own failure — a 500, not a 409' );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the file it replaced is kept and named' );
		$this->assertSame( "<?php // written\n", file_get_contents( $out['moved_aside'] ) );
		$this->assertSame( "edited by another writer\n", file_get_contents( $file ), "the other writer's bytes are untouched" );
	}

	public function test_a_racer_replacing_the_target_right_after_a_linked_publish_is_not_a_success(): void {
		// Codex #102 round-16 P1. With link() the stage is a SECOND NAME for the
		// published inode, so a racer replacing the target between the publish
		// and the stage's discard leaves that name holding the restored bytes —
		// and answering success would report a restore whose result is not at
		// the path at all.
		$file = WP_CONTENT_DIR . '/relinked-publish.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function publish( $tmp, $path ) {
				$out = parent::publish( $tmp, $path );
				if ( true === $out && '' !== $this->armed ) {
					unlink( $path );                              // a racer replaces it
					file_put_contents( $path, "newcomer\n" );     // right after our publish
					$this->armed = '';
				}
				return $out;
			}
		};
		$snaps->armed = $file;

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'], 'the restore is not at the path, so it did not succeed' );
		$this->assertArrayNotHasKey( 'code', $out, 'we wrote, so this is our own failure — a 500, not a 409' );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the file it replaced is kept and named' );
		$this->assertSame( "<?php // written\n", file_get_contents( $out['moved_aside'] ) );
		$this->assertSame( "newcomer\n", file_get_contents( $file ), "the racer's file is untouched" );
	}

	public function test_a_special_node_is_kept_aside_and_never_copied_or_renamed(): void {
		// Codex #102 rounds 8 and 10, settled by simplification. A FIFO, socket
		// or device node can be claimed like anything else — an external writer
		// can replace the verified file with one between the hash and the claim
		// — and neither way of putting it back is safe:
		//
		// - copying opens it, and fopen( 'rb' ) on a FIFO BLOCKS until another
		//   process opens the other end, hanging the request while it holds the
		//   target lock. A regression here deadlocks rather than fails, so the
		//   copy is stubbed instead of being allowed to run.
		// - renaming it back clobbers whatever took the path (measured:
		//   rename( FIFO, existing file ) replaces the file).
		//
		// So it is kept aside and named, and the seam that fires only on the
		// checked rename must not fire at all.
		if ( ! function_exists( 'posix_mkfifo' ) ) {
			$this->markTestSkipped( 'posix_mkfifo() is unavailable on this host' );
		}
		$dir  = WP_CONTENT_DIR;
		$fifo = $dir . '/.aura-restore-f1f0';
		$this->assertTrue( posix_mkfifo( $fifo, 0600 ), 'the fixture is a real FIFO' );

		$snaps = new class extends Aura_Worker_Snapshots {
			public $seam_fired     = 0;
			public $copy_attempted = false;
			protected function link_available() {
				return false; // the host where the copy fallback would be reached
			}
			protected function before_non_file_put_back( $aside, $target ) {
				++$this->seam_fired;
			}
			protected function put_back_by_write( $claim, $target ) {
				// Never delegates: opening the FIFO is the hang under test.
				$this->copy_attempted = true;
				return 'the copy must never be reached for a special node';
			}
			public function put_back( $aside, $target ) {
				return $this->put_back_no_clobber( $aside, $target );
			}
		};

		$this->assertSame( Aura_Worker_Snapshots::CLAIM_KEPT, $snaps->put_back( $fifo, $dir . '/fifo-target' ), 'it is kept aside for the caller to name' );
		$this->assertFalse( $snaps->copy_attempted, 'never opened for copying' );
		$this->assertSame( 0, $snaps->seam_fired, 'and never offered to the clobbering rename' );
		$this->assertSame( 'fifo', filetype( $fifo ), 'the node is still aside, intact' );
		$this->assertFileDoesNotExist( $dir . '/fifo-target', 'and nothing was put at the path' );

		unlink( $fifo );
	}

	public function test_a_symlink_lost_to_a_racer_after_the_delete_is_reported_not_called_clean(): void {
		// Codex #102 round-15 P2: the symlink branch carried rounds 5 and 6's
		// first half — do not delete while the path stopped being ours — but not
		// the second: SAY SO when the race is lost anyway. PHP cannot fuse the
		// check and the unlink, so a racer landing between them leaves the name
		// just removed as the entry's last. That cannot be prevented; answering
		// a clean put-back for it can.
		$dir   = WP_CONTENT_DIR;
		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function before_claim_drop( $claim, $target ) {
				// Fires BEFORE the delete; the racer lands after our check.
				if ( '' !== $this->armed ) {
					unlink( $target );
					file_put_contents( $target, "newcomer\n" );
					$this->armed = '';
				}
			}
			public function put_back( $aside, $target ) {
				return $this->put_back_no_clobber( $aside, $target );
			}
		};

		file_put_contents( $dir . '/lost-dest.txt', "dest\n" );
		symlink( $dir . '/lost-dest.txt', $dir . '/.aura-restore-los1' );
		$snaps->armed = $dir . '/lost-link.txt';

		$out = $snaps->put_back( $dir . '/.aura-restore-los1', $dir . '/lost-link.txt' );

		$this->assertSame( Aura_Worker_Snapshots::CLAIM_KEPT, $out, 'the check catches this one and keeps the name' );
		$this->assertTrue( is_link( $dir . '/.aura-restore-los1' ), 'the link is still held' );
		$this->assertSame( "newcomer\n", file_get_contents( $dir . '/lost-link.txt' ), "the racer's file is untouched" );
		unlink( $dir . '/.aura-restore-los1' );
		unlink( $dir . '/lost-link.txt' );
	}

	public function test_a_symlink_claim_is_kept_when_a_racer_takes_the_path_before_cleanup(): void {
		// Codex #102 round-7 P2: the symlink branch did not carry the rule the
		// hard-link branch learned in rounds 5 and 6. A writer who replaces the
		// path after symlink() lands leaves the entry aside as the only copy,
		// and deleting it loses that writer's entry while the answer reports it
		// safely back. Identity for a symlink is its DESTINATION — the link we
		// create is a different inode from the one held aside, by construction.
		$dir   = WP_CONTENT_DIR;
		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function before_claim_drop( $claim, $target ) {
				if ( '' !== $this->armed ) {
					unlink( $target );                          // inside the window
					file_put_contents( $target, "newcomer\n" );
				}
			}
			public function put_back( $aside, $target ) {
				return $this->put_back_no_clobber( $aside, $target );
			}
		};

		file_put_contents( $dir . '/sl-dest.txt', "dest\n" );
		symlink( $dir . '/sl-dest.txt', $dir . '/.aura-restore-9999' );
		$snaps->armed = $dir . '/sl-target.txt';

		$this->assertSame( Aura_Worker_Snapshots::CLAIM_KEPT, $snaps->put_back( $dir . '/.aura-restore-9999', $dir . '/sl-target.txt' ) );
		$this->assertTrue( is_link( $dir . '/.aura-restore-9999' ), 'the claim stays aside for the caller to name' );
		$this->assertSame( $dir . '/sl-dest.txt', readlink( $dir . '/.aura-restore-9999' ) );
		$this->assertSame( "newcomer\n", file_get_contents( $dir . '/sl-target.txt' ), "the other writer's entry is untouched" );
	}

	public function test_a_racer_inside_the_unclosable_window_is_reported_not_silent(): void {
		// Codex #102 round-6 P1. PHP cannot make a check and an unlink one
		// operation, so the last sliver of residual (a) stays open: a writer
		// who replaces the path between the proof and the delete leaves the
		// claim as the file's last name, and the delete then loses it. What is
		// fixable is the SILENCE — that outcome used to answer a clean put-back
		// for a file that no longer existed anywhere. The seam fires in exactly
		// that window; the answer must say what happened.
		$file  = WP_CONTENT_DIR . '/window-loss.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function before_claim_drop( $claim, $target ) {
				if ( '' !== $this->armed ) {
					unlink( $target );                           // inside the window
					file_put_contents( $target, "newcomer\n" );
				}
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );
		$snaps->armed = $file;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'file_changed_since', $restore['error'] );
		$this->assertArrayNotHasKey( 'moved_aside', $restore, 'there is no file to point at any more' );
		$this->assertArrayHasKey( 'detail', $restore, 'but the caller is told, rather than reading it as a clean put-back' );
		$this->assertStringContainsString( 'could not be preserved', $restore['detail'] );
		$this->assertSame( "newcomer\n", file_get_contents( $file ), "the racer's file is untouched" );
	}

	public function test_a_racer_that_unlinks_the_target_before_the_drop_keeps_the_last_name(): void {
		// The same window, one step earlier — and here it IS caught: the link
		// count read off the claim is 1, so the claim is the file's last name
		// and is kept rather than deleted.
		$file  = WP_CONTENT_DIR . '/window-caught.php';
		$snaps = new class extends Aura_Worker_Snapshots {
			public $armed = '';
			protected function link_into_place( $claim, $target ) {
				$ok = parent::link_into_place( $claim, $target );
				if ( $ok && '' !== $this->armed ) {
					unlink( $target ); // the target's name goes; ours is the last
					file_put_contents( $target, "newcomer\n" );
				}
				return $ok;
			}
		};
		$rec = $snaps->create_file( $file, "agent\n" )['snapshot'];
		file_put_contents( $file, "edited\n" );
		$snaps->armed = $file;

		$restore = $snaps->restore( $rec['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertArrayHasKey( 'moved_aside', $restore );
		$this->assertSame( "edited\n", file_get_contents( $restore['moved_aside'] ), 'the user data survives' );
		$this->assertSame( "newcomer\n", file_get_contents( $file ) );
	}

	public function test_a_no_clobber_shape_is_put_back_without_the_checked_rename(): void {
		// Codex #102 round-3 P1: check-then-rename is a race — rename() CLOBBERS
		// on POSIX, so a writer arriving between the check and the rename is
		// destroyed. Verified on this platform: rename( symlink, existing file )
		// replaces the file, while symlink() onto an existing path refuses. So a
		// symlink and a regular file go back with a primitive that REFUSES an
		// occupied path, and never reach the checked rename at all — which is
		// what the seam below proves, because it fires only on that branch.
		$dir   = WP_CONTENT_DIR;
		$snaps = new class extends Aura_Worker_Snapshots {
			public $seam_fired = 0;
			protected function before_non_file_put_back( $aside, $target ) {
				++$this->seam_fired;
			}
			public function put_back( $aside, $target ) {
				return $this->put_back_no_clobber( $aside, $target );
			}
		};

		// (a) a symlink: recreated with symlink(), no checked rename.
		file_put_contents( $dir . '/pb-dest.txt', "dest\n" );
		symlink( $dir . '/pb-dest.txt', $dir . '/.aura-restore-aaaa' );
		$this->assertSame( Aura_Worker_Snapshots::CLAIM_DROPPED, $snaps->put_back( $dir . '/.aura-restore-aaaa', $dir . '/pb-link.txt' ) );
		$this->assertTrue( is_link( $dir . '/pb-link.txt' ), 'back as a link, not as a copy' );
		$this->assertSame( $dir . '/pb-dest.txt', readlink( $dir . '/pb-link.txt' ), 'pointing where it pointed' );
		$this->assertFileDoesNotExist( $dir . '/.aura-restore-aaaa' );
		$this->assertSame( 0, $snaps->seam_fired, 'a symlink never reaches the checked rename' );

		// (b) a regular file: linked back, no checked rename.
		file_put_contents( $dir . '/.aura-restore-bbbb', "mine\n" );
		$this->assertSame( Aura_Worker_Snapshots::CLAIM_DROPPED, $snaps->put_back( $dir . '/.aura-restore-bbbb', $dir . '/pb-file.txt' ) );
		$this->assertSame( "mine\n", file_get_contents( $dir . '/pb-file.txt' ) );
		$this->assertSame( 0, $snaps->seam_fired, 'a regular file never reaches the checked rename either' );

		// (c) a regular file on a host WITHOUT link(): the exclusive-create copy,
		// still never the rename (Codex #102 round-4 P1 — gating only the link()
		// attempt left this shape falling through to it on most managed hosts).
		$nolink = new class extends Aura_Worker_Snapshots {
			public $seam_fired = 0;
			protected function link_available() {
				return false;
			}
			protected function before_non_file_put_back( $aside, $target ) {
				++$this->seam_fired;
			}
			public function put_back( $aside, $target ) {
				return $this->put_back_no_clobber( $aside, $target );
			}
		};
		file_put_contents( $dir . '/.aura-restore-eeee', "nolink\n" );
		$this->assertSame( Aura_Worker_Snapshots::CLAIM_DROPPED, $nolink->put_back( $dir . '/.aura-restore-eeee', $dir . '/pb-nolink.txt' ) );
		$this->assertSame( "nolink\n", file_get_contents( $dir . '/pb-nolink.txt' ) );
		$this->assertFileDoesNotExist( $dir . '/.aura-restore-eeee' );
		$this->assertSame( 0, $nolink->seam_fired, 'a link-less host copies it back; it never reaches the rename' );

		// ...and an occupied path is refused there too, never replaced.
		file_put_contents( $dir . '/pb-nolink-taken.txt', "theirs\n" );
		file_put_contents( $dir . '/.aura-restore-ffff', "mine\n" );
		$this->assertSame( Aura_Worker_Snapshots::CLAIM_KEPT, $nolink->put_back( $dir . '/.aura-restore-ffff', $dir . '/pb-nolink-taken.txt' ) );
		$this->assertSame( "theirs\n", file_get_contents( $dir . '/pb-nolink-taken.txt' ), "the other writer's file is untouched" );
		$this->assertSame( "mine\n", file_get_contents( $dir . '/.aura-restore-ffff' ), 'the entry stays aside, named' );
		$this->assertSame( 0, $nolink->seam_fired );

		// (d) an occupied path is REFUSED, not replaced — the entry stays aside.
		file_put_contents( $dir . '/pb-taken.txt', "theirs\n" );
		symlink( $dir . '/pb-dest.txt', $dir . '/.aura-restore-cccc' );
		$this->assertSame( Aura_Worker_Snapshots::CLAIM_KEPT, $snaps->put_back( $dir . '/.aura-restore-cccc', $dir . '/pb-taken.txt' ) );
		$this->assertSame( "theirs\n", file_get_contents( $dir . '/pb-taken.txt' ), "the other writer's file is untouched" );
		$this->assertTrue( is_link( $dir . '/.aura-restore-cccc' ), 'the link stays aside, named' );
	}

	public function test_a_directory_put_back_refuses_a_path_a_racer_took_in_the_window(): void {
		// A DIRECTORY has no no-clobber move in PHP, so it keeps the checked
		// rename and the seam fires. The exposure is narrower than it looks,
		// and this pins why: rename() of a directory FAILS onto a regular file
		// (verified on this platform), so a racer's FILE survives even when it
		// lands inside the window. Only an empty directory could be replaced —
		// the residual the plan documents.
		$dir   = WP_CONTENT_DIR;
		$snaps = new class extends Aura_Worker_Snapshots {
			public $racer = '';
			protected function before_non_file_put_back( $aside, $target ) {
				if ( '' !== $this->racer ) {
					file_put_contents( $this->racer, "theirs\n" ); // lands INSIDE the window
				}
			}
			public function put_back( $aside, $target ) {
				return $this->put_back_no_clobber( $aside, $target );
			}
		};

		mkdir( $dir . '/.aura-restore-dddd' );
		file_put_contents( $dir . '/.aura-restore-dddd/inside.txt', "x\n" );
		$snaps->racer = $dir . '/pb-dir';

		$this->assertSame( Aura_Worker_Snapshots::CLAIM_KEPT, $snaps->put_back( $dir . '/.aura-restore-dddd', $dir . '/pb-dir' ) );
		$this->assertSame( "theirs\n", file_get_contents( $dir . '/pb-dir' ), "the racer's file is not replaced" );
		$this->assertFileExists( $dir . '/.aura-restore-dddd/inside.txt', 'the directory stays aside, whole' );
	}

	public function test_an_unfenced_record_says_so_even_when_a_symlink_took_the_path(): void {
		// Codex #102 round-2 P2: a record with no fence can NEVER be restored,
		// whatever is at the path now. Answering aura_file_changed_since for a
		// symlink there told the caller to look again at a file, when the fact
		// it needs is that THE RECORD is unusable.
		$file = WP_CONTENT_DIR . '/unfenced-symlink.php';
		file_put_contents( $file, "original\n" );
		$snaps = new Aura_Worker_Snapshots();
		$rec   = $snaps->snapshot_file( $file )['snapshot']; // a direct snapshot carries no fence
		$this->assertArrayNotHasKey( 'replaced_with_sha256', $snaps->get( $rec['id'] ) );

		unlink( $file );
		file_put_contents( WP_CONTENT_DIR . '/elsewhere-unfenced.php', "real\n" );
		symlink( WP_CONTENT_DIR . '/elsewhere-unfenced.php', $file );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_snapshot_unfenced', $out['code'], 'the record, not the path, is what is wrong' );
		$this->assertTrue( is_link( $file ), 'the link is untouched' );
		$this->assertSame( "real\n", file_get_contents( WP_CONTENT_DIR . '/elsewhere-unfenced.php' ), 'nothing written through it' );
	}

	public function test_a_refused_fsync_after_the_stamp_lands_does_not_unsay_the_fence(): void {
		// Codex #102 round-22 P2: once the rename lands, the record ON DISK
		// carries the hash and every later get() reads it as fenced. Answering
		// "unfenced" because the fsync afterwards was refused made the ANSWER
		// and the RECORD disagree — Aura would mirror it unfenced and never
		// offer it, while the site would accept a restore of the same record.
		$file  = WP_CONTENT_DIR . '/fsync-refused.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function sync_file( $path ) {
				return false; // the fsync is refused, after the rename landed
			}
		};

		$out = $snaps->overwrite_file( $file, "<?php // written\n" );

		$this->assertTrue( $out['success'] );
		$sha = hash( 'sha256', "<?php // written\n" );
		$this->assertSame( $sha, $out['snapshot']['replaced_with_sha256'] ?? null, 'the answer says fenced' );
		$this->assertSame( $sha, $snaps->get( $out['snapshot']['id'] )['replaced_with_sha256'] ?? null, 'and so does the record' );

		// And the two agreeing means the restore behaves as the answer implied.
		file_put_contents( $file, "<?php // written\n" ); // untouched since the write
		$restore = $snaps->restore( $out['snapshot']['id'] );
		$this->assertTrue( $restore['success'] );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
	}

	public function test_a_fence_stamp_that_fails_leaves_the_record_whole_and_readable(): void {
		// Codex #102 round-1 P2: the caller treats a failed stamp as a safely
		// UNFENCED record — which assumes the record still EXISTS.
		// file_put_contents() truncates first, so a short write or a full disk
		// left an undecodable .json: the rollback record lost outright and its
		// payload orphaned, strictly worse than unfenced.
		$file = WP_CONTENT_DIR . '/stamp-fails.php';
		file_put_contents( $file, "<?php // original\n" );

		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				// Fail ONLY the record's own stage — the content's and the
				// write_seq sidecar's must still land, or the test proves
				// nothing about the stamp.
				if ( '.json' === substr( (string) $name, -5 ) ) {
					return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
				}
				return parent::stage( $dir, $name, $content, $mode );
			}
		};

		$out = $snaps->overwrite_file( $file, "<?php // written\n" );

		$this->assertTrue( $out['success'], 'the write itself landed' );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ) );

		$record = $snaps->get( $out['snapshot']['id'] );
		$this->assertIsArray( $record, 'the record is still decodable' );
		$this->assertSame( $file, $record['target'] );
		$this->assertArrayNotHasKey( 'replaced_with_sha256', $record, 'and unfenced, the closed side' );
		$this->assertSame( "<?php // original\n", file_get_contents( $record['payload_path'] ), 'its payload is intact' );

		// Unfenced means the restore refuses rather than writing.
		$restore = $snaps->restore( $record['id'] );
		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'aura_snapshot_unfenced', $restore['code'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ), 'nothing was written' );
	}

	public function test_a_chmod_that_lands_after_the_claim_is_the_mode_that_is_restored(): void {
		// Codex #101 round-4 P1: a chmod leaves the content alone, so the
		// authoritative hash still passes; republishing at the mode read before
		// the claim would discard the restriction someone just applied.
		$file  = WP_CONTENT_DIR . '/tightened.php';
		file_put_contents( $file, "<?php // original\n" );
		chmod( $file, 0644 );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					chmod( $claim, 0600 ); // tightened while we hold it
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		clearstatcache();
		$this->assertSame( 0600, fileperms( $file ) & 0777, 'the mode the file had when we claimed it is the mode it comes back with' );
	}

	public function test_a_dangling_symlink_that_takes_the_path_answers_the_changed_code(): void {
		// Codex #101 round-4 P2: publish() answers 'unsupported_filesystem' for
		// a DANGLING link (file_exists() is false for one), and the merge used
		// to overwrite the put-back's code with null — a 500 for what is a
		// designated changed-since refusal.
		$file  = WP_CONTENT_DIR . '/raced-link.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					symlink( WP_CONTENT_DIR . '/does-not-exist.php', $target ); // a racer takes the path
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'], 'a designated refusal, not a 500' );
		$this->assertArrayHasKey( 'moved_aside', $out );
		$this->assertTrue( is_link( $file ), "the racer's link is never replaced" );
	}

	public function test_a_directory_that_takes_the_path_after_the_claim_is_put_back(): void {
		// Codex #101 round-7 P1: the claim moves whatever is at the path, and a
		// directory cannot be put back by link or copy — only by rename.
		$file  = WP_CONTENT_DIR . '/raced-dir.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					rename( $claim, $claim . '-stash' );   // our file steps aside
					mkdir( $claim, 0755 );                 // a directory is what we now hold
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertDirectoryExists( $file, 'the directory is put back at its path, not stranded aside' );
	}

	public function test_a_non_file_is_kept_aside_when_the_path_is_retaken(): void {
		// Codex #101 round-8 P1: rename() clobbers, so putting a raced symlink
		// back must never destroy a file that took the path after our claim.
		$file  = WP_CONTENT_DIR . '/retaken.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					rename( $claim, $claim . '-stash' );                 // our file steps aside
					symlink( WP_CONTENT_DIR . '/nowhere.php', $claim );  // a symlink is what we hold
					file_put_contents( $target, "<?php // a newer file\n" ); // and someone retakes the path
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertSame( "<?php // a newer file\n", file_get_contents( $file ), 'the newer file is never clobbered' );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the symlink is named, not destroyed' );
	}

	public function test_a_voided_create_record_answers_voided_even_when_the_file_is_gone(): void {
		// Codex #101 round-7 P2: the already-gone shortcut used to run first, so
		// a retired record reported a cheerful success and a rollback counted
		// it as undone.
		$snaps = new class extends Aura_Worker_Snapshots {
			public function void( $id ) {
				return $this->void_record_in_place( $id, array( 'interrupted' => true ) );
			}
		};
		$file = WP_CONTENT_DIR . '/voided-gone.php';
		$rec  = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		$this->assertTrue( $snaps->void( $rec['id'] ) );
		unlink( $file );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_snapshot_voided', $out['code'] );
		$this->assertArrayNotHasKey( 'already', $out );
	}

	public function test_a_write_through_a_descriptor_opened_before_the_claim_is_kept_aside(): void {
		// Codex #101 round-2 P1: rename() does not revoke an open descriptor.
		// A writer that opened the target before the claim can write into the
		// claimed inode while we publish; the claim is then the only pathname
		// those bytes have, so it is re-hashed and KEPT instead of unlinked.
		$file  = WP_CONTENT_DIR . '/descriptor.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $fh = null;
			protected function publish( $tmp, $path ) {
				if ( null !== $this->fh ) {
					fwrite( $this->fh, "// appended through the open handle\n" ); // into the claimed inode
					fflush( $this->fh );
					$this->fh = null;
				}
				return parent::publish( $tmp, $path );
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps->fh = fopen( $file, 'ab' ); // opened BEFORE the claim
		$out       = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'], 'the old bytes are back at the path' );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the changed inode is named, not destroyed' );
		$this->assertFileExists( $out['moved_aside'] );
		$this->assertStringContainsString( 'appended through the open handle', file_get_contents( $out['moved_aside'] ) );
	}

	public function test_a_failed_stage_never_removes_the_live_path(): void {
		// Codex #101 round-2 P2: the payload is staged while the target is
		// still live, so a staging failure leaves the path untouched and
		// nothing is ever claimed.
		$file = WP_CONTENT_DIR . '/stage-first.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$short = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
			}
		};
		$out = $short->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertStringContainsString( 'Short write', $out['error'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ), 'the live path never went away' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'nothing was claimed' );
	}

	public function test_an_overwrite_restore_run_twice_is_already_and_writes_nothing(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/twice.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$this->assertTrue( $snaps->restore( $rec['id'] )['success'] );
		clearstatcache();
		$mtime = filemtime( $file );

		$again = $snaps->restore( $rec['id'] );

		$this->assertTrue( $again['success'] );
		$this->assertTrue( $again['already'] );
		clearstatcache();
		$this->assertSame( $mtime, filemtime( $file ), 'the file was not rewritten' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'an already-restored file is never claimed' );
	}

	public function test_an_overwrite_restore_of_a_file_that_is_gone_is_refused(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/gone.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		unlink( $file );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertFileDoesNotExist( $file, 'a deleted file is never re-created by a restore' );
	}

	public function test_a_directory_or_symlink_at_the_path_is_refused_with_the_changed_code(): void {
		// Codex #101 round-1 P1: these refusals carried no code, so the REST
		// layer answered 500 for what is a designated changed-since refusal.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/swapped.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		unlink( $file );
		file_put_contents( WP_CONTENT_DIR . '/elsewhere-2.php', "real\n" );
		symlink( WP_CONTENT_DIR . '/elsewhere-2.php', $file );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertStringContainsString( 'symlink', $out['error'], 'the wording a reader already knows is kept' );
		$this->assertSame( "real\n", file_get_contents( WP_CONTENT_DIR . '/elsewhere-2.php' ), 'nothing written through the link' );
	}

	public function test_a_record_taken_without_the_replacing_hash_is_refused_as_unfenced(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/unfenced.php';
		file_put_contents( $file, "<?php // original\n" );

		$rec = $snaps->snapshot_file( $file )['snapshot']; // the direct REST/legacy path
		file_put_contents( $file, "<?php // whatever\n" );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_snapshot_unfenced', $out['code'] );
		$this->assertSame( "<?php // whatever\n", file_get_contents( $file ), 'nothing was written' );
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
