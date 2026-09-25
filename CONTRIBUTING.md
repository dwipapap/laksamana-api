# Contributing to laksamana-api

## Test data: anonymised set (no production dumps on your machine)

Pest and parity need real-shaped databases, but the owner's dumps contain
PINs, phone numbers, names and HR data. Contributors work with an
anonymised set instead, generated from those dumps by the owner:

```bash
node tools/anonymise.mjs --src ../db-backup --out ../db-backup-anon
DUMP_DIR=../db-backup-anon tools/restore-dumps.sh      # local MySQL only, like the prod restore
```

Ask the owner for the generated `db-backup-anon` directory (shared
privately, never committed), restore it with the second command, then work
normally: `php artisan test`, `node tools/parity/parity.mjs <module>`.

Rules:

- The anonymised set is **never committed** — not the dumps, not restored
  copies, nothing derived from them. `tools/anonymise.mjs` refuses an
  output dir inside the repo.
- `tools/restore-dumps.sh` drops and recreates local databases. It refuses
  non-local hosts, and it must never point at production or dev (CLAUDE.md
  §0). After verifying against one set, re-restore the other before
  continuing, so the machine is left as found.
- The anonymiser preserves what tests and access rules branch on (ids and
  relations, name uniqueness, PIN length/charset, phone uniqueness and the
  `08…` shape, division/office/HRD Tim words) and fakes the rest. If you
  port a module whose code branches on a **new** word or column, extend
  `tools/anonymise.mjs` (the `SEMANTIC` set and the column rules) and
  regenerate — and keep the full suite green on the new set.
- Free-text notes (`catatan`, `alasan`, brief text) are left as staff wrote
  them; treat the set as test data, not as publishable content.
