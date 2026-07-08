import { randomUUID } from 'node:crypto';

/**
 * Generate a short, human-friendly, collision-resistant id with a type prefix.
 * e.g. newId('post') -> 'post_9f8c1a2b'
 */
export function newId(prefix = 'id') {
  return `${prefix}_${randomUUID().replace(/-/g, '').slice(0, 12)}`;
}

/**
 * A small, fast, seedable PRNG (mulberry32). Used so that generated engagement
 * metrics and content-variant selection are reproducible in tests while still
 * looking organic in production.
 */
export function seededRandom(seed) {
  let a = seed >>> 0;
  return function next() {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * Deterministic 32-bit hash of a string (FNV-1a). Handy for turning an id into
 * a stable seed.
 */
export function hashString(str) {
  let h = 0x811c9dc5;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return h >>> 0;
}
