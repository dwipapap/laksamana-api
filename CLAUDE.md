# Laksamana API — working notes for Claude

One Laravel 12 backend replacing the 16 small PHP backends of `../laksamana-office`
(`<modul>-mysql/api.php` + `lib_*.php`). It reuses the EXISTING MySQL databases
(cPanel/Rumahweb, MariaDB 10.11) and serves two surfaces:

1. **`/api/v1/...`** — clean REST for new apps. Sanctum Bearer tokens, one envelope.
2. **Legacy compat** — the old URLs (`/<modul>-api-mysql/api.php`, `/stock-api-mysql/<file>.php`,
   `/absensi/api/api.php`, `/ticketing-api/api.php`) with byte-compatible behaviour, so the
   existing `deploy/*` frontends switch by changing only their API base URL.

Working language for code comments: English. The legacy code is Indonesian; keep Indonesian
error strings/codes EXACTLY where frontends depend on them.

---

## 0. ABSOLUTE RULE — never touch live data

Same rule as laksamana-office CLAUDE.md §0. Claude never deletes, overwrites, migrates or
runs DDL against the production or dev servers. All work runs against **local copies**:

```bash
tools/restore-dumps.sh            # ../db-backup/*.sql.gz -> local MySQL (127.0.0.1), prod naming
tools/restore-dumps.sh prod stock # just one module
```

Local MySQL: Laragon 8.4 (`C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld --datadir=C:/laragon/data/mysql-8.4`),
root without password. PHP: `C:\Users\dwip\.config\herd-lite\bin\php.exe` (8.4) — prepend it to PATH.

Writing CODE that deletes is fine; executing deletes happens only on local copies.
Laravel migrations run ONLY on the `core` connection. Never create a migration for a legacy table.

---

## 1. Layout

```
app/Support/            shared kernel
  Modules.php           registry: Modules::db('stock'), ::connectionName, ::databaseName, ::dataDir
  JsonDoc.php           the `data` LONGTEXT JSON — decode keeping {} as OBJECT (never assoc) + Eloquent cast
  NamedLock.php         GET_LOCK('<dbname>:<name>') — same lock names as legacy, so old & new serialise together
  Legacy/               LegacyController (base), LegacyRequest (text/plain JSON body), Envelope,
                        Sesi (port of lib_sesi.php), LegacyCors
  Api/ApiResponse.php   v1 envelope: {data, meta} / {error:{code,message,details}}
app/Auth/               AccountUser (legacy users table), OfficeAccess (module truth table),
                        LegacySessions (old 64-hex tokens), CorePersonalAccessToken, Middleware/RequireModule
app/Modules/<Name>/
  Services/             ALL business rules. Legacy and v1 controllers both call these.
  Models/               (optional) Eloquent models; connection via Modules::connectionName('<key>')
  Http/Legacy/          <Name>LegacyController extends App\Support\Legacy\LegacyController
  Http/V1/              REST controllers
  routes/legacy.php     old URL(s)    — auto-loaded, `legacy` middleware group
  routes/v1.php         /api/v1/...   — auto-loaded, `api` middleware group
config/laksamana.php    module registry (connection, db name, data_dir, legacy path)
tests/Feature/<Name>/   Pest tests
tools/parity/           old PHP vs Laravel diff runner + cases/<module>.json
```

Module keys (config/laksamana.php): account absensi akademi bd dw event ticketing finance
hlife hr jadwal kompas konten marketing reservasi stock. event+ticketing share `lakk5493_db_ems`.

## 2. Porting checklist (per module)

1. Read the legacy `api.php` + `lib_*.php` COMPLETELY (they are the spec; comments explain
   incidents — keep those rules). Read the live schema from the restored local DB
   (`SHOW CREATE TABLE`), NOT the repo `schema.sql` (it is stale for many modules).
2. `Services/*` — port every action. Keep behaviour identical: validation order, error
   codes/messages, what is written, locks, transactions, append-only / no-DELETE rules,
   optimistic concurrency (baseTs, _ver, baseUpdatedAt/_sejak/sidik, _rev…).
   Use `Modules::db('<key>')` + parameterised SQL (EMULATE_PREPARES=false — each `?`/name once).
   Do NOT port runtime DDL (`*_pastikan`, ALTER): tables already exist live. If code must
   tolerate a missing optional column, check `information_schema` read-only and degrade.
