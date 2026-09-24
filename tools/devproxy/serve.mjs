#!/usr/bin/env node
/*
 * DEVPROXY — run the OLD laksamana-office frontends against this API, locally.
 *
 *   node tools/devproxy/serve.mjs [--port 8080] [--laravel account,marketing]
 *
 * One origin (like team.laksamanamuda.id) that:
 *   - serves ../laksamana-office/deploy/ as static files (portal at /, modules at /<m>/)
 *   - routes /<m>-api-mysql/*  for modules listed in --laravel  -> this Laravel app
 *   - routes every other /<m>-api-mysql/* (and /stock-api-mysql/*.php) -> the OLD PHP
 *     backend staged from ../laksamana-office/<m>-mysql with a config.php that points
 *     at the LOCAL restored databases (tools/restore-dumps.sh)
 *
 * That is exactly the production cutover shape: "switch module X to Laravel" =
 * route /X-api-mysql/ to Laravel, nothing else changes.
 *
 * LOCAL ONLY. Old backends here WRITE to your local restored copies; re-run
 * tools/restore-dumps.sh to reset them.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_ROOT = path.resolve(HERE, '../..');
const OFFICE = path.resolve(API_ROOT, process.env.OFFICE_DIR || '../laksamana-office');
const PHP = process.env.PHP_BIN || 'php';
const arg = (k, d) => (process.argv.includes(k) ? process.argv[process.argv.indexOf(k) + 1] : d);
const PORT = parseInt(arg('--port', '8080'), 10);
const LARAVEL = new Set(arg('--laravel', 'account,marketing').split(',').filter(Boolean));
const LARAVEL_PORT = PORT + 1, OLD_PORT = PORT + 2;

// legacy folder -> [url prefix, local DB]
const LEGACY = {
  account: ['account-mysql', 'account-api-mysql', 'lakk5493_db_account'],
  marketing: ['marketing-mysql', 'marketing-api-mysql', 'lakk5493_db_marketing'],
  jadwal: ['jadwal-mysql', 'jadwal-api-mysql', 'lakk5493_db_jadwal'],
  dw: ['dw-mysql', 'dw-api-mysql', 'lakk5493_db_dw'],
  event: ['event-mysql', 'event-api-mysql', 'lakk5493_db_ems'],
  kompas: ['kompas-mysql', 'kompas-api-mysql', 'lakk5493_db_kompas'],
  finance: ['finance-mysql', 'finance-api-mysql', 'lakk5493_db_finance'],
  bd: ['bd-mysql', 'bd-api-mysql', 'lakk5493_db_bd'],
  konten: ['konten-mysql', 'konten-api-mysql', 'lakk5493_db_konten'],
  reservasi: ['reservasi-mysql', 'reservasi-api-mysql', 'lakk5493_db_reservasi'],
  stock: ['stock-mysql', 'stock-api-mysql', 'lakk5493_db_stock'],
  akademi: ['akademi-mysql', 'akademi-api-mysql', 'lakk5493_db_akademi'],
  hr: ['hr-mysql', 'hr-api-mysql', 'lakk5493_db_hr'],
  hlife: ['howandi-life-mysql', 'howandi-life-api-mysql', 'lakk5493_db_hlife'],
};
const prefixToKey = Object.fromEntries(Object.entries(LEGACY).map(([k, v]) => [v[1], k]));

// ---- stage old backends ------------------------------------------------------
const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'devproxy-'));
const oldRoot = path.join(scratch, 'old');
const self = `http://127.0.0.1:${PORT}`;
for (const [key, [dir, prefix, db]] of Object.entries(LEGACY)) {
  if (LARAVEL.has(key)) continue;
  const dst = path.join(oldRoot, prefix);
  fs.mkdirSync(dst, { recursive: true });
  fs.cpSync(path.join(OFFICE, dir), dst, { recursive: true });
  const dataDir = path.join(scratch, 'data', key);
  fs.mkdirSync(dataDir, { recursive: true });
  // cross-module URLs go back through THIS proxy, so they hit whichever side owns the module
  fs.writeFileSync(path.join(dst, 'config.php'), `<?php
define('DB_HOST','127.0.0.1'); define('DB_PORT','3306'); define('DB_NAME','${db}');
define('DB_USER','root'); define('DB_PASS',''); define('DB_CHARSET','utf8mb4');
define('ENV_LABEL','lokal'); define('API_TOKEN','');
define('DATA_DIR', ${JSON.stringify(dataDir)}); define('TRAINING_DIR', ${JSON.stringify(dataDir)});
define('ACCOUNT_API_URL','${self}/account-api-mysql/api.php');
define('JADWAL_API_URL','${self}/jadwal-api-mysql/api.php');
define('DW_API_URL','${self}/dw-api-mysql/api.php');
`);
  try { fs.rmSync(path.join(dst, 'config.local.php')); } catch {}
}

const procs = [];
const start = (cmd, argv, opts) => { const p = spawn(cmd, argv, { stdio: 'ignore', ...opts }); procs.push(p); };
start(PHP, ['-S', `127.0.0.1:${OLD_PORT}`, '-t', oldRoot], { cwd: oldRoot });
start(PHP, ['-S', `127.0.0.1:${LARAVEL_PORT}`, '-t', path.join(API_ROOT, 'public'),
  path.join(API_ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
  { cwd: path.join(API_ROOT, 'public'), env: { ...process.env, LAKSAMANA_ENV_LABEL: 'lokal' } });
const cleanup = () => { for (const p of procs) try { p.kill(); } catch {} try { fs.rmSync(scratch, { recursive: true, force: true }); } catch {} };
process.on('exit', cleanup);
process.on('SIGINT', () => process.exit(130));
process.on('SIGTERM', () => process.exit(143));

// ---- proxy + static ------------------------------------------------------------
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.svg': 'image/svg+xml', '.webmanifest': 'application/manifest+json', '.ico': 'image/x-icon' };

function forward(req, res, port) {
  const up = http.request({ host: '127.0.0.1', port, method: req.method, path: req.url,
    headers: { ...req.headers, host: `127.0.0.1:${PORT}` } }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
  up.on('error', (e) => { res.writeHead(502); res.end('devproxy upstream error: ' + e.message); });
  req.pipe(up);
}

const server = http.createServer((req, res) => {
  const u = new URL(req.url, self);
  const first = u.pathname.split('/')[1] || '';
  if (prefixToKey[first]) {
    const key = prefixToKey[first];
    res.setHeader('X-Devproxy-Backend', LARAVEL.has(key) ? 'laravel' : 'legacy-php');
    return forward(req, res, LARAVEL.has(key) ? LARAVEL_PORT : OLD_PORT);
  }
  if (first === 'api') return forward(req, res, LARAVEL_PORT); // /api/v1/*
  let p = path.join(OFFICE, 'deploy', decodeURIComponent(u.pathname));
  if (!p.startsWith(path.join(OFFICE, 'deploy'))) { res.writeHead(403); return res.end(); }
  if (fs.existsSync(p) && fs.statSync(p).isDirectory()) p = path.join(p, 'index.html');
  if (!fs.existsSync(p)) { res.writeHead(404); return res.end('not found'); }
  res.writeHead(200, { 'Content-Type': MIME[path.extname(p).toLowerCase()] || 'application/octet-stream', 'Cache-Control': 'no-store' });
  fs.createReadStream(p).pipe(res);
});
server.listen(PORT, '127.0.0.1', () => {
  console.log(`devproxy on ${self}  (Laravel: ${[...LARAVEL].join(', ')}; everything else: legacy PHP)`);
  console.log(`  portal:    ${self}/`);
  console.log(`  marketing: ${self}/marketing/`);
});
