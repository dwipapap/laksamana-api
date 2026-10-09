# Reservasi API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/reservasi`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account needs the `reservasi` **or** `service_excellent` Modul: they are two Panels of the same Backend and the same master blob. Akses Halaman inside each Panel stays in `master.perms` / `master.sePerms`, as it is in the old client-side matrix.
- **Dana Masuk door (G-14):** holders of `cashier` or `finance` also reach the endpoints the Dana Masuk (DP) page uses — `GET /reservations`, `GET /reservations/{id}`, `PATCH /reservations/{id}`, `GET /master/{section}`, `POST /audit`, `GET /files/{key}` — as the old Office did through `deploy/reservasi/?embed=finance` (Cashier and Kas Kecil embed that page, with verify/reject rights). When such a User holds **neither** `reservasi` nor `service_excellent`, the API narrows it further:
  - `PATCH /reservations/{id}` may only write the DP fields (`dps`, `dpStatus`, `dpMethod`, `dpAmount`, `dpProofData`, `dpProofName`) and the transfer fields (`tf` + an upper-case letter: `tfDate`, `tfStatus`, `tfOcrText`, …), plus `status` for the one move Dana Masuk owns, `Pending` → `Confirmed`. A key sent back with its current value is not a write and passes. Anything else → **403 `forbidden`** with `details.fields` listing the refused keys, and nothing is written.
  - `GET /master/{section}` answers only `dpMethods`; any other section → **403 `module_not_granted`**.
  - Everything else (`POST`/`PUT`/`DELETE /reservations`, `GET /master`, `GET /audit`, master writes, `PUT /files/{key}`) stays **403 `module_not_granted`**.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** reservations use the app's own fields (`name`, `phone`, `date`, `time`, `pax`, `status`, `picName`, `source`, `dpAmount`, `dps[]`, `dpProofData`, `docReqData`, …), exactly as stored in each row's `data` JSON.
- **Roster:** the Office Roster is account data, not Reservasi data. Use `GET /api/v1/account/roster` (every User, with Divisi) and `GET /api/v1/account/modules/reservasi/members` (Users with the Reservasi Modul). Service Excellent crew is its own `master.seCrew`, roster-synced from those reads.

Status: complete. The compat core and dual lock are #32; the per-screen completion is #33.

## Screen → endpoint map

### Reservasi Panel (`deploy/reservasi`, module reservasi)

| Panel | Reads | Writes |
|---|---|---|
| Dashboard & Recap | `GET /reservations?from&to`; `GET /master/categories`, `/master/layouts`, `/master/layoutOverrides`, `/master/layoutOverrides2`, `/master/layoutTanggal`, `/master/waitlist` | reservation status/arrival/follow-up writes; `PUT/DELETE /master/waitlist/{id}` |
| Input Reservasi | `GET /reservations`, `/master/categories`, `/master/dpMethods`, `/master/infoSources`, `/master/waTargets` | `POST /reservations`, `PUT/PATCH/DELETE /reservations/{id}`, photo `PUT /files/{key}` |
| Dana Masuk (DP) — also opened to `cashier` / `finance`, see *Dana Masuk door* above | reservations and their `dps[]`, `/master/dpMethods`, `/files/{key}`; the **DP Event** tab reads `GET /api/v1/marketing/dp` (open to `marketing`, `reservasi`, `cashier`, `finance`) | `PATCH /reservations/{id}` with the DP fields (`dps[]`, `dpStatus`, `dpAmount`, `dpProofData`, `tf*`); `POST /audit`. Reservasi holders only: `PUT /reservations/{id}`, and `DELETE /reservations/{id}` (Hapus Data Demo) removes the reservation and its DP files |
| Riwayat | `GET /reservations` (the page filters past dates, `No-show` and `Cancelled`) | the same reservation writes as Dashboard |
| Analitik | `GET /reservations` | none; the page derives attendance, pax, source, PIC and monthly figures |
| Master Data | `/master/tables`, `/dpMethods`, `/infoSources`, `/categories`, `/users`, `/perms`, `/waTargets`, `/dineEstMin`, `/clashLeadMin`, `/layouts`, `/layoutOverrides`, `/layoutOverrides2`, `/layoutTanggal` | `PUT /master/{section}`; `POST /audit` for the action |
| Audit Log | `GET /audit`, then `GET /reservations/{id}` for a linked row | none |

