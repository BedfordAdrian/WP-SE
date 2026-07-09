import {
  requireString,
  optionalString,
  toStringArray,
  requireOneOf,
} from '../utils/validate.js';
import { TZ_OFFSETS } from '../utils/time.js';

export const PLATFORMS = ['twitter', 'bluesky', 'instagram', 'facebook', 'tiktok', 'threads', 'newsletter'];

/**
 * Validate and normalise an author profile. The app is single-author, so this
 * is a singleton persisted on the store.
 */
export function normalizeAuthor(input = {}, existing = {}) {
  const merged = { ...existing, ...input };
  const author = {
    penName: requireString(merged.penName, 'penName', { max: 120 }),
    realName: optionalString(merged.realName, 'realName', { max: 120 }),
    tagline: optionalString(merged.tagline, 'tagline', { max: 200 }),
    bio: optionalString(merged.bio, 'bio', { max: 2000 }),
    website: optionalString(merged.website, 'website', { max: 300 }),
    timezone: merged.timezone
      ? requireOneOf(merged.timezone, Object.keys(TZ_OFFSETS), 'timezone')
      : 'America/New_York',
    brandVoice: toStringArray(merged.brandVoice, 'brandVoice', { max: 12 }),
    handles: normalizeHandles(merged.handles || {}),
  };
  return author;
}

function normalizeHandles(handles) {
  const out = {};
  for (const platform of PLATFORMS) {
    if (platform === 'newsletter') continue;
    const raw = handles[platform];
    if (typeof raw === 'string' && raw.trim()) {
      out[platform] = raw.trim().replace(/^@/, '');
    }
  }
  return out;
}

export function getAuthor(store) {
  return store.getAuthor();
}

export function saveAuthor(store, input) {
  const existing = store.getAuthor() || {};
  const author = normalizeAuthor(input, existing);
  return store.setAuthor(author);
}
