# kompas — revenue (omset), cashier, analytics, void, BRI matching, investor

**Where things are:**

| | |
|---|---|
| Legacy source | `laksamana-office/kompas-mysql/` (`lib_kompas_mysql.php`, `lib_sesi.php`) |
| Legacy URL | `/kompas-api-mysql/api.php` |
| Database | `lakk5493_db_kompas` |
| Data directory | `KOMPAS_DATA_DIR` (legacy fallback `../../../kompas-db`); investor PDFs are stored in `lapor/lp_<hex>.pdf` |

**Frontends:** `deploy/cashier`, `deploy/finance/omset`, `deploy/finance/kas`, `deploy/analytics`, and `investor/index.html`.

This module handles **money**. The laksamana-office CLAUDE.md documents dozens of rules for it (search for: kompas, Rekap, void, BRI, investor, analytics). Read those sections before porting.

## Tables (almost all created at runtime)

| table | notes |
|---|---|
| `app_state` | **Single-row blob** (id = 1). Columns: `data`, `updated_at` (ms), `updated_by`. Keys inside `data`: `daily[]`, `reports{tgl:{pay{k:{pos,actual}},mdr,aktual,mdrManual,esb,setor}}`, `rekap_setoran[]`, `employees{marketing,event,kasir}[]`, `compliments[]`, `settings`, plus piutang and others. Cashier and Finance > Omset both write the WHOLE blob. |
| `an_state` | Single-row blob holding analytics `{laporan{YYYY-MM},setting,promo,voidb}`. |
| `an_akses` | AUTO_INCREMENT id, UNIQUE(`kunci`, `halaman`), `tingkat` 0–2. |
| `an_peran` | PK `kunci`, column `peran`. |
| `inv_lapor` | AUTO_INCREMENT id, UNIQUE(`bulan`, `jenis` ∈ `balance`/`ledger`). Other columns: `kunci`, `nama`, `ukuran`, `at`, `oleh`. |
| `void_log` | Server-generated `id`; `tgl` indexed. Columns: `bill`, `item`, `alasan`, `nominal`, `subtotal`, `service`, `tax`, `penginput`, `salah`, `oleh`, `oleh_id`, `dibuat`, `diubah*`, `batal_at`, `batal_oleh`, `batal_alasan`. A legacy `pemesan` column exists but is unused. |
| `void_setting` | Single row: `tax_persen` (default 10), `service_persen` (default 5). |
| `bri_mutasi` | PK `id`, **UNIQUE `sidik`**; indexes on `tgl` and `dp_id`. Columns: `tgl`, `jam`, `nominal`, `ket`, `settle`, `booking`, `res_id`, `dp_id`, `res_nama`, `res_tgl`, `cara` (`''` / `cocok` / `bukan`), `catatan`, `cocok_*`, `oleh*`, `dibuat`, `diubah*`, `batal_*`, `sumber` (default `unggah`). |
| `bri_dp_abai` | PK `dp_id`; index on `tgl`. Columns: `res_id`, `nama`, `nominal`, `alasan`, `oleh`, `abai_at`. |
| legacy | `daily`, `targets`, `cashiers`, `pics`, `settings`, `log` — ignore. |

## Actions

Response envelope is `{ok,data}`, except that `getAll` adds a top-level `ts`.

### Blob reads and writes

- **`getAll`** → `{ok, data: blob, ts}`.
- **`saveAll` `{data, baseTs}`**, run under `GET_LOCK('<db>:kompas_save')`:
  - If `baseTs > 0` and it differs from the stored `updated_at`, reject with `{ok:false, konflik:true, ts, by, error}`.
  - An empty `baseTs` is allowed (old clients).
  - Compute the new version once; that value is both written and returned.
  - Success reply: `{saved, ts, waktu}`.
