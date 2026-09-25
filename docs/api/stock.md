# Stock API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app. It grows per porting issue; this page covers **part 1 (#34): products, vendors, orders and Stock Today**. Central kitchen, usage, waste, handovers, opname, logs (#35), HPP & Resep (#36), users/settings/training (#37) follow.

- **Base URL:** `/api/v1/stock`
- **Auth:** `Authorization: Bearer <token>` from `POST /api/v1/auth/login {login, pin}`.
- **Modul:** one Backend serves several Panels. Reads need any of `ordering`, `purchasing`, `hpp`, `usage`. Writes follow who does them in the old Panels (table below).
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

## Errors

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No or bad token |
| 403 | `module_not_granted` | None of the required Modul |
| 404 | `not_found` | Unknown name / order number |
| 409 | `already_exists` / `batch_not_found` / `version_conflict` | See above |
| 422 | `validation_failed` | Bad body; legacy reasons are passed through (`nama produk kosong`, `newQty bukan angka`, `format tanggal jemput harus YYYY-MM-DD`, `stock kosong`) |
| 428 | `version_required` | Write without a version |
