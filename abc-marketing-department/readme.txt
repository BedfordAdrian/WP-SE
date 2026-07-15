=== Marketing Department ===
Contributors: abcbookmarketing
Tags: marketing, books, authors, openai, analytics, campaigns
Requires at least: 6.5
Tested up to: 7.0.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-only marketing operating system for a small book-marketing agency: per-book client workspaces, author dashboards, CSV imports, OpenAI-assisted audits, ranked recommendations, tasks, an approval queue, anomaly flags and a full audit trail.

== Description ==

Marketing Department is an internal tool for ABC Book Marketing staff — not for
author clients, and nothing is exposed on the public site. Every screen is gated
behind a dedicated `manage_abc_marketing_department` capability.

**Phase-one features**

* Book client workspaces and linked author records, with an aggregated author
  dashboard designed to surface oddities, not just totals.
* Manual CSV imports for IngramSpark, Draft2Digital, ACX, InAudio, Spotify,
  Amazon sales/ads, Meta Ads, EmailOctopus, Shopify and generic sources — with
  upload, flexible column mapping, saved profiles, date/currency mapping,
  de-duplication, rejected-row reports, rollback and manual adjustments.
* Dated metric snapshots (never overwriting prior figures).
* OpenAI integration using the current Responses API, with a selectable model
  per task, a per-run data-sharing checklist, cost estimate + actual cost
  logging, editable model pricing, rate limiting and web research where the
  model supports it.
* Client consent records that block AI runs which would exceed consent.
* A weekly automated audit (WP-Cron) producing ranked, evidence-labelled
  recommendations, draft tasks and proposed content in a single approval queue,
  plus a Monday summary email containing no sensitive data.
* Rule-based anomaly detection with per-workspace thresholds.
* File uploads (DOCX, PDF, EPUB, TXT, CSV, XLSX, images) with local text
  extraction and indexing — source files never leave your server.
* CSV, iCalendar and copy-ready exports.
* A demo workspace, sample CSVs, a setup wizard and an editable UK privacy /
  AI-processing template (a starting point requiring legal review).

**Later phases** (architected to plug in without a core rewrite): Shopify, GA4,
EmailOctopus, Meta Ads, Amazon Ads, social analytics, retailer/distributor feeds
and direct publishing/scheduling.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin, or extract to
   `wp-content/plugins/abc-marketing-department`.
2. Activate. On activation the plugin checks requirements, creates its tables,
   grants the capability to administrators, and schedules the weekly audit.
3. Open **Marketing Dept → Setup** to add your OpenAI key and optionally install
   the demo workspace.

Requires PHP 8.1+ and WordPress 6.5+. If PHP is too old, activation stops with a
clear message instead of causing a fatal error.

== Frequently Asked Questions ==

= Does this post to social networks or publish anything automatically? =
No. Phase one never publishes. All content requires human approval.

= Where are API keys stored? =
Encrypted at rest using libsodium (or OpenSSL as a fallback). The key is derived
from your WordPress secret salts and is never stored in the database. If you
rotate your salts, stored API keys can no longer be decrypted and must be
re-entered.

= Is the demo data real? =
No. It is fictionalised operational data for testing and can be removed at any
time.

== Changelog ==

= 1.0.1 =
* Fix: saving the OpenAI API key failed on hosts using WordPress's bundled
  sodium_compat polyfill (without the native libsodium extension) because
  sodium_memzero() throws there. Encryption now prefers the native libsodium
  extension, then OpenSSL AES-256-GCM, then the polyfill, and the memory wipe
  can never abort encryption.

= 1.0.0 =
* Initial phase-one release.

== Upgrade Notice ==

= 1.0.1 =
Fixes API-key saving on hosts without the native libsodium extension.

= 1.0.0 =
Initial release.
