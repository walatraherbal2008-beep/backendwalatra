# API Lokasi Everpro

Dokumen ini menjelaskan penggunaan API lokasi Everpro melalui backend Walatra.
Frontend tidak memanggil Everpro secara langsung. Frontend hanya memanggil endpoint
Walatra, sedangkan backend mengelola access token dan credential Everpro.

## Konfigurasi

Tambahkan atau pastikan konfigurasi berikut tersedia di `.env`:

```env
EVERPRO_ENABLED=true
EVERPRO_BASE_URL=https://client-api-sandbox.everpro.id
EVERPRO_CLIENT_KEY=isi_client_key_dari_everpro
EVERPRO_CLIENT_SECRET=isi_client_secret_dari_everpro
EVERPRO_TIMEOUT=10
```

Credential tidak boleh dikirim ke frontend, disimpan di response API, atau dimasukkan
ke repository.

Setelah mengubah `.env`, bersihkan cache konfigurasi jika aplikasi menggunakan config
cache:

```bash
php artisan config:clear
php artisan cache:clear
```

Untuk production, gunakan base URL production yang diberikan Everpro, bukan URL
sandbox.

## Endpoint yang tersedia

Base URL backend:

```text
http://127.0.0.1:8000/api/v1
```

Semua endpoint lokasi bersifat public karena hanya mengembalikan master wilayah.
Endpoint tetap menggunakan throttle katalog.

### Daftar provinsi

```http
GET /shipping/locations/provinces
```

Parameter query:

| Parameter | Wajib | Keterangan                   |
| --------- | ----- | ---------------------------- |
| `q`       | Tidak | Kata pencarian nama provinsi |

Contoh:

```bash
curl "http://127.0.0.1:8000/api/v1/shipping/locations/provinces?q=Jawa"
```

Jika `q` kosong, backend meminta seluruh daftar provinsi dari Everpro.

### Daftar kota/kabupaten

```http
GET /shipping/locations/cities
```

Minimal salah satu parameter berikut harus dikirim:

| Parameter       | Keterangan                    |
| --------------- | ----------------------------- |
| `province_id`   | ID provinsi Everpro           |
| `province_name` | Nama provinsi                 |
| `city_name`     | Kata pencarian kota/kabupaten |

Contoh:

```bash
curl "http://127.0.0.1:8000/api/v1/shipping/locations/cities?province_id=32"
```

### Daftar kecamatan

```http
GET /shipping/locations/districts
```

Minimal salah satu parameter berikut harus dikirim:

| Parameter       | Keterangan                |
| --------------- | ------------------------- |
| `city_id`       | ID kota/kabupaten Everpro |
| `district_name` | Kata pencarian kecamatan  |

Contoh:

```bash
curl "http://127.0.0.1:8000/api/v1/shipping/locations/districts?city_id=3273"
```

### Daftar kelurahan/desa

```http
GET /shipping/locations/sub-districts
```

Minimal salah satu parameter berikut harus dikirim:

| Parameter           | Keterangan                    |
| ------------------- | ----------------------------- |
| `district_id`       | ID kecamatan Everpro          |
| `sub_district_name` | Kata pencarian kelurahan/desa |
| `postal_code`       | Kode pos                      |

Contoh:

```bash
curl "http://127.0.0.1:8000/api/v1/shipping/locations/sub-districts?district_id=327301"
```

Atau berdasarkan kode pos:

```bash
curl "http://127.0.0.1:8000/api/v1/shipping/locations/sub-districts?postal_code=40135"
```

### Validasi alamat berdasarkan kode pos

```http
POST /shipping/locations/resolve
Content-Type: application/json
```

Minimal salah satu field harus dikirim:

```json
{
    "postal_code": "40135"
}
```

Atau:

```json
{
    "address": "Jl. Diponegoro No. 22, Citarum, Kec. Bandung Wetan, Kota Bandung, Jawa Barat"
}
```

Contoh:

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/shipping/locations/resolve" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"postal_code":"40135"}'
```

Everpro memprioritaskan `postal_code` jika `address` dan `postal_code` dikirim
bersamaan. Format alamat lengkap sebaiknya memuat jalan, kelurahan/desa,
kecamatan, kota/kabupaten, dan provinsi.

## Format response berhasil

Response daftar wilayah:

```json
{
    "success": true,
    "message": "Daftar provinsi berhasil diambil.",
    "data": {
        "locations": [
            {
                "id": "32",
                "name": "Jawa Barat"
            }
        ]
    }
}
```

Response resolve:

```json
{
    "success": true,
    "message": "Alamat berhasil divalidasi.",
    "data": {
        "location": {
            "province_id": "32",
            "city_id": "3273",
            "district_id": "327301",
            "sub_district_id": "3273011001",
            "postal_code": "40135"
        }
    }
}
```

Field lokasi mengikuti response Everpro. Backend belum menyimpan alamat user pada
tahap ini; endpoint CRUD alamat user akan menggunakan field lokasi ini pada tahap
berikutnya.

## Alur pemakaian di frontend

Frontend sebaiknya mengisi alamat secara berurutan:

```text
Ambil provinsi
→ user memilih provinsi
→ ambil kota berdasarkan province_id
→ user memilih kota
→ ambil kecamatan berdasarkan city_id
→ user memilih kecamatan
→ ambil kelurahan/desa berdasarkan district_id
→ user memilih sub_district_id
→ simpan ID dan nama semua wilayah
```

Data yang perlu disimpan frontend untuk dikirim pada tahap CRUD alamat:

```json
{
    "province_id": "32",
    "province_name": "Jawa Barat",
    "city_id": "3273",
    "city_name": "Kota Bandung",
    "district_id": "327301",
    "district_name": "Coblong",
    "sub_district_id": "3273011001",
    "sub_district_name": "Dago",
    "postal_code": "40135"
}
```

Jangan hanya menyimpan nama `district`. Untuk shipment Everpro, referensi utama
yang dibutuhkan adalah `sub_district_id`.

## Error response

### Konfigurasi Everpro belum tersedia

HTTP `503`:

```json
{
    "success": false,
    "message": "Layanan lokasi Everpro sedang tidak tersedia. Silakan coba lagi.",
    "code": "EVERPRO_UNAVAILABLE",
    "errors": null
}
```

### Parameter tidak valid

HTTP `422`:

```json
{
    "success": false,
    "message": "The province id field is required when ...",
    "code": "VALIDATION_ERROR",
    "errors": {
        "province_id": ["The province id field is required."]
    }
}
```

## Catatan teknis

- Access token Everpro dikelola dan dicache backend.
- Frontend tidak perlu mengirim Bearer Token Everpro.
- Endpoint lokasi hanya menyiapkan master wilayah; belum membuat alamat user.
- Rate pengiriman, origin gudang, create shipment, AWB, dan tracking belum termasuk
  dalam tahap ini.
- Data alamat order nantinya harus menyimpan snapshot `sub_district_id` dan nama
  wilayah agar tidak berubah ketika user mengedit alamat setelah order dibuat.

## Referensi Everpro

- [Everpro Open API](https://developer.everpro.id/)
- [Location API](https://developer.everpro.id/)
