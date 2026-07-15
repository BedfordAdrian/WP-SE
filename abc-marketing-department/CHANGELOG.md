# Changelog

All notable changes to Marketing Department are documented here. This project
adheres to semantic versioning.

## [1.0.0] — 2026-07-15

Initial phase-one release.

### Added
- **Plugin core**: installable WordPress plugin (`abc-marketing-department`),
  PHP 8.1+ / WP 6.5+ (tested to 7.0.1), PSR-4 autoloader, dedicated capability
  `manage_abc_marketing_department`, admin-only surface.
- **Activation safety**: requirement checks (PHP, WP, database, libsodium/OpenSSL,
  cron, ZipArchive, mbstring); graceful halt with a clear message on unmet
  requirements instead of a fatal error.
- **Data model**: 14 custom tables via `dbDelta` with a versioned migrator —
  authors, book workspaces, dated metric snapshots, imports, AI runs,
  recommendations, tasks, content drafts, files, file chunks, consent records,
  anomalies, mapping profiles and a searchable audit log.
- **Book workspaces & author dashboards**: each book is a client workspace;
  books roll up into an aggregated author dashboard designed to surface oddities.
- **CSV imports**: flexible upload → column-mapping → import flow with saved
  mapping profiles, date/currency/format/retailer mapping, row-level and
  whole-file de-duplication, rejected-row reports, rollback and manual
  adjustments. Templates for IngramSpark, Draft2Digital, ACX, InAudio, Spotify,
  Amazon sales/ads, Meta Ads, EmailOctopus, Shopify and generic sources, plus
  source-status flags (live/delayed/estimated/reconciled/manually adjusted).
- **OpenAI integration**: current Responses API (`/v1/responses`), selectable
  model per task, optional web research, per-run data-sharing checklist, cost
  estimate + actual-cost logging, editable model pricing, rate limiting and
  classified error handling.
- **Consent**: per-workspace consent records; AI runs blocked by default and
  gated by consent categories; revocable.
- **Weekly automation**: WP-Cron weekly audit (Mon 00:00 Europe/London) and
  Monday 09:00 summary email (no sensitive data), missed-run detection and a
  manual "Run Now" button.
- **Ranked recommendations, tasks, content drafts** flowing into a single
  approval queue; recommendations labelled Evidence-backed / Inferred /
  Experimental with a confidence level. Nothing is published automatically.
- **Rule-based anomaly detection** with per-workspace thresholds.
- **Files**: secure uploads (DOCX, PDF, EPUB, TXT, CSV, XLSX, images) with local
  text extraction and indexing — files never leave the server.
- **Exports**: CSV, iCalendar and copy-ready text.
- **Security**: capability checks, nonces, sanitisation, escaping, prepared
  queries, protected file storage, REST permission callbacks, and API-key
  encryption at rest (libsodium/OpenSSL, key derived from WP salts).
- **Extras**: demo workspace (Mo Fanning / *Lisa Doyle is Absolutely Fine*),
  sample CSVs, setup wizard, editable UK privacy template, and documentation.

### Security
- API keys and tokens encrypted at rest; never displayed after saving; never
  logged. Data preserved on deactivation and (by default) on uninstall.
