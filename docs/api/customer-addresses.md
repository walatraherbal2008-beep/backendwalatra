# API Alamat Customer dan Lokasi Everpro

Base URL: `https://api.example.com/api/v1`. Ganti domain sesuai server.

```http
Accept: application/json
Content-Type: application/json
```

Endpoint `/addresses` memerlukan akun aktif dan token customer:

```http
Authorization: Bearer <TOKEN_CUSTOMER>
```

Endpoint `/shipping/locations/*` bersifat publik. Autentikasi Everpro ditangani backend.

## Alamat customer

| Method | Endpoint | Fungsi | Status sukses |
|---|---|---|---|
| GET | `/addresses` | Daftar alamat akun yang login | 200 |
| POST | `/addresses` | Tambah alamat | 201 |
| PATCH / PUT | `/addresses/{id}` | Ubah alamat atau alamat utama | 200 |
| DELETE | `/addresses/{id}` | Hapus alamat | 200 |

Tidak tersedia endpoint detail `GET /addresses/{id}`. Alamat milik akun lain tidak dapat diubah atau dihapus.

### Field alamat

| Field | Ketentuan saat tambah |
|---|---|
| `label` | Wajib, string maksimal 50 karakter |
| `name` | Wajib, string 2–255 karakter |
| `phone` | Wajib, string nomor seluler Indonesia, maksimal 20 karakter |
| `province`, `city` | Wajib, string maksimal 100 karakter |
| `postal_code` | Wajib, string maksimal 10 karakter |
| `address` | Wajib, string maksimal 1.000 karakter |
| `district` | Opsional, string maksimal 100 karakter atau null |
| `address_note` | Opsional, string maksimal 500 karakter atau null |
| `province_id`, `city_id`, `district_id`, `sub_district_id` | Opsional; jika salah satunya diisi saat tambah, keempatnya wajib. String maksimal 100 karakter |
| `province_name`, `city_name`, `district_name`, `sub_district_name` | Opsional, string maksimal 100 karakter atau null |
| `latitude`, `longitude` | Opsional, angka atau null; rentang latitude -90–90 dan longitude -180–180 |
| `is_default` | Opsional, boolean |

Nomor `081234567890` dinormalisasi menjadi `6281234567890`. ID lokasi menggunakan nilai dari endpoint Everpro, bukan kode wilayah angka dari sumber lain. Contoh berikut memakai ID sandbox; gunakan nilai dari respons server yang sedang digunakan.

### Tambah alamat

`POST /addresses`

```json
{
  "label": "Rumah",
  "name": "Pembeli Walatra",
  "phone": "081234567890",
  "province": "Jawa Barat",
  "province_id": "SdLQ8KONbg==",
  "province_name": "Jawa Barat",
  "city": "Bandung Barat",
  "city_id": "S8u307WCKtg=",
  "city_name": "Bandung Barat",
  "district": "Batujajar",
  "district_id": "SM6jvJaNOtiR",
  "district_name": "Batujajar",
  "sub_district_id": "Qsqup-zBDMKRLLs=",
  "sub_district_name": "Bojonghaleuang",
  "postal_code": "40561",
  "address": "Jalan Contoh Nomor 1",
  "address_note": "Pagar hitam",
  "is_default": true
}
```

Respons 201, cuplikan:

```json
{
  "success": true,
  "message": "Alamat berhasil ditambahkan.",
  "data": {
    "id": 5,
    "label": "Rumah",
    "name": "Pembeli Walatra",
    "phone": "6281234567890",
    "is_default": true
  }
}
```

`data` lengkap berisi seluruh field alamat di atas. Field opsional yang belum diisi dapat bernilai null. Koordinat yang terisi dikembalikan sebagai string desimal tujuh angka di belakang koma.

### Daftar alamat

`GET /addresses`

Respons 200 berisi `data` berupa array objek alamat dan pesan `Daftar alamat berhasil diambil.` Tidak memakai pagination; alamat utama diurutkan terlebih dahulu, lalu waktu pembuatan terbaru. Jika belum ada alamat:

```json
{
  "success": true,
  "message": "Daftar alamat berhasil diambil.",
  "data": []
}
```

### Ubah alamat

`PATCH /addresses/5` atau `PUT /addresses/5`. Keduanya menerima update parsial.

```json
{
  "label": "Kantor",
  "address_note": "Titipkan ke resepsionis"
}
```

Respons 200 berisi objek alamat lengkap pada `data` dan pesan `Alamat berhasil diperbarui.` Field yang tidak dikirim tidak berubah. Field nullable dapat dikosongkan dengan null.

### Jadikan alamat utama

`PATCH /addresses/5`

```json
{"is_default": true}
```

