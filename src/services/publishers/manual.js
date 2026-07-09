import { hashString } from '../../utils/id.js';

/**
 * The Manual publisher. Use this when you post to your networks yourself and
 * just want AuthorLift to track the schedule and the fact that a post went out.
 * It marks the post published WITHOUT fabricating any engagement — metrics stay
 * whatever they were (0 until you record real numbers). This is the honest
 * choice once you're live but haven't wired up an automated API adapter.
 */
export const manualPublisher = {
  name: 'manual',
  simulated: false,
  async publish(post, context = {}) {
    return {
      externalId: `manual_${hashString(`${post.id}`).toString(16)}`,
      publishedAt: (context.now ? new Date(context.now) : new Date()).toISOString(),
      metrics: post.metrics, // no fabrication
    };
  },
};
