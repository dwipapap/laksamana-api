# Stock API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app. It grows per porting issue; this page covers **part 1 (#34): products, vendors, orders and Stock Today** and **part 2 (#35): Central Kitchen, the Usage Panel (usage, waste, handovers, opname) and the activity log** and **part 3 (#36): HPP & Resep**. Users/settings/training (#37) follow.

- **Base URL:** `/api/v1/stock`
- **Auth:** `Authorization: Bearer <token>` from `POST /api/v1/auth/login {login, pin}`.
- **Modul:** one Backend serves several Panels. Reads need any of `ordering`, `purchasing`, `hpp`, `usage` (the Usage Panel records need `usage`). Writes follow who does them in the old Panels (tables below).
- **Envelope:** success `{data, meta?}`, failure `{error: {code, message, details?}}`.
- **Fields:** records are the app's own objects (`utama`, `cadangan`, `satuan`, `tglDatang`, `kedatangan`, …), the same shape the old endpoints return, plus `nama` on products and vendors (the key of the legacy maps). Bodies are read raw: an empty string stays `""`.
- **Versions:** the stock tables have no version column, so every record's `version` is a hash of what is stored (any write through v1 or the old endpoints changes it). Send it as `If-Match` (or `?version=`); stale ⇒ `409 version_conflict` with `details.current`.

## Screen → endpoint map

| Panel / screen | Endpoints |
|---|---|
| Ordering › Form Order (belanja & Central Kitchen tab) | `GET /products`, `GET /orders/batches?tim=&tgl=` (joinable batches), `POST /orders/batches` |
| Ordering › Check-in Penerimaan | `GET /orders?batchId=` / `?tim=&status=Aktif`, `PATCH /orders/{nomor}` (`kedatangan`, `catatanAktual`) |
| Ordering › edit / delete a pending line | `PATCH /orders/{nomor}` (`qty`), `DELETE /orders/{nomor}` |
| Ordering & Purchasing › Stock Today (xlsx upload, forecasting) | `GET /stock-today`, `PUT /stock-today` |
| Purchasing › Monitor Order | `GET /orders`, `POST /orders/archive`, `POST /orders/unarchive`, `PATCH /orders/{nomor}` (`tglJemput`, `status`) |
| Purchasing › Barang Online / Selesai Dijemput | `PATCH /orders/{nomor}` (`kedatangan`, `tglTerima`, `catatanTerima`) |
| Purchasing › Database Vendor | `GET/POST /vendors`, `PATCH/DELETE /vendors/{nama}`, `POST /vendors-import` |
| Purchasing › Database Barang (and the HPP ingredient editor) | `GET/POST /products`, `PATCH/DELETE /products/{nama}`, `POST /products-import` |
| Dashboard counters | `GET /orders/stats` |
| Purchasing › Central Kitchen (stock, movements, manual produksi / penyesuaian / rusak) | `GET /ck/balance`, `GET /ck/movements`, `POST /ck/movements`, `PATCH/DELETE /ck/movements/{id}` |
| Ordering › Kirim ke CK (outlet → CK) | `POST /ck/deliveries` |
| Ordering & Purchasing › every change they log | `POST /logs` |
| Purchasing › Log Aktivitas (admin) | `GET /logs` |
| Pemakaian › Daily SO | `GET/POST /opname`, `GET/PATCH/DELETE /opname/{id}` |
| Pemakaian › Pemakaian Bahan (Catat / Report) | `GET/POST /usage`, `GET/PATCH/DELETE /usage/{id}` (`status` is a PATCH field) |
| Pemakaian › Waste Produk (Catat / Report, photo) | `GET/POST /waste`, `GET/PATCH/DELETE /waste/{id}`, `GET /waste/{id}/photo` |
| Pemakaian › Serah Terima (Catat / Report, photo) | `GET/POST /handovers`, `GET/PATCH/DELETE /handovers/{id}`, `GET /handovers/{id}/photo` |
| HPP › Dashboard, Kalkulator (computed by the client) | `GET /hpp/ingredients`, `GET /hpp/recipes`, `GET /hpp/settings` |
| HPP › Bahan & Harga, Barang Floor | `GET/POST /hpp/ingredients`, `GET/PATCH/DELETE /hpp/ingredients/{nama}`, `POST /hpp/ingredients-import`, `POST /hpp/ingredients-merge`, `POST /hpp/pull-products`, `POST /hpp/remove-shadows`, `POST /hpp/align-names` |
| HPP › Daftar Resep | `GET/POST /hpp/recipes`, `GET/PATCH/DELETE /hpp/recipes/{id}`, `POST /hpp/recipes-import` |
| HPP › Pemakaian & Selisih | `GET/PATCH /hpp/usage/{YYYY-MM}` |
| HPP › Pengaturan | `GET/PATCH /hpp/settings` |
| HPP › first move from Excel (empty tables only) | `POST /hpp/import` (HPP admins) |

