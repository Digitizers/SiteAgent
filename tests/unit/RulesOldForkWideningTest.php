<?php
/**
 * An elementor-mcp older than 1.37.0 cannot say whether it wrote CSS, so its
 * page writes count as possible CSS for block/warn (spec 2026-09-24 §4.2).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesOldForkWideningTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	/**
	 * Copied verbatim from RulesEnforcementTest::install() — there is no
	 * `_set_rules_for_tests()` seam, so a test installs a ruleset the same
	 * way that suite does: straight into the option store.
	 *
	 * @param array $rules Rules.
	 */
	private function store( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 1,
			'issued_at'   => '2026-08-21T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	private function css_rule( string $effect, ?string $id ): array {
		return array( 'key' => 'rule/css', 'effect' => $effect, 'target' => array( 'type' => 'custom_css', 'id' => $id ), 'reason' => 'no CSS', 'until' => null );
	}

	public function test_fork_state_follows_the_loaded_version(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( false );
		$this->assertSame( 'absent', Aura_Worker_Rules::fork_css_state() );
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$this->assertSame( 'widened', Aura_Worker_Rules::fork_css_state() );
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0' );
		$this->assertSame( 'precise', Aura_Worker_Rules::fork_css_state() );
		Aura_Worker_Rules::_set_fork_version_for_tests( 'not-a-version' );
		$this->assertSame( 'widened', Aura_Worker_Rules::fork_css_state(), 'unreadable counts as old' );
	}

	public function test_an_old_fork_page_edit_is_blocked_by_a_css_block(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$this->store( array( $this->css_rule( 'block', '42' ) ) );
		$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'page', 'id' => '42' ), array( 'type' => 'post', 'id' => '42' ) ), 'elementor-mcp/update-element' );
		$this->assertSame( 'block', $v['effect'] );
	}

	public function test_an_old_fork_site_write_meets_an_id_rule_through_the_wildcard(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$this->store( array( $this->css_rule( 'warn', '42' ) ) );
		$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'site', 'id' => '*' ) ), 'elementor-mcp/build-page' );
		$this->assertSame( 'warn', $v['effect'] );
	}

	public function test_a_current_fork_is_not_widened(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0' );
		$this->store( array( $this->css_rule( 'block', '42' ) ) );
		$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'page', 'id' => '42' ) ), 'elementor-mcp/update-element' );
		$this->assertNull( $v['effect'] );
	}

	public function test_only_the_forks_own_abilities_are_widened(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$this->store( array( $this->css_rule( 'block', '42' ) ) );
		foreach ( array( 'update_page_block', 'elementor/manage-elements', 'content__update_post' ) as $tool ) {
			$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'page', 'id' => '42' ) ), $tool );
			$this->assertNull( $v['effect'], "{$tool} must not be widened" );
		}
	}

	public function test_widening_never_speaks_for_allow(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$this->store( array( $this->css_rule( 'allow', '42' ) ) );
		$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'page', 'id' => '42' ) ), 'elementor-mcp/update-element' );
		$this->assertNull( $v['effect'] );
	}
}
