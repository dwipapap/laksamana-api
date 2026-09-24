#!/usr/bin/env node
/*
 * E2E — the REAL old marketing frontend (laksamana-office/deploy/marketing/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/marketing.mjs [--port 8180] [--user u-aurel]
 *
 * Proves the cutover: only /marketing-api-mysql + /account-api-mysql go to Laravel,
 * every other backend the page touches stays the old PHP.
 *
 * Walkthrough (every step through the page's own code: S, save(), unggahBertahap()):
 *   1. SSO boot with a Laravel-issued lm_session  -> app opens, CRM list renders
 *   2. edit a client, save()                      -> no conflict, row in DB has the edit
 *   3. create an event, save()                    -> row in DB
 *   4. chunked upload + receipt stream            -> same bytes back
 *   5. add a Reservasi VIP row, save()            -> settings extra:vip has it
 *   6. reload in a fresh tab                      -> everything is still there
 *   7. two tabs edit the same client              -> second tab gets the conflict modal
 *   8. clean up (delete the event + VIP row via the page, restore the client) -> gone from DB
 *
 * LOCAL ONLY: writes to the local restored lakk5493_db_marketing (rows prefixed e2e).
 */
import { spawn, execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_ROOT = path.resolve(HERE, '../..');
const MYSQL = process.env.MYSQL_BIN || 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8180'), 10);
const USER = arg('--user', 'u-aurel');
const BASE = `http://127.0.0.1:${PORT}`;
const TAG = 'e2e' + Date.now().toString(36);

const sql = (q, db = 'lakk5493_db_marketing') =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 60000) {
  const t0 = Date.now();
  for (;;) {
    let v; try { v = await fn(); } catch { v = false; }
    if (v) return v;
    if (Date.now() - t0 > ms) throw new Error('timeout waiting for ' + what);
    await sleep(150);
  }
}

let passed = 0, failed = 0;
function check(name, ok, detail = '') {
  if (ok) { passed++; console.log('  ok   ' + name); }
  else { failed++; console.log('  FAIL ' + name + (detail ? '  — ' + detail : '')); }
}

// ---- devproxy ----------------------------------------------------------------
const proxy = spawn(process.execPath, [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT)], { stdio: 'ignore' });
const stop = () => { try { proxy.kill(); } catch {} };
process.on('exit', stop);
await until(async () => (await fetch(`${BASE}/marketing-api-mysql/api.php?action=ping`)).ok, 'devproxy', 30000);
const ping = await (await fetch(`${BASE}/marketing-api-mysql/api.php?action=ping`)).json();
check('marketing API served by Laravel', ping.ok && ping.data && ping.data.backend === 'laravel', JSON.stringify(ping).slice(0, 200));
const bdHdr = (await fetch(`${BASE}/bd-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
check('other modules stay on legacy PHP (bd)', bdHdr === 'legacy-php', bdHdr);

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
const html = await (await fetch(`${BASE}/marketing/`)).text();
const pageErrors = [];
async function openTab(label) {
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(e.message)) pageErrors.push(`[${label}] ${e.message}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}/marketing/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
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
  await until(() => w.eval('typeof ME!=="undefined" && ME && S && API_READY'), `${label} boot`);
  return w;
}
const idle = (w) => until(() => w.eval('!saveInFlight && !savePending') && (w.eval('_conflictShown') || !w.eval('adaBelumNaik()')), 'save to settle');
const row = (table, id) => { const r = sql(`SELECT data FROM ${table} WHERE id='${id}'`); return r ? JSON.parse(r) : null; };

console.log('\n1. SSO boot + CRM');
const A = await openTab('tabA');
check('app opened as the Office user', A.eval('ME.id') === u.id, A.eval('ME && ME.id'));
check('state loaded from Laravel', A.eval('S.clients.length') > 0);
A.eval("go('crm')");
const viewText = A.document.getElementById('view').textContent;
const shown = A.eval('S.clients.map(c=>c.nama)').filter((n) => n && viewText.includes(n)).length;
check('CRM list renders clients', shown > 0, `userNav=${A.eval('userNav(ME).join()')} view="${viewText.replace(/\s+/g, ' ').slice(0, 200)}"`);

console.log('\n2. edit a client');
const cid = A.eval('S.clients[0].id');
const origNote = A.eval('S.clients[0].catatan');
A.eval(`S.clients[0].catatan = ${JSON.stringify('E2E ' + TAG)}; save();`);
await idle(A);
check('no conflict modal', !A.eval('_conflictShown'));
check('client edit in DB', (row('clients', cid) || {}).catatan === 'E2E ' + TAG);

