// FORK dev tool: log into the running dev environment with a real browser and visit every major
// page, reporting HTTP status, Laravel error-page markers, JS console errors, uncaught page errors
// and failed requests (assets, API calls). Run through .fork/dev-crawl.sh.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const here = __dirname;
const state = Object.fromEntries(fs.readFileSync(path.join(here, 'state'), 'utf8').trim().split('\n').map(l => l.split('=')));
const BASE = process.env.BASE || state.APP_URL;
const TOKEN = fs.readFileSync(path.join(here, 'token'), 'utf8').trim();
const EMAIL = process.env.DEV_EMAIL || 'dev@example.invalid';
const PASSWORD = process.env.DEV_PASSWORD || 'devpassword';
const MARKERS = [/Whoops/i, /Something went wrong/i, /SQLSTATE/, /ErrorException/, /Call to (a member function|undefined)/, /Undefined (variable|index|array key|property)/, /TypeError:/, /Stack trace/, /Firefly III can(not|'t) (find|handle)/i];

async function api(p) {
  const r = await fetch(`${BASE}/api/v1/${p}`, { headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' } });
  return r.ok ? r.json() : { data: [] };
}
const firstId = async (p) => ((await api(p)).data[0] || {}).id;

(async () => {
  const account = await firstId('accounts?type=asset&limit=1');
  const group = await firstId('transactions?limit=1');
  const budget = await firstId('budgets?limit=1');
  const category = await firstId('categories?limit=1');
  const ruleGroup = await firstId('rule-groups?limit=1');
  const PAGES = [
    '/', '/transactions/withdrawal', '/transactions/deposit', '/transactions/transfer', '/transactions/withdrawal/all',
    group && `/transactions/show/${group}`, group && `/transactions/edit/${group}`, '/transactions/create/withdrawal',
    '/accounts/asset', '/accounts/expense', '/accounts/revenue', '/accounts/liabilities', account && `/accounts/show/${account}`, account && `/accounts/edit/${account}`,
    '/budgets', budget && `/budgets/show/${budget}`, '/categories', category && `/categories/show/${category}`, '/tags', '/bills', '/piggy-banks',
    '/rules', ruleGroup && `/rule-groups/edit/${ruleGroup}`, '/rules/create', '/recurring', '/webhooks/index', '/reports', '/search?search=test',
    '/preferences', '/profile', '/currencies', '/groups', '/export', '/settings',
  ].filter(Boolean);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  let current = null;
  page.on('console', m => { if (current && m.type() === 'error') current.console.push(m.text().split('\n')[0].slice(0, 200)); });
  page.on('pageerror', e => { if (current) current.pageErrors.push(String(e).split('\n')[0].slice(0, 200)); });
  page.on('requestfailed', r => { if (current) current.failed.push(`${r.method()} ${r.url().replace(BASE, '')} → ${r.failure()?.errorText}`); });
  page.on('response', r => { if (current && r.status() >= 400) current.failed.push(`${r.request().method()} ${r.url().replace(BASE, '')} → ${r.status()}`); });

  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type="submit"], input[type="submit"]')]);
  if (page.url().includes('/login')) { console.error(`login failed for ${EMAIL} (set DEV_EMAIL / DEV_PASSWORD)`); process.exit(2); }

  const shots = path.join(here, 'shots'); fs.mkdirSync(shots, { recursive: true });
  const report = [];
  for (const p of PAGES) {
    current = { path: p, status: null, markers: [], console: [], pageErrors: [], failed: [] };
    try {
      const resp = await page.goto(BASE + p, { waitUntil: 'networkidle', timeout: 45000 });
      current.status = resp ? resp.status() : null;
      await page.waitForTimeout(1500);
      const body = await page.evaluate(() => document.body ? document.body.innerText.slice(0, 200000) : '');
      for (const m of MARKERS) if (m.test(body)) current.markers.push(m.source);
    } catch (e) { current.pageErrors.push('NAV: ' + String(e).slice(0, 200)); }
    current.ok = current.status === 200 && !current.markers.length && !current.console.length && !current.pageErrors.length && !current.failed.length;
    if (!current.ok) await page.screenshot({ path: path.join(shots, p.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') + '.png') }).catch(() => {});
    report.push(current);
    console.log(`${current.ok ? 'OK  ' : 'FAIL'} ${String(current.status).padEnd(4)} ${p}` + (current.markers.length ? ' markers=' + current.markers.join('|') : '') + (current.failed.length ? ` failed=${current.failed.length}` : '') + (current.console.length ? ` console=${current.console.length}` : '') + (current.pageErrors.length ? ` pageErrors=${current.pageErrors.length}` : ''));
  }
  // FORK: the chat widget, when FORK_CHAT is on. Checked here because the failure that shipped was
  // invisible to PHPUnit and to the page crawl above: `.fk-chat__panel { display: flex }` overrode
  // the browser's `[hidden] { display: none }`, so the panel was open from page load and its close
  // button toggled an attribute nothing honoured. Everything still rendered, nothing errored.
  const widget = { path: '(chat widget)', status: 200, markers: [], console: [], pageErrors: [], failed: [] };
  try {
    await page.goto(BASE + '/preferences', { waitUntil: 'networkidle' });
    if (await page.locator('#fork-chat').count()) {
      const panel = page.locator('.fk-chat__panel');
      const step = async (label, want) => {
        const visible = await panel.isVisible();
        if (visible !== want) widget.pageErrors.push(`panel ${label}: visible=${visible}, expected ${want}`);
      };
      await step('on load must be closed', false);
      await page.click('.fk-chat__launcher');
      await step('after opening', true);
      await page.click('.fk-chat__close');
      await step('after the close button', false);
      await page.click('.fk-chat__launcher');
      await page.keyboard.press('Escape');
      await step('after Escape', false);
    }
  } catch (e) { widget.pageErrors.push('WIDGET: ' + String(e).slice(0, 200)); }
  widget.ok = !widget.pageErrors.length;
  if (!widget.ok) await page.screenshot({ path: path.join(shots, 'chat_widget.png') }).catch(() => {});
  report.push(widget);
  console.log(`${widget.ok ? 'OK  ' : 'FAIL'} ${String(widget.status).padEnd(4)} ${widget.path}` + (widget.pageErrors.length ? ' ' + widget.pageErrors.join('; ') : ''));

  fs.writeFileSync(path.join(here, 'crawl-report.json'), JSON.stringify(report, null, 2));
  await browser.close();
  const bad = report.filter(r => !r.ok).length;
  console.log(`\n${bad} of ${report.length} pages have problems${bad ? ` — details in .fork/dev/crawl-report.json, screenshots in .fork/dev/shots/` : ''}`);
  process.exit(bad ? 1 : 0);
})();
