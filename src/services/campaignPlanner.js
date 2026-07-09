import { addDays, atLocalHour, daysBetween } from '../utils/time.js';
import { bestTimesFor } from './recommendations.js';
import { generateContent } from './contentStudio.js';
import { createPost } from '../domain/posts.js';
import { PLATFORMS } from '../domain/author.js';
import { hashString } from '../utils/id.js';

/**
 * The Campaign Planner converts a campaign + book into a full, dated posting
 * schedule — a proven book-marketing "playbook" — and (optionally) writes every
 * post into the scheduler. This is what turns "I should post more" into an
 * executable plan that reliably drives a launch-day sales spike.
 */

const AWARENESS_TYPES = ['teaser', 'trope_appeal', 'behind_the_scenes', 'character_spotlight', 'quote_card', 'newsletter_cta'];
const RAMP_TYPES = ['preorder_push', 'countdown', 'quote_card', 'review_highlight', 'trope_appeal'];
const SUSTAIN_TYPES = ['review_highlight', 'question_engagement', 'milestone', 'newsletter_cta'];
const SALE_TYPES = ['quote_card', 'review_highlight', 'trope_appeal', 'question_engagement'];
const NEWSLETTER_TYPES = ['newsletter_cta', 'giveaway', 'behind_the_scenes', 'quote_card', 'teaser'];
const EVERGREEN_TYPES = ['teaser', 'quote_card', 'trope_appeal', 'review_highlight', 'question_engagement', 'behind_the_scenes'];

function socialPlatforms(author) {
  const handles = author?.handles || {};
  const withHandles = PLATFORMS.filter((p) => p !== 'newsletter' && handles[p]);
  const core = withHandles.length > 0 ? withHandles : ['twitter', 'instagram', 'facebook', 'tiktok', 'threads'];
  return core;
}

function allPlatforms(author) {
  return [...socialPlatforms(author), 'newsletter'];
}

/** Walk a day-offset window, assigning post types and platforms round-robin. */
function fillWindow({ fromOffset, toOffset, stepDays, types, platforms }) {
  const beats = [];
  if (platforms.length === 0 || toOffset < fromOffset) return beats;
  let offset = fromOffset;
  let i = 0;
  while (offset <= toOffset) {
    beats.push({
      offset,
      postType: types[i % types.length],
      platform: platforms[i % platforms.length],
    });
    offset += stepDays;
    i += 1;
  }
  return beats;
}

function launchBeats(anchorOffsetStart, anchorOffsetEnd, platforms) {
  const beats = [];
  const preStart = Math.max(anchorOffsetStart, -42);

  // Splashy cover reveal at the start of the awareness phase, everywhere.
  const revealOffset = Math.max(preStart, -35);
  if (revealOffset <= -7) {
    for (const platform of platforms) beats.push({ offset: revealOffset, postType: 'cover_reveal', platform });
  }

  // Awareness phase: steady drumbeat up to two weeks out.
  beats.push(
    ...fillWindow({ fromOffset: preStart, toOffset: -15, stepDays: 3, types: AWARENESS_TYPES, platforms }),
  );

  // Ramp phase: pre-order + countdown energy in the final fortnight.
  beats.push(
    ...fillWindow({ fromOffset: -14, toOffset: -2, stepDays: 2, types: RAMP_TYPES, platforms }),
  );
  // "1 sleep to go" countdown across every channel.
  for (const platform of platforms) beats.push({ offset: -1, postType: 'countdown', platform });

  // Launch day blitz — everywhere.
  for (const platform of platforms) beats.push({ offset: 0, postType: 'launch_day', platform });

  // Sustain phase: keep momentum and social proof flowing after release.
  beats.push(
    ...fillWindow({ fromOffset: 2, toOffset: Math.min(anchorOffsetEnd, 21), stepDays: 3, types: SUSTAIN_TYPES, platforms }),
  );

  return beats;
}

function saleBeats(durationDays, platforms) {
  const beats = [];
  for (const platform of platforms) beats.push({ offset: 0, postType: 'sale_announcement', platform });
  beats.push(...fillWindow({ fromOffset: 1, toOffset: Math.max(1, durationDays - 1), stepDays: 2, types: SALE_TYPES, platforms }));
  // Final-day urgency push.
  for (const platform of platforms) beats.push({ offset: Math.max(1, durationDays), postType: 'sale_announcement', platform });
  return beats;
}

function genericCadenceBeats(durationDays, platforms, types, stepDays) {
  return fillWindow({ fromOffset: 0, toOffset: durationDays, stepDays, types, platforms });
}

function resolveWindow(campaign, book) {
  const windowStart = new Date(campaign.startDate);
  const isLaunch = campaign.type === 'launch' || campaign.type === 'preorder';
  const anchor = isLaunch && book?.releaseDate ? new Date(book.releaseDate) : windowStart;
  let windowEnd;
  if (campaign.endDate) windowEnd = new Date(campaign.endDate);
  else if (isLaunch) windowEnd = addDays(anchor, 14);
  else windowEnd = addDays(windowStart, 30);
  return { windowStart, windowEnd, anchor, isLaunch };
}

