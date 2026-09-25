#!/usr/bin/env node
/*
 * E2E — the REAL old Finance panels (deploy/finance/kas + deploy/finance/brankas)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/finance.mjs [--port 8192]
 *
 * Only /finance-api-mysql + /account-api-mysql go to Laravel; kompas, bd,
 * reservasi and stock (which the panels also read) stay on legacy PHP.
 *
 *   1. Kas Kecil SSO boot (u-novi, module finance) -> KK loaded from the DB
 *   2. every Kas page renders
 *   3. a transaction through the page's own writer (kkKirim simpanTrx) -> DB + reload
 *   4. the Bon tick (kkTandai) -> DB
 *   5. delete it (kkHapus) -> gone
 *   6. the invoice queue page lists the DB requests
 *   7. Brankas SSO boot (u-dwipa, module brankas) -> every Brankas page renders
 * Finally lakk5493_db_finance is restored from a snapshot taken at the start.
 *
 * LOCAL ONLY. PHP_BIN=C:\Users\dwip\.config\herd-lite\bin\php.exe
 */
import { spawn, execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_ROOT = path.resolve(HERE, '../..');
const MYSQL = process.env.MYSQL_BIN || 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysql.exe';
const MYSQLDUMP = MYSQL.replace(/mysql(\.exe)?$/, 'mysqldump$1');
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8192'), 10);
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_finance';
const TAG = 'e2e' + Date.now().toString(36);

const sql = (q, db = DB) =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '-N', '-B', '-r', db, '-e', q], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 30000) {
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

const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const TABLES = ['kk_pos', 'kk_kategori', 'kk_trx', 'kk_trx_pos', 'kk_akses', 'kk_peran', 'bk_state', 'bk_akses', 'bk_peran', 'inv_kwitansi', 'inv_setting', 'inv_penanda'];
// one MD5 per row (a GROUP_CONCAT of whole rows would be truncated at 1 KB)
const hashOf = (t) => sql(`SELECT MD5(CONCAT_WS('|', ${sql(`SELECT GROUP_CONCAT(CONCAT('\`', column_name, '\`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema='${DB}' AND table_name='${t}'`)})) AS h FROM \`${t}\` ORDER BY h`);
const before = Object.fromEntries(TABLES.map((t) => [t, hashOf(t)]));

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,finance'], { stdio: 'ignore' });
let restored = false;
const tabs = [];
const restore = () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {}
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', DB], { input: snapshot, maxBuffer: 1 << 30 });
};
process.on('exit', restore);

async function session(userId, module) {
  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${userId}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check(`login ${userId} via Laravel, holds ${module}`, login.ok && (u.modules || []).includes(module), JSON.stringify(u.modules));
  return JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });
}

const pageErrors = [];
async function openTab(label, urlPath, lmSession, ready) {
  const html = await (await fetch(`${BASE}${urlPath}`)).text();
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}${urlPath}`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      w.localStorage.setItem('lm_session', lmSession);
      w.fetch = (input, init = {}) => {
        const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
        return fetch(new URL(String(input), w.location.href), rest);
      };
      w.confirm = () => true;
      w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
      w.scrollTo = () => {};
      w.print = () => {};
      // CDN libraries are not loaded by jsdom: stub Chart.js (charts are not under test)
      w.Chart = class { constructor() {} destroy() {} update() {} };
      w.HTMLElement.prototype.scrollIntoView = () => {};
      for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
        if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
    },
  });
  const w = dom.window;
  tabs.push({ close: () => dom.window.close() });
  await until(() => ready(w), `${label} boot`);
  return w;
}
const viewText = (w, v) => { w.eval(`go(${JSON.stringify(v)})`); return (w.document.querySelector('main') || w.document.body).textContent; };

try {
  await until(async () => (await fetch(`${BASE}/finance-api-mysql/api.php?action=ping`)).ok, 'devproxy');
  const hdr = (await fetch(`${BASE}/finance-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('finance API served by Laravel', hdr === 'laravel', String(hdr));

  console.log('\n1. Kas Kecil boot');
  const kas = await openTab('kas', '/finance/kas/', await session('u-novi', 'finance'), (w) => w.eval('KK!==null'));
  check('KK loaded from the DB', kas.eval('KK.pos.length') === Number(sql('SELECT COUNT(*) FROM kk_pos'))
    && kas.eval('KK.trx.length') === Number(sql('SELECT COUNT(*) FROM kk_trx')));

  console.log('\n2. every Kas page renders');
  const views = [...kas.document.querySelectorAll('.nav a[data-view]')].map((a) => a.dataset.view);
  check('menu lists the pages', views.length >= 10, views.join(','));
  for (const v of views) check(`page ${v}`, viewText(kas, v).length > 50);

  console.log('\n3. a transaction through the page');
  kas.eval('go("kk_input")');
  kas.eval(`kkKirim('simpanTrx', {tgl:'2099-01-02', keterangan:'E2E ${TAG}', kategori_id:'', baris:[{pos_id:1, debet:0, kredit:12345}]}, 'x')`);
  const tid = await until(() => sql(`SELECT id FROM kk_trx WHERE keterangan='E2E ${TAG}'`), 'trx in DB');
  check('trx + split row in DB, author = the user', sql(`SELECT CONCAT(dibuat_oleh,'|',(SELECT kredit FROM kk_trx_pos WHERE trx_id=${tid}))  FROM kk_trx WHERE id=${tid}`).endsWith('|12345'),
    sql(`SELECT dibuat_oleh FROM kk_trx WHERE id=${tid}`));
  await until(() => kas.eval(`KK.trx.some(function(t){return t.id===${tid};})`), 'page reloaded KK');
  check('page shows it after its reload', true);

  console.log('\n4. Bon tick');
  kas.eval('go("kk_buku")');
  kas.eval(`kkTandai(${tid}, 'bon', 1)`);
  await until(() => sql(`SELECT bon FROM kk_trx WHERE id=${tid}`) === '1', 'bon=1');
  check('bon=1 in DB', true);

  console.log('\n5. delete');
  await until(() => !kas.eval('KK_SIBUK'), 'idle');
  kas.eval(`kkHapus(${tid})`);
  await until(() => sql(`SELECT COUNT(*) FROM kk_trx WHERE id=${tid}`) === '0', 'trx deleted');
  check('trx and its split rows gone', sql(`SELECT COUNT(*) FROM kk_trx_pos WHERE trx_id=${tid}`) === '0');

  console.log('\n6. invoice queue');
  viewText(kas, 'invoice');
  await until(() => kas.eval('INV!==null'), 'invoice list loaded', 15000);
  check('invoice queue loaded from the DB (requests + signatories)', kas.eval('INV.length') === Number(sql('SELECT COUNT(*) FROM inv_kwitansi'))
    && kas.eval('INV_PEN.length') === Number(sql('SELECT COUNT(*) FROM inv_penanda')), kas.eval('JSON.stringify([INV.length, INV_PEN.length])'));

  console.log('\n7. Brankas');
  const bk = await openTab('brankas', '/finance/brankas/', await session('u-dwipa', 'brankas'), (w) => w.document.body.textContent.length > 500 && w.eval('typeof BK!=="undefined" && BK!==null && !!BK.data'));
  check('vault state loaded', bk.eval('BK.data.mutasi.length') === JSON.parse(sql('SELECT data FROM bk_state WHERE id=1')).mutasi.length);
  for (const v of [...bk.document.querySelectorAll('[data-view]')].map((a) => a.dataset.view)) {
    bk.eval(`go(${JSON.stringify(v)})`);
    check(`brankas page ${v}`, bk.document.body.textContent.length > 200);
  }

  const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
  check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
  if (pageErrors.length) console.log('    page errors:', pageErrors.slice(0, 5));
}

await sleep(2000);
restore();
const after = Object.fromEntries(TABLES.map((t) => [t, hashOf(t)]));
check('database restored from snapshot', JSON.stringify(after) === JSON.stringify(before), TABLES.filter((t) => after[t] !== before[t]).join(','));
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
