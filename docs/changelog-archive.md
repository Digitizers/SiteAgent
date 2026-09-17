# Changelog archive — SiteAgent for Aura

WordPress.org truncates a `readme.txt` Changelog longer than 5,000 words and
reports it only in a warning visible to plugin committers, so `readme.txt` keeps
the recent releases and its Changelog ends with a `= <version> and earlier =`
stub pointing here.

**The entries below are the text that was in `readme.txt`, moved verbatim.** They
are not a rewrite and not a summary: the trim moves history, it never edits it,
so nothing a WordPress.org reader could see before is lost.

`README.md`'s own `## Changelog` is a separate, independently written record of
the same releases and is deliberately not merged into this file — the two say
different things about the same versions, and reconciling them by hand would be
the judgement call this file exists to avoid.

Newest first, continuing exactly where `readme.txt`'s Changelog stops.

= 2.11.0 =
* Feature: **the magic-link connect now mints an Application Password for
  the dashboard.** A magic-link connection could run SiteAgent's own tools
  but not the builder tools (Elementor MCP and friends) that authenticate
  with WordPress Basic auth — only a manual connect, which never provisions
  the gateway key, could. The signed /connect callback now also mints an
  Application Password named "Aura SiteAgent" for the administrator who
  created the link and returns it once, in the callback's response, so the
  dashboard stores it encrypted beside the site token. Every connect rotates
  it (earlier ones under that name are deleted first). Where Application
  Passwords are unavailable (no HTTPS, disabled by a filter, no admin user)
  the connect succeeds token-only as before and names the reason.
* The whole install — token, client binding, gateway key, Application
  Password — runs under one site-wide connect claim, so two callbacks for the
  same site cannot split the credentials the dashboard ends up holding.
  "Regenerate Token" takes the same claim (it is refused while a connect is
  installing). The claim is never taken over by age: a callback the dashboard
  timed out on may still be running. Every write the install makes — the site
  token, the client binding, the dashboard URL, the gateway key — is issued by
  a statement conditional on holding the claim, so a claim that goes away
  mid-request costs that request its connect (retryable) rather than letting it
  overwrite the install that replaced it. If a
  killed request ever leaves a claim behind, every later connect is refused
  until an administrator deactivates and reactivates the plugin, which
  releases it.
  "Regenerate Token" issues its cleanup the same way, and skips revoking the
  Application Password when it no longer owns the claim — the password on the
  site then belongs to whichever connect replaced it. It still reveals the
  token it stored: refusing to would revoke the old token while showing no
  replacement.
* Deactivating the plugin now also revokes the Application Password Aura
  minted. Unregistering SiteAgent's routes does not stop an
  administrator-level credential from authenticating to WordPress core and to
  other REST/MCP plugins, so "deactivated" would not have meant
  "disconnected". If the revocation fails the owner and UUID are kept, and
  reactivating finishes the job (activation retries a revocation deactivation
  could not land), as does uninstalling. The site token binding survives
  deactivation, so the settings screen keeps showing the dashboard it is
  connected to. The Connect button is always reachable there, and the line above
  it says what the site actually holds: a working credential, one that was never
  delivered, none at all, or a site that cannot issue one (token-only).

= 2.10.3 =
* Fix (security): **"Regenerate Token" revealed a new site token without ever
  storing it.** The option was registered as a read-only setting, and the
  callback enforcing that ran on every write — not only on the settings form —
  so the handler's write was discarded while the one-time reveal still
  appeared. Two consequences: an admin rotating a leaked token was told it was
  revoked when the old token stayed valid, and a site disconnected from the
  dashboard could not be reconnected, because no token the screen displayed
  ever authenticated. The token is no longer registered as a setting (it is
  display-only, so nothing submits it), and regeneration now stores the new
  hash with a single compare-and-swap, out of reach of any option filter — a
  token is revealed only when that one statement reports it wrote the row, and
  a site whose row is missing or empty can be given its first token the same
  way.

= 2.10.2 =
* Fix: a site moved from one Aura client to another while the old client's
  last push was still in flight could end up holding the old client's ruleset
  and refuse the new client's rules until it was reconnected. The connect
  callback now names the client the site belongs to (a signed, optional field
  — older dashboards keep working unchanged) and writes that binding into the
  ruleset store itself, so a ruleset for any other client is refused from then
  on, whatever was in flight.

