# Laporan QC keamanan dan performa Backend Walatra

Tanggal pemeriksaan: 23 November 2026. Objek audit: commit `08d9117c43dbf421a564d0da68ea76764e7dabea`, branch `master`. Runtime: PHP 8.4.27, Laravel 12.72.0, Filament 5.11.4.

**Pembaruan perbaikan 23 November 2026:** QC01 sudah diperbaiki pada working tree. Ekspor kini mewajibkan role super_admin dan status active; akun blocked, suspended, atau inactive mendapat HTTP 403 meskipun sesi masih valid. Superadmin aktif tetap dapat mengekspor. Seluruh suite setelah perubahan lulus: **483 tes, 3.508 assertion**. Bukti: [export-status-full.txt](evidence/export-status-full.txt). Temuan lain belum diperbaiki. Hasil audit awal dan buktinya di bawah dipertahankan sebagai riwayat.

**Hasil:** 480 tes bawaan lulus, tetapi pengujian tambahan menemukan lima masalah aplikasi yang dapat direproduksi. Prioritas utama adalah akses ekspor customer oleh superadmin yang sudah diblokir dan penguncian gudang bersama selama checkout. Ada pula advisory dependency npm, risiko konfigurasi lokal jika dipublikasikan, serta masalah isolasi fixture tes konkurensi.

Pengujian ini mencakup pembacaan kode, eksekusi request melalui HTTP kernel Laravel, tes konkurensi PostgreSQL, audit dependency online, build frontend, dan pemeriksaan sintaks. Ini merupakan audit repository dan runtime pengujian lokal; hasilnya tidak menyatakan server production sudah diuji atau bebas celah.

## Ringkasan temuan

P1 berarti perlu didahulukan sebelum penggunaan terkait diperluas. P2 berarti perlu dijadwalkan dalam perbaikan berikutnya. P3 berarti perbaikan ketahanan atau pemeliharaan. Tingkat risiko di bawah mempertimbangkan kondisi aplikasi, bukan hanya label scanner.

| ID | Prioritas | Temuan | Status bukti |
|---|---|---|---|
| QC01 | P1 tinggi | Superadmin berstatus blocked tetap dapat mengekspor data customer | Diperbaiki; regresi blocked, suspended, inactive teruji |
| QC02 | P1 tinggi untuk skala | Checkout mengunci semua kandidat gudang selama pemeriksaan stok eksternal | Dibuktikan dengan dua koneksi PostgreSQL pada mode check_only |
| QC03 | P2 sedang | Input nama customer diteruskan menjadi formula CSV | Formula sintetis =1+1 tetap utuh di sel ekspor |
| QC04 | P2 sedang | Request API yang tidak cocok dengan route tetap memicu dua penulisan audit sinkron | Sepuluh request anonim 404 menghasilkan 10 insert dan 10 update |
| QC05 | P3 rendah | ID URL nonnumerik menghasilkan HTTP 500 | Empat request direproduksi; penyebab TypeError |
| QC06 | P3 pada penggunaan yang diperiksa | follow-redirects 1.16.1 terkena advisory berlabel high | npm audit online; eksploitasi melalui aplikasi belum terbukti |
| QC07 | P1 bila lingkungan lokal dipublikasikan | APP_ENV local, debug aktif, dan exposure OTP aktif | Konfigurasi lokal teramati; bukan temuan konfigurasi production |
| QC08 | P2 untuk keandalan QA | Tes konkurensi cart terpengaruh pilihan gudang di lingkungan lokal | Kegagalan preview direkam; tes warisan lulus setelah fixture diisolasi |

## Hasil pengujian

| Pemeriksaan | Hasil | Bukti |
|---|---|---|
| Suite PHPUnit bawaan | 480 tes, 3.505 assertion; 0 gagal, error, atau skipped; sekitar 31,6 detik | [phpunit.txt](evidence/phpunit.txt), [JUnit](evidence/phpunit.xml) |
| Probe tambahan SQLite | 5 probe, 29 assertion; seluruh observasi sesuai hasil yang direproduksi | [probes.txt](evidence/probes.txt) |
| PostgreSQL cart | Dua tes konkurensi warisan dengan fixture eksplisit dan satu probe lock; 3 tes, 110 assertion | [postgres-cart.txt](evidence/postgres-cart.txt) |
| PostgreSQL pembayaran | 1 tes konkurensi, 17 assertion; lulus | [postgres-payment.txt](evidence/postgres-payment.txt) |
| Sintaks PHP | 423 file pada app, bootstrap, config, database, routes, tests; 0 error | [php-lint.json](evidence/php-lint.json) |
| Build Vite | Berhasil; build sekitar 742 ms | [build.txt](evidence/build.txt) |
| Composer validate | Valid | [composer-validate.txt](evidence/composer-validate.txt) |
| Persyaratan platform Composer | Seluruh persyaratan terpasang lulus | [platform.txt](evidence/platform.txt) |
| Composer audit locked online | Tidak ada advisory atau paket abandoned yang dilaporkan saat pemeriksaan | [composer-audit.json](evidence/composer-audit.json) |
| npm audit online | 1 dependency transitive dengan advisory high | [npm-audit.json](evidence/npm-audit.json) |
| Query katalog | 10 query untuk 5 item dan 10 query untuk 25 item per halaman | [probes.txt](evidence/probes.txt) |

