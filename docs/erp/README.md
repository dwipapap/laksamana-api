# ERP (v2) — ruang kerja Tim B

Folder ini adalah titik kerja Tim B (platform & ERP). Keputusan dasarnya:
[ADR-0006](../adr/0006-erp-v2-redesigns-core-per-area.md) (core dirancang ulang per area, v2 terpisah dari v1),
[ADR-0007](../adr/0007-v2-table-conventions.md) (aturan tabel v2), dan [ADR-0008](../adr/0008-laksamana-supports-esb.md) (Laksamana mendukung ESB, tidak menggantikan POS atau akuntansinya). Kondisi core saat ini: [audit-core.md](../db/audit-core.md).

## Isi folder

| File | Isi |
|---|---|
| `README.md` | Cara kerja, urutan area, status |
| `pertanyaan-owner.md` | Pertanyaan bisnis yang menunggu jawaban owner, dan jawabannya |
| `akses.md` | Akses di dalam Modul: Peran, Akses Halaman, Lingkup, Kewenangan (jawaban Q1) |
| `hari-operasional.md` | Hari bisnis yang fleksibel lewat buka/tutup per Lokasi (jawaban Q6) |
| `orang-divisi.md` | Master orang dan divisi: `karyawan`, peran Pihak (klien, talent, KOL, pekerja harian), divisi shift/kantor |
| `event-tiket.md` | Tamu — Event (persetujuan, vendor, sponsor), kelas tiket, kursi, Buyer, pesanan, tiket, check-in, refund, jadwal & pembayaran talent |
| `<area>.md` | Rancangan satu area: kegiatan, istilah, ERD Mermaid, siklus dokumen, aturan, pemetaan dari tabel lama |

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

| Area | Menyentuh modul lama | Status |
|---|---|---|
| Master data: barang + satuan, lokasi, vendor | stock, hpp | tabel + importer selesai |
| Master data: Orang & Divisi (`orang-divisi.md`) | account, hr, akademi, marketing, konten, bd, stock, dw, event | ERD + tabel selesai (asumsi L4, L5); importer `erp-orang` belum (butuh dump) |
| Akses di dalam Modul | semua modul | usulan di `akses.md`, menunggu L3 |
| Hari Operasional | finance, kompas, absensi, jadwal, stock | usulan di `hari-operasional.md`, menunggu L8–L10 |
| **Pembelian & Persediaan** (pertama) | stock, bd, finance (kas kecil) | tabel master + dokumen stok + importer selesai (lihat bagian Status kerja); belum ada service/endpoint v2 |
| Tamu — Event & Tiket (`event-tiket.md`) | event, ticketing | ERD + tabel selesai, di atas Orang & Divisi; importer `erp-event` belum |
| Tamu — Reservasi, Acara Marketing | reservasi, marketing | Reservasi: PR terpisah; Acara Marketing belum mulai |
| Kas (kas kecil, brankas, setoran, QRIS) | finance, kompas | belum mulai |
| SDM (absensi → jadwal → upah harian → bonus) | absensi, jadwal, dw, hr, akademi | belum mulai |

## Status kerja (2026-10-07) — baca ini dulu kalau melanjutkan

Sudah di `main`:

| Bagian | Di mana |
|---|---|
| Tabel master v2 (lokasi, satuan, pihak, vendor, barang + satuan/vendor/lokasi/harga) | migration `2026_10_05_090000`, `_100000`; model `app/Erp/Master/Models` |
| Tabel dokumen stok + `hari_operasional` | migration `2026_10_05_110000`; model `app/Erp/Persediaan/Models` |
| Importer dari Stock/HPP lama | `core:import erp-barang`, lalu `core:import erp-persediaan` (urutan wajib); kasus yang butuh keputusan orang dicetak di akhir |
| Tabel orang & divisi (`karyawan`, `klien`, `talent`, `kol`, `pekerja_harian` + divisi), kolom baru `divisi.nama/jenis/aktif`, `pihak.email/alamat/instagram` | migration `2026_10_07_090000`; model `app/Erp/Master/Models` |
| Tabel Event & Tiket (`event`, `kelas_tiket`, `kursi`, `buyer`, `pesanan_tiket`, `tiket`, `checkin_tiket`, `refund_tiket`, jadwal/pembayaran talent, …) | migration `2026_10_07_160000`; model `app/Erp/Tamu/Models` |
| Tes | `php artisan test tests/Feature/Erp` (30 tes; tes importer butuh dump lama yang sudah dipulihkan, lihat CLAUDE.md §0; tes skema cukup database kosong) |

Belum dikerjakan, urutan yang disarankan:

0. Importer `core:import erp-orang` + pencocok nama → User bersama (`orang-divisi.md` bagian Belum dikerjakan). Butuh mesin dengan dump lama.
1. Kontrak API v2 Pembelian & Persediaan di `docs/api/v2/pembelian-persediaan.md` (umumkan ke Tim A sebelum dibangun).
2. Service + endpoint v2 pertama: buka/tutup Hari Operasional, pesanan bahan + check-in (menulis mutasi CK), saldo stok CK. Kode di `app/Erp/<Area>/` (`Services/`, `Http/V2/`, `routes/v2.php`); auto-load route v2 belum ada.
3. Akses di dalam Modul (`akses.md`): tabel peran/halaman/lingkup/kewenangan + middleware v2.
4. Area Kas: master `rekening`, Planning Pembayaran, `tagihan_vendor` (ditunda dari area ini).
5. ~~Uji semua migration v2 di MariaDB 10.11~~ (2026-10-06: semua migration + tes skema `tests/Feature/Erp` lolos di MariaDB 10.11 setelah `hari_operasional.lokasi_buka` diperbaiki, MariaDB menolak kolom stored `CASE … THEN <kolom CHAR>`; tes importer belum, butuh dump lama). Kolom generated v2 berikutnya: uji di MariaDB juga.
6. Pindahkan foto serah terima/waste dari blob database lama ke penyimpanan berkas.

Menunggu orang lain: jawaban owner putaran 2 (L1–L11, `pertanyaan-owner.md`; L1 paling menentukan), dan keputusan Purchasing/Kitchen atas daftar yang dicetak importer.

## Aturan kerja

- Jangan menambah tabel, kolom, atau importer ke tabel core per-modul yang sekarang (`<modul>_*`). Tabel itu dibekukan (ADR-0006).
- Pertanyaan yang belum dijawab owner memakai **perilaku sistem lama** sebagai asumsi (bentuk tabel lama tidak diikuti), dicatat di bagian D `pertanyaan-owner.md`.
- Setiap jawaban owner langsung dicatat: istilah ke `CONTEXT.md`, keputusan ke ADR baru, jawaban mentah ke `pertanyaan-owner.md`.
  Claude di tim lain hanya tahu apa yang tertulis di repositori.
- `CHECK (kolom IN (...))` pada kolom teks membandingkan dengan collation `utf8mb4_unicode_ci`, jadi **tidak membedakan huruf besar-kecil** (`'PAID'` = `'paid'`). Semua perbandingan di database juga begitu, jadi tidak ada salah hitung, tetapi ejaan baku (huruf kecil) dijaga service dan importer, bukan database.
- Query analisis hanya di MySQL lokal, tidak pernah di dev atau production (CLAUDE.md §0).
