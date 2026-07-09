# 🚀 AuthorLift

**Social media marketing that builds an author's readership and drives a measurable bump in book sales.**

AuthorLift takes an author's catalogue and turns it into a running marketing
machine: it *generates* on-brand social copy, *plans* a complete launch campaign
across every channel, *schedules and publishes* it automatically, and *measures*
the result — quantifying the sales bump each campaign produced against a matched
baseline.

It's a self-contained Node.js app with a REST API, a dashboard, and a CLI. It
installs and runs with **zero native build steps** and **no API keys required**.

---

## What it does

| Capability | Where |
|---|---|
| **Content Studio** — generate ready-to-post copy for 15 post types (teasers, quote cards, cover reveals, countdowns, launch-day, review highlights, giveaways, newsletter CTAs…), fitted to each platform's character budget, with hashtag suggestions drawn from genre + tropes. | `src/services/contentStudio.js` |
| **Campaign Planner** — turn a book + release date into a full, dated posting "playbook": a six-week pre-launch ramp, a launch-day blitz across all channels, and two weeks of sustain. One click fills the calendar. | `src/services/campaignPlanner.js` |
| **Scheduler & Publishers** — a background engine publishes posts when their time arrives, through a pluggable publisher adapter (across Twitter/X, Bluesky, Instagram, Facebook, TikTok, Threads and newsletter). Ships with **Simulated** and **Manual** publishers; real network adapters plug into the same interface. | `src/services/scheduler.js`, `src/services/publishers/` |
| **Analytics & Sales Bump** — track engagement, follower/subscriber growth, and the headline number: the **percentage uplift in sales** during a campaign vs. the prior matched period. | `src/services/metrics.js` |

---

## ⚠️ About publishing (please read)

AuthorLift ships with a **simulated publisher** as the default. It does **not**
post to live social networks, and the engagement numbers it produces (and the
sales figures in the demo seed data) are **realistic simulations, not real
data**. This lets you exercise the entire product — scheduling, publishing,
analytics — without connecting any accounts, which is ideal for demos and dry
runs. The UI labels this publisher as *“simulated.”*