Alamat pertama otomatis menjadi utama. Menetapkan alamat utama baru menonaktifkan penanda utama pada alamat lain. Mengirim `is_default: false` dapat membuat akun tidak memiliki alamat utama; perubahan utama bersamaan belum dijamin hanya menghasilkan satu alamat utama.

### Hapus alamat

`DELETE /addresses/5`, tanpa body.

```json
{
  "success": true,
  "message": "Alamat berhasil dihapus.",
  "data": null
}
```

Jika alamat utama dihapus, alamat tersisa yang paling baru dibuat dipilih sebagai utama. Penghapusan dan pemilihan pengganti belum satu transaksi. Perubahan alamat tidak mengubah snapshot alamat pada order yang sudah dibuat.

## Lokasi Everpro

Semua contoh path berikut dipanggil melalui backend. ID berupa string dan harus di-URL-encode saat menjadi query parameter, misalnya `=` menjadi `%3D`.

| Method | Endpoint | Query/body |
|---|---|---|
| GET | `/shipping/locations/provinces` | `q` opsional, pencarian nama provinsi |
| GET | `/shipping/locations/cities` | Minimal satu: `province_id`, `province_name`, atau `city_name` |
| GET | `/shipping/locations/districts` | Minimal satu: `city_id` atau `district_name` |
| GET | `/shipping/locations/sub-districts` | Minimal satu: `district_id`, `sub_district_name`, atau `postal_code` |
| POST | `/shipping/locations/resolve` | Minimal satu: `address` atau `postal_code` |

Parameter nama/ID dan `q` maksimal 100 karakter, `postal_code` maksimal 10 karakter, dan `address` maksimal 1.000 karakter. Respons daftar berada di `data.locations`; pencarian `resolve` berada di `data.location`.

### Provinsi

`GET /shipping/locations/provinces?q=Jawa%20Barat`

```json
{
  "success": true,
  "message": "Daftar provinsi berhasil diambil.",
  "data": {
    "locations": [
      {"province_id": "SdLQ8KONbg==", "province_name": "Jawa Barat"}
    ]
  }
}
```

Tanpa `q`, endpoint mengambil daftar provinsi tanpa filter nama.

### Kota/kabupaten

`GET /shipping/locations/cities?province_id=SdLQ8KONbg%3D%3D`

Contoh satu objek dalam `data.locations`:

```json
{"city_id": "S8u307WCKtg=", "city_name": "Bandung Barat"}
```

### Kecamatan

`GET /shipping/locations/districts?city_id=S8u307WCKtg%3D`

Contoh satu objek dalam `data.locations`:

```json
{"district_id": "SM6jvJaNOtiR", "district_name": "Batujajar"}
```

### Kelurahan/desa

`GET /shipping/locations/sub-districts?district_id=SM6jvJaNOtiR`

Contoh satu objek dalam `data.locations`:

```json
{
  "sub_district_id": "Qsqup-zBDMKRLLs=",
  "sub_district_name": "Bojonghaleuang",
  "postal_code": "40561"
}
```

Pencarian berdasarkan kode pos juga tersedia: `GET /shipping/locations/sub-districts?postal_code=40561`.

### Cari lokasi dari alamat atau kode pos

`POST /shipping/locations/resolve`

```json
{"postal_code": "40135"}
```

Alternatif body: `{"address": "Dago, Coblong, Bandung"}`. Respons 200 memakai `data.location`, mengikuti struktur Everpro. Hasil dapat berupa array beberapa kandidat dan tidak selalu memuat seluruh ID wilayah. Format ID kandidat juga dapat berbeda dari endpoint daftar; hasil ini bukan payload alamat lengkap.

## Penggunaan alamat pada checkout

ID dari `/addresses` dikirim sebagai `address_id`:

```json
{
  "address_id": 5,
  "cart_item_ids": [12, 15]
}
```

Body tersebut digunakan pada `POST /checkout/preview`. Pembuatan order berikutnya menyertakan `checkout_token` dari preview. Rincian tersedia di [API cart dan checkout](cart-checkout.md).

## Status respons

| HTTP | Arti |
|---|---|
| 200 / 201 | Berhasil |
| 401 / 403 | Autentikasi atau akses akun tidak memenuhi syarat |
| 404 | Alamat tidak ditemukan atau bukan milik akun |
| 422 | Parameter atau field tidak valid |
| 429 | Batas request lokasi terlampaui |
| 503 `EVERPRO_UNAVAILABLE` | Layanan lokasi Everpro tidak tersedia |

Error validasi berisi `code: VALIDATION_ERROR` dan pesan per field pada `errors`. Endpoint lokasi berbagi batas katalog 120 request per menit; respons 429 menyertakan header `Retry-After`.

Koneksi yang diperiksa pada 4 November 2026 menggunakan Everpro sandbox. Penyimpanan alamat tidak melakukan pemeriksaan ulang kode wilayah ke Everpro.
