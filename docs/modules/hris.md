# hris — Talenta replacement (greenfield)

**Greenfield on `core` — no legacy PHP, no legacy database, no parity cases.** Why it is a v1 module and not v2: [ADR-0009](../adr/0009-hris-greenfield-module-reading-v1.md).

- **Hard deadline:** the Talenta subscription ends **March 2027**. Attendance, leave/PH and the meal-allowance recap must run in parallel with Talenta for one full pay period before that.
- **Clients** (repo `laksamana-hris`): one PWA, `apps/web` (Vite + React SPA
  at `hris.laksamanamuda.id`), plus `packages/api` (TS types generated from
  `openapi.json`). The Expo crew app and the Nuxt console are deleted; their
  code stays readable at commit `68686fc` as a logic reference only.
  See [ADR-0010](../adr/0010-hris-pwa-replaces-expo-and-nuxt.md).
- **Git:** this repo uses branches `feat/hris-*` with a PR to `main`, because `main` deploys to dev.

Decisions were settled in a grilling session with the owner on 2026-10-15. Items marked **⏳** wait for the HR mentor.

## Ownership

| HRIS owns (`hris_*`) | Read/written through v1 services |
|---|---|
| Karyawan, Divisi, Kepala Divisi of kantor Divisi, Jabatan and levels, their history (ADR-0012) | Identity, login name + PIN, Roster, Akses (`account`) |
| Punches, faces, attendance locations, decision queue, attendance settings | — |
| Holiday calendar, leave/PH balance ledger, all requests (CUTI, PH, IZIN; later OFF, TUKAR) | Jadwal cells and shifts (`JadwalService`): read for punches, written when a request is approved, and edited from `/kelola/shift` and `/kelola/roster` (ADR-0012) |
| Meal-allowance recap and locked periods, People Score, team KPI, discipline (pelanggaran/SP) | Akademi materials and progress (onboarding, training) |
| Contracts ⏳, onboarding checklist, manpower requests, vacancies, applicants/CVs | BD Permintaan Koordinasi (inter-division requests) |
| — | Marketing → Konten design requests (shown read-only) |

## Locked decisions

### Attendance

