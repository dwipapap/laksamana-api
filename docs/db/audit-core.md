# Audit database `core` — 2 Oktober 2026

Audit ini memotret database `core` (`lakk5493_laksamana_core`) apa adanya, sebagai bahan rancangan ERP.
Tidak ada kode, migration, atau data yang diubah. Dokumen ini untuk Tim B (platform & ERP) dan owner.

Konteks keputusan: laksamana-api akan menjadi ERP dengan `core` yang benar-benar relasional (modular monolith;
v1 = API migrasi di database legacy, ADR-0005; ERP = v2 dengan model domain baru). Usulan area dan ERD awal ada di
`D:\PROJEK\laksamana\laksamana-peta-database.html`.

## Ringkasan

- `core` berisi **172 tabel bisnis** (di luar tabel framework Laravel) untuk 16 modul.
- Ada **336 foreign key**, tetapi **305 (91%) hanya FK aktor** (`created_by` / `updated_by` → `user`).
  **FK bisnis hanya 31**, dan 12 di antaranya di modul identitas.
- **130 tabel (76%) masih menyimpan isi sebenarnya di kolom blob** (`data` / `v` bertipe `longtext`).
  Bentuknya sama dengan database legacy: beberapa kolom terindeks + satu objek JSON, hanya ditambah ULID dan kolom teknis.
- **75 kolom bernama `*_id` tidak punya foreign key**, dan **32 kolom merujuk lewat teks nama** (`pic`, `item`, `vendor`, `oleh`).
- **42 tanggal disimpan sebagai teks**, **168 kolom `created_at` / `updated_at` bertipe angka milidetik** (bukan `TIMESTAMP`),
  dan **tidak ada satu pun kolom uang bertipe `DECIMAL`** (uang = integer, `double`, atau di dalam JSON).
- **Stock paling jauh dari target:** 17 tabel tanpa kolom `version`, tanpa FK aktor, 21 kolom `double`,
  12 relasi lewat nama, 16 tanggal sebagai teks, dan `products` / `vendors` berkunci nama.
- v1 memakai **6 cara berbeda** untuk mencegah bentrok simpan, padahal ADR-0003 menetapkan satu kolom `version`.
- **`core` lokal masih kosong** kecuali identitas (60 user). Modul lain belum pernah diimpor, jadi merancang ulang
  `core` tidak membuang data apa pun.
- Modul greenfield (`menu`, `news`, `homepage`) sudah memakai gaya yang dituju: FK sungguhan, satu `version`,
  tanpa `legacy_id`. Ketiganya bisa dijadikan contoh pola.

## Koreksi terhadap angka sebelumnya

Halaman "Peta Sistem Laksamana" (versi Inggris dan Indonesia) memuat tabel jumlah FK per migration yang dihitung
dengan pencarian teks, misalnya "stock 0 FK, akademi 2 FK, marketing 2 FK". Angka itu **keliru**, karena banyak FK
dibuat lewat loop di migration sehingga tidak terhitung. Angka di dokumen ini diambil dari `information_schema`
dan menggantikannya. Kesimpulannya tetap sama dan justru lebih kuat: hampir semua FK hanya mencatat pelaku,
bukan hubungan antar-data bisnis.

## Cara audit

| Sumber | Dipakai untuk |
|---|---|
| MySQL lokal 8.4, `information_schema` dari `lakk5493_laksamana_core` (21 migration sudah jalan) | tabel, kolom, tipe, PK, FK dan aturan hapusnya |
| File migration `menu`, `news`, `homepage` (belum dijalankan di lokal) | pengecekan modul greenfield |
| Database legacy yang dipulihkan di MySQL lokal (`lakk5493_db_*`) | nama field di dalam blob JSON (baris terbesar per tabel; hanya nama field, tanpa nilai) |
| `docs/api/<modul>.md` | skema versi v1 dan perhitungan yang masih di klien |

Aturan klasifikasi:

- **FK aktor** = FK dari kolom `created_by`, `updated_by`, `saved_by` ke `user`. Sisanya **FK bisnis**.
- **Kolom blob** = `longtext`, `mediumtext`, `text`, atau `json`.
- **`*_id` tanpa FK** = kolom berakhiran `_id` (selain `legacy_id`) yang tidak punya constraint.
- **Tanggal sebagai teks** = kolom `varchar`/`char` dengan nama berisi tgl, tanggal, date, waktu, bulan, jam, atau berakhiran `_at`.
- **Relasi lewat nama** = kolom `varchar` bernama `pic`, `item`, `vendor`, `utama`, `bahan`, `oleh`, `pic_name`, `venue`, dan sejenisnya.

Klasifikasi berbasis nama kolom bisa meleset di beberapa kasus tepi; angkanya sebaiknya dibaca sebagai urutan
besaran masalah per modul, bukan angka mutlak.

## Ringkasan per modul

| Modul | Tabel | Kolom | Kolom blob (tabel) | FK aktor | FK bisnis | `*_id` tanpa FK | Tanggal sbg teks | Relasi lewat nama | `double` | Tanpa `legacy_id` | Tanpa `version` |
|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|--:|
| identity | 10 | 99 | 0 (0) | 18 | **12** | 1 | 0 | 0 | 0 | 4 | 1 |
| jadwal | 7 | 87 | 3 (3) | 14 | **5** | 0 | 0 | 0 | 0 | 1 | 0 |
| dw | 4 | 79 | 1 (1) | 8 | **0** | 2 | 0 | 0 | 0 | 0 | 0 |
| absensi | 4 | 62 | 4 (3) | 8 | **0** | 2 | 1 | 0 | 0 | 0 | 0 |
| marketing | 14 | 140 | 14 (14) | 28 | **0** | 5 | 0 | 0 | 0 | 1 | 0 |
| konten | 15 | 184 | 15 (15) | 30 | **0** | 2 | 0 | 3 | 0 | 1 | 0 |
| akademi | 8 | 85 | 8 (8) | 16 | **0** | 6 | 1 | 0 | 0 | 1 | 0 |
| hr | 25 | 228 | 23 (23) | 49 | **2** | 23 | 18 | 0 | 3 | 1 | 0 |
| bd | 9 | 137 | 9 (9) | 18 | **1** | 6 | 0 | 6 | 0 | 1 | 0 |
| event | 15 | 190 | 15 (15) | 30 | **0** | 15 | 0 | 2 | 0 | 1 | 0 |
| ticketing | 5 | 46 | 0 (0) | 10 | **2** | 5 | 0 | 0 | 0 | 0 | 0 |
| hlife | 14 | 154 | 14 (14) | 28 | **0** | 0 | 3 | 1 | 2 | 1 | 0 |
| reservasi | 3 | 30 | 3 (3) | 6 | **0** | 0 | 1 | 1 | 0 | 1 | 0 |
| finance | 13 | 132 | 6 (4) | 24 | **5** | 1 | 0 | 0 | 0 | 4 | 1 |
| kompas | 9 | 135 | 4 (4) | 18 | **4** | 6 | 2 | 7 | 0 | 0 | 0 |
| stock | 17 | 165 | 17 (14) | 0 | **0** | 1 | 16 | 12 | 21 | 7 | 17 |
| **Total** | **172** | **1953** | **136 (130)** | **305** | **31** | **75** | **42** | **32** | **26** | **24** | **19** |


