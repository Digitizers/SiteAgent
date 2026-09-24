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
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0', true );
		$this->assertSame( 'precise', Aura_Worker_Rules::fork_css_state() );
		Aura_Worker_Rules::_set_fork_version_for_tests( 'not-a-version', true );
		$this->assertSame( 'widened', Aura_Worker_Rules::fork_css_state(), 'unreadable counts as old' );
	}

	/**
	 * Final review I1: `precise` needs the capability, not just the number.
	 * A 1.37.0 that ships no Elementor_MCP_Rules::css_touches() is widened;
	 * so is an older fork that somehow has it.
	 */
	public function test_precise_needs_css_touches_as_well_as_the_version(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0', false );
		$this->assertSame( 'widened', Aura_Worker_Rules::fork_css_state() );
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1', true );
		$this->assertSame( 'widened', Aura_Worker_Rules::fork_css_state() );
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.38.0', true );
		$this->assertSame( 'precise', Aura_Worker_Rules::fork_css_state() );
	}

	public function test_absent_capability_derives_from_the_loaded_code(): void {
		// null = ask method_exists(); this process defines no Elementor_MCP_Rules.
		$this->assertFalse( class_exists( 'Elementor_MCP_Rules', false ) );
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0' );
		$this->assertSame( 'widened', Aura_Worker_Rules::fork_css_state() );
	}

	public function test_a_1_37_fork_without_css_touches_is_still_widened(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0', false );
		$this->store( array( $this->css_rule( 'block', '42' ) ) );
		$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'page', 'id' => '42' ) ), 'elementor-mcp/update-element' );
		$this->assertSame( 'block', $v['effect'] );
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
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0', true );
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

	/**
	 * Non-vacuous (Task 2 review minor 1): enforce() drops every allow
	 * winner anyway, so this asks match() itself — the matcher the door
	 * uses for allow. The widened touch set is the one enforce() builds.
	 */
	public function test_widening_never_speaks_for_allow(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$widen   = new ReflectionMethod( 'Aura_Worker_Rules', 'widen_for_old_fork' );
		if ( PHP_VERSION_ID < 80100 ) {
			$widen->setAccessible( true ); // a no-op since 8.1, deprecated in 8.5
		}
		$widened = $widen->invoke( null, array( array( 'type' => 'page', 'id' => '42' ) ), 'elementor-mcp/update-element' );
		$this->assertContains( array( 'type' => 'custom_css', 'id' => '42' ), $widened, 'the old fork IS widened' );

		$allow = array( $this->css_rule( 'allow', '42' ) );
		// Control: a precise CSS-only touch does satisfy this allow…
		$this->assertSame( 'allow', Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '42' ), array( 'type' => 'custom_css', 'id' => '42', 'precise' => true, 'css_only' => true ) ), $allow )['effect'] );
		// …the widened touch never does.
		$this->assertNull( Aura_Worker_Rules::match( $widened, $allow ) );

		// Paired with a warn: the widened touch reaches the warn, never the allow.
		$this->store( array( $this->css_rule( 'allow', '42' ), array( 'key' => 'rule/css-warn', 'effect' => 'warn', 'target' => array( 'type' => 'custom_css', 'id' => '42' ), 'reason' => 'careful', 'until' => null ) ) );
		$v = Aura_Worker_Rules::enforce( array( array( 'type' => 'page', 'id' => '42' ) ), 'elementor-mcp/update-element' );
		$this->assertSame( 'warn', $v['effect'] );
		$this->assertSame( 'rule/css-warn', $v['rule']['key'] );
		Aura_Worker_Rules::reset_records();
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.37.0', true );
		$this->assertNull( Aura_Worker_Rules::enforce( array( array( 'type' => 'page', 'id' => '42' ) ), 'elementor-mcp/update-element' )['effect'], 'a current fork declares nothing here' );
	}
}
