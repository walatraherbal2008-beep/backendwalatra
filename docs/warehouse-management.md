# Penetapan gudang oleh superadmin

Gudang penyedia ditetapkan pada varian produk master oleh superadmin. Seluruh listing toko yang menawarkan produk tersebut mengikuti penetapan pusat secara langsung; admin toko tidak memiliki pilihan gudang pada form tambah maupun edit Produk Toko.

## Titik Pengiriman

Menu **Manajemen Marketplace → Titik Pengiriman** tersedia khusus superadmin aktif pada `/superadmin/titik-pengiriman`.

- Tabel menampilkan nama titik pengiriman, wilayah, dan grup Pulau Jawa/Luar Jawa dari API eksternal.
- Pencarian mencocokkan nama atau wilayah; filter membatasi grup wilayah. **Muat ulang** mengambil kembali daftar dari sumber.
- **Lihat detail** membuka panel samping berisi nama, kode, wilayah, grup, alamat lengkap, kode pos, dan koordinat.
- Koordinat pasangan `latitude, longitude` yang valid menyediakan tautan Google Maps. Data yang kosong ditampilkan sebagai **Belum tersedia**.
- Jika API gagal, halaman menampilkan pemberitahuan dan dapat dimuat ulang. Respons detail HTTP 200 yang seluruh identitasnya kosong dianggap tidak valid.

Halaman ini hanya membaca informasi. Tidak ada tambah, edit, atau hapus titik pengiriman; penetapan titik penyedia produk tetap dilakukan pada **Edit Varian**.

Daftar memakai `POST /index.php?procs=gudang.list`. Detail memakai `POST /index.php?procs=gudang.detail` dengan body `application/x-www-form-urlencoded` berisi `code` dari daftar. Membuka URL detail dengan GET atau mengirim body JSON tidak menghasilkan detail yang terisi pada API sumber yang diperiksa.

Path detail dapat disesuaikan melalui `WAREHOUSE_DIRECTORY_DETAIL_PATH`; default-nya `/index.php?procs=gudang.detail`. Koneksi memakai konfigurasi `WAREHOUSE_DIRECTORY_*` yang sudah ada.

## Mengatur gudang

1. Login sebagai superadmin aktif melalui `/superadmin`.
2. Buka **Katalog → Produk → Edit** produk yang akan diatur.
3. Pada tabel **Varian Produk**, klik **Edit** pada varian yang diinginkan.
4. Pada field **Gudang penyedia** di form Edit Varian, pilih satu atau beberapa gudang dari direktori gudang eksternal.
5. Simpan form untuk memperbarui data varian dan gudang sekaligus.
6. Ulangi untuk varian lain jika diperlukan. Setiap varian dapat memiliki gudang penyedia berbeda.

Kolom **Gudang penyedia** menampilkan maksimal tiga nama gudang yang sudah ditetapkan. Penetapan lainnya tetap tersimpan, tetapi tidak ditampilkan di tabel. Jika belum ditetapkan, kolom kosong. Nama diambil dari direktori gudang; kode tidak ditampilkan sebagai pengganti jika nama tidak tersedia.

Mengosongkan pilihan pada form menghapus penetapan untuk varian tersebut, bukan menghapus barang atau stok fisik. Varian baru belum memiliki penetapan sampai superadmin mengaturnya melalui Edit Varian. Jika penyimpanan gudang atau validasi varian gagal, seluruh perubahan form dibatalkan agar data tetap konsisten.

## Aturan data dan akses

- Form dan aksi pengaturan hanya dapat digunakan superadmin aktif. Admin toko hanya mengelola penawaran tokonya dan tidak dapat mengubah penetapan pusat melalui form listing.
- Penetapan tersimpan pada `warehouse_product_variants`, per varian. `StoreProductListing::warehouseAssignments()` membaca penetapan varian aktif produk master. Varian nonaktif atau terhapus tidak disertakan.
- Perubahan pusat berlaku untuk listing lama maupun baru di semua toko tanpa proses penyalinan atau sinkronisasi per toko. Bundle mengikuti penetapan pada masing-masing varian komponennya; tidak ada pilihan gudang terpisah pada bundle toko.
- Data lama pada `store_product_listing_warehouses` dipertahankan, tetapi tidak dipakai sebagai override. Service penetapan gudang per listing sudah dihapus.
- Kode gudang yang tidak dikenal ditolak. Jika API direktori tidak tersedia, form menampilkan pemberitahuan dan penyimpanan tidak menghapus penetapan yang sudah ada.

## Pemilihan titik pengiriman saat checkout

Checkout memeriksa penetapan semua varian terpilih, termasuk setiap komponen bundle. Seluruh barang pada satu order harus memiliki satu gudang bersama. Varian tanpa penetapan ditolak dengan `WAREHOUSE_NOT_ASSIGNED`; barang tanpa gudang bersama ditolak dengan `WAREHOUSE_MISMATCH`.

Untuk operasional sementara, pilih hanya gudang Tasikmalaya pada setiap varian yang akan dijual. Checkout otomatis memakai gudang yang ditetapkan, tanpa kode Tasikmalaya permanen dalam aplikasi. Jika beberapa gudang bersama tersedia, konfigurasi `CHECKOUT_WAREHOUSE_CODE` harus berisi kode prioritas yang juga dimiliki semua varian; jika tidak diisi, checkout mengembalikan `WAREHOUSE_SELECTION_REQUIRED`.

Belum ada perhitungan jarak pembeli, integrasi Google Maps, atau pemecahan pengiriman. API direktori memberikan nama dan detail alamat asal. Jika direktori/detail gagal dimuat, checkout mengembalikan 503 dan tidak membuat order.

Nama, kode, alamat, kode pos, dan koordinat titik pengiriman disimpan sebagai snapshot `orders.shipping_origin` dan dapat dilihat pada detail order superadmin. Perubahan penetapan atau direktori berikutnya tidak mengubah asal pengiriman order lama. Data yang kosong pada sumber ditampilkan sebagai belum tersedia.

## Stok sementara

`CHECKOUT_STOCK_MODE=unlimited` menjadi default: semua jumlah stok dianggap tidak membatasi pembelian, tanpa pemeriksaan/reservasi/pengurangan stok eksternal. Penetapan gudang tetap wajib untuk checkout; katalog dan cart tetap dapat dibaca tanpa penetapan tersebut. Mode pembayaran manual dan gratis ongkir tidak berubah.

Mode stok terpisah dari `ORDER_PROCESSING_MODE`. Kontrak eksternal saat ini memakai SKU dan quantity; alokasi stok per gudang perlu disesuaikan ketika API stok siap. Tidak ada jumlah stok fisik yang diklaim tersedia hanya berdasarkan penetapan gudang.

Penetapan varian memakai tabel yang sudah tersedia. Snapshot asal dan mode stok pada order membutuhkan migrasi `2026_11_09_000000_add_shipping_origin_and_stock_mode_to_orders_table`.
