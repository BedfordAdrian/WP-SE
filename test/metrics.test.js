import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../src/db/store.js';
import { createBook } from '../src/domain/books.js';
import { createCampaign } from '../src/domain/campaigns.js';
import { recordSale, recordFollowers, recordSubscribers } from '../src/domain/audience.js';
import { salesBump, campaignReport, engagementRate, sumMetrics, salesTimeseries } from '../src/services/metrics.js';

function isoDay(d) { return `2026-03-${String(d).padStart(2, '0')}T00:00:00.000Z`; }

function setup() {
  const store = createStore({ file: null });
  store.setAuthor({ penName: 'A', timezone: 'UTC', handles: {} });
  const book = createBook(store, { title: 'B', genre: 'Romance', status: 'released', releaseDate: isoDay(11) });
  const campaign = createCampaign(store, {
    name: 'Launch', type: 'launch', bookId: book.id,
    startDate: isoDay(11), endDate: isoDay(20), goal: { metric: 'sales', target: 300 },
  });
  // Baseline: days 1..10 @ 10 units. Window: days 11..20 @ 50 units.
  for (let d = 1; d <= 10; d++) recordSale(store, { bookId: book.id, date: isoDay(d), units: 10, revenue: 30 });
  for (let d = 11; d <= 20; d++) recordSale(store, { bookId: book.id, date: isoDay(d), units: 50, revenue: 150 });
  // Followers before/at window edges.
  recordFollowers(store, { platform: 'twitter', date: isoDay(10), count: 100 });
  recordFollowers(store, { platform: 'twitter', date: isoDay(20), count: 160 });
  recordFollowers(store, { platform: 'instagram', date: isoDay(10), count: 200 });
  recordFollowers(store, { platform: 'instagram', date: isoDay(20), count: 250 });
  recordSubscribers(store, { date: isoDay(10), count: 1000 });
  recordSubscribers(store, { date: isoDay(20), count: 1080 });
  return { store, book, campaign };
}

test('engagementRate and sumMetrics', () => {
  assert.equal(engagementRate({ impressions: 100, likes: 5, comments: 3, shares: 2 }), 0.1);
  assert.equal(engagementRate({ impressions: 0 }), 0);
  const t = sumMetrics([{ metrics: { impressions: 10, likes: 1 } }, { metrics: { impressions: 5, clicks: 2 } }]);
  assert.equal(t.impressions, 15);
  assert.equal(t.clicks, 2);
});

test('salesBump computes matched-baseline uplift', () => {
  const { store, book } = setup();
  const bump = salesBump(store, { bookId: book.id, windowStart: isoDay(11), windowEnd: isoDay(20) });
  assert.equal(bump.windowDays, 10);
  assert.equal(bump.baselineDays, 10);
  assert.equal(bump.window.units, 500);
  assert.equal(bump.baseline.units, 100);
  assert.equal(bump.window.dailyUnits, 50);
  assert.equal(bump.baseline.dailyUnits, 10);
  assert.equal(bump.uplift.unitsPct, 400);
  assert.equal(bump.uplift.units, 400);
});

test('campaignReport aggregates sales bump, growth and goal', () => {
  const { store, campaign } = setup();
  const report = campaignReport(store, campaign.id);
  assert.equal(report.salesBump.uplift.unitsPct, 400);
  assert.equal(report.followerDelta.delta, 110); // twitter +60, instagram +50
  assert.equal(report.subscriberDelta.delta, 80);
  assert.equal(report.goalProgress.actual, 500);
  assert.equal(report.goalProgress.met, true);
});

test('salesTimeseries buckets by UTC day', () => {
  const { store, book } = setup();
  const ts = salesTimeseries(store, { bookId: book.id });
  assert.equal(ts.length, 20);
  assert.equal(ts[0].date, '2026-03-01');
  assert.equal(ts[ts.length - 1].units, 50);
});

test('salesBump counts sales on the final day after midnight (not just 00:00)', () => {
  const store = createStore({ file: null });
  const book = createBook(store, { title: 'B', genre: 'Romance' });
  // A sale mid-afternoon on the LAST day of the window must be included.
  recordSale(store, { bookId: book.id, date: '2026-03-20T15:00:00.000Z', units: 40, revenue: 120 });
  const bump = salesBump(store, { bookId: book.id, windowStart: '2026-03-11T00:00:00.000Z', windowEnd: '2026-03-20T00:00:00.000Z' });
  assert.equal(bump.window.units, 40);
});

test('salesBump with zero baseline reports null percentage, not Infinity', () => {
  const store = createStore({ file: null });
  const book = createBook(store, { title: 'B', genre: 'Romance' });
  recordSale(store, { bookId: book.id, date: isoDay(15), units: 100, revenue: 300 });
  const bump = salesBump(store, { bookId: book.id, windowStart: isoDay(11), windowEnd: isoDay(20) });
  assert.equal(bump.baseline.units, 0);
  assert.equal(bump.uplift.unitsPct, null);
});