= 2.10.1 =
* Fix: `audit_rules` could report zero blocked/warned events for the current
  hour. Reading the counters before the hour's first refusal put the bucket in
  WordPress's negative option cache, and the refusal's atomic insert did not
  clear it — so the count stayed at zero for the rest of the request, and on a
  site with a persistent object cache until the cache was flushed. Enforcement
  was never affected; only what the audit reported.
* Fix: two rulesets pushed to a site at the same moment, before it held any,
  could answer the loser with 500 instead of the ordinary "a newer ruleset is
  already installed" decision. Aura retries a 500, so no policy was lost; the
  site now classifies the database's duplicate-key or deadlock answer as the
  lost race it is.

= 2.10.0 =
* New: operator rules, enforced on the site. A rule is an Aura memory entry
  (`rule/<slug>`) naming a resource — the whole site, a page or post by ID, or a
  plugin by slug — with an effect of `block` or `warn` and an optional expiry.
  Aura signs the client's whole ruleset with the same key that signs approval
  grants and pushes it to every connected site (`POST /aura/v2/rules`), the site
  verifies it and keeps only a newer one (a replayed older ruleset is refused
  even when validly signed). No ruleset means no policy — nothing is refused. A
  site that has never been reconnected since signed approvals shipped holds no
  gateway key and cannot verify a ruleset; it says so, and Aura tells you to
  reconnect it.
* Enforcement runs on every path a write can take: inside the tool executor
  before anything runs or is snapshotted; explicitly on the legacy REST update
  routes; and at WordPress core's own REST API for posts and pages — so a rule on
  a page holds against Aura's content tools, an assistant with an application
  password, or another plugin's MCP server alike. A `block` refuses the call and
  names the rule; **a rule outranks an approval** — a granted call is still
  refused, and the message says to release the rule first. A `warn` runs and
  attaches the warning. Previews are never blocked; they now report what a call
  touches and which rule would decide it.
* New: `audit_rules` (read-only) — ruleset presence and age, whether the site
  can verify one, 24h block/warn counts, expired-but-listed rules, and the
  enforcement points in this build.
* Every mutating tool now declares what a call touches; one that does not is
  caught by every rule rather than by none.

= 2.9.1 =

* Security (hardening): SiteAgent's tools have been dual-registered as WordPress
  abilities since 2.5.0, and an ability is published to the SITE rather than to
  one server — so any co-installed MCP server that enumerates the site's
  abilities could serve them, including the ones that write. The approval-grant
  enforcement lives in SiteAgent's own REST handlers, which that route never
  touches, so a mutating tool could run through another plugin's MCP server with
  no approval, no snapshot binding, and no audit entry.
  Two guards close it. Tools that require a grant now declare a discovery type
  co-installed servers do not serve, and a grant-requiring ability arriving on
  any transport other than SiteAgent's own is refused unless it carries a valid
  approval grant bound to that exact call.
  Nothing changes for the Aura gateway path or for wp-admin: a grant the gateway
  minted is accepted here unchanged. Read-only tools stay discoverable to other
  MCP clients, which is what the dual registration is for. Approval-bound reads
  (`db_query`) follow the write rule, as they already did on the gateway path.

= 2.9.0 =

* New: a read-only security-audit surface — five tools that report what is on
  the site without changing any of it. `check_core_checksums` compares WordPress
  core files against the official manifest, `scan_executable_files` looks for
  executable files and `.htaccess` overrides under uploads, `audit_admin_accounts`
  and `audit_cron` inventory administrator accounts and scheduled events.
