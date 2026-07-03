<?php
/**
 * Behavior tests for the get_seo_meta tool.
 *
 * Plugin selection keys off permanent PHP constants (RANK_MATH_VERSION etc.),
 * so the active-plugin path is exercised for Rank Math (defined lazily inside
 * the tests that need it) and the no-plugin path runs in an isolated process
 * where no SEO constant is defined.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class GetSeoMetaTest extends TestCase {

	private Aura_Tool_Get_Seo_Meta $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Get_Seo_Meta();
	}

	/** Register Rank Math as the active SEO plugin (permanent for the process). */
	private function activateRankMath(): void {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			define( 'RANK_MATH_VERSION', '1.0.0' );
		}
	}

	private function seedPost( int $id ): void {
		$GLOBALS['_posts'][ $id ] = (object) array( 'ID' => $id );
	}

	// -----------------------------------------------------------------------
	// post resolution
	// -----------------------------------------------------------------------

	public function test_missing_post_id_errors(): void {
		$this->assertSame( 'Post not found.', $this->tool->execute( array() )['error'] );
	}

	public function test_zero_post_id_errors(): void {
		$this->assertSame( 'Post not found.', $this->tool->execute( array( 'post_id' => 0 ) )['error'] );
	}

	public function test_nonexistent_post_errors(): void {
		$this->assertSame( 'Post not found.', $this->tool->execute( array( 'post_id' => 999 ) )['error'] );
	}

	// -----------------------------------------------------------------------
	// Rank Math reads
	// -----------------------------------------------------------------------

	public function test_reads_rankmath_meta_from_the_right_keys(): void {
		$this->activateRankMath();
		$this->seedPost( 7 );
		$GLOBALS['_post_meta'][7] = array(
			'rank_math_title'         => 'My SEO Title',
			'rank_math_description'   => 'My meta description',
			'rank_math_focus_keyword' => 'widgets',
		);

		$out = $this->tool->execute( array( 'post_id' => 7 ) );

		$this->assertSame( 'rankmath', $out['plugin'] );
		$this->assertSame( 7, $out['post_id'] );
		$this->assertSame( 'My SEO Title', $out['title'] );
		$this->assertSame( 'My meta description', $out['description'] );
		$this->assertSame( 'widgets', $out['focus_keyword'] );
	}

	public function test_missing_meta_returns_empty_strings(): void {
		$this->activateRankMath();
		$this->seedPost( 8 );

		$out = $this->tool->execute( array( 'post_id' => 8 ) );

		$this->assertSame( '', $out['title'] );
		$this->assertSame( '', $out['description'] );
		$this->assertSame( '', $out['focus_keyword'] );
	}

	public function test_output_has_expected_keys(): void {
		$this->activateRankMath();
		$this->seedPost( 9 );
		$out = $this->tool->execute( array( 'post_id' => 9 ) );
		foreach ( array( 'plugin', 'post_id', 'title', 'description', 'focus_keyword' ) as $key ) {
			$this->assertArrayHasKey( $key, $out );
		}
	}

	// -----------------------------------------------------------------------
	// no supported plugin — isolated so no SEO constant is defined
	// -----------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_supported_plugin_reports_unsupported(): void {
		$tool = new Aura_Tool_Get_Seo_Meta();
		$GLOBALS['_posts'][3] = (object) array( 'ID' => 3 );

		$out = $tool->execute( array( 'post_id' => 3 ) );

		$this->assertNull( $out['plugin'] );
		$this->assertFalse( $out['supported'] );
	}

	// -----------------------------------------------------------------------
	// contract-adjacent
	// -----------------------------------------------------------------------

	public function test_post_id_is_a_required_param(): void {
		$this->assertTrue( $this->tool->get_parameters()['post_id']['required'] );
	}

	public function test_is_declared_read_only(): void {
		$this->assertTrue( $this->tool->get_annotations()['read_only'] );
	}
}
