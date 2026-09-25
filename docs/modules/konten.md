# konten — content production

- **Legacy source:** `laksamana-office/konten-mysql/`
- **Legacy URL:** `/konten-api-mysql/api.php`
- **Database:** `lakk5493_db_konten`
- **Data dir:** `/home/lakk5493/konten-db`, via `KONTEN_DATA_DIR`
- **Deployment:** the legacy backend was uploaded manually; it was never in CI.

This is an older variant of marketing's saveAll. Reuse `App\Support\RowSync` (built with marketing).

## Tables (schema.sql)

- Collections, each with `id` PK, `updated_at` BIGINT, `data` JSON, plus indexed columns:
  - users, brands, campaigns, content (+created_at), prod_tasks, shootings, assets, bank, kols, visits, ads, ad_funds, notifs
- `logs`: append-only, trimmed to 5000 rows.
- `settings`: key/value store (settings, perms, seeded, `extra:*`).
- Content performance data lives in `content.data.perf` (per platform).
- **Keep `bank`** even though its screen was removed. Dropping it from the collections would empty the table.

## Actions (all open)

- `getAll`, `stats`, `ping`.
- `receipt&key`: streams a stored file.
- `uploadReceipt {dataBase64,mimeType,fileName}`:
  - max 8 MB;
  - returns `{key,name}`;
  - stores `<DATA_DIR>/receipts/rc_<hex>.<ext>`.
- `saveAll {data}`:
  - runs under `GET_LOCK('<db>:konten_save')`;
  - returns `{saved,jumlah,berkasDibuang,bentrok[],backend,ts}`.

## saveAll rules (legacy)

- **Conflict check:** if `baseUpdatedAt` is older than the server's `updated_at`, the row goes into `bentrok`.
- **Upsert guard:** `IF(VALUES(updated_at)>=updated_at,…)`, bumping the timestamp by +1 when needed.
- **Missing collections** in the payload are skipped.
- **Empty lists** never delete anything.
- **LEGACY BUG — reproduce on the compat route:** `hapus_yang_hilang` has NO `_sejak` bound. It deletes every row not in the payload, including rows created after the client loaded. Document this; v1 has no whole-state save.
- **GC:** hard-unlinks orphan receipt files after 1 hour (no trash folder).

## v1 (implemented — contract: docs/api/konten.md)

- 13 resources (`users`, `brands`, `campaigns`, `content`, `prod-tasks`,
  `shootings`, `assets`, `bank`, `kols`, `visits`, `ads`, `ad-funds`,
  `notifications`): full CRUD, one record per request, explicit per-record
  delete. No whole-state save — the legacy `_sejak` data-loss bug cannot
  happen here.
- `PUT /content/{id}/performance` merges one platform's figures into
  `content.data.perf` (plus an `{at, by}` stamp). Read models aggregate
  client-side from the content rows, exactly as the old app does.
- Files: `POST /files` (multipart `file`, or JSON
  `{dataBase64,mimeType,fileName}`; ≤8 MB, images/PDF) returns
  `{key,name,url}`; `GET /files/{key}` streams. Store the key inside the
  record; files no record references are hard-deleted 1 hour after upload.
- Concurrency: a record's version is its `updatedAt` (ms). Writes need it
  (`If-Match` header or `version` in the body / query); a successful write
  stamps `max(now, stored+1)` — the same guards under the same
  `konten_save` lock as `saveAll`, so a v1 version is a valid
  `baseUpdatedAt` for old tabs. An identical-content rewrite is a no-op
  success; every write appends a `logs` entry (`<Label> … (API)`).
- `GET /state` (one-call bootstrap) + `GET /stats`, `GET /logs` +
  `POST /logs`, `GET|PUT /documents/{settings,perms,seeded}` (whole-object,
  content-hash versioned).
