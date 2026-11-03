# PRD — Dynamic Destination Location Pin Picker V1

**Project:** Wisata Daerahku (Flutter Mobile Android + Laravel 13 REST API)  
**Dokumen:** `PRD-Wisata-Daerahku-Dynamic-Location-Pin-Picker-V1.md`  
**Status:** Approved requirements — ready for implementation  
**Tanggal:** 3 November 2026  
**Cakupan:** CRUD Destinasi oleh Admin/Superadmin, penyimpanan koordinat di Laravel, serta embedded map preview pada Detail Destinasi User.  
**Batasan implementasi:** Pertahankan desain, API, galeri gambar, otorisasi, dan alur booking yang telah ada; hindari QA agent, full regression, dan penulisan test suite masif.

## 1. Executive brief & latar belakang

Saat ini form tambah/edit destinasi mengandalkan input teks lokasi. Admin/Superadmin harus dapat memilih posisi destinasi langsung di peta menggunakan pin, memperoleh koordinat latitude/longitude dan alamat otomatis dari reverse geocoding, lalu (opsional) memperbaiki alamat. Lokasi terpilih **hanya menjadi draft form**, bukan perubahan backend seketika. Ketika form disimpan, data lokasi ikut payload create/update destinasi dalam operasi yang sama. User melihat peta ber-pin dan alamat pada Detail Destinasi serta bisa membuka Google Maps eksternal untuk navigasi.

**Temuan dari cabang `main` publik yang ditinjau (3 Nov 2026):**
- `lib/screens/tambah_wisata_screen.dart`: saat ini mempunyai `lokasiCtrl`, input teks **Lokasi**, dan payload `'location': lokasiCtrl.text.trim()`; layar juga punya alur gallery manifest dan operation fingerprint.
- `lib/screens/detail_wisata_screen.dart`: menampilkan `w.lokasi` sebagai teks pada detail.
- `lib/data/business_repository.dart`: objek `Destination` membaca `json['location']`; belum ada getter koordinat yang terlihat di cabang publik.
- Laravel `app/Models/Destination.php`: `location` termasuk `$fillable`; `latitude`/`longitude` belum terlihat.
- Laravel `app/Http/Requests/CatalogRequest.php`: validasi `location` ada; `DestinationGalleryRequest` mewarisinya dan menambah mekanisme galeri.
- Laravel `routes/api.php`: `POST destinations`, `PATCH destinations/{destination}`, `GET destinations/{destination}`; response menggunakan `DestinationResource`.
- `DestinationController` menggunakan `DestinationGalleryService` untuk alur galeri dan `CatalogService` untuk perubahan metadata; **jangan buat jalur simpan lokasi yang menyimpang dari kedua jalur tersebut**.
- File desain sumber: `docs/DESIGN.md` (repo Flutter). Cek juga implementasi visual lokal terbaru karena dokumen/branch publik bisa lebih lama dari workspace lokal.

Referensi repo:
- Mobile: https://github.com/koyotot/pami-wisata-daearahku
- Backend: https://github.com/joytimess/pami-wisata-daerahku-api

## 2. Keputusan yang sudah dikunci

| No | Keputusan | Hasil |
|---|---|---|
| 1 | Pengambil lokasi | Pin interaktif; latitude, longitude, alamat otomatis melalui reverse geocoding |
| 2 | Edit alamat | Boleh diedit manual; **tidak** mengubah koordinat pin |
| 3 | Waktu penyimpanan | Bersama tombol **Simpan Destinasi** — bukan POST pada konfirmasi pin |
| 4 | Peta internal | **OpenStreetMap + flutter_map**, tanpa kewajiban billing Google Maps |
| 5 | User detail | Embedded map preview + alamat + **Buka Google Maps** |
| 6 | Jika reverse geocoding gagal | Pertahankan koordinat pin dan izinkan alamat manual |
| 7 | Create | Peta pada viewport Indonesia, tombol opsional **Lokasi Saya**; GPS bukan pin otomatis |
| 8 | Edit | Peta menampilkan pin yang telah disimpan dari Laravel, dapat dipindah dan dikonfirmasi |
| 9 | Platform | Flutter Mobile Android, Laravel 13/MySQL; UI mengikuti `docs/DESIGN.md` |
| 10 | QA | Implementasi langsung, validasi fungsional ringan saja; tidak menugaskan QA agent atau regresi menyeluruh |

