import { simulatedPublisher } from './simulated.js';

/**
 * Publisher registry. A publisher is any object of the shape:
 *
 *   {
 *     name: string,
 *     simulated?: boolean,
 *     async publish(post, context): { externalId, publishedAt, metrics? }
 *   }
 *
 * Real network adapters (X/Twitter, Meta Graph API, TikTok, an ESP webhook, …)
 * register here — typically constructed from credentials in the environment —
 * and the active one is chosen in store settings. Because they all share this
 * contract, the scheduler is agnostic to which is in use.
 */

const registry = new Map();

export function registerPublisher(publisher) {
  if (!publisher || typeof publisher.publish !== 'function' || !publisher.name) {
    throw new Error('A publisher must have a name and an async publish(post, context) method');
  }
  registry.set(publisher.name, publisher);
  return publisher;
}

export function getPublisher(name) {
  return registry.get(name) || null;
}

export function listPublishers() {
  return [...registry.values()].map((p) => ({ name: p.name, simulated: !!p.simulated }));
}

export function getActivePublisher(store) {
  const name = store.getSettings()?.activePublisher || 'simulated';
  return getPublisher(name) || getPublisher('simulated');
}

// Built-in publisher(s).
registerPublisher(simulatedPublisher);

export { simulatedPublisher };
