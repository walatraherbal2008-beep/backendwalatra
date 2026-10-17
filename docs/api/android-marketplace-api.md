# API Marketplace Walatra — Android Developer

Dokumen ini juga menjadi referensi kontrak API untuk website marketplace. Android
dan website menggunakan endpoint yang sama; perbedaannya hanya pada cara
menyimpan token, state cart, dan cara menampilkan response.

Base URL lokal:

```text
http://127.0.0.1:8000/api/v1
```

Header JSON:

```http
Accept: application/json
Content-Type: application/json
```

Endpoint customer memakai:

```http
Authorization: Bearer {token}
```

Response sukses memiliki `success`, `message`, dan `data`. Error memiliki `success: false`, `message`, `code`, dan `errors`.

## Urutan penggunaan

```text
Catalog publik → Login/OTP → Alamat → Cart → Checkout preview → Order → Payment
```

## Catalog publik — tanpa login

Endpoint catalog dapat dipanggil sebelum login. Login hanya diperlukan ketika
member mulai menggunakan fitur personal seperti cart, alamat, checkout, order,
atau pembayaran. Data catalog hanya memuat listing aktif dari toko yang eligible
untuk marketplace. Produk master yang belum memiliki listing aktif tidak akan
tampil.

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/catalog` | Katalog gabungan produk dan bundle |
| GET | `/catalog/products` | Daftar produk aktif |
| GET | `/catalog/products/{slug}` | Detail produk |
| GET | `/catalog/categories` | Daftar kategori aktif |
| GET | `/catalog/categories/{slug}` | Detail kategori |
| GET | `/catalog/bundles` | Daftar bundle aktif |
| GET | `/catalog/bundles/{slug}` | Detail bundle |
| GET | `/banners` | Daftar banner iklan yang sedang aktif |

Parameter katalog yang tersedia: `q`, `type`, `store`, `category`, `min_price`, `max_price`, `sort`, `page`, `per_page`. Nilai `type`: `product` atau `bundle`. Nilai `sort`: `relevance`, `latest`, `name_asc`, `name_desc`, `price_asc`, `price_desc`.

Contoh:

```http
GET /api/v1/catalog/products?q=madu&sort=price_asc&page=1&per_page=15
GET /api/v1/catalog/products/madu-hitam
GET /api/v1/catalog/bundles/paket-sehat-keluarga
GET /api/v1/catalog/products?store=nama-slug-toko&per_page=50
GET /api/v1/catalog/bundles?store=nama-slug-toko&per_page=50
```

Response list produk menyediakan `listing_id`, data `product`, `store`,
`variants`, `images`, dan `published_at`. Gunakan `listing_id` dan `variant_id`
ketika menambahkan produk ke cart. Untuk bundle gunakan `listing_id` pada data
bundle sebagai `bundle_listing_id`.

Website dan Android wajib mengambil URL gambar dari field `images[].url` atau
`image_url`; jangan membentuk URL storage sendiri. Jika toko belum mengunggah
gambar etalase, backend menggunakan fallback gambar produk pusat.

### Banner iklan publik

Banner tidak membutuhkan login. Backend hanya mengembalikan banner dengan status
`active` yang berada dalam rentang `starts_at` dan `ends_at`. Banner yang belum
mulai atau sudah berakhir tidak dikembalikan.

```http
GET /api/v1/banners
```

Gunakan `sort_order` untuk urutan tampilan. Angka lebih kecil tampil lebih dahulu.
Ketika banner dipilih, Android atau website dapat membuka `cta_url`.

## Lokasi Everpro — tanpa login

| Method | Endpoint | Parameter/body |
|---|---|---|
| GET | `/shipping/locations/provinces` | `q` opsional |
| GET | `/shipping/locations/cities` | `province_id`, `province_name`, atau `city_name` |
| GET | `/shipping/locations/districts` | `city_id` atau `district_name` |
| GET | `/shipping/locations/sub-districts` | `district_id`, `sub_district_name`, atau `postal_code` |
| POST | `/shipping/locations/resolve` | `address` atau `postal_code` |

Contoh resolve:

```json
{
  "postal_code": "40135"
}
```

Simpan ID hasil Everpro (`province_id`, `city_id`, `district_id`, `sub_district_id`) ketika menyimpan alamat customer.

## Daftar gudang eksternal — tanpa login

Backend memproxy API eksternal berikut:

```text
https://originalwalatraherbal.com/index.php?procs=gudang.list
```

API eksternal menggunakan method `POST`, sedangkan Android tetap memanggil endpoint backend kita.

```http
GET /api/v1/warehouses
```

Endpoint ini meneruskan request `POST` ke API eksternal tanpa field body dan mengembalikan seluruh daftar gudang.

Untuk mengambil gudang berdasarkan group, gunakan endpoint terpisah dengan method `POST` dan `form-data`:

```http
POST /api/v1/warehouses/group
Content-Type: multipart/form-data
```

Field form-data:

```text
area_group = pulaujawa
```

Nilai `area_group`: `pulaujawa` atau `luarjawa`.

Contoh request dari Postman ke backend:

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/warehouses/group" \
  -F "area_group=pulaujawa"
```

