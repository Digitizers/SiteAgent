<?php
/**
 * audit_mcp_exposure — the `elementor` block (2.15.0).
 *
 * Every WordPress and database read the block makes goes through a protected
 * seam; SA_Elementor_Fake_Tool exposes each as a public property so a test
 * states the site it models and reads the block it produces. Two tests at the
 * end run the REAL seams against the bootstrap's $wpdb stub, pinning the SQL.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/credential-rules.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-mcp-exposure.php';
require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-admin-accounts.php';

class SA_Elementor_Fake_Tool extends Aura_Tool_Audit_Mcp_Exposure {
	/** @var array */
	public $env = array( 'installed' => true, 'version' => '4.3.0-beta1', 'class_present' => true, 'active' => true );
	/** @var array|null */
	public $plugin_header = null;
	/** @var array|null */
	public $abilities = array();
	/** @var array */
	public $server_list = array( array( 'id' => 'elementor-mcp-server', 'route' => '/elementor/mcp', 'tool_count' => 27 ) );
	/** @var array */
	public $consent_rows = array();
	/** @var int[] */
	public $candidates = array();
	/** @var array uid => array|null */
	public $lists = array();
	/** @var int[][] pages of user ids, in order */
	public $context_pages = array();
	/** @var int */
	public $context_total = 0;
	/** @var string[] seam names that throw */
	public $throw_in = array();
	/** @var string|null the message a throwing seam carries (default: "<seam> exploded") */
	public $throw_message = null;
	/** @var int[] user ids whose list was read, in order */
	public $reads = array();
	/** @var int how many times consent_rows() was invoked — the manage_options gate test proves this stays 0 */
	public $consent_rows_calls = 0;

	// --- 2.19.0: the beta3 switch, and which vendored copies resolve ---------
	/** @var mixed raw `elementor_mcp_enabled` value; null = the option is absent */
	public $switch_option = null;
	/** @var array fqcn => bool */
	public $classes = array();
	/** @var array fqcn => string|null — what ReflectionClass::getFileName() would answer */
	public $class_files = array();
	/** @var array fqcn => array( constant => value ) */
	public $class_constants = array();
	/** @var array path => array|null — what read_small_json() would answer */
	public $json = array();
	/** @var int how many times the switch option was read (the gate test proves this stays 0) */
	public $switch_reads = 0;
	/** @var int how many times a class was inspected (ditto) */
	public $class_reads = 0;
	/** @var string[] paths handed to read_small_json(), in order (ditto: stays empty) */
	public $json_reads = array();

	private function maybe_throw( $seam ) {
		if ( in_array( $seam, $this->throw_in, true ) ) {
			throw new RuntimeException( null === $this->throw_message ? $seam . ' exploded' : $this->throw_message );
		}
	}
	protected function elementor_env() {
		$this->maybe_throw( 'env' );
		return $this->env;
	}
	protected function elementor_plugin_header() {
		return $this->plugin_header;
	}
	protected function elementor_ability_names() {
		$this->maybe_throw( 'abilities' );
		return $this->abilities;
	}
	protected function servers() {
		$this->maybe_throw( 'servers' );
		return $this->server_list;
	}
	protected function ability_exposure( $abilities_active ) {
		$this->maybe_throw( 'exposure' );
		return parent::ability_exposure( $abilities_active );
	}

	protected function consent_rows() {
		++$this->consent_rows_calls;
		$this->maybe_throw( 'consent' );
		return $this->consent_rows;
	}
	protected function elementor_candidate_ids() {
		$this->maybe_throw( 'candidates' );
		return $this->candidates;
	}
	protected function password_list( $uid ) {
		$this->maybe_throw( 'list' );
		$this->reads[] = (int) $uid;
		return array_key_exists( (int) $uid, $this->lists ) ? $this->lists[ (int) $uid ] : array();
	}
	protected function context_user_ids( $offset, $number ) {
		$this->maybe_throw( 'context' );
		$page = (int) ( $offset / $number );
		return isset( $this->context_pages[ $page ] ) ? $this->context_pages[ $page ] : array();
	}
	protected function context_users_total() {
		$this->maybe_throw( 'total' );
		return $this->context_total;
	}
	protected function user_login( $uid ) {
		return 'user' . (int) $uid;
	}

	protected function elementor_switch_option() {
		$this->maybe_throw( 'switch' );
		++$this->switch_reads;
		return $this->switch_option;
	}
	protected function class_present( $fqcn ) {
		$this->throw_for_class( $fqcn );
		++$this->class_reads;
		return ! empty( $this->classes[ $fqcn ] );
	}
	protected function class_file( $fqcn ) {
		$this->throw_for_class( $fqcn );
		++$this->class_reads;
		return array_key_exists( $fqcn, $this->class_files ) ? $this->class_files[ $fqcn ] : null;
	}
	protected function class_constant( $fqcn, $name ) {
		$this->throw_for_class( $fqcn );
		++$this->class_reads;
		return isset( $this->class_constants[ $fqcn ][ $name ] ) ? $this->class_constants[ $fqcn ][ $name ] : null;
	}
	protected function read_small_json( $path ) {
		$this->maybe_throw( 'json' );
		$this->json_reads[] = (string) $path;
		return array_key_exists( $path, $this->json ) ? $this->json[ $path ] : null;
	}
	/** One seam name per subtree, so a throw can be aimed at adapter or composer alone. */
	private function throw_for_class( $fqcn ) {
		if ( Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_ADAPTER_CLASS === $fqcn ) {
			$this->maybe_throw( 'adapter' );
		}
		if ( Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS === $fqcn ) {
			$this->maybe_throw( 'composer' );
		}
	}
}

/**
 * A stream whose files "exist" and are readable but refuse to open — the
 * portable stand-in for an open_basedir or permission failure that raises
 * AFTER read_small_json()'s guards have passed. PHP's warning for it names the
 * path, which is the whole point.
 */
class SA_Refusing_Stream {
	/** @var resource|null */
	public $context;
	public function stream_open( $path, $mode, $options, &$opened_path ) {
		return false; // PHP raises "failed to open stream" naming $path
	}
	public function url_stat( $path, $flags ) {
		return array(
			'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1, 'uid' => 0, 'gid' => 0,
			'rdev' => 0, 'size' => 128, 'atime' => 0, 'mtime' => 0, 'ctime' => 0,
			'blksize' => -1, 'blocks' => -1,
		);
	}
}

/**
 * A stream that stats SMALL and streams BIG: the stand-in for a manifest a
 * concurrent plugin update grew between read_small_json()'s stat and its read
 * (Codex round-8 on #125). The bound must hold during the read itself.
 */
class SA_Growing_Stream {
	/** @var resource|null */
	public $context;
	private $pos = 0;
	private $len;
	public function stream_open( $path, $mode, $options, &$opened_path ) {
		$this->len = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_JSON_MAX + 4096;
		$this->pos = 0;
		return true;
	}
	public function stream_read( $count ) {
		$n = min( $count, $this->len - $this->pos );
		if ( $n <= 0 ) {
			return '';
		}
		$this->pos += $n;
		return str_repeat( '{', $n );
	}
	public function stream_eof() {
		return $this->pos >= $this->len;
	}
	public function stream_stat() {
		return $this->url_stat( '', 0 );
	}
	public function url_stat( $path, $flags ) {
		return array(
			'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1, 'uid' => 0, 'gid' => 0,
			'rdev' => 0, 'size' => 10, 'atime' => 0, 'mtime' => 0, 'ctime' => 0,
			'blksize' => -1, 'blocks' => -1,
		);
	}
}

final class McpExposureElementorTest extends TestCase {

	private SA_Elementor_Fake_Tool $tool;

	protected function setUp(): void {
		sa_reset_state();
		$this->tool = new SA_Elementor_Fake_Tool();
	}

	private function block(): array {
		$result = $this->tool->execute( array() );
		$this->assertArrayHasKey( 'elementor', $result );
		return $result['elementor'];
	}

	// --- Task 2: the module subtree -----------------------------------------

	public function test_the_block_is_documented_and_the_byte_bound_matches_the_admin_audit(): void {
		$this->assertArrayHasKey( 'elementor', $this->tool->get_returns() );
		$this->assertSame( Aura_Tool_Audit_Admin_Accounts::MAX_APP_PASSWORD_BYTES, Aura_Tool_Audit_Mcp_Exposure::MAX_APP_PASSWORD_BYTES );
	}