Probe audit sengaja mengassert perilaku masalah yang ada. Hasil probe lulus berarti masalah berhasil dibuktikan, bukan berarti kontrol keamanan tersebut lulus. Tes ini diletakkan di folder audit dan tidak ditambahkan ke suite regresi normal.

Pengukuran katalog memakai 30 produk sintetis pada SQLite in-memory. Waktu request yang tercatat sekitar 10,68 ms dan 10,93 ms pada satu pengambilan sampel per ukuran halaman. Angka ini bukan SLA, persentil latency, atau kapasitas server production. Jumlah query tetap pada dua ukuran tersebut merupakan hasil positif untuk skenario yang diperiksa.

## QC01 Ekspor customer mengabaikan status akun admin

**Status terkini: diperbaiki.** Controller sekarang memeriksa status active bersama role super_admin sebelum membuat respons ekspor. Tiga kasus regresi pada [CustomerManagementTest.php](../../../tests/Feature/Filament/CustomerManagementTest.php) gagal dengan HTTP 200 sebelum perbaikan dan mendapat HTTP 403 setelahnya. Tes izin superadmin aktif dan larangan non-superadmin tetap lulus. Bukti sebelum: [export-status-red.txt](evidence/export-status-red.txt); sesudah, termasuk probe audit: [export-status-green.txt](evidence/export-status-green.txt). Probe audit untuk status blocked juga sudah diubah menjadi pemeriksaan regresi; bukti audit awal tetap tersimpan pada probes.txt.

**Risiko tinggi, dengan syarat sesi superadmin masih valid setelah status akun diubah.** Endpoint `GET /superadmin/customers/export` menggunakan middleware `auth`. Controller hanya memeriksa role `super_admin`, sedangkan pemeriksaan akses panel pada model User juga mewajibkan status aktif.

Reproduksi: buat admin aktif dengan role super_admin, autentikasikan sesi web, ubah statusnya menjadi blocked, lalu akses endpoint ekspor. `canAccessPanel(superadmin)` menjadi false, tetapi endpoint ekspor tetap mengembalikan HTTP 200. File berisi kolom identitas customer, WhatsApp, email, dan informasi aktivitas.

Penyebab berada pada [CustomerExportController.php](../../../app/Http/Controllers/Superadmin/CustomerExportController.php), baris 11, serta [routes/web.php](../../../routes/web.php), baris 11. Pembanding kebijakan status berada pada [User.php](../../../app/Models/User.php), baris 127. Bukti: probe `blocked_admin_export` pada [probes.txt](evidence/probes.txt).

Perbaikan: terapkan policy ekspor yang memeriksa status aktif dan role pada setiap request. Uji ulang memakai sesi yang dibuat sebelum pemblokiran; hasil yang diharapkan adalah penolakan akses. Pemeriksaan menyeluruh terhadap mekanisme pencabutan semua sesi admin di server berada di luar pembuktian ini.

## QC02 Lock gudang bersama dapat mengantrekan checkout antar pengguna

Audit lanjutan menggunakan dua proses checkout dan sembilan skenario PostgreSQL mengonfirmasi antrean pada gudang beririsan, lock harga varian, timeout di luar antrean, serta deadlock dengan sinkronisasi gudang. Hasil lengkap dan rekomendasi: [audit lanjutan lock checkout](../2026-11-23-checkout-locks/README.md). Implementasi checkout belum diubah.

**Risiko tinggi untuk throughput ketika nearest_available dipakai bersama check_only.** OrderController membuka transaksi dan mengunci pemilik cart. Pemilihan asal pengiriman kemudian memanggil selector dengan `lock: true`. Selector menjalankan `FOR UPDATE` atas seluruh kandidat gudang sebelum memeriksa stok melalui HTTP.

