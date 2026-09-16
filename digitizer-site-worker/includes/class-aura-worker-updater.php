<?php
/**
 * Update handler for SiteAgent.
 *
 * Handles WordPress core, plugin, theme, translation, and database updates
 * using WordPress internal Upgrader classes.
 *
 * @package Aura_Worker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-aura-worker-host-probe.php';

class Aura_Worker_Updater {

	/**
	 * Option holding the per-site self-update claim (`<fence>|<unix time>`);
	 * under the prefix uninstall.php sweeps.
	 */
	const SELF_UPDATE_LOCK = 'aura_worker_self_update_lock';

	/**
	 * This plugin's own file, as WordPress names it. Every path that can replace
	 * these files — self-update, the generic single update, the batch — takes
	 * the SELF_UPDATE_LOCK claim first (Codex round-23 P1).
	 */
	const SELF_PLUGIN_FILE = 'digitizer-site-worker/digitizer-site-worker.php';

	/**
	 * Seconds between lease renewals inside one phase (SA#80). Read through
	 * `static::` so a test can zero it.
	 */
	const LEASE_HEARTBEAT_SECONDS = 60;

	/**
	 * Most entries plugin_manifest() walks before giving up (SA#95). A plugin
	 * bigger than this gets no manifest, and its failed install is restored
	 * exactly as before.
	 */
	const MANIFEST_MAX_ENTRIES = 20000;

	/**
	 * Most file bytes plugin_manifest() hashes before giving up (SA#95), with
	 * the same fallback as MANIFEST_MAX_ENTRIES.
	 */
	const MANIFEST_MAX_BYTES = 67108864;

	/**
	 * Can PHP write and delete a `.php` file on this host (SA#95)? Runs the
	 * probe (Aura_Worker_Host_Probe::run()), records the verdict in
	 * `aura_worker_host_probe`, and answers it.
	 *
	 * @return string 'ok', 'blocked' or 'unwritable'.
	 */
	public static function host_php_writes() {
		return ( new Aura_Worker_Host_Probe() )->run();
	}

	/**
	 * The probe verdict every plugin-file mutation consults. A seam: the unit
	 * suite cannot fake a real filesystem permission.
	 *
	 * @return string
	 */
	protected function host_php_writes_verdict() {
		return self::host_php_writes();
	}

	/**
	 * The recovery helper a self-update uses. A seam, so a test can watch
	 * which restores ran.
	 *
	 * @return Aura_Worker_Rollback
	 */
	protected function new_rollback() {
		return new Aura_Worker_Rollback();
	}

	/**
	 * The host refusal for a verdict, or null when the host lets PHP write
	 * `.php` files. Probes when no verdict is given.
	 *
	 * @param string|null $verdict A verdict already taken, or null to probe now.
	 * @return array|null
	 */
	private function host_refusal( $verdict = null ) {
		if ( null === $verdict ) {
			$verdict = $this->host_php_writes_verdict();
		}
		return Aura_Worker_Host_Probe::refusal( $verdict );
	}

	/**
	 * Load required WordPress upgrade files.
	 */
	private function load_upgrade_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	/**
	 * Get available updates for everything.
	 *
	 * Uses cached transients by default (lightweight).
	 * Pass ?refresh=1 to force a fresh check (requires more memory).
	 *
	 * @param bool $force_refresh Whether to force fresh update checks.
	 * @return array Update information.
	 */
	public function get_available_updates( $force_refresh = false ) {
		// Temporarily increase memory for update checks.
		wp_raise_memory_limit( 'admin' );

		// Load required admin files for update functions.
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $force_refresh ) {
			wp_version_check();
			wp_update_plugins();
			wp_update_themes();
		}

		$result = array(
			'core'         => $this->get_core_updates(),
			'plugins'      => $this->get_plugin_updates(),
			'themes'       => $this->get_theme_updates(),
			'translations' => $this->get_translation_updates(),
			'cached'       => ! $force_refresh,
		);

		return $result;
	}

	/**
	 * Get core update info.
	 *
	 * @return array|null Core update data or null.
	 */
	private function get_core_updates() {
		$updates = get_core_updates();

		if ( empty( $updates ) || ! is_array( $updates ) || is_wp_error( $updates ) ) {
			return null;
		}

		$update = $updates[0];
		if ( 'latest' === $update->response ) {
			return null;
		}

		return array(
			'current' => get_bloginfo( 'version' ),
			'new'     => $update->version,
			'locale'  => $update->locale,
		);
	}

	/**
	 * Get plugin updates.
	 *
	 * @return array List of plugins with available updates.
	 */
	private function get_plugin_updates() {
		$update_plugins = get_site_transient( 'update_plugins' );
		$updates        = array();

		if ( ! empty( $update_plugins->response ) ) {
			$all_plugins = get_plugins();

			foreach ( $update_plugins->response as $plugin_file => $plugin_data ) {
				$current_data = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array();

				$updates[] = array(
					'file'        => $plugin_file,
					'slug'        => isset( $plugin_data->slug ) ? $plugin_data->slug : dirname( $plugin_file ),
					'name'        => isset( $current_data['Name'] ) ? $current_data['Name'] : '',
					'current'     => isset( $current_data['Version'] ) ? $current_data['Version'] : '',
					'new'         => isset( $plugin_data->new_version ) ? $plugin_data->new_version : '',
					'auto_update' => wp_is_auto_update_enabled_for_type( 'plugin' ),
				);
			}
		}

		return $updates;
	}

	/**
	 * Get theme updates.
	 *
	 * @return array List of themes with available updates.
	 */
	private function get_theme_updates() {
		$update_themes = get_site_transient( 'update_themes' );
		$updates       = array();

		if ( ! empty( $update_themes->response ) ) {
			foreach ( $update_themes->response as $theme_slug => $theme_data ) {
				$theme = wp_get_theme( $theme_slug );

				$updates[] = array(
					'slug'    => $theme_slug,
					'name'    => $theme->get( 'Name' ),
					'current' => $theme->get( 'Version' ),
					'new'     => isset( $theme_data['new_version'] ) ? $theme_data['new_version'] : '',
				);
			}
		}

		return $updates;
	}

	/**
	 * Get translation updates.
	 *
	 * @return int Number of translation updates available.
	 */
	private function get_translation_updates() {
		$translations = wp_get_translation_updates();
		return count( $translations );
	}

	/**
	 * Self-update SiteAgent from a zip URL (e.g. GitHub release asset).
	 *
	 * Downloads the zip, overwrites the current plugin files, and reactivates.
	 *
	 * @param string $zip_url         URL to the plugin zip file.
	 * @param string $expected_sha256 Optional hex SHA-256 the downloaded zip must
	 *                                match before install (gateway-bound digest).
	 * @return array Result with success status, message, and version info.
	 */
	public function self_update( $zip_url, $expected_sha256 = '' ) {
		$this->load_upgrade_dependencies();

		$refused = $this->self_mutation_refusal( self::SELF_PLUGIN_FILE );
		if ( null !== $refused ) {
			return $refused; // SA#79 — before any claim, download or write
		}

		// SA#95: a host that will not let PHP write or delete .php files fails
		// every install partway, and the restore after it deleted the plugin's
		// other files. Asked after the multisite refusal and before any claim,
		// download, backup or write — the probe's own two files aside.
		$host = $this->host_refusal();
		if ( null !== $host ) {
			return array_merge(
				$host,
				array(
					'old_version'    => AURA_WORKER_VERSION,
					'installed'      => false,
					'backed_up'      => false,
					'health_checked' => false,
					'rolled_back'    => false,
					'restore_error'  => null,
				)
			);
		}

		// ONE self-update at a time per site (Codex round-20 P1). The verdict
		// rests on a single nonce option: a second request overlapping the first
		// overwrote it before the first loopback wrote its beacon, so the first
		// verifier read a record carrying a nonce it did not arm — "unrelated",
		// inconclusive, healthy — and a broken release stood with no rollback;
		// concurrent installs also back up and replace each other's files.
		//
		// The claim is the connect's own (#434): a conditional INSERT to take it,
		// seized only when its holder is older than the takeover window (a request
		// that fatals mid-update never reaches `finally`), and released ONLY by
		// its holder — the DELETE is fenced on the value — so a request that ran
		// past the window and was taken over cannot remove its successor's claim
		// on its way out (Codex round-21 P1; core's WP_Upgrader::release_lock()
		// is an unconditional delete with exactly that hole).
		if ( ! class_exists( 'Aura_Worker_Magic_Link' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-magic-link.php';
		}
		$fence = Aura_Worker_Magic_Link::take_claim( self::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		if ( '' === $fence ) {
			return $this->self_update_busy();
		}
		try {
			return $this->self_update_locked( $zip_url, $expected_sha256, $fence );
		} finally {
			Aura_Worker_Magic_Link::release_claim( self::SELF_UPDATE_LOCK, $fence );
		}
	}

	/**
	 * The self-update proper. Runs only under the lock `self_update()` holds, so
	 * the nonce it arms is the only one armed on this site.
	 *
	 * @param string $zip_url         See self_update().
	 * @param string $expected_sha256 See self_update().
	 * @param string $fence           The claim self_update() holds, renewed between
	 *                                phases so a live update is never seized as
	 *                                stale (Codex round-22 P1).
	 * @return array
	 */
	private function self_update_locked( $zip_url, $expected_sha256, $fence ) {
		// Load the recovery classes BEFORE the install, so their code is in
		// memory from the OLD build. After `install()` this plugin's directory
		// has been replaced; a class first required afterwards would be read
		// from the new files — which are exactly what we may be about to decide
		// are broken. `batch_update_plugins()` takes the same precaution.
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-health.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-rollback.php';

		$old_version = AURA_WORKER_VERSION;
		$plugin_file = self::SELF_PLUGIN_FILE;
		$plugin_slug = 'digitizer-site-worker';

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		// Integrity: when the gateway bound an expected sha256, download the zip
		// first, verify its bytes, then install from the LOCAL file. The grant
		// covers the URL; this covers the bytes — a tampered download (e.g. a
		// compromised CDN edge) is refused before install. No digest → install
		// straight from the URL (back-compat).
		$install_source  = $zip_url;
		$tmp             = '';
		$package_header  = null; // the verified archive's own Version header, once read (SA#95/#104)
		$expected_sha256 = strtolower( trim( (string) $expected_sha256 ) );
		if ( '' !== $expected_sha256 ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$tmp = download_url( $zip_url );
			if ( is_wp_error( $tmp ) ) {
				return array(
					'success' => false,
					'error'   => $tmp->get_error_message(),
				);
			}
			$verified = $this->verify_zip_integrity( $tmp, $expected_sha256 );
			if ( is_wp_error( $verified ) ) {
				wp_delete_file( $tmp );
				return array(
					'success' => false,
					'error'   => $verified->get_error_message(),
				);
			}
			$install_source = $tmp;

			// SA#104: a package carrying the version already running is refused
			// by the post-install check below — but only after this plugin was
			// backed up and install() had written over its live directory, and a
			// refusal that was always coming touched (and on one host damaged)
			// the installed plugin every time it was asked. The verified archive
			// already says which version it carries, so ask it first and refuse
			// with nothing changed. An archive that cannot be read here decides
			// nothing: the update goes on exactly as before, and the
			// post-install check stays the backstop.
			$package_version = $this->verified_package_version( $tmp );
			if ( null !== $package_version
				&& ( $old_version === $package_version['header'] || $old_version === $package_version['constant'] ) ) {
				wp_delete_file( $tmp );
				return $this->self_update_same_version_refused( $old_version, $package_version );
			}
			if ( null !== $package_version ) {
				$package_header = $package_version['header'];
			}
		}

		// The lease is renewed before each phase that could take a while, so a
		// slow download, backup or install is never mistaken for a dead holder
		// and seized from under a request that is still working. Losing it here
		// means another self-update took over: stop before touching the files.
		if ( ! $this->keep_self_update_claim( $fence ) ) {
			return $this->self_update_claim_lost();
		}

		// The recovery helper is BUILT only here, once nothing above can refuse
		// (Codex #105 round-3 P2): its constructor creates wp-content/aura-backups/
		// with an .htaccess and index.php, and a refusal that says nothing on the
		// site was changed must not have done that. Its class was loaded at the
		// top, from the old build; only the construction moved.
		//
		// Under the same contract as the backup itself: anything the recovery
		// setup does that could end the request instead of reporting failure
		// leaves a site unable to self-update at all (Codex round-13).
		try {
			$rollback = $this->new_rollback();
		} catch ( Throwable $e ) {
			$rollback = null;
		}

		// Back up this plugin's own directory so a bad build can be undone.
		// Taken HERE, not at the top: everything above can still refuse the
		// update (a bad digest, a failed download), and a backup made for an
		// install that never runs is just a zip nobody asked for.
		//
		// A backup that CANNOT be made does not refuse the update. Refusing
		// would be safer for this one site and would silently make every site
		// without ZipArchive — or with an unwritable backup dir — permanently
		// un-updatable: a gate that can never pass, which is the defect Aura
		// #472 spent months not noticing. The result reports `backed_up`, so the
		// caller records which updates had no way back instead of the plugin
		// quietly deciding that for it.
		$backup      = $rollback ? $rollback->backup_plugin( $plugin_slug ) : array( 'success' => false );
		$backup_path = ! empty( $backup['success'] ) ? $backup['backup_path'] : null;


		if ( ! $this->keep_self_update_claim( $fence ) ) {
			return $this->self_update_claim_lost();
		}

		// What the directory looks like right before install() (SA#95): a
		// failed install that changed nothing needs no restore, and on a host
		// that refuses .php deletes a restore would only destroy the rest.
		$manifest = $this->plugin_manifest( WP_PLUGIN_DIR . '/' . $plugin_slug );

		// Install from the verified local file (or the URL when no digest given).
		// Heartbeaten from inside (SA#80): the install is the longest phase, and
		// renewing only around it left it seizable while it ran. The download
		// above already sits between two renewals and fires no upgrader filter.
		$result = $this->heartbeat_during( $fence, function () use ( $upgrader, $install_source ) {
			return $upgrader->install( $install_source, array( 'overwrite_package' => true ) );
		} );

		if ( '' !== $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		// The files are replaced. If the lease was seized while install() ran,
		// another self-update owns this directory NOW: it has backed up what
		// we just wrote and is installing its own build. Restoring our backup
		// over it, or rolling back on our verdict, would overwrite its work
		// (Codex #91 round-3 P1). Stop here and say so; the successor's own
		// verdict and rollback govern the outcome.
		if ( ! $this->keep_self_update_claim( $fence ) ) {
			// `installed` is a claim about what happened, so only a result that
			// IS success counts: WP_Error, false and null (an upgrader that never
			// reached its install step) are all "not proven" (Codex #94 round-1 P2).
			return $this->self_update_claim_lost_after_install( $backup_path, ! is_wp_error( $result ) && false !== $result && null !== $result );
		}

		// A FAILED install is the case that most needs the backup: `install()`
		// with `overwrite_package` deletes before it writes, so a failure
		// partway can leave this plugin's directory incomplete. Restoring here
		// is what makes a failed self-update survivable rather than terminal.
		if ( is_wp_error( $result ) ) {
			return $this->self_update_install_failed(
				$rollback,
				$plugin_slug,
				$plugin_file,
				$backup_path,
				$old_version,
				$manifest,
				$result->get_error_message()
			);
		}

		if ( false === $result ) {
			$messages = $skin->get_upgrade_messages();
			$last_msg = ! empty( $messages ) ? end( $messages ) : '';
			return $this->self_update_install_failed(
				$rollback,
				$plugin_slug,
				$plugin_file,
				$backup_path,
				$old_version,
				$manifest,
				__( 'Self-update failed — filesystem error.', 'digitizer-site-worker' ),
				$last_msg
			);
		}

		// Ensure the plugin is activated after overwrite.
		if ( ! is_plugin_active( $plugin_file ) ) {
			activate_plugin( $plugin_file );
		}

		// Clear plugin cache so WordPress reads the fresh file header.
		wp_clean_plugins_cache( true );
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// The install is only complete if the file WordPress will load is there
		// and carries a version header (Codex round-7 P1). `Plugin_Upgrader` can
		// return success for an archive that renamed or omitted the main file —
		// another PHP file with a valid header satisfies it — while the active
		// plugin entry still points at the missing one. The loopback then loads
		// nothing of ours: no beacon, no attributed fatal, and the verdict below
		// would read that as inconclusive and let a headless install stand. The
		// header on disk is a fact of the same kind the restore is held to, so it
		// is checked FIRST and a missing one is a failed install, restored like
		// any other.
		$new_version = $this->installed_version( $plugin_file );
		if ( null === $new_version || '' === $new_version ) {
			return $this->self_update_install_failed(
				$rollback,
				$plugin_slug,
				$plugin_file,
				$backup_path,
				$old_version,
				$manifest,
				__( 'Self-update installed an archive without a readable main plugin file.', 'digitizer-site-worker' )
			);
		}

		// ONE identifier for the build (Codex round-13). The beacon writers name
		// the running build by its AURA_WORKER_VERSION constant; this verdict
		// looks the records up by the version it read from the file header. An
		// archive where those two disagree would write its records under one
		// name and be looked up under another — neither found, inconclusive, a
		// broken build left standing. So the two must agree before the verdict
		// is asked, and a build where they do not is malformed: a failed
		// install, restored like any other.
		$constant_version = $this->installed_constant_version( $plugin_file );
		if ( $constant_version !== $new_version ) {
			return $this->self_update_install_failed(
				$rollback,
				$plugin_slug,
				$plugin_file,
				$backup_path,
				$old_version,
				$manifest,
				sprintf(
					/* translators: 1: header version, 2: constant version or "(missing)" */
					__( 'Self-update installed a build whose header (%1$s) and AURA_WORKER_VERSION constant (%2$s) disagree.', 'digitizer-site-worker' ),
					$new_version,
					null === $constant_version ? '(missing)' : $constant_version
				)
			);
		}

		// The verdict tells builds apart by VERSION — a record counts only when its
		// version is the new one — so a package carrying the version already
		// running is indistinguishable from the old build: a request that loaded
		// the pre-update files and fatals after the nonce is armed writes a record
		// this build would own, and a healthy replacement is rolled back on it
		// (Codex round-23 P1). Aura never sends a same-version package — the
		// rollout compares versions first — so refusing costs nothing real, and
		// it is refused like any other malformed install: restored, and said so.
		//
		// On the verified path a same-version package is refused before the
		// backup (SA#104), so it is the backstop there. Reaching it with the
		// archive's own header already read — and different — has one
		// explanation left: install() reported success and the files on disk were
		// never replaced (SA#95). Without that reading (no digest, or an archive
		// that could not be inspected) the two causes cannot be told apart, and
		// the message names both.
		if ( $new_version === $old_version ) {
			$not_replaced = null !== $package_header && '' !== $package_header && $package_header !== $old_version;
			$message      = $not_replaced
				? sprintf(
					/* translators: 1: plugin directory, 2: the version still on disk, 3: the version the verified package carries */
					__( 'Self-update did not take: install() reported success, but the files at %1$s still carry %2$s while the verified package carries %3$s — the upgrader did not replace the plugin directory.', 'digitizer-site-worker' ),
					WP_PLUGIN_DIR . '/' . $plugin_slug,
					$new_version,
					$package_header
				)
				: sprintf(
					/* translators: 1: the version already installed, 2: plugin directory */
					__( 'Self-update refused: after install() the plugin still reads the version already running (%1$s). Either the package carries that same version — a same-version build cannot be told apart from the old one by its boot records — or the upgrader did not replace the files at %2$s.', 'digitizer-site-worker' ),
					$new_version,
					WP_PLUGIN_DIR . '/' . $plugin_slug
				);
			$out         = $this->self_update_install_failed( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version, $manifest, $message );
			$out['code'] = $not_replaced ? 'aura_self_update_not_replaced' : 'aura_self_update_version_unchanged';
			return $out;
		}

		// Ask the next boot of this plugin to announce itself. Armed HERE — after
		// the install and the main-file check, immediately before the probe —
		// and not before the install (Codex round-8 P1): while `install()` runs,
		// any other front-end request is still served by the OLD build, which
		// would consume the nonce, write a beacon carrying the old version, and
		// leave the verdict a stale beacon instead of a missing one. Only a
		// request that starts after this line can answer, and by then only the
		// new build is on disk. Random, so a beacon from any earlier boot cannot
		// satisfy this verdict either.
		$boot_nonce = bin2hex( random_bytes( 16 ) );
		update_option( 'aura_worker_boot_nonce', $boot_nonce, false );

		// Files are replaced, and a lost lease here means the same thing it
		// meant after install(): a successor owns the directory, and the
		// verdict and rollback below are ITS to run, not ours (Codex #91
		// round-3 P1 — the shipped comment had it backwards: the rollback is
		// owed to the site, and the successor is the one that will pay it).
		if ( ! $this->keep_self_update_claim( $fence ) ) {
			delete_option( 'aura_worker_boot_nonce' ); // the probe we will not run
			return $this->self_update_claim_lost_after_install( $backup_path, true );
		}

		// Did THIS build come up? See `verify_self_update()`.
		$health_result = $this->verify_self_update( $new_version, $boot_nonce );
		// Whatever happened, the request for a beacon is spent.
		delete_option( 'aura_worker_boot_nonce' );
		$healthy       = ! empty( $health_result['healthy'] );

		// The verdict took a loopback round trip; a claim lost across it means
		// the rollback below is the successor's to run (SA#93, closed here).
		if ( ! $this->keep_self_update_claim( $fence ) ) {
			return $this->self_update_claim_lost_after_install( $backup_path, true, true );
		}

		if ( ! $healthy && null !== $backup_path ) {
			// Restoring works even though the on-disk plugin is broken: this
			// method, Aura_Worker_Rollback and ZipArchive are all already in
			// memory from the pre-install require above.
			$rb       = $this->attempt_rollback( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version );
			$restored = $rb['restored'];
			// The message has to agree with the fields (Codex round-2 P2). A
			// dashboard that shows only `error` was told the site had recovered
			// while `rolled_back` said otherwise — and the site may still be
			// carrying the broken build.
			$message = $restored
				? sprintf(
					/* translators: %s: version that was rolled back to */
					__( 'SiteAgent update failed its health check and was rolled back to %s.', 'digitizer-site-worker' ),
					$old_version
				)
				: __( 'SiteAgent update failed its health check AND could not be rolled back — the site may still be running the broken build.', 'digitizer-site-worker' );
			return array(
				'success'       => false,
				'error'         => $message,
				'old_version'   => $old_version,
				'new_version'   => $new_version,
				'backed_up'     => true,
				'health_checked'=> true,
				'healthy'       => false,
				'health'        => $health_result,
				'rolled_back'   => $restored,
				'restore_error' => $rb['error'],
			);
		}

		if ( ! $healthy ) {
			// Unhealthy with nothing to restore. Say so plainly instead of
			// reporting a success the site cannot support — the operator needs
			// to know this one needs hands.
			return array(
				'success'       => false,
				'error'         => __( 'SiteAgent update failed its health check and no backup was available to roll back.', 'digitizer-site-worker' ),
				'old_version'   => $old_version,
				'new_version'   => $new_version,
				'backed_up'     => false,
				'health_checked'=> true,
				'healthy'       => false,
				'health'        => $health_result,
				'rolled_back'   => false,
			);
		}

		// The update stuck, so older copies of this plugin are no longer the
		// thing anyone would restore. Bounded, not emptied: the most recent few
		// stay, because "the update succeeded" and "the new build is good" are
		// not the same claim on a site nobody has looked at yet.
		//
		// And only when there is a rollback object to ask: construction may have
		// failed above and been deliberately continued past (Codex round-14 P1) —
		// a successful update must not fatal on tidying up backups it never took.
		if ( $rollback ) {
			$rollback->cleanup_old_backups( 3 );
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				/* translators: %1$s: old version, %2$s: new version */
				__( 'SiteAgent updated from %1$s to %2$s.', 'digitizer-site-worker' ),
				$old_version,
				$new_version
			),
			'old_version'  => $old_version,
			'new_version'  => $new_version,
			'backed_up'    => null !== $backup_path,
			'health_checked'=> true,
			'healthy'      => true,
			// `verified` is the beacon, specifically: a success with `verified:
			// false` is an update that stood because the evidence was
			// INCONCLUSIVE, not because the build was seen to boot. Aura's
			// update log should be able to tell those apart.
			'verified'     => ! empty( $health_result['verified'] ),
			'health'       => $health_result,
			'rolled_back'  => false,
		);
	}

	/**
	 * The AURA_WORKER_VERSION the installed entry file DEFINES — read from the
	 * file's text, since this process is still running the old constant. This
	 * is the identifier the new build's beacon writers will use; the header is
	 * what the verdict looks records up by; the two must be the same string.
	 *
	 * Unreadable counts as absent (Codex #105 round-1 P1): the only caller
	 * treats null as a failed install, and a read that warns must not escape
	 * a host's warning-to-exception handler mid-update.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return string|null Null when the file cannot be read or the define cannot be found.
	 */
	private function installed_constant_version( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		try {
			$src = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} catch ( Throwable $e ) {
			return null;
		}
		return is_string( $src ) ? $this->constant_version_from_source( $src ) : null;
	}

	/**
	 * The version WordPress reads from a plugin's own file header, right now.
	 * The one post-condition a rollback can be held to: the old build is back
	 * only if the file on disk says so.
	 *
	 * Unreadable counts as absent (Codex #105 round-1 P1). It is read during
	 * recovery, where a warning turned into an exception by the host would end
	 * the request instead of reporting the failed restore — so the read is
	 * checked first and cannot throw. Every caller already takes null as "not
	 * the version expected": the post-install check fails the install, the
	 * rollback verify reports not restored, and the failed-restore message
	 * keeps its missing-or-incomplete warning.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return string|null Null when the file is missing or unreadable.
	 */
	private function installed_version( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		try {
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache( false );
			}
			$data = get_plugin_data( $path, false, false );
		} catch ( Throwable $e ) {
			return null;
		}
		return isset( $data['Version'] ) ? (string) $data['Version'] : null;
	}

	/**
	 * `rest_api_init` callback: emit the boot beacon for the running build.
	 * Hooked LAST from `Aura_Worker::init()`. The write itself lives in
	 * includes/boot-beacon.php, the one owner of `aura_worker_boot`.
	 */
	public static function emit_boot_beacon() {
		self::write_boot_beacon( AURA_WORKER_VERSION );
	}

	/** Thin wrapper kept for callers and tests; see aura_worker_write_boot_beacon(). */
	public static function write_boot_beacon( $version ) {
		return aura_worker_write_boot_beacon( $version );
	}

	/**
	 * Did the build that was just installed actually come up?
	 *
	 * Answered from a FACT the new build writes, not inferred from the outside.
	 * Four review rounds found a case where every inferential signal lies: the
	 * home page served from a full-page cache without starting PHP; 401/403
	 * meaning "registered" on one site and "REST closed to everyone" on
	 * another; a 500 differing from a 404 control; a log tail that was too
	 * short, or the wrong file. Each patch converged on nothing because the
	 * question — "did this code boot?" — has no answer in status codes.
	 *
	 * So: leave a nonce, make ONE uncacheable request so a fresh PHP process
	 * loads the new files, then read `aura_worker_boot`. The new build's own
	 * `aura_worker_boot_beacon()` writes it as the LAST line of init, echoing
	 * the nonce. Beacon carrying our nonce and the new version ⇒ it booted.
	 *
	 * What can still be wrong, stated rather than hidden:
	 *  - The request never reached PHP at all (transport failure: the site
	 *    cannot connect to itself). No beacon then proves nothing, and rolling
	 *    back on it would make every update fail on such a site for ever — the
	 *    unsatisfiable-gate shape Aura #472 spent months in. Reported as
	 *    `inconclusive`, no rollback, `verified: false`.
	 *  - A cache answering a REST URL that carries a random query argument and
	 *    no-cache headers. Accepted as the residual; REST is not page-cached in
	 *    any mainstream setup.
	 *
	 * Breakage is a fact too: the dying process records a fatal in one of our
	 * files against the same nonce (includes/boot-beacon.php). Nothing is read
	 * from anyone's log.
	 *
	 * @param string     $new_version  Version read from the installed header.
	 * @param string     $nonce        The nonce left in `aura_worker_boot_nonce`.
	 * @return array { healthy: bool, inconclusive: bool, checks: array }
	 */
	private function verify_self_update( $new_version, $nonce ) {
		$probe = wp_remote_get(
			add_query_arg( array( 'aura_probe' => $nonce ), rest_url( 'aura/v1/status' ) ),
			array(
				'timeout'     => 15,
				'sslverify'   => false,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ),
			)
		);
		$reached = ! is_wp_error( $probe );

		// Read the fact UNCACHED: the option cache in this process may still hold
		// the pre-install state.
		$fatal_key = aura_worker_fatal_record_key( $new_version );
		wp_cache_delete( 'aura_worker_boot', 'options' );
		wp_cache_delete( $fatal_key, 'options' );
		$boot  = get_option( 'aura_worker_boot' );
		// The NEW version's own fatal record. Per-version records (round-12) mean
		// an old build's straggler death cannot have overwritten this one.
		$fatal = get_option( $fatal_key );

		// A record counts only if it is for THIS verdict (the nonce) and names
		// THIS build (the version). The version test is what keeps a request
		// still running the OLD build — one that loaded before the install and
		// dies, or boots, after the nonce was armed — from overriding the new
		// build's record in either direction (Codex round-11).
		$for_this = function ( $record ) use ( $nonce, $new_version ) {
			return is_array( $record )
				&& isset( $record['nonce'], $record['version'] )
				&& hash_equals( $nonce, (string) $record['nonce'] )
				&& (string) $record['version'] === (string) $new_version;
		};
		// Precedence at READ time: a recorded death outranks a recorded boot.
		// Two records, two owners, no write races between them (round-11).
		$broken = $for_this( $fatal );
		$booted = ! $broken && $for_this( $boot );
		// For the detail strings only: does SOME record carry this nonce?
		$ours = ( is_array( $boot ) && isset( $boot['nonce'] ) && hash_equals( $nonce, (string) $boot['nonce'] ) )
			|| ( is_array( $fatal ) && isset( $fatal['nonce'] ) && hash_equals( $nonce, (string) $fatal['nonce'] ) );
		$beacon = $boot;

		$checks = array(
			'loopback'   => array(
				'status' => $reached ? 'pass' : 'fail',
				'detail' => $reached
					? 'HTTP ' . (int) wp_remote_retrieve_response_code( $probe )
					: $probe->get_error_message(),
			),
			'boot_beacon' => array(
				'status' => $booted ? 'pass' : 'fail',
				'detail' => $booted
					? 'build ' . $new_version . ' reported boot'
					: ( $ours ? ( $broken ? 'died before booting' : 'a different build answered' ) : ( is_array( $beacon ) ? 'stale beacon' : 'no beacon written' ) ),
			),
			'fatal_beacon' => array(
				'status' => $broken ? 'fail' : 'pass',
				'detail' => $broken
					? 'the build died in ' . (string) ( $fatal['file'] ?? '?' ) . ': ' . (string) ( $fatal['message'] ?? '' )
					: 'no fatal recorded by this plugin',
			),
		);

		// The decision rests on POSITIVE facts only, in both directions.
		//
		// Health: the boot beacon. Breakage: the fatal beacon — written by the
		// dying process itself when a fatal in one of OUR files ended a request
		// while this verdict was pending. Attribution is the file PHP names,
		// checked against our directory; another plugin's death cannot count.
		//
		// What is deliberately NOT a fact: "we got an HTTP response". A CDN, WAF
		// or reverse proxy can answer a 301 or a 403 challenge before WordPress
		// ever runs, and no beacon can be written then; treating that response
		// as proof PHP was reached rolled back healthy builds on every site
		// fronted that way — for ever (Codex round-5 P1). So `$reached` is
		// reported and decides nothing.
		//
		// No beacon of either kind is therefore INCONCLUSIVE: the update stands,
		// `verified: false`. The one case that produces that is a parse error in
		// the ENTRY FILE itself — nothing in it runs, so neither handler is ever
		// armed. Stated here rather than hidden: it is visible in Aura's update
		// log as unverified and bounded by the 5-per-night cap, and the
		// alternative — rolling back on absence of evidence — makes every
		// edge-fronted site permanently un-updatable, the worse failure and the
		// one that hides.
		$inconclusive = ! $booted && ! $broken;
		$healthy      = ! $broken && ( $booted || $inconclusive );

		return array(
			'healthy'      => $healthy,
			'inconclusive' => $inconclusive,
			'verified'     => $booted,
			'checks'       => $checks,
		);
	}

	/**
	 * Put the previous build back and PROVE it is back.
	 *
	 * The single place a rollback is decided (Codex round-4 P1: the two exits
	 * had drifted — one checked the header, one trusted the step result). Fifteen
	 * findings on this branch were one class, a success value resting on
	 * evidence that could not support it, so this returns success only on the
	 * post-condition: the plugin's own header reads the version we came from.
	 *
	 * @return array { restored: bool, error: string|null, stage: string|null }
	 *               `stage` is restore_plugin()'s failure stage, when it failed.
	 */
	private function attempt_rollback( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version ) {
		if ( null === $backup_path || null === $rollback ) {
			return array( 'restored' => false, 'error' => null, 'stage' => null );
		}
		$restore = $rollback->restore_plugin( $plugin_slug, $backup_path );
		if ( empty( $restore['success'] ) ) {
			return array(
				'restored' => false,
				'error'    => (string) ( $restore['error'] ?? 'restore failed' ),
				'stage'    => isset( $restore['stage'] ) ? (string) $restore['stage'] : null,
			);
		}
		if ( $old_version !== $this->installed_version( $plugin_file ) ) {
			return array(
				'restored' => false,
				'error'    => 'restore completed but the plugin header does not read ' . $old_version,
				'stage'    => 'verify',
			);
		}
		return array( 'restored' => true, 'error' => null, 'stage' => null );
	}

	/**
	 * Renew the self-update claim's lease. True while this request still holds it.
	 *
	 * @param string $fence The claim self_update() took.
	 * @return bool
	 */
	private function keep_self_update_claim( $fence ) {
		return Aura_Worker_Magic_Link::refresh_claim( self::SELF_UPDATE_LOCK, $fence );
	}

	/**
	 * True while the lease is ours — or when no lease was taken ('' fence:
	 * another plugin's entry, which is not serialised at all).
	 *
	 * @param string $fence The fence guarding_self() handed the work.
	 * @return bool
	 */
	private function lease_kept( $fence ) {
		return '' === (string) $fence || $this->keep_self_update_claim( $fence );
	}

	/**
	 * The batch entry for a SiteAgent mutation that lost its claim mid-way.
	 *
	 * @param string $plugin_file Plugin.
	 * @return array { plugin, status, detail }
	 */
	private function batch_entry_claim_lost( $plugin_file ) {
		return array(
			'plugin' => $plugin_file,
			'status' => 'failed',
			'detail' => 'Lost the self-update claim mid-update; another self-update took over on this site',
		);
	}

	/**
	 * Run $work with the self-update lease kept alive from INSIDE it (SA#80,
	 * Codex #91 round-2): renewing only between phases leaves a phase that
	 * runs past the takeover window seizable. WordPress's upgrader fires
	 * sub-phase filters as it goes — download, source selection, pre-install,
	 * post-install — and a throttled renewal hooked on each keeps a live
	 * request from ever looking dead. The hooks pass their value through
	 * untouched and are removed after the phase, whatever it returns.
	 *
	 * A lost lease is acted on only where WordPress itself can abort cleanly:
	 * the three pre-stage filters and `upgrader_clear_destination` answer a
	 * WP_Error, which the upgrader honours before the stage touches anything
	 * — the last of them fires immediately before the old directory is
	 * deleted, so the destructive step itself is fenced (Codex #94 round-8
	 * P1). What remains unfenced is the copy after that delete: no filter
	 * fires inside it, and a copy of this plugin runs seconds, not the
	 * ten-minute takeover window. A phase already past its last abort point
	 * cannot be stopped halfway without leaving the directory incomplete, so
	 * `upgrader_post_install` passes through and the check after the phase
	 * (`lease_kept()`) is where that loss stops the work.
	 *
	 * @param string   $fence The fence; '' runs $work plainly (no claim was taken).
	 * @param callable $work  The phase.
	 * @return mixed $work's return.
	 */
	private function heartbeat_during( $fence, $work ) {
		if ( '' === (string) $fence ) {
			return $work();
		}
		$last  = time();
		$lost  = false;
		// A failed heartbeat is REMEMBERED, and the three PRE-stage filters
		// answer a WP_Error from then on (Codex #94 round-3 P1): WordPress
		// honours a WP_Error from `upgrader_pre_download`,
		// `upgrader_source_selection` and `upgrader_pre_install` by aborting
		// BEFORE the stage runs — nothing downloaded, unpacked or written — so
		// a request whose claim was seized between two sub-phases stops at the
		// upgrader's own abort point instead of installing beside its
		// successor. `upgrader_clear_destination` is the same kind of point
		// (Codex #94 round-8 P1): WordPress deletes the old directory INSIDE
		// that filter (Plugin_Upgrader::delete_old_plugin at priority 10, which
		// returns a WP_Error it receives untouched), so a beat at priority 1
		// renews the lease right before the destructive step and a lost claim
		// stops the phase with the old files still in place.
		// `upgrader_post_install` fires after the files are replaced; aborting
		// there would only mislabel a finished install, so it passes its value
		// through and the boundary check after the phase (`lease_kept()`) is
		// what stops the work.
		$hooks = array( 'upgrader_pre_download', 'upgrader_source_selection', 'upgrader_pre_install', 'upgrader_clear_destination', 'upgrader_post_install' );
		$beats = array(); // one closure per hook: the hook's name is bound, so no current_filter() lookup
		foreach ( $hooks as $hook ) {
			$abort          = 'upgrader_post_install' !== $hook;
			$beats[ $hook ] = function ( $value ) use ( $fence, &$last, &$lost, $abort ) {
				if ( ! $lost && time() - $last >= static::LEASE_HEARTBEAT_SECONDS ) {
					$lost = ! $this->keep_self_update_claim( $fence );
					$last = time();
				}
				if ( $lost && $abort ) {
					return new WP_Error(
						'aura_self_update_claim_lost',
						__( 'SiteAgent lost its self-update claim mid-phase; another self-update took over on this site.', 'digitizer-site-worker' )
					);
				}
				return $value;
			};
			add_filter( $hook, $beats[ $hook ], 1 );
		}
		try {
			return $work();
		} finally {
			foreach ( $beats as $hook => $beat ) {
				remove_filter( $hook, $beat, 1 );
			}
		}
	}

	/**
	 * The result for a self-update whose claim was seized before it changed
	 * anything: another self-update is the one running now.
	 *
	 * @return array
	 */
	private function self_update_claim_lost() {
		return array(
			'success'     => false,
			'error'       => __( 'SiteAgent lost its self-update claim before installing; another self-update took over on this site.', 'digitizer-site-worker' ),
			'in_progress' => true,
		);
	}

	/**
	 * A self-update whose claim was seized AFTER install() replaced the
	 * files: another self-update took over and now owns the directory, so
	 * this request neither restores nor verifies. Distinct from
	 * self_update_claim_lost() (nothing was changed there).
	 *
	 * @param string|null $backup_path The backup this request took, if any.
	 * @param bool        $installed   Whether install() reported success.
	 * @return array
	 */
	private function self_update_claim_lost_after_install( $backup_path, $installed, $health_checked = false ) {
		return array(
			'success'        => false,
			'error'          => __( 'SiteAgent lost its self-update claim after installing; another self-update took over on this site and its own verdict now governs these files.', 'digitizer-site-worker' ),
			'in_progress'    => true,
			'installed'      => (bool) $installed,
			'backed_up'      => null !== $backup_path,
			'health_checked' => (bool) $health_checked,
			'rolled_back'    => false,
			'restore_error'  => null,
		);
	}

	/**
	 * Shared exit for a self-update whose install did not complete: put the
	 * previous build back when there is one, and report what happened either
	 * way. Deliberately one function — the two failure shapes above differ only
	 * in their message, and duplicating the restore would let the two drift
	 * until one of them silently stopped restoring.
	 *
	 * @param Aura_Worker_Rollback $rollback    Loaded before the install.
	 * @param string               $plugin_slug This plugin's directory name.
	 * @param string|null          $backup_path Backup zip, or null if none was made.
	 * @param array|null           $manifest    plugin_manifest() taken right before
	 *                                          install(), or null if it could not be.
	 * @param string               $error       Message describing the failure.
	 * @param string               $detail      Optional upgrader detail.
	 * @return array
	 */
	private function self_update_install_failed( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version, $manifest, $error, $detail = '' ) {
		// SA#95: an install that failed before it touched the directory left
		// the previous build exactly where it was. Restoring it anyway starts
		// with a recursive delete — on a host that refuses .php deletes, that
		// removed every other file and then stopped. Only a manifest that was
		// taken, and still matches, skips the restore; anything else restores
		// as before.
		if ( null !== $backup_path && null !== $rollback && null !== $manifest
			&& $manifest === $this->plugin_manifest( WP_PLUGIN_DIR . '/' . $plugin_slug ) ) {
			$out = array(
				'success'         => false,
				'error'           => $error . ' ' . __( 'The install failed before it changed any file in the plugin directory, so nothing was restored: the previous build is intact.', 'digitizer-site-worker' ),
				'backed_up'       => true,
				'health_checked'  => false,
				'rolled_back'     => false,
				'restore_skipped' => 'unchanged',
				'restore_error'   => null,
			);
			if ( '' !== $detail ) {
				$out['detail'] = $detail;
			}
			return $out;
		}

		$rb            = $this->attempt_rollback( $rollback, $plugin_slug, $plugin_file, $backup_path, $old_version );
		$restored      = $rb['restored'];
		$restore_error = $rb['error'];

		// The message must carry the recovery outcome too (Codex round-4 P2): a
		// consumer showing only `error` was told about the upgrader failure and
		// not that the plugin may now be missing.
		//
		// Except where that warning is not what happened (SA#104): a restore that
		// could not REMOVE the installed directory refuses to extract over it, so
		// the directory holds what the install left there — less whatever the
		// failed removal got to — and not a half-extracted backup. When the file
		// WordPress loads is still there and readable, say that, with the version
		// it reads, instead of calling the plugin missing. No readable main file
		// is the missing-plugin case, and keeps the warning.
		//
		// And a restore refused by its own preflight (SA#95, `stage: preflight`)
		// deleted nothing at all: the directory is exactly what the failed
		// install left there.
		if ( null !== $backup_path && ! $restored ) {
			$preflight = 'preflight' === $rb['stage'];
			$on_disk   = ( 'clear' === $rb['stage'] || $preflight ) ? $this->installed_version( $plugin_file ) : null;
			if ( $preflight ) {
				$error .= ' ' . __( 'The previous build was not restored: the restore refused before deleting anything, because this host does not let it write and delete the plugin\'s files. The plugin directory holds what the failed install left there.', 'digitizer-site-worker' );
				$error .= ' ' . ( ( null !== $on_disk && '' !== $on_disk )
					? sprintf(
						/* translators: %s: the version the plugin's main file reads now */
						__( 'The plugin\'s main file is in place and reads version %s.', 'digitizer-site-worker' ),
						$on_disk
					)
					: __( 'The plugin\'s main file could not be read — the plugin may be missing or incomplete.', 'digitizer-site-worker' ) );
			} elseif ( null !== $on_disk && '' !== $on_disk ) {
				$error .= ' ' . sprintf(
					/* translators: %s: the version the plugin's main file reads now */
					__( 'The previous build was not restored: the installed plugin directory could not be removed, so the backup was not extracted over it. The plugin\'s main file is still in place and reads version %s; other files in the directory may have been removed.', 'digitizer-site-worker' ),
					$on_disk
				);
			} else {
				$error .= ' ' . __( 'The previous build could NOT be restored — the plugin may be missing or incomplete.', 'digitizer-site-worker' );
			}
		}

		$out = array(
			'success'       => false,
			'error'         => $error,
			'backed_up'     => null !== $backup_path,
			'health_checked'=> false,
			'rolled_back'   => $restored,
			'restore_error' => $restore_error,
		);
		if ( '' !== $detail ) {
			$out['detail'] = $detail;
		}
		return $out;
	}

	/**
	 * A bounded description of a plugin directory (SA#95): for every entry,
	 * its type, size, mtime, mode and — for a file — a hash of its bytes; a
	 * link by its target, never followed. Equal manifests before and after a
	 * failed install mean the install changed nothing there.
	 *
	 * The hash and mode go beyond "size and mtime" on purpose: mtime is whole
	 * seconds, and a same-size rewrite inside the second the manifest was
	 * taken would otherwise read as unchanged and skip a restore that was
	 * owed. A mismatch only ever costs a restore that would have run anyway.
	 * The bound (entries and bytes) keeps it cheap: SiteAgent itself — the
	 * one plugin this runs for — is well under a megabyte.
	 *
	 * @param string $dir Absolute plugin directory.
	 * @return array|null Null when the walk could not finish, or exceeded
	 *                    MANIFEST_MAX_ENTRIES — the caller then restores as
	 *                    it always did.
	 */
	private function plugin_manifest( $dir ) {
		clearstatcache();
		try {
			if ( is_link( $dir ) ) {
				return array( '' => 'l:' . (string) readlink( $dir ) );
			}
			if ( ! is_dir( $dir ) ) {
				return array( '' => 'absent' );
			}
			$bytes    = 0;
			$max      = static::MANIFEST_MAX_BYTES;
			$describe = static function ( $path, $is_link, $is_dir ) use ( &$bytes, $max ) {
				if ( $is_link ) {
					return 'l:' . (string) readlink( $path );
				}
				$st = stat( $path );
				if ( false === $st ) {
					throw new RuntimeException( 'stat failed' );
				}
				if ( $is_dir ) {
					return 'd:' . $st['mode'];
				}
				$bytes += (int) $st['size'];
				if ( $bytes > $max ) {
					throw new RuntimeException( 'too large' );
				}
				$hash = is_readable( $path ) ? @hash_file( 'md5', $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $hash ) {
					throw new RuntimeException( 'unreadable' );
				}
				return 'f:' . $st['size'] . ':' . $st['mtime'] . ':' . $st['mode'] . ':' . $hash;
			};
			$out      = array( '' => $describe( $dir, false, true ) );
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $entry ) {
				if ( count( $out ) > static::MANIFEST_MAX_ENTRIES ) {
					return null;
				}
				$path = $entry->getPathname();
				$out[ substr( $path, strlen( $dir ) ) ] = $describe( $path, $entry->isLink(), $entry->isDir() );
			}
		} catch ( Throwable $e ) {
			return null;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The result for a verified package that carries the version already
	 * running, refused before anything on the site was touched (SA#104).
	 * Same keys a failed self-update answers — `success`/`error` first, so a
	 * caller that shows only those reads it right — with `code` beside them
	 * and every recovery field saying nothing happened.
	 *
	 * @param string $old_version     The running AURA_WORKER_VERSION.
	 * @param array  $package_version verified_package_version()'s answer.
	 * @return array
	 */
	private function self_update_same_version_refused( $old_version, $package_version ) {
		$carried = (string) ( $package_version['header'] ?? '' );
		if ( '' === $carried ) {
			$carried = (string) $package_version['constant'];
		} elseif ( null !== $package_version['constant'] && $package_version['constant'] !== $carried ) {
			$carried = sprintf( '%1$s (AURA_WORKER_VERSION %2$s)', $carried, $package_version['constant'] );
		}
		return array(
			'success'        => false,
			'error'          => sprintf(
				/* translators: 1: the version the package carries, 2: the version already running */
				__( 'Self-update refused before changing anything: the package carries version %1$s and SiteAgent %2$s is already running. Nothing on the site was changed — no backup was taken and no plugin files were touched.', 'digitizer-site-worker' ),
				$carried,
				$old_version
			),
			'code'           => 'aura_self_update_same_version',
			'old_version'    => $old_version,
			'new_version'    => $carried,
			'installed'      => false,
			'backed_up'      => false,
			'health_checked' => false,
			'rolled_back'    => false,
			'restore_error'  => null,
		);
	}

	/**
	 * Whether this process can look inside a zip. A seam: ext-zip is optional
	 * in PHP, and a test cannot unload the class.
	 *
	 * @return bool
	 */
	protected function package_inspection_available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * The version a downloaded, verified package would install — read from
	 * INSIDE the archive, without extracting anything.
	 *
	 * Only the entry the upgrader will put where WordPress loads this plugin
	 * counts: `SELF_PLUGIN_FILE` under the archive's `digitizer-site-worker/`
	 * top-level directory. A header in any other file, or this file under
	 * another directory, is not what the site will run and decides nothing.
	 *
	 * @param string $zip_path The verified local package.
	 * @return array|null { header: string|null, constant: string|null }, or null
	 *                    when the archive or its main file cannot be read, or
	 *                    the file names no version at all — the caller then
	 *                    proceeds as it did before this check existed.
	 */
	private function verified_package_version( $zip_path ) {
		if ( ! $this->package_inspection_available() ) {
			return null;
		}
		try {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				return null;
			}
			$src = $zip->getFromName( self::SELF_PLUGIN_FILE );
			$zip->close();
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! is_string( $src ) || '' === $src ) {
			return null;
		}
		$header   = $this->header_version_from_source( $src );
		$constant = $this->constant_version_from_source( $src );
		if ( null === $header && null === $constant ) {
			return null;
		}
		return array(
			'header'   => $header,
			'constant' => $constant,
		);
	}

	/**
	 * A plugin file's `Version:` header, read the way WordPress's
	 * get_file_data() reads it: the first 8 KB, the same pattern, the same
	 * comment cleanup.
	 *
	 * @param string $src File contents.
	 * @return string|null
	 */
	private function header_version_from_source( $src ) {
		$head = str_replace( "\r", "\n", substr( (string) $src, 0, 8192 ) );
		if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi', $head, $m ) && '' !== $m[1] ) {
			$version = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
			return '' === $version ? null : $version;
		}
		return null;
	}

	/**
	 * The AURA_WORKER_VERSION a plugin file's source defines.
	 *
	 * @param string $src File contents.
	 * @return string|null Null when the define cannot be found.
	 */
	private function constant_version_from_source( $src ) {
		if ( preg_match( "/define\\(\\s*'AURA_WORKER_VERSION'\\s*,\\s*'([^']*)'\\s*\\)/", (string) $src, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Verify a downloaded zip's bytes against the gateway-bound SHA-256.
	 *
	 * @param string $file            Path to the downloaded zip.
	 * @param string $expected_sha256 Expected lower-case hex SHA-256.
	 * @return true|WP_Error True when the file matches; WP_Error otherwise.
	 */
	private function verify_zip_integrity( $file, $expected_sha256 ) {
		$expected = strtolower( trim( (string) $expected_sha256 ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $expected ) ) {
			return new WP_Error(
				'aura_self_update_bad_digest',
				__( 'Self-update integrity check failed: malformed expected digest.', 'digitizer-site-worker' )
			);
		}
		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return new WP_Error(
				'aura_self_update_integrity',
				__( 'Self-update integrity check failed: downloaded package does not match the expected SHA-256.', 'digitizer-site-worker' )
			);
		}
		return true;
	}

	/**
	 * One plugin of a batch: backup, update, health check, rollback on failure.
	 *
	 * @param string               $plugin_file   Plugin file path.
	 * @param Aura_Worker_Rollback $rollback      Shared rollback helper.
	 * @param Aura_Worker_Health   $health        Shared health checker.
	 * @param bool                 $create_backup Whether to back up first.
	 * @param string               $fence         The self-update claim this entry
	 *                                            runs under, or '' when the plugin
	 *                                            is not SiteAgent (SA#80).
	 * @return array { plugin, status, detail }
	 */
	protected function batch_update_one( $plugin_file, $rollback, $health, $create_backup, $fence = '' ) {
		$slug        = dirname( $plugin_file );
		$backup_path = null;
		$entry       = array(
			'plugin'  => $plugin_file,
			'status'  => 'skipped',
			'detail'  => '',
		);

		// 1. Backup.
		if ( $create_backup ) {
			$backup_result = $rollback->backup_plugin( $slug );
			if ( $backup_result['success'] ) {
				$backup_path = $backup_result['backup_path'];
			} else {
				$entry['status'] = 'failed';
				$entry['detail'] = 'Backup failed: ' . $backup_result['error'];
				return $entry;
			}
		}

		// The lease is renewed between phases when this entry is SiteAgent's
		// own (SA#80): a generic mutation that outlived the ten-minute window
		// could be seized and run beside its successor. Losing it means another
		// self-update owns these files now — stop, and touch nothing further.
		if ( ! $this->lease_kept( $fence ) ) {
			return $this->batch_entry_claim_lost( $plugin_file );
		}

		// 2. Update.
		$update_result = $this->heartbeat_during( $fence, function () use ( $plugin_file ) {
			return $this->update_single_plugin( $plugin_file );
		} );
		if ( ! $update_result['success'] ) {
			$entry['status'] = 'failed';
			$entry['detail'] = $update_result['error'];
			return $entry;
		}

		if ( ! $this->lease_kept( $fence ) ) {
			return $this->batch_entry_claim_lost( $plugin_file );
		}

		// 3. Health check.
		$health_result = $health->run_health_check();
		// The probe is a loopback request that can outlive the window too; a
		// claim lost across it means the rollback below belongs to the
		// successor (SA#93, closed here).
		if ( ! $this->lease_kept( $fence ) ) {
			return $this->batch_entry_claim_lost( $plugin_file );
		}
		if ( ! $health_result['healthy'] ) {
			// 4. Auto-rollback.
			if ( $backup_path ) {
				$restore_result  = $rollback->restore_plugin( $slug, $backup_path );
				$entry['status'] = 'rolled_back';
				$entry['detail'] = 'Health check failed; rollback ' . ( $restore_result['success'] ? 'succeeded' : 'failed: ' . $restore_result['error'] );
			} else {
				$entry['status'] = 'failed';
				$entry['detail'] = 'Health check failed; no backup available for rollback';
			}
			return $entry;
		}

		// 5. Success.
		$entry['status'] = 'updated';
		$entry['detail'] = 'Update and health check passed';
		return $entry;
	}

	/**
	 * Restore a plugin from a backup, under the self-update claim when the
	 * plugin is SiteAgent itself. The generic rollback route restored these
	 * files with no claim taken (Codex round-24 P1), so it could delete and
	 * re-extract the directory while a locked self-update was backing up,
	 * installing or probing it.
	 *
	 * @param Aura_Worker_Rollback $rollback    Rollback helper (owns the backups).
	 * @param string               $plugin_slug Plugin folder name.
	 * @param string               $backup_path Backup zip to restore.
	 * @return array restore_plugin()'s result, or the shared busy result.
	 */
	public function restore_plugin_guarded( $rollback, $plugin_slug, $backup_path ) {
		$plugin_file = 'digitizer-site-worker' === $plugin_slug ? self::SELF_PLUGIN_FILE : $plugin_slug . '/-';
		$lost        = false;
		$result      = $this->guarding_self( $plugin_file, function ( $fence ) use ( $rollback, $plugin_slug, $backup_path, &$lost ) {
			$this->before_guarded_restore( $fence );
			// Renewed right before the restore (SA#80). The restore itself is
			// one ZipArchive::extractTo() of this plugin's own zip — sub-second,
			// with no seam inside it to heartbeat on, and no safe way to stop
			// halfway — so it is the one phase kept indivisible.
			if ( ! $this->lease_kept( $fence ) ) {
				$lost = true;
				return null;
			}
			return $rollback->restore_plugin( $plugin_slug, $backup_path );
		}, $busy, $refused );
		if ( null !== $refused ) {
			// A host refusal (SA#95) is the restore's own preflight, answered
			// before the claim: nothing was deleted, and the stage says so.
			if ( isset( $refused['php_writes'] ) ) {
				$refused['stage'] = 'preflight';
			}
			return $refused;
		}
		if ( $busy || $lost ) {
			return $this->self_update_busy();
		}
		return $result;
	}

	/**
	 * Seam between taking the claim and renewing it before a guarded restore.
	 * Nothing in production; a test ages the claim here.
	 *
	 * @param string $fence The fence.
	 */
	protected function before_guarded_restore( $fence ) {
	}

	/**
	 * Run $work under the self-update claim when $plugin_file is this plugin;
	 * run it plainly for any other plugin. $busy is set when the claim is held
	 * by a self-update, in which case $work is not run and null is returned.
	 *
	 * @param string   $plugin_file Plugin being mutated.
	 * @param callable $work        The mutation; receives the fence ('' when no
	 *                              claim was taken) so it can renew the lease
	 *                              between long phases (SA#80).
	 * @param bool     $busy        Out: true when refused for a held claim.
	 * @param array|null $refused   Out: the SA#79 multisite refusal, the SA#95
	 *                              host refusal, or null.
	 * @param string|null $verdict  A host probe verdict the caller already
	 *                              took (the batch probes once), or null to
	 *                              probe here.
	 * @return mixed $work's return, or null when busy or refused.
	 */
	private function guarding_self( $plugin_file, $work, &$busy, &$refused = null, $verdict = null ) {
		$busy    = false;
		$refused = $this->self_mutation_refusal( $plugin_file );
		if ( null !== $refused ) {
			return null; // SA#79: nothing runs, no claim is taken
		}
		// SA#95: every plugin, not only this one — the host refuses .php
		// writes for all of them. After the multisite refusal, before the
		// claim and the work.
		$refused = $this->host_refusal( $verdict );
		if ( null !== $refused ) {
			return null;
		}
		if ( self::SELF_PLUGIN_FILE !== $plugin_file ) {
			return $work( '' ); // no claim: another plugin's files are not ours to serialise
		}
		if ( ! class_exists( 'Aura_Worker_Magic_Link' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-magic-link.php';
		}
		$fence = Aura_Worker_Magic_Link::take_claim( self::SELF_UPDATE_LOCK, 10 * MINUTE_IN_SECONDS );
		if ( '' === $fence ) {
			$busy = true;
			return null;
		}
		try {
			return $work( $fence );
		} finally {
			Aura_Worker_Magic_Link::release_claim( self::SELF_UPDATE_LOCK, $fence );
		}
	}

	/**
	 * The refusal every Aura-driven mutation of SiteAgent's OWN files answers
	 * on a multisite network (SA#79): the self-update claim is stored per blog
	 * while the plugin directory is shared by the whole network, so two
	 * subsites could each take their own claim and replace the same files
	 * concurrently. Until the claim lives in network-wide state, every path
	 * that reaches this directory under that claim — self_update(), the
	 * generic single update, the batch entry, the guarded rollback — refuses
	 * before any claim, download or write, rather than racing. Other plugins
	 * are not this plugin's files and are not refused.
	 *
	 * @param string $plugin_file Plugin being mutated.
	 * @return array|null The refusal, or null when the mutation may proceed.
	 */
	private function self_mutation_refusal( $plugin_file ) {
		if ( self::SELF_PLUGIN_FILE !== $plugin_file || ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return null;
		}
		return array(
			'success'     => false,
			'code'        => 'aura_self_update_multisite_unsupported',
			'error'       => __( 'Updating SiteAgent through Aura is not supported on a multisite network: the update lock is per site while the plugin directory is shared. Update the plugin from the network admin.', 'digitizer-site-worker' ),
			'in_progress' => false,
		);
	}

	/**
	 * The result every SiteAgent-mutating path answers while a self-update
	 * holds the claim.
	 *
	 * @return array
	 */
	private function self_update_busy() {
		return array(
			'success'     => false,
			'error'       => __( 'A SiteAgent self-update is already in progress on this site.', 'digitizer-site-worker' ),
			'in_progress' => true,
		);
	}

	/**
	 * Update a specific plugin.
	 *
	 * @param string $plugin_file Plugin file path (e.g., "akismet/akismet.php").
	 * @return array Result with success status and message.
	 */
	public function update_plugin( $plugin_file ) {
		$this->load_upgrade_dependencies();

		// This plugin's own update goes under the self-update claim, like every
		// other path that can replace these files (Codex round-23 P1): a generic
		// update landing between a self-update's backup, install and probe would
		// have the beacon describing one build and the rollback restoring another.
		$lost   = false;
		$result = $this->guarding_self( $plugin_file, function ( $fence ) use ( $plugin_file, &$lost ) {
			$r = $this->heartbeat_during( $fence, function () use ( $plugin_file ) {
				$skin     = new Automatic_Upgrader_Skin();
				$upgrader = new Plugin_Upgrader( $skin );
				return $upgrader->upgrade( $plugin_file );
			} );
			// A claim lost during the phase (a heartbeat that fired only at
			// post-install passes through) is a successor owning these files:
			// the outcome is ITS to report, never a success from here
			// (Codex #94 round-5 P2).
			if ( ! $this->lease_kept( $fence ) ) {
				$lost = true;
			}
			return $r;
		}, $busy, $refused );
		if ( null !== $refused ) {
			return $refused;
		}
		if ( $busy || $lost ) {
			return $this->self_update_busy();
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Update failed. The plugin may not have an update available.', 'digitizer-site-worker' ),
			);
		}

		if ( null === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'No update available for this plugin.', 'digitizer-site-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Plugin updated successfully.', 'digitizer-site-worker' ),
		);
	}

	/**
	 * Update a specific theme.
	 *
	 * @param string $theme_slug Theme stylesheet slug.
	 * @return array Result with success status and message.
	 */
	public function update_theme( $theme_slug ) {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $theme_slug );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Update failed. The theme may not have an update available.', 'digitizer-site-worker' ),
			);
		}

		if ( null === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'No update available for this theme.', 'digitizer-site-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Theme updated successfully.', 'digitizer-site-worker' ),
		);
	}

	/**
	 * Update WordPress core.
	 *
	 * @return array Result with success status and message.
	 */
	public function update_core() {
		$this->load_upgrade_dependencies();

		$updates = get_core_updates();

		if ( empty( $updates ) || ! is_array( $updates ) || 'latest' === $updates[0]->response ) {
			return array(
				'success' => true,
				'message' => __( 'WordPress is already up to date.', 'digitizer-site-worker' ),
			);
		}

		$update   = $updates[0];
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Core update failed (filesystem error).', 'digitizer-site-worker' ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: WordPress version */
				__( 'WordPress updated to %s.', 'digitizer-site-worker' ),
				$update->version
			),
		);
	}

	/**
	 * Update all translations.
	 *
	 * @return array Result with success status and message.
	 */
	public function update_translations() {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Language_Pack_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade();

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => __( 'Translation update failed.', 'digitizer-site-worker' ),
			);
		}

		$updated_count = is_array( $result ) ? count( array_filter( $result ) ) : 0;

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of translations updated */
				__( '%d translation(s) updated.', 'digitizer-site-worker' ),
				$updated_count
			),
		);
	}

	/**
	 * Update a single plugin using Plugin_Upgrader.
	 *
	 * @param string $plugin_file Plugin file path (e.g., "akismet/akismet.php").
	 * @return array { success: bool, error?: string }
	 */
	protected function update_single_plugin( $plugin_file ) {
		$this->load_upgrade_dependencies();

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'error' => $result->get_error_message() );
		}

		if ( false === $result || null === $result ) {
			return array( 'success' => false, 'error' => __( 'Update failed or no update available.', 'digitizer-site-worker' ) );
		}

		return array( 'success' => true );
	}

	/**
	 * Update plugins in chunks with optional backup and health-check auto-rollback.
	 *
	 * For each plugin:
	 *   1. Backup (if $create_backup is true) using Aura_Worker_Rollback.
	 *   2. Update using Plugin_Upgrader.
	 *   3. Health check using Aura_Worker_Health.
	 *   4. If health check fails → auto-rollback from backup.
	 *   5. Record result (updated / failed / rolled_back / skipped).
	 *
	 * Between chunks: wp_cache_flush() and gc_collect_cycles().
	 * After all chunks: cleanup old backups.
	 *
	 * @param array $plugins       List of plugin file paths (e.g. ["akismet/akismet.php"]).
	 * @param int   $chunk_size    Number of plugins to process per chunk (default 5).
	 * @param bool  $create_backup Whether to create a backup before each update (default true).
	 * @return array { results: array, summary: array }
	 */
	public function batch_update_plugins( $plugins, $chunk_size = 5, $create_backup = true ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-health.php';
		require_once plugin_dir_path( __FILE__ ) . 'class-aura-worker-rollback.php';

		// SA#95: one probe for the whole batch, taken before the recovery
		// helper is built — its constructor may create the backup directory,
		// and a host that refuses .php writes refuses every entry anyway.
		$verdict  = $this->host_php_writes_verdict();
		$refusing = null !== Aura_Worker_Host_Probe::refusal( $verdict );

		$results  = array();
		$rollback = $refusing ? null : new Aura_Worker_Rollback();
		$health   = new Aura_Worker_Health();
		$chunks   = array_chunk( $plugins, max( 1, (int) $chunk_size ) );

		foreach ( $chunks as $chunk ) {
			foreach ( $chunk as $plugin_file ) {
				// SiteAgent's own entry runs under the self-update claim (Codex
				// round-23 P1); while a self-update holds it, the entry is skipped
				// and says why, and the rest of the batch is unaffected.
				$entry = $this->guarding_self( $plugin_file, function ( $fence ) use ( $plugin_file, $rollback, $health, $create_backup ) {
					return $this->batch_update_one( $plugin_file, $rollback, $health, $create_backup, $fence );
				}, $busy, $refused, $verdict );
				if ( null !== $refused ) {
					$entry = array(
						'plugin' => $plugin_file,
						'status' => 'failed',
						'detail' => $refused['error'],
						'code'   => $refused['code'],
					);
				} elseif ( $busy ) {
					$entry = array(
						'plugin' => $plugin_file,
						'status' => 'skipped',
						'detail' => 'A SiteAgent self-update is in progress on this site',
					);
				}
				$results[] = $entry;
			}

			// Between chunks: flush caches and run garbage collection.
			wp_cache_flush();
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Cleanup old backups after all chunks.
		if ( $rollback ) {
			$rollback->cleanup_old_backups();
		}

		// Build summary.
		$summary = array(
			'total'       => count( $plugins ),
			'updated'     => 0,
			'failed'      => 0,
			'rolled_back' => 0,
			'skipped'     => 0,
		);
		foreach ( $results as $r ) {
			if ( isset( $summary[ $r['status'] ] ) ) {
				$summary[ $r['status'] ]++;
			}
		}

		return array(
			'results' => $results,
			'summary' => $summary,
		);
	}

	/**
	 * Get the plugin migration registry.
	 *
	 * Maps known plugin slugs to their detection, pending-check, and
	 * migration callables. Third-party plugins can register their own
	 * entries via the `aura_worker_migration_registry` filter.
	 *
	 * @return array Keyed array of migration entries.
	 */
	private function get_migration_registry() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$registry = array(
			'elementor'     => array(
				'label'   => 'Elementor',
				'detect'  => function () {
					return defined( 'ELEMENTOR_VERSION' ) && is_plugin_active( 'elementor/elementor.php' );
				},
				'pending' => function () {
					if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'elementor_version', '0' );
					return version_compare( $db_ver, ELEMENTOR_VERSION, '<' );
				},
				'run'     => function () {
					if ( ! class_exists( '\Elementor\Plugin' ) ) {
						return;
					}
					\Elementor\Plugin::instance()->files_manager->clear_cache();

					$upgrade = \Elementor\Plugin::instance()->upgrade ?? null;
					if ( ! $upgrade ) {
						return;
					}

					// Run upgrade callbacks directly instead of using
					// do_upgrade() which dispatches a background runner via
					// loopback HTTP — that blocks in REST API context and
					// fails when DISABLE_WP_CRON is set.
					$callbacks = $upgrade->get_upgrade_callbacks();
					foreach ( $callbacks as $callback ) {
						if ( is_callable( $callback ) ) {
							call_user_func( $callback, $upgrade );
						}
					}

					$upgrade->on_runner_complete( true );
				},
			),
			'elementor-pro' => array(
				'label'   => 'Elementor Pro',
				'detect'  => function () {
					return defined( 'ELEMENTOR_PRO_VERSION' ) && is_plugin_active( 'elementor-pro/elementor-pro.php' );
				},
				'pending' => function () {
					if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'elementor_pro_version', '0' );
					return version_compare( $db_ver, ELEMENTOR_PRO_VERSION, '<' );
				},
				'run'     => function () {
					if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
						return;
					}

					$upgrade = \ElementorPro\Plugin::instance()->upgrade ?? null;
					if ( ! $upgrade ) {
						return;
					}

					$callbacks = $upgrade->get_upgrade_callbacks();
					foreach ( $callbacks as $callback ) {
						if ( is_callable( $callback ) ) {
							call_user_func( $callback, $upgrade );
						}
					}

					$upgrade->on_runner_complete( true );
				},
			),
			'woocommerce'   => array(
				'label'   => 'WooCommerce',
				'detect'  => function () {
					return defined( 'WC_VERSION' ) && is_plugin_active( 'woocommerce/woocommerce.php' );
				},
				'pending' => function () {
					if ( ! defined( 'WC_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'woocommerce_db_version', '0' );
					return version_compare( $db_ver, WC_VERSION, '<' );
				},
				'run'     => function () {
					if ( class_exists( 'WC_Install' ) ) {
						\WC_Install::install();
					}
				},
			),
			'jet-engine'    => array(
				'label'   => 'JetEngine (Crocoblock)',
				'detect'  => function () {
					return defined( 'JET_ENGINE_VERSION' ) && is_plugin_active( 'jet-engine/jet-engine.php' );
				},
				'pending' => function () {
					if ( ! defined( 'JET_ENGINE_VERSION' ) ) {
						return false;
					}
					$db_ver = get_option( 'jet_engine_db_version', '0' );
					return version_compare( $db_ver, JET_ENGINE_VERSION, '<' );
				},
				'run'     => function () {
					if ( function_exists( 'jet_engine' ) && isset( jet_engine()->update_db_updater ) ) {
						jet_engine()->update_db_updater->update_db();
					}
				},
			),
		);

		/**
		 * Filter the plugin migration registry.
		 *
		 * Allows third-party plugins to register their own database
		 * migration handlers without modifying SiteAgent core.
		 *
		 * @param array $registry Keyed array of migration entries.
		 */
		return apply_filters( 'aura_worker_migration_registry', $registry );
	}

	/**
	 * Get database migration status for all detected plugins.
	 *
	 * Returns which plugins are installed and whether they have
	 * pending database migrations.
	 *
	 * @return array Keyed array of { label, pending } per plugin.
	 */
	public function get_database_status() {
		$registry   = $this->get_migration_registry();
		$migrations = array();

		foreach ( $registry as $key => $entry ) {
			if ( call_user_func( $entry['detect'] ) ) {
				$migrations[ $key ] = array(
					'label'   => $entry['label'],
					'pending' => (bool) call_user_func( $entry['pending'] ),
				);
			}
		}

		return $migrations;
	}

	/**
	 * Run database upgrade.
	 *
	 * When $plugin is null, runs WordPress core dbDelta (wp_upgrade).
	 * When $plugin is a registry key, runs that plugin's migration.
	 *
	 * @param string|null $plugin Optional plugin key from migration registry.
	 * @return array Result with success status.
	 */
	public function update_database( $plugin = null ) {
		// Extend execution time for potentially long migrations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- Required for long-running DB migrations.
		}

		// Plugin-specific migration.
		if ( $plugin ) {
			$registry = $this->get_migration_registry();

			if ( ! isset( $registry[ $plugin ] ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Unknown plugin migration key.', 'digitizer-site-worker' ),
				);
			}

			$entry = $registry[ $plugin ];

			if ( ! call_user_func( $entry['detect'] ) ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: Plugin label */
						__( '%s is not installed or active.', 'digitizer-site-worker' ),
						$entry['label']
					),
				);
			}

			$is_async = ! empty( $entry['async'] );

			try {
				call_user_func( $entry['run'] );
			} catch ( \Throwable $e ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %1$s: Plugin label, %2$s: Error message */
						__( '%1$s migration failed: %2$s', 'digitizer-site-worker' ),
						$entry['label'],
						$e->getMessage()
					),
				);
			}

			if ( $is_async ) {
				return array(
					'success' => true,
					'async'   => true,
					'message' => sprintf(
						/* translators: %s: Plugin label */
						__( '%s database migration triggered. It will complete in the background — poll database-status to check progress.', 'digitizer-site-worker' ),
						$entry['label']
					),
				);
			}

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: Plugin label */
					__( '%s database migration completed.', 'digitizer-site-worker' ),
					$entry['label']
				),
			);
		}

		// Core WordPress database upgrade (default).
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$db_version_before = get_option( 'db_version' );

		// wp_upgrade() has no return value, so wrap it and verify the result by
		// comparing the stored db_version against the target $wp_db_version.
		try {
			wp_upgrade();
		} catch ( \Throwable $e ) {
			return array(
				'success'   => false,
				'error'     => sprintf(
					/* translators: %s: Error message */
					__( 'Database upgrade failed: %s', 'digitizer-site-worker' ),
					$e->getMessage()
				),
				'db_before' => $db_version_before,
			);
		}

		$db_version_after = get_option( 'db_version' );

		// $wp_db_version is the target schema version WordPress expects.
		$target = isset( $GLOBALS['wp_db_version'] ) ? (int) $GLOBALS['wp_db_version'] : null;
		if ( null !== $target && (int) $db_version_after !== $target ) {
			return array(
				'success'   => false,
				'error'     => __( 'Database upgrade did not reach the expected version.', 'digitizer-site-worker' ),
				'db_before' => $db_version_before,
				'db_after'  => $db_version_after,
				'db_target' => $target,
			);
		}

		return array(
			'success'   => true,
			'message'   => __( 'Database tables updated.', 'digitizer-site-worker' ),
			'db_before' => $db_version_before,
			'db_after'  => $db_version_after,
			'changed'   => $db_version_before !== $db_version_after,
		);
	}
}
