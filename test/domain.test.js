import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../src/db/store.js';
import { createBook, deleteBook } from '../src/domain/books.js';
import { createCampaign } from '../src/domain/campaigns.js';
import { createPost, updatePost, schedulePost } from '../src/domain/posts.js';
import { cleanHandle, saveAuthor } from '../src/domain/author.js';
import { ValidationError } from '../src/utils/validate.js';

function freshStore() {
  const store = createStore({ file: null });
  store.setAuthor({ penName: 'Test Author', timezone: 'UTC', handles: {} });
  return store;
}

test('createBook validates and normalises', () => {
  const store = freshStore();
  const book = createBook(store, { title: '  Ashes  ', genre: 'Romantasy', tropes: ['enemies to lovers', ''] });
  assert.equal(book.title, 'Ashes');
  assert.deepEqual(book.tropes, ['enemies to lovers']);
  assert.equal(book.status, 'draft');
});

test('createBook stores per-format prices', () => {
  const store = freshStore();
  const book = createBook(store, { title: 'X', prices: { ebook: 3.99, paperback: 9.99, hardcover: 16.99, audiobook: 12.99 } });
  assert.equal(book.prices.ebook, 3.99);
  assert.equal(book.prices.hardcover, 16.99);
  assert.equal(book.price, undefined); // legacy single price left untouched by the API
});

test('createBook rejects missing title and bad genre', () => {
  const store = freshStore();
  assert.throws(() => createBook(store, {}), ValidationError);
  assert.throws(() => createBook(store, { title: 'X', genre: 'Nope' }), ValidationError);
});

test('campaign end date must not precede start', () => {
  const store = freshStore();
  assert.throws(() => createCampaign(store, {
    name: 'C', startDate: '2026-02-01T00:00:00Z', endDate: '2026-01-01T00:00:00Z',
  }), /endDate/);
});

test('post scheduled without time is rejected', () => {
  const store = freshStore();
  assert.throws(() => createPost(store, { platform: 'twitter', body: 'hi', status: 'scheduled' }), /scheduledAt/);
});

test('post with unknown platform rejected', () => {
  const store = freshStore();
  assert.throws(() => createPost(store, { platform: 'myspace', body: 'hi' }), /platform/);
});

test('published posts cannot be edited', () => {
  const store = freshStore();
  const p = createPost(store, { platform: 'twitter', body: 'hi' });
  store.update('posts', p.id, { status: 'published' });
  assert.throws(() => updatePost(store, p.id, { body: 'new' }), /Published posts cannot be edited/);
});

test('schedulePost sets status and time', () => {
  const store = freshStore();
  const p = createPost(store, { platform: 'twitter', body: 'hi' });
  const when = '2026-08-01T12:00:00.000Z';
  const s = schedulePost(store, p.id, when);
  assert.equal(s.status, 'scheduled');
  assert.equal(s.scheduledAt, when);
});

test('cleanHandle reduces @handles and profile URLs to the bare handle', () => {
  assert.equal(cleanHandle('mofanningbooks'), 'mofanningbooks');
  assert.equal(cleanHandle('@mofanningbooks'), 'mofanningbooks');
  assert.equal(cleanHandle('https://www.facebook.com/mofanningbooks'), 'mofanningbooks');
  assert.equal(cleanHandle('https://instagram.com/mofanningbooks/'), 'mofanningbooks');
  assert.equal(cleanHandle('https://www.tiktok.com/@mofanningbooks'), 'mofanningbooks');
  assert.equal(cleanHandle('https://bsky.app/profile/mofanning.bsky.social'), 'mofanning.bsky.social');
  assert.equal(cleanHandle('mofanning.bsky.social'), 'mofanning.bsky.social');
});

test('saveAuthor stores cleaned handles even from full URLs', () => {
  const store = freshStore();
  const a = saveAuthor(store, {
    penName: 'Mo',
    handles: { facebook: 'https://www.facebook.com/mofanningbooks', bluesky: '@mo.bsky.social' },
  });
  assert.equal(a.handles.facebook, 'mofanningbooks');
  assert.equal(a.handles.bluesky, 'mo.bsky.social');
});

test('hashtags get normalised with leading #', () => {
  const store = freshStore();
  const p = createPost(store, { platform: 'twitter', body: 'hi', hashtags: ['Romance', '#Fantasy'] });
  assert.deepEqual(p.hashtags, ['#Romance', '#Fantasy']);
});

test('deleting a book detaches its posts and campaigns', () => {
  const store = freshStore();
  const book = createBook(store, { title: 'B' });
  const campaign = createCampaign(store, { name: 'C', startDate: '2026-01-01T00:00:00Z', bookId: book.id });
  const post = createPost(store, { platform: 'twitter', body: 'hi', bookId: book.id, campaignId: campaign.id });
  deleteBook(store, book.id);
  assert.equal(store.get('posts', post.id).bookId, null);
  assert.equal(store.get('campaigns', campaign.id).bookId, null);
});

test('post referencing missing book is rejected', () => {
  const store = freshStore();
  assert.throws(() => createPost(store, { platform: 'twitter', body: 'hi', bookId: 'book_missing' }), /bookId/);
});