- **`simpanTarget` `{data:{companyMonthlyTarget, useWorkingDays, workingDaysPerMonth, target{picId:n}, by}}`**: a narrow read-modify-write of the blob, under the same lock.
- **`simpanRekap` `{data:{hari{tgl:{setor,mdr,aktual,mdrManual,esb}}, setoran{tambah[{tgl,tujuan,catatan,hari[],jumlah{}}], hapus[id]}, by}}`**:
  - A narrow write under the lock.
  - The deposit (setoran) amount is computed on the server and capped at the day's REMAINING amount (`kp_setor_masuk`); legacy rows without `jumlah` count as a full deposit.
  - The bank and payment-group key lists are closed (`$grup`, `$bank`); an MDR for an unknown bank is dropped.
- **`omsetPic`** (`dari`, `sampai`): marketing breakdown per PIC → `{hariAda, hariIsi, pic[], total}`.

### Session-gated reads

- **`performaDivisi`** (`divi` = marketing|event, `dari`, `sampai`, `sesi`): requires a session plus the module matching `divi`. Returns `{pic[], days[{date, omsetHari, bd}], comps[]}`. `omsetHari` comes from `kp_peta_harian` + `kp_tagihan_hari`; the `shift` field is stripped from the breakdown rows.

### Investor (session + module `investor`)

- **`investorRingkas`**: summary built from `kp_peta_harian` (single source; netSales = tagihan − compliment) plus `user{nama, bolehUnggah}`, plus dividends from finance brankas `investor[].returns`.
- **`investorAgenda`**: `{event[], promo[], gagal[]}`, max 200 events / 50 promos. Legacy fetched marketing, event and bd `getAll` over HTTP; the port reads their databases directly.
- **`investorLaporUpload` `{bulan, berkas{balance|ledger:{dataBase64, fileName}}}`**: requires ADMIN of `investor`. Checks the `%PDF` header and a 12 MB limit. The old file is deleted only AFTER the new one is written. A partial success is kept.
- **`investorLaporHapus`**: admin only.
- **`investorLaporFile`**: streams the PDF binary.

### Analytics (all OPEN in legacy)

- `analyticsGet`, `analyticsSave {data, oleh}`.
- `analyticsAkses {akses}` and `analyticsPeran {peran}` delete everything and re-insert, inside a transaction.

### Void log

- **`voidList`** (`dari`, `sampai`): open. Returns `{baris[], total, maks:1500, setting}`; `total` is counted BEFORE the LIMIT.
- **`voidSetting` `{data{tax, service}}`**: session plus ADMIN of `cashier` or `finance`. Values are clamped to 0–100.
- **`voidSimpan` `{data}`**: session plus module `cashier` or `finance`. The name is taken from the session. Accepts one row, or `data.items[]` written in ONE transaction.
  - Required fields come from `void_wajib()`; a failure returns a `kurang[]` list.
  - `void_rinci`: the server sums `nominal = subtotal + service + tax`.
  - `void_bagi` splits bill-level service/tax CUMULATIVELY by each row's subtotal, so no row can go negative. If the subtotal is 0, everything goes to the first row.
  - `void_layar_lama` detects an old client (sends `pemesan`) and replies "reload".
  - Ids embed the row index.
- **`voidBatal` `{id, alasan}`**: sets `batal_at` with a REQUIRED reason. Rows are never DELETEd.

### BRI matching

- **`briList`** (`dari`, `sampai`): open. Returns `{baris[], total, maks:2000, abai[]}`.
- **`briUnggah`**:
  - Upserts rows keyed by the server-generated `sidik` = `tgl|jam|nominal|#k` (`#k` is the occurrence index).
  - On duplicate it updates ONLY `ket`, `settle`, `booking`; it never touches the match columns.
  - Rows that are no longer in the uploaded file are left alone.
  - The whole upload runs in one transaction.
