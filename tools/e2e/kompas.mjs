#!/usr/bin/env node
/*
 * E2E — the REAL old Kompas-backed frontends (deploy/cashier, deploy/finance/omset,
 * deploy/analytics) running headless (jsdom) against Laravel, through devproxy.
 *
 *   node tools/e2e/kompas.mjs [--port 8194] [--user u-dwipa]
 *
 * Only /kompas-api-mysql + /account-api-mysql go to Laravel; everything else
 * the pages read (reservasi DPs, event eventsHari, …) stays on legacy PHP.
 *
 *   1. Cashier boot (module cashier) -> DB + BASE_TS loaded; every page renders
 *   2. a compliment saved through the page's own save() -> blob has it, _savedBy
 *      = the user, BASE_TS advances so a SECOND save from the same tab passes
 *   3. two tabs: B saves, stale A saves -> refused (konflik), B's work survives
 *   4. Finance › Omset boot -> every page renders from the same blob
 *   5. Analytics boot -> every page renders; the Void & Cancel page reads voidList
 *   6. rows the walkthrough did not touch keep their content
 * Finally lakk5493_db_kompas is restored from a snapshot taken at the start.
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
const PORT = parseInt(arg('--port', '8194'), 10);
const USER = arg('--user', 'u-dwipa');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_kompas';
const TAG = 'e2e' + Date.now().toString(36);

const sql = (q, db = DB) =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '-N', '-B', '-r', db, '-e', q], { encoding: 'utf8', maxBuffer: 1 << 28 }).trim();
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
const blob = () => JSON.parse(sql('SELECT data FROM app_state WHERE id=1'));
const ts = () => Number(sql('SELECT updated_at FROM app_state WHERE id=1'));

const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const before = blob();

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,kompas'], { stdio: 'ignore' });
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

const pageErrors = [];
let lmSession = '';
async function openTab(label, urlPath, ready) {
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
      w.print = () => {};
      w.Chart = class { constructor() {} destroy() {} update() {} };   // CDN libs are not loaded by jsdom
      w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
      w.scrollTo = () => {};
      w.HTMLElement.prototype.scrollIntoView = () => {};
      for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
        if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
    },
  });
  const w = dom.window;
  tabs.push({ close: () => dom.window.close() });
  try {
    await until(() => ready(w), `${label} boot`);
  } catch (e) {
    console.log('    boot errors:', pageErrors.slice(-5));
    throw e;
  }
  return w;
}
const views = (w) => [...new Set([...w.document.querySelectorAll('[data-view]')].map((a) => a.dataset.view))];
const idle = (w) => until(() => w.eval('!SAVING && !DIRTY'), 'save idle');

try {
  await until(async () => (await fetch(`${BASE}/kompas-api-mysql/api.php?action=ping`)).ok, 'devproxy');
  const ping = await (await fetch(`${BASE}/kompas-api-mysql/api.php?action=ping`)).json();
  check('kompas API served by Laravel', ping.data && ping.data.backend === 'laravel', JSON.stringify(ping).slice(0, 120));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('login via Laravel (cashier, finance, analytics)', login.ok && ['cashier', 'finance', 'analytics'].every((m) => (u.modules || []).includes(m)), JSON.stringify(u.modules));
  lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  console.log('\n1. Cashier');
  const ready = (w) => w.eval('typeof DB!=="undefined" && DB!==null && BASE_TS>0');
  const A = await openTab('cashierA', '/cashier/', ready);
  check('blob + BASE_TS loaded', A.eval('BASE_TS') === ts() && A.eval('(DB.daily||[]).length') === before.daily.length);
  for (const v of views(A)) { A.eval(`go(${JSON.stringify(v)})`); check(`cashier page ${v}`, A.document.body.textContent.length > 200); }

  console.log('\n2. save through the page, twice');
  const ts0 = ts();
  A.eval(`DB.compliments=DB.compliments||[]; DB.compliments.push({id:'e2e1',date:'2026-09-20',nominal:12345,ket:'${TAG}'}); save();`);
  await until(() => ts() > ts0 && JSON.stringify(blob().compliments || []).includes(TAG), 'first save');
  await idle(A);
  check('saved, _savedBy = the user', blob()._savedBy === u.name, blob()._savedBy);
  const ts1 = ts();
  A.eval(`DB.compliments.find(function(c){return c.id==='e2e1';}).nominal=54321; save();`);
  await until(() => ts() > ts1, 'second save');
  await idle(A);
  check('second save from the same tab passes (BASE_TS advanced)', (blob().compliments || []).some((c) => c.id === 'e2e1' && c.nominal === 54321));

  console.log('\n3. two tabs -> stale save refused');
  const B = await openTab('cashierB', '/cashier/', ready);
  B.eval(`DB.compliments.push({id:'e2eB',date:'2026-09-20',nominal:1,ket:'${TAG}-B'}); save();`);
  await until(() => JSON.stringify(blob().compliments || []).includes(TAG + '-B'), 'tab B save');
  await idle(B);
  const tsB = ts();
  A.eval(`DB.compliments.push({id:'e2eA',date:'2026-09-20',nominal:2,ket:'${TAG}-A'}); save();`);
  await sleep(3500);
  check("stale tab A was refused and B's work survives", ts() === tsB && JSON.stringify(blob().compliments).includes(TAG + '-B') && !JSON.stringify(blob().compliments).includes(TAG + '-A'));

  console.log('\n4. Finance > Omset');
  const O = await openTab('omset', '/finance/omset/', ready);
  check('omset sees the compliments saved from Cashier', O.eval(`(DB.compliments||[]).some(function(c){return c.id==='e2eB';})`));
  for (const v of views(O)) { O.eval(`go(${JSON.stringify(v)})`); check(`omset page ${v}`, O.document.body.textContent.length > 200); }

  console.log('\n5. Analytics');
  const N = await openTab('analytics', '/analytics/', (w) => w.eval('typeof AN!=="undefined" && AN!==null'));
  for (const v of views(N)) { N.eval(`go(${JSON.stringify(v)})`); check(`analytics page ${v}`, N.document.body.textContent.length > 200); }

  console.log('\n6. untouched parts of the blob');
  await sleep(1000);
  const after = blob();
  for (const k of Object.keys(before).filter((k) => !['compliments', '_savedBy'].includes(k))) {
    check(`blob.${k} unchanged`, JSON.stringify(after[k]) === JSON.stringify(before[k]));
  }
  const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
  check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
}

await sleep(2000);
restore();
check('database restored from snapshot', JSON.stringify(blob()) === JSON.stringify(before));
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
