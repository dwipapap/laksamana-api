# `core` tables use Indonesian glossary names and ULID primary keys

Tables and columns in `core` are named with the terms in `CONTEXT.md` (e.g. `divisi`, `izin_akses`, `pekerja_harian`), so the schema, the screens, the error codes and the glossary share one vocabulary. Laravel's framework tables (`personal_access_tokens`, `cache`, `jobs`…) keep their English names. So do the technical columns every table carries: `created_at`, `updated_at`, `created_by` and `updated_by` (FK → `user`), `version` (INT, the single optimistic-concurrency scheme that replaces the legacy `_ver` / `baseUpdatedAt` / `_rev` variants), and `deleted_at`, used only where the legacy rules forbid a hard DELETE. Shared tables have no prefix (`user`, `modul`, `divisi`). Tables owned by one module are prefixed with its key (`finance_brankas`, `dw_ajuan`). Names are singular. Primary keys are ULIDs: they sort by time, index well, and can still be generated client-side, as the current frontends do for optimistic and offline saves. Wherever a legacy id exists, the row keeps it in a unique `legacy_id` column, so compat routes and parity can map old ids 1:1.

## Considered Options

- **English names** (Laravel convention) with a glossary mapping: rejected. It creates a second vocabulary that everyone has to translate in their head.
- **BIGINT auto-increment keys**: rejected. They can't be generated on the client.
- **Legacy ids as keys**: rejected. Their formats are mixed and client-made.
