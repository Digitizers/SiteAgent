<?php
/**
 * What Elementor's own door writes count as custom CSS (spec 2026-09-24 §4.2,
 * plan rulings R1/R2). Pure over the input.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorDoorCssTouchesTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-elementor-door-governor.php';
		// This is a pure producer-classification test: the runtime schema
		// guard (Task 4) has its own suite. Pin the reader to "unreadable" so
		// a bootstrap wp_get_ability() stub never makes a NO_CSS/precise-
		// producer case here start exercising that guard instead.
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function () {
			return null;
		} );
	}

	private function t( string $slug, array $input, string $id = '42' ): array {
		return Aura_Worker_Elementor_Door::css_touches_for( $slug, $input, $id );
	}

	public function test_page_settings_with_only_css_is_precise_and_css_only(): void {
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true, 'css_only' => true ) ),
			$this->t( 'elementor/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'body{color:red}' ) ) )
		);
	}

	public function test_page_settings_with_css_and_a_title_is_precise_but_mixed(): void {
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true ) ),
			$this->t( 'elementor/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'a{}', 'post_title' => 'x' ) ) )
		);
	}

	public function test_css_value_shapes(): void {
		foreach ( array( null, '', '   ', "\n\t" ) as $clear ) {
			$this->assertSame( array(), $this->t( 'elementor/update-page-settings', array( 'settings' => array( 'custom_css' => $clear ) ) ), 'clearing is not CSS: ' . var_export( $clear, true ) );
		}
		foreach ( array( array( 'color:red' ), 123, true ) as $odd ) {
			$this->assertSame(
				array( array( 'type' => 'custom_css', 'id' => '42' ) ),
				$this->t( 'elementor/update-page-settings', array( 'settings' => array( 'custom_css' => $odd ) ) ),
				'unknown shape is conservative: ' . var_export( $odd, true )
			);
		}
	}

	public function test_an_extra_top_level_field_makes_the_call_mixed(): void {
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true ) ),
			$this->t( 'elementor/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'a{}' ), 'status' => 'publish' ) )
		);
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true ) ),
			$this->t( 'elementor/manage-elements', array( 'post_id' => 42, 'operations' => array( array( 'action' => 'update', 'element_id' => 'a', 'style' => 'x' ) ), 'publish' => true ) )
		);
	}

	public function test_page_settings_without_css_declares_nothing(): void {
		$this->assertSame( array(), $this->t( 'elementor/update-page-settings', array( 'settings' => array( 'post_title' => 'x' ) ) ) );
	}

	public function test_manage_elements_style_only_batch_is_css_only(): void {
		$in = array( 'operations' => array(
			array( 'action' => 'update', 'element_id' => 'a1', 'style' => 'color:red' ),
			array( 'action' => 'update', 'element_id' => 'b2', 'settings' => array( 'custom_css' => 'selector{}' ), 'style_apply_mode' => 'patch' ),
		) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true, 'css_only' => true ) ), $this->t( 'elementor/manage-elements', $in ) );
	}

	public function test_manage_elements_css_plus_a_delete_is_mixed(): void {
		$in = array( 'operations' => array(
			array( 'action' => 'update', 'element_id' => 'a1', 'style' => 'color:red' ),
			array( 'action' => 'delete', 'element_id' => 'b2' ),
		) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true ) ), $this->t( 'elementor/manage-elements', $in ) );
	}

	public function test_manage_elements_css_plus_a_title_setting_is_mixed(): void {
		$in = array( 'operations' => array( array( 'action' => 'update', 'element_id' => 'a1', 'settings' => array( 'custom_css' => 'x{}', 'title' => 'Hi' ) ) ) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true ) ), $this->t( 'elementor/manage-elements', $in ) );
	}

	public function test_manage_elements_without_css_declares_nothing(): void {
		$in = array( 'operations' => array( array( 'action' => 'move', 'element_id' => 'a1', 'new_parent_id' => 'document' ), array( 'action' => 'update', 'element_id' => 'b', 'style' => '  ' ) ) );
		$this->assertSame( array(), $this->t( 'elementor/manage-elements', $in ) );
	}

	public function test_manage_elements_with_a_non_array_operations_is_conservative(): void {
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42' ) ), $this->t( 'elementor/manage-elements', array( 'operations' => 'garbage' ) ) );
	}

	public function test_build_composition_is_a_conservative_producer(): void {
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42' ) ), $this->t( 'elementor/build-composition', array( 'post_id' => 42, 'xml_structure' => '<x/>' ) ) );
	}

	public function test_manage_component_is_a_conservative_site_wide_producer(): void {
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor/manage-component', array( 'action' => 'create' ), '*' ) );
	}

	public function test_design_system_and_other_writes_declare_no_css(): void {
		foreach ( array( 'elementor/manage-classes', 'elementor/manage-default-styles', 'elementor/reorder-classes', 'elementor/manage-global-variable', 'elementor/publish-document', 'elementor/create-preview-link', 'elementor/create-page' ) as $slug ) {
			$this->assertSame( array(), $this->t( $slug, array( 'operations' => array( array( 'css' => 'a{}' ) ) ) ), $slug );
		}
	}
}
