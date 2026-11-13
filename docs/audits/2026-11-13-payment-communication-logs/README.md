# Verifikasi Log Komunikasi Pembayaran

Tanggal: 13 November 2026. Fitur: [Log Pembayaran](../../payment-communication-logs.md).

## Implementasi

- Tabel payment_communication_logs dibuat dengan migrasi lokal yang sudah dijalankan. Setiap charge, status, dan cancel melalui gateway membuat baris tersendiri, termasuk request langsung tanpa order Laravel.
- Log menyimpan body request/respons, HTTP status, lingkungan, referensi, waktu mulai/selesai, durasi, dan kegagalan. Kredensial disaring; header autentikasi dan pesan exception mentah tidak disimpan.
- Superadmin aktif dapat membuka menu Log Pembayaran. Payload pembayaran terakhir juga tersedia di detail Pesanan; tautan riwayat memakai filter percobaan pembayaran. Tidak ada aksi tambah/edit/hapus atau pengulangan charge dari menu log.
- CodeEntry bawaan Filament memakai dependency phiki/phiki v2.2.1. Composer manifest dan lock diperbarui.

## Verifikasi otomatis

- Seluruh suite: **388 lulus, 1 gagal, 2.966 assertions**. Kegagalan lama pada CustomerOtpAuthTest.php:529 masih mengharapkan rate limit OTP 429 tetapi menerima 200; terpisah dari fitur ini.
- Tujuh tes logger mencakup riwayat terpisah, asosiasi order/pembayaran, request langsung, penyaringan kredensial, timeout, HTTP ditolak/not found, non-JSON, storage gagal sebelum request, serta kegagalan penyimpanan hasil setelah request terkirim.
- Empat tes resource mencakup akses superadmin, penolakan customer/seller/superadmin nonaktif, filter URL, payload tersanitasi, dan escaping respons non-JSON.
- Konkurensi PostgreSQL: **2 tes cart/checkout lulus (103 assertions)** dan **1 tes pembayaran lulus (17 assertions)**. Database khusus dibuat dan dihapus; database aplikasi tidak digunakan untuk migrate:fresh.
- Pint, Composer validate, dan git diff --check lulus.

## Verifikasi sandbox nyata

[Bukti JSON](live-results.json) berisi empat log untuk satu transaksi sandbox BCA Rp93.000: charge, status, cancel, status. Semua memperoleh respons HTTP 200; JSON charge berisi status_code 201. Respons charge lama tetap pending walaupun log status akhir menyatakan cancel, membuktikan riwayat tidak ditimpa. Transaksi uji sudah dibatalkan.

Satu Get Status terhadap pembayaran audit order 87 juga dicatat dan berhasil diasosiasikan dengan order/pembayaran tersebut. Tidak ada transaksi production atau perubahan pembayaran user yang dilakukan.

## Pemeriksaan browser

Vibium memeriksa halaman daftar, filter operasi, filter pembayaran 37 (satu baris), slide-over, serta pembukaan payload pada detail Pesanan. Tampilan diperiksa pada 1440×1000 dan 390×844. Lebar halaman mobile tetap 390; kode yang panjang memiliki scroll horizontal di dalam bloknya.

[Desktop](desktop.png) · [Mobile](mobile.png).

Akun superadmin sementara untuk pemeriksaan dihapus setelah verifikasi. Log komunikasi sandbox dipertahankan agar dapat ditinjau melalui panel.

## Uji ulang JSON request dan respons

Pada 13 November 2026 pukul 08:50 WIB, satu transaksi sandbox BCA Rp93.000 dibuat, diperiksa, lalu dibatalkan. Empat log baru tersimpan: ID 6 (charge), 7 (status), 8 (cancel), dan 9 (status). Body request di database sama dengan JSON yang dikirim, dan respons charge sama dengan respons provider yang diterima. Respons awal tetap pending pada log charge meskipun status akhir provider sudah cancel.

Tes logger dan resource superadmin dijalankan ulang: **11 lulus, 83 assertions**. Data dan contoh JSON lengkap tersedia di [retest-results.json](retest-results.json). Tidak ada perubahan kode aplikasi pada uji ulang ini.
