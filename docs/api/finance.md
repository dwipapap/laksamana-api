# Finance API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/finance`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs module `finance`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** the legacy field names (`tgl`, `keterangan`, `kategori_id`, `baris[{pos_id, debet, kredit}]`, `nama`, `urut`, `aktif`). Amounts are whole rupiah (integers).

Status: **Kas Kecil + Akses Halaman** (#25) are in place. Brankas and the invoice/kwitansi flow will be added by their own issues.

## Kas Kecil — `/api/v1/finance/petty-cash`

**Balances are never stored.** Clients compute them from the full history (sorted by `tgl`, then `id`), exactly as the old page does.

### Screen → endpoint map

The Kas Kecil Panel (`deploy/finance/kas`), pages of this cluster:

| Page | Endpoints |
|---|---|
| Input Transaksi (`kk_input`) | `POST /transactions`, `PUT /transactions/{id}` |
| Buku Kas (`kk_buku`) | `GET /transactions?from&to`, `PATCH /transactions/{id}` (Input/Bon ticks), `DELETE /transactions/{id}` |
| Pos & Kategori (`kk_pos`) | `/sources`, `/categories` |
| Akses Halaman (`akses`) | `GET /access`, `PUT /access/matrix`, `PUT /access/roles/{userId}` |

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
| PUT | `/access/matrix` | **Module admin only.** `{matrix}` is the COMPLETE set of differences from the frontend's default matrix, replaced as a whole. `tingkat` is clamped to 0–2. |
| PUT | `/access/roles/{userId}` | **Module admin only.** `{role}`; `""`/`null` returns the person to the default role (the row is deleted). The version of a person without a role is the hash of `null`. |

Validation errors keep the legacy messages, e.g. `Tanggal tidak sah.`, `Keterangan wajib diisi.`, `Nominal tidak boleh negatif.`, `Satu pos tidak boleh debet dan kredit sekaligus.`, `Pos yang sama dikirim dua kali.`, `Belum ada nominal di satu pos pun.` → `422 invalid_request`.

### Concurrency

The `kk_*` tables have no version column. A version is the first 16 hex characters of `sha1` of the value the client sees: a transaction together with its split rows, one source/category, the whole access matrix, or one role. Send it as `If-Match: "<version>"` or `?version=`. Missing → `428 version_required`. Stale → `409 version_conflict`, with the live value in `error.details.current`. Every write checks the version under `SELECT … FOR UPDATE` inside one DB transaction.

### Access

- Every endpoint needs module `finance`.
- Editing Akses Halaman additionally needs the finance **module admin** (superadmin or a finance admin). The per-page levels themselves (`tingkat`) are data the UI applies, exactly as in the old app.
- The legacy compat route stays open, as it was (security follow-up #6).

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted`, `forbidden` |
| 404 | `not_found` |
| 409 | `version_conflict` |
| 422 | `validation_failed`, `invalid_request` |
| 428 | `version_required` |
