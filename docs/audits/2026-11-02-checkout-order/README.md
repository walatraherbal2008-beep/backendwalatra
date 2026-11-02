# Audit login sampai pembelian, 2 November 2026

## Kesimpulan

Alur dasar dapat menyelesaikan pembelian ketika stok, ongkir, dan pembayaran disimulasikan. Alur dengan SDK Midtrans sebenarnya memiliki perbedaan nominal pembayaran saat ongkir bersih lebih dari nol. Ada pula masalah transisi status, penanganan request yang beririsan, dan cart yang berisi barang terhapus.

Belum dilakukan pembayaran eksternal atau pemesanan ke gudang sebenarnya. Audit ini menambahkan laporan, bukti HTTP yang sudah disensor, dan pengujian reproduksi; tidak mengubah implementasi aplikasi.

## Metode dan batasan

- HTTP langsung pada aplikasi lokal dengan database PostgreSQL yang sudah ada: login akun baru, profil, alamat, katalog, cart kosong, order tanpa idempotency key, penolakan listing tidak tersedia, logout, pencabutan token, dan login ulang akun yang sama.
- `OTP_EXPOSE_CODE=true` digunakan untuk membaca kode dari respons. Pada proses server audit saja, `MAXCHAT_ENABLED=false` dan `QUEUE_CONNECTION=sync` diterapkan supaya nomor simulasi tidak menerima pesan WhatsApp dan tidak ada job OTP tertinggal. File `.env` tidak diubah. Pengiriman WhatsApp dan perilaku queue production tidak termasuk hasil lulus.
- Database lokal awal memiliki 7 user, 16 produk, 0 listing produk, 0 listing bundle, dan 0 order. Integrasi stok dan ongkir nonaktif; server key Midtrans belum tersedia. Ini adalah hambatan konfigurasi/data, terpisah dari cacat implementasi.
- Akun audit baru dibuat melalui verifikasi OTP, bukan melalui insert user manual. Login ulang membuktikan `is_new_user=false` dan profil tetap lengkap. Token kedua sesi dicabut. Akun beserta alamat audit dibersihkan setelah pemeriksaan.
- Pengujian tambahan memakai database PostgreSQL terpisah `walatra_checkout_audit_20261102_codex`. Tidak ada migrate, seeder, atau perubahan katalog pada database pengguna.
- Penyedia stok, ongkir, dan pembayaran dipalsukan hanya pada pengujian terisolasi. Pengujian nominal menggunakan `MidtransPaymentGateway` dan SDK Midtrans yang terpasang; hanya transport HTTP SDK yang digantikan melalui fasilitas stub bawaan SDK.
- Kasus request beririsan memakai callback pada batas operasi provider untuk menyusun urutan dua request secara deterministik. Ini membuktikan jadwal eksekusi yang bermasalah, bukan uji beban atau proses HTTP paralel sungguhan. `RefreshDatabase` membungkus test dalam transaksi; efektivitas penguncian antar koneksi terpisah belum diuji.
- Vibium digunakan untuk memeriksa halaman root backend. Halaman tersebut berupa landing page, bukan UI pembelian customer. Aplikasi Flutter atau frontend di repository lain tidak diaudit.

## Hasil pengujian

| Pemeriksaan | Hasil |
|---|---|
| Suite yang sudah ada, SQLite | 178 test, 795 assertion, seluruhnya lulus |
| Suite yang sudah ada, PostgreSQL terpisah | 178 test, 793 assertion, 1 gagal pada pencarian kategori produk |
| Pengujian audit tambahan, PostgreSQL terpisah | 19 test, 87 assertion; 6 lulus, 13 gagal terhadap perilaku yang diharapkan |
| HTTP lokal: login baru dan lama, profil, alamat, OTP replay, logout | Lulus |
| Pembelian nyata pada konfigurasi lokal | Terhenti: belum ada listing dan integrasi transaksi belum siap |

Enam test audit yang lulus mencakup alur pembelian dengan provider simulasi, penolakan nominal hasil SDK oleh webhook, penolakan signature palsu, isolasi order antar customer, retry order berurutan, dan dokumentasi perilaku harga cart saat ini. Test dokumentasi menunjukkan perilaku yang ada, bukan persetujuan bahwa perilaku tersebut sudah benar secara bisnis.

## Temuan

### F01, P1: nominal pembayaran kehilangan ongkir

Lokasi: `app/Services/Payments/MidtransPaymentGateway.php:19` dan `vendor/midtrans/midtrans-php/Midtrans/Snap.php:94`.

Reproduksi: cart 2 × Rp15.000 dan ongkir bersih Rp10.000 menghasilkan order Rp40.000. Gateway memberikan `gross_amount=40000`, tetapi `item_details` hanya memuat barang Rp30.000. SDK menghitung ulang gross amount dari item details sehingga payload akhir menjadi Rp30.000. Webhook bertanda tangan valid untuk Rp30.000 kemudian ditolak dengan 422 karena order mengharapkan Rp40.000; status tetap `pending_payment`.

