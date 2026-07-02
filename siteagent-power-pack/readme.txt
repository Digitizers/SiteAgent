=== SiteAgent Power Pack ===
Contributors: benkalsky
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Governed power tools for SiteAgent — approval-gated, snapshot-first filesystem
and database operations exposed to the Aura Fleet Gateway.

== Description ==

Companion to the **SiteAgent (digitizer-site-worker)** base plugin. It adds
higher-capability MCP tools that are deliberately kept OUT of the wordpress.org
build: everything here is either read-only or requires human approval through
the Aura Fleet Gateway before it runs, and state-changing operations snapshot
first so they can be reversed.

This plugin is **not** distributed on wordpress.org. It ships via Freemius /
self-hosted download and is licensed per the Aura plan (bundled with Agency and
Studio) or as SiteAgent Pro.

Tools register with the base plugin through the `aura_worker_register_tools`
filter — this companion loads only local PHP, never anything remote.

= Read tools (enabled on install) =

* **read_file** — read a text file from within wp-content (jailed). Read-only.
  Refuses wp-config.php and paths outside the allowed roots; output byte-capped.
* **db_query** — run a single read-only SQL statement (SELECT/SHOW/EXPLAIN/
  DESCRIBE). Row-capped; refuses writes, stacked statements, and file access.
  Requires approval (a read query can still surface sensitive rows).

= Write tools (approval-gated AND off until you opt in) =

Each is registered so its preview/dry-run works, but EXECUTION is disabled until
the operator sets a constant in wp-config, and every one requires human approval
through the Aura gateway. Installing this plugin does not, by itself, enable any
write or code execution.

* **write_file** — write a file within wp-content (jailed, refuses wp-config.php,
  snapshot-first so it's reversible). Enable with:
  `define( 'AURA_POWER_ALLOW_FS_WRITE', true );`
* **run_wp_cli** — run an allowlisted WP-CLI command with no shell and no
  metacharacters (refuses eval/config/db/shell/installs). Enable with:
  `define( 'AURA_POWER_ALLOW_WP_CLI', true );` (optionally
  `define( 'AURA_POWER_WP_CLI_BIN', '/path/to/wp' );`)
* **execute_php** — run a PHP snippet with the full WP API; shell-outs are
  refused (use run_wp_cli), a static scan flags risky constructs for the
  approver. Enable with: `define( 'AURA_POWER_EXECUTE_PHP', true );`

The safety model is governance, not a sandbox: a wp-config constant to arm each
tool, human approval on every call (with the code/command/diff shown), and a
full audit trail — not a claim that the static scan makes arbitrary code safe.

= Security posture (read before enabling) =

* **Approval is enforced by the Aura gateway, not (yet) by this plugin.** The
  plugin cannot distinguish a gateway-approved call from a raw site-token call —
  both carry the same `X-Aura-Token`. Today the base plugin records every
  approval-required execution via the `aura_worker_power_execute` action (for
  forensics), but hard enforcement (a signed, single-use approval grant the
  plugin verifies) is a planned follow-up. Until then, treat a leaked site token
  as equivalent to unapproved use of whatever tools you have armed — rotate
  tokens on the Aura side and keep IP/domain allowlists populated.
* **execute_php is RCE-equivalent when armed.** The shell-out deny-list and scan
  are advisory (an approved snippet can defeat them via indirect calls/FFI). The
  real control is the constant + the human reading the code.
* **write_file can drop a PHP web shell.** A `.php` write into a web-served dir
  (e.g. uploads) becomes persistent, token-free RCE; the preview flags executable
  targets so the approver sees it. Writes are jailed to wp-content, refuse
  symlinks and wp-config.php, and snapshot the prior file first (failing closed
  if the snapshot can't be taken).
* **run_wp_cli** runs with no shell (argv array), a subcommand allowlist, a
  positive `--flag` allowlist (so `--require`/`--exec`/`--ssh` can't load code),
  `--skip-packages`, a 60s wall-clock limit, and a 256KB output cap.

Reviewed adversarially before release; residual risks above are inherent to
RCE-by-design tools and are the operator's to accept.

== Changelog ==

= 0.2.0 =
* Add governed write tools: write_file, run_wp_cli, execute_php. Each is
  approval-gated and disabled until armed by a wp-config constant. Shell-outs
  refused in execute_php; WP-CLI allowlisted and shell-free; file writes jailed
  to wp-content and snapshot-first.

= 0.1.0 =
* Initial companion scaffold: read_file + db_query power tools, registered via
  the base plugin's aura_worker_register_tools filter.
