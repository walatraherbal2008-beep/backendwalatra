# Verifikasi Midtrans Core API — 9 November 2026

Implementasi diuji menggunakan Midtrans Sandbox. Kredensial dibaca dari `.env` dan tidak disertakan dalam laporan atau koleksi Postman.

## Hasil

- Keenam metode (`bca_va`, `bni_va`, `bri_va`, `mandiri_bill`, `permata_va`, `qris`) berhasil membuat instruksi pending, membaca status, dan membatalkan transaksi sandbox.
- Gambar QRIS aktual berhasil diambil sebagai PNG. URL QR sandbox memakai host merchants-app dan path QRIS v4; proxy mengizinkan format tersebut tanpa mengirim Server Key ke host gambar.
- Simulator BCA dan QRIS menghasilkan settlement; backend mengubah order menjadi `paid` setelah verifikasi Get Status.
- Percobaan BNI yang di-expire di sandbox tersinkron sebagai `expired`; order tetap terbuka dan dapat membuat percobaan kedua.
- Pengujian webhook lewat domain ngrok menghasilkan `403` untuk signature salah dan `200` untuk signature valid. Pengiriman ulang hanya menyimpan satu event identik.
- Setelah Payment Notification URL disimpan pada dashboard, transaksi BCA baru dibayar melalui simulator. Midtrans mengirim event `pending` dan `settlement` melalui ngrok; kedua event diproses worker dan order menjadi `paid` tanpa refresh manual.
- Seluruh fixture order/customer sementara dibersihkan setelah pengujian; transaksi sandbox tetap menjadi riwayat di dashboard provider.

## Pengujian otomatis

- Pembayaran Core, order, order manual/simulasi, dan admin: **57 lulus, 539 assertions**.
- Konkurensi pembayaran pada database PostgreSQL terpisah: **1 lulus, 17 assertions**. Request bersamaan menerima `202`; hanya satu intent/charge dibuat dan retry memakai pembayaran yang sama.
- Suite penuh: **325 lulus, 1 gagal, 2196 assertions**. Kegagalan terpisah pada `CustomerOtpAuthTest`: ekspektasi limiter IP 5 request berbeda dari konfigurasi 20 request. Pengaturan OTP dipertahankan.
- Format PHP diperiksa menggunakan Pint; JSON contoh dokumentasi, koleksi Postman, serta sintaks script Postman diperiksa. PDF dokumentasi dapat diekstrak dengan `pdftotext`.

Worker lokal menggunakan queue `payments`; scheduler menjalankan rekonsiliasi setiap menit. Pada deployment, kelangsungan worker dan scheduler perlu dikelola proses server. Domain ngrok dapat berubah: perbarui Payment Notification URL di dashboard dan `APP_URL` jika domain berganti.

Kontrak endpoint dan pengaturan: [API pembayaran](../../api/midtrans-core-payments.md). Koleksi: [Postman](../../postman/midtrans-core-payments.postman_collection.json).
