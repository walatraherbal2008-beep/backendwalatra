# API Katalog Marketplace

Dokumentasi ini ditujukan untuk developer web frontend dan Android.

## Konfigurasi dasar

Base URL API:

```text
{BASE_URL}/api/v1
```

Contoh lokal:

```text
http://127.0.0.1:8001/api/v1
```

Endpoint katalog bersifat **public read-only** dan tidak memerlukan token autentikasi.

Header yang disarankan untuk setiap request:

```http
Accept: application/json
```

## Katalog gabungan produk dan bundle

```http
GET /catalog
```

Endpoint ini digunakan untuk halaman beranda, eksplorasi, dan pencarian marketplace. Response menggabungkan listing produk biasa dan listing paket bundle.

### Query parameter

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---:|---|
| `q` | string | Tidak | Mencari berdasarkan nama, kode, atau nama toko. Maksimal 100 karakter. |
| `type` | string | Tidak | `product` atau `bundle`. Jika dikosongkan, keduanya ditampilkan. |
| `store` | string | Tidak | Filter berdasarkan slug toko. |
| `category` | string | Tidak | Filter slug kategori. Untuk bundle, kategori dicari dari produk komponennya. |
| `min_price` | number | Tidak | Harga minimum. |
| `max_price` | number | Tidak | Harga maksimum. |
| `sort` | string | Tidak | `latest`, `name_asc`, `name_desc`, `price_asc`, atau `price_desc`. Default: `latest`. |
| `page` | integer | Tidak | Nomor halaman. Default: `1`. |
| `per_page` | integer | Tidak | Jumlah data per halaman, minimal 1 dan maksimal 50. Default: `15`. |

Contoh request:

```bash
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog?q=lambung&type=bundle&sort=price_asc&per_page=20' \
  --header 'Accept: application/json'
```

### Response berhasil

```json
{
  "success": true,
  "message": "Katalog gabungan berhasil diambil.",
  "data": [
    {
      "type": "product",
      "listing_id": 12,
      "id": 5,
      "name": "Sehat Lambungku",
      "slug": "sehat-lambungku",
      "code": "PRD-WAL-ABCDEFGH",
      "price": {
        "min": "50000.00",
        "max": "75000.00"
      },
      "image_url": "http://127.0.0.1:8001/storage/12/image.jpg",
      "store": {
        "id": 3,
        "name": "Walatra Herbal",
        "slug": "walatra-herbal",
        "city": "Bandung",
        "province": "Jawa Barat"
      },
      "published_at": "2026-09-17T10:00:00+07:00"
    },
    {
      "type": "bundle",
      "listing_id": 8,
      "id": 2,
      "name": "Paket Lambung Sehat",
      "slug": "paket-lambung-sehat",
      "code": "BND-ABCDEFGH",
      "price": {
        "value": "120000.00"
      },
      "image_url": null,
      "store": {
        "id": 3,
        "name": "Walatra Herbal",
        "slug": "walatra-herbal",
        "city": "Bandung",
        "province": "Jawa Barat"
      },
      "published_at": "2026-09-17T10:00:00+07:00"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:8001/api/v1/catalog?page=1",
    "last": "http://127.0.0.1:8001/api/v1/catalog?page=2",
    "prev": null,
    "next": "http://127.0.0.1:8001/api/v1/catalog?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "per_page": 15,
    "to": 15,
    "total": 30
  }
}
```

Frontend atau Android wajib menggunakan field `type` untuk menentukan halaman detail:

```text
type = product -> /catalog/products/{slug}
type = bundle  -> /catalog/bundles/{slug}
```

`listing_id` adalah ID penawaran produk/bundle pada toko. `id` adalah ID master produk atau master bundle.

### Contoh penggunaan filter

```bash
# Semua item katalog terbaru
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog' \
  --header 'Accept: application/json'

# Hanya produk dari kategori tertentu
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog?type=product&category=pencernaan&sort=price_asc' \
  --header 'Accept: application/json'

# Hanya bundle pada toko tertentu dengan rentang harga
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog?type=bundle&store=walatra-herbal&min_price=50000&max_price=250000' \
  --header 'Accept: application/json'

# Halaman kedua dengan 20 item
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog?page=2&per_page=20' \
  --header 'Accept: application/json'
```

