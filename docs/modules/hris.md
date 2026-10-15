# hris — Talenta replacement (greenfield)

**Greenfield on `core` — no legacy PHP, no legacy database, no parity cases.** Why it is a v1 module and not v2: [ADR-0009](../adr/0009-hris-greenfield-module-reading-v1.md).

- **Hard deadline:** the Talenta subscription ends **March 2027**. Attendance, leave/PH and the meal-allowance recap must run in parallel with Talenta for one full pay period before that.
- **Clients** (repo `laksamana-hris`):
  - `apps/mobile`: Expo, as a dev build with EAS Build and EAS Update, for Android and iOS. iOS goes out through TestFlight for beta and as an Unlisted App for production, from a company Apple Developer account.
  - `apps/console`: Nuxt + Nuxt UI for HR and Kepala Divisi, at `hris.laksamanamuda.id`.
  - `packages/api`: TS types generated from `openapi.json`.
- **Git:** this repo uses branches `feat/hris-*` with a PR to `main`, because `main` deploys to dev.

Decisions were settled in a grilling session with the owner on 2026-10-15. Items marked **⏳** wait for the HR mentor.

## Ownership

| HRIS owns (`hris_*`) | Read/written through v1 services |
|---|---|
| Punches, faces, attendance locations, decision queue, attendance settings | Identity, login name + PIN, Roster, Akses, Divisi (`account`) |
| Holiday calendar, leave/PH balance ledger, all requests (CUTI, PH, IZIN; later OFF, TUKAR) | Jadwal cells and shifts (`JadwalService`): read for punches, written when a request is approved |
| Meal-allowance recap and locked periods, People Score, team KPI, discipline (pelanggaran/SP) | Akademi materials and progress (onboarding, training) |
| Contracts ⏳, onboarding checklist, manpower requests, vacancies, applicants/CVs | BD Permintaan Koordinasi (inter-division requests) |
| Push tokens, reminders | Marketing → Konten design requests (shown read-only) |

## Locked decisions

### Attendance

| # | Decision | Value |
|---|---|---|
| A1 | Source of truth | HRIS punches. Talenta is switched off in March 2027; the legacy `absensi` module and PWA are not used |
| A2 | Face model | New on-device model (native, e.g. TFLite). Every crew member enrols again. Descriptors store their `model` |
| A3 | Enrolment | Self-service at first login, active at once. Photo comes from the live camera only. The new descriptor is compared with **every** enrolled face, and a match with someone else is rejected and reported to HR. Re-enrolment only after an HR reset. HR can see enrolment photos. Biometric consent is shown once |
| A4 | Liveness | **None.** The defences are face match, the duplicate check, GPS and the audit photo |
| A5 | Location | GPS radius per attendance location (haversine, clamped). Legacy `lokasi_absen` may be imported once as a starting point |
| A6 | Rules copied from `AbsensiService` | Server time only; the subject comes from the token; shift window ±minutes; overnight shift (PULANG ≤ 18 h after MASUK belongs to the MASUK date); queue reasons LUAR_AREA, TANPA_SHIFT, HARI_LIBUR (the person's own day off — a public holiday never queues, it earns PH), LUAR_SHIFT, WAJAH; MENUNGGU needs a human decision; DITOLAK counts as no punch |
| A7 | Shifts | Read from Jadwal cells, falling back to each person's default shift (`JadwalService`) |
| A8 | Geofence | Background geofence is used **only for reminders**. There is no automatic punch |
| A9 | Login | Existing `POST /api/v1/auth/login {login, pin}`. No device binding, no app biometric lock |

### Leave, PH and requests

| # | Decision | Value |
|---|---|---|
| L1 | Requests | **All requests move to HRIS.** Phase 1 covers CUTI, PH and IZIN (with attachment); OFF and TUKAR follow. Approval goes Kepala Divisi → HRD. On approval HRIS writes the Jadwal cell. The Jadwal roster (cell planning) stays in the old Jadwal panel |
| L2 | Balances | One ledger of entries per person (`PH`, `CUTI`): earned, used, expired |
| L3 | PH earned | A **MASUK VALID** on a holiday in the HRIS calendar gives 1 PH |
| L4 | PH expiry | 3 months from the holiday date. Used first-to-expire (FIFO). A reminder goes out 14 days before expiry |
| L5 | PH on resignation | Not paid out |
| L6 | PH claim | Requested in HRIS. Once approved, the Jadwal cell becomes **OFF**, with a note such as "PH 25 Des". The v1 Jadwal contract does not change |
| L7 | Annual leave | 12 days. Only from the **second** contract. It resets on the contract start date. The remainder expires. Probation is 2 months at the start of the first contract |
| L8 | Holiday calendar | Entered by HR, with an automatic import of national holidays where possible |
| L9 | Overtime | **Not counted**, whatever the arrival or leaving time. Crew adjust their shift instead |

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

### Reminders (push via Expo → FCM/APNs; Laravel scheduler from a cPanel cron)

1. No MASUK X minutes after the shift starts.
2. Left the area without a PULANG (geofence).
3. Entered the area without a MASUK (geofence).
4. PH expires in 14 days.
5. A request is waiting for approval (Kepala Divisi/HR).
6. A request or queue decision was made (crew).
7. An Akademi or onboarding deadline is near.

Contract-expiry reminders follow once contracts exist (⏳).

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
| Dec 2026 | Balances (leave/PH), requests CUTI/PH/IZIN → Jadwal, push reminders 1–7, geofence |
| Jan 2027 | Meal-allowance recap + period lock + export; People Score snapshot |
| Jan–Feb 2027 | One full pay period in parallel with Talenta; compare the recaps |
| Mar 2027 | Talenta off |

Running in parallel from the start: team KPI (read), onboarding, manpower requests + CV screening, inter-division requests. Bonus and contracts start when ⏳ is answered.

## Open technical checks

- Whether the cPanel cron can run `php artisan schedule:run` (reminders depend on it).
- Muse Spark API details (endpoint, limits, data handling) before R3 is built.
- An Apple Developer organisation account (D-U-N-S number), and the share of crew on iPhone.
- CORS must allow the console origin (ADR-0005).
