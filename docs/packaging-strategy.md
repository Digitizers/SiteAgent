# SiteAgent Packaging Strategy

_Last updated: 2026-04-29_

## Decision

SiteAgent should stay a genuinely useful free WordPress.org plugin and monetize through Aura SaaS-backed Pro capabilities.

The plugin is the acquisition wedge. Aura is the control plane, billing layer, safety layer, and multi-site workflow layer.

## Product Positioning

**SiteAgent for Aura** is the WordPress-side agent that connects a site to Aura.

- WordPress.org users should understand the plugin is free, open source, and useful with a free Aura account.
- Paid value should come from SaaS-backed automation, fleet management, safety, monitoring, and support.
- Avoid making the plugin feel hostile or crippled; gate orchestration and scale in Aura, not by breaking the plugin experience.

## Packaging Matrix

| Capability | Free / WordPress.org | Aura Pro |
| --- | --- | --- |
| Site connection to Aura | Yes | Yes |
| Manual site status / inventory | Yes | Yes |
| Plugin/theme/core update visibility | Yes | Yes |
| Single-site manual plugin/theme updates | Yes, via Aura dashboard | Yes |
| Translations/database maintenance | Yes | Yes |
| SiteAgent self-update stable channel | Yes | Yes |
| Beta channel opt-in | Limited / manual | Yes, controlled in Aura |
| Multi-site fleet dashboard | Limited summary | Yes |
| Bulk/fleet plugin/theme rollout | No | Yes |
| Safe rollout eligibility checks | Basic | Yes: backup, vuln, connection, policy |
| Scheduled SiteAgent rollout | No | Yes |
| Pre-update backup guard | No | Yes |
| Rollback-aware update history | No | Yes |
| v2 batch update engine | Beta/manual | Yes, when production-ready |
| MCP / AI-agent tools | Beta/manual | Yes, gated by Aura policy |
| Vulnerability intelligence | Basic outdated-plugin signal | Yes, WPScan/Aura-backed insights |
| Alerts and reports | No | Yes |
| Priority support / agency workflows | No | Yes |

## Channel Policy

### Stable

Stable is the WordPress.org-safe, production-default path.

- Keep slug: `digitizer-site-worker` for backward compatibility.
- Keep the plugin name as `SiteAgent for Aura`.
- Stable should favor conservative endpoints and predictable behavior.
- Production Aura sites default to stable.

### Beta

Beta is the controlled rollout path for v2 functionality.

- Distributed via GitHub releases while the feature set is still settling.
- Aura can opt a site into beta through `siteAgentChannel=beta`.
- Beta should be explicit in UI and logs.
- Beta may include MCP tools, batch update engine, rollback, and magic-link onboarding before WordPress.org stable promotion.

## Current Implementation Mapping

Already present in SiteAgent/Aura:

- SiteAgent release-channel awareness in Aura.
- Per-site rollout control center.
- Customer-facing fleet rollout in `apps/app`.
- Backup guard before fleet rollout.
- `daily-siteagent-rollout` Vercel cron.
- SiteAgent v2 beta features in the plugin repo:
  - health checks
  - batch plugin update
  - plugin rollback
  - MCP tools
  - magic-link onboarding

## Guardrails

1. **Do not move customer controls into internal admin.** Customer-facing rollout/policy lives in Aura App.
2. **Do not auto-update WordPress core in safe fleet rollout.** Core remains manual until a separate safety design exists.
3. **Do not promote v2 beta to WordPress.org stable until the packaging and WP.org copy are aligned.**
4. **Do not rename the WordPress.org slug casually.** Slug migration is a separate launch/support decision.
5. **Keep free honest.** If a feature requires paid Aura, label it clearly in Aura and docs.

## Immediate Follow-ups

1. Align website/pricing copy with this matrix (`AUR-8`, `AUR-10`).
2. Add Aura entitlement checks for Pro-only fleet/scheduled features if not already enforced server-side.
3. Review WordPress.org `readme.txt` before the next stable release so free vs Pro claims are accurate.
4. Decide whether v2.0.0 stays beta or becomes the next WordPress.org stable release.
5. Add support docs for stable vs beta rollout behavior.
