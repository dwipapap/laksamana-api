# Area: Pembelian & Persediaan

Status: **langkah 1–5 sebagian**. Jawaban owner putaran 1 sudah masuk. ERD final menunggu **L1** (modul ESB apa yang dipakai, ADR-0008), L5–L7, dan L11.
Istilah: `CONTEXT.md` bagian *Operations & stock*. Aturan tabel: ADR-0007.

## Yang sudah diputuskan

| Hal | Keputusan | Sumber |
|---|---|---|
| Katalog | Satu katalog **Barang** untuk Stock dan HPP | Q3 |
| Alur pembelian | Tetap dua alur terpisah: **Pesanan Bahan** (Stock, bahan baku) dan **PO Proyek** (BD) | Q4 |
| Beli tanpa pesanan | **Pembelian Langsung** adalah dokumen sendiri, bukan Pesanan Bahan | P1 |
| Persetujuan | Belum ada. Siklus dokumen tanpa langkah "disetujui"; nanti ditambah sebagai Kewenangan (`akses.md`) tanpa mengubah tabel | P5 |
| Tagihan | Satu **Tagihan Vendor** bisa untuk beberapa pesanan (N:M lewat baris tagihan) | P4 |
| Lokasi | Satu Outlet + Central Kitchen, stok masing-masing dihitung terpisah | P2 |
| Satuan | Diatur per Barang: satuan sah, **Satuan Dasar**, **Ukuran Satuan**. Stok selalu dalam Satuan Dasar, dikonversi sekali saat ditulis | P3 + cek Stock lama |
| Uang | Rupiah bulat `DECIMAL(15,0)`; harga per Satuan Dasar `DECIMAL(15,4)` | Q5, ADR-0007 |
| Akuntansi | Tidak dibangun; ESB yang memegang | P7, ADR-0008 |

## Master data (langkah 3, usulan)

```mermaid
erDiagram
    barang ||--o{ barang_satuan : "satuan sah + ukuran"
    barang }o--|| satuan : "satuan dasar"
    barang_satuan }o--|| satuan : memakai
    barang ||--o{ barang_vendor : "vendor utama / cadangan"
    pihak ||--o{ barang_vendor : memasok
    lokasi ||--o{ barang_lokasi : "disimpan di (outlet, CK, keduanya)"
    barang ||--o{ barang_lokasi : disimpan

    barang { ulid id; string nama; ulid satuan_dasar_id; string kategori; string sumber "vendor | ck | keduanya"; bool aktif }
    satuan { ulid id; string nama "Gram, Kg, Pcs, Ekor, ..." }
    barang_satuan { ulid barang_id; ulid satuan_id; decimal ukuran "berapa satuan dasar; >0" }
    lokasi { ulid id; string nama; string jenis "outlet | central_kitchen"; time jam_batas }
```

- Ukuran Satuan disimpan sebagai riwayat (`berlaku_dari`). Mutasi yang sudah ditulis menyimpan qty dalam Satuan Dasar, jadi mengubah ukuran tidak menulis ulang masa lalu. Aturan ini sudah dipegang `lib_stock_ck.php`.
- `pihak` (vendor) dirancang bersama area SDM/orang; apakah Stock dan BD berbagi daftar vendor menunggu L5.
- Barang berkunci `id`, bukan nama. Di modul lama, `products` dan `vendors` berkunci nama dan `orders.item` merujuk lewat teks; pemetaan nama → id dibuat saat impor.

## Opname & Penyesuaian Stok (cara mencatat, diserahkan ke Tim B)

Owner: opname belum dipakai (layar Daily Stock Opname lama ada, 0 baris). Arahnya, setiap Head boleh memutuskan koreksi. Usulan pencatatannya:

1. **Opname** (dokumen per Lokasi per Hari Operasional): siapa menghitung, kapan, dan per Barang qty **sistem** (dipotret saat mulai), qty **fisik**, serta catatan. Qty yang belum dihitung disimpan `NULL`, berbeda dari `0`; aturan ini sudah ada di `opname.php` lama. Selisih tidak disimpan, selalu dihitung fisik − sistem. Status: `draf` → `selesai`.
2. **Penyesuaian Stok** (dokumen terpisah, merujuk satu Opname): dibuat oleh User yang punya Kewenangan `stok.koreksi` untuk Lokasi itu (Head, L11). Setiap baris berisi Barang, qty penyesuaian (+/−), dan **alasan wajib** yang dipilih dari daftar (rusak, kedaluwarsa, salah catat, hilang, lainnya) ditambah catatan bebas. Status: `draf` → `disahkan`. Setelah disahkan, dokumen tidak bisa diubah, dan koreksi atasnya dibuat dengan penyesuaian baru.
3. Mutasi stok dari penyesuaian ditulis sebagai baris mutasi biasa yang menunjuk dokumen penyesuaiannya. Saldo tetap `SUM(masuk) − SUM(keluar)`, tidak pernah disimpan, sesuai aturan CK lama.
4. Kalau nanti owner menetapkan batas nilai (L11), penyesuaian di atas batas mendapat status `menunggu` sebelum `disahkan`. Itu menambah satu nilai status, bukan tabel baru.

Dengan pola ini, setiap selisih punya jejak siapa memutuskan, kenapa, dan opname mana asalnya, tanpa mengubah riwayat mutasi.

## Belum dijawab

L1 (ESB inventory/purchasing dipakai atau tidak) menentukan apakah Laksamana memegang buku stok sendiri atau hanya melengkapi ESB. Karena itu tabel dokumen (Pesanan Bahan, penerimaan, Tagihan Vendor, mutasi) belum digambar.
