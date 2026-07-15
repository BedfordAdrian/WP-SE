# Troubleshooting

Common issues and how to fix them. AI errors surface with a specific error code (shown in the run result and stored in `ai_runs.error_state`); the sections below are grouped by symptom.

---

## AI runs

### "No valid OpenAI API key is configured (or it could not be decrypted)" — code `no_key`

Either no key has been saved, or the stored key can no longer be decrypted.

- **No key saved:** add it under **Settings → OpenAI**. It is encrypted immediately and never shown again.
- **Decrypt failed after salts changed:** the encryption key is derived from your WordPress salts (`AUTH_KEY` etc. in `wp-config.php`). If those salts were rotated, the stored key can't be decrypted. **Re-enter the API key** under Settings → OpenAI. See [PRIVACY-CONSENT.md](PRIVACY-CONSENT.md#encryption--the-salts-warning). Also check the requirements screen shows an encryption backend (libsodium or OpenSSL) is available — without one, keys can't be stored at all.

### "Invalid API key" — code `invalid_key` (HTTP 401)

The key was rejected by OpenAI. Confirm you pasted the full key, that it is active in your OpenAI account, and that the correct **organization**/**project** headers are set (or cleared) under Settings → OpenAI.

### "Rate limit reached" — code `rate_limit`

Two different limits produce this:

- **Plugin hourly limit:** interactive runs are capped per user per hour (`ai_rate_limit_per_hour`, default 60). Wait, or raise the limit under **Settings → General**.
- **OpenAI HTTP 429:** OpenAI is rate-limiting or you are out of quota/credit. The client already retries transient 429/5xx with backoff; if it persists, check your OpenAI usage limits and billing, then try again later.

### "Model does not exist / not found" — code `model_unavailable` (HTTP 404)

The selected model id isn't available to your account. Pick a different model for the task, and make sure the model is in **Settings → OpenAI → allowed models**. The default model is `gpt-5.4`. If OpenAI has renamed models, update the allowed-models list (and the pricing table).

### Web search fails — code `web_search_unsupported`

The selected model doesn't support the hosted `web_search` tool, or the tool was rejected. Turn web search off for that run, or choose a model that supports it. Web search also requires `allow_web_research` consent on the workspace **and** the global setting enabled — otherwise it is silently skipped (not an error).

### "AI is not permitted for this workspace" — code `no_consent`

There is no active consent record with `allow_openai` enabled. Open the workspace's **Consent** tab, record consent (default is deny), and tick the data categories the client has agreed to. See [PRIVACY-CONSENT.md](PRIVACY-CONSENT.md).

### "Estimated cost exceeds the confirmation threshold" — code `needs_confirm`

The pre-run estimate is above `ai_cost_confirm_threshold` (default 1.0). Re-submit with the confirmation box ticked, or lower the scope (fewer data categories / smaller model) — or raise the threshold under Settings → General.

### Other AI errors

- **`transport`** — a network/TLS/DNS/timeout failure reaching OpenAI. Check outbound HTTPS from the server and the configured timeout (default 60s).
- **`malformed` / `empty`** — the API returned non-JSON or no readable text. Usage is still logged for cost. Retry; if persistent, try a different model.
- **"AI output had no parseable JSON block"** — the run succeeded and the raw response is saved, but no structured recommendations/tasks/content could be parsed. This is logged as `ai.parse_warning`; you can still read the raw output on the run and act manually.

---

## Imports

### "This exact file has already been imported"

Whole-file de-duplication matched the file's checksum for this workspace. If you genuinely need to re-import, re-submit with **"import anyway"**. Note that row-level de-duplication still prevents individual metrics from being counted twice.

### Rows rejected

A row is rejected when its **date is missing/unparseable** or it has a valid date but **no mapped numeric value**. Check the rejected-row report on the import (line number + reason), then:

- Fix the **date format** option (try an explicit `dmy`/`mdy`/`ymd` instead of `auto` if dates are ambiguous), and
- Confirm the **column mapping** actually maps at least one numeric target (units/revenue/etc.).

### Nothing imported / wrong numbers

Re-check the mapping and currency, and use **rollback** to remove a bad import cleanly (it deletes exactly that import's metric rows), then re-import. See [CSV-IMPORT-GUIDE.md](CSV-IMPORT-GUIDE.md).

---

## File extraction

### Extraction status `error` — especially scanned PDFs

PDF text extraction is best-effort and pure-PHP. **Scanned PDFs and PDFs with embedded font encodings often can't be read** (there is no OCR). The status message will say so. Workarounds:

- Upload a **DOCX or TXT** version instead, or
- Paste the relevant **passages** directly into the AI run.

### DOCX / EPUB / XLSX won't extract

These formats need the **`ZipArchive`** (`ext-zip`) extension. If the requirements screen shows it missing, install/enable `ext-zip`. TXT and CSV extraction work without it.

### Images not extracted

JPG/PNG/WEBP are stored but not text-extracted (status `not_applicable`) — this is expected; there is no OCR.

### Upload rejected

The uploader enforces an extension allow-list, a real-type check (ZIP magic bytes for Office/EPUB, `%PDF` header for PDF) and the server's max upload size. A "does not look like a valid …" message means the file's contents don't match its extension; a size message means it exceeds `wp_max_upload_size()` (raise `upload_max_filesize`/`post_max_size` in PHP if needed).

---

## Cron (weekly audit & email)

The weekly audit runs Monday 00:00 and the summary email Monday 09:00 **Europe/London** by default (configurable under Settings → General). WP-Cron is **traffic-dependent** — it only fires when the site is visited.

### The audit didn't run / the email didn't arrive

- Check **Settings → General** that the weekly audit is enabled and the weekday/hours are as expected. The plugin detects an overdue run (more than 2 hours late).
- Confirm cron isn't disabled unexpectedly, or configure a **real server cron** (recommended for reliable weekly runs).

### Recommended: real server cron

Disable WordPress's traffic-triggered pseudo-cron and drive it from the OS. In `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

Then add a system crontab entry that hits `wp-cron.php` every 15 minutes:

```cron
*/15 * * * * curl -s https://YOURSITE/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

