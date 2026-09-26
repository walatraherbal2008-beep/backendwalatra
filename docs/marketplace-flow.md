# Alur marketplace Walatra

Marketplace mengikuti pola marketplace multi-toko seperti Shopee, tetapi domainnya dibatasi untuk ekosistem Walatra Herbal.

## Batasan bisnis

- products, product_variants, kategori, dan data BPOM adalah katalog inti pusat. Toko tidak boleh mengubah data tersebut.
- store_product_listings dan store_bundle_listings adalah etalase toko. Toko boleh mengatur status tayang, urutan, deskripsi, dan gambar etalase.
- Pemilik toko harus akun customer aktif, email terverifikasi, dan memiliki role store_admin. Akun seller merepresentasikan member Walatra, bukan administrator platform.
- Produk hanya tampil jika produk master, varian, listing, dan toko semuanya aktif. Listing juga tidak boleh memindahkan toko atau produk master setelah dibuat.

## Stok

Stok tidak disimpan sebagai angka lokal di katalog. API gudang akan menjadi sumber kebenaran stok ketika integrasi tersedia. Flow checkout yang akan dibangun harus:

1. meminta availability berdasarkan SKU dan kuantitas ke API gudang;
2. membuat reservasi stok sebelum pembayaran;
3. mengonfirmasi atau melepas reservasi sesuai hasil pembayaran;
4. menganggap respons gudang sebagai snapshot yang dapat berubah, bukan stok permanen pada produk master.

Sebelum API gudang siap, katalog boleh dipakai untuk browsing dan pengelolaan etalase, tetapi checkout tidak boleh mengurangi stok dari database lokal.

## Urutan flow aplikasi

login member -> katalog -> pilih toko/etalase -> pilih varian -> cart -> validasi availability gudang -> alamat dan ongkir -> pembayaran -> order -> status pengiriman.

Pembayaran, pengiriman, dan chat/ulasan belum menjadi domain yang tersedia di project ini; semuanya perlu ditambahkan sebelum disebut setara marketplace transaksional seperti Shopee.

Fondasi API transaksi yang sudah tersedia:

- GET /api/v1/cart
- POST /api/v1/cart/items
- PATCH /api/v1/cart/items/{id}
- DELETE /api/v1/cart/items/{id}
- POST /api/v1/checkout/preview
- POST /api/v1/orders
- GET /api/v1/orders
- GET /api/v1/orders/{id}
- POST /api/v1/orders/{id}/cancel

Order dibuat berstatus pending_payment, menyimpan snapshot harga dan item,
serta wajib menggunakan header Idempotency-Key. Pembayaran dan adapter API
gudang nyata belum dikonfigurasi; provider default sengaja mengembalikan 503,
sehingga sistem tidak menjual tanpa validasi stok.

Kontrak awal API gudang yang digunakan provider HTTP:

- POST /stock/check dengan body { items: [{ sku, quantity }] }
- POST /stock/reserve dengan body { reference, items: [{ sku, quantity }] }
- POST /stock/confirm dengan body { reservation_id }
- POST /stock/release dengan body { reservation_id }

Respons check diharapkan memiliki data.available dan data.items. Respons
reserve diharapkan memiliki data.reservation_id. Path dapat diubah melalui
environment variable karena API gudang masih dalam pengembangan.
