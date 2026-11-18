# Stok produk dan pemilihan gudang terdekat

Fitur ini memilih **satu gudang terdekat dari alamat pengiriman yang cukup untuk seluruh barang terpilih**. Jarak dihitung dengan Haversine (garis lurus), bukan jarak perjalanan kendaraan. Semua request customer memakai Bearer token Sanctum dengan ability `customer-api` dan akun aktif.

## 1. Master gudang dan aktivasi

Jalankan migration, lalu sinkronkan master:

```bash
php artisan migrate
php artisan warehouses:sync
```

Sinkronisasi mengambil daftar `gudang.list` dan detail `gudang.detail`. Koordinat `latitude, longitude` diubah menjadi kolom numerik pada tabel `warehouses`. Kode gudang selalu string, termasuk kode yang seluruh karakternya angka. Status aktif lokal dipertahankan saat sinkronisasi. Gudang yang hilang dari snapshot lengkap ditandai tidak terdaftar; kegagalan daftar/detail tidak memublikasikan snapshot sebagian. Daftar kosong atau kode duplikat dianggap gagal dan tidak mengganti data terakhir.

Koordinat kosong atau tidak valid disimpan untuk ditinjau tetapi tidak dipakai untuk pemilihan otomatis. Scheduler menjalankan sinkronisasi setiap jam apabila integrasi direktori aktif. Pastikan scheduler Laravel berjalan. Sinkronisasi menggunakan lock bersama; gunakan Redis sebagai cache pada deployment dengan beberapa proses/server.

Di **Superadmin → Pengiriman → Titik Pengiriman**, gunakan **Sinkronkan gudang** untuk memperbarui master. Setelah master lokal tersedia, daftar, waktu sinkronisasi, detail dan tautan peta dibaca dari database lokal. Aksi Aktifkan/Nonaktifkan mengatur kelayakan lokal dan tidak mengubah stok. Sebelum master tersedia, mode legacy tetap dapat menampilkan direktori eksternal; mode terdekat menampilkan daftar lokal kosong dan tidak menulis data saat halaman dibuka.

Aktifkan melalui environment setelah memeriksa hasil sinkronisasi dan kecocokan kode produk:

```dotenv
WAREHOUSE_DIRECTORY_ENABLED=true
WAREHOUSE_REMAINING_STOCK_ENABLED=true
WAREHOUSE_REMAINING_STOCK_BASE_URL=https://originalwalatraherbal.com
WAREHOUSE_REMAINING_STOCK_PATH=/index.php?procs=gudang.stok.sisa
WAREHOUSE_REMAINING_STOCK_TIMEOUT=3
CHECKOUT_WAREHOUSE_SELECTION=nearest_available
CHECKOUT_WAREHOUSE_SELECTION_TIMEOUT=15
CHECKOUT_STOCK_MODE=check_only
FREE_SHIPPING_ENABLED=true
```

```bash
php artisan config:clear
```

Konfigurasi default source tetap `CHECKOUT_WAREHOUSE_SELECTION=legacy` dan API sisa stok nonaktif. Mode terdekat wajib dipasangkan dengan `check_only` serta gratis ongkir. Pasangan konfigurasi yang tidak cocok menolak checkout dengan 503; tidak diam-diam melewati pengecekan. Untuk kembali ke alur lama, gunakan `legacy` dan mode stok sebelumnya (`unlimited` atau `external`). Konfigurasi mode hanya berlaku pada order baru; snapshot order lama tetap disimpan.

`CHECKOUT_WAREHOUSE_CODE` tidak mengalahkan pencarian jarak dalam mode terdekat. Penetapan gudang per varian tetap wajib: kandidat adalah irisan gudang seluruh varian, termasuk seluruh komponen bundle, yang aktif lokal, masih terdaftar di API, serta memiliki koordinat valid.

## 2. Cek stok satu produk pada gudang tertentu

```http
POST /api/v1/warehouses/5504027954349/stock
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json

{"product_code":"QNC","quantity":2}
```

`product_code` harus cocok dengan master produk aktif (`products.product_code`), bukan SKU varian atau ID listing. `quantity` wajib integer positif, maksimal 2.147.483.647. Gudang harus ada, aktif lokal dan masih terdaftar. Endpoint dibatasi 30 request per menit per customer. Pengecekan langsung ini bersifat informatif dan tidak menggantikan validasi penetapan gudang di checkout.

Contoh respons berikut bersifat ilustratif; jumlah aktual selalu dibaca dari upstream:

```json
{
  "success": true,
  "message": "Sisa stok berhasil diperiksa. Stok belum direservasi.",
  "data": {
    "warehouse_code": "5504027954349",
    "product_code": "QNC",
    "requested_quantity": 2,
    "available_quantity": 1220,
    "sufficient": true,
    "reserved": false,
    "checked_at": "2026-11-18T13:00:00+07:00"
  }
}
```

Backend mengirim multipart form-data `code=<kode gudang>` dan `produk=<kode produk>` ke `gudang.stok.sisa`. Respons harus HTTP 2xx, `error` berupa string kosong, dan `data.jumlah` integer nonnegatif atau string integer yang valid. Nol berarti stok kosong. Timeout, error bisnis upstream, JSON rusak, jumlah negatif/pecahan/boolean, atau format yang tidak lengkap menghasilkan `503 WAREHOUSE_UNAVAILABLE`, bukan stok nol. Aktivitas request dicatat melalui logger API eksternal.

## 3. Preview checkout

Gunakan endpoint yang sudah ada, `POST /api/v1/checkout/preview`, dengan `cart_item_ids` opsional dan salah satu dari `address_id` milik customer atau `shipping_address`. Alamat wajib memiliki **kedua koordinat** dalam mode terdekat:

