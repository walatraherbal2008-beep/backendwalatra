# Alur API Cart dan Checkout

Postman: `base_url` lokal harus `http://127.0.0.1:8000/api/v1`. Endpoint ditambahkan setelah nilai ini. Environment aktif dapat menimpa variable Collection; lihat [pengaturan Postman](postman-setup.md).

Panduan integrasi frontend untuk menambahkan produk dan bundle ke satu cart, memilih barang yang dibeli, meninjau harga terbaru, lalu membuat order. Diperbarui mengikuti implementasi dan audit HTTP pada 13 November 2026.

Alur operasional memakai Midtrans Core API dan gratis ongkir: order baru berstatus `pending_payment`, ongkir `0.00`, dan pembayaran diverifikasi backend melalui Midtrans. Lihat [API pembayaran Midtrans](midtrans-core-payments.md) untuk VA, QRIS, dan webhook. Stok sementara dianggap tidak membatasi checkout. Titik pengiriman dipilih dari penetapan gudang pada varian; detail pengaturannya tersedia di [manajemen gudang](../warehouse-management.md).

## 1. Persiapan

Base URL contoh: `https://api.example.com/api/v1`. Ganti domain sesuai server. Server lokal dapat menggunakan `http://127.0.0.1:8000/api/v1` jika aplikasi berjalan pada port 8000.

User sudah login melalui [API autentikasi customer](web-authentication-api.md). Endpoint cart, alamat, checkout, dan order memerlukan akun aktif serta token dengan ability `customer-api`.

```http
Authorization: Bearer <TOKEN_CUSTOMER>
Accept: application/json
Content-Type: application/json
```

Katalog dapat diakses tanpa token. Gunakan [API katalog produk](product-catalog.md) untuk memperoleh listing, varian, dan bundle yang tersedia.

Seluruh ID, nama, nomor pesanan, tanggal, dan nominal contoh bersifat ilustratif. Gunakan ID serta token yang benar-benar dikembalikan server. Harga dan total pada cart, preview, serta order berupa string desimal dalam rupiah, misalnya `"15000.00"`.

## 2. Urutan proses

```mermaid
flowchart TD
    A[Pilih produk atau bundle dari katalog] --> B[Tambahkan ke cart]
    B --> C[Lihat cart dan atur quantity]
    C --> D[Pilih item dan alamat]
    D --> E[Preview harga terbaru dan ongkir]
    E --> F[User meninjau dan menyetujui total]
    F --> G[Buat order dengan checkout_token dan Idempotency-Key]
    G --> H[Order pending_payment; item terpilih dihapus dari cart]
    G --> I[409: harga, quantity, pilihan berubah, atau token kedaluwarsa]
    I --> E
```

| Langkah | Request | Data yang disimpan frontend |
|---|---|---|
| 1 | `GET /catalog/products` atau `GET /catalog/bundles` | ID listing dan varian yang dipilih |
| 2 | `POST /cart/items` | Respons cart terbaru |
| 3 | `GET /cart` | `data.items[].id` untuk pilihan checkout |
| 4 | `PATCH /cart/items/{item}` jika quantity diubah | Respons cart terbaru |
| 5 | `GET /addresses` atau `POST /addresses` | `address_id` milik user |
| 6 | `POST /checkout/preview` | Item terpilih, harga terbaru, total, dan `checkout_token` |
| 7 | `POST /orders` setelah user setuju | ID order dan nomor pesanan |
| 8 | `GET /orders/{order}` | Status dan petunjuk pembayaran |
| 9 | `GET /cart` | Sisa item yang tidak dibeli |

## 3. Pahami ID yang digunakan

| Nama | Sumber | Digunakan untuk |
|---|---|---|
| `listing_id` | Listing produk di katalog | Tambah produk ke cart |
| `variant_id` | `variants[].id` pada katalog/detail produk | Memilih varian produk |
| `bundle_listing_id` | `listing_id` pada listing bundle di katalog | Tambah bundle ke cart |
| ID item cart | `data.items[].id` pada respons cart | Ubah/hapus item dan isi `cart_item_ids` |
| `address_id` | ID dari API alamat user | Alamat tujuan checkout |
| `checkout_token` | `data.checkout_token` dari preview | Mengonfirmasi isi pilihan serta harga yang ditinjau |
| ID order | `data.id` dari pembuatan order | Membaca detail pesanan |

ID master produk atau bundle bukan ID listing. ID item cart juga berbeda dari ID varian, listing, maupun item order.

## 4. Tambah barang ke cart

### Produk

`POST /cart/items`

