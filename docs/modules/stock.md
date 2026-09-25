# stock — purchasing, ordering, central kitchen, usage/waste, HPP

**Status:** part 1 (#34) done — `items.php`, `vendors.php`, `orders.php`, `stock.php` on Laravel (`app/Modules/Stock`), v1 in [docs/api/stock.md](../api/stock.md), parity `tools/parity/cases/stock.json`. Part 2 (#35) done — `ck.php`, `usage.php`, `waste.php`, `serah.php`, `opname.php`, `log.php`; Usage Panel e2e `tools/e2e/stock-usage.mjs`. Part 3 (#36) done — `hpp.php`; HPP Panel e2e `tools/e2e/stock-hpp.mjs`. Part 4 (#37) done — `users.php`, `ordering-users.php`, `ordering-settings.php`, `purchasing-settings.php`, `training.php`; Ordering & Purchasing Panels e2e `tools/e2e/stock-admin.mjs`. **All 19 legacy files are on Laravel and the v1 surface of this module is complete.**

Port notes (part 4):
- **`stock_settings` is missing** in the restored production and dev dumps. The old `ordering-settings.php` / `purchasing-settings.php` therefore answer `500 kesalahan server` on GET and on every save, and that is kept byte-for-byte. v1 does not invent a table: reads return the empty default with `meta.available: false`, writes answer `503 settings_unavailable`.
- `purchasing-settings.php` reads the old flat `data` as the permission matrix and writes back the wrapped `{perms, templates}` shape, so the first save through the old screen migrates the row. v1 always speaks the wrapped shape.
- **PINs.** `users.php` and `ordering-users.php` return the PIN — that wire shape is frozen and kept. **v1 never returns a PIN**: it is accepted on write and never read back. Hashing the stored PINs is [#80](../security-followups.md), a separate issue.
- v1 `PATCH` merges: a `pin` that is not sent keeps the old one. The old endpoints replace every sent field, so an update without a `pin` blanks it — kept for compat, not repeated in v1.
- The last `admin` of `users` cannot be deleted (`tidak bisa menghapus admin terakhir` on the old screen, `409 last_admin` in v1). `ordering_users` has no such guard in legacy, so v1 does not add one.
- `training.php` is a **file store**: the Panel POSTs a raw POS `.xlsx`/`.xls` export as base64 into `TRAINING_DIR/<target>/`, and the server never parses the workbook. The stored name is `Ymd-His_<sanitised stem>.<ext>`, `list`/`get` need `API_TOKEN` (`403` when empty), and the summary reads the archive status from the filenames. v1 adds a 12 MB cap and a magic-byte check on top of the extension rule.
- Pohon Resep (`tree`) has no legacy server calls at all; v1 exposes the HPP recipes and ingredients to `module:tree` **read-only**, so the Panel can draw the recipe graph without getting write access it never had.

Port notes (part 3):
- `hpp.php` answers a thrown error as `500 {status:'error', message:'kesalahan server: <reason>'}` (the other stock files hide the reason); the port keeps it, with the PDO message for database errors.
- `by` (updated_by) comes from the request body on the compat route, as legacy stored it; v1 uses the acting user.
- The runtime DDL (`hpp_pastikan_tabel*`, `hpp_pastikan_kolom` with its one-time backfills) is not ported: tables and columns exist live.
- v1 `POST /hpp/import` is all-or-nothing (legacy stops half-way on a bad row, after a `timpa` wipe); v1 refuses a rename onto a name another ingredient holds (legacy's upsert + delete would merge them, or lose the row on a case-only rename).

Port notes (part 2):
- **`pur_ada_baris()` quirk kept (#113).** Its closed table list lacks `serah_terima` and `ck_stock`: a serah terima edit is saved and then answered 500; a CK `simpan` with an `id` answers 500 before writing. The compat route does the same; v1 is not affected.
- `activity_log`'s runtime `CREATE TABLE IF NOT EXISTS` is not ported (the table exists live); a missing table gives legacy's swallowed answers (`dicatat: 0`, `[]`).
- v1 applies the team scope to single reads, photos and writes too (legacy scoped lists only).

Port notes (part 1):
- **Time is WIB, explicitly.** Legacy used `date()`, i.e. the hosting's zone; the stored order times (peaking 16:00–01:00) show that zone is WIB. Order numbers, batch ids, `waktu` and CK row ids use `Asia/Jakarta`.
- **Team scoping** (`pur_batas_tim`) is `App\Modules\Stock\Services\StockTeamScope`, answered in-process by `Sesi`; switch `STOCK_BATAS_PER_TIM` (unset = on only when the env label is `dev`).
- The connection keeps the server `sql_mode` (#97): e.g. a `tim` longer than 20 chars is truncated, as production does.
- `OPTIONS` answers 204 with CORS headers (the shared legacy middleware); legacy stock answered 405. The frontends are same-origin, so nothing depends on it.

- **Legacy source:** `laksamana-office/stock-mysql/`. Each endpoint is its own `.php` file.
- **Legacy URLs:** `/stock-api-mysql/<file>.php`. Register one route per legacy file.
- **Database:** `lakk5493_db_stock` (8 MB, mostly photos).
- **Frontends:** `deploy/stock/{ordering,purchasing,tree,usage,hpp}`. The paths are `../../stock-api-mysql/…`.

## Shared plumbing (`_boot.php`)

- **Config:** loads `config.local.php` or `config.php`.
- **Ping first:** `?action=ping` is answered BEFORE the DB connects, as `{status, ok, time, env, db}`.
- **Token check:** `pur_cek_token()` checks `API_TOKEN`.
- **Team scoping (`pur_batas_tim`):**
  - Session: `?sesi=` or `body.sesi`, resolved with whoami (in the port: `Sesi`).
  - Admins of module `usage` or `*` see everything. Everyone else is filtered to their team (Kitchen/Bar/Floor), parsed from `keterangan` by whole word.
  - Applies to usage, waste and serah only.
  - Controlled by the config flag `BATAS_PER_TIM`: default ON in dev, OFF in prod. Make it an env flag.

## Envelopes (not `{ok, data}`)

- Success bodies are raw: bare arrays or objects, `{status: 'success', …}`, or flat payloads.
- Errors are `{status: 'error', message}` with HTTP 400/403/405/500. Use `Envelope::statusError`.
- `hpp.php?action=all` returns `{bahan, resep, setting, ts}` with **no `ok` key**.
- POST bodies are text/plain JSON, decoded as objects.

## Endpoints

| file | GET | POST actions |
|---|---|---|
| `items.php` | `{products: {Nama: {utama, cadangan[], satuan[], …}}}` | `addProduct` (preserve-if-null: fields that are not sent keep their old value; a rename cascades to HPP via `lib_hpp_nama.php`), `importProducts`, `deleteProduct` |
| `vendors.php` | `{vendors: {Nama: {whatsapp, penerima, bank, norek, tutupHari, perluJadwalJemput}}}` — no `ok` key | `addVendor` (preserve-if-null for `penerima`/`bank`/`norek`), `importVendors`, `deleteVendor` |
| `orders.php` | bare array of orders; `?action=stats`; `?action=batches&tim=&tgl=` | `batchOrder` (transaction with `MAX(row_index) … FOR UPDATE` and `WHERE batch_id=? FOR UPDATE`), `import`, `archive`, `unarchive`, `updateKedatangan` (then `pur_ck_sinkron_order`), `updateTglJemput`, `updateOrderQty`, `deleteRow` |
| `ck.php` | `{saldo[], mutasi[]}` (`?dari&ke`) | `simpan`, `hapus`, `kirim` |
| `stock.php` | `{stock: {nama: {stock_now, stock_unit}}, as_of, count}` | `{type: 'stock', as_of, stock}` — rewrites the whole table in one transaction; an EMPTY payload is refused |
| `usage.php` | list (`?dari&ke`), team-scoped | `simpan`, `status`, `hapus` |
| `waste.php` | list without photos; `?action=foto&id=` | `simpan` (a missing `foto` keeps the old one), `hapus` |
| `serah.php` | list without photos; `?action=foto&id=` | `simpan` (photo required for new rows), `hapus` |
| `opname.php` | list | `simpan`, `hapus` |
| `log.php` | `?dari&ke&modul&q&limit` (max 2000) | `catat` (batch; the server sets `waktu`) |
| `users.php` | `{users: […]}` — **includes PINs** | `add`, `update`, `delete` |
| `ordering-users.php` | `{users}` — includes PINs | `saveUser`, `bulkSeed`, `deleteUser` |
| `ordering-settings.php` | `{perms}` | `savePerms` — table `stock_settings`, row `modul='ordering'` |
| `purchasing-settings.php` | `{perms, templates}`; understands the old flat shape | `savePerms`, `saveTemplates` (read-modify-write) |
| `hpp.php` | `?action=all` → flat payload; `?action=pakai&bulan=` | `simpanBahan`, `hapusBahan`, `hapusBahanSamaResep`, `gabungBahan`, `tarikProduk`, `imporBahan`, `simpanResep`, `hapusResep`, `impor` (with `timpa`, which deletes and replaces everything — a one-time migration tool), `imporResep`, `samakanNama`, `simpanPakai`, `simpanSetting` |
| `training.php` | summary; `?action=list/get&target=` (token required) | `{type: 'training', target, filename, content_b64}` → writes an `.xlsx` into `TRAINING_DIR` |

## Tables

- **From `schema.sql` + `migrasi-*.sql`:**
  - `orders` — PK `nomor_order`, UNIQUE `row_index`
  - `vendors` — PK `nama`
  - `products` — PK `nama`
  - `users`, `ordering_users` — PK `id`
  - `stock` — PK `nama`
  - `usage_events`, `waste`, `opname`, `serah_terima` — PK `id`
  - `ck_stock` — PK `id`, **UNIQUE (`ref`, `arah`)** (makes the order-arrival sync idempotent)
- **Created at runtime:**
  - `activity_log`
  - `hpp_bahan` — PK `nama`
  - `hpp_resep` — PK `id`
  - `hpp_pakai` — PK (`bulan`, `bahan`)
  - `hpp_bulan`, `hpp_setting`
  - columns added to HPP tables at runtime: `di_purchasing`, `dibeli_jadi`, `sisi_harga`, `kode`
- **`stock_settings` is MISSING in prod and dev.** In legacy, `ordering-settings.php` / `purchasing-settings.php` return 500. Reproduce that error on the compat route, and in v1 degrade to defaults. Report it to the user.

## v1 proposal

`/api/v1/stock/{products, vendors, orders, ck, stock, usage, waste, handovers, opname, logs, hpp/*}`
