# Modules cut over to `core` by an offline import behind a maintenance flag

> **Amended by [ADR-0005](0005-production-on-api-subdomain-for-new-apps-only.md) (2026-09-27):** the old URLs are not routed to Laravel, so step 1 no longer applies. A Modul is cut over only after its old screens are retired; the import steps 3–6 stay as described.

Production is cPanel shared hosting (Rumahweb), with no confirmed SSH, and Claude never writes to production or dev (CLAUDE.md §0). So live data reaches `core` by an **offline import**, per module:

1. The module's old URLs already route to Laravel, still on its legacy database.
2. A per-module maintenance flag in Laravel refuses writes with an error the old frontends already show. Reads keep working.
3. The owner exports the legacy database (phpMyAdmin or cPanel backup).
4. The artisan import runs **locally** against that dump, and parity must be green.
5. The owner imports the resulting `core` tables through phpMyAdmin.
6. The module's connection is switched to `core` and the flag is lifted.

Each production cutover is rehearsed first on the dev twin (`lakk5493_laksamana_dev_core`, fed from the dev dumps). Rollback, meaning pointing the module back at its frozen legacy database, is allowed only before the flag is lifted. After that, fixes go forward in `core`.

## Considered Options

- **A guarded on-server import endpoint**: rejected. It runs migration code against the live database with no local rehearsal of that exact run.
- **`php artisan` over SSH / cPanel Terminal**: not available as far as we know. Revisit when laksamana-api's own deployment is planned.
- **A reverse export (`core` → legacy) for rollback after writes**: rejected. It doubles the work per module for a case the parity gate exists to prevent.