* New: `audit_mcp_exposure` answers a question that only appears once a site runs
  more than one AI assistant — which OTHER MCP servers are registered here, and
  how many of this site's abilities pass the discovery rule such a server
  applies. Abilities are registered site-wide, not to the plugin that declared
  them, so a server resolving targets from that registry (as Angie's does) picks
  up mutating ones that never went through this plugin's approval and audit
  path. The counts are a property of the abilities, not proof that any server
  currently serves them — a server with an explicit tool list reaches only what
  it lists. The tool reports; it changes nothing.
* All five report bounded coverage — `truncated` and `cap`, alongside a pair of
  counts naming what was in scope and what was reached (`total_seen` /
  `returned`, or `files_expected` / `files_checked` for `check_core_checksums`,
  which counts files rather than rows). They stop at their caps rather than
  growing without limit on a large site, and an empty result under
  `truncated: true` means "nothing found before the cap" — it is never reported
  as "clean".
* Compatibility: declared tested up to WordPress 7.1.

= 2.8.2 =

* Security (hardening): the snapshot/rollback engine now reads its stored
  payloads back with `unserialize()` restricted to `allowed_classes => false`,
  so a tampered payload file can no longer instantiate arbitrary PHP objects on
  the restore path (object-injection defense-in-depth). Restores fail closed on
  any object-bearing or malformed payload. The restore paths are unchanged for
  the scalar and array data the engine actually stores.
* Fix: the self-updater deletes its temporary download with `wp_delete_file()`,
  and the SEO auditor reads WordPress core's sitemap state through the sitemaps
  server instead of re-firing the `wp_sitemaps_enabled` filter.
* Internal: PRs are now gated in CI on PHPCS (security/correctness sniffs) and
  the official WordPress Plugin Check, so regressions in the above are caught
  before release. No effect on the shipped plugin.

= 2.8.1 =

* Docs: the listing now describes the optional **SiteAgent Power Pack** companion
  plugin — the governed power tools (file read/write, read-only SQL, allowlisted
  WP-CLI, PHP execution) that are deliberately NOT part of this WordPress.org
  build. It states plainly that the write and code tools stay disabled until the
  site owner arms each one in wp-config.php, that the site must be connected to
  Aura first (that provisions the approval key which makes grant enforcement
  active), and that the model is governance — not a sandbox. No code changes.

= 2.8.0 =

* Internal: Snapshot-engine primitives for reversible meta and multi-post writes —
  `snapshot_meta` (post-meta capture with a `meta` restore kind) and
  `snapshot_posts` (multi-post capture with a `posts` restore kind that recreates
  a deleted post under its original id). These are groundwork for upcoming
  governed Elementor and bulk-post editing; they are not yet exposed over the
  remote snapshot API in this release (which still handles `file` and `option`).
* Fix: SEO-meta writes now return a distinct "Failed to write SEO meta" error when
  a write fails despite input, instead of the misleading "Nothing to update".
* Docs: Listing repositioned around governed, reversible AI, with the approval and
  rollback guarantees scoped to the paths that actually enforce and snapshot.

= 2.7.1 =

* Self-update integrity: when the Aura gateway supplies the release's SHA-256,
  SiteAgent now downloads the zip, verifies its bytes against that digest, and
  refuses to install on a mismatch — so an approved self-update is bound to the
  exact package, not just the URL. Sites without a supplied digest behave as
  before.

= 2.7.0 =

* Approval gate now covers the direct REST write endpoints, not just MCP tools.
  When a site has provisioned Aura's approval key, plugin/theme/core/translation
  updates, batch updates, database migrations, rollbacks, self-update, and
  snapshot create/restore each
  require a fresh single-use signed grant bound to the exact action and
  parameters — so a leaked Site Token can no longer trigger a code update on its
  own. Sites without the key keep working as before (token-only) until they
  reconnect.
* Self-update source allowlist: SiteAgent will only install a self-update zip
  from the official GitHub repository (Digitizers/SiteAgent release downloads),
  over HTTPS. Overridable via the aura_worker_self_update_allowed_hosts filter.

= 2.6.1 =

* Tool self-declaration hardening: six mutating tools (update_plugin_safely,
  cleanup_orphaned_assets, backup_plugins, cleanup_transients, clear_caches,
  set_seo_meta) now explicitly declare themselves non-read-only and
  approval-required instead of inheriting neutral defaults, so any consumer that
  trusts a tool's own annotations gates them correctly. Grant enforcement and
  the Aura gateway's verb-based policy already treated them as writes, so live
  behaviour is unchanged.
* cleanup_orphaned_assets now advertises a preview: its dry-run (find orphans,
  delete nothing) is exposed through the preview API, so the orphaned-media
  sample and count can be inspected without approval before the destructive
  delete — which still requires approval.

= 2.6.0 =

* Signed approval grants (G-grants): every mutating (non read-only) MCP tool
  reached over the Aura gateway (X-Aura-Token) path now requires a single-use,
  Ed25519-signed grant that binds the exact tool, parameters, site, and a short
  validity window — so a stolen site token can only ever run READ tools, never a
  write or a power op. The plugin stores only the gateway's PUBLIC key, so even a
  fully compromised site can't mint its own grants; only the gateway can, and
  only for a human-approved action. The gateway public key is provisioned over
  the HMAC-signed magic-link /connect callback, and enforcement activates only
  once it is present, so existing deployments are unaffected until they
  reconnect. The WordPress Abilities / Application-Password path
  (capability-gated) is unchanged.

= 2.5.0 =

* WordPress Abilities API bridge: SiteAgent tools are now dual-registered as WP
  abilities when the core Abilities API is present, so the official MCP adapter
  and standard MCP clients can discover them (aura/mcp namespace unchanged).
* Hardening (external review): register the abilities category before the
  abilities (else a real Abilities API rejects them); default a missing input to
  {} for parameterless abilities; snapshot engine fails closed when a payload/
  metadata write fails, uses an uncollidable "absent option" sentinel, and post
  restore refuses a missing payload instead of wiping the page; Gutenberg
  update refuses inner_html on a block with nested children and surfaces the
  inner_html change in its preview; the snapshot REST file endpoint jails targets
  to wp-content and refuses wp-config.php; AURA_WORKER_VERSION synced.

= 2.4.0 =

* Gutenberg (block editor) tools: list_page_blocks (read), update_page_block
  (approval-gated, snapshot-first, reversible), create_page_from_blocks
  (draft-first). Ends the Elementor-only gap — Gutenberg is core WP.
* Snapshot engine gains a "post" kind (snapshot_post) so block edits are
  reversible.

= 2.3.0 =

* **Token-only connection** — a valid Aura Site Token now authorizes management on its own. After connecting (magic link or Regenerate Token), the plugin runs requests as the connecting administrator, so Aura no longer needs a WordPress Application Password. Existing app-password connections keep working unchanged. No new tools — the set stays at **18**.
* **Forensics hook** — fires `do_action( 'aura_worker_token_run_as', $user_id, $route )` whenever a request is authorized by token alone and run as an admin, so site owners can distinguish token-run-as from interactive admin actions in their audit log. The admin fallback is now deterministic (lowest-ID administrator).

= 2.2.4 =

* Fix: "Connect to Aura" (magic-link onboarding) now targets the Aura app host (`app.my-aura.app`) instead of the marketing domain, so one-click connect works out of the box. (Sites that set the `AURA_DASHBOARD_URL` constant are unaffected.)

= 2.2.3 =

* Fix: `set_seo_meta` on Yoast — after writing the meta, the cached Yoast indexable is now invalidated so the frontend serves the new SEO title/description immediately instead of the stale value (previously required a manual save/reindex).
* Fix: `perf_check` autoload weight — counts all WP 6.6+ autoload values (`yes`, `on`, `auto-on`, `auto`) instead of only `yes`, so the figure is no longer under-reported on newer cores.
* Fix: `scan_broken_links` — the reported counts now reflect the true number of matches; previously they were capped at the 10-item sample limit. Samples remain capped.
* Fix: `scan_seo` — missing excerpts now count toward the score (an `excerpts` finding is reported) instead of being tallied but ignored.
* Fix: `scan_a11y` document language — verified against the rendered `<html lang>` attribute of the home page rather than the configured locale, so a theme that omits `language_attributes()` is correctly flagged.

= 2.2.2 =

* Feature: On-site SEO-meta tools — two agent tools that read and write a post/page's SEO meta directly on the active SEO plugin (Rank Math, Yoast, or SEOPress):
  * `get_seo_meta` (read) — returns the SEO title, description, and focus keyword.
  * `set_seo_meta` (write, approval-gated) — sets any of title / description / focus keyword; only the fields you pass change.
* Because these run on-site via the plugin's own meta keys (not the SEO plugin's REST endpoint), they work even on sites where a firewall/WAF blocks those endpoints. Built-in tool set is now 18.

