# marketing — CRM, events, Reservasi VIP, Request Design — DONE (M3, frontend verified M4)

Ported: `app/Support/RowSync.php`, `app/Modules/Marketing/Services/{MarketingSchema,MarketingState,MarketingFiles,MarketingQueries,MarketingRecords}.php`, legacy controller, v1 (`docs/api/marketing.md`), tests `tests/Feature/Marketing` + `tests/Unit/RowSyncTest.php`, parity 46/46. The unchanged `deploy/marketing` page runs end to end on Laravel: `node tools/e2e/marketing.mjs` (25/25). Legacy getAll decodes JSON as assoc arrays (empty object -> []), kept for fidelity.

- Legacy source: `laksamana-office/marketing-mysql/` (`api.php`, `lib_marketing_mysql.php`, `.user.ini`: post_max_size 64M, memory 256M)
- Legacy URL: `/marketing-api-mysql/api.php`
- Database: `lakk5493_db_marketing`
- Data dir: `/home/lakk5493/marketing-db` → set with `MARKETING_DATA_DIR` or `Modules::dataDir('marketing')`
- `LIB_VERSI = '2026-08-11'`; CI checks that `ping` reports it.
- Used by:
  - finance omset → `eventsHari`
  - reservasi → `dpMasuk`
  - konten → `designReqs` / `designReq` / `designReqSet`
  - kompas → `getAll`, used for the investor agenda
- **Before porting, read the babak 1–7 notes in `laksamana-office/CLAUDE.md`** ("Data Marketing hilang sendiri" and what follows). Every rule below exists because data was lost.

## This module creates the shared `app/Support/RowSync.php`

- Design RowSync from marketing, with options for:
  - conflict detection
  - sidik exemption
  - delete strategy
  - append-only collections
  - composite keys
  - kol_settings collections
- konten, akademi, bd, event, hr and hlife reuse it (see their specs).

## Tables (from schema.sql; all `id` PK + `updated_at` BIGINT ms + `data` LONGTEXT + indexed columns)

- Row tables:
  - `clients` and `events` (both also have `created_at`; events has `idx_ev_tanggal`)
  - `followups`, `approvals`, `users`, `staff`, `task_templates`, `task_categories`, `categories`, `notifs`
- `activities`: append-only, INSERT IGNORE, trimmed to 5000 rows.
- `settings (k,v)`:
  - Scalars: `settings`, `baseline`, `rolePerms`, `roleNav`.
  - **`kol_settings()` collections**: `extra:designreqs` and `extra:vip`. These are id-keyed JSON row arrays merged PER ROW with the same guards as real tables.
  - Other keys: `extra:designreqprog`, `extra:designreqopsi`, other `extra:*`.

## Actions (all open)

| action | notes |
|---|---|
| `getAll` GET | State S plus `_versi` = max `updated_at` over all tables AND kol_settings rows (`versi_baris()`). |
| `stats`, `ping` | `ping` returns `identitas()`: env, db, versi (LIB_VERSI), aksi list, maksUnggahMB. |
| `eventsHari` (`tgl`) | Events with status Deal / Event Done in a 60-day window via idx_ev_tanggal, then filtered in PHP. Includes multi-day events (`tanggalSelesai` in `data`) and computes `hari` and `totalHari`. Returns `{events[{id,nama,…,menuFix,hari,totalHari,detail}], vip: vip_hari(), settings{serviceCharge,pb1}}`. `vip_hari` excludes rows with `batalAt`. |
| `dpMasuk` (`dari`, `sampai`) | Filters by EVENT date in SQL (`e.tanggal BETWEEN`), LEFT JOIN clients. Includes event payments[] plus VIP `bukti`. `cap_ke_tanggal` for VIP proof caps (ms epoch or date string; unreadable → empty). Returns `{baris[], total, tanpaTanggal, vipTerkunci{n,total}, luar{n,total,dari,sampai}}`. `luar` is a count-only query. |
| `designReqs` (`aktif=1`) | `{reqs[], opsi}`: excludes rows with `batalAt` and merges progress from `designreqprog`. |
| `designReq` (`id`) | One request including reference images (data URIs). |
| `designReqOpsi` (`opsi{brands,pics,platforms}`) POST | Under lock; an empty push is ignored. |
| `designReqSet` (`id`, `status`, `picNama`) POST | Under lock; writes ONLY `designreqprog`. |
| `receipt&key` GET | Streams a file from `<DATA_DIR>/receipts/`. |
| `uploadReceipt` (`dataBase64`, `mimeType`, `fileName`) POST | Max 40 MB. Returns `{key:'rc_<hex>.<ext>', name}`. |
| `uploadChunk` (`uploadId`, `seq`, `last`, `dataBase64`, `mimeType`, `fileName`) POST | ~2 MB chunks appended to `receipts_tmp/up_<id>.part`, renamed on the last chunk. |
| `saveAll` (`data:{…, _sejak}`) POST | Under `GET_LOCK('<db>:mkt_save')`. Returns `{saved, jumlah, berkasDibuang, bentrok[], versi{'<koleksi>:<id>': ts}, …}`. |

## saveAll rules (the heart of RowSync)

1. **Conflict check.** For each row with `baseUpdatedAt` (the client marks it as edited): if the server's `updated_at` is newer than `baseUpdatedAt`, it is a conflict, reported in `bentrok[]` and not written.
   - **Exception:** if `sidik_baris(stored) === sidik_baris(sent)`, it is NOT a conflict. Compare key-sorted deep JSON without `updatedAt`/`baseUpdatedAt`, with lists kept in order. Return the server version in `versi`.
2. **Cap bump (`cap_tulis`).** If the client timestamp is not above the server's, bump it to server+1 and return it in `versi`. The timestamp actually used is written into `data.updatedAt`. Timestamps come from the CLIENT clock; do not switch to server time (babak 5 explains why).
3. **Skip unchanged rows.** A row with no `baseUpdatedAt` and the same timestamp as stored is not written. Its id is still recorded as present so it is not deleted.
4. **Deletes (`hapus_yang_hilang`).**
   - Only rows missing from the payload AND with `updated_at <= _sejak` are deleted.
   - If `_sejak` is 0 or missing, delete nothing.
   - An empty list never deletes.
5. **Settings keys.** kol_settings names must be in `$known` and merged BEFORE the generic `extra:` branch; otherwise `put_setting` overwrites them blindly.
6. **Row id required.** Rows without an `id` are not merged.
7. **File GC.** Runs on ~1 in 20 saves. Orphans older than 7 days move to `receipts_sampah/`; trash is purged after 60 days.

## v1 proposal

- `/api/v1/marketing/{clients,events,followups,vip,design-requests}`: CRUD with an explicit `version` field or If-Match, implemented with the same per-row guard.
- `/api/v1/marketing/files` (upload + download).
- `GET /api/v1/marketing/events-on/{date}` (= eventsHari).
- `GET /api/v1/marketing/dp` (= dpMasuk).
