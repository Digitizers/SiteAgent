<?php
/**
 * Behavior tests for the perf_check tool.
 *
 * Drives the deterministic checks — autoload weight, active-plugin count,
 * expired transients, memory limit, PHP version — plus the score/metrics
 * aggregation. get_var scalars are queued in the tool's read order
 * (autoload bytes, then expired count); active_plugins comes from get_option.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class PerfCheckTest extends TestCase {

	private Aura_Tool_Perf_Check $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Perf_Check();
	}

	/** @param int $autoloadBytes get_var #1  @param int $expired get_var #2 */
	private function seed( int $autoloadBytes = 0, int $expired = 0, int $pluginCount = 0 ): void {
		$GLOBALS['_db_var_queue']            = array( $autoloadBytes, $expired );
		$GLOBALS['_options']['active_plugins'] = array_fill( 0, $pluginCount, 'p/p.php' );
	}

	private function statusOf( array $out, string $check ): string {
		foreach ( $out['findings'] as $f ) {
			if ( $f['check'] === $check ) {
				return $f['status'];
			}
		}
		return 'MISSING';
	}

	// -----------------------------------------------------------------------
	// structure + aggregation
	// -----------------------------------------------------------------------

	public function test_runs_eight_checks(): void {
		$this->seed();
		$out = $this->tool->execute( array() );
		$this->assertSame( 8, $out['total'] );
		$this->assertCount( 8, $out['findings'] );
	}

	public function test_output_has_expected_keys(): void {
		$this->seed();
		$out = $this->tool->execute( array() );
		foreach ( array( 'score', 'passed', 'total', 'findings', 'metrics', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $out );
		}
	}

	public function test_each_finding_is_shaped(): void {
		$this->seed();
		foreach ( $this->tool->execute( array() )['findings'] as $f ) {
			$this->assertArrayHasKey( 'check', $f );
			$this->assertContains( $f['status'], array( 'ok', 'warning', 'fail' ) );
			$this->assertArrayHasKey( 'message', $f );
		}
	}

	public function test_score_is_passed_over_total_as_percent(): void {
		$this->seed();
		$out = $this->tool->execute( array() );
		$this->assertSame( (int) round( ( $out['passed'] / $out['total'] ) * 100 ), $out['score'] );
	}

	public function test_passed_counts_only_ok_findings(): void {
		$this->seed();
		$out      = $this->tool->execute( array() );
		$expected = 0;
		foreach ( $out['findings'] as $f ) {
			if ( 'ok' === $f['status'] ) {
				++$expected;
			}
		}
		$this->assertSame( $expected, $out['passed'] );
	}

	// -----------------------------------------------------------------------
	// metrics
	// -----------------------------------------------------------------------

	public function test_metrics_reflect_inputs(): void {
		$this->seed( 2048, 9, 7 );
		$m = $this->tool->execute( array() )['metrics'];
		$this->assertSame( 2048, $m['autoload_bytes'] );
		$this->assertSame( 7, $m['active_plugins'] );
		$this->assertSame( 9, $m['expired_transients'] );
		$this->assertSame( '256M', $m['memory_limit'] );
	}

	// -----------------------------------------------------------------------
	// autoload weight thresholds (>3MB fail, >1MB warning, else ok)
	// -----------------------------------------------------------------------

	public function test_autoload_over_3mb_fails(): void {
		$this->seed( 4 * 1048576 );
		$this->assertSame( 'fail', $this->statusOf( $this->tool->execute( array() ), 'autoload_weight' ) );
	}

	public function test_autoload_over_1mb_warns(): void {
		$this->seed( 2 * 1048576 );
		$this->assertSame( 'warning', $this->statusOf( $this->tool->execute( array() ), 'autoload_weight' ) );
	}

	public function test_autoload_under_1mb_is_ok(): void {
		$this->seed( 200 * 1024 );
		$this->assertSame( 'ok', $this->statusOf( $this->tool->execute( array() ), 'autoload_weight' ) );
	}

	// -----------------------------------------------------------------------
	// plugin count (>40 warning) + expired transients (>100 warning)
	// -----------------------------------------------------------------------

	public function test_high_plugin_count_warns(): void {
		$this->seed( 0, 0, 45 );
		$this->assertSame( 'warning', $this->statusOf( $this->tool->execute( array() ), 'plugin_count' ) );
	}

	public function test_modest_plugin_count_is_ok(): void {
		$this->seed( 0, 0, 12 );
		$this->assertSame( 'ok', $this->statusOf( $this->tool->execute( array() ), 'plugin_count' ) );
	}

	public function test_many_expired_transients_warns(): void {
		$this->seed( 0, 150 );
		$this->assertSame( 'warning', $this->statusOf( $this->tool->execute( array() ), 'expired_transients' ) );
	}

	public function test_few_expired_transients_is_ok(): void {
		$this->seed( 0, 5 );
		$this->assertSame( 'ok', $this->statusOf( $this->tool->execute( array() ), 'expired_transients' ) );
	}

	// -----------------------------------------------------------------------
	// deterministic environment checks + annotation
	// -----------------------------------------------------------------------

	public function test_memory_limit_256m_is_ok(): void {
		$this->seed();
		$this->assertSame( 'ok', $this->statusOf( $this->tool->execute( array() ), 'memory_limit' ) );
	}

	public function test_is_declared_read_only(): void {
		$this->assertTrue( $this->tool->get_annotations()['read_only'] );
	}
}