`review` is still present in the old `ROLE_NAV`, but the page was removed: **Service Excellent owns reviews and feedback**.

### Service Excellent (`deploy/service_excellent`, module service_excellent)

| Panel | Reads | Writes |
|---|---|---|
| Dashboard (Capaian) | reservations; `/master/reviews`, `/master/feedbacks`, `/master/reviewCfg`, `/master/seCrew`, `/master/sePerms` | `PUT /master/reviewCfg`; `POST /audit` |
| Google Review | `/master/reviews`, `/master/reviewCfg`, eligible reservations, `/files/{key}` | `PUT/DELETE /master/reviews/{id}`, photo files, `POST /audit` |
| Catat Feedback | `/master/feedbacks`, `/files/{key}` | `PUT/DELETE /master/feedbacks/{id}`, photo files, `POST /audit` |
| Kelola Role | `/master/seCrew`, `/master/sePerms` plus the Office Roster | `PUT /master/seCrew`, `PUT /master/sePerms`; `POST /audit` |
| Setelan | `/master/reviewCfg` | `PUT /master/reviewCfg`; `POST /audit` |

The waiting list is shared state in `/master/waitlist`; it has no Service Excellent Panel of its own.

### Other Modul

- Dana Masuk (DP) › **Event (Marketing)** is read-only here and stays with Marketing.
- Cashier and Finance embed this Panel for Dana Masuk. QRIS BRI matching is Kompas's `/bri` contract; the ignored-DP marks are `/bri/ignored` and `/bri/ignored/{dpId}`.
- Kwitansi requests for a reservation are Finance's `POST /api/v1/finance/invoices/requests` (status `/status`, file `/file/{resId}`); Reservasi does not duplicate them.

## Granular parts of the master blob

The whole master is shared with Service Excellent, but a screen owns **one section**. Each section carries its own `version`, a 16-character content hash, so two people editing `reviews` and `feedbacks` never conflict.

| Method | Path | Notes |
|---|---|---|
| GET | `/master/{section}` | The section's value, `meta.version` + `ETag`, and `meta.ver` (the global `_ver`) |
| PUT | `/master/{section}` | Body `{value: …}`; `null` removes the section. `If-Match` = that section's version |
| PUT | `/master/{section}/{id}` | Body `{value: {…,"id":"<id>"}}`; the id must match the URL. `If-Match` = the section version. Allowed for `reviews`, `feedbacks`, `waitlist` |
| DELETE | `/master/{section}/{id}` | `If-Match` = the section version. Proof files of the removed item are deleted |

A section write externalises only its own inline photos, so it cannot change another section's content or version. Review proofs use `rv:<id>` and `rv2:<id>`; feedback proofs use `fb:<id>`. Item replace/delete keeps every other item and every unrelated section, and every write advances the global `_ver`.

Known sections: `tables`, `dpMethods`, `infoSources`, `categories`, `users`, `perms`, `waTargets`, `dineEstMin`, `clashLeadMin`, `layouts`, `layoutOverrides`, `layoutOverrides2`, `layoutTanggal`, `waitlist`, `reviews`, `reviewCfg`, `feedbacks`, `sePerms`, `seCrew`. Unknown top-level keys stored by the old app are preserved by section writes.

`GET/PUT /master` remains for bootstrap and whole-blob migrations. New screens should use sections.

## Audit

`GET /audit` returns the newest 500 rows, newest first.

Every reservation write may carry `_audit: {action, detail?}`:

