# Howandi Life OS API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/hlife`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs module `howandi_life` in its effective grants. Being superadmin does not count, and an explicit revoke overrides a `*` grant. The old page's SSO gate applies the same rule.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** records are the app's own objects (`name`, `due`, `done`, `weekStart`, …), exactly as stored in each row's `data` JSON. Nothing is renamed. Empty objects stay `{}`.

Unlike the legacy `saveAll`, which replaces the whole state and lets the last save win, every v1 write touches only the record or setting it names, and it is version-checked.

## Screen → endpoint map

The Panel's menu (`openMoreMenu` in `deploy/howandi_life/index.html`):

| Screen | Endpoints |
|---|---|
| Command Center (home: mood/energy dials, focus, weekly target, brain dump, habits) | `GET /state`, or `settings/{mood,energy,focus,weeklyTarget,dump}` + `/habits` |
| Calendar | `/events`, plus the dated items in `/tasks`, `/content`, `/projects` (aggregated client-side, as the old app does) |
| Tasks (Eisenhower matrix / list) | `/tasks` |
| Projects (Kanban, project detail) | `/projects`, `/tasks` (`task.project` = project id) |
| Content | `/content`, `settings/channels` |
| Goals | `/goals` |
| Roadmap & Dreams | `/roadmap`, `/dreams` |
| Learning | `/learning` |
| Evaluasi Diri (weekly reviews) | `/reviews` |
| Finance & Aset | `/ledger` (the app's `finance.ledger`), `/assets` |
| Businesses | `/businesses` |
| Data, Sync & Login | `GET /state` (backup), `GET /stats`, `settings/auth` (lock-screen `{enabled, hash}`: SHA-256 of the password), `settings/firstRun` |

## Bootstrap & diagnostics

| Method | Path | Returns |
|---|---|---|
| GET | `/state` | The full state S, the same as legacy `getAll`: twelve collections, `finance: {ledger: []}`, and the settings keys (missing ones filled with their defaults) |
| GET | `/stats` | Row count per table (`businesses` … `reviews`, `ledger`, `settings`) |

## Records

Resources: `businesses`, `projects`, `tasks`, `goals`, `dreams`, `roadmap`, `content`, `learning`, `habits`, `events`, `assets`, `reviews`, `ledger`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | All records. `meta.total`, `meta.versions` = `{id: version}`. `ledger` is ordered by `month`; the others come in table order, like legacy `getAll`. |
| GET | `/{resource}/{id}` | One record. `meta.version` + `ETag`. |
| POST | `/{resource}` | Body = the record. `id` is optional: when absent, a 7-character `[0-9a-z]` id is generated (the app's `uid()` shape). `201` + version. `409 already_exists` if the id is taken. |
| PUT | `/{resource}/{id}` | Replace the whole record. If the body has an `id`, it must match the URL. Needs a version. |
| PATCH | `/{resource}/{id}` | Shallow merge of the top-level fields. Needs a version. |
| DELETE | `/{resource}/{id}` | Needs a version. → `{deleted: true}` |

Indexed columns (`nama`, `due`, `done`, `bulan`, …) are kept in sync from the record, as legacy does. On v1, a missing or non-numeric value for a numeric column (`done`, `streak`, `income`, `expense`, `tahun`) is stored as `0`, or as `NULL` where the column allows it. It is never rejected by the database.

## Settings

Keys: `firstRun`, `mood`, `energy`, `focus`, `weeklyTarget`, `auth`, `channels`, `dump`. Each value is any JSON. The order of `channels` and `dump` matters, and `dump` is newest-first.

| Method | Path | Notes |
|---|---|---|
| GET | `/settings` | `{key: value}` for all eight keys, with defaults for missing rows. `meta.versions` = `{key: version}` |
| GET | `/settings/{key}` | One value. `meta.version` + `ETag` |
| PUT | `/settings/{key}` | Body `{"value": …}`. Needs a version. |

## Concurrency

The exposed version is the first 16 hex characters of `sha1` of the stored JSON, so a write through either surface changes it, whether that is v1 or a legacy `saveAll` from an old tab. Send it as `If-Match: "<version>"` or `?version=<version>`. (The legacy tables have no version column; on `core` (#65) each table also counts its writes in the ADR-0003 `version` column, but the contract above does not change — the hash works identically on both storages.)

- missing → `428 version_required`
- stale → `409 version_conflict`, with the live record (or setting value) in `error.details.current`

An unsaved setting reads as its default, and its version is the hash of that default.

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted` |
| 404 | `not_found` (also for an unknown resource or setting key) |
| 409 | `already_exists`, `version_conflict` |
| 422 | `validation_failed` (the body is not a JSON object, the body `id` does not match the URL, or `value` is missing) |
| 428 | `version_required` |

## Admin — reset (G-13, #187)

| Method | Path | Notes |
|---|---|---|
| POST | `/hlife/admin/reset` | **Module admin.** `{konfirmasi: "KOSONGKAN"}` (case-insensitive; otherwise 422 `confirmation_required`). "Reset data" of the old Pengaturan: every collection and the ledger emptied, `auth` (the login hash) kept, `firstRun` false, `mood` 3, `energy` 4, `focus`/`weeklyTarget` "", `channels`/`dump` []. → `{reset: true, state}` (the new full state). |
