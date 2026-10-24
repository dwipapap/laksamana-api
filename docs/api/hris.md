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
  `hris` plus the `hr` id list in settings; HR may decide their own queued
  punch, owner decision 2026-10-17).
- `/kelola` is an HRIS-admin surface in the PWA. `me/today.bolehKelola` is
  true only for `hris` module admins and superadmins. HR settings membership
  and division-head status do not grant entry to `/kelola`.
- Time: server time rendered as WIB. Money: n/a in B1.
- No liveness (A4). Defences are face match, the duplicate check, GPS and the
  audit photo.

## Endpoints

| Method | Path | Lingkup | Notes |
|---|---|---|---|
| GET | `/hris/me/today` | all | Punch-screen bootstrap: shift today, MASUK/PULANG rows, nearest locations, server time, enrolment flag + face count (`wajahJumlah`), `perhatian[]` |
| POST | `/hris/punches` | self | Punch in/out. Repeated MASUK → `200 {duplikat:true}`; repeated PULANG overwrites. `201` otherwise. Optional `buktiWajah` (signed proof, replaces the client `descriptor`) |
| GET | `/hris/punches?from&to&user` | scoped | History. Crew are forced to their own rows; heads to their divisions |
| GET | `/hris/riwayat?bulan&user` | scoped | Monthly log for one user: daily rows + summary (Riwayat screen). `bulan` is `YYYY-MM`; `user` defaults to self |
| GET | `/hris/punches/{id}/photo` | scoped | Audit photo (self, HR, head of division) |
| GET | `/hris/queue` | HR + head | Queued punches, longest-waiting first (rows carry `nama` + `divisi`; no server filters — filter client-side) |
| POST | `/hris/punches/{id}/decision` | HR (own punch too) + head (division, not own) | `{status: VALID\|DITOLAK, nota?, berubah}` — the seen version; stale → `409 version_conflict`, decided → `409 already_decided` |
| POST | `/hris/faces/me` | self | Enrol own face: `{descriptors[1..3] | descriptor, model, foto, consent: true}` + optional `buktiWajah[1..3]` (one signed proof per descriptor; the straight photo binds the first). Active at once. Within the set, two descriptors farther apart than the threshold → `422 validation_failed`. Duplicate of another's → `409 wajah_milik_orang_lain` + HR audit |
| POST | `/hris/faces/me/tambah` | self | Complete own face (W3): `{descriptor, model}` + optional `buktiWajah` while AKTIF and below 3 descriptors → `{user_id, jumlah_descriptor}` + `wajah_dilengkapi` audit. Shares the cek rate limit. At 3 → `409 wajah_lengkap`; outside the threshold → `422 wajah_tidak_cocok`; someone else's → `409 wajah_milik_orang_lain` + HR audit; another model → `422` |
| POST | `/hris/faces/me/cek` | self | Pre-send check: `{descriptor[], model}` + optional `buktiWajah` → `{terdaftar, cocok}`. No score served. Every check audits `wajah_dicek` `{skor, cocok, model, sumber_wajah}` (no photo, no descriptor); 429 writes nothing. Shares the limit with `tambah`: 10 per 5 min per user → `429 terlalu_sering` |
| GET | `/hris/faces` | HR | Enrolled faces, **without** descriptors (`user_id`, `nama`, `model`, `consent_at`, `dibuat_oleh`, `ada_foto`, `jumlah_descriptor`, `gagal_cek_7h`, `version`). `gagal_cek_7h` counts `wajah_dicek` with `cocok: false` in the last 7 days |
| GET | `/hris/faces/kalibrasi` | HR | Check-score percentiles per model over the last 90 days of `wajah_dicek`: `{model: {n, p50, p90, p95, p99}}` |
| GET | `/hris/faces/{userId}/cek?limit=10` | HR | Recent `wajah_dicek` rows of one person, newest first (`[{waktu, skor, cocok, model}]`, limit ≤ 50) |
| GET | `/hris/faces/{userId}/photo` | HR | Enrolment photo stream |
| DELETE | `/hris/faces/{userId}` | HR | Reset: the person may enrol again |
| GET | `/hris/locations` | all | Attendance locations (read is open; the punch screen needs them) |
| GET | `/hris/pengguna` | HR + head | Roster id + name + division for HR pickers (heads: own divisions; crew: `403`) |
| POST | `/hris/locations` | HR | Create (radius clamped 30–2000 m). `201 {id}` |
| PATCH | `/hris/locations/{id}` | HR | Update (upsert body). `201 {id}` |
| DELETE | `/hris/locations/{id}` | HR | `{hapus: 1\|0}` |
| GET | `/hris/settings` | all | Merged settings map (defaults + stored rows) |
| PUT | `/hris/settings` | HR | `{data: {...}}` partial; unknown keys refused |
| POST | `/hris/admin/import-locations` | HR | One-time idempotent import of legacy absensi locations (by nama). `{dibuat, diperbarui}` |
| GET | `/hris/pengumuman` | all | Published announcements for the caller's active HRIS division(s), plus global notices. `?kelola=1` returns the caller's permitted authored items; HRIS admins see all. |
| GET | `/hris/pengumuman/{id}` | audience / author | Detail. An inaccessible or guessed id answers `404`, including foreign published notices. `?kelola=1` is required for an author's own draft. |
| POST | `/hris/pengumuman` | HR, head, granted writer, admin | Create a draft with `{judul, isi, semua, divisi_ids}`. Author id/name come from the session. A head is limited to the canonical HRIS division ids they lead, even if also granted extra rights. |
| PATCH | `/hris/pengumuman/{id}` | author / admin | Edit an owned draft or published item with `{judul, isi, semua, divisi_ids, berubah}`. The author and original `published_at` stay fixed; each successful edit increments `berubah` and `updated_at`. |
| POST | `/hris/pengumuman/{id}/publish` | author / admin | Publish a draft immediately with `{berubah}`. The server rechecks writer and audience scope, target divisions, and version under a row lock. |
| POST | `/hris/pengumuman/{id}/archive` | author / admin | Archive a permitted authored item with `{berubah}`. |
| GET | `/hris/pengumuman/options` | writer; admin for grant list | Returns audience choices. Admins also get active employee names, current announcement grants, and active divisions. |
| PUT | `/hris/pengumuman/grant` | HRIS module admin / superadmin | Set a selected active employee's announcement-only scope: `{user_id, semua_divisi, divisi_ids}`. This grant does not confer any HR endpoint privilege or `/kelola` access. |
| DELETE | `/hris/pengumuman/grant/{userId}` | HRIS module admin / superadmin | Revoke the selected employee's announcement-only grant. |

