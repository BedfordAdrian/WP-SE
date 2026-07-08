import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createStore } from './db/store.js';
import { createApp } from './server.js';
import { startScheduler } from './services/scheduler.js';
import { seed } from './db/seed.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const PORT = Number(process.env.PORT) || 4000;
const DATA_FILE = process.env.AUTHORLIFT_DATA || path.join(__dirname, '..', 'data', 'authorlift.json');
const SCHEDULER_INTERVAL = Number(process.env.SCHEDULER_INTERVAL_MS) || 60_000;

async function main() {
  const store = createStore({ file: DATA_FILE });

  // First run: seed a demo author so the dashboard is immediately useful.
  if (!store.getAuthor() && process.env.AUTHORLIFT_NO_SEED !== '1') {
    console.log('No data found — seeding a demo author (set AUTHORLIFT_NO_SEED=1 to skip)…');
    await seed(store);
  }

  const app = createApp(store);
  const scheduler = startScheduler(store, {
    intervalMs: SCHEDULER_INTERVAL,
    onError: (err) => console.error('[scheduler]', err.message),
  });

  const server = app.listen(PORT, () => {
    console.log(`\n  AuthorLift is running`);
    console.log(`  Dashboard: http://localhost:${PORT}`);
    console.log(`  API:       http://localhost:${PORT}/api`);
    console.log(`  Data file: ${DATA_FILE}`);
    console.log(`  Scheduler: every ${Math.round(SCHEDULER_INTERVAL / 1000)}s\n`);
  });

  const shutdown = (signal) => {
    console.log(`\n${signal} received — shutting down…`);
    scheduler.stop();
    server.close(() => {
      store.close();
      process.exit(0);
    });
    // Force-exit if close hangs.
    setTimeout(() => process.exit(0), 3000).unref();
  };
  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));
}

main().catch((err) => {
  console.error('Failed to start AuthorLift:', err);
  process.exit(1);
});
