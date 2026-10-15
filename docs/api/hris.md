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
| 404 | `not_found` | Unknown punch/face/photo |
| 409 | `already_enrolled` | Enrol while AKTIF (needs HR reset first) |
| 409 | `wajah_milik_orang_lain` | Face matches another enrolled person (audited for HR) |
| 409 | `version_conflict` | Stale `berubah` (`details.current`) |
| 409 | `already_decided` | Deciding a non-MENUNGGU punch |
| 422 | `validation_failed` | Bad arah, missing alasan, bad descriptor/foto/settings |
| 422 | `rejected` | Other rule rejections (legacy tone) |

## For laksamana-hris

`packages/api` generates types from `openapi.json` (tag `hris`). Mobile:
login (name + PIN) → Beranda (`me/today`) → enrol (`faces/me` with consent)
→ punch (`punches` with camera + GPS). Console: queue + decisions (send
`berubah`, handle `409` by reloading), faces, locations, settings. Face
photos and descriptors never leave the server except through the two HR
photo streams.
