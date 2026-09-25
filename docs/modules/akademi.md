# akademi — training platform

| | |
|---|---|
| Legacy source | `laksamana-office/akademi-mysql/` (uploaded manually) |
| Legacy URL | `/akademi-api-mysql/api.php` |
| Database | `lakk5493_db_akademi` |
| Data dir | `/home/lakk5493/akademi-db` (env `AKADEMI_DATA_DIR`) |

Reuse RowSync.

**Legacy bug, not to copy:** when `DATA_DIR` is unset, legacy falls back to `../../../marketing-db`. The Laravel port uses the explicit env value instead.

## Tables (schema.sql only)

| table | key | columns / notes |
|---|---|---|
| `users` | PK id | name, role, divisi (from `division`), title, active, updated_at, data |
| `divisions` | PK id | name, updated_at, data |
| `materials` | PK id | title, kind (from `type`), cat, mandatory, published, passing, created_at, updated_at, data (`division[]` inside data) |
| `programs` | PK id | title, bulan, deadline DATE, created/updated, data |
| `progress` | **composite PK (user_id, material_id)** | done, score, at_ms, updated_at, data. Frontend map: `progress[uid][mid]` |
| `prog_prog` | **PK (user_id, program_id, material_id)** | Frontend map: `progProg[uid][pid][mid]` |
| `activity` | PK id = `'ac_'+sha1(ts|userId|action|detail)` | Append-only, INSERT IGNORE, trimmed to 5000. The frontend sends no id, so the server derives it. |
| `settings` | PK k | Key/value: settings, version, createdAt, `extra:*` |

## Actions

Everything is open, subject only to the optional `API_TOKEN`.

- `getAll` (GET, default). Returns full state; activity is limited to 1000 rows.
- `saveAll` (`{data}`).
  - Upserts rows.
  - A row with `baseUpdatedAt` older than the server version is rejected into `bentrok[]`; `ok` still returns true.
  - A row that passes gets `updated_at` = server + 1.
  - Rows missing from the payload are hard-deleted (strategy `notIn`).
  - After commit, `gc_receipts` deletes orphan files older than 1 hour. `key_terpakai` scans only `materials`.
- `trainingStats` (GET). Returns per-user `{mandPct, mandTotal, mandDone, total, done, certified}`. Read by the hr module.
- `uploadReceipt`. Accepts jpg/png/webp/gif/pdf, or `.bin` for other types; max 8 MB; returns key `rc_<hex>.<ext>`.
- `receipt` (GET `key`). Streams the file.
- `ping`, `stats`.

## v1 (implemented — contract: docs/api/akademi.md)

- 4 resources (`users`, `divisions`, `materials`, `programs`): reads for every
  module holder, writes for module admins (the Kelola screens are admin-only
  in the app). Full CRUD, one record per request, explicit per-record
  delete. No whole-state save — the legacy unbounded-delete behaviour cannot
  happen here.
- `PUT /progress/{user}/{material}` and
  `PUT /program-progress/{user}/{program}/{material}` address the composite-key
  cells one by one (the entry object is stored as-is). Same version rule as
  records.
- `GET /training-stats` (+ `/{user}`) serves the HR read model: per-user
  `{mandPct,mandTotal,mandDone,total,done,certified}`, mirroring the client's
  `userStats` exactly. Percentages only, no material or quiz content.
- Files: `POST /files` (multipart `file`, or JSON
  `{dataBase64,mimeType,fileName}`; ≤8 MB, images/PDF) returns
  `{key,name,url}`; `GET /files/{key}` streams. Store the key inside the
  record; files no `materials` row references are hard-deleted 1 hour after
  upload.
- Concurrency: a record's/cell's version is its `updated_at` column (ms).
  Writes need it (`If-Match` header or `version` in the body / query); a
  successful write stamps `max(now, stored+1)` — the same guards under the
  same `akademi_save` lock as `saveAll`, so a v1 version is a valid
  `baseUpdatedAt` for old tabs. An identical-content rewrite is a no-op
  success; every record write appends an `activity` entry.
- `GET /state` (one-call bootstrap) + `GET /stats`, `GET /activity` +
  `POST /activity`, `GET|PUT /documents/{settings,version,createdAt}`
  (whole-object, content-hash versioned; writes admin-only).
