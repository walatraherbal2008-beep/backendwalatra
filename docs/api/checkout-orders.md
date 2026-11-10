# API Checkout Order

Base URL: `/api/v1`, misalnya `http://127.0.0.1:8000/api/v1` pada server lokal.

Semua endpoint di bawah memerlukan akun customer aktif dan token dengan ability `customer-api`:

```http
Authorization: Bearer <customer_token>
Accept: application/json
Content-Type: application/json
```

ID, nomor VA, tanggal, dan URL pada contoh adalah ilustrasi. Nominal dikembalikan sebagai string desimal rupiah, misalnya `"155000.00"`.

## Alur penggunaan

1. Ambil item cart melalui `GET /cart` dan alamat melalui `GET /addresses`.
2. Kirim item terpilih serta alamat ke `POST /checkout/preview`.
3. Setelah total disetujui pembeli, kirim `POST /orders` dengan token preview dan `Idempotency-Key`.
4. Kirim metode bank atau QRIS ke `POST /orders/{order}/payment` untuk memperoleh instruksi pembayaran dari Midtrans.
5. Baca order atau refresh pembayaran sampai backend mengonfirmasi statusnya.

**Checkout dan pembuatan VA/QRIS menggunakan dua request terpisah.** Respons `POST /orders` menyimpan order terlebih dahulu dengan `payment: null`. Nomor VA muncul pada `data.payment.va_number` setelah endpoint pembayaran berhasil. Metode pembayaran dikirim sebagai `method` pada endpoint pembayaran, bukan `payment_method` pada pembuatan order.

Untuk penambahan produk/bundle ke cart, lihat [API cart](cart-management.md). Pengelolaan alamat tersedia di [API alamat customer](customer-addresses.md).

## Ringkasan endpoint

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| POST | `/checkout/preview` | Tinjau harga, ongkir, gudang asal, dan pilihan barang |
| POST | `/orders` | Simpan order dari item cart |
| GET | `/orders` | Daftar order milik customer |
| GET | `/orders/{order}` | Detail order dan pembayaran aktif |
| POST | `/orders/{order}/cancel` | Batalkan order yang masih dapat dibatalkan |
| GET | `/payment-methods` | Daftar metode pembayaran yang tersedia |
| POST | `/orders/{order}/payment` | Buat atau gunakan kembali instruksi VA/QRIS |
| GET | `/orders/{order}/payment` | Baca snapshot pembayaran tersimpan |
| POST | `/orders/{order}/payment/refresh` | Verifikasi status terbaru melalui Midtrans |

`{order}` adalah `data.id` dari respons pembuatan order, bukan `order_number`.

## 1. Preview checkout

`POST /checkout/preview`

```json
{
  "address_id": 5,
  "cart_item_ids": [12]
}
```

`cart_item_ids` berisi ID item dari `GET /cart`, bukan ID produk, varian, atau listing. Produk dan bundle dapat dipilih bersama dalam array yang sama. Jika field ini tidak dikirim, seluruh cart diproses. Array yang dikirim wajib tidak kosong, berisi integer positif unik, dan semua item harus ada di cart customer tersebut.

Respons `200`, contoh satu produk:

```json
{
  "success": true,
  "message": "Order dapat diajukan. Stok belum diverifikasi.",
  "data": {
    "checkout_token": "<token-preview-dari-server>",
    "price_changed": false,
    "price_changes": [],
    "simulation": false,
    "payment_required": true,
    "payment_provider": "midtrans",
    "stock_mode": "unlimited",
    "shipping_origin": {
      "warehouse_code": "5504027954349",
      "warehouse_name": "TASIKMALAYA",
      "address": "gudang herbal tamansari",
      "postal_code": "46196",
      "coordinates": "-7.366010843848928, 108.22806304350148"
    },
    "stock_verification": "pending_manual",
    "checkout_available": true,
    "items": [
      {
        "cart_item_id": 12,
        "type": "product",
        "image_url": "https://api.example.com/storage/produk.png",
        "sku": "SKU-WAL-KELOREENA-100",
        "quantity": 1,
        "unit_price": "155000.00",
        "subtotal": "155000.00"
      }
    ],
    "subtotal": "155000.00",
    "shipping": {
      "service": "FREE",
      "eta_days": null,
      "gross_fee": "0.00",
      "subsidy": "0.00",
      "fee": "0.00",
      "subsidy_rule_id": null
    },
    "total": "155000.00"
  }
}
```

