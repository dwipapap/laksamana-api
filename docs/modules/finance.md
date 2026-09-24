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
