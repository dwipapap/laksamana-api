# Homepage API (`/api/v1/homepage`) — events & promos on the website

Greenfield module on `core` (no legacy backend, no parity). It owns two things:

1. the **`homepage_event` switch** — which event of the Event (EMS) module appears
   on the public website. The event data itself stays in EMS and is read through
   `App\Modules\Event\Services\EventState::emsRows` (ADR-0002) — this module never
   writes a single EMS row.
2. the **`homepage_banner` rows of the promo slider** — a switch for a BD OS promo
   whose poster and period stay in BD (read through `BdState::setting('promos')`,
   never written), plus banners uploaded in the Homepage panel.

Code: `app/Modules/Homepage`.

- Envelope: `{data, meta?}` / `{error:{code,message,details?}}`.
- Public reads: no auth. Office: `Authorization: Bearer <token>` + module `homepage`.
- Times: EMS stores `start_datetime`/`end_datetime` as UTC instants (suffix `Z`),
  returned here in WIB (`+07:00`). Promo windows (`mulai`/`selesai`) are WIB
  calendar dates (`YYYY-MM-DD`), and "today" is always the WIB date.

## Setup on a server

```bash
# tables: import the CREATE TABLE statements for homepage_event and
# homepage_banner (docs/deploy.md "New core migrations")
php artisan office:grant <login> homepage   # registers the key if new + grants it
```

## The event rule ("boleh tampil")

One function, shared by the public feed, the office list and the toggles:

```
eligible = status ∈ {Upcoming, Today} AND not past
past     = end_datetime < now
           or, with no end_datetime: the WIB date of start_datetime is before today WIB
```

The status list is the same as `TicketShop::sellable()` — no new list. An event
that was switched on and stops being eligible simply falls out of the website;
its `homepage_event` row is **not** deleted.

## Public (events)

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

## The promo rule ("boleh tampil")

One function, `HomepagePromos::eligibility()`, shared by the public feed, the
office list and the toggles:

- **BD promo** (`sumber = bd`): the promo read through `BdState::setting('promos')`
  must exist, be `running` (`RadarRules::promoStatus`, WIB) **and** have a
  decodable poster. `upcoming`/`paused`/`ended`, a promo deleted in BD and an
  invalid poster all leave the website; the row is kept. Reasons: `bd_hilang`,
  `bd_dijeda`, `bd_belum_mulai`, `bd_berakhir`, `bd_tanpa_poster`.
- **Upload** (`sumber = unggah`): `tampil = true` AND today (WIB) inside the
  optional `mulai`–`selesai` window (empty = no bound) AND the file exists.
  Outside the window: `di_luar_periode`.

Homepage **never writes** the BD `promos` document (Radar and Kompas read it too),
and a promo that stops being eligible simply falls out of the feed — its row is
never deleted.

## Public (promos)

| Method | Path | Notes |
|---|---|---|
| GET | `/homepage/promos` | Rows with `tampil = true` AND eligible, `urutan ASC` then the older row first. `meta = {version}`. |
| GET | `/homepage/promos/{id}/gambar` | The image of such a row, **by row id**. `Cache-Control: public, max-age=86400` + content `ETag`. Not found/not shown/not eligible → `404 not_found` |

Both send the same `Cache-Control: public, max-age=60, stale-while-revalidate=300`
+ `ETag`/`304` as the event feed (the image route answers `304` on a matching
`If-None-Match` too).

List item — an allow-list projection:

```json
{
  "id": "01j…",
  "sumber": "bd",
  "image": "/api/v1/homepage/promos/01j…/gambar",
  "alt": "Kopi Kenangan",
  "href": "https://example.com/promo"
}
```

- `sumber` is `bd` or `unggah`; nothing else is ever revealed about a BD promo.
- `image` is a path (prefix the API origin); there is no other way to reach the
  bytes.
- `alt` is the row's own `alt`, falling back to the BD promo `nama`, else `""`.
- `href` is `null` or the optional link.

### Promo security

- Images are served **only by `homepage_banner` row id**, and only while that row
  is switched on AND eligible. No endpoint accepts a file key or a data URL from
  a client.
- A BD poster is a `data:image/(jpeg|png|webp);base64,…` value inside BD. It is
  decoded server-side; only valid base64 with ≤ 1 MB decoded counts, anything
  else is treated as "no poster". The raw data URL never appears in a JSON
  response.
- Uploads are private under `storage/app/homepage/` with a server-made key
  `hb_<hex>.<ext>` (jpg/png/webp, ≤ 8 MB).