## 3. Objectives, non-goals, dan definisi selesai

### Objectives
1. Admin (role=1) dan Superadmin (role=2) yang memang punya izin mengelola destinasi dapat membuka location picker dari form.
2. Posisi pin dikonfirmasi dan dikembalikan ke form sebagai `latitude` serta `longitude` yang terikat pada alamat yang tampil.
3. Laravel menyimpan koordinat bersama `location` pada record destinasi yang benar, secara konsisten dengan upload/edit galeri.
4. API publik mengembalikan `location`, `latitude`, `longitude`; pengguna akhir dapat melihat pin dan membuka aplikasi Google Maps tanpa mengetik alamat.
5. Destinasi lama yang belum memiliki koordinat tetap dapat dibaca tanpa crash; update nonlokasi tidak wajib langsung memigrasikan pin.

### Non-goals V1
- Tidak ada Google Maps SDK atau Google Places Autocomplete.
- Tidak ada rekomendasi lokasi terdekat, pencarian destinasi berdasarkan radius, live tracking, road routing atau ETA di Flutter.
- Tidak ada geofencing atau auto-check-in dari koordinat.
- Tidak ada bulk geocoding/backfill destinasi lama secara diam-diam.
- Tidak ada reverse geocode berulang selama pan/zoom atau setiap frame drag.
- Tidak mengubah gallery sequence, pembayaran DOKU, booking, quota, approval, auth, atau desain seluruh layar detail di luar seksi lokasi.

### Definition of Done
- Create dan edit mempunyai alur pin yang sama namun initial camera berbeda.
- Form tidak kehilangan draft saat picker ditutup/batal.
- Koordinat valid disimpan pada create/update yang sukses, dikembalikan oleh API, dan muncul konsisten setelah layar dibuka ulang.
- User dengan destinasi berkoordinat melihat preview pin benar dan eksternal navigation terbuka.
- Legacy tanpa koordinat menampilkan alamat tanpa peta menipu.
- Error geocoding/tile/GPS ditangani sesuai tabel kondisi; tidak ada perubahan backend sebelum save.

## 4. Role, security, dan otorisasi

| Aksi | User (role 0) | Admin (role 1) | Superadmin (role 2) |
|---|---|---|---|
| Lihat lokasi publik | Ya, untuk destinasi yang tersedia | Ya | Ya |
| Buka picker tambah/edit | Tidak | Ya, sesuai izin katalog | Ya, sesuai izin katalog |
| Reverse geocode via Laravel | Tidak perlu | Ya, terautentikasi | Ya, terautentikasi |
| Simpan koordinat destinasi | Tidak | Ya, sesuai policy aktual | Ya, sesuai policy aktual |

Pertahankan pemeriksaan `role`, status akun aktif, resource policy, dan generation guard sesi yang sudah diterapkan. Seluruh endpoint mutation harus memvalidasi otorisasi di server, bukan berdasarkan tombol yang terlihat. `GET /destinations/{guid}` hanya mengekspos destinasi yang boleh dibaca publik; endpoint admin mengikuti policy existing. Jangan mengubah hak akses untuk role 0 atau memperlebar akses kategori/fasilitas.

## 5. End-to-end flows

### 5.1 Create destination — Admin/Superadmin
1. Buka **Tambah Destinasi** → seksi **Lokasi Destinasi**.
2. Seksi berisi empty map preview/placeholder bertuliskan **Tentukan lokasi di peta**, tombol **Pilih Titik Lokasi**, field **Alamat Lengkap** (awal kosong), dan label koordinat (belum dipilih).
3. Tap **Pilih Titik Lokasi** → masuk halaman/panel map picker full-screen yang aman untuk mobile dan edge-to-edge.
4. Initial viewport memperlihatkan **Indonesia** (bukan pin destinasi palsu); pin baru muncul setelah tap pada peta atau aksi eksplisit menempatkan pin.
5. Pan, zoom, tap atau drag pin hingga tepat; tombol **Lokasi Saya** meminta izin GPS *hanya saat ditekan*, lalu menggeser kamera ke lokasi perangkat. Aksi itu **tidak menetapkan pin otomatis**.
6. Setelah titik dipilih dan interaksi selesai, jalankan satu reverse geocoding secara terkendali. UI menampilkan fetching/hasil/error; kontrol pin tetap responsif.
7. Jika geocoding sukses → alamat terisi otomatis. Jika gagal/no result/limit → alamat manual wajib diisi.
8. Tap **Gunakan Lokasi Ini** → mengembalikan `latitude`, `longitude`, dan draft alamat ke form; jika alamat belum valid, boleh kembali ke form dengan field alamat yang diberi error dan perlu dilengkapi **sebelum simpan** (atau minta isi pada picker; pilih implementasi yang paling konsisten).
9. Admin dapat mengedit alamat tanpa menggeser pin. Jika perlu mengganti posisi, buka lagi picker; posisi awal diambil dari draft saat ini.
10. Tap **Simpan Destinasi** → satu request create existing (POST) yang berisi metadata, `location`, `latitude`, `longitude` dan galeri. Backend commit operasi sesuai mekanisme yang sudah ada.
11. Setelah sukses, ambil/terima resource baru dan tampilkan data tersimpan; jika gagal, draft form dan galeri tetap ada untuk retry yang aman.

