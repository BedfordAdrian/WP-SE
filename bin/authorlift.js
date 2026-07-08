#!/usr/bin/env node
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createStore } from '../src/db/store.js';
import { seed } from '../src/db/seed.js';
import { listBooks } from '../src/domain/books.js';
import { listCampaigns } from '../src/domain/campaigns.js';
import { listPosts } from '../src/domain/posts.js';
import { generateContent } from '../src/services/contentStudio.js';
import { planCampaign } from '../src/services/campaignPlanner.js';
import { runDuePosts } from '../src/services/scheduler.js';
import { overview, campaignReport } from '../src/services/metrics.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_FILE = process.env.AUTHORLIFT_DATA || path.join(__dirname, '..', 'data', 'authorlift.json');

function parseFlags(args) {
  const flags = {};
  const positional = [];
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg.startsWith('--')) {
      const key = arg.slice(2);
      const next = args[i + 1];
      if (next === undefined || next.startsWith('--')) {
        flags[key] = true;
      } else {
        flags[key] = next;
        i++;
      }
    } else {
      positional.push(arg);
    }
  }
  return { flags, positional };
}

const USAGE = `
AuthorLift — social media marketing for authors

Usage: authorlift <command> [options]

Commands:
  seed [--force]                     Seed the demo author/books/campaigns
  overview                           Print the marketing dashboard summary
  books                              List books
  campaigns                          List campaigns
  posts [--status s] [--campaignId]  List posts
  plan <campaignId> [--dry]          Generate a campaign posting schedule
  generate --type <t> [--book <id>] [--platform <p>]
                                     Generate one post's content
  run-due                            Publish any posts whose time has come
  report <campaignId>                Print a campaign performance report
  serve                              Start the web dashboard + API

Environment:
  AUTHORLIFT_DATA   Path to the JSON data file (default: ./data/authorlift.json)
`;

async function main() {
  const [command, ...rest] = process.argv.slice(2);
  const { flags, positional } = parseFlags(rest);

  if (!command || command === 'help' || flags.help) {
    console.log(USAGE);
    return;
  }

  if (command === 'serve') {
    await import('../src/index.js');
    return;
  }

  const store = createStore({ file: DATA_FILE });

  switch (command) {
    case 'seed': {
      if (store.getAuthor() && !flags.force) {
        console.error('Data already exists. Re-run with --force to overwrite.');
        process.exitCode = 1;
        return;
      }
      const result = await seed(store);
      console.log('Seeded:', JSON.stringify(result, null, 2));
      break;
    }
    case 'overview':
      console.log(JSON.stringify(overview(store), null, 2));
      break;
    case 'books':
      printTable(listBooks(store), ['id', 'title', 'status', 'releaseDate']);
      break;
    case 'campaigns':
      printTable(listCampaigns(store), ['id', 'name', 'type', 'status', 'startDate']);
      break;
    case 'posts':
      printTable(
        listPosts(store, { status: flags.status, campaignId: flags.campaignId }),
        ['id', 'platform', 'type', 'status', 'scheduledAt'],
      );
      break;
    case 'plan': {
      const id = positional[0];
      if (!id) return fail('plan requires a <campaignId>');
      const result = planCampaign(store, id, { dryRun: !!flags.dry });
      if (!result) return fail(`No campaign with id ${id}`);
      store.flush();
      console.log(JSON.stringify(result.summary, null, 2));
      console.log(`\n${flags.dry ? 'Would create' : 'Created'} ${result.posts.length} posts.`);
      break;
    }
    case 'generate': {
      if (!flags.type) return fail('generate requires --type');
      const content = generateContent(store, {
        bookId: flags.book,
        postType: flags.type,
        platform: flags.platform || 'twitter',
      });
      console.log(`\n[${content.platform} · ${content.type}] ${content.emoji}\n`);
      console.log(content.preview);
      console.log(`\nMedia: ${content.mediaSuggestion}`);
      break;
    }
    case 'run-due': {
      const result = await runDuePosts(store);
      store.flush();
      console.log(`Published ${result.published.length}, failed ${result.failed.length}.`);
      break;
    }
    case 'report': {
      const id = positional[0];
      if (!id) return fail('report requires a <campaignId>');
      const report = campaignReport(store, id);
      if (!report) return fail(`No campaign with id ${id}`);
      console.log(JSON.stringify(report, null, 2));
      break;
    }
    default:
      console.error(`Unknown command: ${command}`);
      console.log(USAGE);
      process.exitCode = 1;
  }
}

function fail(message) {
  console.error(message);
  process.exitCode = 1;
}

function printTable(rows, columns) {
  if (!rows.length) {
    console.log('(none)');
    return;
  }
  for (const row of rows) {
    console.log(columns.map((c) => `${c}=${format(row[c])}`).join('  '));
  }
  console.log(`\n${rows.length} row(s).`);
}

function format(value) {
  if (value === null || value === undefined) return '-';
  return String(value);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
