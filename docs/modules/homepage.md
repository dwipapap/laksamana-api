# homepage — event di website (saklar)

**Greenfield on `core` — no legacy PHP, no legacy database, no `deploy/homepage`
panel, no parity cases.** There is nothing to port; the point is a small switch
table the website and the Office share.

The event data itself belongs to the **Event (EMS) module**. This module only
stores **which** event appears on the public site, and never writes a single EMS
row. The public website reads; Office flips the switch.

Detail lives in:

- **[docs/db/homepage.md](../db/homepage.md)** — the `homepage_event` schema.
- **[docs/api/homepage.md](../api/homepage.md)** — the complete v1 contract.

## Locked decisions

| # | Decision | Value |
|---|---|---|
| 1 | Owner of event data | Event (EMS). Homepage only reads, through `EventState::emsRows()` |
| 2 | Where the switch lives | New `core` table `homepage_event` (this greenfield module) |
| 3 | Default for a new event | **Off** — nothing shows without a person switching it on |
| 4 | Events that may be switched on | status ∈ {Upcoming, Today} **and** not past; a row switched on that stops qualifying drops off the site, its row is kept |
| 5 | Office access key | `homepage` (Office Vue: `['menu', 'news', 'homepage']`) |
| 6 | Website order | `start ASC`; no manual order |
| 7 | Website CTA | WhatsApp (online reservations stay off) |

## The rule

One function, `HomepageEvents::eligible()`, shared by the public feed, the office
list and the toggles:

```
eligible = status ∈ {Upcoming, Today} AND not past
past     = end_datetime < now
           or, with no end: the WIB date of start_datetime is before today WIB
```

The status list is copied from `TicketShop::sellable()` — no new list. Dates are
UTC instants (suffix `Z`); the projection renders WIB.

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

Models: `HomepageEvent` extends `App\Core\Models\CoreRecord` (ULID key, `core`
connection, `version` auto-bump).

## Code map

```
app/Modules/Homepage/
  Models/HomepageEvent.php        the switch row
  Services/HomepageEvents.php     eligibility + public/office shapes + toggle + poster
  Http/V1/HomepageController.php  public: list, poster
  Http/V1/HomepageOfficeController.php  office: list, poster, toggle
  routes/v1.php                   /api/v1/homepage/...
  HomepageServiceProvider.php
```

`TicketShop::posterAny()` (new) serves a poster by event id whatever the status,
for the Office preview; the public path still gates on switched-on + eligible
before it.

## Tests

`tests/Feature/Homepage/`: `HomepagePublicTest.php` + `HomepageOfficeTest.php`
(shared `helpers.php`; EMS fixtures relative to now).

```bash
php artisan test tests/Feature/Homepage
```
