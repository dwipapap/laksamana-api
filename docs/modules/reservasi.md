# reservasi — table reservations (also Service Excellent)

- **Legacy source:** `laksamana-office/reservasi-mysql/`. It was uploaded to the server by hand; it has never been in CI.
- **Legacy URL:** `/reservasi-api-mysql/api.php`
- **Database:** `lakk5493_db_reservasi`. About 2460 reservations; `getAll` returns roughly 2.4 MB.
- **Data dir:** `/home/lakk5493/reservasi-db`, set with `RESERVASI_DATA_DIR`. Files go in `files/<key>.txt`. The old file-based backend (reservasi-api-OFF) shares this folder.
- **Frontends:** `deploy/reservasi` and `service_excellent`. The Reservasi **Dana Masuk** page (`?embed=finance`) is also embedded in cashier and finance/kas; in v1 those holders reach its endpoints directly (G-14, see docs/api/reservasi.md).
- **Other callers:** the marketing frontend (VIP locks via `saveAll`) and the cocok-bri asset (DP method fixes via `saveAll`).

## Tables

| Table | Key | Columns / notes |
|---|---|---|
| `reservations` | PK `id` | `name`, `phone`, `tanggal` DATE, `jam`, `pax`, `status`, `pic_name`, `source`, `dp_amount`, `updated_at` (BIGINT ms), `created_at`, `data`. The `data` column holds the full reservation and is the source of truth. |
| `audit` | `id` | `ts`, `data`. Append-only, trimmed to 500 rows. |
| `settings` | — | `master`: one JSON blob, shared with Service Excellent. `_ver`: the global version counter. |

## Actions

Response envelope: `{ok, data}`, always HTTP 200. The default action is `getAll`.

| Action | Notes |
|---|---|
| `getAll` | Returns reservations ordered by `created_at, id`, plus `master`, the last 500 audit rows (by `ts` DESC), and `_ver`. |
| `getFile` (`key`) | Reads `files/<sanitised key>.txt`, a base64 data URI. |
| `putFile` (`data:{key, data}`) | Writes a file. Empty `data` deletes it. |
| `stats` | Counts, blob size, number of inline vs referenced photos, number of files, and whether the data dir is outside the web root. |
| `ping` | `{pong, backend}` |
| `saveAll` (`data:{reservations, master, audit}`, `baseVer` required) | See "`saveAll` steps" below. |

### `saveAll` steps

1. If `baseVer` is missing, fail with `APP_LAWAS` (client too old).
2. `externalize()`: move inline `data:` photos into files and replace them with `@f:<key>`. Photo fields and the keys they get (`each_file_field`):

   | Field | Key |
   |---|---|
   | `dpProofData` | `r:<id>:dp` |
   | `docReqData` | `r:<id>:doc` |
   | `dps[].proofData` | `p:<resId>:<dpId>` |
   | `master.reviews[].proofData` | `rv:<id>` |
   | `master.reviews[].proof2Data` | `rv2:<id>` |
   | `master.feedbacks[].proofData` | `fb:<id>` |

3. Begin a transaction and read `_ver` with `SELECT … FOR UPDATE`. If it is not equal to `baseVer`, return `{conflict: true, saved: false, ver}`. This is inside `data`, so the full reply is `{ok: true, data: {conflict: true, …}}`.
4. `gc_files()`: delete orphaned `.txt` files that are not referenced by any key.
5. Upsert each row, applying a column only when `IF(VALUES(updated_at) >= updated_at, …)`.
6. `DELETE` rows that are not in the payload. Skip this when the payload is empty and the DB already has rows.
7. Audit: `INSERT IGNORE` each row, then trim to the latest 500 by `ts`.
8. Upsert `master` and increment `_ver`.

### Locking

- `saveAll` and `putFile` are wrapped in a file lock (`flock` on `<DATA_DIR>/.lock`).
- The port uses `NamedLock` and ALSO takes the flock while the old backend is still live.

## v1 (complete, #32 + #33)

- `/api/v1/reservasi/reservations`: date-range list and CRUD, with a per-row version (`updated_at`). DP payments live in the reservation's `dps[]` and are written with the row version.
- `/api/v1/reservasi/master`: whole blob for bootstrap, plus independently content-versioned `GET/PUT /master/{section}`.
- `PUT/DELETE /master/{section}/{id}` for `reviews`, `feedbacks` and `waitlist`; review/feedback proof files are externalised and removed with their item.
- `GET/POST /audit`: server-owned rows attributed to the token's User and `master.users[id].role`; `_audit` on a reservation write also fills that row's 20-entry `log[]`.
- `/api/v1/reservasi/files/{key}`.
- Every v1 write advances the global `_ver`, so an old tab reloads/merges instead of reconciling around it.
- The v1 gate accepts the `reservasi` **or** `service_excellent` Modul (two Panels, one Backend and one master blob); Akses Halaman stays in `master.perms` / `master.sePerms`.
- The Office Roster stays in account v1: `/api/v1/account/roster` and `/api/v1/account/modules/reservasi/members`.
- Full screen map and contract: `docs/api/reservasi.md`.

## Port notes (#32: compat core)

- Ported: `getAll` (+ `_ver`), `getFile`, `putFile`, `stats`, `ping` and `saveAll` (`APP_LAWAS`, the global `_ver` under `FOR UPDATE`, photo externalisation to `@f:` files, file GC driven by the payload, the `updated_at`-guarded upsert, delete-missing that never empties the table from an empty payload, audit `INSERT IGNORE` trimmed to 500, master) in `App\Modules\Reservasi\Services\ReservasiState`. Byte-identical per parity, with the three tables diffed as well.
- Writes take the legacy `flock` on `<DATA_DIR>/.lock` (so old and new backends serialise during cutover) inside a NamedLock.
- The connection keeps the server sql_mode (`server_sql_mode`, #97).
- v1 core: `docs/api/reservasi.md`. #33 completed it per screen: versioned master sections, item-level reviews/feedbacks/waitlist, server-owned audit with the token's User and `master.users[id].role`, and the old Reservasi + Service Excellent frontends end to end on Laravel (`tools/e2e/reservasi.mjs`). Milestone `m15-reservasi`.
