#!/usr/bin/env node
/*
 * E2E — the REAL old absensi PWA (laksamana-office/absensi/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/absensi.mjs [--port 8183] [--user u-admin]
 *
 * Proves the cutover: only /absensi/api/api.php (+ /api/api.php, the same
 * backend on its own subdomain) and /account-api-mysql go to Laravel, every
 * other backend the page touches stays the old PHP.
 *
 * Walkthrough (every step through the page's own code: its api() transport,
 * muatKonteks(), go()):
 *   1. SSO boot with an lm_absensi_sesi session -> app opens as HR, context loads
 *   2. enrol own face through the page        -> wajahTerdaftar on reload
 *   3. tweak a setting through the page       -> blob in DB has it
 *   4. add a work location through the page   -> punch far away queues LUAR_AREA
 *   5. absen MASUK through the page           -> MENUNGGU, needs a reason
 *   6. antrean + putusAbsen through the page  -> VALID, shown in rekap
 *   7. absen PULANG through the page          -> dated on the MASUK day
 *   8. reload in a fresh tab                  -> everything above is still there
 *   9. walk the screens                       -> they render
 *  10. clean up through the page + restore the setting blob -> DB back to start
 *
 * LOCAL ONLY: writes to the local restored lakk5493_db_absensi (one fixture
 * location + one face + today's punches for the e2e user + one e2e setting
 * key, all removed after).
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
const OFFICE = officeCandidates.find((d) => fs.existsSync(path.join(d, 'absensi/index.html')));
if (!OFFICE) { console.error('laksamana-office not found (set OFFICE_DIR)'); process.exit(2); }
const MYSQL = process.env.MYSQL_BIN || 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8183'), 10);
const USER = arg('--user', 'u-admin');
const BASE = `http://127.0.0.1:${PORT}`;
const TAG = 'e2e' + Date.now().toString(36);
const DESC = Array(128).fill(0.5);

const sql = (q, db = 'lakk5493_db_absensi') =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 90000) {
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

// ---- devproxy (absensi + account to Laravel, everything else legacy PHP) -----
const proxy = spawn(process.execPath, [path.join(API_ROOT, 'tools/devproxy/serve.mjs'),
  '--port', String(PORT), '--laravel', 'account,absensi'],
  { stdio: 'ignore', env: { ...process.env, OFFICE_DIR: OFFICE } });
const stop = () => { try { proxy.kill(); } catch {} };
process.on('exit', stop);
await until(async () => (await fetch(`${BASE}/absensi/api/api.php?action=ping`)).ok, 'devproxy', 30000);
const ping = await (await fetch(`${BASE}/absensi/api/api.php?action=ping`)).json();
check('absensi API served by Laravel', ping.ok && ping.data && ping.data.backend === 'laravel', JSON.stringify(ping).slice(0, 200));
const subPing = await (await fetch(`${BASE}/api/api.php?action=ping`)).json();
check('subdomain shape served by Laravel too', subPing.ok && subPing.data && subPing.data.backend === 'laravel');
const dwHdr = (await fetch(`${BASE}/dw-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
check('other modules stay on legacy PHP (dw)', dwHdr === 'legacy-php', dwHdr);

// ---- Office login like the PWA does it: masuk on the absensi backend ----
// (masuk is forwarded to the account backend server-to-server), then the
// token is kept 30 days as lm_absensi_sesi.
const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
const masuk = await (await fetch(`${BASE}/absensi/api/api.php`, {
  method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'masuk', nama: name, pin }),
})).json();
check('masuk via Laravel (forwarded to account)', masuk.ok && masuk.data && masuk.data.user.token, JSON.stringify(masuk).slice(0, 200));
const u = masuk.data.user;
const sesi = JSON.stringify({ id: u.id, nama: u.name || u.nama, token: u.token || '',
  keterangan: u.keterangan || '', expiry: Date.now() + 30 * 24 * 3600e3 });

// snapshot the setting blob so the run restores it byte-for-byte at the end
const settingBefore = sql('SELECT `data` FROM `abs_setting` WHERE `id`=1');

// ---- one browser tab (HTML read from disk: absensi/ is not under deploy/) ----
const html = fs.readFileSync(path.join(OFFICE, 'absensi/index.html'), 'utf8');
const pageErrors = [];
async function openTab(label) {
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented|canvas/i.test(e.message)) pageErrors.push(`[${label}] ${e.message}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}/absensi/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      w.localStorage.setItem('lm_absensi_sesi', sesi);
      w.fetch = (input, init = {}) => {
        const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
        return fetch(new URL(String(input), w.location.href), rest);
      };
      w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
      w.scrollTo = () => {};
      w.HTMLElement.prototype.scrollIntoView = () => {};
      for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
        if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
      if (!w.navigator.geolocation) w.navigator.geolocation = undefined;
    },
  });
  const w = dom.window;
  await until(() => w.eval('typeof KTX!=="undefined" && KTX && KTX.siapa && KTX.siapa.id'), `${label} boot`);
  return w;
}
const punchOf = (id) => sql(`SELECT \`id\`,\`subjek_id\`,\`tgl\`,\`arah\`,\`status\`,\`sebab\` FROM \`abs_punch\` WHERE \`id\`='${id}'`);

console.log('\n1. SSO boot + context');
const A = await openTab('tabA');
check('app opened as the Office user', A.eval('ME.id') === u.id, A.eval('ME && ME.id'));
check('context loaded from Laravel (HR)', A.eval('KTX.siapa.hr') === 1 && A.eval('KTX.tgl.length') === 10,
  `hr=${A.eval('KTX.siapa.hr')} tgl=${A.eval('KTX.tgl')}`);
A.eval("go('absen')");
check('absen screen renders', A.document.getElementById('app').textContent.length > 50);

console.log('\n2. enrol own face through the page');
const DESCJSON = JSON.stringify(DESC);
await A.eval(`(async()=>{ await api('daftarWajah',{tipe:'USER', id:KTX.siapa.id, nama:KTX.siapa.nama, descriptor:${DESCJSON}, foto:''}); await muatKonteks(); })()`);
check('face in DB', sql(`SELECT COUNT(*) FROM \`abs_wajah\` WHERE \`subjek\`='USER:${USER}'`) === '1');
check('konteks flags the face', A.eval('KTX.wajahTerdaftar') === 1);

console.log('\n3. settings through the page');
await A.eval(`(async()=>{ const d = Object.assign({}, KTX.setting, {toleransiTelat: 9}); await api('simpanSetting',{data:d}); await muatKonteks(); })()`);
check('tweak in DB', sql('SELECT `data` FROM `abs_setting` WHERE `id`=1').includes('"toleransiTelat":9'));

console.log('\n4. work location through the page');
const locId = await A.eval(`(async()=>{ const r = await api('simpanLokasi',{row:{nama:'E2E ${TAG}', lat:-6.2, lng:106.8, radius:120, aktif:1}}); await muatKonteks(); return r.id; })()`);
check('location saved with id', typeof locId === 'string' && locId.length > 0, String(locId));
check('page sees the location', A.eval(`KTX.lokasi.some(l=>l.id===${JSON.stringify(locId)})`));

console.log('\n5. absen MASUK far from the office through the page');
const masukRes = await A.eval(`(async()=>{ return await api('absen',{arah:'MASUK', lat:0, lng:0, akurasi:0, descriptor:${DESCJSON}, foto:'', alasan:'dinas e2e ${TAG}'}); })()`);
const MID = masukRes.punch.id;
check('queued LUAR_AREA', masukRes.punch.status === 'MENUNGGU' && masukRes.punch.sebab === 'LUAR_AREA',
  JSON.stringify(masukRes.punch).slice(0, 160));
check('punch in DB', punchOf(MID).includes('MENUNGGU'));

console.log('\n6. HR queue + decision through the page');
const antre = await A.eval(`(async()=>{ return await api('action=antrean'); })()`);
check('queue shows the punch', antre.some((p) => p.id === MID));
await A.eval(`(async()=>{ await api('putusAbsen',{id:${JSON.stringify(MID)}, status:'VALID', nota:''}); })()`);
check('decided VALID in DB', punchOf(MID).includes('VALID'));
const rek = await A.eval(`(async()=>{ return await api('action=rekap&dari='+encodeURIComponent(KTX.tgl)+'&sampai='+encodeURIComponent(KTX.tgl)); })()`);
check('rekap shows the punch', JSON.stringify(rek).includes(MID));

console.log('\n7. absen PULANG through the page');
const pulangRes = await A.eval(`(async()=>{ return await api('absen',{arah:'PULANG', lat:0, lng:0, akurasi:0, descriptor:${DESCJSON}, foto:'', alasan:'dinas e2e ${TAG}'}); })()`);
check('PULANG dated on the MASUK day', pulangRes.punch.tgl === masukRes.punch.tgl,
  `${pulangRes.punch.tgl} vs ${masukRes.punch.tgl}`);

console.log('\n8. reload in a fresh tab');
const B = await openTab('tabB');
const TGL = B.eval('KTX.tgl');
check('face survives reload', B.eval('KTX.wajahTerdaftar') === 1);
check('setting tweak survives reload', B.eval('KTX.setting.toleransiTelat') === 9);
check('today recap survives reload',
  await B.eval(`(async()=>{ const r = await api('action=rekap&dari='+encodeURIComponent(KTX.tgl)+'&sampai='+encodeURIComponent(KTX.tgl)); return JSON.stringify(r).includes(${JSON.stringify(MID)}); })()`));
A.close();

console.log('\n9. walk the screens');
B.eval(`(()=>{ const a=$('#rDari'); if(a) a.value=${JSON.stringify(TGL)}; const b=$('#rSampai'); if(b) b.value=${JSON.stringify(TGL)}; })()`);
for (const v of ['riwayat', 'antrean', 'wajah', 'atur']) {
  B.eval(`go(${JSON.stringify(v)})`);
  await sleep(300);
  const t = B.document.getElementById('app').textContent.replace(/\s+/g, ' ').slice(0, 120);
  check(`screen ${v} renders`, t.length > 20, t.slice(0, 100));
}

console.log('\n10. clean up through the page');
await B.eval(`(async()=>{
  await api('hapusLokasi',{id:${JSON.stringify(locId)}});
  await api('hapusWajah',{tipe:'USER', id:KTX.siapa.id});
  await muatKonteks();
})()`);
check('fixture location gone', sql(`SELECT COUNT(*) FROM \`abs_lokasi\` WHERE \`id\`='${locId}'`) === '0');
check('face gone', sql(`SELECT COUNT(*) FROM \`abs_wajah\` WHERE \`subjek\`='USER:${USER}'`) === '0');
// punches have no API delete (harness SQL, local DB only): only the e2e work
// date (WIB, from the page — never CURDATE(), the DB zone may differ)
execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '-N', '-B', 'lakk5493_db_absensi', '-e',
  `DELETE FROM \`abs_punch\` WHERE \`subjek_id\`='${USER}' AND \`tgl\`='${TGL}'`], { encoding: 'utf8' });
check('punches gone', sql(`SELECT COUNT(*) FROM \`abs_punch\` WHERE \`subjek_id\`='${USER}' AND \`tgl\`='${TGL}'`) === '0');
// restore the exact blob bytes (harness SQL, local DB only)
{
  const esc = settingBefore.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n');
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '-N', '-B', 'lakk5493_db_absensi', '-e',
    `UPDATE \`abs_setting\` SET \`data\`='${esc}' WHERE \`id\`=1`], { encoding: 'utf8' });
}
check('setting blob restored', sql('SELECT `data` FROM `abs_setting` WHERE `id`=1') === settingBefore);
B.close();

const relevant = pageErrors.filter((e) => !/faceapi|getUserMedia|geolocation|gps|canvas|Could not parse CSS/i.test(e));
check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
console.log(`\n${passed}/${passed + failed} checks passed`);
stop();
process.exit(failed ? 1 : 0);
