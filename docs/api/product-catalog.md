# API Katalog Produk

Referensi endpoint publik untuk menampilkan produk, bundle, kategori, dan penawaran toko kepada user. Kontrak ini mengikuti implementasi backend pada 3 November 2026.

Tidak ada pemisahan berdasarkan nama, kode, atau label simulasi. Data mengikuti aturan visibilitas katalog: produk/varian dan listing aktif, toko memenuhi syarat marketplace, serta komponen bundle masih valid. Data nonaktif atau yang sudah dihapus tidak otomatis menjadi publik.

## Base URL dan akses

Base URL contoh: `https://api.example.com/api/v1`. Ganti domain dengan server yang digunakan. Untuk server lokal yang berjalan di port 8000, gunakan `http://127.0.0.1:8000/api/v1`.

Semua endpoint katalog di dokumen ini menggunakan metode `GET`, tanpa login atau Bearer token. Kirim header:

```http
Accept: application/json
```

Contoh nama, ID, harga, dan URL dalam dokumen bersifat ilustratif; gunakan nilai dari respons server untuk request berikutnya. Harga dalam rupiah, tanpa pemisah ribuan. Harga model dikirim sebagai string desimal; hasil agregasi rentang harga dapat berupa string atau angka sesuai driver database. `weight_grams` menggunakan gram. Field tanggal dapat bernilai `null`.

## Daftar endpoint

| Method | Path relatif terhadap base URL | Fungsi                                                          |
| ------ | ------------------------------ | --------------------------------------------------------------- |
| GET    | `/catalog`                     | Katalog gabungan produk dan bundle, satu baris per listing toko |
| GET    | `/catalog/products`            | Listing produk beserta varian dan harga                         |
| GET    | `/catalog/products/{slug}`     | Detail master produk dan penawaran toko                         |
| GET    | `/catalog/bundles`             | Listing bundle beserta komponen                                 |
| GET    | `/catalog/bundles/{slug}`      | Detail master bundle dan penawaran toko                         |
| GET    | `/catalog/categories`          | Kategori utama beserta anak langsung                            |
| GET    | `/catalog/categories/{slug}`   | Detail kategori, parent, dan anak langsung                      |

## Identitas produk dan listing

| Field                           | Arti dan penggunaan                                          |
| ------------------------------- | ------------------------------------------------------------ |
| `id` pada item katalog gabungan | ID master produk atau bundle, sesuai `type`                  |
| `listing_id`                    | ID penawaran toko; digunakan untuk memilih toko saat membeli |
| `slug`                          | Slug master produk/bundle untuk endpoint detail              |
| `variants[].id`                 | ID varian produk yang dipilih saat masuk cart                |
| `product_code` / `code`         | Kode master, bukan ID listing atau ID varian                 |
| `sku`                           | Kode varian, bukan pengganti `variant_id`                    |
| `store.slug`                    | Nilai filter `store`                                         |
| `categories[].slug`             | Nilai filter `category`                                      |

Satu produk dapat ditawarkan beberapa toko sehingga muncul beberapa kali dengan `listing_id` berbeda. Gunakan gabungan `type` dan `listing_id` sebagai key item pada katalog gabungan; ID listing produk dan bundle dapat memiliki angka yang sama.

## 1. Katalog gabungan

`GET /catalog`

Endpoint ini cocok untuk halaman katalog yang menampilkan produk dan bundle dalam satu daftar.

### Query parameter

Semua parameter opsional.

| Parameter   | Nilai / batas                                                             | Default                                               |
| ----------- | ------------------------------------------------------------------------- | ----------------------------------------------------- |
| `q`         | Teks pencarian, maksimal 100 karakter                                     | Tanpa pencarian                                       |
| `type`      | `product` atau `bundle`                                                   | Kedua tipe                                            |
| `store`     | Slug toko, maksimal 255 karakter                                          | Semua toko yang memenuhi syarat                       |
| `category`  | Slug kategori, maksimal 255 karakter                                      | Semua kategori                                        |
| `min_price` | Angka >= 0                                                                | Tanpa batas bawah                                     |
| `max_price` | Angka >= 0; >= `min_price` jika keduanya diisi                            | Tanpa batas atas                                      |
| `sort`      | `relevance`, `latest`, `name_asc`, `name_desc`, `price_asc`, `price_desc` | `relevance` jika `q` berisi teks; selain itu `latest` |
| `per_page`  | Integer 1–50                                                              | 15                                                    |
| `page`      | Integer >= 1                                                              | 1                                                     |