(Replace `YOURSITE` with your domain.) This guarantees scheduled events run on time regardless of site traffic. The requirements/Help screens note this too.

---

## Email not sending

The Monday summary uses WordPress `wp_mail`. If it isn't arriving:

- Set explicit recipients under **Settings → General** (empty falls back to the site admin email); invalid addresses are dropped.
- WordPress mail on many hosts needs an SMTP plugin/relay to deliver reliably — configure one if `wp_mail` is failing silently.
- Check the audit log: sends are recorded as `cron.weekly_email` with a success flag, so you can confirm whether the plugin attempted a send and whether `wp_mail` reported success.
- The email intentionally contains **no manuscript or sensitive client data** — only counts and workspace names — so it is safe to route through standard mail.

---

## Activation

### Activation halted on old PHP

The plugin requires **PHP 8.1+**. On older PHP it self-deactivates and shows: *"Marketing Department requires PHP 8.1 or newer."* Upgrade PHP and reactivate. Because the version is checked before the namespaced/typed code loads, this never causes a fatal parse error.

### "Marketing Department cannot activate" (requirements)

Activation runs a requirements gate. Fatal blockers are **PHP version** and **WordPress version**; the `wp_die` screen lists exactly what failed. Non-fatal warnings (database probe, encryption backend, WP-Cron, ZipArchive, mbstring) don't block activation but are shown on the requirements screen so you can address them.

### Database setup reported issues

If `dbDelta` reported problems, an admin notice shows them and they're written to the audit log (`migration.error`). Usually this means the DB user lacks `CREATE`/`ALTER` privileges — grant them and reactivate (or revisit any admin page, which re-runs migrations when the DB version trails the plugin).
