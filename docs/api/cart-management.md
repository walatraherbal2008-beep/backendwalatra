# API Manajemen Cart

Postman: `base_url` lokal harus `http://127.0.0.1:8000/api/v1`. Endpoint ditambahkan setelah nilai ini. Environment aktif dapat menimpa variable Collection; lihat [pengaturan Postman](postman-setup.md).

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

Pilihan sementara dapat dikirim melalui `GET /cart?cart_item_ids[]=12&cart_item_ids[]=15`. Respons tetap berisi seluruh cart; parameter tersebut hanya menentukan ringkasan `data.selection`. Pilihan tidak disimpan ke akun dan tidak memanggil API gudang.

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
        "is_available": true,
        "availability_message": null,
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
    "subtotal": "30000.00",
    "selection": {
      "cart_item_ids": [],
      "line_count": 0,
      "total_items": 0,
      "subtotal": "0.00",
      "unavailable_item_ids": []
    }
  }
}
```

`total_items` menjumlahkan quantity semua baris, bukan jumlah baris cart. `subtotal` adalah jumlah `quantity × unit_price` untuk seluruh isi cart. Nilai tersebut merangkum isi cart saat respons dibuat; hitung ulang dari respons baru setelah ada perubahan.

Objek item selalu memiliki `type`, `listing_id`, `bundle_listing_id`, `variant_id`, `product`, `bundle`, `store`, dan `variant`; field yang tidak sesuai jenis item bernilai `null`. Produk mempunyai `product` dan `variant`; bundle mempunyai `bundle` berisi informasi bundle serta komponen-komponennya. `image_url` dapat bernilai `null`.

### Barang tidak tersedia

Listing, produk, varian, atau bundle yang dihapus secara soft delete tetap ditampilkan selama identitasnya masih tersedia. Item diberi `is_available: false` dan `availability_message: "Tidak tersedia"`. Hal yang sama berlaku ketika barang atau toko dinonaktifkan, toko tidak memenuhi syarat marketplace, maupun komponen bundle tidak tersedia. Objek yang tidak ditemukan dapat bernilai `null`, termasuk objek produk/varian komponen bundle. Barang tidak dihapus otomatis dari cart; user tetap dapat menghapusnya, tetapi tidak dapat mengubah quantity atau membelinya.

### Ringkasan barang terpilih

| Field `selection` | Arti |
|---|---|
| `cart_item_ids` | Seluruh ID yang dikirim sebagai pilihan sementara. |
| `line_count` | Jumlah baris terpilih yang tersedia. |
| `total_items` | Jumlah quantity barang terpilih yang tersedia; quantity bundle dihitung sebagai jumlah paket. |
| `subtotal` | Total harga tersimpan di cart × quantity untuk pilihan yang tersedia. |
| `unavailable_item_ids` | ID terpilih yang tidak tersedia dan dikeluarkan dari perhitungan ringkasan. |

Tanpa pilihan, ringkasan kosong/nol. ID harus berupa array berurutan berisi integer positif unik milik cart user; format salah, ID asing, atau tidak ditemukan menghasilkan 422. `total_items` dan `subtotal` di luar `selection` tetap menghitung seluruh cart, termasuk item yang tidak tersedia. Ringkasan GET cart belum merupakan harga final; preview menghitung ulang harga master terbaru. Item tidak tersedia yang masih dipilih akan ditolak oleh preview.

Respons POST/PATCH juga menyediakan `selection` kosong. Untuk memperbarui ringkasan pilihan yang sedang aktif, panggil GET cart lagi dengan kumpulan ID yang masih dipilih.

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
- Nonaktifkan tombol tambah selama request berjalan. Endpoint tambah tidak menyediakan deduplikasi/idempotency baru: dua request yang berhasil tetap berarti dua penambahan, dengan total quantity maksimal 99. Angka tampilan harus mengikuti quantity dari respons server.
- Centang per item/per toko menghasilkan kumpulan ID cart item. Kirim ke GET cart untuk ringkasan pilihan, lalu ke preview. Order baru wajib membawa `checkout_token` dari preview yang sudah disetujui user; lihat [alur checkout](cart-checkout.md).
- Jangan mengasumsikan cart yang tersimpan di perangkat adalah sumber utama. Muat cart dari server setelah login atau saat aplikasi kembali aktif, lalu sinkronkan perubahan berdasarkan respons API.
- Produk dan bundle berada di daftar yang sama. Jika tampilan perlu dikelompokkan per toko, kelompokkan berdasarkan `store.id` di aplikasi.

## Penanganan error

| HTTP | Arti umum | Tindakan aplikasi |
|---|---|---|
| `401` | Token tidak ada, tidak valid, atau kedaluwarsa | Jalankan penanganan sesi sesuai implementasi autentikasi aplikasi |
| `403` | Akun tidak memenuhi syarat akses, misalnya tidak aktif | Tampilkan pesan akses dan arahkan pengguna sesuai alur aplikasi |
| `404` | Item untuk operasi `PATCH` tidak ditemukan di cart pengguna | Muat ulang cart dan sinkronkan tampilan |
| `409 STALE_CART` | Quantity item tidak tersedia tidak boleh diubah | Hapus item tersebut atau muat ulang cart |
| `422` | Payload tidak valid atau listing/varian/bundle tidak tersedia | Tampilkan validasi yang dikembalikan server; muat ulang katalog bila item sudah tidak tersedia |

Format error validasi Laravel umumnya memiliki `message` dan objek `errors`, misalnya:

```json
{
  "message": "The quantity field must be at least 1.",
  "errors": {"quantity": ["The quantity field must be at least 1."]}
}
```

Gunakan teks error aktual dari server bila sesuai bahasa UI; jangan mengandalkan satu kalimat contoh di atas.
