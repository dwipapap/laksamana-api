#!/usr/bin/env node
/*
 * E2E — the REAL old HPP Panel (laksamana-office/deploy/stock/hpp/index.html:
 * Bahan & Harga, Daftar Resep, Pemakaian & Selisih, Pengaturan) running headless
 * (jsdom) against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/stock-hpp.mjs [--port 8188] [--user u-jb]
 *
 * Only /stock-api-mysql + /account-api-mysql go to Laravel.
 *
 *   1. SSO boot -> hpp.php?action=all + items.php load, every row on screen
 *   2. Bahan: new -> hpp_bahan row + Purchasing product; rename with "ikut
 *      purchasing" -> both renamed (items.php addProduct + hpp.php simpanBahan)
 *   3. Resep: new base -> row; edit an existing recipe -> only the price moves;
 *      hapusResep through the confirm dialog -> gone
 *   4. Pemakaian: month loaded, a row typed, simpanPakai -> hpp_pakai + hpp_bulan
 *   5. Pengaturan: simpanAtur -> hpp_setting
 *   6. hapusBahan through the confirm dialog -> gone
 *   7. every row the walkthrough did not touch is unchanged (decoded JSON)
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
const PORT = parseInt(arg('--port', '8188'), 10);
const USER = arg('--user', 'u-jb');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_stock';
const TAG = 'E2E ' + Date.now().toString(36);
const RESEP = 'f-ad-batagor';

const sql = (q, db = DB) =>
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', '--default-character-set=utf8mb4', '-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, what, ms = 30000) {
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

// ---- snapshot, restored at the end whatever happens ----------------------------
const TABLES = ['hpp_bahan', 'hpp_resep', 'hpp_pakai', 'hpp_bulan', 'hpp_setting', 'products'];
const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--single-transaction', '--no-tablespaces', DB, ...TABLES], { maxBuffer: 1 << 30 });
const parse = (s) => { try { return JSON.parse(s); } catch { return s; } };
const rowsOf = () => {
  const out = {};
  const put = (t, q, fn) => {
    out[t] = {};
    for (const line of sql(q).split(/\r?\n/).filter(Boolean)) { const c = line.split('\t'); out[t][c[0]] = JSON.stringify(fn(c)); }
  };
  put('hpp_bahan', 'SELECT nama, CONCAT_WS("|",satuan,qty_beli,harga_beli,vendor,produk,kategori,catatan,di_purchasing,sisi_harga,updated_by) FROM hpp_bahan', (c) => c[1]);
  put('hpp_resep', 'SELECT id, CONCAT_WS("|",nama,jenis,tipe,seksi,kode,yield_qty,yield_unit,harga_baru,modal_manual,aktif,di_purchasing), bahan FROM hpp_resep', (c) => [c[1], parse(c[2])]);
  put('products', 'SELECT nama, data FROM products', (c) => parse(c[1]));
  return out;
};
const before = rowsOf();

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,stock'], { stdio: 'ignore' });
const tabs = [];
let restored = false;
const restore = async () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {}
  await sleep(2000); // in-flight requests land before the snapshot goes back
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', DB], { input: snapshot, maxBuffer: 1 << 30 });
};

try {
  await until(async () => (await fetch(`${BASE}/stock-api-mysql/hpp.php?action=ping`)).ok, 'devproxy');
  const hdr = (await fetch(`${BASE}/stock-api-mysql/hpp.php?action=ping`)).headers.get('x-devproxy-backend');
  check('stock API served by Laravel', hdr === 'laravel', String(hdr));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('Office login via Laravel, user holds hpp', login.ok && ['hpp', '*'].some((m) => (u.modules || []).includes(m)), JSON.stringify(u.modules));
  const lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  const html = await (await fetch(`${BASE}/stock/hpp/`)).text();
  const pageErrors = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(String(e.message).slice(0, 200)); });
  vc.on('error', (...a) => pageErrors.push('console.error ' + a.map(String).join(' ').slice(0, 200)));
  const dom = new JSDOM(html, {
    url: `${BASE}/stock/hpp/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      w.localStorage.setItem('lm_session', lmSession);
      w.fetch = (input, init = {}) => { const { signal, ...rest } = init; return fetch(new URL(String(input), w.location.href), rest); };
      w.confirm = () => true;
      w.alert = (m) => pageErrors.push('alert ' + m);
      w.scrollTo = () => {};
      w.HTMLElement.prototype.scrollIntoView = () => {};
      if (!w.CSS) w.CSS = { escape: (s) => String(s).replace(/["\\]/g, '\\$&') };
    },
  });
  tabs.push(dom.window);
  const w = dom.window;
  const ev = (js) => w.eval(js);
  const idle = (what) => until(() => ev('!JALAN'), what);
  const notice = () => w.document.getElementById('pesan')?.textContent.replace(/\s+/g, ' ').trim() || '';

  // 1. boot
  const nBahan = Number(sql('SELECT COUNT(*) FROM hpp_bahan')), nResep = Number(sql('SELECT COUNT(*) FROM hpp_resep'));
  await until(() => ev('S.bahan.length') === nBahan && ev('S.resep.length') === nResep && ev('Object.keys(PRODUK).length') > 0, 'page boot');
  check('page booted: every ingredient, recipe and product loaded', true);

  // 2. Bahan: new, then rename with Purchasing following
  ev(`bukaBahan('${TAG} Bahan'); Object.assign(EB, {nama:'${TAG} Bahan', satuan:'Gram', qty_beli:1000, harga_beli:20000});`);
  ev('simpanBahan()'); await sleep(100); await idle('simpanBahan');
  check('new ingredient saved, updated_by = the page user', sql(`SELECT CONCAT(harga_beli,'|',updated_by) FROM hpp_bahan WHERE nama='${TAG} Bahan'`) === `20000|${u.name}`);
  check('…and registered as a Purchasing product', sql(`SELECT COUNT(*) FROM products WHERE nama='${TAG} Bahan'`) === '1' && /Purchasing/.test(notice()), notice());
  ev(`bukaBahan('${TAG} Bahan'); EB.nama='${TAG} Baru'; document.getElementById('ikutPur') && (document.getElementById('ikutPur').checked=true);`);
  ev('simpanBahan()'); await sleep(100); await idle('rename');
  await until(() => sql(`SELECT COUNT(*) FROM hpp_bahan WHERE nama='${TAG} Baru'`) === '1', 'rename');
  check('rename: HPP row renamed', sql(`SELECT COUNT(*) FROM hpp_bahan WHERE nama='${TAG} Bahan'`) === '0');
  check('rename: Purchasing product followed (items.php addProduct)', sql(`SELECT GROUP_CONCAT(nama) FROM products WHERE nama LIKE '${TAG}%'`) === `${TAG} Baru`);

  // 3. Resep
  ev(`bukaResep(''); Object.assign(ED, {nama:'${TAG} Resep', tipe:'dish', harga_baru:30000, bahan:[{nama:'${TAG} Baru', qty:50, satuan:'Gram', ref:'bahan'}]});`);
  ev('simpanResep()'); await sleep(100); await idle('simpanResep');
  const rid = sql(`SELECT id FROM hpp_resep WHERE nama='${TAG} Resep'`);
  check('new recipe saved with its line', !!rid && JSON.parse(sql(`SELECT bahan FROM hpp_resep WHERE id='${rid}'`))[0]?.nama === `${TAG} Baru`, rid);
  const lines = sql(`SELECT bahan FROM hpp_resep WHERE id='${RESEP}'`);
  ev(`bukaResep('${RESEP}'); ED.harga_baru=41000;`);
  ev('simpanResep()'); await sleep(100); await idle('edit recipe');
  check('existing recipe edited: price moved, lines kept', sql(`SELECT harga_baru FROM hpp_resep WHERE id='${RESEP}'`) === '41000'
    && JSON.stringify(JSON.parse(sql(`SELECT bahan FROM hpp_resep WHERE id='${RESEP}'`))) === JSON.stringify(JSON.parse(lines)));
  ev(`hapusResep('${rid}'); konfirmYa();`); await sleep(100); await idle('hapusResep');
  check('recipe deleted through the confirm dialog', sql(`SELECT COUNT(*) FROM hpp_resep WHERE id='${rid}'`) === '0');

  // 4. Pemakaian
  ev(`go('pakai')`);
  await ev(`muatPakai('2031-01')`);
  ev(`isiPakai('Gula','sa','3'); isiPakai('Gula','opname','1'); PK.penjualan=5000000; simpanPakai();`);
  await until(() => sql(`SELECT COUNT(*) FROM hpp_bulan WHERE bulan='2031-01'`) === '1', 'simpanPakai');
  check('month saved (row + sales)', sql(`SELECT CONCAT(sa,'|',opname,'|',updated_by) FROM hpp_pakai WHERE bulan='2031-01' AND bahan='Gula'`) === `3|1|${u.name}`
    && sql(`SELECT penjualan FROM hpp_bulan WHERE bulan='2031-01'`) === '5000000');

  // 5. Pengaturan
  ev(`go('atur'); document.getElementById('sF').value='30'; simpanAtur();`);
  await until(() => sql('SELECT COUNT(*) FROM hpp_setting') === '1', 'simpanAtur');
  check('settings saved', JSON.parse(sql('SELECT data FROM hpp_setting WHERE id=1')).targetFood === 0.3);

  // 6. hapus bahan
  ev(`go('bahan'); hapusBahan('${TAG} Baru'); konfirmYa();`); await sleep(100); await idle('hapusBahan');
  check('ingredient deleted through the confirm dialog', sql(`SELECT COUNT(*) FROM hpp_bahan WHERE nama='${TAG} Baru'`) === '0');

  // 7. nothing else moved
  const now = rowsOf();
  for (const t of Object.keys(before)) {
    const changed = Object.keys({ ...before[t], ...now[t] })
      .filter((k) => before[t][k] !== now[t][k] && !k.startsWith(TAG) && !(t === 'hpp_resep' && k === RESEP));
    check(`${t}: untouched rows unchanged`, changed.length === 0, changed.slice(0, 5).join(', '));
  }
  check('no page errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));
} catch (e) {
  failed++;
  console.log('  FAIL ' + (e && e.stack || e));
} finally {
  await restore();
}
console.log(`\n${passed}/${passed + failed} passed`);
process.exit(failed ? 1 : 0);
