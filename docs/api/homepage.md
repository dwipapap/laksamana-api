# Homepage API (`/api/v1/homepage`) — events on the website

Greenfield module on `core` (no legacy backend, no parity). It owns **only** the
`homepage_event` switch: which event of the Event (EMS) module appears on the
public website. The event data itself stays in EMS and is read through
`App\Modules\Event\Services\EventState::emsRows` (ADR-0002) — this module never
writes a single EMS row. Code: `app/Modules/Homepage`.

- Envelope: `{data, meta?}` / `{error:{code,message,details?}}`.
- Public reads: no auth. Office: `Authorization: Bearer <token>` + module `homepage`.
- Times: EMS stores `start_datetime`/`end_datetime` as UTC instants (suffix `Z`),
  returned here in WIB (`+07:00`).

## Setup on a server

```bash
# tables: import the CREATE TABLE statement for homepage_event
# (docs/deploy.md "New core migrations")
php artisan office:grant <login> homepage   # registers the key if new + grants it
```

## The rule ("boleh tampil")

One function, shared by the public feed, the office list and the toggles:

```
eligible = status ∈ {Upcoming, Today} AND not past
past     = end_datetime < now
           or, with no end_datetime: the WIB date of start_datetime is before today WIB
```

The status list is the same as `TicketShop::sellable()` — no new list. An event
that was switched on and stops being eligible simply falls out of the website;
its `homepage_event` row is **not** deleted.

## Public

| Method | Path | Notes |
|---|---|---|
| GET | `/homepage/events` | Events with `tampil = true` AND eligible, `start ASC`. `meta = {version}`. |
| GET | `/homepage/events/{id}/poster` | The poster of a switched-on, eligible event. `Cache-Control: public, max-age=86400`. Not found → `404 not_found` |

Public reads send `Cache-Control: public, max-age=60, stale-while-revalidate=300`
and an `ETag`; a matching `If-None-Match` answers `304`.

List item — an allow-list projection, never "everything minus secrets":

```json
{
  "id": "e2",
  "title": "Horas Party",
  "category": "Music",
  "start": "2026-10-02T20:00:00+07:00",
  "end": "2026-10-03T01:00:00+07:00",
  "venue": "Laksamana Muda",
  "description": "…",
  "poster": "/api/v1/homepage/events/e2/poster",
  "price_from": 150000,
  "is_ticketed": true
}
```

- `start`/`end` are ISO-8601 WIB; `end` is `null` when EMS has none.
- `venue` defaults to `Laksamana Muda` when the EMS row has none.
- `poster` is a path (prefix the API origin) or `null`. It points at the event's
  poster **by event id**; the raw EMS file key is never exposed.
- `price_from` is the cheapest `ticketClasses` price `> 0`, else `0` (the same
  computation as `TicketShop::summary()`). Quota/remaining/order data is never
  included.
- `is_ticketed` is a boolean.

### Poster security

The EMS files folder also holds talent IDs (KTP) and transfer proofs. The poster
route serves **only** the poster of an event that is currently switched on AND
eligible, and only ever **by event id** — a client-supplied key is never
accepted. The file is streamed from `EVENT_FILES_DIR` (`<event data_dir>/files`);
when the file is absent it redirects to the EMS `?action=file` URL. Everything
else is `404`.

## Office (`/homepage/office`, Sanctum + `module:homepage`)

| Method | Path | Notes |
|---|---|---|
| GET | `/events` | Candidate list for the switch screen (see below). `meta.total`. |
| GET | `/events/{id}/poster` | Poster preview of any candidate, by event id. `Cache-Control: public, max-age=86400` |
| PATCH | `/events/{id}/tampil` | `{tampil: bool}` — the on/off switch. No `If-Match` (a boolean, last writer wins) |

Candidate = every EMS event that is **not past** (any status except the clearly
dead `Cancelled`), plus a switched-on event that ended within the last **7 days**
so staff can see why it left the website. `start ASC`. Each row:

```json
{
  "id": "e2", "title": "Horas Party", "category": "Music", "status": "Upcoming",
  "start": "2026-10-02T20:00:00+07:00", "end": "2026-10-03T01:00:00+07:00",
  "venue": "Laksamana Muda", "poster": "/api/v1/homepage/office/events/e2/poster",
  "price_from": 150000, "is_ticketed": true,
  "tampil": true, "eligible": true, "alasan": null, "version": 1
}
```

- `tampil` is the current `homepage_event.tampil` (`false` when no row exists).
- `eligible` is the rule above; `alasan` is `null` when eligible,
  `"belum_upcoming"` when the status is not Upcoming/Today, `"sudah_lewat"` when
  the event is past.
- `version` is `homepage_event.version`, `0` when no row exists yet.

The toggle upserts `homepage_event` by `event_id` (the row is created the first
time the switch is touched; no row means off) and stamps `updated_by` from the
token:

- body without the key `tampil` → `422 validation_failed`;
- an unknown event id → `404 not_found`;
- switching ON an event that is not eligible → `422 tidak_eligible`
  (**switching OFF is always allowed**).

## Errors

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No/bad token on an office route |
| 403 | `module_not_granted` | Token without `homepage` |
| 404 | `not_found` | Unknown event id (toggle, poster) or no poster |
| 422 | `validation_failed` | Toggle body without `tampil` |
| 422 | `tidak_eligible` | Switching on an event that is not eligible |

## For laksamana-homepage

The `Malam` section (`HomeEvents.vue`) replaces the static `eventSpotlights`
with `GET /api/v1/homepage/events`. `poster` is resolved against the API origin;
when it is `null` (or the request fails) the section shows an honest empty state
with a WhatsApp CTA — never a fake event. The homepage's origin must be in the
backend's `CORS_ALLOWED_ORIGINS`.
