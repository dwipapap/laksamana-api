# event — EMS (events, talent, schedules)

- **Legacy source:** `laksamana-office/event-mysql/`
- **Legacy URL:** `/event-api-mysql/api.php`
- **Database:** `lakk5493_db_ems`. This database is **shared with ticketing**: ticketing writes to its own tables (`seat_holds`, `tix_*`) but also to `orders`, `tickets` and `seats`.
- **Data dir:** `/home/lakk5493/event-db`, set via env `EVENT_DATA_DIR`. Files live under `files/`.
- **Reuse:** the RowSync helper.

## Tables

Each collection is stored as indexed columns plus a `data` column.

| collection | table | notes |
|---|---|---|
| talents | `talents` | |
| events | `events` | `start_datetime` / `end_datetime` are converted from ISO to **WIB** DATETIME. Also: status, venue, pic, is_ticketed, idea_id. |
| schedules | `schedules` | tanggal, start/end_time, talent_id, event_id, fee, status, source. Index on (talent_id, tanggal, status). |
| recurringRules | `recurring_rules` | |
| talentPayments | `talent_payments` | |
| ticketClasses | `ticket_classes` | |
| seats | `seats` | |
| orders | `orders` | `created_at` comes from the frontend field `created`. |
| tickets | `tickets` | UNIQUE `qr_token`. |
| ideas | `ideas` | |
| refunds | `refunds` | |
| calendarExtra | `calendar_extra` | |
| eventDetails | `event_details` | PK `event_id`; the frontend treats it as a map keyed by event id. |
| checkins | `checkins` | **Append-only** (INSERT IGNORE). Reads return at most 2000 rows. |
| (settings) | `settings` | Key-value: entertainmentRules, role, layoutTemplates. |

## Actions

All actions are open unless the optional `API_TOKEN` is set.

- **`getAll`** (GET, default).
- **`saveAll`**:
  - Upsert protected only by the `updated_at` guard; there is no `baseUpdatedAt` check.
  - Deletes rows whose id is `NOT IN` the payload **and** whose `updated_at <=` the payload's max `updatedAt`.
  - An empty payload deletes nothing.
  - Ticketing's hold table is deliberately separate so this save cannot wipe seat holds.
- **`eventsHari`** (`tgl`):
  - Returns events on that date whose status is **not** Planning, Draft or Cancelled.
  - Response: `{events:[{id,nama,status,venue,picName,inputOleh,inputOlehId,mulai}]}`.
  - `inputOleh` / `inputOlehId` are read from `data.createdBy` / `createdById`.
- **`upload`** (`{dataBase64,fileName,mimeType}`):
  - Images or PDF only, max 8 MB.
  - Response: `{key:'ev_<hex>.<ext>',name,size,at}`.
  - **No orphan-file cleanup**, on purpose: files include talent ID cards (KTP).
- **`file`** (`key`): streams the file.
- **`ping`**, **`stats`**.

## v1 proposal

- `/api/v1/event/events`
- `/api/v1/event/talents`
- `/api/v1/event/schedules`
- `GET /api/v1/event/events-on/{date}`
- `/api/v1/event/files`
