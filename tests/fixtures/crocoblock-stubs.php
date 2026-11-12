<?php
/**
 * A Crocoblock JetDashboard stand-in, shaped from JetEngine 3.9.1.1 (Aura#766):
 * a Jet plugin registers with the dashboard only from its jet_dashboard_init(),
 * which (unforced) does so only in wp-admin; `init_managers()` loads the plugin
 * manager only once something is registered. Loaded by
 * VendorUpdaterBridgeTest, in a separate process per test, because the plugin
 * manager's class cannot be unloaded.
 *
 * @package Aura_Worker\Tests
 */

namespace Jet_Dashboard {
	class Dashboard {
		private static $instance;
		public $registered = array();

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function init( $args ) {
			$GLOBALS['_jet_calls'][] = 'register:' . $args['plugin_data']['file'];
			$this->registered[]      = $args['plugin_data']['file'];
		}

		public function init_managers() {
			$GLOBALS['_jet_calls'][] = 'init_managers';
			if ( $this->registered && ! class_exists( __NAMESPACE__ . '\Plugin_Manager', false ) ) {
				require __DIR__ . '/crocoblock-plugin-manager.php';
			}
		}
	}
}

namespace {
	class SA_Test_Jet_Engine {
		public function jet_dashboard_init( $force = false ) {
			$GLOBALS['_jet_calls'][] = 'jet_dashboard_init:' . ( $force ? 'force' : 'plain' );
			if ( ! empty( $GLOBALS['_jet_throw'] ) ) {
				throw new \RuntimeException( 'boom' );
			}
			if ( $force || ! empty( $GLOBALS['_is_admin_jet'] ) ) {
				\Jet_Dashboard\Dashboard::get_instance()->init( array( 'plugin_data' => array( 'file' => 'jet-engine/jet-engine.php' ) ) );
			}
		}
	}

	function jet_engine() {
		return new SA_Test_Jet_Engine();
	}
}
