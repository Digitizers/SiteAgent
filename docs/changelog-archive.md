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

= 2.17.5 =
* Plugin updates: on a host where PHP may not write or delete `.php` files (WP Engine is the proven case), every Aura-driven plugin change — the self-update, a single or batch plugin update, `update_plugin_safely` — is refused before anything is touched, with `code: aura_php_writes_blocked` (or `aura_upgrade_dir_unwritable` when `wp-content/upgrade` cannot be written at all). Such a host failed every update at unpack, and the restore that followed deleted the plugin's non-PHP files: SiteAgent's own `readme.txt`, and a plugin's CSS, JS and images. The check creates and removes a temporary file, and runs only when WordPress writes files directly.
* Restore: a restore checks that it can finish — PHP can write `.php` files in the plugins directory, and every directory of the plugin is writable — before it deletes anything; otherwise it answers `stage: preflight` and leaves the directory untouched. A failed install that changed no file is no longer restored at all (`restore_skipped: unchanged`).
* `/status` reports the host's last recorded answer as `host: { php_writes, checked_at }`, so Aura can stop sending updates to such a site.
* `update_plugin_safely` returns the refusal's code and message (it lost both before).

= 2.17.4 =
* Self-update: a package that carries the version already running is refused before anything changes. The verified download is inspected in place, the plugin is not backed up, and no install runs. Before this, a same-version request backed the plugin up, reinstalled over the live directory, refused and restored — and on one host lost `readme.txt` on every run. The refusal answers `code: aura_self_update_same_version`. An archive that cannot be read falls through to the previous path.
* Self-update: when the post-install version check fails, the message now says what the evidence supports. A verified package with a different version means the upgrader did not replace the plugin directory (`aura_self_update_not_replaced`); without that proof both causes are named (`aura_self_update_version_unchanged`).
* Self-update: a restore that cannot finish reports which stage failed (`clear` or `extract`), and the message says what is on disk. A failed clear can remove some files before it stops, so the directory is no longer described as untouched.