Perbaikan: masukkan ongkir bersih dalam rincian pembayaran dan pastikan jumlah seluruh rincian identik dengan total order sesudah pembulatan. Pertahankan pemeriksaan nominal webhook.

### F02, P1: capture berstatus fraud challenge atau tanpa verdict dianggap lunas

Lokasi: `app/Http/Controllers/Api/V1/PaymentController.php:85`.

Reproduksi: webhook `capture` dengan `fraud_status=challenge`, serta kasus verdict null, menghasilkan `paid`. Syarat yang dipakai hanya verdict tidak sama dengan `deny`, lalu reservasi dikonfirmasi.

Perbaikan: gunakan daftar kombinasi status pembayaran dan fraud yang secara eksplisit diterima; status yang masih membutuhkan pemeriksaan tidak boleh langsung memicu pemenuhan order. Dua test audit gagal untuk kasus ini.

### F03, P1: webhook lama dapat mengubah status akhir

Lokasi: `app/Http/Controllers/Api/V1/PaymentController.php:88`.

Reproduksi pertama: settlement → refund → settlement ulang mengubah `refunded` menjadi `paid`. Reproduksi kedua: order dibatalkan, reservasi dilepas, lalu settlement mengubah order menjadi `paid` tanpa reservasi stok.

Perbaikan: definisikan transisi status yang sah. Pembayaran terlambat setelah pembatalan membutuhkan rekonsiliasi stok/pembayaran; jangan langsung menganggap barang siap dipenuhi. Dua test audit gagal.

### F04, P1: pembatalan dapat menimpa pembayaran yang berhasil

Lokasi: `app/Http/Controllers/Api/V1/OrderController.php:56` dan `app/Http/Controllers/Api/V1/PaymentController.php:73`.

Reproduksi interleaving: cancel membaca `pending_payment` → saat operasi release, settlement diproses menjadi `paid` → cancel melanjutkan update menjadi `cancelled`. Status lunas hilang. `lockForUpdate()` pada webhook berada sebelum transaksi di `transition()`, sehingga dalam request biasa tanpa transaksi luar, lock tidak melindungi keseluruhan alur.

Perbaikan: serialisasikan operasi per order, periksa ulang status di bagian yang terlindungi, dan rancang koordinasi perubahan database dengan confirm/release eksternal agar dapat diulang dengan aman. Memindahkan panggilan HTTP ke transaksi saja belum menyelesaikan kegagalan parsial antar sistem.

### F05, P1: isi cart yang sama dapat menjadi dua order

Lokasi: `app/Http/Controllers/Api/V1/OrderController.php:145` sampai pembuatan order dan penghapusan cart.

Reproduksi interleaving: request A membaca cart → pada reserve A, request B membaca cart yang sama dan menyelesaikan order dengan key berbeda → A melanjutkan menggunakan item yang telah dimuat. Hasil: dua order dan dua reservasi untuk isi cart yang sama.

Perbaikan: lakukan claim/versi checkout atas cart secara atomik dan validasi kepemilikan snapshot sebelum reservasi/pembuatan order. Jangan hanya mengandalkan penghapusan cart di akhir.

### F06, P2: idempotency key belum menangani request beririsan

Lokasi: `app/Http/Controllers/Api/V1/OrderController.php:103`, `:197`, dan blok catch setelah transaksi.

Retry berurutan sudah benar. Namun, dua request dengan key sama dapat sama-sama melewati pemeriksaan awal dan melakukan reserve. Request yang kalah insert terkena unique violation PostgreSQL dan mengembalikan 500. Constraint mencegah order duplikat untuk key sama, tetapi belum memberikan respons idempotent.

Perbaikan: serialisasikan request dengan key sama atau tangani konflik unique secara spesifik, lepaskan reservasi milik request yang kalah, lalu kembalikan order yang sudah dibuat. Penanganan kegagalan release juga perlu diperhitungkan.

### F07, P2: cart error 500 setelah listing atau varian dihapus

Lokasi: `app/Http/Resources/Api/V1/CartResource.php:23` dan `:34`.

Reproduksi: tambahkan barang, soft-delete listing atau varian, kemudian GET cart. Resource mengakses relasi null dan menghasilkan 500. Customer tidak dapat membaca cart untuk mengetahui barang mana yang perlu dihapus.

Perbaikan: tampilkan item tidak tersedia dengan data yang aman, atau bersihkan item dengan informasi yang jelas kepada customer. Dua test audit gagal.

### F08, P2: preview tidak memvalidasi alamat seperti create order

Lokasi: `app/Http/Controllers/Api/V1/CheckoutController.php:50`.

Reproduksi dengan provider simulasi: `shipping_address=[]` diterima dan preview mengembalikan 200 beserta ongkir. Preview menggunakan Request biasa, sementara create order mempunyai FormRequest dengan persyaratan alamat. Hasil preview bisa terlihat valid, tetapi order berikutnya ditolak.

Perbaikan: gunakan aturan alamat bersama untuk preview dan create order, sebelum memanggil provider ongkir. Penolakan oleh provider nyata belum diverifikasi.