### Announcement access rules

Announcements and scoped grants are stored in `hris_pengumuman`,
`hris_pengumuman_divisi` and `hris_pengumuman_grant` on the `core` connection.
The feature has no attachment fields or storage. Public listing includes only
published rows. Drafts are visible to their author through the management
view and to admins. A writer's management list contains only their own items;
published content is available there only while every target remains in the
writer's current scope. Admins can manage all rows. Division heads are always
limited to divisions returned by `HrisDivisiService::petaKepala()`; an
additional grant never widens a head's publishing scope. HR settings members
can publish globally or to active divisions but still cannot open `/kelola`.
Other granted writers can publish globally or to the active divisions chosen
by an HRIS admin. All writes require `berubah`; a stale version answers `409`.

### Punch body

```json
{
  "arah": "MASUK",
  "lat": -6.2, "lng": 106.8, "akurasi": 12,
  "descriptor": [0.12, -0.03, "..."],
  "model": "sface-2021dec-int8",
  "buktiWajah": "<signed proof, replaces descriptor when valid>",
  "foto": "<base64, EXIF GPS stripped on device>",
  "alasan": "dinas luar (wajib bila di luar ketentuan)"
}
```

`lat`/`lng`/`akurasi`/`descriptor`/`model`/`foto`/`alasan`/`buktiWajah` are optional;
`arah` is required. A punch outside the rules without `alasan` is `422`.

### Signed face proof (`buktiWajah`)

The VPS signs the on-device embedding so a rooted phone cannot invent a
face: `token = base64url(payload JSON) + "." + base64url(HMAC-SHA256 over
the payload bytes)`. The payload keys run `v,kid,model,descriptor,
fotoSha256,iat` with no spaces. Verification (server time, never the phone)
checks the signature (`hash_equals`), a known `kid` (active or previous),
`model` equal to the request `model` field, age ≤ 120 s with 30 s future
skew tolerance, and — when a photo is sent — `fotoSha256` equal to the
SHA-256 of the decoded photo bytes. Any failure is `422
bukti_wajah_tidak_valid`. The signing keys live only in the server env
(`HRIS_FACE_SECRET` + `HRIS_FACE_KID`, rotation via `HRIS_FACE_SECRET_LAMA`
+ `HRIS_FACE_KID_LAMA`); with an empty secret every proof is refused and
`HRIS_FACE_BUKTI_WAJIB` counts as false.

Transition rules:

- A valid proof's descriptor **replaces** the client field on punch, enrol,
  cek and tambah. Enrol takes one proof per descriptor (`buktiWajah`
  length must equal the descriptor count); the straight enrolment photo
  binds the first proof.
- While `HRIS_FACE_BUKTI_WAJIB=true` (and a secret is set), requests without
  a proof are refused: punch ignores the client descriptor and queues
  (`WAJAH` when enrolled, `WAJAH_KOSONG` when not — still needs `alasan`
  like any queued punch); enrol/cek/tambah answer `422
  bukti_wajah_tidak_valid`.
- With the flag off, requests without a proof behave exactly as before.
- Face audits (`wajah_dicek`, `wajah_didaftarkan`, `wajah_dilengkapi`,
  `wajah_duplikat_ditolak`) carry `sumber_wajah`: `server` (descriptor from
  a verified proof), `klien` (descriptor from the client field), or `tanpa`
  (no descriptor was matched — unenrolled or wrong-model pre-check). Audit
  data never carries descriptor bytes.

### Punch item

```json
{
  "id": "01j…", "user_id": "u-dwipa", "nama": "Dwipa", "divisi": "bar",
  "tanggal_kerja": "2026-10-20",
  "arah": "MASUK", "waktu": 1760918400000, "jam": "09:00",
  "lat": -6.2, "lng": 106.8, "akurasi_m": 12,
  "lokasi_id": "01j…", "jarak_m": 42, "dalam_area": true,
  "shift_kode": "PAGI", "shift_mulai": "08:00", "shift_selesai": "17:00",
  "lintas_hari": false, "shift_sumber": "JADWAL", "dalam_shift": true,
  "telat_menit": 0, "pulang_awal": false, "kurang_menit": 0,
  "target_menit": 540, "durasi_menit": null, "curiga": [],
  "skor_wajah": 0.18, "wajah_ok": true,
  "status": "MENUNGGU", "alasan_antrean": ["LUAR_AREA"], "alasan_kru": "dinas luar",
  "diputus_oleh": "", "diputus_at": 0, "nota": "",
  "berubah": 1, "ada_foto": true, "foto_url": "/api/v1/hris/punches/01j…/photo"
}
```

- `dalam_area`: `jarak_m <= radius` **and** `1 <= akurasi <= akurasiMaks`
  (setting, default 100 m). Missing/zero/too-coarse accuracy is outside;
  no `jarak - akurasi` slack. No locations registered = inside (setup).
- `dalam_shift`: MASUK inside `[mulai - awalMenit, selesai]`; PULANG no later
  than the clock-out goal + `pulangLewatMenit`.
- `telat_menit` (MASUK): past `mulai + toleransiTelat`, else 0. `durasi_menit`
  (PULANG): minutes from the actual MASUK. `target_menit`: JADWAL snapshot
  duration, else `durasiKerja.orang[userId]`, else BAWAAN snapshot, else
  `durasiKerja.default`. The clock-out goal (2026-10-24) is the **scheduled
  end** of the MASUK snapshot for `ROSTER`/`SHIFT_BAWAAN` (arriving early
  never moves it; overnight ends the next day), and MASUK + target only for
  `PER_ORANG`/`CADANGAN`. `pulang_awal` + `kurang_menit`: PULANG before the
  goal (still VALID). Metrics live in `hris_ketukan.info`; rows predating
  the column recompute them live (`curiga: []`).
- `curiga[]`: suspicion flags, never a queue reason, HR/head readers only
  (crew get `[]`): `KOORDINAT_SAMA` (same point as the person's previous
  punch), `TITIK_PUSAT` (exactly on the location centre), `AKURASI_JANGGAL`
  (akurasi ≤ 1 or a multiple of 100), `KOORDINAT_KEMBAR` (another user sent
  the same point within ±5 min).

