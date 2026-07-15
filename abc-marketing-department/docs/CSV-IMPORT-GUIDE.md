# CSV Import Guide

All performance data enters the plugin through **manual CSV imports**. Imports become **dated metric snapshots** in the `metrics` table — importing later data never overwrites earlier figures. This guide covers templates, the upload → map → import flow, source-status flags, de-duplication, rejected-row reports, rollback, saved mapping profiles, manual adjustments, and a worked example.

Implementation: [`includes/Import/CsvImporter.php`](../includes/Import/CsvImporter.php) and [`includes/Import/Templates.php`](../includes/Import/Templates.php). Screen: **Marketing Dept → Imports**.

---

## Templates

A template is deliberately flexible: it declares canonical target fields and **header-alias substrings** used to *suggest* a mapping. The operator always confirms the mapping, so templates keep working when a platform changes its export layout.

| Template key | Label | Default source |
|---|---|---|
| `ingramspark` | IngramSpark (print & ebook sales) | IngramSpark |
| `draft2digital` | Draft2Digital (ebook sales) | Draft2Digital |
| `acx` | ACX (audiobook) | ACX |
| `inaudio` | InAudio (audiobook distribution) | InAudio |
| `spotify` | Spotify (audiobook streams/sales) | Spotify |
| `amazon_sales` | Amazon sales report | Amazon |
| `amazon_ads` | Amazon Ads | Amazon Ads |
| `meta_ads` | Meta Ads | Meta Ads |
| `emailoctopus` | EmailOctopus (email campaigns) | EmailOctopus |
| `shopify` | Shopify (direct shop orders) | Shopify |
| `generic_sales` | Generic sales | Manual/Other |
| `generic_advertising` | Generic advertising | Manual/Other |
| `generic_audience` | Generic audience metrics | Manual/Other |

Each template maps CSV headers to **canonical fields**. Fields fall into two groups:

- **Dimension / identifier fields** (no metric produced): `date`, `identifier`, `format`, `territory`, `retailer`, `platform`, `channel`.
- **Metric fields** (each produces a metric row): `units`/`net_units` → `sales`, `revenue` → `revenue`, `net_income` → `contribution`, `ad_spend` → `ad_spend`, `impressions`, `clicks`, `conversions`, `reach`, `engagement`, `followers`, `list_size` → `mailing_list_size`, `delivered`, `opens`, `open_rate`, `click_rate`, `unsubscribes`, `reviews`, `avg_rating`, `rank` → `retailer_rank`.

`date` is **required** for every row. Only mapped metric fields with a usable numeric value create metric rows.

New sources can be added at runtime via the `abcmd_import_templates` filter — see [HOOKS.md](HOOKS.md).

---

## The upload → map → import flow

1. **Choose the workspace** and the **template** that best matches your file.
2. **Upload the CSV.** The parser handles a leading UTF-8 BOM and skips fully-empty lines.
3. **Confirm the column mapping.** The template suggests a mapping from your headers using its aliases (case-insensitive, matched by "contains"); you adjust any target that isn't right. Unmapped targets are simply skipped.
4. **Set the import options:**
   - **Source status** — `live` / `delayed` / `estimated` / `reconciled` / `manually_adjusted` (see below).
   - **Date format** — `auto`, or an explicit `ymd` / `dmy` / `mdy`, or any PHP date format. `auto` tries common explicit formats first (to avoid day/month ambiguity), then falls back to `strtotime`.
   - **Currency** — ISO code applied to currency metrics (default `GBP`).
   - Optional **format** and **retailer** value maps (normalise labels like `Kindle` → `ebook`).
5. **Import.** The plugin creates an `imports` ledger row, processes each data row, records tallies, and finalises the import. After a successful import, anomaly detection runs automatically for the workspace.

Every value is stored against **its report date**, so re-importing a later month adds rows rather than replacing earlier ones.

---

## Source-status flags

The source status is stored on every imported metric row (`metrics.source_status`) and on the import record, so downstream analysis knows how much to trust a figure.

| Flag | Meaning |
|---|---|
| `live` | Current, authoritative data. |
| `delayed` | Known to lag (e.g. some distributor feeds); the anomaly detector treats delay differently. |
| `estimated` | A provisional figure. |
| `reconciled` | Finalised/settled data. |
| `manually_adjusted` | Set by a manual adjustment rather than an import. |

---

## De-duplication

De-duplication happens at two levels.

### Whole-file checksum

The SHA-256 checksum of the uploaded file is stored on the import. Before importing, the plugin checks whether the **same checksum** has already been imported (completed) for the **same workspace**. If so, the import is refused with a clear message; re-submit with **"import anyway"** (`confirm_duplicate`) to proceed. In the result this is signalled by `duplicate = -1`.

