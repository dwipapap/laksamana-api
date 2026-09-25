#!/usr/bin/env node
/*
 * E2E — the REAL old Staff Performance frontend (laksamana-office/deploy/hr/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/hr.mjs [--port 8190] [--user u-rizkiarfan]
 *
 * Only /hr-api-mysql + /account-api-mysql go to Laravel (akademi's
 * trainingStats, which the page also fetches, stays on legacy PHP).
 *
 *   1. SSO boot as the hr module admin -> state + _rev loaded
 *   2. all 17 pages render (the admin-only ones included)
 *   3. setMood -> moods row, rev +1, saved_by = the user
 *   4. anonymous suggestion (addSuggestion) -> emp_id NULL
 *   5. two tabs: B saves, stale A saves -> A gets the conflict modal and B's work survives
 *   6. the attendance history (extra:attendance) survives every save
 *   7. rows the walkthrough did not touch keep their content
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
const PORT = parseInt(arg('--port', '8190'), 10);
const USER = arg('--user', 'u-rizkiarfan');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_hr';
const TAG = 'e2e' + Date.now().toString(36);

// --default-character-set: the client otherwise picks latin1 output on some calls; -r: no escaping
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
const rev = () => Number(sql('SELECT rev FROM meta WHERE id=1'));

// ---- snapshot, restored at the end whatever happens ---------------------------
const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const TABLES = ['divisions', 'employees', 'kpi_templates', 'okrs', 'reviews', 'competencies', 'trainings', 'training_records', 'coachings',
  'rewards', 'badges', 'violations', 'feedbacks', 'career_paths', 'successions', 'moods', 'suggestions', 'calendar'];
const rowsOf = () => Object.fromEntries([
  ...TABLES.map((t) => [t, sql(`SELECT id, data FROM \`${t}\` ORDER BY id`)]),
  ['settings', sql('SELECT k, v FROM settings ORDER BY k')],
]);
const before = rowsOf();
const attBefore = sql("SELECT v FROM settings WHERE k='extra:attendance'");

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,hr'], { stdio: 'ignore' });
let restored = false;
const tabs = [];
const restore = () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {} // stop their timers before restoring
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', DB], { input: snapshot, maxBuffer: 1 << 30 });
};
process.on('exit', restore);

try {
  await until(async () => (await fetch(`${BASE}/hr-api-mysql/api.php?action=ping`)).ok, 'devproxy');
  const hdr = (await fetch(`${BASE}/hr-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('hr API served by Laravel', hdr === 'laravel', String(hdr));
  const hdrAk = (await fetch(`${BASE}/akademi-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('akademi stays on legacy PHP', hdrAk === 'legacy-php', String(hdrAk));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('Office login via Laravel, hr module admin', login.ok && (u.modules || []).includes('hr') && (u.adminModules || []).includes('hr'), JSON.stringify(u.adminModules));
  const lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  const html = await (await fetch(`${BASE}/hr/`)).text();
  const pageErrors = [];
  async function openTab(label) {
    const vc = new VirtualConsole();
    vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
    vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
    const dom = new JSDOM(html, {
      url: `${BASE}/hr/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
      beforeParse(w) {
        w.localStorage.setItem('lm_session', lmSession);
        w.fetch = (input, init = {}) => {
          const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
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
      await until(() => w.APP && w.APP._state() && w.APP._state()._rev !== undefined && w.document.getElementById('content').textContent.length > 0, `${label} boot`);
    } catch (e) {
      console.log('    boot:', pageErrors.slice(0, 5));
      dom.window.close();
      throw e;
    }
    const tab = { w, close: () => dom.window.close() };
    tabs.push(tab);
    return tab;
  }
  const S = (w) => w.APP._state();

  console.log('\n1. SSO boot');
  const A = await openTab('tabA');
  check('state + _rev loaded from DB', S(A.w)._rev === rev() && S(A.w).employees.length === Number(sql('SELECT COUNT(*) FROM employees')));

  console.log('\n2. all pages render');
  const PAGES = ['dash', 'score', 'kehadiran', 'kpi', 'okr', 'review', 'training', 'comp', 'coach', 'career', 'reward', 'disc', 'engage', 'emp', 'cal', 'settings', 'audit'];
  for (const p of PAGES) {
    A.w.APP.go(p);
    check(`page ${p}`, A.w.document.getElementById('pageTitle').textContent !== 'Anjungan' || p === 'dash', A.w.document.getElementById('pageTitle').textContent);
  }

  console.log('\n3. mood');
  const r0 = rev();
  A.w.APP.setMood(4);
  await until(() => rev() === r0 + 1, 'rev bumped');
  check('moods row for the user, saved_by = the user',
    sql(`SELECT mood FROM moods WHERE emp_id='${S(A.w).moods.find((m) => m.mood === 4 && m.date === new Date().toLocaleDateString('en-CA'))?.empId}' ORDER BY id DESC LIMIT 1`) === '4'
    && sql('SELECT saved_by FROM meta') === u.name, sql('SELECT saved_by FROM meta'));

  console.log('\n4. anonymous suggestion');
  A.w.APP.go('engage');
  A.w.document.getElementById('sg_text').value = 'E2E saran ' + TAG;
  A.w.document.getElementById('sg_anon').checked = true;
  A.w.APP.addSuggestion();
  await until(() => sql(`SELECT COUNT(*) FROM suggestions WHERE data LIKE '%E2E saran ${TAG}%'`) === '1', 'suggestion row');
  check('suggestion stored with emp_id NULL', sql(`SELECT IFNULL(emp_id,'NULL') FROM suggestions WHERE data LIKE '%E2E saran ${TAG}%'`) === 'NULL');
  await until(() => S(A.w)._rev === rev(), 'tab A caught up with its own save');

  console.log('\n5. two tabs -> conflict');
  const B = await openTab('tabB');
  B.w.APP.setMood(2);
  await until(() => rev() === S(A.w)._rev + 1, "tab B's save");
  const moodB = sql(`SELECT data FROM moods WHERE data LIKE '%"mood":2%' ORDER BY id DESC LIMIT 1`);
  A.w.APP.setMood(5); // A still holds the older rev
  await until(() => A.w.document.getElementById('sync-badge') && !A.w.document.getElementById('sync-badge').hidden, 'conflict badge', 15000);
  check('stale tab A gets the conflict modal', A.w.document.body.textContent.includes('Perubahan Tidak Tersimpan'));
  check("tab B's work was not overwritten", sql(`SELECT data FROM moods WHERE data LIKE '%"mood":2%' ORDER BY id DESC LIMIT 1`) === moodB && sql(`SELECT COUNT(*) FROM moods WHERE data LIKE '%"mood":5%'`) === '0');
  A.close();
  B.close();

  console.log('\n6. attendance history');
  check('extra:attendance unchanged after all saves', sql("SELECT v FROM settings WHERE k='extra:attendance'") === attBefore);

  console.log('\n7. untouched rows');
  const canon = (v) => JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x)) ? Object.fromEntries(Object.entries(x).sort()) : x);
  const after = rowsOf();
  for (const t of [...TABLES, 'settings']) {
    const decode = (s) => Object.fromEntries(s.split('\n').filter(Boolean).map((l) => { const i = l.indexOf('\t'); return [l.slice(0, i), canon(JSON.parse(l.slice(i + 1)))]; }));
    const a = decode(before[t]), b = decode(after[t]);
    // new rows (mood, suggestion) and settings keys the page may add are not "untouched" rows
    const changed = Object.keys(a).filter((id) => a[id] !== b[id]);
    check(`${t}: existing rows keep their content`, changed.length === 0, changed.slice(0, 3).join(','));
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
  Object.keys(before).filter((t) => afterRestore[t] !== before[t]).join(','));
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
