<?php
/**
 * The custom_css target (spec 2026-09-24 §3): matching for block/warn/allow,
 * and the fail-closed evidence fields. Pure, no WordPress.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesCustomCssMatchTest extends TestCase {

	private function rule( string $effect, ?string $id = null ): array {
		return array(
			'key'    => 'rule/css',
			'effect' => $effect,
			'target' => array( 'type' => 'custom_css', 'id' => $id ),
			'reason' => 'no agent CSS',
			'until'  => null,
		);
	}

	private function css( string $id, array $extra = array() ): array {
		return array_merge( array( 'type' => 'custom_css', 'id' => $id ), $extra );
	}

	private function exact( string $id ): array {
		return $this->css( $id, array( 'precise' => true, 'css_only' => true ) );
	}

	public function test_block_with_an_id_matches_that_id_and_the_create_wildcard_only(): void {
		$rule = $this->rule( 'block', '42' );
		$this->assertNotNull( Aura_Worker_Rules::match( array( $this->css( '42' ) ), array( $rule ) ) );
		$this->assertNotNull( Aura_Worker_Rules::match( array( $this->css( '*' ) ), array( $rule ) ), 'create-time CSS may land anywhere' );
		$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '43' ) ), array( $rule ) ) );
	}

	public function test_an_id_less_or_star_rule_matches_any_css_touch(): void {
		foreach ( array( null, '*' ) as $id ) {
			$rule = $this->rule( 'warn', $id );
			foreach ( array( '42', '*', '7' ) as $t ) {
				$this->assertNotNull( Aura_Worker_Rules::match( array( $this->css( $t ) ), array( $rule ) ), "id-less rule missed custom_css:{$t}" );
			}
		}
	}

	public function test_an_empty_string_id_never_becomes_site_wide(): void {
		$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '42' ) ), array( $this->rule( 'block', '' ) ) ) );
	}

	public function test_a_css_rule_does_not_match_a_plain_page_edit(): void {
		// Additive: the page edit declares page:42 only; a CSS rule must not bite it.
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '42' ) ), array( $this->rule( 'block', '42' ) ) ) );
	}

	public function test_a_page_rule_still_matches_a_css_write_through_its_page_touch(): void {
		$page = array( 'key' => 'rule/p', 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '42' ), 'reason' => 'x', 'until' => null );
		$hit  = Aura_Worker_Rules::match( array( $this->css( '42' ), array( 'type' => 'page', 'id' => '42' ) ), array( $page ) );
		$this->assertSame( 'rule/p', $hit['key'] );
	}

	public function test_unknown_matches_css_block_and_warn_but_never_css_allow(): void {
		$unknown = array( array( 'type' => 'unknown', 'id' => '*' ) );
		$this->assertNotNull( Aura_Worker_Rules::match( $unknown, array( $this->rule( 'block', '42' ) ) ) );
		$this->assertNotNull( Aura_Worker_Rules::match( $unknown, array( $this->rule( 'warn' ) ) ) );
		$this->assertNull( Aura_Worker_Rules::match( $unknown, array( $this->rule( 'allow', '42' ) ) ) );
		$this->assertNull( Aura_Worker_Rules::match( $unknown, array( $this->rule( 'allow' ) ) ) );
	}

	public function test_allow_with_an_id_needs_a_precise_css_only_touch_on_that_id(): void {
		$rule = $this->rule( 'allow', '42' );
		$this->assertNotNull( Aura_Worker_Rules::match( array( $this->exact( '42' ) ), array( $rule ) ) );
		$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '42' ) ), array( $rule ) ), 'conservative touch' );
		$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '42', array( 'precise' => true ) ) ), array( $rule ) ), 'mixed call' );
		$this->assertNull( Aura_Worker_Rules::match( array( $this->exact( '43' ) ), array( $rule ) ) );
		$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '*', array( 'precise' => true, 'css_only' => true ) ) ), array( $rule ) ), '* is never precise' );
	}

	public function test_id_less_allow_admits_a_precise_css_only_touch_on_any_concrete_id(): void {
		foreach ( array( null, '*' ) as $id ) {
			$rule = $this->rule( 'allow', $id );
			$this->assertNotNull( Aura_Worker_Rules::match( array( $this->exact( '42' ) ), array( $rule ) ) );
			$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '*', array( 'precise' => true, 'css_only' => true ) ) ), array( $rule ) ) );
			$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '42' ) ), array( $rule ) ) );
		}
	}

	public function test_evidence_fields_count_only_as_literal_true(): void {
		$rule = $this->rule( 'allow', '42' );
		foreach ( array( 'true', 1, '1', null, false, array( true ) ) as $bad ) {
			$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '42', array( 'precise' => $bad, 'css_only' => true ) ) ), array( $rule ) ), 'precise=' . var_export( $bad, true ) );
			$this->assertNull( Aura_Worker_Rules::match( array( $this->css( '42', array( 'precise' => true, 'css_only' => $bad ) ) ), array( $rule ) ), 'css_only=' . var_export( $bad, true ) );
		}
		// Fields on a non-custom_css touch are ignored: a precise PAGE touch is just page:42.
		$page_allow = array( 'key' => 'rule/pa', 'effect' => 'allow', 'target' => array( 'type' => 'page', 'id' => '42' ), 'reason' => 'x', 'until' => null );
		$this->assertNotNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '42', 'precise' => true, 'css_only' => true ) ), array( $page_allow ) ) );
		$this->assertNull( Aura_Worker_Rules::match( array( array( 'type' => 'page', 'id' => '42', 'precise' => true, 'css_only' => true ) ), array( $rule ) ) );
	}

	public function test_a_non_digit_id_is_never_precise(): void {
		$this->assertNull( Aura_Worker_Rules::match( array( $this->css( 'abc', array( 'precise' => true, 'css_only' => true ) ) ), array( $this->rule( 'allow' ) ) ) );
	}

	public function test_block_still_outranks_a_css_allow(): void {
		$hit = Aura_Worker_Rules::match( array( $this->exact( '42' ) ), array( $this->rule( 'allow', '42' ), $this->rule( 'block' ) ) );
		$this->assertSame( 'block', $hit['effect'] );
	}

	public function test_a_site_freeze_still_catches_a_css_write(): void {
		$freeze = array( 'key' => 'rule/freeze', 'effect' => 'block', 'target' => array( 'type' => 'site', 'id' => null ), 'reason' => 'x', 'until' => null );
		$this->assertNotNull( Aura_Worker_Rules::match( array( $this->exact( '42' ) ), array( $freeze ) ) );
	}
}
