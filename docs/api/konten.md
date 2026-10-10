# Konten API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/konten`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account must have module `konten`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** record fields are the laksamana-office app fields (`title`, `publishDate`, `rateValue`, …) as stored in each row's JSON. No renaming, so screens built for the old app map 1:1.

## Screen → endpoint map

The Panel's menus (`NAV`/`VIEW_META` in `deploy/konten/index.html`):

| Screen | Endpoints |
|---|---|
| Dashboard | `GET /state`, or `GET /content`, `GET /ads`; aggregation happens client-side, as in the old app. |
| Content Planning, Pipeline, Calendar | `/content` (status flow Idea→Posted; `deadline`, `publishDate`, `platform`, `pillar`, `campaign`, `brand`, `pic`) |
| Shooting Schedule | `/shootings` |
| Design Queue, Editing Queue | `/content` (production state lives inside the content row) plus Marketing's design queue (`GET /api/v1/marketing/design-queue`, `GET /api/v1/marketing/design-requests/{id}`, `PUT /api/v1/marketing/design-requests/{id}/progress` — open to `konten|marketing`, see `docs/api/marketing.md`); brand & crew options are mirrored to Marketing via `PUT /api/v1/marketing/design-options` (the old app posts `designReqOpsi` on boot) |
| Asset Management | `/assets`, plus `/files` for uploads |
| Approval, Publishing Tracker | `/content` (approvals, publish records and checklists live inside the content row) |
| Input Performa, Performa Konten, Performa Desain | `/content` + `PUT /content/{id}/performance` (figures live in `content.data.perf` per platform) |
| KOL & Medpar Management | `/kols`, `/visits` |
| Ads Management | `/ads`, `/ad-funds` |
| Report Percakapan | `/ads` (conversation counts live inside the ads rows) |
| Brand Management | `/brands`, `/campaigns` |
| Activity Log | `GET /logs`, `POST /logs` |
| Pengaturan | `documents/settings`, `documents/perms`, `documents/seeded`; the in-module access matrix (`perms`) is data the UI applies, exactly as the old app does |
| Crew & access | `/users` (module roster); Office accounts come from `/api/v1/account/*` |
| Notifications | `/notifications` |

`bank` (idea bank) has no screen — its page was removed — but the collection is kept and fully reachable, so stored ideas are never lost.

The server enforces module access. The in-module access matrix (`perms`) is data the UI applies, exactly as the old app does.

## Records

Resources: `users`, `brands`, `campaigns`, `content`, `prod-tasks`, `shootings`, `assets`, `bank`, `kols`, `visits`, `ads`, `ad-funds`, `notifications`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | Filters: `from`, `to` (on `tanggal`, else start date), `status`, `brand`, `pic`, `platform`, `campaign`, `kolId`, `updatedSince` (ms), `q` (free text), `page`, `perPage` (≤1000, default 100). Returns `meta.total`. |
| GET | `/{resource}/{id}` | `meta.version` and the `ETag` header hold the version. |
| POST | `/{resource}` | Body = the record (object). `id` is optional; the server generates `<prefix>_xxxxxxxxxx` if omitted. Returns 201 + version. A duplicate id returns 409 `already_exists`. |
| PUT | `/{resource}/{id}` | Replaces the whole record. Needs `If-Match: "<version>"` or `"version"` in the body. |
| PATCH | `/{resource}/{id}` | Merges top-level fields into the record. Same version rule as PUT. |
| DELETE | `/{resource}/{id}` | Same version rule (`If-Match` or `?version=`). |

### Versions and conflicts

- **What a version is.** A record's version is its `updatedAt` (ms). A successful write stamps `max(now, previous + 1)`.
- **Missing version.** A write without a version returns 428 `version_required`.
- **Stale version.** Returns 409 `version_conflict`, with the current record in `error.details.current`. The exception: if what you sent is **identical in content** to the stored record, the write is a no-op success.
- **Shared with the old app.** These rules are the same guards the old app's `saveAll` uses (see `App\Support\RowSync`), under the same `konten_save` lock. A v1 client and an old laksamana-office tab therefore never silently overwrite each other, and a version returned by v1 is a valid `baseUpdatedAt` for the old app.
- **Audit trail.** Every write appends a log entry (`<Label> ditambah/diubah/dihapus (API)`, `by` = the logged-in user).

## Performance figures

Content performance data lives in `content.data.perf`, keyed per platform.

| Method | Path | Notes |
|---|---|---|
| PUT | `/content/{id}/performance` | Body `{platform, metrics?}`. Merges the figures into `perf[platform]` and stamps `{at, by}`. Send `"metrics": null` to remove that platform's entry (the old Input Performa screen deletes `perf[platform]` once every box of that platform is emptied); other platforms are kept. Returns the content row + version. An empty `perf` is always `{}` (object), never `[]`, on every read — v1 and legacy `getAll` (#261): the old Office keeps `c.perf` as-is when truthy and an array silently drops named keys on save. The old konten-mysql `getAll` returned `[]` here; this is a deliberate difference. |

## Design options (for Marketing's Request Design form)

Marketing's Request Design form reads Konten's brands and active crew straight from
the old konten `getAll`. This narrow read replaces that door (G-16): no other Konten
state is handed over. **Open to `konten|marketing`.**

| Method | Path | Returns |
|---|---|---|
| GET | `/design-options` | `{brands:[{id,name}], pics:[{id,name,roles}]}` — active crew only, production roles (designer, video editor, photographer, content director, content planner) first, then by name, like the legacy picker. |

## Documents (settings)

Documents: `settings`, `perms`, `seeded`.

- `GET /documents/{doc}` returns `data` = the value, and `meta.version` = its content hash.
- `PUT /documents/{doc}` with `If-Match: "<hash>"`. The body is the whole new value. A stale hash returns 409.

## Logs

| Method | Path | Notes |
|---|---|---|
| GET | `/logs?refId=&limit=` | Newest first (default 200, ≤5000). Append-only, trimmed to the newest 5000. |
| POST | `/logs` | `{action, target?}`. `by` = the logged-in user. Returns 201. |

## Files

| Method | Path | Notes |
|---|---|---|
| POST | `/files` | Multipart `file`, or JSON `{dataBase64, mimeType, fileName}`. ≤8 MB, images or PDF. Returns 201 `{key, name, url}`. |
| GET | `/files/{key}` | Streams the file. |

Store the returned `key` inside the record (e.g. in an attachment field or a `?action=receipt&key=` URL). Files that no record in `content`, `assets` or `bank` references are hard-deleted 1 hour after upload.

## Admin — restore from backup (G-13, #187)

| Method | Path | Notes |
|---|---|---|
| POST | `/konten/admin/restore` | **Module admin.** `{konfirmasi: "PULIHKAN", data: {…backup JSON…}}` — `restoreJSON` of the old Pengaturan: the whole database is REPLACED by the file. Every collection the file carries is emptied first (otherwise the ordering guard keeps rows newer than the file), then the file goes through the normal saveAll — one transaction under `konten_save`. Logs stay append-only. `data` must carry `users` and `brands` (arrays), else 422 `invalid_backup`. A file without `perms` leaves the stored `perms` as they are (the old page filled the default client-side). → `{restored, jumlah, bentrok}` |