Token preview berlaku **15 menit**, terikat pada akun, pilihan item, quantity, harga, dan kode gudang. Preview tidak membuat order atau mereservasi stok. `price_changes` membandingkan harga master dengan harga yang tersimpan pada cart.

Preview dapat dipanggil tanpa alamat, tetapi `shipping` dan `total` bernilai `null`. Sertakan alamat agar memperoleh total lengkap sebelum membuat order.

## 2. Membuat order

`POST /orders`

Header tambahan wajib:

```http
Idempotency-Key: checkout-customer-1-attempt-1
```

```json
{
  "address_id": 5,
  "cart_item_ids": [12],
  "checkout_token": "<token-preview-dari-server>"
}
```

| Field | Ketentuan |
| --- | --- |
| Header `Idempotency-Key` | Wajib, tidak kosong setelah trim, maksimal 100 karakter |
| `address_id` | Integer positif; alamat harus milik customer. Wajib jika tidak menggunakan `shipping_address` |
| `shipping_address` | Alternatif alamat langsung; wajib jika tidak menggunakan `address_id` |
| `cart_item_ids` | Opsional; jika dikirim wajib array tidak kosong, integer positif unik, dan milik cart customer |
| `checkout_token` | Opsional pada validasi API; jika dikirim wajib string tidak kosong maksimal 4.096 karakter. Kirim token preview agar perubahan pilihan/harga dapat dideteksi |

Jika `address_id` dan `shipping_address` dikirim bersamaan, alamat tersimpan dari `address_id` digunakan. Contoh alternatif tanpa alamat tersimpan:

```json
{
  "cart_item_ids": [12],
  "checkout_token": "<token-preview-dari-server>",
  "shipping_address": {
    "name": "Pembeli Walatra",
    "phone": "6281234567890",
    "address": "Jalan Contoh Nomor 1",
    "province": "Jawa Barat",
    "city": "Tasikmalaya",
    "district": "Cihideung",
    "postal_code": "46121"
  }
}
```

Pada alamat langsung, `name`, `phone`, `address`, `province`, `city`, dan `postal_code` wajib diisi. Field alamat opsional dijelaskan pada [dokumentasi alamat](customer-addresses.md). Jika mengirim ID wilayah Everpro pada `shipping_address`, kirim lengkap keempatnya: `province_id`, `city_id`, `district_id`, dan `sub_district_id`.

Respons pertama **`201 Created`**:

```json
{
  "data": {
    "id": 501,
    "order_number": "WAL-EXAMPLE",
    "status": "pending_payment",
    "payment_provider": "midtrans",
    "simulation": false,
    "payment_required": true,
    "payment_integration": "core_api",
    "payment_due_at": "2026-11-10T13:00:00+07:00",
    "payment": null,
    "payment_instructions": null,
    "paid_at": null,
    "currency": "IDR",
    "subtotal": "155000.00",
    "shipping_gross_fee": "0.00",
    "shipping_subsidy": "0.00",
    "shipping_fee": "0.00",
    "shipping_service": "FREE",
    "total": "155000.00",
    "shipping_address": {
      "name": "Pembeli Walatra",
      "phone": "6281234567890",
      "address": "Jalan Contoh Nomor 1",
      "province": "Jawa Barat",
      "city": "Tasikmalaya",
      "district": "Cihideung",
      "postal_code": "46121"
    },
    "shipping_origin": {
      "warehouse_code": "5504027954349",
      "warehouse_name": "TASIKMALAYA",
      "address": "gudang herbal tamansari",
      "postal_code": "46196",
      "coordinates": "-7.366010843848928, 108.22806304350148"
    },
    "stock_mode": "unlimited",
    "items": [
      {
        "id": 601,
        "type": "product",
        "image_url": "https://api.example.com/storage/order-item-images/contoh/produk.png",
        "sku": "SKU-WAL-KELOREENA-100",
        "product_name": "Keloreena",
        "variant_name": "100 Kapsul",
        "bundle_components": null,
        "store_name": "Toko Herbal",
        "quantity": 1,
        "unit_price": "155000.00",
        "subtotal": "155000.00"
      }
    ],
    "created_at": "2026-11-09T13:00:00+07:00"
  },
  "success": true,
  "message": "Order berhasil dibuat."
}
```

