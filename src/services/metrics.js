import { addDays, daysBetween, startOfUtcDay, DAY_MS } from '../utils/time.js';
import { listSales, listFollowerSnapshots, listSubscriberSnapshots } from '../domain/audience.js';
import { listPosts } from '../domain/posts.js';

/**
 * Analytics. This module answers the question the whole product exists to
 * answer: "is the marketing working?" It aggregates engagement, tracks audience
 * growth, and — most importantly — quantifies the sales bump a campaign
 * produces by comparing sales during the campaign against a matched baseline.
 */

export function engagementRate(metrics) {
  if (!metrics || !metrics.impressions) return 0;
  const engaged = (metrics.likes || 0) + (metrics.comments || 0) + (metrics.shares || 0);
  return engaged / metrics.impressions;
}

export function sumMetrics(posts) {
  const total = { impressions: 0, likes: 0, comments: 0, shares: 0, clicks: 0 };
  for (const p of posts) {
    const m = p.metrics || {};
    total.impressions += m.impressions || 0;
    total.likes += m.likes || 0;
    total.comments += m.comments || 0;
    total.shares += m.shares || 0;
    total.clicks += m.clicks || 0;
  }
  return total;
}

function latestSnapshotValue(snapshots) {
  return snapshots.length ? snapshots[snapshots.length - 1].count : 0;
}

/** Total current followers across all platforms (latest snapshot each). */
export function currentFollowers(store) {
  const byPlatform = {};
  for (const snap of store.all('followerSnapshots')) {
    const prev = byPlatform[snap.platform];
    if (!prev || Date.parse(snap.date) >= Date.parse(prev.date)) byPlatform[snap.platform] = snap;
  }
  const platforms = {};
  let total = 0;
  for (const [platform, snap] of Object.entries(byPlatform)) {
    platforms[platform] = snap.count;
    total += snap.count;
  }
  return { total, platforms };
}

export function overview(store, { now = new Date() } = {}) {
  const nowMs = new Date(now).getTime();
  const published = listPosts(store, { status: 'published' });
  const scheduled = listPosts(store, { status: 'scheduled' });
  const totals = sumMetrics(published);

  const next7 = scheduled.filter((p) => {
    const t = Date.parse(p.scheduledAt);
    return t >= nowMs && t <= nowMs + 7 * DAY_MS;
  });

  const sales30 = listSales(store, { from: new Date(nowMs - 30 * DAY_MS).toISOString() });
  const salesUnits30 = sales30.reduce((s, r) => s + (r.units || 0), 0);
  const salesRevenue30 = sales30.reduce((s, r) => s + (r.revenue || 0), 0);

  const subs = listSubscriberSnapshots(store);

  return {
    publishedPosts: published.length,
    scheduledPosts: scheduled.length,
    postsNext7Days: next7.length,
    engagement: {
      ...totals,
      engagementRate: round(engagementRate(totals), 4),
    },
    followers: currentFollowers(store),
    subscribers: latestSnapshotValue(subs),
    sales30d: { units: salesUnits30, revenue: round(salesRevenue30, 2) },
  };
}

/**
 * Compute the sales bump for a window vs a matched baseline immediately before
 * it. Returns per-period totals plus normalised daily averages and the uplift.
 */
export function salesBump(store, { bookId = null, windowStart, windowEnd, baselineDays } = {}) {
  const start = new Date(windowStart);
  const end = new Date(windowEnd);
  const windowDays = Math.max(1, daysBetween(start, end) + 1);
  const baseDays = baselineDays || windowDays;
  const baselineStart = addDays(start, -baseDays);
  // Cover the FULL final day of the window (through 23:59:59.999), mirroring the
  // baseline's end-exclusive boundary. Otherwise sales on the last day after
  // midnight are dropped while that day is still counted in windowDays, which
  // would understate the window's daily rate for real (non-midnight) sales.
  const windowEndExclusive = new Date(addDays(start, windowDays).getTime() - 1);

  const inWindow = listSales(store, {
    bookId: bookId || undefined,
    from: start.toISOString(),
    to: windowEndExclusive.toISOString(),
  });
  const inBaseline = listSales(store, {
    bookId: bookId || undefined,
    from: baselineStart.toISOString(),
    to: new Date(start.getTime() - 1).toISOString(),
  });

  const windowUnits = inWindow.reduce((s, r) => s + (r.units || 0), 0);
  const windowRevenue = inWindow.reduce((s, r) => s + (r.revenue || 0), 0);
  const baselineUnits = inBaseline.reduce((s, r) => s + (r.units || 0), 0);
  const baselineRevenue = inBaseline.reduce((s, r) => s + (r.revenue || 0), 0);

  const windowDailyUnits = windowUnits / windowDays;
  const baselineDailyUnits = baselineUnits / baseDays;
  const windowDailyRevenue = windowRevenue / windowDays;
  const baselineDailyRevenue = baselineRevenue / baseDays;

  return {
    windowStart: start.toISOString(),
    windowEnd: end.toISOString(),
    windowDays,
    baselineDays: baseDays,
    window: { units: windowUnits, revenue: round(windowRevenue, 2), dailyUnits: round(windowDailyUnits, 2), dailyRevenue: round(windowDailyRevenue, 2) },
    baseline: { units: baselineUnits, revenue: round(baselineRevenue, 2), dailyUnits: round(baselineDailyUnits, 2), dailyRevenue: round(baselineDailyRevenue, 2) },
    uplift: {
      units: round(windowUnits - (baselineDailyUnits * windowDays), 1),
      unitsPct: pct(windowDailyUnits, baselineDailyUnits),
      revenue: round(windowRevenue - (baselineDailyRevenue * windowDays), 2),
      revenuePct: pct(windowDailyRevenue, baselineDailyRevenue),
    },
  };
}

