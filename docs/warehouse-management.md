# Penetapan gudang oleh superadmin

Gudang penyedia ditetapkan pada varian produk master oleh superadmin. Seluruh listing toko yang menawarkan produk tersebut mengikuti penetapan pusat secara langsung; admin toko tidak memiliki pilihan gudang pada form tambah maupun edit Produk Toko.

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

## Batas fungsi

Penetapan menunjukkan gudang penyedia varian, bukan jumlah stok aktual atau reservasi barang. Katalog dan order manual tetap dapat diproses tanpa penetapan gudang; verifikasi stok fisik pada mode manual masih dilakukan admin. Request pemeriksaan/reservasi stok eksternal saat ini memakai SKU dan quantity, belum mengirim pilihan `warehouse_code` sebagai instruksi alokasi gudang. Pembagian pengiriman dan penentuan gudang pemenuhan order memerlukan integrasi lanjutan.

Perubahan ini memakai tabel yang sudah tersedia dan tidak membutuhkan migration tambahan khusus penetapan gudang.