### F09, P2: retry payment selalu membuat pembayaran lagi

Lokasi: `app/Http/Controllers/Api/V1/PaymentController.php:27`.

Reproduksi: dua POST payment untuk order pending yang sama memanggil gateway dua kali dan mengganti `payment_reference`. Provider simulasi menghasilkan dua token berbeda. Perilaku Midtrans nyata pada request kedua belum diuji; aplikasi saat ini tidak melakukan reuse atau recovery token sebelumnya.

Perbaikan: simpan status sesi pembayaran dan gunakan kembali sesi yang masih berlaku, termasuk menangani request beririsan dan hasil request yang tidak diketahui akibat timeout.

### F10, P2: exception transport pembayaran menjadi error 500

Lokasi: `app/Services/Payments/MidtransPaymentGateway.php:19` dan `app/Http/Controllers/Api/V1/PaymentController.php:27`.

Reproduksi: gateway melempar exception transport generik, sesuai bentuk exception dari SDK, dan endpoint menghasilkan 500. Handler 503 yang sudah tersedia hanya menangani `PaymentGatewayUnavailableException`, tetapi gateway nyata tidak membungkus exception SDK ke tipe tersebut.

Perbaikan: petakan kegagalan provider ke error API yang sesuai dan bedakan penolakan definitif dari hasil transaksi yang belum diketahui.

### F11, P2: pencarian kategori tidak konsisten pada PostgreSQL

Lokasi: `app/Http/Controllers/Api/V1/CatalogProductController.php:169`.

Suite lama gagal pada `CatalogQueryPerformanceTest::test_product_search_is_consistent_with_unified_catalog`, baris 43: pencarian `pencernaan x` tidak mengembalikan produk yang berada pada kategori `Pencernaan X`. Filter token mendukung kategori, tetapi filter tambahan khusus PostgreSQL hanya memeriksa nama/kode produk, nama toko, serta SKU. Kondisi tambahan itu dapat membuang hasil yang sudah cocok berdasarkan kategori/deskripsi.

Perbaikan: selaraskan cakupan filter PostgreSQL dengan pencarian token dan unified catalog; bedakan pemeringkatan dari syarat kelolosan hasil.

## Keputusan bisnis yang perlu ditetapkan

Harga disalin saat barang ditambahkan ke cart dan dipakai lagi saat order dibuat. Reproduksi: harga awal Rp15.000 berubah menjadi Rp25.000 setelah cart dibuat, tetapi order tetap memakai Rp15.000. Tentukan apakah harga cart memang dijamin; bila iya, perlu masa berlaku yang jelas. Bila tidak, preview dan create order perlu memvalidasi harga serta memberi tahu perubahan kepada customer. Temuan ini tidak dihitung sebagai bug pasti.

## Urutan perbaikan

1. F01: samakan nominal SDK pembayaran dengan order.
2. F02–F04: tetapkan transisi pembayaran, refund, pembatalan, dan koordinasi reservasi stok.
3. F05–F06 dan F09: cegah pembelian cart ganda serta buat retry order/payment aman.
4. F07–F08 dan F10: benahi cart tidak tersedia, validasi preview, dan error provider.
5. F11: selaraskan pencarian PostgreSQL.
6. Siapkan listing dan integrasi staging, lalu ulangi pembelian dengan gateway sandbox serta provider gudang/ongkir sebenarnya.

## Menjalankan reproduksi

File audit sengaja berada di luar suite reguler karena berisi ekspektasi yang saat ini gagal. Jangan menganggap hasil lulus suite reguler berarti temuan sudah diperbaiki. Jalankan hanya dengan database pengujian terpisah karena `RefreshDatabase` dapat menjalankan migration yang menghapus tabel pada database tujuan.

Contoh reproduksi aman menggunakan SQLite dalam memori:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
MAXCHAT_ENABLED=false MAXCHAT_API_TOKEN= WAREHOUSE_STOCK_ENABLED=false \
SHIPPING_RATE_ENABLED=false WAREHOUSE_DIRECTORY_ENABLED=false EVERPRO_ENABLED=false \
MIDTRANS_SERVER_KEY= \
vendor/bin/phpunit --no-progress \
docs/audits/2026-11-02-checkout-order/CheckoutOrderAuditTest.php
```

Untuk PostgreSQL, ganti `DB_CONNECTION` dan `DB_DATABASE` dengan database audit yang benar-benar terpisah. Bukti F11 ada pada suite reguler PostgreSQL, bukan pada reproduksi SQLite.

## Berkas bukti

- `http-evidence.json`: 16 langkah HTTP lokal, OTP dan token disensor.
- `existing-login.json`: ringkasan login ulang akun yang sama.
- `test-results.json`: hasil 19 test audit PostgreSQL dengan pesan kegagalan dipangkas.
- `baseline-output.txt`: hasil suite reguler SQLite.
- `baseline-postgres-output.txt`: hasil suite reguler PostgreSQL.
- `CheckoutOrderAuditTest.php`: reproduksi terisolasi tanpa request integrasi eksternal.