### 5.2 Edit destination
1. Buka **Edit Destinasi** → data `location`, `latitude`, `longitude` dihydrate dari Laravel, bukan hardcoded.
2. Jika koordinat tersedia/valid, preview dan picker dibuka dengan kamera pada pin existing (zoom detail). Jika belum ada (legacy), layar menjelaskan **Lokasi peta belum ditentukan** dan membuka viewport Indonesia; alamat legacy tetap muncul di field.
3. Ubah pin → reverse-geocode otomatis; konfirmasi → draft berubah. Tidak ada `PATCH` hanya karena picker ditutup.
4. Jika klik **Batal** atau back tanpa konfirmasi di picker, discard *draft picker*, kembali ke form sebelumnya tanpa mutasi; jika form punya perubahan lain, tetap dipertahankan.
5. Jika alamat diedit tanpa menggeser pin, field `location` berubah dan koordinat lama tetap.
6. Tap **Simpan Perubahan** → request update existing (PATCH atau transport multipart yang *sudah* dipakai) dengan field yang benar-benar dimodifikasi, termasuk kedua koordinat jika diubah.
7. Simpan metadata/koordinat harus tetap melalui transaction/save path galeri apabila save manifest menyertainya; konflik galeri harus tidak meninggalkan koordinat baru tersimpan sendirian.
8. Kalau admin sedang mengedit destinasi legacy tanpa menambah pin, perubahan non-lokasi masih diperbolehkan; field coordinate tetap null.

### 5.3 User — destination detail
1. User membuka **Detail Destinasi** (termasuk desain Immersive Hero Gallery yang sudah ada).
2. Di bawah konten informasi yang sesuai layout, tampil seksi **Lokasi Destinasi**: map preview OSM dengan satu marker pada lat/lon dari API; alamat lengkap di bawah/atas preview; tombol **Buka Google Maps**.
3. Preview harus ringkas dan stabil di `SingleChildScrollView`/scroll container; **read-only/non-interaktif** pada V1 agar drag layar tidak tertangkap peta. Tidak memerlukan izin GPS.
4. Tap **Buka Google Maps** → URL Google Maps Directions menggunakan koordinat tujuan (destination); browser fallback bila aplikasi tidak tersedia. Jangan menyusun link dari alamat bebas bila koordinat tersedia.
5. Destinasi tanpa koordinat: tampilkan alamat biasa dan pesan lokasi peta belum tersedia, tanpa marker default dan tanpa tombol arah yang berisiko mengarahkan ke titik salah.
6. Kegagalan tile: alamat dan tombol navigasi tetap ada jika koordinat valid.

## 6. UI / UX specification (Flutter)

### 6.1 Form Tambah/Edit — seksi Lokasi

```text
┌────────────────────────────────────┐
│ Lokasi Destinasi                   │
│ Tentukan titik akurat pada peta.   │
│ ┌────────────────────────────────┐ │
│ │ [OSM preview / empty state]    │ │
│ │       📍 titik terpilih        │ │
│ └────────────────────────────────┘ │
│ [Pilih / Ubah Titik Lokasi]       │
│                                    │
│ Alamat Lengkap                     │
│ [Alamat otomatis atau ketik...]    │
│ Koordinat: -7.801234, 110.364567  │
└────────────────────────────────────┘
```

