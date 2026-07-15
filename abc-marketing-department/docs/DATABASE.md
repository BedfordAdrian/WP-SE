# Database

The plugin creates **14 custom tables**, all prefixed `{$wpdb->prefix}abcmd_` (for example `wp_abcmd_books`). Definitions live in [`includes/Database/Schema.php`](../includes/Database/Schema.php) and are applied with `dbDelta`.

## Design principles

- **Dated snapshots, never overwrites.** The `metrics` table stores one row per value per report date. Re-importing later periods adds rows; it never mutates earlier figures. Aggregation (weekly series, sums, sums-by-dimension) happens at read time.
- **Real columns for reporting-critical numbers; JSON for flexible structures.** Numeric/currency fields that feed reports and anomaly rules are typed columns with indexes. Variable or nested data (mappings, price maps, target sets, error lists, source citations, arbitrary context) is stored as JSON `longtext`. Repositories transparently encode/decode JSON columns.
- **`dbDelta`-compatible SQL.** Every `CREATE TABLE` follows dbDelta rules (two spaces after `PRIMARY KEY`, `KEY` not `INDEX`, one column per line) so schema updates are additive and idempotent.
- **Charset/collation** comes from `$wpdb->get_charset_collate()`.

## Migrations

- The installed schema version is stored in the option **`abcmd_db_version`** (`ABCMD_OPT_DB_VERSION`); the target is the constant `ABCMD_DB_VERSION` (currently `1.0.0`).
- [`includes/Database/Migrator.php`](../includes/Database/Migrator.php) runs on activation and on `admin_init` whenever the stored version trails the target (`Plugin::maybe_migrate`), so schema stays current after a plugin update **without reactivation**.
- `Migrator::run()`:
  1. Calls `Schema::create_all()` — `dbDelta` applies the current schema (additive: adds new tables/columns, widens columns).
  2. Runs any ordered, version-gated **non-additive** steps (data backfills, transforms). The `steps()` array is currently empty; the pattern is in place for future releases (e.g. `'1.1.0' => fn() => …`).
  3. Records `abcmd_db_version = ABCMD_DB_VERSION`.
  - Errors are collected and, if any occur, written to the audit log (`migration.error`).

---

## Tables

Common conventions: `id` is `bigint unsigned AUTO_INCREMENT PRIMARY KEY`; `created_at` / `updated_at` are `datetime` (UTC). Columns marked **JSON** are stored as `longtext` and decoded to arrays by the repository layer.

### `authors`

Author (client) records. One author has many book workspaces.

| Column | Type | Purpose |
|---|---|---|
| `name`, `pen_name` | varchar(200) | Legal name and pen name. |
| `email`, `website` | varchar(200/300) | Contact and site. |
| `biography` | longtext | Author bio. |
| `social_profiles` | longtext **JSON** | List of social handles/links. |
| `email_platform` | longtext **JSON** | Mailing-list provider + notes. |
| `notes` | longtext | Internal notes. |
| `consent_status` | varchar(30) | Rollup status (`unknown` default). |
| `status` | varchar(30) | `active` default. |
| `created_at`, `updated_at` | datetime | Timestamps. |
| Indexes | | `name(100)`, `status`. |

### `books`

Book client workspaces — the central record most other tables reference via `book_id`.

| Column | Type | Purpose |
|---|---|---|
| `author_id` | bigint | Owning author. |
| `title`, `subtitle`, `publisher` | varchar | Bibliographic detail. |
| `publication_date` | date | Publication date (nullable). |
| `genre`, `territory`, `audience` | varchar | Positioning. |
| `synopsis`, `proposition` | longtext | Marketing narrative. |
| `comparison_titles` | longtext **JSON** | Comp titles list. |
| `formats` | longtext **JSON** | Available formats. |
| `prices`, `net_income` | longtext **JSON** | Per-format price / net-income maps. |
| `distributors`, `retailer_links` | longtext **JSON** | Distribution + retailer links. |
| `universal_link` | varchar(300) | Books2Read-style universal link. |
| `campaign_start` | date | Campaign start (nullable). |
| `long_term_target` | longtext **JSON** | Long-term objective. |
| `targets_90day` | longtext **JSON** | 90-day target set. |
| `weekly_hours` | decimal(6,2) | Hours available per week. |
| `budget`, `budget_max` | decimal(12,2) | Initial and conditional-max budget. |
| `break_even` | longtext **JSON** | Break-even requirement (e.g. cost per sale). |
| `excluded_channels` | longtext **JSON** | Channels to avoid. |
| `currency` | varchar(3) | Reporting currency (`GBP` default). |
| `anomaly_thresholds` | longtext **JSON** | Per-workspace threshold overrides. |
| `status` | varchar(30) | `active` default. |
| Indexes | | `author_id`, `status`. |

