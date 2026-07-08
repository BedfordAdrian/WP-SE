import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../src/db/store.js';
import { createBook } from '../src/domain/books.js';
import { createCampaign } from '../src/domain/campaigns.js';
import { planCampaign } from '../src/services/campaignPlanner.js';
import { listPosts } from '../src/domain/posts.js';

function setup({ releaseOffsetDays = 30, campaignStartOffset = -7 } = {}) {
  const store = createStore({ file: null });
  const now = new Date('2026-07-08T00:00:00.000Z');
  store.setAuthor({ penName: 'Adrian', timezone: 'America/New_York', handles: { twitter: 'a', instagram: 'b' } });
  const release = new Date(now.getTime() + releaseOffsetDays * 864e5);
  const book = createBook(store, {
    title: 'Crown of the Hollow King', genre: 'Romantasy', status: 'preorder',
    releaseDate: release.toISOString(), tropes: ['only one bed'], buyLinks: { amazon: 'https://b.example' },
  });
  const start = new Date(now.getTime() + campaignStartOffset * 864e5);
  const campaign = createCampaign(store, {
    name: 'Launch', type: 'launch', bookId: book.id,
    startDate: start.toISOString(), endDate: new Date(release.getTime() + 14 * 864e5).toISOString(),
    goal: { metric: 'sales', target: 500 },
  });
  return { store, campaign, book, now };
}

test('dryRun plan produces posts without persisting', () => {
  const { store, campaign, now } = setup();
  const result = planCampaign(store, campaign.id, { dryRun: true, now });
  assert.ok(result.posts.length > 10, 'should generate a full playbook');
  assert.equal(listPosts(store).length, 0, 'nothing persisted on dry run');
  assert.ok(result.summary.totalPosts === result.posts.length);
});

test('planning persists posts and activates the campaign', () => {
  const { store, campaign, now } = setup();
  const result = planCampaign(store, campaign.id, { dryRun: false, now });
  assert.equal(listPosts(store).length, result.posts.length);
  assert.equal(store.get('campaigns', campaign.id).status, 'active');
});

test('future beats are scheduled, past beats are drafts (never auto-fire)', () => {
  const { store, campaign, now } = setup();
  planCampaign(store, campaign.id, { dryRun: false, now });
  const posts = listPosts(store, { campaignId: campaign.id });
  for (const p of posts) {
    if (p.status === 'scheduled') assert.ok(Date.parse(p.scheduledAt) > now.getTime());
    if (p.status === 'draft') assert.ok(Date.parse(p.scheduledAt) <= now.getTime());
  }
  assert.ok(posts.some((p) => p.status === 'scheduled'), 'some future posts scheduled');
});

test('launch playbook includes a launch-day blitz and countdowns', () => {
  const { store, campaign, now } = setup();
  const { posts } = planCampaign(store, campaign.id, { dryRun: true, now });
  const types = new Set(posts.map((p) => p.type));
  assert.ok(types.has('launch_day'));
  assert.ok(types.has('countdown'));
  assert.ok(types.has('cover_reveal'));
});

test('all planned posts fall inside the campaign window', () => {
  const { store, campaign, now } = setup();
  const { posts, summary } = planCampaign(store, campaign.id, { dryRun: true, now });
  const start = Date.parse(summary.windowStart) - 12 * 3600e3;
  const end = Date.parse(summary.windowEnd) + 12 * 3600e3;
  for (const p of posts) {
    const t = Date.parse(p.scheduledAt);
    assert.ok(t >= start && t <= end, `post outside window: ${p.scheduledAt}`);
  }
});

test('planning an unknown campaign returns null', () => {
  const { store, now } = setup();
  assert.equal(planCampaign(store, 'campaign_missing', { now }), null);
});
