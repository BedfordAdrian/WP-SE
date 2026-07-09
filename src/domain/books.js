import {
  requireString,
  optionalString,
  optionalIsoDate,
  optionalNumber,
  toStringArray,
  requireOneOf,
  assert,
} from '../utils/validate.js';

export const BOOK_STATUSES = ['draft', 'preorder', 'released'];

export const GENRES = [
  'Romance',
  'Romantic Comedy',
  'Romantasy',
  'Fantasy',
  'Science Fiction',
  'Thriller',
  'Mystery',
  'Horror',
  'Literary Fiction',
  'Historical Fiction',
  'Young Adult',
  'Nonfiction',
  'Memoir',
  'Self-Help',
];

function normalizeReviews(reviews) {
  if (reviews === undefined || reviews === null) return [];
  assert(Array.isArray(reviews), 'reviews must be an array', 'reviews');
  return reviews.slice(0, 100).map((r) => ({
    source: typeof r.source === 'string' ? r.source.trim().slice(0, 120) : 'Reader',
    rating: clampRating(r.rating),
    text: typeof r.text === 'string' ? r.text.trim().slice(0, 500) : '',
  })).filter((r) => r.text.length > 0);
}

function clampRating(rating) {
  const n = Number(rating);
  if (Number.isNaN(n)) return 5;
  return Math.max(1, Math.min(5, Math.round(n)));
}

// `universal` is a Books2Read-style link (all stores incl. Amazon); `booklinker`
// covers all Amazon storefronts; `signed` is the author's own webshop.
export const BUY_LINK_KEYS = ['universal', 'booklinker', 'linktree', 'signed', 'amazon', 'apple', 'kobo', 'barnesnoble', 'audible'];

function normalizeBuyLinks(links) {
  if (!links || typeof links !== 'object') return {};
  const out = {};
  for (const key of BUY_LINK_KEYS) {
    if (typeof links[key] === 'string' && links[key].trim()) {
      out[key] = links[key].trim();
    }
  }
  return out;
}

export function normalizeBook(input = {}, existing = {}) {
  const merged = { ...existing, ...input };
  return {
    title: requireString(merged.title, 'title', { max: 300 }),
    series: optionalString(merged.series, 'series', { max: 200 }),
    seriesNumber: optionalNumber(merged.seriesNumber, 'seriesNumber', { min: 0, max: 999 }),
    genre: merged.genre ? requireOneOf(merged.genre, GENRES, 'genre') : 'Fiction',
    subgenres: toStringArray(merged.subgenres, 'subgenres', { max: 10 }),
    blurb: optionalString(merged.blurb, 'blurb', { max: 3000 }),
    tagline: optionalString(merged.tagline, 'tagline', { max: 200 }),
    keywords: toStringArray(merged.keywords, 'keywords', { max: 30 }),
    tropes: toStringArray(merged.tropes, 'tropes', { max: 30 }),
    comps: toStringArray(merged.comps, 'comps', { max: 15 }),
    quotes: toStringArray(merged.quotes, 'quotes', { max: 50 }),
    reviews: normalizeReviews(merged.reviews),
    buyLinks: normalizeBuyLinks(merged.buyLinks),
    price: optionalNumber(merged.price, 'price', { min: 0, max: 1000 }),
    releaseDate: optionalIsoDate(merged.releaseDate, 'releaseDate'),
    status: merged.status ? requireOneOf(merged.status, BOOK_STATUSES, 'status') : 'draft',
    publisher: optionalString(merged.publisher, 'publisher', { max: 200 }),
    preferredLink: merged.preferredLink && BUY_LINK_KEYS.includes(merged.preferredLink) ? merged.preferredLink : null,
    coverImageUrl: optionalString(merged.coverImageUrl, 'coverImageUrl', { max: 500 }),
  };
}

export function listBooks(store) {
  return store.all('books');
}

export function getBook(store, id) {
  return store.get('books', id);
}

export function createBook(store, input) {
  const book = normalizeBook(input);
  return store.insert('books', book);
}

export function updateBook(store, id, input) {
  const existing = store.get('books', id);
  if (!existing) return null;
  const book = normalizeBook(input, existing);
  return store.update('books', id, book);
}

export function deleteBook(store, id) {
  // Detach posts/campaigns from the deleted book rather than orphaning them.
  for (const post of store.find('posts', (p) => p.bookId === id)) {
    store.update('posts', post.id, { bookId: null });
  }
  for (const campaign of store.find('campaigns', (c) => c.bookId === id)) {
    store.update('campaigns', campaign.id, { bookId: null });
  }
  return store.remove('books', id);
}
