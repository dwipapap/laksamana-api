# HRIS is a greenfield v1 module on `core` that reads v1 data through its services

The company stops subscribing to Talenta in **March 2027** (owner, 2026-10-15). Its replacement, **HRIS**, is built now: attendance by camera and GPS, leave and PH balances, the meal-allowance recap, a new People Score, team KPI, onboarding, manpower requests with CV screening, inter-division requests and push reminders. Its clients are a new Expo app for crew and a Nuxt console for HR and Kepala Divisi, in the separate repo `laksamana-hris`.

HRIS lives in this API as the greenfield module **`app/Modules/Hris`**, following `homepage` (M19):

- **Its own data lives in new `core` tables named `hris_*`**, with ULID keys and the technical columns of ADR-0007. A person is keyed by the **legacy User id** (`user_id`, string), because identity is not on `core` yet (`AccountRepository::onCore()` is false), plus a nullable `user_ulid` filled when identity moves.
- **Everything else stays with its owner and is reached in-process through that owner's v1 services**, never over HTTP and never with raw SQL on a legacy database: identity, login (name + PIN), Roster, Akses and Divisi (`account`), Jadwal cells and shifts (`JadwalService`), Akademi, and BD's Permintaan Koordinasi.
- **Routes are `/api/v1/hris/*`** (`auth:sanctum` + `module:hris`, with Lingkup per screen), documented in `docs/api/hris.md`.
- **Attendance is rebuilt, not reused.** HRIS uses a new on-device face model whose descriptors are not comparable with face-api.js and the 0.45 threshold that `AbsensiService` uses. HRIS keeps its own punches, faces, locations and decision queue. It copies the proven punch rules from `AbsensiService` as its own logic: haversine radius, shift window, overnight shift (PULANG within 18 hours), queue reasons, server time, and the subject taken from the token. The legacy `absensi` module and its PWA are left untouched and unused.
- **The new People Score is computed by HRIS** from its own punches, not from the Talenta import in HR v1.

## Considered Options

- **Build HRIS in the SDM area of v2 (`app/Erp/Sdm`)**: rejected for now. Those tables are empty, have no importer, and no `/api/v2` route exists. There is no plan to migrate SDM, so building on them would create a second Roster and a second KPI truth next to v1.
- **A separate HRIS backend calling this API over HTTP**: rejected for the reasons in ADR-0006 (shared cPanel without a shell, one MySQL, a small team), plus a second login.
- **Write HRIS punches into the legacy `ketukan_absen` through `AbsensiService`**: rejected. The face model changes, and the legacy queue and threshold would judge HRIS descriptors wrongly.

## Consequences

- This departs from ADR-0006's rule that new business features go to v2. When the SDM area of v2 is designed, the `hris_*` tables are its starting point and move under `app/Erp/Sdm`. They are not redesigned from the legacy shape.
- The module is Team A territory (`app/Modules`), and its migrations need Team B's review.
- `app/Modules/Hris` may call other modules' services. No other module reads `hris_*` tables.
- Approved leave, PH and IZIN requests are written to Jadwal as cells through `JadwalService`, so the old Jadwal screens keep showing a correct roster. Requests made in the old Jadwal panel are still read, so balances stay correct during the switch.
- Face images and descriptors are biometric personal data (UU PDP): HRIS asks for consent once, keeps the minimum, and only HR sees enrolment photos.
