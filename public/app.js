// AuthorLift dashboard — dependency-free SPA.
// Talks to the REST API in src/server.js. All dynamic text is escaped before
// being inserted into the DOM.

// ---------------------------------------------------------------- API client
const api = {
  async req(method, path, body) {
    const opts = { method, headers: {} };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(`/api${path}`, opts);
    if (res.status === 204) return null;
    const text = await res.text();
    const data = text ? JSON.parse(text) : null;
    if (!res.ok) {
      const err = new Error((data && data.error) || `Request failed (${res.status})`);
      err.field = data && data.field;
      throw err;
    }
    return data;
  },
  get(p) { return this.req('GET', p); },
  post(p, b) { return this.req('POST', p, b); },
  put(p, b) { return this.req('PUT', p, b); },
  del(p) { return this.req('DELETE', p); },
};

// Version baked into this JS file. Compared against the server version (which is
// delivered inline and therefore never cached) to detect a stale cached
// dashboard — the usual cause of "the new option isn't showing up".
const APP_VERSION = '1.2.0';
const AL_SERVER_VERSION = (typeof AuthorLiftConfig !== 'undefined' && AuthorLiftConfig.serverVersion) || null;

// ---------------------------------------------------------------- utilities
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const main = $('#main');

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function fmtNum(n) {
  if (n === null || n === undefined) return '—';
  return Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 });
}
function fmtMoney(n) {
  if (n === null || n === undefined) return '—';
  const symbol = (state.settings && state.settings.currencySymbol) || '$';
  return symbol + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtPct(n) {
  if (n === null || n === undefined) return '—';
  return (n > 0 ? '+' : '') + Number(n).toFixed(1) + '%';
}
function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
function fmtDateTime(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
function fmtTime(d) {
  return new Date(d).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}
function titleCase(s) {
  return String(s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
const PLATFORM_ICON = { twitter: 'Twitter/X', bluesky: 'Bluesky', instagram: 'Instagram', facebook: 'Facebook', tiktok: 'TikTok', threads: 'Threads', newsletter: 'Newsletter' };
const PLATFORM_COLOR = { twitter: '#1d9bf0', bluesky: '#0085ff', instagram: '#e1306c', facebook: '#1877f2', tiktok: '#25f4ee', threads: '#b478f6', newsletter: '#f59e0b' };
const LINK_LABELS = { universal: 'Books2Read universal', booklinker: 'Booklinker (all Amazon)', linktree: 'Linktree', signed: 'Signed copies (webshop)', amazon: 'Amazon', apple: 'Apple Books', kobo: 'Kobo', barnesnoble: 'Barnes & Noble', audible: 'Audible' };

function platformTag(p) {
  return `<span class="plat"><span class="dot dot-${esc(p)}"></span>${esc(PLATFORM_ICON[p] || p)}</span>`;
}
function statusBadge(status) {
  const map = { published: 'good', scheduled: 'accent', draft: '', failed: 'bad', active: 'good', planning: 'warn', completed: 'accent', archived: '' };
  return `<span class="badge ${map[status] || ''}">${esc(titleCase(status))}</span>`;
}

// ---------------------------------------------------------------- toast/modal
function toast(msg, type = 'good') {
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = msg;
  $('#toast').appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, 3200);
}
function openModal(html) {
  const root = $('#modal-root');
  root.innerHTML = `<div class="modal-backdrop" id="mbd"><div class="modal">${html}</div></div>`;
  $('#mbd').addEventListener('click', (e) => { if (e.target.id === 'mbd') closeModal(); });
}
function closeModal() { $('#modal-root').innerHTML = ''; }

// ---------------------------------------------------------------- charts (SVG)
// Only allow hex, CSS custom properties, or bare color names into SVG
// stroke/fill/style attributes — never raw data-derived strings.
function safeColor(c) {
  return /^(#[0-9a-fA-F]{3,8}|var\(--[\w-]+\)|[a-zA-Z]+)$/.test(String(c)) ? c : 'var(--accent)';
}
const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

function niceMax(v) {
  if (v <= 0) return 1;
  const mag = Math.pow(10, Math.floor(Math.log10(v)));
  const norm = v / mag;
  const step = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
  return step * mag;
}

// Multi-series time chart. series: [{name,color,data:[{date,value}]}].
function timeChart(series, { height = 240, marker = null, valueFmt = fmtNum } = {}) {
  const W = 760, H = height, padL = 52, padR = 16, padT = 14, padB = 28;
  const all = series.flatMap((s) => s.data);
  if (!all.length) return `<div class="empty">No data yet.</div>`;
  const times = all.map((d) => new Date(d.date).getTime());
  const minT = Math.min(...times), maxT = Math.max(...times) || minT + 1;
  const maxV = niceMax(Math.max(1, ...all.map((d) => num(d.value))));
  const x = (t) => padL + ((new Date(t).getTime() - minT) / (maxT - minT || 1)) * (W - padL - padR);
  const y = (v) => H - padB - (v / maxV) * (H - padT - padB);

  let grid = '';
  for (let i = 0; i <= 4; i++) {
    const gv = (maxV / 4) * i;
    const gy = y(gv);
    grid += `<line x1="${padL}" y1="${gy}" x2="${W - padR}" y2="${gy}" stroke="var(--border)" stroke-width="1"/>`;
    grid += `<text x="${padL - 8}" y="${gy + 4}" text-anchor="end" font-size="11" fill="var(--text-faint)">${esc(valueFmt(Math.round(gv)))}</text>`;
  }
  // x labels (start, mid, end)
  let xlabels = '';
  [0, 0.5, 1].forEach((f) => {
    const t = minT + (maxT - minT) * f;
    xlabels += `<text x="${x(t)}" y="${H - 8}" text-anchor="middle" font-size="11" fill="var(--text-faint)">${esc(fmtDate(t))}</text>`;
  });

  let paths = '';
  for (const s of series) {
    if (!s.data.length) continue;
    const pts = s.data.map((d) => `${x(d.date).toFixed(1)},${y(num(d.value)).toFixed(1)}`).join(' ');
    paths += `<polyline fill="none" stroke="${safeColor(s.color)}" stroke-width="2.5" stroke-linejoin="round" points="${pts}"/>`;
  }

  let markerEl = '';
  if (marker) {
    const mx = x(marker.date);
    markerEl = `<line x1="${mx}" y1="${padT}" x2="${mx}" y2="${H - padB}" stroke="var(--accent-2)" stroke-width="1.5" stroke-dasharray="4 4"/>
      <text x="${mx}" y="${padT + 2}" text-anchor="middle" font-size="10" fill="var(--accent-2)">${esc(marker.label)}</text>`;
  }

  const legend = series.length > 1
    ? `<div class="chart-legend">${series.map((s) => `<span class="lg"><span class="sw" style="background:${safeColor(s.color)}"></span>${esc(s.name)}</span>`).join('')}</div>`
    : '';

  return `<svg class="chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet">${grid}${markerEl}${paths}${xlabels}</svg>${legend}`;
}

function barChart(data, { height = 220, color = 'var(--accent)', valueFmt = fmtNum } = {}) {
  const W = 760, H = height, padL = 52, padR = 16, padT = 12, padB = 46;
  if (!data.length) return `<div class="empty">No data yet.</div>`;
  const maxV = niceMax(Math.max(1, ...data.map((d) => num(d.value))));
  const bw = (W - padL - padR) / data.length;
  const y = (v) => H - padB - (v / maxV) * (H - padT - padB);
  let grid = '';
  for (let i = 0; i <= 4; i++) {
    const gy = y((maxV / 4) * i);
    grid += `<line x1="${padL}" y1="${gy}" x2="${W - padR}" y2="${gy}" stroke="var(--border)"/>`;
    grid += `<text x="${padL - 8}" y="${gy + 4}" text-anchor="end" font-size="11" fill="var(--text-faint)">${esc(valueFmt(Math.round((maxV / 4) * i)))}</text>`;
  }
  let bars = '';
  data.forEach((d, i) => {
    const v = num(d.value);
    const h = H - padB - y(v);
    const bx = padL + i * bw + bw * 0.15;
    bars += `<rect x="${bx}" y="${y(v)}" width="${bw * 0.7}" height="${Math.max(0, h)}" rx="3" fill="${safeColor(color)}"><title>${esc(d.label)}: ${esc(valueFmt(v))}</title></rect>`;
    if (data.length <= 14) {
      bars += `<text x="${bx + bw * 0.35}" y="${H - padB + 16}" text-anchor="middle" font-size="10" fill="var(--text-faint)" transform="rotate(35 ${bx + bw * 0.35} ${H - padB + 16})">${esc(d.label)}</text>`;
    }
  });
  return `<svg class="chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet">${grid}${bars}</svg>`;
}

// ---------------------------------------------------------------- state
const state = { meta: null, author: null, settings: null };

// True when the numbers on screen are not real: either demo seed data or the
// simulated publisher is active. Drives the disclosure banner and badges.
function isSampleData() {
  const s = state.settings || {};
  return !!s.demoData || s.activePublisher === 'simulated';
}

// A prominent, honest disclosure shown on every screen that displays metrics.
function disclosureBanner() {
  if (!isSampleData()) return '';
  const reason = (state.settings && state.settings.demoData)
    ? 'You are viewing demo data. Engagement, followers and sales shown are illustrative samples, not real results.'
    : 'The active publisher is “simulated”, so engagement and publishing are generated locally — nothing is posted to a live network.';
  return `<div class="disclosure">📊 <strong>Sample data.</strong> ${esc(reason)} Connect a real publisher and record your own sales to see live numbers. <a href="#/settings">Manage in Settings →</a></div>`;
}
const sampleBadge = () => (isSampleData() ? '<span class="badge warn" title="Illustrative sample data, not real results">Sample</span>' : '');

// Headline for the sales bump. When there is no baseline (e.g. a debut with no
// prior sales) a percentage is meaningless, so show the absolute new units.
function upliftHeadline(bump) {
  const pct = bump.uplift.unitsPct;
  if (pct !== null && pct !== undefined) return { big: fmtPct(pct), unit: 'sales' };
  if (bump.window.units > 0) return { big: fmtNum(bump.window.units), unit: 'new units' };
  return { big: '—', unit: 'sales' };
}

async function boot() {
  try {
    const [meta, author, settings] = await Promise.all([api.get('/meta'), api.get('/author'), api.get('/settings')]);
    state.meta = meta;
    state.author = author;
    state.settings = settings;
    const serverVersion = AL_SERVER_VERSION || (meta && meta.version) || null;
    if (serverVersion && serverVersion !== APP_VERSION) {
      state.staleAssets = { loaded: APP_VERSION, server: serverVersion };
    }
    $('#foot-author').innerHTML = (author
      ? `Signed in as<br><strong>${esc(author.penName)}</strong>`
      : 'No author profile yet')
      + `<div style="margin-top:8px;font-size:11px;color:var(--text-faint)">AuthorLift v${esc(APP_VERSION)}</div>`;
  } catch (err) {
    main.innerHTML = `<div class="empty"><div class="big">🔌</div>Could not reach the API.<br><span class="muted">${esc(err.message)}</span></div>`;
    return;
  }
  window.addEventListener('hashchange', route);
  route();
}

function setActiveNav(view) {
  $$('#nav a').forEach((a) => a.classList.toggle('active', a.dataset.view === view));
}

async function route() {
  const hash = location.hash || '#/overview';
  const [, path, param] = hash.replace(/^#\//, '').split('/').reduce((acc, p, i) => { acc[i + 1] = p; return acc; }, ['']);
  const view = path || 'overview';
  setActiveNav(view);
  main.innerHTML = '<div class="loading">Loading…</div>';
  try {
    if (views[view]) await views[view](param);
    else main.innerHTML = `<div class="empty">Unknown page.</div>`;
  } catch (err) {
    main.innerHTML = `<div class="empty"><div class="big">⚠️</div>${esc(err.message)}</div>`;
  }
}

function staleBanner() {
  if (!state.staleAssets) return '';
  const s = state.staleAssets;
  return `<div class="disclosure" style="background:rgba(248,113,113,0.14);border-color:rgba(248,113,113,0.5)">⚠️ <strong>Cached dashboard.</strong> This page loaded an older cached version (v${esc(s.loaded)}) but the plugin on the server is v${esc(s.server)}, so new options (like Bluesky) won't show. Hard-refresh with <strong>Ctrl/Cmd+Shift+R</strong>; if you run a caching/optimization plugin (WP Rocket, LiteSpeed, W3 Total Cache, Autoptimize), purge its cache too.</div>`;
}

function pageHead(title, sub, actions = '') {
  return staleBanner() + `<div class="page-head"><div><h1 class="page-title">${esc(title)}</h1>${sub ? `<p class="page-sub">${esc(sub)}</p>` : ''}</div><div class="btn-row">${actions}</div></div>`;
}

// ---------------------------------------------------------------- views
const views = {};

// ---- Overview ----
views.overview = async () => {
  const [ov, campaigns, books] = await Promise.all([
    api.get('/analytics/overview'), api.get('/campaigns'), api.get('/books'),
  ]);
  const released = books.find((b) => b.status === 'released') || books[0];
  let bumpCampaign = campaigns.find((c) => c.status === 'completed') || campaigns.find((c) => c.bookId) || campaigns[0];
  let report = null;
  if (bumpCampaign) report = await api.get(`/campaigns/${bumpCampaign.id}/report`).catch(() => null);

  let sales = [];
  if (released) sales = await api.get(`/analytics/sales-timeseries?bookId=${released.id}`).catch(() => []);
  const followers = await buildFollowerSeries();

  const h = report ? upliftHeadline(report.salesBump) : null;
  const hero = report ? `
    <div class="hero">
      <div>
        <div class="big">${esc(h.big)} <span class="unit">${esc(h.unit)}</span></div>
        <div class="cap">during <strong>${esc(report.campaign.name)}</strong> vs. the matched ${report.salesBump.baselineDays} days before it — ${fmtNum(report.salesBump.window.units)} units (${fmtMoney(report.salesBump.window.revenue)}).</div>
      </div>
      <div>
        <div class="big">${fmtNum(report.followerDelta.delta)} <span class="unit">followers</span></div>
        <div class="cap">gained across your channels over the same window.</div>
      </div>
      <div style="margin-left:auto">${sampleBadge()}</div>
    </div>` : '';

  main.innerHTML = pageHead('Overview', state.author ? `Welcome back, ${state.author.penName}.` : 'Author marketing dashboard',
    `<a class="btn primary" href="#/studio">✍️ Create a post</a>`)
    + disclosureBanner()
    + hero
    + `<div class="grid kpis">
        ${kpi('Followers', fmtNum(ov.followers.total), 'across all platforms')}
        ${kpi('Subscribers', fmtNum(ov.subscribers), 'newsletter list')}
        ${kpi('Sales (30d)', fmtNum(ov.sales30d.units), fmtMoney(ov.sales30d.revenue) + ' royalties')}
        ${kpi('Engagement rate', (ov.engagement.engagementRate * 100).toFixed(1) + '%', fmtNum(ov.engagement.impressions) + ' impressions')}
        ${kpi('Scheduled', fmtNum(ov.scheduledPosts), fmtNum(ov.postsNext7Days) + ' in next 7 days')}
      </div>
      <div class="spacer"></div>
      <div class="grid two">
        <div class="card"><div class="card-head"><h3>Sales${released ? ' — ' + esc(released.title) : ''}</h3></div>
          ${timeChart([{ name: 'Units', color: 'var(--accent)', data: sales.map((s) => ({ date: s.date, value: s.units })) }],
            { marker: released && released.releaseDate ? { date: released.releaseDate, label: 'launch' } : null })}
        </div>
        <div class="card"><div class="card-head"><h3>Audience growth</h3></div>
          ${timeChart(followers)}
        </div>
      </div>
      <div class="section-title">Active campaigns</div>
      ${campaignMini(campaigns.filter((c) => c.status === 'active' || c.status === 'planning'))}`;
};

function kpi(label, value, delta) {
  return `<div class="kpi"><div class="label">${esc(label)}</div><div class="value">${esc(value)}</div><div class="delta">${esc(delta)}</div></div>`;
}

function campaignMini(campaigns) {
  if (!campaigns.length) return `<div class="card"><div class="muted">No active campaigns. <a href="#/campaigns">Start one →</a></div></div>`;
  return `<div class="grid cards">${campaigns.map((c) => `
    <div class="card">
      <div class="card-head"><h3>${esc(c.name)}</h3>${statusBadge(c.status)}</div>
      <div class="muted" style="font-size:13px">${esc(titleCase(c.type))} · ${fmtDate(c.startDate)} → ${fmtDate(c.endDate)}</div>
      <div class="btn-row" style="margin-top:12px"><a class="btn sm" href="#/campaigns/${c.id}">View report</a></div>
    </div>`).join('')}</div>`;
}

async function buildFollowerSeries() {
  const snaps = await api.get('/analytics/followers').catch(() => []);
  const byPlatform = {};
  for (const s of snaps) (byPlatform[s.platform] ||= []).push({ date: s.date, value: s.count });
  return Object.entries(byPlatform).map(([p, data]) => ({ name: PLATFORM_ICON[p] || p, color: PLATFORM_COLOR[p] || 'var(--accent)', data }));
}

// ---- Content Studio ----
views.studio = async () => {
  const books = await api.get('/books');
  const { postTypes, platforms, platformLimits } = state.meta;
  main.innerHTML = pageHead('Content Studio', 'Generate on-brand posts from your book details in seconds.')
    + `<div class="grid two">
      <div class="card">
        <h3>Compose</h3>
        <div class="field"><label>Book</label>
          <select id="s-book">${books.map((b) => `<option value="${b.id}">${esc(b.title)}</option>`).join('')}</select></div>
        <div class="form-row">
          <div class="field"><label>Post type</label><select id="s-type">${postTypes.map((t) => `<option value="${t}">${esc(titleCase(t))}</option>`).join('')}</select></div>
          <div class="field"><label>Platform</label><select id="s-platform">${platforms.map((p) => `<option value="${p}">${esc(PLATFORM_ICON[p] || p)}</option>`).join('')}</select></div>
        </div>
        <div class="btn-row">
          <button class="btn primary" id="s-gen">✨ Generate</button>
          <button class="btn" id="s-regen" disabled>🎲 Another variant</button>
        </div>
        <div class="help">Content is generated from your book's blurb, tropes, quotes and reviews — no two variants are alike.</div>
      </div>
      <div class="card">
        <h3>Preview</h3>
        <div id="s-preview"><div class="empty">Generate a post to see it here.</div></div>
      </div>
    </div>`;

  let current = null;
  let seed = 1;
  const limits = platformLimits;

  async function generate() {
    const platform = $('#s-platform').value;
    try {
      current = await api.post('/content/generate', {
        bookId: $('#s-book').value, postType: $('#s-type').value, platform, seed,
      });
      $('#s-regen').disabled = false;
      renderPreview(current, limits[platform]);
    } catch (err) { toast(err.message, 'bad'); }
  }

  function renderPreview(c, limit) {
    const len = c.preview.length;
    const over = limit && len > limit;
    $('#s-preview').innerHTML = `
      <div style="margin-bottom:10px">${platformTag(c.platform)} <span class="pill">${esc(titleCase(c.type))}</span></div>
      <div class="post-preview">${esc(c.body)}${c.hashtags.length ? `\n\n<span class="tags">${esc(c.hashtags.join(' '))}</span>` : ''}</div>
      <div class="char-count ${over ? 'over' : ''}">${len}${limit ? ' / ' + limit : ''} chars</div>
      <div class="help">📎 ${esc(c.mediaSuggestion)}</div>
      <div class="btn-row" style="margin-top:14px">
        <button class="btn" id="s-draft">Save as draft</button>
        <button class="btn primary" id="s-schedule">🗓️ Schedule…</button>
      </div>`;
    $('#s-draft').addEventListener('click', () => savePost('draft'));
    $('#s-schedule').addEventListener('click', () => scheduleModal());
  }

  async function savePost(status, scheduledAt) {
    try {
      await api.post('/posts', {
        platform: current.platform, type: current.type, body: current.body, hashtags: current.hashtags,
        cta: current.cta, mediaSuggestion: current.mediaSuggestion, bookId: current.bookId,
        status, scheduledAt,
      });
      toast(status === 'scheduled' ? 'Post scheduled!' : 'Saved to drafts.');
      closeModal();
    } catch (err) { toast(err.message, 'bad'); }
  }

  function scheduleModal() {
    const def = new Date(Date.now() + 3600e3).toISOString().slice(0, 16);
    openModal(`<h2>Schedule post</h2><p class="modal-sub">Pick when this should publish. The scheduler handles the rest.</p>
      <div class="field"><label>Publish at</label><input type="datetime-local" id="m-when" value="${def}"></div>
      <div class="modal-foot"><button class="btn ghost" id="m-cancel">Cancel</button><button class="btn primary" id="m-ok">Schedule</button></div>`);
    $('#m-cancel').addEventListener('click', closeModal);
    $('#m-ok').addEventListener('click', () => {
      const v = $('#m-when').value;
      if (!v) return;
      savePost('scheduled', new Date(v).toISOString());
    });
  }

  $('#s-gen').addEventListener('click', () => { seed = Math.floor(Math.random() * 1e6); generate(); });
  $('#s-regen').addEventListener('click', () => { seed = Math.floor(Math.random() * 1e6); generate(); });
};

// ---- Campaigns ----
views.campaigns = async (param) => {
  if (param) return renderCampaignReport(param);
  const [campaigns, books] = await Promise.all([api.get('/campaigns'), api.get('/books')]);
  main.innerHTML = pageHead('Campaigns', 'Plan a launch once — the playbook fills your calendar automatically.',
    `<button class="btn primary" id="c-new">＋ New campaign</button>`)
    + (campaigns.length ? `<div class="grid cards">${campaigns.map(campaignCard).join('')}</div>`
      : `<div class="empty"><div class="big">🚀</div>No campaigns yet. Create one to auto-generate a full posting schedule.</div>`);

  $('#c-new').addEventListener('click', () => campaignFormModal(books));
  $$('[data-plan]').forEach((b) => b.addEventListener('click', () => planPreview(b.dataset.plan)));
  $$('[data-del-campaign]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Delete this campaign? Unpublished posts will be detached.')) return;
    await api.del(`/campaigns/${b.dataset.delCampaign}`);
    toast('Campaign deleted.'); route();
  }));

  function campaignCard(c) {
    const book = books.find((b) => b.id === c.bookId);
    return `<div class="card">
      <div class="card-head"><h3>${esc(c.name)}</h3>${statusBadge(c.status)}</div>
      <div class="muted" style="font-size:13px">${esc(titleCase(c.type))}${book ? ' · ' + esc(book.title) : ''}</div>
      <div class="muted" style="font-size:13px;margin-top:4px">${fmtDate(c.startDate)} → ${fmtDate(c.endDate)}</div>
      ${c.goal ? `<div style="margin-top:8px"><span class="badge accent">🎯 ${fmtNum(c.goal.target)} ${esc(c.goal.metric)}</span></div>` : ''}
      <div class="btn-row" style="margin-top:14px">
        <button class="btn sm primary" data-plan="${c.id}">🪄 Generate playbook</button>
        <a class="btn sm" href="#/campaigns/${c.id}">Report</a>
        <button class="btn sm danger" data-del-campaign="${c.id}">Delete</button>
      </div>
    </div>`;
  }
};

function campaignFormModal(books, existing) {
  const m = state.meta;
  const c = existing || {};
  openModal(`<h2>${existing ? 'Edit' : 'New'} campaign</h2>
    <p class="modal-sub">A campaign ties a posting schedule to a book and a goal.</p>
    <div class="field"><label>Name</label><input id="cf-name" value="${esc(c.name || '')}" placeholder="Book Two — Launch"></div>
    <div class="form-row">
      <div class="field"><label>Type</label><select id="cf-type">${m.campaignTypes.map((t) => `<option value="${t}" ${c.type === t ? 'selected' : ''}>${esc(titleCase(t))}</option>`).join('')}</select></div>
      <div class="field"><label>Book</label><select id="cf-book"><option value="">— none —</option>${books.map((b) => `<option value="${b.id}" ${c.bookId === b.id ? 'selected' : ''}>${esc(b.title)}</option>`).join('')}</select></div>
    </div>
    <div class="form-row">
      <div class="field"><label>Start date</label><input type="date" id="cf-start" value="${(c.startDate || new Date().toISOString()).slice(0, 10)}"></div>
      <div class="field"><label>End date (optional)</label><input type="date" id="cf-end" value="${c.endDate ? c.endDate.slice(0, 10) : ''}"></div>
    </div>
    <div class="form-row">
      <div class="field"><label>Goal metric</label><select id="cf-metric"><option value="">— none —</option>${m.goalMetrics.map((g) => `<option value="${g}" ${c.goal && c.goal.metric === g ? 'selected' : ''}>${esc(titleCase(g))}</option>`).join('')}</select></div>
      <div class="field"><label>Goal target</label><input type="number" id="cf-target" value="${c.goal ? c.goal.target : ''}" placeholder="600"></div>
    </div>
    <div class="modal-foot"><button class="btn ghost" id="cf-cancel">Cancel</button><button class="btn primary" id="cf-save">Save</button></div>`);
  $('#cf-cancel').addEventListener('click', closeModal);
  $('#cf-save').addEventListener('click', async () => {
    const body = {
      name: $('#cf-name').value, type: $('#cf-type').value, bookId: $('#cf-book').value || null,
      startDate: new Date($('#cf-start').value).toISOString(),
      endDate: $('#cf-end').value ? new Date($('#cf-end').value).toISOString() : null,
    };
    const metric = $('#cf-metric').value;
    if (metric) body.goal = { metric, target: Number($('#cf-target').value) || 0 };
    try {
      if (existing) await api.put(`/campaigns/${existing.id}`, body);
      else await api.post('/campaigns', body);
      toast('Campaign saved.'); closeModal(); route();
    } catch (err) { toast(err.message, 'bad'); }
  });
}

async function planPreview(campaignId) {
  toast('Building playbook…');
  let result;
  try { result = await api.post(`/campaigns/${campaignId}/plan`, { dryRun: true }); }
  catch (err) { return toast(err.message, 'bad'); }
  const s = result.summary;
  const sample = result.posts.slice(0, 6);
  openModal(`<h2>Playbook preview</h2>
    <p class="modal-sub">${fmtNum(s.totalPosts)} posts from ${fmtDate(s.windowStart)} to ${fmtDate(s.windowEnd)}${s.launchDate ? ` · launch ${fmtDate(s.launchDate)}` : ''}.</p>
    <div class="grid kpis" style="grid-template-columns:repeat(3,1fr)">
      ${kpi('Total posts', fmtNum(s.totalPosts), '')}
      ${kpi('Scheduled', fmtNum(s.scheduled), 'future')}
      ${kpi('Drafts', fmtNum(s.drafts), 'past-dated')}
    </div>
    <div class="section-title">Channels</div>
    <div class="btn-row">${Object.entries(s.byPlatform).map(([p, n]) => `<span class="badge">${esc(PLATFORM_ICON[p] || p)}: ${n}</span>`).join('')}</div>
    <div class="section-title">Sample posts</div>
    ${sample.map((p) => `<div class="cal-post"><div class="when">${esc(fmtDate(p.scheduledAt))}</div><div class="body"><div>${platformTag(p.platform)} <span class="pill">${esc(titleCase(p.type))}</span></div><div class="txt">${esc(p.body.slice(0, 160))}${p.body.length > 160 ? '…' : ''}</div></div></div>`).join('')}
    <div class="modal-foot"><button class="btn ghost" id="pp-cancel">Cancel</button><button class="btn primary" id="pp-go">🚀 Create ${fmtNum(s.totalPosts)} posts</button></div>`);
  $('#pp-cancel').addEventListener('click', closeModal);
  $('#pp-go').addEventListener('click', async () => {
    try {
      const res = await api.post(`/campaigns/${campaignId}/plan`, { dryRun: false });
      toast(`Created ${res.posts.length} posts. Check your calendar!`);
      closeModal(); location.hash = '#/calendar';
    } catch (err) { toast(err.message, 'bad'); }
  });
}

async function renderCampaignReport(id) {
  const report = await api.get(`/campaigns/${id}/report`);
  const c = report.campaign;
  const b = report.salesBump;
  const salesSeries = c.bookId ? await api.get(`/analytics/sales-timeseries?bookId=${c.bookId}`).catch(() => []) : [];
  const h = upliftHeadline(b);
  main.innerHTML = pageHead(c.name, `${titleCase(c.type)} campaign · ${fmtDate(c.startDate)} → ${fmtDate(c.endDate)}`,
    `<a class="btn" href="#/campaigns">← All campaigns</a>`)
    + disclosureBanner()
    + `<div class="hero">
        <div><div class="big">${esc(h.big)} <span class="unit">${esc(h.unit)}</span></div>
          <div class="cap">${fmtNum(b.window.units)} units during the campaign vs. ${fmtNum(b.baseline.units)} in the matched ${b.baselineDays}-day baseline before it.</div></div>
        <div><div class="big">${fmtMoney(b.window.revenue)} <span class="unit">in-window</span></div>
          <div class="cap">${fmtMoney(b.uplift.revenue)} above the baseline rate (correlation over the window, not proven causation).</div></div>
        <div style="margin-left:auto">${sampleBadge()}</div>
      </div>
      <div class="grid kpis">
        ${kpi('Posts published', fmtNum(report.postCounts.published), `${fmtNum(report.postCounts.total)} planned`)}
        ${kpi('Impressions', fmtNum(report.engagement.impressions), 'total reach')}
        ${kpi('Link clicks', fmtNum(report.engagement.clicks), (report.engagement.clickThroughRate * 100).toFixed(1) + '% CTR')}
        ${kpi('Followers gained', fmtNum(report.followerDelta.delta), 'during campaign')}
        ${kpi('Subscribers gained', fmtNum(report.subscriberDelta.delta), 'newsletter')}
      </div>
      ${report.goalProgress ? `<div class="spacer"></div><div class="card"><div class="card-head"><h3>🎯 Goal: ${fmtNum(report.goalProgress.target)} ${esc(report.goalProgress.metric)}</h3>${report.goalProgress.met ? '<span class="badge good">Met</span>' : '<span class="badge warn">In progress</span>'}</div>
        <div class="value" style="font-size:24px;font-weight:700">${fmtNum(report.goalProgress.actual)} <span class="muted" style="font-size:15px">(${report.goalProgress.pctOfTarget ?? '—'}% of target)</span></div>
        ${goalBar(report.goalProgress.pctOfTarget)}</div>` : ''}
      <div class="spacer"></div>
      <div class="card"><div class="card-head"><h3>Daily unit sales</h3></div>
        ${timeChart([{ name: 'Units', color: 'var(--accent)', data: salesSeries.map((s) => ({ date: s.date, value: s.units })) }],
          { marker: { date: c.startDate, label: 'campaign start' } })}
      </div>`;
}

function goalBar(pct) {
  const p = Math.max(0, Math.min(100, pct || 0));
  return `<div style="height:10px;background:var(--bg-elev-2);border-radius:6px;overflow:hidden;margin-top:12px"><div style="height:100%;width:${p}%;background:linear-gradient(90deg,var(--accent),var(--accent-2))"></div></div>`;
}

// ---- Calendar ----
views.calendar = async () => {
  const from = new Date(Date.now() - 2 * 864e5).toISOString();
  const to = new Date(Date.now() + 60 * 864e5).toISOString();
  const posts = await api.get(`/calendar?from=${from}&to=${to}`);
  main.innerHTML = pageHead('Calendar', 'Everything scheduled and drafted, in order.',
    `<a class="btn primary" href="#/studio">✍️ New post</a>`);

  if (!posts.length) {
    main.innerHTML += `<div class="empty"><div class="big">🗓️</div>Nothing scheduled. Generate a campaign playbook or create a post.</div>`;
    return;
  }
  const byDay = {};
  for (const p of posts) {
    const key = new Date(p.scheduledAt).toISOString().slice(0, 10);
    (byDay[key] ||= []).push(p);
  }
  let html = '';
  for (const day of Object.keys(byDay).sort()) {
    html += `<div class="cal-day"><div class="cal-day-head">${fmtDate(day)}</div>`;
    for (const p of byDay[day]) {
      html += `<div class="cal-post">
        <div class="when">${fmtTime(p.scheduledAt)}</div>
        <div class="body">
          <div>${platformTag(p.platform)} <span class="pill">${esc(titleCase(p.type))}</span> ${statusBadge(p.status)}${p.publishedVia === 'simulated' ? ' <span class="pill" title="Published via the simulated publisher — not posted to a live network">simulated</span>' : ''}</div>
          <div class="txt">${esc(p.body.slice(0, 200))}${p.body.length > 200 ? '…' : ''}</div>
        </div>
        <div class="row-actions">
          ${p.status !== 'published' ? `<button class="btn sm" data-pub="${p.id}">Publish now</button>` : ''}
          ${p.status !== 'published' ? `<button class="btn sm danger" data-del="${p.id}">✕</button>` : ''}
        </div>
      </div>`;
    }
    html += `</div>`;
  }
  main.innerHTML += html;
  $$('[data-pub]').forEach((b) => b.addEventListener('click', async () => {
    try {
      await api.post(`/posts/${b.dataset.pub}/publish`);
      toast(state.settings && state.settings.activePublisher === 'simulated'
        ? 'Simulated publish — nothing was sent to a live network.'
        : 'Published!');
      route();
    } catch (err) { toast(err.message, 'bad'); }
  }));
  $$('[data-del]').forEach((b) => b.addEventListener('click', async () => {
    await api.del(`/posts/${b.dataset.del}`); toast('Deleted.'); route();
  }));
};

// ---- Books ----
views.books = async () => {
  const books = await api.get('/books');
  main.innerHTML = pageHead('Books', 'Add every title you publish — AuthorLift crafts campaigns and content for each.',
    `<button class="btn" id="b-import">⬇ Import JSON</button><button class="btn primary" id="b-new">＋ Add book</button>`)
    + (books.length ? `<div class="grid cards">${books.map(bookCard).join('')}</div>`
      : `<div class="empty"><div class="big">📚</div>No books yet. Add one (or import JSON) to start generating content.</div>`);
  $('#b-new').addEventListener('click', () => bookFormModal());
  $('#b-import').addEventListener('click', () => importBookModal());
  $$('[data-edit-book]').forEach((b) => b.addEventListener('click', () => {
    bookFormModal(books.find((x) => x.id === b.dataset.editBook));
  }));
  $$('[data-del-book]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Delete this book?')) return;
    await api.del(`/books/${b.dataset.delBook}`); toast('Book deleted.'); route();
  }));

  function bookCard(b) {
    return `<div class="card">
      <div class="card-head"><h3>${esc(b.title)}</h3>${statusBadge(b.status)}</div>
      <div class="muted" style="font-size:13px">${esc(b.genre)}${b.series ? ' · ' + esc(b.series) + (b.seriesNumber ? ' #' + b.seriesNumber : '') : ''}${b.publisher ? ' · ' + esc(b.publisher) : ''}</div>
      ${b.tagline ? `<div style="font-style:italic;margin-top:8px;color:var(--text-dim)">"${esc(b.tagline)}"</div>` : ''}
      <div class="muted" style="font-size:13px;margin-top:8px">${b.releaseDate ? 'Releases ' + fmtDate(b.releaseDate) : 'No release date'} ${b.price != null ? '· ' + ((state.settings && state.settings.currencySymbol) || '$') + b.price : ''}</div>
      ${b.tropes && b.tropes.length ? `<div class="btn-row" style="margin-top:10px">${b.tropes.slice(0, 4).map((t) => `<span class="pill">${esc(t)}</span>`).join('')}</div>` : ''}
      <div class="btn-row" style="margin-top:14px"><button class="btn sm" data-edit-book="${b.id}">Edit</button><button class="btn sm danger" data-del-book="${b.id}">Delete</button></div>
    </div>`;
  }
};

