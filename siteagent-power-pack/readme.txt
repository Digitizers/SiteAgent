=== SiteAgent Power Pack ===
Contributors: digitizer
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

= Tools in this release (0.1.0) =

* **read_file** — read a text file from within wp-content (jailed). Read-only.
  Refuses wp-config.php and paths outside the allowed roots; output byte-capped.
* **db_query** — run a single read-only SQL statement (SELECT/SHOW/EXPLAIN/
  DESCRIBE). Row-capped; refuses writes, stacked statements, and file access.
  Requires approval (a read query can still surface sensitive rows).

Write-class tools (execute-php, wp-cli, filesystem write) are intentionally not
in this release; they ship separately after a dedicated security review.

== Changelog ==

= 0.1.0 =
* Initial companion scaffold: read_file + db_query power tools, registered via
  the base plugin's aura_worker_register_tools filter.
