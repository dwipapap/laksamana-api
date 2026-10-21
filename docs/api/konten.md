# Konten API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/konten`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account must have module `konten`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** record fields are the laksamana-office app fields (`title`, `publishDate`, `rateValue`, …) as stored in each row's JSON. No renaming, so screens built for the old app map 1:1.

## Screen → endpoint map

The Panel's menus (`NAV`/`VIEW_META` in `deploy/konten/index.html`):

| Screen | Endpoints |
|---|---|
| Dashboard | `GET /state`, or `GET /content`, `GET /ads`; aggregation happens client-side, as in the old app. |
| Content Planning, Pipeline, Calendar | `/content` (status flow Idea→Posted; `deadline`, `publishDate`, `platform`, `pillar`, `campaign`, `brand`, `pic`) |
| Shooting Schedule | `/shootings` |
| Design Queue, Editing Queue | `/content` (production state lives inside the content row) plus Marketing's design queue (`GET /api/v1/marketing/design-queue`, `GET /api/v1/marketing/design-requests/{id}`, `PUT /api/v1/marketing/design-requests/{id}/progress` — open to `konten|marketing`, see `docs/api/marketing.md`); brand & crew options are mirrored to Marketing via `PUT /api/v1/marketing/design-options` (the old app posts `designReqOpsi` on boot) |
| Asset Management | `/assets`, plus `/files` for uploads |
| Approval, Publishing Tracker | `/content` (approvals, publish records and checklists live inside the content row) |
| Input Performa, Performa Konten, Performa Desain | `/content` + `PUT /content/{id}/performance` (figures live in `content.data.perf` per platform) |
| KOL & Medpar Management | `/kols`, `/visits` |
| Ads Management | `/ads`, `/ad-funds` |
| Report Percakapan | `/ads` (conversation counts live inside the ads rows) |
| Brand Management | `/brands`, `/campaigns` |
| Activity Log | `GET /logs`, `POST /logs` |
| Pengaturan | `documents/settings`, `documents/perms`, `documents/seeded`; the in-module access matrix (`perms`) is data the UI applies, exactly as the old app does |
| Crew & access | `/users` (module roster); Office accounts come from `/api/v1/account/*` |
| Notifications | `/notifications` |

`bank` (idea bank) has no screen — its page was removed — but the collection is kept and fully reachable, so stored ideas are never lost.

The server enforces module access. The in-module access matrix (`perms`) is data the UI applies, exactly as the old app does.

## Records

Resources: `users`, `brands`, `campaigns`, `content`, `prod-tasks`, `shootings`, `assets`, `bank`, `kols`, `visits`, `ads`, `ad-funds`, `notifications`.

