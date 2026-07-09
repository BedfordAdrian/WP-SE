import {
  requireString,
  optionalString,
  optionalIsoDate,
  requireOneOf,
  optionalOneOf,
  toStringArray,
  assert,
} from '../utils/validate.js';
import { PLATFORMS } from './author.js';

export const POST_STATUSES = ['draft', 'scheduled', 'published', 'failed'];

export const POST_TYPES = [
  'teaser',
  'quote_card',
  'cover_reveal',
  'preorder_push',
  'countdown',
  'launch_day',
  'review_highlight',
  'behind_the_scenes',
  'trope_appeal',
  'character_spotlight',
  'giveaway',
  'newsletter_cta',
  'sale_announcement',
  'milestone',
  'question_engagement',
];

const emptyMetrics = () => ({ impressions: 0, likes: 0, comments: 0, shares: 0, clicks: 0 });

export function normalizePost(input = {}, existing = {}) {
  const merged = { ...existing, ...input };
  const status = merged.status
    ? requireOneOf(merged.status, POST_STATUSES, 'status')
    : 'draft';

  const post = {
    platform: requireOneOf(merged.platform, PLATFORMS, 'platform'),
    type: merged.type ? requireOneOf(merged.type, POST_TYPES, 'type') : 'teaser',
    body: requireString(merged.body, 'body', { max: 5000 }),
    hashtags: toStringArray(merged.hashtags, 'hashtags', { max: 30 }).map((h) =>
      h.startsWith('#') ? h : `#${h.replace(/\s+/g, '')}`,
    ),
    cta: optionalString(merged.cta, 'cta', { max: 300 }),
    mediaSuggestion: optionalString(merged.mediaSuggestion, 'mediaSuggestion', { max: 500 }),
    imagePrompt: optionalString(merged.imagePrompt, 'imagePrompt', { max: 2000 }),
    bookId: merged.bookId ?? null,
    campaignId: merged.campaignId ?? null,
    scheduledAt: optionalIsoDate(merged.scheduledAt, 'scheduledAt') ?? null,
    status,
    publishedAt: merged.publishedAt ?? null,
    externalId: merged.externalId ?? null,
    error: merged.error ?? null,
    metrics: merged.metrics || emptyMetrics(),
  };

  if (post.status === 'scheduled') {
    assert(post.scheduledAt, 'scheduledAt is required to schedule a post', 'scheduledAt');
  }
  return post;
}

export function listPosts(store, { status, platform, campaignId, bookId } = {}) {
  return store
    .find('posts', (p) => {
      if (status && p.status !== status) return false;
      if (platform && p.platform !== platform) return false;
      if (campaignId && p.campaignId !== campaignId) return false;
      if (bookId && p.bookId !== bookId) return false;
      return true;
    })
    .sort(byScheduledThenCreated);
}

function byScheduledThenCreated(a, b) {
  const at = a.scheduledAt ? Date.parse(a.scheduledAt) : Infinity;
  const bt = b.scheduledAt ? Date.parse(b.scheduledAt) : Infinity;
  if (at !== bt) return at - bt;
  return Date.parse(a.createdAt) - Date.parse(b.createdAt);
}

export function getPost(store, id) {
  return store.get('posts', id);
}

export function createPost(store, input) {
  validateRefs(store, input);
  const post = normalizePost(input);
  return store.insert('posts', post);
}

export function updatePost(store, id, input) {
  const existing = store.get('posts', id);
  if (!existing) return null;
  assert(existing.status !== 'published', 'Published posts cannot be edited', 'status');
  validateRefs(store, { ...existing, ...input });
  const post = normalizePost(input, existing);
  return store.update('posts', id, post);
}

export function deletePost(store, id) {
  return store.remove('posts', id);
}

/** Move a post into the scheduled state at a given time. */
export function schedulePost(store, id, scheduledAt) {
  const existing = store.get('posts', id);
  if (!existing) return null;
  assert(existing.status !== 'published', 'Published posts cannot be rescheduled', 'status');
  const when = optionalIsoDate(scheduledAt, 'scheduledAt') ?? existing.scheduledAt;
  assert(when, 'scheduledAt is required', 'scheduledAt');
  return store.update('posts', id, { status: 'scheduled', scheduledAt: when, error: null });
}

function validateRefs(store, input) {
  if (input.bookId) {
    assert(store.get('books', input.bookId), 'bookId does not reference an existing book', 'bookId');
  }
  if (input.campaignId) {
    assert(
      store.get('campaigns', input.campaignId),
      'campaignId does not reference an existing campaign',
      'campaignId',
    );
  }
}

export { emptyMetrics };
