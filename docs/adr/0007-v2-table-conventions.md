# v2 tables: real references, typed money and time, one `version`

Every v2 table in `core` (ADR-0006) follows these rules. They extend ADR-0003, whose naming and key rules still apply (glossary names from `CONTEXT.md`, singular, ULID `id`, unique `legacy_id` where a legacy row exists, technical columns in English). An area ERD (`docs/erp/<area>.md`) may break a rule only by naming the rule and the reason.

**Names.** v2 tables are one domain model, so they carry no Backend prefix (`pihak`, `barang`, `pesanan_pembelian`), and cannot clash with the frozen `<modul>_*` tables. A child table that only exists inside its parent takes the parent's name as prefix (`pesanan_pembelian_baris`).

**References.** Every reference is a foreign key. A column ending in `_id` without a constraint, or a reference by name (`pic`, `vendor`, `item`, `oleh`), is not allowed. The default delete rule is `RESTRICT`. `CASCADE` is used only from a document to its own lines; `SET NULL` only for an optional reference whose loss changes no total.

**Documents.** A document has a unique `nomor`, a `status` column limited by a `CHECK` to the states in the area's lifecycle, and the time of each transition it needs (`diajukan_at`, `disetujui_at`, …). Once a document reaches a final state it is never edited or hard-deleted: it is corrected by a reversing document. The services enforce this; the delete rules make an accidental hard delete fail.

**Money and quantities.** All amounts are whole rupiah (owner, 2026-10-02): totals, prices, payments and fees are `DECIMAL(15,0)`, and a service that divides money (PIC portions, splits) rounds once and puts the remainder on a defined line. Only unit costs per base unit (Rp per gram in HPP) may carry fractions, as `DECIMAL(15,4)`. `FLOAT` / `DOUBLE` are never used for money or quantities, and money is never inside JSON. Quantities are `DECIMAL(15,4)` in the item’s base unit; the unit a line was entered in is kept beside it, and conversion happens once, when the line is written.

**Time.** Instants are `TIMESTAMP`, stored in UTC (the app’s timezone) and shown in WIB by clients. Business dates are `DATE`, never text. Operational documents (sales, cash, shifts, stock movements) carry a `tanggal_bisnis` and a `hari_operasional_id`, derived by the server from the Lokasi’s open Hari Operasional (`docs/erp/hari-operasional.md`), with the Lokasi’s cut-off hour as fallback; a client never computes it.

**Locations.** Every stock and money table references the location it belongs to (outlet, Central Kitchen, gudang), even while there is only one, so a second outlet adds rows, not columns.

**Technical columns.** `created_at`, `updated_at` (`TIMESTAMP`), `created_by`, `updated_by` (FK → `user`, nullable only for imported or system rows), `version` (`INT UNSIGNED NOT NULL DEFAULT 1`, +1 on every write, and the only concurrency check: clients send it back, a mismatch is a `409`), and `deleted_at` for master data only. Timestamps are never used as versions.

**JSON.** Only for open-ended data that is never filtered, summed or joined (free-form settings, audit detail, attachments metadata), as a `JSON` column. Each one is listed and justified in the area ERD.

**History.** Values that past documents depend on (prices, recipes, base pay, unit conversions) are effective-dated (`berlaku_dari` / `berlaku_sampai`) or copied onto the document line, never overwritten in place.

## Considered Options

- **`DECIMAL(15,2)` for every amount**: rejected after the owner confirmed all rupiah are whole; cents would only invite amounts no receipt ever shows. Fractions stay possible where they are real (unit costs).
- **Keep `bigint` millisecond timestamps as the version** (as v1 does): rejected. It ties concurrency to clock precision and makes time unreadable in SQL.
- **`ENUM` for status**: rejected. Adding a state is an `ALTER` of the column type on the cPanel MySQL; a `VARCHAR` with a `CHECK` is easier to extend.
