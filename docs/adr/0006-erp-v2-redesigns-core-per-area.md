# `core` is redesigned for an ERP, one business area at a time, behind `/api/v2`

Amends ADR-0002. laksamana-api becomes the company's ERP: one modular monolith, one `core` database, many clients (Office, laksamana-office-vue, the public site, the ticket shop, later apps). The `core` tables built on 26–27 September 2026 did not keep ADR-0002's promise. They mirror each legacy Backend (130 of 172 tables still hold their truth in a `data` blob, 31 of 336 foreign keys are business links; see `docs/db/audit-core.md`). We stop building on them.

- **v1 is the migration API.** `/api/v1` and the compat routes keep serving each Modul from its legacy database (ADR-0005) in the legacy shape, and are frozen per Modul once its parity is done. Team A owns them.
- **v2 is the ERP API.** `/api/v2` is shaped by the business, not by the old screens. It is designed per **area** (Pembelian & Persediaan first, then Penjualan, Kas, SDM), each area going through the design steps in `docs/erp/README.md` (business activities → terms → master data → cardinalities → document lifecycles → conventions → invariants → JSON → history → ERD + legacy mapping) before any migration is written. Team B owns it.
- **Same `core` database, new tables.** v2 tables live in `core` next to the identity tables (`user`, `modul`, `izin_akses`, `larangan`, `admin_modul`, `divisi`, `divisi_kata`, `kepala_divisi`, `penempatan_divisi`) and the greenfield `menu` / `news` / `homepage` tables, which are kept as they are. They follow ADR-0007.
- **The per-Backend `core` tables are frozen.** No new table, column or importer is added to them, and no Modul cuts over to them (the dev rehearsal / production cutover tickets for jadwal, marketing, dw, absensi, konten, akademi, bd, event+ticketing, hr, hlife, finance, kompas, reservasi and stock are on hold; identity's is not). Their migrations and tests stay until the v2 design of the area that replaces them is merged; a later migration then drops them. Nothing is lost: outside identity, `core` holds no Modul data, and v2 imports read the legacy databases.
- **A Modul moves onto v2 only after its old screens are retired** (as ADR-0005 already requires for `core`), via ADR-0004's offline import, now mapping legacy rows to the area's v2 tables.
- **Code layout.** v2 lives under `app/Erp/<Area>/` (`Services/`, `Models/`, `Http/V2/`, `routes/v2.php`); master data shared by every area lives in `app/Erp/Master/`. `app/Modules/*` stays v1 and never reads v2 tables directly; when a v1 Modul moves, its compat/v1 routes call the v2 services.

## Considered Options

- **Repair the current `core` tables in place** (add FKs and columns around the blobs): rejected. The tables are shaped like the old screens. Their keys, splits and names would carry the legacy model into the ERP, and with no Modul data in them there is nothing to save by keeping them.
- **A new database for the ERP**: rejected. Identity and the greenfield modules are already in `core` and are already relational; a second database brings back cross-database joins without a benefit.
- **Design the whole ERP before building anything**: rejected. One area at a time gives a working pattern (Pembelian touches parties, items and units, document status and money) that the next areas copy.
- **Microservices**: rejected. Shared cPanel hosting without a shell, a small team, and one MySQL server.

## Consequences

- Team A's v1 work is unaffected: same databases, same contracts. A v1 field rename still needs Team A's approval; v2 endpoints are announced in `docs/api/v2/<area>.md` before they are built.
- The PRD `docs/prd/db-consolidation.md` is superseded where it says each Modul is consolidated in its legacy shape; its goals (one database, typed columns, real FKs, an ERD per area) stand.
- Open owner decisions that shape v2 are tracked in `docs/erp/pertanyaan-owner.md`: who the parties are, one Divisi list or several, one item catalogue or two, one purchasing flow or two (Stock vs BD), money precision, the business-day cut-off, outlets, and whether the general ledger is built here or sent to an accounting package. Each answer becomes a `CONTEXT.md` term or an ADR.
- Money and score rules that the v1 contracts leave to the client ("Money is computed client-side") move to v2 services as each area is designed, not before.