Order, snapshot barang/alamat/gudang, dan salinan foto disimpan sebelum respons sukses. Item yang dipilih dihapus dari cart; item lain tetap tersedia. `items[].id` pada respons adalah ID item order, berbeda dari ID item cart. Untuk bundle, `type` bernilai `bundle` dan `bundle_components` berisi snapshot komponennya. Item tanpa foto mengembalikan `image_url: null`.

Retry dengan **key yang sama** mengembalikan order tersimpan dengan HTTP **`200`** dan pesan `Order sebelumnya berhasil diambil.` Gunakan key baru hanya untuk pembelian baru; key lama tidak membuat ulang order yang sudah dibatalkan atau kedaluwarsa.

## 3. Mendapatkan VA atau QRIS

Ambil pilihan metode melalui `GET /payment-methods`. Metode yang didukung: `bca_va`, `bni_va`, `bri_va`, `permata_va`, `mandiri_bill`, dan `qris`, sesuai konfigurasi backend.

`POST /orders/501/payment`

Gunakan key pembayaran tersendiri dan pertahankan nilainya ketika retry:

```http
Idempotency-Key: payment-order-501-attempt-1
```

```json
{
  "method": "bca_va"
}
```

Respons **`201`** untuk percobaan baru atau **`200`** jika menggunakan pembayaran yang sudah tersimpan:

```json
{
  "success": true,
  "code": "PAYMENT_READY",
  "message": "Informasi pembayaran berhasil diambil.",
  "data": {
    "order_id": 501,
    "order_number": "WAL-EXAMPLE",
    "order_status": "pending_payment",
    "payment_due_at": "2026-11-10T13:00:00+07:00",
    "payment_required": true,
    "payment_token": null,
    "payment_url": null,
    "payment": {
      "id": 701,
      "method": "bca_va",
      "payment_type": "bank_transfer",
      "transaction_id": "example-midtrans-transaction-id",
      "provider_order_id": "WAL-EXAMPLE-P000001",
      "state": "pending",
      "transaction_status": "pending",
      "amount": "155000.00",
      "currency": "IDR",
      "bank": "bca",
      "va_number": "1234567890",
      "bill_key": null,
      "biller_code": null,
      "qr_code_url": null,
      "expires_at": "2026-11-10T13:00:00+07:00",
      "paid_at": null,
      "last_synced_at": "2026-11-09T13:00:05+07:00"
    }
  }
}
```

VA berasal dari Midtrans, disimpan bersama percobaan pembayaran, lalu dikirim sebagai `data.payment.va_number`. Permata juga memakai field yang sama. Mandiri menggunakan `bill_key` dan `biller_code`. QRIS menggunakan `qr_code_url`; ambil gambar PNG dari URL tersebut dengan Bearer token customer.

Jika permintaan pembayaran timeout setelah order dibuat, lanjutkan pengecekan/retry pembayaran pada **order yang sama**. Gunakan key pembayaran yang sama; jangan mengulangi checkout dengan key baru.

Detail pergantian metode, penerbitan QR kedaluwarsa, format QR, dan webhook tersedia pada [API pembayaran Midtrans Core](midtrans-core-payments.md).

## 4. Membaca status dan membatalkan order

| Request | Respons |
| --- | --- |
| `GET /orders/501` | `200`; struktur `data` sama dengan objek order di atas, menggunakan status terbaru dan pembayaran aktif |
| `GET /orders?page=1` | `200`; `data` berupa array order, disertai `links` dan `meta`; 15 order per halaman, terbaru dahulu |
| `GET /orders/501/payment` | `200`; snapshot pembayaran tanpa memanggil Midtrans |
| `POST /orders/501/payment/refresh` | Verifikasi status melalui Midtrans; menggunakan bentuk respons pembayaran |
| `POST /orders/501/cancel` | `200`; objek order dengan status `cancelled` setelah pembatalan terkonfirmasi |