### `metrics`

Dated metric snapshots — the analytical core. Populated by CSV imports, manual adjustments and demo seeding.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id` | bigint | Workspace/author. |
| `metric_date` | date | The report date this value belongs to. |
| `metric_key` | varchar(60) | e.g. `sales`, `revenue`, `contribution`, `ad_spend`, `impressions`, `clicks`, `conversions`, `open_rate`, `followers`, `reviews`, `avg_rating`. |
| `format` | varchar(40) | Book format dimension. |
| `territory` | varchar(60) | Territory/country dimension. |
| `channel` | varchar(80) | Campaign/channel dimension. |
| `platform` | varchar(80) | Platform dimension. |
| `value_num` | decimal(16,4) | Numeric value. |
| `value_text` | varchar(255) | Optional text value. |
| `currency` | varchar(3) | For currency metrics. |
| `source` | varchar(80) | Retailer/distributor or source label. |
| `source_status` | varchar(20) | `live` / `delayed` / `estimated` / `reconciled` / `manually_adjusted`. |
| `import_id` | bigint | Import that created the row (0 for manual/demo). Enables rollback. |
| `dedup_key` | varchar(191) | Row-level de-duplication hash. |
| `is_adjustment` | tinyint | 1 for manual adjustments. |
| `meta` | longtext **JSON** | Extra context (e.g. adjustment note). |
| `created_at` | datetime | Insert time. |
| Indexes | | `(book_id, metric_key, metric_date)`, `author_id`, `import_id`, `dedup_key`. |

### `imports`

One row per CSV import run — the ledger that makes rollback and whole-file de-duplication possible.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id` | bigint | Target workspace/author. |
| `source` | varchar(80) | Source label (from template). |
| `source_status` | varchar(20) | Status applied to imported rows. |
| `template` | varchar(80) | Import template key. |
| `original_filename` | varchar(255) | Uploaded file name. |
| `checksum` | varchar(64) | SHA-256 of the whole file (whole-file dup check). |
| `rows_total`, `rows_imported`, `rows_rejected`, `rows_duplicate` | int | Row tallies. |
| `mapping` | longtext **JSON** | Column mapping used. |
| `errors` | longtext **JSON** | Rejected-row report (capped at 500 entries). |
| `user_id` | bigint | Operator. |
| `audit_id` | bigint | Linked audit-log entry. |
| `status` | varchar(20) | `processing` → `completed` / `rolled_back`. |
| `created_at` | datetime | Insert time. |
| Indexes | | `book_id`, `checksum`, `source`. |

### `ai_runs`