| Modul | Versi di v1 | Masih dihitung di klien (kontrak v1) | Area ERP tujuan |
|---|---|---|---|
| identity | tanpa If-Match | — | Master data |
| jadwal | tanpa If-Match | — | SDM & Jadwal |
| dw | tanpa versi (last-wins + guard bentrok) | **upah & pembayaran** | SDM & Jadwal |
| absensi | tanpa If-Match | — | SDM & Jadwal |
| marketing | `updatedAt` (ms) | agregasi dashboard | Tamu & Penjualan (event, klien) + pendukung (CRM) |
| konten | `updatedAt` (ms) | agregasi dashboard, rumus performa | Modul pendukung |
| akademi | `updated_at` (ms) | agregasi dashboard | Modul pendukung |
| hr | satu `rev` untuk seluruh dokumen | **skor kinerja** | Modul pendukung (+ karyawan → Master) |
| bd | `updated_at` (ms) | agregasi dashboard | Modul pendukung + Pembelian (usulan) |
| event | `updated_at` (ms) | agregasi dashboard, matriks hak akses | Tamu & Penjualan (event, tiket) |
| ticketing | — (publik) | — | Tamu & Penjualan (pembeli tiket) |
| hlife | hash sha1 isi (16 hex) | agregasi kalender | Di luar ERP |
| reservasi | `updatedAt` per reservasi + hash per bagian master + `_ver` global | — | Tamu & Reservasi |
| finance | `version` per baris | saldo dompet (sebagian) | Pembayaran & Akuntansi |
| kompas | hash isi per bagian blob | **porsi PIC** (Cashier/Omset) | Penjualan harian + Pembayaran (QRIS) |
| stock | hash isi (tabel tanpa kolom `version`) | **kalkulator HPP** | Pembelian & Persediaan + Resep & HPP |


## Sepuluh temuan terpenting

1. **Hubungan antar-data hampir tidak dijaga database.** Dari 336 FK, hanya 31 yang menghubungkan data bisnis.
   Contoh yang hilang: `event_orders.event_id`, `event_checkins.ticket_id`, `marketing_acara.client_id`,
   `bd_purchase_orders.project_id` / `pr_id`, `akademi_progress.material_id`, dan `emp_id` di banyak tabel HR (HR sendiri punya 23 kolom `*_id` tanpa FK).
2. **Sembilan modul hanya "legacy + ULID".** marketing, konten, akademi, hr, bd, event, hlife, reservasi, dan stock
   menyimpan isi di `data longtext` di semua tabelnya. ADR-0002 secara eksplisit menolak bentuk ini.
3. **Stock adalah yang paling jauh.** Tanpa `version`, tanpa FK aktor, `products` dan `vendors` berkunci nama tanpa
   `legacy_id`, `orders.item` dan `hpp_pakai.bahan` merujuk barang lewat nama, harga dan qty bertipe `double`,
   `updated_by` di tabel HPP berisi nama orang (varchar 120).
4. **Uang belum punya tipe yang benar.** Tidak ada kolom uang `DECIMAL`. Harga HPP bertipe `double`
   (`stock_hpp_resep.harga_baru`, `stock_hpp_bahan.harga_beli`), DP berupa `bigint`, dan sebagian besar nominal
   (DP reservasi, pembayaran event Marketing, omset Kompas) tersembunyi di JSON.
5. **Waktu disimpan sebagai angka.** 168 kolom `created_at` / `updated_at` bertipe `bigint` milidetik, karena dipakai
   sebagai versi v1 dan penjaga urutan `saveAll` lama. Di skema ERP, versi harus kolom tersendiri dan waktu bertipe `TIMESTAMP`.
6. **Tanggal bisnis sebagai teks.** 42 kolom, terbanyak di HR (`bulan`, `tanggal`, `join_date`) dan Stock
   (`tanggal`, `waktu`, `tgl_datang`). Tidak bisa difilter dengan fungsi tanggal dan tidak ada konsep hari bisnis.
7. **Divisi masih banyak versi.** Identitas sudah punya `divisi`, `divisi_kata`, `kepala_divisi`, `penempatan_divisi`
   dengan FK yang benar, tetapi `hr_divisions`, `akademi_divisions`, dan `hr_employees.div_id` (varchar) tetap terpisah.
8. **Orang masih banyak versi.** `user` (identitas), `hr_employees`, `akademi_users`, `marketing_pengguna`,
   `marketing_staf`, `konten_users`, `bd_people`, `stock_users`, `stock_ordering_users`, `dw_pekerja`,
   `event_talents`, `ticketing_buyers`. Hanya `hr_employees.user_id` dan `bd_people.user_id` yang ber-FK ke `user`.
9. **Enam skema konkurensi di v1:** `updatedAt`/`updated_at` ms, satu `rev` per dokumen (HR), hash sha1 isi (hlife),
   hash per bagian (Kompas, master Reservasi), hash isi tanpa kolom (Stock), dan tanpa versi sama sekali
   (dw, jadwal, absensi, account).
