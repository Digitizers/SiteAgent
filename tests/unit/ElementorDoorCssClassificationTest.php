<?php
/**
 * Every Elementor door write is classified for CSS, and a NO_CSS entry is
 * CHECKED against its input schema, never trusted (spec 2026-09-24 §4.1
 * "Guard against drift", applied to the door per §4.2).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ElementorDoorCssClassificationTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		require_once dirname( __DIR__, 2 ) . '/digitizer-site-worker/includes/class-elementor-door-governor.php';
	}

	private function schemas(): array {
		$doc = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/elementor-4.3-write-schemas.json' ), true );
		return $doc['schemas'];
	}

	public function test_every_write_is_in_exactly_one_list(): void {
		foreach ( array_keys( Aura_Worker_Elementor_Door::WRITE_TABLE ) as $slug ) {
			$in = (int) isset( Aura_Worker_Elementor_Door::CSS_PRODUCERS[ $slug ] ) + (int) isset( Aura_Worker_Elementor_Door::NO_CSS[ $slug ] );
			$this->assertSame( 1, $in, "{$slug} must be classified exactly once" );
		}
		$this->assertSame( array(), array_diff( array_keys( Aura_Worker_Elementor_Door::CSS_PRODUCERS ), array_keys( Aura_Worker_Elementor_Door::WRITE_TABLE ) ) );
		$this->assertSame( array(), array_diff( array_keys( Aura_Worker_Elementor_Door::NO_CSS ), array_keys( Aura_Worker_Elementor_Door::WRITE_TABLE ) ) );
	}

	public function test_the_fixture_covers_every_write(): void {
		$this->assertEqualsCanonicalizing( array_keys( Aura_Worker_Elementor_Door::WRITE_TABLE ), array_keys( $this->schemas() ) );
	}

	public function test_a_no_css_entry_cannot_carry_css_beyond_its_exemptions(): void {
		foreach ( Aura_Worker_Elementor_Door::NO_CSS as $slug => $entry ) {
			$paths = Aura_Worker_Elementor_Door::css_capable_paths( $this->schemas()[ $slug ] );
			$this->assertSame( array(), array_values( array_diff( $paths, $entry['exempt'] ) ), "{$slug} can carry CSS — move it to CSS_PRODUCERS" );
		}
	}

	public function test_css_capable_paths_finds_named_and_open_properties(): void {
		$schema = array( 'type' => 'object', 'properties' => array(
			'post_id'    => array( 'type' => 'integer' ),
			'settings'   => array( 'type' => 'object' ),
			'operations' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'style' => array( 'type' => 'string' ), 'label' => array( 'type' => 'string' ) ) ) ),
			'meta'       => array( 'type' => 'object', 'additionalProperties' => true ),
		) );
		$this->assertEqualsCanonicalizing( array( 'settings', 'operations[].style', 'meta' ), Aura_Worker_Elementor_Door::css_capable_paths( $schema ) );
	}

	public function test_an_open_root_or_open_array_item_is_css_capable(): void {
		$this->assertSame( array( '(root)' ), Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'additionalProperties' => true ) ) );
		$this->assertSame( array( '(root)' ), Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ) ) );
		$this->assertSame(
			array( 'ops[]' ),
			Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'ops' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ) ) ) )
		);
		$this->assertSame( array(), Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array( 'id' => array( 'type' => 'integer' ) ) ) ) );
	}

	public function test_a_live_schema_open_at_the_root_makes_a_no_css_write_conservative(): void {
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function () {
			return array( 'type' => 'object', 'additionalProperties' => true );
		} );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42' ) ), Aura_Worker_Elementor_Door::css_touches_for( 'elementor/publish-document', array(), '42' ) );
	}

	public function test_a_live_schema_that_grew_css_makes_a_no_css_write_conservative(): void {
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function ( $slug ) {
			return 'elementor/publish-document' === $slug
				? array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'custom_css' => array( 'type' => 'string' ) ) )
				: null;
		} );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42' ) ), Aura_Worker_Elementor_Door::css_touches_for( 'elementor/publish-document', array( 'post_id' => 42 ), '42' ) );
	}

	public function test_a_css_child_under_a_named_container_surfaces(): void {
		$this->assertEqualsCanonicalizing(
			array( 'settings', 'settings.css' ),
			Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'settings' => array( 'type' => 'object', 'properties' => array( 'css' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ) ) ) ) ) )
		);
	}

	public function test_opaque_forwarded_strings_are_css_capable(): void {
		$this->assertEqualsCanonicalizing(
			array( 'content', 'ops[].markup' ),
			Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array(
				'content' => array( 'type' => 'string' ),
				'ops'     => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'markup' => array( 'type' => 'string' ), 'id' => array( 'type' => 'string' ) ) ) ),
			) ) )
		);
	}

	public function test_a_precise_producer_whose_schema_grew_an_unhandled_path_turns_conservative(): void {
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function ( $slug ) {
			return 'elementor/update-page-settings' === $slug
				? array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'settings' => array( 'type' => 'object' ), 'extra_css' => array( 'type' => 'string' ) ) )
				: null;
		} );
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '42' ) ),
			Aura_Worker_Elementor_Door::css_touches_for( 'elementor/update-page-settings', array( 'settings' => array( 'custom_css' => 'a{}' ) ), '42' )
		);
	}

	public function test_the_handled_paths_match_the_fixture_schemas(): void {
		foreach ( Aura_Worker_Elementor_Door::PRODUCER_HANDLED_PATHS as $slug => $paths ) {
			$this->assertEqualsCanonicalizing( $paths, Aura_Worker_Elementor_Door::css_capable_paths( $this->schemas()[ $slug ] ), "{$slug}: handled paths drifted from the 4.3 schema" );
		}
	}

	public function test_a_live_schema_with_only_exempt_css_stays_no_css(): void {
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function ( $slug ) {
			return array( 'type' => 'object', 'properties' => array( 'operations' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'css' => array( 'type' => 'string' ) ) ) ) ) );
		} );
		$this->assertSame( array(), Aura_Worker_Elementor_Door::css_touches_for( 'elementor/manage-classes', array(), '*' ) );
	}

	/**
	 * The runtime guard rides `touches_for()`, not just `css_touches_for()`
	 * (Controller ruling on Task 4): a `design_system` write whose LIVE
	 * schema grew a CSS-capable path beyond its exemptions must still carry
	 * its design_system touch(es) — the conservative custom_css touch is
	 * additive, never a replacement, for a call the door would otherwise
	 * let through as `design_system:*` alone.
	 */
	public function test_a_design_system_write_with_a_grown_live_schema_declares_both_design_system_and_custom_css(): void {
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function () {
			return array( 'type' => 'object', 'additionalProperties' => true );
		} );
		$input  = array( 'operations' => array( array( 'action' => 'create', 'label' => 'foo', 'css' => 'color:red' ) ) );
		$result = Aura_Worker_Elementor_Door::touches_for( 'elementor/manage-classes', $input );
		$this->assertIsArray( $result );
		$this->assertContains( array( 'type' => 'design_system', 'id' => '*' ), $result );
		$this->assertContains( array( 'type' => 'custom_css', 'id' => '*' ), $result );
	}

	/* ---- final review: fail-closed schema shapes (Task 4 minors) ---- */

	public function test_a_tuple_items_list_is_css_capable(): void {
		$this->assertSame(
			array( 'pair' ),
			Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'pair' => array( 'type' => 'array', 'items' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ) ) ) )
		);
	}

	public function test_a_combinator_is_css_capable(): void {
		foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $k ) {
			$this->assertSame(
				array( 'value' ),
				Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'value' => array( $k => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ) ) ) ),
				$k
			);
			$this->assertSame( array( '(root)' ), Aura_Worker_Elementor_Door::css_capable_paths( array( $k => array( array( 'type' => 'object' ) ) ) ), "{$k} at the root" );
		}
	}

	public function test_pattern_properties_are_css_capable(): void {
		$this->assertSame( array( '(root)' ), Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'patternProperties' => array( '^x_' => array( 'type' => 'string' ) ) ) ) );
		$this->assertSame(
			array( 'meta' ),
			Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'meta' => array( 'type' => 'object', 'patternProperties' => array( '.*' => array( 'type' => 'string' ) ) ) ) ) )
		);
	}

	public function test_nested_arrays_are_descended(): void {
		$this->assertSame(
			array( 'grid[][].css' ),
			Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'grid' => array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'css' => array( 'type' => 'string' ), 'n' => array( 'type' => 'integer' ) ) ) ) ) ) ) )
		);
		$this->assertSame(
			array( 'grid[][]' ),
			Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'grid' => array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ) ) ) ) )
		);
	}

	/** Controller ruling (final review M2): a bare {type: object} stays CLOSED. */
	public function test_a_bare_object_stays_closed(): void {
		$this->assertSame( array(), Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object', 'properties' => array( 'interactions' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'meta' => array( 'type' => 'object' ) ) ) ) );
		$this->assertSame( array(), Aura_Worker_Elementor_Door::css_capable_paths( array( 'type' => 'object' ) ) );
	}

	/** Task 4 minor: the precise-producer guard, on manage-elements. */
	public function test_manage_elements_whose_schema_grew_extra_css_turns_conservative(): void {
		$schema = $this->schemas()['elementor/manage-elements'];
		$this->assertArrayHasKey( 'operations', $schema['properties'] );
		$schema['properties']['operations']['items']['properties']['extra_css'] = array( 'type' => 'string' );
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function ( $slug ) use ( $schema ) {
			return 'elementor/manage-elements' === $slug ? $schema : null;
		} );
		$in = array( 'post_id' => 42, 'operations' => array( array( 'action' => 'update', 'element_id' => 'a1', 'style' => 'color:red' ) ) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42' ) ), Aura_Worker_Elementor_Door::css_touches_for( 'elementor/manage-elements', $in, '42' ) );
	}

	/** Task 4 minor: the 4.3 schema itself leaves manage-elements precise. */
	public function test_manage_elements_with_its_fixture_schema_stays_precise(): void {
		$schema = $this->schemas()['elementor/manage-elements'];
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function () use ( $schema ) {
			return $schema;
		} );
		$in = array( 'post_id' => 42, 'operations' => array( array( 'action' => 'update', 'element_id' => 'a1', 'style' => 'color:red' ) ) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true, 'css_only' => true ) ), Aura_Worker_Elementor_Door::css_touches_for( 'elementor/manage-elements', $in, '42' ) );
	}

	/** Task 4 minor: an unreadable (null) live schema leaves a precise producer precise. */
	public function test_a_null_live_schema_leaves_a_precise_producer_precise(): void {
		Aura_Worker_Elementor_Door::_set_schema_reader_for_tests( function () {
			return null;
		} );
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true, 'css_only' => true ) ),
			Aura_Worker_Elementor_Door::css_touches_for( 'elementor/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'a{}' ) ), '42' )
		);
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true, 'css_only' => true ) ),
			Aura_Worker_Elementor_Door::css_touches_for( 'elementor/manage-elements', array( 'operations' => array( array( 'action' => 'update', 'element_id' => 'a1', 'style' => 'x' ) ) ), '42' )
		);
	}

	/**
	 * Task 4 minor: the PRODUCTION read (no seam) goes through
	 * wp_get_ability()->get_input_schema(). An ability whose registered
	 * schema is open makes a NO_CSS slug conservative.
	 */
	public function test_the_production_schema_read_uses_the_registered_ability(): void {
		sa_register_ability( 'elementor/publish-document', array(
			'execute_callback' => function () {
				return true;
			},
			'input_schema'     => array( 'type' => 'object', 'additionalProperties' => true ),
		) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '42' ) ), Aura_Worker_Elementor_Door::css_touches_for( 'elementor/publish-document', array( 'post_id' => 42 ), '42' ) );
		// And a closed one does not.
		sa_register_ability( 'elementor/create-preview-link', array(
			'execute_callback' => function () {
				return true;
			},
			'input_schema'     => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ) ) ),
		) );
		$this->assertSame( array(), Aura_Worker_Elementor_Door::css_touches_for( 'elementor/create-preview-link', array( 'post_id' => 42 ), '42' ) );
	}
}
