# dw — Daily Worker

- Legacy source: `laksamana-office/dw-mysql/` (`api.php`, `lib_dw_mysql.php`, `lib_sesi.php`)
- Legacy URL: `/dw-api-mysql/api.php`
- Database: `lakk5493_db_dw`, connection `legacy_dw`
- Frontend: `deploy/dw/index.html`
- Called by: jadwal frontend and absensi (`jadwalDW`)
- Read `laksamana-office/CLAUDE.md`, the DW sections (two sets of dated notes: 14–19 Aug and 15–16 Sep 2026), before porting.

## Tables (live; created at runtime — `schema.sql` is out of date)

| table | key | columns / notes |
|---|---|---|
| `dw_pekerja` (workers) | PK `id`, **UNIQUE `no_hp`** | nama, no_hp (normalised to 08…), pin (unused), gender, area, bank (legacy), bayar_jenis (BANK / GOPAY / DANA), bayar_bank, bayar_nomor, bayar_nama. **divisi VARCHAR(120) CSV, first value is the primary one.** posisi VARCHAR(240) CSV, skill, status (AKTIF / NONAKTIF; legacy PANTAU / BLOKIR), catatan, dibuat / updated_at / oleh |
| `dw_ajuan` (shift assignments) | PK `id`; plain index `idx_ajuan_orang_tgl` | dw_id, tgl, jam_mulai, jam_selesai, divisi (**single value**), posisi, catatan, status (MENUNGGU / DISETUJUI / DITOLAK / BATAL / KEDALUWARSA), dibuat_at / oleh, putus_at / oleh / nota, hadir ('' / HADIR / TELAT / ALFA), hadir_nota / oleh / at, permintaan_id. The old unique key (dw_id, tgl) was dropped: a worker may have several shifts a day, and overlaps are blocked in code. |
| `dw_permintaan` (head requests) | PK `id` | divisi, tgl, jam_mulai, jam_selesai, posisi, jumlah, catatan, status, dibuat_* / putus_*, diubah_at / oleh, **usulan** (CSV of suggested worker ids). "How many already assigned" is COUNTED from `dw_ajuan.permintaan_id`. |
| `dw_setting` | id = 1, `data` JSON | tarif, jamDasar, tambahanPanjang, jamBatas, posisiDivisi, kuota, `hr[]`, `akses`, `bayarLunas{'senin|kunci':{at,oleh}}` |
| `dw_login_gagal`, `dw_sesi` | — | legacy, unused — ignore |

## Roles (in api.php)

| role | who |
|---|---|
| `wajib_office` | session + module `dw` (label "Roster · Daily Worker") |
| `dw_hrd` | admin of `dw`, OR the user id is in `setting.hr`. **If `hr` is empty:** every module holder EXCEPT division heads. |
| `dw_head` | `whoami.headDivisi` is non-empty |
| `wajib_minta(divisi)` | HRD, or head of that division |
| `wajib_hadir(id)` | as `wajib_minta`, using the division **read from the DB row** — never from the request |
| `wajib_admin` | admin of `dw` |
| `dw_boleh_lihat` | HRD or head (controls whether phone and payment fields are returned) |

## Actions (`{ok, data}` envelope)

| action | auth | notes |
|---|---|---|
| `getAll` (`dari`, `sampai`) GET | office | Runs `tutup_kedaluwarsa` first: past-dated `MENUNGGU` rows become `KEDALUWARSA` with `putus_oleh = '(sistem)'`; today is computed in WIB. Returns setting, pekerja (HP and bayar fields only for HRD / head), ajuan (in range OR `MENUNGGU`), permintaan (same rule), and `peran{hrd,head,lihat,admin,nama,divisi}`. |
| `jadwalDW` (`dari`, `sampai`) GET | **OPEN** | `DISETUJUI` rows only: `{id,dwId,nama,divisi,posisi,tgl,m,s,hadir}` — no phone numbers |
| `simpanPekerja` (`row`) | HRD | Upsert. A duplicate `no_hp` returns a readable error. |
| `hapusPekerja` (`id`) | HRD | Hard delete |
| `simpanAjuan` (`row{dwId,tgl,m,s,divisi,posisi,catatan,timpa,id}`) | minta(divisi) | Status forced to `MENUNGGU`. On an overlap (`bentrok_ajuan_row` checks ±1 day because of overnight shifts) returns `{saved:false, bentrok}`. With `timpa`, overwrites the conflicting row **by id**. divisi / posisi inherited from the worker use `csv_utama()` (primary value only). |
| `simpanPermintaan` (`row`) | minta | `usulan` filtered to existing, non-`NONAKTIF` workers and trimmed to `jumlah` |
| `putusPermintaan` (`id`, `status`, `nota`) | HRD; a head may send `BATAL` for their own division | |
| `tugaskanDW` (`permintaanId`, `dwIds[]`) | HRD | For each worker: `simpan_ajuan`, then UPDATE to `DISETUJUI` with `permintaan_id`; the request becomes `DISETUJUI`. Returns `tertahan[]` for workers blocked by overlaps. |
| `hapusPermintaan` | HRD or head of that division | Clears `permintaan_id` on linked shifts, then DELETE |
| `putusAjuan` / `putusBanyak` | HRD | `putusBanyak` runs in ONE transaction |
| `hapusAjuan` | HRD | DELETE |
| `simpanHadir` (`id`, `hadir`, `nota`) | wajib_hadir | Only on `DISETUJUI` rows. `''` resets to "not confirmed". |
| `gantiOrang` (`id`, `dwBaru`, `nota`) | wajib_hadir | Transaction: the old row becomes `ALFA` ("Digantikan <nama>"); a NEW row is inserted as `DISETUJUI` + `HADIR` with the same `permintaan_id`. The replacement's hours are overlap-checked. Named placeholders must each be unique (`:t1`, `:t2`, `:t3`). |
| `tandaiBayar` (`senin`, `kunci`, `nyala`) | HRD | Read-modify-write of only `bayarLunas`, `SELECT … FOR UPDATE` |
| `simpanSetting` (`data`) | HRD | A non-admin caller cannot change `hr` or `akses`: both are kept from the stored value. |
| `kosongkanSemua` (`konfirmasi: 'HAPUS SEMUA'`, `pekerja?`) | admin | |
| `ping`, `stats` | open | |

Other rules:

- "Who decided" names always come from the session.
- **No tariffs in PHP.** Money is computed only by the frontend (`hadirDibayar`: `ALFA` is unpaid, `''` / `HADIR` / `TELAT` are paid).

## v1 proposal

| endpoint | notes |
|---|---|
| `/api/v1/dw/workers` | |
| `/api/v1/dw/requests` | head requests |
| `/api/v1/dw/assignments` | ajuan |
| `/api/v1/dw/assignments/{id}/attendance` | |
| `/api/v1/dw/assignments/{id}/replace` | |
| `/api/v1/dw/settings` | |
| `GET /api/v1/dw/schedule?from=&to=` | = `jadwalDW` |
