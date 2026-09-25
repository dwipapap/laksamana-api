# Absensi API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app — for the whole Absensi PWA (`absensi/index.html`). It covers every screen of `HALAMAN` in that file.

- **Base URL:** `/api/v1/absensi`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/absensi/session {nama, pin}` (the account login plus the absensi-module check, like the old `masuk`) or the shared `POST /api/v1/auth/login`. The account must have module `absensi`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** the PWA's field names (`waktuServer`, `siapa`, `wajahTerdaftar`, `dalamArea`, `wajahSkor`, …) as stored. No renaming, so screens built for the old app map 1:1.
- **Roles** (same rules as the old backend, enforced server-side):
  - **HR** — admin of `absensi`, or listed in the setting's `hr`. While `hr` is empty, module admins only — never everybody. Decides queued punches, manages faces (others'), locations and settings.
  - **Crew** — holds the module. Punches (always as themselves — the subject comes from the token, never the request), enrols their own face, reads their own recap.
  - A rule violation is `403 forbidden` with the old message. Other rejections (bad direction, missing reason, unknown decision, bad face data) are `422 rejected` with the old message. A repeated MASUK answers `200 {duplikat:true}` — tapping twice on a slow signal is not an error.
- **Only punches are stored.** Late (`telat`), overtime (`lembur`), early leave (`cepat`) and duration (`durasi`) are computed on read from punches + shifts and never stored — shifts may be tidied after the fact and approvals may land days later. `DITOLAK` punches count as nothing; `MENUNGGU` punches still show their numbers, flagged (`menunggu: 1`).

## Screen → endpoint map

| Screen (`HALAMAN` in `absensi/index.html`) | Endpoints |
|---|---|
| Absen | `POST /session` (login, token kept 30 days as `lm_absensi_sesi`), `GET /context` (one-call bootstrap: `lokasi`, `setting`, `waktuServer`, plus `siapa{boleh,admin,hr}`, `shift`, `tgl`, `hariIni`, `wajahTerdaftar`), `POST /punches` |
| Riwayat | `GET /recap?from&to` (crew are forced to their own rows) |
| Antrean (HR) | `GET /queue`, `POST /punches/{id}/decision` |
| Wajah | `POST /faces` (own face; HR anyone's), `GET /faces` + `DELETE /faces` (HR) |
| Atur (HR) | `/locations`, `/settings` |

## Session

| Method | Path | Notes |
|---|---|---|
| POST | `/session` | `{nama, pin}` → `{user}` incl. the token. `422 rejected`: `Nama dan PIN wajib diisi.` / `Nama atau PIN salah.` / `Akun ini belum diberi akses modul Absensi. Minta admin membukanya di Office.` |

## Context & punches

| Method | Path | Notes |
|---|---|---|
| GET | `/context` | `lokasi` (active only), `setting` (defaults merged), `waktuServer` (ms), `siapa`, `shift` (array, `null` = unscheduled, `false` = roster unreadable), `tgl`, `hariIni`, `wajahTerdaftar` (1/0). |
| POST | `/punches` | `{arah: MASUK\|PULANG, lat, lng, akurasi, descriptor[128], foto, alasan}`. The subject is always the token holder; the time always the server's. Returns 201 `{duplikat:false, punch, wajahTerdaftar}`, or 200 `{duplikat:true, punch}` for a repeated MASUK. A PULANG overwrites the day's PULANG (last wins) and clears any decision trail. |

The punch rules, same as the old backend:

- **Face** is matched server-side (euclidean distance vs `wajahAmbang`, default 0.45). Whoever is enrolled must match; the unenrolled are only stopped when `wajahWajib` is on. Descriptors never leave the server.
- **GPS**: nearest registered location (haversine, unpinned 0,0 locations skipped); no locations at all means "not set up", not "outside". Radius is clamped to 30–2000 m.
- **Queue reasons** (`MENUNGGU`, `alasan` then required), in order: `LUAR_AREA`, `TANPA_SHIFT` (unless `tanpaShiftBoleh`), `HARI_LIBUR`, `LUAR_SHIFT` (shift ± `awalMenit`/`akhirMenit`, past midnight understood), `WAJAH` (enrolled but strange — HR suspicion) / `WAJAH_KOSONG` (never enrolled — an admin chore). An unreadable roster (`TAK_TERBACA`) never queues by itself.
- **Work date** of a PULANG is the date of a MASUK within the previous 18 h (night shifts).

## Recap

| Method | Path | Notes |
|---|---|---|
| GET | `/recap?from&to&user&tipe` | `{dari, sampai, hari[{tipe,uid,nama,tgl,masuk,pulang,hitung{telat,lembur,cepat,durasi,lengkap},shift,menunggu}], setting}`. Non-HR callers are forced to their own `USER` rows. Late counts past `toleransiTelat`; overtime past `lemburMinMenit`; the checkout derives from the real elapsed work, so a morning shift ending past midnight counts as overtime, not early leave. |

## Queue (HR)

| Method | Path | Notes |
|---|---|---|
| GET | `/queue` | Punches with status `MENUNGGU`, longest-waiting first, including `foto`. |
| POST | `/punches/{id}/decision` | `{status: VALID\|DITOLAK, nota}` → `{berubah: 1\|0}`. Only while still `MENUNGGU`: a second decider is told (`berubah: 0`), not silently believed. |

## Faces

| Method | Path | Notes |
|---|---|---|
| GET | `/faces` | HR only. Registered faces **without** descriptors (`{tipe,id,nama,aktif,ada,at,oleh}`). |
| POST | `/faces` | `{tipe, id, nama, descriptor[128], foto}` → 201 `{tersimpan:true}`. Your own, or anyone's when HR. |
| DELETE | `/faces?tipe=&id=` | HR only → `{hapus: n}` (hard delete). |

## Locations (HR writes)

| Method | Path | Notes |
|---|---|---|
| GET | `/locations` | All locations (active and not). |
| POST | `/locations` | `{id?, nama, lat, lng, radius, aktif}` → 201 `{id}`. Creates or replaces; `nama` required; radius clamped to 30–2000 m. |
| DELETE | `/locations/{id}` | Hard delete → `{hapus: n}`. |

## Settings (HR)

| Method | Path | Notes |
|---|---|---|
| GET | `/settings` | The single blob with defaults merged (`toleransiTelat`, `awalMenit`, `akhirMenit`, `lemburMinMenit`, `wajahAmbang`, `wajahWajib`, `tanpaShiftBoleh`, `hr[]`). |
| PUT | `/settings` | `{data}` → the saved blob. |
