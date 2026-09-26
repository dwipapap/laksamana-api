# `core` database

The `core` database consolidates the legacy Backends one Modul at a time. It is
owned by Laravel migrations; a legacy database is read as an import source and,
after its Modul is cut over, is frozen as an archive. No Backend is ever
dual-written (ADR-0002).

## ERD convention

Every cut-over Modul adds `docs/db/<module>.md`. That file contains:

1. a Mermaid `erDiagram` showing every table, relationship, and cardinality;
2. the legacy database/table/field to `core` table/field mapping;
3. which legacy ids become `legacy_id`, including composite ids;
4. delete behaviour (`deleted_at` or hard delete) and concurrency mapping to
   the single `version` column;
5. the import command and the module-specific switch used during cutover.

The Mermaid diagram uses the same canonical terms as `CONTEXT.md`. Technical
columns are omitted from a diagram only when a note lists them once. The
naming and key rules are fixed by ADR-0003:

- shared tables are singular and unprefixed, such as `user`, `modul`, and
  `divisi`;
- tables owned by one Modul are prefixed with its key, such as
  `finance_brankas` and `dw_ajuan`;
- the primary key is a ULID `id`;
- a unique `legacy_id` is present wherever the source has a stable legacy id;
- `version` is an integer concurrency value shared by compat and v1.

## Technical columns

Unless a module ERD explicitly documents an exception, each business table
carries:

| Column | Meaning |
|---|---|
| `created_at`, `updated_at` | Laravel timestamps |
| `created_by`, `updated_by` | ULID references to the `user` who caused the write |
| `version` | Integer optimistic-concurrency value; new rows start at `1` |
| `deleted_at` | Present only when the legacy rules forbid a hard DELETE |

`created_by` and `updated_by` become real foreign keys to `user` when Identity
is cut over. Until then, importers may leave them `NULL` rather than inventing a
User mapping. Laravel's English framework tables keep their framework names.

`App\Core\Models\CoreRecord` supplies the ULID key, the `core` connection, and
the shared timestamp/version casts. A concrete model opts into `SoftDeletes`
only when its table has `deleted_at`; every `legacy_id` column has a database
unique index. The C1 `core_import_probe` table and `dummy` importer exercise
these conventions and must not become a business Modul.

## Import and cut-over switches

Each cut-over ticket registers an idempotent `App\Core\Imports\Importer` and runs:

```bash
php artisan core:import <module-key>
```

Importers read a named legacy connection and write only to `core`. The command
refuses to run unless both database hosts are local. Repeating an import must
update changed rows without creating a second row for the same `legacy_id`.

For the tests, add the Modul to `importCoreModules()` in `tests/TestCase.php`
(behind its `onCore()` switch, after any Modul its FKs point at). It runs once per
test process, committed before the first test's transaction; never import from
`afterRefreshingDatabase()`, which runs inside every test's transaction (#144).

## Parity against `core`

Before a Modul cuts over, its parity cases must be green with Laravel reading
`core` (ADR-0002). `tools/parity` has a core mode for that:

```bash
node tools/parity/parity.mjs <module> --core                    # every cut-over Modul on core
node tools/parity/parity.mjs account --core --importer dummy    # C1 pipeline proof only
```

On top of the usual run (clone the local legacy DBs twice, serve the old PHP
on one set and Laravel on the other), `--core`:

1. creates a scratch database `parity_core` (`parity_<PARITY_TAG>_core` when
   `PARITY_TAG` is set) and runs `php artisan migrate --database=core` on it;
2. runs `php artisan core:import <key>` for every registered importer
   (`core:import --list`), or only for the keys given as `--importer a,b`,
   with every legacy connection pointed at the `parity_new_*` clones, so the
   import reads the same rows the old PHP serves;
3. serves Laravel with `DB_DATABASE=parity_core` and `DB_<KEY>_CONNECTION=core`
   for every imported key, so each cut-over Modul answers from `core` while the
   old PHP still answers from its legacy clone. `jadwal --core` therefore runs
   jadwal with identity (account) on core;
4. diffs every case as today and drops `parity_core` afterwards (kept with
   `--keep`).

A key that is not a Modul (such as `dummy`) switches nothing. Everything runs
on local MySQL only.

## Switches

A Modul opts into cut-over only when its own work is ready. Its two independent
settings are:

```dotenv
# DB_<MODULE>_CONNECTION=core freezes that Modul on its selected connection.
# <MODULE>_MAINTENANCE=true refuses compat and v1 writes while reads continue.
```

The connection is per Modul, not per database. For example, `event` and
`ticketing` both default to `legacy_ems`, but either one can be moved to `core`
without moving the other. Defaults keep every Modul on its current connection
and writable; existing behaviour does not change merely because this scaffold
exists.

After changing either environment variable, rebuild Laravel's config cache
when one is in use (`php artisan config:clear` followed by the deployment's
normal `config:cache` step) and reload the application. A cached configuration
otherwise keeps the previous switch values.

The production sequence is defined by ADR-0004 and remains owner-operated: turn
on one Modul's maintenance flag, export locally, import and run parity, upload
the result through phpMyAdmin, switch that Modul to `core`, then lift the flag.
Claude performs only the local rehearsal and never connects to production or
dev data.