/**
 * Build the beat list (offset/type/platform) for a campaign. Offsets are
 * relative to `anchor`.
 */
function buildBeats(campaign, book, author) {
  const { windowStart, windowEnd, anchor, isLaunch } = resolveWindow(campaign, book);
  const social = socialPlatforms(author);
  const withNewsletter = allPlatforms(author);
  const durationDays = Math.max(1, daysBetween(windowStart, windowEnd));

  let beats;
  if (isLaunch) {
    const startOffset = daysBetween(anchor, windowStart);
    const endOffset = daysBetween(anchor, windowEnd);
    beats = launchBeats(startOffset, endOffset, withNewsletter);
  } else if (campaign.type === 'sale') {
    beats = saleBeats(durationDays, social);
  } else if (campaign.type === 'newsletter_growth') {
    beats = genericCadenceBeats(durationDays, withNewsletter, NEWSLETTER_TYPES, 2);
    for (const platform of social) beats.push({ offset: 1, postType: 'giveaway', platform });
  } else {
    beats = genericCadenceBeats(durationDays, withNewsletter, EVERGREEN_TYPES, 3);
  }

  return { beats, windowStart, windowEnd, anchor };
}

/**
 * Plan a campaign. With `dryRun: true` returns the generated schedule without
 * persisting. Otherwise creates every post in the store (future beats become
 * `scheduled`, past beats become `draft` so the planner never back-fires) and
 * returns the created posts plus a summary.
 */
export function planCampaign(store, campaignId, options = {}) {
  const { dryRun = false, now = new Date() } = options;
  const campaign = store.get('campaigns', campaignId);
  if (!campaign) return null;
  const book = campaign.bookId ? store.get('books', campaign.bookId) : null;
  const author = store.getAuthor();
  const tz = author?.timezone || 'UTC';

  const { beats, windowStart, windowEnd, anchor } = buildBeats(campaign, book, author);

  // Per-platform slot rotation so repeat posts land in different time slots.
  const slotCounter = new Map();
  const nowMs = new Date(now).getTime();

  const items = [];
  for (const beat of beats) {
    const day = addDays(anchor, beat.offset);
    const hours = bestTimesFor(beat.platform);
    const count = slotCounter.get(beat.platform) || 0;
    slotCounter.set(beat.platform, count + 1);
    const hour = hours[count % hours.length];
    const scheduledAt = atLocalHour(day, hour, tz);

    // Clip to the campaign window.
    if (scheduledAt.getTime() < windowStart.getTime() - 12 * 3600 * 1000) continue;
    if (scheduledAt.getTime() > windowEnd.getTime() + 12 * 3600 * 1000) continue;

    const seed = hashString(`${campaignId}:${beat.offset}:${beat.platform}:${beat.postType}`);
    const content = generateContent(store, {
      bookId: campaign.bookId,
      campaignId,
      postType: beat.postType,
      platform: beat.platform,
      referenceDate: scheduledAt,
      seed,
    });

    const status = scheduledAt.getTime() > nowMs ? 'scheduled' : 'draft';
    items.push({
      ...content,
      scheduledAt: scheduledAt.toISOString(),
      status,
      offset: beat.offset,
    });
  }

  // Post earliest first.
  items.sort((a, b) => Date.parse(a.scheduledAt) - Date.parse(b.scheduledAt));

  const summary = summarize(items, { windowStart, windowEnd, anchor, campaign });

  if (dryRun) {
    return { dryRun: true, campaign, summary, posts: items };
  }

  const created = items.map((item) =>
    createPost(store, {
      platform: item.platform,
      type: item.type,
      body: item.body,
      hashtags: item.hashtags,
      cta: item.cta,
      mediaSuggestion: item.mediaSuggestion,
      imagePrompt: item.imagePrompt,
      bookId: item.bookId,
      campaignId,
      scheduledAt: item.scheduledAt,
      status: item.status,
    }),
  );

  // Flip the campaign into "active" now that it has a schedule.
  if (campaign.status === 'planning') {
    store.update('campaigns', campaignId, { status: 'active' });
  }

  return { dryRun: false, campaign: store.get('campaigns', campaignId), summary, posts: created };
}

function summarize(items, { windowStart, windowEnd, anchor, campaign }) {
  const byPlatform = {};
  const byType = {};
  for (const item of items) {
    byPlatform[item.platform] = (byPlatform[item.platform] || 0) + 1;
    byType[item.type] = (byType[item.type] || 0) + 1;
  }
  return {
    totalPosts: items.length,
    scheduled: items.filter((i) => i.status === 'scheduled').length,
    drafts: items.filter((i) => i.status === 'draft').length,
    byPlatform,
    byType,
    windowStart: windowStart.toISOString(),
    windowEnd: windowEnd.toISOString(),
    launchDate: anchor.toISOString(),
    campaignType: campaign.type,
  };
}

export { buildBeats };
