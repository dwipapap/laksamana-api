# ERP (v2) — ruang kerja Tim B

Folder ini adalah titik kerja Tim B (platform & ERP). Keputusan dasarnya:
[ADR-0006](../adr/0006-erp-v2-redesigns-core-per-area.md) (core dirancang ulang per area, v2 terpisah dari v1),
[ADR-0007](../adr/0007-v2-table-conventions.md) (aturan tabel v2), dan [ADR-0008](../adr/0008-laksamana-supports-esb.md) (Laksamana mendukung ESB, tidak menggantikan POS atau akuntansinya). Kondisi core saat ini: [audit-core.md](../db/audit-core.md).

## Isi folder

| File | Isi |
|---|---|
| `README.md` | Cara kerja, urutan area, status |
| `peta-core.md` | Peta seluruh `core` v2: area, tabel bersama, hubungan antar-area, urutan merge PR |
| `pertanyaan-owner.md` | Pertanyaan bisnis yang menunggu jawaban owner, dan jawabannya |
| `akses.md` | Akses di dalam Modul: Peran, Akses Halaman, Lingkup, Kewenangan (jawaban Q1) |
| `hari-operasional.md` | Hari bisnis yang fleksibel lewat buka/tutup per Lokasi (jawaban Q6) |
| `po-proyek.md` | Proyek BD, Pengajuan Pembelian mingguan + penyetuju, PO Proyek + realisasi |
| `kas.md` | Dompet, Arus Kas, Kas Kecil, Mutasi Wallet, Setoran, Planning Pembayaran, Pengembalian Modal |
| `resep-hpp.md` | Resep, harga jual, Pengaturan HPP, Kontrol Bahan Baku; aturan hitung Modal dari layar HPP lama |
| `orang-divisi.md` | Master orang dan divisi: `karyawan`, peran Pihak (klien, talent, KOL, pekerja harian), divisi shift/kantor |
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
| Master data (pihak, barang + satuan, lokasi) | account, hr, stock, bd, marketing, … | Q1–Q3 dijawab; daftar divisi menunggu L4, vendor bersama menunggu L5 |
| Akses di dalam Modul | semua modul | tabel jadi (`akses.md`); middleware `halaman:` dan seeder halaman per Modul belum |
| Master data: barang + satuan, lokasi, vendor | stock, hpp | tabel + importer selesai |
| Master data: Orang & Divisi (`orang-divisi.md`) | account, hr, akademi, marketing, konten, bd, stock, dw, event | ERD + tabel selesai (asumsi L4, L5); importer `erp-orang` belum (butuh dump) |
| Akses di dalam Modul | semua modul | usulan di `akses.md`, menunggu L3 |
| Hari Operasional | finance, kompas, absensi, jadwal, stock | usulan di `hari-operasional.md`, menunggu L8–L10 |
| **Pembelian & Persediaan** (pertama) | stock, bd, finance (kas kecil) | tabel master + dokumen stok + importer selesai (lihat bagian Status kerja); belum ada service/endpoint v2 |
| Proyek & PO Proyek (`po-proyek.md`) | bd | ERD + tabel selesai; importer `erp-po-proyek` belum |
| Resep & HPP (`resep-hpp.md`) | stock (hpp) | ERD + tabel selesai; importer `erp-resep` dan service Modal belum (butuh dump) |
| Penjualan (omset, booking + DP, paket event, tiket) | finance, reservasi, event, ticketing, marketing | belum mulai |
| Kas (`kas.md`) | finance, kompas | ERD + tabel selesai (asumsi L6, L7); importer `erp-kas` dan service saldo belum (butuh dump) |
| SDM (absensi → jadwal → upah harian → bonus) | absensi, jadwal, dw, hr, akademi | belum mulai |

## Status kerja (2026-10-07) — baca ini dulu kalau melanjutkan

Sudah di `main`:

