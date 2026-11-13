# Monitoring Pesanan dan Transaksi

Menu **Manajemen Marketplace → Pesanan** tersedia di `/superadmin/orders`, khusus akun superadmin aktif. Pengelolaan pesanan dan monitoring transaksi menggunakan satu resource. URL lama `/superadmin/transaksi-toko` diarahkan ke Pesanan dengan pencarian dan filter tetap dibawa.

Seluruh order yang tersimpan di database ditampilkan secara default: manual, Midtrans sandbox/production, simulasi, dan penyedia lain yang telah tercatat. Satu baris merupakan satu order, walaupun berisi beberapa toko atau mempunyai beberapa percobaan pembayaran. Menu ini tidak mengimpor transaksi dari luar backend yang belum memiliki record order.

## Tampilan dan filter

Empat kartu ringkasan memakai `StatsOverviewWidget` bawaan Filament: jumlah pesanan, menunggu pembayaran, lunas, dan perlu pemeriksaan pembayaran. Nilai mengikuti filter serta pencarian.

Tabel menampilkan nomor order dengan customer/waktu di bawahnya, toko, sumber pembayaran, status, nominal, dan tindakan. Kolom tambahan seperti metode, lingkungan, WhatsApp, waktu dibuat, serta waktu lunas dapat diaktifkan melalui pemilih kolom. Lingkungan Midtrans juga ditampilkan pada keterangan sumber agar sandbox dan production tetap dapat dibedakan.

Pencarian mencakup nomor order, nama customer, serta WhatsApp. Filter tersedia untuk toko, status, sumber pembayaran, lingkungan, dan rentang tanggal pembuatan. Status/sumber lain yang sudah tercatat ikut tersedia dalam pilihan filter. Tanggal dan waktu menggunakan WIB; tanggal akhir filter termasuk seluruh hari tersebut. Toko nonaktif serta listing yang dihapus secara soft delete tetap dapat ditelusuri.

Nama toko/barang, quantity, dan harga menggunakan snapshot pembelian. Identitas toko ditelusuri dari listing, sehingga penggantian nama toko tidak memindahkan transaksi. Item lama tanpa relasi listing tetap terlihat pada daftar umum; identitasnya tidak ditebak berdasarkan nama.

## Pembaruan otomatis

Halaman memperbarui tabel, kartu ringkasan, dan detail yang sedang terbuka sekitar setiap **5 detik** ketika panel aktif. Polling dipasang pada halaman, sehingga daftar kosong juga dapat mendeteksi order baru. Tombol **Muat ulang** tersedia untuk memperbarui lebih cepat. Pencarian, filter, halaman pagination, dan catatan form yang sedang diketik tetap dipertahankan.

Polling membaca database, tidak memanggil Midtrans atau membuat charge. Perubahan pembayaran dari Midtrans mengikuti webhook dan worker queue `payments` yang sudah tersedia. Worker serta scheduler rekonsiliasi tetap harus berjalan agar status provider masuk ke database; pengaturannya dijelaskan pada [API pembayaran Midtrans](api/midtrans-core-payments.md).

## Perhitungan nominal per toko

Tanpa filter toko, nominal lunas memakai total pembayaran order dan dihitung sekali per order. Ketika toko dipilih:

- **Subtotal barang toko** menjumlahkan snapshot subtotal produk dan bundle milik toko tersebut.
- **Total pembayaran order** tetap memperlihatkan keseluruhan tagihan customer.
- Nominal pada kartu **Lunas** menggunakan subtotal barang toko terpilih; kartu lain menghitung order yang memenuhi filter dan pencarian.

Nilai ini bukan saldo/pencairan seller. Ongkir, subsidi, biaya provider, dan refund tidak dialokasikan ke toko. Order `refunded` dan `payment_review` tidak masuk nominal lunas. Simulasi dan sandbox ikut dihitung jika tidak difilter; pilih sumber/lingkungan yang sesuai untuk memantau transaksi production.

Lingkungan mengikuti pembayaran aktif yang tersimpan. Order Midtrans tanpa pembayaran aktif ditandai **Belum diketahui**; konfigurasi server saat ini tidak dipakai untuk menebaknya. Sumber manual dan simulasi mempunyai penanda masing-masing.

## Detail dan tindakan

Klik **Detail** atau baris order untuk membuka slide-over. Susunan detail memakai Section dan RepeatableEntry bawaan Filament, meliputi customer, semua barang/toko, subtotal toko terpilih, tagihan, seluruh percobaan pembayaran, VA/billing, pengiriman, serta riwayat status. Bagian **Payload pembayaran tersimpan** menampilkan request awal dan respons terakhir melalui CodeEntry; kredensial dan signature disaring. Tautan riwayat membuka [Log Pembayaran](payment-communication-logs.md) yang difilter untuk percobaan tersebut. Riwayat lengkap dimuat ketika detail dibuka agar polling daftar tetap ringan.

Tindakan tersedia pada tabel serta slide-over:

| Tindakan | Ketersediaan |
| --- | --- |
| **Sinkronkan pembayaran** | Order Midtrans; verifikasi status melalui service pembayaran yang sudah tersedia |
| **Konfirmasi lunas** | Hanya order manual yang masih menunggu pembayaran; wajib catatan verifikasi, referensi opsional |
| **Batalkan** | Order manual/Midtrans yang masih menunggu pembayaran; wajib alasan |

Sinkronisasi tidak membuat charge/percobaan baru, tetapi dapat memperbarui status atau menutup pembayaran kedaluwarsa sesuai aturan pembayaran. Pembatalan Midtrans baru dinyatakan berhasil setelah provider mengonfirmasi. Proses yang masih berjalan atau provider gagal dihubungi menghasilkan notifikasi dan mempertahankan status terakhir yang terverifikasi.

Simulasi dan penyedia lain dapat dimonitor tanpa dipaksakan mengikuti tindakan pembayaran manual atau adapter Midtrans. Hak akses diperiksa ulang pada setiap tindakan dan polling. Resource tidak menyediakan tambah, edit bebas, hapus, refund, ekspor, atau pembagian dana seller.

## Susunan kode

- `Orders/OrderResource`: model, navigasi, otorisasi, dan penghubung konfigurasi.
- `Orders/Pages/ManageOrders`: state filter, polling halaman, dan widget.
- `Orders/Tables/OrdersTable`: kolom, pencarian, filter, pagination, serta tindakan tabel.
- `Orders/Schemas/OrderInfolist`: susunan detail slide-over.
- `Orders/Actions/OrderActions`: konfirmasi manual, pembatalan, dan sinkronisasi.
- `Orders/Widgets/OrderStats`: ringkasan menggunakan widget Filament.
- `StoreTransactionReport`: query, agregasi nominal, lingkungan, dan penelusuran toko.

Perubahan ini tidak memerlukan migrasi database atau mengubah kontrak API customer.
