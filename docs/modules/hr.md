# hr — HR, KPI and OKR

- **Legacy source:** `laksamana-office/hr-mysql/`. It was uploaded manually and is not part of CI.
- **Legacy URL:** `/hr-api-mysql/api.php`
- **Database:** `lakk5493_db_hr`

## Response envelope

- Uses `jawab()`, which returns **real HTTP status codes**: 400 when the payload is empty, 500 on a server error with the message `'kesalahan server'`.
- A conflict is returned as HTTP **200** with `{ok:false,error:'conflict',savedBy,savedAt,rev}`.
- The optional `API_TOKEN` is read from `?token=`. `stats` reports `ENV_LABEL`.

## Tables (schema.sql only)

| Table | Key | Notes |
|---|---|---|
| `meta` | id = 1 | Whole-document revision: `rev`, `saved_at`, `saved_by`, `versi` |
| `settings` | `k` | Key/value, `v` is JSON |
| `divisions`, `employees`, `kpi_templates`, `okrs`, `reviews`, `competencies`, `trainings`, `training_records`, `coachings`, `rewards`, `badges`, `violations`, `feedbacks`, `career_paths`, `successions`, `moods`, `suggestions`, `calendar` | `id` | Indexed columns (see `hr_kolom()`) plus a `data` JSON column |
| `kpi_actuals` | (`div_id`, `bulan`, `item_id`) | `nilai` DOUBLE, nullable |
| `monthly_inputs` | (`emp_id`, `bulan`) | `data` |
| `audit` | `id` | Append-only |

- Column renames used in the indexed columns: `jenis` = `type`, `tingkat` = `level`, `tanggal` = `date`, `bulan` = `month`.
- **Nullable on purpose:** `suggestions.emp_id` (NULL means an anonymous suggestion), `moods.mood`, `training_records.skor`.
- `employees.data` contains personal data (PII) and access PINs.
- `attendance_months` and `attendance_days` are declared in `schema.sql` but **do not exist in the live database**. The code must work without them.

## Actions

- `ping`
- `stats` — row counts, `_rev`, `env`, `db`
- `getAll` — the full state `S`. `kpiActuals`, `monthlyInputs`, `attendance{month:{…,days[]}}` and `settings` are rebuilt as nested maps. Also returns `version`, `_rev`, `_savedAt`, `_savedBy`.
- `saveAll` — request body `{data:S, baseRev, by}`, returns `{ok,data:{rev}}`. Rules:
  1. Runs in one transaction with `SELECT … FOR UPDATE` on `meta`.
  2. `baseRev` must equal the current `rev`. `baseRev = null` is allowed only when `rev = 0`.
  3. Collections are fully replaced: upsert, then `DELETE … NOT IN (…)`, or delete everything when the list is empty (**strategy `notIn`, including empty lists**).
  4. `kpi_actuals`, `monthly_inputs` and `settings` are deleted and re-inserted.
  5. `audit` is insert-only (`ON DUPLICATE KEY id=id`).
  6. JSON is decoded **without** the assoc flag, so `{}` stays an object.

## v1 proposal (auth required: employee PII)

- `/api/v1/hr/employees`
- `/api/v1/hr/kpi`
- `/api/v1/hr/okrs`
- `/api/v1/hr/reviews`
- … one resource per collection.
