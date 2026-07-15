# Hooks

The plugin exposes four extension hooks that form the **phase-two integration surface**, plus a set of `admin-post.php` action names that are useful as an integration reference. Extensions should hook these rather than editing core.

---

## Extension hooks

There are three filters and one action.

### `abcmd_loaded` (action)

Fires at the end of `Plugin::boot()`, after the plugin has registered its runtime hooks. This is the entry point for phase-two connectors to register sync jobs, settings panels and additional hooks.

**Signature**

```php
do_action( 'abcmd_loaded', ABCMD\Plugin $plugin );
```

- `$plugin` — the booted `ABCMD\Plugin` singleton.

**Example**

```php
add_action( 'abcmd_loaded', function ( $plugin ) {
    // Register a daily sync for a future Shopify connector.
    if ( ! wp_next_scheduled( 'myco_shopify_sync' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'myco_shopify_sync' );
    }
    add_action( 'myco_shopify_sync', 'myco_pull_shopify_orders' );
} );
```

Defined in [`includes/Plugin.php`](../includes/Plugin.php).

---

### `abcmd_import_templates` (filter)

Add or modify CSV import templates. Templates declare canonical target fields and header-alias substrings used for auto-mapping suggestions; the operator always confirms the mapping before import.

**Signature**

```php
apply_filters( 'abcmd_import_templates', array $templates ): array;
```

Each template is keyed by a machine slug and shaped:

```php
'my_source' => array(
    'label'   => 'My Source (sales)',   // shown in the dropdown
    'source'  => 'My Source',           // default source label stored on rows
    'targets' => array(
        // canonical field => header-alias substrings (case-insensitive, matched by contains)
        'date'       => array( 'date', 'day' ),
        'units'      => array( 'units', 'qty', 'quantity' ),
        'net_income' => array( 'net', 'earnings', 'royalty' ),
    ),
    'note'    => 'Optional operator hint.',
),
```

Canonical target fields (and their metric mapping) are defined in `Import\Templates::fields()`. Fields with a `metric` key create metric rows on import (e.g. `units`/`net_units` → `sales`, `revenue` → `revenue`, `ad_spend` → `ad_spend`).

**Example**

```php
add_filter( 'abcmd_import_templates', function ( array $templates ) {
    $templates['kickstarter'] = array(
        'label'   => 'Kickstarter pledges',
        'source'  => 'Kickstarter',
        'targets' => array(
            'date'    => array( 'date', 'pledged at' ),
            'revenue' => array( 'amount', 'pledge' ),
            'units'   => array( 'backers', 'quantity' ),
        ),
    );
    return $templates;
} );
```

Defined in [`includes/Import/Templates.php`](../includes/Import/Templates.php).

---

### `abcmd_ai_task_types` (filter)

Add or modify AI task types. A task type declares its label, the minimum data categories it needs, whether web research is useful, and which structured outputs it should emit into the approval queue.

**Signature**

```php
apply_filters( 'abcmd_ai_task_types', array $types ): array;
```

Each type is keyed by slug and shaped:

```php
'my_task' => array(
    'label'    => 'My custom analysis',
    'min_data' => array( 'book_profile', 'sales_data' ), // data-sharing category slugs
    'web'      => false,                                  // web research useful?
    'emits'    => array( 'recommendations' ),            // any of: recommendations, tasks, content
),
```

`emits` controls whether the runner appends the structured-JSON directive and parses the result into `recommendations`, `tasks` and/or `content`. Built-in system instructions live in `TaskTypes::system_instruction()`; a custom slug not present there falls back to the `custom` instruction, so provide your own instruction via the prompt where needed.

**Example**

```php
add_filter( 'abcmd_ai_task_types', function ( array $types ) {
    $types['series_strategy'] = array(
        'label'    => 'Series-launch strategy',
        'min_data' => array( 'book_profile', 'sales_data', 'campaign_history' ),
        'web'      => true,
        'emits'    => array( 'recommendations', 'tasks' ),
    );
    return $types;
} );
```

Defined in [`includes/AI/TaskTypes.php`](../includes/AI/TaskTypes.php).

---

### `abcmd_web_search_tool` (filter)

Customise the hosted web-search tool descriptor sent to the OpenAI Responses API when web research is enabled for a run.

