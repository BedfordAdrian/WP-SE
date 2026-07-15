# Test Report — Marketing Department 1.0.0

Generated for the phase-one release.

## Environment

- PHP: 8.4 (plugin minimum is 8.1)
- WordPress target: 6.5+ / tested to 7.0.1
- Encryption backend present: libsodium and/or OpenSSL

## Automated suites

The plugin ships three runnable, dependency-free suites plus a WordPress
integration suite.

### 1. Unit tests (no WordPress required) — `php tests/run-tests.php`

Covers pure logic with WordPress functions stubbed.

| Area | Checks |
|---|---|
| Encryption round-trip, tamper detection, masking | 7 |
| AI cost calculation (input/output/web-search, unknown model) | 6 |
| CSV number parsing (currency, EU format, negatives, thousands) | 8 |
| CSV date parsing (explicit + auto-detect, invalid) | 6 |
| AI JSON extraction, threshold merge, data-sharing shape, mapping, helpers | 16 |

**Result: 43 passed, 0 failed.**

### 2. Functional tests (in-memory fake `$wpdb`) — `php tests/run-functional.php`

Exercises real integration paths.

| Area | Checks |
|---|---|
| Book insert + `find()` round-trip | 2 |
| CSV import round-trip, valid/rejected rows, metric creation | 4 |
| Row-level de-duplication + whole-file duplicate guard | 3 |
| Manual adjustment (flagged `is_adjustment`) | 2 |
| Consent enforcement (default-deny, category gating, `DataSharing::enforce`) | 6 |
| Task dependency gating (blocked → ready) | 2 |
| CSV export formatting (quoting) | 1 |

**Result: 20 passed, 0 failed.**

### 3. OpenAI client tests (stubbed HTTP) — `php tests/run-openai.php`

Captures the outgoing request and returns a canned Responses payload.

| Area | Checks |
|---|---|
| Request construction (endpoint `/v1/responses`, model, input, instructions, `web_search` tool, temperature, max_output_tokens, auth/org/project headers) | 10 |
| Response parsing (text, token usage, web-call count, de-duplicated `url_citation` sources) | 6 |

**Result: 16 passed, 0 failed.**

### 4. Render smoke test (fake `$wpdb` + admin stubs) — `php tests/smoke-render.php`

Installs the demo workspace, then renders every admin page/tab and asserts no
fatal or exception.

- Demo install: 1
- Pages/tabs rendered: 33 (Dashboard, Authors ×2, Books listing + 5 workspace
  tabs + author dashboard, Weekly Review, Recommendations, Tasks, Approval
  Queue ×4, Content Calendar ×2, Imports ×2, Files, AI Runs, Research Log,
  Audit Log, Integrations, Settings ×5, Help, Setup)

**Result: 34 passed, 0 failed.**

### Combined: **113 checks, 0 failures.**

### 5. WordPress-integration suite — `phpunit` (requires WP test library)

`tests/integration/test-integration.php` covers the database-dependent paths
that cannot run without WordPress: table creation, idempotent migrations,
capability grant, PHP-version gate, cron scheduling, encryption in the WP
runtime, import + dedup, consent blocking, demo install + anomaly detection,
the approval status transition, and uninstall data preservation. Run it against
a configured WordPress test environment (see `STAGING-CHECKLIST.md`).

## Static checks

- `php -l` passes on 100% of PHP files (enforced by `build-plugin.sh` before
  packaging).

## Mapping to the brief's required tests

| Required test | Where covered |
|---|---|
| activation | integration (`test_tables_exist`, `test_capability_granted_to_admin`) |
| version checks | integration (`test_php_version_gate`) + `Requirements` |
| capability checks | integration + capability guard on every page/handler |
| database creation | integration (`test_tables_exist`) |
| migrations | integration (`test_migration_is_idempotent`) |
| encryption/decryption | unit (`test-encryption`) + integration |
| import validation | functional (`run-functional`) + integration |
| duplicate detection | functional (row + whole-file) + integration |
| manual adjustment | functional |
| AI request construction | openai (`run-openai`: endpoint, body, tools, headers) + `PromptBuilder`/`Runner` |
| consent enforcement | functional (`Consent`, `DataSharing::enforce`) |
| data-sharing selections | unit (`DataSharing` shape) + functional |
| cost calculation | unit (`test-cost`) |
| task dependencies | functional (`dependencies_met`) |
| cron scheduling | integration (`test_cron_scheduling`) |
| anomaly rules | integration (`test_demo_and_anomaly_detection`) |
| approval workflow | integration (`test_approval_workflow_status_transition`) |
| exports | functional (CSV formatting) |
| uninstall data preservation | integration (`test_uninstall_preserves_data_by_default`) |

## Reproducing

```bash
php tests/run-tests.php
php tests/run-functional.php
php tests/run-openai.php
php tests/smoke-render.php
# and, on a WP test environment:
phpunit
```
