<?php
/**
 * Behavior tests for the cleanup_transients tool.
 *
 * The tool counts expired transient rows (get_var) before and after calling
 * core's delete_expired_transients(); the queued $wpdb scalars stand in for the
 * two counts so the before/after/deleted arithmetic can be asserted.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class CleanupTransientsTest extends TestCase {

	private Aura_Tool_Cleanup_Transients $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Cleanup_Transients();
	}

	/** Queue the before-count then the after-count the tool reads via get_var. */
	private function counts( int $before, int $after ): void {
		$GLOBALS['_db_var_queue'] = array( $before, $after );
	}

	public function test_reports_before_after_and_deleted(): void {
		$this->counts( 5, 1 );
		$out = $this->tool->execute( array() );
		$this->assertSame( 5, $out['expired_before'] );
		$this->assertSame( 1, $out['expired_after'] );
		$this->assertSame( 4, $out['deleted'] );
	}

	public function test_deleted_is_never_negative(): void {
		// If the after-count somehow exceeds before, deleted floors at 0.
		$this->counts( 2, 5 );
		$this->assertSame( 0, $this->tool->execute( array() )['deleted'] );
	}

	public function test_zero_when_nothing_expired(): void {
		$this->counts( 0, 0 );
		$out = $this->tool->execute( array() );
		$this->assertSame( 0, $out['expired_before'] );
		$this->assertSame( 0, $out['deleted'] );
	}

	public function test_invokes_core_delete_expired_transients(): void {
		$this->counts( 3, 0 );
		$this->tool->execute( array() );
		$this->assertTrue( $GLOBALS['_did_delete_expired'] );
	}

	public function test_output_has_expected_keys(): void {
		$this->counts( 1, 0 );
		$out = $this->tool->execute( array() );
		foreach ( array( 'expired_before', 'expired_after', 'deleted', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $out );
		}
	}

	public function test_takes_no_parameters(): void {
		$this->assertSame( array(), $this->tool->get_parameters() );
	}
}
