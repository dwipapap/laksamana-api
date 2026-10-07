# Automation API v1 — read-only n8n feeds

`/api/v1/automation/*` are the **machine feeds** the scheduled n8n workflows read
("Laksamana Rekap Reservasi 17 WIB", "Laksamana Info Pagi 07 WIB"). They are
read-only by construction (every statement is a `SELECT`), carry **no phone
numbers and no photo blobs**, and never write. n8n only formats the message;
multi-day logic, DP-vs-`dps[]`, cancellation and VIP rules stay on the server.

- **Envelope:** success `{data, meta}`, failure `{error: {code, message, details?}}`
  (`App\Support\Api\ApiResponse`).
- **Read-only connection:** every feed reads through
  `App\Modules\Automation\Support\ReadDb::for(<module>)` — one connection
  (`automation_ro`) whose database is swapped per module.

## Auth

| | |
|---|---|
| Header | `Authorization: Bearer <token>` |
| Ability | the token's `abilities` must list exactly `automation:read` |
| Rejected | a staff login token (`abilities: ["*"]`) → **403** `forbidden`; no token → **401** |
| Expiry | none — automation tokens do not expire (unlike login tokens: `SANCTUM_TOKEN_DAYS`) |
| Rate limit | 30 requests/minute per token/IP → **429** `too_many_requests` |

Create the token once, on the server whose API n8n calls:

```bash
php artisan automation:token n8n --user="<name or username>"
```

The command prints the plain token **once** (only its hash is stored). Choose a
**technical account with no Modul grants**: the ability gates `/automation/*`,
while every other Office endpoint still checks the owner's modules — a token on
an account that holds `reservasi` would still pass those gates. The command
warns when the account has modules.

## Where the feeds read

`AUTOMATION_DB_HOST` picks the database; the caller never does (there is no
`?source=`). The database name per module comes from `config/laksamana.php`.

| server | `AUTOMATION_DB_*` points to | effect |
|---|---|---|
| local / tests | *unset* | falls back to the normal module connections (restored local DBs) |
| `dev-api` (now) | PROD DBs, user `recap_ro` | feeds read PROD while the app itself runs on dev |
| `api.laksamanamuda.id` (later) | *unset* | the app already runs on PROD; the module connections are PROD |

When `api.laksamanamuda.id` goes live, point n8n at it and **empty**
`AUTOMATION_DB_*` on `dev-api` (it returns to the dev DB).

## Feeds

### `GET /api/v1/automation/reservasi-harian`

| query | | |
|---|---|---|
| `date` | optional, `YYYY-MM-DD` | base day (default: today, Asia/Jakarta) |
| `days` | optional, integer `0..7` | also return `date+1 … date+days`; invalid → **422** |

`data` is always an **array of day objects**, one per requested day, in order:

```json
{
  "data": [
    {
      "date": "2026-10-07",
      "rows": [
        {
          "id": "rs-123",
          "name": "Wina Polresta",
          "time": "15:00",
          "tables": "VIP 1, VIP 2",
          "pax": 25,
          "status": "Confirmed",
          "dpStatus": "Sudah",
          "dpAmount": 500000,
          "dps": [{ "amount": 500000, "method": "Transfer" }]
        }
      ],
      "total": {
        "reservasi": 1,
        "pax": 25,
        "batal": 0,
        "dpMasuk": 500000,
        "perStatus": { "Confirmed": 1 }
      }
    }
  ],
  "meta": {
    "feed": "reservasi-harian",
    "date": "2026-10-07",
    "generatedAt": "2026-10-07T17:00:00+07:00",
    "errors": {}
  }
}
```

- `rows` skips `status: "Cancelled"`; `total.batal` counts them.
- `dpAmount` = sum of `dps[].amount`, falling back to the legacy
  `dpStatus: "Sudah"` + `dpAmount` single-DP shape. `dps[]` keeps only
  `amount` + `method` (no proof blobs).
- `tables` joins the table list (`table` may be a comma string or an array);
  empty → `"-"`.
- `meta.errors` is keyed by date when a day fails (see below).

### `GET /api/v1/automation/info-pagi`

| query | | |
|---|---|---|
| `date` | optional, `YYYY-MM-DD` | default: today, Asia/Jakarta |

