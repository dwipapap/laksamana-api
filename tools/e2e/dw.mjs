#!/usr/bin/env node
/*
 * E2E — the REAL old DW frontend (laksamana-office/deploy/dw/index.html)
 * running headless (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/dw.mjs [--port 8182] [--user u-rizkiarfan]
 *
 * Proves the cutover: only /dw-api-mysql + /account-api-mysql go to Laravel,
 * every other backend the page touches stays the old PHP.
 *
 * Walkthrough (every step through the page's own code: S, apiPost(),
 * setHadir(), gantiOrangKirim(), toggleBayarLunas(), simpanSetting()):
 *   1. SSO boot with a Laravel-issued lm_session  -> app opens, dashboard renders
 *   2. create + approve a shift via the page      -> DISETUJUI row in DB
 *   3. setHadir HADIR + reset ''                  -> hadir flag in DB follows
 *   4. gantiOrangKirim to a stand-in              -> old row ALFA, new row HADIR+DISETUJUI
 *   5. toggleBayarLunas on/off                    -> bayarLunas tick in the setting blob
 *   6. simpanSetting with a tarif tweak           -> blob in DB has it
 *   7. reload in a fresh tab                      -> everything above is still there
 *   8. walk the screens (hadir, bayar, rekap, pekerja, pengaturan) -> they render
 *   9. clean up through the page + restore the setting blob -> DB back to start
 *
 * LOCAL ONLY: writes to the local restored lakk5493_db_dw (one 2033 fixture
 * shift + one e2e setting key + one e2e payment tick, all removed after).
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
const OFFICE = officeCandidates.find((d) => fs.existsSync(path.join(d, 'deploy/dw/index.html')));
if (!OFFICE) { console.error('laksamana-office not found (set OFFICE_DIR)'); process.exit(2); }
const MYSQL = process.env.MYSQL_BIN || 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8182'), 10);
const USER = arg('--user', 'u-rizkiarfan');
const BASE = `http://127.0.0.1:${PORT}`;
const TAG = 'e2e' + Date.now().toString(36);
const TGL = '2033-06-01';
const SENIN = '2033-01-03';
const KUNCI = 'BANK::' + TAG;

const sql = (q, db = 'lakk5493_db_dw') =>
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

// ---- devproxy (dw + account to Laravel, everything else legacy PHP) -----
const proxy = spawn(process.execPath, [path.join(API_ROOT, 'tools/devproxy/serve.mjs'),
  '--port', String(PORT), '--laravel', 'account,dw'],
  { stdio: 'ignore', env: { ...process.env, OFFICE_DIR: OFFICE } });
const stop = () => { try { proxy.kill(); } catch {} };
process.on('exit', stop);
await until(async () => (await fetch(`${BASE}/dw-api-mysql/api.php?action=ping`)).ok, 'devproxy', 30000);
const ping = await (await fetch(`${BASE}/dw-api-mysql/api.php?action=ping`)).json();
check('dw API served by Laravel', ping.ok && ping.data && ping.data.backend === 'laravel', JSON.stringify(ping).slice(0, 200));
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

// snapshot the setting blob so the run restores it byte-for-byte at the end
const settingBefore = sql('SELECT `data` FROM `dw_setting` WHERE `id`=1');

// ---- one browser tab ------------------------------------------------------------
const html = await (await fetch(`${BASE}/dw/`)).text();
const pageErrors = [];
async function openTab(label) {
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(e.message)) pageErrors.push(`[${label}] ${e.message}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}/dw/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
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
  await until(() => w.eval('typeof ME!=="undefined" && ME && S && S.peran && S.peran.hrd'), `${label} boot`);
  return w;
}
const ajuanRow = (id) => sql(`SELECT \`id\`,\`dw_id\`,\`tgl\`,\`status\`,\`hadir\`,\`permintaan_id\` FROM \`dw_ajuan\` WHERE \`id\`='${id}'`);
const hadirOf = (id) => { const c = ajuanRow(id).split('\t'); while (c.length < 6) c.push(''); return c[4]; };

console.log('\n1. SSO boot + dashboard');
const A = await openTab('tabA');
check('app opened as the Office user', A.eval('ME.id') === u.id, A.eval('ME && ME.id'));
check('state loaded from Laravel (HRD)', A.eval('S.peran.hrd') === true && A.eval('S.pekerja.length') > 0,
  `pekerja=${A.eval('S.pekerja.length')}`);
A.eval("go('dashboard')");
const dashText = A.document.getElementById('view').textContent;
check('dashboard renders', dashText.includes('DW Masuk') && dashText.includes('Menunggu'),
  `"${dashText.replace(/\s+/g, ' ').slice(0, 160)}"`);

console.log('\n2. create + approve a shift through the page');
const mk = await A.eval(`(async()=>{
  const a = await apiPost({action:'simpanAjuan', row:{dwId:'DWmtxzw6fd369', tgl:'${TGL}', m:'18:00', s:'23:00', divisi:'bar'}});
  await apiPost({action:'putusAjuan', id:a.row.id, status:'DISETUJUI'});
  await muatDanGambar(true);
  return JSON.stringify(a.row.id);
})()`);
const AJ = JSON.parse(mk);
check('shift approved in DB', ajuanRow(AJ).includes('DISETUJUI'), ajuanRow(AJ));
// the fixture lives in 2033, outside the loaded month: flip the page calendar there
await A.eval(`(async()=>{ BULAN_AKTIF='2033-06'; DASH_TGL='${TGL}'; await muatDanGambar(true); })()`);
check('page sees the fixture shift', A.eval(`S.ajuan.some(a=>a.id===${JSON.stringify(AJ)})`));

console.log('\n3. attendance through the page');
await A.eval(`(async()=>{ await setHadir(${JSON.stringify(AJ)}, 'HADIR'); })()`);
check('HADIR in DB', hadirOf(AJ) === 'HADIR', ajuanRow(AJ));
await A.eval(`(async()=>{ await setHadir(${JSON.stringify(AJ)}, ''); })()`);
check("reset '' in DB", hadirOf(AJ) === '', ajuanRow(AJ));

console.log('\n4. replacement through the page');
await A.eval(`(async()=>{ await gantiOrangKirim(${JSON.stringify(AJ)}, 'DWmty9fok2781', 'e2e ${TAG}'); })()`);
const oldHad = hadirOf(AJ);
const baruId = sql(`SELECT \`id\` FROM \`dw_ajuan\` WHERE \`dw_id\`='DWmty9fok2781' AND \`tgl\`='${TGL}' AND \`status\`='DISETUJUI'`);
const baru = baruId ? ajuanRow(baruId) : '';
check('old row ALFA', oldHad === 'ALFA', ajuanRow(AJ));
check('new row HADIR + DISETUJUI', baru.includes('HADIR') && baru.includes('DISETUJUI'), baru);

console.log('\n5. payment tick through the page');
await A.eval(`(async()=>{ await toggleBayarLunas(${JSON.stringify(SENIN)}, ${JSON.stringify(KUNCI)}); })()`);
const tickestable = sql('SELECT `data` FROM `dw_setting` WHERE `id`=1');
check('tick in the setting blob', tickestable.includes(SENIN + '|' + KUNCI), tickestable.slice(-160));
await A.eval(`(async()=>{ await toggleBayarLunas(${JSON.stringify(SENIN)}, ${JSON.stringify(KUNCI)}); })()`);
check('tick removed again', !sql('SELECT `data` FROM `dw_setting` WHERE `id`=1').includes(SENIN + '|' + KUNCI));

console.log('\n6. settings through the page');
await A.eval(`(async()=>{ S.setting.tarif['E2E ${TAG}']=12345; await simpanSetting(); })()`);
check('tarif tweak in DB', sql('SELECT `data` FROM `dw_setting` WHERE `id`=1').includes('E2E ' + TAG));

console.log('\n7. reload in a fresh tab');
const B = await openTab('tabB');
await B.eval(`(async()=>{ BULAN_AKTIF='2033-06'; DASH_TGL='${TGL}'; await muatDanGambar(true); })()`);
check('replacement survives reload', B.eval(`!!S.ajuan.find(a=>a.id===${JSON.stringify(baruId)})`), `ajuan=${B.eval('S.ajuan.length')}`);
check('setting tweak survives reload', B.eval(`(S.setting.tarif||{})[${JSON.stringify('E2E ' + TAG)}]`) === 12345);
A.close();

console.log('\n8. walk the screens');
for (const v of ['hadir', 'bayar', 'rekap', 'pekerja', 'pengaturan']) {
  B.eval(`go(${JSON.stringify(v)})`);
  await sleep(300);
  const t = B.document.getElementById('view').textContent.replace(/\s+/g, ' ').slice(0, 120);
  check(`screen ${v} renders`, !t.includes('untuk HRD dan head divisi') && t.length > 20, t.slice(0, 100));
}

console.log('\n9. clean up through the page');
await B.eval(`(async()=>{
  await apiPost({action:'hapusAjuan', id:${JSON.stringify(baruId)}});
  await apiPost({action:'hapusAjuan', id:${JSON.stringify(AJ)}});
  await muatDanGambar(true);
})()`);
check('fixture shifts gone from DB', ajuanRow(AJ) === '' && ajuanRow(baruId) === '');
// restore the exact blob bytes (harness SQL, local DB only)
{
  const esc = settingBefore.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n');
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '-N', '-B', 'lakk5493_db_dw', '-e',
    `UPDATE \`dw_setting\` SET \`data\`='${esc}' WHERE \`id\`=1`], { encoding: 'utf8' });
}
check('setting blob restored', sql('SELECT `data` FROM `dw_setting` WHERE `id`=1') === settingBefore);
B.close();

const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext/i.test(e));
check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
console.log(`\n${passed}/${passed + failed} checks passed`);
stop();
process.exit(failed ? 1 : 0);