3. `Http/Legacy/<Name>LegacyController` — `match ($req->action)` → service → exact legacy
   envelope (Envelope::okData / error / flat / statusError / raw streams). Keep open
   endpoints open (ping, stats, cross-module reads like shiftHari, headIds, jadwalDW, eventsHari,
   dpMasuk, designReqs, listDivisiRoster, listModuleRoster, probeTulis).
   Session-gated actions: `app(Sesi::class)->user($req)` / `requireModule(...)` and the
   `Sesi::reject*()` helpers (exact `sesi_tidak_sah:` / `tanpa_modul:` / `tidak_berhak:` messages).
   The acting user's NAME always comes from the session, never from the request body.
4. Cross-module HTTP calls in legacy code become direct service calls
   (e.g. headIds → App\Modules\Jadwal\Services\HeadDirectory, whoami → OfficeAccess).
5. `Http/V1` — granular REST (no whole-state saveAll). `auth:sanctum` + `module:<key>` /
   `module:<key>,admin` middleware. Responses via ApiResponse. Expose concurrency explicitly
   (ETag/If-Match or a `version` field) using the module's existing version column.
   **v1 must be FEATURE-COMPLETE per module.** It is the long-term contract for the old
   laksamana-office (which may migrate onto it), the future **laksamana-office-vue** (Vue + Nuxt UI,
   same DBs and same menus), and any other app. Derive the feature list from the module's
   frontend menus (`NAV_DEF`/`TITLES` in `deploy/<m>/index.html`) and document the contract in
   `docs/api/<module>.md`.
6. `tests/Feature/<Name>/` — Pest. The base TestCase wraps EVERY connection in a transaction,
   so tests may write. Use real rows from the restored DB. One HTTP request per auth identity
   per test (guards cache the user within a test).
7. `tools/parity/cases/<module>.json` — reads for every action + the important write paths.
   `node tools/parity/parity.mjs <module>` must print `N/N identical`. Ignore only
   volatile fields (ts, backend, generated ids/tokens) — never ignore real behaviour.
8. **Milestone.** Finishing a module = commit + annotated tag (`git tag -a m<N>-<name>`) + a row in
   `docs/MILESTONES.md` + status tables in `docs/modules/README.md` and §5 below.

## 3. Files on disk

Legacy data dirs (`/home/lakk5493/<x>-db`) are configured per module via `<KEY>_DATA_DIR`
env and `Modules::dataDir('<key>')`. Keep key formats (`rc_<hex>.<ext>`, `ev_…`, `@f:<key>`,
`lp_…`) and folder layout identical — old and new backends share these folders during cutover.

## 4. Commands

```bash
export PATH="/c/Users/dwip/.config/herd-lite/bin:$PATH"
php artisan test                         # Pest
php artisan test --filter=Stock
node tools/parity/parity.mjs account     # old vs new, local DB clones (never live)
node tools/devproxy/serve.mjs            # old deploy/* frontends on :8080, account+marketing -> Laravel, rest legacy PHP
node tools/e2e/marketing.mjs             # headless walkthrough of deploy/marketing against Laravel (local DB)
php artisan route:list --path=api/v1
```

CI (`.github/workflows/ci.yml`) runs on every push and PR: `php -l`, Pint `--test`,
the DB-free Unit suite (`php artisan test --testsuite=Unit`), and `route:list`.
Feature tests need the restored dumps (§0), so CI skips them explicitly until
the anonymised set from #9 can be restored in CI.

## 5. Status

| module | legacy compat | v1 | parity |
|---|---|---|---|
| account | done | auth + admin | 139/139 |
| jadwal | done | cells, shifts, requests, settings, heads | 35/35 |
| marketing | done | full contract (docs/api/marketing.md) | 46/46 · frontend e2e 25/25 |
| dw | done | full contract (docs/api/dw.md) | 110/110 · frontend e2e 25/25 |
| (others) | pending — see docs/modules/README.md | | |

## Agent skills

### Issue tracker

Issues live in GitHub Issues for `dwipapap/laksamana-api` (`gh` CLI). See `docs/agents/issue-tracker.md`.

### Triage labels

Default five labels: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` at the repo root (created lazily by `/domain-modeling`). See `docs/agents/domain.md`.