| Bagian | Di mana |
|---|---|
| Tabel master v2 (lokasi, satuan, pihak, vendor, barang + satuan/vendor/lokasi/harga) | migration `2026_10_05_090000`, `_100000`; model `app/Erp/Master/Models` |
| Tabel dokumen stok + `hari_operasional` | migration `2026_10_05_110000`; model `app/Erp/Persediaan/Models` |
| Importer dari Stock/HPP lama | `core:import erp-barang`, lalu `core:import erp-persediaan` (urutan wajib); kasus yang butuh keputusan orang dicetak di akhir |
| Tabel Proyek & PO Proyek (`proyek`, `proyek_pic`, `pengajuan_pembelian` + penyetuju, `po_proyek`) | migration `2026_10_07_140000`; model `app/Erp/Proyek/Models` |
| Tabel Akses (`halaman`, `peran`, `peran_halaman`, `kewenangan`, `peran_kewenangan`, `penempatan_peran`) | migration `2026_10_07_120000`; model `app/Erp/Akses/Models` |
| Tabel Kas (`dompet`, `arus_kas`, `kas_kecil`, `mutasi_dompet`, `setoran`, `rencana_bayar`, `pembayaran`, `investor`, `pengembalian_modal`, …) | migration `2026_10_07_110000`; model `app/Erp/Kas/Models` |
| Tabel Resep & HPP (`resep`, `resep_baris`, `resep_harga`, `pengaturan_hpp`, `kontrol_bahan` + baris), kolom baru `satuan.keluarga/faktor`, `barang.dipesan` | migration `2026_10_07_100000`; model `app/Erp/Resep/Models` |
| Tes | `php artisan test tests/Feature/Erp` (24 tes; butuh dump lama yang sudah dipulihkan, lihat CLAUDE.md §0) |
| Tabel orang & divisi (`karyawan`, `klien`, `talent`, `kol`, `pekerja_harian` + divisi), kolom baru `divisi.nama/jenis/aktif`, `pihak.email/alamat/instagram` | migration `2026_10_07_090000`; model `app/Erp/Master/Models` |
| Tes | `php artisan test tests/Feature/Erp` (30 tes; tes importer butuh dump lama yang sudah dipulihkan, lihat CLAUDE.md §0; tes skema cukup database kosong) |

Belum dikerjakan, urutan yang disarankan:

0. Importer `core:import erp-orang` + pencocok nama → User bersama (`orang-divisi.md` bagian Belum dikerjakan). Butuh mesin dengan dump lama.
1. Kontrak API v2 Pembelian & Persediaan di `docs/api/v2/pembelian-persediaan.md` (umumkan ke Tim A sebelum dibangun).
2. Service + endpoint v2 pertama: buka/tutup Hari Operasional, pesanan bahan + check-in (menulis mutasi CK), saldo stok CK. Kode di `app/Erp/<Area>/` (`Services/`, `Http/V2/`, `routes/v2.php`); auto-load route v2 belum ada.
3. Akses di dalam Modul (`akses.md`): ~~tabel~~ (migration `2026_10_07_120000`); sisa middleware v2 + seeder halaman per Modul + impor matriks lama.
4. Area Kas: master `rekening`, Planning Pembayaran, `tagihan_vendor` (ditunda dari area ini).
3. Akses di dalam Modul (`akses.md`): tabel peran/halaman/lingkup/kewenangan + middleware v2.
4. ~~Area Kas~~: rancangan + tabel di `kas.md` (`dompet`, `pembayaran` menggantikan `rekening`/`tagihan_vendor`); importer belum.
5. ~~Uji semua migration v2 di MariaDB 10.11~~ (2026-10-06: semua migration + tes skema `tests/Feature/Erp` lolos di MariaDB 10.11 setelah `hari_operasional.lokasi_buka` diperbaiki, MariaDB menolak kolom stored `CASE … THEN <kolom CHAR>`; tes importer belum, butuh dump lama). Kolom generated v2 berikutnya: uji di MariaDB juga.
6. Pindahkan foto serah terima/waste dari blob database lama ke penyimpanan berkas.

Menunggu orang lain: jawaban owner putaran 2 (L1–L11, `pertanyaan-owner.md`; L1 paling menentukan), dan keputusan Purchasing/Kitchen atas daftar yang dicetak importer.

## Aturan kerja

- Jangan menambah tabel, kolom, atau importer ke tabel core per-modul yang sekarang (`<modul>_*`). Tabel itu dibekukan (ADR-0006).
- Pertanyaan yang belum dijawab owner memakai **perilaku sistem lama** sebagai asumsi (bentuk tabel lama tidak diikuti), dicatat di bagian D `pertanyaan-owner.md`.
- Setiap jawaban owner langsung dicatat: istilah ke `CONTEXT.md`, keputusan ke ADR baru, jawaban mentah ke `pertanyaan-owner.md`.
  Claude di tim lain hanya tahu apa yang tertulis di repositori.
- Query analisis hanya di MySQL lokal, tidak pernah di dev atau production (CLAUDE.md §0).