10. **Aturan bisnis yang menyangkut uang masih di klien:** upah DW, skor HR, kalkulator HPP, porsi PIC
    (Cashier/Omset). Kontrak v1 menyebutnya terang-terangan ("Money is computed client-side").

## Apa artinya bagi ERP

- **Jangan melanjutkan cutover modul ke `core` yang sekarang.** Skemanya masih bentuk legacy; memindahkan data ke sana
  berarti memindahkan masalahnya juga.
- **Yang bisa dipertahankan:** tabel identitas (`user`, `modul`, `izin_akses`, `larangan`, `admin_modul`, `divisi`,
  `divisi_kata`, `kepala_divisi`, `penempatan_divisi`), konvensi ULID + `legacy_id`, kolom aktor, dan pola modul
  greenfield (`menu`, `news`, `homepage`).
- **Yang perlu dirancang ulang per area:** semua tabel ber-blob, dengan field yang difilter, dijumlah, atau di-join
  menjadi kolom sungguhan (daftar field ada di Lampiran B), dan semua `*_id` / relasi lewat nama menjadi FK.
- **Biaya data nol:** `core` lokal belum berisi data modul. Rancangan baru tidak perlu migrasi data dari `core`;
  sumber impor tetap database legacy.

## Pertanyaan untuk owner

1. **Orang:** apakah kru di HR, Akademi, Marketing, Konten, BD, dan Stock memang selalu User Office yang sama?
   Adakah orang yang tercatat di modul tetapi tidak pernah login (selain pekerja harian dan talent)?
2. **Divisi:** apakah divisi di HR dan Akademi sama dengan empat divisi shift (bar, kitchen, floor, cashier) ditambah
   kantor, atau memang daftar lain (misalnya departemen)?
3. **Barang:** apakah daftar bahan di Stock (`products`) dan di HPP (`hpp_bahan`) adalah barang yang sama?
4. **Pembelian:** opsi A, B, atau C untuk Stock + BD (lihat halaman Peta Database).
5. **Uang:** apakah ada nominal dengan sen (misalnya hasil pembagian porsi), atau semua rupiah bulat?
6. **Hari bisnis:** jam berapa satu hari operasional dianggap berganti (misalnya 05.00)?

## Langkah berikutnya untuk Tim B

1. Tulis ADR yang mengubah ADR-0002: `core` dirancang ulang untuk ERP; cutover ke `core` versi sekarang dihentikan.
2. Bawa pertanyaan di atas ke owner bersama pertanyaan Pembelian & Persediaan.
3. Rancang area pertama (Pembelian & Persediaan) sampai ERD final, memakai Lampiran B untuk memilih field
   yang menjadi kolom, dan modul greenfield sebagai contoh pola migration.
4. Tetapkan satu skema versi (`version` INT) dan satu tipe uang (`DECIMAL(15,2)` atau integer rupiah, sesuai jawaban owner nomor 5).

## Detail per modul

### identity

- **Kolom blob:** —
- **FK bisnis:** `admin_modul.modul_id→modul`, `admin_modul.user_id→user`, `divisi_kata.divisi_id→divisi`, `izin_akses.modul_id→modul`, `izin_akses.user_id→user`, `kepala_divisi.divisi_id→divisi`, `kepala_divisi.user_id→user`, `larangan.modul_id→modul`, `larangan.user_id→user`, `penempatan_divisi.divisi_id→divisi`, `penempatan_divisi.user_id→user`, `sesi_legacy.user_id→user`
- **Kolom `*_id` tanpa FK:** `user.talenta_id`
- **Tanpa `legacy_id`:** `divisi`, `divisi_kata`, `modul`, `sesi_legacy`
- **Tanpa kolom `version`:** `sesi_legacy`
- **Versi v1:** tanpa If-Match · **Dihitung di klien:** — · **Area ERP:** Master data

### jadwal

- **Kolom blob:** `jadwal_pengajuan(alasan)`, `jadwal_pengaturan(ekstra)`, `jadwal_shift(ekstra)`
- **FK bisnis:** `jadwal_jabatan.user_id→user`, `jadwal_manajemen.user_id→user`, `jadwal_pengajuan.user_id→user`, `jadwal_sel.user_id→user`, `jadwal_shift_kru.user_id→user`
- **Kolom `*_id` tanpa FK:** —
- **Tanpa `legacy_id`:** `jadwal_shift`
- **Versi v1:** tanpa If-Match · **Dihitung di klien:** — · **Area ERP:** SDM & Jadwal

### dw

- **Kolom blob:** `dw_setting(data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** `dw_ajuan.dw_id`, `dw_ajuan.permintaan_id`
- **Versi v1:** tanpa versi (last-wins + guard bentrok) · **Dihitung di klien:** **upah & pembayaran** · **Area ERP:** SDM & Jadwal

### absensi

- **Kolom blob:** `abs_punch(foto)`, `abs_setting(data)`, `abs_wajah(descriptor,foto)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** `abs_punch.subjek_id`, `abs_punch.lokasi_id`
- **Tanggal disimpan sebagai teks:** `abs_punch.jam varchar(5)`
- **Versi v1:** tanpa If-Match · **Dihitung di klien:** — · **Area ERP:** SDM & Jadwal

### marketing

