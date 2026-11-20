# API Manajemen Cart

Dokumen ini menjelaskan kontrak cart customer yang berlaku di API marketplace. Produk dan bundle berada pada cart yang sama. Checkout dan pembayaran dijelaskan di [API Checkout Order](checkout-orders.md).

## Base URL dan autentikasi

Base URL API: `/api/v1`, contoh lokal `http://127.0.0.1:8000/api/v1`.

Semua endpoint cart memerlukan customer yang sudah login, akun aktif, dan token Sanctum dengan ability `customer-api`.

```http
Authorization: Bearer <customer_token>
Accept: application/json
Content-Type: application/json
```

Cart terpisah per customer. Item hanya dapat dibaca dan diubah oleh pemilik cart.

## Ringkasan endpoint

| Method | Endpoint                     | Fungsi                                                             |
| ------ | ---------------------------- | ------------------------------------------------------------------ |
| GET    | `/cart`                      | Membaca seluruh cart dan ringkasan pilihan opsional                |
| POST   | `/cart/items`                | Menambahkan produk atau bundle; jika sudah ada, quantity bertambah |
| PATCH  | `/cart/items/{cart_item_id}` | Mengganti quantity item menjadi nilai yang dikirim                 |
| DELETE | `/cart/items/{cart_item_id}` | Menghapus satu item, termasuk item yang tidak tersedia             |
| DELETE | `/cart`                      | Mengosongkan cart                                                  |

Semua endpoint pada tabel mengembalikan HTTP `200` ketika berhasil.

## 1. Membaca cart

```http
GET /api/v1/cart
```

Respons selalu memuat seluruh item cart. Item produk dan bundle dapat dikelompokkan di aplikasi menggunakan `items[].store`; API tidak menyediakan filter `store_id`. Urutan default adalah item yang paling baru ditambahkan di posisi teratas. Mengubah quantity tidak mengubah urutan; menambahkan item yang sama lagi akan memperbarui waktu penambahannya.

### Membaca ringkasan pilihan

Pilihan checkbox disimpan sementara oleh aplikasi. Kirim ID baris cart yang dipilih sebagai query berulang:

```http
GET /api/v1/cart?cart_item_ids[]=301&cart_item_ids[]=302
```

`cart_item_ids` adalah `items[].id`, bukan ID produk, varian, listing, atau bundle. API tetap mengembalikan seluruh cart; pilihan hanya mengisi objek `data.selection`.

Tanpa `cart_item_ids`, ringkasan `selection` kosong dan bernilai nol. Untuk pilihan kosong, hilangkan parameter tersebut. Jika dikirim, ID harus berupa integer positif yang unik dan seluruhnya harus berasal dari cart customer. Format salah, duplikat, atau ID yang tidak ada pada cart menghasilkan HTTP `422`.

Contoh respons:

```json
{
    "success": true,
    "message": "Cart berhasil diambil.",
    "data": {
        "id": 27,
        "items": [
            {
                "id": 301,
                "type": "product",
                "is_available": true,
                "availability_message": null,
                "image_url": "https://api.example.com/storage/products/variant.jpg",
                "listing_id": 18,
                "bundle_listing_id": null,
                "variant_id": 41,
                "product": {
                    "id": 10,
                    "name": "Produk Herbal",
                    "slug": "produk-herbal"
                },
                "bundle": null,
                "store": {
                    "id": 2,
                    "name": "Toko Herbal",
                    "slug": "toko-herbal"
                },
                "variant": {
                    "id": 41,
                    "name": "Botol 60 ml",
                    "sku": "SKU-WAL-PRD-60"
                },
                "quantity": 2,
                "unit_price": "155000.00",
                "subtotal": "310000.00"
            },
            {
                "id": 302,
                "type": "bundle",
                "is_available": true,
                "availability_message": null,
                "image_url": "https://api.example.com/storage/bundles/paket.jpg",
                "listing_id": null,
                "bundle_listing_id": 9,
                "variant_id": null,
                "product": null,
                "bundle": {
                    "id": 6,
                    "name": "Paket Perawatan",
                    "slug": "paket-perawatan",
                    "items": [
                        {
                            "product": {
                                "id": 10,
                                "name": "Produk Herbal",
                                "slug": "produk-herbal",
                                "product_code": "PRD"
                            },
                            "variant": {
                                "id": 41,
                                "name": "Botol 60 ml",
                                "sku": "SKU-WAL-PRD-60",
                                "image_url": null,
                                "price": "155000.00",
                                "weight_grams": 100
                            },
                            "quantity": 1
                        }
                    ]
                },
                "store": {
                    "id": 2,
                    "name": "Toko Herbal",
                    "slug": "toko-herbal"
                },
                "variant": null,
                "quantity": 1,
                "unit_price": "180000.00",
                "subtotal": "180000.00"
            }
        ],
        "total_items": 3,
        "subtotal": "490000.00",
        "selection": {
            "cart_item_ids": [301, 302],
            "line_count": 2,
            "total_items": 3,
            "subtotal": "490000.00",
            "unavailable_item_ids": []
        }
    }
}
```

