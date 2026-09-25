# DW API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app — for the whole DW Panel. It covers every screen of `NAV_DEF` in `deploy/dw/index.html`.

- **Base URL:** `/api/v1/dw`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account must have module `dw`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Fields:** the laksamana-office app fields (`nama`, `hp`, `bayarJenis`, `dwId`, `tgl`, `m`, `s`, `usulan`, …) as stored. No renaming, so screens built for the old app map 1:1.
- **Roles** (same rules as the old backend, enforced server-side):
  - **HRD** — admin of `dw`, or listed in the setting's `hr`. While `hr` is empty, every module holder **except** division heads. Decides everything.
  - **Head** — heads a Divisi. Sees the full talent pool, requests for their own division, confirms attendance and names replacements for their own division's shifts, and watches (but never ticks) the payment marks. Never decides.
  - A rule violation is `403 forbidden` with the old message. An overlap the old API answers with `{saved:false}` is `409 overlap` with the conflict in `error.details`. A replacement clash the old API throws as an error stays `422 rejected` with the old message.

## Screen → endpoint map

| Screen (laksamana-office `NAV_DEF`) | Endpoints |
|---|---|
| Dashboard | `GET /overview` (one-call bootstrap: setting, talent pool, assignments, requests, `peran`) |
| Konfirmasi Kehadiran | `GET /assignments?status=DISETUJUI&from&to`, `POST /assignments/{id}/attendance`, `POST /assignments/{id}/replace` |
| Kalender Tamu | No DW endpoint: pax comes from the marketing/event/reservasi modules; DW context (quotas, approved shifts) from `GET /overview` + `GET /schedule` |
| Kalender DW | `GET /overview` (month range) or `GET /schedule?from&to` (approved shifts only) |
| Permintaan DW | `/requests` (create for the caller's division, history, cancel) |
| Antrean Pengajuan | `/requests` (decide, assign), `/assignments` (create, decide, bulk, delete) |
| Rekap Pegawai | Computed client-side from `GET /assignments?dwId&from&to` + `GET /workers/{id}` + `GET /settings` (tariffs). Rule, same as the old page: only `DISETUJUI` counts as scheduled; only non-`ALFA` counts as paid (`hadirDibayar`: `ALFA` unpaid, `''`/`HADIR`/`TELAT` paid) |
| Pembayaran | Computed client-side from `GET /assignments?from&to` (week) + `GET /workers` (destinations) + `GET /settings` (`tarif`, `bayarLunas` ticks); `POST /payments/marks` ticks transfers done |
| Database DW (talent pool) | `/workers` |
| Pengaturan | `GET /settings`, `PUT /settings`, `POST /admin/clear` (danger zone) |
| Hak Akses | `GET /settings` (`hr`, `akses`) plus the account roster; writes go through `PUT /settings` (admin only for `hr`/`akses`) |

## Reads

| Method | Path | Notes |
|---|---|---|
| GET | `/overview?from=&to=` | Setting, talent pool, assignments (in range **or** still `MENUNGGU`), requests (same rule) and `peran{hrd,head,lihat,admin,nama,divisi}`. Past `MENUNGGU` rows are swept to `KEDALUWARSA` on read, like the old backend. Phone and payment fields are included only for HRD/heads. |
| GET | `/schedule?from=&to=` | Approved shifts in range: `{rows[{id,dwId,nama,divisi,posisi,tgl,m,s,hadir}], dari, sampai}`. Deleted workers draw as `(DW dihapus)`. |

## Workers (talent pool)

Pekerja Harian are never Users: they never log in and are identified by phone number (`UNIQUE no_hp`, normalised to `08…`). Writes are HRD-only.

| Method | Path | Notes |
|---|---|---|
| GET | `/workers?status=&divisi=&q=` | `status` exact (`AKTIF`/`NONAKTIF`), `divisi` CSV membership, `q` substring of name or phone. Same redaction as `/overview`. |
| POST | `/workers` | Body = the worker (always creates; the server mints the id). Returns 201 `{saved, id, baru}`. A taken number returns 422 naming its holder. `PANTAU`→`AKTIF`, `BLOKIR`→`NONAKTIF`; `bayarBank` is kept only for `BANK`. |
| GET | `/workers/{id}` | 404 when missing. |
| PUT | `/workers/{id}` | Replaces the worker (404 when missing). |
| DELETE | `/workers/{id}` | Hard delete (404 when missing). Assignments survive as orphans. |

## Requests (head → HRD)

Way 1 (point at the person directly) lives under `/assignments`. Way 2 (request just the count, HRD picks who) lives here.

| Method | Path | Notes |
|---|---|---|
| GET | `/requests?divisi=&status=&from=&to=` | `status` comma-separated. Date rule mirrors `/overview` (in range or still `MENUNGGU`). |
| POST | `/requests` | Head of the `divisi`, or HRD. `usulan[]` (suggested worker ids) is filtered server-side to active workers within `jumlah`; conflicts are checked now and reported back in `bentrok` — the request is still saved. Returns 201 `{saved, row, bentrok}`. |
| GET | `/requests/{id}` | 404 when missing. |
| PUT | `/requests/{id}` | Edits the request (head of the `divisi`, or HRD). Editing returns it to `MENUNGGU` and clears the decision trail, but keeps the original requester (`dibuat_*`); the editor goes to `diubah_*`. 404 when missing. |
| POST | `/requests/{id}/decision` | `HRD`: any of `DISETUJUI,DITOLAK,MENUNGGU,BATAL`. A head may only send `BATAL` for their own division. |
| POST | `/requests/{id}/assign` | HRD only. `{dwIds[]}` — assigning approves at once (`DISETUJUI` + tagged with the request; the request turns `DISETUJUI`). Workers blocked by overlaps are reported in `tertahan`, never skipped silently. |
| DELETE | `/requests/{id}` | HRD, or the head of that division. Issued assignments survive, only the link is released. |

## Assignments (ajuan)

Creating always yields `MENUNGGU` (re-submitting an `id` returns the edited row to `MENUNGGU` for re-approval); only HRD decisions make `DISETUJUI`. One worker may hold several shifts a day — only overlapping **times** are refused (overnight shifts compare across ±1 day).

| Method | Path | Notes |
|---|---|---|
| GET | `/assignments?dwId=&divisi=&status=&from=&to=` | Same date rule as `/requests`. `status` comma-separated. |
| POST | `/assignments` | Head of the `divisi`, or HRD. `timpa: true` replaces the conflicting shift (shown first as `409`) by its id. Overlap without `timpa` is `409 overlap` with `{saved:false, bentrok}` in `error.details`. Returns 201. |
| GET | `/assignments/{id}` | 404 when missing. |
| POST | `/assignments/{id}/decision` | HRD only (`DISETUJUI,DITOLAK,MENUNGGU,BATAL`). |
| POST | `/assignments/decisions` | HRD only. `{ids[], status, nota}` in one transaction. |
| DELETE | `/assignments/{id}` | HRD only. |

## Attendance (Konfirmasi Kehadiran)

Who really came is known by whoever was on site: HRD, or the head of the row's division (read from the stored row, never the request). Only `DISETUJUI` rows can be marked — attendance on a `MENUNGGU` row is refused. `''` resets the row to unconfirmed.

| Method | Path | Notes |
|---|---|---|
| POST | `/assignments/{id}/attendance` | `{hadir: ''\|HADIR\|TELAT\|ALFA, nota?}`. 404 when missing. Unknown values are `422 rejected` with the old message. Returns `{saved, id}`. |
| POST | `/assignments/{id}/replace` | `{dwBaru, nota?}` — the approved worker is out, someone else comes. The old row turns `ALFA` (`Digantikan <name>`, history kept); the replacement is born as its own `DISETUJUI` + `HADIR` row carrying the same `permintaan_id`, so pay moves with no extra step. 404 when missing. Same-person, unknown or inactive stand-ins, and hour clashes are `422 rejected` with the old message. Returns `{saved, lama, baru}`. |

## Payments (Pembayaran)

Money is computed client-side — the module stores no pay logic in PHP. One shift pays its position's `tarif` plus the long-shift bonus when it runs longer than `jamDasar`; only non-`ALFA` shifts pay. Weekly transfer rows group by destination (`bayarJenis` + normalised number; rows without a number stand alone). The tick marks live in the setting blob, so they travel with every read:

| Method | Path | Notes |
|---|---|---|
| POST | `/payments/marks` | HRD only. `{senin (week Monday, YYYY-MM-DD), kunci (destination key), nyala?}`. Touches one `bayarLunas` key read-modify-write under `SELECT … FOR UPDATE` — concurrent tickers never overwrite each other's tariffs. Returns `{saved, kunci: 'senin\|kunci', nyala}`. Read the marks from `GET /settings` (`bayarLunas{"senin\|kunci":{at,oleh}}`). |

## Settings (Pengaturan, Hak Akses, danger zone)

The request body of `PUT /settings` **is** the setting blob: `tarif{posisi: rupiah}`, `jam[]` presets, `posisiDivisi{divisi: [posisi…]}`, `kuota{divisi: {biasa, weekend, event}}`, `jamDasar`, `jamBatas`, `tambahanPanjang`, `hr[userId…]`, `akses{}`, `bayarLunas{}`. HRD sets tariffs, quotas, default hours and position maps. The `hr` list and the `akses` matrix are kept from the stored value unless the caller is a module admin — kept as-is, so a never-set key stays unset.

| Method | Path | Notes |
|---|---|---|
| GET | `/settings` | The whole blob (any module holder; screens decide what to show from `peran`). |
| PUT | `/settings` | HRD only (admin-ness decides `hr`/`akses` preservation). Returns `{saved, ts}`. |
| POST | `/admin/clear` | Module admin only (`module:dw,admin`) plus the typed `'HAPUS SEMUA'` confirmation (wrong keyword is `422`). `{pekerja?}` — empties every head request and assignment in one transaction (talent pool only with `pekerja: true`; the setting blob stays). Returns `{cleared, ajuan, permintaan, pekerja, ikutPekerja, oleh, ts}`. |

## Concurrency

Like the old app, writes are last-wins per row with the overlap guards above — the module has no version column (unlike marketing's `updatedAt`), so v1 adds none. Two HRDs assigning the same night both succeed only for disjoint workers; the conflict loser lands in `tertahan` (assign) or `409` (create). Payment ticks serialise on the setting row lock.

## For the jadwal/absensi ports

`GET /schedule` is the HTTP form, but same-process callers must use `App\Modules\Dw\Services\DwService::scheduleRange($dari, $sampai)` in-process — no HTTP hop, same minimal columns. The old open `jadwalDW` compat route stays until the last browser client migrates.
