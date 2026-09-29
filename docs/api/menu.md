# Menu API v1 — contract

This is the complete v1 contract for the `menu` module. It is **greenfield**
(see [docs/modules/menu.md](../modules/menu.md)): there is no legacy route, no
import and no parity to keep, so every endpoint below is the contract. It is
consumed by **laksamana-homepage** (public read) and **laksamana-office-vue**
(the Office Menu panel, write).

- **Base URL:** `/api/v1/menu`
- **Auth:**
  - Public reads: none;
  - Office: `Authorization: Bearer <token>` from `POST /api/v1/auth/login {login, pin}` **plus** module `menu`. Missing/bad token → `401 unauthenticated`; token without the module → `403 module_not_granted`.
- **Envelope:** success `{data, meta?}`, failure `{error: {code, message, details?}}` (`App\Support\Api\ApiResponse`).
- **Fields:** Indonesian JSON names, as stored. Prices are **integer IDR** (`45000`, never `45.0` or `"Rp 45.000"`).

## Registering the module key

`menu` is a new module key. Register it once through the existing account API
before granting it to anyone (no migration does this):

```http
POST /api/v1/account/modules
{ "modules": [{ "key": "menu", "label": "Menu" }] }
```

`syncModules` **adds new keys only**; an existing key is never touched, so the
call is safe to repeat. It returns `201 {"data": {"added": ["menu"]}}` (`added`
is empty when the key already existed). The key then needs **granting to the
intended users** through Kelola Akses
(`PUT /api/v1/account/users/{id}/access {"module":"menu","access":true}`, or
the Office UI); a token whose user has no `menu` access gets `403`.

## Consumer map

| Consumer | Screens | Endpoints |
|---|---|---|
| laksamana-homepage | homepage "Our Best Menu" cards | `GET /unggulan` (static fallback on error/empty) |
| laksamana-homepage | future `/menu` page | `GET /jenis` + `GET` |
| laksamana-office-vue | Menu panel bootstrap | `GET /office/state` |
| laksamana-office-vue | Kategori editor | `GET/POST /office/kategori`, `PATCH/DELETE /office/kategori/{id}` |
| laksamana-office-vue | Item editor | `GET/POST /office/item`, `GET/PATCH/DELETE /office/item/{id}` |
| laksamana-office-vue | daily availability (use case b) | `PATCH /office/item/{id}/tersedia` |
| laksamana-office-vue | publication toggle | `PATCH /office/item/{id}/tampil` |
| laksamana-office-vue | drag-to-order | `PUT /office/urutan` |
| laksamana-office-vue | item photos | `POST /office/foto`, `DELETE /office/foto/{key}`, `GET /foto/{key}` |

## Public (no auth)

Literal paths are declared **before** `/menu/{slug}`.

| Method | Path | Returns |
|---|---|---|
| GET | `/api/v1/menu/jenis` | The full tab/filter tree (below) |
| GET | `/api/v1/menu` | Item list; filters below; only `tampil=true` items in `aktif=true` categories |
| GET | `/api/v1/menu/unggulan` | The homepage cards: items with `unggulan=true`, default order. Empty is a valid answer. |
| GET | `/api/v1/menu/{slug}` | One item, the same shape as a list entry, or `404 not_found` |
| GET | `/api/v1/menu/foto/{key}` | Streams the photo file |

The three literal-path routes (`jenis`, `unggulan`, `foto/{key}`) are matched
before `{slug}`; `{slug}` is restricted to `[a-z0-9-]+` and `{key}` to
`[A-Za-z0-9._-]+`.

### Filters on `GET /menu`

| Param | Values | Notes |
|---|---|---|
| `jenis` | `makanan` \| `minuman` | |
| `bagian` | `main_course` \| `snack` | matches the item's own `bagian` |
| `kategori` | category `slug` | |
| `q` | free text, ≤ 100 chars | matches `nama`, `komponen` or `deskripsi` |
| `unggulan` | `1`/`true`/`0`/`false` | `true` = featured only |

Invalid `jenis`/`bagian` values → `422 validation_failed`.

Default order: `kategori.urutan`, then `item.urutan`, then `nama`.
Only `tampil = true` items in `aktif = true` categories are ever returned, on
any public route.

`meta`:

- `GET /menu` and `GET /unggulan`: `{ total, version }`;
- `GET /menu/{slug}`: `{ version }`;
- `GET /menu/jenis`: no `meta`.