`data.total_items` dan `data.subtotal` merangkum seluruh cart. `data.selection` hanya merangkum item terpilih yang tersedia: `line_count` menghitung baris, sedangkan `total_items` menjumlahkan quantity. Untuk bundle, quantity yang dihitung adalah jumlah bundle pada cart, bukan jumlah seluruh komponen. Harga pada GET adalah harga yang tersimpan di cart; harga terbaru dikonfirmasi oleh checkout preview.

Jika item terpilih sudah tidak tersedia, GET tetap berhasil dan ID-nya tercantum di `selection.unavailable_item_ids`; item itu tidak masuk hitungan pilihan. Preview checkout akan menolak pilihan yang berisi item tidak tersedia.

### Ketersediaan item

Setiap item menyertakan:

- `is_available`: `true` jika listing, toko, produk/bundle, dan varian masih aktif serta dapat dibeli.
- `availability_message`: `null` jika tersedia atau `"Tidak tersedia"` jika tidak tersedia.

Item yang listing, master, produk, atau variannya dinonaktifkan maupun dihapus tetap dapat muncul agar identitasnya bisa ditinjau dan itemnya dihapus. Relasi yang sudah benar-benar tidak ditemukan, termasuk `product`, `bundle`, `store`, atau `image_url`, dapat bernilai `null`.

`image_url` adalah gambar utama baris cart. Untuk produk, urutannya adalah foto varian, galeri listing toko, lalu gambar master produk. Untuk bundle, dipakai galeri listing bundle; jika kosong, fallback saat ini memakai gambar master produk dari komponen pertama bundle. Foto varian komponen tersedia terpisah di `bundle.items[].variant.image_url` dan tidak menggantikan `image_url` utama bundle.

## 2. Menambah produk atau bundle

### Produk

```http
POST /api/v1/cart/items
```

```json
{
    "type": "product",
    "listing_id": 18,
    "variant_id": 41,
    "quantity": 2
}
```

`listing_id` harus menunjuk listing toko yang aktif. `variant_id` harus merupakan varian aktif dari produk listing tersebut. Server mengambil harga; client tidak mengirim harga.

### Bundle

```json
{
    "type": "bundle",
    "bundle_listing_id": 9,
    "quantity": 1
}
```

Bundle harus aktif, dapat dibeli, memiliki komponen valid, dan listing/tokonya aktif. Jika `type` tidak dikirim, API menyimpulkan `bundle` ketika `bundle_listing_id` dikirim; jika tidak, default-nya `product`. Sebaiknya client tetap mengirim `type` secara eksplisit.

Quantity pada request wajib integer `1–99`. Request tambah bersifat **incremental**. Jika baris produk dengan pasangan listing-varian atau baris bundle dengan listing yang sama sudah ada, API menambahkan quantity ke jumlah saat ini, maksimal `99`. Respons berisi cart terbaru dan quantity hasil simpan; gunakan nilai respons itu untuk memperbarui tampilan. Nonaktifkan tombol tambah selama request berjalan agar klik ganda tidak menambah quantity tanpa sengaja.

