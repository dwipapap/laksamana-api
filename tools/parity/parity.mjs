#!/usr/bin/env node
/*
 * PARITY — old PHP backend vs this Laravel API, same data, same requests.
 *
 *   node tools/parity/parity.mjs <module> [--case <substring>] [--keep] [--core [--importer <key,...>]]
 *
 * For each module described in tools/parity/cases/<module>.json it:
 *   1. clones the module's LOCAL database twice (parity_old_<db>, parity_new_<db>)
 *      so write cases can run without touching the restored copy, and so both
 *      sides start from identical data;
 *   2. copies the legacy backend folder from ../laksamana-office/<dir> into a
 *      scratch dir, writes a config.php pointing at parity_old_<db>, and serves
 *      it with `php -S`;
 *   3. starts `php artisan serve` with DB_<ENV>_DATABASE=parity_new_<db>;
 *   4. replays every case against both, deep-diffs the JSON (minus `ignore`
 *      paths) and exits non-zero on any difference.
 *
 * --core (the cutover gate, ADR-0002/0004): additionally creates and migrates a
 * scratch core database (parity_core), runs `core:import <key>` for every
 * registered importer (or only those named by --importer a,b) from the
 * parity_new_* clones into it, and serves Laravel with DB_DATABASE=parity_core
 * and DB_<KEY>_CONNECTION=core for each imported key, so every cut-over module
 * answers from core (`jadwal --core` runs jadwal with identity on core).
 * A key that is not a module (`dummy`, the C1 proof) switches nothing.
 *
 * LOCAL ONLY: refuses to run unless MYSQL_HOST is 127.0.0.1/localhost.
 *
 * Case file shape:
 * {
 *   "dir": "account-mysql",            legacy folder in laksamana-office
 *   "legacyPath": "account-api-mysql",  URL prefix on the Laravel side
 *   "dbEnv": "ACCOUNT", "db": "lakk5493_db_account",
 *   "extraDbs": [{"dbEnv":"JADWAL","db":"lakk5493_db_jadwal"}],   optional (cloned for both sides)
 *   "legacyDefines": {"XENDIT_MOCK": true},   optional: extra constants in the legacy config.php
 *   "laravelEnv": {"XENDIT_MOCK": "true"},    optional: extra env for the Laravel side
 *   "setupSql": ["CREATE TABLE ..."],   optional: run on both clones of the module DB before replaying
 *   "extraLegacy": [{"dir":"dw-mysql","legacyPath":"dw-api-mysql","db":"lakk5493_db_dw","root":"acc|old"}],
 *   "ignore": ["data.ts", "data.backend"],   global ignored paths ("*" = any key)
 *   "cases": [
 *     {"name": "...", "file": "api.php", "method": "GET|POST",
 *      "query": {...}, "body": {...}, "ignore": [...],
 *      "headers": {...}, "rawBody": "...", "rawBodySize": N, "manualRedirect": true,   optional
 *      "each": "SELECT id FROM users"   optional: repeat, substituting "$each" / "$each1","$each2" (split on |) }
 *   ],
 *   "sessions": {"hrd": "SELECT CONCAT(name,'|',pin) FROM users WHERE ..."}
 *      logs in on BOTH sides first; use "$sesi:hrd" anywhere in query/body
 * }
 */
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_ROOT = path.resolve(HERE, '../..');
const OFFICE = path.resolve(API_ROOT, process.env.OFFICE_DIR || '../laksamana-office');
const MYSQL = process.env.MYSQL_BIN || 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
const MYSQLDUMP = process.env.MYSQLDUMP_BIN || MYSQL.replace(/mysql(\.exe)?$/, 'mysqldump$1');
const PHP = process.env.PHP_BIN || 'php';
const HOST = process.env.MYSQL_HOST || '127.0.0.1';
// Parallel runs (several agents at once) need distinct ports and DB names.
const BASE = parseInt(process.env.PARITY_PORT_BASE || '8900', 10);
const ACC_PORT = BASE, OLD_PORT = BASE + 1, NEW_PORT = BASE + 2, JADWAL_PORT = BASE + 3;
const TAG = (process.env.PARITY_TAG || '').replace(/[^a-z0-9]/gi, '');
const P = TAG ? `parity_${TAG}` : 'parity';

if (!['127.0.0.1', 'localhost'].includes(HOST)) { console.error('REFUSING: non-local MYSQL_HOST'); process.exit(2); }

const args = process.argv.slice(2);
const mod = args[0];
if (!mod) { console.error('usage: parity.mjs <module> [--case x] [--keep] [--core [--importer key,...]]'); process.exit(2); }
const only = args.includes('--case') ? args[args.indexOf('--case') + 1] : null;
const keep = args.includes('--keep');
const core = args.includes('--core');
const importerArg = args.includes('--importer') ? args[args.indexOf('--importer') + 1] : null;
const spec = JSON.parse(fs.readFileSync(path.join(HERE, 'cases', `${mod}.json`), 'utf8'));

