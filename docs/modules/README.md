# Module porting specs

This directory has one spec per legacy backend, so any person or agent can
port a module without re-reading the whole survey. **Before starting, read
`../../CLAUDE.md` §2 (the porting checklist).** The legacy PHP source in
`../laksamana-office/<dir>` is always the final authority. Where a spec and the
source disagree, the source wins; then correct the spec.

## Status

| module | spec | legacy compat | v1 | parity | tests |
|---|---|---|---|---|---|
| account | [account.md](account.md) | **done** | auth + admin | 139/139 | 11 |
| jadwal | [jadwal.md](jadwal.md) | pending | pending | — | — |
| dw | [dw.md](dw.md) | pending | pending | — | — |
| absensi | [absensi.md](absensi.md) | pending | pending | — | — |
| marketing | [marketing.md](marketing.md) | pending | pending | — | — |
| konten | [konten.md](konten.md) | pending | pending | — | — |
| akademi | [akademi.md](akademi.md) | pending | pending | — | — |
| bd | [bd.md](bd.md) | pending | pending | — | — |
| event | [event.md](event.md) | pending | pending | — | — |
| hr | [hr.md](hr.md) | pending | pending | — | — |
| hlife | [hlife.md](hlife.md) | pending | pending | — | — |
| kompas | [kompas.md](kompas.md) | pending | pending | — | — |
| finance | [finance.md](finance.md) | pending | pending | — | — |
| reservasi | [reservasi.md](reservasi.md) | pending | pending | — | — |
| stock | [stock.md](stock.md) | pending | pending | — | — |
| ticketing | [ticketing.md](ticketing.md) | pending | pending | — | — |

Update this table (and the table in CLAUDE.md §5) whenever a module moves.

## Suggested order

Dependencies come first:

1. `jadwal` — account already reads its `heads`; dw and absensi call it.
2. `dw` — absensi calls it.
3. `absensi`
4. `marketing` — it also creates the shared `App\Support\RowSync` (see marketing.md).
5. The RowSync family: `konten`, `akademi`, `bd`, `event`, `hr`, `hlife`.
6. `finance` — kompas reads finance's brankas.
7. `kompas`
8. `reservasi`, `stock`, `ticketing`.

## Conventions shared by every legacy backend (don't repeat these in the specs)

- **Connection.** PDO with `ERRMODE_EXCEPTION`, `FETCH_ASSOC` and `EMULATE_PREPARES=false`. A named placeholder can appear **only once** per statement (HY093 otherwise). Laravel is configured the same way.
- **Request parsing.** POST bodies are JSON sent as `text/plain`, and `action` is read from the body. GET requests use `?action=`. `App\Support\Legacy\LegacyRequest` handles both.
- **CORS.** Every backend sends `Access-Control-Allow-Origin: *` and answers OPTIONS with 204. The `legacy` middleware group handles this.
- **API token.** Several backends accept an optional `API_TOKEN` via `?token=` or `body.token`; empty means open. `LEGACY_API_TOKEN` covers this in the base controller.
- **Row shape.** The usual table is "indexed columns + a `data` LONGTEXT JSON column", and the JSON is the source of truth. Decode it with `JsonDoc::decode` so `{}` stays an object.
- **Settings tables.** `settings(k, v)` key/value tables store unknown top-level state keys under `extra:<key>`.
- **Runtime DDL.** Tables and columns are created at runtime by `*_pastikan()` in legacy code. **Do not port that DDL**: the live tables already exist. Use the restored local DB (`SHOW CREATE TABLE`) as the schema reference.
- **Server-to-server calls.** Legacy calls to other modules over HTTP (`whoami`, `listDivisiRoster`, `headIds`, `shiftHari`, `jadwalDW`, `getAll`, `brankasGet`) become direct service calls or reads through `Modules::db('<key>')`.

## Session gate (lib_sesi.php)

Use `App\Support\Legacy\Sesi`, which is already ported.

- `app(Sesi::class)->user($req)` returns the whoami payload (`id`, `name`, `username`, `keterangan`, `modules`, `adminModules`, `headDivisi`) or null.
- `Sesi::hasModule($u, 'x')` and `Sesi::isModuleAdmin($u, 'x')` check module access and module-admin rights.
- On failure, throw the matching exception. Their messages are what frontends branch on:
  - `Sesi::rejectUnknown()` → message starts with `sesi_tidak_sah:`
  - `Sesi::rejectNoModule($label)` → message starts with `tanpa_modul:`
  - `Sesi::rejectForbidden($what)` → message starts with `tidak_berhak:`
- `app(Sesi::class)->roster()` returns the Office roster keyed by user id (the old `sesi_roster()`).
- The acting user's NAME always comes from the session, never from the request body.