### Public item shape

An allow-list projection, never "everything minus secrets". Keys, in order:

```json
{
  "id": "01J...",
  "slug": "nasi-goreng-laksamana",
  "nama": "Nasi Goreng Laksamana",
  "jenis": "makanan",
  "bagian": "main_course",
  "kategori": { "slug": "nusantara", "nama": "Nusantara" },
  "deskripsi": "",
  "komponen": "nasi, telur mata sapi, sambal",
  "foto_url": "/api/v1/menu/foto/mn_ab12cd34.jpg",
  "varian": [ { "label": "", "harga": 45000 } ],
  "harga_mulai": 45000,
  "unggulan": true,
  "rekomendasi": false,
  "pedas": false,
  "vegetarian": false,
  "ramah_anak": false,
  "tersedia": true
}
```

- `foto_url` is `null` when the item has no photo; otherwise
  `/api/v1/menu/foto/<foto_key>`.
- `bagian` is `null` for drinks (`minuman`).
- `varian` is `[{label, harga}]` in `urutan` order; `label` is `""` for a
  single-price item. Public variants never expose their `id`/`urutan`.
- `harga_mulai` is `MIN(harga)` over the item's variants, or `0` when it has
  none.
- `tersedia` is **public**: a habis item is still listed, flagged.

### `GET /menu/jenis`

The tab/filter tree. Every **active** category is listed, even one with no
items yet (the panel creates categories before items). Food carries the two
Bagian values; drinks have **no `bagian` key**:

```json
{
  "makanan": {
    "bagian": [
      { "kunci": "main_course", "nama": "Main Course" },
      { "kunci": "snack", "nama": "Snack" }
    ],
    "kategori": [ { "slug": "nusantara", "nama": "Nusantara" } ]
  },
  "minuman": {
    "kategori": [ { "slug": "coffee", "nama": "Coffee" } ]
  }
}
```

The seeded catalogue has 8 food and 9 drink categories.

### Caching

No server-side cache in v1. Public reads send:

- `Cache-Control: public, max-age=60, stale-while-revalidate=300`;
- `ETag` = the version: for a list and for `GET /menu/{slug}` it is a hash of
  the newest `updated_at` in the returned set; `GET /menu/jenis` has no item
  set, so its `ETag` is a hash of the response body;
- `If-None-Match` matching the current `ETag` → `304 Not Modified` with an
  empty body.

`GET /menu/foto/{key}` sends `Cache-Control: public, max-age=86400` instead.
Add a server cache later **only** if a measured p95 shows it is needed.

## Office (`auth:sanctum` + `module:menu`)

All under `/api/v1/menu/office`.

| Method | Path | Notes |
|---|---|---|
| GET | `/state` | Bootstrap: every item (incl. `tampil=false`), internal fields, variants, all categories (incl. `aktif=false`), plus `bagian`. `data = {kategori, item, bagian}` |
| GET | `/kategori` | List, ordered `jenis, urutan, nama`. `meta.total` |
| POST | `/kategori` | Body `{jenis, nama, slug?, urutan?, aktif?}`. `jenis` ∈ `makanan`/`minuman`, `nama` required; `slug` is generated from `nama` when absent. `201`. Duplicate slug → `409 already_exists` |
| PATCH | `/kategori/{id}` | Partial: `nama`, `jenis`, `slug`, `urutan`, `aktif`. `If-Match` required |
| DELETE | `/kategori/{id}` | `If-Match` required. `409 kategori_dipakai` when items reference it. `200 {deleted, id}` |
| GET | `/item` | List; the public filters `jenis`, `bagian`, `kategori`, `q`, `unggulan` plus `tampil` (see below). `meta.total` |
| POST | `/item` | Body = the record **including `varian: [...]`**. `kategori_id` (or `kategori` = slug) required. `201` |
| GET | `/item/{id}` | One office item (below), incl. `version` |
| PATCH | `/item/{id}` | Partial. A `varian` array sent **replaces the whole set**. `If-Match` required |
| DELETE | `/item/{id}` | Cascades its variants. `If-Match` required. `200 {deleted, id}` |
| PATCH | `/item/{id}/tampil` | `{tampil: bool}` — publication toggle. **No `If-Match`** |
| PATCH | `/item/{id}/tersedia` | `{tersedia: bool}` — use case (b), the fast toggle. **No `If-Match`** |
| PUT | `/urutan` | `{kategori?: [{id, urutan}], item?: [{id, urutan}], varian?: [{id, urutan}]}` — any subset; unknown ids are skipped. Returns `{saved: true}`. **No `If-Match`** |
| POST | `/foto` | Multipart field `file`, image only, ≤ 8 MB → `201 {key, url}` |
| DELETE | `/foto/{key}` | Removes the file. → `200 {deleted, key}`. The item's `foto_key` is cleared by its own PATCH |

