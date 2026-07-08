import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createStore } from '../src/db/store.js';

test('in-memory store inserts with generated id and timestamps', () => {
  const store = createStore({ file: null });
  const book = store.insert('books', { title: 'X' });
  assert.match(book.id, /^book_/);
  assert.ok(book.createdAt);
  assert.ok(book.updatedAt);
  assert.equal(store.all('books').length, 1);
});

test('get / update / remove round-trip', () => {
  const store = createStore({ file: null });
  const b = store.insert('books', { title: 'Original' });
  assert.equal(store.get('books', b.id).title, 'Original');

  const updated = store.update('books', b.id, { title: 'Renamed' });
  assert.equal(updated.title, 'Renamed');
  assert.equal(updated.id, b.id, 'id is preserved across update');

  assert.equal(store.remove('books', b.id), true);
  assert.equal(store.get('books', b.id), null);
  assert.equal(store.remove('books', b.id), false);
});

test('update on missing doc returns null', () => {
  const store = createStore({ file: null });
  assert.equal(store.update('books', 'nope', { title: 'x' }), null);
});

test('unknown collection throws', () => {
  const store = createStore({ file: null });
  assert.throws(() => store.all('widgets'), /Unknown collection/);
});

test('a corrupt data file self-heals instead of crashing boot', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'authorlift-'));
  const file = path.join(dir, 'store.json');
  fs.writeFileSync(file, '{ this is : not valid json ,,,');
  let store;
  assert.doesNotThrow(() => { store = createStore({ file }); });
  assert.equal(store.getAuthor(), null, 'starts empty so first-run seeding can proceed');
  assert.ok(fs.readdirSync(dir).some((f) => f.includes('corrupt')), 'bad file preserved for inspection');
  store.close();
  fs.rmSync(dir, { recursive: true, force: true });
});

test('author and settings singletons persist in memory', () => {
  const store = createStore({ file: null });
  assert.equal(store.getAuthor(), null);
  store.setAuthor({ penName: 'A' });
  assert.equal(store.getAuthor().penName, 'A');
  assert.equal(store.getSettings().activePublisher, 'simulated');
  store.updateSettings({ activePublisher: 'x' });
  assert.equal(store.getSettings().activePublisher, 'x');
});