```json
{
  "type": "product",
  "listing_id": 101,
  "variant_id": 201,
  "quantity": 2
}
```

`listing_id` dan `variant_id` wajib berupa integer positif. Varian harus aktif dan menjadi bagian dari produk pada listing. Listing, produk, serta toko harus memenuhi aturan kelayakan pembelian.

### Bundle

Endpoint sama: `POST /cart/items`.

```json
{
  "type": "bundle",
  "bundle_listing_id": 301,
  "quantity": 1
}
```

Bundle menggunakan harga tetap master bundle. Frontend tidak perlu mengirim ID varian komponen atau menghitung harga dari jumlah harga komponennya. Komponen bundle ditentukan backend.

### Aturan penambahan

- `quantity` wajib integer 1–99. Kirim `type` secara eksplisit agar jenis barang jelas.
- Untuk produk, kombinasi listing dan varian yang sama digabung pada baris cart yang sudah ada. Varian atau listing toko berbeda menjadi baris terpisah.
- Untuk bundle, listing bundle yang sama digabung pada baris cart yang sudah ada.
- `POST` menambahkan quantity, bukan menggantinya. Jumlah gabungan dibatasi 99; penambahan melampaui 99 tidak otomatis menghasilkan error jika quantity request masih valid.
- Harga diambil backend dari database. Frontend tidak menentukan `unit_price`, subtotal, ongkir, atau total.
- Respons sukses adalah **200**, berisi seluruh cart terbaru, bukan hanya item yang ditambahkan.
- Request tambah yang dikirim ulang dapat menambah quantity lagi. Perlindungan `Idempotency-Key` pada pembuatan order tidak berlaku untuk penambahan cart.

Pesan sukses: `Produk berhasil ditambahkan ke cart.` atau `Bundle berhasil ditambahkan ke cart.`

## 5. Lihat dan kelola cart

### Daftar cart gabungan

`GET /cart`

Produk dan bundle berada dalam satu `data.items`. Item yang terakhir ditambahkan berada di atas. Menambahkan ulang item melalui `POST` memperbarui waktu penambahannya; mengubah quantity melalui `PATCH` tidak mengubah urutan penambahan. Jika waktunya sama, ID item terbesar didahulukan. Item lama tanpa waktu penambahan khusus memakai waktu pertama dibuat.

Contoh respons dengan satu bundle dan satu produk:

```json
{
  "data": {
    "id": 1,
    "items": [
      {
        "id": 15,
        "type": "bundle",
        "image_url": null,
        "listing_id": null,
        "bundle_listing_id": 301,
        "variant_id": null,
        "product": null,
        "bundle": {
          "id": 30,
          "name": "Paket Herbal A",
          "slug": "paket-herbal-a",
          "items": [
            {
              "product": {"id": 10, "name": "Produk Herbal A", "slug": "produk-herbal-a", "product_code": "PRD-010"},
              "variant": {"id": 201, "name": "Botol 60 ml", "sku": "PRD-010-60", "image_url": null, "price": "15000.00", "weight_grams": 150},
              "quantity": 2
            }
          ]
        },
        "store": {"id": 1, "name": "Toko Utama", "slug": "toko-utama"},
        "variant": null,
        "quantity": 1,
        "unit_price": "40000.00",
        "subtotal": "40000.00"
      },
      {
        "id": 12,
        "type": "product",
        "image_url": "https://api.example.com/storage/123/varian.png",
        "listing_id": 101,
        "bundle_listing_id": null,
        "variant_id": 201,
        "product": {"id": 10, "name": "Produk Herbal A", "slug": "produk-herbal-a"},
        "bundle": null,
        "store": {"id": 1, "name": "Toko Utama", "slug": "toko-utama"},
        "variant": {"id": 201, "name": "Botol 60 ml", "sku": "PRD-010-60"},
        "quantity": 2,
        "unit_price": "15000.00",
        "subtotal": "30000.00"
      }
    ],
    "total_items": 3,
    "subtotal": "70000.00"
  },
  "success": true,
  "message": "Cart berhasil diambil."
}
```

`total_items` adalah jumlah quantity pembelian, bukan jumlah baris atau jumlah komponen fisik bundle. Pada contoh, quantity 2 produk + 1 bundle menghasilkan `total_items: 3`.

Cart belum menyediakan filter query per toko. Frontend dapat mengelompokkan `items` berdasarkan `store.id`, dengan produk dan bundle tetap tergabung dalam toko yang sama. Respons cart tidak memuat gambar; gambar dapat diambil dari data katalog.

