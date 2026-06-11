<p align="center">
  <img src="assets/aura_icon.png" alt="Aura" width="160" />
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
  <img src="https://img.shields.io/badge/Stable-2.0.0-green" alt="Stable" />
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

Built-in MCP tools: `get_site_context`, `update_plugin_safely`, `cleanup_orphaned_assets`, `check_vulnerabilities`.

---

## Changelog

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
