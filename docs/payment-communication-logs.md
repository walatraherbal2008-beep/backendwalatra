# Log Komunikasi Pembayaran Midtrans

Superadmin aktif dapat membuka **Manajemen Marketplace → Log Pembayaran**. Setiap baris mewakili satu panggilan Laravel ke Midtrans, bukan satu pembayaran atau satu order. Resource hanya menyediakan daftar, pencarian/filter, dan detail slide-over; tidak menyediakan tambah, edit, hapus, atau pengulangan transaksi.

## Data yang dicatat

Pencatatan dilakukan oleh `MidtransPaymentGateway` untuk operasi `charge`, `status`, dan `cancel`. Setiap panggilan memperoleh baris baru pada tabel `payment_communication_logs`, termasuk retry, transaksi tidak ditemukan, respons ditolak, dan gangguan koneksi. Pengambilan gambar QR dan webhook masuk tidak dicatat sebagai operasi tabel ini; inbox webhook tetap berada di `payment_notifications`.

| Field | Isi |
|---|---|
| `order_id`, `order_payment_id` | Hubungan ke order dan percobaan pembayaran, jika ditemukan. |
| `provider`, `environment` | Provider Midtrans dan lingkungan sandbox/production ketika request dikirim. |
| `provider_order_id` | Referensi transaksi yang dipakai dalam request Midtrans. |
| `operation`, `http_method`, `endpoint` | Charge/cek status/pembatalan, metode HTTP, dan URL tujuan. |
| `request_payload` | Body JSON yang dikirim, setelah penyaringan kredensial. GET status dan POST cancel tanpa body JSON menyimpan null. |
| `response_payload` | Seluruh respons JSON yang diterima untuk panggilan tersebut, setelah penyaringan kredensial. Field seperti merchant ID dan waktu transaksi tetap dipertahankan jika dikirim provider. |
| `response_body` | Respons non-JSON, misalnya halaman error provider, setelah penyaringan. Ditampilkan sebagai kode yang di-escape. |
| `http_status` | HTTP status sebenarnya dari provider; null jika tidak memperoleh respons HTTP. |
| `outcome` | Hasil komunikasi: started, succeeded, not_found, failed, atau connection_error. |
| `requested_at`, `finished_at` | Waktu mulai dan selesai. Panel menampilkan Asia/Jakarta. |
| `duration_ms` | Durasi panggilan dan pencatatan hingga hasil tersedia, dalam milidetik. |
| `error_type`, `error_message` | Jenis exception dan pesan umum yang aman; pesan exception mentah tidak disimpan. |

HTTP status dan `response_payload.status_code` dapat berbeda. Contoh: provider mengirim HTTP 200 dengan JSON `status_code: "201"` saat berhasil membuat VA. `outcome: succeeded` berarti komunikasi diterima sesuai aturan gateway, bukan pembayaran sudah lunas.

## Cara melihat di superadmin

- **Log Pembayaran:** tampilkan panggilan terbaru terlebih dahulu, dengan kolom No. yang berlanjut mengikuti pagination. ID log tetap terlihat pada detail. Cari referensi/order atau gunakan filter lingkungan, operasi, hasil, order, dan percobaan pembayaran. Baris tanpa order Laravel tetap ditampilkan, sehingga pemanggilan gateway secara langsung juga dapat diaudit.
- **Detail log:** tampilkan metadata request, hubungan pembayaran, body request, respons JSON/non-JSON, HTTP status, waktu, durasi, dan kegagalan jika ada. Nominal/metode/referensi transaksi dapat diambil dari respons untuk panggilan yang tidak memiliki hubungan order.
- **Pesanan → Detail → Pembayaran:** tampilkan `provider_order_id`, `transaction_id`, lingkungan, metode, nominal, serta `last_synced_at`. Bagian **Payload pembayaran tersimpan** dapat diperluas untuk melihat request awal dan respons terakhir. Tautan **Lihat log komunikasi Midtrans** membuka riwayat yang difilter untuk percobaan pembayaran tersebut.
- **Pesanan → titik tiga → Lihat log pesanan:** membuka daftar log yang difilter untuk seluruh order tersebut, mencakup semua percobaan pembayarannya. Order tanpa log menampilkan daftar kosong. Tabel Pesanan juga memiliki nomor urut yang mengikuti pagination.

`order_payments.response_payload` tetap merupakan snapshot terakhir yang sudah dipilih dan divalidasi service pembayaran. Riwayat lengkap setiap komunikasi ada di tabel log baru. Sinkronisasi berikutnya tidak menimpa log panggilan sebelumnya.

## Kegagalan dan perlindungan data

Log awal ditulis sebelum request keluar. Jika penyimpanan log belum tersedia, gateway menolak request tanpa menghubungi Midtrans. Setelah memperoleh respons atau exception, log dilengkapi dengan hasil dan waktu selesai. Tidak ada retry jaringan tambahan yang ditambahkan oleh logger.

Jika hasil tidak dapat disimpan setelah request terkirim, gateway melaporkan layanan pembayaran belum dapat dikonfirmasi. Log awal dapat tetap `started`; status transaksi perlu dipulihkan melalui alur pembayaran yang sudah ada. Proses yang berhenti mendadak juga dapat meninggalkan log `started`. Jangan menganggap tidak adanya waktu selesai sebagai bukti transaksi gagal di provider.

Server Key, Client Key, Authorization, signature, password, secret, dan token disaring pada data log. Header autentikasi tidak disimpan. Tampilan payload juga menyaring ulang kredensial. Data customer dan rincian transaksi tetap dapat tercatat sesuai request, sehingga akses panel dibatasi untuk superadmin aktif.

Tidak ada penghapusan otomatis maupun aksi hapus dari resource. FK ke order/pembayaran menggunakan null saat record induk dihapus agar log komunikasi tetap tersimpan. Pencatatan dimulai setelah fitur dipasang; request lama tidak direkonstruksi menjadi log baru.

## Pemasangan dan verifikasi

Dependency `phiki/phiki` dipakai oleh komponen CodeEntry bawaan Filament. Setelah mengambil perubahan, jalankan Composer install dan migrasi database, lalu mulai ulang worker pembayaran yang berjalan agar memakai kode terbaru:

```bash
composer install
php artisan migrate --force
php artisan queue:restart
```

Database lokal sudah menerima migrasi `2026_11_13_000000_create_payment_communication_logs_table` pada pekerjaan ini. Kontrak API customer dan metode pembayaran tidak berubah.

Tes terkait:

```bash
php artisan test tests/Feature/Payments/PaymentCommunicationLoggingTest.php tests/Feature/Filament/PaymentCommunicationLogTest.php tests/Feature/Filament/OrderMonitoringTest.php tests/Feature/Api/V1/CorePaymentTest.php
```
