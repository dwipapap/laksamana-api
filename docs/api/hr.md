# HR (Staff Performance) API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/hr`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs module `hr`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** records are the app's own objects (`name`, `divId`, `empId`, `month`, `date`, …), exactly as stored in each row's `data` JSON. Empty objects stay `{}`.
- **Personal data:** `employees` rows contain personal data and access PINs, just as the legacy `getAll` returns them. The module gate is the only protection, as in the old app. Nothing in this module logs request bodies or query bindings.

## Concurrency: one version for the whole document

HR keeps the legacy rule: **one revision (`meta.rev`) guards the whole document**. HR stores things that must never be lost silently (SP, review scores, points), so a stale writer is refused rather than merged.

- Every read returns `meta.version` = the current rev.
- Every write must send it, as `If-Match: "<rev>"` or `?version=<rev>`. Missing → `428 version_required`. Stale → `409 version_conflict` with `error.details = {rev, savedBy, savedAt}`.
- A successful write bumps the rev (returned as `meta.version` + `ETag`) and records the **session user** as `saved_by`.
- A legacy laksamana-office tab that loaded before a v1 write therefore gets its "Perubahan Tidak Tersimpan" modal on its next save, and cannot overwrite the change.

## Screen → endpoint map

Pages of `deploy/hr/index.html` (`go('…')`):

| Screen | Endpoints |
|---|---|
| Dashboard (`dash`), Skor (`score`) | `GET /state` (scores are computed client-side, as the old app does) |
| Karyawan (`emp`) | `/employees`, `/divisions` |
| KPI (`kpi`) | `/kpi-templates`, `GET /kpi-actuals`, `PUT /kpi-actuals/{divId}/{month}/{itemId}` |
| OKR (`okr`) | `/okrs` |
| Review (`review`) | `/reviews` (layers live inside the row), `GET /monthly-inputs`, `PUT /monthly-inputs/{empId}/{month}` |
| Kompetensi (`comp`) | `/competencies` |
| Training (`training`) | `/trainings`, `/training-records` |
| Coaching (`coach`) | `/coachings` |
| Reward (`reward`) | `/rewards`, `/badges` |
| Disiplin (`disc`) | `/violations` |
| Engagement (`engage`) | `/moods`, `/suggestions` (an anonymous suggestion has `empId: null`), `/feedbacks` |
| Karier (`career`) | `/career-paths`, `/successions` |
| Kalender (`cal`) | `/calendar` |
| Kehadiran (`kehadiran`) | `GET /attendance`, `PUT /attendance/{YYYY-MM}` |
| Pengaturan (`settings`) | `GET /settings`, `PUT /settings/{key}` |
| Audit trail | `GET /audit`, `POST /audit` |

## Bootstrap & diagnostics

| Method | Path | Returns |
|---|---|---|
| GET | `/state` | The full document, the same as legacy `getAll` (collections, `audit`, `kpiActuals`, `monthlyInputs`, `attendance`, `settings`, `version`, `_rev`, `_savedAt`, `_savedBy`). `meta.version` = rev. |
| GET | `/stats` | Row counts, `_rev`, `env`, `db` |

## Records

Resources: `divisions`, `employees`, `kpi-templates`, `okrs`, `reviews`, `competencies`, `trainings`, `training-records`, `coachings`, `rewards`, `badges`, `violations`, `feedbacks`, `career-paths`, `successions`, `moods`, `suggestions`, `calendar`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | All rows. `meta.total`, `meta.version` |
| GET | `/{resource}/{id}` | One row |
| POST | `/{resource}` | Body = the record. `id` is optional (generated when absent). `201`, or `409 already_exists`. |
| PUT | `/{resource}/{id}` | Replace the record |
| PATCH | `/{resource}/{id}` | Shallow merge of the top-level fields |
| DELETE | `/{resource}/{id}` | → `{deleted: true}` |

Indexed columns are extracted exactly as legacy does. Missing values become `''`, except the nullable ones: `suggestions.emp_id`, `moods.mood`, `training_records.skor`.

## Nested maps

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/kpi-actuals` | — | `{divId: {month: {itemId: number\|null}}}` |
| PUT | `/kpi-actuals/{divId}/{month}/{itemId}` | `{value: number \| null}` | A non-numeric value or `null` removes the cell |
| GET | `/monthly-inputs` | — | `{empId: {month: {...}}}` |
| PUT | `/monthly-inputs/{empId}/{month}` | `{value: {...} \| null}` | `null` removes it |
| GET | `/settings` | — | `{key: value}` |
| PUT | `/settings/{key}` | `{value: any \| null}` | `null` removes the key |
| GET | `/attendance` | — | `{month: {fileName, importedAt, importedBy, unmatched[], days[]}}`. `meta.storage` = `tables` or `settings` (see below) |
| PUT | `/attendance/{YYYY-MM}` | `{value: {...} \| null}` | Replaces that month, including its `days[]`. `null` removes it. |

## Audit (append-only)

| Method | Path | Notes |
|---|---|---|
| GET | `/audit` | Newest first |
| POST | `/audit` | `{action, detail?}`. `id`, `at`, `userId` and `userName` are set by the server from the session. `201`. Needs no version and does not bump the rev (legacy audit is append-only as well). |

## Attendance storage

`schema.sql` declares `attendance_months` / `attendance_days`, but the production database does not have them. There, the attendance map lives in the `extra:attendance` setting, because the older production backend stored unknown top-level keys that way. This API checks read-only for the tables. Without them, it keeps reading and writing `extra:attendance`, on both the compat and v1 surfaces. Once the tables exist, it uses them, as the legacy lib does.

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted` |
| 404 | `not_found` |
| 409 | `already_exists`, `version_conflict` |
| 422 | `validation_failed` |
| 428 | `version_required` |
