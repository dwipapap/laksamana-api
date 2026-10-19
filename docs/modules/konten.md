# konten — content production

- **Legacy source:** `laksamana-office/konten-mysql/`
- **Legacy URL:** `/konten-api-mysql/api.php`
- **Database:** `lakk5493_db_konten`
- **Data dir:** `/home/lakk5493/konten-db`, via `KONTEN_DATA_DIR`
- **Deployment:** the legacy backend was uploaded manually; it was never in CI.

This is an older variant of marketing's saveAll. Reuse `App\Support\RowSync` (built with marketing).

## Tables (schema.sql)

- Collections, each with `id` PK, `updated_at` BIGINT, `data` JSON, plus indexed columns:
  - users, brands, campaigns, content (+created_at), prod_tasks, shootings, assets, bank, kols, visits, ads, ad_funds, notifs
- `logs`: append-only, trimmed to 5000 rows.
- `settings`: key/value store (settings, perms, seeded, `extra:*`).
- Content performance data lives in `content.data.perf` (per platform).
- **Keep `bank`** even though its screen was removed. Dropping it from the collections would empty the table.

## Actions (all open)

- `getAll`, `stats`, `ping`.
- `receipt&key`: streams a stored file.
- `uploadReceipt {dataBase64,mimeType,fileName}`:
  - max 8 MB;
  - returns `{key,name}`;
  - stores `<DATA_DIR>/receipts/rc_<hex>.<ext>`.
- `saveAll {data}`:
  - runs under `GET_LOCK('<db>:konten_save')`;
  - returns `{saved,jumlah,berkasDibuang,bentrok[],backend,ts}`.

## saveAll rules (legacy)

- **Conflict check:** if `baseUpdatedAt` is older than the server's `updated_at`, the row goes into `bentrok`.
- **Upsert guard:** `IF(VALUES(updated_at)>=updated_at,…)`, bumping the timestamp by +1 when needed.
- **Missing collections** in the payload are skipped.
- **Empty lists** never delete anything.
- **LEGACY BUG — reproduce on the compat route:** `hapus_yang_hilang` has NO `_sejak` bound. It deletes every row not in the payload, including rows created after the client loaded. Document this; v1 has no whole-state save.
- **GC:** hard-unlinks orphan receipt files after 1 hour (no trash folder).
- **Strict core (#97):** on `core` the writer truncates each string column to its physical width (and the settings key to 64), as non-strict MySQL did on the legacy connection.

## v1 (implemented — contract: docs/api/konten.md)

- 13 resources (`users`, `brands`, `campaigns`, `content`, `prod-tasks`,
  `shootings`, `assets`, `bank`, `kols`, `visits`, `ads`, `ad-funds`,
  `notifications`): full CRUD, one record per request, explicit per-record
  delete. No whole-state save — the legacy `_sejak` data-loss bug cannot
  happen here.
- `PUT /content/{id}/performance` merges one platform's figures into
  `content.data.perf` (plus an `{at, by}` stamp). Read models aggregate
  client-side from the content rows, exactly as the old app does.
- Files: `POST /files` (multipart `file`, or JSON
  `{dataBase64,mimeType,fileName}`; ≤8 MB, images/PDF) returns
  `{key,name,url}`; `GET /files/{key}` streams. Store the key inside the
  record; files no record references are hard-deleted 1 hour after upload.
- Concurrency: a record's version is its `updatedAt` (ms). Writes need it
  (`If-Match` header or `version` in the body / query); a successful write
  stamps `max(now, stored+1)` — the same guards under the same
  `konten_save` lock as `saveAll`, so a v1 version is a valid
  `baseUpdatedAt` for old tabs. An identical-content rewrite is a no-op
  success; every write appends a `logs` entry (`<Label> … (API)`).
- `GET /state` (one-call bootstrap) + `GET /stats`, `GET /logs` +
  `POST /logs`, `GET|PUT /documents/{settings,perms,seeded}` (whole-object,
  content-hash versioned).

## Google Review (menu Analytics, port of `lib_greview.php`)

Legacy source (office `develop` 78424a5, 6e578df — not on `main`):
`konten-mysql/lib_greview.php` + `campaign-google.php`, 11
`campaignGoogle*` actions through `cg_aksi`. **No legacy compat route:**
campaign actions were never ported to `KontenLegacyController` (TikTok/IG
likewise v1-only), so the old frontend keeps calling the legacy PHP for
these while Vue uses v1.

- **Tables** (konten DB, `CREATE TABLE IF NOT EXISTS` in
  `CampaignAnalytics::ensureTables`, same shape as `gr_pastikan` — no
  Laravel migration, per the legacy-table rule): `cg_gtempat` (watched
  places, `sumber` gbp/places), `cg_gtempat_harian` (PK
  `tempat_id,tgl`, daily rating/total snapshots), `cg_greview` (PK
  `sha1` of the review resource name). `cg_akun` row `platform='google'`
  holds the OAuth tokens; `cg_setting` keys `google_client_id`,
  `google_client_secret`, `google_places_key`, `google_places`,
  `google_state`, `google_sync_at`, `google_galat`, `gr_ai`, `gbp_bf_*`.
  Env `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_PLACES_KEY` win
  over the table, like the TikTok/IG keys.
- **Two sources:** Business Profile OAuth (`business.manage`,
  `access_type=offline` + `prompt=consent`) pulls ALL reviews and can
  reply (PUT/DELETE v4 `/reply`); Places API key pulls 5 reviews per
  place, read-only, accumulated per sync. Places covered by GBP (same
  `metadata.placeId`) are skipped; `metadata.duplicateLocation` profiles
  are skipped (and deactivated). GBP backfills once per location
  (`gbp_bf_<sha1>`, ≤40 pages `updateTime desc`), then the first pages.
- **Never delete:** `cg_greview` has no delete path; `pertama_at` is
  insert-only; sync only overwrites GBP replies from Google itself.
  Reviews reach the AI only via `google/ai` + `google/draft` (existing
  `aiAsk()` provider); invented AI review ids are dropped.
- **v1** (gate `konten|campaign`; search/disconnect need a campaign admin;
  OAuth callback public, state-guarded, redirects to
  `{OFFICE_VUE_URL}/konten/greview?google=ok|gagal&pesan=…#greview`):
  contract in `docs/api/konten.md` ("Google Review"), tests in
  `tests/Feature/Konten/GoogleReviewTest.php`.
- **Deliberate differences from legacy:** PATCH updates merge absent keys
  from the stored row (so partial payloads validate as the whole they
  become); update-without-version is 428 and stale-version 409 before
  field validation (the documented contract, which the port now keeps);
  `safe()` passes controller-built responses through unwrapped
  (previously double-enveloped); disconnect appends a log entry like the
  TikTok/IG disconnects. Validation messages stay the legacy Indonesian
  strings verbatim.
