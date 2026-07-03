<?php
/**
 * Behavior tests for the get_database_info tool.
 *
 * Drives execute() against the queued $wpdb stub: table_limit clamping, size
 * aggregation, the shaped largest-tables / autoload / expired-transient output.
 *
 * Query order the tool issues (so the stub queues line up):
 *   get_results #1 → tables;  get_var #1 → autoload total bytes;
 *   get_var #2 → autoload option count;  get_results #2 → heaviest options;
 *   get_var #3 → expired transient count.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class DatabaseInfoTest extends TestCase {

	private Aura_Tool_Database_Info $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_Database_Info();
	}

	/** @return object A table row shaped like the information_schema SELECT. */
	private function table( string $name, int $bytes, int $rows = 0 ): object {
		return (object) array(
			'name'       => $name,
			'size_bytes' => $bytes,
			'row_count'  => $rows,
		);
	}

	/** Seed the two result-sets + three scalars the tool reads, in order. */
	private function seed( array $tables, int $autoloadBytes = 0, int $autoloadCount = 0, array $heaviest = array(), int $expired = 0 ): void {
		$GLOBALS['_db_results_queue'] = array( $tables, $heaviest );
		$GLOBALS['_db_var_queue']     = array( $autoloadBytes, $autoloadCount, $expired );
	}

	private function lastMainArgs(): array {
		return $GLOBALS['_user_queries'][0] ?? array();
	}

	// -----------------------------------------------------------------------
	// table_limit clamping
	// -----------------------------------------------------------------------

	public function test_table_limit_defaults_to_10(): void {
		$tables = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$tables[] = $this->table( "t$i", 100 );
		}
		$this->seed( $tables );
		$out = $this->tool->execute( array() );
		$this->assertCount( 10, $out['largest_tables'] );
	}

	public function test_table_limit_is_honored(): void {
		$this->seed( array( $this->table( 'a', 5 ), $this->table( 'b', 4 ), $this->table( 'c', 3 ) ) );
		$out = $this->tool->execute( array( 'table_limit' => 2 ) );
		$this->assertCount( 2, $out['largest_tables'] );
	}

	public function test_table_limit_clamps_to_1_minimum(): void {
		$this->seed( array( $this->table( 'a', 5 ), $this->table( 'b', 4 ) ) );
		$out = $this->tool->execute( array( 'table_limit' => 0 ) );
		$this->assertCount( 1, $out['largest_tables'] );
	}

	public function test_returns_all_tables_when_fewer_than_limit(): void {
		$this->seed( array( $this->table( 'a', 5 ), $this->table( 'b', 4 ) ) );
		$out = $this->tool->execute( array( 'table_limit' => 25 ) );
		$this->assertCount( 2, $out['largest_tables'] );
	}

	// -----------------------------------------------------------------------
	// sizes + counts
	// -----------------------------------------------------------------------

	public function test_total_size_bytes_sums_every_table(): void {
		// Sum spans ALL tables, not just the ones returned in largest_tables.
		$this->seed( array( $this->table( 'a', 500 ), $this->table( 'b', 300 ), $this->table( 'c', 200 ) ) );
		$out = $this->tool->execute( array( 'table_limit' => 1 ) );
		$this->assertSame( 1000, $out['total_size_bytes'] );
		$this->assertCount( 1, $out['largest_tables'] );
	}

	public function test_table_count_reflects_all_tables(): void {
		$this->seed( array( $this->table( 'a', 1 ), $this->table( 'b', 1 ), $this->table( 'c', 1 ) ) );
		$out = $this->tool->execute( array() );
		$this->assertSame( 3, $out['table_count'] );
	}

	public function test_largest_table_rows_are_fully_shaped(): void {
		$this->seed( array( $this->table( 'wp_posts', 2048, 42 ) ) );
		$row = $this->tool->execute( array() )['largest_tables'][0];
		$this->assertSame( 'wp_posts', $row['name'] );
		$this->assertSame( 2048, $row['size_bytes'] );
		$this->assertSame( 42, $row['rows'] );
		$this->assertArrayHasKey( 'size_human', $row );
	}

	// -----------------------------------------------------------------------
	// autoload + expired transients
	// -----------------------------------------------------------------------

	public function test_autoload_block_is_shaped(): void {
		$heaviest = array(
			(object) array( 'name' => 'cron', 'bytes' => 4096 ),
			(object) array( 'name' => 'rewrite_rules', 'bytes' => 2048 ),
		);
		$this->seed( array( $this->table( 'a', 1 ) ), 6144, 2, $heaviest );
		$auto = $this->tool->execute( array() )['autoload'];

		$this->assertSame( 6144, $auto['total_bytes'] );
		$this->assertSame( 2, $auto['option_count'] );
		$this->assertCount( 2, $auto['heaviest'] );
		$this->assertSame( 'cron', $auto['heaviest'][0]['name'] );
		$this->assertSame( 4096, $auto['heaviest'][0]['bytes'] );
		$this->assertArrayHasKey( 'human', $auto['heaviest'][0] );
	}

	public function test_expired_transients_is_reported(): void {
		$this->seed( array( $this->table( 'a', 1 ) ), 0, 0, array(), 7 );
		$out = $this->tool->execute( array() );
		$this->assertSame( 7, $out['expired_transients'] );
	}

	// -----------------------------------------------------------------------
	// identity + output contract
	// -----------------------------------------------------------------------

	public function test_reports_database_name_and_prefix(): void {
		$this->seed( array( $this->table( 'a', 1 ) ) );
		$out = $this->tool->execute( array() );
		$this->assertSame( DB_NAME, $out['database'] );
		$this->assertSame( 'wp_', $out['table_prefix'] );
	}

	public function test_output_has_all_top_level_keys(): void {
		$this->seed( array( $this->table( 'a', 1 ) ) );
		$out = $this->tool->execute( array() );
		foreach ( array( 'database', 'table_prefix', 'table_count', 'total_size_bytes', 'total_size_human', 'largest_tables', 'autoload', 'expired_transients', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $out );
		}
	}

	public function test_empty_database_is_handled(): void {
		$this->seed( array() );
		$out = $this->tool->execute( array() );
		$this->assertSame( 0, $out['table_count'] );
		$this->assertSame( 0, $out['total_size_bytes'] );
		$this->assertSame( array(), $out['largest_tables'] );
	}

	public function test_is_declared_read_only(): void {
		$a = $this->tool->get_annotations();
		$this->assertTrue( $a['read_only'] );
		$this->assertFalse( $a['destructive'] );
	}
}
