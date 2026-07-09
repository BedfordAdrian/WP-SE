import { addDays, daysBetween } from '../utils/time.js';
import { seededRandom } from '../utils/id.js';
import { saveAuthor } from '../domain/author.js';
import { createBook } from '../domain/books.js';
import { createCampaign, updateCampaign } from '../domain/campaigns.js';
import { listPosts } from '../domain/posts.js';
import { recordSale, recordFollowers, recordSubscribers } from '../domain/audience.js';
import { planCampaign } from '../services/campaignPlanner.js';
import { publishPost } from '../services/scheduler.js';

/**
 * Seed AuthorLift with a real author (Mo Fanning) and book (*Lisa Doyle is
 * Absolutely Fine*), a launch campaign whose posts have been "published" through
 * the simulated publisher, and a live forward-looking sustain campaign.
 *
 * HONESTY NOTE: the book metadata below is drawn from public information. The
 * engagement, follower and SALES numbers are ILLUSTRATIVE SAMPLE DATA generated
 * by the simulated publisher — they are NOT real results. The store is marked
 * `demoData: true` so the UI can label these surfaces clearly. We deliberately
 * do NOT fabricate pull-quotes or attributed reviews for the book, so generated
 * quote/review posts fall back to safe, non-fabricated copy until the author
 * adds genuine ones.
 */
export async function seed(store, { now = new Date() } = {}) {
  store.reset();
  store.updateSettings({ demoData: true, currencySymbol: '£', activePublisher: 'simulated' });
  const rng = seededRandom(20260708);
  const rint = (min, max) => Math.floor(min + rng() * (max - min + 1));

  const author = saveAuthor(store, {
    penName: 'Mo Fanning',
    realName: 'Mo Fanning',
    tagline: 'Funny, heartfelt romantic comedies for grown-ups.',
    bio: 'Mo Fanning is a British author of romantic comedy and uplit, based in the West Midlands. His award-winning novels — including Husbands and Rainbows and Lollipops — find the funny side of life\'s messier moments.',
    website: 'https://mofanning.co.uk',
    timezone: 'Europe/London',
    brandVoice: ['warm', 'witty', 'honest', 'self-deprecating', 'big-hearted'],
    // Confirmed channels. Add TikTok / Threads / X handles in Settings to widen the plan.
    handles: { facebook: 'mofanningbooks', instagram: 'mofanningbooks' },
  });

  const releaseDate = addDays(now, -26).toISOString(); // released ~late June 2026
  const book = createBook(store, {
    title: 'Lisa Doyle is Absolutely Fine',
    genre: 'Romantic Comedy',
    subgenres: ['Uplit', 'Commercial Fiction'],
    tagline: 'Four glasses of wine. One imaginary fiancé. What could possibly go wrong?',
    blurb:
      "Lisa Doyle has four glasses of wine in her and a fiancé who doesn't exist — so she invents one. A man called Brian. The trouble is, Brian shares his name with her very married boss, and now the real Brian is starting to look at her in ways that suggest this might all have been a terrible idea. A grown-up romantic comedy about love, pressure, friendship, and the exhausting performance of holding it all together when you're quietly falling apart.",
    keywords: ['fake fiancé', 'British romcom', 'messy heroine', 'workplace romance'],
    tropes: ['fake engagement', 'workplace romance', 'one little lie that spirals', 'messy heroine'],
    comps: ['Mhairi McFarlane', 'Beth O\'Leary', 'Marian Keyes'],
    // Intentionally no fabricated quotes/reviews — add real ones in the Books editor.
    quotes: [],
    reviews: [],
    buyLinks: {
      universal: 'https://books2read.com/absolutely',
      signed: 'https://shop.mofanning.co.uk/products/lisa-doyle-is-absolutely-fine-1',
    },
    releaseDate,
    status: 'released',
  });

  // ---- Launch campaign (already run; published via the simulated publisher) --
  const launch = createCampaign(store, {
    name: 'Lisa Doyle is Absolutely Fine — Launch',
    type: 'launch',
    bookId: book.id,
    startDate: addDays(releaseDate, -42).toISOString(),
    endDate: addDays(releaseDate, 14).toISOString(),
    goal: { metric: 'sales', target: 400 },
    notes: 'Six-week ramp into release week, blitz on launch day, two weeks of sustain and reviews.',
  });
  planCampaign(store, launch.id, { now });
  for (const post of listPosts(store, { campaignId: launch.id })) {
    if (post.status !== 'published' && post.scheduledAt) {
      await publishPost(store, post.id, { now: new Date(post.scheduledAt) });
    }
  }
  updateCampaign(store, launch.id, { status: 'completed' });

  // ---- Live sustain campaign (forward-looking, real usable plan) -------------
  const sustain = createCampaign(store, {
    name: 'Lisa Doyle — Summer Sustain',
    type: 'evergreen',
    bookId: book.id,
    startDate: addDays(now, -7).toISOString(),
    endDate: addDays(now, 30).toISOString(),
    goal: { metric: 'subscribers', target: 400 },
    notes: 'Keep momentum after launch: reviews, reader questions, behind-the-scenes and newsletter growth.',
  });
  planCampaign(store, sustain.id, { now });

  // ---- ILLUSTRATIVE sample sales (baseline -> launch spike -> new normal) -----
  const salesStart = addDays(releaseDate, -70);
  const salesDays = daysBetween(salesStart, now);
  for (let i = 0; i <= salesDays; i++) {
    const day = addDays(salesStart, i);
    const off = daysBetween(releaseDate, day);
    let units;
    if (off < -14) units = rint(2, 6);
    else if (off < 0) units = rint(4, 10);
    else if (off <= 2) units = rint(35, 80);
    else if (off <= 7) units = rint(20, 40);
    else if (off <= 21) units = rint(10, 20);
    else units = rint(4, 9);
    recordSale(store, {
      bookId: book.id,
      date: day.toISOString(),
      units,
      revenue: Math.round(units * 3.0 * 100) / 100,
      channel: 'amazon',
      source: 'sample',
    });
  }

  // ---- ILLUSTRATIVE sample audience growth (weekly snapshots) ----------------
  const platformsStart = { facebook: 1200, instagram: 900 };
  const growthPerWeek = { facebook: 25, instagram: 65 };
  const followStart = addDays(now, -84);
  for (let week = 0; week <= 12; week++) {
    const day = addDays(followStart, week * 7);
    const off = daysBetween(releaseDate, day);
    const launchBoost = off >= -7 && off <= 21 ? rint(60, 220) : 0;
    for (const platform of Object.keys(platformsStart)) {
      const count = Math.round(platformsStart[platform] + growthPerWeek[platform] * week + launchBoost);
      recordFollowers(store, { platform, date: day.toISOString(), count });
    }
    recordSubscribers(store, { date: day.toISOString(), count: Math.round(260 + week * 32 + (off >= -7 && off <= 21 ? rint(40, 140) : 0)) });
  }

  store.flush();
  return {
    author: author.penName,
    book: book.title,
    campaigns: [launch.name, sustain.name],
    note: 'Engagement, follower and sales figures are illustrative sample data (simulated publisher), not real results.',
  };
}
