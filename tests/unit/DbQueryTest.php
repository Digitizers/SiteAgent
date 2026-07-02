<?php
/**
 * Tests for Aura_Power_Tool_Db_Query — read-only SQL guardrails.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class DbQueryTest extends TestCase {

	private Aura_Power_Tool_Db_Query $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Power_Tool_Db_Query();
	}

	public function test_requires_approval(): void {
		$ann = $this->tool->get_annotations();
		$this->assertTrue( $ann['requires_approval'] );
		$this->assertTrue( $ann['read_only'] );
	}

	public function test_select_returns_rows(): void {
		$GLOBALS['_db_rows'] = array(
			array( 'ID' => 1, 'post_title' => 'A' ),
			array( 'ID' => 2, 'post_title' => 'B' ),
		);

		$result = $this->tool->execute( array( 'query' => 'SELECT ID, post_title FROM wp_posts' ) );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 2, $result['row_count'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( 'SELECT ID, post_title FROM wp_posts', $GLOBALS['wpdb']->last_query );
	}

	public function test_limit_caps_rows_and_flags_truncation(): void {
		$GLOBALS['_db_rows'] = array( array( 'n' => 1 ), array( 'n' => 2 ), array( 'n' => 3 ) );

		$result = $this->tool->execute( array( 'query' => 'SELECT n FROM wp_x', 'limit' => 2 ) );

		$this->assertSame( 2, $result['row_count'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_trailing_semicolon_is_allowed(): void {
		$GLOBALS['_db_rows'] = array( array( 'ok' => 1 ) );
		$result = $this->tool->execute( array( 'query' => 'SELECT 1;' ) );
		$this->assertArrayNotHasKey( 'error', $result );
	}

	/**
	 * @dataProvider write_queries
	 */
	public function test_rejects_non_read_queries( string $sql ): void {
		$result = $this->tool->execute( array( 'query' => $sql ) );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'read-only', $result['error'] );
	}

	public static function write_queries(): array {
		return array(
			'update' => array( 'UPDATE wp_options SET option_value = "x" WHERE option_name = "y"' ),
			'delete' => array( 'DELETE FROM wp_posts WHERE ID = 1' ),
			'insert' => array( 'INSERT INTO wp_posts (post_title) VALUES ("x")' ),
			'drop'   => array( 'DROP TABLE wp_posts' ),
			'alter'  => array( 'ALTER TABLE wp_posts ADD COLUMN x INT' ),
		);
	}

	public function test_rejects_stacked_statements(): void {
		$result = $this->tool->execute( array( 'query' => 'SELECT 1; DROP TABLE wp_posts' ) );
		$this->assertStringContainsString( 'Multiple statements', $result['error'] );
	}

	public function test_rejects_file_access_functions(): void {
		$result = $this->tool->execute( array( 'query' => 'SELECT * INTO OUTFILE "/tmp/x" FROM wp_posts' ) );
		$this->assertStringContainsString( 'File access', $result['error'] );
	}

	public function test_rejects_empty_query(): void {
		$result = $this->tool->execute( array( 'query' => '   ' ) );
		$this->assertStringContainsString( 'Empty', $result['error'] );
	}

	public function test_surfaces_wpdb_error(): void {
		// get_results returning a non-array signals a DB error.
		$GLOBALS['_db_rows']         = null;
		$GLOBALS['wpdb']->last_error = 'Unknown column';

		$result = $this->tool->execute( array( 'query' => 'SELECT bogus FROM wp_posts' ) );
		$this->assertStringContainsString( 'Unknown column', $result['error'] );
	}
}