- **`briTambah`**: manual entry with `sidik` prefixed `m|`, `cara='bukan'`, and a required reason.
- **`briCocok`**:
  - One DP may be held by only one live row (`bri_dp_dipakai`).
  - A bulk match is not transactional: each row is attempted separately and failures are reported per row.
- **`briBatal`**: soft-cancels a row (sets `batal_*`); rows are never DELETEd.
- **`briAbai`**: marks or unmarks a DP as ignored (`bri_dp_abai`). This is the only DELETE in the module, and it only removes the ignore marker.
- All four BRI writes require a session plus module `cashier` or `finance`.
- **Named placeholders must be unique** within a statement.

### Other

- `ping`, `stats`.

## v1 proposal

- `/api/v1/kompas/daily`: per-day omset. A granular replacement for saveAll, using `baseTs` in If-Match.
- `/api/v1/kompas/rekap`
- `/api/v1/kompas/void`
- `/api/v1/kompas/bri`
- `/api/v1/kompas/analytics`
- `/api/v1/investor/{summary, agenda, reports}`

## Port notes (#28: revenue core)

- Ported: `getAll` (top-level `ts`), `saveAll` (stale guard plus the `konflik` reply), `simpanTarget`, `simpanRekap`, `omsetPic`, `performaDivisi`, `ping` and `stats` (`App\Modules\Kompas\Services\KompasState`). Investor, analytics, void and BRI answer `Aksi tidak dikenal` until their issues land, so do not route kompas to Laravel in production before then.
- JSON handling matches legacy exactly: `getAll` decodes the blob as objects, while the POST body is decoded as arrays, so a `{}` saved through `saveAll` is stored as `[]` (legacy quirk, kept; parity checks the stored bytes).
- `KompasState::dailyMap()` (`kp_peta_harian`) is the single source for daily revenue. Other modules (finance's Rekap, investor) must use it rather than re-summing the blob.
- The legacy `investorAgenda` HTTP fan-out to marketing/event/bd is not part of this issue; its port reads those modules' services.
- The connection keeps the server sql_mode (`server_sql_mode`, #97).
- v1: `docs/api/kompas.md`.

## Port notes (#29: void & BRI)

- `voidList/voidSetting/voidSimpan/voidBatal` and `briList/briUnggah/briTambah/briCocok/briBatal/briAbai` are ported in `App\Modules\Kompas\Services\VoidBri`, byte-identical to legacy (parity, with rows seeded through `setupSql`).
- The runtime DDL and column checks (`void_pastikan`, `bri_pastikan`, `bri_abai_pastikan`) are not ported: the tables exist live.
- v1: `/api/v1/kompas/voids` and `/api/v1/kompas/bri` (`docs/api/kompas.md`). Row versions are the `diubah` column.

## Port notes (#30: investor & analytics)

- Legacy fetched marketing/event/bd `getAll` and finance `brankasGet` over HTTP for the investor agenda and dividends. The port reads `MarketingState`, `EventState`, `BdState` and `Brankas` in-process; a module that fails is listed in `gagal`.
- Parity cannot compare those fields: under `php -S` the legacy URLs are built from SERVER_NAME without the port, so they always fail. They are ignored there and asserted in Pest instead.
- Report PDFs go to `<KOMPAS_DATA_DIR>/lapor/lp_<hex>.pdf`, the same layout as legacy.
- Analytics stays open on the compat route, as in legacy. v1 gates it by the `analytics` Modul.

## Port notes (#31: v1 completion, milestone M14)

- The v1 audit covers all five frontends (Cashier, Finance › Omset, the Kas pages kompas serves, Analytics, Investor); the map is in `docs/api/kompas.md`.
- Legacy screens write the whole blob with `saveAll`. v1 adds granular parts (`/sections/{key}`, `/reports/{date}`, `/days/{date}`), each versioned by its own content hash under the same `kompas_save` lock, so edits to different parts never conflict.
- Frontend walkthrough: `tools/e2e/kompas.mjs` (`devproxy --laravel account,kompas`).
