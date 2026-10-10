# API Marketplace Walatra — Website Developer

Dokumen ini adalah panduan integrasi website marketplace. Website memakai API
yang sama dengan Android dan tidak membutuhkan login untuk menampilkan katalog
publik.

## Konfigurasi

```text
API_BASE_URL=http://127.0.0.1:8000/api/v1
```

Untuk production, gunakan domain API HTTPS. Semua request sebaiknya menyertakan:

```http
Accept: application/json
Content-Type: application/json
```

## Flow website

```text
Landing/katalog publik
  → detail produk atau bundle
  → login OTP saat ingin membeli
  → cart server-side
  → alamat customer
  → preview checkout
  → order
  → pembayaran
```

## Katalog publik

Tidak perlu mengirim `Authorization`.

```javascript
const response = await fetch(`${API_BASE_URL}/catalog?per_page=15`, {
  headers: { Accept: 'application/json' },
});

const result = await response.json();
```

Endpoint yang tersedia:

| Method | Endpoint | Kegunaan |
|---|---|---|
| GET | `/catalog` | Gabungan produk dan bundle |
| GET | `/catalog/products` | Produk aktif dari toko eligible |
| GET | `/catalog/products/{slug}` | Detail produk dan listing toko |
| GET | `/catalog/bundles` | Bundle aktif dari toko eligible |
| GET | `/catalog/bundles/{slug}` | Detail bundle dan komponen |
| GET | `/catalog/categories` | Kategori aktif |
| GET | `/catalog/categories/{slug}` | Detail kategori |

Filter utama:

```text
q, type, store, category, min_price, max_price, sort, page, per_page
```

Contoh katalog toko tertentu:

```text
GET /api/v1/catalog/products?store=admin-toko-tes-vibium-admin-toko&per_page=50
GET /api/v1/catalog/bundles?store=admin-toko-tes-vibium-admin-toko&per_page=50
```

Gunakan `images[].url`, `image_url`, atau `image.url` untuk gambar. Gunakan
`listing_id` sebagai identitas listing toko; jangan menggantinya dengan `product.id`.

## Login customer dengan OTP

```javascript
await fetch(`${API_BASE_URL}/auth/customer/request-otp`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ phone: '081234567890' }),
});
```

Verifikasi OTP:

```javascript
const response = await fetch(`${API_BASE_URL}/auth/customer/verify-otp`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({
    phone: '081234567890',
    challenge_id: 1,
    otp: '123456',
    device_name: 'Website Chrome',
  }),
});

const { data } = await response.json();
// Simpan token menggunakan mekanisme yang aman.
```

Endpoint `POST /auth/login` email/password sudah tidak digunakan untuk customer
dan mengembalikan HTTP `410` dengan code `OTP_ONLY_AUTHENTICATION`. Jangan
mengimplementasikan fallback password pada website.

## Cart, alamat, checkout, dan order

Setelah login, kirim header:

```http
Authorization: Bearer {token}
```

Tambah produk:

```json
{
  "type": "product",
  "listing_id": 10,
  "variant_id": 21,
  "quantity": 1
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

Endpoint customer:

| Method | Endpoint | Login |
|---|---|---|
| GET | `/cart` | Wajib |
| POST | `/cart/items` | Wajib |
| PATCH | `/cart/items/{item}` | Wajib |
| DELETE | `/cart/items/{item}` | Wajib |
| DELETE | `/cart` | Wajib |
| GET/POST/PATCH/DELETE | `/addresses` | Wajib |
| POST | `/checkout/preview` | Wajib |
| GET | `/orders` | Wajib |
| POST | `/orders` | Wajib |
| POST | `/orders/{id}/payment` | Wajib |

Cart bersifat server-side. Website tidak perlu membuat cart utama sendiri di
browser; cukup simpan token dan ambil ulang `/cart` setelah login atau refresh.

Untuk menghitung ongkir dan subsidi gratis ongkir pada preview, kirim alamat
tersimpan:

```json
POST /api/v1/checkout/preview
{ "address_id": 12 }
```

Response data.shipping mengandung gross_fee, subsidy, fee, service, dan
eta_days. Nilai fee adalah ongkir yang dibayar member. Jika provider ongkir
belum aktif, API mengembalikan 503 SHIPPING_UNAVAILABLE; client tidak boleh
menganggapnya sebagai gratis.

Untuk onboarding toko, password yang dikirim pada `POST /store-onboarding`
hanya digunakan untuk akses panel admin toko. Itu tidak mengubah aturan login
customer marketplace yang tetap OTP-only.

## Alamat pengiriman

Sebelum menyimpan alamat, website dapat mengambil hierarchy lokasi Everpro dari:

```text
GET /shipping/locations/provinces
GET /shipping/locations/cities?province_id=32
GET /shipping/locations/districts?city_id=3273
GET /shipping/locations/sub-districts?district_id=327301
POST /shipping/locations/resolve
```

Field penting alamat:

```json
{
  "label": "Rumah",
  "name": "Nama Penerima",
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
  "is_default": true
}
```

Jika mengirim ID hierarchy, seluruh ID terkait wajib dikirim. Simpan ID tersebut
agar alamat tetap kompatibel dengan integrasi Everpro berikutnya.

## Error dan aturan tampilan

| HTTP | Penanganan website |
|---|---|
| 401 | Hapus sesi/token dan arahkan ke login |
| 403 | Tampilkan akses ditolak atau pendaftaran sedang ditutup |
| 404 | Tampilkan data tidak ditemukan |
| 409 | Refresh data listing/cart lalu minta user mencoba lagi |
| 422 | Tampilkan error per field |
| 429 | Tampilkan pesan terlalu banyak request dan retry terkontrol |
| 503 | Tampilkan layanan stok/pihak ketiga sedang tidak tersedia |

## Status publikasi toko

Sebuah produk dapat tampil publik jika:

1. Produk master berstatus `active`.
2. Produk memiliki varian aktif.
3. Listing toko berstatus `active`.
4. Toko berstatus `active` dan eligible.
5. Owner adalah member Walatra aktif, email terverifikasi, dan memiliki role
   `store_admin`.

Provider ongkir sudah dipakai pada preview checkout dan order. Aturan subsidi
gratis ongkir dikelola dari panel Superadmin. API stok gudang, shipment, AWB,
dan tracking masih menunggu provider masing-masing.

## Referensi Android

Detail seluruh request, response, onboarding admin toko, dan Postman collection
tersedia di [android-marketplace-api.md](android-marketplace-api.md).
