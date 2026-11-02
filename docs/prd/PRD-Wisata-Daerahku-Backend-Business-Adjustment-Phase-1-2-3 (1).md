# PRD — Wisata Daerahku: Backend Business Adjustment (Phase 1 + 2 + 3)

**Tanggal:** 2 November 2026  
**Proyek:** Wisata Daerahku — Laravel 13 REST API + MySQL  
**Target repository lokal:** `C:\laragon\www\pami-wisata-daerahku-api`  
**Status:** Specification / implementation brief — **belum diimplementasikan melalui dokumen ini**  
**Prioritas:** High  
**Cakupan:** Backend only; tiga fase diimplementasikan dalam satu delivery terintegrasi.

## 1. Latar belakang dan tujuan

Pengujian manual role **User (0)** menemukan tiga kebutuhan business logic:

1. Fasilitas pada Detail Wisata harus berasal dari **master fasilitas** yang dikelola **Superadmin (2)**. **Admin (1)** hanya dapat memilih fasilitas master untuk destinasi yang dapat dikelolanya. User hanya melihat fasilitas yang benar-benar terasosiasi dengan destinasi tersebut.
2. Pada pemesanan kunjungan, nomor telepon harus memiliki **kode negara**, disimpan dalam format kanonis, dan **mengisi `users.phone` hanya bila belum ada**; jangan pernah overwrite nomor profil yang sudah tersimpan melalui proses booking.
3. Kalender pemilihan tanggal harus memperoleh **ketersediaan kuota seluruh tanggal dalam satu bulan** melalui API, sehingga user tidak perlu mengecek tanggal satu per satu. API membedakan sisa kuota tinggi, sedang, rendah, habis, dan tanggal tanpa konfigurasi.

**Target akhir:** kontrak API siap dikonsumsi Flutter pada fase berikutnya, dengan otorisasi, integritas MySQL, dan perilaku booking existing tetap aman. Pengujian manual dilakukan oleh pemilik produk; agent wajib menjalankan **automated backend tests**, bukan mengklaim telah melakukan manual UI testing.

## 2. Scope dan non-goals

### In scope

- Migration additive tabel `facilities` dan `destination_facilities`; model, relasi, policy, service, controller, FormRequest, API Resource, pengujian.
- CRUD master fasilitas khusus Superadmin; daftar fasilitas aktif untuk pilihan Admin; relasi pilihan fasilitas pada destinasi; serialisasi fasilitas dinamis di detail destinasi.
- Validasi/normalisasi telepon nomor internasional untuk kontak booking; sinkronisasi kondisional ke `users.phone` dalam alur create booking; pertahankan snapshot kontak booking.
- Endpoint ketersediaan kuota **per destinasi per bulan**, status per tanggal dan level persentase tersisa; integrasi dengan perhitungan alokasi kuota yang sudah berlaku.
- Dokumentasi perubahan kontrak dan pengujian integritas, role/ownership, concurrency, regresi.

### Out of scope — jangan disentuh

- **Flutter/UI/UX**, perubahan kalender visual, pemformatan text field Flutter, screenshot, mockup, desain ulang.
- **Payment gateway, checkout, refund, webhook**, atau perubahan urutan booking → pembayaran → approval. Keinginan produk adalah pembayaran sebelum approval **nanti**, sebagai PRD/fase tersendiri. **Pertahankan state machine booking existing untuk delivery ini**.
- Perubahan auth, OTP, Google Sign-In, JWT/refresh, Firebase FCM, review/check-in, invoice/tiket, atau penggantian stack.
- Reset database, `migrate:fresh` pada data pengguna, backfill spekulatif, migrasi destruktif, deploy produksi otomatis.

## 3. Instruksi wajib untuk Codex, agents, dan skills

**Jalankan orkestrasi dengan `master_orchestrator` (Ponytails bila tersedia)**, bukan menugaskan seluruh pekerjaan pada satu agent tanpa review.

Konfigurasi agent **global di perangkat user**, bukan diasumsikan berada di repository:

