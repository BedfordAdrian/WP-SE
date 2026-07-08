import fs from 'node:fs';
import path from 'node:path';
import { newId } from '../utils/id.js';
import { nowIso } from '../utils/time.js';

/**
 * A minimal, dependency-free document store backed by a single JSON file.
 *
 * Chosen over SQLite so the project installs and runs with zero native build
 * steps in any environment. Writes are atomic (write-temp-then-rename) and
 * debounced so bursts of mutations don't thrash the disk. Pass `{ file: null }`
 * for an in-memory store (used by the test suite).
 */

const COLLECTIONS = [
  'books',
  'campaigns',
  'posts',
  'sales',
  'followerSnapshots',
  'subscriberSnapshots',
];

function emptyData() {
  const data = {
    author: null,
    settings: { activePublisher: 'simulated' },
  };
  for (const c of COLLECTIONS) data[c] = [];
  return data;
}

export function createStore({ file = null } = {}) {
  let data = emptyData();
  let writeTimer = null;
  let closed = false;

  if (file && fs.existsSync(file)) {
    try {
      const raw = fs.readFileSync(file, 'utf8');
      const parsed = JSON.parse(raw);
      data = { ...emptyData(), ...parsed };
      // Guard against a truncated/legacy file missing newer collections.
      for (const c of COLLECTIONS) if (!Array.isArray(data[c])) data[c] = [];
    } catch (err) {
      // A corrupt/truncated file must not brick the app at boot. Preserve the
      // bad file for inspection, warn, and start from an empty store (which will
      // then trigger first-run seeding upstream).
      const backup = `${file}.corrupt-${process.pid}`;
      try { fs.renameSync(file, backup); } catch { /* best effort */ }
      console.warn(`[store] Could not parse ${file} (${err.message}). Moved to ${backup} and started fresh.`);
      data = emptyData();
    }
  }

  function flushNow() {
    if (!file || closed) return;
    const dir = path.dirname(file);
    fs.mkdirSync(dir, { recursive: true });
    const tmp = `${file}.${process.pid}.tmp`;
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2));
    fs.renameSync(tmp, file);
  }

  function scheduleWrite() {
    if (!file || closed) return;
    if (writeTimer) return;
    writeTimer = setTimeout(() => {
      writeTimer = null;
      flushNow();
    }, 50);
    // Don't keep the event loop alive solely for a pending write.
    if (typeof writeTimer.unref === 'function') writeTimer.unref();
  }

  const api = {
    /** Force a synchronous flush to disk (used on shutdown and after seeding). */
    flush() {
      if (writeTimer) {
        clearTimeout(writeTimer);
        writeTimer = null;
      }
      flushNow();
    },

    close() {
      api.flush();
      closed = true;
    },

    /** Raw snapshot — read only. */
    raw() {
      return data;
    },

    reset() {
      data = emptyData();
      scheduleWrite();
    },

    // ---- Author (singleton) -------------------------------------------------
    getAuthor() {
      return data.author;
    },
    setAuthor(author) {
      data.author = author;
      scheduleWrite();
      return data.author;
    },

    // ---- Settings -----------------------------------------------------------
    getSettings() {
      return data.settings;
    },
    updateSettings(patch) {
      data.settings = { ...data.settings, ...patch };
      scheduleWrite();
      return data.settings;
    },

    // ---- Generic collection access -----------------------------------------
    all(collection) {
      assertCollection(collection);
      return data[collection];
    },

    find(collection, predicate) {
      assertCollection(collection);
      return data[collection].filter(predicate);
    },

    get(collection, id) {
      assertCollection(collection);
      return data[collection].find((doc) => doc.id === id) ?? null;
    },

    insert(collection, doc) {
      assertCollection(collection);
      const prefix = collection.replace(/s$/, '').replace(/Snapshot$/, '_snap');
      const record = {
        id: doc.id || newId(prefix),
        createdAt: doc.createdAt || nowIso(),
        updatedAt: nowIso(),
        ...doc,
      };
      // Ensure id/createdAt are not overwritten by a spread above.
      record.id = doc.id || record.id;
      data[collection].push(record);
      scheduleWrite();
      return record;
    },

    update(collection, id, patch) {
      assertCollection(collection);
      const doc = api.get(collection, id);
      if (!doc) return null;
      Object.assign(doc, patch, { id: doc.id, updatedAt: nowIso() });
      scheduleWrite();
      return doc;
    },

    remove(collection, id) {
      assertCollection(collection);
      const idx = data[collection].findIndex((doc) => doc.id === id);
      if (idx === -1) return false;
      data[collection].splice(idx, 1);
      scheduleWrite();
      return true;
    },
  };

  function assertCollection(collection) {
    if (!COLLECTIONS.includes(collection)) {
      throw new Error(`Unknown collection: ${collection}`);
    }
  }

  return api;
}

export { COLLECTIONS };