Real network integrations plug in through a small, documented adapter interface
(see [Connecting real platforms](#connecting-real-platforms)). Once you register
an adapter built from your own API credentials, the scheduler uses it with no
other code changes, and the analytics reflect real measured metrics.

---

## Quick start

```bash
npm install
npm start
```

Then open **http://localhost:4000**. On first run the app seeds a real author
and book — **Mo Fanning**'s romantic comedy *Lisa Doyle is Absolutely Fine* — with
a completed launch campaign and a live forward-looking sustain campaign, so the
dashboard is immediately useful. Set `AUTHORLIFT_NO_SEED=1` to start empty.

> **Sample data:** the engagement, follower and sales figures in the seed are
> *illustrative samples produced by the simulated publisher, not real results.*
> The store is flagged `demoData`, so every metrics screen shows a clear "Sample
> data" banner. Record your own sales (Analytics → **Log sales**) and connect a
> real publisher to see live numbers. Book metadata is drawn from public sources;
> no pull-quotes or reviews are fabricated on the author's behalf.

### CLI

```bash
node bin/authorlift.js overview                 # dashboard summary as JSON
node bin/authorlift.js campaigns                # list campaigns
node bin/authorlift.js plan <campaignId> --dry  # preview a generated playbook
node bin/authorlift.js plan <campaignId>        # create the schedule
node bin/authorlift.js generate --type launch_day --book <bookId> --platform twitter
node bin/authorlift.js run-due                  # publish any posts now due
node bin/authorlift.js report <campaignId>      # full performance report
node bin/authorlift.js seed --force             # reset to demo data
```

### Tests

```bash
npm test        # 53 tests, Node's built-in runner, no extra deps
```

---

## Install as a WordPress plugin

The same product ships as a self-contained WordPress plugin (in
`wordpress-plugin/authorlift/`) — ideal if your author site already runs
WordPress.

```bash
bash wordpress-plugin/build.sh      # produces wordpress-plugin/authorlift.zip
```

Then in **wp-admin → Plugins → Add New → Upload Plugin**, upload
`authorlift.zip`, activate, and open **AuthorLift** in the admin menu. It stores
data in a single WordPress option, publishes due posts via WP-Cron, and exposes
the same dashboard under the admin. No database migrations, no external
services, no API keys. Real network publishers register via the
`authorlift_publishers` filter.

---

## Built for your whole catalogue

AuthorLift is multi-book by design — *Lisa Doyle is Absolutely Fine* is only the
seeded example. Add every title you publish under **Books** (or paste JSON via
**Books → Import JSON** to absorb one in a step), and AuthorLift generates
content and full launch/sale/evergreen campaigns for each. The richer a book's
data — tropes, **comps** ("for readers who loved…"), pull-quotes and **reviews**
— the sharper the copy it produces.

---

## How a launch works, end to end

1. **Add your book** (title, blurb, tropes, quotes, reviews, buy links, release date).
2. **Create a campaign** tied to that book, with a goal (e.g. 600 units).
3. **Generate the playbook** — AuthorLift lays out dozens of posts across the
   weeks around your release date, choosing post types and best-time slots per
   platform. Preview it, then commit it to the calendar.
4. **The scheduler publishes** each post when its time comes.
5. **Read the report** — units sold and royalties during the campaign window vs.
   the matched baseline before it, plus follower/subscriber growth and goal
   progress.

---

## Architecture

```
src/
  db/store.js            JSON-backed document store (atomic writes, in-memory mode for tests)
  db/seed.js             Deterministic demo data
  domain/                Validated CRUD: author, books, campaigns, posts, audience/sales
  services/
    contentStudio.js     Template-driven copy generation + per-platform fitting
    campaignPlanner.js   Launch-playbook generation
    recommendations.js   Best-time-to-post + hashtag heuristics
    scheduler.js         Publishes due posts via the active publisher
    publishers/          Publisher registry + the simulated default adapter
    metrics.js           Engagement, growth, and sales-bump analytics
  server.js              Express REST API
  index.js               Entry point (store + scheduler + server)
  utils/                 ids, time/timezone math, validation
public/                  Dependency-free dashboard SPA (vanilla JS, inline SVG charts)
bin/authorlift.js        CLI
test/                    Node test-runner suites
```

**Design choices**

- **No database engine and no build step.** A single JSON file via an atomic
  document store keeps the whole thing portable and instantly runnable.
- **Deterministic generation.** Content variants and simulated metrics come from
  a seeded PRNG, so demos and tests are reproducible.
- **Template-based content, not an LLM.** Copy generation is transparent, free,
  and offline. (An LLM could be dropped into `contentStudio.js` behind the same
  interface if desired.)

---

## Connecting real platforms

A publisher is any object implementing:

```js
{
  name: 'twitter',
  async publish(post, context) {
    // ...call the real API using your credentials...
    return { externalId, publishedAt, metrics };
  }
}
```

Register it and make it active:

```js
import { registerPublisher } from './src/services/publishers/index.js';
registerPublisher(myTwitterAdapter);          // typically built from process.env secrets
// then set settings.activePublisher = 'twitter' (via the Settings page or PUT /api/settings)
```

The scheduler is agnostic to which publisher is active, so no other code changes.

---

## REST API (selected)

```
GET  /api/analytics/overview            Dashboard KPIs
GET  /api/campaigns/:id/report          Sales-bump + engagement report
POST /api/campaigns/:id/plan            Generate a playbook ({ dryRun: true } to preview)
POST /api/content/generate              Generate one post's copy (no persistence)
GET  /api/calendar?from&to              Scheduled/drafted posts in a range
POST /api/posts/:id/publish             Publish a post immediately
GET  /api/meta                          Reference enums for the UI
```

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `PORT` | `4000` | HTTP port |
| `AUTHORLIFT_DATA` | `./data/authorlift.json` | Data file path |
| `SCHEDULER_INTERVAL_MS` | `60000` | How often the scheduler checks for due posts |
| `AUTHORLIFT_NO_SEED` | — | Set to `1` to skip first-run demo seeding |

## License

MIT
