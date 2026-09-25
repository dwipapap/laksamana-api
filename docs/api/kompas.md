# Kompas API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/kompas`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. Several modules read kompas, so each endpoint states the modules it accepts.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Money:** amounts in the blob may be JSON numbers or formatted strings (`"3.855.000"`). The server reads them the way the app's `num()` does: it keeps only digits and `-`.

Status: the **revenue core** (#28) is in place: the omset blob, targets, Rekap Penjualan, daily figures, and per-PIC and per-division revenue. Investor, analytics, void log and BRI matching are added by their own issues.

## Screen → endpoint map (this cluster)

| Screen | Endpoints |
|---|---|
| Finance › Omset: Input Omset Harian, Daily Report; Cashier | `GET /state`, `PUT /state` |
| Finance › Kas Kecil: Pengaturan Target | `PUT /targets` |
| Finance › Kas Kecil: Rekap Penjualan (MDR, aktual masuk, POS ticks, deposits) | `PUT /rekap`, `GET /state` |
| Dashboards that show daily revenue | `GET /daily` |
| Marketing › Performance ("menurut Breakdown") | `GET /omset-pic` |
| Marketing / Event › Performa (bonus calculator inputs) | `GET /performa/{marketing\|event}` |

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

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted` |
| 409 | `version_conflict` |
| 422 | `validation_failed`, `invalid_request` (legacy messages, e.g. `Setoran bernilai nol — tidak ada uang yang berpindah`) |
| 428 | `version_required` |