Cart kosong tetap mengembalikan 200 dengan `items: []`, `total_items: 0`, dan `subtotal: "0.00"`.

### Ganti quantity

`PATCH /cart/items/12`

```json
{"quantity": 3}
```

Quantity menggantikan jumlah sebelumnya dan wajib 1–99. Gunakan `DELETE` untuk menghapus; quantity `0` tidak diterima. Respons 200 berisi seluruh cart terbaru dengan pesan `Jumlah item cart berhasil diperbarui.`

Item yang tidak ditemukan atau milik user lain menghasilkan 404.

### Hapus satu item

`DELETE /cart/items/12`, tanpa body.

```json
{
  "success": true,
  "message": "Item cart dihapus.",
  "code": "CART_ITEM_DELETED",
  "data": null
}
```

Respons 200. Penghapusan dibatasi ke cart user sendiri; ID yang tidak ada di cart tersebut tetap menghasilkan respons sukses tanpa menghapus item user lain.

### Kosongkan cart

`DELETE /cart`, tanpa body.

Respons 200, pesan `Cart dikosongkan.`, kode `CART_CLEARED`, dan `data: null`. Seluruh produk dan bundle dalam cart user dihapus. Untuk checkout sebagian barang, gunakan `cart_item_ids` agar barang lain tetap tersimpan.

## 6. Pilih barang dan alamat checkout

Pilihan checkbox di frontend dikirim sebagai `cart_item_ids`, misalnya `[12, 15]`. Produk dan bundle dapat dipilih bersama, termasuk dari toko berbeda.

| Parameter | Aturan |
|---|---|
| `cart_item_ids` | Opsional; jika ada, wajib array berurutan dengan minimal satu ID integer positif yang unik |
| `address_id` | Integer positif; harus menunjuk alamat milik user yang login |
| `shipping_address` | Alternatif alamat langsung jika tidak memakai `address_id` |

Jika `cart_item_ids` tidak dikirim, backend memproses seluruh cart. Array kosong, `null`, ID duplikat, serta ID hilang atau milik user lain ditolak dengan 422. Jangan menghilangkan parameter saat pilihan checkbox kosong; hentikan checkout sampai user memilih barang.

Pilihan berlaku untuk seluruh quantity pada baris cart. Untuk membeli lebih sedikit dari quantity saat ini, ubah quantity terlebih dahulu melalui `PATCH /cart/items/{item}`.

Untuk memilih alamat tersimpan, gunakan `GET /addresses`. Jika perlu alamat baru, contoh `POST /addresses`:

```json
{
  "label": "Rumah",
  "name": "Pembeli Walatra",
  "phone": "628123456789",
  "address": "Jalan Contoh Nomor 1",
  "province": "Jawa Barat",
  "city": "Bandung",
  "postal_code": "40135",
  "is_default": true
}
```

Simpan `data.id` dari respons sebagai `address_id`. Detail field, edit/hapus alamat, pengaturan alamat utama, dan lokasi tambahan tersedia di [API alamat customer dan lokasi Everpro](customer-addresses.md).

## 7. Preview checkout dan harga terbaru

`POST /checkout/preview`

```json
{
  "address_id": 5,
  "cart_item_ids": [12, 15]
}
```

Contoh respons 200 jika harga belum berubah:

```json
{
  "success": true,
  "message": "Order dapat diajukan. Stok belum diverifikasi.",
  "data": {
    "checkout_token": "<TOKEN_PREVIEW_DARI_SERVER>",
    "price_changed": false,
    "price_changes": [],
    "selection": {
      "cart_item_ids": [
        12,
        15
      ],
      "line_count": 2,
      "total_items": 3,
      "subtotal": "70000.00",
      "unavailable_item_ids": []
    },
    "simulation": false,
    "payment_required": true,
    "payment_provider": "midtrans",
    "stock_mode": "unlimited",
    "stock_verification": "pending_manual",
    "shipping_origin": {
      "warehouse_code": "<KODE_GUDANG_DARI_DIREKTORI>",
      "warehouse_name": "Tasikmalaya",
      "address": "Alamat gudang dari API direktori",
      "postal_code": null,
      "coordinates": null
    },
    "checkout_available": true,
    "items": [
      {"cart_item_id": 12, "type": "product", "image_url": "https://api.example.com/storage/123/varian.png", "sku": "PRD-010-60", "quantity": 2, "unit_price": "15000.00", "subtotal": "30000.00"},
      {"cart_item_id": 15, "type": "bundle", "image_url": null, "sku": null, "quantity": 1, "unit_price": "40000.00", "subtotal": "40000.00"}
    ],
    "subtotal": "70000.00",
    "shipping": {
      "service": "FREE",
      "eta_days": null,
      "gross_fee": "0.00",
      "subsidy": "0.00",
      "fee": "0.00",
      "subsidy_rule_id": null
    },
    "total": "70000.00"
  }
}
```

