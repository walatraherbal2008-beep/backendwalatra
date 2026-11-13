# Audit HTTP Nyata: Login sampai Manajemen Checkout

Tanggal: **12 November 2026**. Run: `20261112-100024`.

**Hasil:** 82 pemeriksaan alur HTTP lulus setelah perbaikan satu ketidaksesuaian status respons. Cart, autentikasi, alamat, pilihan barang, preview, pembuatan order, retry, pembacaan, dan pembatalan berhasil pada server lokal. **Akses gambar lewat domain ngrok yang dikonfigurasi masih gagal karena tunnel offline.**

Bukti terstruktur dan contoh respons tersanitasi: [live-http-results.json](live-http-results.json). OTP, token login, dan token checkout tidak disimpan dalam bukti repository.

## Lingkungan dan metode

- Request memakai HTTP sungguhan melalui `http://127.0.0.1:8000/api/v1`, dengan database aplikasi PostgreSQL, bukan SQLite memory atau request Laravel test client.
- Penerbitan OTP memakai proses aplikasi sementara di port 8011 dengan pengiriman WhatsApp dinonaktifkan dan queue sync. Ini mencegah pesan terkirim ke nomor lain. OTP tetap dibuat, disimpan, dan diverifikasi oleh kode autentikasi asli; kode dibaca dari respons local yang sudah dibuka. Verify OTP, Bearer token, dan seluruh operasi bisnis memakai server port 8000.
- Proses sementara tidak mengubah `.env` atau konfigurasi server utama dan sudah dihentikan setelah audit. **Pengiriman OTP melalui WhatsApp nyata belum dibuktikan.**
- Dua akun baru per run digunakan untuk menguji batas kepemilikan. Ada dua run: run awal menemukan bug, lalu run akhir memverifikasi perbaikan dan mengulang seluruh alur.
- Produk dan bundle untuk order diambil dari katalog yang benar-benar dipublikasikan. Skenario perubahan harga dan penghapusan memakai produk/listing/toko tambahan khusus audit; data katalog milik pengguna tidak diubah.
- Mode aplikasi: integrated, Midtrans sandbox, stok unlimited, gratis ongkir. Daftar/detail gudang dan wilayah Everpro dipanggil melalui layanan yang dikonfigurasi aplikasi tanpa mock.

## Hasil per tahap

| Tahap | Hasil yang dibuktikan |
|---|---|
| Akses tanpa login | GET cart ditolak 401. |
| Request dan verify OTP | Login berhasil; OTP salah dan OTP yang sudah dipakai ditolak 422. |
| Profil | GET profil dan PATCH nama customer berhasil. |
| Cart akun baru | GET pertama dan berikutnya 200, item kosong dan ringkasan nol. |
| Wilayah Everpro | Provinsi, kota, kecamatan, dan kelurahan menghasilkan 200. |
| Alamat | Tambah 201, daftar/ubah/hapus 200; perubahan alamat akun lain ditolak 404. |
| Tambah produk/bundle | Keduanya berada dalam satu cart; penambahan quantity 97 lalu 10 menghasilkan 99. |
| Ubah quantity | PATCH mengganti jumlah menjadi 2; quantity 100 ditolak 422. |
| Ringkasan pilihan | Dua baris terpilih, quantity 3, subtotal Rp486.000; item lain tetap di cart. |
| Kepemilikan cart | Ubah item akun lain ditolak 404; pilihan ID asing dan ID duplikat ditolak 422. |
| Harga berubah | Token lama ditolak 409 setelah perubahan harga fixture; preview baru menampilkan price_changed true. |
| Listing dihapus | GET cart tetap 200, is_available false, pesan Tidak tersedia; item dapat dihapus. Tambah ulang ditolak 422; PATCH, preview, dan order ditolak 409. |
| Kewajiban preview | Order tanpa token atau dengan token rusak ditolak 422. |
| Preview | Gudang, harga terbaru, ringkasan pilihan, serta ongkir nol berhasil diperoleh. Alamat akun lain ditolak 404. |
| Perubahan setelah preview | Quantity diubah dan order dengan token lama ditolak 409. |
| Buat order | HTTP 201, status pending_payment, dua snapshot item produk/bundle, total Rp486.000. |
| Retry order | Key sama tanpa token mengembalikan order lama melalui HTTP 200; tidak ada order kedua untuk key tersebut. |
| Checkout sebagian | Hanya item terpilih keluar dari cart; item tidak dipilih tetap ada. |
| Manajemen order | Daftar/detail berhasil; detail order akun lain ditolak 404. |
| Batas tahap pembayaran | Daftar metode dan snapshot payment berhasil. Belum ada VA/QR karena endpoint create payment tidak dipanggil. |
| Pembatalan | Order belum dibayar berhasil dibatalkan dan berstatus cancelled. |
| Snapshot | Alamat pesanan tetap ada setelah alamat customer dihapus; dua berkas foto snapshot ditemukan di storage. |
| Logout | Kedua token dicabut; penggunaan token setelah logout ditolak 401. |