## Products & vendors

| Method | Path | Modul | Notes |
|---|---|---|---|
| GET | `/products`, `/vendors` | any | All, sorted by name. `meta.versions` = `{nama: version}`. Every optional field is normalised (products: `cadangan`, `satuan`, `kategori`, `area` (list), `aktif` (default true), `satuanDasar`, `isi`, `sumber`, `packIsi`, `packSatuan`, `diOutlet`; vendors: `whatsapp`, `perluJadwalJemput`, `tutupHari` (0 Sunday … 6 Saturday), `penerima`, `bank`, `norek`). |
| GET | `/products/{nama}`, `/vendors/{nama}` | any | One record + version. |
| POST | `/products` | purchasing, hpp | Body = the record with `nama`. `201`, `meta.report` may carry `hppBaru: true` (a zero-priced HPP ingredient was created). `409 already_exists` when the name is taken (case-insensitive). |
| PATCH | `/products/{nama}` | purchasing, hpp | Only the fields sent change (the legacy preserve-if-null rule). `nama` different from the URL **renames**; the rename follows into HPP & Resep (`meta.report.hpp` = `{bahan, resep, pakai, lewat}`). `409 already_exists` when the new name is taken. Version required. |
| DELETE | `/products/{nama}` | purchasing, hpp | Version required. |
| POST | `/products-import` | purchasing, hpp | `{rows:[{nama, …}]}` upsert by name, nothing deleted, never renames. → `{baru, diubah, dilewati, galat}`. |
| POST / PATCH / DELETE | `/vendors…`, `/vendors-import` | purchasing | Same rules. Renaming a vendor does not touch products that name it (free text, as legacy). |

The server keeps the product rules of the old backend: Central Kitchen goods (`sumber: 'ck'`) get their units forced to `[Pack, packSatuan]`; `both` goods get them added; `isi` keeps positive numbers only and needs `satuanDasar`; units that have a size join the unit list; `caraBeli` is `''`, `online` or `jemput`.

## Orders