## Office (`/homepage/office`, Sanctum + `module:homepage`)

### Events

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

### Promos

| Method | Path | Notes |
|---|---|---|
| GET | `/promos` | `{items, bd_gagal}` — every row plus BD candidates without a row |
| GET | `/promos/gambar?banner=…` / `?promo=…` | Preview by row id or BD promo id only (never by key). Exactly one parameter → else `422` |
| POST | `/promos/foto` | multipart `file` → `{key, url}`; `url` is always `null` (a file is only served by row id). Bad file → `422 invalid_file` |
| POST | `/promos` | Create an `unggah` banner; `urutan` = max+10; `201` |
| PATCH | `/promos/{id}` | `If-Match` required. Upload: `alt, href, mulai, selesai, gambar_key`; BD row: `alt, href` only |
| DELETE | `/promos/{id}` | `If-Match` required. Upload only (the file is removed); a BD row → `422 pakai_saklar` |
| PATCH | `/promos/{id}/tampil` | `{tampil}` — no `If-Match` |
| PATCH | `/promos/bd/{promoId}/tampil` | `{tampil}` — upserts the BD row (`urutan` = max+10 when new); unknown promo id → `404` |
| PUT | `/promos/urutan` | `{items:[{id, urutan}]}` — a subset is fine, a foreign id is skipped, no `If-Match` |

The list is one manual order: stored rows by `urutan` (older row first on a
tie), then BD candidates — running first, then the nearest `mulai`. Each item:

```json
{
  "id": "01j…", "sumber": "bd", "promo_id": "pr1234567",
  "alt": null, "href": null, "mulai": null, "selesai": null,
  "tampil": true, "urutan": 10, "version": 2,
  "eligible": true, "alasan": null,
  "gambar": "/api/v1/homepage/office/promos/gambar?banner=01j…",
  "bd": {"nama": "Kopi Kenangan", "tipe": "Diskon", "kategori": "Minuman",
         "benefit": "Diskon 10%", "mulai": "2026-09-28", "selesai": "2026-10-05",
         "status": "running"}
}
```

- A candidate has `id: null`, `tampil: false`, `version: 0`, `urutan: 0` and
  `gambar` pointing at `?promo=…`. Candidates are BD promos with a poster in
  `running`/`upcoming` that have no row yet.
- A row whose BD promo is gone keeps its row: `bd: null`, `alasan: "bd_hilang"`.
- `bd` carries only `nama, tipe, kategori, benefit, mulai, selesai, status` —
  `kode`, `partner`, `kuota`, `lmPIC`, `ketentuan` and `outlet` are never in any
  item.
- `bd_gagal: true` means BD could not be read; the rows are still listed and the
  public feed keeps serving uploads.
- Switching ON anything that is not eligible → `422 tidak_eligible`
  (**switching OFF is always allowed**), including a BD toggle.
- Stored-row PATCH/DELETE send the version they read as `If-Match` (or
  `?version=`): missing → `428 version_required`, stale → `409 version_conflict`
  with `error.details.current`.

Validation (`422 validation_failed`): `href` only `https://…` or a `/…` path
(rejecting `//…`), `mulai ≤ selesai`, dates `YYYY-MM-DD`, `gambar_key` must name
a stored homepage file, `tampil` a boolean.

## Errors

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No/bad token on an office route |
| 403 | `module_not_granted` | Token without `homepage` |
| 404 | `not_found` | Unknown event/banner/promo id, or no image |
| 409 | `version_conflict` | Stale `If-Match` on a banner PATCH/DELETE (`details.current`) |
| 422 | `validation_failed` | Toggle body without `tampil`; bad `href`/period/`gambar_key` |
| 422 | `tidak_eligible` | Switching on something that is not eligible |
| 422 | `pakai_saklar` | DELETE of a BD row (switch it off instead) |
| 428 | `version_required` | Banner PATCH/DELETE without `If-Match`/`?version=` |
| 503 | `bd_tidak_terhubung` | BD read failed during a BD toggle |

## For laksamana-homepage

The `Malam` section (`HomeEvents.vue`) replaces the static `eventSpotlights`
with `GET /api/v1/homepage/events`; the promo slider (`HomePromos.vue` /
`PromoSlider.vue`) replaces the static `promoBanners` with
`GET /api/v1/homepage/promos`. `poster`/`image` are resolved against the API
origin. When the request fails (or the list is empty) the section shows an
honest empty state — never a fake event or a fake promo. The homepage's origin
must be in the backend's `CORS_ALLOWED_ORIGINS`.