| # | Decision | Value |
|---|---|---|
| A1 | Source of truth | HRIS punches. Talenta is switched off in March 2027; the legacy `absensi` module and PWA are not used |
| A2 | Face model | On-device in the web PWA: **YuNet** (detection + 5 landmarks, MIT) + **SFace int8** (OpenCV Zoo, Apache-2.0, 128-dim) on `onnxruntime-web` WASM (`numThreads = 1`, no WebGPU, self-hosted wasm cached with the models); model id `sface-2021dec-int8`, cosine metric, default threshold 0.637 with percentiles on `/dev/kalibrasi` (W4, W6). DeepFace (Python, server-side) and InsightFace/ArcFace weights (research-only) were rejected. Every crew member enrols again. Descriptors store their `model` |
| A3 | Enrolment | Self-service at first login, active at once. Photo comes from the live camera only. The new descriptor is compared with **every** enrolled face, and a match with someone else is rejected and reported to HR. Re-enrolment only after an HR reset. HR can see enrolment photos. Biometric consent is shown once |
| A4 | Liveness | **None.** The defences are face match, the duplicate check, GPS and the audit photo. If photo spoofing appears, passive MiniFASNet (Apache-2.0) can be added later without changing the flow |
| A5 | Location | GPS radius per attendance location (haversine, clamped). Legacy `lokasi_absen` may be imported once as a starting point |
| A6 | Rules copied from `AbsensiService` | Server time only; the subject comes from the token; overnight shift (PULANG ≤ 18 h after MASUK belongs to the MASUK date); queue reasons in the A10–A26 order below (`HARI_LIBUR` is the person's own day off — a public holiday never queues, it earns PH); MENUNGGU needs a human decision; DITOLAK counts as no punch |
| A7 | Shifts | Read from Jadwal cells, falling back to each person's default shift (`JadwalService`) |
| A8 | Geofence | **None.** No background geofence, no ENTER/EXIT events, no automatic punch. Location is a per-punch GPS fix only |
| A9 | Login | Existing `POST /api/v1/auth/login {login, pin}`. No device binding, no app biometric lock. The PWA keeps the Bearer token in `localStorage` (`hris_token`) |
| A10 | Platform | PWA only. Mock-location/emulator/root/background-absence detection is impossible; risk accepted. Defences: face, audit photo, server `curiga[]` (A16) |
| A11 | Area shape | Circles only (centre + radius 30–2000 m). No polygons |
| A12 | Location scope | One global list for everyone. No per-division/person/temporary locations |
| A13 | GPS accuracy | `dalam_area` needs `jarak <= radius` **and** `1 <= akurasi <= akurasiMaks` (setting, default 100 m). Missing/zero/too-coarse → `LUAR_AREA`. No `jarak − akurasi` slack |
| A14 | Fix sent | The shown fix (≤ 60 s old) is the sent fix; older → re-acquire and re-show distance + status before sending |
| A15 | 0,0 | The phone skips the 0,0 placeholder like the server |
| A16 | Anti-fraud | Server `curiga[]` per punch, flags only (never a queue reason), HR/head eyes only: `KOORDINAT_SAMA`, `TITIK_PUSAT`, `AKURASI_JANGGAL`, `KOORDINAT_KEMBAR` |
| A17 | Out of scope | Offline punches, break start/end, live tracking. One MASUK/PULANG pair per day, **except block shifts** (SPLIT: one pair per block, J4) — amended by ADR-0012 |
| A18 | Unenrolled | Punch screen locks until Daftar Wajah. Server: never enrolled → always `WAJAH_KOSONG`. Setting `wajahWajib` removed (old PUTs accepted, ignored) |
| A19 | Pre-send face check | `POST /hris/faces/me/cek {descriptor, model}` → `{terdaftar, cocok}`; no score served; every check audits `wajah_dicek` (W5); shares the 10-per-5-min-per-user limit with `faces/me/tambah`. Mismatch warns (+ refoto); after 2 failures "Ajukan ke atasan" (queued `WAJAH` with note). The server still matches on punch (the app can be bypassed) |
| A20 | Early clock-in | VALID up to `awalMenit` before shift start (default 180). Earlier → `LUAR_SHIFT` |
| A21 | Late clock-in | Always VALID inside the shift, stamped `telat_menit` vs `mulai + toleransiTelat`. Only past shift end is `LUAR_SHIFT`. `akhirMenit` no longer gates |
| A22 | Work duration | Clock-in → clock-out (breaks count). Target: Jadwal cell duration (overnight +24 h) → `durasiKerja.orang[userId]` → BAWAAN duration → `durasiKerja.default` (540), from the MASUK snapshot. The target is reported; the clock-out goal is A23 |
| A23 | Clock-out | **Amended 2026-10-24:** with a schedule (roster cell, or BAWAAN of nonshift staff) the goal is the **scheduled end** — arriving early never moves it. MASUK + target only without a schedule or with a per-person duration over BAWAAN (`me/today` shows "Boleh pulang HH:MM"). Short of target → VALID with `pulang_awal` + `kurang_menit` (warn + confirm before sending). Next PULANG overwrites. `pulang_awal` lowers Ketepatan, never the meal day |
| A24 | Too-late clock-out | Past target + `pulangLewatMenit` (default 180) → `LUAR_SHIFT`. PULANG ignores the shift window |
| A25 | Clock-out without clock-in | No non-DITOLAK MASUK ≤ 18 h earlier → queued, new reason `TANPA_MASUK` |
| A26 | Out-of-policy flow | The phone guesses from `me/today` and requires a note when out of policy; on server `422` it asks for the note and resends the same payload (no re-shoot) |

Owner wording and PWA halves of A10–A26: repo `laksamana-hris`, `docs/rencana-absensi-lokasi.md` (issue #292 carries the same table). Queue-reason order is now `LUAR_AREA` → `WAJAH` → `TANPA_MASUK` → (roster unreadable: recorded, not queued) → `TANPA_SHIFT` → `HARI_LIBUR` → `LUAR_SHIFT` → `WAJAH_KOSONG`.

| # | Decision | Value |
|---|---|---|
| W1 | Recognition, not detection | The fringe-vs-combed-hair failure was SFace recognition (score over threshold), not YuNet detection |
| W2 | Three guided enrolment poses | Straight → slightly left → slightly right. The server stores a descriptor set in the existing `descriptor` column (JSON array of arrays; a flat legacy array reads as one descriptor, no migration). The score is the SMALLEST distance over the set. The duplicate check against other people runs per new descriptor. Within one enrolment, two descriptors farther apart than the threshold mean a different person → `422`. Only the straight photo is kept |
| W3 | Complete your own face | Below 3 descriptors the crew member sees a Beranda card and tops up to 3 via `POST /hris/faces/me/tambah`. A new photo is accepted only when its smallest distance to the stored set is within the threshold (same model) and it matches nobody else. REPLACING a face still goes through the HR reset (A3) |
| W4 | Default threshold 0.637 | `wajahAmbang` default (and `sface-2021dec-int8`) is `{metric: cosine, ambang: 0.637}` per the OpenCV SFace reference (similarity ≥ 0.363). Stored values still win; HR may override in Kelola → Pengaturan |
| W5 | Pre-check audit | Every `POST /hris/faces/me/cek` writes `hris_audit` `wajah_dicek` `{skor, cocok, model}` (no photo, no descriptor); 429 answers write nothing. HR sees the last 10 scores per person in Kelola → Wajah, flagged at ≥ 3 failures in 7 days (`gagal_cek_7h`: "needs top-up or reset"). Crew never sees scores |
| W6 | Calibration percentiles | `GET /hris/faces/kalibrasi` (HR) shows per-model `{n, p50, p90, p95, p99}` over 90 days of `wajah_dicek` scores, next to the on-device different-person test; the proposed threshold is the midpoint between same-person p99 (audit) and different-person p1 (calibration session) when both exist. Calibrate 5–10 crew before production |
| W7 | Template learning from VALID punches | POSTPONED until the threshold is calibrated |
| W8 | Accepted risk | 0.637 is looser: another person's face is more likely to pass at punch time, and a false `wajah_milik_orang_lain` at enrolment is more likely. Closed by W6 |

Owner wording and PWA halves of W1–W8 (map P1–P5 and bottom sheet S1–S3 are PWA-only): repo `laksamana-hris`, `docs/rencana-wajah-peta-sheet.md`.

### Leave, PH and requests

| # | Decision | Value |
|---|---|---|
| L1 | Requests | **All requests move to HRIS.** Phase 1 covers CUTI, PH and IZIN (with attachment); OFF and TUKAR follow. Approval goes Kepala Divisi → HRD. On approval HRIS writes the Jadwal cell. ~~The Jadwal roster stays in the old Jadwal panel~~ — replaced by J6 (ADR-0012): the roster moves to HRIS |
| L2 | Balances | One ledger of entries per person (`PH`, `CUTI`): earned, used, expired |
| L3 | PH earned | A **MASUK VALID** on a holiday in the HRIS calendar gives 1 PH |
| L4 | PH expiry | 3 months from the holiday date. Used first-to-expire (FIFO). A reminder goes out 14 days before expiry |
| L5 | PH on resignation | Not paid out |
| L6 | PH claim | Requested in HRIS. Once approved, the Jadwal cell becomes **OFF**, with a note such as "PH 25 Des". The v1 Jadwal contract does not change |
| L7 | Annual leave | 12 days. Only from the **second** contract. It resets on the contract start date. The remainder expires. Probation is 2 months at the start of the first contract |
| L8 | Holiday calendar | Entered by HR, with an automatic import of national holidays where possible |
| L9 | Overtime | **Not counted**, whatever the arrival or leaving time. Crew adjust their shift instead |

### Karyawan, Divisi and Jabatan (E, ADR-0012)

Settled with the owner on 2026-10-20 (grilling Q1–Q42). Stored in `hris_*`
tables keyed by the legacy User id, because identity is still legacy.

| # | Decision | Value |
|---|---|---|
| E1 | Screens | `/kelola/karyawan`, `/kelola/struktur` (org chart), `/kelola/divisi`, `/kelola/jabatan` in the PWA. office-vue `hr/*` is retired |
| E2 | Record | `hris_karyawan`, one per User: Divisi, Jabatan, atasan, status kerja (PROBATION/KONTRAK/TETAP/MAGANG), NIK, gender, birth date, address, emergency contact, join/probation/contract dates, exit date + reason. Name, PIN, phone and email stay on the Office User. `cabang`, `organisasi` and `level_jabatan` are dropped; bank, NPWP and BPJS wait for payroll phase b |
| E3 | Add employee | One form creates the Office User (`AccountService`) and the Karyawan row. Pengelola Roster (Tim HRD) may do it. Module Izin Akses stays in Kelola Akses. HRIS mirrors Jabatan, status kerja and join date into the User's HR columns |
| E4 | Divisi | "Department" is **Divisi**, flat. `kode` is generated and hidden; name, jenis, order, heads and aktif are editable by HR/Superadmin; delete is a soft delete of an empty Divisi, with "move every member to…". Kantor Divisi may have a Kepala Divisi (`hris_divisi_kepala`); shift heads stay in Jadwal |
| E5 | Divisi is explicit | `hris_karyawan.divisi_id` decides the Jadwal Divisi: HRIS writes Penempatan Divisi (shift) or Nonshift (kantor). Tim words are the fallback for Users without a record. Changing Divisi keeps existing roster cells |
| E6 | Jabatan | Master `hris_jabatan` + configurable `hris_jabatan_level` (seeded 1 Helper/Trainee, 2 Crew, 3 Senior, 4 Captain/Supervisor, 5 Head/Kepala, 6 Manajemen) |
| E7 | History | `hris_karyawan_riwayat` records every change of Divisi, Jabatan, atasan and status kerja with its effective date (timeline) |
| E8 | Org chart | Tree of `atasan_user_id`; empty = the Divisi's Kepala Divisi; cycles and inactive superiors are refused. HR/Superadmin see all, a Kepala Divisi their subtree without personal data, crew nothing |
| E9 | Exit | HR sets exit date + reason; `hris:harian` deactivates the User on that date, deletes later roster cells; subordinates must be moved first. Never hard-deleted |
| E10 | Access | Users without a Karyawan record get **no** HRIS access (narrows PR #300), switched on with env `HRIS_HANYA_KARYAWAN=true` after HR reviews the import report |
| E11 | Import | `php artisan hris:impor-karyawan [--coba]`: active Users with HR data (Talenta Organization, Job Position, Employment Status), a legacy HR employee or a Jadwal Divisi. Idempotent, never overwrites; reports Users it could not place and Users without HR data. Legacy HR violations, KPI and reviews move with `hris:impor-hr-lama` (see below) |

### Legacy HR import (E11)

`php artisan hris:impor-hr-lama [--coba]` moves what the retired HR panel
still uses into the HRIS tables (Q22/Q32). `--coba` runs the import and rolls
it back; it never writes to the legacy databases and must never be run
against the shared dev or production database (CLAUDE.md §0: import into a
local copy only).

| Legacy (db `hr`) | HRIS (db `core`) | Mapping |
|---|---|---|
| `violations` | `hris_pelanggaran` | `empId` → Talenta employee → `users.talenta_id`; `date` → `tanggal`; `type` → `jenis`; `severity` Ringan/Sedang/Berat → `tingkat`; `sp` (SP1…) → `sp_ke`; `note` → `keterangan`; `Tercatat`/`Dibatalkan` → `AKTIF`/`DIBATALKAN` |
| `kpi_templates.items[]` | `hris_kpi_template` + `hris_kpi_item` | `divId` → HRIS Divisi kode (same Kantor/Shift mapping as Karyawan); `name` → `nama`; `weight` → `bobot`; `target` → `target`; `dir` up/down → `arah` NAIK/TURUN; `unit` is dropped |
| `kpi_actuals` | `hris_kpi_realisasi` | `item_id` → the imported item; `bulan` → `bulan`; `nilai` → `nilai` |
| `reviews.layers.hr` | `hris_penilaian_atasan` | `empId` → Talenta employee; `month` → `bulan`; `by` → `penilai_id`; `aspects` 1–7 → `aspek` 1–5 |

- Identity is `talenta_id`, the same match the Karyawan importer uses. Rows
  without a matching employee/User are reported, never guessed.
- Idempotent and never overwrites: violations match on (user, tanggal, jenis,
  tingkat, sp_ke), KPI templates on (Divisi, `berlaku_dari`
  `2020-01-01`), items on (template, nama), realisasi on (item, bulan), and
  reviews on (bulan, user). An existing row always wins.
- Legacy KPI has no effective date. Imported templates use `2020-01-01` so the
  historic realisations line up; a newer template written by HR takes over
  from its own date.
- Review aspects are mapped from the legacy 1–7 scale to the HRIS 1–5 scale
  (`1 + (n−1)·4/6`, rounded). A review with a non-numeric value, an empty
  layer or a value outside 1–7 is skipped and listed in the report.
- `career_paths` and empty legacy tables are not imported. The review comment
  has no HRIS column and is not carried over.

### Shift and roster (J, ADR-0012)

| # | Decision | Value |
|---|---|---|
| J1 | Master shift | `/kelola/shift` edits the Jadwal shift definitions (still stored by `JadwalService`): name, colour, blocks, Divisi, order, libur. Codes are generated and hidden; a used shift is deactivated, never deleted; OFF/IZIN/CUTI/LAIN are locked |
| J2 | Cleaning Staff | New shift Divisi `cleaning` (Tim words cleaning/cleaner/kebersihan). PAGI CS (08–18) and SIANG CS (15–01) are for Cleaning only; a shift without Divisi is for every Divisi |
| J3 | MIDDLE | 11:00–21:00 (the stored value; office-vue's 20:00 default is wrong) |
| J4 | Block shifts | SPLIT has two blocks and the crew member leaves in between. Block times are set by HR; until then SPLIT is one block 11:00–23:00. No per-cell time override on a block shift. Punches: `hris_ketukan.blok`, lateness per block, target = sum of blocks, meal allowance once per day, missed PULANG of block 1 → `pulang_terlewat`, `TANPA_MASUK` per block |
| J5 | Past punches | Changing a shift's hours never changes recorded punches (they keep their snapshot) |
| J6 | Roster in HRIS | `/kelola/roster` (monthly grid per Divisi + weekly view), `/kelola/roster/rekap`, and "Jadwal Saya" for crew. HR edits every Divisi, a Kepala Divisi only theirs; the legacy "anyone may write while no head exists" rule is dropped |
| J7 | Roster rules | `maksBeruntun` (6) and `jedaMin` (10 h) move to `/kelola/pengaturan` and only warn |
| J8 | Clean-up | `jabatan` in the Jadwal setting is dropped for the master Jabatan; the default shift per crew (`shiftKru`) moves to the Karyawan record; `manajemen`/`template` are dropped if unused |
| J9 | Cutover | Two weeks of parallel use, then the old Jadwal panel is read-only, then removed. Pekerja Harian stay in the DW module |
| J11 | Official schedule (owner 2026-10-24) | For shift-Divisi crew the roster cell is the **only** schedule: an empty cell is unscheduled (`TANPA_SHIFT`), the default shift no longer fills it (it stays the fixed schedule of nonshift staff). A night shift is one work date: a MASUK after midnight belongs to yesterday's running overnight shift, and a PULANG is judged by its MASUK's work date |
| J12 | Roster publishing (owner 2026-10-24) | A Kepala Divisi publishes their Divisi's roster directly, from today on (past dates are HR's). Every save that changed a cell lands in `hris_roster_simpan` (who, HR/KEPALA, Divisi, date range, counts, note) and HR follows it in Kelola → Roster → Aktivitas. No date inside a locked period is written by roster, correction or approval |
| J13 | Correction window (owner 2026-10-24) | `koreksiMaksHari` setting (1–31, default 7) set by Admin HRIS in Kelola → Pengaturan. Approved corrections are VALID punches |
| J14 | Old Jadwal panel (owner 2026-10-24) | Locked read-only in laksamana-office-vue, so HRIS is the only roster writer there and the history stays complete |
| J10 | Team roster, shift changes, audit (#346) | Crew `/roster` (schedule only, explicit cells, Belum diatur stays), crew `tim/ubah-shift` (one person, one date, working target), head decides directly in `/persetujuan`, HR fallback in Kelola; every HRIS cell change lands append-only in `hris_roster_event`, readable at Kelola roster Riwayat. `PUT /me/contact` and legacy panels untouched |

PR order: (1) schema + import, (2) kelola karyawan, (3) master shift + block punches, (4) roster, (5) retire the old panels.

### Payroll

| # | Decision | Value |
|---|---|---|
| P1 | Scope (phase a) | Only the variable part: the **meal allowance**, plus the team bonus ⏳. Fixed salary is **not** stored in HRIS. Periods are locked, then exported (Excel) |
| P2 | Meal allowance | Amount per Divisi/grade, effective-from setting. A day counts when it has a MASUK VALID. Late arrival still counts. MENUNGGU does not count until decided, and a period can be locked only when the queue for it is empty. A holiday worked counts (it also gives PH). Sick, IZIN, CUTI, OFF and ALFA do not count. Pekerja Harian (DW) are excluded |
| P3 | Phase b (planned, not built) | Full payslip: fixed components per person (HR-only), crew see their own slip, a PDF, and an immutable locked month |

### People Score (new) and KPI

| # | Decision | Value |
|---|---|---|
| S1 | Purpose | Monthly feedback and a basis for promotion. **Not** a bonus basis |
| S2 | Components & starting weights | Kehadiran 25, Ketepatan 15, KPI tim 20, Pelatihan (Akademi + onboarding) 10, Disiplin 15, Penilaian atasan 15. **Weights and components are settings that change over time**, so the formula is versioned (effective-from) |
| S3 | Snapshot | A monthly snapshot is locked and keeps its formula version. Completeness below 60% means the person is not ranked |
| S4 | Discipline | Pelanggaran/SP are rebuilt in HRIS |
| S5 | Penilaian atasan | Kepala Divisi rates each member monthly on 3–4 aspects, on a 1–5 scale |
| K1 | Team KPI | Template per Divisi: 3–6 items, each with a target, direction (up/down) and weight. Realisation is monthly; achievement is clamped to 0–120% and averaged by weight. Items fill automatically where the data exists, otherwise Kepala Divisi enters them. No individual KPI. Phase 1 only **shows** team KPI; input follows. The list of items per Divisi is ⏳ |

### Recruitment and requests

| # | Decision | Value |
|---|---|---|
| R1 | Manpower request | Kepala Divisi submits: position, headcount, replacement or addition, reason, date needed, qualifications. Approval goes HR → Owner (superadmin). Once approved, it becomes a **vacancy** |
| R2 | CVs in | HR uploads them first. A public application form comes later |
| R3 | AI screening | Behind a `CvScreener` interface (provider: Meta Muse Spark; API not yet verified — see research note below). PII is redacted server-side before sending (NIK, phone, email, address, photo). The key lives only in the server `.env`. The score is advice with reasons; HR decides. Every result is stored with its prompt and model version |
| R4 | Retention | Applicant data is deleted automatically after 12 months, and HR can delete it manually |
| R5 | Hired | A hired applicant becomes a **draft User**, which HR activates. Onboarding then starts |

#### R3 research note (Muse Spark API — UNVERIFIED, do not build on assumptions)

No research access was available when B5 was built, so the integration
speaks a plain JSON contract and refuses (`503 ai_belum_dikonfigurasi`)
until `HRIS_AI_URL` + `HRIS_AI_KEY` are set. Before enabling in production,
verify against the official Meta docs and record here: the HTTPS endpoint
and auth scheme (assumed: POST + Bearer key), request/response shapes
(assumed: `{model, input, criteria, format}` → `{skor, alasan,
ringkasan}`), rate limits and pricing, data-retention/processing terms
(CVs are UU PDP personal data — confirm zero-retention or sign a DPA),
and the exact model name (`muse-spark-1` is a placeholder). Prompt
version `HRIS-CV-1` — bump it with any wording change, since stored
results cite it.
| R6 | Inter-division requests | HRIS screens for every Divisi, with data stored in **BD Permintaan Koordinasi** (Diminta → Diproses → Review → Selesai) through the BD service. Anyone may create one, and the Kepala Divisi of the target Divisi assigns it. Marketing → Konten design requests are shown read-only |
| O1 | Onboarding | Akademi program(s) per Divisi (company knowledge + technical skills), auto-assigned, plus an HRIS checklist for non-learning steps (contract, uniform, accounts, buddy, 30/60/90-day review) |

### Perhatian (computed on request, no push, no scheduler)

`GET /hris/me/today` returns `perhatian[]`: `{kode, judul, pesan, layar,
tingkat: 'info'|'penting'}`, computed by the server when asked. Beranda
renders it as a "Perlu perhatian" box. The kinds:

1. No MASUK X minutes after the shift starts (`perhatianTelatMenit`,
   default 15). On a block shift the check follows the block the clock is
   in: "Belum absen masuk blok 2" starts at block 2's own start.
2. MASUK without PULANG X minutes after the shift ends
   (`perhatianPulangMenit`, default 60). On a block shift every block keeps
   its own pair, so a missed PULANG of block 1 still warns after block 2
   has started. Blocks that cross midnight follow the next day's log, same
   as the existing night-shift guard.
3. PH expiring within 14 days.
4. Requests waiting for your approval (Kepala Divisi or HR only).
5. Your request or queue decisions from the last 3 days.
6. A near Akademi or onboarding deadline.

Contract-expiry notices follow once contracts exist (⏳).

## Jadwal write-back risk (accepted, reconciled daily)

Approval writes roster cells on the legacy Jadwal connection outside the
core transaction (two databases cannot share one). If the core commit fails
after the cells are written, the request stays MENUNGGU_HRD while the cells
exist; HR re-decides and the retry rewrites the same cells, so the state
heals itself. Whatever is still missing is reported by the daily
`hris:harian` reconciliation (`rekonsiliasiJadwal`: approved requests whose
roster cells are fully absent → `jadwal_sel_hilang` audit) instead of being
silently trusted.

## Pending (⏳ HR mentor)

- **Contracts:** where the data lives today; whether probation is part of contract 1; whether the 12 days reset every anniversary within a long contract; whether PKWTT follows the same rule. Proposed shape: `hris_kontrak(user_id, urutan, jenis PROBATION/PKWT/PKWTT, mulai, selesai, akhir_probation, berkas)`.
- **Team bonus formula:** pool, KPI factor and threshold, how it is split, and the period.
- **KPI items per Divisi** and validation of the People Score weights.

## Phase b plan (P3 payslip — planned, not built)

Full payslip stays out of HRIS until the owner confirms the components.
When it is built, the shape is: `hris_gaji_komponen` (person, kind
fixed/allowance/deduction, amount encrypted at rest, effective-from),
`hris_slip` (month, person, line snapshot JSON, immutable once locked),
crew read their own slip, HR reads all, PDF export per slip, and a locked
month is never reopened. Fixed salary is never derived from attendance;
attendance only feeds the variable parts (meal allowance now, team bonus
once ⏳ lands).

## Schedule (all streams start now)

| By | Must be live |
|---|---|
| Nov 2026 | Module + migrations; mobile app: login, home, face enrolment, punch with camera + GPS; HR queue; holiday calendar |
| Dec 2026 | Balances (leave/PH), requests CUTI/PH/IZIN → Jadwal, `perhatian[]` in `me/today` + PWA crew screens |
| Jan 2027 | Meal-allowance recap + period lock + export; People Score snapshot |
| Jan–Feb 2027 | One full pay period in parallel with Talenta; compare the recaps |
| Mar 2027 | Talenta off |

Running in parallel from the start: team KPI (read), onboarding, manpower requests + CV screening, inter-division requests. Bonus and contracts start when ⏳ is answered.

## Open technical checks

- Whether the cPanel cron can run `php artisan schedule:run` (`hris:harian`
  reconciliation depends on it; reminders need no cron).
- Muse Spark API details (endpoint, limits, data handling) before R3 is built.
- CORS must allow the PWA origins `https://hris.laksamanamuda.id` and
  `http://localhost:5173` (ADR-0005).
