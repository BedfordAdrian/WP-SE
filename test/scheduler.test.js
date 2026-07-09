import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../src/db/store.js';
import { createPost } from '../src/domain/posts.js';
import { runDuePosts, publishPost } from '../src/services/scheduler.js';
import { registerPublisher } from '../src/services/publishers/index.js';

function freshStore() {
  const store = createStore({ file: null });
  store.setAuthor({ penName: 'A', timezone: 'UTC', handles: {} });
  return store;
}

test('runDuePosts publishes only posts that are due', async () => {
  const store = freshStore();
  const past = createPost(store, { platform: 'twitter', body: 'past', status: 'scheduled', scheduledAt: '2026-01-01T00:00:00.000Z' });
  const future = createPost(store, { platform: 'twitter', body: 'future', status: 'scheduled', scheduledAt: '2999-01-01T00:00:00.000Z' });

  const result = await runDuePosts(store, { now: new Date('2026-06-01T00:00:00.000Z') });
  assert.deepEqual(result.published, [past.id]);
  assert.equal(store.get('posts', past.id).status, 'published');
  assert.equal(store.get('posts', future.id).status, 'scheduled');
});

test('publishing assigns metrics, publishedAt and externalId', async () => {
  const store = freshStore();
  const p = createPost(store, { platform: 'tiktok', type: 'launch_day', body: 'live!', status: 'scheduled', scheduledAt: '2026-01-01T00:00:00.000Z' });
  const updated = await publishPost(store, p.id, { now: new Date('2026-01-02T00:00:00.000Z') });
  assert.equal(updated.status, 'published');
  assert.ok(updated.externalId);
  assert.ok(updated.publishedAt);
  assert.ok(updated.metrics.impressions > 0);
  assert.equal(updated.publishedVia, 'simulated');
});

test('publishing is idempotent for already-published posts', async () => {
  const store = freshStore();
  const p = createPost(store, { platform: 'twitter', body: 'x' });
  store.update('posts', p.id, { status: 'published', metrics: { impressions: 5 } });
  const updated = await publishPost(store, p.id);
  assert.equal(updated.metrics.impressions, 5, 'metrics not regenerated');
});

test('the manual publisher publishes without fabricating metrics', async () => {
  const store = freshStore();
  store.updateSettings({ activePublisher: 'manual' });
  const p = createPost(store, { platform: 'bluesky', type: 'launch_day', body: 'live!', status: 'scheduled', scheduledAt: '2026-01-01T00:00:00.000Z' });
  const updated = await publishPost(store, p.id, { now: new Date('2026-01-02T00:00:00.000Z') });
  assert.equal(updated.status, 'published');
  assert.equal(updated.publishedVia, 'manual');
  assert.equal(updated.metrics.impressions, 0, 'no fabricated engagement');
  assert.match(updated.externalId, /^manual_/);
});

test('a failing publisher marks the post failed without throwing', async () => {
  const store = freshStore();
  registerPublisher({
    name: 'boom',
    async publish() { throw new Error('network down'); },
  });
  store.updateSettings({ activePublisher: 'boom' });
  const p = createPost(store, { platform: 'twitter', body: 'x', status: 'scheduled', scheduledAt: '2026-01-01T00:00:00.000Z' });
  const result = await runDuePosts(store, { now: new Date('2026-02-01T00:00:00.000Z') });
  assert.deepEqual(result.failed, [p.id]);
  assert.equal(store.get('posts', p.id).status, 'failed');
  assert.match(store.get('posts', p.id).error, /network down/);
});