| Method | Path | Modul | Notes |
|---|---|---|---|
| GET | `/orders` | any | Ordered by `rowIndex`. Filters (exact): `status`, `tim`, `batchId`, `tglDatang`, `kedatangan`, `item`; `from`/`to` on `tglDatang`. `meta.versions` = `{nomorOrder: version}`. |
| GET | `/orders/{nomorOrder}` | any | One order + version. |
| GET | `/orders/batches?tim=&tgl=` | any | Batches still joinable (nothing arrived yet), archived ones included with `terarsip: true`. |
| GET | `/orders/stats` | any | `{orders, vendors, products, users, orders_aktif, env, db}` |
| POST | `/orders/batches` | ordering, purchasing | `{orders:[{item, qty, unit, note, tglDatang}], batchId?, batchName?, tim?}`. No `batchId` = a new batch (id and order numbers made by the server, in WIB). With `batchId` = join: the batch's arrival date wins, and the same item + unit is added to the existing line (which goes back to Aktif). `pic` is **the acting user's name**. `201` → `{created, merged, batchId, batchName, orders, mergedItems}`. `409 batch_not_found` when the batch is gone. |
| PATCH | `/orders/{nomorOrder}` | ordering, purchasing | Any of: `qty`; `kedatangan` (+ `catatanAktual`, `tglTerima` YYYY-MM-DD, `catatanTerima` ≤ 300 chars) — a Central Kitchen order marked `Datang` writes the CK stock-out, cancelling removes it (`meta.ck`); `tglJemput` (YYYY-MM-DD or `''`); `status` (`Aktif`/`Arsip`). Version required. |
| DELETE | `/orders/{nomorOrder}` | ordering, purchasing | A real delete (the Ordering delete button). Version required. |
| POST | `/orders/archive`, `/orders/unarchive` | purchasing | `{orderIds:[…]}` → `{updated}` |
| POST | `/orders/import` | purchasing | Migration tool: keeps numbers, row indexes and statuses; idempotent by `nomorOrder`. |

## Stock Today

| Method | Path | Modul | Notes |
|---|---|---|---|
| GET | `/stock-today` | any | `{stock: {nama: {stock_now, stock_unit}}, as_of, count}` + version |
| PUT | `/stock-today` | ordering, purchasing | `{as_of, stock:{…}}` replaces the whole snapshot in one transaction. An empty map is refused (`422`, "stock kosong"). Version required. |

## Central Kitchen

The balance is never stored: it is always SUM(masuk) − SUM(keluar) of the movements, and every quantity is stored in the item's base unit (a `Pack` is converted with the product master's `packIsi` when written, never when read).

| Method | Path | Modul | Notes |
|---|---|---|---|
| GET | `/ck/balance` | any | One row per CK product (`sumber` `ck`/`both`), 0 when it has no movement yet: `{item, sumber, packIsi, packSatuan, kategori, masuk, keluar, saldo, terakhir}`. Movements of items that are no longer CK products stay, flagged `hilang: true`. Not date-filtered (a balance is "now"). |
| GET | `/ck/movements?from=&to=` | any | Newest first: `{id, tanggal, item, arah, qty (base unit), qtyInput, unitInput, sebab, status, ref, tim, pic, waktu, catatan, packIsi, packSatuan}` (pack size at the time). `meta.versions` = `{id: version}`. |
| GET | `/ck/movements/{id}` | any | One movement + version. |
| POST | `/ck/movements` | purchasing | Manual movement `{item, arah: masuk\|keluar, qtyInput, unitInput?, sebab? (produksi\|penyesuaian\|rusak), tanggal?, tim?, catatan?}`. The item must be a CK product. `pic` = the acting user. `201`. |
| PATCH | `/ck/movements/{id}` | purchasing | Same fields, any subset. Version required. A movement written by an order check-in (`ref` set) is refused (`422`, "mutasi dari pengajuan hanya berubah lewat check-in"). |
| DELETE | `/ck/movements/{id}` | purchasing | Version required. Check-in rows are refused (`422`). |
| POST | `/ck/deliveries` | ordering, purchasing | Outlet → CK `{item, qtyInput, unitInput?, tanggal?, tim?, catatan?}`: a `masuk` row with `sebab: kiriman`, counted at once. Only goods also kept at the outlet (`diOutlet`). `201`. |

Order check-ins write and remove the `keluar` rows of Central Kitchen orders by themselves (`PATCH /orders/{nomor}` with `kedatangan`).

## Usage Panel records

Four kinds with the same endpoints: `usage` (event usage), `waste`, `handovers` (serah terima to Kitchen/Bar), `opname` (Daily SO). Modul `usage` for all of them.

