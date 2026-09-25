#!/usr/bin/env node
/*
 * E2E — the REAL old Ordering and Purchasing Panels running headless (jsdom)
 * against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/stock-admin.mjs [--port 8189] [--user u-wandi]
 *
 * Covers the #37 screens that the other stock walkthroughs do not:
 *   - every Ordering page, including Kelola Akses and Data Forecast
 *   - an Ordering role change saved through ordering-users.php
 *   - a training xlsx uploaded by the page's own handleTrainingUpload()
 *   - every Purchasing page, including Kelola Akses and Log Aktivitas
 *   - a Purchasing user added + demoted through the page's own helpers
 *   - purchasing-settings.php honestly reports the missing stock_settings table
 * Finally the two crew tables and the scratch training folder are restored.
 */
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_ROOT = path.resolve(HERE, '../..');
const MYSQL = process.env.MYSQL_BIN || 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysql.exe';
const MYSQLDUMP = MYSQL.replace(/mysql(\.exe)?$/, 'mysqldump$1');
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8189'), 10);
const USER = arg('--user', 'u-wandi');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_stock';
const TAG = 'E2E ' + Date.now().toString(36);
const DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'stock-admin-e2e-'));

const sql = (q, db = DB) =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '-N', '-B', db, '-e', q], { encoding: 'utf8', maxBuffer: 1 << 28 }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 45000) {
  const t0 = Date.now();
  for (;;) {
    let v; try { v = await fn(); } catch { v = false; }
    if (v) return v;
    if (Date.now() - t0 > ms) throw new Error('timeout waiting for ' + what);
    await sleep(200);
  }
}
let passed = 0, failed = 0;
function check(name, ok, detail = '') {
  if (ok) { passed++; console.log('  ok   ' + name); }
  else { failed++; console.log('  FAIL ' + name + (detail ? '  — ' + detail : '')); }
}

const TABLES = ['users', 'ordering_users'];
const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '--single-transaction', '--no-tablespaces', DB, ...TABLES], { maxBuffer: 1 << 30 });
const before = {
  users: sql('SELECT CONCAT_WS("|",id,nama,pin,role,keterangan,data) FROM users ORDER BY id'),
  ordering: sql('SELECT CONCAT_WS("|",id,nama,pin,role,keterangan,data) FROM ordering_users ORDER BY id'),
};

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,stock'],
  { stdio: 'ignore', env: { ...process.env, STOCK_DATA_DIR: DATA_DIR } });
const tabs = [];
let restored = false;
const restore = async () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {}
  await sleep(1200);
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', DB], { input: snapshot, maxBuffer: 1 << 30 });
  try { fs.rmSync(DATA_DIR, { recursive: true, force: true }); } catch {}
};
process.on('exit', () => { if (!restored) restore(); });

