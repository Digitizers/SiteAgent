<?php
/**
 * Behavior tests for the scan_seo tool.
 *
 * Drives the site-level checks (indexability, permalinks, title) and the
 * per-post content sample (excerpt / featured image / thin content), plus the
 * score aggregation. External SEO-plugin sitemap detection is env-dependent
 * (permanent constants) so it isn't asserted here.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ScanSeoTest extends TestCase {

	private Aura_Tool_Scan_Seo $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Scan_Seo();
		// Healthy site defaults; individual tests override.
		$GLOBALS['_options']['blog_public']         = 1;
		$GLOBALS['_options']['permalink_structure'] = '/%postname%/';
		$GLOBALS['_bloginfo']['name']               = 'My Site';
	}

	private function statusOf( array $out, string $check ): string {
		foreach ( $out['findings'] as $f ) {
			if ( $f['check'] === $check ) {
				return $f['status'];
			}
		}
		return 'MISSING';
	}

	/** Seed one post with the given excerpt / word-count / thumbnail. */
	private function post( int $id, string $excerpt, int $words, bool $thumb ): void {
		$GLOBALS['_wp_query_posts'][]      = $id;
		$GLOBALS['_posts'][ $id ]          = (object) array( 'ID' => $id, 'post_excerpt' => $excerpt );
		$GLOBALS['_post_content'][ $id ]   = trim( str_repeat( 'word ', $words ) );
		$GLOBALS['_thumbnails'][ $id ]     = $thumb;
	}

	// -----------------------------------------------------------------------
	// site-level checks
	// -----------------------------------------------------------------------

	public function test_indexable_site_passes_visibility(): void {
		$this->assertSame( 'ok', $this->statusOf( $this->tool->execute( array() ), 'search_engine_visibility' ) );
	}

	public function test_noindex_site_fails_visibility(): void {
		$GLOBALS['_options']['blog_public'] = 0;
		$this->assertSame( 'fail', $this->statusOf( $this->tool->execute( array() ), 'search_engine_visibility' ) );
	}

	public function test_pretty_permalinks_pass(): void {
		$this->assertSame( 'ok', $this->statusOf( $this->tool->execute( array() ), 'permalinks' ) );
	}

	public function test_plain_permalinks_warn(): void {
		$GLOBALS['_options']['permalink_structure'] = '';
		$this->assertSame( 'warning', $this->statusOf( $this->tool->execute( array() ), 'permalinks' ) );
	}

	public function test_empty_site_title_warns(): void {
		$GLOBALS['_bloginfo']['name'] = '';
		$this->assertSame( 'warning', $this->statusOf( $this->tool->execute( array() ), 'site_title' ) );
	}

	// -----------------------------------------------------------------------
	// content sample
	// -----------------------------------------------------------------------

	public function test_clean_content_sample_passes(): void {
		$this->post( 1, 'A good excerpt', 400, true );
		$out = $this->tool->execute( array() );
		$this->assertSame( 'ok', $this->statusOf( $out, 'thin_content' ) );
		$this->assertSame( 'ok', $this->statusOf( $out, 'featured_images' ) );
		$this->assertSame( 'ok', $this->statusOf( $out, 'excerpts' ) );
		$this->assertSame( 0, $out['content_sample']['missing_excerpt'] );
	}

	public function test_thin_content_is_flagged(): void {
		$this->post( 1, 'ok', 50, true );
		$out = $this->tool->execute( array() );
		$this->assertSame( 'warning', $this->statusOf( $out, 'thin_content' ) );
		$this->assertSame( 1, $out['content_sample']['thin_content'] );
	}

	public function test_missing_featured_image_is_flagged(): void {
		$this->post( 1, 'ok', 400, false );
		$out = $this->tool->execute( array() );
		$this->assertSame( 'warning', $this->statusOf( $out, 'featured_images' ) );
		$this->assertSame( 1, $out['content_sample']['missing_featured_image'] );
	}

	public function test_missing_excerpt_is_flagged(): void {
		$this->post( 1, '', 400, true );
		$out = $this->tool->execute( array() );
		$this->assertSame( 'warning', $this->statusOf( $out, 'excerpts' ) );
		$this->assertSame( 1, $out['content_sample']['missing_excerpt'] );
	}

	public function test_audited_reflects_sample_size(): void {
		$this->post( 1, 'x', 400, true );
		$this->post( 2, 'y', 400, true );
		$this->assertSame( 2, $this->tool->execute( array() )['content_sample']['audited'] );
	}

	// -----------------------------------------------------------------------
	// aggregation + contract
	// -----------------------------------------------------------------------

	public function test_score_is_passed_over_total_percent(): void {
		$out = $this->tool->execute( array() );
		$this->assertSame( (int) round( ( $out['passed'] / $out['total'] ) * 100 ), $out['score'] );
	}

	public function test_output_has_expected_keys(): void {
		$out = $this->tool->execute( array() );
		foreach ( array( 'score', 'passed', 'total', 'findings', 'content_sample', 'note', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $out );
		}
	}

	public function test_is_declared_read_only(): void {
		$this->assertTrue( $this->tool->get_annotations()['read_only'] );
	}
}
