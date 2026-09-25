#!/usr/bin/env node
/*
 * E2E — the REAL old Usage Panel (laksamana-office/deploy/stock/usage/index.html:
 * Daily SO, Pemakaian Bahan, Waste Produk, Serah Terima) running headless (jsdom)
 * against Laravel, through tools/devproxy/serve.mjs.
 *
 *   node tools/e2e/stock-usage.mjs [--port 8187] [--user u-adit]
 *
 * Only /stock-api-mysql + /account-api-mysql go to Laravel. Team scoping is ON
 * (STOCK_BATAS_PER_TIM=1): the default user is Kitchen crew, so every list the
 * page loads must hold Kitchen rows only (the ?sesi= token is resolved in-process).
 *
 *   1. SSO boot -> the four lists load, team-scoped
 *   2. Pemakaian: simpan -> row; ubahStatus -> status; hapus -> gone
 *   3. Waste: simpan with a photo -> row; edit without a photo keeps it;
 *      bukaFoto shows it; hapus -> gone
 *   4. Serah terima: simpan (photo required) -> row; edit saves but the page
 *      shows the legacy 500 (#113, kept on purpose); hapus -> gone
 *   5. Daily SO: closing typed -> soSimpan -> opname row; soHapus -> gone
 *   6. every row the walkthrough did not touch is unchanged (decoded JSON)
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
const PORT = parseInt(arg('--port', '8187'), 10);
const USER = arg('--user', 'u-adit');
const BASE = `http://127.0.0.1:${PORT}`;
const DB = 'lakk5493_db_stock';
const TAG = 'e2e' + Date.now().toString(36);

const FOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
const TABLES = ['usage_events', 'waste', 'serah_terima', 'opname'];
const snapshot = execFileSync(MYSQLDUMP, ['-uroot', '-h127.0.0.1', '--single-transaction', '--no-tablespaces', DB, ...TABLES], { maxBuffer: 1 << 30 });
// rows as {id: decoded row} (JSON `data` decoded, photos as their MD5)
const rowsOf = (t) => {
  const cols = t === 'waste' || t === 'serah_terima' ? "id, JSON_OBJECT('r', CONCAT_WS('|', tanggal, tim, pic, waktu, MD5(foto), foto_nama)), data" : "id, JSON_OBJECT('r', CONCAT_WS('|', tanggal, tim, pic, waktu, status)), data";
  const out = {};
  for (const line of sql(`SELECT ${cols} FROM \`${t}\` ORDER BY id`).split(/\r?\n/).filter(Boolean)) {
    const [id, r, d] = line.split('\t');
    let dd; try { dd = JSON.parse(d); } catch { dd = d; }
    out[id] = JSON.stringify([JSON.parse(r), dd]);
  }
  return out;
};
const before = Object.fromEntries(TABLES.map((t) => [t, rowsOf(t)]));

const proxy = spawn(process.execPath,
  [path.join(API_ROOT, 'tools/devproxy/serve.mjs'), '--port', String(PORT), '--laravel', 'account,stock'],
  { stdio: 'ignore', env: { ...process.env, STOCK_BATAS_PER_TIM: '1' } });
const tabs = [];
let restored = false;
const restore = async () => {
  if (restored) return;
  restored = true;
  for (const t of tabs) try { t.close(); } catch {}
  await sleep(2000); // let in-flight requests land before the snapshot goes back
  try { proxy.kill(); } catch {}
  execFileSync(MYSQL, ['-uroot', '-h127.0.0.1', DB], { input: snapshot, maxBuffer: 1 << 30 });
};

try {
  await until(async () => (await fetch(`${BASE}/stock-api-mysql/usage.php?action=ping`)).ok, 'devproxy');
  const hdr = (await fetch(`${BASE}/stock-api-mysql/usage.php?action=ping`)).headers.get('x-devproxy-backend');
  check('stock API served by Laravel', hdr === 'laravel', String(hdr));
  const bdHdr = (await fetch(`${BASE}/bd-api-mysql/api.php?action=ping`)).headers.get('x-devproxy-backend');
  check('other modules stay on legacy PHP (bd)', bdHdr === 'legacy-php', String(bdHdr));

  const [name, pin] = sql(`SELECT CONCAT(name,'|',pin) FROM users WHERE id='${USER}'`, 'lakk5493_db_account').split('|');
  const login = await (await fetch(`${BASE}/account-api-mysql/api.php`, {
    method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify({ action: 'login', name, pin }),
  })).json();
  const u = login.user || {};
  check('Office login via Laravel, user holds usage', login.ok && (u.modules || []).includes('usage'), JSON.stringify(u.modules));
  const tim = (u.keterangan || '').match(/Kitchen|Bar|Floor/i)?.[0] || '';
  const lmSession = JSON.stringify({ userId: u.id, name: u.name, token: u.token || '', keterangan: u.keterangan || '',
    modules: u.modules || [], adminModules: u.adminModules || [], issuedAt: Date.now(), expiry: Date.now() + 24 * 3600e3 });

  // jsdom does not fetch <script src>: inline the shared catat-common.js, drop the Tailwind CDN.
  const common = await (await fetch(`${BASE}/stock/catat-common.js`)).text();
  const html = (await (await fetch(`${BASE}/stock/usage/`)).text())
    .replace('<script src="https://cdn.tailwindcss.com"></script>', '')
    .replace('<script src="../catat-common.js"></script>', () => `<script>${common}</script>`);
  const pageErrors = [];
  const seen = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/Not implemented/.test(String(e.message))) pageErrors.push(String(e.message).slice(0, 200)); });
  vc.on('error', (...a) => pageErrors.push('console.error ' + a.map(String).join(' ').slice(0, 200)));
  const dom = new JSDOM(html, {
    url: `${BASE}/stock/usage/`, runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole: vc,
    beforeParse(w) {
      w.localStorage.setItem('lm_session', lmSession);
      w.tailwind = { config: {} };
      w.fetch = async (input, init = {}) => {
        const { signal, ...rest } = init;
        const r = await fetch(new URL(String(input), w.location.href), rest);
        seen.push(new URL(String(input), w.location.href).pathname);
        return r;
      };
      w.confirm = () => true;
      w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
      w.scrollTo = () => {};
      w.HTMLElement.prototype.scrollIntoView = () => {};
      for (const k of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver'])
        if (!w[k]) w[k] = class { observe() {} unobserve() {} disconnect() {} };
    },
  });
  tabs.push(dom.window);
  const w = dom.window;
  const ev = (js) => w.eval(js);
  const toasts = () => [...w.document.querySelectorAll('#toast-wrap > *')].map((e) => e.textContent.replace(/\s+/g, ' ').trim());

  // 1. boot: the four lists load (serah.php is the last one fetched)
  await until(() => seen.includes('/stock-api-mysql/serah.php') && ev('Array.isArray(SRH_DATA)'), 'page boot')
    .catch((e) => { throw new Error(`${e.message}; fetched ${seen.join(', ')}; errors ${pageErrors.join(' | ')}`); });
  await sleep(500);
  const TGL = ev('hariIni()'); // today: the report lists default to the current period
  check('page booted with the Office session', ev('!!SESI && SESI.name') === u.name);
  const tims = ev('JSON.stringify([...DATA, ...DATA_WASTE, ...SRH_DATA].map(r => r.tim))');
  check(`lists are team-scoped to ${tim}`, JSON.parse(tims).every((t) => t === tim), tims.slice(0, 200));

  // 2. Pemakaian
  ev(`document.getElementById('f-tanggal').value='${TGL}'; document.getElementById('f-nama').value='${TAG}';
      document.getElementById('br-item-1').value='Gula'; document.getElementById('br-qty-1').value='2';`);
  await ev(`simpan('Selesai')`);
  const use = sql(`SELECT CONCAT_WS('|', id, status, pic, tim, tanggal) FROM usage_events WHERE nama_event='${TAG}'`).split('|');
  check('Pemakaian saved (status, pic from the session, team)', use[1] === 'Selesai' && use[2] === u.name && use[3] === tim && use[4] === TGL, use.join('|'));
  await ev(`ubahStatus('${use[0]}', 'Rencana')`);
  check('Pemakaian status changed', sql(`SELECT status FROM usage_events WHERE id='${use[0]}'`) === 'Rencana');

  // 3. Waste
  ev(`buka('waste','catat'); document.getElementById('w-tanggal').value='${TGL}'; document.getElementById('w-item').value='Roti ${TAG}';
      perbaruiSatuanWaste(); document.getElementById('w-qty').value='2'; FOTO_WASTE='${FOTO}';`);
  await ev('simpanWaste()');
  const wid = sql(`SELECT id FROM waste WHERE item='Roti ${TAG}'`);
  check('Waste saved with its photo', !!wid && sql(`SELECT foto FROM waste WHERE id='${wid}'`) === FOTO, wid);
  check('Waste list carries no photo, only adaFoto', ev(`JSON.stringify(DATA_WASTE.find(r => r.id==='${wid}'))`)?.includes('"adaFoto":true')
    && !ev(`'foto' in (DATA_WASTE.find(r => r.id==='${wid}') || {})`));
  ev(`editWaste('${wid}'); document.getElementById('w-qty').value='3';`);
  await ev('simpanWaste()');
  check('Waste edit without a photo keeps it', sql(`SELECT CONCAT(qty,'|',foto='${FOTO}') FROM waste WHERE id='${wid}'`) === '3|1');
  await ev(`bukaFoto('waste.php', '${wid}', 'x')`);
  check('Waste photo opens alone (?action=foto)', ev(`document.querySelector('img[alt="x"]')?.getAttribute('src')`) === FOTO);
  ev('tutupFoto()');
  await ev(`hapusWaste('${wid}', 'x')`);
  check('Waste deleted', sql(`SELECT COUNT(*) FROM waste WHERE id='${wid}'`) === '0');

  // 4. Serah terima
  ev(`buka('serah','catat'); resetSerah(); document.getElementById('sr-tanggal').value='${TGL}';
      document.getElementById('sr-penerima').value='${TAG}';
      const rid = document.querySelector('#sr-baris [data-sr-row]').getAttribute('data-sr-row');
      document.getElementById('sr-item-' + rid).value='Gula'; document.getElementById('sr-qty-' + rid).value='2';`);
  await ev('srhSimpan()');
  check('Serah terima without a photo refused by the page', sql(`SELECT COUNT(*) FROM serah_terima WHERE penerima='${TAG}'`) === '0');
  ev(`FOTO_SERAH='${FOTO}'`);
  await ev('srhSimpan()');
  const sid = sql(`SELECT id FROM serah_terima WHERE penerima='${TAG}'`);
  check('Serah terima saved with its photo', !!sid && sql(`SELECT foto FROM serah_terima WHERE id='${sid}'`) === FOTO, sid);
  ev(`editSerah('${sid}'); document.getElementById('sr-penerima').value='${TAG} edit';`);
  await ev('srhSimpan()');
  check('Serah terima edit is saved, photo kept', sql(`SELECT CONCAT(penerima,'|',foto='${FOTO}') FROM serah_terima WHERE id='${sid}'`) === `${TAG} edit|1`);
  check('…and the page shows the legacy 500 as a failure (#113)', toasts().some((t) => t.includes('Gagal terhubung')), toasts().join(' / '));
  await ev(`hapusSerah('${sid}')`);
  check('Serah terima deleted', sql(`SELECT COUNT(*) FROM serah_terima WHERE id='${sid}'`) === '0');

  // 5. Daily SO
  ev(`buka('opname'); document.getElementById('so-tanggal').value='${TGL}'`);
  await ev('soMuatHari()');
  ev(`SO_ISI['Gula ${TAG}'] = '5'`);
  await ev('soSimpan()');
  const op = sql(`SELECT CONCAT_WS('|', id, tim, status, pic) FROM opname WHERE tanggal='${TGL}' AND data LIKE '%${TAG}%'`).split('|');
  const opItems = JSON.parse(sql(`SELECT data FROM opname WHERE id='${op[0]}'`) || '{}').items || [];
  check('Daily SO saved (fisik stored, selisih not)', op[1] === tim && op[2] === 'Selesai' && op[3] === u.name
    && opItems.some((i) => i.item === `Gula ${TAG}` && i.fisik === 5 && !('selisih' in i)), JSON.stringify(opItems).slice(0, 200));
  await ev(`soHapus('${op[0]}', '${TGL}')`);
  check('Daily SO deleted', sql(`SELECT COUNT(*) FROM opname WHERE id='${op[0]}'`) === '0');

  await ev(`buka('pemakaian','report'); hapus('${use[0]}', 'x')`);
  check('Pemakaian deleted', sql(`SELECT COUNT(*) FROM usage_events WHERE id='${use[0]}'`) === '0');

  // 6. nothing else moved
  for (const t of TABLES) {
    const now = rowsOf(t);
    const changed = Object.keys({ ...before[t], ...now }).filter((id) => before[t][id] !== now[id]);
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
