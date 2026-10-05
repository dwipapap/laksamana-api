# Pertanyaan untuk owner

Pertanyaan bisnis yang menentukan bentuk database ERP (v2). Jawaban kasar sudah cukup; Tim B yang menerjemahkannya menjadi tabel.
Setelah dijawab: isi kolom **Jawaban** dan **Tanggal**, lalu catat hasilnya di `CONTEXT.md` atau ADR baru (kolom **Dicatat di**).

Status: semua pertanyaan **menunggu owner** (per 2 Oktober 2026).

## A. Master data (dari audit core)

| # | Pertanyaan | Yang ditentukan | Jawaban | Tanggal | Dicatat di |
|---|---|---|---|---|---|
| Q1 | Apakah kru di HR, Akademi, Marketing, Konten, BD, dan Stock selalu User Office yang sama? Adakah orang yang tercatat di modul tetapi tidak pernah login, selain pekerja harian dan talent? | Apakah 12 daftar orang bisa disatukan menjadi satu tabel `pihak` | | | |
| Q2 | Apakah divisi di HR dan Akademi sama dengan empat divisi shift (bar, kitchen, floor, cashier) ditambah kantor, atau daftar lain seperti departemen? | Apakah `hr_divisions` dan `akademi_divisions` melebur ke `divisi` | | | |
| Q3 | Apakah daftar bahan di Stock (`products`) dan di HPP (`hpp_bahan`) adalah barang yang sama? | Satu katalog barang atau dua | | | |
| Q4 | Pembelian di Stock dan di BD: A. satu alur penuh (permintaan → PO → penerimaan → tagihan), B. satu tabel dengan kolom jenis (operasional / proyek-event) dan aturan persetujuan berbeda, atau C. tetap terpisah, hanya berbagi master vendor dan barang? Barang apa yang dibeli lewat BD, siapa yang menyetujui di tiap sistem, dan apakah vendornya sama? | Bentuk area Pembelian | | | |
| Q5 | Apakah ada nominal dengan sen (misalnya hasil pembagian porsi), atau semua rupiah bulat? | Aturan pembulatan (kolom tetap `DECIMAL(15,2)`, ADR-0007) | | | |
| Q6 | Jam berapa satu hari operasional dianggap berganti, misalnya 05.00? | Aturan `tanggal_bisnis` untuk omset, shift, dan absensi | | | |

## B. Pembelian & Persediaan

| # | Pertanyaan | Yang ditentukan | Jawaban | Tanggal | Dicatat di |
|---|---|---|---|---|---|
| P1 | Siapa yang boleh memesan ke vendor, dan apakah setiap pembelian selalu lewat PO? Atau ada pembelian langsung (beli tunai di pasar, dibayar dari kas kecil) yang tidak pernah punya PO? | Satu atau dua jenis dokumen pembelian | | | |
| P2 | Outletnya satu, atau akan bertambah? Apakah Central Kitchen dihitung sebagai lokasi sendiri dengan stok sendiri? | Daftar `lokasi` awal | | | |
| P3 | Dalam satuan apa setiap barang dibeli, disimpan, dan dipakai? Misalnya dibeli per karton, disimpan per gram, dipakai per porsi. | Tabel satuan dan konversinya | | | |
| P4 | Apakah satu PO bisa dikirim dalam beberapa tahap, dan apakah satu tagihan vendor bisa untuk beberapa PO? | Tautan PO, penerimaan, dan tagihan | | | |
| P5 | Siapa yang menyetujui pembelian, dan apakah ada batas belanja per orang atau per divisi? | Langkah persetujuan dan statusnya | | | |
| P6 | Kalau hasil opname berbeda dengan sistem, siapa yang memutuskan koreksinya dan bagaimana dicatat? | Cara kerja penyesuaian stok | | | |
| P7 | Apakah akuntansinya ingin ada di sistem ini, atau dikirim ke software akuntansi yang sudah atau akan dipakai (misalnya Accurate, Jurnal.id, Odoo)? | Apakah buku besar dibangun sama sekali | | | |