### Row-level dedup key

For each metric a `dedup_key` is computed as a SHA-1 hash of:

```
book_id | template | metric_key | date | format | territory | source | channel | row-base
```

The **row-base** is the row's explicit `identifier` if one is mapped and present; otherwise a hash of the whole raw row. If a metric with that key already exists, it is counted as a duplicate and skipped rather than inserted. This lets you safely re-import an overlapping export without double-counting.

---

## Rejected-row reports

Rows are counted into four buckets on the import record: `rows_total`, `rows_imported`, `rows_rejected`, `rows_duplicate`. A row is **rejected** when:

- its date is missing or unparseable, or
- it has a valid date but **no mapped numeric value** produced any metric.

A row is counted as **duplicate** when all of its would-be metrics already exist. Rejected rows are stored (up to 500) in `imports.errors` with the source line number, a reason and the offending value, and shown back to the operator so the file can be corrected and re-imported.

---

## Rollback

Any completed import can be rolled back from the Imports screen. Rollback deletes **exactly** the metric rows created by that import (matched on `import_id`), marks the import `rolled_back`, and writes an audit entry. Because metrics carry their `import_id`, rollback is precise and does not touch data from other imports or manual adjustments.

---

## Saved mapping profiles

A confirmed mapping (template, target→header map, date format, currency, and optional value maps) can be saved as a **mapping profile** (`mapping_profiles` table) and reused for future imports of the same source, so you don't re-map a recurring monthly export each time.

---

## Manual adjustments

When a figure needs a correction outside a file import, use a **manual adjustment**. It inserts a single metric row with `source = "Manual adjustment"`, `source_status = manually_adjusted` and `is_adjustment = 1`, dated to the supplied date (or today), with an optional note stored in the row's `meta`. Adjustments are additive snapshots like any other metric and are audited (`metric.adjustment`).

---

## Sample data

Thirteen sample CSVs ship in [`sample-data/`](../sample-data/) — one per template — for testing mapping and imports:

`ingramspark-sales.csv`, `draft2digital-sales.csv`, `acx-audiobook.csv`, `inaudio-audiobook.csv`, `spotify-audiobook.csv`, `amazon-sales.csv`, `amazon-ads.csv`, `meta-ads.csv`, `emailoctopus-campaigns.csv`, `shopify-orders.csv`, `generic-sales.csv`, `generic-advertising.csv`, `generic-audience.csv`.

---

## Worked example — `sample-data/generic-sales.csv`

The file contains:

```csv
Date,ID,Format,Territory,Retailer,Units,Revenue,Net
2026-07-13,TXN-001,ebook,UK,Kobo,10,49.90,20.00
2026-07-13,TXN-002,paperback,UK,Waterstones,4,51.96,1.20
2026-07-13,TXN-003,audiobook,UK,Audible,3,51.00,15.00
```

**1. Pick template & upload.** Choose **Generic sales** (`generic_sales`) and upload the file.

**2. Confirm the suggested mapping.** The `generic_sales` template auto-maps by alias:

| CSV header | Canonical target | Produces |
|---|---|---|
| `Date` | `date` (required) | — (row date) |
| `ID` | `identifier` | — (dedup base) |
| `Format` | `format` | — (dimension) |
| `Territory` | `territory` | — (dimension) |
| `Retailer` | `retailer` | — (row source) |
| `Units` | `units` | metric `sales` |
| `Revenue` | `revenue` | metric `revenue` (currency) |
| `Net` | `net_income` | metric `contribution` (currency) |

**3. Set options.** Date format `auto` (the dates are `Y-m-d`), currency `GBP`, source status `live`.

**4. Import.** Each of the 3 rows maps three metric fields (`units`, `revenue`, `net_income`), so the import produces **9 metric rows** across `metric_date = 2026-07-13`:

| Retailer (source) | Format | `sales` | `revenue` | `contribution` |
|---|---|---|---|---|
| Kobo | ebook | 10 | 49.90 | 20.00 |
| Waterstones | paperback | 4 | 51.96 | 1.20 |
| Audible | audiobook | 3 | 51.00 | 15.00 |

Result tallies: `rows_total = 3`, `rows_imported = 3`, `rows_rejected = 0`, `rows_duplicate = 0`.

**5. What you can do next.** Those snapshots immediately feed the dashboard, the AI prompt builder (sales by format/territory, total revenue) and the anomaly detector. Re-uploading the exact same file is blocked as a whole-file duplicate unless you tick "import anyway"; even then, each row's `dedup_key` prevents the individual metrics from being counted twice. If you got the mapping wrong, **roll back** the import to remove exactly those 9 rows.