/** Full report for one campaign: reach, engagement, sales bump, goal progress. */
export function campaignReport(store, campaignId, { now = new Date() } = {}) {
  const campaign = store.get('campaigns', campaignId);
  if (!campaign) return null;

  const posts = listPosts(store, { campaignId });
  const published = posts.filter((p) => p.status === 'published');
  const totals = sumMetrics(published);

  const windowStart = campaign.startDate;
  const book = campaign.bookId ? store.get('books', campaign.bookId) : null;
  let windowEnd = campaign.endDate;
  if (!windowEnd) {
    const anchor = (campaign.type === 'launch' || campaign.type === 'preorder') && book?.releaseDate
      ? book.releaseDate
      : campaign.startDate;
    windowEnd = addDays(anchor, 14).toISOString();
  }

  const bump = salesBump(store, { bookId: campaign.bookId, windowStart, windowEnd });

  const followerDelta = followersDelta(store, windowStart, windowEnd);
  const subscriberDelta = deltaOverWindow(listSubscriberSnapshots(store), windowStart, windowEnd);

  const goalProgress = computeGoalProgress(campaign, { bump, followerDelta, subscriberDelta, totals });

  return {
    campaign,
    postCounts: countByStatus(posts),
    engagement: { ...totals, engagementRate: round(engagementRate(totals), 4), clickThroughRate: totals.impressions ? round(totals.clicks / totals.impressions, 4) : 0 },
    salesBump: bump,
    followerDelta,
    subscriberDelta,
    goalProgress,
  };
}

function computeGoalProgress(campaign, { bump, followerDelta, subscriberDelta, totals }) {
  if (!campaign.goal || !campaign.goal.metric) return null;
  const { metric, target } = campaign.goal;
  let actual = 0;
  if (metric === 'sales') actual = bump.window.units;
  else if (metric === 'followers') actual = followerDelta.delta;
  else if (metric === 'subscribers') actual = subscriberDelta.delta;
  else if (metric === 'engagement') actual = totals.likes + totals.comments + totals.shares;
  return {
    metric,
    target,
    actual,
    pctOfTarget: target > 0 ? round((actual / target) * 100, 1) : null,
    met: target > 0 ? actual >= target : null,
  };
}

function deltaOverWindow(snapshots, from, to) {
  const fromMs = Date.parse(from);
  const toMs = Date.parse(to);
  const before = snapshots.filter((s) => Date.parse(s.date) <= fromMs);
  const within = snapshots.filter((s) => Date.parse(s.date) <= toMs);
  const startValue = before.length ? before[before.length - 1].count : (within.length ? within[0].count : 0);
  const endValue = within.length ? within[within.length - 1].count : startValue;
  return { start: startValue, end: endValue, delta: endValue - startValue };
}

/**
 * Follower snapshots span multiple platforms, so a naive single-series delta
 * mixes platforms together. Compute the delta per platform and sum, giving a
 * true "total followers gained" figure for the window.
 */
function followersDelta(store, from, to) {
  const platforms = new Set(store.all('followerSnapshots').map((s) => s.platform));
  let start = 0;
  let end = 0;
  const byPlatform = {};
  for (const platform of platforms) {
    const d = deltaOverWindow(listFollowerSnapshots(store, { platform }), from, to);
    start += d.start;
    end += d.end;
    byPlatform[platform] = d.delta;
  }
  return { start, end, delta: end - start, byPlatform };
}

function countByStatus(posts) {
  const counts = { total: posts.length, draft: 0, scheduled: 0, published: 0, failed: 0 };
  for (const p of posts) counts[p.status] = (counts[p.status] || 0) + 1;
  return counts;
}

/** Daily sales time series (UTC days) for charting. */
export function salesTimeseries(store, { bookId, from, to } = {}) {
  const sales = listSales(store, { bookId, from, to });
  const buckets = new Map();
  for (const sale of sales) {
    const key = startOfUtcDay(sale.date).toISOString().slice(0, 10);
    const b = buckets.get(key) || { date: key, units: 0, revenue: 0 };
    b.units += sale.units || 0;
    b.revenue += sale.revenue || 0;
    buckets.set(key, b);
  }
  return [...buckets.values()]
    .map((b) => ({ ...b, revenue: round(b.revenue, 2) }))
    .sort((a, b) => a.date.localeCompare(b.date));
}

function round(n, dp = 2) {
  const f = 10 ** dp;
  return Math.round((n + Number.EPSILON) * f) / f;
}

function pct(current, base) {
  if (!base) return null;
  return round(((current - base) / base) * 100, 1);
}