Pada pengujian, produk ditempatkan pada gudang NEAR dan FAR. Ketika request stok untuk checkout sedang berlangsung, koneksi PostgreSQL kedua mencoba mengunci masing-masing baris gudang. Keduanya gagal dengan SQLSTATE `55P03` setelah lock timeout 100 ms, meskipun order akhirnya memilih NEAR. Kedalaman transaksi pada saat HTTP berlangsung adalah 1.

Sumber: [NearestWarehouseSelector.php](../../../app/Services/Warehouse/NearestWarehouseSelector.php), baris 23–24 dan 57; [CheckoutShippingOrigin.php](../../../app/Services/Marketplace/CheckoutShippingOrigin.php), baris 55; [OrderController.php](../../../app/Http/Controllers/Api/V1/OrderController.php), baris 131. Bukti: [WarehouseLockProbeTest.php](WarehouseLockProbeTest.php) dan [postgres-cart.txt](evidence/postgres-cart.txt).

Dampaknya adalah checkout lain dengan kandidat gudang yang sama dapat menunggu lock walaupun berbeda pengguna atau produk. Batas waktu pemilihan stok dimulai setelah query penguncian kandidat selesai, sehingga batas tersebut tidak mencakup waktu menunggu lock awal. Belum dilakukan benchmark beban untuk menghitung penurunan throughput.

Konfigurasi lokal yang diperiksa menggunakan unlimited; cabang itu tidak memanggil API sisa stok pada selector. Pembuktian penahanan lock selama HTTP menggunakan check_only secara eksplisit. Lock kandidat tetap ada pada cabang unlimited, tetapi durasi dan dampaknya tidak disamakan dengan skenario HTTP tersebut.

Perbaikan: evaluasi kebutuhan exclusive lock pada master gudang; lakukan pekerjaan jaringan sebelum transaksi singkat yang memvalidasi ulang kondisi. Jika konsistensi stok memerlukan reservasi, gunakan operasi reservasi atomik atau protokol yang sesuai dengan upstream. Menghapus lock secara langsung tanpa mengevaluasi perubahan status gudang dan assignment dapat mengubah jaminan konsistensi. Tambahkan uji checkout dua pengguna dengan API stok lambat dan ukur lock wait serta latency persentil.

## QC03 Formula CSV berasal dari nama customer

**Risiko sedang, memerlukan admin membuka CSV pada spreadsheet yang menafsirkan formula.** Endpoint perubahan profil menerima nama string. Ekspor kemudian memberikan nama tersebut langsung ke `fputcsv`, tanpa perlakuan khusus untuk sel formula.

Reproduksi: customer mengubah nama menjadi `=1+1` lewat `PATCH /api/v1/auth/customer/profile`; respons HTTP 200. Superadmin mengekspor customer; hasil parsing CSV memperlihatkan nilai sel nama tetap `=1+1`. Pengujian menggunakan formula aritmetika dan tidak mengeksekusi spreadsheet, koneksi keluar, atau payload eksfiltrasi.

Sumber: [UpdateCustomerProfileRequest.php](../../../app/Http/Requests/Api/V1/UpdateCustomerProfileRequest.php), baris 21; [CustomerExportController.php](../../../app/Http/Controllers/Superadmin/CustomerExportController.php), baris 23–27. Bukti: probe `csv_formula` pada [probes.txt](evidence/probes.txt). Mekanisme risiko dijelaskan oleh [OWASP CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection).

Perbaikan: keluarkan kolom teks sebagai nilai literal yang aman untuk aplikasi spreadsheet tujuan; pertimbangkan XLSX dengan tipe sel string eksplisit. Jika tetap CSV, tangani karakter pemicu formula beserta whitespace/control character awal dan uji hasil di spreadsheet yang benar-benar digunakan. Quoting CSV saja tidak menjamin formula menjadi teks.

## QC04 Audit sinkron menambah beban pada request anonim tanpa route

**Risiko sedang untuk database dan penyimpanan, terutama bila tidak ada pembatasan di reverse proxy.** Middleware audit berjalan secara global untuk path API, termasuk path yang tidak memiliki route. Middleware melakukan insert, lalu finalizer memperbarui row yang sama. Pembatas route katalog tidak melindungi path yang tidak cocok dengan route.

Sepuluh GET anonim ke path `/api/audit-probe-missing-*` masing-masing menghasilkan 404. Query log menunjukkan 10 insert, 10 update, dan 10 row audit. Pengujian hanya memakai sepuluh request, bukan serangan volume tinggi. Potensi kehabisan disk atau koneksi adalah penilaian risiko dari jalur penulisan ini, bukan insiden yang dipicu dalam audit.

