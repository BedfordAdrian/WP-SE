import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../src/db/store.js';
import { createApp } from '../src/server.js';
import { seed } from '../src/db/seed.js';

let server;
let base;

before(async () => {
  const store = createStore({ file: null });
  await seed(store, { now: new Date('2026-07-08T00:00:00.000Z') });
  const app = createApp(store);
  await new Promise((resolve) => {
    server = app.listen(0, () => {
      base = `http://127.0.0.1:${server.address().port}`;
      resolve();
    });
  });
});

after(() => server && server.close());

async function get(path) {
  const res = await fetch(base + path);
  return { status: res.status, body: await res.json() };
}
async function send(method, path, body) {
  const res = await fetch(base + path, {
    method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  const text = await res.text();
  return { status: res.status, body: text ? JSON.parse(text) : null };
}

test('GET /api/health', async () => {
  const { status, body } = await get('/api/health');
  assert.equal(status, 200);
  assert.equal(body.ok, true);
});

test('GET /api/meta lists reference data', async () => {
  const { body } = await get('/api/meta');
  assert.ok(body.platforms.includes('twitter'));
  assert.ok(body.postTypes.includes('launch_day'));
  assert.ok(body.genres.length > 0);
});

test('seeded author, book, campaigns and posts are present', async () => {
  const author = await get('/api/author');
  assert.equal(author.body.penName, 'Mo Fanning');
  const books = await get('/api/books');
  assert.equal(books.body.length, 1);
  assert.equal(books.body[0].title, 'Lisa Doyle is Absolutely Fine');
  const campaigns = await get('/api/campaigns');
  assert.equal(campaigns.body.length, 2);
  const posts = await get('/api/posts');
  assert.ok(posts.body.length > 20);
});

test('seed marks the store as sample/demo data (honesty flag)', async () => {
  const { body } = await get('/api/settings');
  assert.equal(body.demoData, true);
  assert.equal(body.activePublisher, 'simulated');
});

test('POST /api/content/generate returns a fitted post', async () => {
  const books = (await get('/api/books')).body;
  const { status, body } = await send('POST', '/api/content/generate', {
    bookId: books[0].id, postType: 'teaser', platform: 'twitter',
  });
  assert.equal(status, 200);
  assert.ok(body.body.length > 0);
  assert.ok(body.preview.length <= 280);
});

test('CRUD: create, fetch, update, delete a book', async () => {
  const created = await send('POST', '/api/books', { title: 'Temp Book', genre: 'Thriller' });
  assert.equal(created.status, 201);
  const id = created.body.id;
  const fetched = await get(`/api/books/${id}`);
  assert.equal(fetched.body.title, 'Temp Book');
  const updated = await send('PUT', `/api/books/${id}`, { title: 'Temp Book 2' });
  assert.equal(updated.body.title, 'Temp Book 2');
  const del = await fetch(`${base}/api/books/${id}`, { method: 'DELETE' });
  assert.equal(del.status, 204);
  assert.equal((await get(`/api/books/${id}`)).status, 404);
});

test('validation errors return 400 with a field', async () => {
  const { status, body } = await send('POST', '/api/books', { title: '' });
  assert.equal(status, 400);
  assert.equal(body.field, 'title');
});

test('campaign plan dry-run returns a schedule without persisting extra posts', async () => {
  const campaigns = (await get('/api/campaigns')).body;
  const active = campaigns.find((c) => c.status === 'active');
  const before = (await get('/api/posts')).body.length;
  const { status, body } = await send('POST', `/api/campaigns/${active.id}/plan`, { dryRun: true });
  assert.equal(status, 200);
  assert.ok(body.posts.length > 0);
  const afterCount = (await get('/api/posts')).body.length;
  assert.equal(afterCount, before, 'dry run did not persist');
});

test('campaign report exposes the sales bump', async () => {
  const campaigns = (await get('/api/campaigns')).body;
  const completed = campaigns.find((c) => c.status === 'completed');
  const { body } = await get(`/api/campaigns/${completed.id}/report`);
  assert.ok(body.salesBump.uplift.unitsPct > 0, 'expected a positive sales bump');
  assert.ok(body.followerDelta.delta > 0, 'expected follower growth');
});

test('analytics overview returns aggregates', async () => {
  const { body } = await get('/api/analytics/overview');
  assert.ok(body.followers.total > 0);
  assert.ok(body.publishedPosts > 0);
});

test('unknown API route returns JSON 404', async () => {
  const { status, body } = await get('/api/nope');
  assert.equal(status, 404);
  assert.equal(body.error, 'Not found');
});