| Agent | Path global yang harus ditemukan/dibaca oleh runtime implementasi | Mandat |
|---|---|---|
| `master_orchestrator` | `C:\Users\itsba\.codex\agents\master_orchestrator.toml` | Lead; audit, breakdown, urutan kerja, integrasi, final handoff |
| `system_architect` | `C:\Users\itsba\.codex\agents\system_architect.toml` | Kontrak API, schema dan batas transaksi; review kesesuaian state machine |
| `backend_engineer` | `C:\Users\itsba\.codex\agents\backend_engineer.toml` | Implementasi migration, model, policy, FormRequest, service, controller, resource, routes |
| `security_auditor` | `C:\Users\itsba\.codex\agents\security_auditor.toml` | Authorization, privilege escalation, phone PII, overposting, race/TOCTOU |
| `qa_engineer` | `C:\Users\itsba\.codex\agents\qa_engineer.toml` | Feature, API contract, regression, dan MySQL concurrency tests |

**Perintah agent:** baca `AGENTS.md`, konfigurasi Codex, `composer.json`, `routes/api.php`, migrasi, code domain saat ini, dan skill relevan yang benar-benar tersedia **sebelum edit**. Terapkan skill **Ponytails** jika ditemukan; jangan mengklaim skill/agent aktif bila runtime tidak menyediakannya. Jika tidak tersedia, lanjutkan dengan pembagian kerja setara dan laporkan keterbatasan. Architect tetapkan kontrak dahulu; Backend implement; QA dan Security mengaudit hasil; Orchestrator integrasikan. Jangan dua agent mengedit file yang sama serentak.

**Arsitektur wajib:** `routes/api.php → FormRequest → Controller tipis → Service domain → API Resource`. Gunakan Policy/middleware untuk akses; transaksi/aturan bisnis dalam Service. Reuse utilitas domain dan format response existing; jangan menciptakan lapisan generic repository atau abstraksi berlebihan.

## 4. Baseline audit — wajib sebelum perubahan

Orchestrator + Architect harus membuktikan melalui source lokal:

- Nama model/tabel/PK/FK aktual (`guid`, `*_guid`) untuk User, Destination, DestinationQuota, Booking, kategori, dan relasi relevan; **jangan berasumsi seluruh rancangan PRD awal sudah identik dengan migrasi nyata**.
- Endpoint dan JSON Resource yang sudah dipakai mobile, termasuk detail destinasi, create/update destinasi, quota list, dan create booking. Hindari breaking API tanpa alasan.
- Kolom `users.phone` dan snapshot booking (`visitor_phone` atau nama aktual), validator telepon, serta aksi create booking/idempotency yang sudah ada.
- Status booking **yang benar-benar menghitung hold kuota** beserta expiry/cancellation/approval; apakah angka `remaining` berasal dari counter atau agregasi booking. Pakai **satu sumber kebenaran** yang sama dengan validasi booking.
- Policy role 0/1/2, scope kepemilikan destinasi Admin, otorisasi Superadmin, schema MySQL, fitur test MySQL yang tersedia.

Cantumkan hasil audit ringkas di laporan implementasi: *verified current behavior → proposed adjustment → changed contract → regression risk*.

## 5. Phase 1 — Master fasilitas dinamis

### 5.1 Business rules

- **Superadmin (role 2):** membuat, membaca termasuk nonaktif, mengubah, menonaktifkan/mengaktifkan master fasilitas. Nama dan `slug` unik dan terkontrol. Dilarang membuat entri duplikat karena perbedaan kapital/spasi.
- **Admin (role 1):** hanya membaca master yang **aktif** dan **memilih/melepas** fasilitas untuk destinasi yang memang berhak dikelola; tidak dapat CRUD master fasilitas.
- **User (role 0) dan publik sesuai policy katalog existing:** membaca daftar fasilitas suatu destinasi lewat response detail; tidak boleh mengubahnya.
- Fasilitas destinasi berasal **hanya dari pivot database**, tidak dari hardcoded fallback universal. Destinasi tanpa relasi menampilkan `facilities: []`; jangan otomatis mengasosiasikan semua fasilitas.
- Menonaktifkan master melarang pilihan baru dan menyembunyikannya dari daftar fasilitas aktif publik; **hubungan historis tetap tersimpan** agar dapat diaktifkan lagi. Reaktivasi tidak boleh menciptakan relasi duplikat.
- Untuk keamanan, `icon_key` opsional hanya boleh dari allowlist identifier icon yang disepakati; jangan menerima SVG/HTML mentah atau URL arbitrer dari client. Jika belum ada katalog icon, return `icon_key: null`; Flutter dapat memakai ikon fallback kelak.