Refresh dan cancel tidak memerlukan body JSON. Untuk Midtrans, pembatalan dapat mengembalikan `202` ketika provider belum mengonfirmasi hasilnya. Order yang telah dibayar atau memerlukan pemeriksaan tidak boleh dianggap berhasil dibatalkan.

| Status order | Arti |
| --- | --- |
| `pending_payment` | Order tersimpan dan menunggu pembayaran |
| `paid` | Pembayaran telah diverifikasi backend |
| `cancelled` | Order dibatalkan |
| `expired` | Order ditutup karena batas pembayaran berakhir |
| `payment_review` | Pembayaran membutuhkan pemeriksaan superadmin |
| `refunded` | Pengembalian pembayaran telah tersinkron |

Pembayaran terverifikasi melalui webhook/refresh dapat memperbarui order menjadi `paid`. Status ini bukan status pengiriman. Pembatalan tidak otomatis mengembalikan item order ke cart.

## 5. Validasi dan kegagalan

| HTTP / kode | Penanganan |
| --- | --- |
| `401` / `403` | Periksa token, ability, dan status akun |
| `404` | Order atau alamat tidak ditemukan/milik akun lain |
| `422` | Periksa key, alamat, pilihan item, atau token preview tidak valid; cart kosong juga menghasilkan `422` |
| `409 PRICE_CHANGED` | Harga berubah pada checkout tanpa token; tinjau preview baru |
| `409 CHECKOUT_CHANGED` | Pilihan, quantity, harga, atau kode gudang berubah sejak preview; ambil token baru |
| `409 CHECKOUT_EXPIRED` | Token preview melewati 15 menit; buat preview baru |
| `409 STALE_CART` | Barang tidak lagi tersedia saat pembuatan order |
| `409 WAREHOUSE_NOT_ASSIGNED` | Varian/komponen bundle belum memiliki titik pengiriman |
| `409 WAREHOUSE_MISMATCH` | Barang terpilih belum memiliki satu gudang bersama |
| `409 WAREHOUSE_SELECTION_REQUIRED` | Beberapa gudang bersama tersedia tetapi prioritas server belum ditentukan |
| `409 WAREHOUSE_NOT_AVAILABLE` | Gudang prioritas/direktori tidak memenuhi pilihan barang |
| `202 PAYMENT_PROCESSING` | Operasi pembayaran berlangsung; baca status dan retry dengan key yang sama |
| `409` pada pembayaran | Key dipakai untuk metode berbeda, order ditutup/lunas, atau batas pembayaran tercapai |
| `429` | Batas request pembayaran/refresh tercapai |
| `503` | Gudang, stok, atau provider pembayaran belum dapat dikonfirmasi |

Tidak semua respons error memiliki `code`; baca juga `message` dan `errors` jika tersedia. Barang yang gagal checkout dipertahankan di cart; kegagalan membuat instruksi pembayaran setelah checkout tidak membatalkan order yang sudah tersimpan.

## Kondisi operasional saat ini

- Checkout memakai Midtrans Core API. Order dapat dibayar selama 24 jam; VA maksimal 24 jam dan QRIS maksimal 15 menit, dibatasi deadline order.
- Ongkir gratis: `shipping_service: FREE` dan `shipping_fee: "0.00"`.
- `stock_mode: unlimited` berarti jumlah stok fisik belum membatasi checkout. `stock_verification: pending_manual` pada preview merupakan status pemeriksaan stok, bukan metode pembayaran manual.
- Semua varian produk dan komponen bundle terpilih wajib memiliki penetapan gudang. Tasikmalaya dipilih jika sesuai penetapan/prioritas server; customer tidak mengirim gudang dan belum ada pemilihan berdasarkan jarak.
- Order manual lama tetap memakai alur pembayaran manual. Dokumen ini berfokus pada order baru dengan `payment_provider: midtrans`.
