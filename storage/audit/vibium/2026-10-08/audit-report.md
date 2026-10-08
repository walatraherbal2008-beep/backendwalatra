# Laporan Audit Pengujian Vibium

Tanggal: 2026-10-08  
Environment: `http://127.0.0.1:8000`  
Tool: Vibium `v26.8.21`, mode headless  
Browser: Chrome for Testing `153.0.8010.36`

## Ringkasan

- PASS: 27 skenario
- BLOCKED: 2 skenario
- FAIL: 0 skenario
- Penyebab blocked: data owner toko aktif dan assignment gudang varian belum tersedia pada database.

## Audit akun admin/superadmin

| ID | Skenario | Hasil | Catatan |
|---|---|---|---|
| ADM-001 | Login dengan akun admin | PASS | `admin@mail.com` berhasil masuk ke `/superadmin`. |
| ADM-002 | Akses dashboard superadmin | PASS | Menu utama tampil normal. |
| ADM-003 | Akses manajemen akun seller | PASS | `/superadmin/store-admins` dapat dibuka dan data seller tampil. |
| ADM-004 | Akses manajemen toko | PASS | `/superadmin/stores` dapat dibuka. |
| ADM-005 | Akses manajemen produk | PASS | `/superadmin/products` dapat dibuka dan daftar produk tampil. |
| ADM-006 | Akses manajemen kategori | PASS | `/superadmin/categories` dapat dibuka. |
| ADM-007 | Akses manajemen bundle | PASS | `/superadmin/product-bundles` dapat dibuka. |
| ADM-008 | Superadmin membuka panel admin toko | PASS | `/admin` mengembalikan `403 Forbidden`, sesuai isolasi role. |
| ADM-009 | Logout | PASS | Session diakhiri dan akses berikutnya diarahkan ke `/superadmin/login`. |

## Hasil pengujian

| ID | Skenario | Hasil | Catatan |
|---|---|---|---|
| SM-001 | Membuka halaman login admin | PASS | Form email, password, remember me, dan tombol masuk terbaca Vibium. |
| SM-002 | Login superadmin | PASS | Berhasil diarahkan ke `/superadmin`; menu dashboard, akun seller, toko, produk, kategori, dan bundle tampil. |
| CP-001 | Membuka dashboard superadmin | PASS | Dashboard dapat dibaca dan elemen navigasi tampil normal. |
| CP-002 | Membuka daftar produk master | PASS | `/superadmin/products` menampilkan daftar produk aktif beserta jumlah varian. |
| CP-003 | Membuka halaman edit produk | PASS | Form produk dapat dibuka; halaman relation manager varian tersedia pada resource. |
| ST-001 | Login panel admin toko | BLOCKED | Akun superadmin tidak memiliki akses panel admin toko; data owner toko aktif belum tersedia. |
| ST-002 | Memilih gudang pada listing toko | BLOCKED | Database belum memiliki assignment `warehouse_product_variants`, sehingga pilihan gudang belum dapat diuji secara end-to-end. |
| SM-004 | Katalog produk publik tanpa login | PASS | `/api/v1/catalog/products` mengembalikan 5 produk aktif dengan `success: true`. |
| SM-005 | Daftar gudang publik | PASS | `/api/v1/warehouses` mengembalikan 37 gudang dengan kode eksternal. |

## Bukti screenshot

- `screenshots/SM-001-admin-login.png`
- `screenshots/SM-002-login-result.png`
- `screenshots/CP-001-superadmin-dashboard.png`
- `screenshots/CP-002-superadmin-dashboard.png`
- `screenshots/CP-003-master-products.png`
- `screenshots/CP-004-product-variant-edit.png`
- `screenshots/SM-004-public-catalog.png`
- `screenshots/SM-005-public-warehouses.png`
- `screenshots/ADM-001-login.png`
- `screenshots/ADM-002-resource-access.png`
- `screenshots/ADM-003-logout.png`