```json
{ "name": "Tamu", "_audit": { "action": "Buat Reservasi", "detail": "lewat WhatsApp" } }
```

After a successful create/update/delete the server appends one row, in the same transaction:

```json
{"id":"a…","ts":1790000000000,"user":"Andry","role":"viewer","action":"Buat Reservasi","detail":"lewat WhatsApp","res":"r…"}
```

- `user` is the name from the Bearer token; a body `user`/`role` is never trusted.
- `role` is `master.users[id].role` (empty when that User is not in the legacy crew list).
- The global detail is stored as sent.
- The reservation's own `log[]` gets `{ts, by, role, action, detail}` at the front, with `detail` cut to 180 characters and at most 20 entries, matching the old `logAudit()` / `tempelLogRes()`.
- Audit is append-only by id and the table is trimmed to the newest 500 by `ts`, exactly as `saveAll` does.
- A refused or conflicted write appends nothing.

`POST /audit {action, detail?, res?}` records an action that belongs to no reservation row (settings, roles, review targets). `action` is required; `res` is optional. It also advances the global `_ver`.

<## Guest summary

`GET /guests/summary` returns the Loyal / blacklist / autofill profile computed over the WHOLE history, in the legacy `ringkasTamu` shape (`?action=ringkasTamu&sebelum=` in the old backend). The windowed client cannot compute it from its window rows, so it merges this summary with them exactly like the old screen did (`profilGabung`).

- Query: `before=YYYY-MM-DD` (optional; only reservations dated before it count — the twin of legacy `sebelum`), `phone=` (optional; narrows the map to that one number, normalised the legacy way).
- Without `before` every dated row counts; undated rows never do. `data.sebelum` echoes the bound (or `null`).
- `data.tamu` maps each normalised phone to `[n, datang, noshow, member, memberNo, vip, kunjunganTerakhir, jumlahPax, namaPertama, namaTerakhir]`. Keys carry the legacy `k` prefix (`k628…`); an empty map is `{}`. `meta.ver` is the global version.
- Statuses are normalised before counting (`Checked-in`/`Completed` → `Datang`, `Booking` → `Confirmed`), exactly as legacy.

## Paging, filters and export

The old screens page these tables from the server (`halRecap`/`halDana`/`halAudit`, "Muat berjendela") because the whole list is megabytes; the filter rules are twins of the client (`applyFilter`+`recapList`, `auditCocok`).

