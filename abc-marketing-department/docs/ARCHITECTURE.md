# Architecture

This document describes how the Marketing Department plugin is structured, how a request boots, the repository pattern used for data access, the security model, and how phase-two integrations plug in without touching core.

---

## Folder structure

```
abc-marketing-department/
├── abc-marketing-department.php   Main file: constants, PHP guard, autoloader, activation/boot wiring
├── uninstall.php                  Data-preserving uninstall (purge only if opted in)
├── readme.txt                     WordPress.org-style readme
├── admin/assets/                  Admin CSS + JS
├── includes/                      All plugin PHP (PSR-4 root: ABCMD\ → includes/)
├── sample-data/                   13 sample CSVs, one per import template
└── tests/                         Standalone/functional test harness + unit tests
```

### `includes/` subdirectories

| Path | Responsibility |
|---|---|
| `includes/` (root) | `Autoloader`, `Plugin` (boot/service wiring), `Activator`, `Deactivator`, `Requirements`, `legacy-guard.php` (PHP < 8.1 fallback). |
| `AI/` | OpenAI Responses API client, cost maths, data-sharing checklist, task-type catalogue, prompt builder, and the `Runner` that orchestrates a run end-to-end. |
| `Admin/` | `Admin` (menu tree), `Assets`, `PostHandlers` (all `admin-post.php` handlers), `View` (render/flash helpers), and `Admin/Pages/*` (one class per screen). |
| `Anomaly/` | Rule-based `Detector` for per-workspace anomaly flags. |
| `Cron/` | `Scheduler` (custom weekly schedule, London-anchored timestamps) and `WeeklyAudit` (audit pipeline + Monday email). |
| `Database/` | `Schema` (all `CREATE TABLE` definitions) and `Migrator` (versioned migrations). |
| `Demo/` | `DemoData` (demo workspace installer + purge tools) and `PrivacyTemplate` (default UK privacy text). |
| `Export/` | `CsvExport`, `IcalExport`, `CopyReady`. |
| `Files/` | `Uploader` (validation + storage), `Storage` (protected directory + hardening), `Extractor` (local text extraction/chunking). |
| `Import/` | `Templates` (import template catalogue) and `CsvImporter` (parse → map → validate → de-dup → insert → rollback). |
| `Repository/` | `BaseRepository` plus one repository per entity table. |
| `Rest/` | `Controller` — permission-gated REST routes under `abcmd/v1`. |
| `Support/` | `Helpers`, `Options`, `Capabilities`, `Audit`, `Encryption`. |

---

## Request / boot flow

1. **Main file** (`abc-marketing-department.php`)
   - Bails if `ABSPATH` is undefined (no direct access).
   - Defines constants: `ABCMD_VERSION`, `ABCMD_DB_VERSION`, `ABCMD_PLUGIN_FILE/DIR/URL/BASENAME`, the capability `ABCMD_CAP`, option keys (`ABCMD_OPT_SETTINGS`, `ABCMD_OPT_OPENAI`, `ABCMD_OPT_PRICES`, `ABCMD_OPT_DB_VERSION`, `ABCMD_OPT_PRIVACY`), cron hook names (`ABCMD_CRON_WEEKLY_AUDIT`, `ABCMD_CRON_WEEKLY_EMAIL`), and `ABCMD_TIMEZONE` (`Europe/London`).
   - **PHP version gate:** if PHP < 8.1, it loads `includes/legacy-guard.php` (which contains no 8.1 syntax) and returns — so old interpreters never parse typed/namespaced code and fatal. The guard self-deactivates the plugin and shows an admin notice.

2. **Autoloader** (`includes/Autoloader.php`)
   - `\ABCMD\Autoloader::register()` registers an SPL autoloader mapping `\ABCMD\Sub\ClassName` to `includes/Sub/ClassName.php`. No Composer runtime is required.

3. **Activation / deactivation** are registered against `Activator::activate` and `Deactivator::deactivate`.
   - `Activator` runs the requirements gate (fatal checks block activation with a readable `wp_die`), then: runs migrations (`Migrator::run` → `Schema::create_all` via `dbDelta`), grants the capability to admins, ensures the protected upload directory, schedules cron, seeds default options, and stores an activation report transient for the admin notice.
   - `Deactivator` only unschedules cron events. **It never deletes data.**

