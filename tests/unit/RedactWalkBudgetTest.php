<?php
/**
 * SiteAgent #110 item 2: a total node budget for Aura_Worker_Redact::walk().
 * MAX_WALK_DEPTH bounds one path; a structure that refers back to itself
 * two or more times still took exponential time. The budget is one per
 * served response, carriers included, and fails closed.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class RedactWalkBudgetTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	/** A list of $n empty arrays: $n + 1 containers. */
	private function flat( int $n ): array {
		return array_fill( 0, $n, array() );
	}

	public function test_a_payload_that_references_itself_twice_completes_and_fails_closed(): void {
		$answer = array(
			'found'   => true,
			'record'  => array( 'id' => 'snap_loop', 'door_kind' => 'page' ),
			'payload' => base64_encode( 'a:2:{i:0;R:1;i:1;R:1;}' ),
		);

		$start = microtime( true );
		$out   = Aura_Worker_Redact::redact( array( 'result' => $answer ), $n );

		$this->assertLessThan( 10.0, microtime( true ) - $start, 'exponential without a node budget' );
		$this->assertSame( 1, $n, 'one replacement: the payload (R12)' );
		// Fix round 1 (M4): the documented fail-closed shape, and the record survives.
		$this->assertNull( $out['result']['payload'] );
		$this->assertTrue( $out['result']['payload_redacted'] );
		$this->assertTrue( $out['result']['found'] );
		$this->assertSame( $answer['record'], $out['result']['record'] );
	}

	public function test_after_an_exhausting_payload_the_rest_of_the_response_is_still_walked(): void {
		$loop = array(
			'found'   => true,
			'record'  => array( 'id' => 'snap_loop' ),
			'payload' => base64_encode( 'a:2:{i:0;R:1;i:1;R:1;}' ),
		);
		$body = array(
			'a'    => $loop,
			'b'    => $loop,
			'text' => 'see https://hook.eu2.make.com/abc123secret',
		);

		$start = microtime( true );
		$out   = Aura_Worker_Redact::redact( $body, $n );

		$this->assertLessThan( 10.0, microtime( true ) - $start );
		$this->assertTrue( $out['a']['payload_redacted'] );
		$this->assertTrue( $out['b']['payload_redacted'], 'a second exhausting payload fails closed at once' );
		$this->assertSame( 'see aura-redacted:v1:make', $out['text'] );
		$this->assertSame( 3, $n );
	}

	public function test_a_clean_payload_after_an_exhausting_one_fails_closed_too(): void {
		$loop  = array( 'found' => true, 'record' => null, 'payload' => base64_encode( 'a:2:{i:0;R:1;i:1;R:1;}' ) );
		$clean = array( 'found' => true, 'record' => null, 'payload' => base64_encode( serialize( array( 'x' => 'plain' ) ) ) );
		$out   = Aura_Worker_Redact::redact( array( $loop, $clean ), $n );
		$this->assertNull( $out[1]['payload'] );
		$this->assertTrue( $out[1]['payload_redacted'] );

		// A new response starts with a fresh budget.
		$this->assertSame( array( $clean ), Aura_Worker_Redact::redact( array( $clean ), $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_an_array_that_references_itself_twice_completes_and_fails_closed(): void {
		$loop = unserialize( 'a:2:{i:0;R:1;i:1;R:1;}' );
		$this->assertIsArray( $loop, 'fixture' );

		$start = microtime( true );
		Aura_Worker_Redact::redact( array( 'x' => $loop ), $n );

		$this->assertLessThan( 10.0, microtime( true ) - $start );
		$this->assertGreaterThan( 0, $n );
	}

	public function test_an_object_that_holds_itself_twice_completes_and_fails_closed(): void {
		$node    = new stdClass();
		$node->a = $node;
		$node->b = $node;

		$start = microtime( true );
		Aura_Worker_Redact::redact( $node, $n );

		$this->assertLessThan( 10.0, microtime( true ) - $start );
		$this->assertGreaterThan( 0, $n );
	}

	public function test_the_budget_is_exact_and_a_tree_at_it_is_untouched(): void {
		// assertTrue( === ): PHPUnit's diff exporter runs out of memory on 200k nodes.
		$within = $this->flat( Aura_Worker_Redact::MAX_WALK_NODES - 1 );
		$this->assertTrue( $within === Aura_Worker_Redact::redact( $within, $n ) );
		$this->assertSame( 0, $n, 'MAX_WALK_NODES containers are walked' );

		$past     = $this->flat( Aura_Worker_Redact::MAX_WALK_NODES );
		$out      = Aura_Worker_Redact::redact( $past, $n );
		$expected = $past;
		$expected[ Aura_Worker_Redact::MAX_WALK_NODES - 1 ] = 'aura-redacted:v1:field';
		$this->assertSame( 1, $n );
		$this->assertTrue( $expected === $out, 'the container past the budget is the field placeholder' );

		// One budget per redact(): the next response starts from zero.
		$this->assertTrue( $within === Aura_Worker_Redact::redact( $within, $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_a_large_legitimate_response_is_untouched(): void {
		$rows = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$rows[] = array(
				'id'    => $i,
				'title' => array( 'rendered' => "Post {$i}" ),
				'meta'  => array( '_elementor_data' => wp_json_encode( array_fill( 0, 40, array( 'id' => 'w', 'settings' => array( 'url' => 'https://example.com/x' ) ) ) ) ),
			);
		}
		$this->assertSame( $rows, Aura_Worker_Redact::redact( $rows, $n ) );
		$this->assertSame( 0, $n );
	}

	public function test_one_response_has_one_budget_across_its_carriers(): void {
		$half  = intdiv( Aura_Worker_Redact::MAX_WALK_NODES, 2 ) + 10;
		$json  = wp_json_encode( $this->flat( $half ) );
		$one   = array( 'meta' => array( '_elementor_data' => $json ) );
		$this->assertTrue( $one === Aura_Worker_Redact::redact( $one, $n ) );
		$this->assertSame( 0, $n, 'one carrier alone is within the budget' );

		$two = array(
			array( 'meta' => array( '_elementor_data' => $json ) ),
			array( 'meta' => array( '_elementor_data' => $json ) ),
		);
		$out = Aura_Worker_Redact::redact( $two, $n );
		$this->assertGreaterThan( 0, $n, 'two together exceed the one budget of the response' );
		$this->assertSame( $json, $out[0]['meta']['_elementor_data'], 'the first carrier fit' );
		$this->assertStringContainsString( 'aura-redacted:v1:field', $out[1]['meta']['_elementor_data'] );
	}
}
