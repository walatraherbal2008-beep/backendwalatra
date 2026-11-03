# API Order Manual untuk Production

Order pada alur ini adalah pesanan sebenarnya. Customer checkout dengan status `pending_payment`, mengikuti instruksi pembayaran, lalu superadmin memverifikasi stok dan dana yang diterima sebelum mengonfirmasi `paid` melalui panel. Tidak ada whitelist tester atau pembayaran yang otomatis dianggap lunas.

## Konfigurasi server

```dotenv
APP_ENV=production
APP_DEBUG=false
OTP_EXPOSE_CODE=false

ORDER_PROCESSING_MODE=manual
ORDER_SIMULATION_ENABLED=false
MANUAL_ORDER_SHIPPING_FEE=0
MANUAL_PAYMENT_INSTRUCTIONS="Hubungi admin untuk nomor rekening dan petunjuk pembayaran. Cantumkan nomor pesanan saat konfirmasi."
```

Ganti petunjuk pembayaran dengan prosedur usaha yang digunakan. `MANUAL_ORDER_SHIPPING_FEE` adalah tarif ongkir kotor tetap dalam rupiah untuk setiap order, bukan per item. Nilai `0` berarti gratis ongkir, bukan ongkir yang belum ditentukan. Aturan subsidi ongkir aktif tetap diterapkan. Ongkir dan total disimpan saat order dibuat; perubahan konfigurasi tidak mengubah order lama.

```bash
php artisan migrate --force
php artisan config:cache
php artisan horizon:terminate
```

Supervisor harus menjalankan kembali Horizon; restart proses aplikasi yang berjalan lama jika digunakan. Mode manual adalah default konfigurasi baru, tetapi tetapkan `ORDER_PROCESSING_MODE=manual` secara eksplisit pada server agar pilihan operasional jelas. Flag live demo sebelumnya tidak lagi dipakai.

Siapkan MaxChat dan queue `otp` untuk login WhatsApp. Mode manual tidak membutuhkan Midtrans, API stok, atau API ongkir. Everpro hanya diperlukan jika frontend menggunakan endpoint lokasi Everpro; API order juga menerima alamat teks tanpa ID Everpro.

## Katalog dan akun

Seluruh produk, varian, harga, dan listing toko yang aktif dapat digunakan, tanpa membedakan asal data, nama toko, atau label simulasi. Buat/kelola data melalui panel yang sudah ada. Tidak perlu mereset katalog atau membuat ulang listing.

Listing **Toko Simulasi Order** juga dapat dibeli melalui alur manual seperti listing lain. Order baru menggunakan `payment_provider=manual` dan menunggu verifikasi pembayaran admin. Order simulasi lama tetap memiliki riwayat serta provider sebelumnya; data tersebut tidak dikonversi otomatis. Menu Pesanan untuk admin menampilkan order `payment_provider=manual`.

Tidak ada pendaftaran customer terpisah: login OTP membuat akun jika nomor belum terdaftar. OTP production diterima melalui WhatsApp dan tidak muncul di respons.

## Base URL dan autentikasi

