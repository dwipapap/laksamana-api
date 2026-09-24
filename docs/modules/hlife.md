# hlife — Howandi Life OS (personal dashboard of the owner)

| | |
|---|---|
| Legacy source | `laksamana-office/howandi-life-mysql/` (uploaded by hand) |
| Legacy URL | `/howandi-life-api-mysql/api.php` |
| Database | `lakk5493_db_hlife` |
| Envelope | `jawab()` with real HTTP status codes |

The legacy API expects `API_TOKEN='HL-5mHh8Lfu8bpiPMkgtRphSmvM'` on every data action (`?token=` or `body.token`). This token is embedded in the frontend HTML, so it is not a secret. On the compat route, check it inside the controller (module-specific), not through the global `LEGACY_API_TOKEN`.

## Tables

- `settings` (key/value) holds these keys:
  - `firstRun`, `mood`, `energy`, `focus`, `weeklyTarget`
  - `auth {enabled, hash}` — SHA-256 hash of the lock-screen password
  - `channels[]`, `dump[]` — order matters
- Collections, each stored as `id` + `data`:
  - businesses, projects, tasks, goals, dreams, roadmap, content, learning, habits, events, assets, reviews
- `ledger` — returned as `finance: {ledger: []}`

## Actions

- `ping`, `stats`
- `getAll` — the full state, with missing settings filled from defaults
- `saveAll {token, data}` → `{ok, data: {saved: true}}`

`saveAll` has NO conflict protection: the last save wins. Legacy behaviour to keep:

- It runs in one transaction.
- Each collection is upserted, then rows not in the list are deleted (`DELETE NOT IN`).
- Only `tasks` must be present as an array.
- **Any other collection missing from the payload is treated as `[]`, which empties that table.** Keep this on the compat route. v1 must not do it.

## v1 proposal (superadmin or module `howandi_life` only)

- `/api/v1/hlife/{collection}` — CRUD per collection
- `/api/v1/hlife/settings`
