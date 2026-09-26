<?php
/**
 * audit_agent_code's `installs` subtree (spec §4.3) and uninstall of the
 * ledger's network rows (Ruling R5).
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class AgentCodeAuditInstallsTest extends TestCase {

	protected function setUp(): void {
		sa_reset_state();
		Aura_Worker_Install_Ledger::_set_probe_for_tests( array( 'now' => 1790424000 ) );
	}

	private function run_tool(): array {
		return ( new Aura_Tool_Audit_Agent_Code() )->execute( array() );
	}

	public function test_installs_is_the_ledger_report_verbatim(): void {
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::append( array( 'when' => gmdate( 'c', 1790424000 ), 'type' => 'plugin', 'action' => 'install', 'slug' => 'foo', 'version' => null, 'transport' => 'rest', 'user_id' => 1, 'auth' => 'cookie', 'app_password_name' => null, 'route' => null, 'source' => array( 'kind' => 'wporg' ) ) );
		$out = $this->run_tool();
		$this->assertSame( Aura_Worker_Install_Ledger::report(), $out['installs'] );
		$this->assertSame( 1, $out['installs']['total'] );
	}

	public function test_an_unreadable_ledger_stays_in_its_subtree(): void {
		$GLOBALS['_options'][ Aura_Worker_Install_Ledger::OPTION ] = 'garbage';
		$out = $this->run_tool();
		$this->assertSame( array( 'error' => 'ledger_unreadable' ), $out['installs'] );
		$this->assertArrayHasKey( 'installed', $out['angie_snippets'] ); // siblings unaffected
		$this->assertArrayHasKey( 'counters_as_of', $out );
	}

	public function test_the_returns_contract_documents_installs(): void {
		$returns = ( new Aura_Tool_Audit_Agent_Code() )->get_returns();
		$this->assertArrayHasKey( 'installs', $returns );
		$this->assertStringContainsString( 'since', $returns['installs'] );
	}

	public function test_uninstall_removes_the_network_rows_on_multisite(): void {
		$GLOBALS['_is_multisite'] = true;
		Aura_Worker_Install_Ledger::ensure_started();
		Aura_Worker_Install_Ledger::append( array( 'when' => gmdate( 'c', 1790424000 ), 'type' => 'plugin', 'action' => 'install', 'slug' => 'foo', 'version' => null, 'transport' => 'rest', 'user_id' => 1, 'auth' => 'cookie', 'app_password_name' => null, 'route' => null, 'source' => array( 'kind' => 'wporg' ) ) );
		$GLOBALS['_site_options']['unrelated_network_option'] = 1;
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'digitizer-site-worker/digitizer-site-worker.php' );
		}
		include SA_PLUGIN_DIR . '/uninstall.php';
		$this->assertArrayNotHasKey( Aura_Worker_Install_Ledger::OPTION, $GLOBALS['_site_options'] );
		$this->assertArrayNotHasKey( Aura_Worker_Install_Ledger::STATE_OPTION, $GLOBALS['_site_options'] );
		$this->assertSame( 1, $GLOBALS['_site_options']['unrelated_network_option'] );
	}
}
