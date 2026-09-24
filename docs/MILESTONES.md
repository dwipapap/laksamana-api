# Milestones

A **milestone** = a module (or a foundation step) that is finished and verified:

1. its legacy compat route is complete;
2. its `/api/v1` surface is **feature-complete**. v1 is the long-term contract for the old laksamana-office, the future **laksamana-office-vue** (Vue + Nuxt UI), and any other app;
3. parity is `N/N identical`;
4. Pest tests are green;
5. its docs are updated (`docs/modules/<m>.md`, `docs/modules/README.md`, CLAUDE.md §5).

Each finished milestone gets a row here **and** an annotated git tag
(`git tag -a m<N>-<name> -m "<scope> · parity x/x · n tests"`).
List tags with `git tag -n1`.

## Done

| ID | Date | Scope | Commit / tag | Verification | Notes |
|---|---|---|---|---|---|
| M0 | 2026-09-24 | Foundation: one connection per legacy DB + `core`, kernel (Modules, JsonDoc, NamedLock, Legacy/*), Sanctum on Office accounts, OfficeAccess, restore-dumps, parity tool | `bfa4a86` · `m0-foundation` | — | Local MySQL 8.4 with prod dumps restored |
| M1 | 2026-09-24 | **account**: 25 legacy actions, v1 auth + admin | `bfa4a86` · `m1-account` | parity 139/139 · 11 tests | module resolution identical for all 60 users |
| M1.5 | 2026-09-24 | Porting specs for every module (`docs/modules/`) | `419009e` · `m1.5-specs` | — | — |
| M2 | 2026-09-24 | **jadwal**: 12 legacy actions, v1 cells/shifts/requests/settings/heads | `8f81b7f` · `m2-jadwal` | parity 35/35 · 13 tests | legacy rows have no ORDER BY → compared unordered |
| M3 | 2026-09-24 | **marketing** (13 legacy actions) + shared `App\Support\RowSync`; full v1 contract (`docs/api/marketing.md`: 12 record resources, documents, read models, files, timeline) | `m3-marketing` | parity 46/46 · 18 feature + 5 unit tests | v1 versions are valid `baseUpdatedAt` for old tabs (same guards + lock) |

## In progress / next

| ID | Scope | Status |
|---|---|---|
| M4 | Old marketing frontend (`deploy/marketing`) running end to end against Laravel via local devproxy | planned |
| — | dw | legacy source read, no code yet |
| — | v1 completeness back-fill for account & jadwal (audit against their screens) | planned |
