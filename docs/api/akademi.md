# Akademi API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/akademi`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account must have module `akademi`. Record writes and document writes additionally need module **admin** rights (the Kelola screens are admin-only in the old app).
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** record fields are the laksamana-office app fields (`title`, `division`, `type`, `passing`, …) as stored in each row's JSON. No renaming, so screens built for the old app map 1:1. (`division` ↔ DB column `divisi`, `type` ↔ column `kind`.)

## Screen → endpoint map

The Panel's menus (`navSections`/`views` in `deploy/akademi/index.html`):

| Screen | Endpoints |
|---|---|
| Beranda (dashboard) | `GET /state`, or `GET /training-stats/{me}` + `GET /materials`; aggregation happens client-side, as in the old app. |
| Materi Saya | `/materials` (division-filtered), `/progress?user=` |
| Program Bulanan | `/programs`, `/program-progress?user=&program=` |
| Perpustakaan | `/materials` (`q`, `division`, `type` filters across all published materials) |
| Struktur Organisasi | `/divisions`, `/users` |
| Progress & Sertifikat | `/training-stats/{me}`, `/materials` |
| Progress Tim (manager+) | `/training-stats`, `/users` |
| Anjungan (admin dashboard) | `/stats`, `/training-stats` |
| Kelola Materi (admin) | `/materials` CRUD |
| Kelola Program (admin) | `/programs` CRUD |
| Kelola Kru (admin) | `/users` (edit role/division/title; accounts & login live in Office) |
| Log Aktivitas (admin) | `GET /activity`, `POST /activity` |
| Sinkronisasi & Backup | `GET /state` (one-call bootstrap), `GET /stats` |

The server enforces module access; the admin-only Kelola screens map to the admin-gated writes below.

## Records

Resources: `users`, `divisions`, `materials`, `programs`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | Filters: `role`, `division`, `active` (users); `type`, `cat`, `division`, `mandatory`, `published` (materials); `bulan` (programs); `updatedSince` (ms), `q` (free text), `page`, `perPage` (≤1000, default 100). Returns `meta.total`. Module holder. |
| GET | `/{resource}/{id}` | `meta.version` and the `ETag` header hold the version. Module holder. |
| POST | `/{resource}` | **Admin.** Body = the record (object). `id` is optional; the server generates `<prefix>_xxxxxxxxxx` (`u_`, `d_`, `m_`, `p_`) if omitted. Returns 201 + version. A duplicate id returns 409 `already_exists`. |
| PUT | `/{resource}/{id}` | **Admin.** Replaces the whole record. Needs `If-Match: "<version>"` or `"version"` in the body. |
| PATCH | `/{resource}/{id}` | **Admin.** Merges top-level fields into the record. Same version rule as PUT. |
| DELETE | `/{resource}/{id}` | **Admin.** Same version rule (`If-Match` or `?version=`). |

### Versions and conflicts

- **What a version is.** A record's version is its `updated_at` **column** (ms) — the real ordering guard the old app's `saveAll` compares against. A successful write stamps `max(now, previous + 1)` into the column and `data.updatedAt`.
- **Missing version.** A write without a version returns 428 `version_required`.
- **Stale version.** Returns 409 `version_conflict`, with the current record in `error.details.current`. The exception: if what you sent is **identical in content** to the stored record, the write is a no-op success.
- **Shared with the old app.** These rules are the same guards the old app's `saveAll` uses (see `App\Support\RowSync`), under the same `akademi_save` lock. A v1 client and an old laksamana-office tab therefore never silently overwrite each other, and a version returned by v1 is a valid `baseUpdatedAt` for the old app.
- **Audit trail.** Every record write appends an activity entry (`tambah_/ubah_/hapus_<materi|program|divisi|kru> (API)` semantics: action `tambah_materi` etc., `userId` = the logged-in account id).

## Progress (one cell per crew + material)

The app's `progress[userId][materialId]` map, addressed per cell. The entry object is stored as-is (e.g. `{status:'done', completedAt}` or quiz `{attempts, score, passed, lastAt}`).

| Method | Path | Notes |
|---|---|---|
| GET | `/progress?user=&material=` | Module holder. Returns `{userId, materialId, version, entry}` rows + `meta.total`. |
| GET | `/progress/{user}/{material}` | Module holder. 404 when the cell was never written. |
| PUT | `/progress/{user}/{material}` | Module holder. Body = the entry object (replaces). New cells need no version; existing ones need `If-Match` / `"version"`, else 428; stale ⇒ 409. |
| DELETE | `/progress/{user}/{material}` | Module holder. Same version rule. |

## Program progress (one cell per crew + program + material)

The app's `progProg[userId][programId][materialId]` map. Same shape and version rule as progress.

| Method | Path | Notes |
|---|---|---|
| GET | `/program-progress?user=&program=&material=` | Module holder. |
| PUT | `/program-progress/{user}/{program}/{material}` | Module holder. Body e.g. `{done:true, at, score?}`. |
| DELETE | `/program-progress/{user}/{program}/{material}` | Module holder. Same version rule. |

## Training stats (HR read model)

Percentages only — no material or quiz content. Same numbers the akademi client computes (`userStats`), served for the HR Panel (Staff Performance → Training component).

| Method | Path | Notes |
|---|---|---|
| GET | `/training-stats` | `{userId: {mandPct, mandTotal, mandDone, total, done, certified}}`. Inactive crew excluded. No mandatory materials ⇒ `mandPct: 100`. Module holder. |
| GET | `/training-stats/{user}` | One user's figures. 404 when unknown/inactive. Module holder. |

`isDone` mirrors the client exactly: quiz ⇒ `passed === true`, anything else ⇒ `status === 'done'`; only published materials matching the crew's division count (`all` matches everything).

## Activity

| Method | Path | Notes |
|---|---|---|
| GET | `/activity?user=&action=&limit=` | Newest first (default 200, ≤5000). Module holder. |
| POST | `/activity` | `{action, detail?}`. `userId` = the logged-in account, `ts` = now. Returns 201. Module holder. |

## Documents (settings)

Documents: `settings`, `version`, `createdAt`.

- `GET /documents/{doc}` returns `data` = the value, and `meta.version` = its content hash. Module holder.
- `PUT /documents/{doc}` with `If-Match: "<hash>"` (**admin**). The body is the whole new value. A stale hash returns 409.

## Files

| Method | Path | Notes |
|---|---|---|
| POST | `/files` | Multipart `file`, or JSON `{dataBase64, mimeType, fileName}`. ≤8 MB, images or PDF. Returns 201 `{key, name, url}`. Module holder. |
| GET | `/files/{key}` | Streams the file. Module holder. |

Store the returned `key` inside the record (e.g. in an attachment field or a `?action=receipt&key=` URL). Files that no `materials` row references are hard-deleted 1 hour after upload.