| Method | Path | Notes |
|---|---|---|
| GET | `/{resource}` | Filters: `from`, `to` (on `tanggal`, else start date), `status`, `brand`, `pic`, `platform`, `campaign`, `kolId`, `updatedSince` (ms), `q` (free text), `page`, `perPage` (≤1000, default 100). Returns `meta.total`. Content extras (#282): `pillar`, `arsip=aktif\|arsip` (skipped when `status` is itself archived, F.2), `sort=deadline` (undated last), `facets=pic` (→ `meta.facets` counts per PIC + `__none__`, ignoring the `pic` filter itself, F.13), `ringkas=1` (slim table projection, no photo/detail blobs — full row stays on `GET /{resource}/{id}`). `platform` matches any of the row's platforms (F.27); `q` searches title + detailing only. |
| GET | `/{resource}/{id}` | `meta.version` and the `ETag` header hold the version. |
| POST | `/{resource}` | Body = the record (object). `id` is optional; the server generates `<prefix>_xxxxxxxxxx` if omitted. Returns 201 + version. A duplicate id returns 409 `already_exists`. |
| PUT | `/{resource}/{id}` | Replaces the whole record. Needs `If-Match: "<version>"` or `"version"` in the body. |
| PATCH | `/{resource}/{id}` | Merges top-level fields into the record. Same version rule as PUT. |
| DELETE | `/{resource}/{id}` | Same version rule (`If-Match` or `?version=`). |
| POST | `/ads/auto-status` | Date-driven ad statuses in one call (replaces N per-row PATCHes): past `endDate` → `ended`, started → `active`, not yet → `draft`; `paused` never touched. Dates compare as WIB wall-clock `YYYY-MM-DD`. No version needed — the server reads fresh under one `konten_save` lock and only rewrites changed rows (each version-bumped + audit-logged like PATCH). → `{diubah: n}`. |

### Versions and conflicts

- **What a version is.** A record's version is its `updatedAt` (ms). A successful write stamps `max(now, previous + 1)`.
- **Missing version.** A write without a version returns 428 `version_required`.
- **Stale version.** Returns 409 `version_conflict`, with the current record in `error.details.current`. The exception: if what you sent is **identical in content** to the stored record, the write is a no-op success.
- **Shared with the old app.** These rules are the same guards the old app's `saveAll` uses (see `App\Support\RowSync`), under the same `konten_save` lock. A v1 client and an old laksamana-office tab therefore never silently overwrite each other, and a version returned by v1 is a valid `baseUpdatedAt` for the old app.
- **Audit trail.** Every write appends a log entry (`<Label> ditambah/diubah/dihapus (API)`, `by` = the logged-in user).

## Performance figures

Content performance data lives in `content.data.perf`, keyed per platform.

| Method | Path | Notes |
|---|---|---|
| PUT | `/content/{id}/performance` | Body `{platform, metrics?}`. Merges the figures into `perf[platform]` and stamps `{at, by}`. Send `"metrics": null` to remove that platform's entry (the old Input Performa screen deletes `perf[platform]` once every box of that platform is emptied); other platforms are kept. Returns the content row + version. An empty `perf` is always `{}` (object), never `[]`, on every read — v1 and legacy `getAll` (#261): the old Office keeps `c.perf` as-is when truthy and an array silently drops named keys on save. The old konten-mysql `getAll` returned `[]` here; this is a deliberate difference. |

## Design options (for Marketing's Request Design form)

Marketing's Request Design form reads Konten's brands and active crew straight from
the old konten `getAll`. This narrow read replaces that door (G-16): no other Konten
state is handed over. **Open to `konten|marketing`.**

| Method | Path | Returns |
|---|---|---|
| GET | `/design-options` | `{brands:[{id,name}], pics:[{id,name,roles}]}` — active crew only, production roles (designer, video editor, photographer, content director, content planner) first, then by name, like the legacy picker. |

## Documents (settings)

Documents: `settings`, `perms`, `seeded`.

- `GET /documents/{doc}` returns `data` = the value, and `meta.version` = its content hash.
- `PUT /documents/{doc}` with `If-Match: "<hash>"`. The body is the whole new value. A stale hash returns 409.

## Logs

| Method | Path | Notes |
|---|---|---|
| GET | `/logs?refId=&limit=` | Newest first (default 200, ≤5000). Append-only, trimmed to the newest 5000. |
| POST | `/logs` | `{action, target?}`. `by` = the logged-in user. Returns 201. |

## Files

| Method | Path | Notes |
|---|---|---|
| POST | `/files` | Multipart `file`, or JSON `{dataBase64, mimeType, fileName}`. ≤8 MB, images or PDF. Returns 201 `{key, name, url}`. |
| GET | `/files/{key}` | Streams the file. |

Store the returned `key` inside the record (e.g. in an attachment field or a `?action=receipt&key=` URL). Files that no record in `content`, `assets` or `bank` references are hard-deleted 1 hour after upload.

## Admin — restore from backup (G-13, #187)

| Method | Path | Notes |
|---|---|---|
| POST | `/konten/admin/restore` | **Module admin.** `{konfirmasi: "PULIHKAN", data: {…backup JSON…}}` — `restoreJSON` of the old Pengaturan: the whole database is REPLACED by the file. Every collection the file carries is emptied first (otherwise the ordering guard keeps rows newer than the file), then the file goes through the normal saveAll — one transaction under `konten_save`. Logs stay append-only. `data` must carry `users` and `brands` (arrays), else 422 `invalid_backup`. A file without `perms` leaves the stored `perms` as they are (the old page filled the default client-side). → `{restored, jumlah, bentrok}` |

## Analytics — Campaign panel (#276)

v1 port of `konten-mysql/lib_campaign.php`: the Konten Analytics panel
(Insight, Kalender, Riset, Bank Konten, Pengaturan, TikTok/Instagram OAuth).
Base: `/api/v1/konten/analytics`. Gate `konten|campaign` (the Beranda kartu
holds both keys); settings writes need a campaign module admin; the two
Every sync (TikTok, Instagram, Google) stops cleanly after ~40 s of remote calls (#304); what it skipped is first in line next time (cursors, oldest `sync_at`/`insight_at` first).

OAuth callbacks are public (the single-use state token is the guard, like
legacy) and redirect to the Vue app (`OFFICE_VUE_URL`, else `APP_URL`).

Reads and writes share the physical `cg_*` tables with the old analytics
frontend (created with `CREATE TABLE IF NOT EXISTS`, same shape as
`cg_pastikan`), under the same `konten_save` lock. Writes append
`<Label> … (API)` to the module audit trail.

Insight/jadwal/bank rows carry `version` = `diubah_at` (fallback
`dibuat_at`), in `meta.version` + `ETag`. Updates need it (`If-Match` or
`version` in the body): missing → 428 `version_required`, stale → 409
`version_conflict` + `error.details.current`, identical payload → no-op
success. Validation messages are the legacy Indonesian strings verbatim.

| Method | Path | Notes |
|---|---|---|
| GET | `/analytics/state` | Full read: insight (≤1000), jadwal (≤1500), riset (30, decoded), bank (≤1000), postInfo, akun (no tokens), setting summary, `boleh.admin`. Optional `?insightLimit&jadwalLimit&bankLimit` (each ≤ its maximum) for a smaller head of a section; bare call answers like before. |
| GET | `/analytics/top?platform&urut=views\|er&dari&sampai&hal&per` | One page of top posts (API pulls + Input Performa, deduped by url, WIB dates). `per` clamped 5–50. → `{baris, total, hal, per, halMaks}`. Each row: `rinci` (9 numbers, `v` + `s` = api/manual/''), `tercatat`, `er`. |
| GET | `/analytics/accounts` | Connected accounts (no tokens), each with `terhubung`. |
| GET | `/analytics/bank?status=IDE\|TERJADWAL\|ARSIP&platform` | Bank Konten ideas (≤1000). |
| GET | `/analytics/settings` | Setting summary (no secrets): aiSiap/aiModel/ai, kategori, profil, tiktokSiap, redirectUri, igSiap, igRedirectUri. |
| PUT | `/analytics/settings` | **Campaign admin.** Secrets: empty = keep, `HAPUS` = clear. `aiModelMeta` from the closed list; `kategori` non-empty (≤20×60); `profil` ≤1500. |
| POST/PATCH | `/analytics/insights[/{id}]` | `{platform, kategori, temuan*, url* (https), tglTayang*, metrik, aksi, sumber}`. |
| POST | `/analytics/insights/{id}/arsip` | `{arsip: bool}`. |
| POST | `/analytics/insights/suggest` | AI suggestions (never stored): invented urls dropped, off-list categories emptied. |
| POST/PATCH | `/analytics/schedules[/{id}]` | `{platform, judul*, tanggal*, jam (JJ:MM), status (DRAF/TERJADWAL/TAYANG/BATAL), insightId, pic, tautan (https, required for TAYANG), catatan, bankId}` — `bankId` marks the idea TERJADWAL. |
| POST | `/analytics/research` | `{topik*, platform, catatan}` → AI tren research, stored in `cg_riset`. 1–2 min; the failure stays readable (not a toast). |
| POST/PATCH | `/analytics/bank[/{id}]` | `{judul*, platform, format, pilar, hook, caption, tanggal, jam, alasan, sumber (manual/riset), batch}`. Status/jadwal_id untouched (see arsip/schedules). |
| POST | `/analytics/bank/{id}/arsip` | `{arsip: bool}` — restore returns to TERJADWAL when it has a jadwal. |
| DELETE | `/analytics/bank/{id}` | ARSIP only (enforced in SQL); schedules born from the idea stay. |
| POST | `/analytics/bank/generate` | `{dari*, sampai* (≤62 days), platform, jumlah (3–20), fokus}` → AI planning stored as IDE ideas (≤20). Out-of-range dates and off-list pilars emptied. |
| POST | `/analytics/oauth/tiktok/start` | → `{url}` (state, 15 min). Needs client key+secret. |
| GET | `/analytics/oauth/tiktok/callback?code&state` | Public. Exchanges and saves the token, redirects to Vue `?tiktok=ok\|gagal&pesan=…#insight`. **No sync inside the redirect** (#304): it could run past the PHP/proxy limit; the screen pulls with `POST …/sync`. |
| POST | `/analytics/oauth/tiktok/sync` | Profile + 2 newest pages + 5 history pages + 40 oldest refresh (≤9 TikTok calls). Missing videos → `hilang=1`. Errors stored in `sync_galat`. → `{jumlah, total, riwayatSelesai}`. |
| POST | `/analytics/oauth/tiktok/disconnect` | Clears tokens (posts stay). |
| POST | `/analytics/oauth/instagram/start` | → `{url}` (business login, `force_authentication=1`). |
| GET | `/analytics/oauth/instagram/callback?code&state` | Public. Short token → 60-day token (no sync inside the redirect, #304), redirects `?instagram=ok\|gagal&pesan=…#insight`. |
| POST | `/analytics/oauth/instagram/sync` | Profile + 1×50 newest + 3 history pages + 25 media insights (tiered `views,reach,saved,shares` → `reach,saved` → `reach`; missing → null). Token refreshed when <30 days left and older than 24 h. |
| POST | `/analytics/oauth/instagram/disconnect` | Clears tokens (posts stay). |

Secrets precedence: `META_MODEL_API_KEY`, `TIKTOK_CLIENT_KEY/SECRET`,
`INSTAGRAM_APP_ID/SECRET` from the environment win over the settings table
(like `config.php` constants won in legacy). OAuth redirect URIs are
`{API_PUBLIC_URL}/api/v1/konten/analytics/oauth/{tiktok,instagram}/callback`
(both must be registered in the TikTok/Meta apps, dev and production).
`OFFICE_VUE_URL` tells the callbacks where the Vue app lives.

## Google Review (#greview)

v1 port of `konten-mysql/lib_greview.php` (office `develop`): the Analytics
"Google Review" menu. Base: `/api/v1/konten/analytics/google`. Same gate
`konten|campaign`; place search and disconnect need a campaign module
admin; the OAuth callback is public (the single-use state token is the
guard, like legacy `campaign-google.php`) and redirects to the Vue app
(`{OFFICE_VUE_URL}/konten/greview?google=ok|gagal&pesan=…#greview`).

Two sources, and the difference decides what can be done: **Business
Profile** (OAuth of the profile owner/manager) returns ALL reviews plus
their replies and can post replies; **Places API** (key) returns 5 reviews
per pull and is read-only. Places covered by Business Profile are never
pulled again; duplicate profiles are skipped. There is no review delete:
`ditangani` is a flag, `pertama_at` is insert-only, and reviews reach the
AI only when asked (`/ai`, `/draft`, through the existing Meta provider).

Reads and writes share the physical `cg_gtempat` / `cg_gtempat_harian` /
`cg_greview` tables (plus the `google` row of `cg_akun`) with the old
analytics frontend. Validation messages are the legacy Indonesian strings
verbatim.

| Method | Path | Notes |
|---|---|---|
| GET | `/google` | Full read: `tempat`, `harian` (120 days), `review` (≤3000, newest first), `totalTersimpan`, `akun` (no tokens, with `terhubung`), `ai` (stored analysis or null), `syncAt`, `galat`. |
| POST | `/google/sync` | GBP reviews (full history once per location, then head pages) + Places top-up for uncovered places. → `{jumlah, galat}`. A history longer than 40 pages continues from a stored cursor (`gbp_bfc_*` in `cg_setting`) on the next sync, after page 1 (#304). |
| POST | `/google/start` | → `{url}` (Google OAuth, offline + consent, state 15 min). Needs client id+secret. |
| GET | `/oauth/google/callback?code&state` | Public. Exchanges, saves token, pulls locations once (no review sync inside the redirect, #304: the message asks to press Sinkronkan), redirects to Vue `?google=ok\|gagal&pesan=…#greview`. |
| POST | `/google/reply` | `{id*, teks*}` — published on Maps under the business name (`balasan_oleh` = the writer). GBP only (≤4000 chars). |
| POST | `/google/reply/delete` | `{id*}` — deletes our reply on Google too (confirm in the client). GBP only. |
| POST | `/google/flag` | `{id*, ditangani, catatan}` — internal follow-up flag (≤1500 chars), separate from the public reply. |
| POST | `/google/ai` | `{hari (7–365, default 90), tempat}` → guest-experience analysis stored under `gr_ai`: ringkasan, sentimen, kekuatan, perbaikan, tangani (≤15, with reply drafts). Invented review ids dropped. |
| POST | `/google/draft` | `{id*, arahan}` → one reply draft (≤600 chars, never stored). |
| POST | `/google/search` | **Campaign admin.** `{q*}` → `{hasil: [{id, nama, alamat, rating, total}]}` (Place-ID finder for Pengaturan). |
| POST | `/google/disconnect` | **Campaign admin.** Clears tokens (reviews & places stay). |

Settings additions (`PUT /analytics/settings`, campaign admin):
`googleClientId`, `googleClientSecret`, `placesKey` (empty = keep,
`HAPUS` = clear) and `placesIds` (non-secret; anything not Place-ID
shaped is rejected). The setting summary gains `googleOauthSiap`,
`googleDariConfig`, `googleRedirectUri`, `placesKeyAda`,
`placesDariConfig`, `placesIds`.

Secrets precedence: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
`GOOGLE_PLACES_KEY` from the environment win over the settings table.
The OAuth redirect URI is
`{API_PUBLIC_URL}/api/v1/konten/analytics/oauth/google/callback` (must be
registered in the Google Cloud OAuth client, dev and production).
