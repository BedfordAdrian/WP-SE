# Backup & Data Preservation

The plugin stores agency data in its own database tables and keeps uploaded source files on disk. This document explains what to back up, what to capture before upgrades, and how the plugin's data-preservation defaults protect you.

---

## What to back up

There are two things to include in any backup of this plugin's data:

### 1. The custom database tables

All 14 tables are prefixed `{$wpdb->prefix}abcmd_` (e.g. `wp_abcmd_*`):

```
abcmd_authors        abcmd_books        abcmd_metrics        abcmd_imports
abcmd_ai_runs        abcmd_recommendations  abcmd_tasks      abcmd_content
abcmd_files          abcmd_file_chunks  abcmd_consent        abcmd_anomalies
abcmd_mapping_profiles                    abcmd_audit_log
```

A full-database backup (e.g. `mysqldump` of the whole WordPress DB, or your host's DB backup) captures all of these. The plugin's settings also live in `wp_options` under these keys, so include the options table:

```
abcmd_settings   abcmd_openai   abcmd_model_prices   abcmd_db_version
abcmd_privacy_template   abcmd_last_audit   abcmd_activated_at
```

> The `abcmd_openai` option stores the API key **encrypted with a key derived from your WordPress salts**. A DB backup restored onto a site with **different** salts will not be able to decrypt it — you'll need to re-enter the key. Keep your `wp-config.php` salts backed up alongside the database if you want the stored key to survive a restore. See [PRIVACY-CONSENT.md](PRIVACY-CONSENT.md#encryption--the-salts-warning).

### 2. The protected uploads directory

Uploaded source files (manuscripts, reviews, reports, assets) are stored on disk, **not** in the database. Back up:

```
wp-content/uploads/abcmd-secure/
```

The database `files` table stores each file's metadata and its `stored_path`; the actual bytes live in that directory. Backing up one without the other leaves you with orphaned records or orphaned files, so **always back up the tables and this directory together**. Note the directory is hardened against direct web access (`.htaccess` / `web.config` / `index.php`), which is expected — your backup tool reads it from the filesystem, not over HTTP.

A complete backup of this plugin therefore = **WordPress database** (tables + options) **+ `wp-content/uploads/abcmd-secure/`** (+ your `wp-config.php` salts if you want the encrypted key to restore cleanly).

---

## Before upgrading the plugin

Plugin updates are designed to be safe: schema changes are additive via `dbDelta`, and migrations run automatically (on activation and on the next admin page load when the DB version trails the plugin). Even so, before a plugin, WordPress or PHP upgrade:

1. **Back up the database and `abcmd-secure/`** as above.
2. Note the current versions — `ABCMD_VERSION` and the `abcmd_db_version` option — so you can confirm the migration ran (the plugin bumps `abcmd_db_version` to match after a successful migration).
3. After upgrading, visit any plugin admin page. If a migration was pending it runs then; any issues are shown as an admin notice and logged to the audit log (`migration.error`).

Because migrations are additive and version-gated, an upgrade does not delete or overwrite existing rows.

---

## How data preservation protects you

The plugin is deliberately conservative about destroying data:

- **Deactivation preserves everything.** Deactivating the plugin only clears its scheduled cron events (`Deactivator`). No tables, files or options are touched. Reactivate and your data is exactly as it was.
- **Uninstall preserves data by default.** Deleting the plugin runs `uninstall.php`, which **does nothing unless you explicitly opted in** by enabling the *purge on uninstall* option (Settings → General; `uninstall_purge`, default **off**). With purge off, tables, options and the `abcmd-secure/` directory are all left intact — so an accidental delete-and-reinstall does not lose agency data.
- **Purge is opt-in and only on delete.** Only when `uninstall_purge` is enabled **and** the plugin is deleted (not merely deactivated) does uninstall drop the tables, remove the options, revoke the capability and clear the protected uploads directory.
- **Manual purge is deliberate and audited.** The in-app purge tools (Settings → Data) require typing `PURGE` to confirm, act by explicit scope (workspace / author / file / AI run / all), and are recorded in the audit log. A full purge intentionally **retains the audit log** so the purge itself is traceable.

### Recovering data

If you need to recover, restore the database backup **and** the `abcmd-secure/` directory together, onto a site with the **same WordPress salts** (so the encrypted OpenAI key decrypts). If the salts differ, everything else restores normally — only the stored API key must be re-entered under Settings → OpenAI.