const sql = (q, db) => execFileSync(MYSQL, ['-uroot', `-h${HOST}`, '-N', '-B', ...(db ? [db] : []), '-e', q], { encoding: 'utf8' });
function cloneDb(src, dst) {
  sql(`DROP DATABASE IF EXISTS \`${dst}\`; CREATE DATABASE \`${dst}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`);
  const dump = execFileSync(MYSQLDUMP, ['-uroot', `-h${HOST}`, '--single-transaction', '--routines', '--no-tablespaces', src], { maxBuffer: 1 << 30 });
  execFileSync(MYSQL, ['-uroot', `-h${HOST}`, '--max_allowed_packet=256M', dst], { input: dump, maxBuffer: 1 << 30 });
}

const dbs = [{ dbEnv: spec.dbEnv, db: spec.db }, ...(spec.extraDbs || [])];
// account is always needed by session-gated modules (whoami)
if (!dbs.find(d => d.dbEnv === 'ACCOUNT')) dbs.push({ dbEnv: 'ACCOUNT', db: 'lakk5493_db_account' });
// ...and account asks jadwal for division heads (headIds)
if (!dbs.find(d => d.dbEnv === 'JADWAL')) dbs.push({ dbEnv: 'JADWAL', db: 'lakk5493_db_jadwal' });
for (const d of dbs) { cloneDb(d.db, `${P}_old_${d.db}`); cloneDb(d.db, `${P}_new_${d.db}`); }
// Optional statements run on BOTH clones of the module DB (local copies only), e.g. to
// create tables schema.sql declares but the restored dump lacks, so the legacy code path runs.
for (const q of spec.setupSql || []) for (const side of ["old", "new"]) sql(q, `${P}_${side}_${spec.db}`);

