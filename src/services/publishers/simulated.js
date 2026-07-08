import { seededRandom, hashString } from '../../utils/id.js';

/**
 * The Simulated publisher. It does NOT contact any live social network — it
 * stands in for real platform adapters so the whole product (scheduling,
 * publishing, analytics) is fully exercisable without API keys. Every real
 * adapter should implement the same shape: `async publish(post, context)`
 * returning `{ externalId, publishedAt, metrics }` or throwing on failure.
 *
 * Engagement numbers here are DETERMINISTICALLY SIMULATED from the post id, so
 * dashboards look alive and tests stay stable. They are clearly flagged as
 * simulated in the UI.
 */

const IMPRESSION_FACTOR = {
  twitter: 1.2,
  instagram: 1.6,
  facebook: 0.7,
  tiktok: 3.5,
  threads: 1.1,
  newsletter: 0.45, // "impressions" == opens for newsletters
};

// Relative reach multiplier by post type (some content simply travels further).
const TYPE_REACH = {
  launch_day: 1.8,
  cover_reveal: 1.5,
  giveaway: 2.2,
  sale_announcement: 1.6,
  quote_card: 1.3,
  countdown: 1.2,
  review_highlight: 1.1,
  teaser: 1.0,
  trope_appeal: 1.15,
  character_spotlight: 1.0,
  behind_the_scenes: 0.95,
  question_engagement: 1.25,
  milestone: 1.05,
  newsletter_cta: 0.9,
  preorder_push: 1.1,
};

// Click-through propensity by type (buy/sign-up intent).
const CLICK_RATE = {
  preorder_push: 0.09,
  launch_day: 0.11,
  sale_announcement: 0.13,
  newsletter_cta: 0.08,
  countdown: 0.06,
  cover_reveal: 0.03,
  giveaway: 0.05,
  review_highlight: 0.05,
  quote_card: 0.02,
  teaser: 0.03,
  trope_appeal: 0.035,
  character_spotlight: 0.02,
  behind_the_scenes: 0.015,
  question_engagement: 0.01,
  milestone: 0.02,
};

function jitter(rng, spread = 0.35) {
  return 1 - spread / 2 + rng() * spread;
}

export function computeSimulatedMetrics(post, { reach = 3000 } = {}) {
  const rng = seededRandom(hashString(`${post.id || post.type}:${post.platform}`));
  const impressionFactor = IMPRESSION_FACTOR[post.platform] ?? 1;
  const typeReach = TYPE_REACH[post.type] ?? 1;

  const impressions = Math.round(reach * impressionFactor * typeReach * jitter(rng));
  const likeRate = (0.02 + rng() * 0.03) * (post.platform === 'tiktok' ? 1.4 : 1);
  const likes = Math.round(impressions * likeRate);
  const comments = Math.round(likes * (0.05 + rng() * 0.08));
  const shares = Math.round(likes * (0.08 + rng() * 0.1));
  const clickRate = (CLICK_RATE[post.type] ?? 0.02) * jitter(rng, 0.2);
  const clicks = Math.round(impressions * clickRate);

  return {
    impressions: Math.max(0, impressions),
    likes: Math.max(0, likes),
    comments: Math.max(0, comments),
    shares: Math.max(0, shares),
    clicks: Math.max(0, clicks),
  };
}

export const simulatedPublisher = {
  name: 'simulated',
  simulated: true,
  async publish(post, context = {}) {
    const metrics = computeSimulatedMetrics(post, context);
    const externalId = `sim_${hashString(`${post.id}:${post.scheduledAt || ''}`).toString(16)}`;
    return {
      externalId,
      publishedAt: (context.now ? new Date(context.now) : new Date()).toISOString(),
      metrics,
    };
  },
};
