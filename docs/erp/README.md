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
| Akses di dalam Modul | semua modul | usulan di `akses.md`, menunggu L3 |
| Hari Operasional | finance, kompas, absensi, jadwal, stock | usulan di `hari-operasional.md`, menunggu L8–L10 |
| **Pembelian & Persediaan** (pertama) | stock, bd, finance (kas kecil) | ERD draf di `pembelian-persediaan.md` (asumsi perilaku lama, bagian D pertanyaan-owner) |
| Penjualan (omset, booking + DP, paket event, tiket) | finance, reservasi, event, ticketing, marketing | belum mulai |
| Kas (kas kecil, brankas, setoran, QRIS) | finance, kompas | belum mulai |
| SDM (absensi → jadwal → upah harian → bonus) | absensi, jadwal, dw, hr, akademi | belum mulai |

## Aturan kerja

- Jangan menambah tabel, kolom, atau importer ke tabel core per-modul yang sekarang (`<modul>_*`). Tabel itu dibekukan (ADR-0006).
- Pertanyaan yang belum dijawab owner memakai **perilaku sistem lama** sebagai asumsi (bentuk tabel lama tidak diikuti), dicatat di bagian D `pertanyaan-owner.md`.
- Setiap jawaban owner langsung dicatat: istilah ke `CONTEXT.md`, keputusan ke ADR baru, jawaban mentah ke `pertanyaan-owner.md`.
  Claude di tim lain hanya tahu apa yang tertulis di repositori.
- Query analisis hanya di MySQL lokal, tidak pernah di dev atau production (CLAUDE.md §0).