### Contoh penggunaan di web frontend

```javascript
const params = new URLSearchParams({
  q: 'lambung',
  sort: 'price_asc',
  page: '1',
  per_page: '20',
});

const response = await fetch(`${BASE_URL}/api/v1/catalog?${params}`, {
  headers: { Accept: 'application/json' },
});

const result = await response.json();

if (!result.success) {
  throw new Error(result.message);
}

result.data.forEach((item) => {
  const detailUrl = item.type === 'product'
    ? `/produk/${item.slug}`
    : `/bundle/${item.slug}`;

  console.log(item.name, item.type, detailUrl);
});
```

Gunakan `links.next` atau `meta.current_page` dan `meta.last_page` untuk pagination. Jangan menebak URL halaman berikutnya sendiri jika `links.next` bernilai `null`.

## Katalog produk

```http
GET /catalog/products
GET /catalog/products/{slug}
```

Endpoint list produk mendukung `q`, `store`, `category`, `min_price`, `max_price`, `sort`, `page`, dan `per_page`. Nilai `sort` yang tersedia:

```text
relevance, latest, name_asc, name_desc, price_asc, price_desc
```

Response list menggunakan struktur `listing_id`, `product`, `store`, `variants`, `images`, dan `published_at`.

Contoh request list dan detail:

```bash
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog/products?q=propolis&sort=relevance' \
  --header 'Accept: application/json'

curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog/products/propolis-sm' \
  --header 'Accept: application/json'
```

## Katalog bundle

```http
GET /catalog/bundles
GET /catalog/bundles/{slug}
```

Endpoint list bundle mendukung `q`, `store`, `sort`, `page`, dan `per_page`. Nilai `sort` yang tersedia:

```text
latest, name_asc, name_desc, price_asc, price_desc
```

Setiap bundle memiliki daftar item produk pada field `bundle.items`.

Contoh request list dan detail:

```bash
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog/bundles?sort=price_asc&per_page=10' \
  --header 'Accept: application/json'

curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog/bundles/paket-lambung-sehat' \
  --header 'Accept: application/json'
```

## Katalog kategori

```http
GET /catalog/categories
GET /catalog/categories/{slug}
```

Kategori hanya dikelola oleh superadmin. Produk dapat memiliki lebih dari satu kategori. Endpoint kategori hanya menghitung dan menampilkan produk katalog yang memenuhi aturan aktif.

Contoh request:

```bash
curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog/categories' \
  --header 'Accept: application/json'

curl --request GET \
  --url 'http://127.0.0.1:8001/api/v1/catalog/categories/pencernaan' \
  --header 'Accept: application/json'
```

## Aturan visibilitas katalog

Data hanya dikembalikan jika memenuhi aturan berikut:

- Toko berstatus `active`.
- Listing toko berstatus `active`.
- Produk berstatus `active` dan memiliki minimal satu varian aktif.
- Bundle berstatus `active` dan memiliki item bundle.
- Seluruh varian dan produk yang digunakan bundle berstatus aktif.
- Data yang sudah di-soft-delete tidak ditampilkan.

Client tidak perlu mengubah status atau melakukan validasi availability sendiri. Jika status berubah di backend, data tersebut tidak akan dikembalikan oleh endpoint katalog.

## Error response

Format error menggunakan struktur standar berikut:

```json
{
  "success": false,
  "message": "Tipe katalog hanya boleh product atau bundle.",
  "errors": {
    "type": [
      "Tipe katalog hanya boleh product atau bundle."
    ]
  }
}
```

HTTP status yang umum:

| Status | Keterangan |
|---:|---|
| `200` | Request berhasil. |
| `404` | Produk atau bundle tidak ditemukan/tidak aktif. |
| `422` | Parameter query tidak valid. |
| `429` | Terlalu banyak request dalam waktu singkat. |
| `500` | Kesalahan internal server. Client dapat mencoba kembali kemudian. |