- `GET /reservations`: `q` matches name, phone or table number (case-insensitive); `status` compares the normalised status (`Checked-in`/`Completed` → `Datang`, `Booking` → `Confirmed`) and, when it is anything but `Cancelled`, hides Cancelled rows while counting them as `meta.batal` (the Recap screen's hidden-cancelled count for that filter set; the key only appears with `status`). Without a `status` every row stays, exactly like the unfiltered answer always did. `page` (from 1) + `perPage` (1–100, default 10) cut the matches in Recap display order (date+time, stable); `meta.total` counts every match. A page past the end is clamped to the last page. **Without `page`/`perPage`/`q`/`status` the answer is exactly what it always was**: every row in `created_at` order.
- `GET /audit`: `q` searches user, role, action and detail (the guest name lives in Detail, so a cancelled or deleted reservation is still found). `page`/`perPage` cut it the same way with `meta.total`. Audit has no status dimension. Without the new params the answer is unchanged (newest 500, no `meta`).
- `GET /reservations/export`: the same filter as the list as `text/csv` (`reservasi_laksamana_<today>.csv`, BOM'd for Excel). Columns in the Recap order: Nama, No HP (digits only), Tanggal, Jam, Pax, Pax Aktual, Meja, Lantai, Status, DP, Nominal DP, Metode DP, Rekening DP, Sumber, PIC, Member (Ya/Tidak), No Member, Catatan, Diinput Oleh. Paging is ignored — the export always covers every matching row, in display order. Lantai comes from the custom `layouts` templates in master; tables known only to the browser's built-in templates export an empty Lantai. Read-only.

## Photos and files

Photos never stay inline. A `data:` URI in a photo field is written to `<RESERVASI_DATA_DIR>/files/<key>.txt` and replaced with `@f:<key>`.

| Owner | Field | Key |
|---|---|---|
| reservation | `dpProofData` | `r:<id>:dp` |
| reservation | `docReqData` | `r:<id>:doc` |
| reservation DP | `dps[].proofData` | `p:<resId>:<dpId>` |
| review | `proofData` / `proof2Data` | `rv:<id>` / `rv2:<id>` |
| feedback | `proofData` | `fb:<id>` |

`GET /files/{key}` returns `{key, data}`. `PUT /files/{key}` body `{data}` replaces it; empty `data` deletes it. Every v1 file write advances `_ver`. The folder and the `.lock` file are the same ones the old Backend uses.

## Concurrency

- **A reservation:** `version` = its `updatedAt` (ms). PUT, PATCH and DELETE need it (`If-Match` / `?version=`). Missing → `428`; stale → `409` with `details.current`.
- **A master section or item:** the section's content-hash version, sent the same way.
- **The whole master:** its own content-hash version; prefer section writes.
- **Global `_ver`:** every v1 write advances it, the same counter legacy `saveAll` checks as `baseVer`. An old laksamana-office tab that loaded before the write therefore gets its reload/merge conflict and cannot delete the new row.
- All writes take the same locks as legacy: the `flock` on `<DATA_DIR>/.lock`, plus `GET_LOCK('lakk5493_db_reservasi:reservasi_save')`.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/reservations?from&to&page&perPage&q&status` | Reservations whose date is in range (either bound optional), in `created_at` order. `q` (name/phone/table), `status` and `page`/`perPage` narrow and cut it — see *Paging, filters and export*. `meta.total`, `meta.ver` |
| GET | `/reservations/export?from&to&q&status` | The same filter as the list as CSV (the Recap columns); paging ignored, every match exported |
| GET | `/reservations/{id}` | One reservation + `meta.version` / `ETag` |
| POST | `/reservations` | Body = the reservation; `id` optional, `_audit` optional. `updatedAt` is stamped and kept increasing; `createdAt` defaults to the server clock. `201`, or `409 already_exists` |
| PUT | `/reservations/{id}` | Replace (`createdAt` kept) |
| PATCH | `/reservations/{id}` | Shallow merge of the top-level fields |
| DELETE | `/reservations/{id}` | Also removes that reservation's photo files. **Gated (#241):** only a module admin of `reservasi` (counts as `admin`) or a role whose `master.perms.inputDelete` is 2 (default: manager, admin; viewer never) — the old `CAN.deleteReservation`. Otherwise **403 `forbidden`**, checked before the version |
| GET / PUT | `/master` | Whole master blob. PUT body `{value: {...}}` with `If-Match` = its version |
| GET / PUT | `/master/{section}` | One independently versioned section |
| PUT / DELETE | `/master/{section}/{id}` | One `reviews`, `feedbacks` or `waitlist` item |
| GET | `/audit?page&perPage&q` | The newest 500 audit entries (`q` narrows, `page`/`perPage` cuts, `meta.total` counts) |
| POST | `/audit` | Append one action that belongs to no reservation row |
| GET | `/guests/summary?before&phone` | Whole-history guest summary in the legacy `ringkasTamu` shape |
| GET / PUT | `/files/{key}` | `{key, data}` / body `{data}`; empty `data` deletes the file |

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated` |
| 403 | `module_not_granted`; `forbidden` (Dana Masuk door field outside DP, or DELETE without `inputDelete`) |
| 404 | `not_found` (unknown reservation, item, or an item section outside the three above) |
| 409 | `already_exists`, `version_conflict` (`details.current`) |
| 422 | `validation_failed`, `invalid_request` |
| 428 | `version_required` |
