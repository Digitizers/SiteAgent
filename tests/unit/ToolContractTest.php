<?php
/**
 * Contract tests across EVERY shipped MCP tool.
 *
 * Instantiating Aura_Worker_Tools auto-loads every class-tool-*.php, so this
 * suite runs the pure metadata surface of each tool — name, description,
 * parameters, returns, annotations — through invariants that must hold for all
 * of them. A new tool that violates the contract (bad name, missing schema
 * keys, read-only-yet-destructive) fails here, so the fleet gateway can trust
 * what each tool declares about itself.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class ToolContractTest extends TestCase {

	/** Reused across the data provider and the tests. */
	private static ?Aura_Worker_Tools $registry = null;

	/** Parameter types the schema is allowed to declare. */
	private const VALID_TYPES = array( 'string', 'integer', 'number', 'boolean', 'array', 'object' );

	private static function registry(): Aura_Worker_Tools {
		if ( null === self::$registry ) {
			sa_reset_state();
			self::$registry = new Aura_Worker_Tools();
		}
		return self::$registry;
	}

	/**
	 * Names of the SHIPPED tools only — those whose implementing class lives
	 * under includes/tools/. A fake tool another test registers on the shared
	 * registry (SA_Fake_*) is excluded, so neither the contract rows nor the
	 * health count can be skewed by non-shipped doubles.
	 */
	private static function shippedNames(): array {
		$names = array();
		foreach ( self::registry()->list_tools() as $meta ) {
			$tool = self::registry()->get_tool( $meta['name'] );
			if ( ! $tool instanceof Aura_Tool_Base ) {
				continue;
			}
			$file = ( new ReflectionClass( $tool ) )->getFileName();
			if ( is_string( $file ) && false !== strpos( $file, '/digitizer-site-worker/includes/tools/' ) ) {
				$names[] = $meta['name'];
			}
		}
		return $names;
	}

	/**
	 * One row per SHIPPED tool: [ toolName ]. Keyed by name so a failure names
	 * the offending tool.
	 */
	public static function toolProvider(): array {
		$rows = array();
		foreach ( self::shippedNames() as $name ) {
			$rows[ $name ] = array( $name );
		}
		return $rows;
	}

	private function tool( string $name ): Aura_Tool_Base {
		$tool = self::registry()->get_tool( $name );
		$this->assertInstanceOf( Aura_Tool_Base::class, $tool, "get_tool('$name') must return a tool instance" );
		return $tool;
	}

	// -----------------------------------------------------------------------
	// Name
	// -----------------------------------------------------------------------

	/** @dataProvider toolProvider */
	public function test_name_is_nonempty_snake_case( string $name ): void {
		$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*$/', $name, "Tool name '$name' must be snake_case" );
	}

	/** @dataProvider toolProvider */
	public function test_instance_name_matches_registry_name( string $name ): void {
		$this->assertSame( $name, $this->tool( $name )->get_name() );
	}

	// -----------------------------------------------------------------------
	// Description
	// -----------------------------------------------------------------------

	/** @dataProvider toolProvider */
	public function test_description_is_a_meaningful_string( string $name ): void {
		$desc = $this->tool( $name )->get_description();
		$this->assertIsString( $desc );
		$this->assertGreaterThanOrEqual( 20, strlen( $desc ), "Tool '$name' needs a real description" );
	}

	// -----------------------------------------------------------------------
	// Parameters
	// -----------------------------------------------------------------------

	/** @dataProvider toolProvider */
	public function test_parameters_is_an_array( string $name ): void {
		$this->assertIsArray( $this->tool( $name )->get_parameters() );
	}

	/** @dataProvider toolProvider */
	public function test_each_parameter_declares_a_valid_shape( string $name ): void {
		foreach ( $this->tool( $name )->get_parameters() as $key => $def ) {
			$this->assertIsString( $key, "Parameter key on '$name' must be a string" );
			$this->assertIsArray( $def, "Parameter '$key' on '$name' must be an array" );
			$this->assertArrayHasKey( 'type', $def, "Parameter '$key' on '$name' needs a type" );
			$this->assertContains( $def['type'], self::VALID_TYPES, "Parameter '$key' on '$name' has an unknown type '{$def['type']}'" );
			$this->assertArrayHasKey( 'description', $def, "Parameter '$key' on '$name' needs a description" );
			$this->assertIsString( $def['description'] );
			$this->assertNotSame( '', trim( $def['description'] ), "Parameter '$key' on '$name' has an empty description" );
			// 'required' must be declared explicitly: the gateway/schema builders
			// read a MISSING flag as optional (empty($def['required'])), so an
			// omitted flag would silently turn a required input optional.
			$this->assertArrayHasKey( 'required', $def, "Parameter '$key' on '$name' must declare 'required'" );
			$this->assertIsBool( $def['required'], "Parameter '$key' on '$name': 'required' must be bool" );
		}
	}

	/** @dataProvider toolProvider */
	public function test_optional_params_with_defaults_match_their_type( string $name ): void {
		foreach ( $this->tool( $name )->get_parameters() as $key => $def ) {
			if ( ! array_key_exists( 'default', $def ) ) {
				continue;
			}
			$default = $def['default'];
			switch ( $def['type'] ) {
				case 'integer':
					$this->assertIsInt( $default, "Default for '$key' on '$name' should be int" );
					break;
				case 'number':
					$this->assertTrue( is_int( $default ) || is_float( $default ), "Default for '$key' on '$name' should be numeric" );
					break;
				case 'boolean':
					$this->assertIsBool( $default, "Default for '$key' on '$name' should be bool" );
					break;
				case 'string':
					$this->assertIsString( $default, "Default for '$key' on '$name' should be string" );
					break;
				case 'array':
					$this->assertIsArray( $default, "Default for '$key' on '$name' should be array" );
					break;
			}
		}
	}

	// -----------------------------------------------------------------------
	// Returns
	// -----------------------------------------------------------------------

	/** @dataProvider toolProvider */
	public function test_returns_is_a_nonempty_array( string $name ): void {
		$returns = $this->tool( $name )->get_returns();
		$this->assertIsArray( $returns );
		$this->assertNotEmpty( $returns, "Tool '$name' must document its return shape" );
	}

	// -----------------------------------------------------------------------
	// Annotations — the surface the fleet gateway trusts
	// -----------------------------------------------------------------------

	/** @dataProvider toolProvider */
	public function test_annotations_declare_all_four_boolean_flags( string $name ): void {
		$a = $this->tool( $name )->get_annotations();
		foreach ( array( 'read_only', 'destructive', 'requires_approval', 'supports_preview' ) as $flag ) {
			$this->assertArrayHasKey( $flag, $a, "Tool '$name' annotations missing '$flag'" );
			$this->assertIsBool( $a[ $flag ], "Tool '$name' annotation '$flag' must be bool" );
		}
	}

	/** @dataProvider toolProvider */
	public function test_read_only_tool_is_not_destructive( string $name ): void {
		$a = $this->tool( $name )->get_annotations();
		if ( $a['read_only'] ) {
			$this->assertFalse( $a['destructive'], "Tool '$name' is read_only but marked destructive" );
		} else {
			$this->assertTrue( true ); // non-read-only tools carry no constraint here
		}
	}

	/** @dataProvider toolProvider */
	public function test_mutating_tool_requires_approval( string $name ): void {
		// Governance invariant: a tool that isn't read-only changes site state,
		// so it must declare requires_approval=true rather than inherit the
		// neutral default — otherwise a consumer trusting the annotation could
		// run a write without human sign-off.
		$a = $this->tool( $name )->get_annotations();
		if ( ! $a['read_only'] ) {
			$this->assertTrue( $a['requires_approval'], "Mutating tool '$name' must declare requires_approval=true" );
		} else {
			$this->assertTrue( true ); // read-only tools carry no constraint here
		}
	}

	/** @dataProvider toolProvider */
	public function test_preview_capable_tool_overrides_dry_run( string $name ): void {
		$tool = $this->tool( $name );
		$a    = $tool->get_annotations();
		if ( empty( $a['supports_preview'] ) ) {
			$this->assertTrue( true ); // nothing to prove
			return;
		}
		// A tool that advertises preview must actually implement dry_run() rather
		// than inheriting the base no-op, or the gateway's preview path is a lie.
		$ref = new ReflectionMethod( $tool, 'dry_run' );
		$this->assertNotSame(
			Aura_Tool_Base::class,
			$ref->getDeclaringClass()->getName(),
			"Tool '$name' sets supports_preview but doesn't override dry_run()"
		);
	}

	// -----------------------------------------------------------------------
	// Aggregate invariants (not per-tool)
	// -----------------------------------------------------------------------

	public function test_all_tool_names_are_unique(): void {
		// The registry keys tools by name, so list_tools() has ALREADY collapsed
		// any duplicate — asserting on it can never catch a collision (one tool
		// would just silently vanish). Instead enumerate the shipped tool classes
		// directly (constructing the registry has autoloaded them all) and read
		// each name pre-dedup, so two classes claiming the same name fail here.
		self::registry();
		$names = array();
		foreach ( get_declared_classes() as $class ) {
			if ( ! is_subclass_of( $class, Aura_Tool_Base::class ) ) {
				continue;
			}
			$ref = new ReflectionClass( $class );
			if ( $ref->isAbstract() ) {
				continue;
			}
			$file = $ref->getFileName();
			if ( ! is_string( $file ) || false === strpos( $file, '/digitizer-site-worker/includes/tools/' ) ) {
				continue;
			}
			$names[] = $ref->newInstance()->get_name();
		}

		$this->assertNotEmpty( $names, 'Expected to enumerate shipped tool classes' );
		$dupes = array_values( array_diff_assoc( $names, array_unique( $names ) ) );
		$this->assertSame( array(), $dupes, 'Duplicate tool names among shipped classes: ' . implode( ', ', $dupes ) );
	}

	public function test_registry_ships_a_healthy_tool_count(): void {
		// Count SHIPPED tools only — fake doubles other test files register on
		// the shared registry must not prop this guard up if the real set shrinks.
		$this->assertGreaterThanOrEqual( 18, count( self::shippedNames() ) );
	}
}