	public function test_the_rest_of_the_payload_is_unchanged(): void {
		$result = $this->tool->execute( array() );
		foreach ( array( 'abilities_api_active', 'mcp_adapter', 'servers', 'angie', 'abilities', 'coverage' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		// 2.19.0 added three subtrees to the `elementor` block and nothing else:
		// every subtree the block promised before is still in its place.
		$b = $result['elementor'];
		foreach ( array( 'installed', 'version', 'mcp_module', 'consent', 'app_passwords', 'coverage', 'governor' ) as $key ) {
			$this->assertArrayHasKey( $key, $b );
		}
		$this->assertSame(
			array( 'class_present' => true, 'active' => true, 'abilities_registered' => 0, 'server_id' => 'elementor-mcp-server' ),
			$b['mcp_module']
		);
		$this->assertSame( array(), $b['consent'] );
		$this->assertArrayHasKey( 'elementor', $b['app_passwords'] );
		$this->assertArrayHasKey( 'other', $b['app_passwords'] );
		$this->assertArrayHasKey( 'users_total', $b['coverage'] );
	}

	// --- Codex round-3 P2: the block requires manage_options -----------------
	// `POST /aura/mcp/tools/execute` is gated by check_update_plugins_permission,
	// not manage_options — a caller holding update_plugins without manage_options
	// must get the { error } shape on every subtree, with NOTHING read.

	public function test_without_manage_options_every_subtree_is_the_error_shape_and_nothing_is_read(): void {
		$GLOBALS['_caps'] = array( 'update_plugins' ); // held, but NOT manage_options
		$b = $this->block();
		$this->assertSame(
			array(
				'installed'     => false,
				'version'       => null,
				'mcp_module'    => array( 'error' => 'manage_options required' ),
				'consent'       => array( 'error' => 'manage_options required' ),
				'app_passwords' => array(
					'elementor' => array( 'error' => 'manage_options required' ),
					'other'     => array( 'error' => 'manage_options required' ),
				),
				'coverage'      => array( 'error' => 'manage_options required' ),
				'governor'      => array( 'error' => 'manage_options required' ),
				'switch'        => array( 'error' => 'manage_options required' ),
				'adapter'       => array( 'error' => 'manage_options required' ),
				'composer'      => array( 'error' => 'manage_options required' ),
			),
			$b
		);
		$this->assertSame( array(), $this->tool->reads );
		$this->assertSame( 0, $this->tool->consent_rows_calls );
		// 2.19.0: the switch option, the two class inspections and the bounded
		// composer.json read are reads too — a refused caller makes none of them.
		$this->assertSame( 0, $this->tool->switch_reads );
		$this->assertSame( 0, $this->tool->class_reads );
		$this->assertSame( array(), $this->tool->json_reads );
	}

	public function test_with_manage_options_the_block_is_read(): void {
		// sa_reset_state()'s default ($GLOBALS['_caps'] = null) already allows
		// everything; asserted explicitly here as the gate's other branch.
		$GLOBALS['_caps'] = null;
		$this->tool->abilities = array( 'elementor/create-page' );
		$b = $this->block();
		$this->assertTrue( $b['installed'] );
		$this->assertArrayHasKey( 'class_present', $b['mcp_module'] );
		$this->assertSame( 1, $b['mcp_module']['abilities_registered'] );
	}

	public function test_a_clean_4_3_site_is_built_with_the_official_server_id(): void {
		$this->tool->abilities = array( 'elementor/create-page', 'elementor/get-page', 'elementor-mcp/save-page', 'angie/run' );
		$b = $this->block();
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
		$this->assertSame(
			array( 'class_present' => true, 'active' => true, 'abilities_registered' => 2, 'server_id' => 'elementor-mcp-server' ),
			$b['mcp_module']
		);
	}

	public function test_abilities_registered_counts_only_the_elementor_prefix(): void {
		// The fork's elementor-mcp/* and Angie's abilities share the unified
		// server; they must never inflate Elementor's own count.
		$this->tool->abilities = array( 'elementor-mcp/save', 'angie/x', 'elementor/a', 'elementorx/b' );
		$this->assertSame( 1, $this->block()['mcp_module']['abilities_registered'] );
	}

	public function test_abilities_registered_is_null_when_the_abilities_api_is_absent(): void {
		$this->tool->abilities = null;
		$this->assertNull( $this->block()['mcp_module']['abilities_registered'] );
	}

	public function test_server_id_is_null_when_the_official_server_is_not_registered(): void {
		$this->tool->server_list = array( array( 'id' => 'angie', 'route' => '/mcp/angie', 'tool_count' => 4 ) );
		$this->assertNull( $this->block()['mcp_module']['server_id'] );
	}

	public function test_elementor_4_2_4_with_angie_has_the_class_absent_and_active_null(): void {
		// Angie's door on 4.2.4: the route exists, the module does not. The
		// Aura parser needs the MODULE, not the route.
		$this->tool->env = array( 'installed' => true, 'version' => '4.2.4', 'class_present' => false, 'active' => false );
		$this->tool->server_list = array( array( 'id' => 'elementor-mcp-server', 'route' => '/elementor/mcp', 'tool_count' => 3 ) );
		$m = $this->block()['mcp_module'];
		$this->assertFalse( $m['class_present'] );
		$this->assertNull( $m['active'] ); // class absent ⇒ active null, whatever the seam said
		$this->assertSame( 'elementor-mcp-server', $m['server_id'] );
	}

	public function test_elementor_absent_reports_not_installed_and_the_module_shape_still(): void {
		$this->tool->env = array( 'installed' => false, 'version' => '9.9.9', 'class_present' => false, 'active' => null );
		$this->tool->abilities = array( 'elementor/orphan' );
		$b = $this->block();
		$this->assertFalse( $b['installed'] );
		$this->assertNull( $b['version'] ); // installed:false ⇒ version:null
		$this->assertSame( array( 'class_present' => false, 'active' => null, 'abilities_registered' => 1, 'server_id' => null ), $b['mcp_module'] );
	}

	public function test_the_module_class_present_implies_installed(): void {
		// The class cannot exist without Elementor; a seam that says otherwise
		// is corrected rather than emitting a payload the parser refuses.
		$this->tool->env = array( 'installed' => false, 'version' => null, 'class_present' => true, 'active' => true );
		$this->assertTrue( $this->block()['installed'] );
	}

	public function test_active_null_when_is_active_cannot_be_called(): void {
		$this->tool->env = array( 'installed' => true, 'version' => '4.3.0', 'class_present' => true, 'active' => null );
		$this->assertNull( $this->block()['mcp_module']['active'] );
	}

	public function test_a_throw_in_the_module_scan_replaces_only_the_module_subtree(): void {
		$this->tool->throw_in = array( 'env' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'env exploded' ), $b['mcp_module'] );
		$this->assertFalse( $b['installed'] );
		$this->assertNull( $b['version'] );
		$this->assertSame( array(), $b['consent'] ); // the other subtrees were still read
		$this->assertArrayHasKey( 'coverage', $b );
	}

	public function test_a_throw_counting_abilities_is_a_module_error_too(): void {
		// Codex round-2 P2: a throw counting abilities is a DISCOVERY failure,
		// not an installation one — installed/version, already read from
		// elementor_env() alone, must survive it.
		$this->tool->throw_in = array( 'abilities' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'abilities exploded' ), $b['mcp_module'] );
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
	}

