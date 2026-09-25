#!/usr/bin/env node
/*
 * E2E — the REAL old Howandi Life OS frontend (laksamana-office/deploy/howandi_life/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/hlife.mjs [--port 8186] [--user u-jb]
 *
 * Only /howandi-life-api-mysql + /account-api-mysql go to Laravel.
 * The page saves the WHOLE state (saveAll) after every edit, so the walkthrough
 * also proves that untouched rows round-trip byte-for-byte (`{}` stays `{}`).
 *
 *   1. SSO boot (lm_session with howandi_life) -> app opens with the DB state
 *   2. new task via openTaskModal/saveTask -> row in DB
 *   3. toggleTask -> done=1 in DB
 *   4. brain dump (addDump) -> settings.dump
 *   5. dream without a year (the real-data case: tahun=0 on non-strict prod)
 *   6. finance month via upsertLed -> ledger row
 *   7. every row the walkthrough did not touch is byte-identical
 *   8. reload in a fresh tab -> edits are there
 *   9. delTask -> row gone
 * Finally the database is restored from a snapshot taken at the start.
 *
 * LOCAL ONLY. Laravel runs with DB_HLIFE_SQL_MODE=NO_ENGINE_SUBSTITUTION to mirror
 * production's non-strict server (the restored data has dreams with year "").
 * PHP_BIN=C:\Users\dwip\.config\herd-lite\bin\php.exe
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
const PORT = parseInt(arg('--port', '8186'), 10);
const USER = arg('--user', 'u-jb');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_hlife';
const TAG = 'e2e' + Date.now().toString(36);

const sql = (q, db = DB) =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
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

// ---- snapshot, restored at the end whatever happens ----------------------------
const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const TABLES = ['businesses', 'projects', 'tasks', 'goals', 'dreams', 'roadmap', 'content', 'learning', 'habits', 'events', 'assets', 'reviews', 'ledger'];
const rowsOf = () => Object.fromEntries(TABLES.map((t) => [t, sql(`SELECT id, data FROM \`${t}\` ORDER BY id`)]));
const before = rowsOf();

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,hlife'],
  { stdio: 'ignore', env: { ...process.env, DB_HLIFE_SQL_MODE: 'NO_ENGINE_SUBSTITUTION' } });
let restored = false;
const restore = () => {
  if (restored) return;
  restored = true;
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', DB], { input: snapshot, maxBuffer: 1 << 30 });
};
process.on('exit', restore);

try {
  await until(async () => (await fetch(`${BASE}/howandi-life-api-mysql/api.php?action=ping`)).ok, 'devproxy');
  const hdr = (await fetch(`${BASE}/howandi-life-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('hlife API served by Laravel', hdr === 'laravel', String(hdr));
  const bdHdr = (await fetch(`${BASE}/bd-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('other modules stay on legacy PHP (bd)', bdHdr === 'legacy-php', String(bdHdr));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('Office login via Laravel, user holds howandi_life', login.ok && (u.modules || []).includes('howandi_life'), JSON.stringify(u.modules));
  const lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  const html = await (await fetch(`${BASE}/howandi_life/`)).text();
  const pageErrors = [];
  async function openTab(label) {
    const vc = new VirtualConsole();
    vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
    vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
    const dom = new JSDOM(html, {
      url: `${BASE}/howandi_life/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
      beforeParse(w) {
        w.localStorage.setItem('lm_session', lmSession);
        w.fetch = (input, init = {}) => {
          const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
          return fetch(new URL(String(input), w.location.href), rest);
        };
        w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
        w.scrollTo = () => {};
        w.HTMLElement.prototype.scrollIntoView = () => {};
        for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
          if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
      },
    });
    const w = dom.window;
    await until(() => w.eval('S!==null && document.getElementById("app").style.display==="grid"'), `${label} boot`);
    return { w, close: () => dom.window.close() };
  }
  const idle = (w) => until(() => w.eval('!_saving && !_again'), 'save idle');

  console.log('\n1. SSO boot');
  const A = await openTab('tabA');
  check('state loaded from DB', A.w.eval('S.tasks.length') === Number(sql('SELECT COUNT(*) FROM tasks')));
  check('home renders', A.w.document.getElementById('app').textContent.length > 200);

  console.log('\n2. new task');
  A.w.eval('openTaskModal(null)');
  A.w.document.getElementById('f-name').value = 'E2E task ' + TAG;
  A.w.eval('saveTask()');
  const tid = await until(() => sql(`SELECT id FROM tasks WHERE nama='E2E task ${TAG}'`), 'task in DB');
  check('task row in DB', !!tid, tid);

  console.log('\n3. toggle it done');
  await idle(A.w);
  A.w.eval(`toggleTask(${JSON.stringify(tid)})`);
  await until(() => sql(`SELECT done FROM tasks WHERE id='${tid}'`) === '1', 'done=1');
  check('task done=1 in DB', true);

  console.log('\n4. brain dump');
  await idle(A.w);
  A.w.eval("setView('home')");
  A.w.document.getElementById('dump-in').value = 'E2E dump ' + TAG;
  A.w.eval('addDump()');
  await until(() => JSON.parse(sql("SELECT v FROM settings WHERE k='dump'"))[0] === 'E2E dump ' + TAG, 'dump saved');
  check('settings.dump starts with the new entry', true);

  console.log('\n5. dream without a year');
  await idle(A.w);
  A.w.eval('openDreamModal()');
  A.w.document.getElementById('d-title').value = 'E2E dream ' + TAG;
  A.w.document.getElementById('d-year').value = '';
  A.w.eval('saveDream()');
  const dream = await until(() => sql(`SELECT CONCAT(id,'|',IFNULL(tahun,'NULL')) FROM dreams WHERE judul='E2E dream ${TAG}'`), 'dream in DB');
  check('dream saved, tahun=0 like non-strict production', dream.endsWith('|0'), dream);

  console.log('\n6. finance month');
  await idle(A.w);
  A.w.eval(`upsertLed('2099-01','personal',1234,56,'E2E ${TAG}'); save()`);
  await until(() => sql("SELECT CONCAT(income,'/',expense) FROM ledger WHERE bulan='2099-01'") === '1234/56', 'ledger row');
  check('ledger row in DB', true);

  console.log('\n7. untouched rows round-trip byte-for-byte');
  await idle(A.w);
  const after = rowsOf();
  const newIds = new Set([tid, dream.split('|')[0]]);
  for (const t of TABLES) {
    const keep = (s) => s.split('\n').filter((l) => l && !newIds.has(l.split('\t')[0]) && !l.includes('2099-01')).join('\n');
    check(`${t}: existing rows unchanged`, keep(after[t]) === keep(before[t]));
  }

  console.log('\n8. reload');
  const B = await openTab('tabB');
  check('new task survives reload', B.w.eval(`S.tasks.some(t=>t.id===${JSON.stringify(tid)} && t.done===true)`));
  check('dump survives reload', B.w.eval('S.dump[0]') === 'E2E dump ' + TAG);
  A.close();

  console.log('\n9. delete the task');
  B.w.eval(`delTask(${JSON.stringify(tid)})`);
  await until(() => sql(`SELECT COUNT(*) FROM tasks WHERE id='${tid}'`) === '0', 'task deleted');
  check('task row gone', true);
  await idle(B.w);
  B.close();

  const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
  check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
}

restore();
check('database restored from snapshot', JSON.stringify(rowsOf()) === JSON.stringify(before));
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
