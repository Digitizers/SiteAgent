<p align="center">
  <img src="assets/aura_icon.png" alt="Aura" width="120" />
</p>
<p align="center">
  <img src="assets/aura_logotype.png" alt="Aura" width="140" />
</p>

<h3 align="center">SiteAgent for Aura</h3>

<p align="center">
  Official WordPress agent for <a href="https://my-aura.app"><strong>Aura</strong></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/digitizer-site-worker/">
    <img src="https://img.shields.io/badge/WordPress.org-Plugin-blue?logo=wordpress" alt="WordPress.org" />
  </a>
  <img src="https://img.shields.io/badge/WordPress-6.2%E2%80%937.0-21759b?logo=wordpress" alt="WordPress" />
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php" alt="PHP" />
  <img src="https://img.shields.io/badge/Stable-2.2.3-green" alt="Stable" />
</p>

---

## What is SiteAgent?

SiteAgent is the official remote management agent for the [Aura Infrastructure Hub](https://my-aura.app). It connects your WordPress sites to your Aura dashboard for seamless remote management, monitoring, and updates from a single centralized interface.

---

## Features

| Capability | Description |
|------------|-------------|
| **Site Health** | Real-time monitoring of WordPress & PHP versions, plugins, themes, and server health. |
| **One-Click Updates** | Update WordPress core, plugins, and themes remotely from the Aura dashboard. |
| **Safe Update Engine** | Chunked batch updates with health checks and automatic rollback on failure. |
| **Per-Plugin Rollback** | Zip backups in `wp-content/aura-backups/` with one-shot restore. |
| **MCP Tools Layer** | `/aura/mcp/` REST namespace exposing AI-agent-friendly tools with JSON schemas. |
| **Magic Link Onboarding** | One-click connection from wp-admin to the Aura dashboard — HMAC-signed, no token copy/paste. |
| **Maintenance** | Run database upgrades and translation updates across all sites. |
| **Hardened Security** | Hashed site tokens, brute-force throttling, signed magic-link connect, and optional IP/domain allowlists. |
| **Developer API** | Fully exposed via secure REST API endpoints. |

### Zero Frontend Impact

SiteAgent is built for performance. It only registers REST API routes and has **zero impact** on your site's frontend performance — no extra scripts, styles, or queries on page load.

---

## Installation

### Via WordPress.org (Recommended)

1. Go to **Plugins > Add New** in your WordPress admin.
2. Search for **SiteAgent**.
3. Click **Install Now** and then **Activate**.

### Via WP-CLI

```bash
wp plugin install digitizer-site-worker --activate
```

### Manual upload

Download the zip from the [latest release](https://github.com/Digitizers/SiteAgent/releases) and upload via **Plugins → Add New → Upload Plugin**.

> The display name is **SiteAgent for Aura**; the WordPress.org slug remains `digitizer-site-worker`.

---

## Security

Layered authentication protects every request:

1. **WordPress Auth:** Application Password with capability checks (`manage_options` / `update_*`).
2. **Site Token:** Per-site token in the `X-Aura-Token` header, **stored as a SHA-256 hash** (never plaintext) and compared timing-safely. Legacy plaintext tokens migrate automatically on first use.
3. **Brute-force throttle:** Per-IP failed-attempt limit returns HTTP 429.
4. **IP / Domain allowlist:** Optional restriction to your Aura instance.

Onboarding via magic link is **HMAC-signed**: the `/connect` callback carries a signature derived from a one-time secret the site issued, plus a timestamp replay window — so the token exchange can't be hijacked or replayed. Rotate the token anytime from **Settings → SiteAgent → Regenerate Token**.

---

## REST API

### v1 namespace — `/wp-json/aura/v1/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/status` | Full site health report |
| `GET` | `/updates` | Check available core, plugin, and theme updates |
| `POST` | `/update/core` | Upgrade WordPress core |
| `POST` | `/update/plugin` | Update a specific plugin |
| `POST` | `/update/theme` | Update a specific theme |
| `POST` | `/update/translations` | Bulk update translation packs |
| `POST` | `/update/database` | Run WordPress database upgrades |
| `POST` | `/connect` | Magic-link token exchange (public, HMAC-signed, 10-min expiring) |

### v2 namespace — `/wp-json/aura/v2/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/health` | HTTP, PHP fatals, white-screen, and DB connectivity checks |
| `POST` | `/update/batch` | Chunked batch updates with auto-rollback on health failure |
| `POST` | `/rollback/{plugin}` | Restore a plugin from its most recent zip backup |

### MCP namespace — `/wp-json/aura/mcp/`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/tools/list` | Enumerate available tools with JSON schemas |
| `POST` | `/tools/execute` | Execute a tool with validated parameters |
| `GET` | `/context` | Full site context for AI decision-making |

**Built-in MCP tools (18):**

| Tool | Kind | Purpose |
|------|------|---------|
| `get_site_context` | read | WP/PHP/theme/plugins/disk/performance snapshot + detected issues |
| `get_database_info` | read | DB size, largest tables, autoload weight, expired transients |
| `scan_security` | read | scored posture (file-edit, debug, SSL, default admin/prefix, registration, PHP) |
| `scan_seo` | read | scored SEO posture (indexability, permalinks, sitemap, title) + sampled content audit |
| `scan_a11y` | read | scored accessibility audit (image alt text, link text, headings, document language) |
| `perf_check` | read | scored performance posture (object cache, OPcache, page cache, PHP, autoload, plugins, memory) |
| `scan_broken_links` | read | link triage (empty/anchor, dev/staging hosts, unresolved internal links) — no outbound HTTP |
| `get_seo_meta` | read | a post/page's SEO title, description, focus keyword (Rank Math / Yoast / SEOPress) |
| `list_users` | read | users + roles + post counts, admins flagged (never returns secrets) |
| `check_health` | read | live health gate — HTTP, PHP fatals, white-screen, DB |
| `scan_error_log` | read | tail + severity-group the error log, surface recent fatals |
| `check_vulnerabilities` | read | plugins/themes vs the WordPress.org vulnerability DB |
| `set_seo_meta` | write | set a post/page's SEO title / description / focus keyword (approval-gated; only fields you pass change) |
| `update_plugin_safely` | write | backup → update → health check → auto-rollback |
| `clear_caches` | write | flush object/opcode caches + detected page-cache plugins |
| `cleanup_transients` | write | remove expired transients (autoload hygiene) |
| `cleanup_orphaned_assets` | write | find/remove unused media (dry-run by default) |
| `backup_plugins` | write | zip-snapshot one or all active plugins (rollback safety net) |

These plug straight into **Aura's Fleet MCP Gateway**: read tools run on demand, write tools are gated behind human approval, every call is audited. Tool names are classified by verb so the gateway applies the right risk policy automatically.

---

## Changelog

### 2.2.3

- **Auditor accuracy fixes** (no new tools — set stays at **18**): `set_seo_meta` now invalidates Yoast's cached indexable so SEO changes show on the frontend immediately; `perf_check` counts all WP 6.6+ autoload values (`yes`/`on`/`auto-on`/`auto`); `scan_broken_links` reports true totals instead of the capped sample count; `scan_seo` scores missing excerpts; `scan_a11y` checks the rendered `<html lang>` rather than the configured locale.

### 2.2.2

- **On-site SEO-meta tools** — two agent tools that read and write a post/page's SEO meta directly via the active SEO plugin's own meta keys (Rank Math, Yoast, SEOPress), bringing the built-in set to **18**. `get_seo_meta` (read) returns title / description / focus keyword; `set_seo_meta` (write, approval-gated) sets any subset. Because they run on-site rather than via the SEO plugin's REST endpoint, they work even where a WAF blocks those endpoints.

### 2.2.1

- **Performance & broken-link auditors** — two more read-only agent tools (`perf_check`, `scan_broken_links`), bringing the built-in set to **16**. `perf_check` scores caching layers, PHP, autoload weight, plugin count, memory, and expired transients. `scan_broken_links` triages links over a content sample **without any outbound HTTP** (empty/anchor links, dev/staging hosts, internal links that don't resolve locally). Both auto-register and run as read tools.

### 2.2.0

- **SEO & accessibility auditors** — two new read-only agent tools (`scan_seo`, `scan_a11y`), bringing the built-in set to **14**. Both are scored, no-AI-cost structural audits over a sampled set of published content (indexability/permalinks/sitemap/title + missing excerpts/featured images/thin content for SEO; image alt text, non-descriptive link text, heading structure, document language for accessibility). Auto-register via the tool loader; governed by Aura's risk policy as read tools.
- Cheap, fleet-friendly: run across every site through Aura's Fleet MCP Gateway to spot SEO/a11y regressions at scale.

### 2.1.0

- **MCP ops toolset expansion** — eight new agent tools (`get_database_info`, `scan_security`, `list_users`, `check_health`, `scan_error_log`, `clear_caches`, `cleanup_transients`, `backup_plugins`), bringing the built-in set to **12**. Each auto-registers via the tool loader and is governed by Aura's risk/approval policy.
- `check_health` + `backup_plugins` reuse the existing health-check and rollback engines — building blocks for health-gated fleet-wide safe updates.
- Read tools run on demand; cache/transient/backup tools are mutating and approval-gated.

### 2.0.0 *(stable — live on WordPress.org)*

- **v2 Update Engine:** health checks, per-plugin rollback, chunked batch updates, auto-rollback on failure.
- **MCP Tools Layer:** `/aura/mcp/` namespace with `tools/list`, `tools/execute`, `context`, plus four built-in tools.
- **Magic Link Onboarding:** one-click, **HMAC-signed** connection from wp-admin to the Aura dashboard.
- **Security hardening:** SHA-256 hashed site token (auto-migrates legacy tokens), per-IP brute-force throttle, Regenerate Token UI, timestamp replay protection on `/connect`.
- **Reliability:** core database upgrade now reports real failures instead of always succeeding.
- **Compliance:** WordPress.org Plugin Check fixes — `WP_Filesystem`, `wp_json_encode()`, `gmdate()`, `wp_delete_file()`. Tested up to WordPress 7.0.

### 1.3.5

- Security: timing-safe token comparison, optional IP whitelisting, Cloudflare/reverse-proxy header support.

### 1.3.4

- **Branding Update:** New official icons and banners for WordPress.org.
- **Improved UX:** Updated documentation and installation guides.

### 1.3.3

- **Official WordPress.org Launch:** Now available in the official plugin repository.
- GitHub Release: [v1.3.3](https://github.com/Digitizers/SiteAgent/releases/tag/v1.3.3)

### 1.3.0

- Rebranded from "AuraWorker" to "Digitizer Site Worker for Aura"
- New slug: `digitizer-site-worker`

---

Built with ❤️ by [Digitizer](https://www.digitizer.studio) for the [Aura](https://my-aura.app) ecosystem
