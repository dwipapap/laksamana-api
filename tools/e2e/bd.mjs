#!/usr/bin/env node
/*
 * E2E — the REAL old BD OS frontend (laksamana-office/deploy/bd/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/bd.mjs [--port 8188] [--user u-novi]
 *
 * Only /bd-api-mysql + /account-api-mysql go to Laravel.
 *
 *   1. SSO boot (roster sync through the Laravel account API) -> app opens
 *   2. every menu view renders
 *   3. new task via taskModal/simpanTask -> row + indexed columns in DB
 *   4. toggleTask -> Done in DB
 *   5. togglePoProses on a PO -> Diterima + statusSebelum; toggle back restores it
 *   6. Marketing's addPo and Finance's setRealisasi (the calls those pages make)
 *      -> a fresh tab sees both
 *   7. hapusTask -> row gone (sinceTs-bounded delete)
 *   8. rows the walkthrough did not touch are byte-identical
 * Finally the database is restored from a snapshot taken at the start.
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
const PORT = parseInt(arg('--port', '8188'), 10);
const USER = arg('--user', 'u-novi');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_bd';
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
const post = async (body) => (await fetch(`${BASE}/bd-api-mysql/api.php`, {
  method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify(body) })).json();

// ---- snapshot, restored at the end whatever happens ---------------------------
const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const TABLES = ['people', 'projects', 'tasks', 'routines', 'coord_requests', 'purchase_orders', 'purchase_requests', 'agenda', 'settings'];
const rowsOf = () => Object.fromEntries(TABLES.map((t) => [t, sql(t === 'settings' ? 'SELECT k, v FROM settings ORDER BY k' : `SELECT id, data FROM \`${t}\` ORDER BY id`)]));
const before = rowsOf();

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,bd'], { stdio: 'ignore' });
let restored = false;
const tabs = [];
const restore = () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {} // stop their polling/saves before restoring
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', DB], { input: snapshot, maxBuffer: 1 << 30 });
};
process.on('exit', restore);

try {
  await until(async () => (await fetch(`${BASE}/bd-api-mysql/api.php?action=ping`)).ok, 'devproxy');
  const ping = await (await fetch(`${BASE}/bd-api-mysql/api.php?action=ping`)).json();
  check('bd API served by Laravel', ping.ok && ping.data.backend === 'laravel', JSON.stringify(ping).slice(0, 160));
  const hdr = (await fetch(`${BASE}/kompas-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('other modules stay on legacy PHP (kompas)', hdr === 'legacy-php', String(hdr));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('Office login via Laravel, user holds bd', login.ok && (u.modules || []).includes('bd'), JSON.stringify(u.modules));
  const lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  const html = await (await fetch(`${BASE}/bd/`)).text();
  const pageErrors = [];
  async function openTab(label) {
    const vc = new VirtualConsole();
    vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
    vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
    const dom = new JSDOM(html, {
      url: `${BASE}/bd/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
      beforeParse(w) {
        w.localStorage.setItem('lm_session', lmSession);
        w.fetch = (input, init = {}) => {
          const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
          if (rest.body) { w.__posts = w.__posts || []; w.__posts.push(String(rest.body)); }
          return fetch(new URL(String(input), w.location.href), rest);
        };
        w.confirm = () => true;
        w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
        w.scrollTo = () => {};
        w.HTMLElement.prototype.scrollIntoView = () => {};
        for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
          if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
      },
    });
    const w = dom.window;
    try {
      await until(() => w.document.getElementById('app').style.display === '' && typeof w.APP === 'object', `${label} boot`);
    } catch (e) {
      console.log('    boot:', w.document.getElementById('loading').textContent.replace(/s+/g, ' ').slice(0, 200), pageErrors.slice(0, 5));
      dom.window.close();
      throw e;
    }
    const tab = { w, close: () => dom.window.close() };
    tabs.push(tab);
    return tab;
  }
  // the app is one IIFE: only window.APP is reachable; save state shows in #sync
  const idle = async (w) => { await sleep(900); await until(() => w.document.querySelector('#sync span').textContent === 'Tersimpan', 'save idle'); };
  const VIEWS = ['dashboard', 'tasks', 'projects', 'calendar', 'coord', 'routine', 'promo', 'purchasing', 'tim'];
  const viewText = (w, v) => { w.eval(`APP.go(${JSON.stringify(v)})`); return w.document.getElementById('view').textContent; };
  // two queries: `mysql -B` escapes tabs/backslashes inside values, so one CONCAT'd line would not split reliably
  const poRow = (id) => {
    const status = sql(`SELECT status FROM purchase_orders WHERE id='${id}'`);
    if (!status) return null;
    return { status, data: JSON.parse(sql(`SELECT JSON_OBJECT('statusSebelum', JSON_EXTRACT(data,'$.statusSebelum'), 'prosesBy', JSON_EXTRACT(data,'$.prosesBy')) FROM purchase_orders WHERE id='${id}'`)) };
  };

  console.log('\n1. SSO boot');
  const A = await openTab('tabA');
  const somePo = sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(data,'$.item')) FROM purchase_orders ORDER BY created_at DESC LIMIT 1");
  check('purchasing board shows DB rows', viewText(A.w, 'purchasing').includes(somePo), somePo);
  await idle(A.w); // the roster sync may save people rows at boot

  console.log('\n2. every menu view renders');
  for (const v of VIEWS) check(`view ${v}`, viewText(A.w, v).length > 50);

  console.log('\n3. new task');
  A.w.eval('APP.go("tasks"); APP.taskModal()');
  A.w.document.getElementById('m_name').value = 'E2E task ' + TAG;
  A.w.document.getElementById('m_due').value = '2099-01-02';
  A.w.eval('APP.simpanTask()');
  const tid = await until(() => sql(`SELECT id FROM tasks WHERE name='E2E task ${TAG}'`), 'task in DB');
  check('task row in DB', /^t[0-9a-z]{7}$/.test(tid), tid);
  check('indexed columns extracted', sql(`SELECT CONCAT(deadline,'|',status) FROM tasks WHERE id='${tid}'`) === '2099-01-02|To Do');

  console.log('\n4. toggle it done');
  await idle(A.w);
  A.w.eval(`APP.toggleTask(${JSON.stringify(tid)})`);
  await until(() => sql(`SELECT status FROM tasks WHERE id='${tid}'`) === 'Done', 'task Done');
  check('task Done in DB', true);

  console.log('\n5. PO proses marker');
  await idle(A.w);
  const pid = sql("SELECT id FROM purchase_orders WHERE status='Diajukan' AND data NOT LIKE '%\"proses\":true%' ORDER BY id LIMIT 1");
  A.w.eval(`APP.togglePoProses(${JSON.stringify(pid)})`);
  try {
    await until(() => (poRow(pid) || {}).status === 'Diterima', 'PO Diterima', 15000);
  } catch (e) {
    const last = (A.w.__posts || []).slice(-1)[0] || '';
    const lp = last ? (JSON.parse(last).data.po || []).find((p) => p.id === pid) : null;
    console.log('    posts:', (A.w.__posts || []).length, 'last po row:', JSON.stringify(lp));
    console.log('    sync:', A.w.document.querySelector('#sync').title, A.w.document.querySelector('#sync span').textContent,
      '| toasts:', A.w.document.getElementById('toasts').textContent.slice(0, 200), '| pid', pid);
    throw e;
  }
  check('PO Diterima with statusSebelum', poRow(pid).data.statusSebelum === 'Diajukan' && poRow(pid).data.prosesBy === u.name);
  await idle(A.w);
  A.w.eval(`APP.togglePoProses(${JSON.stringify(pid)})`);
  await until(() => (poRow(pid) || {}).status === 'Diajukan', 'PO back to Diajukan');
  check('toggle back restores the status', poRow(pid).data.statusSebelum === null);
  await idle(A.w);

  console.log('\n6. Marketing addPo + Finance setRealisasi');
  const add = await post({ action: 'addPo', items: [{ item: 'E2E barang ' + TAG, qty: 2, amount: 25000, div: 'Marketing' }] });
  check('addPo', add.ok && add.data.added === 1, JSON.stringify(add));
  const newPo = sql(`SELECT id FROM purchase_orders WHERE item='E2E barang ${TAG}'`);
  const real = await post({ action: 'setRealisasi', id: newPo, realisasi: 24000, oleh: 'Kasir E2E' });
  check('setRealisasi', real.ok && real.data.status === 'Diterima' && real.data.realisasi === 24000, JSON.stringify(real));
  const B = await openTab('tabB');
  check('fresh tab shows the new PO', viewText(B.w, 'purchasing').includes('E2E barang ' + TAG));
  await idle(B.w);
  check('the done task is still Done after tab B booted', sql(`SELECT status FROM tasks WHERE id='${tid}'`) === 'Done');
  A.close();

  console.log('\n7. delete the task');
  B.w.eval(`APP.hapusTask(${JSON.stringify(tid)})`);
  await until(() => sql(`SELECT COUNT(*) FROM tasks WHERE id='${tid}'`) === '0', 'task deleted');
  check('task row gone', true);
  check('the PO added after tab A loaded survived tab B saves', !!sql(`SELECT id FROM purchase_orders WHERE id='${newPo}'`));
  await idle(B.w);
  B.close();

  console.log('\n8. untouched rows');
  // Compared as decoded JSON: legacy saveAll rewrites equal-stamp rows with the page's
  // re-encoded copy, so bytes may legitimately change while the content must not.
  const after = rowsOf();
  const canon = (v) => JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x)) ? Object.fromEntries(Object.entries(x).sort()) : x);
  const decoded = (s) => Object.fromEntries(s.split('\n').filter(Boolean).map((l) => {
    const i = l.indexOf('\t');
    return [l.slice(0, i), canon(JSON.parse(l.slice(i + 1)))];
  }).filter(([id]) => ![tid, pid, newPo].includes(id)));
  for (const t of TABLES) {
    if (t === 'people') continue; // the roster sync may refresh people rows on boot
    const a = decoded(before[t]), b = decoded(after[t]);
    const diff = Object.keys({ ...a, ...b }).filter((id) => a[id] !== b[id]);
    const at = (x, y) => { let i = 0; while (i < x.length && x[i] === y[i]) i++; return i; };
    check(`${t}: other rows unchanged`, diff.length === 0, diff.slice(0, 2).map((id) => { const i = at(a[id] || '', b[id] || ''); return `${id}: ...${(a[id] || '').slice(i - 30, i + 40)} -> ...${(b[id] || '').slice(i - 30, i + 40)}`; }).join(' | '));
  }

  const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
  check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
}

await sleep(3000); // let requests still in flight from the closed tabs land before restoring
restore();
const afterRestore = rowsOf();
check('database restored from snapshot', JSON.stringify(afterRestore) === JSON.stringify(before),
  TABLES.filter((t) => afterRestore[t] !== before[t]).map((t) => {
    const a = before[t].split('\n'), b = afterRestore[t].split('\n');
    return t + ' ' + a.length + '/' + b.length + ' e.g. ' + (a.find((l, i) => l !== b[i]) || '').slice(0, 120);
  }).join(', '));
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