```bash
CATALOG_BASE_URL='https://api.example.com/api/v1'
curl --get "$CATALOG_BASE_URL/catalog" \
  -H 'Accept: application/json' \
  --data-urlencode 'q=herbal' \
  --data-urlencode 'category=suplemen' \
  --data-urlencode 'min_price=10000' \
  --data-urlencode 'max_price=100000' \
  --data-urlencode 'sort=price_asc' \
  --data-urlencode 'per_page=15' \
  --data-urlencode 'page=1'
```

### Pencarian, filter, dan urutan

- Pencarian memisahkan teks menjadi maksimal 10 kata. Semua kata harus ditemukan, tetapi dapat tersebar di field berbeda dan tidak harus berurutan.
- Pencarian gabungan mencakup nama/kode item, nama toko, deskripsi listing, SKU/nama varian, kategori aktif, serta produk komponen bundle.
- `category` mencocokkan kategori yang terpasang langsung pada produk. Untuk bundle, pencocokan melalui kategori produk komponennya. Filter kategori tidak otomatis menyertakan seluruh kategori turunan.
- Untuk produk, setidaknya satu varian aktif harus memenuhi kedua batas harga sekaligus. Rentang `price.min`–`price.max` pada respons tetap merangkum semua varian aktif produk, sehingga dapat melewati batas filter.
- Untuk bundle, filter harga memakai harga tetap bundle. `max_price` juga dapat digunakan sendiri.
- `price_asc` / `price_desc` memakai harga minimum varian untuk produk dan harga tetap untuk bundle. `name_asc` / `name_desc` memakai nama item.
- `matched_in` hanya melaporkan `name`, `code`, dan/atau `store` ketika seluruh kata pencarian cocok pada field tersebut. Nilai `[]` tetap dapat berarti hasil cocok melalui deskripsi, varian, kategori, komponen, atau beberapa field sekaligus.

### Contoh respons 200

Cuplikan respons berikut memperlihatkan kedua tipe. Respons pagination sebenarnya juga menyertakan field `meta.links`.

```json
{
    "data": [
        {
            "type": "product",
            "listing_id": 101,
            "id": 10,
            "name": "Produk Herbal A",
            "slug": "produk-herbal-a",
            "code": "PRD-010",
            "price": { "min": "25000.00", "max": "45000.00" },
            "image_url": null,
            "image": {
                "url": null,
                "thumb": null,
                "preview": null,
                "full": null
            },
            "store": {
                "id": 1,
                "name": "Toko Utama",
                "slug": "toko-utama",
                "city": "Bandung",
                "province": "Jawa Barat"
            },
            "published_at": "2026-11-03T01:00:00+00:00",
            "matched_in": []
        },
        {
            "type": "bundle",
            "listing_id": 301,
            "id": 30,
            "name": "Paket Herbal A",
            "slug": "paket-herbal-a",
            "code": "BND-030",
            "price": { "value": "40000.00" },
            "image_url": null,
            "image": {
                "url": null,
                "thumb": null,
                "preview": null,
                "full": null
            },
            "store": {
                "id": 1,
                "name": "Toko Utama",
                "slug": "toko-utama",
                "city": "Bandung",
                "province": "Jawa Barat"
            },
            "published_at": "2026-11-03T01:00:00+00:00",
            "matched_in": []
        }
    ],
    "links": {
        "first": "https://api.example.com/api/v1/catalog?page=1",
        "last": "https://api.example.com/api/v1/catalog?page=1",
        "prev": null,
        "next": null
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "path": "https://api.example.com/api/v1/catalog",
        "per_page": 15,
        "to": 2,
        "total": 2
    },
    "success": true,
    "message": "Katalog gabungan berhasil diambil."
}
```

Endpoint daftar produk dan bundle menggunakan envelope pagination yang sama. `meta.total` menghitung listing yang cocok, bukan jumlah unit barang. Saat hasil kosong, `data` adalah `[]` dan `meta.from` / `meta.to` bernilai `null`. Filter yang tidak cocok, termasuk slug toko/kategori yang tidak ditemukan, menghasilkan daftar kosong dengan status 200.

