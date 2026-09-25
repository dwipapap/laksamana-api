#!/usr/bin/env node
/*
 * E2E — the REAL Reservasi and Service Excellent frontends running headless
 * (jsdom) against Laravel, through devproxy.
 *
 *   node tools/e2e/reservasi.mjs [--port 8195] [--user u-wandi]
 *
 * /reservasi-api-mysql + /account-api-mysql go to Laravel; the other legacy
 * backends stay on old PHP. The walkthrough covers:
 *   1. Reservasi boot -> every Panel page renders from getAll
 *   2. reservation create + edit + DP saved through the page's own saveNow()
 *   3. two tabs merge instead of overwriting each other
 *   4. Master Data + Audit Log render
 *   5. Service Excellent boot -> every page renders; review + feedback saved
 *   6. the Reservasi tab then saves again without losing the Service Excellent rows
 *   7. rows/keys the walkthrough did not touch stay byte-identical
 * Finally lakk5493_db_reservasi and the scratch photo folder are restored.
 *
 * LOCAL ONLY. PHP_BIN=C:\Users\dwip\.config\herd-lite\bin\php.exe
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
const PORT = parseInt(arg('--port', '8195'), 10);
const USER = arg('--user', 'u-wandi');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_reservasi';
const TAG = 'e2e' + Date.now().toString(36);
const ROW_A = TAG + '-a';
const ROW_B = TAG + '-b';
const DP = TAG + '-dp';
const REVIEW = TAG + '-rv';
const FEEDBACK = TAG + '-fb';
const CATEGORY = TAG + '-kategori';
const DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'reservasi-e2e-'));

const sql = (q, db = DB) =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '-N', '-B', '-r', db, '-e', q], { encoding: 'utf8', maxBuffer: 1 << 28 }).trim();
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
const row = (id) => {
  const v = sql(`SELECT data FROM reservations WHERE id='${id}'`);
  return v ? JSON.parse(v) : null;
};
const master = () => JSON.parse(sql("SELECT v FROM settings WHERE k='master'"));
const ver = () => Number(sql("SELECT v FROM settings WHERE k='_ver'"));
const reservationRows = (except = []) => {
  const where = except.length ? ` WHERE id NOT IN (${except.map((id) => `'${id}'`).join(',')})` : '';
  return sql(`SELECT data FROM reservations${where} ORDER BY created_at, id`);
};

const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '--single-transaction', '--no-tablespaces', DB], { maxBuffer: 1 << 30 });
const beforeRows = reservationRows();
const beforeMaster = master();

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,reservasi'],
  { stdio: 'ignore', env: { ...process.env, RESERVASI_DATA_DIR: DATA_DIR } });
let restored = false;
const tabs = [];
const restore = () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {}
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', DB], { input: snapshot, maxBuffer: 1 << 30 });
  try { fs.rmSync(DATA_DIR, { recursive: true, force: true }); } catch {}
};
process.on('exit', restore);

const pageErrors = [];
let lmSession = '';
async function openTab(label, urlPath, ready) {
  const html = await (await fetch(`${BASE}${urlPath}`)).text();
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(`[${label}] ${String(e.message).slice(0, 200)}`); });
  vc.on('error', (...a) => pageErrors.push(`[${label}] console.error ${a.map(String).join(' ').slice(0, 200)}`));
  const dom = new JSDOM(html, {
    url: `${BASE}${urlPath}`, runScripts: 'dangerously', resources: 'usable', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      w.localStorage.setItem('lm_session', lmSession);
      w.fetch = (input, init = {}) => {
        const { signal, ...rest } = init;          // jsdom AbortSignal is not Node's
        return fetch(new URL(String(input), w.location.href), rest);
      };
      w.confirm = () => true;
      w.print = () => {};
      w.Chart = class { constructor() {} destroy() {} update() {} };   // CDN libs are not loaded by jsdom
      w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
      w.scrollTo = () => {};
      w.HTMLElement.prototype.scrollIntoView = () => {};
      for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
        if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
    },
  });
  const w = dom.window;
  tabs.push({ close: () => dom.window.close() });
  try {
    await until(() => ready(w), `${label} boot`);
  } catch (e) {
    console.log('    boot errors:', pageErrors.slice(-5));
    throw e;
  }
  return w;
}
const pages = (w) => JSON.parse(w.eval('JSON.stringify(NAV_PAGES)'));
const idle = (w) => until(() => w.eval('!_saving && !_saveAgain'), 'save idle');
const bootReady = (w) => w.eval('typeof STATE!=="undefined" && DATA_LOADED && !!SESSION && !!CURRENT_PAGE');
const pageText = (w, page) => (w.document.querySelector('#page-' + page)?.textContent || '').length;

try {
  await until(async () => (await fetch(`${BASE}/reservasi-api-mysql/api.php?action=ping`)).ok, 'devproxy');
  const pingRes = await fetch(`${BASE}/reservasi-api-mysql/api.php?action=ping`);
  const ping = await pingRes.json();
  check('Reservasi API served by Laravel', ping.data?.backend === 'laravel' && pingRes.headers.get('x-devproxy-backend') === 'laravel', JSON.stringify(ping).slice(0, 140));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('login has both Modul', login.ok && ['reservasi', 'service_excellent'].every((m) => (u.modules || []).includes(m)), JSON.stringify(u.modules));
  lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  console.log('\n1. Reservasi Panel');
  const R = await openTab('reservasi', '/reservasi/', bootReady);
  check('state + master loaded', R.eval('STATE.reservations.length') === Number(sql('SELECT COUNT(*) FROM reservations'))
    && R.eval('Array.isArray(STATE.master.users)'));
  for (const p of pages(R)) {
    R.eval(`navigate(${JSON.stringify(p)})`);
    check(`reservasi page ${p}`, pageText(R, p) > 200, String(pageText(R, p)));
  }

  console.log('\n2. reservation create, edit and DP through the page');
  let v0 = ver();
  R.eval(`STATE.reservations.push({id:${JSON.stringify(ROW_A)},name:'E2E Reservasi',phone:'0812',date:'2030-01-15',time:'19:00',pax:2,status:'Pending',dps:[],createdAt:Date.now(),updatedAt:Date.now(),createdBy:${JSON.stringify(u.name)}}); logAudit('Buat Reservasi',${JSON.stringify(TAG)},${JSON.stringify(ROW_A)}); saveState(); saveNow();`);
  await until(() => ver() > v0 && row(ROW_A), 'reservation create');
  await idle(R);
  check('reservation created by the page', row(ROW_A)?.name === 'E2E Reservasi' && row(ROW_A).log?.[0]?.action === 'Buat Reservasi');

  v0 = ver();
  R.eval(`STATE.reservations.find(function(r){return r.id===${JSON.stringify(ROW_A)}}).pax=9; STATE.reservations.find(function(r){return r.id===${JSON.stringify(ROW_A)}}).updatedAt=Date.now(); saveState(); saveNow();`);
  await until(() => ver() > v0 && row(ROW_A)?.pax === 9, 'reservation edit');
  await idle(R);
  check('edit from the same tab passes', row(ROW_A)?.pax === 9);

  v0 = ver();
  R.eval(`(function(){const r=STATE.reservations.find(function(x){return x.id===${JSON.stringify(ROW_A)}}); r.dpStatus='Sudah'; r.dpMethod='Cash'; r.dpAmount=50000; r.dpProofData='data:image/png;base64,QUJD'; r.dpProofName='bukti.png'; r.dps=[{id:${JSON.stringify(DP)},amount:50000,method:'Cash',proofData:r.dpProofData,proofName:r.dpProofName,by:${JSON.stringify(u.name)},at:Date.now()}]; r.updatedAt=Date.now(); logAudit('Tambah DP',${JSON.stringify(TAG)},r.id); saveState(); saveNow();})();`);
  await until(() => ver() > v0 && row(ROW_A)?.dps?.[0]?.id === DP, 'DP save');
  await idle(R);
  check('DP and its proof saved', row(ROW_A)?.dps?.[0]?.amount === 50000 && row(ROW_A)?.dpProofData === `@f:r:${ROW_A}:dp`);
  check('proof is a file, not inline', fs.existsSync(path.join(DATA_DIR, 'files', `r_${ROW_A}_dp.txt`)));
  const proof = await (await fetch(`${BASE}/reservasi-api-mysql/api.php?action=getFile&key=${encodeURIComponent(`r:${ROW_A}:dp`)}`)).json();
  check('proof served back through getFile', proof.data?.data === 'data:image/png;base64,QUJD');

  console.log('\n3. two tabs merge instead of overwriting');
  const B = await openTab('reservasiB', '/reservasi/', bootReady);
  v0 = ver();
  B.eval(`STATE.reservations.push({id:${JSON.stringify(ROW_B)},name:'E2E Tab B',date:'2030-01-16',time:'19:00',pax:3,status:'Pending',dps:[],createdAt:Date.now(),updatedAt:Date.now()}); logAudit('Buat Reservasi','${TAG}-B',${JSON.stringify(ROW_B)}); saveState(); saveNow();`);
  await until(() => ver() > v0 && row(ROW_B), 'tab B save');
  await idle(B);
  v0 = ver();
  R.eval(`STATE.reservations.find(function(r){return r.id===${JSON.stringify(ROW_A)}}).note='${TAG}-A'; STATE.reservations.find(function(r){return r.id===${JSON.stringify(ROW_A)}}).updatedAt=Date.now(); saveState(); saveNow();`);
  await until(() => ver() > v0 && row(ROW_A)?.note === `${TAG}-A`, 'tab A save after B');
  await idle(R);
  check('the other tab\'s reservation survives', !!row(ROW_B) && row(ROW_A)?.note === `${TAG}-A`);

  console.log('\n4. Master Data and Audit Log');
  R.eval('navigate("master")');
  check('master page renders', pageText(R, 'master') > 200);
  v0 = ver();
  R.eval(`STATE.master.categories.push(${JSON.stringify(CATEGORY)}); logAudit('Ubah Master Data',${JSON.stringify(TAG)}); saveState(); saveNow();`);
  await until(() => ver() > v0 && master().categories?.includes(CATEGORY), 'master save');
  await idle(R);
  R.eval('navigate("audit")');
  check('audit page shows the acting user', pageText(R, 'audit') > 200 && R.document.body.textContent.includes(u.name));

  console.log('\n5. Service Excellent');
  const S = await openTab('serviceExcellent', '/service_excellent/', bootReady);
  check('Service Excellent sees the reservations saved from Reservasi', S.eval(`STATE.reservations.some(function(r){return r.id===${JSON.stringify(ROW_A)}})`));
  for (const p of pages(S)) {
    S.eval(`navigate(${JSON.stringify(p)})`);
    check(`service excellent page ${p}`, pageText(S, p) > 200, String(pageText(S, p)));
  }
  v0 = ver();
  S.eval(`STATE.master.reviews.push({id:${JSON.stringify(REVIEW)},resId:${JSON.stringify(ROW_A)},name:'E2E Tamu',date:'2030-01-15',status:'diminta',rating:0,by:${JSON.stringify(u.name)},at:Date.now()}); STATE.master.feedbacks.push({id:${JSON.stringify(FEEDBACK)},resId:${JSON.stringify(ROW_A)},name:'E2E Tamu',date:'2030-01-15',tone:'positif',items:[{cat:'service',text:${JSON.stringify(TAG)}}],status:'baru',by:${JSON.stringify(u.name)},at:Date.now()}); logAudit('Catat Feedback',${JSON.stringify(TAG)}); saveState(); saveNow();`);
  await until(() => ver() > v0 && master().reviews?.some((r) => r.id === REVIEW) && master().feedbacks?.some((f) => f.id === FEEDBACK), 'Service Excellent save');
  await idle(S);
  check('review + feedback saved in the shared master', !!master().reviews.find((r) => r.id === REVIEW) && !!master().feedbacks.find((f) => f.id === FEEDBACK));

  console.log('\n6. Reservasi saves again without losing Service Excellent rows');
  v0 = ver();
  R.eval(`STATE.reservations.find(function(r){return r.id===${JSON.stringify(ROW_A)}}).pax=11; STATE.reservations.find(function(r){return r.id===${JSON.stringify(ROW_A)}}).updatedAt=Date.now(); saveState(); saveNow();`);
  await until(() => ver() > v0 && row(ROW_A)?.pax === 11, 'Reservasi save after Service Excellent');
  await idle(R);
  check('shared master rows survive the other frontend', !!master().reviews.find((r) => r.id === REVIEW) && !!master().feedbacks.find((f) => f.id === FEEDBACK));

  console.log('\n7. untouched state');
  await sleep(800);
  check('untouched reservation rows byte-identical', reservationRows([ROW_A, ROW_B]) === beforeRows);
  const afterMaster = master();
  const cleanMaster = (m) => {
    const c = JSON.parse(JSON.stringify(m));
    c.categories = (c.categories || []).filter((x) => x !== CATEGORY);
    c.reviews = (c.reviews || []).filter((x) => x.id !== REVIEW).map((x) => {
      if (typeof x.proofData === 'string' && x.proofData.startsWith('data:')) x.proofData = `@f:rv:${x.id}`;
      if (typeof x.proof2Data === 'string' && x.proof2Data.startsWith('data:')) x.proof2Data = `@f:rv2:${x.id}`;
      return x;
    });
    c.feedbacks = (c.feedbacks || []).filter((x) => x.id !== FEEDBACK).map((x) => {
      if (typeof x.proofData === 'string' && x.proofData.startsWith('data:')) x.proofData = `@f:fb:${x.id}`;
      return x;
    });
    // seCrew is filled from the Office roster in the background when Service
    // Excellent boots, so it is a boot migration rather than walkthrough data.
    delete c.seCrew;
    return c;
  };
  const beforeComparable = cleanMaster(beforeMaster);
  const cleaned = cleanMaster(afterMaster);
  const changedMasterKeys = [...new Set([...Object.keys(beforeComparable), ...Object.keys(cleaned)])]
    .filter((k) => JSON.stringify(beforeComparable[k]) !== JSON.stringify(cleaned[k]));
  check('untouched master keys byte-identical', changedMasterKeys.length === 0, changedMasterKeys.join(', '));
  const audit = sql('SELECT data FROM audit ORDER BY ts DESC LIMIT 20').split('\n').filter(Boolean).map((x) => JSON.parse(x));
  check('audit names the session user', audit.some((a) => a.user === u.name && a.action === 'Buat Reservasi'));
  // Dana Masuk auto-scans transfer proofs; jsdom has no OCR, so a missing proof logs its own error.
  const relevant = pageErrors.filter((e) => !/Could not parse CSS|canvas|getContext|ResizeObserver loop|Bukti tidak ditemukan di database/i.test(e));
  check('no page errors', relevant.length === 0, relevant.slice(0, 5).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
}

await sleep(1500);
restore();
check('database restored from snapshot', reservationRows() === beforeRows && JSON.stringify(master()) === JSON.stringify(beforeMaster));
console.log(`\n${passed}/${passed + failed} checks passed`);
process.exit(failed ? 1 : 0);
