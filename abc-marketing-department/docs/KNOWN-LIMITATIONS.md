# Known Limitations — Marketing Department 1.0.0 (phase one)

Phase one delivers a working, secure core. Where a feature could not be made
reliable without paid services or heavy dependencies, the plugin provides a
stable fallback rather than a decorative non-working control. The main limits:

## Platform integrations
- **No live API integrations yet.** Data enters via manual CSV import. Shopify,
  GA4, EmailOctopus, Meta Ads, Amazon Ads, social analytics, retailer/distributor
  feeds and direct publishing are phase-two work. The architecture exposes hooks
  (`abcmd_import_templates`, `abcmd_ai_task_types`, `abcmd_loaded`) so they can be
  added without a core rewrite. See `FUTURE-INTEGRATIONS.md`.
- **No automatic publishing.** By design, phase one never posts to any channel;
  all content requires human approval.

## PDF text extraction
- PDF extraction is **best-effort** using pure PHP (uncompressed + FlateDecode
  streams). Scanned PDFs, or PDFs using embedded/custom font encodings, may not
  extract. The UI reports this clearly and suggests uploading a DOCX/TXT or
  pasting passages. DOCX, EPUB, XLSX (via ZipArchive), TXT and CSV are reliable.
- XLSX extraction reads shared strings; numeric-only cells are not pulled into
  the text index (they are better handled via CSV import).

## OpenAI / web research
- Web research depends on the **selected model and current API supporting the
  `web_search` tool**. If the model/endpoint cannot perform live research, the
  interface says so rather than pretending; no citations are fabricated.
- Some reasoning models reject a `temperature` parameter. The client handles this
  automatically: if the API rejects an unsupported parameter (temperature/top_p/
  penalties), it drops that parameter, retries, and remembers the model's quirk so
  later runs omit it upfront. You can also leave the temperature setting blank.
- Structured outputs (recommendations/tasks/content) are parsed from a JSON block
  the model appends. If the model returns no parseable JSON, the raw response is
  stored and a parse-warning is logged; nothing is silently dropped.
- Token cost is computed from the usage the API returns and an **editable** price
  table. Prices are defaults and must be reviewed against your current OpenAI
  pricing — nothing is treated as permanent fact.

## Scheduling (WP-Cron)
- WP-Cron is **traffic-dependent**. The weekly audit and Monday email may run
  late on low-traffic sites. The plugin detects missed runs, shows the next/last
  run, and provides a manual "Run Now" button — but a real server cron is
  recommended (see `TROUBLESHOOTING.md`).

## Encryption / salts
- API keys are encrypted with a key **derived from your WordPress salts**. If you
  rotate the salts in `wp-config.php`, stored keys can no longer be decrypted and
  must be re-entered. This is intentional (no usable key is stored in the DB).

## Anomaly detection
- Detection is **rule-based** (not statistical/ML). It flags what changed and why
  it merits checking — never asserting fraud or certainty. Some rules need enough
  history (a couple of weeks of metrics) before they can fire.
- The fake-`$wpdb` test harness does not model SQL aggregates, so anomaly maths is
  validated on a real database via the WP-integration suite, not the standalone
  smoke test.

## Multisite
- Not supported/tested in phase one. Designed for standard single-site installs.

## Author/workspace purge id ambiguity
- On the Settings → Data purge tool, author ids and workspace ids share one
  target dropdown; choose the target that matches the selected scope.
