# v2 tables: real references, typed money and time, one `version`

Every v2 table in `core` (ADR-0006) follows these rules. They extend ADR-0003, whose naming and key rules still apply (glossary names from `CONTEXT.md`, singular, ULID `id`, unique `legacy_id` where a legacy row exists, technical columns in English). An area ERD (`docs/erp/<area>.md`) may break a rule only by naming the rule and the reason.

**Names.** v2 tables are one domain model, so they carry no Backend prefix (`pihak`, `barang`, `pesanan_pembelian`), and cannot clash with the frozen `<modul>_*` tables. A child table that only exists inside its parent takes the parent's name as prefix (`pesanan_pembelian_baris`).

**References.** Every reference is a foreign key. A column ending in `_id` without a constraint, or a reference by name (`pic`, `vendor`, `item`, `oleh`), is not allowed. The default delete rule is `RESTRICT`. `CASCADE` is used only from a document to its own lines; `SET NULL` only for an optional reference whose loss changes no total.

**Documents.** A document has a unique `nomor`, a `status` column limited by a `CHECK` to the states in the area's lifecycle, and the time of each transition it needs (`diajukan_at`, `disetujui_at`, …). Once a document reaches a final state it is never edited or hard-deleted: it is corrected by a reversing document. The services enforce this; the delete rules make an accidental hard delete fail.

**Money and quantities.** Money is `DECIMAL(15,2)` in rupiah; `FLOAT` / `DOUBLE` are never used for money or quantities, and money is never inside JSON. Whether an amount is rounded to whole rupiah, and how PB1 and service are rounded, is a business rule the service applies, not a column type. Quantities are `DECIMAL(15,4)` in the item's base unit; the unit a line was entered in is kept beside it.

**Time.** Instants are `TIMESTAMP`, stored in UTC (the app's timezone) and shown in WIB by clients. Business dates are `DATE`, never text. Operational documents (sales, shifts, stock movements) carry a `tanggal_bisnis` that the server derives from the business-day cut-off; until the owner sets that cut-off it is midnight WIB, and it is never computed by a client.

**Locations.** Every stock and money table references the location it belongs to (outlet, Central Kitchen, gudang), even while there is only one, so a second outlet adds rows, not columns.

**Technical columns.** `created_at`, `updated_at` (`TIMESTAMP`), `created_by`, `updated_by` (FK → `user`, nullable only for imported or system rows), `version` (`INT UNSIGNED NOT NULL DEFAULT 1`, +1 on every write, and the only concurrency check: clients send it back, a mismatch is a `409`), and `deleted_at` for master data only. Timestamps are never used as versions.

**JSON.** Only for open-ended data that is never filtered, summed or joined (free-form settings, audit detail, attachments metadata), as a `JSON` column. Each one is listed and justified in the area ERD.

**History.** Values that past documents depend on (prices, recipes, base pay, unit conversions) are effective-dated (`berlaku_dari` / `berlaku_sampai`) or copied onto the document line, never overwritten in place.

## Considered Options

- **Integer rupiah** instead of `DECIMAL(15,2)`: rejected for now. Splits (PIC portions, per-portion cost) produce cents; `DECIMAL` holds whole rupiah too, so the owner's answer on precision changes a rounding rule, not the schema.
- **Keep `bigint` millisecond timestamps as the version** (as v1 does): rejected. It ties concurrency to clock precision and makes time unreadable in SQL.
- **`ENUM` for status**: rejected. Adding a state is an `ALTER` of the column type on the cPanel MySQL; a `VARCHAR` with a `CHECK` is easier to extend.