### Arti field preview

| Field | Makna |
|---|---|
| `items` | Hanya item yang dipilih; kaitkan `cart_item_id` dengan `items[].id` dari cart untuk nama barang, varian, dan toko |
| `price_changed` | Harga terbaru berbeda dari harga yang tersimpan pada cart |
| `price_changes` | Rincian harga tersimpan dan harga terbaru untuk item terpilih |
| `checkout_token` | Konfirmasi pilihan, quantity, harga, gudang, snapshot alamat, ongkir, subtotal, dan total; terikat akun dan berlaku 15 menit |
| `checkout_available` | Checkout dapat diajukan; pada mode manual bukan jaminan stok fisik sudah diperiksa |
| `shipping_origin` | Titik pengiriman bersama untuk seluruh varian terpilih, termasuk komponen bundle |
| `stock_mode` | `unlimited`: kuantitas stok tidak membatasi checkout; `external`: mengikuti pemeriksaan stok eksternal |
| `stock_verification` | `pending_manual` berarti stok masih perlu diperiksa admin |
| `shipping.fee` | Ongkir bersih; `0.00` selama gratis ongkir aktif |
| `total` | Subtotal item terpilih + ongkir |

Jangan mengandalkan urutan array preview sama dengan cart. Gunakan ID untuk mencocokkan item.

Preview membaca harga master terbaru, sehingga subtotalnya dapat berbeda dari subtotal cart. Harga produk memakai harga varian; harga bundle memakai harga tetap bundle. Preview tidak mengubah harga tersimpan, quantity, maupun isi cart dan tidak membuat order. Perubahan harga barang yang tidak dipilih tidak memengaruhi checkout ini.

Preview boleh tanpa alamat dengan body `{"cart_item_ids": [12, 15]}`. Dalam kondisi tersebut, subtotal serta pemeriksaan item tetap tersedia, tetapi `shipping`, `total`, dan `checkout_token` adalah `null`. Panggil preview lagi dengan alamat tujuan sebelum user mengonfirmasi pesanan. Token dari preview beralamat mengikat snapshot alamat, ongkir, subtotal, dan total. Jika mengirim `shipping_address`, objek tidak boleh kosong dan field wajibnya harus lengkap.

### Jika harga berubah

Sebagai contoh terpisah, hanya produk cart 12 dipilih, quantity 2, dan harga berubah dari Rp15.000 menjadi Rp20.000. Preview tetap mengembalikan 200. Cuplikan `data`:

```json
{
  "checkout_token": "<TOKEN_PREVIEW_TERBARU>",
  "price_changed": true,
  "price_changes": [
    {
      "cart_item_id": 12,
      "type": "product",
      "previous_unit_price": "15000.00",
      "current_unit_price": "20000.00"
    }
  ],
  "subtotal": "40000.00",
  "total": "40000.00"
}
```

Frontend menampilkan harga dan total terbaru untuk ditinjau user. Setelah user setuju, kirim `checkout_token` dari preview tersebut pada create order. Jangan langsung membuat order secara otomatis hanya karena `checkout_available` bernilai `true`.

Token tidak membekukan harga di database atau mereservasi stok. Jika harga, quantity, pilihan, atau gudang asal berubah sesudah preview, backend meminta preview baru saat pembuatan order.

### Mengapa frontend perlu checkout_token?

Token adalah bukti preview yang diterbitkan backend untuk akun dan isi checkout tertentu. Order baru wajib menggunakannya agar backend dapat menolak pilihan, quantity, harga, atau kode gudang yang sudah berbeda dari preview. Contohnya, harga Rp93.000 berubah setelah preview: order ditolak dengan `CHECKOUT_CHANGED`, lalu frontend perlu menampilkan preview baru dan meminta user mengonfirmasi kembali.

Frontend menyimpan token sementara setelah preview, menampilkan ringkasannya, lalu mengirim token asli ketika user menekan konfirmasi order. User tidak perlu melihat atau mengisi token. Jangan menganggap token sebagai bukti user sudah menyetujui harga; konfirmasi tersebut tetap ditangani frontend.

