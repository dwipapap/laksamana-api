# bd — Business Development OS

- **Legacy source:** `laksamana-office/bd-mysql/`
- **Legacy URL:** `/bd-api-mysql/api.php`
- **Database:** `lakk5493_db_bd`
- **Reuse:** RowSync

## Tables

Every table has these columns in addition to the ones listed: `id`, `created_at`, `updated_at`, `data`.

The app field `div` is stored in a column named **`divisi`**, because `DIV` is a MySQL keyword. Always backtick-quote identifiers.

| App key | Table | Indexed columns |
|---|---|---|
| people | `people` | name, role, divisi, boss_id (from `boss`), office_user_id (from `officeUserId`), active |
| projects | `projects` | name, type, stage, divisi, pic (first of `pics`), start_date, end_date, budget, spent, health |
| tasks | `tasks` | name, divisi, pic (first of `pics`), status, priority, deadline, important, urgent, type, project_id, progress |
| routines | `routines` | name, divisi, pic, freq, important, urgent, active |
| coord | `coord_requests` | title, from_div, to_div, requested_by, assignee, status, priority, due_date, project_id |
| po | `purchase_orders` | item, vendor, qty, unit, divisi, amount, payment (NULL means undecided), status, need_by, pic, project_id, pr_id |
| pr | `purchase_requests` | no, nama, dept, tanggal, week_start, status, total |
| agenda | `agenda` | title, tanggal, type, divisi |
| — | `settings` (key/value) | keys: `focus`, `approverSets`, `promos` (`promos` is read by radar and kompas) |

## Actions

Access control: open, or protected by `API_TOKEN` if one is configured.

| Action | Behaviour |
|---|---|
| `getAll` (GET, default) | Returns the full state plus `_serverTs` (server time in ms). |
| `saveAll` | See "saveAll" below. |
| `addPo` | Takes `{items[] ≤ 200}`. **Insert only** (INSERT IGNORE). The server generates each id as `po<hex>` and defaults `status` to `Diajukan`. Called by the Marketing module. |
| `setRealisasi` | Takes `{id, realisasi, oleh}`. See "setRealisasi" below. Called by the finance/kas module. |
| `ping` | Includes `LIB_VERSI`. |
| `stats` | — |

### saveAll

Request body: `{data, sinceTs}`.

- **Upsert:** rows are written only with the `updated_at` ordering guard. There is no `baseUpdatedAt` conflict check.
- **Delete:** removes rows that are `NOT IN` the sent ids **and** have `updated_at <= sinceTs`.
  - If `sinceTs` is 0, the payload's maximum `updatedAt` is used instead.
- **Empty payload:** when `sinceTs` is present, an empty payload is trusted, so it **deletes**.
- **Response:** includes `tsMs`.

### setRealisasi

- Changes only `data.realisasi`, plus `proses`, `prosesAt`, `prosesBy` and `statusSebelum`.
- Sets `status` to `'Diterima'` (`PO_SELESAI_PHP`).
- An empty value reverts all of these changes.

## Proposed v1 API

- `/api/v1/bd/projects`
- `/api/v1/bd/tasks`
- `/api/v1/bd/purchase-orders`
- `/api/v1/bd/purchase-requests`
- `/api/v1/bd/promos`