`GET /office/item` filters: the public `jenis`, `bagian`, `kategori`, `q`,
`unggulan` plus `tampil`. Unlike the public list it also shows `tampil=false`
items and items of `aktif=false` categories (that is the point of the panel).

### Office item shape

The public shape **plus** the office-only keys:

| Extra key | Meaning |
|---|---|
| `kategori_id` | the category ULID |
| `tampil` | publication flag |
| `urutan` | display order within the category |
| `catatan_internal` | office-only note |
| `version` | the optimistic-concurrency value |

and each entry of `varian` gains `id` and `urutan`
(`[{id, label, harga, urutan}]`).

`data.kategori` rows are `{id, jenis, nama, slug, urutan, aktif, version}`.

### Write rules

- **`If-Match: "<version>"`** (or `?version=`) is required on kategori/item
  `PATCH` and `DELETE`. Missing or non-numeric → `428 version_required`; stale
  → `409 version_conflict` with `error.details.current` = the current version.
- **Exempt from `If-Match`:** the two toggles (`tampil`, `tersedia`) and
  `PUT /urutan`. They are last-writer-wins and still bump `version`.
- **A `varian` array sent on item PATCH replaces the whole set.** Omit `varian`
  to leave the variants untouched.
- **Validation → `422 validation_failed`:**
  - `nama` required on create and never blank;
  - a category must exist (`kategori_id` or `kategori`); neither sent →
    `422`;
  - `bagian` is required when the category is `makanan`, and must be `null`
    when it is `minuman`;
  - each variant's `harga` must be numeric and `>= 0`; `varian` must be an
    array of objects;
  - `slug` must be unique (a new one that collides → `409 already_exists`);
  - the toggle body must carry its `tampil`/`tersedia` key, else `422`.
- **Every write stamps `updated_by` from the Sanctum user** (`NULL` when the
  acting account has no core ULID). `created_by` is stamped on create.

## Photos

- **Upload:** multipart `file`. Image mime only (`image/jpeg`, `image/jpg`,
  `image/png`, `image/webp`), ≤ 8 MB (8 \* 1024 \* 1024 bytes). A missing
  field → `422 validation_failed`; a non-image or an oversized file →
  `422 invalid_file`. Success → `201 {key, url}`.
- **Key format:** `mn_<hex>.<ext>`, e.g. `mn_ab12cd34.jpg`. `url` is
  `/api/v1/menu/foto/<key>`.
- **Stream:** `GET /foto/{key}` returns the bytes with the stored mime and
  `Cache-Control: public, max-age=86400`; a bad key → `400`, a missing file →
  `404`.
- **Delete:** removes the file; missing is not an error (`deleted: false`).
  The item is not touched.
- **Orphans are not garbage-collected in v1** (the `ponytail:` note in the
  code): an item PATCH that clears `foto_key` leaves the file behind.

## Errors

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No or bad token on an office route |
| 403 | `module_not_granted` | Token without `menu` |
| 404 | `not_found` | Unknown slug (public) or id (office) |
| 409 | `version_conflict` | Stale `If-Match`; `error.details.current` = current version |
| 409 | `already_exists` | Duplicate kategori/item slug |
| 409 | `kategori_dipakai` | Deleting a kategori items still reference |
| 422 | `validation_failed` | Bad body (see the write rules) |
| 422 | `invalid_file` | Photo upload not an image, or > 8 MB |
| 428 | `version_required` | PATCH/DELETE without `If-Match`/`version` |

## For the homepage

`HomeView.vue` (today the hard-coded `menuDishes`, in
`src/views/home/homeContent.ts`) replaces its featured dishes with
`GET /api/v1/menu/unggulan`, **falling back to the current static copy** on
error or when the list is empty. The `/menu` route (today `ComingSoonView`)
uses `GET /api/v1/menu/jenis` for the tabs and `GET /api/v1/menu` for the
grid. The homepage's origin must be in the backend's
`CORS_ALLOWED_ORIGINS`.
