# API Simulasi Order

Untuk operasional production dengan verifikasi pembayaran oleh admin, gunakan [API Order Manual](manual-orders.md). Dokumen ini khusus simulasi pengembangan lokal.

Mode ini digunakan untuk menguji pembelian sebelum integrasi pembayaran, stok, dan ongkir siap. Mode hanya aktif pada `APP_ENV=local` atau `testing` dan harus diaktifkan secara eksplisit di environment server. Parameter request customer tidak dapat mengaktifkannya.

Semua ID, OTP, token, produk, dan harga pada contoh bersifat ilustratif. Ambil nilai sebenarnya dari respons API; jangan mengasumsikan ID selalu `1`.

## Base URL dan header

Base URL lokal: `http://127.0.0.1:8000/api/v1`. Semua path endpoint di bawah relatif terhadap base URL ini.

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <TOKEN_DARI_VERIFY_OTP>
```

Header Authorization diperlukan untuk endpoint customer. Request OTP, verify OTP, dan katalog dapat diakses tanpa token. Nomor telepon dan OTP dikirim sebagai string; ID dan quantity sebagai integer. Harga dan total pada resource cart/order berupa string desimal, misalnya `"30000.00"`. Waktu menggunakan ISO 8601.

## Persiapan

```dotenv
ORDER_SIMULATION_ENABLED=true
ORDER_PROCESSING_MODE=integrated
ORDER_SIMULATION_SHIPPING_FEE=0
```

```bash
php artisan config:clear
php artisan migrate
php artisan orders:prepare-simulation
php artisan serve --host=127.0.0.1 --port=8000
```

Command terakhir menambahkan toko bernama **Toko Simulasi Order** beserta listing untuk produk aktif yang memiliki varian aktif dan bundle aktif yang dapat dibeli. Command dapat diulang dan tidak mereset master katalog, harga, gambar, atau listing toko lain. Produk tanpa varian aktif tidak dipublikasikan.

Login customer tetap menggunakan endpoint OTP yang sudah ada. Jika memakai kode dari respons untuk testing lokal, aktifkan `OTP_EXPOSE_CODE=true`. Konfigurasi dan pengiriman OTP tidak diubah oleh mode simulasi order.

Untuk proses testing yang hanya membaca OTP dari respons, operator dapat memakai `MAXCHAT_ENABLED=false` dan `QUEUE_CONNECTION=sync`; tidak ada pesan WhatsApp yang dikirim dan respons pengiriman berstatus `skipped`. Jika memakai queue Redis, jalankan Horizon untuk queue `otp` dan `default`. Command persiapan tidak membuat master produk jika katalog belum tersedia.

## Alur API

1. Login melalui `/api/v1/auth/customer/request-otp` dan `/api/v1/auth/customer/verify-otp`, lalu gunakan Bearer token.
2. Ambil listing di `GET /api/v1/catalog/products` atau `GET /api/v1/catalog/bundles`.
3. Tambahkan barang menggunakan `POST /api/v1/cart/items`, dengan `listing_id`, `variant_id`, dan `quantity`. Untuk bundle gunakan `type=bundle`, `bundle_listing_id`, dan `quantity`.
4. Simpan alamat melalui `POST /api/v1/addresses`.
5. Preview menggunakan `POST /api/v1/checkout/preview` dengan `address_id`.
6. Buat order menggunakan `POST /api/v1/orders` dengan `address_id` dan header `Idempotency-Key` yang unik untuk satu percobaan checkout. Saat mengulang request yang sama, gunakan key yang sama.
7. Periksa order melalui `GET /api/v1/orders` dan `GET /api/v1/orders/{id}`.

Contoh body create order:

```json
{"address_id": 1}
```

Preview akan mengembalikan:

```json
{
  "simulation": true,
  "payment_required": false,
  "checkout_available": true,
  "shipping": {"service": "SIMULATION", "fee": "0.00"}
}
```

Field di atas merupakan cuplikan dari `data`; subtotal dan total tetap dihitung dari isi cart. `ORDER_SIMULATION_SHIPPING_FEE` mengatur ongkir kotor simulasi dalam rupiah. Aturan subsidi ongkir yang aktif tetap diterapkan.

## Hasil checkout

Order langsung berstatus `paid` dengan:

```json
{
  "status": "paid",
  "payment_provider": "simulation",
  "simulation": true,
  "payment_required": false
}
```

- Tidak membutuhkan server key, token Snap, redirect pembayaran, atau webhook Midtrans.
- Stok dianggap tersedia; tidak ada reservasi atau pengurangan stok gudang eksternal.
- Harga, detail barang, komponen bundle, dan alamat disalin ke order seperti alur biasa.
- Cart dikosongkan setelah order tersimpan. Riwayat status mencatat bahwa pembayaran, stok, dan ongkir disimulasikan.
- Frontend dapat langsung membuka halaman detail/berhasil ketika `payment_required=false`. Jangan mencoba membuka Snap menggunakan token null.
- Jika client masih memanggil `POST /api/v1/orders/{id}/payment`, endpoint mengembalikan `PAYMENT_SIMULATED`, `payment_required=false`, serta token dan URL null tanpa memanggil gateway.
- Webhook Midtrans tidak dapat mengubah order dengan `payment_provider=simulation`.
- Validasi akun aktif, akses order milik sendiri, alamat, listing aktif, dan header idempotency tetap berlaku.
- Order simulasi mengikuti aturan order berstatus `paid`; endpoint pembatalan customer yang saat ini hanya menerima `pending_payment` tidak berlaku untuk order ini.

Mode ini belum menambahkan tahapan diproses, dikirim, resi, atau selesai. Tahapan tersebut dapat dikerjakan setelah testing pembuatan dan pembacaan order selesai.

## Ringkasan endpoint

| Method | Path | Bearer token | Keterangan |
|---|---|---|---|
| POST | `/auth/customer/request-otp` | Tidak | Meminta OTP |
| POST | `/auth/customer/verify-otp` | Tidak | Login dan otomatis membuat akun baru bila diperlukan |
| GET | `/auth/me` | Ya | Memeriksa user aktif |
| PATCH | `/auth/customer/profile` | Ya | Melengkapi nama customer |
| GET | `/catalog/products` | Tidak | Listing produk dan variannya |
| GET | `/catalog/bundles` | Tidak | Listing bundle dan komposisinya |
| GET | `/cart` | Ya | Membaca cart |
| POST | `/cart/items` | Ya | Menambahkan produk atau bundle |
| PATCH | `/cart/items/{item}` | Ya | Mengubah quantity |
| DELETE | `/cart/items/{item}` | Ya | Menghapus satu item |
| DELETE | `/cart` | Ya | Mengosongkan cart |
| GET | `/addresses` | Ya | Daftar alamat customer |
| POST | `/addresses` | Ya | Menyimpan alamat |
| POST | `/checkout/preview` | Ya | Preview stok, ongkir, dan total |
| POST | `/orders` | Ya | Membuat order; wajib header `Idempotency-Key` |
| GET | `/orders` | Ya | Riwayat order dengan pagination |
| GET | `/orders/{order}` | Ya | Detail order |
| POST | `/orders/{order}/payment` | Ya | Opsional: konfirmasi pembayaran simulasi |
| POST | `/orders/{order}/cancel` | Ya | Hanya menerima `pending_payment`; order simulasi `paid` ditolak |
| POST | `/auth/logout` | Ya | Mencabut token yang dipakai |

## 1. Login OTP dan pendaftaran otomatis

`POST /auth/customer/request-otp`

```json
{"phone": "089990001102"}
```

Respons `200`, contoh dengan MaxChat nonaktif dan queue sync:

```json
{
  "success": true,
  "code": "OTP_REQUESTED",
  "message": "Jika nomor dapat menerima WhatsApp, OTP akan dikirim.",
  "data": {
    "challenge_id": 15,
    "expires_at": "2026-11-02T20:05:00+07:00",
    "delivery_status": "skipped",
    "otp": "123456"
  }
}
```

`data.otp` hanya muncul jika `OTP_EXPOSE_CODE=true` pada environment `local/testing`. Status pengiriman dan pesan dapat berbeda jika MaxChat atau queue aktif. OTP berlaku 5 menit, maksimal 5 percobaan, dan hanya bisa dipakai sekali. Permintaan OTP baru membatalkan challenge sebelumnya untuk nomor serta purpose yang sama.

`POST /auth/customer/verify-otp`

```json
{
  "phone": "089990001102",
  "challenge_id": 15,
  "otp": "123456",
  "device_name": "postman-order-simulation"
}
```

Ganti challenge dan OTP dengan respons sebelumnya. `device_name` wajib, maksimal 100 karakter.

Respons `200` untuk akun baru:

```json
{
  "success": true,
  "code": "AUTHENTICATED",
  "message": "Login berhasil.",
  "data": {
    "user": {
      "id": 8,
      "name": null,
      "phone": "6289990001102",
      "profile_completed_at": null
    },
    "token": "8|TOKEN_CONTOH",
    "token_type": "Bearer",
    "is_new_user": true,
    "requires_profile_completion": true
  }
}
```

Simpan `data.token` untuk Authorization. Tidak ada endpoint daftar customer terpisah: verifikasi OTP otomatis membuat akun jika nomor belum terdaftar. Login nomor yang sudah terdaftar menghasilkan `is_new_user=false`.

Jika `requires_profile_completion=true`, kirim `PATCH /auth/customer/profile` dengan Bearer token:

```json
{"name": "Pembeli Simulasi"}
```

Nama wajib 2–255 karakter. Respons `200` berisi `code=PROFILE_UPDATED`, `data.user`, dan `data.requires_profile_completion=false`. `GET /auth/me` dapat dipakai untuk memeriksa profil dan token aktif. Lihat juga [dokumentasi autentikasi customer](web-authentication-api.md).

## 2. Memilih produk atau bundle

`GET /catalog/products?per_page=15&page=1`

Respons `200`; cuplikan satu listing, dengan field gambar, kategori, dan pagination dipangkas:

```json
{
  "success": true,
  "message": "Katalog produk berhasil diambil.",
  "data": [
    {
      "listing_id": 1,
      "product": {"id": 3, "name": "Produk Contoh", "slug": "produk-contoh"},
      "store": {"id": 1, "name": "Toko Simulasi Order"},
      "variants": [
        {
          "id": 7,
          "name": "Kemasan Contoh",
          "sku": "SKU-CONTOH-001",
          "price": "15000.00",
          "weight_grams": 100,
          "attributes": null
        }
      ]
    }
  ]
}
```

Gunakan `listing_id` dan `variants[n].id` untuk memasukkan produk ke cart. `product.id` bukan listing ID. Varian harus berasal dari produk listing tersebut.

`GET /catalog/bundles?per_page=15&page=1` mengembalikan listing bundle. Gunakan `data[n].listing_id` sebagai `bundle_listing_id`, bukan `bundle.id`. `bundle.price` adalah harga satu paket dan `bundle.items` berisi komposisinya.

Kedua katalog memakai pagination, maksimal 50 item per halaman; ikuti `links` dan `meta` pada respons. Katalog kosong berarti belum ada listing yang memenuhi syarat aktif. Periksa master katalog dan jalankan command persiapan simulasi.

## 3. Mengelola cart

`POST /cart/items` untuk produk:

```json
{"type": "product", "listing_id": 1, "variant_id": 7, "quantity": 2}
```

Untuk bundle, gunakan endpoint yang sama:

```json
{"type": "bundle", "bundle_listing_id": 4, "quantity": 1}
```

`quantity` wajib integer 1–99. Menambahkan listing/varian yang sama atau listing bundle yang sama akan menambah quantity pada item yang sudah ada, dengan batas total 99. Harga ditentukan server; tidak perlu mengirim `unit_price` atau total.

Respons tambah produk `200`:

```json
{
  "success": true,
  "message": "Produk berhasil ditambahkan ke cart.",
  "data": {
    "id": 2,
    "items": [
      {
        "id": 9,
        "type": "product",
        "listing_id": 1,
        "bundle_listing_id": null,
        "variant_id": 7,
        "product": {"id": 3, "name": "Produk Contoh", "slug": "produk-contoh"},
        "bundle": null,
        "store": {"id": 1, "name": "Toko Simulasi Order", "slug": "toko-simulasi-order"},
        "variant": {"id": 7, "name": "Kemasan Contoh", "sku": "SKU-CONTOH-001"},
        "quantity": 2,
        "unit_price": "15000.00",
        "subtotal": "30000.00"
      }
    ],
    "total_items": 2,
    "subtotal": "30000.00"
  }
}
```

`total_items` adalah jumlah unit/paket, bukan jumlah baris cart. Produk dan bundle dapat digabung dalam satu cart. Harga disalin saat item ditambahkan dan dipakai saat order dibuat; PATCH quantity tidak mengambil ulang harga master.

| Request | Body | Hasil |
|---|---|---|
| `GET /cart` | Tidak ada | Resource cart; umumnya `200`, dapat `201` saat cart baru dibuat otomatis |
| `PATCH /cart/items/9` | `{"quantity": 3}` | `200`, resource cart terbaru; quantity diganti menjadi 3 |
| `DELETE /cart/items/9` | Tidak ada | `200`, `code=CART_ITEM_DELETED` |
| `DELETE /cart` | Tidak ada | `200`, `code=CART_CLEARED` |

Parameter `{item}` adalah `data.items[n].id` dari cart, bukan listing ID atau variant ID.

## 4. Menyimpan alamat

`POST /addresses`

```json
{
  "label": "Rumah",
  "name": "Pembeli Simulasi",
  "phone": "089990001102",
  "province": "Jawa Barat",
  "city": "Bandung",
  "district": "Coblong",
  "postal_code": "40135",
  "address": "Alamat contoh untuk testing order",
  "address_note": "Pagar hitam",
  "is_default": true
}
```

Respons `201` berisi `success=true`, pesan `Alamat berhasil ditambahkan.`, dan `data` berupa resource alamat. Simpan `data.id` sebagai `address_id`. Nomor telepon dinormalisasi ke awalan `62`. Alamat pertama otomatis menjadi default. `GET /addresses` mengembalikan daftar alamat customer yang sedang login.

| Field | Ketentuan |
|---|---|
| `label` | Wajib, string maksimal 50 karakter |
| `name` | Wajib, string 2–255 karakter |
| `phone` | Wajib, nomor Indonesia valid, string maksimal 20 karakter |
| `province`, `city` | Wajib, masing-masing string maksimal 100 karakter |
| `postal_code` | Wajib, string maksimal 10 karakter |
| `address` | Wajib, string maksimal 1.000 karakter |
| `district` | Opsional, string maksimal 100 karakter |
| `address_note` | Opsional, string maksimal 500 karakter |
| `is_default` | Opsional, boolean |
| `latitude`, `longitude` | Opsional, angka dalam rentang -90 sampai 90 dan -180 sampai 180 |

ID lokasi Everpro tidak wajib untuk simulasi. Jika mengisi salah satu dari `province_id`, `city_id`, `district_id`, atau `sub_district_id`, sertakan keempatnya sebagai string. Resource alamat juga mengembalikan field ID/nama lokasi dan koordinat; field yang tidak diisi bernilai null.

## 5. Preview checkout

`POST /checkout/preview`

```json
{"address_id": 5}
```

Respons `200` untuk contoh cart 2 × Rp15.000:

```json
{
  "success": true,
  "message": "Ketersediaan stok berhasil divalidasi.",
  "data": {
    "simulation": true,
    "payment_required": false,
    "checkout_available": true,
    "items": [
      {
        "cart_item_id": 9,
        "type": "product",
        "sku": "SKU-CONTOH-001",
        "quantity": 2,
        "unit_price": "15000.00",
        "subtotal": "30000.00"
      }
    ],
    "subtotal": "30000.00",
    "shipping": {
      "service": "SIMULATION",
      "eta_days": null,
      "gross_fee": "0.00",
      "subsidy": "0.00",
      "fee": "0.00",
      "subsidy_rule_id": null
    },
    "total": "30000.00"
  }
}
```

Preview tidak membuat order atau mengosongkan cart. Preview tanpa alamat hanya memeriksa stok; `shipping` dan `total` bernilai null. Pada item bundle, `sku` di preview bernilai null.

Rumus: ongkir bersih = ongkir kotor dikurangi subsidi, minimal nol. Total order = subtotal ditambah ongkir bersih.

## 6. Membuat order

`POST /orders`

```http
Authorization: Bearer <TOKEN>
Accept: application/json
Content-Type: application/json
Idempotency-Key: checkout-simulasi-001
```

```json
{"address_id": 5}
```

Header `Idempotency-Key` wajib berupa string tidak kosong, maksimal 100 karakter. Gunakan key yang sama untuk retry satu percobaan checkout; gunakan key baru untuk pembelian baru. Header ini tidak diperlukan pada cart atau preview.

Respons pertama `201`, contoh dengan field opsional alamat dipangkas:

```json
{
  "success": true,
  "message": "Order berhasil dibuat.",
  "data": {
    "id": 1,
    "order_number": "WAL-CONTOH123456",
    "status": "paid",
    "payment_provider": "simulation",
    "simulation": true,
    "payment_required": false,
    "currency": "IDR",
    "subtotal": "30000.00",
    "shipping_gross_fee": "0.00",
    "shipping_subsidy": "0.00",
    "shipping_fee": "0.00",
    "shipping_service": "SIMULATION",
    "total": "30000.00",
    "shipping_address": {
      "label": "Rumah",
      "name": "Pembeli Simulasi",
      "phone": "6289990001102",
      "province": "Jawa Barat",
      "city": "Bandung",
      "district": "Coblong",
      "postal_code": "40135",
      "address": "Alamat contoh untuk testing order",
      "address_note": "Pagar hitam"
    },
    "items": [
      {
        "id": 12,
        "type": "product",
        "sku": "SKU-CONTOH-001",
        "product_name": "Produk Contoh",
        "variant_name": "Kemasan Contoh",
        "bundle_components": null,
        "store_name": "Toko Simulasi Order",
        "quantity": 2,
        "unit_price": "15000.00",
        "subtotal": "30000.00"
      }
    ],
    "created_at": "2026-11-02T20:00:00+07:00"
  }
}
```

Retry berurutan dengan user dan key yang sama mengembalikan order yang sudah ada dengan HTTP `200` serta pesan `Order sebelumnya berhasil diambil.`. Key dikenali per user, bukan per payload. Perlindungan request checkout bersamaan masih memiliki temuan pada [laporan audit](../audits/2026-11-02-checkout-order/README.md).

### Alternatif alamat langsung

Create order dan preview juga menerima `shipping_address`:

```json
{
  "shipping_address": {
    "name": "Pembeli Simulasi",
    "phone": "6289990001102",
    "province": "Jawa Barat",
    "city": "Bandung",
    "postal_code": "40135",
    "address": "Alamat contoh untuk testing order"
  }
}
```

Create order mewajibkan minimal `name`, `phone`, `province`, `city`, `postal_code`, dan `address` pada alamat langsung. Jika `address_id` dan alamat langsung sama-sama dikirim, server menggunakan alamat tersimpan milik user. Gunakan salah satu bentuk agar tidak ambigu.

### Item bundle pada order

Item memiliki `type=bundle`, SKU berawalan `BUNDLE-`, `variant_name=Bundle`, dan `bundle_components` berupa array berisi `variant_id`, `sku`, `product_name`, `variant_name`, serta `quantity`.

`items[n].quantity` adalah jumlah paket, sedangkan `bundle_components[n].quantity` adalah jumlah komponen per paket. Membeli 3 paket yang masing-masing berisi 2 unit berarti total komponen 6 unit. `unit_price` adalah harga satu paket; `subtotal` adalah harga paket dikalikan jumlah paket.

## 7. Riwayat, detail, dan payment opsional

`GET /orders?page=1` mengembalikan `data` berupa array resource order dengan `links` dan `meta`. Ukuran halaman saat ini 15 order, diurutkan dari terbaru. Hanya order user yang sedang login yang ditampilkan.

`GET /orders/1` mengembalikan `data` berupa satu resource order dengan bentuk yang sama seperti create order. Parameter `{order}` pada URL menggunakan `data.id`, bukan `order_number`.

`POST /orders/1/payment` tidak wajib dipanggil. Untuk order simulasi `paid`, responsnya `200` tanpa memanggil Midtrans:

```json
{
  "success": true,
  "message": "Order simulasi sudah dianggap dibayar.",
  "code": "PAYMENT_SIMULATED",
  "data": {
    "order_id": 1,
    "order_number": "WAL-CONTOH123456",
    "simulation": true,
    "payment_required": false,
    "payment_token": null,
    "payment_url": null
  }
}
```

`POST /orders/1/cancel` menghasilkan `422` untuk order simulasi `paid`. Endpoint ini hanya menerima `pending_payment`.

`POST /auth/logout` mencabut token yang digunakan dan menghasilkan `200`. Pemakaian token tersebut setelah logout menghasilkan `401`.

## Error dan penanganan frontend

| HTTP | Kondisi | Tindakan |
|---|---|---|
| `401` | Token tidak ada, kedaluwarsa, atau sudah dicabut | Login ulang melalui OTP |
| `403` | Akun tidak aktif atau token tidak memiliki ability `customer-api` | Hentikan checkout dan tampilkan pesan akses |
| `404` | Order/alamat tidak ditemukan atau bukan milik user | Ambil ulang daftar milik user |
| `409` | `STALE_CART`: barang atau komponen tidak lagi tersedia | Periksa dan hapus item tidak tersedia sebelum checkout ulang |
| `422` | OTP salah, input tidak valid, cart kosong, atau key idempotency tidak valid | Tampilkan `message` dan `errors` jika tersedia |
| `429` | Rate limit OTP atau katalog | Tunggu sesuai informasi retry dari respons |
| `503` | Stok/ongkir tidak tersedia ketika mode simulasi tidak aktif | Periksa flag simulasi dan environment server |

Contoh struktur validasi `422`; teks pesan bersifat ilustratif dan mengikuti bahasa aplikasi:

```json
{
  "success": false,
  "message": "Jumlah item wajib diisi.",
  "code": "VALIDATION_ERROR",
  "errors": {"quantity": ["Jumlah item wajib diisi."]}
}
```

Bentuk error belum seragam pada semua endpoint. Sebagian error hanya memiliki `success` dan `message`, misalnya header idempotency kosong. Jangan mewajibkan `code`, `data`, atau `errors` pada setiap respons gagal. Create order dengan cart kosong menghasilkan `422` dan `code=CART_EMPTY`.

Jika preview menghasilkan `simulation=false` atau order baru berstatus `pending_payment`, mode simulasi tidak aktif pada proses backend yang menerima request.

## Contoh cURL

```bash
API_BASE='http://127.0.0.1:8000/api/v1'