- Work date: a MASUK after midnight belongs to yesterday's overnight shift
  while that shift has not ended and has no MASUK yet; a PULANG is judged by
  the roster of its MASUK's work date (never the next day's cell).
- `shift_sumber`: `JADWAL` (roster cell), `BAWAAN` (default shift of a
  **nonshift** staff member; shift-Divisi crew with an empty cell are `NONE`),
  `NONE` (genuinely unscheduled), `TAK_TERBACA` (roster unreadable — recorded,
  never queued).
- `alasan_antrean`: `[]` when VALID, else one of `LUAR_AREA`, `WAJAH`
  (enrolled but mismatched — always wins over rostering reasons so a
  stranger's punch never hides behind a scheduling issue, and is decided
  before the roster-unreadable short-circuit), `TANPA_MASUK` (PULANG with no
  non-DITOLAK MASUK ≤ 18 h earlier), `TANPA_SHIFT`, `HARI_LIBUR`, `LUAR_SHIFT`
  (MASUK outside `[mulai - awalMenit, selesai]`; PULANG past
  `target + pulangLewatMenit`), `WAJAH_KOSONG` (never enrolled — always
  queues, an admin chore; the punch screen locks until Daftar Wajah).
  A `MENUNGGU` row always carries the crew's `alasan_kru`.
  `HARI_LIBUR` means the person's own schedule says off that day. A public
  holiday in the HRIS calendar never queues a punch; a VALID MASUK on it
  earns PH.
- `berubah` is the ADR-0007 `version` column (the build prompt's `berubah`
  name maps to it; see the migration note). Send it back on decisions.
- A night-shift PULANG within 18 h of MASUK belongs to the MASUK date.

### Riwayat item

`GET /hris/riwayat?bulan=2026-10` answers one user's month, server-assembled
so the PWA needs no `jadwal` module grant for the log screen:

```json
{
  "bulan": "2026-10", "dari": "2026-10-01", "sampai": "2026-10-31",
  "hari": [{"tanggal": "2026-10-20", "jenis": "kerja",
    "masuk": {…}, "pulang": {…}, "tanpaMasuk": false,
    "kalender": null, "shift": {"kode": "PAGI", "mulai": "08:00", "selesai": "17:00",
    "lintas_hari": false, "sumber": "JADWAL", "libur": 0}}],
  "ringkasan": {"terlambat": 1, "pulang_awal": 0, "absen": 0, "tidak_clock_in": 0,
    "hadir": 20, "menunggu": 0, "terjadwal": 22},
  "rosterTakTerbaca": false,
  "lokasi": [{"id": "01j…", "nama": "Outlet", "lat": -6.2, "lng": 106.8, "radius": 120, "aktif": true}]
}
```

- `jenis` per day: `kerja` (a non-DITOLAK MASUK or PULANG exists),
  `libur-umum` (HRIS calendar, never queued), `libur-jadwal` (own roster
  flag), `cuti`/`izin`/`off` (roster cell code), `absen` (a real shift cell
  with no punch), `tanpa-jadwal` (no cell at all), `tak-diketahui` (roster
  unreadable, or today still in progress), `masa-depan`.
- Counting follows the meal rule (P2): only VALID punches count
  (`terlambat` from `telat_menit`, `pulang_awal` from the flag, `hadir`
  distinct days); MENUNGGU is reported but never counted; DITOLAK is
  ignored. Today never counts `absen` or `tidak_clock_in`.
- `masuk`/`pulang` are the punch item shape above (latest non-DITOLAK per
  direction). `shift` is the `shiftFor` answer (`JADWAL`, `BAWAAN`, `null`,
  never `false` — unreadable days set `rosterTakTerbaca` instead).
- `user` defaults to the token holder; heads and HR may pass `user` for
  someone in scope (outside it is `403`). Bad `bulan` is `422`.

### me/today item

```json
{
  "siapa": {"id": "u-dwipa", "nama": "Dwipa"},
  "tgl": "2026-10-20", "waktuServer": 1760918400000, "jam": "09:00",
  "shift": {"kode": "PAGI", "mulai": "08:00", "selesai": "17:00",
            "lintas_hari": false, "sumber": "JADWAL", "libur": 0},
  "masuk": {…}|null, "pulang": {…}|null,
  "wajahTerdaftar": true, "wajahJumlah": 2,
  "lokasi": [{"id": "01j…", "nama": "Outlet", "lat": -6.2, "lng": 106.8, "radius": 120, "aktif": true}],
  "aturan": {"akurasiMaks": 100, "awalMenit": 180, "pulangLewatMenit": 180, "toleransiTelat": 5},
  "targetMenit": 540, "targetAsal": "ROSTER", "bolehPulang": "17:00",
  "lingkup": "sendiri",
  "sisaKemarin": {"tanggal_kerja": "2026-10-19", "arah": "PULANG", "masuk_jam": "22:04", "masuk_waktu": 1760898240000},
  "perhatian": [{"kode": "belum_masuk", "judul": "Belum absen masuk", "pesan": "Shift PAGI mulai 08:00.", "layar": "/absen", "tingkat": "penting"}]
}
```

`sisaKemarin` is the unclosed MASUK of a past work date (within 7 days):
the next PULANG tap auto-pairs to it while within 18h, silently. The crew
screen warns and offers a correction request instead of tapping blind.
`null` when nothing is open.

`shift` is `null` when unscheduled, `false` when the roster is unreadable.

`wajahJumlah` is the stored descriptor count (0 when not enrolled) so the
PWA can show "Lengkapi wajah" below 3.

`targetMenit` is the clock-out goal in minutes from the latest non-DITOLAK
MASUK (`null` without one); `targetAsal` identifies whether that goal came
from the roster snapshot (`ROSTER`), a per-person duration (`PER_ORANG`), the
default-shift snapshot (`SHIFT_BAWAAN`), or the fallback duration (`CADANGAN`).
`bolehPulang` is `"HH:MM"` WIB of the clock-out goal: the scheduled end for
`ROSTER`/`SHIFT_BAWAAN`, MASUK + target otherwise (`null` without a MASUK).
`tgl` is yesterday while a night shift that started yesterday is still open.
`aturan.koreksiMaksHari` tells the correction form how far back it may go. `aturan` carries the punch-screen thresholds so the
PWA can warn before sending (missing fields on an old server = defaults).