// ---- legacy side -----------------------------------------------------------
const scratch = fs.mkdtempSync(path.join(os.tmpdir(), `parity-${mod}-`));
function legacyConfig(dbName, dataDir) {
  const acc = `http://127.0.0.1:${ACC_PORT}/account-api-mysql/api.php`;
  return `<?php
define('DB_HOST','${HOST}'); define('DB_PORT','3306'); define('DB_NAME','${dbName}');
define('DB_USER','root'); define('DB_PASS',''); define('DB_CHARSET','utf8mb4');
define('ENV_LABEL','lokal'); define('API_TOKEN','');
define('DATA_DIR', ${JSON.stringify(dataDir)}); define('TRAINING_DIR', ${JSON.stringify(dataDir)});
define('ACCOUNT_API_URL','${acc}');
define('JADWAL_API_URL','http://127.0.0.1:${JADWAL_PORT}/jadwal-api-mysql/api.php');
define('DW_API_URL','http://127.0.0.1:${ACC_PORT}/dw-api-mysql/api.php');
${Object.entries(spec.legacyDefines || {}).map(([k, v]) => `define('${k}', ${JSON.stringify(v)});
`).join('')}`;
}
function stageLegacy(dir, urlPrefix, dbName, docroot) {
  const dst = path.join(docroot, urlPrefix);
  fs.mkdirSync(dst, { recursive: true });
  fs.cpSync(path.join(OFFICE, dir), dst, { recursive: true });
  const dataDir = path.join(scratch, 'data-old', urlPrefix.replace(/\//g, '_'));
  fs.mkdirSync(dataDir, { recursive: true });
  fs.writeFileSync(path.join(dst, 'config.php'), legacyConfig(dbName, dataDir));
  try { fs.rmSync(path.join(dst, 'config.local.php')); } catch {}
}
const oldRoot = path.join(scratch, 'old'), accRoot = path.join(scratch, 'acc'), jadwalRoot = path.join(scratch, 'jadwal');
stageLegacy(spec.dir, spec.legacyPath, `${P}_old_${spec.db}`, oldRoot);
stageLegacy('account-mysql', 'account-api-mysql', `${P}_old_lakk5493_db_account`, accRoot);
// jadwal gets its OWN server (JADWAL_PORT): the legacy account lib resolves the
// heads over JADWAL_API_URL while answering a request, and a single-threaded
// `php -S` cannot serve that nested call on the port it is already busy with.
stageLegacy('jadwal-mysql', 'jadwal-api-mysql', `${P}_old_lakk5493_db_jadwal`, jadwalRoot);
// Other old backends this one calls over HTTP. root "acc" (default) = the helper server that
// ACCOUNT/JADWAL/DW_API_URL point at; root "old" = same host as the module (SERVER_NAME-derived URLs).
for (const x of spec.extraLegacy || []) {
  stageLegacy(x.dir, x.legacyPath, `${P}_old_${x.db}`, x.root === 'old' ? oldRoot : accRoot);
}

const procs = [];
function start(cmd, argv, opts) {
  const p = spawn(cmd, argv, { stdio: ['ignore', 'ignore', 'pipe'], ...opts });
  procs.push(p);
  return p;
}
start(PHP, ['-S', `127.0.0.1:${OLD_PORT}`, '-t', oldRoot], { cwd: oldRoot });
start(PHP, ['-S', `127.0.0.1:${ACC_PORT}`, '-t', accRoot], { cwd: accRoot });
start(PHP, ['-S', `127.0.0.1:${JADWAL_PORT}`, '-t', jadwalRoot], { cwd: jadwalRoot });

// ---- laravel side ----------------------------------------------------------
const env = { ...process.env, LAKSAMANA_ENV_LABEL: 'lokal', CACHE_STORE: 'array', ...(spec.laravelEnv || {}) };
for (const d of dbs) env[`DB_${d.dbEnv}_DATABASE`] = `${P}_new_${d.db}`;
const CORE_DB = `${P}_core`;

const cleanup = () => {
  for (const p of procs) try { p.kill(); } catch {}
  if (!keep) {
    try { fs.rmSync(scratch, { recursive: true, force: true }); } catch {}
    for (const d of dbs) try { sql(`DROP DATABASE IF EXISTS \`${P}_old_${d.db}\`; DROP DATABASE IF EXISTS \`${P}_new_${d.db}\`;`); } catch {}
    if (core) try { sql(`DROP DATABASE IF EXISTS \`${CORE_DB}\`;`); } catch {}
  }
};
process.on('exit', cleanup);
process.on('SIGINT', () => process.exit(130));

if (core) {
  sql(`DROP DATABASE IF EXISTS \`${CORE_DB}\`; CREATE DATABASE \`${CORE_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`);
  env.DB_DATABASE = CORE_DB;
  const artisan = argv => {
    try { execFileSync(PHP, ['artisan', ...argv], { cwd: API_ROOT, env, stdio: 'inherit' }); }
    catch { console.error(`core mode: php artisan ${argv.join(' ')} failed`); process.exit(2); }
  };
  artisan(['migrate', '--database=core', '--force']);
  const keys = importerArg ? importerArg.split(',')
    : execFileSync(PHP, ['artisan', 'core:import', '--list'], { cwd: API_ROOT, env, encoding: 'utf8' }).split(/\r?\n/).filter(Boolean);
  for (const key of keys) {
    artisan(['core:import', key]);
    env[`DB_${key.toUpperCase()}_CONNECTION`] = 'core';
  }
}
const newData = path.join(scratch, 'data-new');
for (const [k] of Object.entries({ AKADEMI: 1, EVENT: 1, KOMPAS: 1, KONTEN: 1, MARKETING: 1, RESERVASI: 1, STOCK: 1 })) {
  env[`${k}_DATA_DIR`] = path.join(newData, k.toLowerCase()); fs.mkdirSync(env[`${k}_DATA_DIR`], { recursive: true });
}
start(PHP, ['-S', `127.0.0.1:${NEW_PORT}`, '-t', path.join(API_ROOT, 'public'), path.join(API_ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
  { cwd: path.join(API_ROOT, 'public'), env });

async function waitUp(url) {
  for (let i = 0; i < 60; i++) {
    try { await fetch(url); return; } catch { await new Promise(r => setTimeout(r, 250)); }
  }
  throw new Error('server did not start: ' + url);
}

// ---- comparison -------------------------------------------------------------
function matches(p, pattern) {
  const a = p.split('.'), b = pattern.split('.');
  if (b[b.length - 1] === '**') return b.slice(0, -1).every((x, i) => x === '*' || x === a[i]);
  return a.length === b.length && a.every((x, i) => b[i] === '*' || b[i] === x);
}
// Paths whose array ORDER is unspecified in legacy (SELECT without ORDER BY):
// compared as multisets. Use sparingly — only where legacy SQL has no ORDER BY.
let UNORDERED = [];
const canon = v => JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x)) ? Object.fromEntries(Object.entries(x).sort()) : x);
function diff(a, b, ignore, p = '', out = []) {
  if (p && ignore.some(ig => matches(p.slice(1), ig))) return out;
  if (a === b) return out;
  if (p && Array.isArray(a) && Array.isArray(b) && UNORDERED.some(u => matches(p.slice(1), u))) {
    // sort on each element WITHOUT its ignored (volatile) fields, or random ids decide the order
    const strip = (v, at) => (v && typeof v === 'object')
      ? Object.fromEntries(Object.entries(v).filter(([k]) => !ignore.some(ig => matches(`${at}.${k}`, ig))).map(([k, x]) => [k, strip(x, `${at}.${k}`)]))
      : v;
    const key = x => canon(strip(x, `${p.slice(1)}.0`));
    a = [...a].sort((x, y) => key(x) < key(y) ? -1 : 1);
    b = [...b].sort((x, y) => key(x) < key(y) ? -1 : 1);
  }
  const ta = Array.isArray(a) ? 'array' : typeof a, tb = Array.isArray(b) ? 'array' : typeof b;
  if (ta !== tb || a === null || b === null || ta !== 'object' && ta !== 'array') {
    // numeric-string vs number counts as a difference only if values differ
    if ((ta === 'number' || tb === 'number') && String(a) === String(b)) return out;
    out.push(`${p || '(root)'}: old=${JSON.stringify(a)?.slice(0, 160)} new=${JSON.stringify(b)?.slice(0, 160)}`);
    return out;
  }
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  for (const k of keys) diff(a[k], b[k], ignore, `${p}.${k}`, out);
  return out;
}

// Office session tokens per side, from spec.sessions {label: "SQL -> name|pin"}; used as "$sesi:<label>".
const TOKENS = { old: {}, new: {} };
async function loginBoth() {
  for (const [label, q] of Object.entries(spec.sessions || {})) {
    const [name, pin] = sql(q, `${P}_old_lakk5493_db_account`).trim().split(/\r?\n/)[0].split('|');
    for (const [side, port] of [['old', ACC_PORT], ['new', NEW_PORT]]) {
      const r = await fetch(`http://127.0.0.1:${port}/account-api-mysql/api.php`, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ action: 'login', name, pin }) });
      const j = await r.json();
      if (!j.ok || !j.user.token) throw new Error(`login ${label} failed on ${side}: ${JSON.stringify(j)}`);
      TOKENS[side][label] = j.user.token;
    }
  }
}

