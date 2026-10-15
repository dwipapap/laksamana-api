# HRIS API (`/api/v1/hris`) — attendance (B1)

Talenta replacement, greenfield module on `core` (no legacy backend, no
parity). Why a v1 module and not v2: [ADR-0009](../adr/0009-hris-greenfield-module-reading-v1.md).
Decisions: [docs/modules/hris.md](../modules/hris.md) (Attendance A1–A9).
Code: `app/Modules/Hris`.

- Envelope: `{data, meta?}` / `{error:{code,message,details?}}`.
- Auth: `Authorization: Bearer <token>` (existing `POST /api/v1/auth/login`
  with name + PIN) + module `hris`. No device binding, no app biometric lock (A9).
- Lingkup (CONTEXT.md) is enforced on the server; the subject is always the
  token holder: `sendiri` (crew: own rows), `divisi` (Kepala Divisi: their
  divisions, never their own queue decision), `semua` (HR: module admins of
  `hris` plus the `hr` id list in settings).
- Time: server time rendered as WIB. Money: n/a in B1.
- No liveness (A4). Defences are face match, the duplicate check, GPS and the
  audit photo.

## Endpoints

| Method | Path | Lingkup | Notes |
|---|---|---|---|
| GET | `/hris/me/today` | all | Punch-screen bootstrap: shift today, MASUK/PULANG rows, nearest locations, server time, enrolment flag |
| POST | `/hris/punches` | self | Punch in/out. Repeated MASUK → `200 {duplikat:true}`; repeated PULANG overwrites. `201` otherwise |
| GET | `/hris/punches?from&to&user` | scoped | History. Crew are forced to their own rows; heads to their divisions |
| GET | `/hris/punches/{id}/photo` | scoped | Audit photo (self, HR, head of division) |
| GET | `/hris/queue` | HR + head | Queued punches, longest-waiting first |
| POST | `/hris/punches/{id}/decision` | HR + head | `{status: VALID\|DITOLAK, nota?, berubah}` — the seen version; stale → `409 version_conflict`, decided → `409 already_decided` |
| POST | `/hris/faces/me` | self | Enrol own face: `{descriptor[], model, foto?, consent: true}`. Active at once. Duplicate of another's → `409 wajah_milik_orang_lain` + HR audit |
| GET | `/hris/faces` | HR | Enrolled faces, **without** descriptors |
| GET | `/hris/faces/{userId}/photo` | HR | Enrolment photo stream |
| DELETE | `/hris/faces/{userId}` | HR | Reset: the person may enrol again |
| GET | `/hris/locations` | all | Attendance locations (read is open; the punch screen needs them) |
| POST | `/hris/locations` | HR | Create (radius clamped 30–2000 m). `201 {id}` |
| PATCH | `/hris/locations/{id}` | HR | Update (upsert body). `201 {id}` |
| DELETE | `/hris/locations/{id}` | HR | `{hapus: 1\|0}` |
| GET | `/hris/settings` | all | Merged settings map (defaults + stored rows) |
| PUT | `/hris/settings` | HR | `{data: {...}}` partial; unknown keys refused |
| POST | `/hris/admin/import-locations` | HR | One-time idempotent import of legacy absensi locations (by nama). `{dibuat, diperbarui}` |

### Punch body

```json
{
  "arah": "MASUK",
  "lat": -6.2, "lng": 106.8, "akurasi": 12,
  "descriptor": [0.12, -0.03, "..."],
  "model": "mobilefacenet-v1",
  "foto": "<base64, EXIF GPS stripped on device>",
  "alasan": "dinas luar (wajib bila di luar ketentuan)"
}
```

`lat`/`lng`/`akurasi`/`descriptor`/`model`/`foto`/`alasan` are optional;
`arah` is required. A punch outside the rules without `alasan` is `422`.

### Punch item

