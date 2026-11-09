# API Banner Iklan

API publik untuk mengambil banner berdasarkan area penempatan. Tidak memerlukan token login.

## Endpoint dan area penempatan

**Method:** `GET`  
**URL:** `/api/v1/banners`  
**Header:** `Accept: application/json`

| Query `position` | Area yang dikembalikan |
| --- | --- |
| Tidak diisi | Seluruh area dalam satu array `data` |
| `main` | Banner utama yang besar |
| `secondary_top` | Area 2, kanan atas |
| `secondary_bottom` | Area 3, kanan bawah |

Query `position` opsional dan hanya menerima satu area per request. Endpoint tidak menggunakan pagination.

```bash
# Seluruh area
curl "https://api.example.com/api/v1/banners" -H 'Accept: application/json'

# Banner utama
curl "https://api.example.com/api/v1/banners?position=main" -H 'Accept: application/json'

# Area 2 — kanan atas
curl "https://api.example.com/api/v1/banners?position=secondary_top" -H 'Accept: application/json'

# Area 3 — kanan bawah
curl "https://api.example.com/api/v1/banners?position=secondary_bottom" -H 'Accept: application/json'
```

Ganti `https://api.example.com` dengan domain backend. Setiap area dapat memiliki beberapa banner. Respons selalu berupa array; request tanpa filter juga mengembalikan array datar dengan field `position` pada setiap banner.

## Respons berhasil — HTTP 200

Contoh respons untuk `?position=main`:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Promo Herbal",
      "position": "main",
      "image_url": "https://api.example.com/storage/123/banner.png",
      "image_preview_url": null,
      "cta_url": "https://example.com/promo",
      "open_in_new_tab": true,
      "starts_at": null,
      "ends_at": null,
      "sort_order": 1
    }
  ],
  "success": true,
  "message": "Daftar banner berhasil diambil."
}
```

| Field banner | Tipe | Keterangan |
| --- | --- | --- |
| `id` | integer | ID banner |
| `name` | string | Nama banner |
| `position` | string | `main`, `secondary_top`, atau `secondary_bottom` |
| `image_url` | string | URL publik gambar asli |
| `image_preview_url` | string atau null | URL pratinjau jika sudah tersedia |
| `cta_url` | string | URL tujuan menggunakan HTTP/HTTPS |
| `open_in_new_tab` | boolean | `false`: tab yang sama; `true`: tab baru |
| `starts_at` | string atau null | Awal tayang dalam ISO 8601; null berarti tanpa batas awal |
| `ends_at` | string atau null | Akhir tayang dalam ISO 8601; null berarti tanpa batas akhir |
| `sort_order` | integer | Urutan tampil; nilai lebih kecil lebih dahulu |

Banner diurutkan berdasarkan `sort_order` terkecil, lalu waktu pembuatan terbaru, lalu ID terbesar. Pengurutan yang sama berlaku pada request dengan filter area maupun seluruh area.

Untuk website, tab baru menggunakan `target="_blank"` dan `rel="noopener noreferrer"`. Pada Android, frontend menyesuaikan navigasi aplikasi atau browser eksternal berdasarkan pengaturan tersebut.

## Banner kosong dan filter tidak valid

Banner tampil jika berstatus **aktif**, memiliki gambar, dan berada dalam jadwal tayang. Banner yang sudah berakhir tidak dikembalikan meskipun statusnya masih aktif.

Jika tidak ada banner yang memenuhi syarat pada area yang diminta, respons tetap **HTTP 200**:

```json
{
  "data": [],
  "success": true,
  "message": "Daftar banner berhasil diambil."
}
```

Nilai `position` selain tiga pilihan di atas menghasilkan **HTTP 422** dengan kode `VALIDATION_ERROR` dan detail pada `errors.position`.

## Pengaturan melalui panel admin

Superadmin mengatur **Area banner**, **Buka tautan**, **Urutan tampil**, status, dan jadwal melalui form tambah/edit Banner Iklan. Daftar admin dapat difilter berdasarkan area.

- Default area untuk banner baru dan banner lama: **Utama** (`main`). Request API tanpa filter tetap mengambil seluruh area.
- Default pengaturan tautan: **Tab yang sama** (`open_in_new_tab: false`).
- Format gambar: **PNG, JPG, atau WebP**, maksimal **2 MB (2048 KB)**.
- Rekomendasi dimensi: **1536 × 625 px**. Dimensi ini bukan syarat validasi wajib.

Endpoint publik ini digunakan untuk membaca banner. Pengunggahan gambar dan pengelolaan banner dilakukan melalui panel admin.

Koleksi Postman: [banners.postman_collection.json](../postman/banners.postman_collection.json). Isi variable `base_url` dengan URL backend yang berakhiran `/api/v1`, tanpa slash terakhir. Koleksi menyediakan empat request: seluruh area, utama, area 2, dan area 3.