`data` combines the Event (EMS) schedule with the Marketing deals + VIP:

```json
{
  "data": {
    "event": [
      {
        "id": "ev-1",
        "nama": "Live Music",
        "status": "Upcoming",
        "venue": "Hall A",
        "picName": "PIC Event",
        "mulai": "2031-08-01 07:00"
      }
    ],
    "marketing": {
      "events": [
        {
          "id": "mk-1",
          "nama": "Wedding Budi",
          "status": "Deal",
          "pax": 50,
          "picName": "PIC Mkt",
          "menuFix": "",
          "selesai": "2031-08-03",
          "hari": 1,
          "totalHari": 3
        }
      ],
      "vip": [
        {
          "nama": "Tamu VIP",
          "perusahaan": "PT Contoh",
          "jam": "19:00 - 21:00",
          "pax": 8,
          "meja": ["VIP 1"],
          "nominal": 0,
          "picName": "PIC Mkt",
          "menuFix": ""
        }
      ]
    }
  },
  "meta": {
    "feed": "info-pagi",
    "date": "2031-08-01",
    "generatedAt": "2026-10-07T07:00:00+07:00",
    "errors": {}
  }
}
```

- `event` excludes `Planning` / `Draft` / `Cancelled`.
- `marketing.events` are `Deal` / `Event Done` rows touching the day
  (`tanggal` … `data.tanggalSelesai`, multi-day aware via `hari`/`totalHari`).
- `marketing.vip` are Assisted, not-cancelled VIP rows of the day.

## `meta.errors` — degenerate answers stay 200

A failed section is **not** a 500. The feed answers 200 with the section empty
and `meta.errors` filled, so n8n can tell "no events" from "source failed":

```json
{
  "data": { "event": [], "marketing": { "events": [], "vip": [] } },
  "meta": {
    "feed": "info-pagi",
    "date": "2031-08-01",
    "generatedAt": "2026-10-07T07:00:00+07:00",
    "errors": { "marketing": "SQLSTATE[42S02]: Base table or view not found …" }
  }
}
```

`reservasi-harian` keys the errors by the failing day's date. `errors` is always
an object (`{}` when everything is fine).

## GRANT SELECT per table

`automation_ro` must be a SELECT-only user (the existing `recap_ro` is reused).
Grant **per table**, never `db.*`; a new feed adds its grants deliberately and
lists them here:

```sql
GRANT SELECT ON lakk5493_db_reservasi.reservations TO 'recap_ro'@'%';
GRANT SELECT ON lakk5493_db_ems.events               TO 'recap_ro'@'%';
GRANT SELECT ON lakk5493_db_marketing.events         TO 'recap_ro'@'%';
GRANT SELECT ON lakk5493_db_marketing.settings       TO 'recap_ro'@'%';
```

The database names are the `database` values in `config/laksamana.php`; on dev
they are the `DB_<ENV>_DATABASE` overrides, so grant on those names when the
pin is used there. No `GRANT` on any other table is needed or wanted.

## Versioning

- Fields may be **added** at any time — consumers must ignore unknown fields.
- Fields are never **renamed** or **removed** inside a feed; `data`/`meta`
  shapes documented above are stable.
- A breaking change ships as a **new feed** (`reservasi-harian-v2`), and the
  old feed stays until n8n has moved.

## Differences from the endpoints they replace

Old (temporary, kept while n8n migrates):

- `GET /api/v1/reservasi/recap?days=&source=prod`
- `GET /api/v1/info/pagi?date=&source=prod`

New:

- **No `?source=`** — `AUTOMATION_DB_*` (server side) decides the database.
- **Unified meta** — `{feed, date, generatedAt, errors}` for every feed; the
  old `meta.total` lives in each day's `data[].total` and `data` is always the
  list of days (even for one day).
- **No `table` alias** — rows carry `tables` only. (The old recap also sent
  `table` for one n8n Code node; the new workflows read `tables`.)
- **`meta.errors` always present** — the old `info/pagi` dropped the section
  errors the service had isolated.
- Credentials for the prod pin come from the single `AUTOMATION_DB_*` set, not
  from `RESERVASI_RECAP_PROD_*` / `EVENT_INFO_PROD_*` / `MARKETING_INFO_PROD_*`.
