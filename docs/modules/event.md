# event — EMS (events, talent, schedules)

**Status: done (M11).** Compat on `/event-api-mysql/api.php`, v1 in [docs/api/event.md](../api/event.md), parity `tools/parity/cases/event.json`, e2e `tools/e2e/event.mjs`.

- **Legacy source:** `laksamana-office/event-mysql/`
- **Legacy URL:** `/event-api-mysql/api.php`
- **Database:** `lakk5493_db_ems`. This database is **shared with ticketing**: ticketing writes to its own tables (`seat_holds`, `tix_*`) but also to `orders`, `tickets` and `seats`. Both default to one Laravel connection (`legacy_ems`), which keeps the server `sql_mode` (#97: production is non-strict, e.g. an over-long phone is truncated, not refused). On `core` (always strict) the service truncates an over-long indexed string to the column width explicitly, as non-strict MySQL did. During consolidation they have independent switches (`DB_EVENT_CONNECTION` / `DB_TICKETING_CONNECTION` and `EVENT_MAINTENANCE` / `TICKETING_MAINTENANCE`) so one Modul can be imported without moving the other.
- **Data dir:** `/home/lakk5493/event-db`, set via env `EVENT_DATA_DIR`. Files live under `files/`.
- **Code:** `app/Modules/Event` (`EventSchema`, `EventState`, `EventFiles`, `EventRecords`) on the RowSync helper.

## Tables

Each collection is stored as indexed columns plus a `data` column (the source of truth, stored verbatim — even a `baseUpdatedAt` a client sends is kept).

| collection | table | notes |
|---|---|---|
| talents | `talents` | has `created_at` (from `createdAt`) |
| events | `events` | `start_datetime` / `end_datetime`: `strtotime()` of the app value shown in **WIB** — ISO `Z`/offset values convert properly, a zone-less value is read as UTC (PHP default zone) and shifted +7h, like legacy. Also: status, venue, pic, is_ticketed, idea_id, `created_at`. |
| schedules | `schedules` | `tanggal` ← app `date`; start/end_time = first 8 chars; talent_id, event_id, fee, status, source. Index on (talent_id, tanggal, status). |
| recurringRules | `recurring_rules` | |
| talentPayments | `talent_payments` | |
| ticketClasses | `ticket_classes` | |
| seats | `seats` | |
| orders | `orders` | `created_at` comes from the frontend field `created` (ISO), not `createdAt`. |
| tickets | `tickets` | UNIQUE `qr_token` — but see #100: the upsert's ON DUPLICATE KEY UPDATE fires on it. |
| ideas | `ideas` | |
| refunds | `refunds` | |
| calendarExtra | `calendar_extra` | `tanggal` ← app `date` |
| eventDetails | `event_details` | PK `event_id`; the frontend treats it as a map keyed by event id. |
| checkins | `checkins` | **Append-only** (INSERT IGNORE). Reads return the newest 2000. `checked_in_at` in WIB. |
| (settings) | `settings` | Key-value: entertainmentRules (default `[]`), role (default `"Director"`), layoutTemplates (default `[]`). |

String columns get PHP's `(string)` cast (an array becomes `"Array"`), numbers `intval`, flags `0/1`.

## Actions

All actions are open unless the optional `API_TOKEN` is set. A GET without `action` is `getAll`; `?action=` (empty) is an unknown action. `tgl` and `key` come from the query string only.

- **`getAll`** (GET, default). Collections in `created_at, id` order (or `id`), then `checkins`, `eventDetails`, the three settings.
- **`saveAll`** (`data`), under `GET_LOCK('<db>:ems_save', 10)` (busy: `Server sedang sibuk menyimpan, coba lagi sebentar.`), in one transaction. The lock is taken before the payload is checked (`Payload data kosong/invalid`).
  - Upsert protected only by the `updated_at` guard (`>=` wins); there is no `baseUpdatedAt` check and no cap bump.
  - **Changed (#100, owner decision 2026-09-27 — fix, not reproduce):** a payload whose ticket `qr_token` already belongs to **another** ticket id refuses the whole save **before anything is written** with `{ok:false,error:"qr_token ganda: <token>"}`. Legacy let the upsert's `ON DUPLICATE KEY UPDATE` fire on the UNIQUE `qr_token`, silently rewriting the other ticket's row — and the bounded delete then removed it (silent ticket loss). v1 already answers 409 `duplicate` for the same case.
  - Deletes rows whose id is `NOT IN` the payload **and** whose `updated_at <=` the payload's max `updatedAt` (rows without an id do not count). When no row carries a stamp, the delete has **no bound**. An empty list deletes nothing. A collection not sent is untouched. Consequence: deleting the newest-stamped row of a collection is silently undone (#103).
  - `checkins`: INSERT IGNORE, never updated or deleted. `eventDetails`: upsert with the same guard, missing entries deleted without a bound.
  - Settings written only when sent and not `null`.
  - Response `{saved:true, jumlah:{<collection>:n,…}, backend, ts}`.
  - Ticketing's hold table is deliberately separate so this save cannot wipe seat holds.
- **`eventsHari`** (`tgl`):
  - Returns events on that date whose status is **not** Planning, Draft or Cancelled (drop list on purpose: old rows still say Today/Finished).
  - Response: `{events:[{id,nama,status,venue,picName,inputOleh,inputOlehId,mulai}]}`, ordered by start, title.
  - `inputOleh` / `inputOlehId` are read from `data.createdBy` / `createdById` (`''` when absent).
  - Bad date: `tanggal tidak sah: <tgl>`.
- **`upload`** (`{dataBase64,fileName,mimeType}`):
  - Checks in order: `file kosong`, `hanya gambar (jpg/png/webp/gif) atau PDF`, `base64 tidak valid`, `file melebihi 8MB`.
  - Response: `{key:'ev_<hex>.<ext>',name,size,at}`.
  - **No orphan-file cleanup**, on purpose: files include talent ID cards (KTP).
- **`file`** (`key`): streams the file; empty key or `..` → 400, missing → 404 (empty bodies).
- **`ping`**, **`stats`** (`env`, `db`, `versi` 2026-08-11, per-table counts, `blobChars`/`blobMB`).

## Accepted differences

- `backend` in ping/stats/saveAll says `laravel` (was `php-mysql`).
- Legacy printed a PHP warning into the JSON when an array reached a string column (dev servers with display_errors); Laravel answers clean JSON and stores `"Array"`, as production did.
- The data-dir fallbacks (`event-db` next to public_html, `db/` in the backend) are not reproduced: `EVENT_DATA_DIR` applies.
- DB errors answer `kesalahan database` outside debug mode (project convention).

## #22 check (v1 per screen)

Checked against #22's list on 2026-09-25. The v1 that landed with #21 (PR #105) covers every item:

| #22 item | v1 |
|---|---|
| events, talents, schedules, recurring rules, talent payments | `/events`, `/talents`, `/schedules`, `/recurring-rules`, `/talent-payments` |
| ticket classes and seats | `/ticket-classes`, `/seats` |
| orders / tickets / refunds (read side) | `GET /orders`, `/tickets`, `/refunds` (writes as well, version-guarded) |
| ideas | `/ideas` |
| check-ins | `GET/POST /checkins` (append-only) |
| files | `POST /files`, `GET /files/{key}` |
| event details, settings, finance's per-day list | `/event-details`, `/settings`, `/events-on/{date}` |

Re-run from main: `php artisan test tests/Feature/Event` 26 passed, `node tools/parity/parity.mjs event` 65/65 identical, `node tools/e2e/event.mjs` 24/24. The milestone is M11 (`m11-event`).