## 2. Daftar produk

`GET /catalog/products`

Mendukung parameter `q`, `store`, `category`, `min_price`, `max_price`, `sort`, `per_page`, dan `page` dengan batas seperti tabel katalog gabungan. Parameter `type` tidak digunakan. Default `sort` adalah **`relevance`**, termasuk ketika tidak ada pencarian. Implementasi relevance di PostgreSQL memperhitungkan pencarian, kualitas listing, dan kebaruan; pada driver lain dapat menggunakan urutan terbaru sebagai fallback. Kirim `sort` secara eksplisit jika membutuhkan urutan tertentu.

```bash
curl --get "$CATALOG_BASE_URL/catalog/products" \
  -H 'Accept: application/json' \
  --data-urlencode 'store=toko-utama' \
  --data-urlencode 'category=suplemen' \
  --data-urlencode 'max_price=50000' \
  --data-urlencode 'sort=latest'
```

Contoh satu elemen `data[]` (envelope pagination tidak ditampilkan):

```json
{
    "listing_id": 101,
    "product": {
        "id": 10,
        "name": "Produk Herbal A",
        "slug": "produk-herbal-a",
        "product_code": "PRD-010",
        "bpom_number": null,
        "categories": [{ "id": 5, "name": "Suplemen", "slug": "suplemen" }]
    },
    "store": {
        "id": 1,
        "name": "Toko Utama",
        "slug": "toko-utama",
        "code": "TOKO-001",
        "city": "Bandung",
        "province": "Jawa Barat"
    },
    "description": "Deskripsi penawaran toko.",
    "status": "active",
    "sort_order": 0,
    "images": [],
    "variants": [
        {
            "id": 201,
            "name": "Botol 60 ml",
            "sku": "PRD-010-60",
            "price": "25000.00",
            "weight_grams": 150,
            "attributes": { "volume": "60 ml" }
        },
        {
            "id": 202,
            "name": "Botol 120 ml",
            "sku": "PRD-010-120",
            "price": "45000.00",
            "weight_grams": 250,
            "attributes": { "volume": "120 ml" }
        }
    ],
    "published_at": "2026-11-03T01:00:00+00:00"
}
```

`variants` hanya memuat varian aktif. `attributes` berisi atribut varian sesuai data tersimpan dan dapat bernilai `null`. Pesan sukses endpoint: `Katalog produk berhasil diambil.`

## 3. Detail produk

`GET /catalog/products/{slug}`

Gunakan `product.slug` dari daftar produk atau `slug` item bertipe `product` pada katalog gabungan.

```bash
curl "$CATALOG_BASE_URL/catalog/products/produk-herbal-a" \
  -H 'Accept: application/json'
```

```json
{
    "data": {
        "id": 10,
        "name": "Produk Herbal A",
        "slug": "produk-herbal-a",
        "product_code": "PRD-010",
        "bpom_number": null,
        "categories": [{ "id": 5, "name": "Suplemen", "slug": "suplemen" }],
        "variants": [
            {
                "id": 201,
                "name": "Botol 60 ml",
                "sku": "PRD-010-60",
                "price": "25000.00",
                "weight_grams": 150,
                "attributes": { "volume": "60 ml" }
            },
            {
                "id": 202,
                "name": "Botol 120 ml",
                "sku": "PRD-010-120",
                "price": "45000.00",
                "weight_grams": 250,
                "attributes": { "volume": "120 ml" }
            }
        ],
        "stores": [
            {
                "listing_id": 101,
                "store": {
                    "id": 1,
                    "name": "Toko Utama",
                    "slug": "toko-utama",
                    "code": "TOKO-001",
                    "city": "Bandung",
                    "province": "Jawa Barat"
                },
                "description": "Deskripsi penawaran toko.",
                "images": [],
                "published_at": "2026-11-03T01:00:00+00:00"
            }
        ]
    },
    "success": true,
    "message": "Detail produk berhasil diambil."
}
```

Gambar dan deskripsi penawaran ada di setiap `stores[]`, bukan di root produk. Produk aktif dengan varian aktif dapat mengembalikan 200 dengan `stores: []` jika belum memiliki penawaran toko yang memenuhi syarat; jangan menampilkan pilihan beli tanpa listing.

