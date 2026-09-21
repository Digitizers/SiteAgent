<?php
/**
 * Tests for audit_agent_code — executable code an agent authored or can
 * author, per site (P4.6 piece 1, SiteAgent side). Spec: Digitizers/Aura
 * docs/superpowers/specs/2026-09-09-agent-code-detection-design.md §3.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

require_once SA_PLUGIN_DIR . '/includes/tools/class-tool-audit-agent-code.php';

final class AgentCodeAuditTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0755, true );
		$this->root = WP_CONTENT_DIR . '/angie-snippets';
	}

	protected function tearDown(): void {
		$this->rrmdir( WP_CONTENT_DIR );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$path = $dir . '/' . $item;
			// A link is removed, never descended into — otherwise a link to a
			// directory would delete the target's contents and survive itself,
			// leaking a sandbox root symlink into the next test.
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				@unlink( $path );
				continue;
			}
			$this->rrmdir( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * An Angie site: installed, module on, dev mode off unless a test says
	 * otherwise. A no-arg anonymous subclass, matching every other tool
	 * test's pattern in this suite (McpExposureAuditTest, SecurityAuditToolsTest,
	 * …): Aura_Worker_Tools::__construct() blindly instantiates every declared
	 * Aura_Tool_Base subclass with `new $class()` (includes/class-aura-worker-tools.php:47),
	 * and PHPUnit runs this suite in one process — an anonymous class declared
	 * by any earlier test stays declared for every later test. A REQUIRED
	 * constructor arg here would make that later `new $class()` throw
	 * ArgumentCountError in unrelated tests (AuditRulesTest and others), so
	 * $over is set as a public property after construction instead.
	 */
	private function tool( array $over = array() ): Aura_Tool_Audit_Agent_Code {
		$GLOBALS['_post_types']['angie_snippet'] = true;
		$tool = new class() extends Aura_Tool_Audit_Agent_Code {
			public $over = array();
			protected function angie_installed() { return $this->over['installed'] ?? true; }
			protected function angie_version() { return $this->over['version'] ?? '1.1.16'; }
			protected function dev_mode_enabled() { return array_key_exists( 'dev', $this->over ) ? $this->over['dev'] : false; }
			protected function module_active() { return array_key_exists( 'module', $this->over ) ? $this->over['module'] : parent::module_active(); }
			protected function env_dirs() { return array_key_exists( 'envs', $this->over ) ? $this->over['envs'] : parent::env_dirs(); }
			protected function snippet_rows() {
				if ( array_key_exists( 'rows', $this->over ) ) {
					return is_callable( $this->over['rows'] ) ? ( $this->over['rows'] )() : $this->over['rows'];
				}
				return parent::snippet_rows();
			}
			protected function open_dir( $path ) {
				if ( isset( $this->over['throw_on'] ) && $this->over['throw_on'] === $path ) {
					throw new RuntimeException( 'opendir exploded' );
				}
				if ( isset( $this->over['unreadable'] ) && $this->over['unreadable'] === $path ) {
					return false;
				}
				return parent::open_dir( $path );
			}
			protected function power_pack_env() { return $this->over['pp'] ?? parent::power_pack_env(); }
			protected function create_publish() { return array_key_exists( 'publish', $this->over ) ? $this->over['publish'] : parent::create_publish(); }
			protected function third_party_env() { return $this->over['tp'] ?? parent::third_party_env(); }
			protected function emcp_store_cap() { return $this->over['store_cap'] ?? parent::emcp_store_cap(); }
		};
		$tool->over = $over;
		return $tool;
	}

	private function row( int $id, string $status, bool $artifact, string $modified = '2026-09-01 10:00:00', string $title = 'Snippet' ): void {
		$GLOBALS['_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'angie_snippet',
			'post_status'       => $status,
			'post_title'        => $title,
			'post_modified_gmt' => $modified,
		);
		if ( $artifact ) {
			$GLOBALS['_post_meta'][ $id ]['_angie_snippet_artifact_id'] = 'art-' . $id;
		}
	}

	private function dir( string $env, string $name, bool $main = true, int $mtime = 0 ): void {
		$d = $this->root . '/' . $env . '/' . $name;
		mkdir( $d, 0755, true );
		file_put_contents( $d . '/index.php', '<?php // Silence is golden.' );
		if ( $main ) {
			file_put_contents( $d . '/main.php', '<?php // snippet' );
			if ( $mtime > 0 ) {
				touch( $d . '/main.php', $mtime );
			}
		}
	}

	// --- contract -------------------------------------------------------------

	public function test_the_tool_is_read_only_and_takes_no_parameters(): void {
		$t = new Aura_Tool_Audit_Agent_Code();
		$a = $t->get_annotations();
		$this->assertSame( 'audit_agent_code', $t->get_name() );
		$this->assertSame( array(), $t->get_parameters() );
		$this->assertTrue( $a['read_only'] );
		$this->assertFalse( $a['destructive'] );
		$this->assertFalse( $a['requires_approval'] );
		$this->assertFalse( $a['supports_preview'] );
	}

	public function test_a_deactivated_angie_on_disk_is_installed_with_its_dormant_rows_and_directories(): void {
		// Codex #94 round-6 P2: a deactivated Angie loads no constant and no
		// class, but its CPT rows and deployed snippet directories are still
		// there — dormant code, the thing this audit counts. installed comes
		// from the inventory; module_active (runtime) stays false; active_env
		// is null because no loader runs.
		$this->row( 1, 'publish', true, '2026-09-01 10:00:00', 'Agent one' );
		$this->dir( 'prod', 'snippet-1' );
		$t = new class extends Aura_Tool_Audit_Agent_Code {
			protected function angie_header() {
				return array( 'Name' => 'Angie', 'Version' => '1.1.16' );
			}
		};

		$a = $t->execute( array() )['angie_snippets'];

		$this->assertTrue( $a['installed'] );
		$this->assertSame( '1.1.16', $a['version'], 'the inventory header supplies the version' );
		$this->assertFalse( $a['module_active'] );
		$this->assertNull( $a['active_env'] );
		$this->assertSame( 1, $a['total'] );
		$this->assertSame( 1, $a['agent_authored'] );
		$this->assertSame( array( 'dirs' => 1, 'agent_authored' => 1, 'orphan' => 0 ), $a['deployed']['prod'] );
	}

	public function test_angie_absent_from_runtime_and_inventory_is_installed_false(): void {
		$GLOBALS['_installed_plugins'] = array( 'akismet/akismet.php' => array( 'Name' => 'Akismet', 'Version' => '1.0' ) );
		$t = new Aura_Tool_Audit_Agent_Code();
		$this->assertSame( array( 'installed' => false, 'version' => '' ), $t->execute( array() )['angie_snippets'] );
	}

	public function test_angie_absent_is_installed_false_and_nothing_else_in_that_subtree(): void {
		$r = $this->tool( array( 'installed' => false ) )->execute( array() );
		$this->assertSame( array( 'installed' => false, 'version' => '' ), $r['angie_snippets'] );
		$this->assertArrayHasKey( 'power_pack', $r );
		$this->assertArrayHasKey( 'third_party', $r );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $r['counters_as_of'] );
	}

	// --- the spec §3 fixture ---------------------------------------------------

	/**
	 * Three rows (one agent-authored), fake prod/dev directories: one without
	 * main.php (not counted), one snippet-<n> with no CPT row (orphan), the
	 * agent-authored row deployed only in dev.
	 */
	private function fixture(): void {
		$this->row( 1, 'publish', false, '2026-09-01 10:00:00', 'Human one' );
		$this->row( 2, 'draft', false, '2026-09-02 10:00:00', 'Human draft' );
		$this->row( 3, 'publish', true, '2026-09-03 10:00:00', 'Agent one' );
		$this->dir( 'prod', 'snippet-1', true, 1_756_800_000 );  // 2025-09-02T08:00:00Z
		$this->dir( 'prod', 'snippet-2', false );                // no main.php → not counted
		$this->dir( 'prod', 'snippet-9', true, 1_756_900_000 );  // orphan (no row)
		$this->dir( 'prod', 'README', true );                    // not snippet-<n> → seen, ignored
		$this->dir( 'dev', 'snippet-3', true, 1_757_000_000 );   // agent-authored, dev only
	}

	public function test_the_fixture_correlates_per_environment_by_directory_name(): void {
		$this->fixture();

		$a = $this->tool()->execute( array() )['angie_snippets'];

		$this->assertTrue( $a['installed'] );
		$this->assertSame( '1.1.16', $a['version'] );
		$this->assertTrue( $a['module_active'] );
		$this->assertSame( 3, $a['total'] );
		$this->assertSame( 2, $a['published'] );
		$this->assertSame( 1, $a['drafts'] );
		$this->assertSame( 1, $a['agent_authored'] );
		$this->assertSame( 'prod', $a['active_env'] );
		$this->assertSame( array( 'dirs' => 2, 'agent_authored' => 0, 'orphan' => 1 ), $a['deployed']['prod'] );
		$this->assertSame( array( 'dirs' => 1, 'agent_authored' => 1, 'orphan' => 0 ), $a['deployed']['dev'] );
		$this->assertSame( '2025-09-03T11:46:40+00:00', $a['latest_deploy_at']['prod'], 'the newest main.php in prod, never a max across both' );
		$this->assertSame( '2025-09-04T15:33:20+00:00', $a['latest_deploy_at']['dev'] );
		$this->assertSame( array( 'total_seen' => 5, 'returned' => 3, 'truncated' => false, 'cap' => 200 ), $a['coverage'] );
		$this->assertFalse( $a['recent_truncated'] );
		$this->assertSame( array( 3, 2, 1 ), array_column( $a['recent'], 'id' ), 'newest first' );
		$this->assertSame( array( 'dev' ), $a['recent'][0]['environments'] );
		$this->assertTrue( $a['recent'][0]['agent_authored'] );
		$this->assertSame( 'publish', $a['recent'][0]['status'] );
		$this->assertSame( array( 'prod' ), $a['recent'][2]['environments'] );
		$this->assertSame( array(), $a['recent'][1]['environments'], 'a draft with no directory is recorded, not deployed' );
	}

	public function test_dev_mode_on_reports_dev_as_the_active_environment(): void {
		$this->fixture();
		$this->assertSame( 'dev', $this->tool( array( 'dev' => true ) )->execute( array() )['angie_snippets']['active_env'] );
	}

	public function test_dev_mode_api_missing_is_null_never_a_guess(): void {
		$this->fixture();
		$this->assertNull( $this->tool( array( 'dev' => null ) )->execute( array() )['angie_snippets']['active_env'] );
	}

	public function test_module_inactive_still_counts_rows_and_directories(): void {
		$this->fixture();

		$a = $this->tool( array( 'module' => false ) )->execute( array() )['angie_snippets'];

		$this->assertFalse( $a['module_active'] );
		$this->assertSame( 3, $a['total'] );
		$this->assertSame( 2, $a['deployed']['prod']['dirs'] );
		$this->assertNull( $a['active_env'], 'no loader runs with the module off — a callable dev-mode API must not make dormant code look live' );
	}

	public function test_no_snippet_directories_at_all_is_zero_not_null(): void {
		$this->row( 1, 'draft', true );
		$a = $this->tool()->execute( array() )['angie_snippets'];
		$this->assertSame( array( 'dirs' => 0, 'agent_authored' => 0, 'orphan' => 0 ), $a['deployed']['prod'] );
		$this->assertNull( $a['latest_deploy_at']['prod'] );
		$this->assertSame( 0, $a['coverage']['total_seen'] );
	}

	public function test_an_unreadable_environment_directory_is_null_not_zero_and_the_other_still_answers(): void {
		$this->fixture();
		$a = $this->tool( array( 'unreadable' => $this->root . '/prod' ) )->execute( array() )['angie_snippets'];

		$this->assertSame( array( 'dirs' => null, 'agent_authored' => null, 'orphan' => null ), $a['deployed']['prod'] );
		$this->assertNull( $a['latest_deploy_at']['prod'] );
		$this->assertSame( array( 'dirs' => 1, 'agent_authored' => 1, 'orphan' => 0 ), $a['deployed']['dev'] );
		$this->assertTrue( $a['coverage']['truncated'], 'an environment that could not be walked is incomplete coverage' );
	}

	public function test_the_directory_cap_sets_coverage_truncated(): void {
		for ( $i = 1; $i <= 201; $i++ ) {
			$this->dir( 'prod', 'snippet-' . $i );
		}
		$a = $this->tool()->execute( array() )['angie_snippets'];

		$this->assertTrue( $a['coverage']['truncated'] );
		$this->assertSame( 200, $a['coverage']['cap'] );
		$this->assertSame( 200, $a['coverage']['total_seen'] );
		$this->assertSame( 200, $a['deployed']['prod']['dirs'] );
		$this->assertSame( 200, $a['deployed']['prod']['orphan'] );
		$this->assertFalse( $a['recent_truncated'] );
	}

	public function test_recent_is_capped_at_twenty_and_never_touches_coverage(): void {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->row( $i, 'draft', false, sprintf( '2026-09-%02d 10:00:00', $i % 28 + 1 ) );
		}
		$a = $this->tool()->execute( array() )['angie_snippets'];

		$this->assertCount( 20, $a['recent'] );
		$this->assertTrue( $a['recent_truncated'] );
		$this->assertFalse( $a['coverage']['truncated'] );
		$this->assertSame( 21, $a['total'], 'recent never bounds the Angie count' );
	}

	public function test_a_cpt_read_that_hits_its_cap_answers_null_counts_and_still_walks_directories(): void {
		$this->dir( 'prod', 'snippet-1' );
		$rows = array();
		for ( $i = 1; $i <= 501; $i++ ) {
			$rows[] = (object) array( 'ID' => $i, 'post_type' => 'angie_snippet', 'post_status' => 'draft', 'post_title' => 't', 'post_modified_gmt' => '2026-09-01 00:00:00' );
		}
		$a = $this->tool( array( 'rows' => $rows ) )->execute( array() )['angie_snippets'];

		$this->assertNull( $a['total'] );
		$this->assertNull( $a['published'] );
		$this->assertNull( $a['drafts'] );
		$this->assertNull( $a['agent_authored'] );
		$this->assertSame( 1, $a['deployed']['prod']['dirs'], 'the walk is independent of the CPT read' );
		$this->assertNull( $a['deployed']['prod']['orphan'], 'the join needs the rows; without them the split is unknown' );
		$this->assertNull( $a['deployed']['prod']['agent_authored'] );
		$this->assertTrue( $a['recent_truncated'] );
		$this->assertCount( 20, $a['recent'] );
	}

	public function test_a_failed_cpt_query_answers_null_counts_never_an_empty_site(): void {
		// Codex #91 round-2 P2: get_posts() answers [] on a database error, which
		// read as "no snippets, every directory an orphan". snippet_rows()
		// reads $wpdb->last_error and answers null; the tool reports null for
		// every CPT-derived figure and still walks the directories.
		$this->dir( 'prod', 'snippet-1' );
		$a = $this->tool( array( 'rows' => null ) )->execute( array() )['angie_snippets'];

		$this->assertNull( $a['total'] );
		$this->assertNull( $a['published'] );
		$this->assertNull( $a['drafts'] );
		$this->assertNull( $a['agent_authored'] );
		$this->assertSame( 1, $a['deployed']['prod']['dirs'] );
		$this->assertNull( $a['deployed']['prod']['orphan'], 'not an orphan — unknown' );
		$this->assertNull( $a['deployed']['prod']['agent_authored'] );
		$this->assertSame( array(), $a['recent'] );
		$this->assertFalse( $a['recent_truncated'], 'nothing was cut; nothing was read' );
		$this->assertFalse( $a['coverage']['truncated'], 'coverage is about the walk, which completed' );
	}

	public function test_snippet_rows_reads_wpdb_last_error_after_the_query(): void {
		// The real seam, not the override: a stub $wpdb that records an error
		// during get_posts() must turn the (empty) result into null.
		$GLOBALS['_sa_get_posts_effect'] = static function () {
			$GLOBALS['wpdb']->last_error = 'Table wp_posts is marked as crashed';
		};
		try {
			$a = $this->tool()->execute( array() )['angie_snippets'];
		} finally {
			unset( $GLOBALS['_sa_get_posts_effect'] );
		}
		$this->assertNull( $a['total'] );
	}

	public function test_a_renamed_environment_constant_degrades_to_null_with_truncated(): void {
		$this->fixture();
		$a = $this->tool( array( 'envs' => null ) )->execute( array() )['angie_snippets'];

		$this->assertSame( array( 'dirs' => null, 'agent_authored' => null, 'orphan' => null ), $a['deployed']['prod'] );
		$this->assertSame( array( 'dirs' => null, 'agent_authored' => null, 'orphan' => null ), $a['deployed']['dev'] );
		$this->assertTrue( $a['coverage']['truncated'] );
		$this->assertSame( 3, $a['total'], 'the CPT read does not depend on the directory names' );
	}

	public function test_a_throwing_subtree_answers_error_while_its_siblings_answer(): void {
		$this->fixture();
		$t = $this->tool( array( 'rows' => function () { throw new RuntimeException( 'wpdb exploded' ); } ) );

		$r = $t->execute( array() );

		$this->assertSame( array( 'error' => 'wpdb exploded' ), $r['angie_snippets'] );
		$this->assertIsArray( $r['power_pack'] );
		$this->assertFalse( $r['power_pack']['installed'] );
		$this->assertIsArray( $r['third_party'] );
	}

	public function test_titles_are_clipped_multibyte_safe(): void {
		$this->row( 1, 'draft', false, '2026-09-01 10:00:00', str_repeat( 'é', 300 ) );
		$a = $this->tool()->execute( array() )['angie_snippets'];
		$this->assertSame( 200, mb_strlen( $a['recent'][0]['title'] ) );
	}

	// --- power_pack / third_party ---------------------------------------------

	public function test_power_pack_absent_is_installed_false_with_every_flag_false(): void {
		$this->assertSame(
			array( 'installed' => false, 'version' => '', 'execute_php' => false, 'fs_write' => false, 'wp_cli' => false ),
			$this->tool()->execute( array() )['power_pack']
		);
	}

	/** @dataProvider flagProvider */
	public function test_each_power_pack_flag_is_reported_as_set( string $flag ): void {
		$env = array( 'version' => '0.2.3', 'execute_php' => false, 'fs_write' => false, 'wp_cli' => false );
		$env[ $flag ] = true;
		$pp = $this->tool( array( 'pp' => $env ) )->execute( array() )['power_pack'];

		$this->assertTrue( $pp['installed'] );
		$this->assertSame( '0.2.3', $pp['version'] );
		foreach ( array( 'execute_php', 'fs_write', 'wp_cli' ) as $f ) {
			$this->assertSame( $f === $flag, $pp[ $f ], $f );
		}
	}

	public function test_power_pack_reports_how_a_create_publishes_on_this_host(): void {
		// SiteAgent#96: link() is disabled on Cloudways web PHP, so the fleet
		// needs to know whether a write_file create lands by link, by the
		// placeholder+rename fallback, or not at all.
		$env = array( 'version' => '0.2.4', 'execute_php' => false, 'fs_write' => true, 'wp_cli' => false );
		$this->assertSame( 'link', $this->tool( array( 'pp' => $env, 'publish' => 'link' ) )->execute( array() )['power_pack']['create_publish'] );
		$this->assertSame( 'rename', $this->tool( array( 'pp' => $env, 'publish' => 'rename' ) )->execute( array() )['power_pack']['create_publish'] );
		$this->assertNull( $this->tool( array( 'pp' => $env, 'publish' => null ) )->execute( array() )['power_pack']['create_publish'] );
		$this->assertSame( Aura_Worker_Snapshots::publish_mode(), $this->tool( array( 'pp' => $env ) )->execute( array() )['power_pack']['create_publish'], 'unseamed: the engine answers' );
		$this->assertArrayNotHasKey( 'create_publish', $this->tool()->execute( array() )['power_pack'], 'absent Power Pack keeps its fixed shape' );
	}

	public function flagProvider(): array {
		return array( array( 'execute_php' ), array( 'fs_write' ), array( 'wp_cli' ) );
	}

	public function test_third_party_reports_emcp_and_atarim_presence(): void {
		$tp = $this->tool( array( 'tp' => array( 'emcp_version' => '3.14.1', 'emcp_dir' => false, 'atarim' => true ) ) )->execute( array() )['third_party'];
		$this->assertSame( array( 'present' => true, 'version' => '3.14.1', 'active' => true, 'store' => null ), $tp['emcp_sandbox'] );
		$this->assertSame( array( 'present' => true ), $tp['atarim_exec'] );

		$tp = $this->tool()->execute( array() )['third_party'];
		$this->assertSame( array( 'present' => false, 'version' => '', 'active' => false, 'store' => null ), $tp['emcp_sandbox'] );
		$this->assertSame( array( 'present' => false ), $tp['atarim_exec'] );
	}

	// --- third_party.emcp_sandbox.store (P6.3 phase 1) --------------------------

	private function sandbox( array $files ): string {
		$root = WP_CONTENT_DIR . '/emcp-sandbox';
		mkdir( $root, 0755, true );
		foreach ( $files as $rel => $mtime ) {
			$path = $root . '/' . $rel;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0755, true );
			}
			file_put_contents( $path, 'x' );
			touch( $path, $mtime );
		}
		return $root;
	}

	private function emcp( array $over = array() ): array {
		return $this->tool( $over )->execute( array() )['third_party']['emcp_sandbox'];
	}

	public function test_the_directory_alone_proves_presence_and_the_plugin_is_not_active(): void {
		$this->sandbox( array() );
		$e = $this->emcp();
		$this->assertTrue( $e['present'] );
		$this->assertFalse( $e['active'], 'EMCP_TOOLS_VERSION is not defined — files left behind, plugin not loaded' );
		$this->assertSame( array( 'files' => 0, 'executable_files' => 0, 'newest_mtime' => null, 'truncated' => false, 'unreadable_dirs' => 0 ), $e['store'] );
	}

	public function test_non_executable_files_are_counted_as_files_only(): void {
		$this->sandbox( array( 'blocks/a/block.json' => 1757400000, 'widgets/w.css' => 1757400100 ) );
		$s = $this->emcp()['store'];
		$this->assertSame( 2, $s['files'] );
		$this->assertSame( 0, $s['executable_files'] );
		$this->assertSame( gmdate( 'c', 1757400100 ), $s['newest_mtime'] );
	}

	public function test_executable_files_are_counted_at_any_depth_case_insensitively(): void {
		$this->sandbox( array( 'snippets/s1/main.php' => 1757400000, 'widgets/deep/er/W.PHTML' => 1757400200, 'tool.phar' => 1757400050, 'readme.txt' => 1757400010 ) );
		$s = $this->emcp()['store'];
		$this->assertSame( 4, $s['files'] );
		$this->assertSame( 3, $s['executable_files'] );
		$this->assertSame( gmdate( 'c', 1757400200 ), $s['newest_mtime'] );
		$this->assertFalse( $s['truncated'] );
	}

	public function test_htaccess_is_not_an_executable_here(): void {
		$this->sandbox( array( '.htaccess' => 1757400000 ) );
		$s = $this->emcp()['store'];
		$this->assertSame( 1, $s['files'] );
		$this->assertSame( 0, $s['executable_files'] );
	}

	public function test_the_cap_marks_the_counts_as_lower_bounds(): void {
		$this->sandbox( array( 'a.php' => 1757400000, 'b.php' => 1757400000, 'c.php' => 1757400000, 'd.php' => 1757400000 ) );
		$s = $this->emcp( array( 'store_cap' => 3 ) )['store'];
		$this->assertTrue( $s['truncated'] );
		$this->assertSame( 3, $s['files'] );
		$this->assertSame( 3, $s['executable_files'] );
	}

	public function test_the_cap_bounds_directories_too_not_only_files(): void {
		$this->sandbox( array( 'd1/x.txt' => 1757400000, 'd2/y.txt' => 1757400000, 'd3/z.php' => 1757400000 ) );
		$s = $this->emcp( array( 'store_cap' => 2 ) )['store'];
		$this->assertTrue( $s['truncated'], 'two entries visited, a third pending — directories count toward the cap' );
		$this->assertSame( 0, $s['files'], 'the three root entries are all directories — the cap is reached before any descent' );
	}

	public function test_an_unreadable_root_is_an_error_never_a_zero(): void {
		$root = $this->sandbox( array( 'a.php' => 1757400000 ) );
		$e    = $this->emcp( array( 'unreadable' => $root ) );
		$this->assertTrue( $e['present'] );
		$this->assertSame( array( 'error' => 'sandbox_unreadable' ), $e['store'] );
	}

	public function test_an_unreadable_subdirectory_is_counted_and_the_rest_still_answers(): void {
		$root = $this->sandbox( array( 'ok/a.php' => 1757400000, 'locked/b.php' => 1757400000 ) );
		$s    = $this->emcp( array( 'unreadable' => $root . '/locked' ) )['store'];
		$this->assertSame( 1, $s['unreadable_dirs'] );
		$this->assertSame( 1, $s['executable_files'] );
	}

	public function test_a_symlink_is_counted_and_never_followed(): void {
		$root    = $this->sandbox( array( 'a.txt' => 1757400000 ) );
		$outside = WP_CONTENT_DIR . '/outside';
		mkdir( $outside, 0755, true );
		file_put_contents( $outside . '/evil.php', 'x' );
		if ( ! @symlink( $outside, $root . '/link' ) ) {
			$this->markTestSkipped( 'symlinks unavailable on this filesystem' );
		}
		$s = $this->emcp()['store'];
		$this->assertSame( 2, $s['files'], 'the link itself is one entry' );
		$this->assertSame( 0, $s['executable_files'], 'evil.php behind the link is never seen' );
	}

	public function test_a_php_named_symlink_is_counted_executable_by_its_own_name_and_never_stated(): void {
		$root    = $this->sandbox( array( 'older.txt' => 1757400000 ) );
		$outside = WP_CONTENT_DIR . '/outside2';
		mkdir( $outside, 0755, true );
		$target = $outside . '/payload.php';
		file_put_contents( $target, 'x' );
		touch( $target, 2147483647 ); // far-future mtime on the TARGET — must never surface
		if ( ! @symlink( $target, $root . '/shell.php' ) ) {
			$this->markTestSkipped( 'symlinks unavailable on this filesystem' );
		}
		$s = $this->emcp()['store'];
		$this->assertSame( 2, $s['files'], 'the link itself is one entry, plus older.txt' );
		$this->assertSame( 1, $s['executable_files'], 'a symlink named shell.php is judged by its own name — pathinfo() is pure string work' );
		$this->assertSame( gmdate( 'c', 1757400000 ), $s['newest_mtime'], 'the link is never followed and never stat\'ed — the target\'s far-future mtime must not surface' );
	}

	public function test_a_sandbox_root_that_is_itself_a_link_is_not_walked(): void {
		// A link INSIDE the store is one entry and never followed; the root
		// itself deserves the same rule, or one symlink turns the whole
		// filesystem into "EMCP's sandbox store".
		$outside = WP_CONTENT_DIR . '/outside-root';
		mkdir( $outside, 0755, true );
		file_put_contents( $outside . '/evil.php', 'x' );
		if ( ! @symlink( $outside, WP_CONTENT_DIR . '/emcp-sandbox' ) ) {
			$this->markTestSkipped( 'symlinks unavailable on this filesystem' );
		}

		$e = $this->emcp();

		$this->assertTrue( $e['present'], 'a linked root is still a store on this site' );
		$this->assertSame( array( 'error' => 'sandbox_is_link' ), $e['store'] );
	}

	public function test_a_throwing_walk_stays_inside_the_store_and_its_siblings_still_answer(): void {
		$root = $this->sandbox( array( 'a.php' => 1757400000 ) );

		$tp = $this->tool( array( 'throw_on' => $root ) )->execute( array() )['third_party'];

		$this->assertSame( array( 'error' => 'sandbox_walk_failed' ), $tp['emcp_sandbox']['store'] );
		$this->assertTrue( $tp['emcp_sandbox']['present'], 'the walk failed; the directory is still there' );
		$this->assertSame( array( 'present' => false ), $tp['atarim_exec'], 'a throwing walk must not take the sibling subtree down with it' );
	}

	public function test_the_returns_declaration_names_the_new_keys(): void {
		$returns = $this->tool()->get_returns();
		$this->assertStringContainsString( '{ present, version, active, store }', $returns['third_party'] );
	}
}
