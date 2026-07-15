# Staging Installation & Manual Test Checklist

Run through this on a **staging** WordPress site before using the plugin in
production.

## A. Install
- [ ] Upload `abc-marketing-department-<version>.zip` via **Plugins → Add New →
      Upload Plugin** (or extract to `wp-content/plugins/`).
- [ ] Verify the SHA-256 matches the `.sha256` file shipped alongside the ZIP.
- [ ] Activate. Confirm no fatal error and a success notice appears.
- [ ] On a PHP < 8.1 host, confirm activation is halted with a readable message
      (not a fatal).

## B. Activation results
- [ ] **Marketing Dept → Setup** opens and the environment check shows PHP, WP,
      database, encryption, cron and ZipArchive rows.
- [ ] In the database, confirm 14 `wp_abcmd_*` tables were created.
- [ ] Administrator role has the `manage_abc_marketing_department` capability
      (e.g. a non-admin cannot see the menu).

## C. Demo & dashboards
- [ ] Install the demo workspace from Setup or Book Workspaces.
- [ ] Book dashboard shows sales, format mix, contribution, budget remaining,
      reviews, audience, targets, campaign history.
- [ ] Author dashboard aggregates the book and lists "things to check".

## D. OpenAI
- [ ] Settings → OpenAI: save an API key; confirm it is **not** displayed after
      saving and the field shows a "saved" placeholder.
- [ ] Pick allowed models and a default model.
- [ ] On a workspace with consent recorded, run an AI task (e.g. Weekly review).
      Confirm the live cost estimate appears, the run completes, and
      recommendations/tasks/content land in the **Approval Queue**.
- [ ] Confirm the AI run is logged under **AI Runs** with tokens + actual cost.
- [ ] Remove/withhold consent and confirm AI runs are **blocked**.

## E. Imports
- [ ] Import `sample-data/generic-sales.csv` into a workspace; map columns;
      confirm rows imported and metrics updated.
- [ ] Re-import the same file; confirm the whole-file duplicate guard triggers,
      and that "import anyway" then reports rows as duplicates (no double count).
- [ ] Roll back an import; confirm its metric rows are removed.
- [ ] Add a manual adjustment; confirm it appears as a metric.

## F. Files
- [ ] Upload a DOCX and a TXT; confirm text extraction succeeds and chunk counts
      appear.
- [ ] Upload a scanned PDF; confirm a clear "could not extract" status (fallback,
      not a crash).
- [ ] Confirm files are stored under `wp-content/uploads/abcmd-secure/` and are
      **not** directly downloadable via URL (403/forbidden). Download works only
      via the in-admin nonced link.

## G. Workflow
- [ ] Approve a recommendation with "generate task"; confirm a draft task is
      created.
- [ ] Create two tasks with a dependency; confirm the dependent task cannot be
      marked in-progress/complete until the prerequisite is complete, and that an
      override requires a logged reason.

## H. Exports
- [ ] Export tasks/metrics/costs as CSV.
- [ ] Export the calendar as iCalendar and open it in a calendar app.
- [ ] Export copy-ready text for a content item.

## I. Scheduling
- [ ] Weekly Review shows next audit and next email times.
- [ ] Click **Run Now**; confirm the audit runs and updates "last run".
- [ ] Configure a real server cron (see `TROUBLESHOOTING.md`) if WP-Cron is
      unreliable.

## J. Audit & anomalies
- [ ] Audit Log records logins-sensitive actions (imports, AI runs, approvals,
      settings changes, exports) and is searchable/filterable.
- [ ] Run an anomaly scan on the demo workspace; review flags.

## K. Uninstall safety
- [ ] With "delete data on uninstall" **off** (default), deactivate then delete
      the plugin on a scratch site; confirm `wp_abcmd_*` tables and data remain.
- [ ] Only with the purge option **on** and confirmed does uninstall remove data.

## WordPress-integration test suite (optional, for developers)
```bash
# Configure a WP test DB, then:
export WP_TESTS_DIR=/path/to/wordpress-tests-lib
phpunit                       # runs tests/integration/*
# The no-WP suites always run anywhere:
php tests/run-tests.php && php tests/run-functional.php && php tests/smoke-render.php
```