| Method | Path | Notes |
|---|---|---|
| GET | `/{kind}?from=&to=` | Newest first (`tanggal`, then `waktu`). `meta.versions` = `{id: version}`. Lists never carry photos, only `adaFoto`. |
| GET | `/{kind}/{id}` | One record + version. |
| GET | `/waste/{id}/photo`, `/handovers/{id}/photo` | `{foto (data URL), fotoNama}`; `404` when there is none. |
| POST | `/{kind}` | `201`. `pic` = the acting user. |
| PATCH | `/{kind}/{id}` | Any subset of the fields; the rest keep their value. `foto` not sent keeps the photo, `""` removes it. Version required. |
| DELETE | `/{kind}/{id}` | Version required. |

Records (the old endpoints' shapes):

- **usage** `{id, tanggal, jenis, namaEvent, status: Rencana|Selesai, pic, tim, waktu, catatan, items:[{item, qty, unit, note}]}`. `tanggal`, `jenis` and one named line are required.
- **waste** `{id, tanggal, item, qty, unit, sebab, pic, tim, waktu, fotoNama, adaFoto, catatan}` (+ `foto` on write). `tanggal`, `item`, `qty > 0` are required.
- **handovers** `{id, tanggal, tujuan, penerima, pic, tim, waktu, fotoNama, adaFoto, catatan, items:[{item, qty, unit}]}`. `tanggal`, `tujuan`, a line with `qty > 0` and, for a new one, `foto` are required.
- **opname** `{id, tanggal, pic, tim, status: Draft|Selesai, waktu, catatan, items:[{item, unit, opening, masuk, sistem, fisik, note}]}`. Numbers not filled are `null` ("not counted" is not 0); `selisih` is never stored (always `fisik − sistem`).

**Team scoping.** When the switch is on (`STOCK_BATAS_PER_TIM`, default on only in `dev`), `usage`, `waste` and `handovers` show only the rows of the caller's teams (from the Office `keterangan`: Kitchen, Bar, Floor). Admins of `usage` see all; a user without a team sees nothing. The same lookup guards single reads, photos and writes (`404` outside the scope).

Nothing here changes the Stock Today snapshot (the next upload replaces it whole).

## Activity log

| Method | Path | Modul | Notes |
|---|---|---|---|
| POST | `/logs` | ordering, purchasing | `{entries:[{modul, aksi, tim?, ringkas?, data?}]}` → `{recorded}`. Entries without `modul`/`aksi` are skipped; `aktor` is the acting user; `waktu` is the server clock; `ringkas` is cut at 500 chars. Logging never fails the caller (an error answers `recorded: 0`). |
| GET | `/logs?from=&to=&modul=&q=&limit=` | purchasing **admin** | Newest first; `limit` default 300, max 2000; `q` searches `ringkas`, `aktor`, `aksi`. |

## HPP & Resep

Modul `hpp` for everything below (`/hpp/import`: HPP admins). Records are the rows of the old `hpp.php` (snake_case columns). Costs are **not** computed by the server — the client computes them, cascading through recipes that use other recipes — except `per1` = `harga_beli / qty_beli` on each ingredient. `updated_by` is always the acting user. Versions are content hashes (the rename cascade into recipe lines does not bump `updated_at`).

| Method | Path | Notes |
|---|---|---|
| GET | `/hpp/ingredients` | `{nama, satuan, qty_beli, harga_beli, per1, vendor, produk, kategori, catatan, di_purchasing, dibeli_jadi, sisi_harga: ''\|beli\|resep, updated_at, updated_by}` by name. `meta.versions` = `{nama: version}`. |
| GET | `/hpp/ingredients/{nama}` | One + version. |
| POST | `/hpp/ingredients` | `201`. `di_purchasing` defaults to on: the name is then registered as a Purchasing product if missing (`meta.report.purchasingBaru`). `409 already_exists` when the name is taken (case-insensitive). |
| PATCH | `/hpp/ingredients/{nama}` | Only the fields sent change. `nama` different from the URL **renames**: every recipe line and the monthly usage follow (`meta.report.resepIkutBerubah`); `409` when the new name is taken. Version required. |
| DELETE | `/hpp/ingredients/{nama}` | Version required. Recipe lines naming it are left as they are (the screen warns first). |
| POST | `/hpp/ingredients-merge` | `{from, into}` + If-Match = the version of `from`: recipe lines and usage of `from` move to `into` (usage months that already hold `into` are dropped), then `from` is deleted; `into`'s price is untouched. → the `into` record, `meta.recipes`. |
| POST | `/hpp/ingredients-import` | `{rows:[…]}` upsert by name, never deletes, never renames. → `{baru, diubah, dilewati, galat:[names]}`. |
| POST | `/hpp/pull-products` | Every Purchasing product without an ingredient row gets a zero-priced one. → `{pulled}`. |
| POST | `/hpp/remove-shadows` | Deletes the ingredients named like a recipe, except when `sisi_harga` is set, a **base** recipe has that name, or a recipe uses it as an ingredient line. → `{deleted, names}` (first 50). |
| POST | `/hpp/align-names` | Renames every ingredient paired with a Purchasing product (`produk`) to that name (recipes follow) and clears the pairing; targets already taken are skipped. → `{renamed, cleared, conflicts}`. |
| GET | `/hpp/recipes` | `{id, nama, jenis: food\|drink, tipe: base\|dish, seksi, kode, yield_qty, yield_unit, harga_lama, harga_baru, harga_upsize, modal_manual, catatan, bahan:[{nama, qty, satuan, ref: bahan\|resep} \| {catatan}], aktif, di_purchasing, updated_at, updated_by}` by `jenis, tipe, nama`. |
| GET | `/hpp/recipes/{id}` | One + version. |
| POST | `/hpp/recipes` | `201`. The server makes the `id` unless one is sent (`409` when taken). `kode` is upper-cased; a zero `yield_qty` becomes 1. `di_purchasing` registers the recipe as a Central Kitchen product (`sumber: ck`) if missing. |
| PATCH | `/hpp/recipes/{id}` | Only the fields sent change; `bahan` is replaced whole when sent. Version required. |
| DELETE | `/hpp/recipes/{id}` | Version required. |
| POST | `/hpp/recipes-import` | `{rows:[…]}` upsert by id, deletes no recipe (the lines of an uploaded recipe are replaced). → `{baru, diubah, lewat, galat}`. |
| GET | `/hpp/usage/{YYYY-MM}` | `{bulan, baris:[{bahan, sa, beli, resep, spoil, team, rnd, comp, opname, …}], penjualan, catatan, daftarBulan}` + version (other months do not change it). |
| PATCH | `/hpp/usage/{YYYY-MM}` | `{baris?, penjualan?, catatan?}`: rows are upserted by `bahan` (rows not sent are kept), the sales figure and note are kept unless sent. Version required. |
| GET / PATCH | `/hpp/settings` | `{targetFood, targetDrink, buffer, lampuKuning, lampuMerah}` (defaults 0.33, 0.33, 0.05, 3, 8); only these keys, as numbers. Version required on PATCH. |
| POST | `/hpp/import` | `{bahan:[…], resep:[…], replace?}` — the one-time move from Excel. `409 hpp_not_empty` when HPP already holds data, unless `replace` (which wipes both tables first). All-or-nothing: a bad row (`422`) rolls the whole import back. |

Numbers are read like the old screen sends them: everything but digits, `.` and `-` is dropped (`"Rp 25.000"` → 25.0 — dots are decimals).

## Errors

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No or bad token |
| 403 | `module_not_granted` | None of the required Modul |
| 404 | `not_found` | Unknown name / order number |
| 409 | `already_exists` / `batch_not_found` / `hpp_not_empty` / `version_conflict` | See above |
| 422 | `validation_failed` | Bad body; legacy reasons are passed through (`nama produk kosong`, `newQty bukan angka`, `format tanggal jemput harus YYYY-MM-DD`, `stock kosong`, `tanggal wajib diisi`, `foto bukti wajib diunggah`, `barang bukan barang Central Kitchen: …`, …) |
| 428 | `version_required` | Write without a version |