### 5.2 Schema yang diusulkan (adaptasikan ke schema aktual)

**`facilities`:** `guid` PK UUID, `name` varchar, `slug` unique, `icon_key` nullable, `is_active` bool default true, timestamps. Normalisasi nama/slug, validasi panjang, indeks status untuk katalog.

**`destination_facilities`:** `guid` PK UUID (menjaga konvensi domain), `destination_guid` FK → destinations, `facility_guid` FK → facilities, timestamps, `UNIQUE(destination_guid, facility_guid)`, indeks balik `facility_guid`. FK dan tipe mengikuti tipe kolom sumber. Gunakan `ON DELETE RESTRICT` untuk master jika DB mengizinkan hard delete; tidak ada cascade penghapusan fasilitas master yang masih direferensikan.

**Migration:** additive dan backward-compatible. Jangan menyentuh kolom existing yang belum diaudit. Tidak melakukan seed fasilitas produksi atau backfill tanpa data sumber valid. Data baru dibuat lewat endpoint Superadmin; fixtures test boleh memakai factory.

### 5.3 Draft API contract (finalkan setelah audit routes existing)

| Method / proposed path | Role | Perilaku |
|---|---|---|
| `GET /api/v1/facilities` | Admin/Superadmin | Daftar aktif, urut deterministik untuk selector |
| `GET /api/v1/superadmin/facilities` | Superadmin | Daftar termasuk nonaktif, filter/paginasi sesuai pola existing |
| `POST /api/v1/superadmin/facilities` | Superadmin | Buat master |
| `PATCH /api/v1/superadmin/facilities/{facility_guid}` | Superadmin | Ubah `name`, `icon_key`, `is_active`; slug stabil kecuali kebutuhan perubahan eksplisit |
| `DELETE /api/v1/superadmin/facilities/{facility_guid}` | Superadmin | **Logical deactivation**, bukan physical delete |
| `PUT /api/v1/admin/destinations/{destination_guid}/facilities` | Admin pemilik scope / Superadmin | Atomic replace daftar fasilitas berdasarkan `facility_guids` |
| `GET /api/v1/destinations/{destination_guid}` | Sesuai katalog existing | Response detail menyertakan `facilities` yang aktif dan terasosiasi |

Path bersifat **proposed**, bukan klaim sudah ada. Jika rute CRUD destinasi saat ini lebih cocok diperluas `facility_guids` dalam create/update, utamakan kompatibilitas dan dokumentasikan kontrak aktual; hindari membuat dua cara update yang tidak konsisten.

**Request assign:** `facility_guids` array GUID unik. Semua GUID harus exist, aktif, dan tidak duplicate; reject keseluruhan request bila ada invalid/inactive/foreign. `[]` sengaja menghapus seluruh hubungan. Jika ditambahkan ke PATCH destinasi yang lebih umum, field **absen = no change**. Sync atomik di transaction, diproteksi policy destinasi.

**Response detail contoh (hanya bagian fasilitas):**

```json
{
  "data": {
    "guid": "<destination_guid>",
    "facilities": [
      {"guid": "<facility_guid_1>", "name": "Parkir", "slug": "parkir", "icon_key": "parking"},
      {"guid": "<facility_guid_2>", "name": "Toilet", "slug": "toilet", "icon_key": null}
    ]
  }
}
```

Contoh adalah **potongan field**, bukan menggantikan seluruh resource detail existing. Urutan stabil (misalnya nama ASC, GUID tie-breaker); gunakan eager loading untuk mencegah N+1.

## 6. Phase 2 — Nomor telepon booking dan profil

### 6.1 Business rules

