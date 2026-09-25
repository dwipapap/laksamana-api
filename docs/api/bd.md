# BD OS API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/bd`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs module `bd`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** records are the app's own objects (`name`, `div`, `pics`, `deadline`, `needBy`, `prId`, …), exactly as stored in each row's `data` JSON. Nothing is renamed. As on the legacy surface, JSON is handled as PHP arrays, so an empty object is returned as `[]`.

## Screen → endpoint map

The Panel's menus (`NAV`/`SUB` in `deploy/bd/index.html`):

| Screen | Endpoints |
|---|---|
| Dashboard | `GET /state` (aggregated client-side, as the old app does) |
| Tasks (Eisenhower) | `/tasks` |
| Projects, project detail (milestones live in the project row) | `/projects`, `/tasks` (`task.project` = project id) |
| Calendar | `/agenda`, plus the deadlines in `/tasks`, `/projects`, `/coord-requests`, `/purchase-orders` |
| Koordinasi | `/coord-requests` |
| Routine | `/routines` |
| Promotion | `settings/promos` (also read by Radar and Kompas) |
| Purchasing (board, weekly PR sheet, signers) | `/purchase-orders`, `/purchase-requests` (items point back through `prId`), `settings/approverSets`, `PUT /purchase-orders/{id}/realisasi` |
| Kru BD | `/people` (the Office roster comes from `/api/v1/account/*`) |
| Daily focus | `settings/focus` (`{peopleId: {teks, tgl}}`) |
| Marketing → purchase request | `POST /purchase-orders/batch` |
| Finance → Kas Kecil realisation | `PUT /purchase-orders/{id}/realisasi` |

## Bootstrap & diagnostics

| Method | Path | Returns |
|---|---|---|
| GET | `/state` | The full state, the same as legacy `getAll`: eight collections, `focus`, `approverSets`, `promos`, `_serverTs` |
| GET | `/stats` | Row counts, blob size, `env`, `db`, `versi` |

## Records

Resources (API name → app collection): `people`, `projects`, `tasks`, `routines`, `coord-requests` (`coord`), `purchase-orders` (`po`), `purchase-requests` (`pr`), `agenda`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | All rows, in legacy order (`created_at`, then `id`). `?updatedSince=<ms>` returns only the rows changed after that time. `meta.total`. |
| GET | `/{resource}/{id}` | One row. `meta.version` + `ETag` |
| POST | `/{resource}` | Body = the record. `id` is optional: when absent, one is generated in the app's `uid(prefix)` shape (`t…`, `p…`, `po…`, `pr…`, …). `createdAt`/`updatedAt` are stamped. `201`, or `409 already_exists`. |
| PUT | `/{resource}/{id}` | Replace the record (`createdAt` is kept). Needs a version. |
| PATCH | `/{resource}/{id}` | Shallow merge of the top-level fields. Needs a version. |
| DELETE | `/{resource}/{id}` | Needs a version. → `{deleted: true}` |

The indexed columns are extracted exactly as legacy does. `div` goes to `divisi`, the first of `pics` goes to `pic`, and invalid dates become `NULL`.

## Purchasing actions

| Method | Path | Notes |
|---|---|---|
| POST | `/purchase-orders/batch` | `{items: [...]}`, 1 to 200 rows. Insert-only, like legacy `addPo`. Rows without `item` are skipped. Ids are always generated here (`po<10 hex>`), and `status` defaults to `Diajukan`. → `201 {added, ids}`. `422 invalid_request` with the legacy message for an empty or oversized list. |
| PUT | `/purchase-orders/{id}/realisasi` | `{realisasi: <amount> \| "" \| null}`, like legacy `setRealisasi`. An amount sets `realisasi`, marks the row processed (`proses`, `prosesAt`, `prosesBy`) and moves `status` to `Diterima`, keeping the previous status in `statusSebelum`. `""`/`null` removes all of that and restores the previous status if it is still `Diterima`. **`prosesBy` is the session user**, never a body field. → `{result, record}` + the new version. |

## Settings documents

Keys: `focus` (object), `approverSets` (list), `promos` (list). A missing row reads as the legacy default.

| Method | Path | Notes |
|---|---|---|
| GET | `/settings` | All three. `meta.versions` |
| GET | `/settings/{key}` | One document. `meta.version` + `ETag` |
| PUT | `/settings/{key}` | `{"value": …}` replaces the whole document. Needs a version. |

## Concurrency

- **Records:** `version` = the row's `updated_at` (ms), the same ordering guard that legacy `saveAll` compares against. A v1 write stamps `max(now, stored + 1)` into both the column and `data.updatedAt`. An old laksamana-office tab therefore cannot overwrite it with an older copy, and that tab's `sinceTs`-bounded delete cannot remove a row created after the tab loaded.
- **Documents:** `version` = the first 16 hex characters of `sha1` of the stored JSON.
- Send the version as `If-Match: "<version>"` or `?version=`. Missing → `428 version_required`. Stale → `409 version_conflict`, with the live record in `error.details.current`.
- All writes take the same `GET_LOCK('<db>:bd_save')` as legacy `saveAll`, `addPo` and `setRealisasi`.

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted` |
| 404 | `not_found` |
| 409 | `already_exists`, `version_conflict` |
| 422 | `validation_failed`, `invalid_request` |
| 428 | `version_required` |
