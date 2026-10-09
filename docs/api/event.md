# Event Planner (EMS) API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/event`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs module `event` in its effective grants (the old page's SSO gate applies the same rule).
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** records are the app's own objects (`title`, `start_datetime`, `talent_id`, `qr_token`, …), exactly as stored in each row's `data` JSON. Nothing is renamed. Bodies are stored as sent: an empty string stays `""`, it is never turned into `null`.
- **Akses Halaman:** the Panel's per-page rights (`emsPerm()` × the user's peran, and approval by *manajemen*) are enforced by the app, as they were with the old open backend. v1 gates on the module only.

Unlike the legacy `saveAll`, which sends the whole state on every save, every v1 write touches only the record, document or setting it names, and it is version-checked. All v1 writes take the same MySQL lock as legacy saves (`GET_LOCK('<db>:ems_save')`), so v1 and an old Event Planner tab serialise against each other.

## Screen → endpoint map

The Panel's menu (`TITLES` in `deploy/event/index.html`):

| Screen | Endpoints |
|---|---|
| Dashboard, Report & Analytics, Event History, Event Performance | `GET /state` (the app aggregates client-side), or the collections below |
| Event Pipeline, Approval Flow | `/events` (`status`, and the `pengajuan` object inside the record) |
| Event Calendar | `/events?from=&to=`, `/schedules?from=&to=`, `/calendar-extra?from=&to=` |
| Talent Management | `/talents`, `POST /files` for documents (KTP, NPWP, contract, portfolio) |
| Talent Schedule | `/schedules` (`talent_id`, `event_id`, `date`, `start_time`, `end_time`, `status`), `/recurring-rules`, `settings/entertainmentRules` |
| Fee & Pembayaran Talent | `/talent-payments`, `POST /files` for transfer receipts |
| Perencanaan Event | `/events`, `/event-details/{eventId}` (rundown, budget, sponsors…), `/ideas` |
| Event Management | `/events`, `/event-details/{eventId}`, `POST /files` for posters |
| Ticketing | `/ticket-classes`, `/seats`, `/orders`, `/tickets`, `/refunds`, `settings/layoutTemplates` (seat-map templates) |
| Check-In (Hari H) | `/ticket-classes?event_id=` → `/tickets?ticket_class_id=`, `/seats?event_id=` (live seat map), `GET/POST /checkins` |
| Event Idea Bank | `/ideas` |
| Performa Omset & Bonus | served by the kompas module (`GET /api/v1/kompas/performa/event`), not here |
| Hak Akses | client-side; the Panel's display role is `settings/role` |
| Finance > Omset > Breakdown Sumber (another module) | `GET /events-on/{date}` |

## Bootstrap & diagnostics

| Method | Path | Returns |
|---|---|---|
| GET | `/state` | The full state, the same as legacy `getAll`: the twelve collections, `checkins` (newest 2000), `eventDetails` (map `eventId → detail`), `entertainmentRules`, `role`, `layoutTemplates` |
| GET | `/stats` | Row count per table, `blobChars`/`blobMB` (size of the state), `env`, `db`, `versi` |
| GET | `/events-on/{date}` | `date` = `YYYY-MM-DD`. The events of that day that actually happen: status NOT Planning/Draft/Cancelled (a drop list on purpose; old rows still say `Today`/`Finished`). Items: `{id, nama, status, venue, picName, inputOleh, inputOlehId, mulai}`, `mulai` in WIB (`Y-m-d H:i:s`). `422` on a bad date. |

## Records

Resources: `talents`, `events`, `schedules`, `recurring-rules`, `talent-payments`, `ticket-classes`, `seats`, `orders`, `tickets`, `ideas`, `refunds`, `calendar-extra`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | All records, in legacy `getAll` order. `meta.total`, `meta.versions` = `{id: version}`. Filters below. |
| GET | `/{resource}/{id}` | One record. `meta.version` + `ETag`. |
| POST | `/{resource}` | Body = the record. `id` is optional: when absent, the app's `uid()` shape is generated (`id` + 7 chars of `[0-9a-z]`). `createdAt`/`updatedAt` are stamped. On `events`, `createdBy`/`createdById` are **always** the acting user (the body cannot set them); Finance reads them back through `events-on`. On `orders`, `created` (ISO) is set when absent. `201`. `409 already_exists` if the id is taken. |
| PUT | `/{resource}/{id}` | Replace the whole record (fields not sent are dropped; `createdAt`, `created`, `createdBy`, `createdById` are kept). A body `id` must match the URL. Needs a version. |
| PATCH | `/{resource}/{id}` | Shallow merge of the top-level fields. Needs a version. |
| DELETE | `/{resource}/{id}` | Needs a version. → `{deleted: true}`. Deletes only that row: removing an event does not remove its schedules or tickets (the old app cleans those up itself, one save at a time). |

