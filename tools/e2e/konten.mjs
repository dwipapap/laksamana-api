#!/usr/bin/env node
/*
 * E2E — the REAL old konten frontend (laksamana-office/deploy/konten/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/konten.mjs [--port 8182] [--user u-andry]
 *
 * Proves the cutover: only /konten-api-mysql + /account-api-mysql go to Laravel,
 * every other backend the page touches stays the old PHP.
 *
 * Walkthrough (every step through the page's own code, except the receipt
 * upload which the old page never calls directly — images ride inside rows
 * as data URIs — so it goes through the same proxied compat URL instead):
 *   1. SSO boot with a Laravel-issued lm_session -> app opens, brands render
 *   2. edit a brand via COMS.openBrandForm/saveBrand -> no conflict, DB has it
 *   3. move a content card one pipeline step (COMS.moveStatus) -> DB has it
 *   4. create a brand via COMS -> row in DB
 *   5. uploadReceipt + receipt stream -> same bytes back; still served after
 *      several page saves (the 1-hour orphan grace, not instant GC)
 *   6. reload in a fresh tab -> everything is still there
 *   7. two tabs edit the same brand -> stale tab gets the conflict toast,
 *      the other tab's edit is not overwritten
 *   8. clean up through the page (delBrand, restore desc, moveStatus back)
 *
 * LOCAL ONLY: writes to the local restored lakk5493_db_konten (rows/names
 * prefixed E2E, restored at the end). Needs PHP for the legacy side of the
 * proxy: PHP_BIN=C:\Users\dwip\.config\herd-lite\bin\php.exe
 */
import { spawn, execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_ROOT = path.resolve(HERE, '../..');
const MYSQL = process.env.MYSQL_BIN || 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysql.exe';
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8182'), 10);
const USER = arg('--user', 'u-andry');
const BASE = `http://127.0.0.1:${PORT}`;
const TAG = 'e2e' + Date.now().toString(36);

const sql = (q, db = 'lakk5493_db_konten') =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 60000) {
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

// ---- devproxy (konten + account -> Laravel, everything else -> legacy PHP) --
const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,konten'],
  { stdio: 'ignore' });