function bookFormModal(existing) {
  const m = state.meta;
  const b = existing || {};
  const bl = b.buyLinks || {};
  const baseLinkKeys = ['universal', 'booklinker', 'linktree', 'signed'];
  const prefKeys = [...baseLinkKeys, ...Object.keys(bl).filter((k) => !baseLinkKeys.includes(k))];
  const preferredOptions = ['<option value="">Auto (best available)</option>']
    .concat(prefKeys.map((k) => `<option value="${esc(k)}" ${b.preferredLink === k ? 'selected' : ''}>${esc(LINK_LABELS[k] || k)}</option>`))
    .join('');
  openModal(`<h2>${existing ? 'Edit' : 'Add'} book</h2>
    <div class="field"><label>Title</label><input id="bf-title" value="${esc(b.title || '')}"></div>
    <div class="form-row">
      <div class="field"><label>Genre</label><select id="bf-genre">${m.genres.map((g) => `<option ${b.genre === g ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select></div>
      <div class="field"><label>Status</label><select id="bf-status">${m.bookStatuses.map((s) => `<option value="${s}" ${b.status === s ? 'selected' : ''}>${esc(titleCase(s))}</option>`).join('')}</select></div>
    </div>
    <div class="form-row">
      <div class="field"><label>Series</label><input id="bf-series" value="${esc(b.series || '')}"></div>
      <div class="field"><label>Release date</label><input type="date" id="bf-release" value="${b.releaseDate ? b.releaseDate.slice(0, 10) : ''}"></div>
    </div>
    <div class="field"><label>Publisher / imprint (optional)</label><input id="bf-publisher" value="${esc(b.publisher || '')}" placeholder="e.g. Spring Street Books"></div>
    <div class="field"><label>Tagline</label><input id="bf-tagline" value="${esc(b.tagline || '')}" placeholder="One irresistible line"></div>
    <div class="field"><label>Blurb</label><textarea id="bf-blurb" placeholder="The back-cover copy">${esc(b.blurb || '')}</textarea></div>
    <div class="form-row">
      <div class="field"><label>Tropes (comma-separated)</label><input id="bf-tropes" value="${esc((b.tropes || []).join(', '))}" placeholder="fake engagement, workplace romance"></div>
      <div class="field"><label>Price</label><input type="number" step="0.01" id="bf-price" value="${b.price ?? ''}"></div>
    </div>
    <div class="field"><label>Comparable authors / titles ("comps", comma-separated)</label><input id="bf-comps" value="${esc((b.comps || []).join(', '))}" placeholder="Marian Keyes, Beth O'Leary"></div>
    <div class="field"><label>Quotes (one per line)</label><textarea id="bf-quotes" placeholder="Pull quotes readers will screenshot">${esc((b.quotes || []).join('\n'))}</textarea></div>
    <div class="field"><label>Reviews (one per line: <span class="mono">Source | rating | text</span>)</label><textarea id="bf-reviews" placeholder="Goodreads | 5 | Couldn't put it down.">${esc((b.reviews || []).map((r) => `${r.source} | ${r.rating} | ${r.text}`).join('\n'))}</textarea></div>
    <div class="section-title">Buy links</div>
    <div class="field"><label>Books2Read universal (all stores incl. Amazon)</label><input id="bl-universal" value="${esc(bl.universal || '')}" placeholder="https://books2read.com/…"></div>
    <div class="field"><label>Booklinker / mybook.to (all Amazon stores)</label><input id="bl-booklinker" value="${esc(bl.booklinker || '')}" placeholder="https://mybook.to/…"></div>
    <div class="form-row">
      <div class="field"><label>Linktree</label><input id="bl-linktree" value="${esc(bl.linktree || '')}" placeholder="https://linktr.ee/…"></div>
      <div class="field"><label>Signed copies (your webshop)</label><input id="bl-signed" value="${esc(bl.signed || '')}" placeholder="https://your.shop/…"></div>
    </div>
    <div class="field"><label>Preferred link (used in generated posts)</label><select id="bl-preferred">${preferredOptions}</select></div>
    <div class="modal-foot"><button class="btn ghost" id="bf-cancel">Cancel</button><button class="btn primary" id="bf-save">Save</button></div>`);
  $('#bf-cancel').addEventListener('click', closeModal);
  $('#bf-save').addEventListener('click', async () => {
    const body = {
      title: $('#bf-title').value, genre: $('#bf-genre').value, status: $('#bf-status').value,
      series: $('#bf-series').value || null,
      publisher: $('#bf-publisher').value || null,
      releaseDate: $('#bf-release').value ? new Date($('#bf-release').value).toISOString() : null,
      tagline: $('#bf-tagline').value || null, blurb: $('#bf-blurb').value || null,
      tropes: splitList($('#bf-tropes').value),
      comps: splitList($('#bf-comps').value),
      quotes: $('#bf-quotes').value.split('\n').map((s) => s.trim()).filter(Boolean),
      reviews: parseReviews($('#bf-reviews').value),
      price: $('#bf-price').value ? Number($('#bf-price').value) : null,
    };
    const buyLinks = { ...(b.buyLinks || {}) };
    const setLink = (key, id) => { const v = $(id).value.trim(); if (v) buyLinks[key] = v; else delete buyLinks[key]; };
    setLink('universal', '#bl-universal');
    setLink('booklinker', '#bl-booklinker');
    setLink('linktree', '#bl-linktree');
    setLink('signed', '#bl-signed');
    body.buyLinks = buyLinks;
    body.preferredLink = $('#bl-preferred').value || null;
    try {
      if (existing) await api.put(`/books/${existing.id}`, body);
      else await api.post('/books', body);
      toast('Book saved.'); closeModal(); route();
    } catch (err) { toast(err.message, 'bad'); }
  });
}

function splitList(value) {
  return String(value || '').split(',').map((s) => s.trim()).filter(Boolean);
}

// Parse a reviews textarea where each line is "Source | rating | text"
// (rating/source optional). Powers the review-highlight social-proof posts.
function parseReviews(text) {
  return String(text || '').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
    const parts = line.split('|').map((s) => s.trim());
    if (parts.length >= 3) return { source: parts[0] || 'Reader', rating: Number(parts[1]) || 5, text: parts.slice(2).join(' | ') };
    if (parts.length === 2) return { source: parts[0] || 'Reader', rating: 5, text: parts[1] };
    return { source: 'Reader', rating: 5, text: parts[0] };
  }).filter((r) => r.text);
}

// Bulk "absorb" path: paste a JSON object (or array) of book data.
function importBookModal() {
  openModal(`<h2>Import book data</h2>
    <p class="modal-sub">Paste a title (or an array of titles) as JSON to absorb it in one step. AuthorLift immediately builds content and campaigns from it.</p>
    <div class="field"><textarea id="imp-json" style="min-height:220px" placeholder='{\n  "title": "Your Next Book",\n  "genre": "Romantic Comedy",\n  "status": "preorder",\n  "tagline": "…",\n  "blurb": "…",\n  "tropes": ["…"],\n  "comps": ["Marian Keyes"],\n  "reviews": [{ "source": "ARC reader", "rating": 5, "text": "…" }],\n  "buyLinks": { "universal": "https://…" },\n  "releaseDate": "2026-11-01"\n}'></textarea></div>
    <div class="help">Recognised fields: title, genre, status, series, seriesNumber, publisher, tagline, blurb, tropes[], comps[], keywords[], subgenres[], quotes[], reviews[{source,rating,text}], buyLinks{universal,booklinker,linktree,signed,amazon,apple,kobo,…}, preferredLink, price, releaseDate.</div>
    <div class="modal-foot"><button class="btn ghost" id="imp-cancel">Cancel</button><button class="btn primary" id="imp-go">Import</button></div>`);
  $('#imp-cancel').addEventListener('click', closeModal);
  $('#imp-go').addEventListener('click', async () => {
    let parsed;
    try { parsed = JSON.parse($('#imp-json').value); }
    catch (e) { return toast('That is not valid JSON.', 'bad'); }
    const list = Array.isArray(parsed) ? parsed : [parsed];
    let ok = 0; let firstErr = null;
    for (const item of list) {
      try { await api.post('/books', item); ok += 1; }
      catch (err) { if (!firstErr) firstErr = `"${(item && item.title) || 'book'}": ${err.message}`; }
    }
    if (ok) { toast(`Imported ${ok} book${ok === 1 ? '' : 's'}.`); closeModal(); route(); }
    if (firstErr) toast(firstErr, 'bad');
  });
}

// ---- Analytics ----
views.analytics = async () => {
  const [ov, books, subs] = await Promise.all([
    api.get('/analytics/overview'), api.get('/books'), api.get('/analytics/subscribers'),
  ]);
  const followers = await buildFollowerSeries();
  const released = books.find((b) => b.status === 'released') || books[0];
  const sales = released ? await api.get(`/analytics/sales-timeseries?bookId=${released.id}`).catch(() => []) : [];
  const recent = sales.slice(-14);

  main.innerHTML = pageHead('Analytics', 'Engagement, audience and sales over time.',
    `<button class="btn primary" id="an-log">＋ Log sales</button>`)
    + disclosureBanner()
    + `<div class="grid kpis">
        ${kpi('Impressions', fmtNum(ov.engagement.impressions), 'lifetime')}
        ${kpi('Likes', fmtNum(ov.engagement.likes), '')}
        ${kpi('Shares', fmtNum(ov.engagement.shares), '')}
        ${kpi('Link clicks', fmtNum(ov.engagement.clicks), '')}
      </div>
      <div class="spacer"></div>
      <div class="card"><div class="card-head"><h3>Audience growth by platform</h3></div>${timeChart(followers, { height: 260 })}</div>
      <div class="spacer"></div>
      <div class="grid two">
        <div class="card"><div class="card-head"><h3>Newsletter subscribers</h3></div>${timeChart([{ name: 'Subscribers', color: '#f59e0b', data: subs.map((s) => ({ date: s.date, value: s.count })) }])}</div>
        <div class="card"><div class="card-head"><h3>Units sold — last 14 days${released ? ' (' + esc(released.title) + ')' : ''}</h3></div>${barChart(recent.map((s) => ({ label: fmtDate(s.date).replace(/,.*/, ''), value: s.units })))}</div>
      </div>`;
  $('#an-log').addEventListener('click', () => salesModal(books));
};

// Manual sales entry — the real-data input path for the sales-bump metric.
function salesModal(books) {
  const m = state.meta;
  const today = new Date().toISOString().slice(0, 10);
  openModal(`<h2>Log a sale</h2>
    <p class="modal-sub">Record real sales here (or import them via <span class="mono">POST /api/sales</span>) so the sales-bump report reflects your actual numbers.</p>
    <div class="form-row">
      <div class="field"><label>Book</label><select id="sl-book">${books.map((b) => `<option value="${b.id}">${esc(b.title)}</option>`).join('')}</select></div>
      <div class="field"><label>Date</label><input type="date" id="sl-date" value="${today}"></div>
    </div>
    <div class="form-row">
      <div class="field"><label>Units</label><input type="number" id="sl-units" value="1" min="0"></div>
      <div class="field"><label>Royalty / revenue</label><input type="number" step="0.01" id="sl-rev" placeholder="0.00"></div>
    </div>
    <div class="field"><label>Channel</label><select id="sl-channel">${m.salesChannels.map((c) => `<option value="${c}">${esc(titleCase(c))}</option>`).join('')}</select></div>
    <div class="modal-foot"><button class="btn ghost" id="sl-cancel">Cancel</button><button class="btn primary" id="sl-save">Save</button></div>`);
  $('#sl-cancel').addEventListener('click', closeModal);
  $('#sl-save').addEventListener('click', async () => {
    try {
      await api.post('/sales', {
        bookId: $('#sl-book').value,
        date: new Date($('#sl-date').value).toISOString(),
        units: Number($('#sl-units').value) || 0,
        revenue: $('#sl-rev').value ? Number($('#sl-rev').value) : 0,
        channel: $('#sl-channel').value,
        source: 'manual',
      });
      toast('Sale recorded.'); closeModal(); route();
    } catch (err) { toast(err.message, 'bad'); }
  });
}

// ---- Settings ----
views.settings = async () => {
  const [settings, publishers] = await Promise.all([api.get('/settings'), api.get('/publishers')]);
  const a = state.author || {};
  const m = state.meta;
  const handles = a.handles || {};
  // A field for every social platform (newsletter has no handle).
  const socialPlatforms = m.platforms.filter((p) => p !== 'newsletter');
  const handleRows = [];
  for (let i = 0; i < socialPlatforms.length; i += 2) {
    const pair = socialPlatforms.slice(i, i + 2);
    handleRows.push(`<div class="form-row">${pair.map((p) =>
      `<div class="field"><label>${esc(PLATFORM_ICON[p] || p)}</label><input id="h-${esc(p)}" value="${esc(handles[p] || '')}" placeholder="yourhandle"></div>`).join('')}</div>`);
  }

  main.innerHTML = pageHead('Settings', 'Your author profile and publishing configuration.')
    + `<div class="grid two">
      <div class="card"><h3>Author profile</h3>
        <div class="field"><label>Pen name</label><input id="a-pen" value="${esc(a.penName || '')}"></div>
        <div class="field"><label>Tagline</label><input id="a-tag" value="${esc(a.tagline || '')}"></div>
        <div class="field"><label>Bio</label><textarea id="a-bio">${esc(a.bio || '')}</textarea></div>
        <div class="form-row">
          <div class="field"><label>Website / newsletter</label><input id="a-web" value="${esc(a.website || '')}"></div>
          <div class="field"><label>Timezone</label><select id="a-tz">${m.timezones.map((t) => `<option ${a.timezone === t ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></div>
        </div>
        <div class="section-title">Handles</div>
        <div class="help" style="margin-bottom:10px">Set a handle for each network you post on — the campaign planner schedules to exactly the channels you fill in here (leave the rest blank).</div>
        ${handleRows.join('')}
        <button class="btn primary" id="a-save">Save profile</button>
      </div>
      <div class="card"><h3>Posting method</h3>
        <div class="help" style="margin-bottom:10px">How AuthorLift sends your scheduled posts out. This is <em>not</em> your book's publisher — record that on each book (Books → Publisher / imprint).</div>
        <div class="field"><label>Send posts via</label>
          <select id="set-pub">${publishers.map((p) => `<option value="${esc(p.name)}" ${settings.activePublisher === p.name ? 'selected' : ''}>${esc(titleCase(p.name))}${p.simulated ? ' — simulated' : ''}</option>`).join('')}</select>
        </div>
        <button class="btn" id="set-save">Save</button>
        <div class="help" style="margin-top:12px">
          <strong>Simulated</strong> — generates realistic engagement locally; nothing is posted to a live network (good for demos/dry runs).<br>
          <strong>Manual</strong> — marks posts as published without inventing any numbers; use this when you post to your networks yourself. Metrics stay at zero until you record real ones.<br>
          Automated network adapters (X, Meta, TikTok, Bluesky, your ESP) register through the same interface${AL_CONFIG.nonce ? ' via the <span class="mono">authorlift_publishers</span> filter' : ''} and appear here once configured with credentials.
        </div>
      </div>
      <div class="card"><h3>Data</h3>
        ${isSampleData() ? '<div class="disclosure" style="margin-bottom:14px">This install currently contains demo/sample data.</div>' : ''}
        <p class="help" style="margin-bottom:12px">Start fresh clears the seeded books, campaigns, posts and sample metrics, keeps your author profile, and turns off demo mode — ready for your own catalogue.</p>
        <button class="btn danger" id="data-reset">Clear sample data &amp; start fresh</button>
      </div>
    </div>`;

  $('#a-save').addEventListener('click', async () => {
    try {
      const newHandles = {};
      for (const p of socialPlatforms) newHandles[p] = $(`#h-${p}`).value;
      const author = await api.put('/author', {
        penName: $('#a-pen').value, tagline: $('#a-tag').value, bio: $('#a-bio').value,
        website: $('#a-web').value, timezone: $('#a-tz').value, handles: newHandles,
      });
      state.author = author;
      $('#foot-author').innerHTML = `Signed in as<br><strong>${esc(author.penName)}</strong>`;
      toast('Profile saved.');
    } catch (err) { toast(err.message, 'bad'); }
  });
  $('#set-save').addEventListener('click', async () => {
    try {
      state.settings = await api.put('/settings', { activePublisher: $('#set-pub').value });
      toast('Publisher saved.');
    } catch (err) { toast(err.message, 'bad'); }
  });
  $('#data-reset').addEventListener('click', async () => {
    if (!confirm('Clear all sample data and start fresh? This removes the seeded books, campaigns, posts and sample metrics. Your author profile is kept. This cannot be undone.')) return;
    try {
      const res = await api.post('/data/reset', {});
      state.settings = res.settings;
      toast('Sample data cleared. Add your own books to begin.');
      location.hash = '#/books';
    } catch (err) { toast(err.message, 'bad'); }
  });
};

boot();