- Gunakan hierarki, warna, radius, typography, ukuran tombol, safe-area dan form behavior yang cocok dengan implementasi terkini + `docs/DESIGN.md`. Tidak mengarang design system kedua.
- Field alamat input teks, multiline cukup, batas sesuai backend (maksimal 255 karakter jika tetap `location VARCHAR(255)`).
- Tombol **Simpan** layar utama tetap di tempatnya; map picker tidak mempunyai aksi jaringan mutation.
- Tampilkan koordinat maksimum sekitar 6–7 desimal untuk admin; jangan biarkan field coordinate dalam bentuk string terlokalisasi berisi koma desimal. Angka berasal dari pin.
- Status: `Belum dipilih`, `Mencari alamat...`, `Alamat ditemukan`, `Alamat perlu diisi manual`, `Lokasi siap disimpan`.

### 6.2 Full-screen Location Picker

```text
┌────────────────────────────────────┐
│ ← Pilih Lokasi Destinasi           │
│                                    │
│                                    │
│         [ OSM map ]                │
│           📍                       │
│                                    │
│                         [◎ GPS]    │
│ Attribution: © OSM contributors   │
├────────────────────────────────────┤
│ Koordinat: lat, lon                │
│ Alamat hasil pencarian / manual    │
│ [Batal] [Gunakan Lokasi Ini]       │
└────────────────────────────────────┘
```

- Full-screen route lebih cocok untuk akurasi pin di mobile daripada dialog kecil. Tombol back/batal tidak menyimpan draft picker.
- Gestur: tap pada map untuk meletakkan/memindah pin, long-press/drag marker jika stabil menurut versi `flutter_map` yang dipakai. Jangan memaksa drag gesture yang tidak reliable; tap-to-position wajib ada.
- GPS recenter hanya saat user memilih tombol; timeout/perizinan ditangani dengan pesan dan manual map tetap berfungsi.
- Setelah ganti pin, hasil reverse geocode lama ditandai stale. Gunakan request generation/cancellation sehingga respons lama **tidak** menimpa pin/teks terbaru.
- Jika admin mengetik alamat sendiri, simpan sebagai manual override; jangan diam-diam overwrite manual text oleh geocoding response terlambat.
- Berikan satu sumber titik aktif (draft `LatLng`), bukan variabel berbeda antara kamera, marker, dan form.

### 6.3 User detail location section

- Embedded OSM preview dengan marker non-draggable; radius dan ukuran mengikuti komposisi Detail Destinasi yang terakhir disetujui; jangan mengganti hero, carousel, CTA Daftar Kunjungan, atau susunan lain.
- Label **Lokasi Destinasi**, alamat yang benar, CTA **Buka Google Maps**, attribution yang tetap terlihat di viewport.
- Jika belum ada koordinat, jangan render peta ke pusat Indonesia seolah itu destinasi.
- Responsif pada layar 320–430 dp dan edge-to-edge/status bar; map preview tidak menyebabkan overflow ataupun scroll conflict.

## 7. Domain model & migration MySQL

**Pertahankan kolom `destinations.location`** sebagai alamat user-facing yang dapat diedit. Tambahkan:

| Field | Tipe yang disarankan | Null | Keterangan |
|---|---|---|---|
| `latitude` | `DECIMAL(10,7)` | Ya | [-90, 90] |
| `longitude` | `DECIMAL(10,7)` | Ya | [-180, 180] |
| `location` | existing `VARCHAR(255)` | Existing | Alamat final; **jangan rename/drop** |

- Coordinate pair invariant: `latitude` dan `longitude` harus **keduanya null atau keduanya valid**. Gunakan validation serta, jika sesuai versi database, CHECK constraint yang aman.
- Pada **create baru**, wajib mempunyai kedua koordinat dan alamat yang tidak kosong.
- Pada **edit legacy**, null pair diizinkan bila lokasi peta belum diset dan user hanya mengubah metadata lain. Begitu memilih pin, simpan keduanya sekaligus.
- Jangan mengisi koordinat dummy, `0,0`, atau default Indonesia ke record legacy.
- Pada read JSON, serialize `latitude` dan `longitude` sebagai angka (number) atau nullable; jika Eloquent menyimpan decimal sebagai string, normalisasi di `DestinationResource` dan buat parser Flutter toleran `num`/numeric-string dari legacy.
- Tidak memerlukan FK atau tabel baru untuk satu lokasi destinasi. Jangan menyimpan Google Maps URL sebagai sumber kebenaran; bentuk link dari koordinat saat tap.
- Audit migration/database seed/reseeding dan schema sebelum mengubahnya. Tidak mengubah relasi GUID, kuota per tanggal, timezone, atau galeri.

