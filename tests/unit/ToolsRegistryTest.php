<?php
/**
 * Tests for Aura_Worker_Tools — the auto-loading MCP tool registry.
 *
 * Instantiating the registry loads every real class-tool-*.php from
 * includes/tools/, so these tests also smoke-test that the shipped tool set
 * loads and instantiates without fatals.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ToolsRegistryTest extends TestCase {

	private Aura_Worker_Tools $registry;

	protected function setUp(): void {
		sa_reset_state();
		$this->registry = new Aura_Worker_Tools();
	}

	public function test_registry_loads_the_shipped_tools(): void {
		$tools = $this->registry->list_tools();

		// The plugin ships 18 MCP tools; assert a healthy, non-trivial set loaded.
		$this->assertGreaterThanOrEqual( 10, count( $tools ) );

		foreach ( $tools as $meta ) {
			$this->assertArrayHasKey( 'name', $meta );
			$this->assertArrayHasKey( 'description', $meta );
			$this->assertArrayHasKey( 'parameters', $meta );
			$this->assertNotSame( '', $meta['name'] );
		}
	}

	public function test_get_tool_returns_instance_for_known_name(): void {
		$tools = $this->registry->list_tools();
		$name  = $tools[0]['name'];

		$this->assertInstanceOf( Aura_Tool_Base::class, $this->registry->get_tool( $name ) );
	}

	public function test_get_tool_returns_null_for_unknown_name(): void {
		$this->assertNull( $this->registry->get_tool( 'no_such_tool_xyz' ) );
	}

	public function test_execute_unknown_tool_reports_failure(): void {
		$result = $this->registry->execute_tool( 'no_such_tool_xyz', array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown tool', $result['error'] );
	}

	public function test_execute_reports_validation_errors_before_running(): void {
		// Find a shipped tool with at least one required param and call it with
		// no params — execution must be refused at validation, never reaching execute().
		$target = null;
		foreach ( $this->registry->list_tools() as $meta ) {
			foreach ( $meta['parameters'] as $def ) {
				if ( ! empty( $def['required'] ) ) {
					$target = $meta['name'];
					break 2;
				}
			}
		}

		if ( null === $target ) {
			$this->markTestSkipped( 'No shipped tool declares a required parameter.' );
		}

		$result = $this->registry->execute_tool( $target, array() );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Parameter validation failed.', $result['error'] );
		$this->assertNotEmpty( $result['errors'] );
	}
}
