# API Pembayaran Midtrans Core

Postman: `base_url` lokal harus `http://127.0.0.1:8000/api/v1`. Endpoint ditambahkan setelah nilai ini. Environment aktif dapat menimpa variable Collection; lihat [pengaturan Postman](postman-setup.md).

Base URL: `/api/v1`. Endpoint customer memakai `Authorization: Bearer <customer_token>` dengan ability `customer-api` dan akun aktif. Kirim `Accept: application/json` dan `Content-Type: application/json` untuk request JSON. Server Key hanya disimpan backend.

## Alur pembayaran

Komunikasi charge, Get Status, dan cancel dicatat terpisah untuk audit request/respons, HTTP status, serta waktunya. Superadmin dapat melihatnya melalui menu **Log Pembayaran** atau tautan dari detail Pesanan. Lihat [log komunikasi pembayaran](../payment-communication-logs.md). Fitur ini tidak menambahkan field pada respons API customer.

1. Buat order melalui [cart dan checkout](cart-checkout.md). Order baru menggunakan `payment_provider: midtrans`, `payment_integration: core_api`, dan status `pending_payment`.
2. Ambil metode pembayaran, lalu buat instruksi pembayaran pada order tersebut.
3. Tampilkan VA, kode billing Mandiri, atau QR dari respons server.
4. Baca snapshot pembayaran atau panggil refresh untuk mengambil status terbaru dari Midtrans. Webhook juga menyinkronkan status otomatis.
5. Gunakan `order_status` dari backend untuk menentukan hasil pembayaran. Order `paid` berarti pembayaran terverifikasi, bukan barang sudah dikirim.

Order dapat dibayar selama 24 jam sejak dibuat. VA berlaku maksimal 24 jam; QRIS maksimal 15 menit. Masa berlaku instruksi selalu dibatasi oleh `payment_due_at` order. Nominal dan rincian barang berasal dari snapshot order; customer tidak mengirim nominal pembayaran.

## Metode pembayaran

`GET /payment-methods`

```json
{"success":true,"data":[{"code":"bca_va","name":"BCA Virtual Account","payment_type":"bank_transfer","expiry_minutes":1440}]}
```

Contoh di atas dipersingkat. Metode yang dikembalikan mengikuti konfigurasi backend:

| Kode | Instruksi pembayaran |
| --- | --- |
| `bca_va` | `bank`, `va_number` |
| `bni_va` | `bank`, `va_number` |
| `bri_va` | `bank`, `va_number` |
| `permata_va` | `bank`, `va_number` |
| `mandiri_bill` | `bank`, `bill_key`, `biller_code` |
| `qris` | `qr_code_url` |

## Membuat, mencoba ulang, atau mengganti metode

`POST /orders/{order}/payment`

Header tambahan: `Idempotency-Key: payment-order-42-attempt-1` (wajib, maksimal 100 karakter).

```json
{"method":"bca_va"}
```

Respons `201` jika membuat percobaan baru, atau `200` jika menggunakan percobaan yang sudah ada:

```json
{
  "success": true,
  "code": "PAYMENT_READY",
  "message": "Informasi pembayaran berhasil diambil.",
  "data": {
    "order_id": 42,
    "order_number": "ORD-EXAMPLE",
    "order_status": "pending_payment",
    "payment_due_at": "2026-11-10T10:00:00+07:00",
    "payment_required": true,
    "payment_token": null,
    "payment_url": null,
    "payment": {
      "id": 7,
      "method": "bca_va",
      "payment_type": "bank_transfer",
      "transaction_id": "example-transaction-id",
      "provider_order_id": "ORD-EXAMPLE-P000001",
      "state": "pending",
      "transaction_status": "pending",
      "amount": "10000.00",
      "currency": "IDR",
      "bank": "bca",
      "va_number": "1234567890",
      "bill_key": null,
      "biller_code": null,
      "qr_code_url": null,
      "expires_at": "2026-11-10T10:00:00+07:00",
      "paid_at": null,
      "last_synced_at": "2026-11-09T10:00:00+07:00"
    }
  }
}
```

