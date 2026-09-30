# Marketing API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/marketing`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account must have module `marketing`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** record fields are the laksamana-office app fields (`nama`, `mktPIC`, `tanggal`, …) as stored in each row's JSON. No renaming, so screens built for the old app map 1:1.

## Screen → endpoint map

| Screen (laksamana-office `NAV_DEF`) | Endpoints |
|---|---|
| Dashboard, Kalender, Reporting | `GET /state`, or `GET /events?from&to`, `GET /clients`, `GET /followups`. Aggregation happens client-side, as in the old app. |
| Database Client (CRM) | `/clients`, `/followups` |
| Sales Pipeline & Event, Event Brief & Quotation, Task Divisi, Invoice & Payment | `/events`. Brief, quotation (`detail`), tasks and `payments[]` live **inside the event record**. |
| Reservasi VIP | `/vip` |
| Request Design & Video | `/design-requests` (Marketing's side), plus `GET /design-queue`, `PUT /design-requests/{id}/progress`, `PUT /design-options` (Konten's side) |
| Template Task | `/task-templates`, `/task-categories`, `/categories` |
| Approval Flow | `/approvals` |
| Timeline & Audit Log | `GET /activities`, `POST /activities` |
| Notification Center | `/notifications` |
| Pegawai & Akses, User Management | `/staff`, `/users` (module roster); Office accounts come from `/api/v1/account/*` |
| Database Menu, Menu Kalkulator | `documents/menuDb`, `documents/fbFormats`, `documents/fbSubs` |
| Katalog | `documents/katalog` |
| Pengaturan | `documents/settings`, `documents/baseline`; role matrix in `documents/rolePerms`, `documents/roleNav`, `documents/roleAcc` |
| Purchase Order | bd module (not in marketing) |
| Performa Omset & Bonus | kompas module (not in marketing) |
| Invoice/Kwitansi numbering | finance module (not in marketing) |

The server enforces module access. The in-module role matrix (`rolePerms`, `roleNav`) is data the UI applies, exactly as the old app does.

## Records

Resources: `clients`, `events`, `followups`, `approvals`, `users`, `staff`, `task-templates`, `task-categories`, `categories`, `notifications`, `vip`, `design-requests`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | Filters: `from`, `to` (on `tanggal`), `status`, `pic` (`mktPIC`), `clientId`, `eventId`, `updatedSince` (ms), `q` (free text), `page`, `perPage` (≤1000, default 100). Returns `meta.total`. |
| GET | `/{resource}/{id}` | `meta.version` and the `ETag` header hold the version. |
| POST | `/{resource}` | Body = the record (object). `id` is optional; the server generates `<prefix>_xxxxxxx` if omitted. Returns 201 + version. A duplicate id returns 409 `already_exists`. |
| PUT | `/{resource}/{id}` | Replaces the whole record. Needs `If-Match: "<version>"` or `"version"` in the body. |
| PATCH | `/{resource}/{id}` | Merges top-level fields into the record. Same version rule as PUT. |
| DELETE | `/{resource}/{id}` | Same version rule (`If-Match` or `?version=`). |

### Versions and conflicts

- **What a version is.** A record's version is its `updatedAt` (ms). A successful write stamps `max(now, previous + 1)`.
- **Missing version.** A write without a version returns 428 `version_required`.
- **Stale version.** Returns 409 `version_conflict`, with the current record in `error.details.current`. The exception: if what you sent is **identical in content** to the stored record, the write is a no-op success.
- **Shared with the old app.** These rules are the same guards the old app's `saveAll` uses (see `App\Support\RowSync`), under the same `mkt_save` lock. A v1 client and an old laksamana-office tab therefore never silently overwrite each other, and a version returned by v1 is a valid `baseUpdatedAt` for the old app.
- **Audit trail.** Every write appends a timeline entry (`<Label> ditambah/diubah/dihapus (API)`, `by` = the logged-in user).

## Documents (settings)

Documents: `settings`, `baseline`, `rolePerms`, `roleNav`, `menuDb`, `katalog`, `fbFormats`, `fbSubs`, `roleAcc`.

- `GET /documents/{doc}` returns `data` = the value, and `meta.version` = its content hash.
- `PUT /documents/{doc}` with `If-Match: "<hash>"`. The body is the whole new value. A stale hash returns 409.

## Read models

| Method | Path | Returns |
|---|---|---|
| GET | `/state` | The full state in one call (same as the old `getAll`). `meta.version` = `_versi`. |
| GET | `/events-on/{YYYY-MM-DD}` | Deal/Event Done events that day (multi-day events included, with `hari`/`totalHari`), plus Assisted VIP rows, plus `settings{serviceCharge, pb1}`. |
| GET | `/dp?from=&to=` | Event payments and VIP DP proofs, **filtered by event date**, plus `vipTerkunci` and the `luar` (outside-range) summary. **Open to `marketing`, `reservasi`, `cashier` and `finance`** (G-15): it feeds the DP Event tab of the Dana Masuk page. It returns payments only — no CRM, pipeline or invoice — which is why it may be wider than the rest of this contract. |
| GET | `/design-queue?active=1` | Design requests without reference images, including progress. |

## Files

| Method | Path | Notes |
|---|---|---|
| POST | `/files` | Multipart `file`, or JSON `{dataBase64, mimeType, fileName}`. ≤40 MB, images or PDF. Returns 201 `{key, name, url}`. |
| POST | `/files/chunks` | `{uploadId, seq, last, dataBase64, mimeType, fileName}`. Send ~2 MB per chunk; the last chunk returns `{key, name}`. |
| GET | `/files/{key}` | Streams the file. |

Store the returned `key` inside the record (e.g. `{key, name}` in `detail.attachFiles`). Files that no record references are moved to the trash after 7 days.
