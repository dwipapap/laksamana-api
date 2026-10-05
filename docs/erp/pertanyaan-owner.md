# Pertanyaan untuk owner

Pertanyaan bisnis yang menentukan bentuk database ERP (v2). Jawaban kasar sudah cukup; Tim B yang menerjemahkannya menjadi tabel.
Setelah dijawab: isi kolom **Jawaban** dan **Tanggal**, lalu catat hasilnya di `CONTEXT.md` atau ADR baru (kolom **Dicatat di**).

Status per 5 Oktober 2026: putaran 1 (Q1–Q6, P1–P7) **sudah dijawab**. Putaran 2 (L1–L11) **menunggu owner**; sementara itu dipakai asumsi dari perilaku sistem lama (bagian D).

## A. Master data (dari audit core)

| # | Pertanyaan | Yang ditentukan | Jawaban | Tanggal | Dicatat di |
|---|---|---|---|---|---|
| Q1 | Apakah kru di HR, Akademi, Marketing, Konten, BD, dan Stock selalu User Office yang sama? Adakah orang yang tercatat di modul tetapi tidak pernah login, selain pekerja harian dan talent? | Apakah 12 daftar orang bisa disatukan menjadi satu tabel `pihak` | **Ya, sama.** Tambahan: di dalam modul harus ada pengaturan khusus, sehingga kru tertentu tidak melihat atau tidak bisa memakai sebagian fitur. | 2026-10-02 | `CONTEXT.md` (User, Lingkup); rancangan di [`akses.md`](akses.md) |
| Q2 | Apakah divisi di HR dan Akademi sama dengan empat divisi shift ditambah kantor, atau daftar lain? | Apakah `hr_divisions` dan `akademi_divisions` melebur ke `divisi` | **Disamaratakan:** satu daftar divisi untuk semua modul. | 2026-10-02 | daftar finalnya: L4 |
| Q3 | Apakah daftar bahan di Stock (`products`) dan di HPP (`hpp_bahan`) adalah barang yang sama? | Satu katalog barang atau dua | **Sama.** Satu katalog barang. | 2026-10-02 | `CONTEXT.md` (Barang) |
| Q4 | Pembelian di Stock dan di BD: A satu alur, B satu tabel dua jenis, atau C tetap terpisah? | Bentuk area Pembelian | **C, tetap terpisah.** Stock untuk bahan baku; PO di BD adalah hal yang berbeda. | 2026-10-02 | `CONTEXT.md` (Pesanan Bahan, PO Proyek); vendor bersama: L5 |
| Q5 | Apakah ada nominal dengan sen, atau semua rupiah bulat? | Aturan pembulatan | **Semua rupiah selalu bulat.** | 2026-10-02 | ADR-0007 (uang) |
| Q6 | Jam berapa satu hari operasional dianggap berganti? | `tanggal_bisnis` | **Minta dibuat fleksibel**, seperti buka shift dan tutup shift yang dihitung satu sesi. | 2026-10-02 | `CONTEXT.md` (Hari Operasional); rancangan di [`hari-operasional.md`](hari-operasional.md) |

## B. Pembelian & Persediaan

