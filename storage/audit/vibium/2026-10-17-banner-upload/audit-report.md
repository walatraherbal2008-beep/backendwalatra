# Audit Vibium — Upload Banner Iklan

Tanggal pengujian: 17 Oktober 2026  
Environment: `http://127.0.0.1:8000`  
Akun: Superadmin  
Tool: Vibium

## Skenario

1. Login ke `/superadmin/login`.
2. Membuka menu `/superadmin/banners`.
3. Membuka form `Buat banner iklan`.
4. Mengisi nama iklan:
   `Promo Madu Herbal Tes Vibium`.
5. Upload file:
   `docs/banner/Golden Honey Jar Herbal Still Life.png`.
6. Mengisi CTA:
   `https://example.com/promo-madu`.
7. Mengatur status `active`.
8. Mengatur jadwal:
   - Mulai: `17 Okt 2026 00:00`
   - Berakhir: `31 Okt 2026 23:59`
9. Mengatur urutan: `1`.
10. Menyimpan banner.
11. Memastikan banner muncul di daftar admin.
12. Memanggil API publik `/api/v1/banners`.

## Hasil

- Login Superadmin: berhasil.
- Menu Banner Iklan: berhasil tampil.
- Form create banner: berhasil setelah perbaikan.
- Upload gambar PNG: berhasil.
- Ukuran file yang tersimpan: sekitar 1.8 MB.
- Status dan jadwal: tersimpan sesuai input.
- Urutan banner: tersimpan sebagai `1`.
- Daftar admin menampilkan 1 banner.
- API publik mengembalikan 1 banner aktif.
- URL gambar media berhasil dikembalikan.
- URL CTA berhasil dikembalikan.
- Endpoint dapat diakses tanpa login.

## Pengujian lanjutan CRUD dan seluruh aset

### Update

- Banner pertama diubah menjadi:
  `Promo Madu Herbal Tes Vibium - Updated`.
- Setelah disimpan, halaman otomatis kembali ke tabel.
- Notifikasi custom tampil:
  `Banner iklan berhasil diperbarui.`

### Delete

- Banner pertama dihapus melalui tombol `Hapus`.
- Dialog konfirmasi tampil.
- Setelah dihapus, halaman tetap/kembali ke tabel.
- Notifikasi custom tampil:
  `Banner iklan berhasil dihapus.`
- Banner madu kemudian dibuat kembali agar seluruh aset tetap tersedia.

### Seluruh gambar yang diuji

| Urutan | File sumber | Status |
|---:|---|---|
| 1 | `Golden Honey Jar Herbal Still Life.png` | Berhasil |
| 2 | `Fresh Moringa Garden Still Life-1.png` | Berhasil |
| 3 | `Burgundy beetroot garden still life-2.png` | Berhasil |
| 4 | `Fresh garlic on a mint garden backdrop-3.png` | Berhasil |

Total 4 banner aktif tampil di tabel Filament dan dikembalikan oleh API publik
sesuai urutan `sort_order` 1 sampai 4.

## Response API terverifikasi

```json
{
  "id": 1,
  "name": "Promo Madu Herbal Tes Vibium",
  "cta_url": "https://example.com/promo-madu",
  "starts_at": "2026-10-17T00:00:00+07:00",
  "ends_at": "2026-10-31T23:59:00+07:00",
  "sort_order": 1
}
```

## Temuan dan perbaikan

### Temuan 1 — Preview gambar Filament HTTP 500

`ImageColumn` pada Filament 5.7.8 tidak mendukung method `collection()`.
Kolom diperbaiki menggunakan URL media dari `getFirstMediaUrl('image')`.

### Temuan 2 — Form create HTTP 500

Class `Illuminate\Validation\Rules\Url` tidak tersedia pada instalasi project.
Dependency tersebut dihapus dari resource. Validasi URL tetap dijaga di model
dan hanya menerima scheme `http` atau `https`.

## Bukti screenshot

- `storage/audit-banner-login.png`
- `storage/audit-banner-form.png`
- `storage/audit-banner-list.png`
- `storage/audit-banner-all-list.png`

## Kesimpulan

Flow upload banner melalui panel Superadmin sudah dapat digunakan. Banner yang
berstatus aktif dan berada dalam jadwal tampil dapat dikonsumsi oleh Android dan
website melalui endpoint publik.