`perhatian[]` is computed on request (no push, no scheduler). Each item
carries `{kode, judul, pesan, layar, tingkat: 'info'|'penting'}`:

1. No MASUK X minutes after the shift starts (`perhatianTelatMenit`,
   default 15).
2. MASUK without PULANG X minutes after the shift ends
   (`perhatianPulangMenit`, default 60).
3. PH expiring within 14 days.
4. Requests waiting for your approval (Kepala Divisi or HR only).
5. Your request or queue decisions from the last 3 days.
6. A near Akademi or onboarding deadline.

### Settings

Defaults (`PUT /hris/settings` takes a partial map; unknown keys are `422`):

```json
{
  "awalMenit": 180, "toleransiTelat": 5,
  "akurasiMaks": 100, "pulangLewatMenit": 180,
  "durasiKerja": {"default": 540, "orang": {"u-dwipa": 480}},
  "tanpaShiftBoleh": false,
  "wajahAmbang": {
    "default": {"metric": "cosine", "ambang": 0.637},
    "sface-2021dec-int8": {"metric": "cosine", "ambang": 0.637}
  },
  "hr": ["u-admin"],
  "koreksiMaksHari": 7
}
```

`koreksiMaksHari` (1–31): how many days back crew may file an attendance
correction; older dates go to HR. A locked period (Uang Makan → Kunci) is
refused either way (`409 periode_terkunci`).

- `awalMenit` (0–720): how early a MASUK is still VALID. `akhirMenit` is
  removed: PUT still accepts it and ignores it (old PWA sends it), and
  stored rows stay in the DB but no longer surface in reads.
- `akurasiMaks` (10–1000 m): GPS accuracy ceiling for `dalam_area`.
- `pulangLewatMenit` (0–720): grace past the duration target before PULANG
  queues `LUAR_SHIFT`.
- `durasiKerja`: minutes 60–1440 each; `orang` keys are User ids.
- The unenrolled always queue `WAJAH_KOSONG` (no setting). `wajahWajib` was
  removed: PUT still accepts it and ignores it (old PWA sends it).
- `wajahAmbang` defaults follow the OpenCV SFace reference (similarity ≥
  0.363 → distance ≤ 0.637, cosine); HR may still override per model, and
  stored values always win. The `/dev/kalibrasi` screen (W6) shows the
  `wajah_dicek` score percentiles next to the on-device different-person
  test to propose a threshold.
- `hr`: extra HR staff by User id; module admins of `hris` are always HR.

## Errors

| Status | `error.code` | When |
|---|---|---|
| 401 | `unauthenticated` | No/bad token |
| 403 | `module_not_granted` | Token without `hris` |
| 403 | `forbidden` | Outside Lingkup (foreign rows, crew in queue, non-HR writes) |
| 404 | `not_found` | Unknown punch/face/photo/request/attachment |
| 409 | `already_enrolled` | Enrol while AKTIF (needs HR reset first) |
| 409 | `wajah_lengkap` | Top-up at 3 descriptors (needs HR reset to replace the face) |
| 409 | `wajah_milik_orang_lain` | Face matches another enrolled person (audited for HR) |
| 422 | `wajah_tidak_cocok` | Top-up outside the threshold of the stored face |
| 422 | `bukti_wajah_tidak_valid` | Signed face proof missing (while mandatory), expired, or failing signature/kid/model/photo checks |
| 409 | `version_conflict` | Stale `berubah` (`details.current`) |
| 409 | `already_decided` | Deciding a non-MENUNGGU punch or a final request |
| 429 | `terlalu_sering` | Face pre-check over the rate limit (`details.retryAfter`) |
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
| GET | `/hris/balances?user` | scoped | Always a list (HR without a filter sees their own); rows carry `nama` |
| GET | `/hris/balances/mutations?user` | scoped | Ledger history newest-first (`id,tanggal,jenis,delta,sumber,catatan,kedaluwarsa`); crew see their own |
| POST | `/hris/balances/adjust` | HR | `{user, jenis, delta, catatan}` — reason required; negative beyond balance is `422 saldo_kurang` |
| GET | `/hris/requests?user&status&jenis` | scoped | Request list (max 500); rows carry the requester's `nama` |
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

## B4 — perhatian (replaces push reminders and geofence)

No push devices, no geofence events, no scheduler. `GET /hris/me/today`
returns `perhatian[]` (see above); the PWA renders it as a "Perlu perhatian"
box. The punch body carries no `mock` field: the client sends only `lat`,
`lng`, `akurasi`, and the server treats a missing location as-is.

## B5 — manpower requests and CV screening

Recruitment (hris.md R1–R5). Owner = superadmin. Applicants auto-delete
after 12 months (`hris:harian`); HR can delete manually.

| Method | Path | Notes |
|---|---|---|
| GET | `/hris/permintaan?divisi&status` | Heads: own divisions; HR/owner: all |
| POST | `/hris/permintaan` | `{divisi, posisi, jumlah?, jenis?, alasan?, dibutuhkan_tgl?, kualifikasi?}` (head/HR). `201` |
| POST | `/hris/permintaan/{id}/decision` | `{status, nota?}` — HR step, then Owner step; approval opens a vacancy |
| DELETE | `/hris/permintaan/{id}` | Cancel before final (requester/HR) |
| GET | `/hris/lowongan?tutup?` | Vacancies with applicant counts |
| POST | `/hris/lowongan/{id}/tutup` | HR closes |
| GET | `/hris/pelamar?lowongan&tahap` | HR, contacts withheld |
| POST | `/hris/pelamar` | `{lowongan_id, nama, cv (base64 PDF/DOCX/TXT), cv_nama?, kontak?}` — extracts, redacts, encrypts contact. `201` |
| GET | `/hris/pelamar/{id}` | HR, contact decrypted |
| POST | `/hris/pelamar/{id}/tahap` | `{tahap, catatan?}` — forward-only; `DITERIMA` hires a draft (nonaktif) User |
| POST | `/hris/pelamar/{id}/screen` | AI advice (`?async=1` queues on `database`, needs a worker) |
| GET | `/hris/pelamar/{id}/screening` | Result history (prompts withheld) |
| DELETE | `/hris/pelamar/{id}` | HR permanent delete (file + rows) |

