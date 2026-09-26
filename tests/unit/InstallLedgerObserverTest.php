<?php
/**
 * The install ledger observes the upgrader and never decides (Aura spec
 * 2026-09-21 §4.1–§4.2, amended in Aura#594).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class InstallLedgerObserverTest extends TestCase {

	const NOW = 1790424000;

	/** Every probe fact false/empty: a context nothing can be read from. */
	private function quiet( array $over = array() ): array {
		return array_merge(
			array(
				'now'               => self::NOW,
				'siteagent'         => false,
				'wp_cli'            => false,
				'auto_update'       => false,
				'cron'              => false,
				'rest'              => false,
				'admin'             => false,
				'rest_cookie'       => false,
				'user_id'           => 0,
				'app_password_uuid' => '',
				'route'             => null,
				'uploads'           => array( 'basedir' => '/srv/wp-content/uploads', 'baseurl' => 'https://example.com/wp-content/uploads' ),
				'attachment_ids'    => array(),
				'versions'          => array( '/srv/wp-content/plugins/foo' => '1.2.0', '/srv/wp-content/themes/bar' => '3.0' ),
			),
			$over
		);
	}

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet() );
		Aura_Worker_Install_Ledger::ensure_started();
	}

	/** Drive one run through the three filters the way WP_Upgrader::run() does. */
	private function run_package( array $hook_extra, $package, $result = null, $reply = false ) {
		$options = Aura_Worker_Install_Ledger::on_package_options( array( 'package' => $package, 'hook_extra' => $hook_extra ) );
		$extra   = $options['hook_extra'];
		Aura_Worker_Install_Ledger::on_pre_download( $reply, $package, null, $extra );
		$result = null === $result ? array( 'destination_name' => 'foo', 'destination' => '/srv/wp-content/plugins/foo' ) : $result;
		return array( $extra, Aura_Worker_Install_Ledger::on_install_result( $result, $extra ) );
	}

	private function entries(): array {
		return Aura_Worker_Install_Ledger::report()['entries'];
	}

	// ---- scope ----

	public function test_type_of_reads_type_first_then_the_named_key(): void {
		$this->assertSame( 'plugin', Aura_Worker_Install_Ledger::type_of( array( 'type' => 'plugin', 'action' => 'install' ) ) );
		$this->assertSame( 'theme', Aura_Worker_Install_Ledger::type_of( array( 'type' => 'theme', 'action' => 'install' ) ) );
		$this->assertSame( 'plugin', Aura_Worker_Install_Ledger::type_of( array( 'plugin' => 'foo/foo.php' ) ) );
		$this->assertSame( 'theme', Aura_Worker_Install_Ledger::type_of( array( 'theme' => 'bar' ) ) );
		$this->assertNull( Aura_Worker_Install_Ledger::type_of( array( 'language_update_type' => 'plugin', 'language_update' => (object) array() ) ) );
		$this->assertNull( Aura_Worker_Install_Ledger::type_of( array() ) );
		$this->assertNull( Aura_Worker_Install_Ledger::type_of( 'nope' ) );
	}

	public function test_a_language_pack_run_is_ignored_end_to_end(): void {
		list( $extra, $result ) = $this->run_package( array( 'language_update_type' => 'plugin', 'language_update' => (object) array() ), 'https://downloads.wordpress.org/translation/x.zip' );
		$this->assertArrayNotHasKey( Aura_Worker_Install_Ledger::TOKEN_KEY, $extra );
		$this->assertSame( array(), $this->entries() );
	}

	// ---- pass-through ----

	public function test_every_filter_returns_its_argument(): void {
		$opts = array( 'package' => 'x', 'hook_extra' => array( 'type' => 'plugin', 'action' => 'install' ), 'other' => 1 );
		$out  = Aura_Worker_Install_Ledger::on_package_options( $opts );
		unset( $out['hook_extra'][ Aura_Worker_Install_Ledger::TOKEN_KEY ] );
		$this->assertSame( $opts, $out );
		$err = new WP_Error( 'x', 'y' );
		$this->assertSame( $err, Aura_Worker_Install_Ledger::on_pre_download( $err, 'p', null, array() ) );
		$this->assertSame( '/tmp/local.zip', Aura_Worker_Install_Ledger::on_pre_download( '/tmp/local.zip', 'p', null, array() ) );
		$this->assertFalse( Aura_Worker_Install_Ledger::on_pre_download( false, 'p', null, array() ) );
		$res = array( 'destination_name' => 'foo' );
		$this->assertSame( $res, Aura_Worker_Install_Ledger::on_install_result( $res, array() ) );
		$this->assertSame( $err, Aura_Worker_Install_Ledger::on_install_result( $err, array( 'type' => 'plugin' ) ) );
	}

	public function test_a_throwing_probe_never_breaks_the_upgrade_and_moves_coverage(): void {
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = array( 'started' => gmdate( 'c', self::NOW - 30 * 86400 ), 'count_edge' => null, 'evicted' => false );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'app_password_uuid' => new ArrayObject() ) ) ); // (string) cast throws
		list( , $result ) = $this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip' );
		$this->assertSame( 'foo', $result['destination_name'] );
		// The install happened and could not be recorded: coverage restarts now (Codex r10 on #595).
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( gmdate( 'c', self::NOW ), true ), array( $r['since'], $r['evicted'] ) );
	}

	public function test_a_refused_callback_boundary_is_retried_once(): void {
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = array( 'started' => gmdate( 'c', self::NOW - 30 * 86400 ), 'count_edge' => null, 'evicted' => false );
		$GLOBALS['_sa_option_write_fail'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = 1; // the first boundary write is refused
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'app_password_uuid' => new ArrayObject() ) ) );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip' );
		$r = Aura_Worker_Install_Ledger::report(); // Codex r14 on #595
		$this->assertSame( array( gmdate( 'c', self::NOW ), true ), array( $r['since'], $r['evicted'] ) );
	}

	public function test_a_rejected_entry_moves_coverage_to_a_boundary(): void {
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::STATE_OPTION ] = array( 'started' => gmdate( 'c', self::NOW - 30 * 86400 ), 'count_edge' => null, 'evicted' => false );
		// A malformed FACT, not a thrown exception: a negative user_id makes
		// context() build an entry valid_entry() itself refuses — append()
		// returns false, nothing is thrown, and on_install_result() must
		// still move coverage to a boundary the same as a thrown probe would
		// (Codex review round 1, Task 2).
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'user_id' => -1 ) ) );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip' );
		$this->assertSame( array(), $this->entries() );
		$r = Aura_Worker_Install_Ledger::report();
		$this->assertSame( array( gmdate( 'c', self::NOW ), true ), array( $r['since'], $r['evicted'] ) );
	}

	// ---- the entry ----

	public function test_a_rest_application_password_install_from_wporg(): void {
		$GLOBALS['_app_passwords'][5] = array( array( 'uuid' => 'u-1', 'name' => 'claude', 'app_id' => '' ) );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'rest' => true, 'user_id' => 5, 'app_password_uuid' => 'u-1', 'route' => '/mcp/elementor-mcp-server' ) ) );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.1.2.0.zip' );
		$this->assertSame(
			array(
				array(
					'when'              => gmdate( 'c', self::NOW ),
					'type'              => 'plugin',
					'action'            => 'install',
					'slug'              => 'foo',
					'version'           => '1.2.0',
					'transport'         => 'rest',
					'user_id'           => 5,
					'auth'              => 'application_password',
					'app_password_name' => 'claude',
					'route'             => '/mcp/elementor-mcp-server',
					'source'            => array( 'kind' => 'wporg' ),
				),
			),
			$this->entries()
		);
	}

	public function test_the_uuid_is_never_stored(): void {
		$GLOBALS['_app_passwords'][5] = array( array( 'uuid' => 'secret-uuid', 'name' => 'claude', 'app_id' => '' ) );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'rest' => true, 'user_id' => 5, 'app_password_uuid' => 'secret-uuid' ) ) );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip' );
		$this->assertStringNotContainsString( 'secret-uuid', serialize( get_option( Aura_Worker_Install_Ledger::OPTION ) ) );
	}

	public function test_a_failed_install_writes_nothing_and_leaves_no_frame_for_the_next_run(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'rest' => true, 'user_id' => 5, 'rest_cookie' => false ) ) );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://evil.example/x.zip', new WP_Error( 'bad', 'no' ) );
		$this->assertSame( array(), $this->entries() );
		// The frame this token staked at pre-download is actually gone, not
		// merely orphaned — left in place it would leak for the rest of a
		// long bulk request (Codex review round 1, Task 2).
		$this->assertSame( 0, Aura_Worker_Install_Ledger::_frame_count_for_tests() );
		// An identical outer run with its own token still gets its OWN source (Codex r1 on #594).
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip' );
		$this->assertSame( 'wporg', $this->entries()[0]['source']['kind'] );
	}

	public function test_a_wp_error_reply_stores_no_frame(): void {
		$opts  = Aura_Worker_Install_Ledger::on_package_options( array( 'hook_extra' => array( 'type' => 'plugin', 'action' => 'install' ) ) );
		Aura_Worker_Install_Ledger::on_pre_download( new WP_Error( 'aura_self_update_claim_lost', 'x' ), 'https://downloads.wordpress.org/plugin/foo.zip', null, $opts['hook_extra'] );
		Aura_Worker_Install_Ledger::on_install_result( array( 'destination_name' => 'foo', 'destination' => '/srv/wp-content/plugins/foo' ), $opts['hook_extra'] );
		$this->assertSame( 'unknown', $this->entries()[0]['source']['kind'] ); // no frame → unknown, never the URL
	}

	public function test_a_substituted_download_is_an_unknown_source(): void {
		$opts = Aura_Worker_Install_Ledger::on_package_options( array( 'hook_extra' => array( 'type' => 'plugin', 'action' => 'install' ) ) );
		Aura_Worker_Install_Ledger::on_pre_download( '/tmp/power-pack-supplied.zip', 'https://downloads.wordpress.org/plugin/foo.zip', null, $opts['hook_extra'] );
		Aura_Worker_Install_Ledger::on_install_result( array( 'destination_name' => 'foo', 'destination' => '/srv/wp-content/plugins/foo' ), $opts['hook_extra'] );
		$this->assertSame( array( 'kind' => 'unknown' ), $this->entries()[0]['source'] );
	}

	public function test_no_token_means_unknown_source_and_the_context_read_now(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'wp_cli' => true ) ) );
		Aura_Worker_Install_Ledger::on_install_result( array( 'destination_name' => 'foo', 'destination' => '/srv/wp-content/plugins/foo' ), array( 'type' => 'plugin', 'action' => 'install' ) );
		$e = $this->entries()[0];
		$this->assertSame( array( 'kind' => 'unknown' ), $e['source'] );
		$this->assertSame( 'wp_cli', $e['transport'] );
	}

	public function test_bulk_items_each_get_their_own_token_and_entry(): void {
		list( $a ) = $this->run_package( array( 'plugin' => 'foo/foo.php', 'temp_backup' => array() ), 'https://downloads.wordpress.org/plugin/foo.zip' );
		list( $b ) = $this->run_package( array( 'plugin' => 'baz/baz.php', 'temp_backup' => array() ), 'https://downloads.wordpress.org/plugin/baz.zip', array( 'destination_name' => 'baz', 'destination' => '/srv/wp-content/plugins/baz' ) );
		$this->assertNotSame( $a[ Aura_Worker_Install_Ledger::TOKEN_KEY ], $b[ Aura_Worker_Install_Ledger::TOKEN_KEY ] );
		$this->assertSame( array( 'baz', 'foo' ), array_column( $this->entries(), 'slug' ) );
		$this->assertSame( array( 'update', 'update' ), array_column( $this->entries(), 'action' ) );
	}

	public function test_a_theme_install_reads_the_theme_version(): void {
		$this->run_package( array( 'type' => 'theme', 'action' => 'install' ), 'https://downloads.wordpress.org/theme/bar.zip', array( 'destination_name' => 'bar', 'destination' => '/srv/wp-content/themes/bar' ) );
		$e = $this->entries()[0];
		$this->assertSame( array( 'theme', 'install', 'bar', '3.0' ), array( $e['type'], $e['action'], $e['slug'], $e['version'] ) );
	}

	public function test_the_version_comes_from_the_main_plugin_file_not_a_companion(): void {
		$dir = sys_get_temp_dir() . '/sa-ledger-' . uniqid();
		mkdir( $dir . '/foo', 0777, true );
		file_put_contents( $dir . '/foo/aaa-companion.php', "<?php\n/**\n * Plugin Name: Companion\n * Version: 9.9\n */\n" );
		file_put_contents( $dir . '/foo/foo.php', "<?php\n/**\n * Plugin Name: Foo\n * Version: 1.2\n */\n" );
		file_put_contents( $dir . '/foo/main-file.php', "<?php\n/**\n * Plugin Name: Foo Main\n * Version: 3.0\n */\n" );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'versions' => null ) ) ); // read the files
		$result = array( 'destination_name' => 'foo', 'destination' => $dir . '/foo' );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip', $result );
		$this->assertSame( '1.2', $this->entries()[0]['version'] ); // <slug>.php, not the alphabetically-first companion (Codex r21 on #595)
		$this->run_package( array( 'plugin' => 'foo/main-file.php' ), 'https://downloads.wordpress.org/plugin/foo.zip', $result );
		$this->assertSame( '3.0', $this->entries()[0]['version'] ); // an update reads the file WordPress named
		// No <slug>.php and several headers: unknown, never the sort-first companion (Codex r22 on #595).
		unlink( $dir . '/foo/foo.php' );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip', $result );
		$this->assertNull( $this->entries()[0]['version'] );
		// Exactly one header file: that one.
		unlink( $dir . '/foo/aaa-companion.php' );
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/foo.zip', $result );
		$this->assertSame( '3.0', $this->entries()[0]['version'] );
		array_map( 'unlink', glob( $dir . '/foo/*.php' ) );
		rmdir( $dir . '/foo' );
		rmdir( $dir );
	}

	public function test_an_unreadable_version_is_null(): void {
		$this->run_package( array( 'type' => 'plugin', 'action' => 'install' ), 'https://downloads.wordpress.org/plugin/q.zip', array( 'destination_name' => 'q', 'destination' => '/srv/wp-content/plugins/q' ) );
		$this->assertNull( $this->entries()[0]['version'] );
	}

	// ---- transport and auth ----

	public static function transports(): array {
		return array(
			'siteagent beats everything' => array( array( 'siteagent' => true, 'wp_cli' => true, 'rest' => true ), 'siteagent' ),
			'wp-cli'                     => array( array( 'wp_cli' => true, 'cron' => true ), 'wp_cli' ),
			'core auto-update'           => array( array( 'auto_update' => true, 'cron' => true ), 'auto_update' ),
			'cron'                       => array( array( 'cron' => true ), 'cron' ),
			'rest'                       => array( array( 'rest' => true, 'admin' => true, 'user_id' => 1 ), 'rest' ),
			'wp-admin with a user'       => array( array( 'admin' => true, 'user_id' => 1 ), 'wp_admin' ),
			'wp-admin with nobody'       => array( array( 'admin' => true ), 'unknown' ),
			'nothing known'              => array( array(), 'unknown' ),
		);
	}

	/** @dataProvider transports */
	public function test_transport_precedence( array $facts, string $expected ): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( $facts ) );
		$this->assertSame( $expected, Aura_Worker_Install_Ledger::context()['transport'] );
	}

	public function test_an_automatic_skin_outside_core_auto_update_is_not_auto_update(): void {
		// EMCP Pro installs over REST with Automatic_Upgrader_Skin (Ruling R1):
		// a real, tokenized run through that skin stores `rest` (Codex r11 on #595).
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'rest' => true, 'user_id' => 5, 'app_password_uuid' => 'u-1' ) ) );
		$opts = Aura_Worker_Install_Ledger::on_package_options( array( 'hook_extra' => array( 'type' => 'plugin', 'action' => 'install' ) ) );
		Aura_Worker_Install_Ledger::on_pre_download( false, 'https://downloads.wordpress.org/plugin/foo.zip', new Plugin_Upgrader( new Automatic_Upgrader_Skin() ), $opts['hook_extra'] );
		Aura_Worker_Install_Ledger::on_install_result( array( 'destination_name' => 'foo', 'destination' => '/srv/wp-content/plugins/foo' ), $opts['hook_extra'] );
		$e = $this->entries()[0];
		$this->assertSame( array( 'rest', 'application_password' ), array( $e['transport'], $e['auth'] ) );
	}

	public static function auths(): array {
		return array(
			'app password wins'            => array( array( 'rest' => true, 'user_id' => 5, 'rest_cookie' => true, 'app_password_uuid' => 'u-1' ), 'application_password' ),
			'rest cookie'                  => array( array( 'rest' => true, 'user_id' => 5, 'rest_cookie' => true ), 'cookie' ),
			'rest, a user, no cookie'      => array( array( 'rest' => true, 'user_id' => 5 ), 'unknown' ),
			'rest, nobody'                 => array( array( 'rest' => true ), 'none' ),
			'admin screen'                 => array( array( 'admin' => true, 'user_id' => 1 ), 'cookie' ),
			'cli, nobody'                  => array( array( 'wp_cli' => true ), 'none' ),
			'cli as a user'                => array( array( 'wp_cli' => true, 'user_id' => 1 ), 'unknown' ),
		);
	}

	/** @dataProvider auths */
	public function test_auth( array $facts, string $expected ): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( $facts ) );
		$this->assertSame( $expected, Aura_Worker_Install_Ledger::context()['auth'] );
	}

	public function test_route_only_on_rest_and_never_with_a_query(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'rest' => true, 'route' => '/wp-abilities/v1/x?token=abc' ) ) );
		$this->assertSame( '/wp-abilities/v1/x', Aura_Worker_Install_Ledger::context()['route'] );
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'admin' => true, 'user_id' => 1, 'route' => '/stale' ) ) );
		$this->assertNull( Aura_Worker_Install_Ledger::context()['route'] );
	}

	public function test_an_unknown_uuid_names_nothing(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'rest' => true, 'user_id' => 5, 'app_password_uuid' => 'gone' ) ) );
		$c = Aura_Worker_Install_Ledger::context();
		$this->assertSame( 'application_password', $c['auth'] );
		$this->assertNull( $c['app_password_name'] );
	}

	// ---- source ----

	public static function sources(): array {
		return array(
			'wp.org'             => array( 'https://downloads.wordpress.org/plugin/foo.zip', array( 'kind' => 'wporg' ) ),
			'wp.org, any case'   => array( 'HTTPS://Downloads.WordPress.org/plugin/foo.zip', array( 'kind' => 'wporg' ) ),
			'other host'         => array( 'https://cdn.evil.example/p.zip?sig=1', array( 'kind' => 'remote_host', 'host' => 'cdn.evil.example' ) ),
			'uploads'            => array( '/srv/wp-content/uploads/2026/09/p.zip', array( 'kind' => 'uploaded_zip' ) ),
			'other local path'   => array( '/tmp/p.zip', array( 'kind' => 'local_path' ) ),
			'traversal segment'  => array( '/srv/wp-content/uploads/2026/09/../../../etc/passwd.zip', array( 'kind' => 'local_path' ) ),
			'traversal, backslash normalised' => array( '/srv/wp-content/uploads\\..\\..\\etc\\passwd.zip', array( 'kind' => 'local_path' ) ),
			'another scheme'     => array( 'ftp://x/p.zip', array( 'kind' => 'unknown' ) ),
			'empty'              => array( '', array( 'kind' => 'unknown' ) ),
			'not a string'       => array( array( 'x' ), array( 'kind' => 'unknown' ) ),
			'url with no host'   => array( 'https:///p.zip', array( 'kind' => 'unknown' ) ),
		);
	}

	/** @dataProvider sources */
	public function test_classify_source( $package, array $expected ): void {
		$this->assertSame( $expected, Aura_Worker_Install_Ledger::classify_source( $package ) );
	}

	public function test_an_uploaded_zip_carries_its_attachment_when_one_resolves(): void {
		Aura_Worker_Install_Ledger::_set_probe_for_tests( $this->quiet( array( 'attachment_ids' => array( 'https://example.com/wp-content/uploads/2026/09/p.zip' => 42 ) ) ) );
		$this->assertSame( array( 'kind' => 'uploaded_zip', 'attachment_id' => 42 ), Aura_Worker_Install_Ledger::classify_source( '/srv/wp-content/uploads/2026/09/p.zip' ) );
	}

	// ---- wiring ----

	public function test_init_registers_three_filters_and_the_daily_purge(): void {
		Aura_Worker_Install_Ledger::init();
		$this->assertSame( PHP_INT_MAX, has_filter( 'upgrader_package_options', array( 'Aura_Worker_Install_Ledger', 'on_package_options' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'upgrader_pre_download', array( 'Aura_Worker_Install_Ledger', 'on_pre_download' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'upgrader_install_package_result', array( 'Aura_Worker_Install_Ledger', 'on_install_result' ) ) );
		$this->assertNotFalse( has_action( 'wp_scheduled_delete', array( 'Aura_Worker_Install_Ledger', 'purge_expired' ) ) ); // physical retention, daily
	}

	/**
	 * Deactivate -> reactivate must not leave `started` unchanged (final
	 * review Task 1): the ledger would otherwise claim coverage over a gap it
	 * never observed while inactive. Static source inspection, like this
	 * suite's other activation-hook assertions (ConnectAppPasswordTest) —
	 * digitizer-site-worker.php is never `require`d live by this bootstrap.
	 */
	public function test_activation_restarts_the_ledgers_coverage_per_site(): void {
		$main     = (string) file_get_contents( SA_PLUGIN_DIR . '/digitizer-site-worker.php' );
		$activate = substr( $main, (int) strpos( $main, 'function aura_worker_activate_site()' ) );
		$call     = strpos( $activate, 'Aura_Worker_Install_Ledger::restart_coverage()' );
		$this->assertNotFalse( $call, 'aura_worker_activate_site() must call Aura_Worker_Install_Ledger::restart_coverage()' );
		$before = substr( $activate, 0, $call );
		$this->assertStringContainsString( "class_exists( 'Aura_Worker_Install_Ledger' )", $before );
		$try = strrpos( $before, 'try {' );
		$this->assertNotFalse( $try, 'the call must be guarded by a try block so activation never fails because of the ledger' );
		$this->assertStringContainsString( 'catch ( \Throwable $e )', substr( $activate, $try ) );
	}
}