1. User role 0 dapat memasukkan nomor dengan country code (default UX Indonesia +62 nanti di Flutter); backend menerima **nomor internasional valid**, bukan hanya dekorasi `+62` di UI.
2. **Simpan bentuk kanonis E.164**, misalnya nomor tampilan `+62 896-5586-2781` menjadi `+6289655862781`. Spasi dan tanda hubung hanya presentasi; pastikan negara/nomor benar. Gunakan library parsing yang sudah terpasang jika ada; jika menambah library baru, jelaskan kebutuhan dan test kasus Indonesia serta internasional. Jangan hanya menghapus semua karakter lalu menganggap nomor valid.
3. Pada create booking sukses, simpan nomor yang digunakan user pada **snapshot kontak booking** (`bookings.visitor_phone` atau kolom aktual). Snapshot tidak ikut berubah ketika user memperbarui profil di kemudian hari.
4. **Hanya ketika `users.phone` `NULL` atau string kosong/whitespace**, isi dengan nomor booking valid. Bila sudah berisi, **tidak boleh overwrite**, meskipun nomor input booking berbeda. Jangan mengubah `users.name`/email tanpa permintaan produk.
5. User tidak bisa menentukan `user_guid`, `role`, atau nomor orang lain untuk disinkronkan. Ambil identitas dari JWT. Nomor pada form booking tetap boleh berbeda dari telepon profil (snapshot), tetapi profil yang sudah ada tidak disentuh.
6. Input invalid → 422 tanpa membuat booking dan tanpa menulis profil. Create booking gagal/rollback → nomor profil tidak berubah. Idempotency retry dengan request sama tidak menggandakan booking atau perubahan profil.

### 6.2 Integrasi transaksi dan konkurensi

Integrasikan ke service **create booking existing** setelah validasi request, otorisasi, dan validasi kuota. **Gunakan conditional update atomik (compare-and-set)**, bukan pola rentan `if (!$user->phone) $user->save()`:

```sql
UPDATE users
SET phone = :normalized_phone, updated_at = :now
WHERE guid = :authenticated_user_guid
  AND (phone IS NULL OR TRIM(phone) = '');
```

Jalankan di **transaksi booking yang sama** dan pastikan urutan lock konsisten dengan transaksi alokasi kuota existing. Satu dari dua request paralel yang valid bisa menjadi penulis pertama; yang lain tidak boleh overwrite. Jangan menambah nested transaction/lock yang berisiko deadlock; review bersama Architect. Jika existing `users.phone` berisi nomor lama/format legacy, **jangan backfill atau normalisasi massal diam-diam**.

**Catatan struktur:** jangan menambah kolom `bookings.visitor_phone` jika sudah ada; gunakan nama/tipe aktual setelah audit. `users.phone` harus nullable dan cukup panjang untuk format E.164. Perubahan schema hanya bila dibutuhkan dan harus non-destruktif.

### 6.3 Kontrak API

- **Utamakan endpoint create booking existing**, tambah/selaraskan input `visitor_phone` berbentuk E.164; jika sebelumnya menerima format nasional, sediakan normalisasi kompatibel atau pesan validasi yang eksplisit.
- Response booking tetap memakai struktur dan status existing; jangan menambah side effect pembayaran.
- `GET /api/v1/auth/me` atau endpoint profil existing menampilkan `phone` terbaru milik user sendiri melalui resource yang sudah ada; nomor user tidak boleh masuk response publik destinasi ataupun log sensitif.
- Error field `visitor_phone`: 422; konflik kapasitas booking: 409 sesuai error contract existing.

## 7. Phase 3 — API kalender ketersediaan kuota per bulan

### 7.1 Kontrak read API yang diusulkan

`GET /api/v1/destinations/{destination_guid}/availability?month=2026-11&quantity=1`

- `month` **wajib** `YYYY-MM`, satu bulan saja. Invalid month → 422; batasi horizon tanggal sesuai kebijakan booking existing, bukan rentang tahun bebas tanpa kontrol.
- `quantity` **opsional**, integer positif (default 1) dengan batas wajar mengikuti validasi quantity booking yang sudah ada.
- Akses mengikuti policy daftar/detail destinasi aktif dan publikasi existing; inactive/hidden tidak boleh bocor lewat endpoint availability.
- Berikan **setiap tanggal** dalam bulan tersebut (28–31 data), termasuk yang **belum dikonfigurasi**. `visit_date` memakai timezone destinasi, bukan tanggal UTC hasil konversi sembarang.
- Response tidak berisi identitas, daftar booking orang lain, atau informasi pembayaran.

