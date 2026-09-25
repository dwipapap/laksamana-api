#!/usr/bin/env node
/*
 * E2E — the REAL old Event Planner frontend (laksamana-office/deploy/event/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/event.mjs [--port 8190] [--user u-steven]
 *
 * Only /event-api-mysql + /account-api-mysql go to Laravel. The page saves the
 * WHOLE state (saveAll) after every edit, so the walkthrough also proves that
 * what the page holds is what the database stores, byte for byte.
 *
 *   1. SSO boot (lm_session with event) -> app opens with the DB state
 *   2. every menu page renders (TITLES)
 *   3. two new ideas via ideaForm/saveIdea -> rows in DB
 *   4. new event via eventForm/saveEvent -> row with WIB start, createdBy = session user,
 *      its eventDetails row; eventsHari (Finance's read) is not showing it while Draft
 *   5. the event goes Upcoming -> eventsHari returns it with inputOleh = the session user
 *   6. upload a KTP image (uploadBerkas) -> ev_<hex>.png on disk, served back by fileUrl
 *   7. check-in a ticket (checkinTiket) -> append-only checkins row
 *   8. every row the page holds equals the stored row (round-trip)
 *   9. reload in a fresh tab -> edits are there
 *  10. hapusIde on the OLDER idea -> row gone (bounded delete)
 * Finally the database is restored from a snapshot taken at the start.
 *
 * LOCAL ONLY. Laravel runs with DB_EMS_SQL_MODE=NO_ENGINE_SUBSTITUTION to mirror
 * production's non-strict server, and EVENT_DATA_DIR in a temp folder.
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
const PORT = parseInt(arg('--port', '8190'), 10);
const USER = arg('--user', 'u-steven');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_ems';
const TAG = 'e2e' + Date.now().toString(36);
const DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'e2e-event-data-'));

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
const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const TABLES = ['talents', 'events', 'schedules', 'recurring_rules', 'talent_payments', 'ticket_classes', 'seats',
  'orders', 'tickets', 'ideas', 'refunds', 'calendar_extra', 'checkins', 'event_details', 'settings', 'seat_holds'];
const dumpAll = () => TABLES.map((t) => sql(`SELECT * FROM \`${t}\` ORDER BY 1`)).join('\n#\n');
const before = dumpAll();

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,event'],
  { stdio: 'ignore', env: { ...process.env, DB_EMS_SQL_MODE: 'NO_ENGINE_SUBSTITUTION', EVENT_DATA_DIR: DATA_DIR } });
const tabs = [];
let restored = false;
const restore = () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {}   // stop the page timers BEFORE restoring
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', DB], { input: snapshot, maxBuffer: 1 << 30 });
  try { fs.rmSync(DATA_DIR, { recursive: true, force: true }); } catch {}
};
process.on('exit', restore);

try {
  await until(async () => (await fetch(`${BASE}/event-api-mysql/api.php?action=ping`)).ok, 'devproxy');
  const hdr = (await fetch(`${BASE}/event-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('event API served by Laravel', hdr === 'laravel', String(hdr));
  const bdHdr = (await fetch(`${BASE}/bd-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('other modules stay on legacy PHP (bd)', bdHdr === 'legacy-php', String(bdHdr));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('Office login via Laravel, user holds event', login.ok && (u.modules || []).some((m) => m === 'event' || m === '*'), JSON.stringify(u.modules));
  const lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  // The page needs ../assets/performa-bonus.js before its own script; inline it
  // (jsdom does not load external resources here).
  const pb = await (await fetch(`${BASE}/assets/performa-bonus.js`)).text();
  const html = (await (await fetch(`${BASE}/event/`)).text())
    .replace('<script src="../assets/performa-bonus.js"></script>', () => `<script>${pb}</script>`);
  const pageErrors = [];
  async function openTab(label) {
    const vc = new VirtualConsole();
    vc.on('jsdomError', (e) => { if (!/Not implemented|Could not parse CSS/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
    vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
    const dom = new JSDOM(html, {
      url: `${BASE}/event/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
      beforeParse(w) {
        w.localStorage.setItem('lm_session', lmSession);
        w.fetch = (input, init = {}) => {
          const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
          return fetch(new URL(String(input), w.location.href), rest);
        };
        w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
        w.scrollTo = () => {};
        w.HTMLElement.prototype.scrollIntoView = () => {};
        w.HTMLCanvasElement.prototype.getContext = () => null;
        for (const k of ['IntersectionObserver', 'ResizeObserver'])
          if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
      },
    });
    const w = dom.window;
    const tab = { w, close: () => { try { w.close(); } catch {} } };
    tabs.push(tab);
    await until(() => w.eval('DB!==null && document.getElementById("pageTitle").textContent.length>0 && location.hash.length>2'), `${label} boot`);
    return tab;
  }
  const idle = (w) => until(() => w.eval('!_saving && !_dirty'), 'save idle');

  console.log('\n1. SSO boot');
  const A = await openTab('tabA');
  check('state loaded from DB (talents, seats)', A.w.eval('DB.talents.length') === Number(sql('SELECT COUNT(*) FROM talents'))
    && A.w.eval('DB.seats.length') === Number(sql('SELECT COUNT(*) FROM seats')));
  check('dashboard renders', A.w.document.getElementById('content').textContent.length > 100);
  await idle(A.w);

  console.log('\n2. every menu page renders');
  const pages = A.w.eval('Object.keys(TITLES)');
  const broken = [];
  for (const p of pages) {
    A.w.location.hash = '#/' + p;
    A.w.eval('router()');
    const txt = A.w.document.getElementById('content').textContent;
    if (/gagal digambar/.test(txt)) broken.push(p);
  }
  check(`${pages.length} pages render without "gagal digambar"`, broken.length === 0, broken.join(','));
  await idle(A.w);

  console.log('\n3. new ideas');
  A.w.location.hash = '#/ideas'; A.w.eval('router()');
  for (const n of ['1', '2']) {
    A.w.eval('ideaForm()');
    A.w.document.getElementById('i_name').value = `E2E idea ${n} ${TAG}`;
    A.w.eval('saveIdea()');
    await until(() => sql(`SELECT id FROM ideas WHERE name='E2E idea ${n} ${TAG}'`), `idea ${n} in DB`);
    await idle(A.w);
    await sleep(20); // distinct updatedAt stamps
  }
  const idea1 = sql(`SELECT id FROM ideas WHERE name='E2E idea 1 ${TAG}'`);
  check('two idea rows in DB', !!idea1 && !!sql(`SELECT id FROM ideas WHERE name='E2E idea 2 ${TAG}'`));

  console.log('\n4. new event');
  A.w.eval('eventForm()');
  const d = A.w.document;
  d.getElementById('e_title').value = 'E2E Night ' + TAG;
  d.getElementById('e_venue').value = 'Laksamana Muda Pekanbaru';
  d.getElementById('e_start').value = '2031-07-01T19:30';
  d.getElementById('e_end').value = '2031-07-01T23:00';
  A.w.eval("saveEvent('')");
  const evRow = await until(() => sql(`SELECT CONCAT(id,'|',status,'|',start_datetime) FROM events WHERE title='E2E Night ${TAG}'`), 'event in DB');
  const [evId, evStatus, evStart] = evRow.split('|');
  const tz = A.w.eval("new Date('2031-07-01T19:30').toISOString()");
  const expStart = new Date(new Date(tz).getTime() + 7 * 3600e3).toISOString().slice(0, 19).replace('T', ' ');
  check('event row stored, start_datetime in WIB', evStart === expStart, `${evStart} vs ${expStart}`);
  const evData = JSON.parse(sql(`SELECT data FROM events WHERE id='${evId}'`));
  check('createdBy / createdById = the session user', evData.createdBy === u.name && evData.createdById === u.id, `${evData.createdBy}/${evData.createdById}`);
  await until(() => sql(`SELECT COUNT(*) FROM event_details WHERE event_id='${evId}'`) === '1', 'event detail row');
  check('eventDetails row for the new event', true);
  await idle(A.w);
  const hari0 = await (await fetch(`${BASE}/event-api-mysql/api.php?action=eventsHari&tgl=2031-07-01`)).json();
  check(`eventsHari hides it while ${evStatus}`, hari0.ok && !hari0.data.events.some((e) => e.id === evId));

  console.log('\n5. event goes Upcoming -> Finance sees it');
  A.w.eval(`(function(){const e=DB.events.find(x=>x.id===${JSON.stringify(evId)}); e.status='Upcoming'; save();})()`);
  await until(() => sql(`SELECT status FROM events WHERE id='${evId}'`) === 'Upcoming', 'status Upcoming');
  const hari = await (await fetch(`${BASE}/event-api-mysql/api.php?action=eventsHari&tgl=2031-07-01`)).json();
  const hit = (hari.data?.events || []).find((e) => e.id === evId);
  check('eventsHari returns it with inputOleh = session user', hit && hit.inputOleh === u.name && hit.inputOlehId === u.id && hit.mulai === expStart, JSON.stringify(hit));
  await idle(A.w);

  console.log('\n6. upload a KTP image');
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  A.w.__png = new A.w.File([new A.w.Uint8Array(png)], 'ktp.png', { type: 'image/png' });
  const up = await A.w.eval('uploadBerkas(window.__png)');
  check('upload -> {key: ev_<hex>.png, name, size}', /^ev_[0-9a-f]{16}\.png$/.test(up.key) && up.name === 'ktp.png' && up.size === png.length, JSON.stringify(up));
  check('file written to EVENT_DATA_DIR/files', fs.existsSync(path.join(DATA_DIR, 'files', up.key)));
  const served = Buffer.from(await (await fetch(new URL(A.w.eval(`fileUrl(${JSON.stringify(up.key)})`), `${BASE}/event/`))).arrayBuffer());
  check('fileUrl serves the same bytes', served.equals(png));
  A.w.eval(`(function(){const t=DB.talents[0]; t.docs=Object.assign({},t.docs||{}, {ktp:${JSON.stringify(up)}}); save();})()`);
  const tal0 = A.w.eval('DB.talents[0].id');
  await until(() => sql(`SELECT data FROM talents WHERE id='${tal0}'`).includes(up.key), 'talent points at the file');
  check('talent document pointer saved', true);
  await idle(A.w);

  console.log('\n7. check-in');
  const tk = A.w.eval('(DB.tickets.find(t=>t.status!=="Checked-In")||{}).id');
  if (tk) {
    const ci = A.w.eval(`checkinTiket(DB.tickets.find(t=>t.id===${JSON.stringify(tk)})).id`);
    await until(() => sql(`SELECT COUNT(*) FROM checkins WHERE id='${ci}'`) === '1', 'checkin row');
    check('checkin row appended, ticket Checked-In', sql(`SELECT status FROM tickets WHERE id='${tk}'`) === 'Checked-In');
    await idle(A.w);
  } else check('a ticket to check in exists', false);

  console.log('\n8. round-trip: what the page holds is what is stored');
  const cols = A.w.eval('JSON.stringify(COLS)');
  const mism = [];
  for (const [k, t] of Object.entries({ talents: 'talents', events: 'events', schedules: 'schedules', recurringRules: 'recurring_rules',
    talentPayments: 'talent_payments', ticketClasses: 'ticket_classes', seats: 'seats', orders: 'orders', tickets: 'tickets',
    ideas: 'ideas', refunds: 'refunds', calendarExtra: 'calendar_extra' })) {
    const page = JSON.parse(A.w.eval(`JSON.stringify(DB.${k})`));
    // HEX: mysql -B escapes backslashes/newlines inside the JSON
    const stored = sql(`SELECT HEX(data) FROM \`${t}\``).split('\n').filter(Boolean)
      .map((h) => JSON.parse(Buffer.from(h, 'hex').toString('utf8')));
    const byId = Object.fromEntries(stored.map((r) => [r.id, r]));
    for (const r of page) if (JSON.stringify(byId[r.id]) !== JSON.stringify(r)) mism.push(`${k}:${r.id}`);
    if (page.length !== stored.length) mism.push(`${k}: page ${page.length} vs db ${stored.length}`);
  }
  check(`every row of ${JSON.parse(cols).length} collections equals the stored row`, mism.length === 0, mism.slice(0, 5).join(' '));
  const after = dumpAll();
  check('ticketing\'s seat_holds untouched', after.split('\n#\n').at(-1) === before.split('\n#\n').at(-1));

  console.log('\n9. reload');
  const B = await openTab('tabB');
  check('event survives reload', B.w.eval(`DB.events.some(e=>e.id===${JSON.stringify(evId)} && e.status==='Upcoming')`));
  check('ideas survive reload', B.w.eval(`DB.ideas.filter(i=>i.name.endsWith(${JSON.stringify(TAG)})).length`) === 2);
  A.close();

  console.log('\n10. delete the older idea');
  B.w.location.hash = '#/ideas'; B.w.eval('router()');
  B.w.eval(`hapusIde(${JSON.stringify(idea1)})`);
  B.w.eval('jalankanHapus()');
  await until(() => sql(`SELECT COUNT(*) FROM ideas WHERE id='${idea1}'`) === '0', 'idea deleted');
  check('idea row gone', true);
  await idle(B.w);
  B.close();

  const relevant = pageErrors.filter((e) => !/canvas|getContext|fonts\.googleapis|Could not load (link|img|script)/i.test(e));
  check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
}

for (const t of tabs) t.close();
try { proxy.kill(); } catch {}
await sleep(1500); // let in-flight saves land before the snapshot goes back
restore();
const restoredDump = dumpAll();
check('database restored from snapshot', restoredDump === before,
  (() => {
    const a = before.split('\n'), b = restoredDump.split('\n');
    const i = b.findIndex((l, j) => l !== a[j]);
    if (i < 0) return `line count ${a.length} vs ${b.length}`;
    const k = [...b[i]].findIndex((c, j) => c !== a[i][j]);
    return `line ${i}: before …${a[i].slice(Math.max(0, k - 60), k + 80)} | after …${b[i].slice(Math.max(0, k - 60), k + 80)}`;
  })());
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
