# Monitoring Transaksi Toko

Menu **Manajemen Marketplace → Transaksi Toko** tersedia di `/superadmin/transaksi-toko`, khusus akun superadmin aktif. Menu menampilkan order manual dan Midtrans; order simulasi tidak disertakan.

Satu baris merupakan satu order, walaupun berisi beberapa toko atau mempunyai beberapa percobaan pembayaran. Daftar diurutkan dari yang terbaru dan menyediakan pencarian nomor order, nama customer, serta WhatsApp.

## Filter dan nominal

Filter tersedia untuk satu toko, status order, penyedia pembayaran, lingkungan Midtrans, serta rentang tanggal pembuatan. Tanggal dan waktu ditampilkan dalam WIB; tanggal akhir filter termasuk seluruh hari tersebut. Toko nonaktif dan listing yang dihapus secara soft delete tetap dapat ditelusuri.

Tanpa filter toko, jumlah nominal lunas menggunakan total pembayaran order. Ketika toko dipilih:

- Kolom **Subtotal barang toko** menjumlahkan snapshot subtotal produk dan bundle milik toko tersebut.
- Kolom **Total pembayaran order** tetap menunjukkan keseluruhan tagihan customer.
- Nominal pada kartu **Lunas** menggunakan subtotal barang toko yang dipilih; kartu lain menghitung order yang memenuhi filter dan pencarian.

Nilai barang toko bukan saldo seller atau nilai pencairan. Ongkir, subsidi, biaya provider, dan refund tidak dibagi ke toko. Order `refunded` dan `payment_review` tidak ikut nominal lunas. Transaksi sandbox tetap dihitung jika tidak difilter; gunakan filter lingkungan ketika memantau production.

Nama toko, nama barang, quantity, dan harga menggunakan snapshot pembelian. Filter menggunakan identitas toko dari listing, sehingga perubahan nama toko tidak mengubah kepemilikan transaksi. Item lama tanpa relasi listing tetap terlihat pada daftar umum, tetapi tidak ditebak identitas tokonya berdasarkan nama.

Lingkungan mengikuti pembayaran aktif yang tersimpan, bukan konfigurasi server saat ini. Order Midtrans tanpa pembayaran aktif ditandai **Belum diketahui**; order manual ditandai **Manual**.

## Detail dan sinkronisasi

Klik **Detail** atau baris order untuk membuka slide-over. Detail berisi customer, seluruh barang/toko, subtotal toko terpilih jika ada, rincian tagihan, seluruh percobaan pembayaran, pengiriman, serta riwayat status. Payload mentah dan signature pembayaran tidak ditampilkan.

**Sinkronkan pembayaran** tersedia pada order Midtrans, melalui tabel maupun slide-over. Tindakan memakai service pembayaran yang sudah tersedia untuk memverifikasi status provider. Sinkronisasi tidak membuat charge atau percobaan baru, tetapi dapat memperbarui status order dan menutup pembayaran kedaluwarsa sesuai aturan pembayaran. Filter tetap dipertahankan dan ringkasan ikut diperbarui.

Jika proses pembayaran masih berjalan atau provider belum dapat dihubungi, panel menampilkan notifikasi dan status terakhir. Pembayaran dengan lingkungan berbeda dari konfigurasi aktif tidak disinkronkan lintas lingkungan.

Gunakan **Buka Pesanan** pada slide-over untuk menuju pencarian order yang sama pada menu Pesanan. Konfirmasi pembayaran manual dan pembatalan tetap dilakukan di sana. Monitoring tidak menyediakan tambah, edit, hapus, refund, ekspor, atau pembagian dana seller.
