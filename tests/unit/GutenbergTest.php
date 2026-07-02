<?php
/**
 * Tests for the Gutenberg block tools (list / update / create) and the
 * snapshot_post reversal that update relies on.
 *
 * Blocks are JSON in these tests (the bootstrap's parse/serialize round-trip
 * JSON); the real plugin uses WordPress's block parser on real markup.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class GutenbergTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0777, true );
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

	/** Seed a post whose content is a JSON block array (test format). */
	private function seed_post( int $id, array $blocks ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'           => $id,
			'post_title'   => 'Test',
			'post_content' => wp_json_encode( $blocks ),
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);
	}

	private function blocks( int $id ): array {
		return json_decode( $GLOBALS['_posts'][ $id ]->post_content, true );
	}

	// --- list_page_blocks -----------------------------------------------------

	public function test_lists_blocks(): void {
		$this->seed_post( 5, array(
			array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 2 ), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
		) );

		$result = ( new Aura_Tool_Gutenberg_List_Blocks() )->execute( array( 'post_id' => 5 ) );

		$this->assertSame( 2, $result['block_count'] );
		$this->assertSame( 'core/heading', $result['blocks'][0]['name'] );
		$this->assertSame( 2, $result['blocks'][0]['attrs']['level'] );
	}

	public function test_list_missing_post_errors(): void {
		$result = ( new Aura_Tool_Gutenberg_List_Blocks() )->execute( array( 'post_id' => 999 ) );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	// --- update_page_block ----------------------------------------------------

	public function test_update_block_merges_attrs_and_snapshots_first(): void {
		$this->seed_post( 7, array(
			array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 2 ), 'innerHTML' => '<h2>Old</h2>', 'innerContent' => array( '<h2>Old</h2>' ), 'innerBlocks' => array() ),
		) );

		$result = ( new Aura_Tool_Gutenberg_Update_Block() )->execute( array(
			'post_id'     => 7,
			'block_index' => 0,
			'attrs'       => array( 'level' => 3 ),
			'inner_html'  => '<h3>New</h3>',
		) );

		$this->assertTrue( $result['updated'] );
		$this->assertNotSame( '', $result['snapshot_id'] );

		$blocks = $this->blocks( 7 );
		$this->assertSame( 3, $blocks[0]['attrs']['level'] );
		$this->assertSame( '<h3>New</h3>', $blocks[0]['innerHTML'] );
		$this->assertSame( array( '<h3>New</h3>' ), $blocks[0]['innerContent'] );
	}

	public function test_update_is_reversible_via_snapshot(): void {
		$this->seed_post( 8, array(
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => 'v1', 'innerContent' => array( 'v1' ), 'innerBlocks' => array() ),
		) );
		$original = $GLOBALS['_posts'][8]->post_content;

		$result = ( new Aura_Tool_Gutenberg_Update_Block() )->execute( array(
			'post_id'     => 8,
			'block_index' => 0,
			'inner_html'  => 'v2',
		) );
		$this->assertSame( 'v2', $this->blocks( 8 )[0]['innerHTML'] );

		( new Aura_Worker_Snapshots() )->restore( $result['snapshot_id'] );
		$this->assertSame( $original, $GLOBALS['_posts'][8]->post_content );
	}

	public function test_update_refuses_inner_html_on_a_block_with_nested_blocks(): void {
		$this->seed_post( 30, array(
			array(
				'blockName'   => 'core/columns',
				'attrs'       => array(),
				'innerHTML'   => '',
				'innerBlocks' => array( array( 'blockName' => 'core/column', 'innerBlocks' => array() ) ),
			),
		) );
		$original = $GLOBALS['_posts'][30]->post_content;

		$result = ( new Aura_Tool_Gutenberg_Update_Block() )->execute( array(
			'post_id'     => 30,
			'block_index' => 0,
			'inner_html'  => '<p>clobber</p>',
		) );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'nested blocks', $result['error'] );
		$this->assertSame( $original, $GLOBALS['_posts'][30]->post_content ); // untouched.
	}

	public function test_dry_run_surfaces_the_inner_html_change(): void {
		$this->seed_post( 31, array(
			array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => 'old', 'innerContent' => array( 'old' ), 'innerBlocks' => array() ),
		) );

		$preview = ( new Aura_Tool_Gutenberg_Update_Block() )->dry_run( array(
			'post_id'     => 31,
			'block_index' => 0,
			'inner_html'  => 'new',
		) );

		$this->assertSame( 'old', $preview['current']['inner_html'] );
		$this->assertSame( 'new', $preview['proposed']['inner_html'] );
	}

	public function test_post_restore_fails_closed_when_payload_missing(): void {
		$this->seed_post( 40, array( array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ) ) );
		$original = $GLOBALS['_posts'][40]->post_content;

		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_post( 40 );
		// Simulate a lost payload file.
		@unlink( $snap['snapshot']['payload_path'] );

		$result = $snaps->restore( $snap['snapshot']['id'] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'payload missing', $result['error'] );
		// The post must NOT be wiped to ''.
		$this->assertSame( $original, $GLOBALS['_posts'][40]->post_content );
	}

	public function test_update_out_of_range_index_errors(): void {
		$this->seed_post( 9, array( array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ) ) );
		$result = ( new Aura_Tool_Gutenberg_Update_Block() )->execute( array( 'post_id' => 9, 'block_index' => 7 ) );
		$this->assertStringContainsString( 'out of range', $result['error'] );
	}

	public function test_update_dry_run_does_not_write(): void {
		$this->seed_post( 10, array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => 'keep', 'innerContent' => array( 'keep' ), 'innerBlocks' => array() ) ) );
		$before = $GLOBALS['_posts'][10]->post_content;

		$preview = ( new Aura_Tool_Gutenberg_Update_Block() )->dry_run( array(
			'post_id'     => 10,
			'block_index' => 0,
			'inner_html'  => 'changed',
		) );

		$this->assertSame( 'core/paragraph', $preview['proposed']['name'] );
		$this->assertSame( $before, $GLOBALS['_posts'][10]->post_content ); // unchanged.
	}

	// --- create_page_from_blocks ----------------------------------------------

	public function test_create_defaults_to_draft(): void {
		$result = ( new Aura_Tool_Gutenberg_Create_Page() )->execute( array(
			'title'         => 'Hello',
			'blocks_markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
		) );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'page', $result['type'] );
		$this->assertSame( 'draft', $GLOBALS['_posts'][ $result['post_id'] ]->post_status );
	}

	public function test_create_unknown_status_falls_back_to_draft(): void {
		$result = ( new Aura_Tool_Gutenberg_Create_Page() )->execute( array(
			'title'         => 'X',
			'blocks_markup' => '',
			'status'        => 'launch-nukes',
		) );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_create_requires_title(): void {
		$result = ( new Aura_Tool_Gutenberg_Create_Page() )->execute( array( 'title' => '', 'blocks_markup' => 'x' ) );
		$this->assertStringContainsString( 'title', $result['error'] );
	}

	public function test_create_annotations_require_approval_not_destructive(): void {
		$ann = ( new Aura_Tool_Gutenberg_Create_Page() )->get_annotations();
		$this->assertTrue( $ann['requires_approval'] );
		$this->assertFalse( $ann['destructive'] );
	}

	// --- snapshot_post engine -------------------------------------------------

	public function test_snapshot_post_roundtrip(): void {
		$this->seed_post( 20, array( array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ) ) );
		$original = $GLOBALS['_posts'][20]->post_content;

		$snaps = new Aura_Worker_Snapshots();
		$snap  = $snaps->snapshot_post( 20 );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'post', $snap['snapshot']['kind'] );

		$GLOBALS['_posts'][20]->post_content = 'clobbered';
		$snaps->restore( $snap['snapshot']['id'] );
		$this->assertSame( $original, $GLOBALS['_posts'][20]->post_content );
	}

	public function test_snapshot_post_missing_errors(): void {
		$result = ( new Aura_Worker_Snapshots() )->snapshot_post( 404 );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}
}
