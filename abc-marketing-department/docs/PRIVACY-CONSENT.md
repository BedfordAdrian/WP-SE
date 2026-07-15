# Privacy & Consent

The plugin treats client data conservatively. AI processing is **off by default** for every workspace, data is shared with OpenAI only per-run and only within recorded consent, and all AI output requires human review before use. This document explains the consent model, the per-run data-sharing checklist, the editable UK privacy template, and the encryption/salts warning.

> **This document and the bundled privacy template are not legal advice.** They are operational descriptions and a starting point that ABC Book Marketing must have reviewed by a qualified adviser.

---

## The consent model

Consent is recorded **per workspace** (per book) in the `consent` table, edited under a workspace's Consent tab. Enforcement lives in [`includes/Repository/Consent.php`](../includes/Repository/Consent.php) and [`includes/AI/DataSharing.php`](../includes/AI/DataSharing.php).

### Default-deny

A workspace with no consent record — or a record whose `status` is not `active`, or whose `allow_openai` is not `1` — **cannot run AI at all**. `Consent::ai_allowed()` returns `false` in every one of those cases. There is no implicit consent.

### Category gating

Beyond the master `allow_openai` switch, consent has four category toggles that gate the sensitive data-sharing categories:

| Consent toggle | Gates data-sharing categories |
|---|---|
| `allow_manuscript` | full manuscript, manuscript passages, locally generated manuscript summary, uploaded files |
| `allow_sales` | sales data, advertising data, email data, social data, budget, campaign history |
| `allow_personal` | author profile, personal client data |
| `allow_web_research` | AI-assisted web search |

`Consent::category_allowed()` first requires active consent with `allow_openai = 1`, then checks the relevant toggle. Categories that are not consent-restricted are permitted once AI is allowed at all.

### AI runs blocked when they would exceed consent

Every interactive run passes through `AI\Runner::run()`, which enforces consent **before** any prompt is sent:

1. If AI isn't permitted for the workspace, the run is refused (`no_consent`) and audited (`ai.blocked_consent`).
2. The requested data-sharing categories are filtered through `DataSharing::enforce()`; any category the consent record forbids is **dropped from the prompt** — it is never sent. Only the surviving categories are recorded on the run (`ai_runs.data_sharing`).
3. Web search is disabled for the run unless `allow_web_research` consent is present, regardless of the global setting.

The prompt builder includes **only** the categories that survived enforcement, and states missing/delayed sources transparently rather than silently omitting them.

### Revocation

Consent can be withdrawn at any time by setting a `revoked_date` and/or changing `status` away from `active`. Because every run re-checks consent at run time, withdrawing consent (or unticking a category) immediately stops the corresponding AI processing on the next run — there is nothing cached that bypasses the check.

---

## The per-run data-sharing checklist

For every run the operator sees a checklist of data-sharing **categories**. Each category maps to a consent gate (or `null` when it isn't consent-restricted) and carries a sensitivity flag used to warn the operator. The full catalogue (from `DataSharing::categories()`):

| Category | Label | Consent gate | Sensitive |
|---|---|---|---|
| `book_profile` | Book profile | — | no |
| `author_profile` | Author profile | personal | yes |
| `synopsis` | Synopsis | — | no |
| `full_manuscript` | Full manuscript | manuscript | yes |
| `manuscript_passages` | Selected manuscript passages | manuscript | yes |
| `manuscript_summary` | Locally generated manuscript summary | manuscript | yes |
| `reviews` | Uploaded reviews | — | no |
| `press` | Press coverage | — | no |
| `sales_data` | Sales data | sales | yes |
| `advertising_data` | Advertising data | sales | yes |
| `email_data` | Email data | sales | yes |
| `social_data` | Social data | sales | no |
| `budget` | Budget | sales | yes |
| `campaign_history` | Campaign history | sales | no |
| `previous_recs` | Previous AI recommendations | — | no |
| `uploaded_files` | Uploaded files | manuscript | yes |
| `personal_client` | Personal client data | personal | yes |

An AI task type declares a **minimum** set of categories it needs (`min_data`); the checklist defaults to that minimum. The operator can add or remove categories, but anything the consent record forbids is stripped before the request is sent. The full manuscript is never sent by default — it requires both the `full_manuscript` category and `allow_manuscript` consent, and even then only extracted text of explicitly selected files is included (capped to respect model limits).

---

## The editable UK privacy / AI-processing template

The plugin ships a client-facing **UK privacy / AI-processing notice template**, editable under **Settings → Privacy** (option `abcmd_privacy_template`, default text from [`includes/Demo/PrivacyTemplate.php`](../includes/Demo/PrivacyTemplate.php)).

It is a **starting point that requires legal review — not legal advice.** The default text covers: who the agency is; the UK GDPR / Data Protection Act 2018 lawful bases; categories of data; purpose of processing; the OpenAI subprocessor and what is (and isn't) sent; international transfers (with a placeholder for the transfer mechanism and risk assessment); web research; retention; security; human review; the limits of AI output; the client's right to set or withdraw consent; and agency contact details. Bracketed fields must be completed per author/book, and the whole notice must be reviewed by a qualified adviser before it is relied upon.

Its promises align with how the plugin behaves: only ticked categories are sent, the full manuscript is not sent by default, sources for web research are recorded and shown, and **all AI output is human-reviewed and nothing is published automatically** (recommendations are labelled Evidence-backed / Inferred / Experimental with a confidence level).

---

## Encryption & the salts warning

The OpenAI API key is encrypted at rest (libsodium secretbox, OpenSSL AES-256-GCM fallback) with a key **derived from your WordPress secret salts** and never stored in the database (see [`includes/Support/Encryption.php`](../includes/Support/Encryption.php) and [ARCHITECTURE.md](ARCHITECTURE.md#encryption)).

> **Warning — rotating WordPress salts invalidates stored secrets.** The encryption key is derived from `AUTH_KEY`/`SECURE_AUTH_KEY`/`LOGGED_IN_KEY`/`NONCE_KEY` salts in `wp-config.php`. If you rotate (change) those salts, the derived key changes and the stored API key can **no longer be decrypted**. AI runs will fail with a "no key / decrypt failed" error until you re-enter the key under Settings → OpenAI. This is by design — the usable key is never persisted — but plan salt rotations accordingly and be ready to re-enter the key afterwards.

Manuscripts and other uploads are held in protected, non-public storage (`wp-content/uploads/abcmd-secure/`), served only through capability-checked handlers, and text extraction is performed locally — source files are never sent to an external extraction service.
