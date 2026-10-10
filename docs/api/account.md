# Account API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/account` (plus `/api/v1/auth` and `/api/v1/me`).
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}` (`login` = username or full name). Every account may open the rosters; administration needs the module the row says (`*` = Superadmin for Kelola User / Kelola Akses, `jadwal` admin for Pengelola Roster).
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **PINs:** v1 never returns a PIN, on any endpoint. PINs are only ever written.
- **Same rules as the legacy `account-api-mysql` actions:** every refusal below is the legacy error code, so the old screens and the new contract cannot drift.

## Screen → endpoint map

The Office screens (`deploy/index.html`: Kelola User / Kelola Akses; `deploy/jadwal/index.html`: Data Pegawai):

| Screen | Endpoints |
|---|---|
| Login / logout | `auth/login`, `auth/logout` |
| Current user | `GET /me`, `PUT /me/pin`, `PUT /me/username` |
| Kelola User (list, add, edit, import Excel) | `GET /users`, `POST /users`, `PATCH /users/{id}`, `POST /users/bulk` |
| Kelola User (activate/deactivate, delete) | `PUT /users/{id}/active`, `DELETE /users/{id}` |
| Kelola Akses (checkboxes, per Modul) | `GET /users` (`modules`, `grants`, `denies`, `adminModules` per user + `meta.rules`), `PUT /users/{id}/access`, `PUT /users/{id}/admin`, `listAccess` |
| Kelola Akses (Modul registry) | `GET /modules`, `POST /modules`, `PATCH /modules/{key}` |
| Sheet migration / Talenta import | `POST /import` |
| Read-only rosters (any account) | `GET /account/roster`, `GET /account/modules/{module}/members` |
| Data Pegawai (Jadwal, Pengelola Roster) | `POST /account/roster`, `PATCH /account/roster/{id}`, `PUT /account/roster/{id}/active`, `DELETE /account/roster/{id}` |

`GET /users` already carries everything the legacy `listAccess` returned per user (`modules`, `adminModules`, plus the raw `grants`/`denies`), so there is no separate listAccess endpoint.

## Auth

| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/auth/login` | `{login, pin, device?}`. Username first, then full name; active accounts only. Rate limited (10 failed attempts / 15 min per IP+login). Returns `{token, tokenType, expiresAt, user}`. Wrong/inactive → 401 `invalid_credentials`. |
| POST | `/api/v1/auth/logout` | Revokes the current token. → `{loggedOut: true}` |
| GET | `/api/v1/me` | The caller's profile (`id, name, username, keterangan, modules, adminModules, headDivisi`, the six HR columns, `tim`, `noHp`, `talentaId`, `ulid`). `tim` = `[{key, label}]`, the Beranda teams read whole-word from the Tim column (`AppSupportTim`; several per User, never an access rule). v1 only: legacy `whoami` is unchanged. |
| PUT | `/api/v1/me/pin` | `{currentPin, newPin}`. Unlike legacy `changePin` this verifies the current PIN. Wrong → 422 `invalid_credentials`; bad shape → 422 `validation_failed`. |
| PUT | `/api/v1/me/username` | `{username}` (may be `null`/empty to clear). → `{username}`. Invalid → 422 `bad_username`; taken → 422 `username_taken`. |

## Users (Superadmin)

| Method | Path | Notes |
|---|---|---|
| GET | `/users` | Every real user: identity + HR columns + `modules`, `grants`, `denies`, `adminModules`, `ulid`. `meta.modules` = active Modul keys; `meta.rules` = `{bawaan, terbatas, adminBawaan}` for drawing the checkboxes. Never a PIN. |
| POST | `/users` | Body as `saveUser` (`name` required; `pin` empty → `1111`; `active`; `keterangan`; `noHp`; `talentaId`; `username`; the six HR columns). → `201 {id}`. Duplicate name/username → 422 `name_taken`/`username_taken`; duplicate Talenta ID → 422 `talenta_taken`. |
| PATCH | `/users/{id}` | Same body. An empty/omitted `pin` keeps the old PIN (v1 never falls back to `1111` on edit). → `{id}` |
| POST | `/users/bulk` | `{users: [{…saveUser…, _baris?}]}`, 1–500 rows (legacy `saveUsers`). Each row goes through the same `saveUser` rules. → `201 {sukses, gagal, baris:[{baris, nama, error, takenBy}]}`. Empty → 422 `empty`; over 500 → 422 `too_many`. |
| PUT | `/users/{id}/active` | `{active: bool}`. → `{id, active}`. Self → 422 `cannot_deactivate_self`; another Superadmin → 422 `cannot_deactivate_admin`. |
| DELETE | `/users/{id}` | Permanent delete of a **deactivated** account (+ its grants/admins). Active → 422 `must_deactivate_first`; self → 422 `cannot_delete_self`; Kepala Divisi on core → 409 `is_kepala_divisi`. → `{}` |
| PUT | `/users/{id}/access` | `{module, access: bool}`. Upserts one Izin Akses / Larangan. → `{}` |
| PUT | `/users/{id}/admin` | `{module, admin: bool}`. `module: '*'` promotes/demotes a Superadmin. The caller cannot drop its own `*` when it is the last Superadmin → 422 `last_superadmin`. → `{userId, module, admin}` |

