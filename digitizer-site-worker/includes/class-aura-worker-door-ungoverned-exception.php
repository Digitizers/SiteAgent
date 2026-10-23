<?php
/**
 * An Elementor write was about to run without the door's wrapper.
 *
 * `close_transport()` closes the two Elementor routes when coverage cannot be
 * verified, but any MCP server built on the WordPress MCP adapter runs the
 * same REGISTERED callback from a route of its own — Atarim 5.1.8 serves every
 * registered third-party ability on `/atarim/mcp`, for one. Core's
 * `wp_before_execute_ability` fires on every one of those paths and cannot
 * return a refusal, so the refusal is thrown: the adapter catches it and
 * answers the call with an error, and nothing runs.
 *
 * @package Aura_Worker
 * @since 2.23.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An ungoverned Elementor write was refused at execution.
 */
class Aura_Worker_Door_Ungoverned_Exception extends RuntimeException {
}
