# ERP (v2) — ruang kerja Tim B

Folder ini adalah titik kerja Tim B (platform & ERP). Keputusan dasarnya:
[ADR-0006](../adr/0006-erp-v2-redesigns-core-per-area.md) (core dirancang ulang per area, v2 terpisah dari v1),
[ADR-0007](../adr/0007-v2-table-conventions.md) (aturan tabel v2), dan [ADR-0008](../adr/0008-laksamana-supports-esb.md) (Laksamana mendukung ESB, tidak menggantikan POS atau akuntansinya). Kondisi core saat ini: [audit-core.md](../db/audit-core.md).

## Isi folder

| File | Isi |
|---|---|
| `README.md` | Cara kerja, urutan area, status |
| `peta-core.md` | **Mulai di sini**: peta seluruh `core` v2 (area, tabel bersama, hubungan antar-area) |
| `pertanyaan-owner.md` | Pertanyaan bisnis untuk owner dan jawabannya (Q, P, L, M) |
| `akses.md` | Akses di dalam Modul: Peran, Akses Halaman, Lingkup, Kewenangan |
| `hari-operasional.md` | Hari bisnis yang fleksibel lewat buka/tutup per Lokasi |
| `orang-divisi.md` | Master orang dan divisi: `karyawan`, peran Pihak, divisi shift/kantor |
| `pembelian-persediaan.md` | Master Barang, Pesanan Bahan, buku stok CK, dokumen stok |
| `resep-hpp.md` | Resep, harga jual, Pengaturan HPP, Kontrol Bahan Baku |
| `kas.md` | Dompet, Arus Kas, Kas Kecil, Mutasi Wallet, Setoran, Planning Pembayaran, Pengembalian Modal |
| `penjualan-harian.md` | Omset Harian + breakdown PIC, Report Daily, Compliment, Bon, Void, target |
| `po-proyek.md` | Proyek BD, Pengajuan Pembelian + penyetuju, PO Proyek |
| `kerja-tim.md` | Tugas tim, rutinitas, permintaan koordinasi, agenda; log aktivitas dan notifikasi bersama |
| `reservasi.md` | Tamu — Reservasi |
| `event-tiket.md` | Tamu — Event, tiket, Buyer, check-in, refund, jadwal & pembayaran talent |
| `acara-marketing.md` | Tamu — Acara Marketing: booking klien, quotation, pembayaran |
| `sdm.md` | SDM — Jadwal, Absensi, Pekerja Harian |
| `hr.md` | SDM — HR: People Score |
| `akademi.md` | SDM — Akademi |
| `konten.md` | Modul pendukung — Konten |

Kontrak endpoint v2 ditulis di `docs/api/v2/<area>.md` **sebelum** dibangun, supaya Tim A tahu apa yang akan datang.

## Sepuluh langkah per area

Satu area dibawa melewati semua langkah sampai selesai, baru pindah ke area berikutnya.
Kolom "Siapa" menunjukkan siapa yang menjawab: owner (bisnis) atau tim (teknis).

| # | Langkah | Siapa | Hasil |
|---|---|---|---|
| 1 | Petakan kegiatan bisnis ("siapa melakukan apa terhadap apa") | Owner | daftar kegiatan di `<area>.md`; satu kalimat = satu jenis dokumen |
| 2 | Sepakati istilah | Owner | entri baru di `CONTEXT.md` |
| 3 | Master data dan kunci uniknya (pihak, barang + satuan, lokasi, akun) | Tim | bagian master data di `<area>.md` |
| 4 | Satu atau banyak untuk setiap pasangan | Owner | kardinalitas di ERD |
| 5 | Siklus hidup tiap dokumen, siapa memindahkan status, kapan terkunci | Owner | diagram status di `<area>.md` |
| 6 | Konvensi angka dan waktu | Tim | sudah ditetapkan di ADR-0007; area hanya mencatat pengecualian |
| 7 | Aturan yang tidak boleh dilanggar, dijaga database atau kode | Tim | daftar invarian di `<area>.md` |
| 8 | Apa yang boleh tetap JSON | Tim | daftar kolom JSON + alasannya |
| 9 | Apa yang butuh riwayat | Tim | tabel riwayat / berlaku-dari |
| 10 | ERD final + pemetaan field lama ke kolom baru | Tim | ERD Mermaid + tabel pemetaan; baru setelah ini migration ditulis |