| # | Pertanyaan | Yang ditentukan | Jawaban | Tanggal | Dicatat di |
|---|---|---|---|---|---|
| P1 | Siapa yang boleh memesan ke vendor, dan apakah setiap pembelian selalu lewat PO? | Satu atau dua jenis dokumen pembelian | **Pembelian langsung tidak lewat PO**; ia dokumen yang berbeda. | 2026-10-02 | `CONTEXT.md` (Pembelian Langsung); siapa yang mencatat: L6 |
| P2 | Outletnya satu, atau akan bertambah? Apakah Central Kitchen punya stok sendiri? | Daftar `lokasi` awal | **Satu outlet dulu.** Central Kitchen punya stok sendiri yang dihitung terpisah. | 2026-10-02 | `CONTEXT.md` (Lokasi) |
| P3 | Dalam satuan apa barang dibeli, disimpan, dan dipakai? | Tabel satuan dan konversinya | **Dipilih per barang lewat pengaturan.** Diminta dicek ulang di modul Stock lama (hasilnya di bawah). | 2026-10-02 | `CONTEXT.md` (Satuan Dasar, Ukuran Satuan) |
| P4 | Apakah satu PO bisa dikirim bertahap, dan apakah satu tagihan vendor bisa untuk beberapa PO? | Tautan PO, penerimaan, tagihan | **Satu tagihan vendor bisa untuk beberapa PO.** (Pengiriman bertahap tidak dijawab; dirancang boleh.) | 2026-10-02 | `CONTEXT.md` (Tagihan Vendor); tempat pencatatannya: L7 |
| P5 | Siapa yang menyetujui pembelian, dan apakah ada batas belanja? | Langkah persetujuan | **Belum ada persetujuan saat ini.** | 2026-10-02 | siklus dokumen tanpa langkah setuju; tempatnya disiapkan sebagai Kewenangan ([`akses.md`](akses.md)) |
| P6 | Kalau hasil opname berbeda dengan sistem, siapa yang memutuskan koreksinya? | Penyesuaian stok | **Opname belum dipakai.** Arahnya: setiap Head boleh memutuskan koreksi. Cara mencatatnya diserahkan ke Tim B. | 2026-10-02 | `CONTEXT.md` (Opname, Penyesuaian Stok) |
| P7 | Apakah akuntansinya ada di sistem ini, atau dikirim ke software akuntansi? | Apakah buku besar dibangun | **Tetap memakai ESB (Esensi Solusi Buana).** Laksamana berposisi sebagai pendukung ESB. | 2026-10-02 | [ADR-0008](../adr/0008-laksamana-supports-esb.md) |

### Hasil cek ulang satuan di modul Stock lama (P3)

Diperiksa di `laksamana-office/stock-mysql` dan salinan lokal `lakk5493_db_stock` (395 barang):

- Setiap barang sudah punya pengaturan sendiri di blob `products.data`:
  - `satuan[]`: satuan yang sah untuk barang itu. 272 barang punya lebih dari satu.
  - `satuanDasar`: satuan terkecil tempat stok dihitung, misalnya Gram, Ml, atau Pcs. Terisi di 263 barang.
  - `isi`: berapa satuan dasar dalam satu satuan lain, misalnya `{"Kg":1000}`, `{"Ekor":4}`, atau `{"Pcs":946}` untuk Apple Juice per botol. Terisi di 249 barang.
- Rasionya memang berbeda per barang (1 Ekor ayam = 4 atau 16 potong), jadi tidak ada konversi umum.
- Central Kitchen masih memakai pola lama `packIsi`/`packSatuan` (13 barang). Konversinya dilakukan sekali saat data masuk, dan stok disimpan dalam satuan dasar supaya riwayat tidak berubah ketika isi pack diubah. Aturan ini dipertahankan di v2.
- HPP (`hpp_bahan`) menyimpan satuan dasar (mayoritas Gram) beserta `qty_beli` dan `harga_beli` per kemasan beli, sehingga harga per satuan terkecil dihitung dari keduanya.
- Pesanan (`orders.unit`) paling sering dalam Kg (1.510), Pack (421), dan Pcs (297).
- Layar Daily Stock Opname (`opname.php`) sudah ada tetapi belum pernah dipakai (0 baris). Ini cocok dengan jawaban P6.

## C. Putaran 2 — pertanyaan lanjutan (menunggu owner)

