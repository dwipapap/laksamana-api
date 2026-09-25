# finance — petty cash (kas kecil), cash vault (brankas), invoices & receipts (kwitansi)

- **Legacy source:** `laksamana-office/finance-mysql/` (`lib_finance_mysql.php`, `lib_invoice.php`)
- **Legacy URL:** `/finance-api-mysql/api.php`
- **Database:** `lakk5493_db_finance`
- **Frontends:** `deploy/finance/kas`, `deploy/finance/brankas`
- **Called by:** reservasi and marketing (`invMinta`, `invStatus`), kompas (`brankasGet` for the investor page)

## How the legacy API is shaped

- **`action` comes from the QUERY STRING first**, then from the body. Set `$actionFromQueryFirst = true`.
- A shutdown handler turns fatal errors into JSON.
- `ping` always returns `ok:true` and reports the DB status.
- MySQL errors 1044/1049/1045 come with cPanel hints (`petunjuk_galat`).
- **Access: every action is OPEN**, apart from `API_TOKEN`. There are no session checks at all (see security-followups #6).

## Tables (all created at runtime)

| Table | Keys | What it holds |
|---|---|---|
| `kk_pos` | AI id, UNIQUE nama | Payment sources: `urut`, `aktif`. `seed_awal` uses legacy names. |
| `kk_kategori` | AI id, UNIQUE nama | 12 seeded categories |
| `kk_trx` | AI id; FK `kategori_id` ON DELETE SET NULL | `tgl`, `keterangan`, `input`, `bon`, `dibuat_at`, `dibuat_oleh` |
| `kk_trx_pos` | AI id, UNIQUE(`trx_id`,`pos_id`); FK `trx_id` CASCADE | Split of a transaction across sources: `debet`, `kredit`. **Balances are never stored.** |
| `kk_akses` | UNIQUE(`kunci`,`halaman`) | `tingkat` 0–2. Stores only the differences from the default matrix. Each run DELETEs keys starting with `#` or `@`. |
| `kk_peran` | PK `kunci` = `'#<userId>'` | `peran` |
| `bk_state` | id = 1 | Cash-vault state as one JSON blob. Kept keys: **rekening, piutang, bayar, investor, mutasi, setting**; every other key is dropped. |
| `bk_akses`, `bk_peran` | — | Same shapes as `kk_akses` / `kk_peran` |
| `inv_kwitansi` | id `inv<hex>`, **UNIQUE `res_id`** | `no_invoice`, `status` (MENUNGGU/DIBUAT/DITOLAK), `jenis` (KWITANSI/INV_DP/INV_LUNAS), `ringkas` JSON, `penanda` JSON (signatory names/titles copied in), `minta_*`, `putus_*`, `catatan` |
| `inv_setting` | k/v | `cap`, `prefix`, `penandaDefault`, `prefixInvoice`, `penandaDefaultInvoice`, plus legacy `ttd` / `penandaNama` / `penandaJabatan` |
| `inv_penanda` | id | Signatories: `nama`, `jabatan`, `ttd` (signature as a data URI), `urut`, `aktif` |

## Actions (`{ok, data}` envelope)

| Group | Actions |
|---|---|
| Petty cash — read | `getAll` → `{pos, kategori, trx (each with baris[]), akses, peran}` |
| Petty cash — transactions | `simpanTrx` `{id?, tgl, keterangan, kategori_id, input, bon, oleh, baris[{pos_id,debet,kredit}]}`: one DB transaction; an edit deletes and re-inserts the split rows.<br>`hapusTrx` (split rows cascade).<br>`tandai` `{id, field input\|bon, nilai}` |
| Petty cash — sources & categories | `simpanPos` / `nonaktifPos` / `aktifPos` / `hapusPos` — `hapusPos` is refused if the source is used.<br>Same four actions for Kategori. |
| Petty cash — access | `simpanAkses {peta}` replaces the whole matrix.<br>`simpanPeran {kunci, peran}`: an empty `peran` deletes the row. |
| Cash vault | `brankasGet` → `{data, akses, peran, updated_at}`<br>`brankasSave {data, oleh}` writes the WHOLE blob, filtered to the kept keys.<br>`bayarSave {bayar[], oleh}` reads the blob, replaces ONLY `bayar`, writes it back.<br>`brankasAkses` / `brankasPeran` |
| Invoices & receipts | See the rules below. |
| Diagnostics | `ping`, `stats` |

### Invoice & receipt rules

- **`invMinta {resId, oleh, jenis, ringkas}`** — request a document. Idempotent per `res_id`; a request that is already DIBUAT (issued) is left unchanged.
- **`invStatus {res[]}`** or `?res=a,b` — at most 400 ids.
- **`invBerkas {resId}`** — only when DIBUAT; also returns the stamp (`cap`) and signature images.
- **`invDaftar`, `invAntre`** — the list and the queue badge.
- **`invPutus {id, aksi buat|tolak|batal, oleh, catatan, penanda[]}`** — decide a request:
  - The number is `PREFIX/YYYY/MM/NNNN`: the month's highest number + 1.
  - A number is allocated ONCE and never reused.
  - `tolak` (reject) requires a note.
- **`invSetting`, `invPenandaSimpan`, `invPenandaHapus`** — a signatory already used on an issued document cannot be deleted.

## v1 proposal

- `/api/v1/finance/petty-cash/{transactions, sources, categories}`
- `/api/v1/finance/vault` — split into resources, with a version field
- `/api/v1/finance/invoices` — request, decide, file

## Port notes (#25: Kas Kecil + Akses Halaman)

- The compat controller serves the Kas Kecil and Akses Halaman actions, plus `ping`/`stats`. Brankas (`brankas*`, `bayarSave`) and invoices (`inv*`) answer `Aksi tidak dikenal` until their issues land, so do not route finance to Laravel in production before then.
- `pastikan_tabel()` (runtime DDL, seeding of empty tables, and the one-off cleanup of `#`/`@` keys in `kk_akses`) is not ported. The tables exist live and hold no such keys.
- Error messages keep the `petunjuk_galat()` cPanel hints. As in every port, raw database error messages are hidden behind `kesalahan database` outside debug mode.
- The connection keeps the server sql_mode (`server_sql_mode`, #97): `keterangan` is not length-checked, and a non-strict production truncates it.
- v1: `docs/api/finance.md`.

## Port notes (#26: Brankas)

- `brankasGet` / `brankasSave` (whole blob, only `rekening, piutang, bayar, investor, mutasi, setting` survive; the lists are re-indexed) / `bayarSave` (reads the blob, replaces only `bayar`) / `brankasAkses` / `brankasPeran` are served by the compat route, byte-identical (parity).
- `bayarSave` now reads and writes under one row lock. Legacy had none; the observable behaviour is unchanged.
- JSON is handled as assoc arrays like legacy, so an empty `setting` `{}` is stored as `[]` once round-tripped (legacy quirk, kept).
- Kompas reads the vault in-process through `Brankas::read()` (kompas is not ported yet).
- v1: `/api/v1/finance/vault` (module `brankas`) plus `/api/v1/finance/petty-cash/payment-plan` (module `finance`). See `docs/api/finance.md`.

## Port notes (#27: invoices & kwitansi, milestone)

- Every `inv*` action is served by the compat route, byte-identical (parity, including numbering in both pools, idempotent requests, issued rows left untouched, `batal` keeping the number, the signatory rules). `inv_pastikan_tabel()` (DDL, column checks, the one-off single-signatory migration) is not ported: all of it has been applied live.
- Issuing (`invPutus buat`) now takes a `GET_LOCK('<db>:inv_nomor')`, so two decisions at the same instant cannot take the same number. Legacy had no lock; the observable behaviour is unchanged.
- Reservasi and Marketing call these actions from the browser, not from their backends. The in-process entry point for future server-side callers is `App\Modules\Finance\Services\Invoices` (`request`, `statuses`, `file`).
- v1: `/api/v1/finance/invoices`, where requests, status and file are open to holders of finance, reservasi or marketing. The screen map of all Kas and Brankas pages is in `docs/api/finance.md`.
- Frontend walkthrough: `tools/e2e/finance.mjs` (`devproxy --laravel account,finance`).
