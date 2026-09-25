#!/usr/bin/env node
/*
 * E2E — the REAL old akademi frontend (laksamana-office/deploy/akademi/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/akademi.mjs [--port 8183] [--user u-rizkiarfan]
 *
 * Proves the cutover: only /akademi-api-mysql + /account-api-mysql go to Laravel,
 * every other backend the page touches stays the old PHP.
 *
 * Walkthrough (every step through the page's own code: editDivisi/saveDivisi,
 * setProg, editProgram/saveProgram, logAct, delDivisi/delProgram):
 *   1. SSO boot with a Laravel-issued lm_session -> app opens, dashboard renders
 *   2. create a division via the page modal   -> divisions row in DB
 *   3. complete a material via setProg        -> progress row in DB
 *   4. create a monthly program via the page  -> programs row in DB
 *   5. logAct                                 -> activity row in DB
 *   6. reload in a fresh tab                  -> everything above is still there
 *   7. walk the screens (all 13 views)        -> they render
 *   8. clean up through the page              -> DB back to start
 *
 * LOCAL ONLY: writes to the local restored lakk5493_db_akademi (fixture
 * division + program + progress cell, all removed after; the activity
 * fixture row stays — the trail is append-only by design).
 */
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_ROOT = path.resolve(HERE, '../..');
// The script runs from a worktree as well as the main checkout: find the
// laksamana-office sibling wherever it is.
const officeCandidates = [
  process.env.OFFICE_DIR,
  path.resolve(API_ROOT, '../laksamana-office'),
  path.resolve(API_ROOT, '../../../../laksamana-office'),
].filter(Boolean);
const OFFICE = officeCandidates.find((d) => fs.existsSync(path.join(d, 'deploy/akademi/index.html')));
if (!OFFICE) { console.error('laksamana-office not found (set OFFICE_DIR)'); process.exit(2); }
const MYSQL = process.env.MYSQL_BIN || 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8183'), 10);
const USER = arg('--user', 'u-rizkiarfan');
const BASE = `http://127.0.0.1:${PORT}`;
const TAG = 'e2e' + Date.now().toString(36);

const sql = (q, db = 'lakk5493_db_akademi') =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 60000) {
  const t0 = Date.now();
  for (;;) {
    let v; try { v = await fn(); } catch { v = false; }
    if (v) return v;
    if (Date.now() - t0 > ms) throw new Error('timeout waiting for ' + what);
    await sleep(500);
  }
}

let passed = 0, failed = 0;
function check(name, ok, detail = '') {
  if (ok) { passed++; console.log('  ok   ' + name); }
  else { failed++; console.log('  FAIL ' + name + (detail ? '  — ' + detail : '')); }
}

// ---- devproxy (akademi + account to Laravel, everything else legacy PHP) -----
const proxy = spawn(process.execPath, [path.join(API_ROOT, 'tools/devproxy/serve.mjs'),
  '--port', String(PORT), '--laravel', 'account,akademi'],
  { stdio: 'ignore', env: { ...process.env, OFFICE_DIR: OFFICE } });
const stop = () => { try { proxy.kill(); } catch {} };
process.on('exit', stop);
await until(async () => (await fetch(`${BASE}/akademi-api-mysql/api.php?action=ping`)).ok, 'devproxy', 30000);
const ping = await (await fetch(`${BASE}/akademi-api-mysql/api.php?action=ping`)).json();
check('akademi API served by Laravel', ping.ok && ping.data && ping.data.backend === 'laravel', JSON.stringify(ping).slice(0, 200));
const mktHdr = (await fetch(`${BASE}/marketing-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
check('other modules stay on legacy PHP (marketing)', mktHdr === 'legacy-php', mktHdr);

// ---- Office login (Laravel legacy account) -> lm_session like the portal writes it --
const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
  method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
})).json();
check('Office login via Laravel', login.ok && login.user && login.user.token, JSON.stringify(login).slice(0, 200));
const u = login.user;
const lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
  modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

// ---- one browser tab ------------------------------------------------------------
const html = await (await fetch(`${BASE}/akademi/`)).text();
const pageErrors = [];
async function openTab(label) {
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(e.message)) pageErrors.push(`[${label}] ${e.message}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}/akademi/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
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
  await until(() => {
    try {
      const main = w.document.getElementById('main');
      return main && main.textContent.includes('Halo');
    } catch { return false; }
  }, `${label} boot`, 120000);
  return w;
}

console.log('\n1. SSO boot + dashboard');
const A = await openTab('tabA');
check('app opened as the Office user', A.eval('currentUser() && currentUser().id') === u.id, A.eval('currentUser() && currentUser().id'));
check('state loaded from Laravel', A.eval('DB.users.length') > 0 && A.eval('DB.materials.length') > 0,
  `users=${A.eval('DB.users.length')} materials=${A.eval('DB.materials.length')}`);
