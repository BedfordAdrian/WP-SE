# Rollback & Recovery

Marketing Department is designed so that removing or downgrading the plugin does
**not** destroy your agency data.

## Key safety guarantees
- **Deactivation** only clears scheduled cron events. It never deletes data.
- **Uninstall/delete** preserves all data **unless** you explicitly enabled
  "Delete all data on uninstall" (Settings → General) *and* the option is on at
  delete time. Default is off.
- All data lives in `wp_abcmd_*` tables and in
  `wp-content/uploads/abcmd-secure/`; standard WordPress/database backups capture
  it.

## Rolling back a plugin upgrade
1. **Back up first**: export a database dump and copy
   `wp-content/uploads/abcmd-secure/`. (Optionally use the in-app CSV exports.)
2. Deactivate the plugin (data is retained).
3. Replace the plugin folder `wp-content/plugins/abc-marketing-department/` with
   the previous version's files (or upload the previous ZIP).
4. Reactivate. The versioned migrator only ever adds/updates schema; it does not
   drop columns, so older code continues to read the existing tables. If you must
   downgrade the database, restore the database backup from step 1.

## Rolling back a bad import
- Use **Imports → Roll back** on the specific import. This deletes exactly the
  metric rows that import created (tracked by `import_id`) and marks it
  rolled-back. Other data is untouched.

## Rolling back an AI run's outputs
- AI-generated recommendations/tasks/content enter the Approval Queue as
  *awaiting review*. Reject or delete them there; nothing was applied
  automatically. Delete the AI run itself from **AI Runs** if desired.

## Recovering from a lost/rotated encryption key
- If WordPress salts changed, the stored OpenAI key can no longer be decrypted.
  Nothing else is affected. Re-enter the key in Settings → OpenAI. Consider
  restoring the previous salts from a `wp-config.php` backup if you need the old
  key back.

## Full removal (deliberate)
1. Export anything you want to keep (CSV exports, DB dump, the secure uploads
   folder).
2. Settings → General: enable "Delete all data on uninstall" only if you truly
   want the data gone.
3. Deactivate, then **Delete** the plugin. With the option on and confirmed,
   `uninstall.php` drops the `wp_abcmd_*` tables, removes options and the secure
   uploads directory, and revokes the capability. With the option off, all of
   that is preserved.

## Emergency: plugin causes a white screen
- Rename `wp-content/plugins/abc-marketing-department/` via SFTP/hosting file
  manager to force-deactivate. Your data remains in the database. Then restore a
  known-good plugin version and rename back.