## 8. API contract

### 8.1 Destination create/update (existing routes)

| Method | Route existing | Tujuan |
|---|---|---|
| `POST` | `/api/v1/destinations` | Create; koordinat + alamat ikut operasi create & gallery |
| `PATCH` | `/api/v1/destinations/{destination_guid}` | Update; koordinat + alamat ikut operasi edit, mempertahankan transport galeri yang sudah ada |
| `GET` | `/api/v1/destinations/{destination_guid}` | User read; mengembalikan koordinat jika tersedia |
| `GET` | `/api/v1/admin/destinations/{destination_guid}` | Admin edit hydration, termasuk destinasi tidak aktif sesuai existing policy |

Contoh **potongan** fields yang disertakan dalam payload create/update, bukan pengganti fields existing:

```json
{
  "location": "Alamat yang disesuaikan admin, Yogyakarta, DIY",
  "latitude": -7.8012345,
  "longitude": 110.3645678
}
```

Contoh **potongan** data response:

```json
{
  "guid": "<existing-destination-guid>",
  "name": "Nama Destinasi",
  "location": "Alamat yang disesuaikan admin, Yogyakarta, DIY",
  "latitude": -7.8012345,
  "longitude": 110.3645678
}
```

- Gunakan field persis `location`, `latitude`, `longitude` di Flutter, Request validation, model, service, resource, dan docs.
- POST mengharuskan ketiga field. PATCH: `sometimes` sesuai existing semantics; bila salah satu coordinate dikirim, field pasangannya juga harus ada dan valid. Saat edit form normal, kirim kedua coordinate agar tidak ambiguous.
- Reject invalid range, nonnumerik, nonfinite, coordinate parsial, alamat kosong/terlalu panjang dengan `422` standar aplikasi (field-level errors).
- Reuse existing `DestinationGalleryRequest` yang extends `CatalogRequest`, `CatalogService::destinationSave`, `DestinationGalleryService`, serta galeri operation idempotency / optimistic gallery version.
- Jika upload manifest ada, metadata lokasi **harus** ikut hash/fingerprint operasi existing. Retry sama: payload dan Idempotency-Key sama. Jika lokasi berubah, fingerprint dan operation key harus berbeda, tidak menggandakan destinasi.
- Saat terjadi `gallery_version_conflict`, perubahan lokasi tidak boleh ikut ter-commit secara parsial.
- Public read response mengembalikan lokasi yang stabil untuk semua user setelah save.

### 8.2 Reverse geocoding — adapter backend ringan (usulan implementasi)

**Endpoint baru (read-only, bukan mutation):**

`GET /api/v1/admin/geocoding/reverse?latitude=-7.8012345&longitude=110.3645678`

Auth: `role` 1/2 dan akun aktif. Response contoh:

```json
{
  "data": {
    "latitude": -7.8012345,
    "longitude": 110.3645678,
    "formatted_address": "Alamat perkiraan dari OpenStreetMap",
    "provider": "nominatim"
  }
}
```

- Peta `flutter_map` mengambil **tile imagery** dari tile provider; reverse geocoding **bukan** fitur bawaan `flutter_map` dan harus memakai layanan terpisah.
- Untuk demo kampus dapat memakai public Nominatim dengan **kontrol server-side terpusat**; jangan minta alamat pada tiap pan/drag dan jangan kirim setiap request langsung dari setiap perangkat tanpa pembatasan global.
- Public Nominatim: maksimum absolut **1 request/detik** lintas seluruh aplikasi, User-Agent valid mengidentifikasi aplikasi, attribution, caching, tidak untuk autocomplete; gunakan backoff pada 429/503. Pertimbangkan service pihak ketiga atau self-host bila skalanya meningkat.
- Endpoint reverse hanya dipanggil setelah titik dipilih dan stabil / drag selesai, sekali per titik (cache), bukan saat map recenter saja. Kunci berdasarkan presisi yang sesuai, tidak melakukan query grid sistematis.
- Backend perlu timeout singkat, error pemetaan (`429`, `502`, `503`), dan pesan fallback agar Flutter dapat mengizinkan alamat manual. Jangan log lokasi GPS user secara tidak perlu.
- Jika provider unavailable, koordinat dari pin **tidak berubah**. Reverse endpoint tidak menyimpan data destinasi.
- Konfigurasi provider di env/config; jangan hardcode identitas sensitif/credential di aplikasi. Proxy Laravel mempermudah kontrol rate lintas client dan pergantian provider.

