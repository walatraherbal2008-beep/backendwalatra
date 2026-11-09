# API Manajemen Cart

Panduan integrasi API keranjang belanja untuk frontend web dan aplikasi Android. Dokumen ini mencakup operasi cart saja; checkout dan pembuatan order dijelaskan di [API Cart dan Checkout](cart-checkout.md).

## Ringkasan

Base URL contoh: `https://api.example.com/api/v1`. Ganti domain sesuai server.

Semua endpoint cart memerlukan autentikasi customer dan akun aktif. Gunakan token customer pada header `Authorization`. Dokumentasi cara memperoleh dan menggunakan token tersedia terpisah di [API autentikasi customer](web-authentication-api.md).

```http
Authorization: Bearer <TOKEN_CUSTOMER>
Accept: application/json
Content-Type: application/json
```

| Method | Endpoint | Fungsi | Respons sukses |
|---|---|---|---|
| `GET` | `/cart` | Membaca cart beserta item | `200` |
| `POST` | `/cart/items` | Menambahkan produk atau bundle | `200` |
| `PATCH` | `/cart/items/{item_id}` | Mengganti quantity item | `200` |
| `DELETE` | `/cart/items/{item_id}` | Menghapus satu item | `200` |
| `DELETE` | `/cart` | Mengosongkan seluruh cart | `200` |

## Model data dan ID

Cart dibuat otomatis saat pertama kali dibaca atau ditambah item. Cart terikat ke akun customer yang sedang login. Item cart milik customer lain tidak dapat dibaca, diubah, atau dihapus.

Bedakan ID berikut:

| ID | Arti | Digunakan untuk |
|---|---|---|
| `listing_id` | ID listing produk di toko | Menambahkan produk |
| `variant_id` | ID varian produk | Memilih varian produk |
| `bundle_listing_id` | ID listing bundle di toko | Menambahkan bundle |
| `items[].id` | ID baris item cart | Mengubah atau menghapus item |

ID item cart tidak sama dengan ID listing, produk, bundle, atau varian. Ambil `items[].id` dari respons cart untuk operasi item.

Harga dikirim sebagai string desimal dalam rupiah, misalnya `"15000.00"`. Backend menentukan harga; jangan mengirim harga dari aplikasi.

## Membaca cart

`GET /cart`

Respons `200` berisi produk dan bundle dalam satu daftar `data.items`. Item yang terakhir ditambahkan tampil lebih dulu. Cart kosong tetap menghasilkan respons `200` dengan `items: []`, `total_items: 0`, dan `subtotal: "0.00"`.

Contoh respons:

```json
{
  "success": true,
  "message": "Cart berhasil diambil.",
  "data": {
    "id": 1,
    "items": [
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
    "total_items": 2,
    "subtotal": "30000.00"
  }
}
```

`total_items` menjumlahkan quantity semua baris, bukan jumlah baris cart. `subtotal` adalah jumlah `quantity × unit_price` untuk seluruh isi cart. Nilai tersebut merangkum isi cart saat respons dibuat; hitung ulang dari respons baru setelah ada perubahan.

Objek item selalu memiliki `type`, `listing_id`, `bundle_listing_id`, `variant_id`, `product`, `bundle`, `store`, dan `variant`; field yang tidak sesuai jenis item bernilai `null`. Produk mempunyai `product` dan `variant`; bundle mempunyai `bundle` berisi informasi bundle serta komponen-komponennya. `image_url` dapat bernilai `null`.

### URL gambar dan urutan sumber

`image_url` adalah URL media yang dibuat oleh Laravel Media Library. Pada konfigurasi public storage biasa bentuknya URL absolut berdasarkan `APP_URL` (misalnya `https://api.example.com/storage/...`); jika disk media menggunakan URL/CDN sendiri, URL mengikuti konfigurasi disk tersebut. API tidak menjamin host tertentu. Field dapat bernilai `null` jika semua sumber gambar terkait kosong; klien sebaiknya tidak menyambung path sendiri dan menyiapkan placeholder.

Urutan gambar utama item cart:

| Jenis item | Urutan sumber `image_url` |
|---|---|
| Produk | Foto milik varian (`variant.image_url`) → foto listing toko → foto master produk. Untuk dua sumber terakhir, backend memilih gambar galeri pertama dari listing toko; jika listing tidak memiliki foto, memakai gambar galeri pertama produk master. |
| Bundle | Foto listing bundle toko → gambar galeri pertama produk master pada komponen bundle pertama. Foto varian komponen tidak dipakai untuk `items[].image_url`. |

Pada bundle, gambar tiap komponen tersedia terpisah di `bundle.items[].variant.image_url`. Field itu berisi foto khusus varian komponen atau `null`; ia tidak memakai foto listing/master sebagai fallback.

### Contoh item bundle

Contoh ini menunjukkan bentuk lengkap objek cart untuk satu bundle. `bundle.items[].quantity` adalah jumlah komponen yang dibutuhkan untuk satu bundle; `items[].quantity` di luar objek `bundle` adalah jumlah paket bundle yang dibeli.

```json
{
  "id": 15,
  "type": "bundle",
  "image_url": "https://api.example.com/storage/bundles/paket-herbal-a.jpg",
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
          "image_url": "https://api.example.com/storage/variants/prd-010-60.png",
          "price": "15000.00",
          "weight_grams": 150
        },
        "quantity": 2
      }
    ]
  },
  "store": {"id": 1, "name": "Toko Utama", "slug": "toko-utama"},
  "variant": null,
  "quantity": 1,
  "unit_price": "40000.00",
  "subtotal": "40000.00"
}
```