Base URL: `https://api.example.com/api/v1`, ganti domain sesuai server.

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <TOKEN_CUSTOMER>
```

Authorization dibutuhkan untuk cart, alamat, checkout, dan order. Katalog dan endpoint permintaan/verifikasi OTP dapat diakses tanpa token. Lihat [kontrak autentikasi customer](web-authentication-api.md).

## Urutan endpoint

| Langkah | Request | Hasil |
|---|---|---|
| 1 | `POST /auth/customer/request-otp` dengan `phone` | Challenge OTP |
| 2 | `POST /auth/customer/verify-otp` dengan `phone`, `challenge_id`, `otp`, `device_name` | Bearer token dan akun customer |
| 3 | `PATCH /auth/customer/profile` dengan `name` jika profil belum lengkap | Profil customer |
| 4 | `GET /catalog/products` atau `/catalog/bundles` | Listing dan ID varian/bundle |
| 5 | `POST /cart/items` | Cart terbaru |
| 6 | `POST /addresses` | ID alamat customer |
| 7 | `POST /checkout/preview` | Subtotal, ongkir, dan total |
| 8 | `POST /orders` dengan `Idempotency-Key` | Order `pending_payment` |
| 9 | `GET /orders/{id}` atau `POST /orders/{id}/payment` | Status dan petunjuk pembayaran manual |
| 10 | Superadmin mengonfirmasi dari panel Pesanan | Order menjadi `paid` |
| 11 | `GET /orders` atau `/orders/{id}` | Riwayat dan status terbaru |

Contoh ID dan nominal di bawah bersifat ilustratif; gunakan ID dari respons server.

### Cart

Produk:

```json
{"type": "product", "listing_id": 1, "variant_id": 7, "quantity": 2}
```

Bundle:

```json
{"type": "bundle", "bundle_listing_id": 4, "quantity": 1}
```

Quantity 1–99. Harga diambil server, disimpan pada cart, dan disalin ke order; client tidak dapat menentukan harga atau total. Kuantitas dapat diubah melalui `PATCH /cart/items/{id}` dan item dihapus melalui `DELETE /cart/items/{id}`.

### Alamat

`POST /addresses`:

```json
{
  "label": "Rumah",
  "name": "Nama Penerima",
  "phone": "081112223334",
  "province": "Jawa Barat",
  "city": "Bandung",
  "postal_code": "40135",
  "address": "Alamat pengiriman customer",
  "is_default": true
}
```

Simpan `data.id` sebagai `address_id`. Alternatifnya, kirim `shipping_address` langsung ke preview/create order dengan field `name`, `phone`, `province`, `city`, `postal_code`, dan `address`. Alamat tersimpan harus milik customer yang login.

### Preview

`POST /checkout/preview`:

```json
{"address_id": 5}
```

Cuplikan `data` pada respons `200`, untuk subtotal Rp30.000 dan ongkir bersih Rp10.000:

```json
{
  "simulation": false,
  "payment_provider": "manual",
  "payment_required": true,
  "stock_verification": "pending_manual",
  "checkout_available": true,
  "subtotal": "30000.00",
  "shipping": {
    "service": "MANUAL",
    "eta_days": null,
    "gross_fee": "10000.00",
    "subsidy": "0.00",
    "fee": "10000.00",
    "subsidy_rule_id": null
  },
  "total": "40000.00"
}
```

`checkout_available=true` berarti order dapat diajukan berdasarkan listing/varian aktif. Field tersebut bukan bukti stok fisik sudah diperiksa. Admin tetap memeriksa ketersediaan sebelum menerima pembayaran/pemenuhan pesanan. Preview tidak membuat order. Tanpa alamat, preview hanya mengembalikan subtotal dan pemeriksaan item; `shipping` dan `total` null.

### Create order

`POST /orders`:

```http
Idempotency-Key: pembelian-20261103-001
```

```json
{"address_id": 5}
```

Respons pertama `201`, cuplikan:

```json
{
  "success": true,
  "message": "Order berhasil dibuat.",
  "data": {
    "id": 1,
    "order_number": "WAL-CONTOH123456",
    "status": "pending_payment",
    "payment_provider": "manual",
    "simulation": false,
    "payment_required": true,
    "payment_instructions": "Hubungi admin untuk nomor rekening dan petunjuk pembayaran. Cantumkan nomor pesanan saat konfirmasi.",
    "paid_at": null,
    "currency": "IDR",
    "subtotal": "30000.00",
    "shipping_gross_fee": "10000.00",
    "shipping_subsidy": "0.00",
    "shipping_fee": "10000.00",
    "shipping_service": "MANUAL",
    "total": "40000.00"
  }
}
```

Respons lengkap juga berisi `shipping_address`, snapshot `items`, serta `created_at`. Item bundle menyimpan komponen dan jumlah per paket. Setelah order berhasil disimpan, item cart yang dibeli dihapus.

`Idempotency-Key` wajib string tidak kosong maksimal 100 karakter. Retry pembelian yang sama memakai key yang sama menghasilkan `200` dan ID order yang sama. Pembelian baru harus memakai key baru. Pembuatan order diserialisasikan per customer dengan lock database pada baris user; verifikasi status pembayaran memakai lock baris order.

### Petunjuk pembayaran

Frontend dapat langsung menampilkan `payment_instructions` dari order. `POST /orders/1/payment` juga tersedia tanpa body khusus dan menghasilkan `200`:

```json
{
  "success": true,
  "message": "Pembayaran menunggu verifikasi admin.",
  "code": "MANUAL_PAYMENT_REQUIRED",
  "data": {
    "order_id": 1,
    "order_number": "WAL-CONTOH123456",
    "payment_provider": "manual",
    "payment_required": true,
    "instructions": "Hubungi admin untuk nomor rekening dan petunjuk pembayaran. Cantumkan nomor pesanan saat konfirmasi.",
    "payment_token": null,
    "payment_url": null
  }
}
```

Jangan membuka Midtrans Snap dengan token null. Endpoint ini hanya membaca instruksi/status; tidak menandai order lunas. Upload bukti pembayaran melalui API belum ditambahkan; customer mengirim konfirmasi melalui prosedur kontak admin yang ditetapkan usaha.

## Operasional superadmin

1. Login ke `/superadmin` sebagai akun aktif dengan role `super_admin`.
2. Buka **Manajemen Marketplace → Pesanan**.
3. Buka menu **Tindakan** pada baris pesanan, lalu gunakan **Detail** untuk memeriksa barang/bundle, total, penerima, alamat, dan riwayat perubahan.
4. Periksa stok secara manual dan cocokkan dana yang sudah diterima dengan total order.
5. Pilih **Konfirmasi lunas**, isi catatan verifikasi 5–1.000 karakter, serta referensi pembayaran jika tersedia, lalu konfirmasikan.
6. Jika order tidak dapat dipenuhi atau dibatalkan sebelum pembayaran dikonfirmasi, gunakan **Batalkan** dan isi alasannya.

Konfirmasi menyimpan status `paid`, `paid_at`, referensi pembayaran, serta riwayat status berisi admin, waktu, dan catatan. Order yang sudah `paid` atau `cancelled` tidak bisa dikonfirmasi lagi. Customer dan admin toko tidak memiliki akses ke tindakan ini.

API customer tidak menyediakan endpoint untuk menetapkan `paid`. Mengirim `status`, `payment_provider`, `paid_at`, atau flag simulasi pada body checkout tidak mengubah keputusan server. Webhook Midtrans tidak dapat memproses order manual.

Stok, pemenuhan, dan pengiriman dikelola operator di luar integrasi otomatis. Konfirmasi lunas tidak mengurangi stok eksternal atau membuat resi. Tahap pengiriman, refund, dan penyelesaian pesanan belum ditambahkan pada perubahan ini.

## Status setelah verifikasi

`GET /orders/{id}` menampilkan `status=paid`, `payment_required=false`, `payment_instructions=null`, serta waktu `paid_at`. `POST /orders/{id}/payment` menghasilkan `code=MANUAL_PAYMENT_CONFIRMED`.

`GET /orders?page=1` menampilkan hanya pesanan customer yang login, dengan pagination 15 order per halaman, terbaru lebih dahulu. Parameter `{id}` memakai ID numerik, bukan nomor pesanan.

Customer dapat membatalkan `pending_payment` melalui `POST /orders/{id}/cancel`. Setelah lunas, pembatalan customer ditolak dengan `422`; refund harus ditangani melalui prosedur usaha di luar fitur ini. Order `cancelled` tidak menyediakan instruksi pembayaran lagi.

## Error penting

| HTTP | Kondisi |
|---|---|
| 401 | Token tidak ada atau tidak valid |
| 403 | Akun tidak aktif / akses panel atau tindakan admin tidak diizinkan |
| 404 | Order/alamat tidak ditemukan atau bukan milik customer |
| 409 | `STALE_CART`: listing/varian atau komponen bundle tidak lagi tersedia |
| 422 | Input salah, alamat preview tidak lengkap, `CART_EMPTY`, key idempotency kosong, atau transisi status tidak sah |
| 429 | Batas request OTP/katalog tercapai |

Tampilkan `message` dan `errors` jika tersedia; beberapa endpoint masih menggunakan bentuk error tanpa field `code`. Jika endpoint mengeluhkan layanan stok/ongkir, periksa `ORDER_PROCESSING_MODE` dan config cache proses server.

## Menambahkan integrasi nanti

Gunakan `ORDER_PROCESSING_MODE=integrated` hanya setelah integrasi stok, ongkir, dan pembayaran siap serta temuan audit transaksi diselesaikan. Order manual lama tetap berprovider `manual` dan diproses manual; tidak berubah menjadi transaksi Midtrans. Simulasi pengembangan tetap tersedia khusus local/testing melalui konfigurasi tersendiri.
