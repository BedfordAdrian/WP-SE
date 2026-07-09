=== AuthorLift ===
Contributors: mofanning
Tags: author, marketing, social-media, book-launch, scheduler, analytics
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.2.1
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

AuthorLift supports Twitter/X, Bluesky, Instagram, Facebook, TikTok, Threads and
a newsletter channel. Set a handle for each network you use under Settings →
Handles; the planner schedules to exactly those channels.

Two publishers are built in, chosen under Settings → Publishing:

* **Simulated** (default) — does **not** post to live networks; it generates
  realistic engagement locally so you can try everything without connecting
  accounts. Its numbers (and the demo seed's sample sales) are clearly labelled
  "Sample data" throughout the dashboard.
* **Manual** — marks posts as published without inventing any numbers, for when
  you post to your networks yourself. Engagement stays at zero until you record
  real figures.

Automated network adapters (X, Meta, TikTok, Bluesky, an email service provider,
…) implement the same interface and register via the `authorlift_publishers`
filter; once active, the scheduler uses them with no other changes and analytics
reflect real measured metrics.

Use **Settings → Data → Clear sample data & start fresh** to remove the demo
seed and use the tool with your own catalogue, and **Analytics → Log sales** to
record real sales so the sales-bump report reflects your true numbers.

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

= 1.2.0 =
* Add multiple buy-link fields per book: Books2Read universal, Booklinker (all
  Amazon stores), Linktree, and a direct "signed copies" webshop link, plus a
  "preferred link" that controls which one appears in generated posts (with an
  "Order a signed copy" call to action when the webshop link is preferred).
* Show the running version in the sidebar and warn if the browser is showing a
  cached (stale) dashboard, to make upgrade/caching issues obvious.

= 1.1.0 =
* Add Bluesky and a handle field for every network in Settings.
* Add a "Manual" posting method (publishes without fabricating metrics) and
  relabel the "publisher" setting to "Posting method" to avoid confusion with a
  book's publisher.
* Add a Publisher / imprint field to each book (e.g. "Spring Street Books").
* Add "Clear sample data & start fresh".
* Version admin assets by file mtime so in-place updates always load fresh JS/CSS.

= 1.0.0 =
* Initial release: Content Studio, Campaign Planner, scheduler, pluggable
  publishers, and analytics with sales-bump reporting.
