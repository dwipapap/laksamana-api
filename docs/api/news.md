# News API (`/api/v1/news`)

Greenfield module on `core` (no legacy backend, no parity). Spec:
laksamana-homepage `docs/12-NEWS-API-REQUEST.md`. Code: `app/Modules/News`.

- Envelope: `{data, meta?}` / `{error:{code,message,details?}}`.
- Public reads: no auth. Office: `Authorization: Bearer <token>` + module `news`.
- Times: stored UTC, returned in WIB (`+07:00`). A `published_at` sent without
  an offset is read as WIB.

## Setup on a server

```bash
# tables: import the CREATE TABLE statements for news_kategori/news_artikel (docs/deploy.md "New core migrations")
php artisan news:seed                 # 3 categories: Event, Promo, Kabar
php artisan office:grant <login> news # registers the key if new + grants it (also: menu)
```

## Public

| Method | Path | Notes |
|---|---|---|
| GET | `/news` | `category` (slug), `q`, `page` (1), `per_page` (9, max 50). Newest first (`published_at DESC, id DESC`). `meta = {total, page, per_page, version}`. Unknown category → `422 validation_failed` |
| GET | `/news/kategori` | Active categories `[{slug, nama}]`, ordered `urutan, nama` |
| GET | `/news/{slug}` | List shape + `content` (sanitized HTML) + `published_at`. `meta.version`. Unknown → `404 not_found` |
| GET | `/news/foto/{key}` | Cover file, `Cache-Control: public, max-age=86400`. Missing → 404 |

Only `tampil = true` articles in `aktif = true` categories are public. Public
reads send `Cache-Control: public, max-age=60, stale-while-revalidate=300` and
an `ETag`; a matching `If-None-Match` answers `304`.

List item:

```json
{
  "id": "01J…", "slug": "horas-party-10-tahun",
  "category": { "slug": "event", "nama": "Event" },
  "title": "…", "excerpt": "…", "date": "2026-02-16",
  "cover": "/api/v1/news/foto/nw_ab12cd34.jpg", "author": "Tim Laksamana"
}
```

`cover` is a path (prefix the API origin) or `null`.

## Office (`/news/office`, Sanctum + `module:news`)

| Method | Path | Notes |
|---|---|---|
| GET | `/state` | `{kategori, artikel}` — every category (incl. inactive) and article (incl. drafts) |
| GET | `/kategori` | `[{id, nama, slug, urutan, aktif, version}]`, `meta.total` |
| POST | `/kategori` | `{nama, slug?, urutan?, aktif?}` → `201`. Duplicate slug → `409 already_exists` |
| PATCH | `/kategori/{id}` | Partial `nama, slug, urutan, aktif`. **If-Match** |
| DELETE | `/kategori/{id}` | **If-Match**. In use → `409 kategori_dipakai` |
| GET | `/artikel` | Filters `category`, `q`, `tampil`. Drafts without a date first, then newest. `meta.total` |
| POST | `/artikel` | `{judul, kategori_id \| kategori, slug?, ringkasan?, isi?, cover_key?, penulis?, tampil?, published_at?}` → `201` |
| GET | `/artikel/{id}` | One office article |
| PATCH | `/artikel/{id}` | Partial, same fields. **If-Match** |
| DELETE | `/artikel/{id}` | **If-Match** |
| PATCH | `/artikel/{id}/tampil` | `{tampil: bool}`. No If-Match. Publishing without a date stamps `published_at = now` |
| PUT | `/urutan` | `{kategori: [{id, urutan}]}` → `{saved: true}`. No If-Match |
| POST | `/foto` | Multipart `file` (jpg/png/webp, ≤ 8 MB) → `201 {key, url}`; else `422 invalid_file` |
| DELETE | `/foto/{key}` | `{deleted, key}`. Clearing `cover_key` is the article's own PATCH |

Office article = list item + `content`, `published_at`, `kategori_id`,
`cover_key`, `tampil`, `version`, `created_at`, `updated_at`.

Write rules:

- `If-Match: "<version>"` (or `?version=`) on kategori/artikel PATCH and DELETE:
  missing → `428 version_required`; stale → `409 version_conflict` with
  `error.details.current`. The toggle and `/urutan` still bump `version`, so
  keep the row they return.
- `slug` is generated from `judul` on create only; a later title change keeps
  the URL. `kategori`, `office` and `foto` are reserved slugs (`422`).
- `isi` is sanitized on write (allow-list): `p br hr strong b em i u s h2 h3 h4
  ul ol li blockquote figure figcaption a[href,title] img[src,alt,width,height]`.
  Scripts/styles/iframes/forms are removed with their content, other tags are
  unwrapped, `href`/`src` must be http(s) (links also mailto/tel) or relative.
- Validation errors → `422 validation_failed` with an Indonesian message.
