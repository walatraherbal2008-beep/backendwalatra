# Laporan perapian perubahan lokal dan audit akhir

Tanggal: **18 November 2026**. Repository: Backend Walatra, branch `master`.

**Hasil:** pekerjaan nomor 1 selesai. Perubahan lokal ditinjau, masalah validasi katalog diperbaiki, pengujian ditambah, panduan deployment dilengkapi, dan 58 file disimpan dalam tiga commit lokal. Audit setelah commit memastikan daftar file sesuai manifest, isi file working tree sesuai commit, serta dependency terpasang sesuai versi lockfile. Tidak ada temuan penghambat tambahan dalam lingkup pemeriksaan ini; batas verifikasi dicantumkan di bawah.

## 1. Kondisi awal dan pekerjaan yang dilakukan

Awalnya terdapat 57 file berubah, mencakup keamanan OTP/bridge, webhook, log eksternal, model dan seeder katalog, dependency, aset Filament, serta tes banner. Sebelum mengedit, patch perubahan tracked dan daftar file untracked disimpan di `/tmp/walatra-cleanup-2026-11-18/`. Salinan ini bersifat sementara; bukti audit yang dipertahankan tersedia dalam folder `evidence/` laporan ini.

Tes awal menghasilkan **407 tes lulus dan 3.071 assertions**. Hasil ini dipakai sebagai patokan, kemudian diperluas untuk menguji kasus yang belum tercakup.

| Commit | Kelompok | Jumlah file |
|---|---|---:|
| `6d1c5bd` | Lockfile dependency, aset Filament, dan penyesuaian tes banner | 36 |
| `52584a8` | OTP bridge, webhook, sanitasi/retensi log, pemeriksaan production, tes dan dokumentasi | 17 |
| `deb3ec4` | Validasi ketersediaan listing, SKU/seeder katalog, dan tes regresi | 5 |

Manifest lengkap tersedia pada [commit-groups.json](evidence/commit-groups.json); SHA lengkap tersedia pada [commits.json](evidence/commits.json). Commit ini lokal dan belum dipush.

Perubahan dependency yang sudah ada dipertahankan dan diperiksa: 86 paket diperbarui dan tiga paket ditambahkan. Di antaranya Laravel `12.69.1` menjadi `12.72.0` dan Filament `5.7.8` menjadi `5.11.4`. Daftar lengkap tersedia pada [dependency-changes.json](evidence/dependency-changes.json). Tidak dilakukan pembaruan dependency tambahan dalam pekerjaan ini.

## 2. Temuan dan perbaikan sebelum audit akhir

### A. Status relasi lama dapat meloloskan aktivasi listing

**Prioritas: tinggi untuk integritas katalog. Status: diperbaiki.**

Optimasi lokal sebelumnya menggunakan relasi produk, varian, bundle, komponen, dan toko yang sudah dimuat di memori. Jika data tersebut berubah di database, validasi dapat tetap memakai status lama dan mengaktifkan listing yang seharusnya ditolak.

Masalah direproduksi melalui sembilan kasus: produk nonaktif, varian nonaktif, toko nonaktif, produk dihapus, bundle nonaktif, varian komponen nonaktif, produk komponen nonaktif, toko bundle nonaktif, dan komponen terakhir dihapus. Kesembilan tes gagal sebelum perbaikan karena exception validasi tidak dilempar. Bukti: [stale-relations-red.txt](evidence/stale-relations-red.txt).

`StoreProductListing` dan `StoreBundleListing` kini memeriksa kondisi database melalui query `exists`, termasuk relasi yang sudah dihapus secara soft delete. Validasi tidak mengandalkan koleksi relasi dalam memori. Tes juga memastikan aktivasi valid memakai dua query pemeriksaan tanpa memuat seluruh objek relasi. Seluruh 19 tes pada `ProductAvailabilityTest` lulus setelah perbaikan: [stale-relations-green.txt](evidence/stale-relations-green.txt).

Pemeriksaan ini tidak menambahkan penguncian lintas transaksi. Tes konkurensi PostgreSQL tetap merupakan verifikasi terpisah.

### B. Panduan deployment belum mencakup syarat boot production

**Prioritas: sedang untuk deployment. Status: diperbaiki.**

Pemeriksaan production yang baru mensyaratkan secure cookie dan secret bridge minimal 32 karakter. Panduan first run sebelumnya belum mencantumkan kedua nilai tersebut. Panduan kini menjelaskan konfigurasi wajib, penggunaan `.env.production.example` sebagai pelengkap, format signature bridge, toleransi waktu, penyimpanan secret pada proses server, serta perintah peninjauan retensi/sanitasi log.

Tes pemeriksaan production diperluas untuk menguji debug, exposure OTP, cookie tidak aman atau tidak ditentukan, secret kosong atau terlalu pendek, konfigurasi aman, dan lingkungan lokal.

### C. Cakupan tes keamanan diperluas

**Status: ditambahkan dan lulus.**

Tes bridge kini mencakup timestamp terlalu jauh di masa depan, method berbeda, IP/timestamp/signature tidak valid, dan header kosong. Tes webhook memastikan signature palsu tidak menghasilkan log database, inbox notifikasi, maupun job. Tes retensi memastikan nilai tidak valid tidak menghapus log.