- Retry request yang sama memakai key yang sama. Key lama mengembalikan percobaan lama, termasuk jika sudah kedaluwarsa.
- Mengganti metode atau menerbitkan QR yang sudah kedaluwarsa memakai key baru pada order yang sama. Backend memastikan transaksi lama berakhir sebelum menerbitkan penggantinya. Jika status transaksi lama belum dapat dipastikan, respons `202 PAYMENT_PROCESSING` dan charge baru tidak dibuat.
- Key baru dengan metode yang sama masih memakai instruksi aktif jika belum kedaluwarsa.
- Key yang sama dengan metode berbeda menghasilkan `409 IDEMPOTENCY_CONFLICT`.
- Jika charge awal timeout dan status Midtrans belum ditemukan (`state: unknown`), `POST /orders/{order}/payment/refresh` hanya memeriksa status; endpoint ini tidak mengirim charge baru. Retry eksplisit request pembayaran semula dengan key yang sama dapat memulihkan percobaan tersebut.
- `202 PAYMENT_PROCESSING` berarti pergantian metode atau pembatalan lama belum terkonfirmasi; `data` bernilai `null`. Baca snapshot dengan `GET /orders/{order}/payment`, lalu ulangi request pergantian dengan metode dan key yang sama. Jangan meminta metode lain dengan key baru atau membayar instruksi lama selama status belum jelas.
- Jika pembayaran lama terdeteksi lunas saat pergantian berlangsung, order menjadi `paid` dan percobaan baru tidak dibuat. Jika konfirmasi lunas datang terlambat setelah metode baru diterbitkan, order ditandai `payment_review` agar admin memeriksa kemungkinan pembayaran ganda.
- Field `payment_token` dan `payment_url` dipertahankan dengan nilai `null`; integrasi ini memakai Core API.

Panduan langkah demi langkah dan koleksi khusus Postman tersedia pada [Pergantian Metode Pembayaran](payment-method-switching.md).

## Membaca dan menyinkronkan pembayaran

| Endpoint | Kegunaan |
| --- | --- |
| `GET /orders/{order}/payment` | Snapshot database tanpa memanggil Midtrans |
| `POST /orders/{order}/payment/refresh` | Memverifikasi status melalui Get Status Midtrans |
| `GET /orders/{order}` | Detail order beserta pembayaran aktif |
| `POST /orders/{order}/cancel` | Membatalkan order dan transaksi pending setelah konfirmasi Midtrans |

Show dan refresh memakai bentuk respons pembayaran di atas; `payment` dapat `null` jika belum ada percobaan. Order yang sudah dibayar tidak dapat dibatalkan customer. Pembatalan tidak mengembalikan item ke cart.

Status order: `pending_payment`, `paid`, `cancelled`, `expired`, `payment_review`, atau `refunded`. Percobaan pembayaran mempunyai `state` tersendiri, seperti `creating`, `unknown`, `pending`, `cancelling`, `paid`, `expired`, `cancelled`, `failed`, `review`, dan `refunded`. Gunakan status order untuk keputusan operasional. Pembayaran terlambat, pembayaran ganda, dan status yang perlu pemeriksaan masuk `payment_review`; refund versi ini dikerjakan melalui dashboard Midtrans.

## Gambar QRIS

`GET /orders/{order}/payments/{payment}/qr-code`

Ambil URL dari `payment.qr_code_url`, tetap sertakan Bearer token. Respons berhasil berupa PNG dengan `Cache-Control: no-store`. Endpoint hanya untuk percobaan QRIS aktif milik customer; QR kedaluwarsa/tidak lagi dapat dibayar menghasilkan `410`. Jangan menambahkan Server Key ke website atau aplikasi Android.

## Webhook / Payment Notification URL

`POST /payments/midtrans/webhook`

Route bernama `payments.midtrans.webhook`. Endpoint publik tanpa Bearer token dan tanpa CSRF form web. Autentikasi notifikasi menggunakan signature Midtrans; frontend tidak perlu memanggil endpoint ini.

Contoh Notification URL, ganti `DOMAIN-BACKEND-AKTIF` dengan domain/tunnel HTTPS yang sedang berjalan:

```text
https://DOMAIN-BACKEND-AKTIF/api/v1/payments/midtrans/webhook
```

Pasang URL lengkap tersebut pada **Payment Notification URL** di dashboard **Midtrans Sandbox**, lalu simpan. Ketika domain tunnel berubah, perbarui pengaturan dashboard. Domain production nantinya menggunakan path yang sama. `APP_URL` tidak otomatis mengubah konfigurasi dashboard Midtrans.

Payload minimal (nilai contoh, signature harus berasal dari Midtrans):