### 8.3 External Google Maps navigation

Saat user tap, bangun URI dari koordinat valid, misalnya:

```text
https://www.google.com/maps/dir/?api=1&destination=-7.8012345%2C110.3645678
```

Gunakan `url_launcher` (atau util external launcher yang sudah ada), `LaunchMode.externalApplication`, browser fallback, validasi URL yang dibangun dari numeric pair (bukan user-supplied URL), dan tangani `launchUrl` gagal dengan pesan non-blocking. URL ini tidak berarti aplikasi menggunakan Google Maps SDK untuk peta internal.

## 9. Third-party usage, biaya, attribution, privacy

Provider peta pilihan: **OpenStreetMap tiles** lewat `flutter_map`. Untuk V1/demonstrasi kecil dapat mengonfigurasi tile provider OSM sesuai kebijakannya, **bukan jaminan gratis tanpa batas**. Kebijakan OSM tile mensyaratkan attribution yang terlihat, identifikasi User-Agent aplikasi, cache mengikuti header, melarang bulk prefetch/offline downloading dari server publik. Jangan menambahkan prefetch viewport massal sebagai 'optimisasi'. Sediakan konfigurasi URL/attribution agar tile service bisa diganti bila perlu. Policy publik Nominatim berbeda dari tile policy.

Referensi resmi:
- https://operations.osmfoundation.org/policies/tiles/
- https://operations.osmfoundation.org/policies/nominatim/
- https://docs.fleaflet.dev/usage/programmatic-interaction

Pertahankan privacy: GPS hanya diminta eksplisit untuk **Lokasi Saya**; posisi perangkat bukan data destinasi dan tidak dikirim dalam save kecuali Admin benar-benar menempatkan pin lalu mengonfirmasi.

## 10. Conditional state & edge cases

| Kondisi | Respons wajib |
|---|---|
| Create, belum pilih pin | Form belum dapat disimpan; tampilkan error **Pilih titik lokasi terlebih dahulu** |
| Create, pin valid + alamat otomatis | Bisa konfirmasi dan simpan bersama form |
| Pin valid, reverse geocode gagal/no result | Tampilkan fallback manual address; coordinate tetap |
| Alamat manual diubah | `location` berubah, latitude/longitude tidak berubah |
| Pin dipindah setelah alamat lama terisi | Tandai address stale; geocode ulang untuk titik baru, minta verifikasi jika memakai alamat manual |
| Respons geocoder lama datang setelah pin terbaru | Abaikan respons lama, jangan overwrite alamat/pin terbaru |
| Batal dari picker | Form utama tetap persis sebelum picker dibuka |
| GPS ditolak, disabled, timeout | Pesan singkat; pemilihan pin manual tetap ada |
| Admin tekan Lokasi Saya | Kamera recenter; **tidak auto-save / tidak otomatis mengunci pin** |
| Offline/OSM tiles gagal | Feedback peta gagal dimuat; jangan fake marker; existing confirmed coordinates tetap ada bila tersedia |
| Geocoding rate limit | Jangan infinite retry; tawarkan input manual |
| Invalid coordinate (NaN, infinity, out of range) | Client guard + backend `422`, jangan simpan |
| Koordinat parsial hanya lat/tanpa lon | Backend `422` |
| Destinasi legacy: address ada, coords null | Tampilkan alamat; map preview dan navigasi disembunyikan/dinyatakan belum tersedia |
| Edit legacy metadata saja | Boleh simpan tanpa memaksa membuat koordinat |
| Map user gagal load | Lokasi teks dan tombol Google Maps tetap tersedia jika coords valid |
| Google Maps app tidak terpasang | Fallback browser eksternal |
| Browser/URL gagal | Snackbar **Tidak dapat membuka peta**; layar tetap stabil |
| 401/403 saat geocode atau save | Ikuti auth/session guard existing; jangan leak data, jangan auto-retry mutation tanpa validasi sesi |
| 422 save | Field `location`/`latitude`/`longitude` error inline, draft tetap |
| 409 gallery conflict | Reload/resolve dengan flow galeri existing; jangan kehilangan draft pin/address |
| Request save timeout | Gunakan existing idempotency/reconciliation behavior; jangan membuat duplikat, retain form |
| Form lain berubah ketika picker terbuka | Picker hanya mengganti field lokasi, tidak reset kategori, harga, gambar, quota, fasilitas |
| App resume / rotasi / viewport kecil | Rebuild aman; confirmed draft tidak hilang tanpa tindakan user |