| # | Pertanyaan | Kenapa ditanyakan | Jawaban | Tanggal | Dicatat di |
|---|---|---|---|---|---|
| L1 | **Paling penting.** Modul ESB apa saja yang dipakai: hanya POS (kasir), atau juga back office ESB (persediaan, pembelian, akuntansi)? | Kalau persediaan dan pembelian sudah di ESB, area Pembelian & Persediaan di Laksamana harus menjadi pelengkap, bukan sistem kedua yang mencatat hal sama | | | |
| L2 | Data apa yang sekarang diketik ulang ke ESB dari Laksamana (atau sebaliknya)? Apakah ESB menyediakan API, atau hanya ekspor Excel? | Menentukan integrasi: impor berkas seperti sekarang, atau koneksi langsung | | | |
| L3 | Untuk pengaturan fitur per kru: siapa yang mengaturnya, admin tiap modul (seperti Kas dan Brankas sekarang), atau satu orang pusat (HRD atau Superadmin)? Beri satu contoh nyata: modul apa, kru siapa, fitur apa yang harus tersembunyi. | Menguji rancangan di `akses.md` dengan kasus asli | | | |
| L4 | Daftar divisi bersama yang final. HR punya: Bar, Kitchen, Store/Service, Event, Finance, HR/GA/Legal, Marketing & Digital, Management. Akademi punya: Galangan Bar, Galangan Dapur, Service/FOH, Sales & Event, Finance & Admin, Marketing & Digital. Jadwal memakai bar, kitchen, floor, cashier. Apakah Store/Service = floor + cashier? Apakah "Event" sama dengan "Sales & Event"? | Satu tabel `divisi` dengan penanda divisi shift atau kantor | | | |
| L5 | Walau alur Stock dan BD terpisah, apakah daftar vendornya boleh satu (vendor yang sama hanya dicatat sekali)? | Satu master vendor atau dua | | | |
| L6 | Pembelian langsung (tunai, tanpa PO): siapa yang mencatat sekarang, apakah lewat Kas Kecil, dan apakah barangnya ikut menambah stok? | Tautan ke Kas Kecil dan ke stok | | | |
| L7 | Tagihan vendor sekarang dicatat dan dibayar di mana: Laksamana (Brankas/Kas Kecil), ESB, atau keduanya? | Apakah Tagihan Vendor dibuat di v2 atau cukup dirujuk dari ESB | | | |
| L8 | Hari operasional: siapa yang menekan "Buka" dan "Tutup" (kasir, captain, manager)? Apakah tutup hari sama dengan saat Report Daily dikirim? | Siapa yang memegang Hari Operasional | | | |
| L9 | Apakah di ESB kasir melakukan tutup shift / End of Day, dan apakah laporannya bisa diekspor dengan jam buka dan jam tutup? | Kalau bisa, jam buka-tutup diambil dari ESB, tidak dua kali dicatat | | | |
| L10 | Central Kitchen bekerja dengan jam sendiri (misalnya pagi sampai sore), atau ikut jam outlet? | CK punya Hari Operasional sendiri atau cukup memakai tanggal kalender | | | |
| L11 | Untuk koreksi opname: Head divisi mana untuk lokasi mana? Misalnya Head Kitchen untuk dapur dan CK, Head Bar untuk bar. Apakah perlu batas nilai koreksi, sehingga yang besar butuh persetujuan owner? | Siapa yang punya Kewenangan "koreksi stok" | | | |

## D. Asumsi sementara: ikuti perilaku sistem lama

Keputusan 2026-10-05: selama putaran 2 belum dijawab, rancangan **mengikuti perilaku sistem lama** (alur, siapa melakukan apa, aturan yang sudah berjalan). Bentuk tabel lama **tidak** diikuti: hasilnya tetap ERD relasional sesuai ADR-0007.
Setiap asumsi di bawah dipakai sampai owner menjawab lain. Kalau jawabannya berbeda, yang diubah adalah baris di sini dan bagian ERD yang disebut di kolom terakhir.