```json
{
  "id": "01j…", "user_id": "u-dwipa", "tanggal_kerja": "2026-10-20",
  "arah": "MASUK", "waktu": 1760918400000, "jam": "09:00",
  "lokasi_id": "01j…", "jarak_m": 42, "dalam_area": true,
  "shift_kode": "PAGI", "shift_mulai": "08:00", "shift_selesai": "17:00",
  "lintas_hari": false, "shift_sumber": "JADWAL", "dalam_shift": true,
  "skor_wajah": 0.18, "wajah_ok": true,
  "status": "MENUNGGU", "alasan_antrean": ["LUAR_AREA"], "alasan_kru": "dinas luar",
  "diputus_oleh": "", "diputus_at": 0, "nota": "",
  "berubah": 1, "ada_foto": true, "foto_url": "/api/v1/hris/punches/01j…/photo"
}
```

- `shift_sumber`: `JADWAL` (roster cell), `BAWAAN` (person's default shift),
  `NONE` (genuinely unscheduled), `TAK_TERBACA` (roster unreadable — recorded,
  never queued).
- `alasan_antrean`: `[]` when VALID, else one of `LUAR_AREA`, `TANPA_SHIFT`,
  `HARI_LIBUR`, `LUAR_SHIFT`, `WAJAH`, `WAJAH_KOSONG` (precedence in this
  order; a `MENUNGGU` row always carries the crew's `alasan_kru`).
- `berubah` is the ADR-0007 `version` column (the build prompt's `berubah`
  name maps to it; see the migration note). Send it back on decisions.
- A night-shift PULANG within 18 h of MASUK belongs to the MASUK date.

### me/today item

```json
{
  "siapa": {"id": "u-dwipa", "nama": "Dwipa"},
  "tgl": "2026-10-20", "waktuServer": 1760918400000, "jam": "09:00",
  "shift": {"kode": "PAGI", "mulai": "08:00", "selesai": "17:00",
            "lintas_hari": false, "sumber": "JADWAL", "libur": 0},
  "masuk": {…}|null, "pulang": {…}|null,
  "wajahTerdaftar": true,
  "lokasi": [{"id": "01j…", "nama": "Outlet", "lat": -6.2, "lng": 106.8, "radius": 120, "aktif": true}],
  "lingkup": "sendiri"
}
```

`shift` is `null` when unscheduled, `false` when the roster is unreadable.

### Settings

Defaults (`PUT /hris/settings` takes a partial map; unknown keys are `422`):

```json
{
  "awalMenit": 60, "akhirMenit": 180, "toleransiTelat": 5,
  "tanpaShiftBoleh": false, "wajahWajib": false,
  "wajahAmbang": {
    "default": {"metric": "cosine", "ambang": 0.4},
    "mobilefacenet-v1": {"metric": "cosine", "ambang": 0.4}
  },
  "hr": ["u-admin"]
}
```

- `awalMenit`/`akhirMenit`: shift window tolerances (0–720).
- Whoever IS enrolled must match; the unenrolled only queue when
  `wajahWajib` is on (so day one does not lock the crew out).
- `wajahAmbang` thresholds are placeholders until the M1 calibration
  (TODO(mentor) in docs/modules/hris.md).
- `hr`: extra HR staff by User id; module admins of `hris` are always HR.

## Errors

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No/bad token |
| 403 | `module_not_granted` | Token without `hris` |
| 403 | `forbidden` | Outside Lingkup (foreign rows, crew in queue, non-HR writes) |
| 404 | `not_found` | Unknown punch/face/photo/request/attachment |
| 409 | `already_enrolled` | Enrol while AKTIF (needs HR reset first) |
| 409 | `wajah_milik_orang_lain` | Face matches another enrolled person (audited for HR) |
| 409 | `version_conflict` | Stale `berubah` (`details.current`) |
| 409 | `already_decided` | Deciding a non-MENUNGGU punch or a final request |
| 422 | `validation_failed` | Bad arah, missing alasan, bad descriptor/foto/settings/dates |
| 422 | `rejected` | Other rule rejections (legacy tone) |
| 422 | `saldo_kurang` | Request/adjustment exceeds the balance (`details.saldo/butuh`) |
| 422 | `jadwal_tulis_ditolak` | Approved request whose Jadwal cells the approver may not write (HRD needs jadwal-admin rights or a heading Kepala Divisi) |

## B2 — calendar, balances, requests

Leave, PH and requests (hris.md L1–L9). Tables: `hris_kalender`,
`hris_saldo_mutasi` (append-only ledger, saldo = SUM), `hris_pengajuan`.
Scheduler: `php artisan hris:harian` daily 01:10 Asia/Jakarta
(routes/console.php); the cPanel cron `* * * * * php artisan schedule:run`
drives it (docs/deploy.md).

| Method | Path | Lingkup | Notes |
|---|---|---|---|
| GET | `/hris/calendar?dari&sampai` | all | Holiday list |
| POST | `/hris/calendar` | HR | `{tanggal, nama, jenis?}` — same date twice is `422`. `201 {id}` |
| DELETE | `/hris/calendar/{id}` | HR | `{hapus: 1}` |
| POST | `/hris/calendar/import` | HR | `{libur: [{tanggal, nama, jenis?}]}` or multipart `file` (.json/.csv). Upsert by date: `{dibuat, diperbarui}` |
| GET | `/hris/balances/me` | self | `{PH: {saldo, hangus_dekat}, CUTI: {saldo, jatah_tahunan}}` |
| GET | `/hris/balances?user` | scoped | Always a list (HR without a filter sees their own) |
| POST | `/hris/balances/adjust` | HR | `{user, jenis, delta, catatan}` — reason required; negative beyond balance is `422 saldo_kurang` |
| GET | `/hris/requests?user&status&jenis` | scoped | Request list (max 500) |
| POST | `/hris/requests` | self | `{jenis, dari, sampai?, alasan, lampiran?, lampiran_nama?}` — IZIN needs proof; CUTI/PH need balance. `201` |
| GET | `/hris/requests/{id}` | scoped | One request |
| GET | `/hris/requests/{id}/lampiran` | scoped | Attachment download |
| DELETE | `/hris/requests/{id}` | scoped | Cancel: requester while waiting; HR also after approval (refund + cell cleanup) |
| POST | `/hris/requests/{id}/decision` | head/HRD | `{status: DISETUJUI\|DITOLAK, nota?}` — head step (`MENUNGGU` → `MENUNGGU_HRD`), then HRD step |

### Request item

```json
{
  "id": "01j…", "user_id": "u-dwipa", "jenis": "CUTI",
  "tgl_mulai": "2026-11-02", "tgl_selesai": "2026-11-04", "hari": 3,
  "alasan": "Keluarga", "ada_lampiran": false,
  "status": "MENUNGGU_HRD",
  "head_oleh": "Arif", "head_at": 1760918400000, "head_nota": "ok",
  "hrd_oleh": "", "hrd_at": 0, "hrd_nota": "",
  "berubah": 1
}
```

- Filing deducts nothing; approval deducts FIFO (oldest PH expiry first)
  and writes Jadwal cells (`CUTI`→`CUTI`, `IZIN`→`IZIN`, `PH`→`OFF` with a
  note such as `PH 25 Des`). The old Jadwal screens keep working unchanged.
- The head step needs the Kepala Divisi of the requester's division (never
  for their own request). A division with no head — or office staff outside
  every division — is head-approved by HR instead (two decisions stay two).
- Approving needs Jadwal write rights: the cells are written with the
  approving head's authority. An HRD who is neither a jadwal admin nor
  acting on a headed division gets `422 jadwal_tulis_ditolak`.
- A `MASUK VALID` on a holiday earns +1 PH (also when a queued punch turns
  VALID late); expiry is the holiday + 3 months, first-to-expire first.
  PH is never paid out. Annual leave quota is the `jatahCuti` setting
  (default 12), granted manually by HR — TODO(mentor) for the per-contract
  rule.
- Approved CUTI from the old Jadwal panel is deducted once during the
  transition (idempotent); requests beyond the balance are skipped and
  reported for HR to top up.

## B3 — meal allowance, discipline, team KPI, People Score

Payroll phase a (P1–P2), discipline (S4), team KPI (K1), People Score
(S1–S3, S5). Money is whole rupiah; fixed salary is NOT stored here.

| Method | Path | Lingkup | Notes |
|---|---|---|---|
| GET | `/hris/meal/tarifs` | HR | Rates per (divisi, grade), effective-from |
| POST | `/hris/meal/tarifs` | HR | `{divisi?, grade?, nominal, berlaku_dari}`. `201 {id}` |
| DELETE | `/hris/meal/tarifs/{id}` | HR | `{hapus: 1}` |
| GET | `/hris/meal/preview?bulan` | scoped | Live recap, nothing stored |
| POST | `/hris/meal/lock` | HR | `{bulan}` — refused on a non-empty queue (`422 antrean_belum_kosong`) or a locked month (`409 sudah_terkunci`) |
| GET | `/hris/meal?bulan` | scoped | Locked snapshot, or live recap while open |
| GET | `/hris/meal/export?bulan` | scoped | CSV download (`-draft` suffix while open; XLSX follows when PhpSpreadsheet is added) |
| GET | `/hris/pelanggaran?user&dari&sampai` | scoped | Violation list |
| POST | `/hris/pelanggaran` | HR | `{user, tanggal, jenis?, tingkat?, sp_ke?, keterangan?}`. `201` |
| POST | `/hris/pelanggaran/{id}/batal` | HR | Void (never hard-deleted) |
| GET | `/hris/kpi?divisi&bulan` | scoped | Division score 0–120 with per-item capaian (crew: own division) |
| POST | `/hris/kpi/templates` | head/HR | Template + items; behind `kpiInputAktif` (`403 fitur_nonaktif`) |
| POST | `/hris/kpi/realisasi` | head/HR | `{item_id, bulan, nilai}`; behind `kpiInputAktif` |
| GET | `/hris/skor?bulan&user` | scoped | Score list with the active formula |
| POST | `/hris/skor/hitung` | scoped | Compute `{bulan, user?}` (crew: own only); locked months are `409 terkunci` |
| POST | `/hris/skor/kunci` | HR | `{bulan}` — immutable after |
| GET | `/hris/skor/formula` | all | Active formula + version history |
| POST | `/hris/skor/formula` | HR | `{komponen: {key: bobot}, berlaku_dari}` — new version, old snapshots keep theirs |
| POST | `/hris/penilaian` | head/HR | `{user, bulan, aspek: {name: 1..5}}` — never self |

### Meal recap item

```json
{"user_id": "u-dwipa", "divisi": "bar", "grade": "", "hari_hadir": 20,
 "nominal": 25000, "total": 500000, "rincian": ["2026-09-01", "…"]}
```

- A day counts on MASUK VALID (late counts, holidays count, MENUNGGU only
  once decided). Sick/IZIN/CUTI/OFF/ALFA never punch, so they never count.
  DW are excluded by construction (Pihak, never Users).
- Rate resolution per person: exact (divisi, grade) → (divisi) → (grade) →
  global default, latest `berlaku_dari` at work.

### Score item

```json
{"user_id": "u-dwipa", "bulan": "2026-09", "formula_versi": 1,
 "komponen": {"kehadiran": {"bobot": 50, "skor": 100, "kelengkapan": 100, "detail": {"terjadwal": 4, "hadir": 4}}},
 "skor": 100, "kelengkapan": 100, "terkunci": false, "diranking": true}
```

- Components (one class each, same interface): Kehadiran (VALID days over
  roster cells), Ketepatan (on-time over punches with a shift snapshot),
  KPI tim (division achievement capped at 100), Pelatihan (Akademi materials
  done, all-time), Disiplin (100 − penalties), Penilaian atasan (1–5 avg ×20).
- Missing data scores 0 with 0 completeness; `kelengkapan < 60` is not
  ranked. Achievement per KPI item is clamped 0–120% (`NAIK`: nilai/target,
  `TURUN`: target/nilai) and averaged by weight.

## For laksamana-hris

`packages/api` generates types from `openapi.json` (tag `hris`). Mobile:
login (name + PIN) → Beranda (`me/today`) → enrol (`faces/me` with consent)
→ punch (`punches` with camera + GPS). Console: queue + decisions (send
`berubah`, handle `409` by reloading), faces, locations, settings. Face
photos and descriptors never leave the server except through the two HR
photo streams.
