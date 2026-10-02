# Laksamana supports ESB; it does not replace the POS or the ledger

The company runs **ESB** (Esensi Solusi Buana) and keeps running it (owner, 2026-10-02). ESB is the system of record for point-of-sale data (bills, payments, menu sales) and for accounting. laksamana-api's ERP (ADR-0006) is the operational system around ESB, not a second POS or a second general ledger. It owns what ESB does not: purchasing of raw materials and project/event POs, Central Kitchen and outlet stock movements, HPP and recipes, schedules, attendance and daily workers, HR, marketing, events, tickets, reservations, and the daily cash reconciliation against what ESB reports.

- **No general ledger, journals, tax or period closing are built here.** Money in v2 documents is operational (what was ordered, received, paid out of petty cash, deposited), stored per ADR-0007.
- **ESB data comes in, it is not re-entered.** Today the Office parses ESB exports (Bill Report and Menu Report) in the browser. In v2 those imports become server-side, idempotent imports that keep the ESB identifiers (bill number, menu code) as unique keys, so importing a file twice changes nothing.
- **Screens say "POS", the code says `esb`.** This follows the Office's rename of 12 August 2026. Integration code lives in `app/Erp/Esb/`, so that replacing or upgrading the POS touches one place.
- **Anything ESB needs from us is exported, never written into ESB by hand from two places.** What that is (purchases, receipts, stock counts) depends on which ESB modules are in use, which is open question L1/L2 in `docs/erp/pertanyaan-owner.md`.

## Considered Options

- **Build the general ledger in laksamana-api**: rejected. ESB already does accounting for the company, and a second ledger would have to be reconciled with it forever.
- **Replace ESB's POS with our own**: rejected. Out of scope, and the cashiers' daily tool works.

## Consequences

- Open question L1 can change the scope of the first area. If the company also uses ESB's inventory or purchasing modules, Pembelian & Persediaan in v2 must complement them (requests, CK production, HPP, opname notes) rather than keep a second stock ledger. The area design waits for that answer.
- Business dates for imported ESB bills come from the Hari Operasional window that contains the bill time (`docs/erp/hari-operasional.md`), not from the calendar date ESB prints.