| # | Asumsi sementara (perilaku lama) | Bukti di sistem lama | Kalau owner menjawab lain |
|---|---|---|---|
| L1 | Laksamana mencatat stok operasionalnya sendiri, terpisah dari ESB; ESB hanya POS + akuntansi | modul Stock punya buku stok CK (`ck_stock`), pemakaian, waste, serah terima; tidak ada sinkron stok dengan ESB | kalau ESB juga memegang persediaan: buku stok v2 menjadi pelengkap, mutasi dikirim/dicocokkan ke ESB |
| L2 | Data ESB masuk lewat **berkas ekspor** (Bill Report, Menu Report); tidak ada API. Penanda "Input POS" dicentang manual setelah nominal diketik di POS | modul Analytics mengurai ekspor POS; Kompas menyimpan penanda `esb` | kalau ada API: impor berkas diganti koneksi di `app/Erp/Esb`, tabelnya tetap |
| L3 | Akses Halaman diatur **admin modul masing-masing** | Kas dan Brankas: matriks peran diatur admin panel itu (`kk_peran`, `bk_peran`) | kalau satu orang pusat: hanya siapa yang boleh mengubah matriks, tabel `akses.md` tetap |
| L4 | Satu daftar `divisi`: Bar, Kitchen, Floor, Cashier (divisi shift, dari Jadwal) + Event, Finance, HR/GA/Legal, Marketing & Digital, Management (kantor). Pemetaan: Galangan Bar → Bar, Galangan Dapur → Kitchen, Sales & Event → Event, Finance & Admin → Finance; Store/Service dan Service/FOH dipecah ke Floor atau Cashier menurut Penempatan Divisi orangnya | tiga daftar lama (HR 8, Akademi 6, Jadwal 4) | baris divisi dan tabel pemetaan saja |
| L5 | **Satu daftar vendor** (`pihak` berperan vendor) dipakai Stock, BD, dan pembayaran | Planning Pembayaran sudah membaca rekening dari Daftar Kontak Vendor Purchasing; issue #178 meminta BD membaca master vendor Stock | kalau dipisah: tambah kolom `pemakai` pada peran vendor |
| L6 | Pembelian Langsung dicatat di **Kas Kecil** sebagai pengeluaran berkategori (misalnya COGS) dan **tidak menambah stok** | `kk_trx` + `kk_kategori`; tidak ada jalur dari Kas Kecil ke stok | kalau menambah stok: baris Pembelian Langsung diberi Barang + qty dan menulis mutasi |
| L7 | Tagihan vendor dibayar lewat **Planning Pembayaran** (lembar per tanggal bayar, dikelompokkan per rekening pembayar, bukti bayar dengan siapa/kapan); satu baris bayar boleh menunjuk beberapa Pesanan Bahan (jawaban P4) | Planning Pembayaran di Kas Kecil, data di `bk_state.bayar` | kalau dicatat di ESB: tabel tagihan menjadi rujukan nomor ESB saja |
| L8 | Hari Operasional outlet **dibuka otomatis** oleh transaksi pertama setelah jam batas, dan **ditutup saat Report Daily dikirim** (kasir) | Report Daily di Cashier per tanggal; Input Omset memilih tanggal | hanya siapa/kapan tombol buka-tutup ditekan |
| L9 | Jam buka-tutup **tidak** diambil dari ESB | ekspor ESB yang diurai Analytics tidak memakai kolom shift | kalau ekspor punya End of Day: diimpor ke `hari_operasional` |
| L10 | Central Kitchen memakai **tanggal kalender** (tanpa buka-tutup) | `ck_stock.tanggal` diisi tanggal kalender | CK diberi Hari Operasional seperti outlet |
| L11 | Penyesuaian stok boleh dibuat oleh **Kepala Divisi** lokasi/area barangnya dan oleh admin modul Stock; tanpa batas nilai; alasan wajib | CK sudah mencatat `penyesuaian` masuk/keluar langsung di buku stok | tambah status `menunggu` untuk koreksi di atas batas |