## 4. Daftar bundle

`GET /catalog/bundles`

| Parameter  | Nilai / batas                                                | Default                         |
| ---------- | ------------------------------------------------------------ | ------------------------------- |
| `q`        | Teks maksimal 100 karakter                                   | Tanpa pencarian                 |
| `store`    | Slug toko maksimal 255 karakter                              | Semua toko yang memenuhi syarat |
| `sort`     | `latest`, `name_asc`, `name_desc`, `price_asc`, `price_desc` | `latest`                        |
| `per_page` | Integer 1–50                                                 | 15                              |
| `page`     | Integer >= 1                                                 | 1                               |

Endpoint khusus bundle **tidak mendukung** filter `category`, `min_price`, `max_price`, atau urutan `relevance`. Gunakan `/catalog?type=bundle` untuk kebutuhan tersebut. Parameter yang tidak dikenal dapat diabaikan; jangan menganggap filter diterapkan hanya karena respons berstatus 200.

Pencarian bundle mencakup nama/kode bundle, toko, deskripsi listing, SKU/nama varian komponen, dan nama produk komponen. Semua kata pencarian harus cocok, maksimal 10 kata.

```bash
curl --get "$CATALOG_BASE_URL/catalog/bundles" \
  -H 'Accept: application/json' \
  --data-urlencode 'q=herbal' \
  --data-urlencode 'sort=price_asc'
```

Satu elemen `data[]` memiliki struktur berikut:

| Field                                 | Isi                                              |
| ------------------------------------- | ------------------------------------------------ |
| `listing_id`                          | ID listing bundle untuk cart                     |
| `bundle`                              | `id`, `name`, `slug`, `code`, `price`, `items`   |
| `store`                               | `id`, `name`, `slug`, `code`, `city`, `province` |
| `description`, `status`, `sort_order` | Informasi listing toko                           |
| `images`, `published_at`              | Galeri dan tanggal publikasi listing             |

Struktur `bundle.items` sama dengan `items` pada contoh detail di bawah. Pesan sukses endpoint: `Katalog bundle berhasil diambil.`

## 5. Detail bundle

`GET /catalog/bundles/{slug}`

```bash
curl "$CATALOG_BASE_URL/catalog/bundles/paket-herbal-a" \
  -H 'Accept: application/json'
```

```json
{
    "data": {
        "id": 30,
        "name": "Paket Herbal A",
        "slug": "paket-herbal-a",
        "code": "BND-030",
        "price": "40000.00",
        "items": [
            {
                "product": {
                    "id": 10,
                    "name": "Produk Herbal A",
                    "slug": "produk-herbal-a",
                    "product_code": "PRD-010"
                },
                "variant": {
                    "id": 201,
                    "name": "Botol 60 ml",
                    "sku": "PRD-010-60",
                    "price": "25000.00",
                    "weight_grams": 150
                },
                "quantity": 2
            }
        ],
        "stores": [
            {
                "listing_id": 301,
                "store": {
                    "id": 1,
                    "name": "Toko Utama",
                    "slug": "toko-utama",
                    "code": "TOKO-001",
                    "city": "Bandung",
                    "province": "Jawa Barat"
                },
                "description": "Paket dua botol.",
                "images": [],
                "published_at": "2026-11-03T01:00:00+00:00"
            }
        ]
    },
    "success": true,
    "message": "Detail bundle berhasil diambil."
}
```

Harga pembelian bundle adalah `price` milik bundle, bukan penjumlahan `variant.price` komponennya. `items[].quantity` adalah jumlah komponen dalam satu bundle. Membeli 3 bundle dengan komponen berjumlah 2 berarti 6 unit komponen. Bundle yang gagal memenuhi aturan kelayakan komponen mengembalikan 404.

## 6. Kategori

`GET /catalog/categories` mengembalikan kategori utama aktif beserta anak langsung yang aktif, diurutkan berdasarkan `sort_order` lalu nama. Endpoint ini tidak menggunakan pagination.

```bash
curl "$CATALOG_BASE_URL/catalog/categories" -H 'Accept: application/json'
```

