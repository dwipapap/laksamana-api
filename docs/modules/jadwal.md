# jadwal — Jadwal Shift (shift roster) — DONE

Ported: `app/Modules/Jadwal/Services/JadwalService.php` (+ HeadDirectory), legacy controller, `/api/v1/jadwal/*`, tests `tests/Feature/Jadwal`, parity `tools/parity/cases/jadwal.json` (35/35). Note: legacy `getAll`/`shiftHari` rows have no ORDER BY — parity compares them unordered.

- Legacy source: `laksamana-office/jadwal-mysql/` (`api.php`, `lib_jadwal_mysql.php`, `lib_sesi.php`)
- Legacy URL: `/jadwal-api-mysql/api.php`
- Database: `lakk5493_db_jadwal`, connection `legacy_jadwal`
- Frontend: `deploy/jadwal/index.html`
- Called by other modules: account (`headIds`), absensi (`shiftHari`), dw (`headIds`, reached through account's whoami)
- Already in Laravel: `app/Modules/Jadwal/Services/HeadDirectory.php` (the `head_ids()` logic, 60-second cache)

## Tables (live schema, created at runtime in legacy)

| table | key | columns |
|---|---|---|
| `jadwal_sel` | PK (`user_id`, `tgl` DATE) | shift, jam_mulai, jam_selesai, catatan, updated_at (ms), updated_by. **An empty cell means no row.** |
| `jadwal_pengajuan` | PK `id`; idx user_id, status | jenis, tgl_mulai, tgl_selesai, alasan, status (`MENUNGGU`, `MENUNGGU_HRD`, `DISETUJUI`, `DITOLAK`), dibuat_*, putus_*, shift, jam_mulai, jam_selesai, head_at, head_oleh |
| `jadwal_setting` | id = 1 | `data` JSON blob with keys: `shifts`, `heads{div:[uid]}`, `divOverride`, `jabatan`, `shiftKru`, `template` |

There is no employee table. `user_id` is the Office account id.

## Actions

Response envelope: `{ok, data}` / `{ok:false, error}`, always HTTP 200.

| action | method | auth | behaviour |
|---|---|---|---|
| `getAll` (`dari`, `sampai`, `sesi`) | GET | session + module `jadwal` (label "Roster · Jadwal Shift") | Returns `{setting, sel:[{u,d,t,m,s,n}], pengajuan[]}`. `pengajuan` = all still in progress (`MENUNGGU`, `MENUNGGU_HRD`) plus the 200 most recently decided. |
| `shiftHari` (`user`, `dari`, `sampai`) | GET/POST | **OPEN** (called by absensi) | Returns `{dari, sampai, rows:[{u,d,t,m,s,libur}]}`. Blank times are filled from the shift defaults in `setting.shifts`. |
| `headIds` | GET | **OPEN** | `{heads:{uid:[div]}}`. Use `HeadDirectory::compute()`. |
| `ping`, `stats` | GET | open | |
| `probeTulis` | any | open | A no-op write transaction; the CI deploy check uses it. |
| `simpanSel` (`rows[{u,d,t,m,s,n}]`, `hapus[{u,d}]`) | POST | session; **every row** checked by `jdw_wajib_boleh_baris` (see below) | One transaction: upsert rows, then DELETE the `hapus` cells. Dates validated with `checkdate`. `updated_by` = the session name. |
| `simpanSetting` (`data`) | POST | module admin of `jadwal` | Upserts the blob. Empty maps are forced to `{}` via `jdw_peta_objek` — keep them as objects. |
| `simpanPengajuan` (`row`) | POST | session | `userId` is forced to the caller unless admin. `status` is forced to `MENUNGGU`. Single date: `sampai` = `dari`. |
| `putusPengajuan` (`id`, `status`, `nota`) | POST | head / admin (= HRD) | Two steps, **enforced server-side**. See "Two-step approval" below. |
| `hapusPengajuan` (`id`) | POST | the requester or a head | Hard DELETE. |
| `kosongkanSemua` (`konfirmasi: 'HAPUS SEMUA'`) | POST | admin | Deletes every cell and every request; settings are kept. |

### Two-step approval (`putusPengajuan`)

1. **Head step.** Moves `MENUNGGU` → `MENUNGGU_HRD` and writes `head_at` / `head_oleh`.
2. **HRD step.** Only a module admin may move `MENUNGGU_HRD` → `DISETUJUI`.
3. **Rejection.** Either step may set `DITOLAK`.
4. **No head in the division.** HRD may do step 1 only when the request's division has no head at all (`jdw_div_punya_head`).

## Rules to preserve

- **Who may write a row** (`jdw_wajib_boleh_baris`): a module admin, or anyone while no heads are configured yet, or the head of that crew member's division.
- **Division of a crew member:** `setting.divOverride[uid]` first. Otherwise, keywords in the Office `keterangan` field, using the synonym list (`kitchen`/`dapur`, `bar`/`bartender`, `floor`/`service`/`waiter`/…, `cashier`/`kasir`); `office`/`kantor` means a non-shift worker. The roster comes from `Sesi::roster()`.
- **Names written to `*_oleh` columns** come from the session.
- **Rejection messages** use the `sesi_tidak_sah:` / `tanpa_modul:` / `tidak_berhak:` prefixes.

## v1 proposal

| endpoint | behaviour |
|---|---|
| `GET /api/v1/jadwal/cells?from=&to=` | |
| `PUT /api/v1/jadwal/cells` (batch) | |
| `GET /api/v1/jadwal/shifts?user=&from=&to=` | |
| `GET/POST /api/v1/jadwal/requests` | |
| `POST /api/v1/jadwal/requests/{id}/decision` | |
| `GET /api/v1/jadwal/settings` | |
| `PUT /api/v1/jadwal/settings` | admin only |
| `GET /api/v1/jadwal/heads` | |