= 2.2.1 =

* Feature: Performance & broken-link auditors — two more read-only agent tools, scored/structured and no-AI-cost:
  * `perf_check` (read) — performance posture (persistent object cache, OPcache, page-cache plugin, PHP version, autoload weight, active plugin count, PHP memory limit, expired transients).
  * `scan_broken_links` (read) — link triage over a content sample with NO outbound HTTP: empty/anchor-only links, links to dev/staging hosts, and internal links that don't resolve locally.
* Built-in tool set is now 16.

= 2.2.0 =

* Feature: SEO & accessibility auditors — two new read-only agent tools, scored and no-AI-cost, governed by Aura's risk policy:
  * `scan_seo` (read) — SEO posture (search-engine visibility, permalink structure, XML sitemap, site title) plus a sampled content audit (missing excerpts/featured images, thin content).
  * `scan_a11y` (read) — accessibility audit over sampled content (images missing alt text, non-descriptive link text, missing heading structure, document language attribute).
* Both run fleet-wide through Aura's Fleet MCP Gateway to catch SEO/accessibility regressions across many sites at once.

= 2.1.0 =

* Feature: MCP ops toolset expansion — new agent tools governed by Aura's approval/risk policy:
  * `get_database_info` (read) — database size, largest tables, autoload weight, expired transient count.
  * `scan_security` (read) — scored security posture (file-edit lockdown, debug exposure, SSL, default admin/prefix, open registration, PHP version).
  * `list_users` (read) — users with roles and post counts, admins flagged; never returns secrets.
  * `check_health` (read) — live health gate (home-page HTTP, PHP fatals, white-screen, DB) for wrapping updates.
  * `scan_error_log` (read) — tails and severity-groups the PHP/WordPress error log, surfacing recent fatals.
  * `clear_caches` (write) — flush object cache, opcache, and detected page-cache plugins (W3TC, WP Super Cache, WP Rocket, LiteSpeed, Autoptimize).
  * `cleanup_transients` (write) — remove expired transients to reduce autoload bloat.
  * `backup_plugins` (write) — zip-snapshot one or all active plugins (rollback safety net) before mutating actions.

