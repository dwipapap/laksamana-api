#!/usr/bin/env node
/*
 * E2E smoke: the REAL public ticket shop (deploy/ticketing) running headless (jsdom)
 * against Laravel, through devproxy.
 *
 *   node tools/e2e/ticketing.mjs [--port 8197]
 *
 * /ticketing-api goes to Laravel (payments in XENDIT_MOCK mode, no SMTP).
 * The walkthrough drives the page's own screens and api():
 *   1. boot: the event list renders from `events`
 *   2. register a Buyer (daftar), then the session survives a reload (saya)
 *   3. event page + seat map render; hold a seat, check out one seat + one unseated ticket
 *   4. the simulated payment page pays through the same path as the webhook
 *   5. the e-ticket page shows every ticket with a drawn QR; Tiket Saya lists the order
 *   6. logout (keluar); forgot/reset password answer as the old backend did
 * Finally lakk5493_db_ems is restored from a snapshot.
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
const PORT = parseInt(arg('--port', '8197'), 10);
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_ems';
const TAG = 'e2e' + Date.now().toString(36);
const EMAIL = `${TAG}@example.test`;

const sql = (q) => execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '-N', '-B', '-r', DB, '-e', q], { encoding: 'utf8', maxBuffer: 1 << 28 }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 60000) {
  const t0 = Date.now();
  for (;;) {
    let v; try { v = await fn(); } catch { v = false; }
    if (v) return v;
    if (Date.now() - t0 > ms) throw new Error('timeout waiting for ' + what);
    await sleep(250);
  }
}
let passed = 0, failed = 0;
function check(name, ok, detail = '') {
  if (ok) { passed++; console.log('  ok   ' + name); }
  else { failed++; console.log('  FAIL ' + name + (detail ? '  — ' + detail : '')); }
}
const counts = () => sql('SELECT (SELECT COUNT(*) FROM orders),(SELECT COUNT(*) FROM tickets),(SELECT COUNT(*) FROM tix_users),(SELECT COUNT(*) FROM seat_holds),(SELECT SUM(sold) FROM ticket_classes)');

const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const before = counts();
const proxy = spawn(process.execPath, [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,ticketing'], { stdio: 'ignore' });
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
const store = {};   // localStorage carried into the next tab (the Buyer session survives a "reload")
async function openTab(hash) {
  const html = await (await fetch(`${BASE}/ticketing/`)).text();
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented|Could not parse CSS/.test(String(e.message))) pageErrors.push(String(e.message).slice(0, 200)); });
  const dom = new JSDOM(html, {
    url: `${BASE}/ticketing/${hash}`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      for (const [k, v] of Object.entries(store)) w.localStorage.setItem(k, v);
      w.fetch = (input, init = {}) => { const { signal, ...rest } = init; return fetch(new URL(String(input), w.location.href), rest); };
      w.scrollTo = () => {}; w.alert = () => {}; w.confirm = () => true;
      w.HTMLElement.prototype.scrollIntoView = () => {};
      w.matchMedia = () => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} });
      for (const k of ['IntersectionObserver', 'ResizeObserver']) if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
    },
  });
  tabs.push(dom.window);
  return dom.window;
}
const app = (w) => w.document.querySelector('#app')?.textContent || '';
const api = (w, aksi, data) => w.eval(`api(${JSON.stringify(aksi)}${data ? ', ' + JSON.stringify(data) : ''})`);

try {
  await until(async () => (await fetch(`${BASE}/ticketing-api/api.php?action=ping`)).ok, 'devproxy');
  const pingRes = await fetch(`${BASE}/ticketing-api/api.php?action=ping`);
  const ping = await pingRes.json();
  check('ticketing API served by Laravel, payments simulated', ping.data?.backend === 'laravel' && ping.data?.simulasi_bayar === true
    && pingRes.headers.get('x-devproxy-backend') === 'laravel', JSON.stringify(ping).slice(0, 160));

  const eventTitle = JSON.parse(sql("SELECT data FROM events WHERE id='e2'")).title;
  let w = await openTab('');
  await until(() => app(w).includes(eventTitle), 'event list');
  check('event list renders the events on sale', app(w).includes(eventTitle));

  const reg = await api(w, 'daftar', { name: 'Pembeli E2E', email: EMAIL, phone: '0812', password: 'rahasia123' });
  check('daftar returns a Buyer + session', reg.user?.email === EMAIL && !!reg.token);
  w.eval(`Akun.simpan(${JSON.stringify(reg.token)}, ${JSON.stringify(reg.user)})`);
  store.lmtix_sesi = w.localStorage.getItem('lmtix_sesi');

  w = await openTab('#event/e2');
  await until(() => w.eval('Akun.masuk()') && app(w).includes(eventTitle), 'event page with session');
  check('session survives a reload (saya)', w.eval('Akun.user && Akun.user.email') === EMAIL);

  w.eval("go('denah','e2')");
  await until(() => w.eval('typeof DENAH_DETIL!=="undefined" && DENAH_DETIL.length > 0'), 'seat map');
  const seat = w.eval('(DENAH_DETIL.find(o=>o.status==="available" && o.dijual && o.price>0)||{}).id');
  check('seat map renders with sellable seats', !!seat);

  const hold = await api(w, 'hold', { event_id: 'e2', seats: [seat], hold_token: TAG });
  check('hold', hold.held?.[0] === seat, JSON.stringify(hold).slice(0, 160));
  const classes = await api(w, 'action=event&id=e2');
  const general = classes.classes.find((c) => !c.is_seated && c.price > 0 && c.sisa > 0);
  const co = await api(w, 'checkout', { event_id: 'e2', hold_token: TAG, name: 'Pembeli E2E', email: EMAIL, phone: '0812',
    sesi: w.eval('Akun.sesi'), umum: general ? [{ class_id: general.id, qty: 1 }] : [] });
  check('checkout returns the simulated payment page', /#simbayar\//.test(co.invoice_url) && !!co.ref, JSON.stringify(co).slice(0, 160));

  w.eval(`go('simbayar', ${JSON.stringify(co.ref)}, ${JSON.stringify(co.access_token)})`);
  await until(() => w.document.querySelector('#sim-go'), 'simbayar button');
  w.document.querySelector('#sim-go').click();
  const want = 1 + (general ? 1 : 0);
  await until(() => w.eval('typeof TIKET_KINI!=="undefined" && TIKET_KINI && TIKET_KINI.status==="Paid"'), 'e-ticket page');
  await sleep(500);
  const tickets = w.eval('JSON.stringify(TIKET_KINI.tickets.map(t=>t.ticket_number))');
  check('payment issues one ticket per guest', JSON.parse(tickets).length === want, tickets);
  check('e-ticket page shows every ticket number', JSON.parse(tickets).every((n) => app(w).includes(n)));
  check('QR codes are drawn on the page', w.document.querySelectorAll('#app svg, #app canvas').length >= want);

  w.eval("go('tiketsaya')");
  await until(() => app(w).includes(co.ref), 'Tiket Saya');
  check('Tiket Saya lists the order', app(w).includes(co.ref));

  const out = await api(w, 'keluar', { sesi: w.eval('Akun.sesi') });
  check('keluar ends the session', out.keluar === true && (await api(w, 'action=saya&sesi=' + encodeURIComponent(w.eval('Akun.sesi')))) === null);
  const lupa = await api(w, 'lupaPassword', { email: EMAIL });
  check('lupaPassword answers the same for everyone', lupa.terkirim === true);

  check('no page errors', pageErrors.length === 0, pageErrors.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
}

await sleep(1000);
restore();
check('database restored from snapshot', counts() === before, `${before} vs ${counts()}`);
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
