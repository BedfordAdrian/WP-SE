import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { getAuthor, saveAuthor, PLATFORMS } from './domain/author.js';
import {
  listBooks, getBook, createBook, updateBook, deleteBook, BOOK_STATUSES, GENRES,
} from './domain/books.js';
import {
  listCampaigns, getCampaign, createCampaign, updateCampaign, deleteCampaign,
  CAMPAIGN_TYPES, CAMPAIGN_STATUSES, GOAL_METRICS,
} from './domain/campaigns.js';
import {
  listPosts, getPost, createPost, updatePost, deletePost, schedulePost,
  POST_TYPES, POST_STATUSES,
} from './domain/posts.js';
import {
  recordSale, listSales, recordFollowers, recordSubscribers, SALES_CHANNELS,
} from './domain/audience.js';
import { generateContent, PLATFORM_LIMITS } from './services/contentStudio.js';
import { planCampaign } from './services/campaignPlanner.js';
import { publishPost } from './services/scheduler.js';
import { suggestHashtags, bestTimesFor } from './services/recommendations.js';
import {
  overview, campaignReport, salesTimeseries,
} from './services/metrics.js';
import { listFollowerSnapshots, listSubscriberSnapshots } from './domain/audience.js';
import { listPublishers, getPublisher } from './services/publishers/index.js';
import { TZ_OFFSETS } from './utils/time.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC_DIR = path.join(__dirname, '..', 'public');

const wrap = (fn) => async (req, res, next) => {
  try {
    await fn(req, res, next);
  } catch (err) {
    next(err);
  }
};

const notFound = (res, what = 'Resource') => res.status(404).json({ error: `${what} not found` });

