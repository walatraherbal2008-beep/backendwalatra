# Referensi API Marketplace Walatra

Dokumen ini adalah pintu masuk ringkas untuk API internal marketplace dan layanan eksternal yang sudah dipelajari. Rincian yang lebih panjang tetap tersedia pada dokumen khusus yang ditautkan di bagian akhir.

**Pembaruan:** 20 November 2026  
**Base URL internal:** `http://127.0.0.1:8000/api/v1` (lokal)  
**Format:** JSON, kecuali disebutkan lain.

> Daftar endpoint internal di bawah merangkum route dan kontrak yang sudah ditinjau dalam repository. Dokumentasi Everpro adalah spesifikasi provider; bagian pengiriman Everpro belum berarti integrasinya sudah aktif di backend Walatra.

## Daftar isi

- [Aturan umum](#aturan-umum)
- [Autentikasi customer](#autentikasi-customer)
- [Katalog dan banner](#katalog-dan-banner)
- [Cart](#cart)
- [Alamat dan lokasi Everpro](#alamat-dan-lokasi-everpro)
- [Checkout, order, dan pembayaran](#checkout-order-dan-pembayaran)
- [Gudang dan stok](#gudang-dan-stok)
- [API eksternal lainnya](#api-eksternal-lainnya)
- [Konfigurasi lingkungan](#konfigurasi-lingkungan)
- [Dokumentasi lanjutan](#dokumentasi-lanjutan)

## Aturan umum

- Kirim `Accept: application/json` pada request API.
- Untuk body JSON, kirim `Content-Type: application/json`.
- Endpoint customer terproteksi memakai `Authorization: Bearer <customer_token>`.
- Simpan URL dasar dengan `/api/v1` di Postman. Contoh: `{{base_url}}/cart`; jangan menambahkan `/api/v1` lagi pada path request.
- Respons API internal umumnya membungkus hasil dalam `data`; validasi biasanya mengembalikan `message` dan `errors`.
- Jangan memasukkan API key, secret, token provider, atau isi `.env` ke dokumentasi, Postman yang dibagikan, maupun log publik.

## Autentikasi customer

Semua request login menggunakan nomor telepon. OTP dikirim melalui provider WhatsApp bila integrasi aktif.

| Method | Path | Akses | Tujuan |
|---|---|---|---|
| POST | `/auth/customer/request-otp` | Publik | Meminta OTP dengan body `{ "phone": "..." }` |
| POST | `/auth/customer/verify-otp` | Publik | Verifikasi `{ "phone", "challenge_id", "otp", "device_name" }`; menghasilkan token Bearer dan profil |
| GET | `/auth/me` | Bearer | Profil customer yang sedang login |
| PATCH | `/auth/customer/profile` | Bearer | Memperbarui profil, body `{ "name": "..." }` |
| POST | `/auth/logout` | Bearer | Mencabut token aktif |

OTP berlaku terbatas (5 menit). Nomor baru dapat dibuatkan akun saat OTP berhasil diverifikasi. Nilai OTP hanya disertakan pada respons dalam konfigurasi lokal/testing yang mengaktifkan eksposur OTP. Endpoint password lama `/auth/login` tidak digunakan untuk login customer dan mengembalikan `410`.

## Katalog dan banner

### Katalog produk

| Method | Path | Tujuan |
|---|---|---|
| GET | `/catalog` | Katalog gabungan produk dan bundle; mendukung pencarian, filter, sort, dan pagination |
| GET | `/catalog/products` | Daftar produk |
| GET | `/catalog/products/{slug}` | Detail produk |
| GET | `/catalog/bundles` | Daftar bundle |
| GET | `/catalog/bundles/{slug}` | Detail bundle |
| GET | `/catalog/categories` | Daftar kategori |
| GET | `/catalog/categories/{slug}` | Detail kategori |

Filter katalog gabungan meliputi `q`, `type`, `store`, `category`, `min_price`, `max_price`, `sort`, `per_page`, dan `page`. Nilai `sort` yang didukung: `relevance`, `latest`, `name_asc`, `name_desc`, `price_asc`, `price_desc`; `per_page` dibatasi 1–50.

Bedakan ID listing (`listing_id`), ID varian (`variant_id`), ID listing bundle (`bundle_listing_id`), kode master produk (`product_code`), dan SKU varian (`sku`). Foto varian tersedia sebagai `image_url` jika ada; fallback mengikuti kontrak katalog. Endpoint publik hanya menampilkan data yang memenuhi status publik/aktif.

### Banner

| Method | Path | Akses | Catatan |
|---|---|---|---|
| GET | `/banners` | Publik | Banner aktif; opsional `?position=main`, `secondary_top`, atau `secondary_bottom` |

Objek banner dapat memuat `image_url`, `cta_url`, `open_in_new_tab`, posisi, urutan, serta rentang waktu tayang. Field area lama tidak menjadi dasar tata letak frontend; posisi banner adalah pengelompokan konten sesuai kontrak yang berlaku.

## Cart

Cart produk dan bundle berada dalam satu daftar.

| Method | Path | Akses | Tujuan |
|---|---|---|---|
| GET | `/cart` | Bearer | Mengambil seluruh item cart; item terbaru lebih dahulu |
| POST | `/cart/items` | Bearer | Menambahkan produk atau bundle |
| PATCH | `/cart/items/{item}` | Bearer | Mengganti quantity item |
| DELETE | `/cart/items/{item}` | Bearer | Menghapus satu item |
| DELETE | `/cart` | Bearer | Mengosongkan cart |

Contoh tambah produk:

```json
{
  "type": "product",
  "listing_id": 1,
  "variant_id": 1,
  "quantity": 2
}
```

Contoh tambah bundle:

```json
{
  "type": "bundle",
  "bundle_listing_id": 1,
  "quantity": 1
}
```

POST menambahkan quantity ke item yang sama, dengan batas total 99. PATCH menetapkan quantity baru 1–99. Tampilkan quantity dari respons backend; client sebaiknya menonaktifkan tombol tambah selama request berlangsung karena POST bukan operasi idempotent.

Pilihan checkout dikirim sebagai daftar ID cart, tanpa disimpan sebagai status pilihan:

```text
GET /cart?cart_item_ids[]=301&cart_item_ids[]=302
```

Respons tetap memuat seluruh cart, serta `selection` berisi `cart_item_ids`, `line_count`, `total_items`, `subtotal`, dan `unavailable_item_ids`. Tanpa parameter pilihan, ringkasan pilihan kosong/nol. Item tidak tersedia tetap terlihat dengan `is_available: false` dan `availability_message: "Tidak tersedia"`; user masih dapat menghapusnya. Item usang tidak dapat diubah atau dibeli.

## Alamat dan lokasi Everpro

### Alamat customer (API internal)

| Method | Path | Akses | Tujuan |
|---|---|---|---|
| GET | `/addresses` | Bearer | Daftar alamat milik customer |
| POST | `/addresses` | Bearer | Membuat alamat |
| PUT/PATCH | `/addresses/{id}` | Bearer | Mengubah alamat |
| DELETE | `/addresses/{id}` | Bearer | Menghapus alamat |

Field utama: `label`, `name`, `phone`, `province`, `city`, `postal_code`, `address`; detail kecamatan, catatan, ID/nama wilayah, koordinat, dan `is_default` mengikuti kontrak alamat. Tidak ada endpoint GET detail alamat terpisah.

### Proxy lokasi internal

Proxy publik berikut meneruskan pencarian wilayah ke Everpro. Client menggunakan endpoint Walatra; kredensial Everpro hanya digunakan backend.

| Method | Path internal | Parameter/body |
|---|---|---|
| GET | `/shipping/locations/provinces` | Opsional `q` |
| GET | `/shipping/locations/cities` | `province_id` atau nama wilayah yang didukung |
| GET | `/shipping/locations/districts` | `city_id` atau nama yang didukung |
| GET | `/shipping/locations/sub-districts` | `district_id`, kode pos, atau nama yang didukung |
| POST | `/shipping/locations/resolve` | JSON alamat atau `postal_code` |

### Endpoint lokasi provider Everpro

Base URL sandbox yang dicontohkan oleh Everpro adalah `https://client-api-sandbox.everpro.id`. Gunakan base URL production yang diberikan Everpro untuk akun production; jangan menyimpulkannya dari URL sandbox.

| Method | Path Everpro | Input penting |
|---|---|---|
| POST | `/auth/v1/token` | JSON `client_key`, `client_secret`; respons token Bearer dan masa berlaku |
| GET | `/location/v1/provinces` | Opsional `province_name` |
| GET | `/location/v1/cities` | Salah satu `province_id`, `province_name`, `city_name`; utamakan ID |
| GET | `/location/v1/districts` | Salah satu `city_id`, `district_name`; utamakan ID |
| GET | `/location/v1/sub-districts` | `district_id`, `sub_district_name`, atau `postal_code`; utamakan ID |
| POST | `/location/v1/postalcode` | JSON `address` atau `postal_code`; jika keduanya dikirim, kode pos diprioritaskan |

Backend saat ini mengintegrasikan layanan lokasi/alamat tersebut. Token diminta menggunakan client credentials dan di-cache oleh aplikasi; jangan meminta token Everpro dari aplikasi frontend.

## Checkout, order, dan pembayaran

### Urutan transaksi

1. Ambil cart, tentukan item terpilih, dan pilih alamat.
2. Minta preview checkout.
3. Tinjau perubahan harga dan ringkasan preview.
4. Buat order dengan token preview dan idempotency key.
5. Buat pembayaran melalui endpoint pembayaran terpisah.
6. Tampilkan instruksi VA/QRIS dari respons pembayaran dan tunggu notifikasi/status provider.

### Endpoint internal

| Method | Path | Akses | Kegunaan |
|---|---|---|---|
| POST | `/checkout/preview` | Bearer | Validasi pilihan, harga, alamat, dan gudang; menghasilkan `checkout_token` |
| POST | `/orders` | Bearer | Membuat order baru dari preview |
| GET | `/orders` | Bearer | Daftar order customer |
| GET | `/orders/{order}` | Bearer | Detail order |
| POST | `/orders/{order}/cancel` | Bearer | Meminta pembatalan order sesuai status yang diizinkan |
| GET | `/payment-methods` | Publik | Daftar metode pembayaran aktif |
| POST | `/orders/{order}/payment` | Bearer | Membuat percobaan pembayaran |
| GET | `/orders/{order}/payment` | Bearer | Membaca snapshot pembayaran tersimpan |
| POST | `/orders/{order}/payment/refresh` | Bearer | Sinkronisasi status dari Midtrans tanpa membuat charge baru |
| GET | `/orders/{order}/payments/{payment}/qr-code` | Bearer | Mengambil QR pembayaran yang sudah tercatat |
| POST | `/payments/midtrans/webhook` | Provider | Notifikasi status Midtrans; validasi signature wajib |

Preview menerima `address_id` atau data `shipping_address` serta opsional `cart_item_ids`. Respons menyediakan `checkout_token`, selection, ringkasan harga, dan `price_changed`/`price_changes` jika ada perubahan harga. Jika harga berubah, minta user meninjau ulang preview sebelum membuat order.

Setiap order baru wajib membawa `checkout_token` yang masih valid. Token mengikat preview pada customer dan keadaan checkout yang diperiksa (termasuk item/quantity/harga/gudang) selama 15 menit. Token bukan token login atau pembayaran; fungsinya mencegah order dibuat dari ringkasan lama. Jika token kedaluwarsa atau checkout berubah, buat preview baru. Retry order yang telah berhasil dengan `Idempotency-Key` yang sama mengembalikan order yang sama walau token lama kedaluwarsa.

Contoh header pembuatan order:

```http
Authorization: Bearer <customer_token>
Idempotency-Key: order-<uuid>
Content-Type: application/json
```

Body order membawa `checkout_token` dari preview. Jangan menggunakan token preview sebagai `Idempotency-Key`.

Pembuatan pembayaran adalah langkah terpisah:

```http
POST /orders/{order_id}/payment
Idempotency-Key: payment-<uuid>
Content-Type: application/json
```

```json
{ "method": "bca_va" }
```

Metode yang disediakan bergantung konfigurasi dan dapat mencakup `bca_va`, `bni_va`, `bri_va`, `permata_va`, `mandiri_bill`, dan `qris`. Respons pembayaran dapat berisi nomor VA atau data QRIS sesuai metode/provider; tampilkan instruksi tersebut kepada pembeli. Ulangi request logis dengan key yang sama. Jangan membuat charge baru hanya untuk mengecek status: gunakan GET snapshot atau endpoint refresh. Jika pembayaran masih pending/ambigu, sinkronkan status terlebih dahulu sebelum membuat percobaan lain. Pembayaran yang berhasil dikomunikasikan belum tentu lunas; status final berasal dari notifikasi/status provider yang tervalidasi.

Status order dan status pembayaran adalah dua hal terpisah. Status order dapat berupa `pending_payment`, `paid`, `cancelled`, `expired`, `payment_review`, atau `refunded`, mengikuti transisi aplikasi. Webhook URL harus dapat dijangkau Midtrans, misalnya `https://<domain>/api/v1/payments/midtrans/webhook`.

### Midtrans Core API eksternal

Backend menggunakan Server Key melalui Basic Authentication (Server Key sebagai username, password kosong). Base URL sandbox umumnya `https://api.sandbox.midtrans.com`; production `https://api.midtrans.com`.

| Operasi | Request Midtrans |
|---|---|
| Membuat transaksi | `POST /v2/charge` |
| Mengecek status | `GET /v2/{provider_order_id}/status` |
| Membatalkan transaksi | `POST /v2/{provider_order_id}/cancel` |

Lihat [dokumentasi resmi Midtrans Core API](https://docs.midtrans.com/reference/quick-start-1) untuk skema provider terkini.

Respons charge dapat mencakup `transaction_id`, `transaction_status`, `payment_type`, `va_numbers`/`permata_va_number`, atau instruksi QRIS seperti `actions`. Aplikasi menyimpan data pembayaran untuk ditampilkan dan diaudit. Kredensial dan data sensitif harus disaring dari log. Keberhasilan HTTP/API hanya membuktikan provider menerima request, bukan bahwa pembeli sudah membayar.

## Gudang dan stok

### API internal

| Method | Path | Akses | Tujuan |
|---|---|---|---|
| GET | `/warehouses` | Publik | Daftar titik/gudang pengiriman |
| POST | `/warehouses/group` | Publik | Informasi/pengelompokan gudang sesuai kontrak |
| POST | `/warehouses/{code}/stock` | Bearer | Memeriksa stok produk dari gudang eksternal |

Request cek stok memakai `{ "product_code": "QNC", "quantity": 2 }`. `product_code` adalah kode master produk, bukan SKU varian. Respons mencakup jumlah tersedia dan kecukupan. Pemeriksaan ini tidak mereservasi stok; hasilnya dapat berubah sebelum pengiriman.

### API eksternal direktori dan stok Walatra

Host yang saat ini dipakai adalah `https://originalwalatraherbal.com/index.php`.

| Operasi | Method dan URL | Body |
|---|---|---|
| Daftar gudang | POST `?procs=gudang.list` | Bergantung kontrak provider |
| Detail gudang | POST `?procs=gudang.detail` | Form `code=<kode_gudang>` |
| Sisa stok produk | POST `?procs=gudang.stok.sisa` | Multipart `code=<kode_gudang>`, `produk=<kode_produk>` |

Respons stok menggunakan nilai `data.jumlah`. Nilai dari provider adalah snapshot; jumlah yang dapat dibeli tidak boleh negatif. Proses sinkronisasi snapshot admin dapat berjalan di queue/background. Mode stok checkout bergantung konfigurasi lingkungan; pastikan `CHECKOUT_STOCK_MODE` dan integrasi stok production ditinjau sebelum deploy. Stok yang dibaca belum tentu stok terreservasi.

## API eksternal lainnya

### Maxchat (OTP/WhatsApp dan health check)

- Base URL berisi username akun, misalnya `https://core.maxchat.id/<username>`.
- Endpoint uji status yang digunakan backend: `GET /api/system/status` dengan autentikasi Bearer dari konfigurasi server.
- Maxchat juga dipakai untuk pengiriman OTP bila diaktifkan.
- Pengujian koneksi mencatat request dan respons sesuai mekanisme log internal; token harus tetap dirahasiakan.

### API health/monitoring

Panel superadmin menyediakan pemeriksaan konektivitas provider yang didukung konfigurasi (termasuk Midtrans, Everpro, Maxchat, titik pengiriman, dan layanan stok/tarif bila aktif). Pemeriksaan endpoint adalah tes komunikasi, bukan jaminan seluruh operasi bisnis provider berhasil. Respons HTTP, payload, dan error transport harus dibaca bersama; jangan menyamakan endpoint yang merespons dengan transaksi sukses.

## Konfigurasi lingkungan

Nama variabel relevan (nilai rahasia hanya di `.env`, jangan salin nilainya ke dokumentasi):

| Integrasi | Variabel utama |
|---|---|
| Everpro | `EVERPRO_BASE_URL`, `EVERPRO_CLIENT_KEY`, `EVERPRO_CLIENT_SECRET`, timeout terkait |
| Midtrans | `MIDTRANS_BASE_URL`, `MIDTRANS_SERVER_KEY`, `MIDTRANS_IS_PRODUCTION`, `MIDTRANS_QR_ALLOWED_HOSTS` |
| Maxchat | `MAXCHAT_ENABLED`, `MAXCHAT_BASE_URL`, `MAXCHAT_API_TOKEN`, timeout terkait |
| Gudang/stok | variabel `WAREHOUSE_*`, konfigurasi host/dan endpoint stok, `CHECKOUT_STOCK_MODE` |
| Aplikasi/webhook | `APP_URL` dan URL HTTPS yang dapat diakses provider |

Periksa `.env.example` dan konfigurasi aplikasi untuk nama serta default terbaru. URL webhook Midtrans perlu mengarah ke route `/api/v1/payments/midtrans/webhook`. Domain ngrok bersifat sementara; perbarui dashboard provider bila URL tunnel berubah.

## Batas implementasi Everpro

Bagian alamat di atas adalah integrasi Everpro yang dipakai backend saat ini. Operasi shipment berikut berasal dari dokumentasi provider dan menjadi rujukan implementasi mendatang; **belum dinyatakan aktif pada backend Walatra**:

| Operasi Everpro | Endpoint provider |
|---|---|
| Daftar kurir/layanan | `GET /shipment/v1/logistics` |
| Cek tarif | `POST /shipment/v2/rates` |
| Waktu pickup | `GET /shipment/v1/pickup-times` |
| Daftar shipment | `GET /shipment/v1/orders` |
| Detail shipment | `GET /shipment/v1/orders/{order_number}` |
| Buat shipment | `POST /shipment/v1/orders` |
| Buat AWB | `POST /shipment/v1/orders/{client_order_no}/generate` |
| Ambil AWB | `GET /shipment/v1/orders/{client_order_no}/awb` |
| Label | `GET /shipment/v1/orders/{awb_number}/label` |
| Tracking | `GET /shipment/v1/orders/{awb_number}/track` |
| Tracking banyak AWB | `POST /shipment/v1/orders/track` dengan `awb_numbers` |
| Batalkan shipment | `POST /shipment/v1/orders/{order_identifier}/cancel` |

Alur provider umumnya memilih layanan/rate sebelum membuat shipment, lalu menghasilkan AWB dan membaca tracking. Persyaratan shipment mencakup data asal/tujuan, paket, berat/dimensi, layanan kurir, dan data penerima; detail wajib bergantung endpoint dan layanan. `client_order_no` digunakan sebagai identitas order client pada operasi tertentu. COD shipment adalah fitur logistik dan terpisah dari pembayaran order marketplace melalui Midtrans.

Callback tracking harus dikonfigurasi di dashboard Everpro. Dokumentasi provider menyebut callback POST dengan header `x-everpro-key`; endpoint penerima perlu memvalidasi secret yang disepakati, mencatat payload, dan merespons HTTP 200 setelah diterima. Everpro dapat mencoba ulang callback jika tidak menerima respons sukses; buat penanganan callback idempotent dan cek kebijakan retry terbaru pada dokumentasi akun.

Sebelum mengaktifkan shipment Everpro, konfirmasi base URL production, kredensial/izin layanan, payload terkini, callback secret, serta proses gudang yang digunakan. Referensi resmi: [Dokumentasi Everpro Open API](https://developer.everpro.id/).

## Dokumentasi lanjutan

- [Autentikasi web](web-authentication-api.md)
- [Katalog produk](product-catalog.md)
- [Manajemen cart](cart-management.md)
- [Cart dan checkout](cart-checkout.md)
- [Checkout dan order](checkout-orders.md)
- [Alur cart, checkout, dan pembayaran](cart-checkout-payment-flow.md)
- [Alamat customer dan lokasi](customer-addresses.md)
- [Midtrans Core API](midtrans-core-payments.md)
- [Pergantian metode pembayaran](payment-method-switching.md)
- [Banner](banners.md)
- [Gudang dan stok terdekat](nearest-warehouse-stock.md)
- [Setup Postman](postman-setup.md)
- [Pengelolaan gudang](../warehouse-management.md)
- [Log komunikasi pembayaran](../payment-communication-logs.md)
- [Monitoring transaksi toko](../store-transactions.md)

Koleksi Postman tersedia di [`docs/postman/`](../postman/). Cocokkan environment Postman dengan server yang sedang diuji; jangan membagikan bearer token atau secret.