async function call(port, prefix, c, each, side) {
  const [e1, e2] = String(each ?? '').split('|');
  const sub = v => JSON.parse(JSON.stringify(v ?? {}).replaceAll('$each1', e1 ?? '').replaceAll('$each2', e2 ?? '').replaceAll('$each', each ?? '')
    .replace(/\$sesi:([A-Za-z0-9_]+)/g, (_, l) => TOKENS[side][l] ?? ''));
  const q = new URLSearchParams(sub(c.query)).toString();
  const url = `http://127.0.0.1:${port}/${prefix}/${c.file || spec.file || 'api.php'}${q ? '?' + q : ''}`;
  const init = (c.method || 'POST') === 'GET' ? { headers: { ...(c.headers || {}) } }
    : { method: 'POST', headers: { 'Content-Type': 'text/plain', ...(c.headers || {}) }, body: c.rawBodySize ? "x".repeat(c.rawBodySize) : c.rawBody ?? JSON.stringify(sub(c.body)) };
  if (c.manualRedirect) init.redirect = 'manual';
  const r = await fetch(url, init);
  const text = await r.text();
  let json; try { json = JSON.parse(text); } catch { json = { __nonJson: text.slice(0, 300) }; }
  return { status: r.status, json };
}

(async () => {
  await waitUp(`http://127.0.0.1:${OLD_PORT}/`);
  await waitUp(`http://127.0.0.1:${ACC_PORT}/`);
  await waitUp(`http://127.0.0.1:${JADWAL_PORT}/`);
  await waitUp(`http://127.0.0.1:${NEW_PORT}/up`);
  await loginBoth();
  let fails = 0, runs = 0;
  for (const c of spec.cases) {
    if (only && !c.name.includes(only)) continue;
    const eachVals = c.each ? sql(c.each, `${P}_old_${spec.db}`).trim().split(/\r?\n/).filter(Boolean) : [null];
    for (const e of eachVals) {
      runs++;
      const [o, n] = [await call(OLD_PORT, spec.legacyPath, c, e, 'old'), await call(NEW_PORT, spec.legacyPath, c, e, 'new')];
      const ignore = [...(spec.ignore || []), ...(c.ignore || [])];
      UNORDERED = [...(spec.unordered || []), ...(c.unordered || [])];
      const d = diff(o.json, n.json, ignore);
      if (o.status !== n.status) d.unshift(`HTTP status old=${o.status} new=${n.status}`);
      const label = c.name + (e ? ` [${e}]` : '');
      if (d.length) { fails++; console.log(`FAIL ${label}\n   ` + d.slice(0, 12).join('\n   ')); }
      else if (!c.each) console.log(`ok   ${label}`);
    }
    if (c.each) console.log(`${eachVals.length} run(s) of "${c.name}" done`);
  }
  console.log(`\n${runs - fails}/${runs} identical` + (fails ? `, ${fails} DIFFERENT` : ''));
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
