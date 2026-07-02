<?php
/**
 * Tests for Aura_Tool_Base — the abstract every MCP tool extends.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Concrete double: one required param ("target"), one optional ("note").
 */
class SA_Fake_Tool extends Aura_Tool_Base {
	public function get_name() {
		return 'test_double_tool';
	}
	public function get_description() {
		return 'A fake tool used only in unit tests.';
	}
	public function get_parameters() {
		return array(
			'target' => array( 'type' => 'string', 'description' => 'Required target.', 'required' => true ),
			'note'   => array( 'type' => 'string', 'description' => 'Optional note.', 'required' => false ),
		);
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function execute( $params ) {
		return array( 'ok' => true, 'echo' => $params );
	}
}

final class ToolBaseTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
	}

	public function test_metadata_shape(): void {
		$tool = new SA_Fake_Tool();
		$meta = $tool->get_metadata();

		$this->assertSame( 'test_double_tool', $meta['name'] );
		$this->assertArrayHasKey( 'description', $meta );
		$this->assertArrayHasKey( 'parameters', $meta );
		$this->assertArrayHasKey( 'returns', $meta );
		$this->assertArrayHasKey( 'target', $meta['parameters'] );
	}

	public function test_validate_params_passes_when_required_present(): void {
		$tool   = new SA_Fake_Tool();
		$result = $tool->validate_params( array( 'target' => 'homepage' ) );

		$this->assertTrue( $result['valid'] );
		$this->assertArrayNotHasKey( 'errors', $result );
	}

	public function test_validate_params_fails_when_required_missing(): void {
		$tool   = new SA_Fake_Tool();
		$result = $tool->validate_params( array( 'note' => 'no target here' ) );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'target', $result['errors'][0] );
	}

	public function test_optional_param_absence_is_valid(): void {
		$tool   = new SA_Fake_Tool();
		$result = $tool->validate_params( array( 'target' => 'x' ) );

		$this->assertTrue( $result['valid'] );
	}

	public function test_default_annotations_are_neutral(): void {
		$ann = ( new SA_Fake_Tool() )->get_annotations();

		$this->assertFalse( $ann['read_only'] );
		$this->assertFalse( $ann['destructive'] );
		$this->assertFalse( $ann['requires_approval'] );
		$this->assertFalse( $ann['supports_preview'] );
	}

	public function test_metadata_includes_annotations(): void {
		$meta = ( new SA_Fake_Tool() )->get_metadata();

		$this->assertArrayHasKey( 'annotations', $meta );
		$this->assertArrayHasKey( 'requires_approval', $meta['annotations'] );
	}

	public function test_dry_run_defaults_to_null(): void {
		$this->assertNull( ( new SA_Fake_Tool() )->dry_run( array( 'target' => 'x' ) ) );
	}

	public function test_power_tool_can_override_annotations_and_preview(): void {
		$tool = new SA_Fake_Power_Tool();
		$ann  = $tool->get_annotations();

		$this->assertTrue( $ann['requires_approval'] );
		$this->assertTrue( $ann['supports_preview'] );
		$this->assertSame( array( 'preview' => 'would run: x' ), $tool->dry_run( array( 'target' => 'x' ) ) );
	}
}

/**
 * A power-tool-shaped double: overrides annotations + dry_run the way the
 * companion power pack's tools will.
 */
class SA_Fake_Power_Tool extends Aura_Tool_Base {
	public function get_name() {
		return 'test_power_double';
	}
	public function get_description() {
		return 'A fake power tool.';
	}
	public function get_parameters() {
		return array( 'target' => array( 'type' => 'string', 'required' => true ) );
	}
	public function get_returns() {
		return array( 'ok' => array( 'type' => 'boolean' ) );
	}
	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => true,
			'requires_approval' => true,
			'supports_preview'  => true,
		);
	}
	public function dry_run( $params ) {
		return array( 'preview' => 'would run: ' . ( $params['target'] ?? '' ) );
	}
	public function execute( $params ) {
		return array( 'ok' => true );
	}
}
