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

## v1 proposal

- `/api/v1/akademi/materials`, `/programs`, `/progress`, `/training-stats`.
- File upload/download.
