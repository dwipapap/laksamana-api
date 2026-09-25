# Reservasi API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/reservasi`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs module `reservasi`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** reservations use the app's own fields (`name`, `phone`, `date`, `time`, `pax`, `status`, `picName`, `source`, `dpAmount`, `dps[]`, `dpProofData`, `docReqData`, …), exactly as stored in each row's `data` JSON.

Status: the core contract (#32). The per-screen completion is #33.

## Photos

Photos never stay inline. A `data:` URI in a photo field (`dpProofData`, `docReqData`, `dps[].proofData`, and in the master blob `reviews[].proofData` / `proof2Data` and `feedbacks[].proofData`) is written to `<RESERVASI_DATA_DIR>/files/<key>.txt` and replaced with `@f:<key>`. Read it back with `GET /files/{key}`. These are the same keys and the same folder the old backends use.

## Concurrency

- **A reservation:** `version` = its `updatedAt` (ms). PUT, PATCH and DELETE need it (`If-Match` / `?version=`). Missing → `428`; stale → `409` with the current row.
- **The master blob:** `version` = a content hash.
- **Global `_ver`:** every v1 write bumps it, the same counter legacy `saveAll` checks as `baseVer`. An old laksamana-office tab that loaded before the write therefore gets its reload conflict. It cannot reconcile around the write or delete it.
- All writes take the same locks as legacy: the `flock` on `<DATA_DIR>/.lock`, plus a NamedLock.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/reservations?from&to` | Reservations whose date is in range (either bound optional), in `created_at` order. `meta.ver` = the global `_ver` |
| GET | `/reservations/{id}` | One reservation + `meta.version` / `ETag` |
| POST | `/reservations` | Body = the reservation; `id` is optional. `createdAt`/`updatedAt` are stamped and photos moved to files. `201`, or `409 already_exists` |
| PUT | `/reservations/{id}` | Replace (`createdAt` kept) |
| PATCH | `/reservations/{id}` | Shallow merge of the top-level fields |
| DELETE | `/reservations/{id}` | Also removes that reservation's photo files |
| GET / PUT | `/master` | The master blob shared with Service Excellent (tables, reviews, feedbacks, …). PUT body `{value: {...}}` with `If-Match` = its version |
| GET | `/audit` | The newest 500 audit entries |
| GET / PUT | `/files/{key}` | `{key, data}` / body `{data}`; an empty `data` deletes the file |

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted` |
| 404 | `not_found` |
| 409 | `already_exists`, `version_conflict` |
| 422 | `validation_failed`, `invalid_request` |
| 428 | `version_required` |
