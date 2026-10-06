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
| Tamu — Reservasi | `reservasi.md` | reservasi | importer `erp-reservasi`, DP ke `arus_kas` |
| Tamu — Event & Tiket | `event-tiket.md` | event, ticketing | importer `erp-event`, uang tiket ke `arus_kas` |
| Tamu — Acara Marketing | `acara-marketing.md` | marketing | importer `erp-acara`; VIP (M6) |
| SDM — Jadwal, Absensi, DW | `sdm.md` | jadwal, absensi, dw | importer `erp-sdm`, service upah DW |
| SDM — HR | `hr.md` | hr | importer `erp-hr`, service People Score (M5, M7) |
| SDM — Akademi | `akademi.md` | akademi | importer `erp-akademi` |
| Konten | `konten.md` | konten | importer `erp-konten` |
| Di luar ERP (usulan) | `peta-core.md` | hlife | menunggu M2 |

## Status kerja (2026-10-07) — baca ini dulu kalau melanjutkan

Sudah di `main`: semua migration v2 `2026_10_05_*` dan `2026_10_07_*` (daftar tabel per area di `peta-core.md`), model di `app/Erp/<Area>/Models` (+ `app/Core/Models` untuk log dan notifikasi), tes skema `tests/Feature/Erp/*SchemaTest.php`, importer `erp-barang` dan `erp-persediaan`.

Tes:

- `php artisan test tests/Feature/Erp --filter=Schema`: cukup database kosong (tidak butuh dump). Per 2026-10-07 lolos di Docker MariaDB 10.11 dan MySQL 8.4, termasuk `migrate` → `migrate:rollback` → `migrate`.
- Tes importer (`*ImportTest.php`) butuh dump lama yang sudah dipulihkan (CLAUDE.md §0).

Belum dikerjakan, urutan yang disarankan:

1. **Importer per area** (`core:import erp-orang`, lalu area lain sesuai urutan di `peta-core.md`), dengan pencocok nama → User bersama. Butuh mesin dengan dump lama.
2. **Service** aturan yang dulu dihitung di peramban: Modal resep, saldo dompet, tiga angka omset, upah DW, People Score. Bandingkan dengan layar lama.
3. **Kontrak API v2** per area di `docs/api/v2/<area>.md` (umumkan ke Tim A sebelum dibangun), lalu auto-load route `app/Erp/*/routes/v2.php` dan middleware Akses.
4. **Uang ke `arus_kas`** dari tiket, DP reservasi, pembayaran acara, honor talent, transfer DW, realisasi PO Proyek.
5. Pindahkan foto/bukti dari blob database lama ke penyimpanan berkas.
6. Migration penghapus tabel `core` per-modul yang dibekukan, setelah Modul-nya pindah ke v2.

Menunggu orang lain: jawaban owner putaran 2 (L1–L11) dan 3 (M1–M8) di `pertanyaan-owner.md`; keputusan Purchasing/Kitchen atas daftar yang dicetak importer Barang/Persediaan.

## Aturan kerja

- Jangan menambah tabel, kolom, atau importer ke tabel core per-modul yang sekarang (`<modul>_*`). Tabel itu dibekukan (ADR-0006).
- Pertanyaan yang belum dijawab owner memakai **perilaku sistem lama** sebagai asumsi (bentuk tabel lama tidak diikuti), dicatat di bagian D `pertanyaan-owner.md`.
- Setiap jawaban owner langsung dicatat: istilah ke `CONTEXT.md`, keputusan ke ADR baru, jawaban mentah ke `pertanyaan-owner.md`.
  Claude di tim lain hanya tahu apa yang tertulis di repositori.
- `CHECK (kolom IN (...))` pada kolom teks membandingkan dengan collation `utf8mb4_unicode_ci`, jadi **tidak membedakan huruf besar-kecil** (`'PAID'` = `'paid'`). Semua perbandingan di database juga begitu, jadi tidak ada salah hitung, tetapi ejaan baku (huruf kecil) dijaga service dan importer, bukan database.
- Query analisis hanya di MySQL lokal, tidak pernah di dev atau production (CLAUDE.md §0).