```json
{
  "cart_item_ids": [301, 302],
  "shipping_address": {
    "name": "Nama penerima",
    "phone": "628123456789",
    "address": "Alamat pengiriman",
    "province": "Jawa Barat",
    "city": "Bandung",
    "postal_code": "40135",
    "latitude": -6.9,
    "longitude": 107.6
  }
}
```

ID contoh harus diganti dengan ID cart milik customer. Frontend harus meminta pembeli mengonfirmasi titik **alamat pengiriman**; lokasi perangkat tidak otomatis menjadi alamat tujuan. Koordinat yang belum lengkap menghasilkan 422, sehingga frontend dapat meminta pengguna melengkapi titik alamat.

Kebutuhan stok dijumlahkan berdasarkan kode produk di seluruh listing/varian dan komponen bundle. Dua QNC satuan dan dua bundle berisi masing-masing tiga QNC membutuhkan delapan QNC. Satu unit varian memakai satu unit stok produk; tidak ada faktor konversi kemasan pada versi ini.

Kandidat diurutkan menurut jarak tanpa pembulatan. Jarak sama memakai urutan kode gudang yang stabil. Gudang pertama diperiksa sampai ditemukan kekurangan atau seluruh produk mencukupi. Jika kurang, lanjut ke kandidat berikutnya. Jika status stok sebuah kandidat tidak dapat dipastikan, proses berhenti dengan 503, bukan melewati kandidat tersebut lalu mengklaim gudang lain sebagai terdekat.

Pemeriksaan dibatasi maksimal tiga detik per request upstream dan maksimal 15 detik untuk seluruh pencarian stok. Satu pasangan gudang–produk hanya diperiksa sekali dalam pencarian karena kebutuhan sudah digabung. Tidak ada cache stok lintas request.

Preview berhasil mempertahankan field lama dan menambahkan:

- `stock_mode: "check_only"`, `stock_verification: "check_only"`.
- `shipping_origin.distance_km`, `latitude`, `longitude`, dan `selection_method: "nearest_available"`.
- `stock_check`: kode gudang, `available: true`, `reserved: false`, waktu pengecekan, dan `items[]` dengan kode produk, jumlah diminta, jumlah tersedia, serta `sufficient`.

Jarak respons dibulatkan hingga tiga desimal kilometer. Token hanya diterbitkan setelah pengecekan berhasil dan quote checkout tersedia. Jika tidak ada gudang yang cukup, tidak ada token/order yang dibuat.

| HTTP / code | Arti dan tindakan frontend |
|---|---|
| 422 `VALIDATION_ERROR` | Lengkapi atau perbaiki alamat/koordinat/masukan. |
| 409 `WAREHOUSE_NOT_ASSIGNED` | Ada varian tanpa penetapan gudang; hubungi admin. |
| 409 `WAREHOUSE_MISMATCH` | Barang tidak memiliki satu gudang bersama; ubah pilihan barang. |
| 409 `WAREHOUSE_NOT_AVAILABLE` | Tidak ada kandidat aktif dengan koordinat valid. |
| 409 `STOCK_UNAVAILABLE` | Seluruh kandidat sudah terbukti tidak cukup. `data.warehouses[].items` berisi hasil yang sempat diperiksa sampai kekurangan pertama, bukan inventaris lengkap. |
| 503 `WAREHOUSE_UNAVAILABLE` | Pemeriksaan stok belum berhasil atau konfigurasi tidak cocok; jangan tampilkan sebagai stok nol. |

## 4. Membuat order dan perubahan setelah preview

Tetap gunakan `POST /api/v1/orders` dengan pilihan cart/alamat yang sama, `checkout_token`, serta header `Idempotency-Key`. Backend membaca ulang stok dan memilih ulang kandidat berdasarkan data terbaru.

Token mengikat alamat, pilihan barang, jumlah, harga, kebutuhan per kode produk, komposisi bundle, kode gudang dan ongkir. Jumlah stok tersedia dan waktu pengecekan tidak masuk fingerprint karena berubah setiap saat. Jika gudang, alamat, harga, jumlah atau komposisi bundle berubah, respons `409 CHECKOUT_CHANGED` meminta preview dan konfirmasi ulang. Jika stok sudah tidak cukup di semua kandidat, respons `409 STOCK_UNAVAILABLE`. Kegagalan mempertahankan cart dan tidak menyimpan order baru.

Order menyimpan `shipping_origin`, `stock_mode` dan snapshot `stock_check` hasil pemeriksaan terakhir. `stock_reservation_id` tetap kosong. Pembayaran/pembatalan tidak memanggil reserve/confirm/release untuk order check-only. Retry idempotent order yang sudah berhasil mengembalikan snapshot order sebelumnya tanpa memeriksa stok ulang atau mengganti gudang.

Frontend dapat menampilkan nama gudang, jarak, hasil cek stok dan pesan **stok belum direservasi** dari respons tersebut. Perubahan alamat atau pilihan barang memerlukan preview baru. Integrasi UI pembeli menggunakan kontrak API ini; perhitungan tarif berbayar berdasarkan gudang asal tidak ditambahkan pada versi ini.

## 5. Batas jaminan stok

API ini hanya membaca sisa stok. Pembeli lain, kanal penjualan lain atau proses gudang dapat mengubah stok setelah pengecekan. Mode check-only **tidak menjamin bebas overselling**, tidak menahan stok untuk order pending, dan tidak mengurangi stok saat pembayaran lunas. Jaminan tersebut memerlukan kontrak reservasi/pengurangan stok atomik dari sistem gudang; fitur tidak memalsukan operasi tersebut.