- Screening (`503 ai_belum_dikonfigurasi` until `HRIS_AI_URL`/`HRIS_AI_KEY`
  are set): redacted text + vacancy criteria → `{skor 0–100, alasan,
  ringkasan}`. The score is advice; HR moves the stages. Every result is
  stored with provider, model and prompt version.

## B6 — coordination tickets and onboarding

| Method | Path | Notes |
|---|---|---|
| GET | `/hris/coordination?status&divisi` | Scoped (own division traffic + own tickets; heads their divisions; HR all) |
| POST | `/hris/coordination` | `{judul, keDivisi, prioritas?, tenggat?, proyek?, detail?}` — from your division, status `Diminta`. `201` |
| GET | `/hris/coordination/{id}` | One ticket with the BD version (`berubah`) |
| PATCH | `/hris/coordination/{id}` | `{pelaksana?, status?, berubah}` — target head assigns; assignee/head/HR advance `Diminta → Diproses → Review → Selesai` (forward only; stale `berubah` is `409` with the live record) |
| GET | `/hris/design-requests` | Marketing → Konten requests, read-only |
| GET | `/hris/design-requests/{id}` | One request, read-only (reference bytes withheld) |
| GET | `/hris/onboarding/templates` | HR division templates |
| POST | `/hris/onboarding/templates` | `{divisi, langkah: [{kunci, judul, tenggat_hari?}], program_akademi_id?}`. `201` |
| GET | `/hris/onboarding?user` | Scoped checklists with progress |
| POST | `/hris/onboarding/start` | `{user, mulai, divisi?}` — steps from the template (or the O1 fallback), deadlines from `mulai`, Akademi program linked. `201` |
| POST | `/hris/onboarding/{id}/langkah/{kunci}` | `{selesai, catatan?}` — self/HR; `eval-*` takes HR/head |

- Ticket rows live in BD (`coord-requests`) and are touched only through
  `BdRecords` — same lock, same `RowSync`, same version guard as the BD
  panel. The assignee stores the legacy User id (stable across renames).