	public function test_a_throw_listing_servers_is_a_module_error_too(): void {
		// Same rule, the other discovery input: elementor_module_from() also
		// reads servers() to attribute server_id.
		$this->tool->throw_in = array( 'servers' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'servers exploded' ), $b['mcp_module'] );
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
	}

	public function test_a_throw_listing_servers_never_leaves_execute(): void {
		// Codex round-8 P2: execute() builds `servers` and `angie` BEFORE
		// `elementor`, from the same servers() read — so a throw there used to
		// fail the whole tool and the module-subtree isolation above was never
		// reached. Discovery is now read once in execute(); every reader gets
		// { error } in its own place and the rest of the payload still lands.
		$this->tool->throw_in = array( 'servers' );
		$result = $this->tool->execute( array() );
		$this->assertSame( array( 'error' => 'servers exploded' ), $result['servers'] );
		$this->assertSame( array( 'error' => 'servers exploded' ), $result['angie'] );
		$this->assertSame( array( 'error' => 'servers exploded' ), $result['elementor']['mcp_module'] );
		$this->assertTrue( $result['elementor']['installed'] );
		$this->assertSame( '4.3.0-beta1', $result['elementor']['version'] );
		foreach ( array( 'abilities_api_active', 'mcp_adapter', 'abilities', 'coverage' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$this->assertIsArray( $result['elementor']['consent'] );
	}

	public function test_a_throw_in_the_ability_scan_never_leaves_execute(): void {
		// Codex round-9 P2 (same class as round 8): ability_exposure() scans
		// the registry AFTER the block is built, outside its isolation — a
		// third-party ability throwing from get_meta() discarded the whole
		// audit. Now `abilities` and `coverage` become { error } and every
		// other key, the elementor block included, still lands intact.
		$this->tool->throw_in = array( 'exposure' );
		$result = $this->tool->execute( array() );
		$this->assertSame( array( 'error' => 'exposure exploded' ), $result['abilities'] );
		$this->assertSame( array( 'error' => 'exposure exploded' ), $result['coverage'] );
		$this->assertIsArray( $result['servers'] );
		$this->assertIsArray( $result['angie'] );
		$this->assertTrue( $result['elementor']['installed'] );
		$this->assertSame( '4.3.0-beta1', $result['elementor']['version'] );
		$this->assertArrayNotHasKey( 'error', $result['elementor']['mcp_module'] );
	}

	public function test_strings_are_clipped_at_200(): void {
		$this->tool->env = array( 'installed' => true, 'version' => str_repeat( 'v', 500 ), 'class_present' => true, 'active' => true );
		$this->assertSame( 200, strlen( $this->block()['version'] ) );
	}

	public function test_the_no_mbstring_clip_never_splits_a_character(): void {
		// Codex round-6 P2: a byte substr() at 200 could cut an emoji in half
		// and hand JSON encoding invalid UTF-8.
		$name = 'Elementor MCP ' . str_repeat( "\u{1F642}", 200 );
		$out  = Aura_Tool_Audit_Mcp_Exposure::clip_fallback( $name );
		$this->assertSame( 200, mb_strlen( $out ) );
		$this->assertTrue( (bool) preg_match( '//u', $out ) ); // valid UTF-8
		$this->assertSame( 'Elementor MCP ', substr( $out, 0, 14 ) );
		$this->assertSame( '', Aura_Tool_Audit_Mcp_Exposure::clip_fallback( "abc\xff\xfe" ) ); // invalid UTF-8 → nothing
		$this->assertSame( 'short', Aura_Tool_Audit_Mcp_Exposure::clip_fallback( 'short' ) );
	}

	public function test_elementor_module_from_is_pure(): void {
		$m = Aura_Tool_Audit_Mcp_Exposure::elementor_module_from(
			array( 'installed' => true, 'version' => '4.3.0', 'class_present' => true, 'active' => false ),
			array( 'elementor/a', 'elementor/b' ),
			array( array( 'id' => 'angie' ) )
		);
		$this->assertSame( array( 'class_present' => true, 'active' => false, 'abilities_registered' => 2, 'server_id' => null ), $m );
	}


	// --- 2.19.0: the beta3 switch, and which vendored copies resolve --------
	// Elementor 4.3.0-beta3 put the `/elementor/mcp` token door behind
	// `elementor_mcp_enabled` (absent ⇒ OFF), and the WP MCP adapter moved
	// 0.5.0 → 0.6.1 and may be served by ANOTHER plugin's vendored copy. The
	// block reports which switch and which copies this site actually has.

	public function test_the_three_new_subtrees_are_documented_and_in_the_payload(): void {
		$doc = $this->tool->get_returns()['elementor'];
		foreach ( array( 'switch:', 'adapter:', 'composer:', 'elementor_mcp_enabled', '2.19.0' ) as $needle ) {
			$this->assertStringContainsString( $needle, $doc );
		}
		$b = $this->block();
		foreach ( array( 'switch', 'adapter', 'composer' ) as $key ) {
			$this->assertArrayHasKey( $key, $b );
		}
	}

	public function test_the_switch_option_absent_is_a_door_that_is_off(): void {
		// The rule McpSettingsController::is_enabled() applies at composer
		// 1.0.13: no option ⇒ false. On an older Elementor there is no switch
		// and no token door either, so "off" is honest there too.
		$this->tool->switch_option = null;
		$this->assertSame( array( 'option_present' => false, 'enabled' => false ), $this->block()['switch'] );
	}

	public function test_the_switch_reads_the_stored_value_as_is_enabled_does(): void {
		// A list of pairs, not a keyed array: PHP would collapse '1', 1 and
		// true (and '0' and 0) into one key and silently drop the coverage.
		$cases = array(
			array( '1', true ),
			array( 1, true ),
			array( true, true ),
			array( 'yes', true ),
			array( '0', false ),
			array( 0, false ),
			array( '', false ),
			array( false, false ),
		);
		foreach ( $cases as $case ) {
			list( $raw, $expected ) = $case;
			$tool                   = new SA_Elementor_Fake_Tool();
			$tool->switch_option    = $raw;
			$sw                     = $tool->execute( array() )['elementor']['switch'];
			$this->assertTrue( $sw['option_present'], var_export( $raw, true ) );
			$this->assertSame( $expected, $sw['enabled'], var_export( $raw, true ) );
		}
	}

	public function test_the_switch_is_read_even_when_elementor_is_absent(): void {
		// An option outlives the plugin that wrote it, like a consent row.
		$this->tool->env           = array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
		$this->tool->switch_option = '1';
		$b                         = $this->block();
		$this->assertFalse( $b['installed'] );
		$this->assertSame( array( 'option_present' => true, 'enabled' => true ), $b['switch'] );
	}

	public function test_a_throw_reading_the_switch_replaces_only_the_switch(): void {
		$this->tool->throw_in = array( 'switch' );
		$b                    = $this->block();
		$this->assertSame( array( 'error' => 'switch exploded' ), $b['switch'] );
		$this->assertSame( array( 'class_present' => false, 'version' => null, 'path' => null ), $b['adapter'] );
		$this->assertSame( array( 'class_present' => false, 'version' => null, 'path' => null ), $b['composer'] );
		$this->assertTrue( $b['installed'] );
		$this->assertArrayNotHasKey( 'error', $b['mcp_module'] );
	}

	public function test_the_adapter_is_absent_when_no_copy_resolves(): void {
		$this->assertSame(
			array( 'class_present' => false, 'version' => null, 'path' => null ),
			$this->block()['adapter']
		);
	}

	public function test_the_adapter_copy_that_resolves_is_reported_with_its_version_and_relative_path(): void {
		$fqcn                            = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_ADAPTER_CLASS;
		$this->tool->classes             = array( $fqcn => true );
		$this->tool->class_constants     = array( $fqcn => array( 'VERSION' => '0.6.1' ) );
		$this->tool->class_files         = array( $fqcn => ABSPATH . 'wp-content/plugins/elementor/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php' );
		$this->assertSame(
			array(
				'class_present' => true,
				'version'       => '0.6.1',
				'path'          => 'wp-content/plugins/elementor/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
			),
			$this->block()['adapter']
		);
	}

	public function test_a_non_string_adapter_version_is_null(): void {
		$fqcn                        = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_ADAPTER_CLASS;
		$this->tool->classes         = array( $fqcn => true );
		$this->tool->class_constants = array( $fqcn => array( 'VERSION' => 61 ) );
		$this->tool->class_files     = array( $fqcn => ABSPATH . 'a.php' );
		$a                           = $this->block()['adapter'];
		$this->assertTrue( $a['class_present'] );
		$this->assertNull( $a['version'] );
		$this->assertSame( 'a.php', $a['path'] );
	}

	public function test_an_adapter_file_outside_abspath_is_reported_as_a_basename(): void {
		// No absolute server path ever leaves this tool.
		$fqcn                    = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_ADAPTER_CLASS;
		$this->tool->classes     = array( $fqcn => true );
		$this->tool->class_files = array( $fqcn => '/opt/elsewhere/mcp-adapter/includes/Core/McpAdapter.php' );
		$this->assertSame( 'McpAdapter.php', $this->block()['adapter']['path'] );
	}

	public function test_an_adapter_class_with_no_file_has_a_null_path(): void {
		$fqcn                    = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_ADAPTER_CLASS;
		$this->tool->classes     = array( $fqcn => true );
		$this->tool->class_files = array( $fqcn => null );
		$this->assertNull( $this->block()['adapter']['path'] );
	}

	public function test_a_throw_inspecting_the_adapter_replaces_only_the_adapter(): void {
		$this->tool->throw_in = array( 'adapter' );
		$b                    = $this->block();
		$this->assertSame( array( 'error' => 'adapter unreadable' ), $b['adapter'] ); // a fixed literal, never the throw's text
		$this->assertSame( array( 'option_present' => false, 'enabled' => false ), $b['switch'] );
		$this->assertSame( array( 'class_present' => false, 'version' => null, 'path' => null ), $b['composer'] );
	}

	public function test_the_composer_copy_is_read_from_the_package_manifest_two_directories_up(): void {
		$fqcn                    = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS;
		$file                    = ABSPATH . 'wp-content/plugins/elementor/vendor/elementor/mcp-composer/src/Mcp/Server_Bootstrap.php';
		$this->tool->classes     = array( $fqcn => true );
		$this->tool->class_files = array( $fqcn => $file );
		$this->tool->json        = array(
			ABSPATH . 'wp-content/plugins/elementor/vendor/elementor/mcp-composer/composer.json' => array( 'version' => '1.0.13' ),
		);
		$this->assertSame(
			array(
				'class_present' => true,
				'version'       => '1.0.13',
				'path'          => 'wp-content/plugins/elementor/vendor/elementor/mcp-composer/src/Mcp/Server_Bootstrap.php',
			),
			$this->block()['composer']
		);
		$this->assertSame(
			array( ABSPATH . 'wp-content/plugins/elementor/vendor/elementor/mcp-composer/composer.json' ),
			$this->tool->json_reads
		);
	}

	public function test_an_unreadable_manifest_leaves_the_version_null_and_the_copy_reported(): void {
		// Missing, over 64 KB, or not JSON: the seam answers null for each, and
		// the copy that resolves is still worth reporting.
		$fqcn                    = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS;
		$this->tool->classes     = array( $fqcn => true );
		$this->tool->class_files = array( $fqcn => ABSPATH . 'wp-content/plugins/x/src/Mcp/Server_Bootstrap.php' );
		$this->tool->json        = array();
		$this->assertSame(
			array(
				'class_present' => true,
				'version'       => null,
				'path'          => 'wp-content/plugins/x/src/Mcp/Server_Bootstrap.php',
			),
			$this->block()['composer']
		);
	}

	public function test_a_manifest_version_that_is_not_a_string_is_null(): void {
		$fqcn                    = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS;
		$this->tool->classes     = array( $fqcn => true );
		$this->tool->class_files = array( $fqcn => ABSPATH . 'wp-content/plugins/x/src/Mcp/Server_Bootstrap.php' );
		$this->tool->json        = array( ABSPATH . 'wp-content/plugins/x/composer.json' => array( 'version' => array( '1.0.13' ) ) );
		$this->assertNull( $this->block()['composer']['version'] );
		$this->assertTrue( $this->block()['composer']['class_present'] );
	}

	public function test_the_composer_manifest_is_not_read_when_the_class_is_absent(): void {
		$this->assertSame( array(), $this->tool->json_reads );
		$this->assertSame( array( 'class_present' => false, 'version' => null, 'path' => null ), $this->block()['composer'] );
		$this->assertSame( array(), $this->tool->json_reads );
	}

	public function test_a_throw_reading_the_manifest_is_the_composer_subtree_error(): void {
		$fqcn                    = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS;
		$this->tool->classes     = array( $fqcn => true );
		$this->tool->class_files = array( $fqcn => ABSPATH . 'wp-content/plugins/x/src/Mcp/Server_Bootstrap.php' );
		$this->tool->throw_in    = array( 'json' );
		$b                       = $this->block();
		$this->assertSame( array( 'error' => 'composer unreadable' ), $b['composer'] ); // a fixed literal, never the throw's text
		$this->assertSame( array( 'class_present' => false, 'version' => null, 'path' => null ), $b['adapter'] );
		$this->assertSame( array( 'option_present' => false, 'enabled' => false ), $b['switch'] );
	}

	public function test_a_throw_inspecting_the_composer_class_replaces_only_the_composer(): void {
		$this->tool->throw_in = array( 'composer' );
		$b                    = $this->block();
		$this->assertSame( array( 'error' => 'composer unreadable' ), $b['composer'] );
		$this->assertSame( array( 'class_present' => false, 'version' => null, 'path' => null ), $b['adapter'] );
	}

	public function test_a_long_path_and_a_long_version_are_clipped_at_200(): void {
		$fqcn                        = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_ADAPTER_CLASS;
		$this->tool->classes         = array( $fqcn => true );
		$this->tool->class_files     = array( $fqcn => ABSPATH . str_repeat( 'p', 300 ) . '.php' );
		$this->tool->class_constants = array( $fqcn => array( 'VERSION' => str_repeat( 'v', 300 ) ) );
		$a                           = $this->block()['adapter'];
		$this->assertSame( 200, strlen( $a['path'] ) );
		$this->assertSame( 200, strlen( $a['version'] ) );
	}


	// --- Fix round 1 / I1: no absolute server path may leave through { error }
	// A site that converts warnings to exceptions (Whoops, which Bedrock ships;
	// a hardening plugin calling set_error_handler) turns an open_basedir or
	// permission warning into a Throwable whose MESSAGE carries the absolute
	// path — and subtree_error() would publish it verbatim, defeating
	// abspath_relative() on the one subtree that touches the filesystem.

	public function test_a_throw_carrying_an_absolute_path_never_reaches_the_composer_error(): void {
		$fqcn                      = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS;
		$this->tool->classes       = array( $fqcn => true );
		$this->tool->class_files   = array( $fqcn => ABSPATH . 'wp-content/plugins/x/src/Mcp/Server_Bootstrap.php' );
		$this->tool->throw_in      = array( 'json' );
		$this->tool->throw_message = 'file_get_contents(' . ABSPATH . 'wp-content/plugins/x/composer.json): failed to open stream';
		$error                     = $this->block()['composer']['error'];
		$this->assertSame( 'composer unreadable', $error ); // a fixed literal: nothing of the throw, so nothing of the path
		$this->assertStringNotContainsString( ABSPATH, $error );
	}

	public function test_a_throw_carrying_an_absolute_path_never_reaches_the_adapter_error(): void {
		$this->tool->throw_in      = array( 'adapter' );
		$this->tool->throw_message = 'autoloader died reading ' . ABSPATH . 'wp-content/plugins/emcp/vendor/autoload.php';
		$error                     = $this->block()['adapter']['error'];
		$this->assertSame( 'adapter unreadable', $error );
		$this->assertStringNotContainsString( ABSPATH, $error );
	}

	public function test_the_abspath_stripper_is_pure_and_handles_both_separators(): void {
		$win = str_replace( '/', '\\', ABSPATH );
		$this->assertSame(
			'file_get_contents(wp-content/x/composer.json): denied',
			Aura_Tool_Audit_Mcp_Exposure::without_abspath( 'file_get_contents(' . ABSPATH . 'wp-content/x/composer.json): denied' )
		);
		// Separators are normalised to `/` on the way out (Codex round-1 on
		// #125): the message is an error string, so the remainder is spelled
		// forward-slash whatever the platform spelled it.
		$this->assertSame(
			'open_basedir restriction: wp-content/x/composer.json',
			Aura_Tool_Audit_Mcp_Exposure::without_abspath( 'open_basedir restriction: ' . $win . 'wp-content\\x\\composer.json' )
		);
		// The directory named without its trailing separator is stripped too.
		$this->assertSame( 'no such directory: ', Aura_Tool_Audit_Mcp_Exposure::without_abspath( 'no such directory: ' . rtrim( ABSPATH, '/' ) ) );
		// A message with no path in it is untouched.
		$this->assertSame( 'json exploded', Aura_Tool_Audit_Mcp_Exposure::without_abspath( 'json exploded' ) );
		// Windows, mixed separators (Codex round-1 on #125): an ABSPATH spelled
		// with backslashes and a message spelling the same directory with
		// forward slashes — or the reverse — still strips.
		$mixed = 'C:\\site\\wp/';
		$this->assertSame( 'open(vendor/x.php): denied', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(C:/site/wp/vendor/x.php): denied', $mixed ) );
		$this->assertSame( 'open(vendor/x.php): denied', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(C:\\site\\wp\\vendor/x.php): denied', $mixed ) );
		$this->assertSame( 'open(vendor/x.php): denied', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(C:/site/wp\\vendor\\x.php): denied', 'C:/site/wp/' ) );
		// …and case-insensitively, because Windows is (Codex round-2 on #125).
		$this->assertSame( 'open(vendor/x.php): denied', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(c:/SITE/wp/vendor/x.php): denied', 'C:\\Site\\WP\\' ) );
		// A sibling whose name merely STARTS with the root is not this tree
		// (Codex round-6 on #125): `/srv/site-old/…` beside `/srv/site/` stays.
		$this->assertSame( 'open(/srv/site-old/vendor.php)', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(/srv/site-old/vendor.php)', '/srv/site/' ) );
		$this->assertSame( 'open(vendor.php) in ', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(/srv/site/vendor.php) in /srv/site', '/srv/site/' ) );
		// …and a path that CONTAINS the root as a later segment is another tree
		// too (Codex round-7 on #125): `/mnt/srv/site/x` beside `/srv/site/`.
		$this->assertSame( 'open(/mnt/srv/site/vendor.php)', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(/mnt/srv/site/vendor.php)', '/srv/site/' ) );
		$this->assertSame( 'x/mnt/srv/site/vendor.php', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'x/mnt/srv/site/vendor.php', '/srv/site/' ) );
		// The boundary is the message-delimiter set, not a path-character
		// allowlist (Codex round-9 on #125): `+` is a filename byte.
		$this->assertSame( 'open(/mnt/backup+/srv/site/vendor.php)', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(/mnt/backup+/srv/site/vendor.php)', '/srv/site/' ) );
		$this->assertSame( 'open(/srv/site+old/vendor.php)', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(/srv/site+old/vendor.php)', '/srv/site/' ) );
		// A stream-wrapper spelling of an in-tree path is still relativised.
		$this->assertSame( 'open(file://vendor.php)', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(file:///srv/site/vendor.php)', '/srv/site/' ) );
		$this->assertSame( 'path=vendor.php, root=', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'path=/srv/site/vendor.php, root=/srv/site', '/srv/site/' ) );
		// A POSIX root strips byte-exactly: the differently-cased twin is not this tree.
		$this->assertSame( 'open(/srv/site/vendor/x.php): denied', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(/srv/site/vendor/x.php): denied', '/srv/Site/' ) );
		// ABSPATH '/' (a container): the leading slash of each path token goes,
		// other slashes stay (Codex round-5 on #125).
		$this->assertSame( 'open(wp-content/plugins/x/vendor.php): denied at wp-content/y', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(/wp-content/plugins/x/vendor.php): denied at /wp-content/y', '/' ) );
		$this->assertSame( 'a/b and 3/4', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'a/b and 3/4', '/' ) );
		// A stream-wrapper spelling under a '/' root (Codex round-8 on #125).
		$this->assertSame( 'open(file://wp-content/plugins/x.php)', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(file:///wp-content/plugins/x.php)', '/' ) );
		// UNC root in the message, different casing.
		$this->assertSame( 'open(vendor/x.php)', Aura_Tool_Audit_Mcp_Exposure::without_abspath_from( 'open(//SERVER/Share/site/vendor/x.php)', '\\\\server\\share\\site\\' ) );
	}

	public function test_the_real_manifest_seam_converts_a_raising_read_to_a_fixed_message(): void {
		// The real conversion, on every supported PHP: with a throwing error
		// handler installed, a path PHP refuses raises (a warning on 7.4, a
		// ValueError on 8.x) inside the seam — and what comes out is the fixed
		// message, never the raised one.
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public function read( $path ) {
				return $this->read_small_json( $path );
			}
		};
		stream_wrapper_register( 'sa-refusing', 'SA_Refusing_Stream' );
		set_error_handler(
			static function ( $severity, $message, $file = '', $line = 0 ) {
				throw new ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		$thrown = null;
		try {
			$seam->read( 'sa-refusing://' . ABSPATH . 'wp-content/plugins/x/composer.json' );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		} finally {
			restore_error_handler();
			stream_wrapper_unregister( 'sa-refusing' );
		}
		$this->assertInstanceOf( RuntimeException::class, $thrown );
		$this->assertSame( Aura_Tool_Audit_Mcp_Exposure::MANIFEST_UNREADABLE, $thrown->getMessage() );
		$this->assertStringNotContainsString( ABSPATH, $thrown->getMessage() );
		$this->assertNull( $thrown->getPrevious() ); // the raised message is not carried along either
	}

	public function test_a_manifest_that_grows_after_the_stat_is_still_bounded_during_the_read(): void {
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public function read( $path ) {
				return $this->read_small_json( $path );
			}
		};
		stream_wrapper_register( 'sa-growing', 'SA_Growing_Stream' );
		try {
			$this->assertNull( $seam->read( 'sa-growing://composer.json' ) );
		} finally {
			stream_wrapper_unregister( 'sa-growing' );
		}
	}

	public function test_the_fixed_message_is_what_the_composer_subtree_reports(): void {
		$fqcn                      = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS;
		$this->tool->classes       = array( $fqcn => true );
		$this->tool->class_files   = array( $fqcn => ABSPATH . 'wp-content/plugins/x/src/Mcp/Server_Bootstrap.php' );
		$this->tool->throw_in      = array( 'json' );
		$this->tool->throw_message = Aura_Tool_Audit_Mcp_Exposure::MANIFEST_UNREADABLE;
		$this->assertSame(
			array( 'error' => Aura_Tool_Audit_Mcp_Exposure::MANIFEST_UNREADABLE ),
			$this->block()['composer']
		);
	}

	// --- Fix round 1 / I2: the two-step autoload rule ------------------------

	public function test_the_presence_seam_asks_without_the_autoloader_first_and_with_it_once(): void {
		// A seam under class_present() records the autoload flag of every
		// lookup, so neither half of `class_exists( $f, false ) || class_exists( $f )`
		// can be deleted with the suite still green.
		$probe = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			/** @var bool[] the autoload flag of each lookup, in order */
			public $flags = array();
			/** @var bool[] fqcn => what the NON-autoloading lookup answers */
			public $loaded = array();
			/** @var bool[] fqcn => what the autoloading lookup answers */
			public $autoloadable = array();
			protected function class_declared( $fqcn, $autoload ) {
				$this->flags[] = (bool) $autoload;
				return $autoload
					? ! empty( $this->autoloadable[ $fqcn ] )
					: ! empty( $this->loaded[ $fqcn ] );
			}
			public function present( $fqcn ) {
				return $this->class_present( $fqcn );
			}
		};

		// Already loaded: answered without the autoloader, and the autoloading
		// lookup is never made.
		$probe->loaded = array( 'A' => true );
		$this->assertTrue( $probe->present( 'A' ) );
		$this->assertSame( array( false ), $probe->flags );

		// Not loaded but registered: the second lookup, with the autoloader,
		// is what finds it — this is the copy a real request would resolve.
		$probe->flags        = array();
		$probe->autoloadable = array( 'B' => true );
		$this->assertTrue( $probe->present( 'B' ) );
		$this->assertSame( array( false, true ), $probe->flags );

		// Absent either way: asked twice, at most once with the autoloader.
		$probe->flags = array();
		$this->assertFalse( $probe->present( 'C' ) );
		$this->assertSame( array( false, true ), $probe->flags );
	}

	public function test_the_real_presence_seam_resolves_a_registered_class_through_the_autoloader_once(): void {
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public function present( $fqcn ) {
				return $this->class_present( $fqcn );
			}
		};
		$name   = 'SA_Autoloaded_Probe_' . getmypid();
		$calls  = 0;
		$loader = static function ( $requested ) use ( $name, &$calls ) {
			if ( $requested === $name ) {
				++$calls;
				eval( 'class ' . $name . ' {}' ); // phpcs:ignore Squiz.PHP.Eval
			}
		};
		spl_autoload_register( $loader );
		try {
			$this->assertFalse( class_exists( $name, false ) ); // not loaded yet
			$this->assertTrue( $seam->present( $name ) );       // the autoloading lookup found it
			$this->assertSame( 1, $calls );
			$this->assertTrue( class_exists( $name, false ) );
		} finally {
			spl_autoload_unregister( $loader );
		}
	}

	// --- Fix round 1 / M1: the manifest is only read at the package layout ---

	public function test_a_composer_copy_outside_the_package_layout_reads_no_manifest(): void {
		// `<pkg>/composer.json` is two directories above
		// `<pkg>/src/Mcp/Server_Bootstrap.php`. A flattened or relocated copy
		// would make that formula point at a STRANGER's manifest, and a wrong
		// version in an audit is worse than none.
		$fqcn                    = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_CLASS;
		$this->tool->classes     = array( $fqcn => true );
		$this->tool->class_files = array( $fqcn => ABSPATH . 'wp-content/plugins/foo/src/Server_Bootstrap.php' );
		$this->tool->json        = array( ABSPATH . 'wp-content/plugins/composer.json' => array( 'version' => '9.9.9' ) );
		$this->assertSame(
			array(
				'class_present' => true,
				'version'       => null,
				'path'          => 'wp-content/plugins/foo/src/Server_Bootstrap.php',
			),
			$this->block()['composer']
		);
		$this->assertSame( array(), $this->tool->json_reads );
	}

	// --- Fix round 1 / M2: native separators and doubled slashes ------------

	public function test_the_relative_path_survives_native_separators_and_doubled_slashes(): void {
		$win = str_replace( '/', '\\', ABSPATH );
		$this->assertSame(
			'wp-content/plugins/x/McpAdapter.php',
			Aura_Tool_Audit_Mcp_Exposure::abspath_relative( $win . 'wp-content\\plugins\\x\\McpAdapter.php' )
		);
		$this->assertSame(
			'wp-content/plugins/x/McpAdapter.php',
			Aura_Tool_Audit_Mcp_Exposure::abspath_relative( ABSPATH . 'wp-content//plugins/x/McpAdapter.php' )
		);
		$this->assertSame( 'McpAdapter.php', Aura_Tool_Audit_Mcp_Exposure::abspath_relative( '/opt/elsewhere/McpAdapter.php' ) );
		// Windows casing (Codex round-3 on #125): the same root spelled in a
		// different case is still the root, so the directory survives.
		$this->assertSame(
			'wp-content/plugins/x/McpAdapter.php',
			Aura_Tool_Audit_Mcp_Exposure::abspath_relative_from( 'c:/site/wp/wp-content/plugins/x/McpAdapter.php', 'C:\\Site\\WP/' )
		);
		$this->assertSame( 'McpAdapter.php', Aura_Tool_Audit_Mcp_Exposure::abspath_relative_from( 'D:/other/McpAdapter.php', 'C:\\Site\\WP/' ) );
		// …but a POSIX root is byte-exact (Codex round-4 on #125): `/srv/site/`
		// is not `/srv/Site/`, so a file under the twin is outside WordPress.
		$this->assertSame( 'McpAdapter.php', Aura_Tool_Audit_Mcp_Exposure::abspath_relative_from( '/srv/site/wp-content/plugins/foo/McpAdapter.php', '/srv/Site/' ) );
		$this->assertSame( 'wp-content/plugins/foo/McpAdapter.php', Aura_Tool_Audit_Mcp_Exposure::abspath_relative_from( '/srv/Site/wp-content/plugins/foo/McpAdapter.php', '/srv/Site/' ) );
		$this->assertTrue( Aura_Tool_Audit_Mcp_Exposure::is_windows_root( 'c:/x/' ) );
		$this->assertTrue( Aura_Tool_Audit_Mcp_Exposure::is_windows_root( '//server/share/site/' ) );
		// UNC root, different casing (Codex round-5 on #125): still one directory.
		$this->assertSame( 'wp-content/plugins/x/McpAdapter.php', Aura_Tool_Audit_Mcp_Exposure::abspath_relative_from( '//SERVER/share/Site/wp-content/plugins/x/McpAdapter.php', '\\\\server\\share\\site\\' ) );
		// ABSPATH is the filesystem root: relative = the path without its leading slash.
		$this->assertSame( 'wp-content/plugins/x/McpAdapter.php', Aura_Tool_Audit_Mcp_Exposure::abspath_relative_from( '/wp-content/plugins/x/McpAdapter.php', '/' ) );
		$this->assertFalse( Aura_Tool_Audit_Mcp_Exposure::is_windows_root( '/srv/x/' ) );
		$this->assertSame( 'McpAdapter.php', Aura_Tool_Audit_Mcp_Exposure::abspath_relative( 'C:\\elsewhere\\McpAdapter.php' ) );
	}

	// --- Fix round 1 / M10: the size boundary, and a subtree that reads no file

	public function test_the_manifest_size_boundary_is_at_most_64_kb(): void {
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public function read( $path ) {
				return $this->read_small_json( $path );
			}
		};
		$dir = sys_get_temp_dir() . '/sa-composer-bound-' . getmypid() . '-' . uniqid();
		mkdir( $dir, 0777, true );
		$max  = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_COMPOSER_JSON_MAX;
		$at   = $dir . '/at.json';
		$over = $dir . '/over.json';
		// Exactly the cap, and one byte over it, both valid JSON.
		$head = '{"version":"1.0.13","pad":"';
		$tail = '"}';
		file_put_contents( $at, $head . str_repeat( 'x', $max - strlen( $head ) - strlen( $tail ) ) . $tail );
		file_put_contents( $over, $head . str_repeat( 'x', $max - strlen( $head ) - strlen( $tail ) + 1 ) . $tail );
		try {
			$this->assertSame( $max, filesize( $at ) );
			$this->assertSame( $max + 1, filesize( $over ) );
			$this->assertSame( '1.0.13', $seam->read( $at )['version'] ); // at the cap: read
			$this->assertNull( $seam->read( $over ) );                    // one byte over: not read
		} finally {
			foreach ( array( $at, $over ) as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
			rmdir( $dir );
		}
	}

	public function test_the_adapter_subtree_never_touches_the_filesystem(): void {
		$fqcn                        = Aura_Tool_Audit_Mcp_Exposure::ELEMENTOR_ADAPTER_CLASS;
		$this->tool->classes         = array( $fqcn => true );
		$this->tool->class_constants = array( $fqcn => array( 'VERSION' => '0.6.1' ) );
		$this->tool->class_files     = array( $fqcn => ABSPATH . 'wp-content/plugins/elementor/vendor/x/McpAdapter.php' );
		$this->assertSame( '0.6.1', $this->block()['adapter']['version'] );
		$this->assertSame( array(), $this->tool->json_reads );
	}

	// --- the REAL seams: reflection, and the one bounded file read ----------

	public function test_the_real_class_seams_read_a_class_that_exists(): void {
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public function present( $fqcn ) {
				return $this->class_present( $fqcn );
			}
			public function file( $fqcn ) {
				return $this->class_file( $fqcn );
			}
			public function constant_of( $fqcn, $name ) {
				return $this->class_constant( $fqcn, $name );
			}
		};
		$this->assertTrue( $seam->present( 'Aura_Tool_Audit_Mcp_Exposure' ) );
		$this->assertFalse( $seam->present( '\\Definitely\\Not\\Here' ) );
		$this->assertNull( $seam->file( '\\Definitely\\Not\\Here' ) );
		$this->assertNull( $seam->constant_of( '\\Definitely\\Not\\Here', 'VERSION' ) );
		$this->assertStringEndsWith( 'class-tool-audit-mcp-exposure.php', (string) $seam->file( 'Aura_Tool_Audit_Mcp_Exposure' ) );
		$this->assertSame( 500, $seam->constant_of( 'Aura_Tool_Audit_Mcp_Exposure', 'MAX_ABILITIES' ) );
		$this->assertNull( $seam->constant_of( 'Aura_Tool_Audit_Mcp_Exposure', 'NO_SUCH_CONSTANT' ) );
	}

	public function test_the_real_manifest_seam_is_bounded(): void {
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public function read( $path ) {
				return $this->read_small_json( $path );
			}
		};
		$dir = sys_get_temp_dir() . '/sa-composer-' . getmypid() . '-' . uniqid();
		mkdir( $dir, 0777, true );
		$good = $dir . '/good.json';
		$bad  = $dir . '/bad.json';
		$big  = $dir . '/big.json';
		file_put_contents( $good, '{"version":"1.0.13","name":"elementor/mcp-composer"}' );
		file_put_contents( $bad, '{"version": ' );
		file_put_contents( $big, '{"version":"1.0.13","pad":"' . str_repeat( 'x', 70000 ) . '"}' );
		try {
			$this->assertSame( '1.0.13', $seam->read( $good )['version'] );
			$this->assertNull( $seam->read( $bad ) );
			$this->assertNull( $seam->read( $big ) );
			$this->assertNull( $seam->read( $dir . '/missing.json' ) );
			$this->assertNull( $seam->read( $dir ) );   // a directory is not a file
			$this->assertNull( $seam->read( '' ) );
		} finally {
			foreach ( array( $good, $bad, $big ) as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
	}

	// --- Codex round-1 P2: a deactivated Elementor is still "installed" ----
	// via the plugin inventory, never only via runtime signals. These three
	// run the REAL elementor_env()/elementor_plugin_header() (the fake tool
	// overrides elementor_env() wholesale, so it cannot exercise this path).

	public function test_a_deactivated_elementor_is_installed_with_its_header_version(): void {
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_plugin_header() {
				return array( 'Name' => 'Elementor', 'Version' => '4.3.0-beta1' );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$b = $real->execute( array() )['elementor'];
		$this->assertTrue( $b['installed'] );
		$this->assertSame( '4.3.0-beta1', $b['version'] );
		$this->assertFalse( $b['mcp_module']['class_present'] );
		$this->assertNull( $b['mcp_module']['active'] ); // the class is not loaded in the suite
	}

	public function test_no_header_and_no_runtime_signal_is_not_installed(): void {
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_plugin_header() {
				return null;
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$b = $real->execute( array() )['elementor'];
		$this->assertFalse( $b['installed'] );
		$this->assertNull( $b['version'] );
	}

	public function test_the_real_plugin_inventory_answers_installed_and_version(): void {
		// The bootstrap's get_plugins() stub reads this global (~line 1332);
		// sa_reset_state() also unsets it, but the finally{} below is belt and
		// braces so a failed assertion still cannot leak it into later tests.
		$GLOBALS['_installed_plugins'] = array(
			'elementor/elementor.php' => array( 'Name' => 'Elementor', 'Version' => '4.2.4' ),
		);
		try {
			$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
				protected function consent_rows() {
					return array();
				}
				protected function elementor_candidate_ids() {
					return array();
				}
				protected function context_user_ids( $offset, $number ) {
					return array();
				}
				protected function context_users_total() {
					return 0;
				}
			};
			$b = $real->execute( array() )['elementor'];
			$this->assertTrue( $b['installed'] );
			$this->assertSame( '4.2.4', $b['version'] );
		} finally {
			unset( $GLOBALS['_installed_plugins'] );
		}
	}

	// --- Task 3: consent ----------------------------------------------------

	private static function consent_row( int $uid, $data, ?string $login = null ): object {
		$raw = is_string( $data ) ? $data : serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		return (object) array( 'user_id' => $uid, 'user_login' => $login ?? 'user' . $uid, 'len' => strlen( $raw ), 'v' => $raw );
	}

	public function test_a_consent_row_is_reported_with_its_owner_and_time(): void {
		$this->tool->consent_rows = array( self::consent_row( 3, array( 'allowed' => true, 'timestamp' => 1788000000 ), 'ben' ) );
		$b = $this->block();
		$this->assertSame( array( array( 'user_id' => 3, 'login' => 'ben', 'allowed' => true, 'timestamp' => 1788000000 ) ), $b['consent'] );
		$this->assertSame( array(), $b['consent_unproven'] );
		$this->assertFalse( $b['consent_truncated'] );
	}

	public function test_consent_is_read_for_every_user_not_only_edit_posts(): void {
		// The seam is a usermeta query with no capability filter; a demoted
		// user's consent row still comes back. Modelled: no context pages at all.
		$this->tool->context_pages = array();
		$this->tool->consent_rows  = array( self::consent_row( 42, array( 'allowed' => true, 'timestamp' => 1 ) ) );
		$this->assertSame( 42, $this->block()['consent'][0]['user_id'] );
	}

	public function test_allowed_false_is_reported_as_false(): void {
		$this->tool->consent_rows = array( self::consent_row( 3, array( 'allowed' => false, 'timestamp' => 5 ) ) );
		$this->assertFalse( $this->block()['consent'][0]['allowed'] );
	}

	public function test_allowed_is_a_strict_bool_from_storage(): void {
		// Elementor stores a bool; a legacy or edited row may hold 1 / '1'.
		// Anything else — 'yes', 'true', 2 — is NOT consent.
		$rows = array();
		foreach ( array( true, 1, '1', 'yes', 'true', 2, null ) as $i => $v ) {
			$rows[] = self::consent_row( $i + 1, array( 'allowed' => $v, 'timestamp' => 5 ) );
		}
		$this->tool->consent_rows = $rows;
		$got = array_map( static function ( $r ) { return $r['allowed']; }, $this->block()['consent'] );
		$this->assertSame( array( true, true, true, false, false, false, false ), $got );
	}

	public function test_a_missing_or_non_numeric_timestamp_is_null(): void {
		$this->tool->consent_rows = array(
			self::consent_row( 1, array( 'allowed' => true ) ),
			self::consent_row( 2, array( 'allowed' => true, 'timestamp' => 'soon' ) ),
			self::consent_row( 3, array( 'allowed' => true, 'timestamp' => '1788000000' ) ),
		);
		$got = array_map( static function ( $r ) { return $r['timestamp']; }, $this->block()['consent'] );
		$this->assertSame( array( null, null, 1788000000 ), $got );
	}

	public function test_an_oversized_consent_row_is_unproven_and_never_decoded(): void {
		// The seam returns v NULL when LENGTH exceeded the bound in the
		// statement; nothing here may try to read it.
		$this->tool->consent_rows = array( (object) array( 'user_id' => 9, 'user_login' => 'big', 'len' => 999999, 'v' => null ) );
		$b = $this->block();
		$this->assertSame( array(), $b['consent'] );
		$this->assertSame( array( 9 ), $b['consent_unproven'] );
	}

	public function test_a_row_that_is_not_the_documented_shape_is_unproven(): void {
		$this->tool->consent_rows = array(
			self::consent_row( 5, 'not-serialized-garbage' ),
			self::consent_row( 6, array( 'no_allowed_key' => 1 ) ),
		);
		$b = $this->block();
		$this->assertSame( array(), $b['consent'] );
		$this->assertSame( array( 5, 6 ), $b['consent_unproven'] );
	}

	public function test_a_row_with_a_valid_envelope_but_a_corrupt_serialization_is_unproven_without_warning(): void {
		// Codex round-7 P2: is_serialized() is a SHAPE check — it passes a
		// payload that declares 2 elements and holds 1, which makes a bare
		// unserialize() emit E_WARNING. This test passing at all IS the proof:
		// PHPUnit converts an escaped E_WARNING into a fatal error, so if the
		// fix's warning suppression ever regresses, this test does not merely
		// fail an assertion — it errors out with the warning itself.
		$this->tool->consent_rows = array( self::consent_row( 5, 'a:2:{s:7:"allowed";b:1;}' ) );
		$b                         = $this->block();
		$this->assertSame( array(), $b['consent'] );
		$this->assertSame( array( 5 ), $b['consent_unproven'] );
	}

	public function test_fifty_one_consent_rows_are_fifty_and_truncated(): void {
		$rows = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$rows[] = self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertCount( 50, $b['consent'] );
		$this->assertSame( 50, $b['consent'][49]['user_id'] );
		$this->assertTrue( $b['consent_truncated'] );
	}

	public function test_truncation_counts_unproven_rows_toward_the_fifty(): void {
		// Parser invariant: consent_truncated ⇒ count(consent) + count(unproven) == 50.
		$rows = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$rows[] = 25 === $i
				? (object) array( 'user_id' => $i, 'user_login' => 'x', 'len' => 999999, 'v' => null )
				: self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertTrue( $b['consent_truncated'] );
		$this->assertSame( 50, count( $b['consent'] ) + count( $b['consent_unproven'] ) );
	}

	public function test_a_login_is_clipped_and_a_missing_login_falls_back_to_the_id(): void {
		$this->tool->consent_rows = array(
			self::consent_row( 1, array( 'allowed' => true, 'timestamp' => 1 ), str_repeat( 'l', 300 ) ),
			(object) array( 'user_id' => 2, 'user_login' => null, 'len' => 10, 'v' => serialize( array( 'allowed' => true, 'timestamp' => 1 ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		);
		$c = $this->block()['consent'];
		$this->assertSame( 200, strlen( $c[0]['login'] ) );
		$this->assertSame( 'user:2', $c[1]['login'] );
	}

	public function test_a_throw_in_the_consent_scan_replaces_only_consent(): void {
		$this->tool->throw_in = array( 'consent' );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'consent exploded' ), $b['consent'] );
		$this->assertArrayNotHasKey( 'consent_truncated', $b );
		$this->assertArrayNotHasKey( 'consent_unproven', $b );
		$this->assertSame( true, $b['installed'] ); // the module subtree survived
	}

	public function test_a_serialised_object_in_a_consent_row_is_never_instantiated(): void {
		// A crafted meta_value must never become a live object: allowed_classes
		// must be false, or a gadget's __wakeup()/__destruct() could run.
		$obj_row = (object) array(
			'user_id'    => 9,
			'user_login' => 'attacker',
			'len'        => 60,
			'v'          => 'O:8:"stdClass":2:{s:7:"allowed";b:1;s:9:"timestamp";i:5;}',
		);
		$this->tool->consent_rows = array(
			$obj_row,
			self::consent_row( 3, array( 'allowed' => true, 'timestamp' => 5 ), 'ben' ),
		);
		$b = $this->block();
		$this->assertSame( array( 9 ), $b['consent_unproven'] );
		$this->assertCount( 1, $b['consent'] );
		$this->assertSame( 3, $b['consent'][0]['user_id'] );
		$this->assertTrue( $b['consent'][0]['allowed'] );
	}

	public function test_invalid_user_ids_are_filtered_before_the_cap(): void {
		// A user_id <= 0 row must not consume one of the 50 slots — the
		// truncation invariant (consent + consent_unproven == 50) must hold
		// by construction, not merely when every row happens to be valid.
		$rows = array();
		for ( $i = 1; $i <= 52; $i++ ) {
			$rows[] = 3 === $i
				? (object) array( 'user_id' => 0, 'user_login' => 'nobody', 'len' => 10, 'v' => serialize( array( 'allowed' => true, 'timestamp' => $i ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				: self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertTrue( $b['consent_truncated'] );
		$this->assertSame( 50, count( $b['consent'] ) + count( $b['consent_unproven'] ) );
	}

	public function test_duplicate_rows_for_one_user_are_deduped_the_first_wins(): void {
		// Two rows for uid 7 (the query orders by umeta_id ASC, so the first
		// row IS the oversized one here) must not put 7 in both `consent` and
		// `consent_unproven` — that would break the invariant
		// consent_unproven ∩ consent[].user_id = ∅.
		$this->tool->consent_rows = array(
			(object) array( 'user_id' => 7, 'user_login' => 'dup', 'len' => 999999, 'v' => null ), // oversized, first
			self::consent_row( 7, array( 'allowed' => true, 'timestamp' => 1 ) ), // valid, later — dropped
			self::consent_row( 8, array( 'allowed' => true, 'timestamp' => 2 ) ),
		);
		$b = $this->block();
		$this->assertSame( array( 7 ), $b['consent_unproven'] );
		$this->assertSame( array( 8 ), array_map( static function ( $r ) { return $r['user_id']; }, $b['consent'] ) );
	}

	public function test_a_duplicate_among_fifty_two_rows_is_still_fifty_and_truncated(): void {
		$rows = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$rows[] = self::consent_row( $i, array( 'allowed' => true, 'timestamp' => $i ) );
		}
		// A 52nd row duplicating uid 1: dropped by the dedupe, not counted
		// toward the 50-row cap at all.
		$rows[] = self::consent_row( 1, array( 'allowed' => false, 'timestamp' => 999 ) );
		$this->tool->consent_rows = $rows;
		$b = $this->block();
		$this->assertTrue( $b['consent_truncated'] );
		$this->assertSame( 50, count( $b['consent'] ) + count( $b['consent_unproven'] ) );
		// The FIRST row for uid 1 (allowed:true) is the one kept.
		$this->assertTrue( $b['consent'][0]['allowed'] );
	}

	public function test_the_consent_statement_is_bounded_in_rows_and_bytes(): void {
		// The REAL seam against the bootstrap's $wpdb: pins the SQL shape.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_db_rows'] = array( self::consent_row( 4, array( 'allowed' => true, 'timestamp' => 7 ), 'ben' ) );
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( 4, $b['consent'][0]['user_id'] );
		$prepared = array_values( array_filter( $GLOBALS['_db_prepared'], static function ( $p ) {
			return false !== strpos( $p['query'], 'elementor_mcp_consent' ) || in_array( 'elementor_mcp_consent', $p['args'], true );
		} ) );
		$this->assertCount( 1, $prepared );
		// Exact-string pin: proves the statement returns one row per user
		// (MIN(umeta_id), the dedupe subquery) and excludes invalid ids
		// (m.user_id > 0) BEFORE the LIMIT is applied — the fix for Codex
		// round-4 P2 (#85), where 51 raw rows could dedupe to <= 50 distinct
		// users and read consent_truncated as false while a later user's row
		// was never fetched.
		// Codex round-10 P2: the statement is probe/sentinel shaped (the
		// #434 usermeta_holders() pattern) so its result set proves it ran;
		// the inner SELECT is the round-4 statement verbatim.
		$this->assertSame(
			"SELECT %s AS probe, 0 AS user_id, NULL AS user_login, NULL AS len, NULL AS v, NULL AS umeta_id UNION ALL SELECT %s AS probe, c.user_id, c.user_login, c.len, c.v, c.umeta_id FROM (SELECT m.user_id, u.user_login, LENGTH(m.meta_value) AS len, IF(LENGTH(m.meta_value) <= %d, m.meta_value, NULL) AS v, m.umeta_id FROM wp_usermeta m LEFT JOIN wp_users u ON u.ID = m.user_id WHERE m.meta_key = %s AND m.user_id > 0 AND m.umeta_id IN (SELECT MIN(umeta_id) FROM wp_usermeta WHERE meta_key = %s GROUP BY user_id) ORDER BY m.umeta_id ASC LIMIT %d) AS c",
			$prepared[0]['query']
		);
		$this->assertStringContainsString( 'MIN(umeta_id)', $prepared[0]['query'] );
		$this->assertStringContainsString( 'm.user_id > 0', $prepared[0]['query'] );
		$args = $prepared[0]['args'];
		$this->assertCount( 6, $args );
		$this->assertIsString( $args[0] );
		$this->assertNotSame( '', $args[0] );
		$this->assertSame( $args[0], $args[1], 'sentinel and rows carry the SAME nonce' );
		$this->assertSame( array( 262144, 'elementor_mcp_consent', 'elementor_mcp_consent', 51 ), array_slice( $args, 2 ) );
	}

	public function test_a_consent_statement_the_query_filter_blanked_is_an_error_not_a_stale_inventory(): void {
		// Codex round-10 P2: a `query` filter returning '' makes wpdb::query()
		// return before flush(), and get_results() then answers the PREVIOUS
		// statement's rows with last_error untouched. Run once for real (a
		// proven row set with nonce A), then blank the next statement: the
		// stale set carries nonce A, not this call's, and must be { error }.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_db_rows'] = array( self::consent_row( 4, array( 'allowed' => true, 'timestamp' => 7 ), 'ben' ) );
		$first = $real->execute( array() )['elementor'];
		$this->assertSame( 4, $first['consent'][0]['user_id'] );

		$GLOBALS['_sa_wpdb_query_filtered_out'] = true;
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'consent statement did not run: result set is not this statement\'s' ), $b['consent'] );
		$this->assertArrayNotHasKey( 'consent_unproven', $b );
	}

	public function test_a_consent_statement_answered_with_nothing_at_all_is_an_error_not_an_empty_inventory(): void {
		// The other stale shape: no previous statement, so the filtered-out
		// call meets an EMPTY last_result — no sentinel, nothing proved.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_sa_wpdb_query_filtered_out'] = true;
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'consent statement did not run: no sentinel row' ), $b['consent'] );
	}

	public function test_a_failed_consent_statement_is_an_error_not_an_empty_inventory(): void {
		// Codex round-2 P2: wpdb::get_results() answers its CLEARED
		// $last_result — an empty array — when the statement itself fails,
		// not false. The REAL seam against the bootstrap's $wpdb: an empty
		// row set together with a set last_error must still be reported as
		// { error }, never as "no consent rows".
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_db_rows']               = array();
		$GLOBALS['_sa_wpdb_results_error'] = 'Table wp_usermeta doesnt exist';
		$b = $real->execute( array() )['elementor'];
		$this->assertSame(
			array( 'error' => 'consent statement failed: Table wp_usermeta doesnt exist' ),
			$b['consent']
		);
		$this->assertArrayNotHasKey( 'consent_unproven', $b );
		$this->assertArrayNotHasKey( 'consent_truncated', $b );
		// The module subtree, read independently, is untouched.
		$this->assertFalse( $b['installed'] );
	}

	// --- Task 4: Elementor passwords -----------------------------------------

	private static function pw( array $over = array() ): array {
		return $over + array(
			'uuid'      => 'u-' . wp_generate_uuid4(),
			'app_id'    => '',
			'name'      => 'Elementor MCP - Claude Desktop (2026-09-01 22:25:05)',
			'password'  => 'hash',
			'created'   => 1788000000,
			'last_used' => null,
			'last_ip'   => null,
		);
	}

	private function passwords(): array {
		return $this->block()['app_passwords'];
	}

	public function test_an_elementor_named_password_is_reported_with_full_detail(): void {
		$this->tool->candidates = array( 3 );
		$this->tool->lists      = array( 3 => array( self::pw( array( 'last_used' => 1788100000, 'last_ip' => '203.0.113.9' ) ) ) );
		$p = $this->passwords();
		$this->assertSame(
			array( array( 'user_id' => 3, 'login' => 'user3', 'name' => 'Elementor MCP - Claude Desktop (2026-09-01 22:25:05)', 'created' => 1788000000, 'last_used' => 1788100000, 'last_ip' => '203.0.113.9' ) ),
			$p['elementor']
		);
		$this->assertSame( 1, $p['candidates_read'] );
		$this->assertFalse( $p['elementor_truncated'] );
		$this->assertFalse( $p['elementor_entries_truncated'] );
		$this->assertSame( array(), $p['elementor_unproven'] );
	}

	public function test_only_names_with_the_prefix_are_reported_the_like_is_a_prefilter(): void {
		// 'Not Elementor MCP' matches the LIKE and must not be reported.
		$this->tool->candidates = array( 3 );
		$this->tool->lists      = array( 3 => array( self::pw( array( 'name' => 'Not Elementor MCP' ) ), self::pw( array( 'name' => 'Aura SiteAgent' ) ), self::pw() ) );
		$p = $this->passwords();
		$this->assertCount( 1, $p['elementor'] );
		$this->assertSame( 1, $p['candidates_read'] );
	}

	public function test_a_candidate_whose_list_cannot_be_read_is_unproven_not_empty(): void {
		$this->tool->candidates = array( 3, 4 );
		$this->tool->lists      = array( 3 => null, 4 => array( self::pw() ) );
		$p = $this->passwords();
		$this->assertSame( array( 3 ), $p['elementor_unproven'] );
		$this->assertSame( 4, $p['elementor'][0]['user_id'] );
		$this->assertSame( 2, $p['candidates_read'] );
	}

	public function test_fifty_one_candidates_read_fifty_and_truncated(): void {
		$this->tool->candidates = range( 1, 51 );
		$p = $this->passwords();
		$this->assertTrue( $p['elementor_truncated'] );
		$this->assertSame( 50, $p['candidates_read'] );
		$this->assertSame( range( 1, 50 ), $this->tool->reads );
	}

	public function test_the_entry_cap_does_not_abandon_the_rest_of_the_candidate_slice(): void {
		// 51 candidates (sliced to 50), candidate 1 alone holds 60 Elementor-
		// named passwords, candidate 2's list is unreadable. Before the fix a
		// `break 2` on the entry cap stopped reading candidates the instant
		// the 50th entry was appended (candidates_read: 1), breaking the spec
		// invariant elementor_truncated ⇒ candidates_read == 50. The fix walks
		// every candidate in the slice regardless — entries past 50 are
		// simply not appended.
		$this->tool->candidates = range( 1, 51 );
		$this->tool->lists      = array(
			1 => array_fill( 0, 60, self::pw() ),
			2 => null,
		);
		$p = $this->passwords();
		$this->assertCount( 50, $p['elementor'] );
		$this->assertTrue( $p['elementor_entries_truncated'] );
		$this->assertTrue( $p['elementor_truncated'] );
		$this->assertSame( 50, $p['candidates_read'] );
		$this->assertSame( array( 2 ), $p['elementor_unproven'] );
		$this->assertSame( range( 1, 50 ), $this->tool->reads );
	}

	public function test_entries_are_capped_at_fifty_across_candidates(): void {
		$this->tool->candidates = array( 1, 2 );
		$this->tool->lists      = array(
			1 => array_fill( 0, 30, self::pw() ),
			2 => array_fill( 0, 30, self::pw() ),
		);
		$p = $this->passwords();
		$this->assertCount( 50, $p['elementor'] );
		$this->assertTrue( $p['elementor_entries_truncated'] );
		$this->assertFalse( $p['elementor_truncated'] );
		$this->assertSame( 2, $p['candidates_read'] );
	}

	public function test_exactly_fifty_entries_is_not_truncated(): void {
		$this->tool->candidates = array( 1 );
		$this->tool->lists      = array( 1 => array_fill( 0, 50, self::pw() ) );
		$this->assertFalse( $this->passwords()['elementor_entries_truncated'] );
	}

	public function test_created_and_last_used_are_ints_or_null_and_strings_are_clipped(): void {
		$this->tool->candidates = array( 1 );
		$this->tool->lists      = array( 1 => array( self::pw( array( 'name' => 'Elementor MCP ' . str_repeat( 'n', 300 ), 'created' => '1788000000', 'last_used' => 'yesterday', 'last_ip' => 5 ) ) ) );
		$e = $this->passwords()['elementor'][0];
		$this->assertSame( 200, strlen( $e['name'] ) );
		$this->assertSame( 1788000000, $e['created'] );
		$this->assertNull( $e['last_used'] );
		$this->assertNull( $e['last_ip'] );
	}

	public function test_a_list_item_that_is_not_an_array_is_skipped(): void {
		$this->tool->candidates = array( 1 );
		$this->tool->lists      = array( 1 => array( 'garbage', self::pw() ) );
		$this->assertCount( 1, $this->passwords()['elementor'] );
	}

	public function test_a_throw_in_the_password_scan_replaces_only_that_subtree(): void {
		$this->tool->throw_in = array( 'candidates' );
		$p = $this->passwords();
		$this->assertSame( array( 'error' => 'candidates exploded' ), $p['elementor'] );
		$this->assertArrayNotHasKey( 'elementor_truncated', $p );
		$this->assertArrayNotHasKey( 'candidates_read', $p );
		$this->assertArrayHasKey( 'other', $p ); // context still ran
	}

	public function test_the_candidate_statement_is_distinct_ordered_and_bounded(): void {
		// The REAL seam against the bootstrap's $wpdb; the stub models the LIKE.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_app_passwords'] = array(
			9 => array( self::pw() ),
			2 => array( self::pw( array( 'name' => 'Aura SiteAgent' ) ) ),
			5 => array( self::pw( array( 'name' => 'Not Elementor MCP' ) ) ),
		);
		$p = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( 2, $p['candidates_read'] ); // users 5 and 9 matched the LIKE
		$this->assertCount( 1, $p['elementor'] );
		$this->assertSame( 9, $p['elementor'][0]['user_id'] );
		$prepared = array_values( array_filter( $GLOBALS['_db_prepared'], static function ( $p ) {
			return false !== strpos( $p['query'], 'SELECT DISTINCT user_id' );
		} ) );
		$this->assertCount( 1, $prepared );
		// Codex round-10 P2: probe/sentinel shaped, the inner SELECT verbatim.
		$this->assertSame( 'SELECT %s AS probe, 0 AS user_id UNION ALL SELECT %s AS probe, c.user_id FROM (SELECT DISTINCT user_id FROM wp_usermeta WHERE meta_key = %s AND meta_value LIKE %s AND user_id > 0 ORDER BY user_id ASC LIMIT %d) AS c', $prepared[0]['query'] );
		$args = $prepared[0]['args'];
		$this->assertCount( 5, $args );
		$this->assertNotSame( '', $args[0] );
		$this->assertSame( $args[0], $args[1], 'sentinel and rows carry the SAME nonce' );
		$this->assertSame( array( '_application_passwords', '%Elementor MCP%', 51 ), array_slice( $args, 2 ) );
		// And each candidate was read through the BOUNDED helper.
		$bounded = array_filter( $GLOBALS['_db_queries'], static function ( $q ) {
			return false !== strpos( $q, 'IF(LENGTH(meta_value) <= 262144' );
		} );
		$this->assertCount( 2, $bounded );
	}

	public function test_a_failed_candidate_statement_is_an_error_not_an_empty_inventory(): void {
		// Codex round-2 P2: the same empty-array-on-failure shape as
		// consent, for the candidate scan. `_sa_app_password_scan_fail`
		// already models real wpdb behaviour here (last_error set, an
		// empty array returned) -- this pins that a table/driver error
		// reads as { error }, never as "no Elementor passwords".
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_sa_app_password_scan_fail'] = true;
		$p = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( array( 'error' => 'candidate statement failed: scan failed' ), $p['elementor'] );
		$this->assertArrayNotHasKey( 'candidates_read', $p );
		$this->assertArrayNotHasKey( 'elementor_truncated', $p );
	}

	public function test_a_candidate_statement_the_query_filter_blanked_is_an_error_not_a_stale_inventory(): void {
		// Codex round-10 P2, the candidate scan: same probe/sentinel proof
		// as consent_rows(). A real run first (proven, nonce A), then a
		// blanked statement meeting that stale set.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		$GLOBALS['_app_passwords'][7] = array( array( 'uuid' => 'u-7', 'name' => 'Elementor MCP (cursor)', 'created' => 1, 'last_used' => null, 'last_ip' => null ) );
		$first = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( 1, $first['candidates_read'] );

		$GLOBALS['_sa_wpdb_query_filtered_out'] = true;
		$p = $real->execute( array() )['elementor']['app_passwords'];
		$this->assertSame( array( 'error' => 'candidate statement did not run: result set is not this statement\'s' ), $p['elementor'] );
		$this->assertArrayNotHasKey( 'candidates_read', $p );
	}

	public function test_a_read_only_tool_fires_no_unproven_action_even_when_oversized(): void {
		// `audit_mcp_exposure` is annotated read_only: true, and can read up to
		// ~250 users' Application Password lists. The #434 breadcrumb listener
		// (Aura_Worker_Magic_Link::record_probe_unproven, which update_option()s
		// aura_worker_app_password_probe_unproven) is registered by
		// Aura_Worker::init() in production — never run by this bootstrap — so
		// it is registered here directly, against the REAL class the bootstrap
		// already loads (tests/bootstrap.php requires class-aura-worker-magic-
		// link.php unconditionally), proving the real boundary rather than a
		// mirror of it.
		require_once SA_PLUGIN_DIR . '/includes/class-aura-worker-magic-link.php';
		add_action( 'aura_worker_app_password_probe_unproven', array( 'Aura_Worker_Magic_Link', 'record_probe_unproven' ), 10, 1 );

		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				return array();
			}
			protected function context_users_total() {
				return 0;
			}
		};
		// One Elementor-named candidate whose list is oversized (300,000-char
		// name) — a LIKE match the bounded read then cannot decode.
		$GLOBALS['_app_passwords'] = array(
			3 => array( self::pw( array( 'name' => 'Elementor MCP ' . str_repeat( 'n', 300000 ) ) ) ),
		);
		$before = get_option( 'aura_worker_app_password_probe_unproven', null );
		$b      = $real->execute( array() )['elementor'];
		$after  = get_option( 'aura_worker_app_password_probe_unproven', null );

		$this->assertSame( $before, $after ); // the breadcrumb was never written
		$this->assertSame( array( 3 ), $b['app_passwords']['elementor_unproven'] );
	}

	// --- Task 5: context ------------------------------------------------------

	public function test_context_counts_other_passwords_and_recent_use(): void {
		$now = time();
		$this->tool->context_pages = array( array( 1, 2, 3 ) );
		$this->tool->context_total = 3;
		$this->tool->lists         = array(
			1 => array( self::pw( array( 'name' => 'Aura SiteAgent', 'last_used' => $now - 86400 ) ), self::pw( array( 'name' => 'Aura Fleet', 'last_used' => $now - 40 * 86400 ) ) ),
			2 => array(),
			3 => array( self::pw( array( 'name' => 'Zapier', 'last_used' => null ) ) ),
		);
		$b = $this->block();
		$this->assertSame( array( 'users_checked' => 3, 'count' => 3, 'recently_used' => 1, 'unproven' => array() ), $b['app_passwords']['other'] );
		$this->assertSame( array( 'users_total' => 3, 'users_checked' => 3, 'truncated' => false, 'cap' => 200 ), $b['coverage'] );
	}

	public function test_an_elementor_named_password_met_in_context_is_not_counted_twice(): void {
		$this->tool->candidates    = array( 1 );
		$this->tool->context_pages = array( array( 1 ) );
		$this->tool->context_total = 1;
		$this->tool->lists         = array( 1 => array( self::pw(), self::pw( array( 'name' => 'Aura SiteAgent' ) ) ) );
		$b = $this->block();
		$this->assertCount( 1, $b['app_passwords']['elementor'] );
		$this->assertSame( 1, $b['app_passwords']['other']['count'] );
	}

	public function test_an_unreadable_context_list_is_unproven_never_zero(): void {
		$this->tool->context_pages = array( array( 1, 2 ) );
		$this->tool->context_total = 2;
		$this->tool->lists         = array( 1 => null, 2 => array( self::pw( array( 'name' => 'X' ) ) ) );
		$o = $this->block()['app_passwords']['other'];
		$this->assertSame( array( 1 ), $o['unproven'] );
		$this->assertSame( 1, $o['count'] );
		$this->assertSame( 2, $o['users_checked'] );
	}

	public function test_context_pages_through_fifty_at_a_time(): void {
		$this->tool->context_pages = array( range( 1, 50 ), range( 51, 100 ), range( 101, 120 ) );
		$this->tool->context_total = 120;
		$c = $this->block()['coverage'];
		$this->assertSame( array( 'users_total' => 120, 'users_checked' => 120, 'truncated' => false, 'cap' => 200 ), $c );
	}

	public function test_more_than_the_cap_is_truncated_at_two_hundred(): void {
		$this->tool->context_pages = array( range( 1, 50 ), range( 51, 100 ), range( 101, 150 ), range( 151, 200 ), range( 201, 250 ) );
		$this->tool->context_total = 250;
		$b = $this->block();
		$this->assertSame( array( 'users_total' => 250, 'users_checked' => 200, 'truncated' => true, 'cap' => 200 ), $b['coverage'] );
		$this->assertSame( 200, $b['app_passwords']['other']['users_checked'] );
		$this->assertSame( 200, count( array_unique( $this->tool->reads ) ) );
	}

	public function test_a_total_that_lags_the_pages_is_reconciled_so_the_invariant_holds(): void {
		// Parser invariant: truncated:false ⇒ users_checked == users_total, and
		// users_checked <= users_total. A count query that raced a user
		// creation must not produce a payload the parser refuses.
		$this->tool->context_pages = array( array( 1, 2, 3, 4 ) );
		$this->tool->context_total = 3;
		$c = $this->block()['coverage'];
		$this->assertSame( 4, $c['users_total'] );
		$this->assertSame( 4, $c['users_checked'] );
		$this->assertFalse( $c['truncated'] );
	}

	public function test_a_total_above_the_pages_is_reported_as_truncated(): void {
		// The pages ran dry before the count said they should: incomplete, honestly.
		$this->tool->context_pages = array( array( 1, 2 ) );
		$this->tool->context_total = 5;
		$c = $this->block()['coverage'];
		$this->assertSame( array( 'users_total' => 5, 'users_checked' => 2, 'truncated' => true, 'cap' => 200 ), $c );
	}

	public function test_a_duplicate_id_across_pages_is_checked_once(): void {
		// Pages must be FULL for the next one to be fetched (a short page ends
		// the scan), so the duplicate sits at the seam of two 50-id pages.
		$this->tool->context_pages = array( range( 1, 50 ), array( 50, 51 ) );
		$this->tool->context_total = 51;
		$this->assertSame( 51, $this->block()['coverage']['users_checked'] );
	}

	public function test_a_throw_in_the_context_scan_replaces_other_and_coverage_together(): void {
		$this->tool->throw_in      = array( 'context' );
		$this->tool->candidates    = array( 1 );
		$this->tool->lists         = array( 1 => array( self::pw( array( 'last_used' => 5 ) ) ) );
		$b = $this->block();
		$this->assertSame( array( 'error' => 'context exploded' ), $b['app_passwords']['other'] );
		$this->assertSame( array( 'error' => 'context exploded' ), $b['coverage'] );
		$this->assertCount( 1, $b['app_passwords']['elementor'] ); // the used credential survived
	}

	public function test_a_throw_counting_users_is_a_context_error_too(): void {
		$this->tool->throw_in = array( 'total' );
		$this->assertSame( array( 'error' => 'total exploded' ), $this->block()['coverage'] );
	}

	public function test_the_context_query_asks_for_edit_posts_ids_in_order(): void {
		// The REAL seams against the bootstrap's WP_User_Query stub, which
		// records every query's vars.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function password_list( $uid ) {
				return array();
			}
		};
		$GLOBALS['_users']        = array( 3, 4 );
		$GLOBALS['_users_total']  = 2;
		$GLOBALS['_user_queries'] = array();
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'users_total' => 2, 'users_checked' => 2, 'truncated' => false, 'cap' => 200 ), $b['coverage'] );
		$vars = $GLOBALS['_user_queries'];
		$this->assertSame( array( 'capability' => 'edit_posts', 'fields' => 'ID', 'number' => 1, 'count_total' => true ), $vars[0] );
		$this->assertSame( array( 'capability' => 'edit_posts', 'fields' => 'ID', 'number' => 50, 'offset' => 0, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => false ), $vars[1] );
	}

	public function test_a_failed_user_count_query_is_a_context_error_not_an_empty_inventory(): void {
		// Codex round-3 P2: WP_User_Query wraps a wpdb statement that can fail
		// the same way the direct SQL scans (consent, candidates) can —
		// WordPress answers an empty result / zero total on a database
		// failure, never a throw. The REAL seam against the bootstrap's
		// WP_User_Query stub, via its _sa_user_query_error knob.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
		};
		$GLOBALS['_sa_user_query_error'] = 'Table wp_users doesnt exist';
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['coverage'] );
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['app_passwords']['other'] );
		// mcp_module and consent, read independently, are untouched.
		$this->assertFalse( $b['installed'] );
		$this->assertArrayNotHasKey( 'error', $b['mcp_module'] );
		$this->assertSame( array(), $b['consent'] );
	}

	public function test_a_count_query_failure_cleared_by_found_rows_is_still_caught(): void {
		// Codex round-7 P2: count_total => true runs the main SELECT and then
		// SELECT FOUND_ROWS() via $wpdb->get_var(), which flushes
		// $wpdb->last_error — so a failed main query followed by a successful
		// FOUND_ROWS leaves last_error empty by the time get_total() returns,
		// and the round-3 fix above (which reads last_error only AFTER
		// get_total()) would read 0 with no error. The bootstrap's
		// _sa_user_query_error_cleared_by_found_rows knob models exactly that
		// clearing; context_users_total()'s found_users_query filter is what
		// still sees the error, since it fires between the two statements.
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
		};
		$GLOBALS['_sa_user_query_error'] = 'Table wp_users doesnt exist';
		$GLOBALS['_sa_user_query_error_cleared_by_found_rows'] = true;
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['coverage'] );
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['app_passwords']['other'] );
		$this->assertFalse( $b['installed'] );
		$this->assertArrayNotHasKey( 'error', $b['mcp_module'] );
		$this->assertSame( array(), $b['consent'] );
	}

	public function test_a_failed_paging_query_is_also_a_context_error(): void {
		// Isolates context_user_ids()'s own failure path (context_users_total()
		// is overridden to succeed, so the scan reaches the paging query).
		$real = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_users_total() {
				return 5;
			}
		};
		$GLOBALS['_sa_user_query_error'] = 'Table wp_users doesnt exist';
		$b = $real->execute( array() )['elementor'];
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['coverage'] );
		$this->assertSame( array( 'error' => 'user query failed: Table wp_users doesnt exist' ), $b['app_passwords']['other'] );
	}

	public function test_a_page_that_never_advances_terminates_and_is_bounded(): void {
		// A context_user_ids() that ignores $offset (a pre_user_query filter
		// dropping it) returns the SAME full page every time. Before the fix,
		// count($ids) === PAGE kept `if ( count( $ids ) < PAGE ) break;` from
		// firing while $checked never advanced, so the while() never exited
		// on its own.
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public $calls = 0;
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				++$this->calls;
				return range( 1, 50 );
			}
			protected function context_users_total() {
				return 120;
			}
			protected function password_list( $uid ) {
				return array();
			}
		};
		$b = $seam->execute( array() )['elementor'];
		$this->assertSame( 50, $b['coverage']['users_checked'] );
		$this->assertTrue( $b['coverage']['truncated'] );
		$this->assertLessThanOrEqual( 6, $seam->calls );
	}

	public function test_a_mid_page_cap_reads_every_id_exactly_once(): void {
		// The fixture the ledger deferred: page 4 overlaps page 3 by 25 ids,
		// so the cap (200) is reached partway through it. Nothing here may be
		// read twice, and the scan must still terminate cleanly.
		$seam = new class() extends Aura_Tool_Audit_Mcp_Exposure {
			public $pages = array();
			public $reads = array();
			protected function elementor_env() {
				return array( 'installed' => false, 'version' => null, 'class_present' => false, 'active' => null );
			}
			protected function consent_rows() {
				return array();
			}
			protected function elementor_candidate_ids() {
				return array();
			}
			protected function context_user_ids( $offset, $number ) {
				$page = (int) ( $offset / $number );
				return isset( $this->pages[ $page ] ) ? $this->pages[ $page ] : array();
			}
			protected function context_users_total() {
				return 250;
			}
			protected function password_list( $uid ) {
				$this->reads[] = (int) $uid;
				return array();
			}
		};
		$seam->pages = array(
			range( 1, 50 ),
			range( 51, 100 ),
			range( 101, 150 ),
			range( 126, 175 ),
			range( 176, 250 ),
		);
		$b = $seam->execute( array() )['elementor'];
		$this->assertSame( 200, $b['coverage']['users_checked'] );
		$this->assertTrue( $b['coverage']['truncated'] );
		$this->assertSame( count( $seam->reads ), count( array_unique( $seam->reads ) ) );
	}
}