const stop = () => { try { proxy.kill(); } catch {} };
process.on('exit', stop);
await until(async () => (await fetch(`${BASE}/konten-api-mysql/api.php?action=ping`)).ok, 'devproxy', 30000);
const ping = await (await fetch(`${BASE}/konten-api-mysql/api.php?action=ping`)).json();
check('konten API served by Laravel', ping.ok && ping.data && ping.data.backend === 'laravel', JSON.stringify(ping).slice(0, 200));
const bdHdr = (await fetch(`${BASE}/bd-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
check('other modules stay on legacy PHP (bd)', bdHdr === 'legacy-php', String(bdHdr));

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
const html = await (await fetch(`${BASE}/konten/`)).text();
const pageErrors = [];
async function openTab(label) {
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}/konten/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      w.localStorage.setItem('lm_session', lmSession);
      w.fetch = (input, init = {}) => {
        const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
        return fetch(new URL(String(input), w.location.href), rest);
      };
      w.confirm = () => true;                      // deletions in cleanup
      w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
      w.scrollTo = () => {};
      w.HTMLElement.prototype.scrollIntoView = () => {};
      for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
        if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
    },
  });
  const w = dom.window;
  await until(() => w.eval('typeof COMS!=="undefined" && document.querySelectorAll("#sbNav .nav-item").length > 5'), `${label} boot`);
  return { w, close: () => dom.window.close() };
}
const brandRow = (id) => { const r = sql(`SELECT data FROM brands WHERE id='${id}'`); return r ? JSON.parse(r) : null; };
const contentStatus = (id) => { const r = sql(`SELECT data FROM content WHERE id='${id}'`); return r ? JSON.parse(r).status : null; };
const setBrandDesc = (w, id, desc) => {
  w.eval(`COMS.openBrandForm(${JSON.stringify(id)})`);
  w.document.getElementById('br_desc').value = desc;
  w.eval(`COMS.saveBrand(${JSON.stringify(id)})`);
};

console.log('\n1. SSO boot + brands');
const A = await openTab('tabA');
check('app opened (sidebar renders)', A.w.eval('document.querySelector("#sbUser").textContent').includes(u.name), A.w.eval('document.querySelector("#sbUser").textContent').slice(0, 80));
A.w.eval("COMS.route('brands')");
const firstBrand = A.w.eval('document.getElementById("view").textContent');
check('brand list renders', firstBrand.length > 100, firstBrand.replace(/\s+/g, ' ').slice(0, 120));

console.log('\n2. edit a brand');
const bid = sql('SELECT id FROM brands ORDER BY id LIMIT 1');
const origDesc = (brandRow(bid) || {}).desc || '';
setBrandDesc(A.w, bid, 'E2E ' + TAG);
await until(() => (brandRow(bid) || {}).desc === 'E2E ' + TAG, 'brand edit in DB');
check('brand edit in DB', true);
check('no conflict toast', !A.w.document.getElementById('toastWrap').textContent.includes('tidak tersimpan'));

console.log('\n3. move a content card one pipeline step');
const cid = sql(`SELECT id FROM content WHERE data LIKE '%"status":"Idea"%' ORDER BY id LIMIT 1`);
check('an Idea content exists to move', !!cid, String(cid));
if (cid) {
  A.w.eval(`COMS.moveStatus(${JSON.stringify(cid)}, 1)`);
  await until(() => contentStatus(cid) === 'Research', 'content moved in DB');
  check('content Idea -> Research in DB', true);
}

console.log('\n4. create a brand');
const newName = 'E2E Brand ' + TAG;
A.w.eval('COMS.openBrandForm()');
A.w.document.getElementById('br_name').value = newName;
A.w.eval("COMS.saveBrand('')");
const newId = await until(() => sql(`SELECT id FROM brands WHERE data LIKE '%${newName}%' LIMIT 1`), 'new brand in DB');
check('brand created in DB', !!newId, String(newId));

console.log('\n5. receipt upload + stream + survives page saves');
const bytes = Buffer.from('e2e-konten-' + TAG);
const up = await (await fetch(`${BASE}/konten-api-mysql/api.php`, {
  method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' },
  body: JSON.stringify({ action: 'uploadReceipt', dataBase64: bytes.toString('base64'), mimeType: 'image/png', fileName: 'e2e.png' }),
})).json();
check('uploadReceipt returns key', up.ok && /^rc_[0-9a-f]+\.png$/.test(up.data.key), JSON.stringify(up).slice(0, 160));
const back = Buffer.from(await (await fetch(`${BASE}/konten-api-mysql/api.php?action=receipt&key=${up.data.key}`)).arrayBuffer());
check('receipt streams the same bytes', back.equals(bytes), `${back.length} vs ${bytes.length}`);

console.log('\n6. reload');
const B = await openTab('tabB');
B.w.eval("COMS.route('brands')");
check('brand edit survives reload', B.w.eval('document.getElementById("view").textContent').includes('E2E ' + TAG));
check('new brand survives reload', B.w.eval('document.getElementById("view").textContent').includes(newName));

console.log('\n7. two tabs, same brand -> conflict guard');
// B loaded after A's saves, so both hold the same server version now.
setBrandDesc(B.w, bid, 'from B ' + TAG);
await until(() => (brandRow(bid) || {}).desc === 'from B ' + TAG, 'tab B save in DB');
check('tab B saves cleanly', !B.w.document.getElementById('toastWrap').textContent.includes('tidak tersimpan'));
setBrandDesc(A.w, bid, 'from A ' + TAG);
await sleep(2500); // let A's stale save finish; its toast lives ~2.6s
check("B's edit is not overwritten", (brandRow(bid) || {}).desc === 'from B ' + TAG, (brandRow(bid) || {}).desc);
check('stale tab A gets the conflict toast', A.w.document.getElementById('toastWrap').textContent.includes('tidak tersimpan'),
  A.w.document.getElementById('toastWrap').textContent.replace(/\s+/g, ' ').slice(0, 160));
A.close();

console.log('\n8. clean up through the page');
setBrandDesc(B.w, bid, origDesc);
await until(() => (brandRow(bid) || {}).desc === origDesc, 'brand desc restored');
check('brand desc restored', true);
if (newId) {
  B.w.eval(`COMS.delBrand(${JSON.stringify(newId)})`);
  await until(() => brandRow(newId) === null, 'e2e brand deleted');
  check('e2e brand deleted from DB', true);
}
if (cid) {
  B.w.eval(`COMS.moveStatus(${JSON.stringify(cid)}, -1)`);
  await until(() => contentStatus(cid) === 'Idea', 'content moved back');
  check('content Research -> Idea in DB', true);
}
check('uploaded file survives all the page saves (orphan grace)',
  (await (await fetch(`${BASE}/konten-api-mysql/api.php?action=receipt&key=${up.data.key}`)).arrayBuffer()).byteLength === bytes.length);
B.close();
// belt and suspenders: nothing E2E-named may remain
sql(`DELETE FROM brands WHERE data LIKE '%E2E Brand%'`);
sql(`DELETE FROM brands WHERE data LIKE '%${TAG}%'`);
check('no E2E rows left', !sql(`SELECT id FROM brands WHERE data LIKE '%${TAG}%' LIMIT 1`));

const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
console.log(`\n${passed}/${passed + failed} checks passed`);
stop();
process.exit(failed ? 1 : 0);