```json
{
  "order_id": "ORD-EXAMPLE-P000001",
  "transaction_id": "example-transaction-id",
  "status_code": "200",
  "gross_amount": "10000.00",
  "transaction_status": "settlement",
  "signature_key": "<SHA512-dari-Midtrans>"
}
```

Backend membandingkan `SHA512(order_id + status_code + gross_amount + ServerKey)` dengan `signature_key`, menyimpan inbox secara persisten, lalu memprosesnya pada queue `payments`. Nominal, mata uang, identitas transaksi, dan status diverifikasi ulang melalui Get Status sebelum order dinyatakan lunas. Pengiriman ulang notifikasi tidak menggandakan pencatatan pembayaran.

Respons penerimaan `200`:

```json
{"success":true,"message":"Webhook diterima.","code":"PAYMENT_WEBHOOK_ACCEPTED","data":null}
```

`200` berarti notifikasi diterima; sinkronisasi berlangsung melalui worker. Payload tidak lengkap menghasilkan `422`, signature tidak valid menghasilkan `403 INVALID_SIGNATURE`. Membuka URL lewat address bar browser menggunakan GET dan mendapat `405`; itu bukan pengujian POST webhook.

## Konfigurasi dan worker backend

```dotenv
APP_URL=https://domain-backend-anda.example
ORDER_PROCESSING_MODE=integrated
MIDTRANS_SERVER_KEY=
MIDTRANS_IS_PRODUCTION=false
MIDTRANS_PAYMENT_METHODS=bca_va,bni_va,bri_va,mandiri_bill,permata_va,qris
MIDTRANS_QRIS_ACQUIRER=gopay
MIDTRANS_VA_EXPIRY_MINUTES=1440
MIDTRANS_QRIS_EXPIRY_MINUTES=15
MIDTRANS_ORDER_TTL_HOURS=24
MIDTRANS_TIMEOUT=10
CHECKOUT_STOCK_MODE=unlimited
FREE_SHIPPING_ENABLED=true
```

Isi Server Key sandbox di `.env`; jangan kirim ke frontend atau menyimpannya dalam koleksi Postman. Client Key tidak diperlukan oleh endpoint Core API backend ini. Mode `integrated` berlaku pada order baru; order manual lama tetap mengikuti konfirmasi admin.

Setelah mengubah konfigurasi, jalankan `php artisan config:clear` dan restart worker. Jalankan worker yang mengonsumsi queue pembayaran:

```bash
php artisan queue:work --queue=payments --tries=5 --timeout=60
```

Horizon telah menyertakan queue `payments`; jika Horizon digunakan, restart agar konfigurasi barunya terbaca. Scheduler menjalankan rekonsiliasi setiap menit. Untuk lokal:

```bash
php artisan schedule:work
```

Pada server, jalankan cron `* * * * *` untuk `php artisan schedule:run` dari direktori aplikasi. Pemulihan manual dapat dipicu dengan `php artisan payments:reconcile --limit=50`, lalu diproses worker. Jangan hanya menjalankan worker queue `default` karena notifikasi pembayaran memakai queue `payments`.

## Kesalahan utama

| HTTP | Arti |
| --- | --- |
| `202` | Operasi sedang berjalan atau pembatalan provider belum terkonfirmasi; pertahankan key retry |
| `401` / `403` | Token customer tidak sesuai atau signature webhook salah |
| `404` | Order/percobaan tidak ditemukan atau bukan milik customer |
| `409` | Konflik key, order sudah ditutup/dibayar, atau metode tidak dapat diganti |
| `410` | QR tidak lagi dapat digunakan |
| `422` | Metode, key, atau payload tidak valid |
| `429` | Batas request customer tercapai |
| `503` | Provider belum dapat dikonfirmasi; sinkronkan dan retry dengan key yang sama |

Pembuatan instruksi dibatasi 10 request/menit per customer/order, refresh 12 request/menit. Batas ini tidak diterapkan pada route webhook publik.

Referensi: [Core API](https://docs.midtrans.com/reference/core-api-overview), [HTTP Notification](https://docs.midtrans.com/docs/https-notification-webhooks). Koleksi request umum tersedia di [Postman](../postman/midtrans-core-payments.postman_collection.json); koleksi skenario ganti metode tersedia di [Payment Method Switching](../postman/payment-method-switching.postman_collection.json).