check('module admin recognised', A.eval('typeof isAdmin==="function" && isAdmin(currentUser())') === true);

console.log('\n2. division through the page modal');
const DIVNAME = 'E2E ' + TAG;
await A.eval(`(async()=>{ editDivisi(null); document.getElementById('dName').value=${JSON.stringify(DIVNAME)}; saveDivisi(''); })()`);
const divId = await until(() => sql(`SELECT id FROM divisions WHERE data LIKE '%${TAG}%'`) || false, 'division in DB');
check('division saved to DB', !!divId, divId);

console.log('\n3. material progress through the page');
// A synthetic material id: progress rows do not FK to materials, and this
// guarantees no pre-existing row that cleanup could destroy.
const MAT = 'm_e2e_' + TAG;
await A.eval(`(async()=>{ setProg(${JSON.stringify(u.id)}, ${JSON.stringify(MAT)}, {status:'done', completedAt:Date.now()}); })()`);
await until(() => sql(`SELECT data FROM progress WHERE user_id='${u.id}' AND material_id='${MAT}'`).includes('done'), 'progress in DB');
check('progress row in DB', true);

console.log('\n4. monthly program through the page modal');
await A.eval(`(async()=>{
  editProgram(null);
  document.getElementById('pBulan').value='2033-06';
  document.getElementById('pTitle').value=${JSON.stringify('E2E Prog ' + TAG)};
  document.getElementById('pDeadline').value='2033-06-30';
  document.getElementById('pNote').value='e2e';
  document.querySelector('.pMat').checked=true;
  saveProgram('');
})()`);
const progId = await until(() => sql(`SELECT id FROM programs WHERE data LIKE '%${TAG}%'`) || false, 'program in DB');
check('program saved to DB', !!progId, progId);

console.log('\n5. activity through the page');
await A.eval(`(async()=>{ logAct('uji_e2e', ${JSON.stringify('walkthrough ' + TAG)}); })()`);
await until(() => sql(`SELECT data FROM activity WHERE data LIKE '%${TAG}%'`), 'activity in DB');
check('activity row in DB', true);

console.log('\n6. reload in a fresh tab');
const B = await openTab('tabB');
check('division survives reload', B.eval(`DB.divisions.some(function(d){return d.name===${JSON.stringify(DIVNAME)}})`));
check('progress survives reload', B.eval(`!!(DB.progress[${JSON.stringify(u.id)}]||{})[${JSON.stringify(MAT)}]`));
check('program survives reload', B.eval(`DB.programs.some(function(p){return p.id===${JSON.stringify(progId)}})`));
A.close();

console.log('\n7. walk the screens');
for (const v of ['dashboard', 'materi', 'program', 'perpus', 'struktur', 'sertifikat', 'tim',
  'anjungan', 'kelolaMateri', 'kelolaProgram', 'kelolaKru', 'log', 'sync']) {
  B.eval(`go(${JSON.stringify(v)})`);
  await sleep(300);
  const t = B.document.getElementById('main').textContent.replace(/\s+/g, ' ').slice(0, 120);
  check(`screen ${v} renders`, !/undefined/.test(t) && t.length > 40, t.slice(0, 100));
}

console.log('\n8. clean up through the page');
await B.eval(`(async()=>{
  delDivisi(${JSON.stringify(divId)});
  delProgram(${JSON.stringify(progId)});
  delete DB.progress[${JSON.stringify(u.id)}][${JSON.stringify(MAT)}];
  saveDB();
})()`);
await until(() => sql(`SELECT COUNT(*) FROM divisions WHERE id='${divId}'`) === '0'
  && sql(`SELECT COUNT(*) FROM programs WHERE id='${progId}'`) === '0'
  && sql(`SELECT COUNT(*) FROM progress WHERE user_id='${u.id}' AND material_id='${MAT}'`) === '0', 'fixtures gone');
check('division gone from DB', sql(`SELECT COUNT(*) FROM divisions WHERE id='${divId}'`) === '0');
check('program gone from DB', sql(`SELECT COUNT(*) FROM programs WHERE id='${progId}'`) === '0');
check('progress gone from DB', sql(`SELECT COUNT(*) FROM progress WHERE user_id='${u.id}' AND material_id='${MAT}'`) === '0');
// activity is append-only by design; the fixture row stays as the trail intends
check('activity fixture present (append-only)', sql(`SELECT COUNT(*) FROM activity WHERE data LIKE '%${TAG}%'`) !== '0');
B.close();

const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
console.log(`\n${passed}/${passed + failed} checks passed`);
stop();
process.exit(failed ? 1 : 0);