Sumber untuk langkah 10: `docs/db/<modul>.md` (pemetaan legacy yang sudah ada) dan pencarian field JSON di halaman audit core.
Contoh pola migration yang sudah benar: `menu`, `news`, `homepage` di `database/migrations`.

## Urutan area dan status

Semua area di bawah sudah punya rancangan, migration, model, dan tes skema di `main` (PR #204–#223). **Importer** dan **service** belum, kecuali Pembelian & Persediaan (importer sudah).

| Area | Dokumen | Modul lama | Belum |
|---|---|---|---|
| Master Barang, Persediaan | `pembelian-persediaan.md` | stock, hpp | service/endpoint v2 |
| Hari Operasional | `hari-operasional.md` | finance, kompas, absensi, jadwal, stock | service buka/tutup; menunggu L8–L10 |
| Akses di dalam Modul | `akses.md` | semua | middleware `halaman:`, seeder halaman, impor matriks lama |
| Orang & Divisi | `orang-divisi.md` | account, hr, akademi, marketing, konten, bd, stock, dw, event | — (importer `erp-orang` selesai; asumsi L4, L5) |
| Resep & HPP | `resep-hpp.md` | stock (hpp) | service Modal (importer `erp-resep` selesai) |
| Kas | `kas.md` | finance, kompas | service saldo (importer `erp-kas` selesai; asumsi L6, L7, M1) |
| Penjualan Harian | `penjualan-harian.md` | kompas, finance | service tiga angka omset, impor ESB (importer `erp-penjualan` selesai) |
| Proyek & PO Proyek | `po-proyek.md` | bd | — (importer `erp-po-proyek` selesai) |
| Kerja Tim, Log, Notifikasi | `kerja-tim.md` | bd, semua (log/notifikasi) | helper log (importer `erp-kerja-tim` selesai) |
| Tamu — Reservasi | `reservasi.md` | reservasi | DP ke `arus_kas`; metode DP tanpa padanan (importer selesai) |
| Tamu — Event & Tiket | `event-tiket.md` | event, ticketing | uang tiket ke `arus_kas` (importer `erp-event` selesai) |
| Tamu — Acara Marketing | `acara-marketing.md` | marketing | importer `erp-acara`; VIP (M6) |
| SDM — Jadwal, Absensi, DW | `sdm.md` | jadwal, absensi, dw | importer `erp-sdm`, service upah DW |
| SDM — HR | `hr.md` | hr | importer `erp-hr`, service People Score (M5, M7) |
| SDM — Akademi | `akademi.md` | akademi | importer `erp-akademi` |
| Konten | `konten.md` | konten | importer `erp-konten` |
| Di luar ERP (usulan) | `peta-core.md` | hlife | menunggu M2 |

## Status kerja (2026-10-08) — Tim B DIPENDING, baca ini dulu kalau melanjutkan

Pekerjaan Tim B **dihentikan sementara** atas keputusan owner proyek. Semua yang di bawah tertulis supaya siapa pun bisa melanjutkan tanpa ingatan sesi sebelumnya.

### Sudah di `main`

Rancangan, migration, model, dan tes skema untuk semua area di tabel "Urutan area dan status" (PR #204–#225). Peta: `peta-core.md`.

### Di PR #226 `feat/erp-importers` (belum di-merge)

Importer yang sudah ditulis dan dicocokkan dengan **salinan lokal produksi** (total uang sama sampai rupiah, idempoten):

| Urutan | Importer | Dari |
|---|---|---|
| 1 | `account` (identitas, sudah lama) | account, jadwal |
| 2 | `erp-barang`, `erp-persediaan` (sudah lama) | stock |
| 3 | `erp-orang` | hr, dw, ems, marketing, konten, bd |
| 4 | `erp-resep` | stock (hpp) |
| 5 | `erp-kas` | finance, kompas |
| 6 | `erp-po-proyek`, `erp-kerja-tim` | bd (+ log semua modul) |
| 7 | `erp-reservasi` | reservasi |
| 8 | `erp-event` | ems |
| 9 | `erp-penjualan` (jalankan **paling akhir**, menautkan sumber breakdown ke acara/reservasi/event) | kompas |

Juga di PR itu: migration `2026_10_08_090000`–`120000` (kolom yang ternyata dibutuhkan data asli), `PencocokUser` (id Office → username → nama), `MenulisImpor` (helper bersama). Hasil dan kasus yang perlu diputuskan tercatat di bagian "Hasil impor" tiap dokumen area.

### Belum dikerjakan

1. Importer `erp-sdm` (jadwal, dw; **absensi tidak ada dump produksi**, hanya dev), `erp-akademi`, `erp-acara` (lalu ulang `erp-penjualan`), `erp-hr`, `erp-konten`.
2. Service aturan yang dulu dihitung di peramban (Modal resep, saldo dompet, tiga angka omset, upah DW, People Score) dan pembandingnya dengan layar lama.
3. Kontrak dan endpoint API v2 (`docs/api/v2/`, route `app/Erp/*/routes/v2.php`, middleware Akses).
4. Uang ke `arus_kas` dari tiket, DP, pembayaran acara, honor talent, transfer DW, realisasi PO.
5. Pemindahan berkas (bukti, poster, foto) dari folder data lama.
6. Keputusan owner: putaran 2 (L1–L11), putaran 3 (M1–M8), dan kasus "to decide" dari importer.

### Menyiapkan data lokal untuk melanjutkan

1. Ekspor database lama dari phpMyAdmin (SQL, gzip, satu berkas per database, nama = nama database) ke `../db-backup/` (di luar repo).
2. Restore ke MariaDB 10.11 lokal (versi produksi), mis. Docker dengan `--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci`; buat juga `lakk5493_laksamana_core`, `lakk5493_laksamana_core_test`, dan database kosong untuk modul yang tidak punya dump (absensi).
3. `php artisan migrate --database=core`, lalu jalankan importer sesuai urutan di atas. `core:import` hanya menerima host `127.0.0.1`/`localhost`: jalankan PHP di host yang sama dengan databasenya (pada Docker: `--network container:<db>`).
4. Tes: `php artisan test tests/Feature/Erp` (skema + importer), terhadap data yang sama.

## Aturan kerja

- Jangan menambah tabel, kolom, atau importer ke tabel core per-modul yang sekarang (`<modul>_*`). Tabel itu dibekukan (ADR-0006).
- Pertanyaan yang belum dijawab owner memakai **perilaku sistem lama** sebagai asumsi (bentuk tabel lama tidak diikuti), dicatat di bagian D `pertanyaan-owner.md`.
- Setiap jawaban owner langsung dicatat: istilah ke `CONTEXT.md`, keputusan ke ADR baru, jawaban mentah ke `pertanyaan-owner.md`.
  Claude di tim lain hanya tahu apa yang tertulis di repositori.
- `CHECK (kolom IN (...))` pada kolom teks membandingkan dengan collation `utf8mb4_unicode_ci`, jadi **tidak membedakan huruf besar-kecil** (`'PAID'` = `'paid'`). Semua perbandingan di database juga begitu, jadi tidak ada salah hitung, tetapi ejaan baku (huruf kecil) dijaga service dan importer, bukan database.
- Query analisis hanya di MySQL lokal, tidak pernah di dev atau production (CLAUDE.md §0).
