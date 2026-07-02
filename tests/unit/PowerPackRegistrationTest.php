<?php
/**
 * Integration: the Power Pack registers its tools through the base plugin's
 * aura_worker_register_tools filter and they surface in the MCP registry.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

// Loads the companion bootstrap so aura_power_pack_register_tools() is defined.
require_once dirname( __DIR__, 2 ) . '/siteagent-power-pack/siteagent-power-pack.php';

final class PowerPackRegistrationTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		add_filter( 'aura_worker_register_tools', 'aura_power_pack_register_tools' );
	}

	public function test_power_tools_appear_in_the_registry(): void {
		$registry = new Aura_Worker_Tools();

		$this->assertInstanceOf( Aura_Power_Tool_Fs_Read::class, $registry->get_tool( 'read_file' ) );
		$this->assertInstanceOf( Aura_Power_Tool_Db_Query::class, $registry->get_tool( 'db_query' ) );
	}

	public function test_registered_tools_carry_their_annotations(): void {
		$registry = new Aura_Worker_Tools();
		$meta     = array();
		foreach ( $registry->list_tools() as $t ) {
			$meta[ $t['name'] ] = $t['annotations'];
		}

		$this->assertFalse( $meta['read_file']['requires_approval'] );
		$this->assertTrue( $meta['read_file']['read_only'] );
		$this->assertTrue( $meta['db_query']['requires_approval'] );
	}
}