## Modul registry (Superadmin)

| Method | Path | Notes |
|---|---|---|
| GET | `/modules` | Rows `{key, label, active}` in display order. `?all=1` includes inactive Moduls. |
| POST | `/modules` | `{modules: [{key, label?}]}` (legacy `syncModules`). **Adds new keys only**; an existing key is never touched and never deleted. → `201 {added: [key]}` |
| PATCH | `/modules/{key}` | `{label?, active?}` (legacy `saveModule`). `key` is never changed. Unknown key → 404 `not_found`; no key → 422 `missing_key`. → `{}` |

## One-time import (Superadmin)

| Method | Path | Notes |
|---|---|---|
| POST | `/import` | Legacy `import`: `{users?, modules?, grants?, admins?}` upserted in that order (users first, so grants/admins resolve). Users are keyed by `id`, modules by `key`; a blank Talenta ID in an incoming user does not erase the stored one. → `{diproses: {users, modules, grants, admins}}` |

## Investor account (Finance → Brankas)

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/account/investor-akun` | admin of `investor`, `brankas` or `finance` (or Superadmin) | Legacy `investorAkun` (6 Oct 2026). `{userId}` **links** an existing account: that account is not changed in any column. `{name, noHp?, pin}` **creates** one (`keterangan: "Investor"`, active; `pin` required, 4–6 digits → 422 `bad_pin`; empty name → 422 `missing_fields`; taken name → 422 `name_taken`). Both grant module `investor` (never admin, never revokes anything). Unknown `userId` → 404. → `{id, name}` (no PIN). Brankas stores `id`/`name` as the investor record’s `akunId`/`akunNama`; the picker of existing accounts reads `GET /account/roster`. |

## Rosters

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/account/roster` | any account | All users with Tim + HR columns (never PINs). Same as legacy `listDivisiRoster`. |
| GET | `/account/modules/{module}/members` | any account | Users holding that Modul, with `isModuleAdmin`. Same as legacy `listModuleRoster`. |
| POST | `/account/roster` | `jadwal` admin | Pengelola Roster (`rosterSaveUser`): add a crew member. Only identity columns are accepted (`name`, `keterangan`, `noHp`, `talentaId`, `active`, the six HR columns) — never `grants`/`admins`/`username`. → `201 {id}` |
| PATCH | `/account/roster/{id}` | `jadwal` admin | Same identity whitelist; keeps the old PIN. When `active` is sent it is applied through the `rosterSetActive` rules below, so a PATCH cannot deactivate the caller or a Superadmin (422 `cannot_deactivate_self` / `cannot_deactivate_admin`). → `{id}` |
| PUT | `/account/roster/{id}/active` | `jadwal` admin | Legacy `rosterSetActive`. Anything but an explicit `false` means active. → `{id, active}`. Self → 422 `cannot_deactivate_self`; Superadmin → 422 `cannot_deactivate_admin`. |
| DELETE | `/account/roster/{id}` | `jadwal` admin | Legacy `rosterHapusUser`. Only a **deactivated** non-Superadmin, non-self row. → `{id, nama}`. Refusals: 422 `must_deactivate_first`, `cannot_delete_self`, `cannot_delete_admin`; on core 409 `is_kepala_divisi`. |

## Errors

| Status | code |
|---|---|
| 401 | `unauthenticated`, `invalid_credentials`, `too_many_attempts` |
| 403 | `forbidden` (module not granted / not a Superadmin / not a Pengelola Roster) |
| 404 | `not_found` |
| 409 | `is_kepala_divisi` |
| 422 | `validation_failed`, `missing_fields`, `missing_key`, `empty`, `too_many`, `name_taken`, `username_taken`, `talenta_taken`, `bad_username`, `last_superadmin`, `cannot_delete_self`, `cannot_delete_admin`, `must_deactivate_first`, `cannot_deactivate_self`, `cannot_deactivate_admin`, `bad_pin` |
