<?php
/**
 * Minimal stand-in for wordpress/php-mcp-schema (MCP adapter 0.7.x), enough
 * for a server whose get_tools() takes the exact schema to be asked.
 *
 * @package Aura_Worker\Tests
 */

namespace WP\McpSchema;

if ( ! class_exists( Schema::class ) ) {
	final class Schema {
		public $version;
		public function __construct( $version ) {
			$this->version = $version;
		}
	}
}

if ( ! class_exists( Schemas::class ) ) {
	final class Schemas {
		public static function create(): self {
			return new self();
		}
		public function forVersion( string $version ): Schema {
			return new Schema( $version );
		}
		public static function supportedVersions(): array {
			return array( '2025-11-25', '2026-07-28' );
		}
	}
}
