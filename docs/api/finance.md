# Finance API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/finance`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs module `finance`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** the legacy field names (`tgl`, `keterangan`, `kategori_id`, `baris[{pos_id, debet, kredit}]`, `nama`, `urut`, `aktif`). Amounts are whole rupiah (integers).

Status: complete. The contract covers **Kas Kecil + Akses Halaman** (#25), **Brankas** (#26), **invoices & kwitansi** (#27) and **Tagihan Rutin** (recurring subscriptions).

## Screen → endpoint map (every page of both Panels)

Some finance pages only **display data owned by other modules**: kompas' sales recap, reservasi's DPs, bd's POs and the stock vendor list. The old pages read those from the other modules' backends, and the v1 equivalents belong to those modules' contracts. The table names them so a client knows where each page's data comes from.

**Kas Kecil Panel** (`deploy/finance/kas`):

| Page | Endpoints |
|---|---|
| Input Transaksi (`kk_input`) | `POST /petty-cash/transactions`, `PUT /petty-cash/transactions/{id}` |
| Buku Kas (`kk_buku`) | `GET /petty-cash/transactions?from&to`, `PATCH …/{id}` (Input/Bon ticks), `DELETE …/{id}` |
| Pos & Kategori (`kk_pos`) | `/petty-cash/sources`, `/petty-cash/categories` |
| Planning Pembayaran (`bayar`) | `GET/PUT /petty-cash/payment-plan`; the vault-side wallet balances come from `GET /petty-cash/wallet-balances` (kompas sales are added client-side from kompas state, as in the old page) |
| Tagihan Rutin (`tagihan`) | `/tagihan` (list, create, update, activate), `/tagihan/{id}/payments`, `/tagihan/payments/{id}/cancel` |
| Invoice & Kwitansi (`invoice`) | `/invoices` (queue, decisions), `/invoices/settings`, `/invoices/signatories` |
| Akses Halaman (`akses`) | `GET /petty-cash/access`, `PUT /petty-cash/access/matrix`, `PUT /petty-cash/access/roles/{userId}` |
| Rekap Penjualan (`rekap`), Bulanan (`bulanan`), Analytics (`analytics`), Void Bill (`voidb`), Kasir (`kasir`), BRI (`bri`) | kompas' sales recap (kompas contract, #28) |
| DP Reservasi (`dp`) | reservasi's DPs (reservasi contract), plus `GET /invoices/status` for the kwitansi state |
| Performa Marketing / Event (`marketing`, `event`) | kompas' recap + `/api/v1/marketing/*`, `/api/v1/event/events-on/{date}` |

**Brankas Panel** (`deploy/finance/brankas`, module `brankas`):

| Page | Endpoints |
|---|---|
| Ringkasan (`ringkasan`) | `GET /vault` (+ kompas recap for the account balances) |
| Saldo (`saldo`) | `/vault/rekening`, `GET /vault` |
| Mutasi & Transfer (`mutasi`) | `/vault/mutasi` |
| Piutang / pending (`pending`) | `/vault/piutang`, `/vault/bayar` |
| Modal / investor (`modal`) | `/vault/investor` |
| Pengaturan (`pengaturan`) | `GET/PUT /vault/setting` |
| Akses Halaman (`akses`) | `GET /vault/access`, `PUT /vault/access/matrix`, `PUT /vault/access/roles/{userId}` |

The vendor list both Panels show comes from stock (`stock-api-mysql/vendors.php`, stock contract).

## Kas Kecil — `/api/v1/finance/petty-cash`

**Balances are never stored.** Clients compute them from the full history (sorted by `tgl`, then `id`), exactly as the old page does.

### Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/` | Bootstrap, the same as legacy `getAll`: `{pos, kategori, trx (each with baris[]), akses, peran}` |
| GET | `/transactions` | `?from=YYYY-MM-DD&to=YYYY-MM-DD` (optional). `meta.versions` = `{id: version}` |
| GET | `/transactions/{id}` | One transaction with its split rows. `meta.version` + `ETag` |
| POST | `/transactions` | `{tgl, keterangan, kategori_id?, input?, bon?, baris:[{pos_id, debet, kredit}]}`. `dibuat_oleh` is the **session user**. `201` |
| PUT | `/transactions/{id}` | Replaces the transaction and **rewrites its split rows**. Needs a version. |
| PATCH | `/transactions/{id}` | `{input?: bool, bon?: bool}`: only the administrative ticks, never the content. Needs a version. |
| DELETE | `/transactions/{id}` | The split rows go with it. Needs a version. |
| GET | `/sources`, `/categories` | `meta.versions` |
| POST | `/sources`, `/categories` | `{nama, urut?}`. `201`. A duplicate name returns `422 "<nama>" sudah ada dalam daftar.` |
| PATCH | `/sources/{id}`, `/categories/{id}` | Any of `{nama, urut, aktif}`. Needs a version. |
| DELETE | `/sources/{id}`, `/categories/{id}` | Refused with `422` once the item is used by a transaction: deactivate it instead (`aktif: false`). |
| GET | `/access` | `{matrix: {role: {page: 0\|1\|2}}, roles: {"#<userId>": role}}`. `meta.version` (matrix), `meta.roleVersions` |
| GET | `/wallet-balances` | Vault-side saldo per wallet (`wallets: [{wallet, nama, saldo}]`, `total`, stored group→wallet `peta`) + the blob version. No mutasi, piutang or investor lists. Kompas sales are added client-side from kompas state (module `finance` already reads it). |
| PUT | `/access/matrix` | **Module admin only.** `{matrix}` is the COMPLETE set of differences from the frontend's default matrix, replaced as a whole. `tingkat` is clamped to 0–2. |
| PUT | `/access/roles/{userId}` | **Module admin only.** `{role}`; `""`/`null` returns the person to the default role (the row is deleted). The version of a person without a role is the hash of `null`. |

Validation errors keep the legacy messages, e.g. `Tanggal tidak sah.`, `Keterangan wajib diisi.`, `Nominal tidak boleh negatif.`, `Satu pos tidak boleh debet dan kredit sekaligus.`, `Pos yang sama dikirim dua kali.`, `Belum ada nominal di satu pos pun.` → `422 invalid_request`.

### Concurrency

The `kk_*` tables have no version column. A version is the first 16 hex characters of `sha1` of the value the client sees: a transaction together with its split rows, one source/category, the whole access matrix, or one role. Send it as `If-Match: "<version>"` or `?version=`. Missing → `428 version_required`. Stale → `409 version_conflict`, with the live value in `error.details.current`. Every write checks the version under `SELECT … FOR UPDATE` inside one DB transaction.

### Access

- Every endpoint needs module `finance`.
- Editing Akses Halaman additionally needs the finance **module admin** (superadmin or a finance admin). The per-page levels themselves (`tingkat`) are data the UI applies, exactly as in the old app.
- The legacy compat route stays open, as it was (security follow-up #6).

## Brankas (vault) — `/api/v1/finance/vault`

Gated by the **`brankas`** Modul (Panel Brankas). The legacy state is ONE JSON blob; v1 splits it into resources but keeps **one version for the whole blob**: its `updated_at` (ms). Every read returns it as `meta.version`, and every write needs it (`If-Match` or `?version=`) and returns the new one. Missing → `428`; stale → `409 version_conflict` with `{data, version}` of the current state. Writes record the **session user** as `updated_by`. Account balances are not stored: the screen derives them from kompas' sales recap, as in the old app.

| Method | Path | Notes |
|---|---|---|
| GET | `/` | Same as legacy `brankasGet`: `{data: {rekening, piutang, bayar, investor, mutasi, setting}, akses, peran, updated_at}` |
| GET | `/{list}` | `list` is one of `rekening` (opening balances), `piutang` (receivables), `bayar` (payment planning), `investor`, `mutasi` (wallet transfers) |
| GET | `/{list}/{id}` | One record |
| POST | `/{list}` | Body = the record. `id` is optional (generated). `201`, or `409 already_exists` |
| PUT / PATCH | `/{list}/{id}` | Replace / shallow merge |
| DELETE | `/{list}/{id}` | `{deleted: true}` |
| GET / PUT | `/setting` | The settings map (e.g. payment method → bank). PUT body `{value: {...}}` |
| GET | `/access` | `{matrix, roles}` of the Brankas pages. `meta.version` = hash of the matrix |
| PUT | `/access/matrix` | **Module admin only.** `{matrix}`, the complete matrix, `tingkat` clamped to 0–2. Version = hash of the matrix you read. |
| PUT | `/access/roles/{userId}` | **Module admin only.** `{role}` (`""`/`null` = default). Version = hash of the current role (of `null` when none). |

Kas Kecil's **payment plan** lives in the same blob (`bayar`), and the Kas Kecil panel (module `finance`) writes it narrowly:

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/finance/petty-cash/payment-plan` | `data` is the **plain array** of plan rows (not `{bayar: [...]}`), + the blob version in `meta.version` |
| PUT | `/api/v1/finance/petty-cash/payment-plan` | Body `{bayar: [...]}` **replaces the whole list** (legacy `bayarSave`); the rest of the vault is untouched. Sending one row leaves one row. |
| GET | `/api/v1/finance/petty-cash/wallet-balances` | Vault-side saldo per wallet for Payment Planning (`wallets: [{wallet, nama, saldo}]`, `total`, stored group→wallet `peta`) + the blob version. Computed by `Brankas::walletBalances()` — the same service and blob read as `GET /vault`, so the two can never disagree on the vault half. No mutasi, piutang or investor lists. |

GET returns the list directly:

```json
{ "data": [{ "id": "b1", "status": "plan" }], "meta": { "version": 1728288000000 } }
```

PUT sends the complete new list (version required):

```json
{ "bayar": [{ "id": "b1", "status": "plan" }] }
```

Other modules (kompas' investor page) read the vault in-process through `AppModulesinanceservicesbrankas::read()`.

## Invoices & kwitansi — `/api/v1/finance/invoices`

One request per reservation or marketing deal (`resId`, unique). A decision moves the same row from `MENUNGGU` to `DIBUAT` (issued) or `DITOLAK` (rejected). There are three kinds: `KWITANSI` (Reservasi DP receipt), `INV_DP` and `INV_LUNAS` (Marketing invoices). An unknown kind becomes `KWITANSI`.

- **Numbering:** `PREFIX/YYYY/MM/NNNN` (WIB month) is the month's highest + 1, taken once. A number is never reused: `batal` keeps it. The invoice kinds use `prefixInvoice`, the kwitansi `prefix`; the same prefix means one shared sequence.
- **Signatories:** names and titles are copied into the row when it is issued. Images are read live when the document is printed. A signatory already used on an issued document cannot be deleted (deactivate it instead).

**Callers** (Reservasi, Marketing, Finance): need **any of** modules `finance`, `reservasi`, `marketing`.

| Method | Path | Notes |
|---|---|---|
| POST | `/requests` | `{resId, jenis?, ringkas?}`. Idempotent per `resId`: an issued one is returned untouched; a waiting or rejected one is refreshed into a live request. `mintaOleh` is the **session user**. |
| GET | `/status?res=a,b,…` | `{resId: request}` for at most 400 ids, without images |
| GET | `/file/{resId}` | The printable document of an **issued** request: `{jenis, no, putusOleh, putusAt, cap, penanda[{nama, jabatan, ttd}]}`. `404 not_issued` otherwise. |

**Finance** (module `finance`):

| Method | Path | Notes |
|---|---|---|
| GET | `/` | The queue (`?status=MENUNGGU\|DIBUAT\|DITOLAK`, `?jenis=`), newest request first. `meta.queue` (counts per kind), `meta.versions` |
| GET | `/queue` | `{total, perJenis}`, the menu badge |
| GET | `/{id}` | One request + version |
| POST | `/{id}/decision` | `{aksi: buat\|tolak\|batal, catatan?, penanda?: [signatoryId…]}`. `penanda` absent = the kind's default signatories; `[]` = deliberately unsigned. `tolak` requires a `catatan`. `putusOleh` is the **session user**. Needs the request's version. |
| GET / PUT | `/settings` | `prefix`, `prefixInvoice`, `cap` (stamp image), `penandaDefault`, `penandaDefaultInvoice` (comma-separated signatory ids). Only the keys sent are written. PUT needs the version. |
| GET / POST | `/signatories` | `{nama, jabatan?, ttd? (data URI), urut?, aktif?}`. POST → `201` |
| PATCH / DELETE | `/signatories/{id}` | PATCH writes `ttd` only when sent. DELETE is refused (`422`) once the signatory is used. Both need the version. |

Validation errors keep the legacy messages (`resId kosong`, `alasan penolakan wajib diisi`, `nama penanda tangan wajib diisi`, …) → `422 invalid_request`. Versions are content hashes (`If-Match` / `?version=`; `428` / `409`).

## Tagihan Rutin — `/api/v1/finance/tagihan`

Recurring subscriptions (wifi, Claude, ChatGPT, Spotify, YouTube): *when is each due* and *how much have we paid in total*. Port of `finance-mysql/lib_tagihan.php` (`tagihanList/Simpan/Aktif/Bayar/Batal`), gated by module `finance`. No versioning: the legacy actions carry none, and concurrent edits to different bills never collide; the one true race (two people paying the same due date) is guarded server-side in the payment transaction.

**Two tables, and the split is what keeps the totals trustworthy:** `kk_tagihan` (the subscriptions — what CHANGES) and `kk_tagihan_bayar` (one row per payment that really happened, with the nominal AS OF THEN — what must never change). The tables are created by the old PHP at runtime, never by a migration: until they exist the endpoints answer `503 tagihan_unavailable`.

| Method | Path | Notes |
|---|---|---|
| GET | `/` | `{tagihan: [...], bayar: [...]}` (the `tg_baca` shape), tagihan by `aktif` then `nama`, payments newest first |
| POST | `/` | Create a tagihan (`tg_simpan` without id). → `201` the new tagihan |
| PATCH | `/{id}` | Update a tagihan. Partial bodies are merged over the current row first (legacy defaults would wipe unsent fields) |
| PUT | `/{id}/active` | `{aktif: bool}` — deactivate (or reactivate); a tagihan is never deleted |
| POST | `/{id}/payments` | Record a payment (`tg_bayar`). → `201` the new payment |
| POST | `/payments/{id}/cancel` | `{alasan*}` — cancel a payment (`tg_batal`); there is no DELETE |

Shapes (legacy field names, camelCase as stored):

- tagihan: `{id, nama, kategori, nominal, siklus, mulai, metode, catatan, aktif, dibuatOleh, dibuatAt, diubahOleh, diubahAt}`. `mulai` is `""` when the date is not known yet.
- payment: `{id, tagihanId, periode, tglBayar, nominal, catatan, oleh, at, batalAt, batalOleh, batalAlasan}`. `batalAt` is `null` while live.

Rules (each covered by `tests/Feature/Finance/TagihanRutinTest.php`):

- `siklus` is one of 1, 2, 3, 6, 12 months; anything else → `422 invalid_request`.
- The nominal is COPIED onto the payment row as typed. "Sudah dibayar" totals = live rows only, never price × months.
- Due dates are NOT stored. The client computes them from `mulai` + `siklus` (add months, clamp day 31 to the month's last day; sweep from the first due date, count past-unpaid as late). The server only guards that one due date is not paid twice: `tg_bayar`'s transaction + `FOR UPDATE` on the tagihan row, cancelled payments excluded. A duplicate → `422` naming who recorded the first payment.
- No DELETE for payments: cancel with a required `alasan`, once only. A tagihan is deactivated, never deleted.
- `dibuatOleh` / `diubahOleh` / `oleh` / `batalOleh` always come from the Bearer token, never the body.
- Every SQL placeholder is used once per statement (`EMULATE_PREPARES=false` binds by position).

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted`, `forbidden` |
| 404 | `not_found` |
| 409 | `version_conflict` |
| 422 | `validation_failed`, `invalid_request` |
| 428 | `version_required` |
| 503 | `tagihan_unavailable` |
