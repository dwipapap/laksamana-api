# Kompas API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/kompas`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. Several modules read kompas, so each endpoint states the modules it accepts.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Money:** amounts in the blob may be JSON numbers or formatted strings (`"3.855.000"`). The server reads them the way the app's `num()` does: it keeps only digits and `-`.

Status: complete. The contract covers the **revenue core** (#28), the **void log and QRIS BRI matching** (#29), and **Investor Compass and Analytics** (#30).

## Screen → endpoint map (all five kompas-backed frontends)

**Cashier** (`deploy/cashier`, module cashier):

| Page | Endpoints |
|---|---|
| Report (Daily Report) | `GET/PUT /reports/{date}` |
| Kasir (per-cashier revenue rows of a day) | `GET/PUT /days/{date}` |
| Compliment | `GET/PUT /sections/compliments` |
| Piutang | `GET/PUT /sections/piutang` |
| Rokok, Rekap Rokok, Setelan Rokok | `GET/PUT /sections/rokok`, `/sections/rokok_items` |
| Void (Catatan Void) | `/voids`, `/voids/settings` |
| QRIS BRI, DP | `/bri` (DPs themselves: reservasi contract) |
| Akses | `GET/PUT /sections/settings` (the page matrix lives in the blob's settings) |

**Finance › Omset** (`deploy/finance/omset`): Input Omset Harian and Breakdown Sumber Omset use `GET/PUT /days/{date}` (the breakdown lives in the day rows' `bd`); Report uses `/reports/{date}`; Compliment uses `/sections/compliments`; Piutang uses `/sections/piutang`; Akses uses `/sections/settings`. Employees and PICs use `/sections/employees`.

**Finance › Kas Kecil** (the pages kompas serves): Pengaturan Target uses `PUT /targets`; Rekap Penjualan (MDR, actual received, POS ticks, deposits) uses `PUT /rekap` + `GET /state`; Void uses `/voids`; BRI uses `/bri`.

**Analytics** (`deploy/analytics`, module analytics): every page (ringkasan, tren, hari, menu, kategori, kunjungan, meja, talent, error, voidb, promo, event, marketing, konten, desain, unggah, pengaturan, akses) reads `GET /analytics`. Unggah writes `PUT /analytics`, and Akses writes `PUT /analytics/access`. The Void & Cancel page also reads `/voids`. Promo, event, marketing and konten comparisons use those modules' own contracts.

**Investor Compass** (`investor/`, module investor): Ringkasan, Dividen and Laporan use `/investor/summary` + `/investor/reports*`; Event and Promo use `/investor/agenda`.

**Other modules:** dashboards use `GET /daily`; Marketing › Performance uses `GET /omset-pic`; Marketing and Event › Performa use `GET /performa/{marketing|event}`.

## Granular parts of the blob

Legacy screens send the **whole** blob with `saveAll`, so a stale tab conflicts with everything. v1 edits one part at a time instead. Each part carries **its own version**, a hash of its content, so two people editing different days or different sections never conflict.

| Method | Path | Body | Part |
|---|---|---|---|
| GET / PUT | `/sections/{key}` | `{value}` (`null` removes it) | one top-level key of the blob: `compliments`, `piutang`, `rokok`, `rokok_items`, `employees`, `owners`, `settings`, … |
| GET / PUT | `/reports/{YYYY-MM-DD}` | `{value}` (`null` removes it) | one Daily Report (`reports[date]`: `pay`, `mdr`, `aktual`, `setor`, …) |
| GET / PUT | `/days/{YYYY-MM-DD}` | `{rows: [...]}` | all `daily` rows of that date, replaced together (each row is stamped with that `date`; the breakdown lives in `bd`) |

A PUT needs `If-Match: "<part version>"` → `428` / `409` with `details.current`. The response carries the new part `version`, plus `meta.blobVersion` (the blob's `updated_at`, which a legacy tab would see). Writes take the same `kompas_save` lock as `saveAll` and record the session user as `updated_by`.

## Endpoints

| Method | Path | Modules | Notes |
|---|---|---|---|
| GET | `/state` | kompas, finance or cashier | The whole omset blob (`daily[]`, `reports{}`, `rekap_setoran[]`, `employees{}`, `compliments[]`, `settings`, …). `meta.version` = `updated_at` (ms) + `ETag`. |
| PUT | `/state` | kompas, finance or cashier | `{data: <the whole blob>}`. **Needs the version** (`If-Match` / `?version=`): a newer stored blob returns `409 version_conflict` with `{version, savedBy}`. The author is the session user. |
| PUT | `/targets` | kompas, finance or cashier | `{companyMonthlyTarget?, useWorkingDays?, workingDaysPerMonth?, target?: {picId: amount}}`. Patches only those fields; `workingDaysPerMonth` 0 becomes 26. → `{saved, ubah, hilang: [unknown PIC ids]}` |
| PUT | `/rekap` | kompas, finance or cashier | `{hari: {date: {setor?, mdr?, aktual?, mdrManual?, esb?}}, setoran?: {tambah?: [{tgl, tujuan?, catatan?, hari: [dates], jumlah?: {date: amount}}], hapus?: [id]}}`. See the rules below. → `{saved, ubah, hari, takDikenal, setoran}` |
| GET | `/daily?from&to` | kompas, finance or cashier | One row per day from the single daily map: `food, bev, lainnya, discount, service, tax, bill, compliment` plus the three conventions `net`, `tagihan`, `netSales` |
| GET | `/omset-pic?from&to` | marketing or finance | Section B (marketing breakdown) per PIC: `diakui = amount + tax + service + open bill (only when ticked)`. `{dari, sampai, hariAda, hariIsi, pic[], total}` |
| GET | `/performa/{divisi}?from&to` | that division's module (`marketing` or `event`) | `{pic[], days[{date, omsetHari, bd}], comps[]}`: the raw inputs of `deploy/assets/performa-bonus.js`. `omsetHari` is the day's **tagihan**. Breakdown rows carry no `shift`; `comps` contains only the compliments that involve the division's PICs. |

**Versions.** The whole-blob PUT must send the version. The two narrow writes merge safely under the same `GET_LOCK('<db>:kompas_save')` and can never overwrite revenue, breakdowns or Daily Reports, so their version is **optional**. When sent, it is checked inside the lock (`409` when stale). Every write returns the new `meta.version`.

## Rekap Penjualan rules (server-side, as legacy)

- It writes only the panel's own marks: `setor`, `mdr`, `aktual`, `mdrManual`, `esb`. It **never** writes `pay`; POS and Actual belong to the Daily Report.
- The bank and group key lists are **closed**. An MDR, `aktual` or `mdrManual` value for an unknown bank is dropped. An unknown `esb` group is reported in `takDikenal`.
- For `aktual` and `mdrManual`, `""` or `null` **deletes** the value; it is not zero. `mdrManual` is clamped at ≥ 0.
- **Deposits:** the amount is computed by the server from each covered day's cash actual. A per-day `jumlah` is **capped at the day's remaining cash** (cash − what was already deposited; older deposits without `jumlah` count as full). A deposit worth 0 is refused. The per-day `setor` flag is recomputed from all deposits, with a 1-rupiah tolerance.

## The three revenue conventions

| | formula | used by |
|---|---|---|
| `net` | food + bev + lainnya − discount | Dashboard Omset |
| `tagihan` | net + service + tax | Rekap Penjualan, performa `omsetHari` |
| `netSales` | tagihan − compliment | CFO / investor reports |

All three come from the same daily map. Days that have only a compliment still appear.

## Catatan Void — `/voids` (module cashier or finance)

These are accountability records: **nothing is deleted**. A wrong entry is cancelled with a required reason and stays visible. The recorded name (`oleh`) is always the session user.

| Method | Path | Notes |
|---|---|---|
| GET | `/voids?from&to` | `{baris[], total, maks: 1500, setting}`, newest first. `total` is counted before the 1500 limit. `rinci: false` marks rows from before the subtotal/service/tax breakdown existed. |
| POST | `/voids` | One row: `{tgl, bill, item, penginput, salah, alasan, subtotal, service?, tax?}`. Or a whole bill in one transaction: `{tgl, bill, penginput, salah, alasan, service?, tax?, items: [{item, subtotal}]}`. The server **computes** `nominal = subtotal + service + tax`. Bill-level service and tax are split over the items by subtotal, **cumulatively**: the parts add up exactly and none is negative (if every subtotal is 0, the first item takes it all). Missing fields → `422` with `details.kurang` (field labels). Items without a name are dropped. → `201 {id, baru}` or `{n, ids}` |
| PUT | `/voids/{id}` | Edit one row (not once it is cancelled). **If-Match = the row's `diubah`** |
| POST | `/voids/{id}/cancel` | `{alasan}` (required). If-Match = `diubah` |
| GET / PUT | `/voids/settings` | `{tax, service}` percentages, 0–100 (defaults 10 / 5). PUT needs the **module admin** of cashier or finance, and If-Match = the setting's `diubah` (0 before the first save). |

## QRIS BRI matching — `/bri` (module cashier or finance)

| Method | Path | Notes |
|---|---|---|
| GET | `/bri?from&to` | `{baris[], total, maks: 2000, abai[], dipakai[]}`, oldest first, together with the DPs marked invalid. `dipakai[]` is every dpId held by any live row in **any month** (ids only; cancelled rows hold nothing; no `cara` filter), so a DP recorded in another month is not offered again as unrecorded |
| POST | `/bri/upload` | `{baris: [{tgl, jam, nominal, ket?, settle?, booking?}]}`, at most 3000 rows, in one transaction. The server builds the key `sidik = tgl\|jam\|nominal\|#k`, where `k` numbers identical transfers so they all survive. A re-upload updates only `ket`, `settle` and `booking`, **never the match**. Rows missing from the file are kept. Rows without a date or nominal are skipped and counted (`lewat`). → `{n, baru, lama, lewat}` |
| POST | `/bri` | A manual incoming fund that is not a reservation DP: `{tgl, jam?, nominal, ket}`, `ket` required. It is stored with `cara = bukan` and `sumber = manual`. `201` |
| POST | `/bri/match` | `{id, cara: cocok\|bukan\|lepas, resId, dpId, resNama?, resTgl?, catatan?}` or `{items: [...]}`. `cocok` needs `resId` and `dpId`, and **one DP can be held by only one live row**. `bukan` needs `catatan`. `lepas` clears the decision. A bulk call is **not** one transaction: each row stands alone and failures come back in `gagal[{id, sebab}]`. The response is `422` only when every row failed. No version is needed: the one-DP rule is enforced by the server. |
| POST | `/bri/{id}/cancel` | `{alasan}` (required). If-Match = the row's `diubah` |
| POST | `/bri/ignored` | `{dpId, resId?, nama?, tgl?, nominal?, alasan}`: marks a reservation DP as not a valid BRI fund; a reason is required. The DP itself (module reservasi) is not touched. |
| DELETE | `/bri/ignored/{dpId}` | Lifts the mark |

Validation failures keep the legacy Indonesian messages → `422 invalid_request` (with `details.kurang` or `details.gagal`).

## Investor Compass — `/investor` (module investor)

Figures investors see are **already summed** from the single daily map: no staff names, no per-cashier breakdown, no receivables.

| Method | Path | Notes |
|---|---|---|
| GET | `/investor/summary` | Today, yesterday, this month (with the company target) and last month, in all three conventions, plus: `tahunan` (net sales per month, `null` = no data), `harian`, `labaRugi` (the CFO Profit & Loss lines this system knows, newest month first), `lapor` (the reports list), `dividen` (capital returns from Finance → Brankas: `{riwayat, total, modal, investor, per:[{nama, modal, kembali, kali, kepemilikan, terhubung}], semua, jumlahSemua, gagal}`. **Filtered per viewer on the server:** an investor admin gets every investor (`semua: true`, `jumlahSemua` = all records); anyone else only the records that are theirs (`semua: false`, `jumlahSemua: null`) — a record whose `akunId` is the viewer’s id, or a record without `akunId` whose name equals the viewer’s name (case and edge spaces ignored; a record with an `akunId` is never matched by name). `modal` per investor = `capital` + `tambahan[].amount`), `terakhir` and `adaData`. `meta.canUpload` = investor admin. |
| GET | `/investor/agenda` | Upcoming events from Marketing and Event (max 200, nearest first; events without a time go last in their day) and running or upcoming promos from BD (max 50, running first). Titles, dates and places only: no clients and no prices. `gagal` names any module that could not be read. |
| GET | `/investor/reports` | `{bulan: {balance\|ledger: {nama, ukuran, at, oleh}}}` |
| GET | `/investor/reports/{YYYY-MM}/{balance\|ledger}` | The PDF (binary) |
| PUT | `/investor/reports/{YYYY-MM}/{balance\|ledger}` | **Investor admin.** `{dataBase64, fileName?}`: must be a PDF (checked by its content) of at most 12 MB. Replaces that month's report; the old file is removed only after the new one is written. |
| DELETE | `/investor/reports/{YYYY-MM}/{balance\|ledger}` | **Investor admin** |

## Analytics — `/analytics` (module analytics)

| Method | Path | Notes |
|---|---|---|
| GET | `/analytics` | `{data: {laporan, setting, …}, akses, peran, ts}`: summaries of the uploaded POS reports plus the page × role matrix. `meta.version` = `ts`; `meta.accessVersion` = hash of matrix + roles |
| PUT | `/analytics` | `{data}` replaces the whole analytics state. **If-Match = version** |
| PUT | `/analytics/access` | **Analytics admin.** `{matrix?, roles?}`: each one given replaces its whole map (`tingkat` is clamped to 0–2). If-Match = `accessVersion` |

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted` |
| 409 | `version_conflict` |
| 422 | `validation_failed`, `invalid_request` (legacy messages, e.g. `Setoran bernilai nol — tidak ada uang yang berpindah`) |
| 428 | `version_required` |
