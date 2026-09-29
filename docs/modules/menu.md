# menu — homepage + Office catalogue

**Greenfield — no legacy PHP, no legacy database, no `deploy/menu` panel, no
parity cases.** The porting checklist in `../../CLAUDE.md` §2 does **not**
apply. There is nothing to port and nothing to be byte-compatible with; the
[spec](../../../laksamana-homepage/docs/10-MENU-API-REQUEST.md) is the
contract. Detail lives in:

- **[docs/db/menu.md](../db/menu.md)** — schema, ERD, columns, delete
  behaviour, concurrency, seeding.
- **[docs/api/menu.md](../api/menu.md)** — the complete v1 contract.

The `TopMenu` shape in the homepage repo (`docs/01`, `docs/05`, `docs/07`) is
from an abandoned earlier attempt and is **not** an authority.

## Locked decisions

| # | Decision | Value |
|---|---|---|
| 1 | Sell price lives in | Menu's own `menu_varian.harga` (not `stock.hpp_resep`) |
| 2 | Module | New module key `menu` (not inside `konten`/`stock`) |
| 3 | `tersedia` semantics | Persistent boolean on the item (no per-day table) |
| 4 | Price variants | `menu_varian` table (`label` + `harga`), any item may have 1..n |
| 5 | JSON field names | Indonesian (`nama`, `harga`, `tersedia`, …) |
| 6 | Hierarchy terms | Jenis (Makanan/Minuman) → Bagian (Main Course/Snack) → Kategori |
| 7 | Food categories | Flat shared list of 8, usable under either Bagian |
| 8 | Photos | Admin upload from empty; no PDF export in v1 |
| 9 | Public cache | No server-side cache; `Cache-Control` + `ETag` |
| 10 | Money | Integer IDR (`45000`, not `45`) |

## The visible hierarchy

```
Makanan                          ← jenis (column, not a table row)
├─ Main Course                   ← bagian (column on the item)
│  └─ Nusantara                  ← kategori (menu_kategori row)
└─ Snack
   └─ Sail Satay

Minuman                          ← jenis
└─ Coffee                       ← kategori (no bagian level)
```

`Jenis` and `Bagian` are **columns, never table rows** — there is no
"Makanan" row to maintain. `bagian` is a two-value enum that never changes; if
its labels ever need editing from the panel, promote it to a table (marked
with a `ponytail:` comment in `MenuCatalog::bagian`).

**The 8 food categories** (shared, may appear under either Bagian):
`Nusantara`, `Harbour Bowls`, `Asian Kitchen`, `Wok Specials`, `Western`,
`Pasta`, `Sail Satay`, `Cake & Pastries`.

**The 9 drink categories:**
`Coffee`, `Tea Collection`, `Signature Non Coffee`, `Fresh & Tropical`,
`Coconut Fresh`, `Warm Comfort`, `Wellness Tea`, `Blended Refresher`,
`Grab and Go`.

## Tables

Three tables in `core` (schema: [docs/db/menu.md](../db/menu.md)):
`menu_kategori` → `menu_item` → `menu_varian`. Kategori 1—n item
(`RESTRICT`), item 1—n varian (`CASCADE`). No `legacy_id`, no `deleted_at`,
hard deletes, one `version` column per row.

`jenis` lives on the kategori; `bagian` (`main_course`/`snack`) lives on the
item and is service-enforced (`makanan` requires it, `minuman` must leave it
null). `harga_mulai` is not stored — it is `MIN(harga)` at read time.

## Wiring

`menu` is the first module whose connection is `core` by default:

```php
// config/laksamana.php, $modules
'menu' => ['env' => 'MENU', 'database' => 'lakk5493_db_menu', 'connection' => 'core'],
```

`database` is unused (kept only so the connection-building loop has a key) and
`lakk5493_db_menu` is never created. `config/database.php` still builds an
unused `legacy_menu` connection for this entry; it is never resolved.

The same file's normalisation loop makes an explicit `connection` win:

```php
$m['connection'] = env(
    'DB_'.$envKey.'_CONNECTION',
    $m['connection'] ?? env('DB_'.$m['env'].'_CONNECTION', 'legacy_'.strtolower($m['env']))
);
```

That is a one-line change; every existing module keeps its current default.

- **No `legacy` key, no `legacy_policies` entry, no `routes/legacy.php`.**
  `ModuleServiceProvider` auto-loads only what exists, so `menu` has a v1
  surface and nothing else. `MenuServiceProvider` registers only the
  `menu:seed` command.
- **No `Importer`** in `CoreServiceProvider` — there is nothing to import
  from. `menu` never appears in `core:import`.
- **Register the access key** through the account API (not a migration), then
  grant it:

  ```http
  POST /api/v1/account/modules   { "modules": [{ "key": "menu", "label": "Menu" }] }
  ```

  `syncModules` adds new keys only, so this is idempotent. The key then needs
  granting to the intended users (`PUT /api/v1/account/users/{id}/access` or
  the Office UI).

Models (`MenuKategori`, `MenuItem`, `MenuVarian`) extend
`App\Core\Models\CoreRecord`, which supplies the ULID key, the `core`
connection and the `version` auto-bump.

## Seeding

The one-time data source is the committed
`database/seeders/data/menu.json` (categories only today; the `item` array
starts empty so the panel can be filled by hand).

```bash
php artisan menu:seed           # idempotent by slug
php artisan menu:seed --fresh   # delete every menu row first
```

Prices in the JSON are integer IDR and the seeder does **not** multiply. An
unknown `kategori` slug aborts the whole run before writing. A second run
changes nothing.

## Consumers

- **laksamana-homepage** — `HomeView.vue` replaces the hard-coded
  `menuDishes` with `GET /api/v1/menu/unggulan` (static fallback on
  error/empty); the `/menu` route (today `ComingSoonView`) uses
  `GET /api/v1/menu/jenis` + `GET /api/v1/menu`. Its origin must be in the
  backend's `CORS_ALLOWED_ORIGINS`.
- **laksamana-office-vue** — a new Panel (entry in `app/utils/modul.ts` with
  `access: ['menu']`, group "Operasional"; `app/composables/useMenu.ts`;
  pages under `app/pages/menu/`). Separate ticket; not built by the API agent.
- **laksamana-office** (legacy) is **not** touched — it has no menu panel and
  is read-only.

## Tests

`tests/Feature/Menu/`: `MenuPublicTest.php` + `MenuOfficeTest.php` (2 test
files, shared `helpers.php`).

```bash
php artisan migrate
php artisan test tests/Feature/Menu
```