**Signature**

```php
apply_filters( 'abcmd_web_search_tool', array $tool ): array;
// default: array( 'type' => 'web_search' )
```

**Example**

```php
add_filter( 'abcmd_web_search_tool', function ( array $tool ) {
    // Constrain to recent, UK-focused results (illustrative — use only fields the API accepts).
    $tool['filters'] = array( 'recency_days' => 30 );
    return $tool;
} );
```

Defined in [`includes/AI/OpenAIClient.php`](../includes/AI/OpenAIClient.php).

---

## `admin-post.php` action reference

Every plugin form posts to `admin-post.php` with an `action` and an `_abcmd_nonce` field. Each handler verifies the capability **and** the nonce (via `Helpers::verify_nonce`), so these are not general-purpose extension points — but the list is a useful map of the plugin's write operations. Handlers are registered in [`includes/Admin/PostHandlers.php`](../includes/Admin/PostHandlers.php) as `admin_post_{action}`.

| Action | Handler | What it does |
|---|---|---|
| `abcmd_save_author` | `save_author` | Create/update an author. |
| `abcmd_delete_author` | `delete_author` | Delete an author (blocked if it still has books). |
| `abcmd_save_book` | `save_book` | Create/update a book workspace (incl. anomaly thresholds). |
| `abcmd_delete_book` | `delete_book` | Delete a workspace and its data (type-to-confirm). |
| `abcmd_save_consent` | `save_consent` | Save the per-workspace consent record. |
| `abcmd_run_ai` | `run_ai` | Launch an interactive AI run. |
| `abcmd_import_run` | `import_run` | Run a CSV import (then triggers anomaly detection). |
| `abcmd_import_rollback` | `import_rollback` | Roll back an import (delete its metrics). |
| `abcmd_save_mapping` | `save_mapping` | Save a mapping profile. |
| `abcmd_manual_adjustment` | `manual_adjustment` | Insert a manual metric adjustment. |
| `abcmd_upload_file` | `upload_file` | Upload a file to protected storage + extract text. |
| `abcmd_delete_file` | `delete_file` | Delete a file, its bytes and chunks. |
| `abcmd_extract_file` | `extract_file` | Re-run text extraction for a file. |
| `abcmd_download_file` | `download_file` | Stream a stored file (capability + nonce checked). |
| `abcmd_save_task` | `save_task` | Create/update a task (with dependency gate). |
| `abcmd_save_recommendation` | `save_recommendation` | Update a recommendation; optionally spawn a task. |
| `abcmd_save_content` | `save_content` | Create/update a content item. |
| `abcmd_approval_decision` | `approval_decision` | Approve/reject/regenerate queue items in bulk. |
| `abcmd_run_audit_now` | `run_audit_now` | Run the weekly audit immediately. |
| `abcmd_detect_anomalies` | `detect_anomalies` | Run anomaly detection for a workspace. |
| `abcmd_save_settings` | `save_settings` | Save general settings + reschedule cron. |
| `abcmd_save_openai` | `save_openai` | Save OpenAI settings (encrypts a new key). |
| `abcmd_save_prices` | `save_prices` | Edit model pricing. |
| `abcmd_save_privacy` | `save_privacy` | Edit the privacy template. |
| `abcmd_install_demo` | `install_demo` | Install the demo workspace. |
| `abcmd_purge` | `purge` | Purge data by scope (type-to-confirm). |
| `abcmd_export_csv` | `export_csv` | Download a CSV export. |
| `abcmd_export_ical` | `export_ical` | Download an iCalendar file. |
| `abcmd_export_copy` | `export_copy` | Download copy-ready content. |
| `abcmd_complete_setup` | `complete_setup` | Mark the setup wizard complete. |

### REST endpoints

Dynamic admin interactions use REST routes under the `abcmd/v1` namespace, each gated by `current_user_can( ABCMD_CAP )` (see [`includes/Rest/Controller.php`](../includes/Rest/Controller.php)):

| Route | Method | Purpose |
|---|---|---|
| `/abcmd/v1/ping` | GET | Health check (returns version). |
| `/abcmd/v1/ai/estimate` | POST | Live per-run cost estimate. |
| `/abcmd/v1/anomalies` | GET | Open anomalies for a workspace. |