## 11. Technical plan & kandidat file

### 11.1 Flutter repo (`koyotot/pami-wisata-daearahku`)

**Pasti relevan / existing confirmed:**
- `pubspec.yaml`: dependency `flutter_map`, `latlong2`, `url_launcher`, util GPS seperti `geolocator` bila belum tersedia; pilih versi kompatibel dengan SDK Flutter lokal, jangan asal upgrade besar.
- `lib/screens/tambah_wisata_screen.dart`: seksi alamat+map preview+launch picker; hydrate form; draft handling; state `dirty`, `canSave`, form validation; payload `location`/`latitude`/`longitude`; update operation fingerprint/idempotency.
- `lib/screens/detail_wisata_screen.dart`: embedded read-only OSM preview, address, external navigation, no-coordinates state.
- `lib/data/business_repository.dart`: nullable getters koordinat, numeric parsing, API reverse helper (jika pola service/repo existing mendukung).
- `lib/data/app_data.dart`/model display adapters terkait: hanya jika `Wisata` wrapper/mapper masih dibutuhkan di UI untuk `latitude`/`longitude`.
- `lib/services/auth_session.dart` / existing API client: reuse authenticated request dan stale-session guard; jangan ubah token flow.
- `docs/DESIGN.md`: baca sebagai desain otoritatif, jangan overwrite hanya demi feature ini.

**File baru yang disarankan (sesuaikan struktur aktual sebelum membuat):**
- `lib/screens/destination_location_picker_screen.dart`: full-screen picker, pin placement, GPS recenter, pending/confirmed state.
- `lib/widgets/destination_location_map_preview.dart`: reusable tile+marker preview jika benar-benar dipakai oleh form dan detail.
- `lib/services/location_geocoding_service.dart`: adapter reverse API atau letakkan di repository jika pemisahan ini redundan.
- `lib/models/destination_coordinates.dart`: optional typed value object `lat/lon`, validasi, equality, formatting; buat hanya jika membantu, bukan wajib.

**Catatan:** Bila `docs/DESIGN.md` lebih tua daripada UI V4 terbaru, pertahankan UI aktual yang telah disetujui sembari mengikuti intent desain; jangan downgrade scenic header/hero gallery/edge-to-edge.

### 11.2 Laravel repo (`joytimess/pami-wisata-daerahku-api`)

**Existing confirmed:**
- `database/migrations/*`: migration baru add `latitude`, `longitude` nullable.
- `app/Models/Destination.php`: `$fillable` + casts, coordinate pair invariant.
- `app/Http/Requests/CatalogRequest.php`: validasi create/update `latitude` dan `longitude` serta pair consistency.
- `app/Http/Requests/DestinationGalleryRequest.php`: inherit existing rules; pastikan coordinate ikut save gallery path tanpa menabrak manifest/idempotency.
- `app/Http/Resources/DestinationResource.php`: expose `latitude`, `longitude` pada public/admin reads.
- `app/Services/CatalogService.php` & `app/Services/DestinationGalleryService.php`: pastikan `destinationSave` menerima koordinat dalam transaction yang sesuai.
- `app/Http/Controllers/Api/V1/DestinationController.php`: tetap thin, jangan pindahkan logika geocoding ke controller ini.
- `routes/api.php`: tambah satu route admin geocoding yang dikelompokkan rapi menurut modul, tidak mengacak grouping route lainnya.

**File baru opsional yang disarankan:**
- `app/Http/Controllers/Api/V1/LocationGeocodingController.php` (thin).
- `app/Http/Requests/ReverseGeocodingRequest.php` (authorize role + validation lat/lon).
- `app/Services/ReverseGeocodingService.php` (HTTP client, cache, rate limit, provider response normalization).
- `config/geocoding.php` (provider URL, timeout, app identification, caching/limits; konfigurasi via env).
- `docs/destination-location-picker-v1.md` (ringkasan implementasi dan API contract); atau update dokumentasi backend yang sudah ada.