= 2.0.2 =

* Fix: Removed an arrow character from screenshot caption #1 that WordPress.org wrapped in emoji markup inside the image `alt` attribute, breaking the plugin page's HTML.

= 2.0.1 =

* Docs: readme rewritten for the 2.0 feature set (safe batch updates, rollback, magic-link, MCP), corrected security description, and added v2/MCP endpoint reference.
* Docs: fixed admin menu location — the settings page lives under **Settings → SiteAgent**.

= 2.0.0 =

* Feature: Site health checks — read recent error-log tail, surface PHP/DB/disk status in the health report.
* Feature: Plugin rollback & backup — zip-snapshot a plugin before updating and restore on demand if an update breaks the site.
* Feature: Magic-link admin access — generate a short-lived one-time login link from Aura for support sessions.
* Feature: MCP tools — expose site context, safe plugin updates, asset cleanup, and vulnerability checks to AI agents.
* Security: Site token is now stored hashed (SHA-256) instead of plaintext; existing tokens migrate automatically on first use.
* Security: Brute-force throttling on token authentication (per-IP failure limit).
* Security: Signed magic-link connect — the dashboard callback is HMAC-verified with a one-time secret and replay-protected by timestamp.
* Feature: Regenerate Token button under Settings → SiteAgent.
* Fix: Core database upgrade now reports real failures instead of always returning success (verifies db_version reached the target).
* Improvement: Tested with WordPress 7.0.
* Compliance: WordPress.org Plugin Check fixes — WP_Filesystem usage (no direct file_put_contents), gmdate(), wp_delete_file().

= 1.3.5 =

* Security: Enhanced authentication with timing-safe token comparison.
* Feature: Added optional IP whitelisting for restricted API access.
* Improvement: Support for Cloudflare and reverse proxy headers in IP detection.
* Fix: Improved compatibility with WordPress 6.7.

= 1.3.0 =

* Performance: Optimized REST API endpoints for faster health reports.
* UI: Updated admin interface under Tools for better clarity.

= 1.0.0 =

* Initial release.
* REST API endpoints for site health, available updates, core/plugin/theme/translation/database updates.
* Auto-generated Site Token.
* Admin page under Tools → SiteAgent.
* Zero frontend performance impact.


---

## Releases that never had a `readme.txt` entry

The five releases below were tagged and shipped but were never written into
`readme.txt`, so WordPress.org has never listed them. They are recorded here so
that every stable release has an entry somewhere. **These were not moved from
`readme.txt` — they are added**, each with its source named, so this section is
never mistaken for the verbatim history above.

= 1.3.4 =
_Source: `README.md` `## Changelog`._

* **Branding Update:** New official icons and banners for WordPress.org.
* **Improved UX:** Updated documentation and installation guides.

= 1.3.3 =
_Source: `README.md` `## Changelog`._

* **Official WordPress.org Launch:** Now available in the official plugin repository.
* GitHub Release: [v1.3.3](https://github.com/Digitizers/SiteAgent/releases/tag/v1.3.3)

= 1.3.2 =
_Source: the subject of the tagged commit `v1.3.2`; no changelog was written at the time._

* Fix: clear plugin cache after self-update

= 1.3.1 =
_Source: the subject of the tagged commit `v1.3.1`; no changelog was written at the time._

* Security: specific capability permission callbacks per WP.org review

= 1.2.0 =
_Source: the subject of the tagged commit `v1.2.0`; no changelog was written at the time._

* Refactor: Rename plugin from AuraWP to AuraWorker and update text domain from `aurawp` to `aura-worker` for trademark compliance.
