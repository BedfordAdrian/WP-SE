=== AuthorLift ===
Contributors: mofanning
Tags: author, marketing, social-media, book-launch, scheduler, analytics
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Social media marketing for authors: generate on-brand posts, plan launch campaigns, schedule & publish, and measure the sales bump.

== Description ==

AuthorLift turns your book catalogue into a running marketing machine, right
inside your WordPress admin:

* **Content Studio** — generate ready-to-post copy for 15 post types (teasers,
  quote cards, cover reveals, countdowns, launch-day, review highlights,
  giveaways, newsletter CTAs and more), fitted to each platform's character
  budget, with hashtag suggestions from your genre and tropes.
* **Campaign Planner** — turn a book + release date into a full dated posting
  playbook (pre-launch ramp, launch-day blitz, post-launch sustain) and fill
  your calendar in one click.
* **Scheduler & pluggable publishers** — WP-Cron publishes posts when they're
  due, through a publisher adapter.
* **Analytics** — track engagement and audience growth, and quantify the sales
  bump each campaign produced versus a matched baseline.

= Please read: about publishing =

AuthorLift ships with a **simulated** publisher as the default. It does **not**
post to live social networks, and the engagement numbers it produces (plus the
sample sales in the demo seed) are **realistic simulations, not real data** —
clearly labelled as "Sample data" throughout the dashboard. This lets you use
the whole product without connecting any accounts.

Real network adapters (X, Meta, TikTok, an email service provider, …) implement
the same interface and register via the `authorlift_publishers` filter; once
active, the scheduler uses them with no other changes and analytics reflect real
measured metrics. Record your own sales under Analytics → Log sales so the
sales-bump report reflects your real numbers.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
2. Choose `authorlift.zip` and click **Install Now**, then **Activate**.
3. Open **AuthorLift** in the admin menu. On first activation it seeds a demo
   profile so the dashboard is immediately useful; replace it with your own
   author, book and data.

== Frequently Asked Questions ==

= Does it post to my real social accounts? =

Not out of the box — the bundled publisher is simulated and clearly labelled.
Register a real adapter via the `authorlift_publishers` filter to post for real.

= Where is my data stored? =

In a single (non-autoloaded) WordPress option, `authorlift_data`. Uninstalling
the plugin removes it.

= Does it require any external services or API keys? =

No. Content generation is template-based and runs entirely on your server.

== Changelog ==

= 1.0.0 =
* Initial release: Content Studio, Campaign Planner, scheduler, pluggable
  publishers, and analytics with sales-bump reporting.
