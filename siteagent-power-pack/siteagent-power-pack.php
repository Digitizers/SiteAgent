<?php
/**
 * Plugin Name:       SiteAgent Power Pack
 * Plugin URI:        https://my-aura.app/siteagent
 * Description:        Governed power tools for SiteAgent — approval-gated, snapshot-first filesystem and database operations exposed to the Aura Fleet Gateway. NOT distributed on wordpress.org (companion to the digitizer-site-worker base plugin).
 * Version:           0.1.0
 * Requires PHP:      7.4
 * Requires Plugins:  digitizer-site-worker
 * Author:            Digitizer
 * License:           GPL-2.0-or-later
 * Text Domain:       siteagent-power-pack
 *
 * @package Aura_Worker_Power_Pack
 *
 * Distribution note (audit decision D2): the base plugin `digitizer-site-worker`
 * stays ops-safe on wordpress.org; the power tools live here and ship only via
 * Freemius / self-hosted. This companion registers its tool classes through the
 * base plugin's `aura_worker_register_tools` filter — it loads only local PHP,
 * never anything remote, and every tool it adds is read-only or approval-gated.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AURA_POWER_PACK_DIR' ) ) {
	define( 'AURA_POWER_PACK_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Register the Power Pack's tools with the SiteAgent MCP registry.
 *
 * Fires from Aura_Worker_Tools' constructor. The base plugin (and therefore
 * Aura_Tool_Base) is guaranteed loaded at that point; we require the tool files
 * lazily here so this companion never fatals when the base plugin is absent.
 *
 * @param array $tools Tool class names / instances contributed by companions.
 * @return array
 */
function aura_power_pack_register_tools( $tools ) {
	if ( ! class_exists( 'Aura_Tool_Base' ) ) {
		return $tools;
	}

	$dir = AURA_POWER_PACK_DIR . 'includes/tools/';
	require_once $dir . 'class-tool-fs-read.php';
	require_once $dir . 'class-tool-db-query.php';

	$tools[] = 'Aura_Power_Tool_Fs_Read';
	$tools[] = 'Aura_Power_Tool_Db_Query';

	return $tools;
}
add_filter( 'aura_worker_register_tools', 'aura_power_pack_register_tools' );
