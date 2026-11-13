# Pengaturan Postman

## Base URL dan Authorization

Untuk server lokal pada port 8000, gunakan:

| Variable | Value |
|---|---|
| `base_url` | `http://127.0.0.1:8000/api/v1` |
| `customer_token` | Token dari respons verify OTP, tanpa awalan `Bearer` |

`base_url` sudah mencakup `/api/v1` dan tidak diakhiri `/`. Endpoint pada dokumentasi ditambahkan setelah base URL. Contoh:

```text
{{base_url}}/cart/items
```

URL akhirnya harus menjadi:

```text
http://127.0.0.1:8000/api/v1/cart/items
```

Jika Environment aktif memiliki `base_url`, nilainya menimpa variable Collection dengan nama yang sama. Ubah nilainya agar sama dengan tabel di atas, atau hapus variable tersebut dari Environment dan gunakan nilai Collection. Periksa nilai yang benar-benar digunakan dengan mengarahkan kursor ke `{{base_url}}`.

Aturan yang sama berlaku untuk ID dan token yang diisi otomatis oleh script koleksi. Variable Environment lama dapat menimpa `product_cart_item_id`, `bundle_cart_item_id`, `checkout_token`, atau `order_id` yang baru disimpan ke Collection. Hindari mendefinisikan nama yang sama di kedua tempat.

Untuk server publik, ganti hanya domain/port dengan alamat backend yang aktif; pertahankan `/api/v1`. Alamat ngrok yang offline tidak dapat digunakan.

Untuk endpoint customer, pilih Authorization → Bearer Token → `{{customer_token}}`. Request OTP dan katalog publik tidak memerlukan Bearer token. Kirim `Accept: application/json` dan gunakan Body → raw → JSON untuk request JSON.

## Contoh tambah satu produk

Pada data lokal yang diperiksa 12 November 2026, listing **32** adalah Keloreena dan varian **132** adalah 50 Kapsul. Ini contoh data yang tersedia saat pemeriksaan, bukan ID tetap untuk seluruh instalasi. Di server lain, ambil `listing_id` dari katalog dan `variant_id` dari `variants[].id` pada listing yang sama.

```http
POST http://127.0.0.1:8000/api/v1/cart/items
```

```json
{
  "type": "product",
  "listing_id": 32,
  "variant_id": 132,
  "quantity": 2
}
```

Hasil sukses: HTTP **200**. Ambil ID baris pada `data.items[].id` untuk mengisi `product_cart_item_id`; jangan menggunakan listing ID atau variant ID sebagai ID cart item.

Untuk alamat, ambil `address_id` dari `GET {{base_url}}/addresses` milik akun yang login. Untuk checkout, jalankan preview terlebih dahulu, kemudian kirim `checkout_token` hasil preview dan header `Idempotency-Key` pada pembuatan order. ID order diperoleh dari respons order, bukan dibuat sendiri.

## Jika mendapat 404

- Pesan `The route cart/items could not be found` menunjukkan request menuju `/cart/items`, tanpa prefix `/api/v1`.
- Jangan memakai `base_url` yang hanya berisi `http://127.0.0.1:8000` pada koleksi ini.
- Jangan menambahkan `/api/v1` dua kali: jika base URL sudah lengkap, request tetap `{{base_url}}/cart/items`.
- Jika path sudah benar dan endpoint memakai ID, periksa apakah ID masih tersedia dan milik akun yang sesuai.

Koleksi: [cart/checkout](../postman/cart-checkout.postman_collection.json), [alamat](../postman/customer-addresses.postman_collection.json), [pembayaran Midtrans](../postman/midtrans-core-payments.postman_collection.json), dan [pergantian metode pembayaran](../postman/payment-method-switching.postman_collection.json).