4. **Boot on `plugins_loaded`** → `ABCMD\Plugin::instance()->boot()` (idempotent singleton):
   - Loads the text domain.
   - Registers `admin_init → maybe_migrate` so schema stays current after updates without reactivation.
   - **Admin only** (`is_admin()`): instantiates `Admin`, `Assets`, `PostHandlers` and registers `admin_notices`.
   - **Cron:** registers the custom schedule filter (`Scheduler::hooks`) and the two cron handlers (`WeeklyAudit::run_scheduled`, `WeeklyAudit::send_email`).
   - **REST:** on `rest_api_init`, registers `Rest\Controller` routes.
   - Fires `do_action( 'abcmd_loaded', $this )` — the phase-two integration entry point.

5. **Admin menu** (`Admin::register_menu`, on `admin_menu`) builds the top-level *Marketing Dept* menu and its subpages (Dashboard, Authors, Book Workspaces, Weekly Review, Recommendations, Tasks, Approval Queue, Content Calendar, Imports, Files, AI Runs, Research Log, Audit Log, Integrations, Settings, Help), plus a hidden Setup wizard. Every page requires `ABCMD_CAP`.

6. **Form submissions** post to `admin-post.php`. `PostHandlers::hooks()` registers one `admin_post_{action}` handler per action; each handler verifies the capability and nonce, sanitises input, performs the work, logs it, and redirects with a flash message.

---

## Repository pattern

Data access is centralised in `includes/Repository/`.

### `BaseRepository` (abstract)

Subclasses declare three things:

- `key()` — the bare table key (e.g. `'books'`), resolved to `{$wpdb->prefix}abcmd_books` via `Schema::table()`.
- `columns()` — a `column => wpdb format` map (`%s`, `%d`, `%f`). **Only listed columns are ever written** — unknown keys are silently dropped in `prepare_write()`.
- `json_columns()` — columns stored as JSON `longtext`.

`BaseRepository` then provides:

- `insert()`, `update()`, `find()`, `delete()`, `where()`, `count()`, `all()` — all using `$wpdb->insert/update/delete` with explicit formats, or `$wpdb->prepare()` for reads.
- Automatic `created_at` / `updated_at` timestamps (`timestamp_columns()` can be narrowed, e.g. `Metrics` and `Imports` keep only `created_at`).
- JSON handling: on write, non-string JSON columns are `wp_json_encode`d; on read, `decode_row()` decodes them to arrays and also exposes the raw string as `{col}_raw`.
- `where()` validates the order-by column against `[a-z0-9_]` and whitelists filter columns against the declared column set, so filters and ordering cannot inject SQL.

### Entity repositories

`Authors`, `Books`, `Metrics`, `Imports`, `AiRuns`, `Recommendations`, `Tasks`, `Content`, `Files`, `FileChunks`, `Consent`, `Anomalies`, `MappingProfiles` each extend `BaseRepository` and add table-specific query helpers. Examples:

- `Metrics` — `sum()`, `sum_by()`, `weekly_series()` (ISO week buckets), `latest_value()`, `last_date()`, `dedup_exists()`, `delete_by_import()`. Enforces the dated-snapshot model (never overwrites).
- `Imports` — `checksum_seen()` for whole-file de-duplication.
- `Consent` — `for_book()`, `ai_allowed()`, `category_allowed()` — the consent gates the AI runner consults.
- `Books` — `for_author()`, `options()`, and the static `thresholds()` (anomaly threshold defaults merged with per-workspace overrides).

The `audit_log` table is written through `Support\Audit` (a static helper) rather than a repository.

---

## Security model

### Capability

- One capability governs the whole plugin: **`manage_abc_marketing_department`** (`ABCMD_CAP`).
- Granted to the `administrator` role (and directly to the activating user) on activation by `Support\Capabilities::grant_to_admins()`.
- `Helpers::require_cap()` gates admin pages; `Helpers::can()` is the boolean check.

### Nonces (CSRF)

- Every `admin-post.php` handler calls `Helpers::verify_nonce( $action )`, which checks the capability **and** verifies the `_abcmd_nonce` field against the action name, dying with a 403 on failure.
- Download/export handlers verify their own nonces before streaming.

### Prepared queries

