import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../src/db/store.js';
import { createBook } from '../src/domain/books.js';
import { generateContent, fitToPlatform, PLATFORM_LIMITS } from '../src/services/contentStudio.js';
import { suggestHashtags } from '../src/services/recommendations.js';
import { POST_TYPES } from '../src/domain/posts.js';

function storeWithBook(extra = {}) {
  const store = createStore({ file: null });
  store.setAuthor({ penName: 'Adrian', website: 'https://x.example', timezone: 'UTC', handles: {} });
  const book = createBook(store, {
    title: 'Ashes of the Court',
    genre: 'Romantasy',
    status: 'released',
    tropes: ['enemies to lovers', 'slow burn'],
    quotes: ['A knife the court feared to hold.'],
    reviews: [{ source: 'BookTok', rating: 5, text: 'I did not sleep.' }],
    buyLinks: { amazon: 'https://buy.example/ashes' },
    blurb: 'A spymaster is sent to kill a fae king. She forgets to hate him.',
    ...extra,
  });
  return { store, book };
}

test('every post type generates non-empty content for every platform', () => {
  const { store, book } = storeWithBook();
  for (const type of POST_TYPES) {
    for (const platform of Object.keys(PLATFORM_LIMITS)) {
      const c = generateContent(store, { bookId: book.id, postType: type, platform });
      assert.ok(c.body && c.body.trim().length > 0, `empty body for ${type}/${platform}`);
      assert.ok(!/undefined|NaN/.test(c.body), `bad token in ${type}/${platform}: ${c.body}`);
      assert.ok(c.preview.length <= PLATFORM_LIMITS[platform], `over limit for ${type}/${platform}`);
    }
  }
});

test('twitter content fits within 280 chars including hashtags', () => {
  const { store, book } = storeWithBook();
  for (const type of POST_TYPES) {
    const c = generateContent(store, { bookId: book.id, postType: type, platform: 'twitter' });
    assert.ok(c.preview.length <= 280, `${type} preview was ${c.preview.length} chars`);
  }
});

test('generation is deterministic for a fixed seed', () => {
  const { store, book } = storeWithBook();
  const a = generateContent(store, { bookId: book.id, postType: 'teaser', platform: 'twitter', seed: 42 });
  const b = generateContent(store, { bookId: book.id, postType: 'teaser', platform: 'twitter', seed: 42 });
  assert.equal(a.body, b.body);
});

test('newsletter posts carry no hashtags', () => {
  const { store, book } = storeWithBook();
  const c = generateContent(store, { bookId: book.id, postType: 'newsletter_cta', platform: 'newsletter' });
  assert.equal(c.hashtags.length, 0);
});

test('countdown reflects days to release via referenceDate', () => {
  const { store, book } = storeWithBook({ status: 'preorder', releaseDate: '2026-09-10T00:00:00.000Z' });
  const c = generateContent(store, {
    bookId: book.id, postType: 'countdown', platform: 'instagram',
    referenceDate: '2026-09-05T00:00:00.000Z',
  });
  assert.match(c.body, /5 days/);
});

test('launch_day copy credits the publisher/imprint when set', () => {
  const { store, book } = storeWithBook({ publisher: 'Spring Street Books' });
  const c = generateContent(store, { bookId: book.id, postType: 'launch_day', platform: 'facebook', variantIndex: 0 });
  assert.match(c.body, /from Spring Street Books/);
});

test('released book CTA uses the buy link', () => {
  const { store, book } = storeWithBook();
  const c = generateContent(store, { bookId: book.id, postType: 'launch_day', platform: 'facebook' });
  assert.match(c.cta, /buy\.example/);
});

test('sale copy uses the per-format price with the store currency symbol', () => {
  const { store, book } = storeWithBook({ status: 'released', prices: { ebook: 3.99, paperback: 9.99 } });
  store.updateSettings({ currencySymbol: '£' });
  const c = generateContent(store, { bookId: book.id, postType: 'sale_announcement', platform: 'facebook', variantIndex: 0 });
  assert.match(c.body, /£3\.99/);
});

test('preferred link overrides the default and signed copies get their own CTA', () => {
  const { store, book } = storeWithBook({
    buyLinks: { universal: 'https://b2r.example/x', signed: 'https://shop.example/signed' },
    preferredLink: 'signed',
  });
  const c = generateContent(store, { bookId: book.id, postType: 'launch_day', platform: 'facebook' });
  assert.match(c.cta, /Order a signed copy: https:\/\/shop\.example\/signed/);
});

test('with no preferred link, the broad-reach universal link wins', () => {
  const { store, book } = storeWithBook({
    buyLinks: { universal: 'https://b2r.example/x', signed: 'https://shop.example/signed' },
  });
  const c = generateContent(store, { bookId: book.id, postType: 'launch_day', platform: 'facebook' });
  assert.match(c.cta, /Grab your copy: https:\/\/b2r\.example\/x/);
});

test('fitToPlatform trims hashtags before truncating body', () => {
  const body = 'x'.repeat(278); // leaves no room for even one "\n\n#a" (4 chars) within 280
  const { body: outBody, hashtags, text } = fitToPlatform(body, ['#a', '#b', '#c'], 'twitter');
  assert.equal(outBody, body, 'body untouched when it fits alone');
  assert.equal(hashtags.length, 0, 'hashtags dropped to fit');
  assert.ok(text.length <= 280);
});

test('fitToPlatform truncates an over-long body', () => {
  const body = 'y'.repeat(400);
  const { text } = fitToPlatform(body, ['#a'], 'twitter');
  assert.ok(text.length <= 280);
  assert.ok(text.endsWith('…'));
});

test('suggestHashtags respects platform limits and dedupes', () => {
  const book = { genre: 'Romantasy', tropes: ['Enemies to Lovers'], keywords: [] };
  const twitter = suggestHashtags(book, 'twitter');
  assert.ok(twitter.length <= 3);
  const insta = suggestHashtags(book, 'instagram');
  assert.ok(insta.length <= 12);
  assert.equal(new Set(insta.map((t) => t.toLowerCase())).size, insta.length, 'no duplicates');
});

test('unknown post type throws a 400', () => {
  const { store, book } = storeWithBook();
  assert.throws(() => generateContent(store, { bookId: book.id, postType: 'nope', platform: 'twitter' }), /Unknown post type/);
});