**Contoh JSON terpotong (ilustrasi, bukan data aktual server):**

```json
{
  "data": {
    "destination_guid": "<destination_guid>",
    "month": "2026-11",
    "timezone": "Asia/Jakarta",
    "requested_quantity": 1,
    "dates": [
      {"date":"2026-11-06","capacity":0,"remaining":0,"remaining_percent":null,"level":"none","status":"unavailable","bookable":false},
      {"date":"2026-11-07","capacity":200,"remaining":200,"remaining_percent":100.0,"level":"high","status":"available","bookable":true},
      {"date":"2026-11-08","capacity":null,"remaining":null,"remaining_percent":null,"level":"none","status":"unconfigured","bookable":false}
    ]
  }
}
```

Resource nyata harus berisi seluruh tanggal bulan, berurut ASC. Null bermakna **tidak ada denominator / belum ditentukan**, bukan unlimited. Kolom database asli tetap `capacity`; `remaining` adalah sisa alokasi valid, bukan kuota acak.

### 7.2 Perhitungan dan warna semantik

Untuk `capacity > 0`:

`remaining = max(0, capacity - allocated_quantity)`  
`remaining_percent = (remaining / capacity) * 100`

`allocated_quantity` **harus menggunakan definisi status booking yang saat ini benar-benar menahan kuota**. Gunakan service/query existing yang sama, jangan membuat logika berbeda dari saat create booking. Status yang sudah expired **tetapi belum direkonsiliasi secara persisten** tidak boleh tiba-tiba diabaikan dan menimbulkan oversell. Jika counter sudah tersedia, ikuti invariants counter existing dan uji akurasinya.

| Kondisi | `level` | `status` / `bookable` |
|---|---|---|
| `remaining_percent > 60` | `high` (hijau di Flutter nanti) | `available`, selama booking window valid dan `remaining >= quantity` |
| `35 <= remaining_percent <= 60` | `medium` (kuning) | `available`, dengan aturan sama |
| `0 < remaining_percent < 35` | `low` (merah) | **Masih dapat booking** bila sisa mencukupi; merah bukan sama dengan sold out |
| `remaining = 0` pada kapasitas positif | `none` | `sold_out`, `bookable: false` |
| `capacity = 0` (terkonfigurasi) | `none`, persen null | `unavailable`, `bookable: false` |
| Tidak ada quota record untuk tanggal tersebut | `none`, angka null | `unconfigured`, `bookable: false` |
| Kuota positif tetapi belum dibuka/sudah tutup/melewati cutoff | Level tetap berdasarkan sisa | `closed`, `bookable: false` |

Tepat 60% **kuning**, tepat 35% **kuning**. Tidak boleh bagi nol. Jangan hardcode warna hex pada backend: kirim **level semantik**, Flutter yang memetakan warna serta teks aksesibel. Pada kondisi `remaining > 0` tetapi `remaining < requested_quantity`, `bookable: false` (status boleh `insufficient_quantity`, didokumentasikan secara konsisten); jangan melaporkan stok habis secara keliru.

**Penting:** angka kalender adalah snapshot informatif. Saat membuat booking, server wajib **menghitung ulang dan mengunci kuota dalam transaksi**. API kalender **tidak** menjamin tiket sampai booking berhasil.

### 7.3 Query dan performa

- Gunakan range satu bulan pada `destination_quotas.visit_date`, satu query terindeks untuk quota + agregasi alokasi (atau bentuk efisien lain), bukan 31 query per tanggal.
- Index minimal existing `UNIQUE(destination_guid, visit_date)` dan index relevan booking `(destination_quota_guid, status)` bila belum ada, berdasarkan `EXPLAIN`/audit skema nyata.
- Bangun daftar tanggal bulan di layer service, isi `unconfigured` untuk tanggal tanpa record. Cegah N+1 dan query untuk setiap booking.
- Hindari cache lintas request pada tahap ini bila tidak ada invalidasi saat quota, booking, approval, expiry; jika menggunakan cache existing, invalidasi wajib tercakup pengujian.
- Respon deterministic berdasarkan timezone dan current clock; batas opening/closing booking tetap mengikuti kebijakan bisnis existing.

