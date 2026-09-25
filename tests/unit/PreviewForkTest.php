<?php
/**
 * tools/preview answers an elementor-mcp fork ability with the fork's own
 * declared touches (Aura spec 2026-09-25 §5).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class PreviewForkTest extends TestCase {

	/** @var array[] Every (name, input) the fake fork was asked. */
	private $asked = array();

	protected function setUp(): void {
		sa_reset_state();
		$this->asked = array();
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

	/** A fake fork answering $answer (or running it, if callable). */
	private function fork( $answer ): void {
		Aura_Worker_Tools::_set_fork_declarer_for_tests(
			function ( string $mcp_tool, array $input ) use ( $answer ) {
				$this->asked[] = array( $mcp_tool, $input );
				return is_callable( $answer ) ? $answer() : $answer;
			}
		);
	}

	private function page7_css(): array {
		return array(
			array( 'type' => 'post', 'id' => '7' ),
			array( 'type' => 'page', 'id' => '7' ),
			array( 'type' => 'custom_css', 'id' => '7', 'precise' => true, 'css_only' => true ),
		);
	}

	private function preview( array $params = array( 'post_id' => 7 ) ): array {
		return ( new Aura_Worker_Tools() )->preview_tool( 'elementor-mcp-update-page-settings', $params );
	}

	private function assert_unknown( array $res ): void {
		$this->assertSame( array( 'success' => false, 'error' => 'Unknown tool: elementor-mcp-update-page-settings' ), $res );
	}

	public function effects(): array {
		return array( 'block' => array( 'block' ), 'warn' => array( 'warn' ) );
	}

	/** @dataProvider effects */
	public function test_a_fork_write_gets_its_touches_and_the_rule_enforcement_would_apply( string $effect ): void {
		$this->store( array( $this->rule( $effect, 'custom_css', '7' ) ) );
		$this->fork( array( 'ability' => 'elementor-mcp/update-page-settings', 'touches' => $this->page7_css() ) );
		$res = $this->preview();
		$this->assertSame(
			array(
				'success'    => true,
				'supported'  => false,
				'preview'    => null,
				'touches'    => $this->page7_css(),
				'rule_match' => array( 'key' => 'rule/' . $effect, 'effect' => $effect, 'reason' => 'r' ),
			),
			$res
		);
		$this->assertEmpty( array_filter( $GLOBALS['_did_actions'], static function ( $a ) {
			return in_array( $a['tag'], array( 'aura_worker_rule_blocked', 'aura_worker_rule_warned' ), true );
		} ), 'a preview fired a rule hook' );
	}

	public function test_an_allow_winner_is_no_rule_as_enforcement_ignores_it(): void {
		$this->store( array( $this->rule( 'allow', 'custom_css', '7' ) ) );
		$this->fork( array( 'ability' => 'elementor-mcp/update-page-settings', 'touches' => $this->page7_css() ) );
		$res = $this->preview();
		$this->assertTrue( $res['success'] );
		$this->assertNull( $res['rule_match'] );
	}

	public function test_the_fork_path_widens_by_the_ability_name(): void {
		Aura_Worker_Rules::_set_fork_version_for_tests( '1.36.1' );
		$this->store( array( $this->rule( 'block', 'custom_css', '7' ) ) );
		$this->fork( array( 'ability' => 'elementor-mcp/update-element', 'touches' => array( array( 'type' => 'post', 'id' => '7' ), array( 'type' => 'page', 'id' => '7' ) ) ) );
		$this->assertSame( 'block', $this->preview()['rule_match']['effect'] );
	}

	public function test_the_routing_key_never_reaches_the_fork(): void {
		$this->fork( array( 'ability' => 'elementor-mcp/update-page-settings', 'touches' => array() ) );
		$this->preview( array( 'post_id' => 7, '_mcpPath' => '/wp-json/mcp/elementor-mcp-server', 'settings' => array( 'custom_css' => 'a{}' ) ) );
		$this->assertSame(
			array( array( 'elementor-mcp-update-page-settings', array( 'post_id' => 7, 'settings' => array( 'custom_css' => 'a{}' ) ) ) ),
			$this->asked
		);
	}

	public function test_a_dry_run_declares_nothing_and_matches_nothing(): void {
		$this->store( array( $this->rule( 'block', 'site', null ) ) );
		$this->fork( array( 'ability' => 'elementor-mcp/generate-meta-tags', 'touches' => array() ) );
		$res = $this->preview();
		$this->assertTrue( $res['success'] );
		$this->assertSame( array(), $res['touches'] );
		$this->assertNull( $res['rule_match'] );
	}

	public function test_evidence_fields_survive_only_as_literal_true(): void {
		$this->fork( array(
			'ability' => 'elementor-mcp/update-page-settings',
			'touches' => array(
				array( 'type' => 'custom_css', 'id' => '7', 'precise' => 'true', 'css_only' => 1 ),
				array( 'type' => 'page', 'id' => '7', 'precise' => true ),
			),
		) );
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '7' ), array( 'type' => 'page', 'id' => '7' ) ),
			$this->preview()['touches']
		);
	}

	public function malformed(): array {
		return array(
			'null'               => array( null ),
			'not an array'       => array( 'yes' ),
			'no ability'         => array( array( 'touches' => array() ) ),
			'ability not string' => array( array( 'ability' => 7, 'touches' => array() ) ),
			'touches not array'  => array( array( 'ability' => 'elementor-mcp/x', 'touches' => 'page:7' ) ),
			'touch not array'    => array( array( 'ability' => 'elementor-mcp/x', 'touches' => array( 'page:7' ) ) ),
			'id not string'      => array( array( 'ability' => 'elementor-mcp/x', 'touches' => array( array( 'type' => 'page', 'id' => 7 ) ) ) ),
			'type missing'       => array( array( 'ability' => 'elementor-mcp/x', 'touches' => array( array( 'id' => '7' ) ) ) ),
			'one bad of two'     => array( array( 'ability' => 'elementor-mcp/x', 'touches' => array( array( 'type' => 'page', 'id' => '7' ), array( 'type' => 'post' ) ) ) ),
		);
	}

	/** @dataProvider malformed */
	public function test_a_malformed_answer_is_unknown( $answer ): void {
		$this->fork( $answer );
		$this->assert_unknown( $this->preview() );
	}

	public function test_a_throwing_fork_is_unknown(): void {
		$this->fork( static function () {
			throw new RuntimeException( 'boom' );
		} );
		$this->assert_unknown( $this->preview() );
	}

	public function test_no_fork_is_todays_answer(): void {
		Aura_Worker_Tools::_set_fork_declarer_for_tests( false );
		$this->assert_unknown( $this->preview() );
	}

	public function test_the_real_detection_finds_no_fork_in_this_harness(): void {
		Aura_Worker_Tools::_set_fork_declarer_for_tests( null );
		$this->assertFalse( class_exists( 'Elementor_MCP_Governance' ), 'the harness must not define the fork class' );
		$this->assert_unknown( $this->preview() );
	}

	public function test_a_native_tool_never_asks_the_fork(): void {
		$this->fork( array( 'ability' => 'elementor-mcp/x', 'touches' => array() ) );
		( new Aura_Worker_Tools() )->preview_tool( 'test_double_tool', array( 'target' => 'x' ) );
		$this->assertSame( array(), $this->asked );
	}

	public function test_the_rest_handler_returns_200_for_a_fork_answer(): void {
		$this->fork( array( 'ability' => 'elementor-mcp/update-page-settings', 'touches' => $this->page7_css() ) );
		$req = new WP_REST_Request();
		$req->set_param( 'tool', 'elementor-mcp-update-page-settings' );
		$req->set_param( 'params', array( 'post_id' => 7 ) );
		$resp = ( new Aura_Worker_MCP( new Aura_Worker_Security() ) )->preview_tool( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( $this->page7_css(), $resp->get_data()['touches'] );
	}
}