curl -sS -X POST "$API_BASE/auth/customer/request-otp" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"phone":"089990001102"}'
```

Verifikasi dengan challenge dan OTP terbaru:

```bash
curl -sS -X POST "$API_BASE/auth/customer/verify-otp" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"phone":"089990001102","challenge_id":15,"otp":"123456","device_name":"curl-simulation"}'

TOKEN='GANTI_DENGAN_TOKEN_RESPONS'
```

Sesudah menambahkan barang dan menyimpan alamat sesuai kontrak di atas, kirim preview dan create order. Ganti `address_id` dengan ID dari respons alamat:

```bash
curl -sS -X POST "$API_BASE/checkout/preview" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"address_id":5}'

curl -sS -X POST "$API_BASE/orders" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: checkout-simulasi-001' \
  -d '{"address_id":5}'

curl -sS "$API_BASE/orders?page=1" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json'
```

## Checklist testing

- Login baru membuat akun; login ulang memakai akun yang sama.
- Produk dan bundle dapat ditambahkan ke cart.
- Preview menghasilkan `simulation=true`, `checkout_available=true`, dan total sesuai subtotal ditambah ongkir bersih.
- Create order menghasilkan `201`, `status=paid`, dan `payment_required=false`.
- Cart kosong setelah order tersimpan.
- Retry berurutan memakai key yang sama mengembalikan ID order yang sama.
- Detail serta riwayat menampilkan order milik user.
- Payment simulasi menghasilkan token dan URL null.

## Beralih ke integrasi berikutnya

```dotenv
ORDER_SIMULATION_ENABLED=false
```

Setelah mengubah environment, clear/rebuild config cache dan restart proses aplikasi yang berjalan lama. Alur kembali membuat order `pending_payment` serta memerlukan integrasi stok dan ongkir yang aktif. Siapkan key Midtrans dan selesaikan temuan audit pembayaran sebelum menguji pembayaran sungguhan. Order simulasi lama tetap ditandai sebagai simulasi dan tidak dikonversi menjadi transaksi nyata.

`APP_ENV=production` atau `staging` selalu mengabaikan flag simulasi. Nonaktifkan listing toko simulasi jika memakai database lokal yang sama untuk menguji integrasi nyata.
