# Future Integrations (Phase Two)

Phase one is deliberately **manual and admin-only**: data arrives by CSV import and nothing is published automatically. The architecture is built so that live platform connectors and, eventually, direct publishing can be added **without a core rewrite** — they plug into the same tables, the same approval queue and the same four extension hooks documented in [HOOKS.md](HOOKS.md).

> **Direct publishing and scheduling are NOT in phase one.** Every piece of AI-generated content lands in the approval queue and requires human approval. Phase-two publishing must preserve that: content is only ever published after a human approves it — never automatically.

## How a connector plugs in

Most connectors need only three things, all through documented hooks — no core edits:

1. **Ingest data** by registering an importer template via the `abcmd_import_templates` filter (or, for API-fed sources, mapping pulled records to the same canonical fields and writing metric snapshots). Data then flows into the shared `metrics` snapshots and is picked up automatically by the dashboard, anomaly detector, weekly audit and exports.
2. **Add analysis** by registering AI task types via the `abcmd_ai_task_types` filter, so the source's data can drive tailored audits/recommendations that land in the approval queue.
3. **Wire background work and settings** by hooking `abcmd_loaded` — register sync jobs (WP-Cron), OAuth/credential settings panels, and any additional hooks there.

Because ingest normalises to dated metric snapshots and analysis emits into the existing recommendation/task/content queue, a new source reuses all downstream machinery for free.

---

## Roadmap

| Integration | Direction | Plugs in via |
|---|---|---|
| **Shopify** | Direct-shop orders/revenue in; later order sync | `abcmd_import_templates` (a `shopify` template already ships for CSV); `abcmd_loaded` for an API sync job + store credentials. Feeds `sales`/`revenue`/`conversions` metrics. |
| **Google Analytics 4 (GA4)** | Traffic, conversions, audience in | `abcmd_import_templates` for report CSVs; `abcmd_loaded` for a GA4 Data API pull + property settings. Feeds audience/conversion metrics and enriches audit context. |
| **EmailOctopus** | Campaign performance in | `abcmd_import_templates` (an `emailoctopus` template already ships); `abcmd_loaded` for an API sync. Feeds `delivered`/`opens`/`open_rate`/`click_rate`/`unsubscribes`. |
| **Meta Ads** | Ad spend/results in | `abcmd_import_templates` (a `meta_ads` template already ships); `abcmd_loaded` for a Marketing API pull. Feeds `ad_spend`/`impressions`/`clicks`/`conversions`/`reach`; drives ad-efficiency anomaly rules. |
| **Amazon Ads** | Ad spend/results in | `abcmd_import_templates` (an `amazon_ads` template already ships); `abcmd_loaded` for an Amazon Advertising API pull. Feeds the same advertising metrics. |
| **Social-platform analytics** | Followers, reach, engagement in | `abcmd_import_templates` (a generic `generic_audience` template ships) or per-platform templates; `abcmd_loaded` for platform API syncs. Feeds `followers`/`reach`/`engagement`. |
| **Retailer / distributor feeds** | Sales & royalties in | `abcmd_import_templates` (templates for IngramSpark, Draft2Digital, ACX, InAudio, Spotify, Amazon already ship); `abcmd_loaded` for scheduled feed pulls. Honours the `delayed` source status the distributor-delay anomaly rule already understands. |
| **Direct publishing / scheduling** | Approved content **out** | New task types via `abcmd_ai_task_types` and publish adapters wired on `abcmd_loaded`. **Gated on approval — see below.** |

---

## Direct publishing — the phase-two guardrail

When outbound publishing is added, it must respect the phase-one contract:

- **Approval-gated:** only content with an approved `approval_status` may be published; the approval queue remains the single point of human sign-off.
- **No auto-post:** scheduling means "publish an approved item at a chosen time", not "generate and post without review".
- **Auditable:** every publish action should be written to the audit log like every other sensitive action.
- **Consent-aware:** any data sent to a third party as part of publishing remains subject to the same per-workspace consent model.

The `content` table already carries the fields such a feature needs (`publish_date`, `destination_link`, `tracking_link`, `export_status`, `approval_status`, version/edit history), and the AI task types that emit content already route through the approval queue — so publishing becomes an adapter on top of approved content rather than a new data path.

---

## Why this works without a core rewrite

- **One ingest shape.** All sources normalise to dated metric snapshots (`metrics`), so adding a source doesn't change how anything downstream reads data.
- **One output queue.** All AI analysis emits recommendations/tasks/content into the same approval queue with the same review workflow.
- **Four stable hooks.** `abcmd_import_templates`, `abcmd_ai_task_types`, `abcmd_web_search_tool` and `abcmd_loaded` are the intended, documented extension surface. New connectors ship as their own add-ons that use these hooks — the core stays untouched.