**Jangan** memperkenalkan DB lokasi terpisah, role baru, Google Maps API key, Firestore, atau dependency Firebase tambahan.

## 12. Implementation sequence (minimum agents)

**Agent plan maksimal 2 spesialis, tanpa orkestrasi bolak-balik:**
1. `backend_engineer`: migration + Request + Resource + endpoint reverse geocoding + save integration + dokumentasi API. Pastikan security/role/legacy/idempotency.
2. `flutter_engineer` (primary UI owner): OSM picker + map preview + GPS + form draft/save integration + user detail + URL launcher. Baca `docs/DESIGN.md` dan gunakan skill `tasteskill`/mobile design hanya jika benar-benar tersedia di lingkungan Codex; jangan mengarang path skill.

Kerjakan backend contract dahulu atau koordinasikan kontraknya secara sederhana, baru Flutter. `master_orchestrator`, `system_architect`, `qa_engineer`, `security_auditor` tidak perlu dipanggil otomatis. Jika agen yang tersedia di Codex tidak mendukung delegasi efisien, satu implementer boleh menangani kedua repo. **Tidak perlu full `flutter test`, full `php artisan test`, mass analyze, regression QA, commit/push/deploy** kecuali diminta.

## 13. Acceptance checklist (manual/targeted saja)

- [ ] Create: tap pin, alamat autofill; alamat manual edit; simpan; detail menampilkan lokasi sama.
- [ ] Create: geocode error, isi alamat manual, tetap sukses tanpa perubahan coordinate.
- [ ] Edit: load existing pin; pindah; batal → lokasi unchanged; konfirmasi dan simpan → lokasi baru muncul.
- [ ] Edit legacy: tanpa coords tidak crash, update metadata nonlokasi tetap berhasil, tidak mengarang pin.
- [ ] User: preview pin sesuai backend, Google Maps URL menunjuk lat/lon benar.
- [ ] User: no-coordinate state tidak menunjuk ke pusat Indonesia.
- [ ] GPS denied dan tile unavailable tidak memblokir manual pin flow ketika map masih bisa dipakai.
- [ ] Server menolak coordinate parsial/range salah; tidak memberi akses mutation pada role 0.
- [ ] Save gallery + location tetap satu operasi, konflik/retry tidak memicu partial update ataupun duplicate.
- [ ] Tampilan tidak overflow pada perangkat target; desain lama yang tidak relevan tetap utuh.

Pemeriksaan cukup dari smoke manual create/edit/read/navigation dan **targeted checks jika menyentuh logic kritis**; tanpa QA agent atau full regression.

## 14. Open technical decisions delegated to implementer (bukan pertanyaan tambahan untuk user)

1. Tentukan plugin versions sesuai Flutter SDK dan dependencies aktual, bukan asumsi dokumen publik.
2. Gunakan tile provider OSM demo yang patuh policy; buat penggantian provider configurable untuk kebutuhan skala lain.
3. Gunakan HTTP/caching/rate-limit yang tersedia pada Laravel lokal; pastikan limit Nominatim berlaku global, bukan 1 req/s per proses worker.
4. Tentukan apakah provider reverse cukup Nominatim demo atau adapter provider configurable. Kontrak ke Flutter tidak berubah.
5. Pastikan path create/update multipart/`_method` bekerja dengan payload koordinat dan gallery existing; jangan mengubah semantics request.
6. Migrasi DB dengan safe defaults untuk legacy dan siapkan rollback.

## 15. Source of truth & implementation guardrails

1. **Repo lokal saat eksekusi menang atas snapshot GitHub** bila terdapat perubahan uncommitted/branch lain. Jangan overwrite user changes.
2. **`docs/DESIGN.md` + UI aktual** adalah sumber visual. PRD ini sumber feature behavior.
3. `location` = alamat; `latitude`/`longitude` = koordinat sumber kebenaran. Tidak ada auto reverse mismatch silent.
4. Laravel pemilik persistent state; Flutter pemilik interactive draft state.
5. Pertahankan idempotency gallery, 1–10 images, optimistic `gallery_version`, booking/kuota, dan session guard.
6. Setelah implementasi, berikan daftar file berubah, contract API, migration command, dan langkah smoke test singkat. **Tidak commit atau push otomatis.**

---

**Selesai — PRD V1 approved for direct implementation.**
