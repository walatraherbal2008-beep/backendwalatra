# Laporan Simulasi Admin Toko via Vibium

Tanggal: 2026-10-09  
Metode: Simulasi browser Vibium dan verifikasi API/database  
Suffix data: `tesvibiumadmintoko`

## Hasil akhir

- Akun calon admin toko: `admin.tesvibiumadmintoko@example.com`.
- Toko: **Admin Toko Tes Vibium Admin Toko**.
- Kode toko: `TOKO-AKQKWPQX`.
- Status toko: `active` dan eligible untuk marketplace.
- Produk aktif di etalase: **16**.
- Bundle aktif di etalase: **3**.
- Endpoint publik produk mengembalikan total **16** listing.
- Endpoint publik bundle mengembalikan total **3** listing.
- Seluruh listing aktif memiliki satu gambar etalase.

## Flow yang diuji

1. Login member Walatra melalui OTP menggunakan endpoint customer.
2. Submit pendaftaran toko mandiri melalui `POST /api/v1/store-onboarding`.
3. Verifikasi email melalui signed URL.
4. Superadmin menyetujui toko dari panel `/superadmin/stores`.
5. Login ke panel admin toko `/admin`.
6. Membuat listing aktif dari seluruh produk master pusat, satu per satu, dengan gambar etalase.
7. Membuat tiga listing bundle aktif dari bundle master yang sudah tersedia.
8. Menguji publikasi melalui endpoint katalog tanpa login.

## Produk yang dipublikasikan

Albucare, Bio Squalene, Etaku Goat Milk, Garlic, Hexabumin, Kapsul Gamat Emas, Kapsul Oil Fit, Kapsul Sehat Lambungku, Keloreena, Kurmaqu GMJ, Propolis SM, Proskin, QNC Jelly Gamat, Radimax, Sehat Wanita, dan Sendifit.

Setiap listing dibuat `active`, diberi urutan tampil 1–16, dan diberi satu gambar etalase dari `docs/api/fotoproduk/`.

## Bundle yang dipublikasikan

| Listing | Bundle master | Status | Urutan |
|---:|---|---|---:|
| 7 | Paket Herbal Keluarga | active | 1 |
| 8 | Paket Herbal Sendi dan Otot | active | 2 |
| 9 | Paket Herbal Daya Tahan | active | 3 |

Bundle dibuat dari master bundle yang telah memiliki isi dan harga. Gambar etalase menggunakan artwork produk terkait karena belum tersedia aset gambar bundle khusus.

## Verifikasi publik

Request Vibium ke:

```text
GET /api/v1/catalog/products?store=admin-toko-tes-vibium-admin-toko&per_page=50
```

Hasil: `success: true`, `total: 16`.

```text
GET /api/v1/catalog/bundles?store=admin-toko-tes-vibium-admin-toko&per_page=50
```

Hasil: `success: true`, `total: 3`.

## Temuan audit

1. **Bug notifikasi pendaftaran:** endpoint pendaftaran awal mengembalikan HTTP 500 setelah data akun dan toko tersimpan karena `VerifyStoreAdminEmailNotification` mengakses `name` pada `AnonymousNotifiable`. Bug diperbaiki dengan greeting generik untuk notifikasi on-demand. Test `StoreOnboarding` kembali lulus 4/4.
2. **Risiko partial success:** sebelum perbaikan, kegagalan pengiriman email terjadi setelah transaksi toko selesai. API sebaiknya memakai notifikasi queued/outbox atau menangani kegagalan delivery agar respons tidak tampak gagal ketika data sudah tersimpan.
3. **Flow persetujuan masih membutuhkan Superadmin:** setelah email diverifikasi, toko tetap `pending_approval` sampai disetujui dari panel Superadmin. Ini sesuai kontrol bisnis yang direncanakan.
4. **Flow listing menggunakan upload asinkron:** Vibium harus menunggu status `Pengunggahan selesai` sebelum tombol Buat ditekan. Tanpa menunggu, listing dapat gagal menyimpan gambar.
5. **Aset bundle belum khusus:** tiga bundle aktif memakai gambar produk terkait sebagai placeholder etalase. Perlu aset bundle khusus untuk kualitas publikasi.
6. **Gudang tidak dipilih:** opsi gudang dibiarkan kosong karena assignment gudang produk belum dikonfigurasi. Listing tetap dapat dipublikasikan, tetapi ketersediaan stok/pemenuhan order masih bergantung pada integrasi gudang.

## Bukti

- `product-listings.png`: daftar 16 produk toko.
- `bundle-listings.png`: daftar bundle toko.
- `public-bundles-api.png`: respons katalog bundle publik.

## Kesimpulan

Flow bisnis utama berhasil sampai publikasi publik: calon admin menjadi admin toko, toko aktif, seluruh produk pusat masuk etalase, dan tiga bundle tampil pada katalog publik. Temuan paling penting adalah bug notifikasi onboarding yang sudah diperbaiki dan risiko partial success yang perlu diperkuat pada tahap berikutnya.
