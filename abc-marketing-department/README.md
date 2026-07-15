# Marketing Department

An admin-only marketing operating system for a small book-marketing agency, built as a WordPress plugin. It manages authors and per-book client workspaces, collects sales and campaign data through manual CSV imports, runs OpenAI-assisted audits, and produces ranked recommendations, tasks, content drafts and an approval queue — all behind a single dedicated capability, with a full audit trail.

- **Plugin slug:** `abc-marketing-department`
- **Namespace:** `ABCMD` (PSR-4: `ABCMD\Foo\Bar` → `includes/Foo/Bar.php`)
- **Text domain:** `abc-marketing-department`
- **Version:** 1.0.0
- **Author:** ABC Book Marketing
- **Licence:** GPL-2.0-or-later
- **Requires:** PHP 8.1+, WordPress 6.5+ (tested to 7.0.1)

> This is an internal agency tool. It is **not** for author clients, and it never exposes client workspaces, manuscripts, API settings or campaign data on the public site. Every screen is gated behind the `manage_abc_marketing_department` capability.

---

## Phase-one features

- **Author records and per-book client workspaces**, with an author dashboard designed to surface oddities, not just totals.
- **Manual CSV imports** for IngramSpark, Draft2Digital, ACX, InAudio, Spotify, Amazon (sales and ads), Meta Ads, EmailOctopus, Shopify, plus generic sales / advertising / audience sources — with upload, flexible column mapping, saved mapping profiles, date/currency handling, de-duplication, rejected-row reports, rollback and manual adjustments.
- **Dated metric snapshots** — imports never overwrite prior figures; every value is stored against its report date.
- **OpenAI integration** using the current Responses API (`/v1/responses`), with a model selectable per task, a per-run data-sharing checklist, cost estimate plus actual-cost logging, editable model pricing, hourly rate limiting, and web research where the selected model supports it.
- **Client consent records** that block AI runs which would exceed the consent on file.
- **Weekly automated audit** (WP-Cron) producing ranked, evidence-labelled recommendations, draft tasks and proposed content in a single approval queue, plus a Monday summary email that contains no sensitive data.
- **Rule-based anomaly detection** with per-workspace thresholds.
- **File uploads** (DOCX, PDF, EPUB, TXT, CSV, XLSX, images) with local text extraction and indexing — source files never leave your server.
- **Exports:** CSV, iCalendar and copy-ready text.
- **A demo workspace, sample CSVs, a setup wizard, and an editable UK privacy / AI-processing template** (a starting point requiring legal review).

### Not in phase one

Phase one **never publishes anything automatically**. There is no posting to social networks, ad platforms or retailers. All AI output — recommendations, tasks and content — lands in an approval queue and requires human review. Direct publishing and scheduling, and live platform connectors (Shopify, GA4, EmailOctopus, Meta/Amazon Ads, social analytics, retailer feeds), are phase-two work; the architecture is built so they plug in without a core rewrite. See [`docs/FUTURE-INTEGRATIONS.md`](docs/FUTURE-INTEGRATIONS.md).

---

## Requirements

| Requirement | Minimum | Notes |
|---|---|---|
| PHP | 8.1 | Activation halts with a readable message on older PHP; it never fatals. |
| WordPress | 6.5 | Tested up to 7.0.1. |
| Encryption | libsodium **or** OpenSSL (AES-256-GCM) | Needed to store the OpenAI key securely. |
| `ext-zip` | recommended | For DOCX / EPUB / XLSX text extraction. TXT / CSV work without it. |
| `ext-mbstring` | optional | Falls back to byte-length token estimates if missing. |
| WP-Cron or real cron | recommended | A real server cron is recommended for reliable weekly audits. |

Full dependency and licence detail is in [`docs/DEPENDENCIES.md`](docs/DEPENDENCIES.md).

---

## Installation

1. **Upload the ZIP** via *Plugins → Add New → Upload Plugin*, or **extract** the folder to `wp-content/plugins/abc-marketing-department`.
2. **Activate.** On activation the plugin checks requirements, creates its custom tables, grants the capability to administrators, creates the protected upload directory, schedules the weekly audit, and seeds default options.
3. If PHP is too old, activation stops with a clear message rather than causing a fatal error.