console.log('\n3. create an event');
const evId = 'ev_' + TAG;
A.eval(`S.events.push({id:${JSON.stringify(evId)}, nama:'E2E Event ${TAG}', clientId:${JSON.stringify(cid)}, tanggal:'2026-12-01',
  status:'Lead', pax:10, mktPIC:${JSON.stringify(u.id)}, createdAt:Date.now()}); save();`);
await idle(A);
check('event in DB', (row('events', evId) || {}).nama === 'E2E Event ' + TAG);
check('event tanggal column indexed', sql(`SELECT tanggal FROM events WHERE id='${evId}'`) === '2026-12-01');

console.log('\n4. upload (chunked) + receipt stream');
const bytes = Buffer.alloc(2 * 1024 * 1024 + 4321); for (let i = 0; i < bytes.length; i++) bytes[i] = (i * 31) & 255;
A.__up = bytes;
const up = await A.eval(`(async()=>{ const f=new File([new Uint8Array(window.__up)], 'e2e.png', {type:'image/png'});
  const pct=[]; const r=await unggahBertahap(f, p=>pct.push(p)); return JSON.stringify({r, pct}); })()`);
const { r: upRes, pct } = JSON.parse(up);
check('chunked upload returns key (2 chunks)', /^rc_[0-9a-f]+\.png$/.test(upRes.key) && pct.join() === '50,100', up);
const back = Buffer.from(await (await fetch(`${BASE}/marketing-api-mysql/api.php?action=receipt&key=${upRes.key}`)).arrayBuffer());
check('receipt streams the same bytes', back.equals(bytes), `${back.length} vs ${bytes.length}`);
A.eval(`(S.events.find(e=>e.id===${JSON.stringify(evId)}).detail={attachFiles:[${JSON.stringify(upRes)}]}); save();`);
await idle(A);
check('file key referenced from the event row', JSON.stringify(row('events', evId)).includes(upRes.key));

console.log('\n5. Reservasi VIP row');
const vipId = 'vip_' + TAG;
A.eval(`S.vip=S.vip||[]; S.vip.push({id:${JSON.stringify(vipId)}, nama:'E2E VIP ${TAG}', tanggal:'2026-12-02', pax:4, status:'Booked'}); save();`);
await idle(A);
const vipDb = JSON.parse(sql(`SELECT v FROM settings WHERE k='extra:vip'`) || '[]');
check('VIP row merged into settings extra:vip', vipDb.some((r) => r.id === vipId));
check('existing VIP rows kept', vipDb.length === A.eval('S.vip.length'), `${vipDb.length} vs ${A.eval('S.vip.length')}`);

console.log('\n6. reload');
const B = await openTab('tabB');
check('client edit survives reload', B.eval(`S.clients.find(c=>c.id===${JSON.stringify(cid)}).catatan`) === 'E2E ' + TAG);
check('event survives reload', B.eval(`!!S.events.find(e=>e.id===${JSON.stringify(evId)})`));
check('VIP row survives reload', B.eval(`!!S.vip.find(v=>v.id===${JSON.stringify(vipId)})`));

console.log('\n7. two tabs, same client -> conflict guard');
// B loaded after A's saves, so both hold the same server version now.
B.eval(`S.clients.find(c=>c.id===${JSON.stringify(cid)}).catatan='from B ${TAG}'; save();`);
await idle(B);
check('tab B saves cleanly', !B.eval('_conflictShown') && (row('clients', cid) || {}).catatan === 'from B ' + TAG);
A.eval(`S.clients.find(c=>c.id===${JSON.stringify(cid)}).catatan='from A ${TAG}'; save();`);
await idle(A);
check('stale tab A gets the conflict modal', A.eval('_conflictShown') === true);
check("B's edit is not overwritten", (row('clients', cid) || {}).catatan === 'from B ' + TAG);
A.close();

console.log('\n8. clean up through the page (delete via _sejak)');
B.eval(`S.events=S.events.filter(e=>e.id!==${JSON.stringify(evId)}); S.vip=S.vip.filter(v=>v.id!==${JSON.stringify(vipId)});
  (S.clients.find(c=>c.id===${JSON.stringify(cid)}).catatan=${JSON.stringify(origNote ?? '')}); save();`);
await idle(B);
check('event deleted from DB', row('events', evId) === null);
check('VIP row removed', !JSON.parse(sql(`SELECT v FROM settings WHERE k='extra:vip'`) || '[]').some((r) => r.id === vipId));
check('client note restored', ((row('clients', cid) || {}).catatan ?? '') === (origNote ?? ''));
B.close();

const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
console.log(`\n${passed}/${passed + failed} checks passed`);
stop();
process.exit(failed ? 1 : 0);
