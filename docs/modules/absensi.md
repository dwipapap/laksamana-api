# absensi — attendance with face and GPS check

- **Legacy source:** `laksamana-office/absensi-mysql/`. The PWA frontend is `laksamana-office/absensi/` and calls `api/api.php`.
- **Legacy URL:** `/absensi/api/api.php`. It lives on its own subdomain (`absensi.laksamanamuda.id`), so on that host it resolves to `/api/api.php`. Register **both** paths.
- **Database:** `lakk5493_db_absensi` via connection `legacy_absensi`. There is no prod dump; the local copy was restored from the dev dump.
- **Timezone:** every date and time is WIB (UTC+7). The default GET action is `konteks`.

## Tables (created at runtime)

### `abs_lokasi` — allowed clock-in locations
- PK `id`.
- Columns: `nama`, `lat`, `lng`, `radius_m` (clamped to 30–2000), `aktif`, `updated_at`, `updated_by`.
- Rows are hard-deleted.

### `abs_wajah` — registered faces
- PK `subjek`, formatted `'USER:<id>'` or `'DW:<id>'`.
- Columns: `nama`, `descriptor` (JSON array of 128 floats — **never sent to the browser**), `foto` (MEDIUMTEXT data URI), `aktif`, `daftar_at`, `oleh`.

### `abs_punch` — clock-ins and clock-outs
- PK `id`. **UNIQUE (`subjek_tipe`, `subjek_id`, `tgl`, `arah`).**
- `subjek_tipe` is USER or DW; `tgl` is the **work date**; `arah` is MASUK (in) or PULANG (out).
- Other columns: `subjek_id`, `nama`, `waktu` (ms), `jam`, `lat`, `lng`, `akurasi_m`, `lokasi_id`, `jarak_m`, `dalam_area`, `wajah_skor`, `wajah_ok`, `shift_kode`, `shift_mulai`, `shift_selesai`, `shift_sumber`, `dalam_shift`, `status`, `sebab`, `alasan`, `foto`, `putus_at`, `putus_oleh`, `putus_nota`, `dibuat_at`.
- `shift_sumber` values: ROSTER, DW, NONE, TAK_TERBACA.
- `status` values: VALID, MENUNGGU, DITOLAK.

### `abs_setting` — one settings row
- `id` = 1, with `data` as a JSON blob.
- Keys: `toleransiTelat`, `awalMenit`, `akhirMenit`, `lemburMinMenit`, `wajahAmbang`, `wajahWajib`, `tanpaShiftBoleh`, `hr[]`.

## Access checks

| Gate | Rule |
|---|---|
| `wajib_masuk` | Valid session **and** the user's modules include `*` or `absensi`. |
| `wajib_hr` | `wajib_masuk`, **plus** the user is a module admin of `absensi` **or** their id is in `setting.hr`. An empty `hr` list means module admins only. |

## Actions

Responses use the `{ok, data}` envelope.

| Action | Method | Auth | Notes |
|---|---|---|---|
| `masuk` (`nama`, `pin`) | POST | open | Legacy forwarded this to the account `login`. Here, call `AccountService::login` in-process, then require module `absensi` and a token. Returns `{user}` including the token; the PWA stores it for 30 days as `lm_absensi_sesi`. |
| `konteks` | GET, default | session optional | Returns `lokasi`, `setting`, `waktuServer`. With a session, also `siapa{boleh,admin,hr}`, `shift`, `tgl`, `hariIni`, `wajahTerdaftar`. |
| `absen` (`arah`, `lat`, `lng`, `akurasi`, `descriptor[128]`, `foto`, `alasan`) | POST | masuk | Clock in or out. See "Clock-in/out rules" below. |
| `daftarWajah` (`tipe`, `id`, `nama`, `descriptor`, `foto`) | POST | a user may register their own face; HR may register anyone | Upsert into `abs_wajah`. |
| `hapusWajah` | POST | HR | Deletes a face. |
| `wajahDaftar` | GET | HR | Lists registered faces, without descriptors. |
| `antrean` | GET | HR | Punches with status MENUNGGU, including photo; limit 500. |
| `putusAbsen` (`id`, `status` VALID\|DITOLAK, `nota`) | POST | HR | Updates only while the row is still MENUNGGU. Returns `berubah`. |
| `rekap` (`dari`, `sampai`, `user?`, `tipe?`) | GET | masuk | Non-HR users are forced to their own records. Late minutes, overtime, early leave and duration are **computed on read and never stored**. Durations use the real difference between the two timestamps. |
| `simpanLokasi`, `hapusLokasi`, `simpanSetting` | POST | HR | Settings are saved as the single blob. |
| `ping`, `stats` | GET | open | |

### Clock-in/out rules (`absen`)

1. The subject is **always taken from the token**, and the time from the **server**.
2. The face is matched server-side: Euclidean distance compared against `wajahAmbang`.
3. Any of these reasons puts the punch in status MENUNGGU, and `alasan` is then required:
   - `LUAR_AREA`, `TANPA_SHIFT`, `HARI_LIBUR`, `LUAR_SHIFT`, `WAJAH`, `WAJAH_KOSONG`
4. **MASUK: the first one is kept.** A second MASUK returns `{duplikat: true}`.
5. **PULANG: the last one wins.** It overwrites via ON DUPLICATE KEY UPDATE and resets the `putus_*` columns.
6. **Work date of a PULANG:** the date of a MASUK within the previous 18 hours (this handles night shifts).

### Shift lookup (`shift_hari`)

Returns one of three states:

| Result | Meaning | `shift_sumber` |
|---|---|---|
| array | shift found | ROSTER or DW |
| `null` | not scheduled | NONE |
| `false` | the jadwal/dw module did not answer | `TAK_TERBACA` — **not** sent to the MENUNGGU queue |

- Legacy looked shifts up through `jadwal shiftHari` and `dw jadwalDW`. Here, call the Jadwal and Dw services directly.
- A daily worker (DW) with several shifts that day gets the shift nearest to the punch minute.

## v1 proposal

- `POST /api/v1/absensi/punches`
- `GET /api/v1/absensi/context`
- `GET /api/v1/absensi/recap`
- `GET /api/v1/absensi/queue`
- `POST /api/v1/absensi/punches/{id}/decision`
- `/api/v1/absensi/faces`
- `/api/v1/absensi/locations`
- `/api/v1/absensi/settings`
