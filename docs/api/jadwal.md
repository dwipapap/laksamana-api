# Jadwal API v1 — contract

This is the contract for **laksamana-office-vue**, the old laksamana-office if it migrates, and any other app.

- **Base URL:** `/api/v1/jadwal`
- **Auth:** `Authorization: Bearer <token>`, obtained from `POST /api/v1/auth/login {login, pin}`. The account must have module `jadwal`.
- **Envelope:**
  - success: `{data, meta?}`
  - failure: `{error: {code, message, details?}}`
- **Permission rule:** a Divisi schedule is written by that Divisi's Kepala Divisi; Admin Modul (= HRD) may write everything. Checked per row. Violations return 403 `forbidden`.

## Cells (schedule)

Cell fields are the legacy `jadwal_sel` columns, abbreviated as in the old app:

| key | column | notes |
|---|---|---|
| `u` | `user_id` | Office account id of the crew member |
| `d` | `tgl` | `YYYY-MM-DD` |
| `t` | `shift` | shift code, max 16 chars |
| `m` | `jam_mulai` | start time `HH:MM`; anything else is stored as `''` (same as legacy `jam_valid`) |
| `s` | `jam_selesai` | end time, same rule as `m` |
| `n` | `catatan` | note, max 120 chars |

| Method | Path | Notes |
|---|---|---|
| GET | `/cells?from=&to=` | `data` = `[{u,d,t,m,s,n}]` in range. `meta` = `{from, to}`. |
| PUT | `/cells` | Body `{cells: [{u,d,t,m?,s?,n?}], clear: [{u,d}]}`. Upserts `cells`, deletes `clear` cells, in one transaction. `m/s` accept `HH:MM` (max 5 chars); `n` max 120 chars. Returns `{saved, isi, hapus, ts}`. Behaves exactly like the legacy `simpanSel` rows. |
| DELETE | `/cells` | Wipe everything (Admin Modul only). Body `{konfirmasi: 'HAPUS SEMUA'}` — any other value returns 422 and nothing is deleted. Deletes all cells and all requests, keeps settings. Returns `{cleared, sel, pengajuan, oleh, ts}`. Same rules as the legacy `kosongkanSemua`. |

## Shifts (read model for absensi and other modules)

| Method | Path | Returns |
|---|---|---|
| GET | `/shifts?user=&from=&to=` | `{dari, sampai, rows: [{u,d,t,m,s,libur}]}`. Blank times are filled from the shift defaults in settings. `user` empty = all crew. |
| GET | `/dw-schedule?from=&to=` | Approved DW shifts in range for the "DAILY WORKER" block: the same `DwService::scheduleRange` read as `GET /dw/schedule` (`{rows: [{id,dwId,nama,divisi,posisi,tgl,m,s,hadir}], dari, sampai}`), read-only and without phone numbers — legacy `jadwalDW` deliberately omits them, so a jadwal holder without the `dw` module can still draw the block. |

## Requests (izin / time-off)

| Method | Path | Notes |
|---|---|---|
| GET | `/requests` | Filters (all optional, combine freely): `mine=1` (caller's own — wins over `user`), `user=<id>`, `status=<a,b>` (comma-separated, uppercase), `from=`/`to=` (`YYYY-MM-DD`; a request matches if it spans at least one day inside the range). Full table, newest first — no row cap. Same visibility as legacy `getAll`: any module holder sees all rows, filters only narrow. |
| POST | `/requests` | Body `{userId?, jenis, dari, sampai?, alasan?, shift?, jamMulai?, jamSelesai?}`. `userId` is forced to the caller unless an admin. `status` is always `MENUNGGU`. Returns 201 `{saved, id}`. |
| POST | `/requests/{id}/decision` | Body `{status: MENUNGGU_HRD\|DISETUJUI\|DITOLAK\|MENUNGGU, nota?}`. Two-step approval enforced: Kepala Divisi forwards `MENUNGGU` → `MENUNGGU_HRD`, then HRD (admin) approves → `DISETUJUI`; either step may `DITOLAK`. |
| DELETE | `/requests/{id}` | The requester or a head may delete. Returns `{deleted, id}`. |

## Roster (Divisi + crew read model)

| Method | Path | Returns |
|---|---|---|
| GET | `/roster` | Every Office User with their Divisi attached: `[{id, name, keterangan, noHp, talentaId, active, branch, organization, jobPosition, jobLevel, employmentStatus, joinDate, divisi, tim}]`. `tim` = the Beranda team keys of the Tim column (`AppSupportTim`, same as `/me`). `divisi` follows the legacy `jdw_divisi_user` rule: Penempatan Divisi (`divOverride`) wins, then Tim words (`bar`/`bartender`, `kitchen`/`dapur`, `floor`/`service`/`waiter`/…, `cashier`/`kasir`), with `office`/`kantor` winning over every division word; otherwise `nonshift`. Combine with `/heads` to sort heads first, as the screens do. |

## Settings and heads

| Method | Path | Notes |
|---|---|---|
| GET | `/settings` | `data` = the setting blob (`shifts`, `heads{div:[uid]}`, `divOverride`, `jabatan`, `shiftKru`, `template`, `manajemen`, `maksBeruntun`, `jedaMin`). Empty maps are `{}`; `maksBeruntun`/`jedaMin` are present only when set. |
| PUT | `/settings` | Admin Modul only. Body = the whole setting object. |
| GET | `/heads` | `data` = `{uid: [div]}` (same as the legacy `headIds`). |
