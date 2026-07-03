<?php
/**
 * Behavior tests for the scan_broken_links tool.
 *
 * The tool does NO outbound HTTP: it scans recent post content for anchors and
 * classifies them — empty/anchor-only, dev/staging hosts, and internal links
 * that url_to_postid() can't resolve. External links are left unchecked.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class BrokenLinksTest extends TestCase {

	private Aura_Tool_Scan_Broken_Links $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Scan_Broken_Links();
		$GLOBALS['_home_url'] = 'https://example.com';
	}

	/** Seed one post (id 1) with the given content as the only scanned post. */
	private function content( string $html ): void {
		$GLOBALS['_wp_query_posts']  = array( 1 );
		$GLOBALS['_post_content'][1] = $html;
	}

	public function test_scanned_reflects_query_results(): void {
		$GLOBALS['_wp_query_posts'] = array( 1, 2, 3 );
		$this->assertSame( 3, $this->tool->execute( array() )['scanned'] );
	}

	public function test_counts_total_links(): void {
		$this->content( '<a href="https://example.com/a">a</a> <a href="#">b</a> <a href="mailto:x@y.z">c</a>' );
		$this->assertSame( 3, $this->tool->execute( array() )['links_total'] );
	}

	public function test_flags_empty_and_anchor_only_hrefs(): void {
		$this->content( '<a href="#">top</a> <a href="">nada</a>' );
		$out = $this->tool->execute( array() );
		$this->assertSame( 2, $out['empty_or_anchor']['count'] );
	}

	public function test_flags_dev_and_staging_links(): void {
		$this->content( '<a href="http://localhost/x">dev</a> <a href="https://staging.example.com/y">stg</a>' );
		$out = $this->tool->execute( array() );
		$this->assertSame( 2, $out['dev_staging_links']['count'] );
	}

	public function test_unresolved_internal_link_is_flagged(): void {
		$this->content( '<a href="https://example.com/missing">gone</a>' );
		// url_to_postid returns 0 (default) → unresolved.
		$out = $this->tool->execute( array() );
		$this->assertSame( 1, $out['unresolved_internal']['count'] );
	}

	public function test_resolvable_internal_link_is_not_flagged(): void {
		$this->content( '<a href="https://example.com/good">ok</a>' );
		$GLOBALS['_url_to_postid']['https://example.com/good'] = 42;
		$out = $this->tool->execute( array() );
		$this->assertSame( 0, $out['unresolved_internal']['count'] );
	}

	public function test_external_links_are_not_checked(): void {
		$this->content( '<a href="https://other-site.com/page">ext</a>' );
		$out = $this->tool->execute( array() );
		// Counted in links_total but never marked unresolved (no outbound HTTP).
		$this->assertSame( 1, $out['links_total'] );
		$this->assertSame( 0, $out['unresolved_internal']['count'] );
	}

	public function test_mailto_and_tel_are_ignored(): void {
		$this->content( '<a href="mailto:a@b.c">m</a> <a href="tel:+1">t</a>' );
		$out = $this->tool->execute( array() );
		$this->assertSame( 0, $out['unresolved_internal']['count'] );
		$this->assertSame( 0, $out['empty_or_anchor']['count'] );
	}

	public function test_media_file_links_are_skipped(): void {
		$this->content( '<a href="https://example.com/wp-content/x.pdf">doc</a>' );
		$out = $this->tool->execute( array() );
		$this->assertSame( 0, $out['unresolved_internal']['count'] );
	}

	public function test_sample_lists_cap_at_10(): void {
		$links = '';
		for ( $i = 0; $i < 15; $i++ ) {
			$links .= '<a href="#">x</a> ';
		}
		$this->content( $links );
		$out = $this->tool->execute( array() );
		$this->assertSame( 15, $out['empty_or_anchor']['count'] );
		$this->assertLessThanOrEqual( 10, count( $out['empty_or_anchor']['samples'] ) );
	}

	public function test_output_has_expected_keys(): void {
		$this->content( '<a href="#">x</a>' );
		$out = $this->tool->execute( array() );
		foreach ( array( 'scanned', 'links_total', 'empty_or_anchor', 'dev_staging_links', 'unresolved_internal', 'note', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $out );
		}
	}

	public function test_is_declared_read_only(): void {
		$this->assertTrue( $this->tool->get_annotations()['read_only'] );
	}
}
