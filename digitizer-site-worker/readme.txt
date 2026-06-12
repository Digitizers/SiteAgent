=== SiteAgent for Aura ===
Contributors: benkalsky
Tags: wordpress management, remote updates, site monitoring, maintenance, dashboard
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to the Aura dashboard for remote monitoring, plugin & theme updates, and maintenance — all from one place.

== Description ==

**SiteAgent** is the bridge between your WordPress sites and the [Aura infrastructure dashboard](https://my-aura.app) — a unified control center for teams managing multiple WordPress sites alongside servers, CDN, and DNS.

Install this plugin on any WordPress site to unlock remote management capabilities directly from Aura — no SSH, no wp-admin juggling, no manual logins.

= What You Can Do =

* **Monitor site health** — See WordPress version, PHP version, installed plugins & themes, database info, and disk usage in real time.
* **Update plugins, themes & core remotely** — Push updates to any connected site from the Aura dashboard, no wp-admin login.
* **Safe batch updates with auto-rollback** — Run chunked updates with health checks; if an update breaks the site, the plugin restores the previous version automatically.
* **Per-plugin rollback** — Every update is zip-snapshotted first; restore any plugin to its last good state on demand.
* **Bulk translation & database upgrades** — Update all language packs and run WordPress database migrations remotely.
* **One-click connect (magic link)** — Connect a site to Aura straight from wp-admin — no manual token copy/paste.
* **AI-agent ready (MCP tools)** — Exposes machine-readable tools (site context, safe plugin update, asset cleanup, vulnerability checks) for AI-driven management.
* **Zero frontend impact** — The plugin only registers REST API endpoints. No scripts, no styles, no database queries on visitor-facing page loads.

= How It Works =

After activation, click **Connect to Aura** on the **Settings → SiteAgent** page for a one-click magic-link connection, or copy the Site Token shown once and paste it into your Aura dashboard manually. From that point, Aura communicates with your site over a signed, authenticated REST API to pull health data and push updates.

= Security =

Defence-in-depth protects every request:

1. **WordPress Application Password** — Standard WordPress auth with capability checks (`manage_options` / `update_*`). Only authorized administrators can trigger actions.
2. **Hashed Site Token** — A per-site token sent via the `X-Aura-Token` header. Only a SHA-256 **hash** is stored (never the raw token), compared timing-safely. Tokens from older versions migrate to a hash automatically.
3. **Brute-force throttling** — Repeated bad-token attempts from an IP are blocked.
4. **Signed magic-link connect** — The onboarding callback is HMAC-signed with a one-time secret and timestamp, so the token exchange can't be hijacked or replayed.
5. **IP / Domain allowlist** (optional) — Restrict API access to your Aura instance, with Cloudflare and reverse-proxy header support.

You can rotate the token anytime from **Settings → SiteAgent → Regenerate Token**.

= REST API Endpoints =

Core endpoints under `/wp-json/aura/v1/`:

* `GET /status` — Full site health report
* `GET /updates` — Check available updates (core, plugins, themes, translations)
* `POST /update/core` / `/update/plugin` / `/update/theme` / `/update/translations` — Apply updates
* `POST /update/database` — Run WordPress database upgrades
* `POST /connect` — Magic-link token exchange (public, HMAC-signed, 10-minute expiry)

Version 2 endpoints under `/wp-json/aura/v2/`:

* `GET /health` — HTTP, PHP fatal, white-screen and DB connectivity checks
* `POST /update/batch` — Chunked batch updates with auto-rollback on health failure
* `POST /rollback/{plugin}` — Restore a plugin from its most recent backup

MCP tools under `/wp-json/aura/mcp/`:

* `POST /tools/list` / `POST /tools/execute` — Enumerate and run AI-agent tools
* `GET /context` — Full site context for AI decision-making

= About Aura =

Aura is a full-stack operations dashboard by [Digitizer](https://digitizer.studio) that brings servers, applications, DNS zones, and CDN pull zones from Cloudways, Hostinger VPS, Cloudflare, and Bunny.net into a single unified interface.

SiteAgent extends that reach into every WordPress installation — so you can manage your entire infrastructure, including WordPress sites, from one place.

= Free to Use =

The plugin is completely free and open source (GPLv2+). You need a free or paid Aura account to connect your sites. [Sign up at my-aura.app](https://my-aura.app).

= Links =

* [Aura Dashboard](https://my-aura.app)
* [Documentation](https://my-aura.app/siteagent)
* [GitHub Repository](https://github.com/Digitizers/SiteAgent)
* [Digitizer](https://digitizer.studio)

== Installation ==

= Via WordPress Admin (Recommended) =

1. Go to **Plugins → Add New** in your WordPress admin.
2. Search for **SiteAgent**.
3. Click **Install Now**, then **Activate**.
4. Navigate to **Settings → SiteAgent**.
5. Click **Connect to Aura** for one-click magic-link onboarding — or copy the Site Token (shown once) and paste it into your Aura dashboard manually.

= Via WP-CLI =

`wp plugin install digitizer-site-worker --activate`

= Manual Upload =

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.
4. Navigate to **Settings → SiteAgent** to connect or get your Site Token.

== Frequently Asked Questions ==

= Do I need an Aura account? =

Yes, you need an Aura account to connect your WordPress sites. Aura offers a free tier that includes up to 3 WordPress sites. [Sign up at my-aura.app](https://my-aura.app).

= Is this plugin safe to use? =

Yes. The plugin uses defence-in-depth: WordPress Application Passwords (the same standard mechanism used by the block editor), a per-site token stored only as a SHA-256 hash and verified timing-safely, per-IP brute-force throttling, an HMAC-signed onboarding handshake, and an optional IP/domain allowlist. No data is transmitted unless a request is made by your Aura instance.

= Does it slow down my site? =

No. The plugin registers only REST API endpoints. It does not load any code, scripts, or database queries on frontend page loads. Your visitors experience zero impact.

= What WordPress versions are supported? =

WordPress 6.2 or higher is required. This is needed for full Application Password support. The plugin has been tested up to WordPress 7.0.

= What PHP versions are supported? =

PHP 7.4 or higher. PHP 8.0+ is recommended.

= Can I restrict which IP addresses can access the API? =

Yes. The plugin supports an optional IP whitelist. If configured, only requests from the specified IP addresses will be accepted. Cloudflare and reverse proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`) are fully supported for IP detection.

= Does this work with WordPress multisite? =

The plugin is designed for single WordPress installations. Multisite support is not currently available but is on the roadmap.

= Where is the Site Token stored? =

Only a SHA-256 **hash** of the Site Token is stored, in the WordPress option `aura_worker_site_token` — the raw token is never persisted. It is generated on first activation and shown once so you can copy it; the Aura dashboard keeps the only raw copy. Tokens created by older versions are upgraded to a hash automatically on first use.

= Can I regenerate the Site Token? =

Yes. Use **Regenerate Token** on the **Settings → SiteAgent** page. The new token is shown once. Regenerating invalidates the old token and disconnects the site from Aura until you reconnect with the new one.

= How do I disconnect a site from Aura? =

Simply deactivate or delete the plugin, or remove the site from your Aura dashboard. If you deactivate the plugin, the REST API endpoints are unregistered and Aura can no longer communicate with the site.

= Does Aura store my wp-admin credentials? =

No. Aura uses WordPress Application Passwords, not your main admin password. Application Passwords are scoped specifically for REST API access and can be revoked at any time from **Users → Your Profile** in wp-admin.

= Is the plugin open source? =

Yes. SiteAgent is open source under the GPLv2 or later license. The source code is available on [GitHub](https://github.com/Digitizers/SiteAgent).

== Screenshots ==

1. The SiteAgent settings page in WordPress admin (Settings → SiteAgent) showing the Site Token and connection status.
2. The Aura dashboard showing connected WordPress sites with health status, WordPress version, PHP version, and available updates.
3. Remote plugin update in progress from the Aura dashboard — select a plugin and update it with a single click.

== Changelog ==

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

== Upgrade Notice ==

= 2.0.1 =
Documentation update — corrected feature list, security description, endpoint reference, and admin menu location. No code changes.

= 2.0.0 =
Major update: plugin rollback/backup, site health checks, magic-link admin access, and MCP tools. Tested with WordPress 7.0. Recommended for all users.

= 1.3.5 =
Enhanced security with timing-safe comparison and IP whitelisting. Recommended for all users.
