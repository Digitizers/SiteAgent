<?php
/**
 * /status tells Aura whether this site's fork can say it wrote CSS
 * (spec 2026-09-24 §5 UI, §4.2).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class StatusCssRulesTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	private function body(): array {
		$api = new Aura_Worker_API( new Aura_Worker_Security() );
		return $api->get_status( new WP_REST_Request( 'GET', '/aura/v1/status' ) )->get_data();
	}

	public function test_css_rules_is_an_object_naming_the_fork_state(): void {
		$cases = array(
			array( false, null, 'absent' ),
			array( '1.36.1', false, 'widened' ),
			array( '1.37.0', true, 'precise' ),
			// A version number is not a capability (final review I1).
			array( '1.37.0', false, 'widened' ),
			array( '1.36.1', true, 'widened' ),
		);
		foreach ( $cases as $case ) {
			list( $v, $cap, $want ) = $case;
			Aura_Worker_Rules::_set_fork_version_for_tests( $v, $cap );
			$body = $this->body();
			$this->assertIsObject( $body['css_rules'] );
			$this->assertSame( '{"fork":"' . $want . '"}', wp_json_encode( $body['css_rules'] ) );
		}
	}
}
