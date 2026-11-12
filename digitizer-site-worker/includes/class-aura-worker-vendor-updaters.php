<?php
/**
 * Vendor updaters that register only in wp-admin (Aura#766).
 *
 * Crocoblock's JetDashboard adds its plugins' offers to `update_plugins` from
 * `Jet_Dashboard\Plugin_Manager::check_update`, hooked on
 * `pre_set_site_transient_update_plugins` — but a Jet plugin registers itself
 * with the dashboard only from its `jet_dashboard_init()`, which runs on
 * `init` and only when `is_admin()`. SiteAgent's REST calls are not admin, so
 * the update list had no JetEngine entry even with an active licence, and the
 * update answered "no update offered". Forcing an admin context is not an
 * option (elementor-pro fatals there).
 *
 * `prime()` registers the target plugin with the dashboard the way its own
 * admin boot would, and builds the dashboard's managers if the plugin manager
 * was never built, so the next `wp_update_plugins()` asks Crocoblock. It is
 * best-effort: anything missing or throwing leaves the update path exactly as
 * it was, and the outcome is reported, never raised.
 *
 * @package Aura_Worker
 */

defined( 'ABSPATH' ) || exit;

final class Aura_Worker_Vendor_Updaters {

	/**
	 * Register $plugin_file's vendor updater when it is one that only loads in
	 * wp-admin. Call it before refreshing `update_plugins`.
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

		// The managers are built on `init` (priority -998). Outside admin the
		// plugin manager's class was never loaded because nothing had
		// registered; build them now that something has.
		if ( ! class_exists( 'Jet_Dashboard\Plugin_Manager', false ) ) {
			$dashboard = call_user_func( array( 'Jet_Dashboard\Dashboard', 'get_instance' ) );
			if ( is_object( $dashboard ) && method_exists( $dashboard, 'init_managers' ) ) {
				$dashboard->init_managers();
			}
		}
		return class_exists( 'Jet_Dashboard\Plugin_Manager', false );
	}
}
