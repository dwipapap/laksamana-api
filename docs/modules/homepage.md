# homepage — event & promo di website (saklar)

**Greenfield on `core` — no legacy PHP, no legacy database, no `deploy/homepage`
panel, no parity cases.** There is nothing to port; the point is a small switch
surface the website and the Office share.

Two data sources stay with their owners:

- the **Event (EMS) module** owns every event row — this module only stores
  **which** event appears on the public site;
- the **BD module** owns the `promos` document — this module only stores the
  switch, the manual position, and the banners uploaded in the panel. It never
  writes a single BD row (Radar and Kompas read that document too).

Detail lives in:

- **[docs/db/homepage.md](../db/homepage.md)** — the `homepage_event` and
  `homepage_banner` schemas.
- **[docs/api/homepage.md](../api/homepage.md)** — the complete v1 contract.

## Locked decisions

| # | Decision | Value |
|---|---|---|
| 1 | Owner of event data | Event (EMS). Homepage only reads, through `EventState::emsRows()` |
| 2 | Where the event switch lives | New `core` table `homepage_event` (this greenfield module) |
| 3 | Default for a new event | **Off** — nothing shows without a person switching it on |
| 4 | Events that may be switched on | status ∈ {Upcoming, Today} **and** not past; a row switched on that stops qualifying drops off the site, its row is kept |
| 5 | Office access key | `homepage` (Office Vue: `['menu', 'news', 'homepage']`) |
| 6 | Website event order | `start ASC`; no manual order |
| 7 | Website CTA | WhatsApp (online reservations stay off) |
| 8 | Promo slider sources | One ordered list over two kinds: BD promos with a poster (data & poster stay in BD), and banners uploaded in the Homepage panel |
| 9 | Owner of promo data | BD OS. Homepage **never writes** the `promos` document (the old panel overwrites it whole, and Radar/Kompas read it) |
| 10 | Where promo rows live | New `core` table `homepage_banner` (this module). No row = a BD promo is not shown. **Default off.** |
| 11 | BD promo may appear when | `promoStatus = running` (WIB, `RadarRules`) **and** the poster decodes (largest 1 MB); `upcoming`/`paused`/`ended`/no poster drop off, their row is kept |
| 12 | Upload may appear when | `tampil = true` **and** today (WIB) inside the optional `mulai`–`selesai` window (empty = no bound) |
| 13 | Promo order | One `urutan` column for both kinds, set manually in the Office; a tie → the older row first. New rows get max+10 |
| 14 | Promo links | Optional `href`: only `https://…` or a `/…` path |
| 15 | Public promo allow-list | `id, sumber, image, alt, href` — never `kode`, `partner`, `kuota`, `lmPIC`, `ketentuan`, `outlet` |
| 16 | Promo images | Only by `homepage_banner` row id, and only while shown & eligible; no key or data URL ever reaches a client. BD posters are decoded server-side |
| 17 | Office promo concurrency | Upload/BD-row PATCH & DELETE need `If-Match` (428/409); the toggles and `PUT /urutan` are exempt |
| 18 | Office promo key | `homepage` (the same key as the event screen) |

## The event rule

One function, `HomepageEvents::eligible()`, shared by the public feed, the office
list and the toggles:

```
eligible = status ∈ {Upcoming, Today} AND not past
past     = end_datetime < now
           or, with no end: the WIB date of start_datetime is before today WIB
```

The status list is copied from `TicketShop::sellable()` — no new list. Dates are
UTC instants (suffix `Z`); the projection renders WIB.

## The promo rule

One function, `HomepagePromos::eligibility()`:

- **BD** (`sumber = bd`): the promo read through `BdState::setting('promos')`
  must exist, be `running` (`RadarRules::promoStatus`, the same function Radar
  uses) and have a decodable poster. The `alasan` for the Office is one of
  `bd_hilang`, `bd_dijeda`, `bd_belum_mulai`, `bd_berakhir`, `bd_tanpa_poster`.
- **Upload** (`sumber = unggah`): `tampil = true` and today inside the optional
  window; the Office reason outside it is `di_luar_periode`.

When BD cannot be read, the public feed keeps serving uploads and the office
list reports `bd_gagal: true`.

**Reading BD cheaply.** The `promos` document carries every poster as a data URL
(up to 400 KB each), so the lists never decode it per request:
`HomepagePromos::bdPromos()` asks the database for `MD5(v)` of the setting
(`BdState::settingHash`) and caches the derived index — the promos without their
poster bytes, plus `_poster_ok` and `_poster_v` — under that hash (1 h). Any BD
save changes the hash, so the index is never stale. Only the two image routes
read the full document (`bdPromosFull()`), and their URLs carry `?v=` so browsers
and a CDN keep them for a day.

**Orphan uploads (ponytail).** A file sent to `POST …/promos/foto` that never
becomes a banner (the form was closed, or the create was refused) stays in
`storage/app/homepage/`. Same trade-off as the news covers: nothing reads it,
and a clean-up is added when it actually accumulates. A replaced or deleted
banner does remove its own file.

## Wiring

```php
// config/laksamana.php, $modules
'homepage' => ['env' => 'HOMEPAGE', 'database' => 'lakk5493_db_homepage', 'connection' => 'core'],
```

`database` is unused (kept only so the connection-building loop has a key) and
`lakk5493_db_homepage` is never created. No `legacy` key, no `routes/legacy.php`,
no `Importer`; `HomepageServiceProvider` registers nothing but the module.
`ModuleServiceProvider` auto-loads `routes/v1.php`.

Register the access key on a server through the CLI (no migration for the
`modules` table):

```bash
php artisan office:grant <login> homepage   # registers the key if new + grants it
```

Models: `HomepageEvent` and `HomepageBanner` extend `App\Core\Models\CoreRecord`
(ULID key, `core` connection, `version` auto-bump).

## Code map

```
app/Modules/Homepage/
  Models/HomepageEvent.php               the event switch row
  Models/HomepageBanner.php              a promo slider row (bd | unggah)
  Services/HomepageEvents.php            event eligibility + public/office shapes + toggle + poster
  Services/HomepagePromos.php            promo eligibility + BD reading + public/office shapes + writes
  Services/HomepagePhotos.php            uploaded banner files (storage/app/homepage, hb_ keys)
  Services/HomepageConflict.php          version_required / version_conflict
  Http/V1/HomepageController.php         public: events list, poster
  Http/V1/HomepagePromoController.php    public: promos list, image by row id
  Http/V1/HomepageOfficeController.php   office: events list, poster preview, toggle
  Http/V1/HomepagePromoOfficeController.php  office: promo panel, previews, upload, CRUD, toggles, order
  routes/v1.php                          /api/v1/homepage/...
  HomepageServiceProvider.php
```

`TicketShop::posterAny()` (new in the event work) serves a poster by event id
whatever the status, for the Office preview; the public path still gates on
switched-on + eligible before it. Promo images follow the same principle: the
office preview accepts a row id or a BD promo id, never a key.

## Tests

`tests/Feature/Homepage/`: `HomepagePublicTest.php`, `HomepageOfficeTest.php`,
`PromoPublicTest.php`, `PromoOfficeTest.php` (shared `helpers.php`; EMS and BD
fixtures relative to now).

```bash
php artisan test tests/Feature/Homepage
```
