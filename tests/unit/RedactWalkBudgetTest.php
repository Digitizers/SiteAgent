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
		$this->assertGreaterThan( 0, $n );
		// The payload is walked first and spends the whole budget, so what
		// follows it in the response fails closed too (the record included).
		if ( null === $out['result']['payload'] ) {
			$this->assertTrue( $out['result']['payload_redacted'] );
		} else {
			$bytes = base64_decode( $out['result']['payload'], true );
			$this->assertIsString( $bytes );
			$this->assertStringContainsString( 'aura-redacted:v1:field', $bytes );
		}
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