## 3. Hasil verifikasi

| Pemeriksaan | Hasil | Bukti |
|---|---|---|
| Patokan awal, suite default | 407 tes lulus; 3.071 assertions | [baseline-tests.txt](evidence/baseline-tests.txt) |
| Suite default setelah perbaikan | **425 tes lulus; 3.104 assertions; 0 gagal/error/skipped** | [final-tests.txt](evidence/final-tests.txt) |
| Build Vite | Berhasil, exit 0 | [final-build.txt](evidence/final-build.txt) |
| Sintaks PHP pada file berubah | 20 file; 0 error | [php-lint.json](evidence/php-lint.json) |
| Laravel Pint pada file PHP berubah | Lulus | [final-style.txt](evidence/final-style.txt) |
| Validasi Composer | Valid, pemeriksaan strict lulus | [composer-validate.txt](evidence/composer-validate.txt) |
| Composer audit dengan lockfile | Tidak ada advisory atau paket abandoned yang dilaporkan Packagist saat pemeriksaan | [composer-audit.json](evidence/composer-audit.json) |
| Aset Filament | 36 aset diperiksa; semuanya identik byte-for-byte dengan `vendor/filament/*/dist` | [asset-verification.json](evidence/asset-verification.json) |
| Audit commit | 58 file sesuai manifest; working tree bersih sebelum laporan; versi dependency cocok; diff check lulus | [post-commit-audit.json](evidence/post-commit-audit.json) |
| Tes integration terpisah | 3 tes dilewati karena memerlukan database PostgreSQL khusus | [integration-tests.txt](evidence/integration-tests.txt) |

Jumlah aset yang diverifikasi mencakup dua aset yang tidak berubah. Kelompok commit dependency berisi 34 aset berubah, satu lockfile, dan satu file tes banner. Ringkasan hasil JUnit tersedia pada [test-summary.json](evidence/test-summary.json).

Perintah utama yang dijalankan:

```bash
php vendor/bin/phpunit --log-junit /tmp/walatra-cleanup-2026-11-18/final-junit.xml
npm run build
php -l <setiap-file-PHP-yang-berubah>
php vendor/bin/pint --test <daftar-file-PHP-yang-berubah>
composer validate --strict --no-check-publish
composer audit --locked --format=json
php vendor/bin/phpunit tests/Integration --display-skipped
git diff 2284c216fceb529e3f1f9201166d82562e5d3c65 HEAD --check
```

## 4. Audit setelah pekerjaan selesai

Audit membandingkan tiga commit dengan manifest dan membaca ulang isi 58 file dari commit terhadap working tree. Daftar file berubah juga diperiksa melalui `detect_changes` dengan base `2284c216fceb529e3f1f9201166d82562e5d3c65`. Graph digunakan untuk pemetaan simbol dan relasi sebelum perubahan; coverage seluruh 58 path diperiksa sebelum kesimpulan.

Graph mencatat coverage parsial pada CSS Filament dan tidak melacak kesegaran lockfile. Keduanya diverifikasi melalui pembacaan berkas langsung: seluruh aset dibandingkan byte-for-byte dengan sumber dependency, sedangkan lockfile dibandingkan dengan versi awal serta dependency terpasang. Sinyal coverage tetap bersifat best effort, bukan bukti kelengkapan analisis statis. Lihat [index-coverage-summary.json](evidence/index-coverage-summary.json).

Hasil audit akhir tidak menemukan ketidaksesuaian tambahan pada pengelompokan commit, dependency/aset, dan pengujian yang dijalankan. Bukti hasil tes sesuai kode yang disimpan dalam tiga commit; commit hanya menyimpan isi yang sudah diverifikasi.

## 5. Batas verifikasi dan tindak lanjut

- Tiga tes konkurensi berada di `tests/Integration`, di luar suite default pada `phpunit.xml`. Ketiganya memerlukan database PostgreSQL khusus dengan prefix `walatra_cart_concurrency_test_` atau `walatra_payment_concurrency_test_`. Hasil skipped tidak dihitung sebagai tes lulus. Pengujian konkurensi perlu dijalankan pada database terisolasi tersebut.
- Pemeriksaan keamanan ini terbatas pada perubahan lokal dan tes yang disebutkan. Hasil Composer audit hanya menyatakan advisory yang dilaporkan saat pemeriksaan, bukan jaminan semua dependency bebas celah.
- Tidak dilakukan pengujian browser manual setelah pembaruan Filament. Interaksi yang tercakup tes Filament/Livewire telah diuji melalui suite otomatis; pemeriksaan visual panel tetap terpisah.
- Tidak dilakukan pemeriksaan server production, pembayaran live, webhook internet, worker production, atau settlement Midtrans. Itu merupakan pekerjaan nomor 2 yang belum dikerjakan dalam laporan ini.
- Tidak ada migrasi atau seeding terhadap database aplikasi aktif, perubahan `.env` aktif, penghapusan log operasional, push, atau deployment dalam pekerjaan ini. Tes memakai konfigurasi pengujian SQLite in-memory; perintah log diuji pada database tes.

Laporan dan bukti disimpan dalam commit dokumentasi terpisah setelah audit. Tiga commit implementasi di atas tetap menjadi objek audit yang dirujuk laporan ini.