One row per OpenAI run (successful or failed), for cost accounting and provenance.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id` | bigint | Workspace/author. |
| `run_type` | varchar(60) | Task slug (see AI task types). |
| `user_id` | bigint | Operator (0 for scheduled). |
| `model` | varchar(80) | Model actually used (as reported by the API). |
| `source_materials` | longtext **JSON** | File ids / materials referenced. |
| `data_sharing` | longtext **JSON** | Data-sharing categories actually sent (post-consent). |
| `prompt`, `response` | longtext | Assembled prompt and model output. |
| `web_search` | tinyint | Whether web search was used. |
| `sources` | longtext **JSON** | Web citations returned. |
| `input_tokens`, `output_tokens` | int | Token usage. |
| `est_cost`, `actual_cost` | decimal(12,6) | Estimated vs realised cost. |
| `currency` | varchar(3) | Cost currency (`USD` default). |
| `approval_status` | varchar(30) | `draft` / `awaiting_review` / `approved` / `rejected`. |
| `error_state` | varchar(255) | Error code + message on failure. |
| `created_at` | datetime | Insert time. |
| Indexes | | `book_id`, `run_type`, `created_at`. |

### `recommendations`

Ranked, evidence-labelled recommendations parsed from AI output into the approval queue.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id`, `ai_run_id` | bigint | Origin. |
| `title`, `description` | varchar/longtext | Recommendation text. |
| `rec_type` | varchar(60) | Recommendation category. |
| `evidence_label` | varchar(20) | `evidence-backed` / `inferred` / `experimental` (default `inferred`). |
| `confidence` | varchar(10) | `high` / `medium` / `low` (default `medium`). |
| `objective` | varchar(255) | Objective it serves. |
| `expected_cost` | decimal(12,2) | Expected spend. |
| `expected_time`, `expected_impact` | varchar(80/255) | Effort and impact. |
| `success_metric`, `stop_rule`, `scale_rule` | varchar(255) | How to judge, when to stop, when to scale. |
| `dependencies` | longtext **JSON** | Prerequisites. |
| `source_links` | longtext **JSON** | Supporting web sources. |
| `rank_score` | decimal(8,3) | Ranking score. |
| `status` | varchar(30) | `draft` / `awaiting_review` / `approved` / `rejected` / `scheduled` / `in_progress`. |
| `created_at`, `updated_at` | datetime | Timestamps. |
| Indexes | | `book_id`, `status`. |

### `tasks`

Draft and tracked marketing tasks, optionally generated from an approved recommendation.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id`, `recommendation_id` | bigint | Origin/links. |
| `title`, `owner` | varchar | Task and owner. |
| `priority` | varchar(20) | `high` / `medium` / `low`. |
| `due_date` | date | Deadline. |
| `effort` | varchar(80) | Effort estimate. |
| `budget`, `actual_cost` | decimal(12,2) | Planned vs actual. |
| `status` | varchar(30) | `draft` / `scheduled` / `in_progress` / `completed` / … |
| `dependencies` | longtext **JSON** | Prerequisite task ids (gated at save). |
| `recurring` | varchar(40) | Recurrence, if any. |
| `notes` | longtext | Notes. |
| `content_id` | bigint | Linked content item. |
| `completion_date` | date | Completion. |
| `override_reason` | varchar(255) | Reason a dependency gate was overridden. |
| `created_at`, `updated_at` | datetime | Timestamps. |
| Indexes | | `book_id`, `status`, `due_date`. |

### `content`

Proposed campaign content (copy, captions, scripts, design direction) for approval.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id` | bigint | Workspace/author. |
| `campaign`, `channel`, `format` | varchar | Placement. |
| `title` | varchar(300) | Content title. |
| `copy`, `caption`, `script`, `design_direction`, `image_prompt` | longtext | Content bodies and creative direction. |
| `hashtags` | varchar(500) | Hashtags. |
| `cta` | varchar(255) | Call to action. |
| `destination_link`, `tracking_link` | varchar(300) | Links. |
| `publish_date` | datetime | Intended publish date (nullable; never auto-published in phase one). |
| `objective` | varchar(255) | Goal. |
| `approval_status` | varchar(30) | `draft` / `awaiting_review` / `approved` / `rejected`. |
| `export_status` | varchar(30) | `not_exported` default. |
| `version_history`, `edit_history` | longtext **JSON** | Change tracking. |
| `ai_run_id` | bigint | Originating run. |
| `created_at`, `updated_at` | datetime | Timestamps. |
| Indexes | | `book_id`, `approval_status`, `publish_date`. |

### `files`