Jika listing/produk/varian/bundle tidak tersedia, respons `422` dan cart tidak berubah. Respons sukses memiliki bentuk `data` Cart seperti pada `GET /cart`, termasuk `items[].quantity` dan ringkasan seluruh cart.

## 3. Mengganti quantity

```http
PATCH /api/v1/cart/items/301
Content-Type: application/json

{"quantity": 3}
```

PATCH **mengganti** quantity menjadi angka yang dikirim; tidak menambahkannya. Quantity wajib integer `1–99`. Respons mengembalikan seluruh cart terbaru dan quantity aktual.

Item yang tidak tersedia tidak dapat diubah. Responsnya HTTP `409`:

```json
{
    "success": false,
    "code": "STALE_CART",
    "message": "Barang tidak tersedia. Silakan hapus item dari cart.",
    "errors": null,
    "data": null
}
```

ID item yang tidak dimiliki atau tidak ditemukan untuk customer menghasilkan `404`. Quantity invalid menghasilkan `422`.

## 4. Menghapus item atau mengosongkan cart

Hapus satu item, termasuk item tidak tersedia:

```http
DELETE /api/v1/cart/items/301
```

```json
{
    "success": true,
    "message": "Item cart dihapus.",
    "code": "CART_ITEM_DELETED",
    "data": null
}
```

Endpoint ini idempotent untuk ID yang tidak ditemukan atau bukan milik customer: respons tetap `200`, tetapi tidak ada item yang berubah.

Kosongkan seluruh cart:

```http
DELETE /api/v1/cart
```

Respons sukses menggunakan `code: "CART_CLEARED"` dan `data: null`.

## Alur penggunaan untuk client

1. Tambahkan produk atau bundle dengan `POST /cart/items`.
2. Baca cart dengan `GET /cart`; simpan ID baris, group item berdasarkan `store`, dan tampilkan urutan yang dikirim server.
3. Simpan pilihan checkbox di state aplikasi. Saat pilihan berubah, kirim ID terpilih sebagai `cart_item_ids[]` untuk memperoleh ringkasan pilihan tanpa menyaring isi cart.
4. Gunakan `PATCH` untuk mengganti quantity dan selalu tampilkan quantity dari respons server. Jika menerima `409 STALE_CART`, tawarkan hapus item.
5. Hapus item dengan `DELETE /cart/items/{cart_item_id}`. Cart dapat berisi produk dan bundle sekaligus.
6. Untuk order baru, kirim pilihan yang sama sebagai `cart_item_ids` ke `POST /checkout/preview` terlebih dahulu. Ketentuan preview, alamat, dan token checkout ada di [API Checkout Order](checkout-orders.md).

Pilihan item tidak disimpan di database dan tidak melekat pada toko tertentu. Untuk memilih semua item yang tersedia dari sebuah toko, client mengambil ID item tersedia dari `data.items` yang `store.id`-nya cocok lalu mengirim kumpulan ID itu. Tidak ada endpoint filter-per-toko terpisah.

## Ringkasan status dan error

| HTTP             | Kondisi                                                                                                               |
| ---------------- | --------------------------------------------------------------------------------------------------------------------- |
| `200`            | GET, POST, PATCH, dan DELETE berhasil; DELETE item juga idempotent untuk ID yang tidak ditemukan/bukan milik customer |
| `401`            | Token hilang atau tidak valid                                                                                         |
| `404`            | PATCH terhadap item yang tidak ditemukan atau bukan milik customer                                                    |
| `409 STALE_CART` | PATCH terhadap item cart yang sudah tidak tersedia                                                                    |
| `422`            | Field request invalid, quantity di luar `1–99`, pilihan duplikat/invalid, atau item pilihan bukan milik cart customer |