export function createApp(store) {
  const app = express();
  app.use(express.json({ limit: '1mb' }));

  const api = express.Router();

  // ---- Health & reference metadata ----------------------------------------
  api.get('/health', (req, res) => res.json({ ok: true, time: new Date().toISOString() }));

  api.get('/meta', (req, res) => {
    res.json({
      version: '1.2.2',
      platforms: PLATFORMS,
      postTypes: POST_TYPES,
      postStatuses: POST_STATUSES,
      genres: GENRES,
      campaignTypes: CAMPAIGN_TYPES,
      campaignStatuses: CAMPAIGN_STATUSES,
      goalMetrics: GOAL_METRICS,
      bookStatuses: BOOK_STATUSES,
      salesChannels: SALES_CHANNELS,
      timezones: Object.keys(TZ_OFFSETS),
      platformLimits: PLATFORM_LIMITS,
    });
  });

  // ---- Author --------------------------------------------------------------
  api.get('/author', (req, res) => res.json(getAuthor(store)));
  api.put('/author', wrap(async (req, res) => res.json(saveAuthor(store, req.body || {}))));

  // ---- Books ---------------------------------------------------------------
  api.get('/books', (req, res) => res.json(listBooks(store)));
  api.post('/books', wrap(async (req, res) => res.status(201).json(createBook(store, req.body || {}))));
  api.get('/books/:id', (req, res) => {
    const book = getBook(store, req.params.id);
    return book ? res.json(book) : notFound(res, 'Book');
  });
  api.put('/books/:id', wrap(async (req, res) => {
    const updated = updateBook(store, req.params.id, req.body || {});
    return updated ? res.json(updated) : notFound(res, 'Book');
  }));
  api.delete('/books/:id', (req, res) => {
    const ok = deleteBook(store, req.params.id);
    return ok ? res.status(204).end() : notFound(res, 'Book');
  });

  // ---- Campaigns -----------------------------------------------------------
  api.get('/campaigns', (req, res) => res.json(listCampaigns(store)));
  api.post('/campaigns', wrap(async (req, res) => res.status(201).json(createCampaign(store, req.body || {}))));
  api.get('/campaigns/:id', (req, res) => {
    const campaign = getCampaign(store, req.params.id);
    return campaign ? res.json(campaign) : notFound(res, 'Campaign');
  });
  api.put('/campaigns/:id', wrap(async (req, res) => {
    const updated = updateCampaign(store, req.params.id, req.body || {});
    return updated ? res.json(updated) : notFound(res, 'Campaign');
  }));
  api.delete('/campaigns/:id', (req, res) => {
    const deletePosts = req.query.deletePosts === 'true';
    const ok = deleteCampaign(store, req.params.id, { deletePosts });
    return ok ? res.status(204).end() : notFound(res, 'Campaign');
  });
  api.post('/campaigns/:id/plan', wrap(async (req, res) => {
    const dryRun = !!(req.body && req.body.dryRun);
    const result = planCampaign(store, req.params.id, { dryRun });
    return result ? res.json(result) : notFound(res, 'Campaign');
  }));
  api.get('/campaigns/:id/report', (req, res) => {
    const report = campaignReport(store, req.params.id);
    return report ? res.json(report) : notFound(res, 'Campaign');
  });

  // ---- Posts ---------------------------------------------------------------
  api.get('/posts', (req, res) => {
    const { status, platform, campaignId, bookId } = req.query;
    res.json(listPosts(store, { status, platform, campaignId, bookId }));
  });
  api.post('/posts', wrap(async (req, res) => res.status(201).json(createPost(store, req.body || {}))));
  api.get('/posts/:id', (req, res) => {
    const post = getPost(store, req.params.id);
    return post ? res.json(post) : notFound(res, 'Post');
  });
  api.put('/posts/:id', wrap(async (req, res) => {
    const updated = updatePost(store, req.params.id, req.body || {});
    return updated ? res.json(updated) : notFound(res, 'Post');
  }));
  api.delete('/posts/:id', (req, res) => {
    const ok = deletePost(store, req.params.id);
    return ok ? res.status(204).end() : notFound(res, 'Post');
  });
  api.post('/posts/:id/schedule', wrap(async (req, res) => {
    const updated = schedulePost(store, req.params.id, req.body && req.body.scheduledAt);
    return updated ? res.json(updated) : notFound(res, 'Post');
  }));
  api.post('/posts/:id/publish', wrap(async (req, res) => {
    const post = getPost(store, req.params.id);
    if (!post) return notFound(res, 'Post');
    const updated = await publishPost(store, req.params.id, { now: new Date() });
    return res.json(updated);
  }));

  // ---- Content Studio ------------------------------------------------------
  api.post('/content/generate', wrap(async (req, res) => {
    res.json(generateContent(store, req.body || {}));
  }));

  // ---- Calendar ------------------------------------------------------------
  api.get('/calendar', (req, res) => {
    const { from, to } = req.query;
    const fromMs = from ? Date.parse(from) : -Infinity;
    const toMs = to ? Date.parse(to) : Infinity;
    const posts = store
      .find('posts', (p) => {
        if (!p.scheduledAt) return false;
        const t = Date.parse(p.scheduledAt);
        return t >= fromMs && t <= toMs;
      })
      .sort((a, b) => Date.parse(a.scheduledAt) - Date.parse(b.scheduledAt));
    res.json(posts);
  });

  // ---- Sales & audience ----------------------------------------------------
  api.get('/sales', (req, res) => res.json(listSales(store, req.query)));
  api.post('/sales', wrap(async (req, res) => res.status(201).json(recordSale(store, req.body || {}))));
  api.post('/followers', wrap(async (req, res) => res.status(201).json(recordFollowers(store, req.body || {}))));
  api.post('/subscribers', wrap(async (req, res) => res.status(201).json(recordSubscribers(store, req.body || {}))));

  // ---- Analytics -----------------------------------------------------------
  api.get('/analytics/overview', (req, res) => res.json(overview(store)));
  api.get('/analytics/sales-timeseries', (req, res) => {
    const { bookId, from, to } = req.query;
    res.json(salesTimeseries(store, { bookId, from, to }));
  });
  api.get('/analytics/followers', (req, res) => res.json(listFollowerSnapshots(store, { platform: req.query.platform })));
  api.get('/analytics/subscribers', (req, res) => res.json(listSubscriberSnapshots(store)));

  // ---- Recommendations -----------------------------------------------------
  api.get('/recommendations/hashtags', (req, res) => {
    const book = req.query.bookId ? getBook(store, req.query.bookId) : {};
    res.json(suggestHashtags(book || {}, req.query.platform || 'twitter'));
  });
  api.get('/recommendations/best-times', (req, res) => {
    res.json(bestTimesFor(req.query.platform || 'twitter'));
  });

  // ---- Settings & publishers ----------------------------------------------
  api.get('/settings', (req, res) => res.json(store.getSettings()));
  api.put('/settings', wrap(async (req, res) => {
    const patch = req.body || {};
    if (patch.activePublisher && !getPublisher(patch.activePublisher)) {
      return res.status(400).json({ error: `Unknown publisher: ${patch.activePublisher}` });
    }
    if (patch.currencySymbol !== undefined && !['$', '£', '€'].includes(patch.currencySymbol)) {
      return res.status(400).json({ error: 'Unsupported currency', field: 'currencySymbol' });
    }
    res.json(store.updateSettings(patch));
  }));
  api.get('/publishers', (req, res) => res.json(listPublishers()));

  // Clear sample/demo data and exit demo mode, keeping the author profile,
  // currency and active publisher. Used by the "Start fresh" action.
  api.post('/data/reset', wrap(async (req, res) => {
    const author = store.getAuthor();
    const settings = store.getSettings();
    store.reset();
    if (author) store.setAuthor(author);
    store.updateSettings({
      demoData: false,
      currencySymbol: settings.currencySymbol || '$',
      activePublisher: settings.activePublisher || 'simulated',
    });
    res.json({ ok: true, settings: store.getSettings() });
  }));

  app.use('/api', api);

  // Static dashboard.
  app.use(express.static(PUBLIC_DIR));

  // API 404 (must come after routes, before error handler).
  app.use('/api', (req, res) => res.status(404).json({ error: 'Not found' }));

  // Central error handler.
  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    const status = err.status || 500;
    const payload = { error: err.message || 'Internal error' };
    if (err.field) payload.field = err.field;
    if (status >= 500) payload.error = 'Internal server error';
    res.status(status).json(payload);
  });

  return app;
}