```json
{
    "data": [
        {
            "id": 5,
            "name": "Suplemen",
            "slug": "suplemen",
            "description": null,
            "status": "active",
            "sort_order": 0,
            "product_count": 1,
            "children": []
        }
    ],
    "success": true,
    "message": "Daftar kategori berhasil diambil."
}
```

`GET /catalog/categories/{slug}` mengembalikan `data` berupa satu objek kategori dengan field yang sama, ditambah `parent` berisi `{id, name, slug}` atau `null`. Pesan sukses: `Detail kategori berhasil diambil.` Field `parent` tidak disertakan pada daftar kategori. Field `children` pada anak kategori dapat tidak disertakan karena relasi tingkat berikutnya tidak dimuat; respons bukan jaminan pohon kategori dengan kedalaman tak terbatas.

`product_count` menghitung master produk aktif yang terhubung langsung ke kategori, memiliki varian aktif dan listing aktif pada toko yang memenuhi syarat. Nilainya bukan jumlah listing, unit stok, atau bundle. Hitungan kategori induk tidak otomatis menjumlahkan produk kategori anak.

Kategori tidak memuat daftar produk. Untuk mengambil produknya:

```bash
curl --get "$CATALOG_BASE_URL/catalog/products" \
  -H 'Accept: application/json' \
  --data-urlencode 'category=suplemen'
```

## Gambar produk dan bundle

Katalog gabungan menyediakan gambar utama melalui `image_url` dan objek `image`. Endpoint khusus produk/bundle menyediakan galeri `images[]` pada listing atau penawaran toko. Contoh satu elemen galeri:

```json
{
    "id": 51,
    "name": "produk-herbal-a",
    "order": 1,
    "url": "https://api.example.com/storage/51/produk-herbal-a.jpg",
    "thumb": "https://api.example.com/storage/51/conversions/produk-herbal-a-thumb.jpg",
    "preview": null,
    "full": null
}
```

- `url` adalah gambar asli. `image_url` pada katalog gabungan juga menunjuk gambar asli.
- `thumb` adalah crop 300 × 300; `preview` dibatasi 800 × 800 dan `full` 1600 × 1600 dengan mempertahankan rasio.
- `preview` dan `full` diproses melalui queue. URL turunan dapat `null` jika belum tersedia; frontend dapat memakai URL asli sebagai fallback.
- Jika galeri listing kosong, backend memakai gambar master produk. Untuk bundle, fallback memakai master produk komponen pertama.
- Jika seluruh sumber gambar kosong, galeri adalah `[]`; seluruh URL pada gambar utama bernilai `null`. Frontend perlu menyediakan placeholder.

## Respons error dan batas akses

| HTTP | Kondisi                                                                              |
| ---- | ------------------------------------------------------------------------------------ |
| 200  | Berhasil, termasuk daftar kosong                                                     |
| 404  | Slug detail tidak ditemukan/tidak aktif, atau bundle tidak memenuhi aturan kelayakan |
| 422  | Query parameter yang divalidasi tidak memenuhi aturan                                |
| 429  | Batas akses katalog terlampaui                                                       |

Contoh 404 detail produk:

```json
{
    "success": false,
    "message": "Produk tidak ditemukan.",
    "code": "NOT_FOUND",
    "errors": null
}
```

Untuk bundle/kategori, pesan masing-masing `Bundle tidak ditemukan.` dan `Kategori tidak ditemukan.`

Contoh 422 untuk `min_price=50000&max_price=10000`:

```json
{
    "success": false,
    "message": "Harga maksimum harus lebih besar atau sama dengan harga minimum.",
    "code": "VALIDATION_ERROR",
    "errors": {
        "max_price": [
            "Harga maksimum harus lebih besar atau sama dengan harga minimum."
        ]
    }
}
```

Batas akses katalog adalah **120 request per menit** berdasarkan ID user yang dikenali atau IP request. Kuota limiter yang sama juga digunakan endpoint publik lain di grup tersebut, termasuk banner, warehouse, dan lokasi pengiriman. Saat menerima 429, ikuti header `Retry-After` sebelum mencoba lagi. Contoh body:

```json
{
    "success": false,
    "message": "Terlalu banyak percobaan. Silakan tunggu sebelum mencoba lagi.",
    "retry_after": 30
}
```