Contoh request langsung ke API eksternal:

```bash
curl -X POST "https://originalwalatraherbal.com/index.php?procs=gudang.list" \
  -F "area_group=pulaujawa"
```

Response API eksternal memiliki format dasar:

```json
{
  "error": "",
  "data": []
}
```

Backend menormalisasi response tersebut ke format standar API marketplace.

Response:

```json
{
  "success": true,
  "message": "Daftar gudang berhasil diambil.",
  "data": [
    {
      "code": "9667506354122",
      "initial": "BG",
      "codename": "BANDUNG",
      "realname": "Bandung",
      "area_group": "pulaujawa"
    }
  ],
  "meta": { "total": 1 }
}
```

`code` adalah identifier dari API gudang eksternal dan dapat digunakan untuk relasi produk-gudang. Endpoint ini belum menyediakan stok, alamat detail, koordinat, atau kode Everpro.

## Authentication

### POST `/auth/customer/request-otp`

```json
{ "phone": "081234567890" }
```

Response memberikan `challenge_id` dan `expires_at`.

### POST `/auth/customer/verify-otp`

```json
{
  "phone": "081234567890",
  "challenge_id": 1,
  "otp": "123456",
  "device_name": "Android Samsung A55"
}
```

Simpan `data.token`. Periksa `requires_profile_completion`.

Autentikasi customer hanya menggunakan OTP nomor telepon. Nomor baru dibuat
sebagai akun customer setelah OTP valid; nomor lama hanya membuat token baru
untuk akun yang sudah ada. `is_new_user` membedakan kedua kondisi tersebut.

Endpoint legacy `POST /auth/login` tidak lagi menerima email/password dan
mengembalikan HTTP `410` dengan code `OTP_ONLY_AUTHENTICATION`. Login panel
Superadmin/Admin toko tetap terpisah dari Customer API.

### GET `/auth/me`

Mengambil data user aktif. Membutuhkan token.

### PATCH `/auth/customer/profile`

```json
{ "name": "Member Walatra" }
```

### POST `/auth/logout`

Menghapus token device yang sedang aktif.

## Cart — membutuhkan token

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/cart` | Ambil cart server-side |
| POST | `/cart/items` | Tambah produk/bundle |
| PATCH | `/cart/items/{item}` | Ubah quantity |
| DELETE | `/cart/items/{item}` | Hapus item |
| DELETE | `/cart` | Kosongkan cart |

Tambah produk:

```json
{
  "type": "product",
  "listing_id": 10,
  "variant_id": 21,
  "quantity": 2
}
```

Tambah bundle:

```json
{
  "type": "bundle",
  "bundle_listing_id": 7,
  "quantity": 1
}
```

Ubah quantity: `{ "quantity": 3 }`.

## Alamat customer — membutuhkan token

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/addresses` | Daftar alamat milik user |
| POST | `/addresses` | Tambah alamat |
| PATCH | `/addresses/{id}` | Ubah alamat secara partial |
| DELETE | `/addresses/{id}` | Hapus alamat |

Body tambah alamat:

```json
{
  "label": "Rumah",
  "name": "Member Walatra",
  "phone": "081234567890",
  "province": "Jawa Barat",
  "province_id": "32",
  "province_name": "Jawa Barat",
  "city": "Kota Bandung",
  "city_id": "3273",
  "city_name": "Kota Bandung",
  "district": "Coblong",
  "district_id": "327301",
  "district_name": "Coblong",
  "sub_district_id": "3273011001",
  "sub_district_name": "Dago",
  "postal_code": "40135",
  "address": "Jl. Contoh No. 1",
  "address_note": "Pagar hitam",
  "is_default": true
}
```

Jika mengirim salah satu ID lokasi, seluruh hierarchy ID wajib dikirim.

## Checkout dan order — membutuhkan token

### POST `/checkout/preview`

Memvalidasi isi cart dan ketersediaan stok. Untuk menghitung ongkir, kirim
address_id atau shipping_address. Tanpa alamat, field shipping dan total
bernilai null.

```json
{ "address_id": 12 }
```

Response shipping berisi gross_fee (tarif provider), subsidy (subsidi gratis
ongkir), dan fee (yang dibayar member). Subsidi tidak pernah melebihi tarif
provider. Status 503 berarti API stok atau provider ongkir belum tersedia.

### GET `/orders`

Daftar order milik user dengan pagination.

### GET `/orders/{id}`

Detail order milik user.

### POST `/orders`

Header wajib:

```http
Idempotency-Key: android-checkout-unique-001
```

Body dengan alamat tersimpan:

```json
{ "address_id": 12 }
```

Atau alamat langsung:

```json
{
  "shipping_address": {
    "name": "Member Walatra",
    "phone": "081234567890",
    "province": "Jawa Barat",
    "city": "Kota Bandung",
    "postal_code": "40135",
    "address": "Jl. Contoh No. 1"
  }
}
```

Gunakan `Idempotency-Key` yang sama ketika retry agar tidak membuat order ganda.

### POST `/orders/{id}/cancel`

Hanya order berstatus `pending_payment` yang dapat dibatalkan.

## Payment

### POST `/orders/{id}/payment`

Menyiapkan pembayaran Midtrans untuk order `pending_payment`.

Response berisi `payment_token` dan `payment_url`.

### POST `/payments/midtrans/webhook`

Endpoint server-to-server Midtrans, bukan dipanggil oleh Android. Backend memvalidasi signature, nominal, dan status transaksi.

## Status dan error

| HTTP | Arti |
|---|---|
| 200/201 | Berhasil |
| 401 | Token/kredensial tidak valid |
| 404 | Data tidak ditemukan |
| 409 | Data sudah berubah/tidak tersedia |
| 422 | Validasi gagal |
| 429 | Terlalu banyak request |
| 503 | Layanan eksternal belum tersedia |

Provider ongkir sudah diintegrasikan ke alur preview checkout dan pembuatan
order. Besaran subsidi dikelola Superadmin berdasarkan wilayah, minimal
belanja, batas berat, periode, dan prioritas aturan. API stok per gudang,
shipment, AWB, dan tracking masih menunggu provider masing-masing.

## Onboarding toko mandiri

Endpoint ini menggunakan token Sanctum dengan ability `customer-api`. User harus
lebih dulu login melalui OTP atau login customer biasa.

### POST `/store-onboarding`

Mendaftarkan toko mandiri. Pengaturan ini dapat dibuka atau ditutup Superadmin
melalui menu `Superadmin > Pengaturan > Pendaftaran toko mandiri`.

```json
{
  "name": "Toko Herbal Bandung",
  "email": "admin-toko@example.com",
  "password": "PasswordMinimal12",
  "city": "Bandung",
  "address": "Jl. Contoh No. 1"
}
```

`password` pada endpoint onboarding adalah kredensial panel admin toko, bukan
metode login customer marketplace. Login customer Android/website tetap wajib
menggunakan OTP. User yang mendaftar toko harus sudah terautentikasi sebagai
member melalui OTP.

Toko dibuat dengan status `pending_approval`. Email harus diverifikasi dan toko
harus disetujui Superadmin sebelum dapat mengakses panel admin toko.

Alur aplikasi:

```text
OTP customer → POST store-onboarding → verifikasi signed URL
→ persetujuan Superadmin → login panel /admin
```

Jika pengaturan pendaftaran mandiri ditutup, endpoint mengembalikan HTTP `403`
dengan code `SELF_SERVICE_STORE_REGISTRATION_DISABLED`. Pendaftaran hanya dapat
dilakukan oleh member Walatra aktif.

### GET `/store-onboarding/verify-email/{user}/{hash}`

Tautan bertanda tangan yang dikirim ke email. Tautan berlaku 24 jam dan hanya
dapat digunakan untuk email user yang sesuai.

## Undangan admin toko

### POST `/store/admin-invitations`

Membuat undangan admin tambahan. Hanya admin dari toko aktif yang dapat memakai
endpoint ini. Pengaturan dapat dikontrol Superadmin melalui menu Pengaturan.

```json
{
  "email": "admin-kedua@example.com"
}
```

### GET `/store/admin-invitations/accept/{token}`

Memeriksa token undangan dan mengembalikan informasi toko tujuan. Token berasal
dari tautan email dan memiliki masa berlaku 24 jam.

### POST `/store/admin-invitations/accept`

Menerima undangan. User harus sudah terautentikasi menggunakan token
`customer-api`, lalu mengirim:

```json
{
  "token": "token-dari-email",
  "email": "admin-kedua@example.com",
  "name": "Admin Kedua",
  "password": "PasswordMinimal12"
}
```

Token undangan hanya dapat digunakan satu kali. Admin tambahan hanya mendapat
akses ke toko yang mengundangnya dan tidak dapat menjadi Superadmin.

## Catatan implementasi Android dan website

- Catalog, kategori, lokasi Everpro, dan gudang adalah endpoint publik.
- Cart disimpan server-side sehingga Android dan website dapat melanjutkan cart
  setelah login pada perangkat berbeda.
- Jangan menyimpan Bearer token di local storage website; gunakan mekanisme cookie
  HttpOnly/Secure pada backend website atau penyimpanan token aman pada Android.
- Jangan menampilkan produk hanya berdasarkan data master. Gunakan response
  listing karena status, toko, gambar, dan harga berasal dari listing toko.
- Untuk publikasi toko, status toko harus `active`, email owner harus terverifikasi,
  owner harus memiliki role `store_admin`, dan listing harus `active`.
