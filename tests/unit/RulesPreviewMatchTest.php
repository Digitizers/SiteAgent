<?php
/**
 * preview_match() is the decision enforce() applies, without enforce()'s
 * side effects (Aura spec 2026-09-25 §5.2).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RulesPreviewMatchTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	private function store( array $rules ): void {
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 1,
			'issued_at'   => '2026-09-25T00:00:00Z',
			'received_at' => time(),
			'rules'       => $rules,
		);
	}

	private function rule( string $effect, string $type, ?string $id ): array {
		return array( 'key' => 'rule/' . $effect, 'effect' => $effect, 'target' => array( 'type' => $type, 'id' => $id ), 'reason' => 'r', 'until' => null );
	}

	private function page7(): array {
		return array( array( 'type' => 'post', 'id' => '7' ), array( 'type' => 'page', 'id' => '7' ) );
	}

	public function effects(): array {
		return array(
			'block' => array( 'block' ),
			'warn'  => array( 'warn' ),
			'allow' => array( 'allow' ),
		);
	}

	/** @dataProvider effects */
	public function test_it_is_the_decision_enforce_applies( string $effect ): void {
		$this->store( array( $this->rule( $effect, 'page', '7' ) ) );
		$rule    = Aura_Worker_Rules::preview_match( $this->page7(), 'elementor-mcp/update-element' );
		$verdict = Aura_Worker_Rules::enforce( $this->page7(), 'elementor-mcp/update-element' );
		if ( 'allow' === $effect ) {
			$this->assertNull( $rule );
			$this->assertNull( $verdict['effect'] );
		} else {
			$this->assertSame( $effect, $rule['effect'] );
			$this->assertSame( $rule, $verdict['rule'] );
		}
	}

	public function test_it_widens_an_old_forks_page_write_as_enforce_does(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$this->store( array( $this->rule( 'block', 'custom_css', '7' ) ) );
		$this->assertSame( 'block', Aura_Worker_Rules::preview_match( $this->page7(), 'elementor-mcp/update-element' )['effect'] );
		$this->assertNull( Aura_Worker_Rules::preview_match( $this->page7(), 'update_post' ), 'only the fork is widened' );
	}

	public function test_it_records_nothing_and_fires_no_hook(): void {
		$this->store( array( $this->rule( 'block', 'page', '7' ), $this->rule( 'warn', 'site', null ) ) );
		Aura_Worker_Rules::preview_match( $this->page7(), 'elementor-mcp/update-element' );
		$this->assertEmpty( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return in_array( $a['tag'], array( 'aura_worker_rule_blocked', 'aura_worker_rule_warned' ), true );
		} ) );
		Aura_Worker_Rules::enforce( $this->page7(), 'elementor-mcp/update-element' );
		$blocked = array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return 'aura_worker_rule_blocked' === $a['tag'];
		} );
		$this->assertCount( 1, $blocked, 'the preview must not have consumed the dispatch\'s first record' );
	}
}