**Filters** on `GET /{resource}`:

- any indexed column, exact match: `event_id`, `talent_id`, `status`, `ticket_class_id`, `order_id`, `order_item_id`, `seat_id`, `qr_token`, `payment_status`, `category`, `period_month`, `type`, …
- `from` / `to` (`YYYY-MM-DD`, inclusive) on the collection's date: `schedules` and `calendar-extra` by `date`, `events` by the WIB date of `start_datetime`, `recurring-rules` by `valid_from`
- `updatedSince` (ms): records whose version is newer

`tickets` carry no `event_id` column (legacy stores only `order_item_id`, `ticket_class_id`, …). `GET /tickets?event_id=` is still supported: a ticket matches when its own `event_id` (when present) equals the filter, otherwise when its order (`order_item_id`/`order_id` → `orders.event_id`) does. A ticket without a matching order is excluded.

The indexed columns are derived from the record exactly as legacy does: `start_datetime`/`end_datetime`/`checked_in_at` are stored in **WIB** (the record keeps the app's ISO/UTC value), `date` feeds the `tanggal` column, `start_time`/`end_time` keep their first 8 characters, numbers are `intval`, flags are `0/1`.

**`tickets.qr_token` is unique.** A create or update that reuses another ticket's token is refused with `409 duplicate` (the legacy `saveAll` would silently overwrite the other ticket instead, see #100).

## Event details

One document per event (rundown, budget, sponsors, …), the app's `eventDetails[eventId]`.

| Method | Path | Notes |
|---|---|---|
| GET | `/event-details` | Map `eventId → detail`; `meta.versions` = `{eventId: version}` |
| GET | `/event-details/{eventId}` | One detail + version |
| PUT | `/event-details/{eventId}` | Creates it when there is none (no version needed, `201`), otherwise replaces it (version required: `428` without, `409` when stale) |
| DELETE | `/event-details/{eventId}` | Needs a version |

## Check-ins (append-only)

| Method | Path | Notes |
|---|---|---|
| GET | `/checkins` | Newest first, at most 2000 (`?limit=` lowers it). Filter `?ticket_id=`. `meta.total`. |
| POST | `/checkins` | Body `{ticket_id, gate?, result?, id?, checked_in_at?}`. `ticket_id` is required. `staff` is **the acting user's name** (the body cannot set it). `checked_in_at` defaults to now (ISO UTC). `201`. An existing id is never overwritten: `409 already_exists`. |

There is no update or delete: attendance is never rewritten, as in legacy.

## Settings

Keys: `entertainmentRules` (list, default `[]`), `role` (string, default `"Director"`), `layoutTemplates` (list of seat-map templates, default `[]`).

| Method | Path | Notes |
|---|---|---|
| GET | `/settings` | All three; `meta.versions` = `{key: version}` |
| GET | `/settings/{key}` | Value + version |
| PUT | `/settings/{key}` | Body `{"value": …}` (not `null`, which legacy treats as "not sent"). Version required. |

## Files

Talent documents (KTP, NPWP, contracts, portfolio), transfer receipts and event posters. Stored on disk as `ev_<16 hex>.<ext>` in `EVENT_DATA_DIR/files`, the same folder and names as the old backend. Records keep only the pointer `{key, name, size, at}`.

| Method | Path | Notes |
|---|---|---|
| POST | `/files` | Body `{dataBase64, fileName, mimeType}` (a `data:` prefix is allowed). Images (jpg/png/webp/gif) or PDF, at most 8 MB. `201` → `{key, name, size, at}`. `422 invalid_file` with the legacy reason (`file kosong`, `hanya gambar (jpg/png/webp/gif) atau PDF`, `base64 tidak valid`, `file melebihi 8MB`). |
| GET | `/files/{key}` | The file, with its content type. `404` when missing. |

There is **no orphan cleanup**, on purpose: a KTP scan that is still referenced must never be swept away. Unused files only cost disk space.

## Versions and conflicts

- A record's `version` is its `updated_at` column (epoch ms). A successful write stamps `max(now, stored + 1)` into the column and into `data.updatedAt`, so an older copy saved later by an old tab (legacy `saveAll`) cannot overwrite it: the legacy `updated_at` guard refuses older stamps.
- Settings versions are a hash of the stored value.
- Send the version as `If-Match` (quotes optional) or `?version=`.

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No or bad token |
| 403 | `module_not_granted` | No `event` module |
| 404 | `not_found` | Unknown id / key |
| 409 | `already_exists` | Create with a taken id (record or check-in) |
| 409 | `duplicate` | A reused ticket `qr_token` |
| 409 | `version_conflict` | Stale version; `details.current` is the live record / value |
| 422 | `validation_failed` / `invalid_file` | Bad body, bad date, bad file |
| 428 | `version_required` | Update/delete (or replacing an existing detail) without a version |