Metadata for uploaded source documents (bytes live in protected storage, not the DB).

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id` | bigint | Workspace/author. |
| `original_name`, `safe_name` | varchar(255) | Client name and stored safe name. |
| `stored_path` | varchar(500) | Absolute path under `abcmd-secure/`. |
| `mime_type` | varchar(100) | Detected MIME type. |
| `size_bytes` | bigint | File size. |
| `document_type` | varchar(60) | `manuscript` / `reviews` / `press` / `report` / `asset` / `other`. |
| `extract_status` | varchar(30) | `pending` / `extracted` / `not_applicable` / `error`. |
| `extract_error` | varchar(500) | Extraction message/warning. |
| `chunk_count` | int | Number of text chunks. |
| `extracted_text` | longtext | Full extracted text. |
| `summary` | longtext | Optional summary. |
| `consent_class` | varchar(40) | `unclassified` / `non_sensitive` / `contains_pii` / `manuscript`. |
| `checksum` | varchar(64) | SHA-256 of stored bytes. |
| `user_id` | bigint | Uploader. |
| `created_at` | datetime | Upload time. |
| Indexes | | `book_id`, `document_type`. |

### `file_chunks`

Paragraph-aware text chunks of an extracted file (for supplying manuscript text to the model within token limits).

| Column | Type | Purpose |
|---|---|---|
| `file_id`, `book_id` | bigint | Parent file/workspace. |
| `chunk_index` | int | Order within the file. |
| `reference` | varchar(120) | Human label (e.g. `part 1`). |
| `content` | longtext | Chunk text (~1500 chars). |
| `token_estimate` | int | Rough token count. |
| `created_at` | datetime | Insert time. |
| Indexes | | `file_id`, `book_id`. |

### `consent`

Per-workspace client consent — the gate the AI runner consults. One current record per book.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id` | bigint | Workspace/author. |
| `allow_openai` | tinyint | Master AI switch (default 0 = deny). |
| `data_categories` | longtext **JSON** | Explicitly consented category slugs. |
| `allow_manuscript` | tinyint | Manuscript content may be sent. |
| `allow_sales` | tinyint | Sales/advertising/email/budget data may be sent. |
| `allow_personal` | tinyint | Personal/author-profile data may be sent. |
| `allow_web_research` | tinyint | Web research permitted. |
| `granted_date`, `revoked_date` | date | Grant/revocation dates. |
| `method` | varchar(120) | How consent was obtained. |
| `notes` | longtext | Notes. |
| `status` | varchar(20) | `none` / `active` (default `none`). |
| `updated_by` | bigint | Who last edited. |
| `created_at`, `updated_at` | datetime | Timestamps. |
| Indexes | | `book_id`. |

### `anomalies`

Rule-based anomaly flags produced by the detector.

| Column | Type | Purpose |
|---|---|---|
| `book_id`, `author_id` | bigint | Workspace/author. |
| `rule` | varchar(80) | Rule key (e.g. `sales_collapse`, `missing_report`, `cost_per_sale`). |
| `severity` | varchar(20) | `info` / `warning` (default `info`). |
| `title`, `detail` | varchar/longtext | Human explanation. |
| `metric_key` | varchar(60) | Related metric. |
| `dedup_key` | varchar(191) | Prevents duplicate flags within a period. |
| `status` | varchar(20) | `open` default. |
| `detected_at` | datetime | Detection time. |
| Indexes | | `book_id`, `status`, `dedup_key`. |

### `mapping_profiles`

Saved CSV column-mapping profiles for reuse across imports.

| Column | Type | Purpose |
|---|---|---|
| `name` | varchar(200) | Profile name. |
| `template` | varchar(80) | Import template it applies to. |
| `mapping` | longtext **JSON** | Target → header map. |
| `date_format` | varchar(40) | Chosen date format. |
| `currency` | varchar(3) | Currency. |
| `format_map`, `retailer_map` | longtext **JSON** | Value normalisation maps. |
| `user_id` | bigint | Creator. |
| `created_at`, `updated_at` | datetime | Timestamps. |
| Indexes | | `template`. |

### `audit_log`

Append-only audit trail of sensitive actions. Written via `Support\Audit`; secret-looking context keys are scrubbed before storage.

| Column | Type | Purpose |
|---|---|---|
| `created_at` | datetime | When. |
| `user_id`, `user_login` | bigint / varchar(120) | Actor (`system` for cron). |
| `action` | varchar(100) | Machine key (e.g. `import.create`, `ai.run`, `consent.update`). |
| `summary` | varchar(500) | Human description (no secrets). |
| `book_id`, `author_id` | bigint | Optional links. |
| `context` | longtext **JSON** | Structured context (scrubbed). |
| `ip` | varchar(45) | Client IP. |
| Indexes | | `action`, `book_id`, `created_at`. |

---

## Enumeration & teardown

`Schema::keys()` returns all 14 bare table keys, used by the purge tools (`Demo\DemoData::purge`) and by `uninstall.php` when — and only when — the purge option is enabled. `Schema::table( $key )` resolves a bare key to a fully-qualified table name and is the single source of truth for table naming across the codebase.