## 8. Otorisasi dan HTTP errors

| Aksi | User 0 | Admin 1 | Superadmin 2 |
|---|---|---|---|
| Lihat fasilitas destinasi aktif | Ya | Ya | Ya |
| Lihat master fasilitas aktif untuk selector | Tidak perlu | Ya | Ya |
| CRUD master fasilitas | Tidak | Tidak | Ya |
| Assign fasilitas ke destinasi | Tidak | Sesuai scope existing | Sesuai policy existing |
| Buat booking untuk diri sendiri | Ya | Ikuti larangan/izin existing (jangan longgarkan) | Ikuti existing |
| Lihat availability destinasi aktif | Ya | Ya | Ya |

- `401` tanpa autentikasi untuk endpoint protected, `403` jika role tidak memiliki izin, `404` jika resource tidak tersedia/tersembunyi, `422` untuk input invalid, `409` jika konflik state/kuota/aturan referensi.
- Validasi `facility_guids` dengan DB check **dan** verifikasi ulang dalam operasi transaksi yang relevan. Filter mass assignment; `is_active`, foreign key, `role`, `user_guid` tidak boleh dibajak.
- Jangan memercayai client untuk kapasitas, sisa, persentase, status booking, data profil, atau daftar fasilitas yang tidak disahkan.
- Data telepon adalah PII; jangan tampilkan di response publik atau log aplikasi, exception, dan audit metadata mentah.

## 9. File/module yang menjadi kandidat perubahan

Semua path di bawah **kandidat**, bukan klaim file sudah tersedia; temukan nama class/folder aktual sebelum mengubah:

- `routes/api.php`.
- `database/migrations/*create_facilities*`, `*create_destination_facilities*`; migrasi penyesuaian phone/index **hanya jika dibutuhkan**.
- `app/Models/Facility.php` (baru), `Destination.php`, `DestinationQuota.php`, `Booking.php`, `User.php`.
- `app/Policies/*`, `app/Http/Requests/*Facility*`, request create booking existing, `app/Http/Resources/*Facility*`, destination detail resource existing.
- `app/Http/Controllers/Api/V1/*Facility*`, destination controller/update existing, controller availability baru atau existing.
- `app/Services/*Facility*`, destination mutation service existing, booking service existing, quota availability service/query existing.
- `tests/Feature/*Facility*`, `*Booking*`, `*Availability*`, dan test concurrency di folder MySQL existing.
- Dokumentasi kontrak API (README/docs sesuai pola repository).

Tidak perlu membuat file dengan nama serupa jika proyek sudah memiliki class yang sesuai. Jangan merombak modul auth, payment, atau mobile di luar scope.

## 10. Pengujian wajib — QA dan Security gates

**Facilities:**
- Superadmin create/edit/list/activate/deactivate berhasil; role 1/0 tidak dapat CRUD walau mengirim request langsung.
- Slug/label duplikat (termasuk beda kapital/spasi) ditolak; `icon_key` di luar allowlist ditolak; no raw HTML.
- Admin berhasil assign fasilitas aktif ke destinasi dalam scope; GUID tidak valid, tidak aktif, duplikat, atau destinasi di luar scope ditolak **tanpa partial pivot update**.
- `[]` menghapus relasi; field absen pada update umum tidak menghapus relasi; relasi unik tetap unik pada concurrent update.
- Detail destinasi A hanya mengembalikan fasilitas A; destinasi B berbeda; tanpa relasi hasil `[]`; nonaktif tidak terlihat oleh user tetapi pivot tetap ada.

**Phone:**
- `users.phone = NULL` dan `''` → setelah booking sukses menjadi E.164; `users.phone` sudah ada → tetap sama.
- Booking menyimpan snapshot kontak sendiri meski berbeda dari profil; update profil sesudahnya tidak mengubah snapshot.
- Input `+62` valid, nasional Indonesia jika supported, negara lain yang didukung, nomor malformed, panjang tidak valid, whitespace/formatting.
- Booking gagal karena kuota/validasi tidak mengubah phone. Retry idempotency sama tidak menulis booking kedua; dua request paralel dengan phone berbeda tidak bisa overwrite nomor profil yang terisi lebih dahulu.