- Buang token ketika pilihan, quantity, atau alamat berubah, lalu ambil preview baru. Pastikan respons preview lama yang terlambat tidak menggantikan hasil terbaru.
- Token berlaku 15 menit. `CHECKOUT_EXPIRED` atau `CHECKOUT_CHANGED` memerlukan preview baru dan konfirmasi ulang. Token tidak ada, rusak, atau milik akun lain menghasilkan 422 pada field `checkout_token`.
- Token mengikat snapshot alamat tujuan dan rincian ongkir; perubahan alamat atau nilai ongkir sejak preview menghasilkan `409 CHECKOUT_CHANGED`. Token belum mengikat komposisi komponen bundle, tidak mengunci harga di database, dan tidak mereservasi stok.
- Bearer token digunakan untuk login; `checkout_token` untuk memeriksa isi checkout; `Idempotency-Key` untuk mencegah order ganda saat retry. Jika request order timeout, pertahankan key dan payload yang sama.

Rincian penanganan tersedia pada [fungsi checkout_token untuk frontend](checkout-orders.md#fungsi-checkout_token-untuk-frontend).

## 8. Konfirmasi dan buat order

`POST /orders`

Tambahkan header selain header autentikasi:

```http
Idempotency-Key: checkout-20261104-001
```

Untuk melanjutkan contoh utama dua item dengan total Rp70.000:

```json
{
  "address_id": 5,
  "cart_item_ids": [12, 15],
  "checkout_token": "<TOKEN_PREVIEW_DARI_SERVER>"
}
```

Kirim pilihan serta alamat yang sudah ditinjau user. `checkout_token` wajib menggunakan nilai asli dari respons preview, bukan teks placeholder di atas. Token wajib untuk order baru, berupa string tidak kosong maksimal 4.096 karakter. `Idempotency-Key` wajib tidak kosong dan maksimal 100 karakter.

Jika memakai alamat langsung, contoh body alternatif:

```json
{
  "cart_item_ids": [12, 15],
  "checkout_token": "<TOKEN_PREVIEW_DARI_SERVER>",
  "shipping_address": {
    "name": "Pembeli Walatra",
    "phone": "628123456789",
    "address": "Jalan Contoh Nomor 1",
    "province": "Jawa Barat",
    "city": "Bandung",
    "postal_code": "40135"
  }
}
```

Alamat wajib saat create order: gunakan `address_id` atau objek `shipping_address` yang lengkap. Jika keduanya dikirim, backend memakai alamat tersimpan dari `address_id`.

### Respons order berhasil

Respons pertama **201**. Berikut cuplikan; respons lengkap juga menyertakan alamat serta snapshot `items` produk/bundle:

```json
{
  "data": {
    "id": 90,
    "order_number": "WAL-CONTOH123456",
    "status": "pending_payment",
    "payment_provider": "midtrans",
    "simulation": false,
    "payment_required": true,
    "payment_instructions": null,
    "paid_at": null,
    "currency": "IDR",
    "subtotal": "70000.00",
    "shipping_gross_fee": "0.00",
    "shipping_subsidy": "0.00",
    "shipping_fee": "0.00",
    "shipping_service": "FREE",
    "shipping_origin": {
      "warehouse_code": "<KODE_GUDANG_DARI_DIREKTORI>",
      "warehouse_name": "Tasikmalaya",
      "address": "Alamat gudang dari API direktori",
      "postal_code": null,
      "coordinates": null
    },
    "stock_mode": "unlimited",
    "total": "70000.00",
    "created_at": "2026-11-04T08:00:00+07:00"
  },
  "success": true,
  "message": "Order berhasil dibuat."
}
```

Petunjuk pembayaran mengikuti konfigurasi server. Backend memvalidasi ulang barang, token, dan harga, menyimpan snapshot order, lalu menghapus hanya baris cart yang dibeli dalam transaksi. Item yang tidak dipilih tetap berada di cart. Cart tidak dihapus saat preview atau request order gagal.

Order yang sudah dibuat mempertahankan snapshot harga dan jumlah, meskipun data master kemudian berubah. Pembayaran tidak otomatis dianggap lunas; status menjadi `paid` setelah superadmin mengonfirmasi.

### Retry dan request bersamaan

- Buat satu `Idempotency-Key` untuk satu pengajuan order. Simpan key dan payload sampai hasilnya diketahui.
- Jika koneksi timeout atau respons hilang, ulangi request dengan **key dan payload yang sama**. Jangan langsung membuat key baru karena order pertama mungkin sudah tersimpan.
- Jika order sebelumnya sudah berhasil, respons ulang adalah 200 dengan ID order yang sama dan pesan `Order sebelumnya berhasil diambil.` Tidak ada order atau riwayat baru yang dibuat, walaupun token sekarang kedaluwarsa atau item sudah dihapus dari cart.
- Backend mengembalikan order lama untuk key yang sama, bukan menggantinya dengan pilihan baru. Gunakan key baru untuk pembelian berbeda atau pengajuan baru yang telah ditinjau ulang.
- Tambah produk/bundle, ubah quantity, hapus item, kosongkan cart, dan create order diproses bergantian per user. Snapshot order konsisten dengan item yang dihapus. Item yang baru ditambahkan sesudah checkout selesai tetap berada di cart; `PATCH` ke ID item yang sudah dibeli menghasilkan 404.

`checkout_token` wajib untuk setiap order baru pada semua mode pembayaran. Request tanpa token menghasilkan 422 pada field `checkout_token`. Retry key order yang sudah berhasil tetap mengembalikan order lama walaupun token kedaluwarsa atau tidak disertakan; key tidak boleh dipakai untuk pembelian baru.

## 9. Tangani respons gagal

Tidak semua endpoint memakai envelope error yang sama. Gunakan HTTP status sebagai dasar penanganan dan periksa `code` jika tersedia. Khusus preview, respons cart kosong dapat hanya berisi `success` serta `message`; barang tidak tersedia menyertakan `code: STALE_CART`.

| HTTP / kode | Penyebab | Tindakan frontend |
|---|---|---|
| 401 | Token login hilang atau tidak valid | Minta user login kembali |
| 403 | Akun tidak aktif atau ability token tidak sesuai | Tampilkan pesan akses yang dikembalikan server |
| 404 | Item cart, alamat, atau order tidak ditemukan/tidak dimiliki user | Muat ulang data terkait |
| 422 `VALIDATION_ERROR` | Quantity, pilihan item, alamat, atau format token tidak valid | Tampilkan `errors` pada field terkait |
| 422 pada cart kosong | Tidak ada item untuk checkout | Arahkan ke cart/katalog |
| 409 `STALE_CART` pada order | Item terpilih tidak aktif/tidak tersedia | Muat ulang cart dan perbarui pilihan |
| 422 `VALIDATION_ERROR` pada `checkout_token` | Order baru tanpa token preview atau token tidak valid | Panggil preview dan kirim token asli dari respons |
| 409 `CHECKOUT_CHANGED` | Pilihan, quantity, harga, gudang, alamat, atau rincian ongkir berbeda dari preview bertoken | Buat preview baru dan minta persetujuan user |
| 409 `WAREHOUSE_NOT_ASSIGNED` | Varian produk atau komponen bundle belum ditetapkan ke gudang | Tampilkan pesan server; admin perlu mengatur varian |
| 409 `WAREHOUSE_MISMATCH` | Tidak ada satu gudang bersama untuk seluruh barang terpilih | Ubah pilihan barang atau perbaiki penetapan gudang |
| 409 `WAREHOUSE_SELECTION_REQUIRED` | Ada beberapa gudang bersama tanpa pilihan prioritas server | Admin menentukan satu gudang atau mengisi konfigurasi prioritas |
| 409 `WAREHOUSE_NOT_AVAILABLE` | Gudang prioritas tidak dimiliki semua varian atau kode gudang tidak ditemukan | Periksa penetapan serta direktori gudang |
| 409 `CHECKOUT_EXPIRED` | Token preview sudah melewati 15 menit | Buat preview baru |
| 503 pada integrasi eksternal | Layanan stok atau layanan terkait tidak tersedia | Tampilkan pesan layanan; jangan menyatakan order berhasil |

ID item hilang atau milik user lain dalam `cart_item_ids` menghasilkan 422. Mengakses ID item tersebut melalui endpoint `PATCH` menghasilkan 404. Item yang tidak dipilih tidak ikut divalidasi kelayakannya untuk checkout sebagian.

Contoh konflik saat harga berubah setelah preview:

```json
{
  "success": false,
  "message": "Pilihan barang, jumlah, atau harga berubah. Silakan tinjau ulang preview checkout.",
  "code": "CHECKOUT_CHANGED",
  "errors": null,
  "data": {
    "price_changes": [
      {
        "cart_item_id": 12,
        "type": "product",
        "image_url": "https://api.example.com/storage/123/varian.png",
        "previous_unit_price": "15000.00",
        "current_unit_price": "20000.00"
      }
    ]
  }
}
```

`price_changes` membandingkan harga master dengan harga tersimpan pada cart, bukan dengan harga preview sebelumnya. Array ini dapat kosong pada `CHECKOUT_CHANGED` jika perubahannya hanya quantity atau pilihan item. Respons konflik tidak otomatis memperbarui harga cart.

Token rusak atau milik akun lain menghasilkan 422 pada `checkout_token`; jangan mencoba memperbaiki atau membuat token di frontend. Ambil token baru dari preview akun yang sedang login.

## 10. Sesudah order dibuat

1. Simpan `data.id` dan `data.order_number`, lalu tampilkan detail pesanan melalui `GET /orders/{order}`.
2. Ambil `GET /payment-methods`, lalu kirim `POST /orders/{order}/payment` dengan `method` dan `Idempotency-Key` untuk memperoleh instruksi VA atau QRIS. Lihat [API pembayaran Midtrans](midtrans-core-payments.md).
3. Muat ulang `GET /cart` agar tampilan sesuai sisa barang yang belum dibeli.
4. Baca status order kembali setelah pembayaran; webhook dan endpoint refresh memverifikasi status melalui Midtrans. `paid` berarti pembayaran sudah dikonfirmasi; bukan otomatis status pengiriman.

Daftar pesanan tersedia melalui `GET /orders`. User dapat membatalkan order yang masih `pending_payment` melalui `POST /orders/{order}/cancel`; order yang sudah dibayar tidak dapat dibatalkan melalui endpoint customer tersebut. Pembatalan tidak otomatis mengembalikan item ke cart. Lihat [API pembayaran Midtrans](midtrans-core-payments.md). Order manual lama tetap menggunakan [konfirmasi admin melalui menu Pesanan](../store-transactions.md).

## 11. Ringkasan implementasi frontend

- Gunakan satu daftar campuran produk dan bundle, lalu bedakan tampilannya melalui `type`.
- Simpan pilihan berdasarkan ID item cart, bukan indeks array atau ID master produk.
- Setelah quantity, pilihan, atau alamat tujuan diubah, panggil preview lagi sebelum user mengonfirmasi.
- Gunakan `data.items`, subtotal, ongkir, dan total dari preview untuk halaman konfirmasi; harga cart dapat belum mengikuti harga master terbaru.
- Simpan token dari preview terbaru dan kirim hanya setelah user menyetujui harga yang ditampilkan.
- Untuk request order yang sedang berjalan, cegah pengiriman berulang dari tombol frontend dan pertahankan `Idempotency-Key` untuk retry.
- Tanggapi konflik dengan preview baru. Jangan menghapus isi cart atau menandai pembayaran lunas dari frontend.
- Pada mode manual, ketersediaan stok fisik tetap diperiksa admin. Gratis ongkir tidak mengubah kewajiban verifikasi pembayaran.

## Foto pada cart, preview, dan pesanan

Setiap item pada cart dan checkout preview menyertakan `image_url`, dengan prioritas foto varian → foto etalase toko → foto produk utama. Item bundle memakai foto etalase bundle, lalu foto produk utama komponen pertama. Jika tidak ada gambar, nilainya `null`.

Item pesanan juga menyertakan `image_url`, tetapi URL tersebut menunjuk salinan berkas yang dibuat saat checkout. URL cart/preview dan URL order dapat berbeda meskipun gambarnya sama. Foto riwayat pesanan tidak mengikuti penggantian atau penghapusan foto sumber.

```json
{
  "id": 501,
  "type": "product",
  "sku": "PRD-010-60",
  "product_name": "Produk Herbal A",
  "variant_name": "Botol 60 ml",
  "image_url": "https://api.example.com/storage/order-item-images/550e8400-e29b-41d4-a716-446655440000/varian.png"
}
```

Pesanan lama dan item tanpa foto mengembalikan `image_url: null`. Jika sumber gambar ada tetapi gagal dibaca atau disalin, pembuatan order gagal; item cart dipertahankan dan salinan baru dibersihkan. Ulangi request dengan `Idempotency-Key` yang sama: order yang sudah berhasil dibuat dikembalikan tanpa membuat salinan tambahan.

## Stok sementara dan titik pengiriman

Frontend tetap mengirim `cart_item_ids`, alamat, dan token preview. Pemilihan gudang dilakukan backend; frontend tidak mengirim `warehouse_code` atau menentukan stok tersedia.

Semua varian produk dan seluruh komponen bundle terpilih wajib memiliki penetapan gudang. Backend memilih satu gudang yang sama untuk seluruh checkout. Barang yang tidak dipilih di cart tidak ikut diperiksa. Pada tahap ini belum ada pemilihan berdasarkan jarak, panggilan Google Maps, atau pembagian satu order menjadi beberapa pengiriman.

Jika semua varian ditetapkan hanya ke Tasikmalaya melalui superadmin, gudang tersebut dipilih otomatis. Tidak ada kode/nama Tasikmalaya yang ditanam permanen pada logika checkout. Jika terdapat beberapa gudang bersama, server memerlukan `CHECKOUT_WAREHOUSE_CODE`; gudang tersebut tetap wajib dimiliki oleh semua varian terpilih.

`shipping_origin` pada preview dan order berisi `warehouse_code`, `warehouse_name`, `address`, `postal_code`, dan `coordinates`. Alamat, kode pos, atau koordinat yang belum diisi sumber bernilai `null`. Detail diambil dari direktori gudang, sehingga kegagalan API daftar/detail menghasilkan HTTP 503 dan order tidak dibuat.

Origin disalin ke order saat checkout. Detail/list order dan retry idempotent membaca snapshot tersebut, tanpa mengambil ulang direktori atau mengikuti perubahan penetapan gudang. Order lama memiliki `shipping_origin: null` dan `stock_mode: null`.

Token preview mencakup kode gudang asal. Perubahan kode setelah preview menghasilkan `409 CHECKOUT_CHANGED`; panggil preview kembali. Token lama sebelum fitur ini ditambahkan perlu dibuat ulang.

### Konfigurasi server

```dotenv
ORDER_PROCESSING_MODE=integrated
CHECKOUT_STOCK_MODE=unlimited
CHECKOUT_WAREHOUSE_CODE=
FREE_SHIPPING_ENABLED=true
```

- `CHECKOUT_STOCK_MODE=unlimited`: tidak memeriksa jumlah stok, tidak membuat reservasi, dan tidak mengurangi stok fisik. API preview mengirim `stock_verification: pending_manual`. Kuantitas cart, status barang, kelayakan toko, dan penetapan gudang tetap divalidasi.
- `CHECKOUT_WAREHOUSE_CODE`: opsional jika hanya ada satu gudang bersama. Isi kode asli dari direktori untuk menentukan prioritas ketika beberapa gudang tersedia; bukan nama atau kode contoh dari dokumentasi.
- `ORDER_PROCESSING_MODE=integrated`: pembayaran tetap menunggu konfirmasi superadmin. Mode pembayaran ini terpisah dari mode stok.
- `CHECKOUT_STOCK_MODE=external`: memakai kontrak pemeriksaan/reservasi stok yang tersedia. Kontrak saat ini mengirim SKU dan quantity; integrasi alokasi stok per kode gudang masih perlu dilengkapi saat API stok gudang siap.

Saat `external` digunakan bersama pembayaran manual, konfirmasi lunas admin mengonfirmasi reservasi yang tercatat; pembatalan melepasnya. Pada `unlimited`, reservasi tidak dibuat sehingga tidak ada panggilan tersebut.

## Ringkasan pilihan dan barang tidak tersedia

`GET /cart?cart_item_ids[]=12&cart_item_ids[]=15` tetap mengembalikan seluruh item, dengan ringkasan tambahan `selection`: `cart_item_ids`, `line_count`, `total_items`, `subtotal`, dan `unavailable_item_ids`. Tanpa pilihan, ringkasan GET kosong/nol. Total lama di tingkat cart tetap mencakup seluruh isi cart. Pilihan tidak disimpan; pengelompokan per toko memakai `items[].store`.

Item cart memiliki `is_available` dan `availability_message`. Barang yang dihapus/nonaktif tetap dapat dilihat dan dihapus, tetapi tidak dapat diubah quantity-nya atau dibeli. Identitas/gambar yang tidak ditemukan bernilai `null`. Ringkasan pilihan hanya menghitung barang tersedia; jika `unavailable_item_ids` tidak kosong, perbaiki pilihan sebelum preview.

Preview menyediakan `selection` dengan struktur yang sama, memakai harga terbaru dari master. Pilihan preview yang dihilangkan tetap berarti seluruh cart, berbeda dengan ringkasan GET tanpa pilihan yang kosong. Quantity bundle adalah jumlah paket, bukan jumlah komponen.

Endpoint tambah menjumlahkan quantity hingga maksimal 99; PATCH mengganti quantity. Gunakan quantity dari respons, cegah klik ganda selama request berjalan, dan muat ulang GET dengan pilihan saat ini setelah mutasi. Tidak ada idempotency baru pada tambah cart.

Koleksi siap impor: [Postman cart dan checkout](../postman/cart-checkout.postman_collection.json).
