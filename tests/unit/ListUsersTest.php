<?php
/**
 * Behavior tests for the list_users tool.
 *
 * Drives execute() against the WP_User_Query / count_user_posts stubs: argument
 * building (clamping, role/search, wildcards, count_total), the shaped output
 * (per-user keys, admin flag, post counts), and the separate admin-count query.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ListUsersTest extends TestCase {

	private Aura_Tool_List_Users $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new Aura_Tool_List_Users();
	}

	/** Build a stub user object shaped like WP's WP_User. */
	private function user( int $id, string $login, array $roles, string $email = '', string $name = '' ): object {
		return (object) array(
			'ID'              => $id,
			'user_login'      => $login,
			'display_name'    => '' !== $name ? $name : ucfirst( $login ),
			'user_email'      => '' !== $email ? $email : $login . '@example.com',
			'roles'           => $roles,
			'user_registered' => '2024-01-02 03:04:05',
		);
	}

	/** The args the tool passed to the MAIN user query. */
	private function mainQueryArgs(): array {
		return $GLOBALS['_user_queries'][0];
	}

	// -----------------------------------------------------------------------
	// number / offset clamping + defaults
	// -----------------------------------------------------------------------

	public function test_number_defaults_to_50(): void {
		$this->tool->execute( array() );
		$this->assertSame( 50, $this->mainQueryArgs()['number'] );
	}

	public function test_number_is_clamped_to_200_max(): void {
		$this->tool->execute( array( 'number' => 999 ) );
		$this->assertSame( 200, $this->mainQueryArgs()['number'] );
	}

	public function test_number_is_clamped_to_1_min(): void {
		$this->tool->execute( array( 'number' => 0 ) );
		$this->assertSame( 1, $this->mainQueryArgs()['number'] );
	}

	public function test_negative_number_clamps_to_1(): void {
		$this->tool->execute( array( 'number' => -25 ) );
		$this->assertSame( 1, $this->mainQueryArgs()['number'] );
	}

	public function test_offset_defaults_to_0(): void {
		$this->tool->execute( array() );
		$this->assertSame( 0, $this->mainQueryArgs()['offset'] );
	}

	public function test_negative_offset_clamps_to_0(): void {
		$this->tool->execute( array( 'offset' => -10 ) );
		$this->assertSame( 0, $this->mainQueryArgs()['offset'] );
	}

	public function test_offset_passes_through_when_valid(): void {
		$this->tool->execute( array( 'offset' => 30 ) );
		$this->assertSame( 30, $this->mainQueryArgs()['offset'] );
	}

	// -----------------------------------------------------------------------
	// role / search argument building
	// -----------------------------------------------------------------------

	public function test_count_total_is_always_requested(): void {
		$this->tool->execute( array() );
		$this->assertTrue( $this->mainQueryArgs()['count_total'] );
	}

	public function test_role_filter_is_forwarded(): void {
		$this->tool->execute( array( 'role' => 'editor' ) );
		$this->assertSame( 'editor', $this->mainQueryArgs()['role'] );
	}

	public function test_no_role_key_when_role_omitted(): void {
		$this->tool->execute( array() );
		$this->assertArrayNotHasKey( 'role', $this->mainQueryArgs() );
	}

	public function test_search_is_wrapped_in_wildcards(): void {
		$this->tool->execute( array( 'search' => 'jane' ) );
		$this->assertSame( '*jane*', $this->mainQueryArgs()['search'] );
	}

	public function test_search_sets_search_columns(): void {
		$this->tool->execute( array( 'search' => 'jane' ) );
		$this->assertSame(
			array( 'user_login', 'user_email', 'display_name' ),
			$this->mainQueryArgs()['search_columns']
		);
	}

	public function test_no_search_key_when_search_omitted(): void {
		$this->tool->execute( array() );
		$this->assertArrayNotHasKey( 'search', $this->mainQueryArgs() );
	}

	// -----------------------------------------------------------------------
	// output shape
	// -----------------------------------------------------------------------

	public function test_output_has_top_level_keys(): void {
		$out = $this->tool->execute( array() );
		foreach ( array( 'total', 'admin_count', 'returned', 'users', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $out );
		}
	}

	public function test_total_and_admin_count_come_from_the_queries(): void {
		$GLOBALS['_users']       = array( $this->user( 1, 'admin', array( 'administrator' ) ) );
		$GLOBALS['_users_total'] = 7;
		$GLOBALS['_admin_total'] = 3;

		$out = $this->tool->execute( array() );

		$this->assertSame( 7, $out['total'] );
		$this->assertSame( 3, $out['admin_count'] );
		$this->assertSame( 1, $out['returned'] );
	}

	public function test_each_user_row_is_fully_shaped(): void {
		$GLOBALS['_users']       = array( $this->user( 5, 'jdoe', array( 'editor' ), 'j@x.com', 'Jane Doe' ) );
		$GLOBALS['_users_total'] = 1;
		$GLOBALS['_post_counts'] = array( 5 => 12 );

		$row = $this->tool->execute( array() )['users'][0];

		$this->assertSame( 5, $row['id'] );
		$this->assertSame( 'jdoe', $row['login'] );
		$this->assertSame( 'Jane Doe', $row['display_name'] );
		$this->assertSame( 'j@x.com', $row['email'] );
		$this->assertSame( array( 'editor' ), $row['roles'] );
		$this->assertFalse( $row['is_admin'] );
		$this->assertSame( '2024-01-02 03:04:05', $row['registered'] );
		$this->assertSame( 12, $row['post_count'] );
	}

	public function test_administrator_row_is_flagged_is_admin(): void {
		$GLOBALS['_users']       = array( $this->user( 9, 'root', array( 'administrator', 'editor' ) ) );
		$GLOBALS['_users_total'] = 1;

		$row = $this->tool->execute( array() )['users'][0];

		$this->assertTrue( $row['is_admin'] );
		$this->assertSame( array( 'administrator', 'editor' ), $row['roles'] );
	}

	public function test_empty_result_yields_no_users(): void {
		$out = $this->tool->execute( array() );
		$this->assertSame( array(), $out['users'] );
		$this->assertSame( 0, $out['returned'] );
	}

	public function test_generated_at_is_iso8601(): void {
		$out = $this->tool->execute( array() );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
			$out['generated_at']
		);
	}

	// -----------------------------------------------------------------------
	// annotations (behavior-adjacent contract)
	// -----------------------------------------------------------------------

	public function test_is_declared_read_only(): void {
		$a = $this->tool->get_annotations();
		$this->assertTrue( $a['read_only'] );
		$this->assertFalse( $a['requires_approval'] );
	}
}