= 2.17.3 =
* Snapshots: an `overwrite_file()` record now stores the sha256 of the content it wrote (`replaced_with_sha256`), and restoring that record puts the old bytes back ONLY while the file still holds exactly what the write left there. A file edited since is refused untouched (`aura_file_changed_since`); a file already holding the old bytes answers `already` and is not rewritten; a record taken without that hash — a direct `POST /aura/v2/snapshot`, or a Power Pack older than 0.2.5 — is refused as unfenced (`aura_snapshot_unfenced`), because nothing proves what it would be writing over.
* Snapshots: every engine write to a path records a `write_seq`, taken under that path's lock and strictly increasing per target, so two writes to one file can be ordered after the fact — Aura rolls a run back in that order.
* Snapshots: a file restore that cannot act says why with a code instead of a bare failure — `aura_file_changed_since`, `aura_snapshot_unfenced`, `aura_snapshot_voided` (the create's rollback record is gone) and `aura_path_locked` (another write to the path is in progress) — each answered as an HTTP 409 refusal rather than a 500. Restoring a created file that is already gone answers `already`.

= 2.17.2 =
* Snapshots: every engine write to a path — `create_file()`, the new `overwrite_file()`, and the restore of a created file — runs under a per-path lock, so two agent writes racing one new file can no longer interleave in the same inode (a concurrent in-place overwrite passed the inode check). After a publish the target must hash to the recorded content, else the record is voided as `interrupted` and the staged bytes kept.
* Snapshots: `overwrite_file()` replaces an existing file the way the engine creates one — old bytes snapshotted, new content staged beside the target with its own mode, then renamed over it: a reader sees the old file or the new, never a truncated one, and a short write touches nothing. The Power Pack's `write_file` adopts it in 0.2.5.
* Snapshots: on a host without `flock()` the record and path locks are `mkdir()` directories (atomic everywhere; a lock older than five minutes is a crashed holder and is broken) instead of running unlocked, which let a stage sweep retire the record of a publish that then completed.
* Snapshots: without `link()`, an executable created file that was edited since is verified in place and left where it is on `file_changed_since` — the claim by rename came first and the put-back refused exec bits, stranding the edited file under its `.aura-restore-*` name.
* Snapshots: `delete()` removes the record's lock file with the record.

= 2.17.1 =
* Snapshots: `create_file()` publishes without `link()`. Most managed hosts put `link()` in `disable_functions` for web PHP (Cloudways does), and 2.17.0 refused every new-file create there (`unsupported_filesystem`). Without `link()` the target is now claimed with an exclusive create — it refuses an existing path and returns an inode this call owns — and the bytes are written into that handle; ownership is checked by inode after the write, so nothing that took the path meanwhile is ever overwritten. What this mode gives up is the empty-to-complete jump: a reader in the milliseconds of the write can see the file grow. A short write leaves the entry empty, never truncated content. Restoring a created file that was edited since puts it back the same way instead of leaving it aside under its `.aura-restore-*` name. A publish interrupted mid-write is reconciled by the stage sweep: the record is voided and marked `interrupted`, the file is never deleted by a restore. `create_file()` answers `published: link | write`.
* `audit_agent_code`: `power_pack.create_publish` says how a create lands on this host (`link` or `write`).

= 2.17.0 =
* New read-only tool `audit_agent_code`: executable code an AI agent authored or can author on this site — Angie code snippets (recorded, agent-authored, and which are live in the environment the loader includes, correlated per snippet directory in both environments), the SiteAgent Power Pack's execute-php / file-write / wp-cli flags, and third-party exec stores (EMCP Pro sandbox, Atarim exec abilities). Counts and presence only; never file contents, never a verdict. Bounded (200 directory entries per environment, `coverage.truncated`), `null` for anything unreadable, `{ error }` per subtree.
* Snapshots: `create_file()` creates a NEW file with the engine owning the whole create — staged beside the target under a non-PHP name, recorded, then published with `link()` (atomic, no-clobber) — so no target ever exists without its record and no partial content is ever visible. Restoring the record claims the path with an atomic rename, verifies the claimed file, and removes it only while its bytes still match; a file edited since is put back and refused (`file_changed_since`), never deleted. The Power Pack's `write_file` adopts it in 0.2.4.
* Tool metadata lists an empty parameter map as `{}` rather than `[]` (SA#83).
* Generic SiteAgent mutations (batch entry, generic single update, generic rollback) renew the self-update lease between phases and heartbeat it from inside the upgrader's own sub-phases, so a slow download, unpack or install is never seized as stale, and a lost lease stops the entry rather than racing its successor (SA#80).
* Every Aura-driven mutation of SiteAgent's own files — self-update, generic single update, the batch entry, the guarded rollback — refuses on a multisite network with `aura_self_update_multisite_unsupported`: the update lock is per site while the plugin directory is shared (SA#79). Other plugins are unaffected.
* A rule-blocked call now says to scope the rule out of the site with `sites`, or release it.

= 2.16.2 =
* `/status`'s door fragment carries `observation`, a per-site door-version witness bumped atomically by every door-state mutation (never by a mere poll) and clock-floored so a restored backup can never reissue a value it already served, so Aura can order overlapping polls by the site's own witness instead of request timestamps; `elementor.governor` reports the current value. The observation witness requires InnoDB for wp_options and 64-bit PHP; without them ordering falls back to Aura's own request order for that site (`elementor.governor` reports why via `observation_unsupported`: `engine` or `php32`).
* `/status` also accepts `door_observation_seen` — Aura's own last accepted observation, echoed back — so the site can bump its witness forward, clock-floored, when a restore rewound its own copy behind Aura's. Accepted for ANY non-negative integer the site's own witness could ever report (no magnitude ceiling — the witness only counts up); honoured only within a fixed headroom below the class ceiling, silently ignored (200, no bump, no error) above it.
* `elementor.governor` reports `counters_as_of` beside the four `_30d` rolling counters, which `observation` does not cover; the counters themselves are `int|null`.
* `interrupted` / `running` / `held` are `null`, not `[]`, when their own queue could not be read, and `observation` is withheld entirely whenever any read behind the fragment was unreadable.
* New `door_write_unsupported` reason `reconnect_guard_unavailable`: a governed write refuses outright on a `$wpdb` replacement that cannot disable reconnects, rather than risk a mutation replaying on a session this plugin no longer controls.
* Door writes report a `committed` tri-state (`true` / `false` / unknown) and answer a retryable 503 with `may_have_run: true` whenever a commit cannot be proven — claims, holds, acks, and rotations alike; `replay()` now forwards `claim()`'s own error instead of a generic one.
* Hold references and pending log-row reservations are derived from the request's own identity, so a retry after an ambiguous commit finds and reuses its own prior write instead of risking a duplicate.

= 2.16.1 =
* `/status` door fragment and `audit_mcp_exposure`'s `elementor.governor` carry `binding`, the site's current binding generation, so Aura can label a departed client's door-log entries without inferring the generation from the rows.

= 2.16.0 =
* Elementor MCP door governance: every write through Elementor >= 4.3's official MCP server is held for approval in Aura unless an operator `allow` rule covers it; `block` refuses; every write that runs is snapshotted first on the site and recorded in a per-site door log Aura drains. New tools `elementor_replay_ability`, `snapshot_get`; new routes `/aura/v1/door/reject`, `/aura/v1/door/ack`; `/status` carries `door`; `audit_mcp_exposure` carries `elementor.governor`. Rules gain the `allow` effect and the `design_system` / `page_create` targets.

= 2.15.0 =
* audit_mcp_exposure reports an `elementor` block: Elementor >= 4.3's official MCP module state, every `elementor_mcp_consent` row, every `Elementor MCP…` Application Password across all users (full detail), and the other Application Passwords of edit_posts users as counts. Every list is bounded (50 / 50 / 200) with a truncation flag beside it; no usermeta value over 256 KB is decoded; a scan that fails is reported as `{ error }` in its place, never as an empty list. Read-only.
* aura_worker_app_password_list() accepts an optional byte bound, enforced in the same statement that returns the value.

= 2.14.0 =
* Feature: **a self-update can now undo itself.** Before installing, SiteAgent
  archives its current build — best-effort: a site without ZipArchive or with
  an unwritable backup directory still updates, and the result says
  `backed_up: false` so the caller knows this one had no way back. After
  installing, SiteAgent asks the new build to prove it came up. The proof is
  written by the build itself: a boot beacon recorded the first time the new
  code serves a request, and a fatal beacon recorded by
  a shutdown guard when the new code dies while loading. A build whose own
  records say it broke is rolled back to the archived one, and compiled
  copies are asked out of the opcode cache (best-effort — a host that
  restricts `opcache_invalidate()` may serve the old compiled code until its
  cache revalidates). The result reports exactly what happened (`backed_up`,
  `verified`, `rolled_back`). When neither record appears, the update stands
  and says it was not verified, never guessed.
* Feature: **one SiteAgent mutation at a time.** Every path that can replace
  SiteAgent's own files — the self-update, the generic plugin update, a batch
  entry, the rollback endpoint — runs under a single per-site claim: taken by
  a conditional insert, seizable only after its holder has gone silent for ten
  minutes (a request that dies never releases), and released only by its
  owner. The self-update additionally renews the claim between its phases,
  so a slow step costs the lease nothing once the next phase begins. An
  overlapping request is answered "in progress" and touches nothing.
* Hardening: backups follow a symlink only while its target stays inside the
  plugin folder — a link pointing out of the tree makes the backup report
  itself incomplete instead of copying unrelated files into an archive.
  Restores never delete through a symlink, root or child, and refuse to run at
  all when a link cannot be removed. A package carrying the version already
  running is refused, because its boot records could not be told apart from
  the old build's. Backup filenames carry a per-operation suffix, so two
  backups started in the same second never overwrite each other.

= 2.13.0 =
* Feature: **Aura can now disconnect a site in two phases, and the site
  refuses changes the moment it is told to.** When Aura sends a disconnect,
  SiteAgent records it and every mutation on the site — SiteAgent's own write
  endpoints, MCP write tools, grant-signed calls, and any WordPress REST write
  made with the Application Password or site token of the departing connection
  — is answered `403 aura_site_unbound` until the site is reconnected. Reads
  keep working, `/status` keeps answering, and Aura's own ruleset endpoint
  stays reachable so the disconnect can be finished or retried.
* Feature: **a departing connection's Application Password stops working
  everywhere, not just on the REST API.** WordPress authenticates Application
  Passwords on XML-RPC as well, so a credential the disconnect could not revoke
  is now refused where WordPress decides whether it authenticates at all. Your
  own Application Passwords are untouched, and so is admin login.
* Feature: **the cleanup is proven, not assumed.** SiteAgent revokes the
  Application Password(s) Aura minted, clears the stored ruleset and the
  gateway public key, forgets the connect bookkeeping, and deletes the site
  token LAST — and only when Aura says the disconnect is final. Every step is
  idempotent, runs in one fixed order, and continues past a step that failed.
  An interrupted disconnect finishes itself on the site's next page load
  (throttled, at most once every five minutes), so a site Aura can no longer
  reach still converges.
* Feature: the answer to a disconnect now names what is still owed:
  `leftovers` lists the credentials or stores this site could not prove it had
  released (`app_passwords`, `options`, `ruleset`, `grant_pubkey`), alongside
  `cleanup_complete`. An empty list means only the shared site token was still
  outstanding; a non-empty one means Aura must keep waiting.
* Feature: **the settings screen tells you when Aura disconnected the site**
  ("Disconnected by Aura at …") and offers **Remove remaining Aura data** — an
  admin-initiated teardown that runs the same proven cleanup, and only clears
  the disconnect record once everything it names, the site token included, is
  gone.
* Feature: manually connected sites (those with no gateway public key, which
  therefore cannot verify a signed document) can be disconnected with a bare
  `{ "unbind": true, … }` body on `POST /wp-json/aura/v2/rules`, authenticated
  by the site token alone. A site that DOES hold a gateway key refuses the
  bare form and requires the signed envelope.
* Safety: every ruleset push now runs under the site-wide claim the connect
  flow already used, so a push, a disconnect and a reconnect can no longer
  interleave. A site already busy answers `503 aura_site_busy`, which is
  retryable. A claim left behind by a killed request is taken over after two
  minutes rather than blocking the site indefinitely.
* Safety: reconnecting (magic link) and **Regenerate Token** now settle the
  previous connection's outstanding cleanup BEFORE installing a new token, and
  release the disconnect record only after the replacement connection is
  installed and read back — so a reconnect that fails halfway leaves the site
  still refusing the departed connection instead of quietly reviving it.
* Fix: a disconnect record damaged in the database is now REPAIRED — rebuilt
  from the site's own state, under the claim — and then torn down through the
  ordinary path. Previously a damaged record was a permanent dead end: the
  site refused every change and no control could clear it.
* Diagnostics: `/status` reports `unbound: { at, site_ref }` while a
  disconnect is outstanding, and `app_password_probe_unproven:
  { count, at, owner }` when the site cannot prove an Application Password was
  revoked — the usual reason a disconnect never finishes. Both are bounded and
  contain no secrets.
* New error codes: `aura_site_unbound` (403 — the site is disconnected),
  `aura_site_busy` (503 — retry), `aura_unbind_incomplete` (409 — something is
  still owed; the answer lists it), `aura_unbind_unreadable` (409 — the
  disconnect record could not be read, which is NOT the same as an incomplete
  cleanup), `aura_unbind_unrepairable` (409), `aura_unbind_marker_stuck`
  (500 — everything was removed but the record itself would not delete),
  `aura_unbind_marker_malformed` (500), `aura_unbind_store_failed` (500) and
  `aura_ruleset_client_mismatch` (409 — a disconnect addressed to a different
  Aura client), which the bare unkeyed form now checks too.
* Compatibility: a 2.13 site that is never sent a disconnect behaves exactly
  as 2.12 did. Nothing on the site changes until Aura asks for one.

= 2.12.0 =
* Feature: **a rule can now apply to some of a client's sites instead of all
  of them.** Aura's signed ruleset names the site each document was issued
  for, SiteAgent stores that identity, and a rule that lists the sites it
  applies to is enforced only where it belongs. Rules that name no sites are
  client-wide exactly as before.
* Safety: a site that cannot prove its own identity — an older record, a
  document issued before this field existed — enforces EVERY rule rather than
  skipping the ones it cannot place. Scoping only ever narrows on proof.
* Upgrade: the identity is recovered offline from the ruleset already stored,
  by re-verifying its signature locally. No new network traffic, and a site
  whose ruleset has not changed since the upgrade is repaired on its next
  request rather than waiting for the next push.

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
