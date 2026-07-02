<?php
/**
 * WordPress Abilities API bridge for SiteAgent.
 *
 * Dual-registers SiteAgent's MCP tools as WordPress *abilities* when the core
 * Abilities API is present, so standard MCP clients (Claude Desktop et al. via
 * the official WordPress MCP adapter) can discover them — the same standards
 * stack Respira, Novamira, and EMCP ride. The plugin's own `aura/mcp` REST
 * namespace stays intact for the Aura Fleet Gateway; this is purely additive.
 *
 * Auth here is WordPress-native: the Abilities/MCP-adapter transport
 * authenticates the request (e.g. Application Password) and the ability's
 * permission_callback gates on capability. This path does NOT use the
 * X-Aura-Token layer — that belongs to the Aura gateway path.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Worker_Abilities {

	/**
	 * Ability namespace prefix.
	 */
	const NAMESPACE_PREFIX = 'aura-worker';

	/**
	 * Tool registry.
	 *
	 * @var Aura_Worker_Tools
	 */
	private $tools;

	/**
	 * Constructor.
	 */
	public function __construct() {
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-tools.php';
		$this->tools = new Aura_Worker_Tools();
	}

	/**
	 * Register every SiteAgent tool as a WordPress ability.
	 * Hooked on `wp_abilities_api_init`; a no-op when the API is absent.
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// The Abilities API rejects a registration whose category isn't
		// registered (WP_Abilities_Registry::register() returns null), so declare
		// our category first when the API supports categories.
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'site-management',
				array(
					'label' => __( 'Site Management', 'digitizer-site-worker' ),
				)
			);
		}

		foreach ( $this->tools->list_tools() as $meta ) {
			$name = $meta['name'];
			$ann  = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

			wp_register_ability(
				self::NAMESPACE_PREFIX . '/' . str_replace( '_', '-', $name ),
				array(
					'label'               => $this->labelize( $name ),
					'description'         => isset( $meta['description'] ) ? $meta['description'] : $name,
					'category'            => 'site-management',
					'input_schema'        => $this->build_input_schema( isset( $meta['parameters'] ) ? $meta['parameters'] : array() ),
					'execute_callback'    => $this->make_executor( $name ),
					'permission_callback' => $this->make_permission( $ann ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'readonly'          => ! empty( $ann['read_only'] ),
							'destructive'       => ! empty( $ann['destructive'] ),
							'requires_approval' => ! empty( $ann['requires_approval'] ),
						),
					),
				)
			);
		}
	}

	/**
	 * Build a JSON-Schema input object from a tool's parameter metadata.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	private function build_input_schema( $parameters ) {
		$properties = array();
		$required   = array();

		foreach ( $parameters as $pname => $def ) {
			$properties[ $pname ] = array(
				'type'        => isset( $def['type'] ) ? $def['type'] : 'string',
				'description' => isset( $def['description'] ) ? $def['description'] : '',
			);
			if ( ! empty( $def['required'] ) ) {
				$required[] = $pname;
			}
		}

		$schema = array(
			'type'                 => 'object',
			'properties'           => empty( $properties ) ? (object) array() : $properties,
			'required'             => $required,
			'additionalProperties' => true,
		);

		// When nothing is required, default a missing input to {} so a
		// no-argument ability (check_health, scan_security) isn't rejected by
		// validate_input just because the caller omitted `input`.
		if ( empty( $required ) ) {
			$schema['default'] = (object) array();
		}

		return $schema;
	}

	/**
	 * Executor closure that routes an ability call back through the tool registry.
	 *
	 * @param string $name Tool name.
	 * @return callable
	 */
	private function make_executor( $name ) {
		$tools = $this->tools;
		return static function ( $input ) use ( $tools, $name ) {
			return $tools->execute_tool( $name, is_array( $input ) ? $input : array() );
		};
	}

	/**
	 * Capability gate for an ability. Read-only tools need `read`-level admin
	 * access; everything else requires `manage_options`.
	 *
	 * @param array $annotations Tool annotations.
	 * @return callable
	 */
	private function make_permission( $annotations ) {
		$read_only = ! empty( $annotations['read_only'] );
		return static function () use ( $read_only ) {
			// SiteAgent tools operate at admin level; even reads expose admin data.
			return current_user_can( 'manage_options' );
		};
	}

	/**
	 * Human label from a snake_case tool name.
	 *
	 * @param string $name Tool name.
	 * @return string
	 */
	private function labelize( $name ) {
		return ucwords( str_replace( '_', ' ', $name ) );
	}
}
