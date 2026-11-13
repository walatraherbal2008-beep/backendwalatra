# Pergantian Metode Pembayaran

Dokumen ini menjelaskan cara mengganti metode pembayaran pada order Midtrans Core yang masih `pending_payment`, termasuk penanganan status transaksi yang belum pasti.

## Syarat

- Customer sudah login dan memiliki `customer_token` dengan ability `customer-api`.
- Order milik customer dan masih berstatus `pending_payment`.
- Percobaan pembayaran Midtrans lama mungkin masih aktif.
- Gunakan `base_url` yang mencakup `/api/v1`, misalnya `http://127.0.0.1:8000/api/v1`.

## Cara mengganti metode

Kirim metode tujuan ke endpoint pembayaran pada order yang sama. Buat `Idempotency-Key` baru untuk aksi pergantian ini.

```http
POST /api/v1/orders/{order_id}/payment
Authorization: Bearer <customer_token>
Idempotency-Key: switch-order-501-to-qris-01
Content-Type: application/json
Accept: application/json
```

```json
{
  "method": "qris"
}
```

Backend akan memeriksa status transaksi lama. Jika masih dapat dibayar, backend meminta Midtrans membatalkannya lalu memeriksa status lagi. Percobaan baru hanya diterbitkan setelah status lama dipastikan terminal. Gunakan kode metode yang tersedia dari `GET /api/v1/payment-methods`.

## Respons yang perlu ditangani

| Respons | Makna dan tindakan |
| --- | --- |
| `201 PAYMENT_READY` | Percobaan baru dibuat. Tampilkan instruksi dari `data.payment`, misalnya `va_number` atau `qr_code_url`. |
| `200 PAYMENT_READY` | Permintaan idempotent memakai percobaan yang sudah ada. Tampilkan pembayaran pada respons. |
| `202 PAYMENT_PROCESSING` | Status lama belum dapat dipastikan atau pembatalannya masih berjalan. `data` bernilai `null`; jangan tampilkan instruksi baru dan jangan bayar instruksi lama. Ulangi request pergantian dengan metode dan key yang sama. |
| `409 ORDER_NOT_PAYABLE` | Order lunas, ditutup, atau perlu pemeriksaan. Muat ulang order dan jangan membuat percobaan baru. |
| `503` | Midtrans belum dapat dikonfirmasi. Pertahankan key dan ulangi request/status sesuai langkah di bawah. |

### Jika menerima `202` atau timeout

1. Pertahankan nilai `method` dan `Idempotency-Key` yang dipakai untuk pergantian.
2. `GET /api/v1/orders/{order_id}/payment` dapat dipakai untuk membaca snapshot database; endpoint ini tidak menghubungi Midtrans.
3. `POST /api/v1/orders/{order_id}/payment/refresh` memeriksa status ke Midtrans dan **tidak membuat charge baru**.
4. Ulangi `POST /api/v1/orders/{order_id}/payment` dengan metode tujuan dan key pergantian yang sama sampai server mengembalikan `200`/`201` atau status final lain.
5. Jangan membuat key baru untuk metode lain selagi pergantian sebelumnya masih `PAYMENT_PROCESSING`.

Jika charge pertama sempat timeout dan `state` pembayaran masih `unknown`, status Midtrans yang belum ditemukan tidak membuktikan charge tidak pernah diterima. Karena itu refresh dan pergantian metode tidak mengirim charge ulang. Retry request pembayaran awal dengan key awal yang sama hanya untuk melanjutkan percobaan awal; jika user ingin mengganti metode, teruskan request pergantian dengan key pergantian.

## Aturan `Idempotency-Key`

- Key awal dan key pergantian adalah dua nilai berbeda karena keduanya merupakan aksi berbeda.
- Retry request yang sama, termasuk setelah `202` atau timeout, wajib memakai key yang sama.
- Jangan menggunakan key pergantian yang sama untuk metode lain. Server menolak key yang dipakai ulang dengan metode berbeda.
- Buat key baru hanya setelah instruksi pembayaran sebelumnya selesai/kedaluwarsa dan user memulai aksi baru.
- Key maksimal 100 karakter. UUID juga dapat digunakan.

Contoh urutan:

| Aksi | Metode | Key |
| --- | --- | --- |
| Buat pembayaran awal | `bca_va` | `payment-order-501-01` |
| Ganti ke QRIS | `qris` | `switch-order-501-qris-01` |
| Retry pergantian QRIS | `qris` | `switch-order-501-qris-01` |

## Pembayaran terlambat

Midtrans dapat mengonfirmasi pembayaran lama terlambat setelah metode baru diterbitkan. Backend menandai order sebagai `payment_review` agar transaksi dapat diperiksa oleh superadmin. Jika lebih dari satu percobaan terkonfirmasi lunas, jangan menganggap pembayaran kedua otomatis dikembalikan; penyelesaian/refund perlu diperiksa melalui monitoring transaksi dan dashboard Midtrans.

## Pengujian dengan Postman

Impor koleksi [Walatra - Payment Method Switching](../postman/payment-method-switching.postman_collection.json). Isi `customer_token` dan `order_id` dari order yang masih menunggu pembayaran. Jalankan request **Buat pembayaran awal (VA)**, lalu **Ganti metode**. Jika mendapat `202`, jalankan **Snapshot pembayaran** atau **Refresh status (tanpa charge)**, kemudian ulangi **Retry pergantian (key sama)**.

`base_url` koleksi harus mencakup `/api/v1`. Jika Environment Postman memiliki variable bernama sama, nilainya menimpa Collection. Server Key Midtrans tidak diperlukan di Postman.

Dokumentasi lengkap API pembayaran ada di [API Midtrans Core](midtrans-core-payments.md).
