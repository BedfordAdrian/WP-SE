import {
  requireIsoDate,
  requireOneOf,
  optionalString,
  optionalNumber,
  assert,
} from '../utils/validate.js';
import { PLATFORMS } from './author.js';

export const SALES_CHANNELS = ['amazon', 'kobo', 'apple', 'barnesnoble', 'audible', 'direct', 'other'];

// ---- Sales ----------------------------------------------------------------

export function recordSale(store, input = {}) {
  const bookId = input.bookId ?? null;
  if (bookId) {
    assert(store.get('books', bookId), 'bookId does not reference an existing book', 'bookId');
  }
  const sale = {
    bookId,
    date: requireIsoDate(input.date, 'date'),
    units: optionalNumber(input.units, 'units', { min: 0, max: 1_000_000 }) ?? 0,
    revenue: optionalNumber(input.revenue, 'revenue', { min: 0, max: 100_000_000 }) ?? 0,
    channel: input.channel ? requireOneOf(input.channel, SALES_CHANNELS, 'channel') : 'other',
    source: optionalString(input.source, 'source', { max: 200 }),
  };
  return store.insert('sales', sale);
}

export function listSales(store, { bookId, from, to } = {}) {
  return store
    .find('sales', (s) => {
      if (bookId && s.bookId !== bookId) return false;
      if (from && Date.parse(s.date) < Date.parse(from)) return false;
      if (to && Date.parse(s.date) > Date.parse(to)) return false;
      return true;
    })
    .sort((a, b) => Date.parse(a.date) - Date.parse(b.date));
}

// ---- Follower snapshots ----------------------------------------------------

export function recordFollowers(store, input = {}) {
  const snapshot = {
    platform: requireOneOf(input.platform, PLATFORMS, 'platform'),
    date: requireIsoDate(input.date, 'date'),
    count: optionalNumber(input.count, 'count', { min: 0, max: 1_000_000_000 }) ?? 0,
  };
  return store.insert('followerSnapshots', snapshot);
}

export function listFollowerSnapshots(store, { platform } = {}) {
  return store
    .find('followerSnapshots', (s) => (platform ? s.platform === platform : true))
    .sort((a, b) => Date.parse(a.date) - Date.parse(b.date));
}

// ---- Newsletter subscribers ------------------------------------------------

export function recordSubscribers(store, input = {}) {
  const snapshot = {
    date: requireIsoDate(input.date, 'date'),
    count: optionalNumber(input.count, 'count', { min: 0, max: 1_000_000_000 }) ?? 0,
  };
  return store.insert('subscriberSnapshots', snapshot);
}

export function listSubscriberSnapshots(store) {
  return store
    .all('subscriberSnapshots')
    .slice()
    .sort((a, b) => Date.parse(a.date) - Date.parse(b.date));
}