**Availability:**
- Bulan 28/29/30/31 hari, lintas tahun, invalid `month`, timezone/cutoff, bulan tanpa kuota.
- Perhitungan 100%, >60%, 60%, 35%, <35%, 0%; capacity 0 dan kuota tidak ada. Sample 6 November 2026 capacity 0 unavailable; 7 November capacity 200 remaining 200 available.
- Sisa 20/100 `low` namun masih `bookable` untuk quantity 1; untuk quantity 21 false.
- Booking berbeda status yang hold/release mempengaruhi angka dengan tepat; expiry belum final tidak melepas hold secara prematur.
- Security: destinasi inactive/hidden tidak bocor, tidak ada data booking personal, mass quota lookup tetap efisien tanpa N+1.
- **MySQL concurrency nyata:** dua booking yang berebut kuota tidak oversell, availability vs create booking tidak menjanjikan reservasi, concurrent phone update first-writer-wins, serta regresi approval/expiry/idempotency tetap lolos.

**Regression:** Jalankan test suite Laravel existing (auth/JWT/OTP, kategori/destinasi, booking/approval, kuota, review/check-in) dan analisis diff; seluruh tes penting harus PASS. Jangan mengklaim Flutter tested. Laporkan jumlah tes dan command yang benar-benar dijalankan, kegagalan existing vs baru, serta bukti MySQL concurrency (SQLite saja tidak cukup).

## 11. Delivery plan (satu PRD, satu deliverable)

1. **Architect + Orchestrator:** audit source, lock kontrak schema/route/response dan error; petakan invariants booking/kuota.
2. **Backend Engineer:** migration/model/policy/master fasilitas; API list + assign; tambah fasilitas dinamis ke detail existing.
3. **Backend Engineer:** normalisasi phone + conditional profile fill pada create booking existing tanpa perubahan state machine.
4. **Backend Engineer:** monthly availability endpoint + agregasi kuota + timezone/booking window.
5. **QA + Security Auditor:** test unit/feature/integration/MySQL concurrency, audit access, PII, failure modes, index/query.
6. **Orchestrator:** integrasi, perbaikan temuan, API examples + notes untuk Flutter berikutnya, final report. **Jangan deploy / commit otomatis kecuali diarahkan secara eksplisit.**

## 12. Acceptance criteria / definition of done

- [ ] Master facilities tersimpan di MySQL dan CRUD hanya Superadmin.
- [ ] Admin hanya memilih fasilitas master aktif untuk destinasi sesuai otoritas; user melihat fasilitas relasional yang tepat tanpa fallback default.
- [ ] Phone booking divalidasi dan dinormalisasi ke E.164; snapshot tersimpan; `users.phone` hanya terisi bila kosong dan **tidak tertimpa**.
- [ ] Endpoint availability per bulan memberikan semua tanggal, hitungan yang konsisten dengan kuota booking nyata, `level` dan `bookable` akurat.
- [ ] Tidak ada oversell/double booking/partial assignment atau kerusakan idempotency, approval, expiry, auth.
- [ ] Tidak ada perubahan Flutter atau payment flow; tidak ada destruksi data existing.
- [ ] Automated tests relevan PASS, termasuk MySQL concurrency; audit Security tanpa Critical/High terbuka.
- [ ] Dokumentasi API dan daftar file berubah diserahkan; setiap penyimpangan dari proposed route/schema dicatat dengan alasan dan kontrak final.

## 13. Handoff untuk fase Flutter berikutnya (bukan pekerjaan sekarang)

Kontrak backend akan mendukung: selector fasilitas master di Admin, fasilitas aktual pada Detail Wisata User; country picker dan number formatter pada formulir booking; kalender bertanda level hijau/kuning/merah dan status disabled pada tanggal tak bookable. Mapping visual harus mengikuti `DESIGN.md` Flutter saat fase frontend dimulai. **Payment-before-approval adalah backlog terpisah dan tidak otomatis diimplementasikan dalam PRD ini.**
