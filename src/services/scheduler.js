import { getActivePublisher } from './publishers/index.js';
import { listFollowerSnapshots, listSubscriberSnapshots } from '../domain/audience.js';

/**
 * The scheduling engine. It periodically finds posts whose scheduled time has
 * arrived and hands them to the active publisher, recording the result. The
 * timer is intentionally thin: all real logic lives in `runDuePosts` /
 * `publishPost` so it can be driven directly from tests.
 */

const DEFAULT_REACH = { twitter: 2500, instagram: 3500, facebook: 1500, tiktok: 6000, threads: 1800, newsletter: 1200 };

function reachFor(store, platform) {
  if (platform === 'newsletter') {
    const subs = listSubscriberSnapshots(store);
    return subs.length ? subs[subs.length - 1].count : DEFAULT_REACH.newsletter;
  }
  const snaps = listFollowerSnapshots(store, { platform });
  return snaps.length ? snaps[snaps.length - 1].count : (DEFAULT_REACH[platform] ?? 2000);
}

/**
 * Publish a single post through the active publisher. Returns the updated post.
 * Any publisher error is captured onto the post as a `failed` status rather than
 * throwing, so one bad post never halts a batch.
 */
export async function publishPost(store, postId, { now = new Date() } = {}) {
  const post = store.get('posts', postId);
  if (!post) return null;
  if (post.status === 'published') return post;

  const publisher = getActivePublisher(store);
  const author = store.getAuthor();
  const book = post.bookId ? store.get('books', post.bookId) : null;
  const reach = reachFor(store, post.platform);

  try {
    const result = await publisher.publish(post, { author, book, reach, now });
    return store.update('posts', postId, {
      status: 'published',
      publishedAt: result.publishedAt || new Date(now).toISOString(),
      externalId: result.externalId || null,
      error: null,
      metrics: result.metrics || post.metrics,
      publishedVia: publisher.name,
    });
  } catch (err) {
    return store.update('posts', postId, {
      status: 'failed',
      error: String(err && err.message ? err.message : err),
    });
  }
}

/**
 * Find every scheduled post that is due (scheduledAt <= now) and publish it.
 * Returns a summary of what happened.
 */
export async function runDuePosts(store, { now = new Date() } = {}) {
  const nowMs = new Date(now).getTime();
  const due = store
    .find('posts', (p) => p.status === 'scheduled' && p.scheduledAt && Date.parse(p.scheduledAt) <= nowMs)
    .sort((a, b) => Date.parse(a.scheduledAt) - Date.parse(b.scheduledAt));

  const results = { published: [], failed: [] };
  for (const post of due) {
    const updated = await publishPost(store, post.id, { now });
    if (updated && updated.status === 'published') results.published.push(updated.id);
    else if (updated && updated.status === 'failed') results.failed.push(updated.id);
  }
  return results;
}

/**
 * Start the background ticker. Returns a handle with `stop()`. The interval is
 * unref'd so it never keeps a process alive on its own.
 */
export function startScheduler(store, { intervalMs = 60_000, onError } = {}) {
  let running = false;
  let stopped = false;

  async function tick() {
    if (running || stopped) return;
    running = true;
    try {
      await runDuePosts(store, { now: new Date() });
    } catch (err) {
      if (onError) onError(err);
    } finally {
      running = false;
    }
  }

  const timer = setInterval(tick, intervalMs);
  if (typeof timer.unref === 'function') timer.unref();
  // Kick once shortly after start so overdue posts don't wait a full interval.
  const kick = setTimeout(tick, 250);
  if (typeof kick.unref === 'function') kick.unref();

  return {
    stop() {
      stopped = true;
      clearInterval(timer);
      clearTimeout(kick);
    },
    tick,
  };
}