Nama dan struktur field komponen mengikuti resource bundle: setiap komponen memiliki `product` (`id`, `name`, `slug`, `product_code`), `variant` (`id`, `name`, `sku`, `image_url`, `price`, `weight_grams`), dan `quantity`. `bundle.items` bukan daftar baris cart; seluruh bundle tetap satu baris cart dengan ID `items[].id`.

## Menambahkan item

`POST /cart/items`

### Produk

```json
{
  "type": "product",
  "listing_id": 101,
  "variant_id": 201,
  "quantity": 2
}
```

`type` boleh tidak dikirim untuk produk; jika tidak ada `bundle_listing_id`, backend menganggapnya sebagai `product`. Sebaiknya kirim `type` secara eksplisit.

### Bundle

```json
{
  "type": "bundle",
  "bundle_listing_id": 301,
  "quantity": 1
}
```

Backend memvalidasi bahwa listing, produk atau bundle, varian, dan toko tersedia untuk dibeli. Jika tidak tersedia, backend mengembalikan `422` dengan rincian validasi. Harga dan detail bundle ditentukan server.

### Aturan penambahan

- `quantity` wajib integer `1` sampai `99`.
- Kombinasi listing produk dan varian yang sama digabung ke baris cart yang ada. Listing atau varian berbeda menjadi baris terpisah.
- Bundle dengan listing yang sama digabung ke baris yang ada.
- Penambahan menambah quantity saat ini, bukan menggantinya. Quantity gabungan dibatasi maksimal `99`.
- Sukses mengembalikan `200` dan seluruh cart terbaru.
- Mengirim ulang request yang sama akan menambah quantity lagi. Hindari retry otomatis tanpa memastikan hasil request sebelumnya.

Pesan sukses: `Produk berhasil ditambahkan ke cart.` atau `Bundle berhasil ditambahkan ke cart.`

## Mengubah quantity

`PATCH /cart/items/{item_id}`

Path memakai ID baris cart dari `data.items[].id`.

```json
{"quantity": 3}
```

`quantity` wajib integer `1` sampai `99` dan menggantikan nilai sebelumnya. Untuk menghapus item, gunakan endpoint hapus; quantity `0` tidak diterima.

Respons sukses `200` berisi seluruh cart terbaru dengan pesan `Jumlah item cart berhasil diperbarui.` Item yang tidak ada di cart customer menghasilkan `404`.

## Menghapus satu item

`DELETE /cart/items/{item_id}` tanpa body.

Respons sukses `200`:

```json
{
  "success": true,
  "message": "Item cart dihapus.",
  "code": "CART_ITEM_DELETED",
  "data": null
}
```

Jika ID tidak ditemukan di cart customer, endpoint tetap mengembalikan sukses dan tidak menghapus item milik customer lain.

## Mengosongkan cart

`DELETE /cart` tanpa body menghapus semua item produk dan bundle milik customer yang sedang login.

Respons sukses `200`:

```json
{
  "success": true,
  "message": "Cart dikosongkan.",
  "code": "CART_CLEARED",
  "data": null
}
```

## Respons dan integrasi frontend/Android

- `GET`, `POST`, dan `PATCH` mengembalikan representasi cart penuh. Gunakan `data` dari respons itu untuk memperbarui state lokal, sehingga quantity, subtotal, dan urutan item tetap sesuai server.
- Setelah operasi `DELETE`, panggil `GET /cart` jika tampilan membutuhkan daftar dan total cart terbaru.
- Untuk menampilkan jumlah pada ikon cart, gunakan `data.total_items`. Untuk ringkasan nilai barang, gunakan `data.subtotal`.
- Gunakan ID cart item sebagai key stabil pada daftar UI. Jangan gunakan indeks array.
- Request tambah yang timeout belum tentu gagal diproses server. Muat ulang `GET /cart` sebelum mencoba tambah lagi agar quantity tidak bertambah dua kali.
- Jangan mengasumsikan cart yang tersimpan di perangkat adalah sumber utama. Muat cart dari server setelah login atau saat aplikasi kembali aktif, lalu sinkronkan perubahan berdasarkan respons API.
- Produk dan bundle berada di daftar yang sama. Jika tampilan perlu dikelompokkan per toko, kelompokkan berdasarkan `store.id` di aplikasi.

## Penanganan error

| HTTP | Arti umum | Tindakan aplikasi |
|---|---|---|
| `401` | Token tidak ada, tidak valid, atau kedaluwarsa | Jalankan penanganan sesi sesuai implementasi autentikasi aplikasi |
| `403` | Akun tidak memenuhi syarat akses, misalnya tidak aktif | Tampilkan pesan akses dan arahkan pengguna sesuai alur aplikasi |
| `404` | Item untuk operasi `PATCH` tidak ditemukan di cart pengguna | Muat ulang cart dan sinkronkan tampilan |
| `422` | Payload tidak valid atau listing/varian/bundle tidak tersedia | Tampilkan validasi yang dikembalikan server; muat ulang katalog bila item sudah tidak tersedia |

Format error validasi Laravel umumnya memiliki `message` dan objek `errors`, misalnya:

```json
{
  "message": "The quantity field must be at least 1.",
  "errors": {"quantity": ["The quantity field must be at least 1."]}
}
```

Gunakan teks error aktual dari server bila sesuai bahasa UI; jangan mengandalkan satu kalimat contoh di atas.