## Temuan audit

1. Vibium, daemon, dan browser dapat digunakan normal setelah perbaikan IPv6 loopback.
2. Login superadmin dan navigasi resource katalog berjalan.
3. Katalog produk publik sudah dapat diakses tanpa login.
4. API daftar gudang publik berhasil mengambil data dari external warehouse API.
5. Pengujian panel admin toko belum dapat dinyatakan lulus karena data owner toko aktif belum dikaitkan.
6. Pengujian pilihan gudang listing belum dapat dinyatakan lulus karena belum ada assignment gudang pada varian produk.

## Pengujian input Superadmin dengan Vibium

Suffix data uji: `tesvibiumsuperadmin`

| ID | Skenario | Hasil | Data hasil |
|---|---|---|---|
| ADM-TEST-001 | Membuka daftar akun seller | PASS | Halaman daftar seller tampil. |
| ADM-TEST-002 | Membuat akun seller | PASS | `Seller Tes Vibium Superadmin`, `seller.tesvibiumsuperadmin@example.com`. |
| ADM-TEST-003 | Memverifikasi email seller | PASS | Status seller berubah menjadi terverifikasi. |
| ADM-TEST-004 | Membuat toko dan memilih owner | PASS | `Toko Tes Vibium Superadmin`, kode `TOKO-VVCNNGEC`. |
| ADM-TEST-005 | Mengisi profil dan alamat toko | PASS | Data kota, alamat, kontak, latitude, dan longitude tersimpan. |
| ADM-TEST-006 | Menyetujui toko | PASS | Status toko berubah menjadi `active`. |
| ADM-TEST-007 | Membuat kategori | PASS | `Kategori Tes Vibium Superadmin` berhasil dibuat. |
| ADM-TEST-008 | Membuat produk master | PASS | `Produk Tes Vibium Superadmin`, kode `PRD-WAL-STNNNHIL`. |
| ADM-TEST-009 | Membuat varian produk | PASS | `Varian Tes Vibium Superadmin`, harga Rp125.000, berat 250 gram, status aktif. |
| ADM-TEST-010 | Mengaktifkan produk | PASS | Produk berhasil berubah menjadi `active`. |
| ADM-TEST-011 | Membuat bundle | PASS | `Bundle Tes Vibium Superadmin`, kode `BND-UZ42JF6F`, satu komponen varian. |
| ADM-TEST-012 | Mengecek katalog publik | PASS | Produk/bundle belum tampil karena belum memiliki listing toko; sesuai aturan katalog. |

### Bukti input Superadmin

- `screenshots/ADM-TEST-001-seller-list.png`
- `screenshots/ADM-TEST-002-seller-created.png`
- `screenshots/ADM-TEST-003-store-created.png`
- `screenshots/ADM-TEST-004-store-approved.png`
- `screenshots/ADM-TEST-005-category-created.png`
- `screenshots/ADM-TEST-006-product-created.png`
- `screenshots/ADM-TEST-007-variant-created.png`
- `screenshots/ADM-TEST-008-product-activated.png`
- `screenshots/ADM-TEST-009-bundle-created.png`
- `screenshots/ADM-TEST-010-public-catalog.png`

### Catatan

- Assignment gudang pada varian belum dapat diinput melalui UI Superadmin karena relation manager varian saat ini belum menyediakan field gudang.
- Produk dan bundle baru dapat tampil di katalog setelah admin toko membuat listing etalase.
- Data uji sengaja dipertahankan untuk pengujian lanjutan admin toko.

## Tindak lanjut sebelum retest

1. Kaitkan user dengan role `store_admin` dan toko aktif melalui panel superadmin.
2. Tambahkan assignment kode gudang pada seluruh varian produk uji.
3. Jalankan ulang skenario ST-001 dan ST-002 menggunakan akun admin toko.
4. Dokumentasikan screenshot pilihan gudang, penyimpanan listing, edit listing, dan penghapusan assignment.