- Onboarding learning stays in Akademi (linked program id + title/deadline
  read, never copied); program progress rows are deliberately not written
  (no invented conventions in Akademi's store). Overdue steps join the
  daily reminders.

## E — Karyawan, org chart, Divisi and Jabatan (ADR-0012)

Who may call:

- **Pengelola**: HR (HRIS admins plus the `hr` list) and Pengelola Roster (admins of `jadwal`, Tim HRD). They read everything, including personal data, and add or change Karyawan.
- **HR**: Divisi and Jabatan writes.
- **Kepala Divisi**: reads the Karyawan of the Divisi they head (shift heads in Jadwal, kantor heads in HRIS) without personal data, and their own org subtree. No writes.
- **Crew**: gets `403` on every route below.

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/hris/kelola/karyawan` | `status=aktif\|keluar\|semua`, `divisi`, `q` | `aktif` (default) includes exits dated today or later |
| POST | `/hris/kelola/karyawan` | `nama`, `pin?` (4–8 digits, default 1111), `tim?`, `no_hp?`, `email?` + Karyawan fields | Creates the Office User through `AccountService`, then the record. Office errors (`name_taken`, `email_taken`, …) → `422` with that code |
| GET | `/hris/kelola/karyawan-calon` | — | Active Users without a record |
| GET | `/hris/kelola/karyawan/{userId}` | — | Detail + `riwayat[]` + `bawahan[]` |
| PATCH | `/hris/kelola/karyawan/{userId}` | Karyawan fields, `berubah`, `berlaku_dari?`, `catatan?` | Divisi/Jabatan/atasan/status changes write `riwayat`. Stale `berubah` → `409 conflict` |
| POST | `/hris/kelola/karyawan/{userId}/jadikan` | Karyawan fields | Existing User → Karyawan |
| POST | `/hris/kelola/karyawan/{userId}/keluar` | `tanggal`, `alasan` (RESIGN, KONTRAK_HABIS, PHK, LAINNYA), `catatan?` | `409 masih_punya_bawahan` (details.bawahan) or `409 masih_kepala_divisi`. A date ≤ today deactivates the User and deletes later roster cells at once; a later date is handled by `hris:harian` |
| POST | `/hris/kelola/karyawan/{userId}/batal-keluar` | — | Only while the User is still active |
| GET | `/hris/kelola/struktur` | — | Nodes `{user_id, nama, jabatan, level_urutan, divisi_id, divisi, atasan_user_id, atasan_otomatis}`; a head gets their subtree |
| GET/POST | `/hris/kelola/divisi` | `nama`, `jenis` (shift/kantor), `urutan?` | `kode` is generated (slug) and never changes |
| PATCH/DELETE | `/hris/kelola/divisi/{id}` | `nama?`, `jenis?` (only when empty), `urutan?`, `aktif?`, `berubah?` | DELETE = soft delete, `409 divisi_berisi` while members or heads remain |
| PUT | `/hris/kelola/divisi/{id}/kepala` | `user_ids[]` | Shift Divisi → Jadwal `heads`; kantor → `hris_divisi_kepala`. Heads must be employed Karyawan |
| POST | `/hris/kelola/divisi/{id}/pindahkan` | `ke`, `berlaku_dari?` | Moves every member (one history row each) |
| GET | `/hris/kelola/jabatan` | — | `{level[], jabatan[]}` |
| POST/PATCH/DELETE | `/hris/kelola/jabatan[/{id}]` | `nama`, `level_id`, `divisi_id?`, `urutan?`, `aktif?` | DELETE refused (`409 jabatan_dipakai`) while used |
| POST/PATCH/DELETE | `/hris/kelola/jabatan-level[/{id}]` | `nama`, `urutan?`, `aktif?` | DELETE refused (`409 level_dipakai`) while a Jabatan uses it |

**Karyawan fields:** `divisi_id`, `jabatan_id`, `atasan_user_id` (empty means the Divisi's Kepala Divisi; self, cycles and non-employees are refused), `status_kerja` (PROBATION/KONTRAK/TETAP/MAGANG), `nik` (16 digits, unique), `jenis_kelamin` (L/P), `tanggal_lahir`, `alamat`, `kontak_darurat_nama`, `kontak_darurat_hp`, `tanggal_gabung`, `akhir_percobaan`, `akhir_kontrak`.

**Karyawan item:** `user_id`, `nama`, `aktif` (Office login), `tim`, `divisi{id,kode,nama,jenis}|null`, `divisi_impor`, `jabatan{id,nama,level,level_urutan}|null`, `jabatan_impor`, `atasan_user_id`, `atasan{user_id,nama,otomatis}|null`, `status_kerja`, dates, `alasan_keluar`, `catatan_keluar`, `berubah`. For Pengelola it also carries `nik`, `jenis_kelamin`, `tanggal_lahir`, `alamat`, `kontak_darurat_*`, `no_hp` and `email`. Write responses add `peringatan[]` when the Jadwal placement or the Office HR columns could not be updated.

## P — crew profile and contact change requests with HR approval (issue #344)

Crew read their current profile from the server and propose contact
corrections; nothing official changes at submit. An Admin Modul HRIS
(superadmin `*` included) approves the whole patch or rejects it with a
readable note. The `hr` settings list, Kepala Divisi, Admin Jadwal and
announcement grantees get `403` on every route below.

Only these five fields can be requested: `email`, `no_hp` (both on the
Office User via `AccountRepository`), `alamat`, `kontak_darurat_nama`,
`kontak_darurat_hp` (on the Karyawan row). Anything else in `perubahan`
is a `422`; a diff that changes nothing is `tidak_ada_perubahan`.
`PUT /api/v1/me/contact` and the other Office apps keep their direct
behaviour on purpose: a contact changed elsewhere while a request waits
is detected as a `409 kontak_berubah` at approval, never silently
overwritten.

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/hris/me/profile` | — | Current profile: `kontak{email,no_hp,alamat,kontak_darurat_nama,kontak_darurat_hp}`, `editable_fields`, `revisi_profil`, `pengajuan_aktif` |
| GET | `/hris/me/profile-change-requests` | `status?`, `page?`, `per_page?` | Own history, newest first |
| POST | `/hris/me/profile-change-requests` | `perubahan{...}`, `revisi_profil`, `idempotency_key` | `201` MENUNGGU, official contact untouched; same key + payload replays (`200`), same key + other payload is `409`; stale `revisi_profil` is `409 profil_berubah` |
| GET | `/hris/me/profile-change-requests/{id}` | — | Own detail with `beda[{field,lama,baru,terkini,konflik}]`; another person's id is `404` |
| POST | `/hris/me/profile-change-requests/{id}/cancel` | `berubah` | Only the owner and only MENUNGGU; `409` on a stale version |
| GET | `/hris/kelola/perubahan-profil` | `status`=MENUNGGU, `divisi?`, `q?`, `user?`, `page?`, `per_page?` | Oldest first with an accurate `meta.total` |
| GET | `/hris/kelola/perubahan-profil/{id}` | — | Detail plus `apply_account_ok`, `apply_core_ok`, `apply_galat`, `apply_percobaan` while DIPROSES |
| POST | `/hris/kelola/perubahan-profil/{id}/decision` | `status` DISETUJUI/DITOLAK, `catatan?` (required on reject), `berubah`, `idempotency_key?` | Whole patch only; replaying the final status returns it, a different one is `409` |

**Apply strategy (two storages, no shared transaction):** `core` holds the
request and the Karyawan row, the legacy account database holds
email/no_hp. On approval the service first checks the requester is still
an active employee, the email is still free and the requested fields
still match the snapshot — all inside a row lock, so a failed check
leaves MENUNGGU. It then persists DIPROSES, writes the Account fields
through `AccountRepository::updateUser` (requested keys only; never
`saveUserCore`/`applyContact`, so PIN/active/name never move), verifies
on read-back (a missing legacy email column fails visibly as
`email_tak_tersimpan` instead of a false success), writes the Karyawan
fields, verifies, and only then marks DISETUJUI. A crash in between
leaves a DIPROSES row with `apply_galat` naming the failed target only
(no PII in audit/logs); the same decision endpoint retries it,
skipping targets that already match. DIPROSES accepts only a DISETUJUI
retry; rejecting it is `409 sudah_diproses`.

**Release:** the `hris_perubahan_profil` migration is additive on `core`
(no legacy DDL). Ship the schema before the API, then the HRIS frontend.

**Side effects:**
- A write keeps the Jadwal Penempatan Divisi in line: a shift Divisi uses its kode, and anything else uses `nonshift`.
- A write mirrors Jabatan, status kerja (Talenta words), join date and the Divisi name into the Office User's HR columns.
- The env switch `HRIS_HANYA_KARYAWAN` (default `false`) limits HRIS Akses Bawaan to Users with a record (E10).

## J — master shift and block shifts (ADR-0012)

The shift definitions stay in the Jadwal setting (`shifts`), so the old panels keep working. HRIS adds three keys to a definition, and the old screens ignore them:

- `blok` `[{m,s},…]`: two or more blocks. `m`/`s` then span from the first start to the last end.
- `divisi` `[kode]`: shift Divisi only. When absent, the shift is for every Divisi.
- `aktif` `0`: hides the shift from new roster cells.

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/hris/kelola/shift` | — | Any HRIS user. Item: `kode, nama, warna, mulai, selesai, blok[{mulai,selesai}], libur, divisi[], aktif, urutan, terkunci` |
| POST | `/hris/kelola/shift` | `nama`, `warna` (#RRGGBB), `blok[{mulai,selesai}]` (1–3, in order, only the last may pass midnight), `divisi[]?`, `libur?` | HR. `kode` = the name in upper case without spaces (`Split Malam` → `SPLITMALAM`), suffixed on a clash |
| PATCH | `/hris/kelola/shift/{kode}` | same keys, plus `urutan?` and `aktif?` | HR. OFF/IZIN/CUTI/LAIN: only name, colour and order (`409 shift_terkunci`) |
| DELETE | `/hris/kelola/shift/{kode}` | — | HR. Refused for locked shifts and for any code a roster cell ever used (`409 shift_dipakai`: deactivate it instead) |

**Block punches (J4):**
- Every punch carries `blok` (1-based).
- A MASUK belongs to the block its time falls in; the boundary is the middle of the gap.
- A PULANG belongs to the block of the open MASUK it closes. Once a block is closed, a PULANG in a later block is `TANPA_MASUK` for that block instead of overwriting.
- Each block snapshots its own hours (`shift_mulai`/`shift_selesai`), so lateness and the target are per block. The target is the block's own duration, never a per-person override.
- A MASUK of block *n* while block *n−1* has no PULANG sets `info.pulang_terlewat` on the MASUK of *n−1*.
- The meal allowance still counts the day once.
- A block shift ignores a per-cell time override.

**`GET /hris/me/today`** adds:
- `blokAktif`: the open block if one exists, else the block the clock is in.
- `ketukanHariIni[]`: every punch of the day.

`masuk`, `pulang`, `targetMenit` and `bolehPulang` now describe `blokAktif`. `shift.blok` lists the blocks, and is `[]` for a plain shift.

### Roster (J6–J8)

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/hris/roster` | `bulan=YYYY-MM`, `divisi` (shift kode) | HR: any Divisi. Kepala Divisi: their own. Returns `{bulan, divisi, hari[], kru[{user_id,nama,jabatan}], sel[{u,d,t,m,s,n}], shift[], aturan, bolehDivisi}`. `shift` = active shifts for this Divisi plus any code already used in the month |
| PUT | `/hris/roster` | `isi[{u,d,t,m?,s?,n?}]`, `hapus[{u,d}]`, `catatan?` (≤ 255, shown to HR) | Every row is checked: Divisi (`403`), a Kepala Divisi only from today on (`403 tanggal_lewat`; past dates are HR's), no date in a locked period (`409 periode_terkunci`), shift known/active/for this Divisi (`422`); block shifts drop `m`/`s`. Answer `{isi, hapus, berubah, peringatan[]}`. Warnings never block. A save that changed a cell is logged in `hris_roster_simpan` (published directly, no HR approval) |
| GET | `/hris/roster/rekap` | `bulan`, `divisi` | Per crew member: `per_shift{kode:n}`, `hari_kerja`, `hari_libur`, `kosong` |
| GET/PUT | `/hris/roster/aturan` | `maksBeruntun` (1–31), `jedaMin` (hours, 0–24) | PUT is HR only; stored in the Jadwal setting |
| GET | `/hris/me/jadwal` | `dari`, `sampai` (≤ 62 days) | Own cells `{tanggal, kode, nama, warna, mulai, selesai, blok[], libur}` |

**Default shift (J8):** `hris_karyawan.shift_bawaan` (set through `PATCH /hris/kelola/karyawan/{userId}`; it must be an active working shift). Attendance reads it before the old `shiftKru` map. `hris:impor-karyawan` fills empty values from `shiftKru`. `GET /hris/kelola/karyawan/{userId}` also returns `shift_lama` (the legacy `shiftKru` value, if any) as a transition hint; it is read-only and never written by HRIS.

## T — crew team roster, shift changes and head approval (issue #346)

Crew read the team roster and file one-person one-date shift changes;
the division head approves directly (HR fallback through Kelola).
CUTI/PH/IZIN keep their own flow and approvals in `HrisRequestService`.

Who may call:

- **Crew**: `tim/divisi`, `tim/roster`, own `tim/ubah-shift` rows, `tim/persetujuan` (only what they may decide: heads see their scope, plain crew get empty lists). No roster writes, no HR audit.
- **Head**: decides shift changes of their current division members (never self) through `tim/ubah-shift/{id}/putuskan`, plus their head-step CUTI/PH/IZIN through the existing `POST /hris/requests/{id}/decision`. Kantor and shift heads both resolve through the canonical HRIS map.
- **HR admin** (Admin Modul HRIS, superadmin `*`): decides fallback shift changes (division without a head, or the requester is a head themself) through the same putuskan endpoint, and reads `kelola/roster/riwayat`.

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/hris/tim/divisi` | — | Active divisions `{id,kode,nama,jenis}` for the filter |
| GET | `/hris/tim/roster` | `dari`, `sampai` (≤ 31 days), `divisi` (code or `semua`; default own, else `semua`) | Per day: `kru[{user_id,nama,jabatan,divisi,milik_sendiri,status,shift}]`, `jumlah{dijadwalkan,tidak_masuk,belum_diatur}`, `masalah[]` (unknown shift codes, excluded from counts) |
| GET | `/hris/tim/ubah-shift` | `status?`, `page?`, `per_page?` | Own history, newest first |
| POST | `/hris/tim/ubah-shift` | `tanggal_kerja`, `shift_baru`, `alasan`, `idempotency_key` | `201` MENUNGGU, official roster untouched; same key + payload replays, same key + other payload is `409` |
| GET | `/hris/tim/ubah-shift/{id}` | — | Own detail with frozen before/after plus the live cell and a conflict flag; another person's id is `404` |
| GET | `/hris/tim/persetujuan/ubah-shift/{id}` | — | Decision detail for the requester's current division head only (never self); a plain crew member or another head gets `403` |
| POST | `/hris/tim/ubah-shift/{id}/batal` | `berubah` | Only the owner and only MENUNGGU |
| GET | `/hris/tim/persetujuan` | — | `{ubah_shift[], koreksi[], pengajuan[]}` the caller may decide (heads only; plain crew get empty lists) |
| POST | `/hris/tim/ubah-shift/{id}/putuskan` | `status` DISETUJUI/DITOLAK, `catatan?` (required on reject), `berubah` | Head in scope (never self) or HR fallback; final replay is authorized and idempotent; `APPLYING` can only be resumed by its frozen approver using the latest version; cancel/reject or another approver gets `409` |
| GET | `/hris/kelola/ubah-shift/{id}` | — | HR shift change detail (same shape as the crew detail); Admin HRIS only |
| GET | `/hris/kelola/roster/aktivitas` | `bulan?` YYYY-MM, `divisi?`, `peran?` HR/KEPALA, `page?`, `per_page?` | `{simpan[]}` one row per roster save, newest first: `{id, waktu, aktor, aktor_user_id, peran, divisi[], tanggal_dari, tanggal_sampai, jumlah_isi, jumlah_hapus, jumlah_berubah, catatan}`; Admin HRIS only |
| GET | `/hris/kelola/roster/riwayat` | `tanggal_dari/sampai`, `waktu_dari/sampai`, `divisi?`, `user?`, `q?`, `sumber?`, `simpan_id?`, `page?`, `per_page?` | `{event[], menunggu_hr[]}`: append-only cell events with before/after snapshots plus shift changes waiting for the HR fallback decider |

**Team roster meaning (schedule, never attendance):** a cell with a valid working shift is Dijadwalkan; a cell with a valid off/libur shift is Tidak masuk; no explicit cell is Belum diatur (shift bawaan never upgrades it; office/nonshift crew without cells stay Belum diatur). SPLIT counts once with both blocks; an end earlier than the start is marked `lintas_hari` on the work date. Unknown shift codes are listed under `masalah` with names and dates, never silently counted. Peer DTOs carry names, positions, divisions, dates, schedule status and working-shift detail only; nonworking/leave cells return `shift: null` to avoid revealing specific leave categories.

**Ubah shift rules:** one explicit working source cell; target must be a working active shift valid for the requester's division (OFF/CUTI/IZIN/SAKIT/non-working excluded); no past dates, no started source or target shifts (effective cell override or first block bound on the work date, WIB), no punched dates — those get a message to contact HR instead of a silent queue. The target snapshot clears source-cell time overrides and uses the target shift definition. One active request per crew per date. A manual edit after submit is a `409 sel_berubah` with the live cell, never an overwrite.

**Apply strategy:** approval first commits `APPLYING` with the frozen approver identity, time and note. The finalization transaction locks the request while it checks/writes the cell and records its event. Jadwal uses a row-locked compare against the source fingerprint captured at submit; manual/batch/clear writers share the same InnoDB row locks and deterministic cell order. When Jadwal is on the legacy connection, `updated_by` carries `HRIS-UBAH-SHIFT:<request-id>` so a retry can prove the operation identity after a crash. When Jadwal shares `core`, the cell write, request finalization and event are in the same core transaction. A retry resumes only the frozen approver's approval; target equality alone never proves application. If a legacy manual SQL write removes the operation marker while the request is still `APPLYING`, the system leaves it in that state for HR review instead of claiming success or allowing cancellation. Rejections and cancelled pendings never create cell events.

**Migration:** apply only `2026_10_24_090000_create_hris_ubah_shift_tables.php` on the `core` connection after reviewing `php artisan migrate:status`; it creates the HRIS request and event tables plus the source fingerprint. No legacy `jadwal_*` schema changes are required. Do not enable the feature before the migration is present.

## K — attendance corrections (koreksi absen)

Crew file a missing MASUK/PULANG for a past work date (max `koreksiMaksHari` days back, default 7)
with the clock time and reason; the division head approves directly (HR
fallback through Kelola). Approval writes the punch row; nothing lands in
`hris_ketukan` while MENUNGGU. One active request per crew per date and
direction; a recorded punch settles the date+direction first.

Who may call:

- **Crew**: own `tim/koreksi-absen` rows, plus `tim/persetujuan` (only what they may decide: heads see their scope, plain crew get empty lists).
- **Head**: decides corrections of their current division members (never self) through `tim/koreksi-absen/{id}/putuskan`.
- **HR admin** (Admin Modul HRIS, superadmin `*`): decides fallback corrections (division without a head, or the requester is a head themself) through `kelola/koreksi-absen/{id}/putuskan`, and reads `kelola/koreksi-absen`.

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/hris/tim/koreksi-absen` | `status?`, `page?`, `per_page?` | Own history, newest first |
| POST | `/hris/tim/koreksi-absen` | `tanggal_kerja`, `arah` MASUK/PULANG, `jam` HH:MM, `alasan`, `idempotency_key` | `201` MENUNGGU, official punches untouched; past dates only (max `koreksiMaksHari` days back, `422 terlalu_lama` with `details.maks_hari`; locked period `409 periode_terkunci`); same key + payload replays, same key + other payload is `409` |
| GET | `/hris/tim/koreksi-absen/{id}` | — | Own detail; another person's id is `404` |
| GET | `/hris/tim/persetujuan/koreksi-absen/{id}` | — | Decision detail for the requester's current division head only (never self); a plain crew member or another head gets `403` |
| POST | `/hris/tim/koreksi-absen/{id}/batal` | `berubah` | Only the owner and only MENUNGGU |
| POST | `/hris/tim/koreksi-absen/{id}/putuskan` | `status` DISETUJUI/DITOLAK, `catatan?` (required on reject), `berubah` | Head in scope (never self) or HR fallback; approval writes the punch row then finalizes in one transaction; a punch recorded in between is `409` |
| GET | `/hris/kelola/koreksi-absen` | `status?`, `page?`, `per_page?` | `{data[], meta{..., menunggu_hr[]}}`: every correction plus the ones waiting for the HR fallback decider; Admin HRIS only |
| GET | `/hris/kelola/koreksi-absen/{id}` | — | HR correction detail; Admin HRIS only |
| POST | `/hris/kelola/koreksi-absen/{id}/putuskan` | `status`, `catatan?`, `berubah` | HR fallback decision; same verdict semantics as the head route; Admin HRIS only |

**Koreksi rules:** past work dates only, at most `koreksiMaksHari` days back (older goes to HR directly), never inside a locked period (checked again on approval); clock time is WIB HH:MM converted to server epoch ms; the approved punch carries the request reason (`alasan_kru`), the decider (`diputus_oleh/at`, `nota`), status VALID (so the meal allowance, Kehadiran and PH count it; migration `2026_10_27_090000` turns earlier DISETUJUI punches VALID) and a roster snapshot when the date is scheduled (`shift_sumber` JADWAL, else NONE); rejections and cancelled pendings never create punches.

**Migration:** apply `2026_10_26_090000_create_hris_koreksi_absen.php` on the `core` connection after reviewing `php artisan migrate:status`; it creates only `hris_koreksi_absen`. Do not enable the feature before the migration is present.

**Audit boundary:** `hris_roster_event` starts at deploy (manual/batch/clear edits, approved shift changes, and finalized leave requests). It does not backfill past manual SQL or legacy panel writes; those stay out of scope and are documented as such.

## For laksamana-hris

`packages/api` generates types from `openapi.json` (tag `hris`). Web PWA:
login (name + PIN) → Beranda (`me/today` with `perhatian[]`) → enrol
(`faces/me` with consent) → punch (`punches` with camera + GPS). Kelola:
queue + decisions (send `berubah`, handle `409` by reloading), faces,
locations, settings. Face photos and descriptors never leave the server
except through the two HR photo streams.