## Bug yang ditemukan dan diperbaiki

Pada run awal, GET cart pertama untuk customer baru mengembalikan **201**, bertentangan dengan dokumentasi **200**. Backend membuat cart menggunakan firstOrCreate; Laravel JsonResource menghitung status berdasarkan wasRecentlyCreated.

Perbaikan menetapkan respons CartResource menjadi HTTP 200 untuk endpoint cart. Tes regresi pertama kali gagal dengan perilaku lama, lalu lulus setelah perbaikan. Run HTTP kedua memakai akun baru dan seluruh 82 pemeriksaan berhasil. Struktur body cart tidak berubah karena perbaikan status ini.

## Kendala akses publik

URL gambar yang dikembalikan API masih menggunakan domain:

```text
https://494e-2405-8180-801-4279-9c19-ecbe-98a5-17c7.ngrok-free.app
```

Request gambar melalui URL tersebut menghasilkan **404 ERR_NGROK_3200**, dengan informasi endpoint offline. Berkas yang sama melalui `http://127.0.0.1:8000/storage/...` menghasilkan **200 image/jpeg**. Agent ngrok lokal pada port 4040 juga tidak dapat dihubungi saat pemeriksaan.

Untuk pengujian website/Android melalui internet, aktifkan tunnel/domain yang benar dan sesuaikan APP_URL. Setelah domain berubah, Notification URL Midtrans juga perlu mengikuti domain aktif. Audit ini tidak mengubah domain atau pengaturan dashboard Midtrans.

## Data pesanan pengujian

| Run | ID | Nomor order | Total | Status akhir |
|---|---|---|---|---|
| Awal, sebelum perbaikan GET cart | 84 | WAL-1GGNGZBHSCFY | Rp486.000 | cancelled |
| Akhir, setelah perbaikan | 85 | WAL-GLNI2S33X33F | Rp486.000 | cancelled |

Pesanan dan akun customer pembuatnya dipertahankan sebagai bukti yang dapat dilihat di panel Pesanan. Tidak ada charge, pembayaran, atau pengiriman fisik yang dibuat. Pemeriksaan database untuk run akhir: 0 token tersisa, 0 alamat tersisa, 0 item cart tersisa, 0 listing fixture tersisa, 2 foto snapshot tersedia, dan 0 percobaan pembayaran.

## Batas verifikasi

Pengujian ini membuktikan alur lokal dari login sampai manajemen checkout. Pengiriman WhatsApp OTP, charge VA/QRIS, settlement, webhook publik, stok fisik, dan pengiriman barang tidak diuji pada run ini. Status pembayaran lunas tidak disimulasikan. Domain ngrok offline merupakan kendala lingkungan yang masih perlu ditangani sebelum pengujian publik.

Regresi otomatis dan pemeriksaan format dijalankan setelah perbaikan. Hasilnya ditambahkan pada catatan verifikasi di bawah.

### Verifikasi otomatis setelah audit HTTP

- Tes regresi GET cart akun baru: gagal sebelum perbaikan (201), lulus setelah perbaikan (200).
- Tes terkait cart, harga, dan pilihan checkout: **46 lulus, 668 assertions**.
- Seluruh suite: **377 lulus, 1 gagal, 2.880 assertions**. Kegagalan lama tetap `CustomerOtpAuthTest.php:529`, ekspektasi rate limit 429 sedangkan respons aktual 200.
- Pint dan pemeriksaan whitespace dijalankan untuk perubahan audit.
