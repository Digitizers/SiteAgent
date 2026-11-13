<?php
/**
 * Run one plugin's own database upgrade (2.23.7, Aura#766).
 *
 * A plugin update replaces files; Elementor, Elementor Pro, WooCommerce and
 * JetEngine also migrate their database, which runs on the next wp-admin load
 * or here. update_plugin_safely reports `db_upgrade.status: pending` with the
 * registry key to pass. The migration is the one the dashboard's database
 * action runs (Aura_Worker_Updater::update_database()).
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Aura_Tool_Run_Plugin_Db_Upgrade extends Aura_Tool_Base {

	public function get_name() {
		return 'run_plugin_db_upgrade';
	}

	public function get_description() {
		return 'Runs one plugin\'s own database upgrade (elementor, elementor-pro, woocommerce, jet-engine) after its files were updated. Pass the `db_upgrade.key` update_plugin_safely reported, as plugin_slug.';
	}

	public function get_parameters() {
		return array(
			'plugin_slug' => array(
				'type'        => 'string',
				'description' => 'The plugin\'s slug, as update_plugin_safely took it and as it reported in db_upgrade.key: elementor, elementor-pro, woocommerce or jet-engine.',
				'required'    => true,
			),
		);
	}

	public function get_returns() {
		return array(
			'success'    => 'bool — whether the migration ran without error',
			'plugin'     => 'string — the migration key',
			'message'    => 'string|null — what ran',
			'error'      => 'string|null — why it did not',
			'db_upgrade' => 'object|null — { key, status, from, to } after the run',
		);
	}

	/** A database migration: mutating, approved before it runs. */
	public function get_annotations() {
		return array(
			'read_only'         => false,
			'destructive'       => false,
			'requires_approval' => true,
			'supports_preview'  => false,
		);
	}

	/**
	 * The migration key this call names.
	 *
	 * @param array $params Tool params.
	 * @return string
	 */
	private function key( $params ) {
		return isset( $params['plugin_slug'] ) && is_string( $params['plugin_slug'] ) ? sanitize_text_field( $params['plugin_slug'] ) : '';
	}

	/** @inheritDoc */
	public function touches( $params ) {
		$key = $this->key( $params );
		if ( '' === $key ) {
			return parent::touches( $params );
		}
		return array( array( 'type' => 'plugin', 'id' => Aura_Worker_Rules::plugin_slug( $key ) ) );
	}

	public function execute( $params ) {
		$key     = $this->key( $params );
		$updater = $this->new_updater();
		// A key is required: update_database( null ) is WordPress core's own
		// upgrade, which this tool never runs.
		if ( ! $updater->has_migration( $key ) ) {
			return array(
				'success'    => false,
				'plugin'     => $key,
				'message'    => null,
				'error'      => 'Unknown plugin migration key. Use one of the keys update_plugin_safely reports in db_upgrade.key.',
				'db_upgrade' => null,
			);
		}
		$res  = $updater->update_database( $key );
		$file = $updater->migration_file( $key );
		return array(
			'success'    => ! empty( $res['success'] ),
			'plugin'     => $key,
			'message'    => isset( $res['message'] ) ? (string) $res['message'] : null,
			'error'      => empty( $res['success'] ) ? (string) ( $res['error'] ?? 'The migration did not complete.' ) : null,
			'db_upgrade' => null !== $file ? $updater->db_upgrade_status( $file ) : null,
		);
	}

	/**
	 * The updater. A seam, like update_plugin_safely's.
	 *
	 * @return Aura_Worker_Updater
	 */
	protected function new_updater() {
		require_once plugin_dir_path( __FILE__ ) . '../class-aura-worker-updater.php';
		return new Aura_Worker_Updater();
	}
}
