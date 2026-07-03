<?php
/**
 * Behavior tests for the clear_caches tool.
 *
 * The tool probes for known cache layers (function/class/action presence) and
 * always fires the aura_worker_clear_caches extension hook. These assert the
 * output contract, the count/list consistency, detection of a present layer,
 * and that the extension action is dispatched.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ClearCachesTest extends TestCase {

	private Aura_Tool_Clear_Caches $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Clear_Caches();
	}

	private function tags(): array {
		return array_map(
			static function ( $a ) {
				return $a['tag'];
			},
			$GLOBALS['_did_actions']
		);
	}

	public function test_output_has_expected_shape(): void {
		$out = $this->tool->execute( array() );
		$this->assertArrayHasKey( 'cleared', $out );
		$this->assertIsArray( $out['cleared'] );
		$this->assertArrayHasKey( 'count', $out );
		$this->assertArrayHasKey( 'generated_at', $out );
	}

	public function test_count_matches_cleared_list(): void {
		$out = $this->tool->execute( array() );
		$this->assertSame( count( $out['cleared'] ), $out['count'] );
	}

	public function test_fires_the_extension_action(): void {
		$this->tool->execute( array() );
		$this->assertContains( 'aura_worker_clear_caches', $this->tags() );
	}

	public function test_detects_a_present_cache_layer(): void {
		// Register a LiteSpeed purge action so has_action() reports it present.
		$GLOBALS['_registered_actions']['litespeed_purge_all'] = true;
		$out = $this->tool->execute( array() );
		$this->assertContains( 'litespeed_cache', $out['cleared'] );
		$this->assertContains( 'litespeed_purge_all', $this->tags() );
	}

	public function test_takes_no_parameters(): void {
		$this->assertSame( array(), $this->tool->get_parameters() );
	}
}
