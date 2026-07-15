# Dependencies & Licences

Marketing Department bundles **no third-party PHP libraries**. There is no `vendor/` directory and no Composer runtime dependency — class loading uses the plugin's own PSR-4 autoloader ([`includes/Autoloader.php`](../includes/Autoloader.php)). Everything the plugin needs comes from **PHP core extensions** and **WordPress core APIs**.

This keeps the supply chain minimal, avoids licence-compatibility questions from bundled code, and means there is nothing extra to keep patched.

## Plugin licence

**GPL-2.0-or-later** (`License URI: https://www.gnu.org/licenses/gpl-2.0.html`), consistent with WordPress.

---

## PHP runtime

- **PHP 8.1+** is required. The main file checks the version *before* engaging the autoloader; on older PHP it loads a syntax-safe guard that halts activation with a readable message instead of fatally erroring (see [`includes/legacy-guard.php`](../includes/legacy-guard.php)).

## PHP core extensions

| Extension | Required? | Why it's needed | Fallback when missing |
|---|---|---|---|
| `ext-json` | **Required** | JSON columns, API request/response encoding, audit context. Effectively always present in modern PHP. | None — it is a hard requirement. |
| `ext-sodium` **or** `ext-openssl` | **Required (one of)** | Encrypts the OpenAI API key at rest. libsodium `crypto_secretbox` is preferred; OpenSSL `aes-256-gcm` is the fallback — both are authenticated. | If **neither** is available, the key cannot be stored securely. This check is non-fatal at activation but is reported; without a backend, `Encryption::encrypt()` throws and the key can't be saved. |
| `ext-zip` (`ZipArchive`) | Optional (recommended) | Text extraction from ZIP-container formats: **DOCX**, **EPUB**, **XLSX**. | Extraction of those formats returns a clear error status; **TXT and CSV extraction still work**, and operators can paste passages or upload a DOCX/TXT instead. |
| `ext-mbstring` | Optional | Multibyte-aware length for token estimates and text chunking. | Falls back to byte-length (`strlen`); token estimates are slightly less accurate but functional. |
| `ext-zlib` (`gzuncompress`) | Optional | Best-effort PDF text extraction (decoding FlateDecode content streams). | PDF extraction still attempts uncompressed text operators; if too little text is recovered it returns a clear "could not reliably extract" status. |

### Notes on file-format support

- **Reliable:** TXT, MD, CSV are read directly. DOCX, EPUB and XLSX are read by unzipping and stripping their XML/HTML members (needs `ext-zip`).
- **Best-effort:** PDF extraction is pure-PHP and inherently limited — scanned PDFs and those using embedded font encodings often cannot be decoded. On failure the operator is told to upload a DOCX/TXT or paste passages instead. Even on success, PDF text is flagged as best-effort.
- **Images** (JPG/JPEG/PNG/WEBP) are stored but **not** text-extracted (status `not_applicable`); there is no OCR.
- **All extraction happens locally on your WordPress server.** Source files are never sent to any external extraction or conversion service.

The requirements screen (`ABCMD\Requirements`) reports the live status of each of these so an operator can see exactly what is available on their server.

---

## WordPress core

- **WordPress 6.5+** (tested to 7.0.1). The plugin relies only on standard WordPress core APIs — no other plugins are required. Key APIs used include:
  - **Database:** `$wpdb` and `dbDelta` (custom tables, prepared queries).
  - **HTTP:** `wp_remote_post` for the OpenAI Responses API — no separate HTTP client library.
  - **Options / transients:** settings, pricing, DB version, activation report.
  - **Cron:** `wp_schedule_event`, `cron_schedules`, `wp_next_scheduled`.
  - **REST API:** `register_rest_route` for permission-gated endpoints.
  - **Capabilities, roles & nonces:** `current_user_can`, role caps, `wp_verify_nonce`.
  - **Uploads & files:** `wp_upload_dir`, `wp_check_filetype_and_ext`, `wp_max_upload_size`, `move_uploaded_file`.
  - **Salts & security:** `wp_salt`, `wp_generate_password`, sanitisation/escaping helpers.
  - **Mail:** `wp_mail` for the weekly summary email.

## Third-party service

- **OpenAI API** is an external *service*, not a bundled dependency. It is called only when an API key is configured and consent permits, over `wp_remote_post` to the Responses endpoint (`/v1/responses`). No OpenAI SDK is bundled. Model pricing is stored in an editable option and is not treated as a fixed fact.

## Front-end / build

- No JavaScript build step, package manager, or bundled front-end framework. The admin UI uses a single hand-written CSS file and JS file under `admin/assets/`, enqueued by WordPress. There is no `node_modules` runtime dependency.