- All reads use `$wpdb->prepare()`; all writes use `$wpdb->insert/update/delete` with explicit format arrays. `BaseRepository` whitelists columns and sanitises order-by, so no user value reaches SQL unescaped.

### Escaping & sanitisation

- Handlers sanitise on input (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_key`, `sanitize_email`, `esc_url_raw`, `wp_kses_post` for rich text, numeric casts).
- Admin pages escape on output.

### Encryption

- `Support\Encryption` provides authenticated symmetric encryption for the OpenAI key.
- **Preferred backend:** libsodium `crypto_secretbox` (version tag `sv1`). **Fallback:** OpenSSL `aes-256-gcm` (tag `ov1`). Both are authenticated.
- The key is derived (HKDF via `hash_hkdf`, with a SHA-256 stretch fallback) from **WordPress salts** — `wp_salt('auth'|'secure_auth'|'logged_in'|'nonce')` plus a plugin context string. Salts live in `wp-config.php`, outside the database, and **the derived key is never stored**.
- **Consequence:** rotating any of those salts changes the key, so previously encrypted values become undecryptable and the API key must be re-entered. This is intentional and documented in Settings and the README.
- `decrypt()` returns `null` on any failure (bad tag, wrong salts, missing backend) rather than throwing.

### Protected file storage

- `Files\Storage` places uploads under `wp-content/uploads/abcmd-secure/` and, on creation, writes `.htaccess` (`Require all denied`), `web.config` (IIS deny) and `index.php` guards.
- `Files\Uploader` enforces an extension allow-list, validates real file types (magic-byte checks for ZIP-based Office/EPUB and the `%PDF` header), respects `wp_max_upload_size()`, stores under a collision-resistant safe name, and chmods `0640`.
- Files are only served through the capability- and nonce-checked `download_file` handler; deletes verify the path is inside the protected directory before unlinking.

### REST permission callbacks

- All routes under `abcmd/v1` (`/ping`, `/ai/estimate`, `/anomalies`) set `permission_callback => can()`, which returns `current_user_can( ABCMD_CAP )`. There are no public endpoints.

### AI rate limiting & cost control (`AI\Runner`)

For every interactive run the runner enforces, in order:

1. **Consent gate** — `Consent::ai_allowed()` must be true (default-deny).
2. **Data-sharing enforcement** — requested categories are filtered against consent (`DataSharing::enforce()`); blocked categories are dropped.
3. **Web-search permission** — requires both the setting and `web_research` consent.
4. **Rate limit** — interactive runs are capped per user per hour (`ai_rate_limit_per_hour`, default 60).
5. **Cost-confirmation threshold** — runs whose estimate exceeds `ai_cost_confirm_threshold` (default 1.0) must be re-submitted with confirmation.

Every run is persisted (success or failure) with token counts, estimated vs actual cost, and any error state, and is written to the audit log. Scheduled (cron) runs bypass the interactive rate limit and confirmation but still honour consent.

### Audit trail

- `Support\Audit::log()` appends to `abcmd_audit_log` with actor, action key, human summary, optional book/author ids, structured context and client IP.
- `scrub()` redacts obvious secret keys (`api_key`, `token`, `secret`, `password`, …) from context; summaries are stripped of tags and length-capped. Manuscripts, keys and tokens are never passed to the log.

---

## Extension points (phase two)

Four hooks form the phase-two integration surface. New connectors register through them **without editing core** (typically from a plugin or a `functions.php` that hooks `abcmd_loaded`). See [`HOOKS.md`](HOOKS.md) for signatures and examples, and [`FUTURE-INTEGRATIONS.md`](FUTURE-INTEGRATIONS.md) for the roadmap.

| Hook | Type | Purpose |
|---|---|---|
| `abcmd_loaded` | action | Fires after boot with the `Plugin` instance. Register sync jobs, settings panels and additional hooks here. |
| `abcmd_import_templates` | filter | Add or modify CSV import templates (canonical fields + header aliases). |
| `abcmd_ai_task_types` | filter | Add or modify AI task types (label, minimum data categories, web flag, emitted structures). |
| `abcmd_web_search_tool` | filter | Customise the web-search tool descriptor sent to the Responses API. |

Because imports flow into the shared `metrics` snapshots and AI runs into the shared approval queue, a new data source or task type reuses all downstream machinery (anomaly detection, weekly audit, exports, audit log) automatically.
