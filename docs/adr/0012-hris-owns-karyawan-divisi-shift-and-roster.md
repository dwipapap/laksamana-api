# ADR-0012: HRIS owns Karyawan, Divisi, Jabatan, master shift and the roster

Date: 2026-10-20. Status: accepted (owner grilling, Q1–Q42). Amends ADR-0009
(where HR master data lives) and decisions **A17** and **L1** of
`docs/modules/hris.md`.

## Context

Employee data is split over three places today: the HR columns on the legacy
Office `users` row (`organization`, `job_position`, `job_level`,
`employment_status`, `join_date`), the legacy HR panel's `employees` blob
(53 rows, almost empty), and Tim words that decide a crew member's Divisi by
keyword. Divisi exist only as four hard-coded shift codes in Jadwal (bar,
kitchen, floor, cashier) plus eight free-standing HR divisions. Shifts are
defined in the Jadwal setting blob; the roster is planned in the old Jadwal
panel (office-vue and laksamana-office).

The owner wants one tidy place to manage employees, Divisi, the org chart,
shifts and the roster, inside HRIS. Two facts shape where the data can live:

1. **Identity and Jadwal still run on their legacy databases in production**
   (`docs/deploy.md`: every Modul stays on legacy; ADR-0009: identity is not
   on core). The core `user`, `karyawan`, `divisi` and `jadwal_*` tables are
   import copies, not the live truth, and every old Office panel logs in
   through the legacy `users` table. Moving identity first is not possible
   before Talenta ends (March 2027).
2. The core `jadwal_*` tables are frozen (ADR-0006), and the v2 names
   (`karyawan`, `divisi`, `shift`) are taken by Team B's empty v2 tables.

## Decision

**HRIS owns the new HR master data in `hris_*` tables, keyed like every other
HRIS table** (legacy User id `user_id` + nullable `user_ulid`, ADR-0009):

| Table | Holds |
|---|---|
| `hris_divisi` | Every Divisi: `kode` (generated, hidden, immutable), `nama`, `jenis` shift/kantor, `urutan`, `aktif`, soft delete. Shift Divisi codes equal the Jadwal codes (`bar`, `kitchen`, `floor`, `cashier`, `cleaning`) |
| `hris_divisi_kepala` | Kepala Divisi of a **kantor** Divisi. Heads of shift Divisi stay in Jadwal (`heads`), which Jadwal, Akses Bawaan and DW already read |
| `hris_jabatan_level` | Configurable levels (seeded 1 Helper/Trainee … 6 Manajemen) |
| `hris_jabatan` | Master Jabatan: `nama`, level, optional Divisi |
| `hris_karyawan` | The HR record of a User (1:1): Divisi, Jabatan, atasan, status kerja, identity (NIK, gender, birth date, address, emergency contact), join/probation/contract dates, exit date and reason |
| `hris_karyawan_riwayat` | Every change of Divisi, Jabatan, atasan and status kerja, with its effective date |

- **Login stays in the legacy Office account.** Adding an employee creates the
  User through `AccountService` (so every old panel knows them) and the
  `hris_karyawan` row in HRIS. Name, PIN, phone and email stay on the User.
  HRIS mirrors Jabatan name, status kerja and join date back into the User's
  HR columns, so old screens keep showing them.
- **Divisi is explicit.** A Karyawan's `divisi_id` decides their Jadwal Divisi:
  HRIS writes Penempatan Divisi (`divOverride`) for a shift Divisi and
  Nonshift for a kantor Divisi. Tim words remain a fallback for Users without
  a Karyawan record.
- **Shifts and the roster stay in Jadwal's storage, edited from HRIS.** HRIS
  reads and writes them through `JadwalService` only. A shift may have
  **blocks** (SPLIT: two blocks, the crew member leaves in between) and a list
  of Divisi it is for; both are attributes of the shift definition, so no
  Jadwal table is added. Cleaning Staff is a new shift Divisi, and PAGI CS /
  SIANG CS are its shifts.
- **A17 is reopened for block shifts:** a SPLIT day has two MASUK/PULANG
  pairs (`hris_ketukan.blok`). Lateness is per block, the duration target is
  the sum of the blocks, the meal allowance counts the day once, a missed
  PULANG of block 1 is flagged `pulang_terlewat`, and `TANPA_MASUK` is per block.
- **L1 is replaced:** roster planning moves to HRIS (`/kelola/roster`), with a
  two-week parallel run before the old Jadwal panel becomes read-only and is
  retired. The legacy HR panel is retired too (its data is imported).
- **Users without a Karyawan record get no HRIS access**, which narrows the
  Akses Bawaan of PR #300. The gate switches on only after HR has reviewed the
  import report, so nobody loses access by an import gap.

## Considered Options

- **Use the core `user`/`karyawan`/`divisi` tables now.** Rejected: they are
  not live in production, so a new employee would never reach the old Office.
- **Cut identity and Jadwal over to core first.** Rejected for now: every old
  panel logs in through the legacy account, and the cutover cannot finish
  before March 2027.
- **A `jadwal_shift_blok` table.** Rejected: the core `jadwal_*` tables are
  frozen and Jadwal is legacy in production; blocks are a shift attribute.
- **Jabatan as free text (`docs/erp/sdm.md`).** Overruled by the owner (Q6):
  filters, the org chart, manpower requests and meal-allowance grades need a
  master list.

## Consequences

- When identity moves to core, `hris_divisi`/`hris_karyawan`/`hris_jabatan*`
  become the source for the v2 `divisi`/`karyawan` tables (and a v2 `jabatan`),
  the same path ADR-0009 sets for the other `hris_*` tables.
- Two Divisi lists exist until then: `hris_divisi` (truth for HRIS) and the
  Jadwal codes. They stay aligned because shift Divisi share the codes and
  `kode` never changes.
- The importer `hris:impor-karyawan` is idempotent and never overwrites a row
  HR has edited; what it cannot place is reported for HR.