Sumber: [AuditIncomingApiRequest.php](../../../app/Http/Middleware/AuditIncomingApiRequest.php), baris 20, 41, dan 55; [ApiRequestAuditResponseFinalizer.php](../../../app/Services/ApiRequestAuditResponseFinalizer.php), baris 25; [bootstrap/app.php](../../../bootstrap/app.php). Bukti: `unmatched_request_audit_writes` pada [probes.txt](evidence/probes.txt).

Perbaikan: tambahkan pembatas request sebelum jalur audit mahal, evaluasi penulisan final sekali per request, dan gunakan penyimpanan log dengan kapasitas serta backpressure yang terukur. Pertahankan pencatatan kejadian keamanan penting. Retensi 90 hari sudah tersedia, tetapi retensi tidak membatasi laju penambahan data harian. Konfigurasi rate limit Nginx/WAF dan kapasitas disk production belum diperiksa.

## QC05 Parameter ID yang salah menghasilkan TypeError

**Risiko rendah untuk ketahanan API.** Empat request terautentikasi dengan ID `not-a-number` menghasilkan HTTP 500: detail order, informasi pembayaran order, pembatalan order, dan perubahan jumlah item cart. Pengujian tanpa exception handler mengonfirmasi TypeError pada argumen integer `OrderController::show()`.

Sumber: [routes/api.php](../../../routes/api.php), baris 55–68; parameter integer pada [OrderController.php](../../../app/Http/Controllers/Api/V1/OrderController.php), [PaymentController.php](../../../app/Http/Controllers/Api/V1/PaymentController.php), dan [CartController.php](../../../app/Http/Controllers/Api/V1/CartController.php). Bukti: `invalid_route_ids` pada [probes.txt](evidence/probes.txt).

Perbaikan: pasang constraint numerik pada parameter route dan pilih validasi/model binding yang sesuai. Uji ID alfabet, negatif, nol, serta nilai melampaui rentang integer. Hasil yang diharapkan adalah 404 atau error validasi terkontrol. Tidak dibuktikan adanya akses data lintas pengguna melalui masalah ini.

## QC06 Advisory dependency npm perlu diperbarui sesuai konteks

`npm audit` melaporkan GHSA-mrwp-f3q8-3g9p pada `follow-redirects` 1.16.1 melalui axios 1.20.0. Advisory menandai versi 1.16.0–1.16.1 dan menyebut patch 1.16.2. Label scanner adalah high.

Kondisi eksploitasi pada advisory melibatkan pemanggilan Node dengan array sensitiveHeaders lintas realm dan redirect lintas origin. Advisory sendiri menyatakan pengujian Axios 1.20.0 tidak mereproduksi kebocoran tersebut karena normalisasi array. Di repository ini axios berada pada devDependencies; keberadaan paket rentan tidak cukup untuk menyatakan backend PHP atau bundle browser dapat dieksploitasi melalui mekanisme Node ini.