- **Kolom blob:** `marketing_acara(data)`, `marketing_aktivitas(data)`, `marketing_kategori(data)`, `marketing_kategori_tugas(data)`, `marketing_klien(data)`, `marketing_notifikasi(data)`, `marketing_pengaturan(v)`, `marketing_pengguna(data)`, `marketing_permintaan_desain(data)`, `marketing_persetujuan(data)`, `marketing_staf(data)`, `marketing_template_tugas(data)`, `marketing_tindak_lanjut(data)`, `marketing_vip(data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** `marketing_acara.client_id`, `marketing_aktivitas.ref_id`, `marketing_persetujuan.event_id`, `marketing_tindak_lanjut.client_id`, `marketing_tindak_lanjut.event_id`
- **Tanpa `legacy_id`:** `marketing_pengaturan`
- **Versi v1:** `updatedAt` (ms) · **Dihitung di klien:** agregasi dashboard · **Area ERP:** Tamu & Penjualan (event, klien) + pendukung (CRM)

### konten

- **Kolom blob:** `konten_ad_funds(data)`, `konten_ads(data)`, `konten_assets(data)`, `konten_bank(data)`, `konten_brands(data)`, `konten_campaigns(data)`, `konten_content(data)`, `konten_kols(data)`, `konten_logs(data)`, `konten_notifs(data)`, `konten_pengaturan(v)`, `konten_prod_tasks(data)`, `konten_shootings(data)`, `konten_users(data)`, `konten_visits(data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** `konten_logs.ref_id`, `konten_visits.kol_id`
- **Relasi lewat teks nama:** `konten_content.pic`, `konten_prod_tasks.pic`, `konten_visits.pic`
- **Tanpa `legacy_id`:** `konten_pengaturan`
- **Versi v1:** `updatedAt` (ms) · **Dihitung di klien:** agregasi dashboard, rumus performa · **Area ERP:** Modul pendukung

### akademi

- **Kolom blob:** `akademi_activity(data)`, `akademi_divisions(data)`, `akademi_materials(data)`, `akademi_pengaturan(v)`, `akademi_prog_prog(data)`, `akademi_programs(data)`, `akademi_progress(data)`, `akademi_users(data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** `akademi_activity.user_id`, `akademi_prog_prog.user_id`, `akademi_prog_prog.program_id`, `akademi_prog_prog.material_id`, `akademi_progress.user_id`, `akademi_progress.material_id`
- **Tanggal disimpan sebagai teks:** `akademi_programs.bulan varchar(7)`
- **Tanpa `legacy_id`:** `akademi_pengaturan`
- **Versi v1:** `updated_at` (ms) · **Dihitung di klien:** agregasi dashboard · **Area ERP:** Modul pendukung

### hr

- **Kolom blob:** `hr_attendance_days(data)`, `hr_attendance_months(data)`, `hr_audit(detail)`, `hr_badges(data)`, `hr_calendar(data)`, `hr_career_paths(data)`, `hr_coachings(data)`, `hr_competencies(data)`, `hr_divisions(data)`, `hr_employees(data)`, `hr_feedbacks(data)`, `hr_kpi_templates(data)`, `hr_monthly_inputs(data)`, `hr_moods(data)`, `hr_okrs(data)`, `hr_pengaturan(v)`, `hr_reviews(data)`, `hr_rewards(data)`, `hr_successions(data)`, `hr_suggestions(data)`, `hr_training_records(data)`, `hr_trainings(data)`, `hr_violations(data)`
- **FK bisnis:** `hr_attendance_days.month_id→hr_attendance_months`, `hr_employees.user_id→user`
- **Kolom `*_id` tanpa FK:** `hr_attendance_days.talenta_id`, `hr_attendance_days.emp_id`, `hr_audit.user_id`, `hr_badges.emp_id`, `hr_coachings.emp_id`, `hr_coachings.coach_id`, `hr_competencies.emp_id`, `hr_employees.div_id`, `hr_feedbacks.emp_id`, `hr_kpi_actuals.div_id`, `hr_kpi_actuals.item_id`, `hr_kpi_templates.div_id`, `hr_monthly_inputs.emp_id`, `hr_moods.emp_id`, `hr_okrs.owner_id`, `hr_reviews.emp_id`, `hr_rewards.emp_id`, `hr_successions.emp_id`, `hr_suggestions.emp_id`, `hr_training_records.training_id`, `hr_training_records.emp_id`, `hr_trainings.div_id`, `hr_violations.emp_id`
- **Tanggal disimpan sebagai teks:** `hr_attendance_days.bulan varchar(10)`, `hr_attendance_days.tanggal varchar(10)`, `hr_attendance_months.bulan varchar(10)`, `hr_attendance_months.imported_at varchar(40)`, `hr_badges.bulan varchar(10)`, `hr_calendar.tanggal varchar(20)`, `hr_coachings.tanggal varchar(20)`, `hr_employees.join_date varchar(20)`, `hr_feedbacks.tanggal varchar(20)`, `hr_kpi_actuals.bulan varchar(10)`, `hr_meta.saved_at varchar(40)`, `hr_monthly_inputs.bulan varchar(10)`, `hr_moods.tanggal varchar(20)`, `hr_reviews.bulan varchar(10)`, `hr_rewards.tanggal varchar(20)`, `hr_suggestions.tanggal varchar(20)`, `hr_training_records.tanggal varchar(20)`, `hr_violations.tanggal varchar(20)`
- **Angka `double`:** `hr_kpi_actuals.nilai`, `hr_rewards.points`, `hr_training_records.skor`
- **Tanpa `legacy_id`:** `hr_pengaturan`
- **Versi v1:** satu `rev` untuk seluruh dokumen · **Dihitung di klien:** **skor kinerja** · **Area ERP:** Modul pendukung (+ karyawan → Master)

### bd

- **Kolom blob:** `bd_agenda(data)`, `bd_coord_requests(data)`, `bd_pengaturan(v)`, `bd_people(data)`, `bd_projects(data)`, `bd_purchase_orders(data)`, `bd_purchase_requests(data)`, `bd_routines(data)`, `bd_tasks(data)`
- **FK bisnis:** `bd_people.user_id→user`
- **Kolom `*_id` tanpa FK:** `bd_coord_requests.project_id`, `bd_people.boss_id`, `bd_people.office_user_id`, `bd_purchase_orders.project_id`, `bd_purchase_orders.pr_id`, `bd_tasks.project_id`
- **Relasi lewat teks nama:** `bd_projects.pic`, `bd_purchase_orders.item`, `bd_purchase_orders.vendor`, `bd_purchase_orders.pic`, `bd_routines.pic`, `bd_tasks.pic`
- **Tanpa `legacy_id`:** `bd_pengaturan`
- **Versi v1:** `updated_at` (ms) · **Dihitung di klien:** agregasi dashboard · **Area ERP:** Modul pendukung + Pembelian (usulan)

### event

- **Kolom blob:** `event_calendar_extra(data)`, `event_checkins(data)`, `event_event_details(data)`, `event_events(data)`, `event_ideas(data)`, `event_orders(data)`, `event_pengaturan(v)`, `event_recurring_rules(data)`, `event_refunds(data)`, `event_schedules(data)`, `event_seats(data)`, `event_talent_payments(data)`, `event_talents(data)`, `event_ticket_classes(data)`, `event_tickets(data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** `event_checkins.ticket_id`, `event_event_details.event_id`, `event_events.idea_id`, `event_orders.event_id`, `event_recurring_rules.talent_id`, `event_refunds.order_id`, `event_schedules.talent_id`, `event_schedules.event_id`, `event_seats.event_id`, `event_seats.ticket_class_id`, `event_talent_payments.talent_id`, `event_ticket_classes.event_id`, `event_tickets.order_item_id`, `event_tickets.ticket_class_id`, `event_tickets.seat_id`
- **Relasi lewat teks nama:** `event_events.venue`, `event_events.pic`
- **Tanpa `legacy_id`:** `event_pengaturan`
- **Versi v1:** `updated_at` (ms) · **Dihitung di klien:** agregasi dashboard, matriks hak akses · **Area ERP:** Tamu & Penjualan (event, tiket)

### ticketing

- **Kolom blob:** —
- **FK bisnis:** `ticketing_resets.buyer_id→ticketing_buyers`, `ticketing_sessions.buyer_id→ticketing_buyers`
- **Kolom `*_id` tanpa FK:** `ticketing_resets.user_id`, `ticketing_seat_holds.event_id`, `ticketing_seat_holds.seat_id`, `ticketing_seat_holds.order_id`, `ticketing_sessions.user_id`
- **Versi v1:** — (publik) · **Dihitung di klien:** — · **Area ERP:** Tamu & Penjualan (pembeli tiket)

### hlife

- **Kolom blob:** `hlife_assets(data)`, `hlife_businesses(data)`, `hlife_content(data)`, `hlife_dreams(data)`, `hlife_events(data)`, `hlife_goals(data)`, `hlife_habits(data)`, `hlife_learning(data)`, `hlife_ledger(data)`, `hlife_pengaturan(v)`, `hlife_projects(data)`, `hlife_reviews(data)`, `hlife_roadmap(data)`, `hlife_tasks(data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** —
- **Relasi lewat teks nama:** `hlife_projects.pic`
- **Tanggal disimpan sebagai teks:** `hlife_content.tanggal varchar(20)`, `hlife_events.tanggal varchar(20)`, `hlife_ledger.bulan varchar(10)`
- **Angka `double`:** `hlife_ledger.income`, `hlife_ledger.expense`
- **Tanpa `legacy_id`:** `hlife_pengaturan`
- **Versi v1:** hash sha1 isi (16 hex) · **Dihitung di klien:** agregasi kalender · **Area ERP:** Di luar ERP

### reservasi

- **Kolom blob:** `reservasi_audit(data)`, `reservasi_pengaturan(v)`, `reservasi_reservations(data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** —
- **Relasi lewat teks nama:** `reservasi_reservations.pic_name`
- **Tanggal disimpan sebagai teks:** `reservasi_reservations.jam varchar(8)`
- **Tanpa `legacy_id`:** `reservasi_pengaturan`
- **Versi v1:** `updatedAt` per reservasi + hash per bagian master + `_ver` global · **Dihitung di klien:** — · **Area ERP:** Tamu & Reservasi

### finance

- **Kolom blob:** `finance_bk_state(data)`, `finance_inv_kwitansi(ringkas,catatan,penanda)`, `finance_inv_penanda(ttd)`, `finance_inv_setting(v)`
- **FK bisnis:** `finance_bk_peran.user_id→user`, `finance_kk_peran.user_id→user`, `finance_kk_trx.kategori_id→finance_kk_kategori`, `finance_kk_trx_pos.pos_id→finance_kk_pos`, `finance_kk_trx_pos.trx_id→finance_kk_trx`
- **Kolom `*_id` tanpa FK:** `finance_inv_kwitansi.res_id`
- **Tanpa `legacy_id`:** `finance_bk_peran`, `finance_counter`, `finance_inv_setting`, `finance_kk_peran`
- **Tanpa kolom `version`:** `finance_counter`
- **Versi v1:** `version` per baris · **Dihitung di klien:** saldo dompet (sebagian) · **Area ERP:** Pembayaran & Akuntansi

### kompas

- **Kolom blob:** `kompas_an_state(data)`, `kompas_app_state(data)`, `kompas_pengaturan(v)`, `kompas_void_log(alasan)`
- **FK bisnis:** `kompas_an_akses.user_id→user`, `kompas_an_peran.user_id→user`, `kompas_bri_mutasi.user_id→user`, `kompas_void_log.user_id→user`
- **Kolom `*_id` tanpa FK:** `kompas_bri_dp_abai.dp_id`, `kompas_bri_dp_abai.res_id`, `kompas_bri_mutasi.res_id`, `kompas_bri_mutasi.dp_id`, `kompas_bri_mutasi.oleh_id`, `kompas_void_log.oleh_id`
- **Relasi lewat teks nama:** `kompas_an_state.oleh`, `kompas_app_state.oleh`, `kompas_bri_dp_abai.oleh`, `kompas_bri_mutasi.oleh`, `kompas_inv_lapor.oleh`, `kompas_void_log.item`, `kompas_void_log.oleh`
- **Tanggal disimpan sebagai teks:** `kompas_bri_mutasi.jam varchar(8)`, `kompas_inv_lapor.bulan char(7)`
- **Versi v1:** hash isi per bagian blob · **Dihitung di klien:** **porsi PIC** (Cashier/Omset) · **Area ERP:** Penjualan harian + Pembayaran (QRIS)

### stock

- **Kolom blob:** `stock_activity_log(data)`, `stock_ck(data)`, `stock_hpp_resep(catatan,bahan)`, `stock_hpp_setting(data)`, `stock_opname(data)`, `stock_ordering_users(data)`, `stock_orders(data)`, `stock_products(data)`, `stock_serah_terima(foto,data)`, `stock_snapshot(data)`, `stock_usage_events(data)`, `stock_users(data)`, `stock_vendors(data)`, `stock_waste(foto,data)`
- **FK bisnis:** —
- **Kolom `*_id` tanpa FK:** `stock_orders.batch_id`
- **Relasi lewat teks nama:** `stock_ck.item`, `stock_ck.pic`, `stock_hpp_bahan.vendor`, `stock_hpp_pakai.bahan`, `stock_opname.pic`, `stock_orders.item`, `stock_orders.pic`, `stock_products.utama`, `stock_serah_terima.pic`, `stock_usage_events.pic`, `stock_waste.item`, `stock_waste.pic`
- **Tanggal disimpan sebagai teks:** `stock_activity_log.waktu varchar(30)`, `stock_activity_log.tanggal varchar(20)`, `stock_ck.tanggal varchar(20)`, `stock_ck.waktu varchar(30)`, `stock_hpp_bulan.bulan char(7)`, `stock_hpp_pakai.bulan char(7)`, `stock_opname.tanggal varchar(20)`, `stock_opname.waktu varchar(30)`, `stock_orders.waktu varchar(30)`, `stock_orders.tgl_datang varchar(20)`, `stock_serah_terima.tanggal varchar(20)`, `stock_serah_terima.waktu varchar(30)`, `stock_usage_events.tanggal varchar(20)`, `stock_usage_events.waktu varchar(30)`, `stock_waste.tanggal varchar(20)`, `stock_waste.waktu varchar(30)`
- **Angka `double`:** `stock_ck.qty`, `stock_ck.qty_input`, `stock_hpp_bahan.qty_beli`, `stock_hpp_bahan.harga_beli`, `stock_hpp_bulan.penjualan`, `stock_hpp_pakai.sa`, `stock_hpp_pakai.beli`, `stock_hpp_pakai.resep`, `stock_hpp_pakai.spoil`, `stock_hpp_pakai.team`, `stock_hpp_pakai.rnd`, `stock_hpp_pakai.comp`, `stock_hpp_pakai.opname`, `stock_hpp_resep.yield_qty`, `stock_hpp_resep.harga_lama`, `stock_hpp_resep.harga_baru`, `stock_hpp_resep.harga_upsize`, `stock_hpp_resep.modal_manual`, `stock_orders.qty`, `stock_snapshot.stock_now`, `stock_waste.qty`
- **Tanpa `legacy_id`:** `stock_hpp_bahan`, `stock_hpp_bulan`, `stock_hpp_pakai`, `stock_orders`, `stock_products`, `stock_snapshot`, `stock_vendors`
- **Tanpa kolom `version`:** `stock_activity_log`, `stock_ck`, `stock_hpp_bahan`, `stock_hpp_bulan`, `stock_hpp_pakai`, `stock_hpp_resep`, `stock_hpp_setting`, `stock_opname`, `stock_ordering_users`, `stock_orders`, `stock_products`, `stock_serah_terima`, `stock_snapshot`, `stock_usage_events`, `stock_users`, `stock_vendors`, `stock_waste`
- **Versi v1:** hash isi (tabel tanpa kolom `version`) · **Dihitung di klien:** **kalkulator HPP** · **Area ERP:** Pembelian & Persediaan + Resep & HPP



## Lampiran A — FK bisnis yang sudah ada

Seluruhnya tercantum di baris "FK bisnis" pada detail per modul di atas (31 constraint). Semua 305 FK aktor
memakai `ON DELETE SET NULL` ke `user`. Aturan hapus 31 FK bisnis: 16 `CASCADE`, 11 `SET NULL`, 3 `RESTRICT`, 1 `NO ACTION`.
Untuk ERP, `CASCADE` pada data bisnis perlu ditinjau ulang: dokumen final sebaiknya tidak ikut terhapus diam-diam.

## Lampiran B — Field di dalam blob JSON (database legacy)

Diambil dari baris dengan isi terbesar di setiap tabel legacy. Hanya nama field, tanpa nilai. Field yang pernah
difilter, dijumlah, atau di-join (misalnya tanggal, status, nominal, rujukan id) adalah calon kolom sungguhan.

| Tabel lama | Kolom | Baris | Field di dalam JSON |
|---|---|--:|---|
| `akademi.activity` | `data` | 218 | ts, action, detail, userId |
| `akademi.divisions` | `data` | 6 | ic, id, name, updatedAt |
| `akademi.materials` | `data` | 13 | id, cat, type, steps, title, division, createdAt, mandatory, published, updatedAt |
| `akademi.prog_prog` | `data` | 3 | at, done, score |
| `akademi.programs` | `data` | 1 | id, note, bulan, title, deadline, createdAt, updatedAt, materialIds |
| `akademi.progress` | `data` | 31 | score, lastAt, passed, attempts, completedAt |
| `akademi.settings` | `v` | 4 | orgName, syncKey, syncUrl, syncAuto, passingDefault, requirePinChange |
| `akademi.users` | `data` | 60 | id, name, role, title, active, division, updatedAt |
| `dw.dw_setting` | `data` | 1 | jam, kuota, tarif, posisi |
| `konten.ad_funds` | `data` | 29 | id, date, note, brand, amount, createdAt, updatedAt |
| `konten.ads` | `data` | 114 | id, tax, name, note, brand, chats, spend, budget, status, target, endDate, ageRange, expenses, platform, createdAt, objective, spendBase, startDate, updatedAt, impressions |
| `konten.bank` | `data` | 1 | at, id, brand, owner, title, trend, source, status, keyword, category, estReach, platform, priority, reference, updatedAt, difficulty |
| `konten.brands` | `data` | 4 | id, pic, desc, freq, name, tone, color, pillars, website, audience, hashtags, kpiReach, platforms, updatedAt, kpiFollowers |
| `konten.content` | `data` | 59 | id, cta, pic, hook, lang, prod, refs, brand, title, assets, detail, pillar, script, series, status, caption, keyword, metrics, audience, campaign, comments, deadline, duration, hashtags, platform, priority, shotList, approvals, checklist, createdAt, createdBy, objective, platforms, printType, reference, revisions, thumbnail, updatedAt, printTypes, contentType, publishDate, publishTime, contentTypes, publishRecord |
| `konten.kols` | `data` | 38 | id, name, note, type, tiktok, whatsapp, createdAt, instagram, rateImage, rateValue, updatedAt, categories, rateHistory |
| `konten.logs` | `data` | 855 | at, by, id, action, target |
| `konten.notifs` | `data` | 144 | at, id, to, read, text, type, updatedAt |
| `konten.prod_tasks` | `data` | 7 | id, pic, date, kind, note, brand, title, status, priority, updatedAt |
| `konten.settings` | `v` | 3 | ads, kol, bank, team, assets, brands, design, approve, content, editing, publish, reports, activity, settings, shooting, analytics, campaigns |
| `konten.users` | `data` | 13 | id, pin, name, avail, email, roles, active, brands, skills, capacity, division, updatedAt, workHours |
| `konten.visits` | `data` | 4 | id, pic, date, desc, note, time, brand, kolId, title, status, invitees, location, createdAt, objective, updatedAt |
| `stock.activity_log` | `data` | 1176 | mode, items, dibuat, batchId, digabung, batchName, tglDatang |
| `stock.ck_stock` | `data` | 207 | catatan, packIsi, packSatuan |
| `stock.orders` | `data` | 2618 | pic, qty, tim, item, note, unit, status, batchId, catatan, rowIndex, batchName, tglDatang, tglJemput, tglTerima, timestamp, kedatangan, nomorOrder, catatanTerima |
| `stock.vendors` | `data` | 48 | bank, norek, penerima, whatsapp, tutupHari, perluJadwalJemput |
| `absensi.abs_setting` | `data` | 1 | hr, awalMenit, akhirMenit, wajahWajib, wajahAmbang, lemburMinMenit, toleransiTelat, tanpaShiftBoleh |
| `jadwal.jadwal_setting` | `data` | 1 | heads, shifts, jabatan, jedaMin, shiftKru, manajemen, divOverride, maksBeruntun |
| `marketing.activities` | `data` | 881 | at, by, id, refId, action, detail, refType, createdAt, updatedAt, baseUpdatedAt |
| `marketing.approvals` | `data` | 1 | at, by, id, by2, auto, note, reason, status, eventId, createdAt, decidedAt, updatedAt |
| `marketing.clients` | `data` | 56 | hp, id, ig, pic, nama, email, alamat, mktPIC, nextFU, source, catatan, birthday, createdAt, updatedAt, perusahaan, lastContact |
| `marketing.events` | `data` | 54 | id, pax, nama, jenis, tasks, detail, mktPIC, status, pipeCol, tanggal, clientId, payments, createdAt, updatedAt, invoiceSent |
| `marketing.followups` | `data` | 1 | at, by, id, next, note, hasil, _dummy, action, eventId, clientId, updatedAt |
| `marketing.notifs` | `data` | 13 | at, id, body, link, read, type, title, createdAt, updatedAt |
| `marketing.staff` | `data` | 52 | hp, id, div, name, active, source, jabatan, officeId, createdAt, updatedAt |
| `marketing.task_templates` | `data` | 60 | id, div, pic, sub, note, task, hminus, deadline, createdAt, updatedAt |
| `marketing.users` | `data` | 62 | id, acc, name, role, active, jabatan, createdAt, updatedAt |
| `bd.people` | `data` | 10 | id, div, ket, boss, name, role, active, createdAt, updatedAt, officeUserId |
| `bd.projects` | `data` | 33 | id, div, end, pic, desc, name, pics, type, spent, stage, start, budget, health, createdAt, updatedAt, milestones |
| `bd.purchase_orders` | `data` | 84 | by, id, div, qty, item, note, prId, unit, input, amount, byName, needBy, proses, status, sumber, vendor, prosesAt, prosesBy, createdAt, realisasi, updatedAt, statusSebelum |
| `bd.purchase_requests` | `data` | 10 | id, no, dept, nama, total, status, catatan, tanggal, approvals, createdAt, updatedAt, weekStart |
| `bd.tasks` | `data` | 176 | id, div, pic, desc, name, pics, type, status, urgent, project, deadline, priority, progress, createdAt, important, updatedAt |
| `hr.career_paths` | `data` | 3 | id, req, steps, track |
| `hr.divisions` | `data` | 8 | id, name, color |
| `hr.employees` | `data` | 52 | id, name, role, divId, email, level, notes, phone, status, appRole, joinDate, birthDate, talentaId, contractEnd, probationEnd |
| `hr.kpi_templates` | `data` | 6 | id, divId, items |
| `hr.reviews` | `data` | 1 | id, empId, month, layers |
| `hr.settings` | `v` | 11 | 2026-06 |
| `hr.violations` | `data` | 1 | id, sp, date, note, type, empId, status, severity |
| `finance.bk_state` | `data` | 1 | bayar, mutasi, piutang, setting, investor, rekening |
| `finance.inv_kwitansi` | `ringkas` | 7 | dp, pax, pic, sub, tax, disc, paid, bayar, event, grand, items, tagih, client, taxPct, dpBayar, service, tanggal, jenisDok, praBayar, picClient, remaining, jenisAcara, noInternal, perusahaan |
| `kompas.an_state` | `data` | 1 | promo, voidb, laporan, setting |
| `kompas.app_state` | `data` | 1 | daily, rokok, owners, piutang, reports, _savedBy, settings, employees, compliments, rokok_items, rekap_setoran |
| `kompas.cashiers` | `data` | 1 | id, name, active, updatedAtMs |
| `kompas.pics` | `data` | 1 | id, name, active, updatedAtMs |
| `reservasi.audit` | `data` | 500 | id, ts, role, user, action, detail |
| `reservasi.reservations` | `data` | 2460 | id, dps, pax, vip, date, name, tfAt, tfBy, time, lunas, notes, phone, table, leftAt, member, source, status, tfBank, tfDate, tfNote, tfTime, foodReq, picName, picType, sharing, tfOcrAt, arrivals, category, dpAmount, dpMethod, dpStatus, drinkReq, memberNo, tfAmount, tfSource, tfStatus, actualPax, checkinAt, createdAt, createdBy, followups, tfOcrText, updatedAt, autoClosed, docReqData, docReqName, dpProofData, dpProofName, cancelReason |
| `reservasi.settings` | `v` | 2 | 0, 1, perms, users, seCrew, tables, _migUOB, layouts, reviews, sePerms, waitlist, dpMethods, feedbacks, reviewCfg, waTargets, categories, dineEstMin, infoSources, clashLeadMin, layoutTanggal, layoutOverrides, layoutOverrides2 |
| `stock.users` | `data` | 1 | id, pin, name, role, keterangan |
| `stock.ordering_users` | `data` | 27 | id, pin, name, role, keterangan |
| `stock.products` | `data` | 395 | isi, area, aktif, utama, satuan, sumber, packIsi, cadangan, caraBeli, diOutlet, kategori, packSatuan, satuanDasar |
| `stock.usage_events` | `data` | 4 | items, catatan |
| `stock.waste` | `data` | 13 | catatan |
| `stock.serah_terima` | `data` | 32 | items, catatan |
| `ems.calendar_extra` | `data` | 4 | id, date, type, color, title, createdAt, updatedAt |
| `ems.checkins` | `data` | 4 | id, gate, staff, result, ticket_id, checked_in_at |
| `ems.event_details` | `data` | 25 | tasks, budget, layout, rundown, vendors, sponsors, timeline, createdAt, updatedAt |
| `ems.events` | `data` | 25 | id, pic, theme, title, venue, co_pic, poster, status, idea_id, capacity, category, createdAt, updatedAt, poster_img, description, is_ticketed, end_datetime, start_datetime |
| `ems.ideas` | `data` | 5 | id, name, budget, category, createdAt, frequency, updatedAt, difficulty, description, evaluations, ever_executed, target_market, potential_revenue |
| `ems.orders` | `data` | 27 | id, fee, email, items, notes, phone, total, _cek_at, channel, created, payment, user_id, event_id, subtotal, birthdate, buyer_name, expires_at, payment_ref, recorded_by, access_token, recorded_via, payment_status |
| `ems.recurring_rules` | `data` | 1 | id, fee, end_time, valid_to, createdAt, talent_id, updatedAt, start_time, valid_from, days_of_week, performance_type |
| `ems.refunds` | `data` | 6 | id, oleh, reason, status, event_id, order_id, createdAt, penyetuju, updatedAt, decided_at, approved_at, requested_at |
| `ems.schedules` | `data` | 117 | id, fee, date, source, status, end_time, event_id, createdAt, talent_id, updatedAt, start_time, performance_type, recurring_rule_id |
| `ems.seats` | `data` | 670 | h, w, x, y, id, kind, pola, tier, zone, floor, petak, shape, status, seat_no, capacity, event_id, table_no, createdAt, updatedAt, ticket_class_id |
| `ems.talent_payments` | `data` | 3 | id, bank, status, paid_at, createdAt, talent_id, updatedAt, created_at, show_count, pic_finance, period_month, total_amount, transfer_proof |
| `ems.talents` | `data` | 16 | id, name, npwp, email, notes, phone, photo, status, address, category, whatsapp, bank_name, createdAt, instagram, updatedAt, default_fee, bank_account, manager_name, manager_phone, account_holder, contract_status |
| `ems.ticket_classes` | `data` | 8 | id, name, sold, price, quota, benefit, event_id, sale_end, createdAt, is_seated, updatedAt, sale_start, description |
| `ems.tickets` | `data` | 11 | id, kind, tier, pax_no, status, pdf_url, seat_id, qr_token, createdAt, issued_at, pax_total, updatedAt, buyer_name, seat_label, order_item_id, ticket_number, ticket_class_id |
| `hlife.assets` | `data` | 7 | id, cat, loc, qty, liab, name, unit, value |
| `hlife.businesses` | `data` | 11 | id, area, desc, name, field, founded, revenue |
| `hlife.content` | `data` | 8 | id, date, notes, stage, title, channel, platform |
| `hlife.dreams` | `data` | 5 | id, cat, note, year, title, status, milestone |
| `hlife.goals` | `data` | 5 | id, cur, area, desc, name, unit, target, milestone |
| `hlife.learning` | `data` | 1 | id, date, take, type, title, rating, source, status |
| `hlife.projects` | `data` | 17 | id, biz, due, pic, desc, name, team, stage, priority, progress |
| `hlife.reviews` | `data` | 1 | id, wins, improve, sContent, sProject, sBusiness, sPersonal, weekStart |
| `hlife.roadmap` | `data` | 5 | id, desc, done, year, title |
| `hlife.tasks` | `data` | 60 | id, biz, due, est, desc, done, name, owner, urgent, project, important |


## Lampiran C — Mengulang audit

```sql
-- kolom, tipe, kunci
SELECT table_name, column_name, data_type, column_type, is_nullable, column_key
FROM information_schema.columns WHERE table_schema = 'lakk5493_laksamana_core'
ORDER BY table_name, ordinal_position;

-- foreign key dan aturan hapusnya
SELECT k.table_name, k.column_name, k.referenced_table_name, r.delete_rule
FROM information_schema.key_column_usage k
JOIN information_schema.referential_constraints r
  ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
WHERE k.table_schema = 'lakk5493_laksamana_core' AND k.referenced_table_name IS NOT NULL;

-- field di dalam blob legacy (hanya nama field)
SELECT JSON_KEYS(data) FROM lakk5493_db_<modul>.<tabel>
WHERE JSON_VALID(data) ORDER BY LENGTH(data) DESC LIMIT 1;
```

MySQL lokal: `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe --datadir=C:/laragon/data/mysql-8.4`.
Jangan pernah menjalankan query ini ke database dev atau production.
