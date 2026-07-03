<?php
/**
 * Behavior tests for the scan_error_log tool.
 *
 * Writes a real debug.log inside WP_CONTENT_DIR (the tool's contained fallback
 * path) and asserts the level counting, recent-fatal capture, line clamping,
 * and the no-log path.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ScanErrorLogTest extends TestCase {

	private Aura_Tool_Scan_Error_Log $tool;
	private string $log;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Scan_Error_Log();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0777, true );
		}
		$this->log = WP_CONTENT_DIR . '/debug.log';
		$this->removeLog();
	}

	protected function tearDown(): void {
		$this->removeLog();
	}

	private function removeLog(): void {
		if ( file_exists( $this->log ) ) {
			unlink( $this->log );
		}
	}

	private function writeLog( string $contents ): void {
		file_put_contents( $this->log, $contents );
	}

	// -----------------------------------------------------------------------
	// no log present
	// -----------------------------------------------------------------------

	public function test_reports_not_found_when_no_log(): void {
		$out = $this->tool->execute( array() );
		$this->assertFalse( $out['log_found'] );
		$this->assertSame( 0, $out['analyzed_lines'] );
		$this->assertSame( 0, $out['counts']['fatal'] );
		$this->assertSame( array(), $out['recent_fatals'] );
	}

	// -----------------------------------------------------------------------
	// level counting
	// -----------------------------------------------------------------------

	public function test_counts_each_error_level(): void {
		$this->writeLog(
			"[time] PHP Fatal error:  boom\n"
			. "[time] PHP Warning:  careful\n"
			. "[time] PHP Notice:  fyi\n"
			. "[time] PHP Deprecated:  old\n"
			. "[time] WordPress database error something\n"
		);
		$c = $this->tool->execute( array() )['counts'];
		$this->assertSame( 1, $c['fatal'] );
		$this->assertSame( 1, $c['warning'] );
		$this->assertSame( 1, $c['notice'] );
		$this->assertSame( 1, $c['deprecated'] );
		$this->assertSame( 1, $c['database'] );
	}

	public function test_uncaught_exception_counts_as_fatal(): void {
		$this->writeLog( "[time] PHP message: Uncaught Error: nope\n" );
		$this->assertSame( 1, $this->tool->execute( array() )['counts']['fatal'] );
	}

	public function test_recent_fatals_capture_the_line(): void {
		$this->writeLog( "[time] PHP Fatal error:  call to undefined function foo()\n" );
		$out = $this->tool->execute( array() );
		$this->assertCount( 1, $out['recent_fatals'] );
		$this->assertStringContainsString( 'undefined function foo', $out['recent_fatals'][0] );
	}

	public function test_recent_fatals_capped_at_10(): void {
		$this->writeLog( str_repeat( "PHP Fatal error:  boom\n", 15 ) );
		$this->assertCount( 10, $this->tool->execute( array() )['recent_fatals'] );
	}

	public function test_blank_lines_are_ignored(): void {
		$this->writeLog( "PHP Fatal error:  x\n\n\n   \n" );
		$this->assertSame( 1, $this->tool->execute( array() )['analyzed_lines'] );
	}

	// -----------------------------------------------------------------------
	// lines clamping + metadata
	// -----------------------------------------------------------------------

	public function test_lines_param_limits_analyzed_lines(): void {
		$this->writeLog( str_repeat( "PHP Notice:  n\n", 50 ) );
		$out = $this->tool->execute( array( 'lines' => 5 ) );
		$this->assertLessThanOrEqual( 5, $out['analyzed_lines'] );
	}

	public function test_found_log_reports_basename(): void {
		$this->writeLog( "PHP Notice:  n\n" );
		$out = $this->tool->execute( array() );
		$this->assertTrue( $out['log_found'] );
		$this->assertSame( 'debug.log', $out['file'] );
		$this->assertArrayHasKey( 'size_human', $out );
	}

	public function test_is_declared_read_only(): void {
		$this->assertTrue( $this->tool->get_annotations()['read_only'] );
	}
}
