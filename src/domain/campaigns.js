import {
  requireString,
  optionalString,
  requireIsoDate,
  optionalIsoDate,
  requireOneOf,
  optionalNumber,
  assert,
} from '../utils/validate.js';

export const CAMPAIGN_TYPES = ['launch', 'preorder', 'sale', 'newsletter_growth', 'evergreen'];
export const CAMPAIGN_STATUSES = ['planning', 'active', 'completed', 'archived'];
export const GOAL_METRICS = ['sales', 'subscribers', 'followers', 'engagement'];

export function normalizeCampaign(input = {}, existing = {}) {
  const merged = { ...existing, ...input };

  const goal = merged.goal
    ? {
        metric: requireOneOf(merged.goal.metric, GOAL_METRICS, 'goal.metric'),
        target: optionalNumber(merged.goal.target, 'goal.target', { min: 0 }) ?? 0,
      }
    : null;

  const startDate = requireIsoDate(merged.startDate, 'startDate');
  const endDate = optionalIsoDate(merged.endDate, 'endDate');
  if (endDate) {
    assert(Date.parse(endDate) >= Date.parse(startDate), 'endDate must be on or after startDate', 'endDate');
  }

  return {
    name: requireString(merged.name, 'name', { max: 200 }),
    type: merged.type ? requireOneOf(merged.type, CAMPAIGN_TYPES, 'type') : 'launch',
    bookId: merged.bookId ?? null,
    startDate,
    endDate,
    goal,
    status: merged.status ? requireOneOf(merged.status, CAMPAIGN_STATUSES, 'status') : 'planning',
    notes: optionalString(merged.notes, 'notes', { max: 2000 }),
  };
}

export function listCampaigns(store) {
  return store
    .all('campaigns')
    .slice()
    .sort((a, b) => Date.parse(b.startDate) - Date.parse(a.startDate));
}

export function getCampaign(store, id) {
  return store.get('campaigns', id);
}

export function createCampaign(store, input) {
  if (input.bookId) {
    assert(store.get('books', input.bookId), 'bookId does not reference an existing book', 'bookId');
  }
  const campaign = normalizeCampaign(input);
  return store.insert('campaigns', campaign);
}

export function updateCampaign(store, id, input) {
  const existing = store.get('campaigns', id);
  if (!existing) return null;
  if (input.bookId) {
    assert(store.get('books', input.bookId), 'bookId does not reference an existing book', 'bookId');
  }
  const campaign = normalizeCampaign(input, existing);
  return store.update('campaigns', id, campaign);
}

export function deleteCampaign(store, id, { deletePosts = false } = {}) {
  const posts = store.find('posts', (p) => p.campaignId === id);
  for (const post of posts) {
    if (deletePosts && post.status !== 'published') {
      store.remove('posts', post.id);
    } else {
      store.update('posts', post.id, { campaignId: null });
    }
  }
  return store.remove('campaigns', id);
}