let lmSession = '';
const pageErrors = [];
async function openTab(label, urlPath, ready) {
  const html = await (await fetch(`${BASE}${urlPath}`)).text();
  // The two same-origin libs the Panel ships (laksamana-forecast.js and
  // forecast_export.js). jsdom does not fetch subresources, so they are read
  // through the proxy and evaluated before the page script runs: the real
  // library, not a stub, must answer restockTable()/healthView().
  const libs = await Promise.all(['laksamana-forecast.js', 'forecast_export.js']
    .map((n) => fetch(`${BASE}${urlPath}${n}`).then((r) => (r.ok ? r.text() : '')).catch(() => '')));
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}${urlPath}`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      w.localStorage.setItem('lm_session', lmSession);
      w.localStorage.setItem('purchasing_webAppUrlForecast', '');
      w.fetch = (input, init = {}) => {
        const url = new URL(String(input), w.location.href);
        if (url.hostname === 'script.google.com') return Promise.resolve({ ok: false, json: async () => ({}) });
        const { signal, ...rest } = init;
        return fetch(url, rest);
      };
      w.confirm = () => true;
      w.URL.createObjectURL = () => 'blob:mock';
      w.URL.revokeObjectURL = () => {};
      w.tailwind = { config: {} };
      for (const src of libs) {
        if (src) {
          try { w.eval(src); } catch (e) { pageErrors.push(`[${label}] lib ${String(e).slice(0, 160)}`); }
        }
      }
      w.LaksForecast ||= {
        ForecastBook: class { constructor() {} },
        fmtNum: () => '', fmtDays: () => '', TIER_LABEL: {},
      };
      w.alert = (m) => pageErrors.push(`[${label}] alert ${m}`);
      w.scrollTo = () => {};
      w.HTMLElement.prototype.scrollIntoView = () => {};
      w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
      for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
        if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
    },
  });
  const w = dom.window;
  tabs.push(w);
  try {
    await until(() => ready(w), `${label} boot`);
  } catch (e) {
    console.log(`    ${label} readyState`, w.document.readyState, 'url', w.location.href);
    console.log(`    ${label} errors`, pageErrors.slice(-8));
    throw e;
  }
  return w;
}
const textOf = (w, selector) => (w.document.querySelector(selector)?.textContent || '').replace(/\s+/g, ' ').trim();
// A Panel that throws inside an unawaited promise is a bug to report, not a
// reason to take the whole run down with a raw Node stack.
process.on('unhandledRejection', (e) => pageErrors.push(`unhandled rejection ${String(e).slice(0, 160)}`));
// One page failing must not hide the findings of the others.
function tryEval(w, code) {
  try { return { value: w.eval(code) }; } catch (e) { return { error: String(e).split('\n')[0] }; }
}

try {
  await until(async () => (await fetch(`${BASE}/stock-api-mysql/items.php?action=ping`)).ok, 'devproxy');
  const ping = await fetch(`${BASE}/stock-api-mysql/items.php?action=ping`);
  check('stock API served by Laravel', ping.headers.get('x-devproxy-backend') === 'laravel');

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('Office login has ordering + purchasing', login.ok && ['ordering', 'purchasing'].every((m) => (u.modules || []).includes(m)), JSON.stringify(u.modules));
  lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  console.log('\n1. Ordering Panel');
  const O = await openTab('ordering', '/stock/ordering/', (w) => w.eval('appInitialized && !!currentUser && currentUser.role==="admin"'));
  check('Ordering boot: crew + stock loaded', O.eval('loadUsers().length') === Number(sql('SELECT COUNT(*) FROM ordering_users')));
  for (const tab of ['order', 'ck', 'checkin', 'restock', 'forecast', 'users']) {
    const opened = tryEval(O, `switchTab(${JSON.stringify(tab)})`);
    const len = textOf(O, `#view-${tab}`).length;
    check(`ordering page ${tab}`, !opened.error && len > 120, opened.error || `only ${len} chars`);
  }

  const andryBefore = sql("SELECT role FROM ordering_users WHERE id='u-andry'");
  await O.eval(`setUserRole('u-andry','checkin')`);
  await until(() => sql("SELECT role FROM ordering_users WHERE id='u-andry'") === 'checkin', 'ordering role change');
  check('Kelola Akses role change saved through the page', andryBefore === 'checkin' || sql("SELECT role FROM ordering_users WHERE id='u-andry'") === 'checkin');
  await O.eval(`setUserRole('u-andry',${JSON.stringify(andryBefore)})`);

  O.eval(`(async()=>{const input=document.querySelector('input[data-target="sales_detail"]'); const file=new File([new Uint8Array([80,75,3,4,69,50,69])],${JSON.stringify(TAG + '.xlsx')},{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}); Object.defineProperty(input,'files',{value:[file]}); await handleTrainingUpload(input);})()`);
  await until(() => fs.existsSync(DATA_DIR) && fs.readdirSync(path.join(DATA_DIR, 'sales_detail')).some((f) => f.endsWith('.xlsx')), 'training upload');
  await until(() => /Arsip tersimpan sampai/.test(textOf(O, '#sales-archive-note')), 'training archive note');
  check('Data Forecast uploaded the xlsx and read the archive status back', /Arsip tersimpan sampai/.test(textOf(O, '#sales-archive-note')));

  console.log('\n2. Purchasing Panel');
  const P = await openTab('purchasing', '/stock/purchasing/', (w) => w.eval('appBooted && !!currentUser && currentUser.role==="admin"'));
  check('missing stock_settings falls back to the built-in matrix', P.eval('PERMS === null') && P.eval('pagePerm("dashboard","full")') === 2);
  for (const tab of ['dashboard', 'jemput', 'online', 'ck', 'overview', 'database', 'users', 'log']) {
    const opened = tryEval(P, `switchTab(${JSON.stringify(tab)})`);
    const len = textOf(P, `#content-${tab}`).length;
    check(`purchasing page ${tab}`, !opened.error && len > 120, opened.error || `only ${len} chars`);
  }

  // Add then demote a crew member through the Panel's own helpers, so the walk
  // goes users.php add -> setUserRole -> users.php update like a real admin does.
  const crewId = 'u-' + TAG.toLowerCase().replace(/[^a-z0-9]+/g, '').slice(0, 10);
  const push = tryEval(P, `(async()=>{
    const list=loadUsersLocal();
    const crew={id:${JSON.stringify(crewId)},name:${JSON.stringify(TAG)},pin:'1234',role:'full',keterangan:'Kitchen'};
    list.push(crew); saveUsersLocal(list);
    const added=await pushUserToSheet('add',crew);
    setUserRole(${JSON.stringify(crewId)},'view');
    return {added, updated: await pushUserToSheet('update',Object.assign(crew,{role:'view'}))};
  })()`);
  const crewRow = await until(() => sql(`SELECT CONCAT_WS('|',nama,role,keterangan) FROM users WHERE id='${crewId}'`) || null,
    'purchasing crew row', 15000).catch(() => null);
  check('Kelola Akses add + demote saved through the page', !push.error && crewRow === `${TAG}|view|Kitchen`,
    push.error || `row=${crewRow}`);
  const saved = await P.eval(`savePerms({dashboard:{full:1}})`);
  check('purchasing settings honestly reports the missing table', saved === false && P.eval('PERMS === null'));

  console.log('\n3. untouched state');
  await sleep(800);
  const usersNow = sql('SELECT CONCAT_WS("|",id,nama,pin,role,keterangan,data) FROM users ORDER BY id')
    .split(/\r?\n/).filter((r) => !r.startsWith(crewId + '|'));
  const usersWas = before.users.split(/\r?\n/);
  check('users rows the walkthrough did not touch are unchanged', usersNow.join('\n') === usersWas.join('\n'),
    [...usersWas.filter((r, i) => usersNow[i] !== r), ...usersNow.filter((r, i) => usersWas[i] !== r)].slice(0, 4).join(' / '));
  check('ordering_users rows are unchanged', sql('SELECT CONCAT_WS("|",id,nama,pin,role,keterangan,data) FROM ordering_users ORDER BY id') === before.ordering);
  const relevant = pageErrors.filter((e) => !/Could not parse CSS|ResizeObserver loop/i.test(e));
  check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + (e?.stack || e));
}

await restore();
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