## Quick start

1. After activation, follow the admin notice or open **Marketing Dept → Setup** (the setup wizard).
2. **Add your OpenAI API key** under Settings → OpenAI. The key is encrypted at rest immediately and is never shown again.
3. **Install the demo workspace** (optional) — a fictionalised author/book with seeded metrics — so you can explore imports, an AI run and the approval queue with realistic data.
4. Create your first real **author** and **book workspace**, record **client consent**, import a CSV, and run an AI task.

---

## How it works

- A single main file (`abc-marketing-department.php`) defines constants, engages the PSR-4 autoloader, and boots `ABCMD\Plugin` on `plugins_loaded`.
- `Plugin::boot()` wires the admin surface (menus, pages, `admin-post.php` handlers, assets), the cron schedule and handlers, and the permission-gated REST controller, then fires the `abcmd_loaded` action for extensions.
- Data lives in **14 custom tables** (`{$wpdb->prefix}abcmd_*`) accessed through a repository layer built on prepared queries. Reporting-critical numbers are real columns; flexible structures are JSON.
- CSV imports become **dated metric snapshots**. The weekly audit and anomaly detector read those snapshots; the AI runner assembles a consent-filtered data package, calls the Responses API, logs the run and cost, and files any structured output into the approval queue.
- The OpenAI key is **encrypted with a key derived from your WordPress salts** and never stored in the database.

For the full picture, read the architecture doc.

---

## Documentation

All detailed documentation lives in the [`docs/`](docs/) folder:

| Document | Contents |
|---|---|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Folder structure, boot flow, repository pattern, security model, extension points. |
| [DATABASE.md](docs/DATABASE.md) | Every custom table and column, dated-snapshot design, JSON columns, migrations. |
| [HOOKS.md](docs/HOOKS.md) | Extension actions/filters and admin-post action reference. |
| [CSV-IMPORT-GUIDE.md](docs/CSV-IMPORT-GUIDE.md) | Import templates, the upload→map→import flow, de-dup, rollback, worked example. |
| [PRIVACY-CONSENT.md](docs/PRIVACY-CONSENT.md) | Consent model, data-sharing checklist, privacy template, encryption warning. |
| [DEPENDENCIES.md](docs/DEPENDENCIES.md) | Dependency and licence list (no bundled third-party PHP libraries). |
| [FUTURE-INTEGRATIONS.md](docs/FUTURE-INTEGRATIONS.md) | Phase-two roadmap and how each connector plugs in via hooks. |
| [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common issues and fixes, real cron setup. |
| [BACKUP.md](docs/BACKUP.md) | What to back up and how uninstall protects data by default. |

---

## Security summary

- **One capability governs everything:** `manage_abc_marketing_department`, granted to administrators on activation. Every admin page, form handler and REST route checks it.
- **CSRF protection:** every state-changing form is nonce-verified.
- **Injection protection:** all database access goes through prepared statements and `wpdb` with explicit column formats.
- **Encryption at rest:** the OpenAI API key is encrypted with libsodium secretbox (OpenSSL AES-256-GCM fallback), using a key derived from your WordPress salts. The key is never stored. **Rotating your WordPress salts makes stored keys undecryptable** — you must re-enter the key.
- **Protected file storage:** uploads live in `wp-content/uploads/abcmd-secure/`, guarded by `.htaccess`, `web.config` and `index.php`, and are only ever served through capability-checked download handlers.
- **AI guardrails:** consent gating, per-run data-sharing enforcement, hourly rate limiting, and a per-run cost-confirmation threshold.
- **Audit trail:** sensitive actions are logged to a searchable audit table, with obvious secret keys scrubbed from context.
- **Data preserved by default:** deactivation only clears scheduled events; uninstall preserves all data unless you explicitly opt in to purge.

See [`docs/PRIVACY-CONSENT.md`](docs/PRIVACY-CONSENT.md) and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md#security-model) for detail.
