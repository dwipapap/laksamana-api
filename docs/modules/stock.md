# stock — purchasing, ordering, central kitchen, usage/waste, HPP

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
