<?php
/**
 * Vendor updaters that register only in wp-admin (Aura#766).
 *
 * Crocoblock's JetDashboard adds its plugins' offers to WordPress's update
 * list from `Jet_Dashboard\Plugin_Manager::check_update`, run when core
 * refreshes that list — but a Jet plugin registers itself
 * with the dashboard only from its `jet_dashboard_init()`, which runs on
 * `init` and only when `is_admin()`. SiteAgent's REST calls are not admin, so
 * the update list had no JetEngine entry even with an active licence, and the
 * update answered "no update offered". Forcing an admin context is not an
 * option (elementor-pro fatals there).
 *
 * `prime()` registers the target plugin with the dashboard the way its own
 * admin boot would, and builds the dashboard's managers if the plugin manager
 * was never built, so core's next refresh asks Crocoblock. It is
 * best-effort: anything missing or throwing leaves the update path exactly as
 * it was, and the outcome is reported, never raised.
 *
 * @package Aura_Worker
 */

defined( 'ABSPATH' ) || exit;

final class Aura_Worker_Vendor_Updaters {

	/**
	 * Register $plugin_file's vendor updater when it is one that only loads in
	 * wp-admin. Call it before core's update list is refreshed.
	 *
	 * @param string $plugin_file Plugin file, e.g. `jet-engine/jet-engine.php`.
	 * @return string|null The vendor primed (`crocoblock`), or null when none
	 *                     applied or priming did not complete.
	 */
	public static function prime( $plugin_file ) {
		$slug = dirname( (string) $plugin_file );
		if ( 0 !== strpos( $slug, 'jet-' ) || ! class_exists( 'Jet_Dashboard\Dashboard', false ) ) {
			return null;
		}
		try {
			return self::prime_crocoblock( $slug ) ? 'crocoblock' : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Run the Jet plugin's own dashboard registration, forced past its admin
	 * gate, then make sure the plugin manager exists.
	 *
	 * The plugin's main instance is its accessor function — `jet_engine()`,
	 * `jet_smart_filters()` — named after the folder. JetEngine's
	 * `jet_dashboard_init( $force )` honours `$force`; a plugin whose method
	 * takes no argument (JetSmartFilters gates the HOOK, not the body) ignores
	 * the extra one.
	 *
	 * @param string $slug Plugin folder, starting `jet-`.
	 * @return bool Whether the dashboard now has a plugin manager.
	 */
	private static function prime_crocoblock( $slug ) {
		$accessor = str_replace( '-', '_', $slug );
		if ( ! function_exists( $accessor ) ) {
			return false;
		}
		$plugin = call_user_func( $accessor );
		if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'jet_dashboard_init' ) ) {
			return false;
		}
		$plugin->jet_dashboard_init( true );

		// The managers are built on `init` (priority -998); outside admin the
		// plugin manager is not, though its CLASS is loaded with the
		// dashboard's other files (live on dev.holylandmint, 2.23.4: class
		// loaded, `plugin_manager` null, nothing hooked on the update check).
		// So the INSTANCE is what is tested, never the class — which also
		// keeps init_managers() from running twice and doubling every hook.
		$dashboard = call_user_func( array( 'Jet_Dashboard\Dashboard', 'get_instance' ) );
		if ( ! is_object( $dashboard ) ) {
			return false;
		}
		if ( ! self::has_plugin_manager( $dashboard ) && method_exists( $dashboard, 'init_managers' ) ) {
			$dashboard->init_managers();
		}
		return self::has_plugin_manager( $dashboard );
	}

	/**
	 * Whether the dashboard holds a built plugin manager. Read through
	 * get_object_vars(), so a property that is not public reads as absent
	 * instead of throwing.
	 *
	 * @param object $dashboard The JetDashboard instance.
	 * @return bool
	 */
	private static function has_plugin_manager( $dashboard ) {
		$vars = get_object_vars( $dashboard );
		return isset( $vars['plugin_manager'] ) && is_object( $vars['plugin_manager'] );
	}
}
