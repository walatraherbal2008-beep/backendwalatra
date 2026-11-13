# Pemeriksaan Integrasi Midtrans dan Laravel

Tanggal: **13 November 2026**. Bukti: [results.json](results.json).

**Kesimpulan:** koneksi Laravel ke Midtrans Sandbox, charge BCA VA dan QRIS, penyimpanan instruksi, retry idempotent, Get Status, proxy QR PNG, dan pembatalan berhasil. Notifikasi otomatis dari internet sampai settlement belum dibuktikan pada pemeriksaan ini.

## Pengujian yang dilakukan

- Konfigurasi aplikasi dimuat dan diperiksa tanpa menampilkan Server Key. Mode order integrated, gateway MidtransPaymentGateway, lingkungan sandbox, dan key terkonfigurasi.
- Get Status menggunakan referensi yang tidak ada berhasil mencapai sandbox dan menghasilkan not found, tanpa membuat transaksi.
- Dibuat satu akun audit dan token customer sementara untuk menguji endpoint pembayaran; login OTP bukan bagian pengujian ini.
- Dibuat dua order melalui endpoint cart → preview → orders, masing-masing Keloreena 50 Kapsul Rp93.000 dengan ongkir nol. Produk berasal dari katalog aktif.
- POST payment menghasilkan HTTP 201 dan instruksi BCA VA/QRIS. Retry key yang sama menghasilkan HTTP 200 dengan payment ID yang sama.
- GET payment mempertahankan instruksi tersimpan. Refresh mengambil status pending dari Midtrans. QRIS berhasil diunduh melalui endpoint berautentikasi sebagai PNG, 66.929 byte.
- Pembatalan melalui endpoint order berhasil. Pemeriksaan terpisah ke Midtrans mengonfirmasi status provider cancel untuk kedua transaksi; database mencatat cancelled dan satu attempt per order.
- Webhook dengan signature palsu ditolak HTTP 403 tanpa autentikasi customer.
- Token audit sudah dicabut dan cart kosong. Order serta akun audit dipertahankan sebagai bukti di panel admin.

**Hasil:** 31 pemeriksaan live lulus; 27 tes otomatis CorePaymentTest dan OrderTest lulus dengan 234 assertions. Pengujian otomatis memakai fake provider; pengujian live di atas menghubungi Midtrans Sandbox sungguhan. Tidak ada pembayaran production atau simulator settlement yang dijalankan.

## Order uji

| Metode | Nomor order | Total | Status Laravel | Status Midtrans |
|---|---|---|---|---|
| bca_va | WAL-G2WNPWPB3Z3B | Rp93.000 | cancelled | cancel |
| qris | WAL-NZKENHYB6O8B | Rp93.000 | cancelled | cancel |

## Bagian yang perlu disiapkan untuk notifikasi otomatis

1. **URL publik aktif.** APP_URL saat diperiksa adalah http://127.0.0.1:8000. URL lokal ini tidak dapat dipanggil Midtrans melalui internet. Domain/tunnel HTTPS aktif dan Payment Notification URL pada dashboard belum diverifikasi pada sesi ini. Path backend tersedia: POST /api/v1/payments/midtrans/webhook.
2. **Worker payments.** Worker backend yang ditemukan berjalan dengan --queue=default,otp, sehingga tidak mengambil job ProcessMidtransNotification dan ReconcileOrderPayment pada queue payments. Horizon yang juga terlihat milik project chatbot, bukan backend ini. Tidak ada inbox sandbox belum diproses saat diperiksa, tetapi ini tidak membuktikan worker payments aktif.
3. **Scheduler.** payments:reconcile terdaftar setiap menit pada Laravel Scheduler. Cron pengguna untuk schedule:run dan proses schedule:work tidak ditemukan saat pemeriksaan; eksekusi terjadwal melalui pengelola sistem lain belum diverifikasi.

Contoh worker backend:

```bash
php artisan queue:work redis --queue=payments --tries=5 --timeout=60
```

Notification URL harus memakai domain HTTPS yang aktif:

```text
https://DOMAIN-BACKEND-AKTIF/api/v1/payments/midtrans/webhook
```

Sesudah URL dan worker siap, uji pembayaran pada simulator sandbox dan verifikasi notifikasi diterima, inbox diproses, serta order berubah menjadi paid. Jangan menganggap keberhasilan menerbitkan VA/QR sebagai bukti webhook sudah berjalan.

## Catatan kode yang masih berlaku

Webhook memverifikasi SHA-512, menyimpan inbox secara persisten, dan job mengambil status resmi dari Midtrans sebelum mengubah order. Pemeriksaan identitas, nominal, mata uang, metode, fraud, serta transaksi terlambat/ganda tersedia dan dicakup tes otomatis.

Refresh untuk attempt creating/unknown dapat memanggil charge ulang dengan referensi yang sama ketika transaksi belum ditemukan di provider dan belum kedaluwarsa. Ini mekanisme recovery saat ini; refresh tidak selalu murni membaca status. Perilaku ini sudah dicatat pada audit sebelumnya dan tidak diubah pada pemeriksaan integrasi ini.

Tidak ada perubahan kode pembayaran, konfigurasi .env, worker, atau dashboard Midtrans pada pemeriksaan ini.
