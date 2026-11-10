# Audit dan Penyatuan Monitoring Pesanan

## Temuan

1. Resource Filament `Orders` dan `StoreTransactions` memakai model `Order` yang sama. Resource pertama menyediakan konfirmasi/pembatalan, sedangkan resource kedua menambahkan filter toko, agregasi nominal, serta sinkronisasi. Pemisahan ini menggandakan menu dan susunan detail, bukan memisahkan jenis transaksi database.
2. Kedua query awal membatasi sumber pada `manual` dan `midtrans`, sehingga simulasi serta sumber lain tidak terlihat secara umum.
3. Belum ada polling monitoring. Pembaruan hanya terlihat setelah interaksi/reload. Polling tabel saja juga tidak cukup untuk mendeteksi order baru ketika daftar kosong karena komponen tabel Filament tidak memasang poller pada empty state.
4. Query daftar memuat seluruh percobaan pembayaran dan riwayat status, walaupun riwayat hanya diperlukan ketika detail dibuka.

`App\Http\Resources\Api\V1\OrderResource` berbeda fungsi: kelas tersebut membentuk JSON API customer dan bukan resource/menu Filament. Kelas API tetap dipertahankan.

## Hasil perubahan

- Satu resource Filament di `/superadmin/orders`, dengan menu **Pesanan**. Resource/page/widget Transaksi Toko yang terpisah dihapus; URL lamanya menjadi redirect dengan otorisasi dan parameter filter/pencarian dipertahankan.
- Seluruh order database terlihat secara default, termasuk manual, Midtrans, simulasi, dan sumber lain. Filter sumber/status mengikuti nilai yang tercatat; transaksi di luar backend yang belum mempunyai order tidak diimpor otomatis.
- Konfirmasi manual, pembatalan, sinkronisasi, subtotal toko, lingkungan pembayaran, detail VA/billing, alamat/gudang, dan riwayat tersedia dalam satu menu.
- Query/agregasi dipusatkan pada service laporan; resource tipis meneruskan konfigurasi ke Tables, Schemas, Actions, Pages, dan Widgets.
- Empat kartu memakai `StatsOverviewWidget` Filament. Tabel, slide-over, Section, RepeatableEntry, filter, tombol, dan pemilih kolom memakai komponen Filament.
- Polling halaman setiap 5 detik memperbarui daftar kosong/berisi, kartu, dan detail terbuka. Filter, pencarian, pagination, serta catatan form dipertahankan. Detail tetap bisa dibaca saat perubahan status membuat order tidak lagi cocok dengan filter tabel.
- Polling hanya membaca database dan tidak melakukan charge/panggilan gateway. Sinkronisasi provider tetap melalui webhook/worker dan tindakan eksplisit yang sudah ada.
- Riwayat pembayaran/status dimuat saat detail dibuka. Daftar tetap memakai eager loading untuk customer, pembayaran aktif, item, dan relasi listing; agregasi nominal tetap dilakukan di database.
- Pembatalan Midtrans yang belum terkonfirmasi tidak dilaporkan berhasil. Pembatalan berhasil mencatat alasan/aktor admin pada riwayat pembatalan yang sama, tanpa membuat entri status ganda.

## Verifikasi

- Tes terkait monitoring, pengelolaan manual, dan pembayaran Core: **59 lulus, 502 assertions**.
- Tes monitoring/pengelolaan admin pada PostgreSQL terpisah: **37 lulus, 304 assertions**.
- Suite penuh: **353 lulus, 1 gagal, 2445 assertions**. Kegagalan yang sudah ada pada `CustomerOtpAuthTest`: ekspektasi limiter 5 request berbeda dari konfigurasi 20 request. Keduanya tidak diubah dalam pekerjaan ini.
- Vibium desktop 1440 × 900 dan mobile 390 × 844: satu menu Pesanan, daftar/kartu/detail tampil memakai Filament, serta lebar halaman tidak keluar viewport. Tabel mobile dapat digeser di dalam kontainernya.
- Uji browser langsung: mulai dari pencarian kosong, tambahkan tiga fixture sumber manual/simulasi/import, dan pastikan semuanya muncul tanpa reload. Konfirmasi pembayaran manual fixture dari backend kemudian memperbarui detail terbuka dan ringkasan tanpa klik/reload.
- Data akun/order fixture visual dibersihkan sesudah pemeriksaan. Tidak ada migrasi database atau perubahan kontrak API customer.

Panduan operasional: [Pesanan](../../store-transactions.md).
