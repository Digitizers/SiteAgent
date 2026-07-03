<?php
/**
 * Behavior tests for the set_seo_meta tool (Rank Math active).
 *
 * Covers post resolution, per-field writes to the correct plugin meta keys with
 * sanitization, partial updates, the nothing-to-update guard, and the post-cache
 * flush that keeps a cached SEO title/description from being served stale.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SetSeoMetaTest extends TestCase {

	private Aura_Tool_Set_Seo_Meta $tool;

	protected function setUp(): void {
		sa_reset_state();
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			define( 'RANK_MATH_VERSION', '1.0.0' );
		}
		$this->tool = new Aura_Tool_Set_Seo_Meta();
	}

	private function seedPost( int $id ): void {
		$GLOBALS['_posts'][ $id ] = (object) array( 'ID' => $id );
	}

	private function meta( int $id, string $key ): string {
		return (string) ( $GLOBALS['_post_meta'][ $id ][ $key ] ?? '' );
	}

	// -----------------------------------------------------------------------
	// post resolution
	// -----------------------------------------------------------------------

	public function test_missing_post_id_errors(): void {
		$this->assertSame( 'Post not found.', $this->tool->execute( array( 'title' => 'x' ) )['error'] );
	}

	public function test_nonexistent_post_errors(): void {
		$this->assertSame( 'Post not found.', $this->tool->execute( array( 'post_id' => 404, 'title' => 'x' ) )['error'] );
	}

	// -----------------------------------------------------------------------
	// writes
	// -----------------------------------------------------------------------

	public function test_writes_all_three_fields_to_rankmath_keys(): void {
		$this->seedPost( 10 );
		$out = $this->tool->execute(
			array(
				'post_id'       => 10,
				'title'         => 'T',
				'description'   => 'D',
				'focus_keyword' => 'K',
			)
		);

		$this->assertSame( 'T', $this->meta( 10, 'rank_math_title' ) );
		$this->assertSame( 'D', $this->meta( 10, 'rank_math_description' ) );
		$this->assertSame( 'K', $this->meta( 10, 'rank_math_focus_keyword' ) );
		$this->assertSame( 'rankmath', $out['plugin'] );
		$this->assertSame( 10, $out['post_id'] );
		$this->assertEqualsCanonicalizing( array( 'title', 'description', 'focus_keyword' ), $out['updated'] );
	}

	public function test_partial_update_touches_only_supplied_fields(): void {
		$this->seedPost( 11 );
		$out = $this->tool->execute( array( 'post_id' => 11, 'title' => 'Only Title' ) );

		$this->assertSame( 'Only Title', $this->meta( 11, 'rank_math_title' ) );
		$this->assertSame( '', $this->meta( 11, 'rank_math_description' ) );
		$this->assertSame( array( 'title' ), $out['updated'] );
	}

	public function test_values_are_sanitized(): void {
		$this->seedPost( 12 );
		$this->tool->execute( array( 'post_id' => 12, 'title' => '  <b>Bold</b> Title  ' ) );
		// sanitize_text_field strips tags + trims.
		$this->assertSame( 'Bold Title', $this->meta( 12, 'rank_math_title' ) );
	}

	public function test_nothing_to_update_errors(): void {
		$this->seedPost( 13 );
		$out = $this->tool->execute( array( 'post_id' => 13 ) );
		$this->assertSame( 'Nothing to update — provide title, description, and/or focus_keyword.', $out['error'] );
	}

	public function test_flushes_post_cache_after_a_write(): void {
		$this->seedPost( 14 );
		$this->tool->execute( array( 'post_id' => 14, 'title' => 'T' ) );
		$this->assertContains( 14, $GLOBALS['_cleaned_post_cache'] );
	}

	public function test_no_cache_flush_when_nothing_written(): void {
		$this->seedPost( 15 );
		$this->tool->execute( array( 'post_id' => 15 ) );
		$this->assertNotContains( 15, $GLOBALS['_cleaned_post_cache'] );
	}

	// -----------------------------------------------------------------------
	// contract-adjacent
	// -----------------------------------------------------------------------

	public function test_is_not_a_read_only_tool(): void {
		// set_seo_meta writes post meta, so it is not read-only. (It currently
		// inherits the neutral requires_approval=false default rather than
		// declaring itself approval-required — flagged for the tool author; the
		// gateway still gates it as a write via the verb classifier.)
		$this->assertFalse( $this->tool->get_annotations()['read_only'] );
	}
}