Sumber: [package-lock.json](../../../package-lock.json), [npm-audit.json](evidence/npm-audit.json), dan [advisory GitHub](https://github.com/advisories/GHSA-mrwp-f3q8-3g9p). Perbaikan: perbarui dependency transitive ke versi patched, tinjau diff lockfile, kemudian ulangi build dan audit. Dependency belum diubah selama pemeriksaan ini.

## QC07 Konfigurasi lokal tidak boleh menjadi konfigurasi publik

File lingkungan lokal berisi `APP_ENV=local`, `APP_DEBUG=true`, dan `OTP_EXPOSE_CODE=true`. Controller OTP mengembalikan kode hanya pada lingkungan local/testing ketika exposure aktif. Karena itu konfigurasi ini berisiko tinggi jika instance lokal diteruskan ke internet atau dipakai sebagai deployment publik. Belum dibuktikan bahwa konfigurasi tersebut sedang digunakan pada server production.

Ada kontrol yang baik: [ProductionSecurity.php](../../../app/Support/ProductionSecurity.php) menolak boot production jika debug/exposure OTP aktif, secure cookie belum benar, atau secret bridge terlalu pendek. Tes kontrol itu lulus. Namun guard tersebut secara sengaja tidak berlaku pada APP_ENV local. [HorizonServiceProvider.php](../../../app/Providers/HorizonServiceProvider.php) juga bergantung pada pembedaan local/non-local dari framework untuk akses dashboard.

Perbaikan operasional: gunakan [contoh environment production](../../../.env.production.example), pastikan nama environment benar, dan verifikasi respons publik tidak mengandung OTP/debug. Ringkasan flag lokal yang tidak memuat secret tersimpan pada [local-environment-flags.json](evidence/local-environment-flags.json). Kredensial dan isi penuh .env tidak disalin ke laporan.

## QC08 Fixture konkurensi harus mengunci konfigurasi pengujian

Tes PostgreSQL asli tidak berhasil mencapai tahap konkurensi dalam konfigurasi lokal ini. Percobaan awal mengikuti nearest_available dari lingkungan dan mendapat 422 karena alamat fixture tidak memiliki koordinat. Setelah selector dibuat legacy, preview mendapat 409 WAREHOUSE_NOT_AVAILABLE karena preferred warehouse dari .env berbeda dari WH-TASIK pada fixture. Respons diagnostik tersedia pada [postgres-cart-diagnostic.txt](evidence/postgres-cart-diagnostic.txt).

Salinan turunan tes di [DiagnosedCartConcurrencyTest.php](DiagnosedCartConcurrencyTest.php) menetapkan `checkout.preferred_warehouse_code=null` dan `checkout.warehouse_selection=legacy` setelah setUp induk. Kedua metode tes warisan lalu lulus, termasuk verifikasi proses yang menunggu lock dan snapshot harga/cart. File tes asli tidak diubah. Bukti akhir: [postgres-cart.txt](evidence/postgres-cart.txt).

Perbaikan: isolasi konfigurasi tersebut pada fixture pengujian asli atau environment CI khusus, dan jalankan suite Integration sebagai job terpisah dengan PostgreSQL. Suite Integration saat ini berada di luar suite default phpunit.xml, sehingga 480 tes bawaan yang lulus tidak mencakup pengujian konkurensi ini. Hasil tes yang telah diadaptasi tidak diklaim sebagai hasil langsung file asli tanpa penyesuaian.

## Pengulangan dan batas pemeriksaan

Probe SQLite dapat diulang dengan perintah berikut dari root repository:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAXCHAT_ENABLED=false MAXCHAT_API_TOKEN= MIDTRANS_SERVER_KEY= php vendor/bin/phpunit docs/audits/2026-11-23-qc-security/AuditProbeTest.php
```

Tes PostgreSQL dapat diulang dengan `php docs/audits/2026-11-23-qc-security/run-postgres.php`. Skrip memakai koneksi PostgreSQL lokal dari .env untuk membuat database dengan prefix pengujian khusus, meneruskan kredensial database yang diperlukan ke proses tes, lalu menghapus database sementara dalam finally. Skrip membutuhkan izin create/drop database. Semua request upstream pada probe dan tes pembayaran dipalsukan; tidak ada transaksi Midtrans live atau pengiriman OTP nyata yang sengaja dilakukan.

Database aplikasi aktif tidak dimigrasi atau di-seed. Pada audit awal, perubahan hanya berupa laporan, probe, runner pengujian, dan bukti di folder audit ini. Tindak lanjut QC01 kemudian mengubah controller ekspor dan menambah tes regresi seperti dijelaskan di atas. Tidak ada perubahan .env aktif, commit, push, atau deployment. Build saat audit awal menghasilkan ulang aset pada public/build yang diabaikan Git.

Codebase Memory digunakan mulai dari list_projects dan index_status, pencarian simbol, trace, snippet, sampai pemeriksaan coverage path bukti. Source dibaca langsung untuk memastikan relasi penting karena graph PHP dapat menghasilkan relasi nama metode yang ambigu. Coverage merupakan sinyal best effort; .env dikecualikan dari indeks dan flag relevannya diperiksa langsung, sedangkan lockfile diverifikasi lewat pembacaan langsung serta scanner dependency. Pemeriksaan ini tidak mengklaim graph atau tinjauan source mencakup semua jalur aplikasi.

Keamanan TLS, firewall, reverse proxy, konfigurasi worker/Redis production, backup, seluruh interaksi browser, serangan beban besar, dan integrasi pihak ketiga secara live belum diuji. Tidak dipasang skill pentesting tambahan karena tool yang tersedia cukup untuk pembuktian dalam lingkup ini.

Urutan tindak lanjut yang disarankan: tutup akses ekspor akun nonaktif, evaluasi lock checkout, amankan ekspor spreadsheet dan beban audit, perbaiki respons ID invalid, lalu perbarui dependency dan isolasi fixture CI. Setelah perbaikan, ubah probe masalah menjadi tes regresi dengan ekspektasi perilaku aman dan ulangi pemeriksaan yang relevan.
