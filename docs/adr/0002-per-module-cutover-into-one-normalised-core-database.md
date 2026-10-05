# Consolidate every Backend into one normalised `core` database, one module at a time

> Amended by [ADR-0006](0006-erp-v2-redesigns-core-per-area.md): `core` is redesigned per business area for the ERP; the per-Backend tables built from this ADR are frozen.

The ~16 legacy databases (`lakk5493_db_<mod>`) become one database, `core`, owned by Laravel migrations. For now the API is hybrid: unmigrated modules still read their legacy database. When a module is migrated, `core` becomes its **only** source of truth. Its compat routes then read and write the new tables and rebuild the legacy wire shapes, and its legacy database is frozen as a read-only archive. Nothing is ever written to both. `core` is not a copy of the legacy shape: fields the domain knows become typed columns, repeated parts become child tables with real foreign keys, and JSON stays only for open-ended data. Each migrated module ships an ERD.

## Considered Options

- **Dual write** to legacy and `core` during a transition: rejected. It doubles every write path and reintroduces the drift the project exists to remove.
- **`core` as a projection** synced from the legacy databases: rejected. The legacy databases would stay the truth, so nothing is actually consolidated.
- **Keep the JSON `data` blobs** and add keys around them: rejected. The blobs are what make FKs and a meaningful ERD impossible.

## Consequences

- Compat routes for a migrated module become translators, not pass-throughs. The parity suite (`tools/parity`) must be green against `core` before the module's cutover.
- A cross-module read that goes into another module's tables directly has to move behind that module's service before the target module can cut over.
