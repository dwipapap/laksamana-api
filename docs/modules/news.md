# news — homepage Berita + Office panel

**Greenfield — no legacy PHP, no legacy database, no `deploy/news` panel, no
parity cases.** The porting checklist in `../../CLAUDE.md` §2 does **not**
apply. There is nothing to port and nothing to be byte-compatible with; the
[spec](../../../laksamana-homepage/docs/12-NEWS-API-REQUEST.md) is the
contract. Detail lives in:

- **[docs/db/news.md](../db/news.md)** — schema, ERD, columns, delete behaviour,
  concurrency, sanitization, seeding.
- **[docs/api/news.md](../api/news.md)** — the complete v1 contract.

The `News` shape in the homepage repo (`docs/01`, `docs/05`, `docs/07`) is from
an abandoned earlier attempt and is **not** an authority. `konten`
(`module:konten`) is not a source and is never read or written from here.

## Locked decisions

| # | Decision | Value |
|---|---|---|
| 1 | Module | New module key `news`, connection `core` (greenfield, like `menu`) |
| 2 | Public surface | Listing + detail: `GET /news`, `GET /news/{slug}` |
| 3 | Ordering | `published_at DESC` (newest first); no manual item order |
| 4 | Homepage rail | Newest N read straight from the listing; no featured flag |
| 5 | Categories | Small shared table `news_kategori` (flat, `aktif`, ordered) |
| 6 | Body content | Rich text `isi`, stored as **server-sanitized HTML** |
| 7 | Publish state | `tampil` boolean; drafts are office-only |
| 8 | Photos | Admin upload, streamed by `GET /news/foto/{key}` (no data dir) |
| 9 | Cache | No server-side cache; `Cache-Control` + `ETag` |
| 10 | Slug | Unique, `[a-z0-9-]+`; the public URL is `/news/{slug}`; `kategori`, `office`, `foto` are reserved |
| 11 | Homepage relationship | **Model A**: `news` owns articles, a future `homepage` module only composes |

## Tables

Two tables in `core` (schema: [docs/db/news.md](../db/news.md)):
`news_kategori` → `news_artikel`. Kategori 1—n artikel (`RESTRICT`). No
`legacy_id`, no `deleted_at`, hard deletes, one `version` column per row.

`published_at` is the only ordering key; there is no `urutan` on the article.
`isi` is stored sanitized (`NewsSanitize`), so the homepage may render it with
`v-html` without adding a sanitizer.

## Wiring

Like `menu`, `news` lives in `core` from day one:

```php
// config/laksamana.php, $modules
'news' => ['env' => 'NEWS', 'database' => 'lakk5493_db_news', 'connection' => 'core'],
```

`database` is unused (kept only so the connection-building loop has a key) and
`lakk5493_db_news` is never created. `config/database.php` still builds an
unused `legacy_news` connection for this entry; it is never resolved.

- **No `legacy` key, no `legacy_policies` entry, no `routes/legacy.php`.**
  `ModuleServiceProvider` auto-loads only what exists, so `news` has a v1
  surface and nothing else. `NewsServiceProvider` registers only the
  `news:seed` command (added to `bootstrap/providers.php`).
- **No `Importer`** in `CoreServiceProvider` — there is nothing to import from.
  `news` never appears in `core:import`.
- **Register and grant the access key** with one command (not a migration):

  ```bash
  php artisan office:grant <login> news   # --dry-run to preview
  ```

  It registers the key when it is new (`syncModules` adds only) and grants it
  to one active user found by name or username; idempotent. The account API
  (`POST /api/v1/account/modules`, `PUT /api/v1/account/users/{id}/access`)
  does the same by hand.

Models (`NewsKategori`, `NewsArtikel`) extend `App\Core\Models\CoreRecord`,
which supplies the ULID key, the `core` connection and the `version` auto-bump.

## Module layout

```
app/Modules/News/
  Models/       NewsKategori.php, NewsArtikel.php   (extends CoreRecord)
  Services/     NewsCatalog.php    (public reads, allow-list projection)
                NewsAdmin.php      (writes, validation, publish toggle)
                NewsPhotos.php     (upload / stream / delete)
                NewsSanitize.php   (the `isi` allow-list sanitizer)
                NewsSeed.php       (the committed JSON load)
                NewsConflict.php   (optimistic-concurrency failure)
  Http/V1/      NewsController.php        (public)
                NewsOfficeController.php  (auth + module:news)
  Console/      NewsSeedCommand.php
  routes/v1.php
  NewsServiceProvider.php
```

## Seeding

The one-time data source is the committed
`database/seeders/data/news.json`. It ships the three start categories
(`Event`, `Promo`, `Kabar`) and an empty `artikel` array: articles are written
in the Office panel. A `published_at` in the file without an offset is read as
WIB.

```bash
php artisan news:seed           # idempotent by slug
php artisan news:seed --fresh   # delete every news row first
```

An unknown `kategori` slug aborts the whole run before writing; a second run
changes nothing. The old homepage `newsItems` fixture is not the seed source.

## Consumers

- **laksamana-homepage** — `HomeNews.vue` replaces the hard-coded `newsItems`
  with `GET /api/v1/news?per_page=3` (static fallback on error/empty); the
  `/news` route uses `GET /api/v1/news/kategori` + `GET /api/v1/news` and
  `/news/{slug}` uses `GET /api/v1/news/{slug}`. Its origin must be in the
  backend's `CORS_ALLOWED_ORIGINS`.
- **laksamana-office-vue** — the "Homepage" Kartu (`access: ['menu', 'news']`),
  Berita screens under `app/pages/homepage/berita/`, calls in
  `app/composables/useHomepage.ts`.
- **laksamana-office** (legacy) is **not** touched — it has no berita panel and
  is read-only.

## Tests

`tests/Feature/News/`: `NewsPublicTest.php` + `NewsOfficeTest.php` (2 test
files, shared `helpers.php`). `news.json` has no articles, so each test inserts
the three sample articles itself (`newsSamples()`).

```bash
php artisan migrate
php artisan test tests/Feature/News
```
