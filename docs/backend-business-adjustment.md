# Backend Business Adjustment Phase 1–3 — implementation handoff

Tanggal: 2 November 2026. Backend Laravel 13 / MySQL saja.
Sumber requirement: `docs/prd/PRD-Wisata-Daerahku-Backend-Business-Adjustment-Phase-1-2-3 (1).md` (nama file aktual).

## Baseline dan keputusan kompatibilitas

| Baseline terverifikasi | Penyesuaian | Risiko/kontrak |
|---|---|---|
| Destinasi tidak mempunyai owner/admin FK; Admin role >=1 mengelola katalog bersama | Policy assignment mengikuti scope bersama existing | Tidak menambahkan pemetaan kepemilikan fiktif; pembatasan per pemilik memerlukan requirement/schema tersendiri |
| `users.phone` nullable dan `bookings.visitor_phone` sudah ada | Normalisasi snapshot dan conditional profile fill | Tidak ada migration/backfill telepon; nomor profil legacy tetap utuh |
| Availability existing authenticated, filter `visit_date/from/to`, response array | Mode `month` pada endpoint yang sama | Mode legacy dipertahankan; mode bulanan menggunakan envelope objek |
| `BookingRules::HELD` menentukan alokasi | Kalender memakai aggregate yang sama | Deadline lewat belum melepaskan kursi sampai status persisten berubah |
| Unique quota `(destination_guid, visit_date)` dan index booking `(destination_quota_guid, status)` sudah ada | Tidak menambah index duplikat | Satu query quota + subquery aggregate terindeks; tidak ada query per hari |
| Booking memakai opening/closing dan cutoff >25 jam 15 menit sebelum midnight lokal | Kalender memakai aturan yang sama | Tidak ada maximum advance horizon existing; tidak menambah larangan tanggal baru. Setiap request dibatasi satu bulan |

## Phase 1: fasilitas

Migration additive: `database/migrations/2026_11_02_043300_create_facilities_tables.php`.
Membuat `facilities` dan `destination_facilities`, PK/FK GUID, unique pair pivot, FK restrict, index status/nama dan reverse facility FK.
`name_key` adalah tambahan internal lowercase + collapsed whitespace dengan unique index untuk mencegah duplicate label termasuk saat race.
Migration sudah diterapkan pada database aplikasi **lokal**, tanpa seed dan tanpa perubahan data existing. Suite MySQL menggunakan database khusus `wisata_daerahku_test`.

Nama dinormalisasi trim/collapse whitespace. Slug dibuat saat POST (opsional berasal dari nama), dinormalisasi, unik, lalu stabil; PATCH slug ditolak. `icon_key` hanya boleh null karena belum ada katalog ikon yang disepakati.
Deactivation memakai UPDATE `is_active=false`, tidak menghapus master/pivot. Reaktivasi mengembalikan visibilitas relasi yang masih tersimpan.
Assignment mengunci destinasi dan master dengan urutan GUID, mengecek ulang master aktif dalam transaksi, lalu atomic sync. `[]` melepas semua relasi. GUID invalid/inactive/duplicate menolak seluruh request.
Update destinasi biasa tidak mengubah fasilitas; assignment hanya melalui endpoint khusus.
Detail/list destinasi memuat fasilitas aktif melalui eager loading dan urutan nama/GUID; tanpa relasi menghasilkan `facilities: []`.

Semua path berikut memakai prefix `/api/v1`:

| Method/path | Akses | Request/hasil |
|---|---|---|
| GET `/facilities` | Admin/Superadmin | Active selector, pagination `per_page` 1–100 |
| GET `/superadmin/facilities` | Superadmin | Termasuk inactive; `status=active/inactive/all`, `per_page` |
| POST `/superadmin/facilities` | Superadmin | `name`, optional `slug`, `icon_key:null`, `is_active`; 201 |
| PATCH `/superadmin/facilities/{guid}` | Superadmin | `name`, `icon_key:null`, `is_active`; 200 |
| DELETE `/superadmin/facilities/{guid}` | Superadmin | Logical deactivation; 200 resource |
| PUT `/admin/destinations/{guid}/facilities` | Admin/Superadmin | `facility_guids` array, max100; destination resource |
| GET `/destinations/{guid}` | Katalog publik existing | Tambahan field `facilities` |

Contoh POST master (Bearer Superadmin):

```json
{"name":"Parkir","icon_key":null,"is_active":true}
```

Response 201:

```json
{"data":{"guid":"<facility-guid>","name":"Parkir","slug":"parkir","icon_key":null,"is_active":true}}
```

Contoh PUT assignment (Bearer Admin/Superadmin):

```json
{"facility_guids":["<facility-guid>"]}
```

Potongan response detail destinasi:

```json
{"data":{"guid":"<destination-guid>","facilities":[{"guid":"<facility-guid>","name":"Parkir","slug":"parkir","icon_key":null}]}}
```

## Phase 2: telepon booking

Endpoint tetap `POST /api/v1/bookings`, Bearer authenticated user dan header `Idempotency-Key` existing.
Identity diambil dari token; field tambahan seperti `user_guid`, `role`, `phone` tidak dipakai dari request booking.
Nomor harus internasional dengan awalan `+` dan country code. Format nasional tanpa `+` ditolak 422 dengan pesan eksplisit.
Spasi, tanda hubung dan parentheses presentasi diterima jika nomor valid menurut metadata `giggsey/libphonenumber-for-php` ^9.0 (locked 9.0.42). Ini membutuhkan dependency karena regex saja tidak membuktikan validitas negara/nomor.
Tidak memverifikasi kepemilikan atau keterjangkauan nomor lewat SMS.

```json
{
  "destination_guid":"<destination-guid>",
  "visit_date":"2026-11-07",
  "quantity":1,
  "visitor_name":"Visitor",
  "visitor_email":"visitor@example.com",
  "visitor_phone":"+62 896-5586-2781"
}
```

Potongan response booking existing:

```json
{"data":{"guid":"<booking-guid>","visitor_phone":"+6289655862781","status":"pending_approval"}}
```

`bookings.visitor_phone` adalah snapshot mandiri. `users.phone` diisi secara compare-and-set hanya jika null atau kosong/spasi/tab/line-break, dalam transaksi booking yang sama sesudah validasi/insert. Profil nonkosong tidak dinormalisasi atau ditimpa. Nama/email tidak diubah. Kegagalan sesudah insert me-rollback booking dan profile fill.
Urutan lock existing idempotency → quota → booking dipertahankan, lalu CAS user, dengan retry transaksi existing. Replay completed key tidak mengisi profil lagi.
Fingerprint tetap memakai payload asli sesuai kontrak existing, sehingga retry harus mempertahankan payload dan key yang sama, termasuk formatting telepon. Normalisasi hanya pada kontak yang disimpan; tidak mengganti fingerprint historis.

**Perubahan validasi:** caller lama yang mengirim nomor nasional/placeholder invalid kini menerima 422. Historical request dengan nomor invalid juga tidak melewati validasi baru. Flutter harus mengirim country code. Tidak ada perubahan struktur response booking/auth, state machine approval/payment, JWT, OTP, Google Sign-In, atau profil update.

## Phase 3: kalender bulanan

`GET /api/v1/destinations/{guid}/availability?month=2026-11&quantity=1` (Bearer, mengikuti proteksi availability existing).
`month` format tepat YYYY-MM; quantity opsional default1, integer 1–1000000 sesuai booking existing. Quantity tanpa month ditolak422. Jangan gabungkan mode bulan dengan `visit_date/from/to`.
Destinasi/category inactive menghasilkan404. Response hanya agregat, tanpa booking/user/telepon/pembayaran.

Contoh response **dipotong** (response nyata selalu seluruh28–31 hari, tanggal ASC):

```json
{
  "data":{
    "destination_guid":"<destination-guid>",
    "month":"2026-11",
    "timezone":"Asia/Jakarta",
    "requested_quantity":1,
    "dates":[
      {"date":"2026-11-06","capacity":0,"remaining":0,"remaining_percent":null,"level":"none","status":"unavailable","bookable":false},
      {"date":"2026-11-07","capacity":200,"remaining":200,"remaining_percent":100,"level":"high","status":"available","bookable":true},
      {"date":"2026-11-08","capacity":null,"remaining":null,"remaining_percent":null,"level":"none","status":"unconfigured","bookable":false}
    ]
  }
}
```

`remaining=max(0,capacity-allocated)`, persen dihitung hanya jika capacity>0, tanpa pembulatan untuk penentuan level. Angka JSON100 dan100.0 setara.
HELD tetap `pending_approval`, `awaiting_payment`, `payment_verification`, `payment_review`, `confirmed`; tidak mengabaikan hold yang deadline-nya lewat sebelum rekonsiliasi status.

| Kondisi | Level | Status/bookable |
|---|---|---|
| >60% | high | available jika window/cutoff/quantity valid |
| 35–60% inklusif | medium | aturan sama |
| >0 dan<35% | low | tetap bookable jika quantity cukup |
| Sisa0, capacity positif | none | sold_out/false |
| Capacity0 | none | unavailable/false, persen null |
| Tidak ada quota | none | unconfigured/false, angka null |
| Quota positif tetapi window/cutoff tutup | Berdasarkan sisa | closed/false |
| Sisa positif kurang dari quantity | Berdasarkan sisa | insufficient_quantity/false |

Kalender adalah snapshot, bukan reservasi. Create booking tetap mengunci dan menghitung ulang kuota dalam transaksi. Tidak menggunakan cache lintas request.

## Security review dan resolusi

Kelima konfigurasi global dibaca dan role agent runtime dipanggil: master_orchestrator, system_architect, backend_engineer, qa_engineer, security_auditor. Main thread tetap integrator akhir. Slot runtime dibatasi4 sehingga spesialis berjalan bergiliran. QA/Security/Backend kemudian terkena usage limit; pekerjaan tersisa diselesaikan main thread menggunakan hasil review dan tes yang sudah disiapkan. Tidak mengklaim independent final sign-off setelah agent berhenti.

Temuan dan tindakan:

- Malformed nama fasilitas berpotensi TypeError dalam closure uniqueness: ditutup dengan `bail` sebelum custom check, diuji array/numeric input422.
- PATCH slug null berpotensi mengubah identifier stabil: field `slug` memakai `missing`, sehingga semua upaya perubahan ditolak.
- TOCTOU facility aktif: DB recheck dengan row lock pada assignment, save/deactivate memakai lock master yang sama; pivot unique menjadi guard terakhir.
- Privilege escalation/mass assignment: Policy role2 master dan >=1 selector/assignment, request validated-only, GUID/role/identity client tidak dimasukkan ke field yang ditulis.
- Profile phone race/FK lock upgrade: CAS user di transaksi booking, existing retry3 dipertahankan; diuji dua quota berbeda pada user yang sama.
- MySQL TRIM hanya mengenali spasi: CAS juga menghapus ASCII tab/line-break/form-feed/vertical-tab saat memeriksa kosong; nomor berisi tetap utuh.
- PII: tidak ada penambahan telepon pada katalog/kalender/log; response booking/profil tetap mengikuti akses existing.
- Dependency parser dipin ^9.0, bukan wildcard.

Tidak ada temuan Critical/High terbuka pada review source yang dilakukan; ini bukan klaim audit produksi atau penetration test.

## File implementasi

Baru:
- `app/Http/Controllers/Api/V1/FacilityController.php`
- `app/Http/Requests/FacilityRequest.php`, `DestinationFacilitiesRequest.php`
- `app/Http/Resources/FacilityResource.php`
- `app/Models/Facility.php`
- `app/Policies/FacilityPolicy.php`, `DestinationPolicy.php`
- `app/Services/FacilityService.php`
- `app/Support/BookingPhone.php`
- `database/factories/FacilityFactory.php`
- `database/migrations/2026_11_02_043300_create_facilities_tables.php`
- `tests/Feature/BusinessAdjustmentApiTest.php`
- `docs/backend-business-adjustment.md`

Diubah:
- `routes/api.php`
- `app/Http/Controllers/Api/V1/DestinationController.php`, `QuotaController.php`
- `app/Http/Requests/QuotaRequest.php`
- `app/Http/Resources/DestinationResource.php`
- `app/Models/Destination.php`
- `app/Services/BookingService.php`, `CatalogService.php`, `QuotaService.php`
- `composer.json`, `composer.lock`
- `tests/Pest.php`
- `tests/Feature/BookingServiceTest.php`, `BookingDocumentsTest.php`, `BookingNotificationTest.php`, `CatalogServiceTest.php`, `FcmDeliveryTest.php` (nomor fixture valid)
- `tests/Mysql/ConcurrencyTest.php`, `worker.php`
- `tests/E2E/EndToEndTest.php`, `phase6_flutter_fixture.php` (nomor fixture valid; bukan perubahan Flutter)
- `AGENTS.md`, `boost.json`: dihasilkan ulang oleh setup Boost yang diwajibkan instruksi repository; Boost sudah terpasang sebelumnya.

## Integrasi Flutter berikutnya

1. Superadmin CRUD master; Admin membaca selector paginated dan melakukan PUT array GUID untuk destinasi.
2. Render `facilities` yang dikembalikan detail; array kosong berarti tidak ada fasilitas aktif. `icon_key:null` memakai fallback lokal.
3. Country picker/formatter mengirim nomor internasional berawalan+; tampilkan422 pada field visitor_phone. Gunakan payload/key asli saat retry.
4. Kalender memakai month+quantity; petakan level semantik ke warna/aksesibilitas. Disable tanggal bookable=false, tampilkan insufficient_quantity terpisah dari sold_out.
5. Anggap kalender snapshot; tangani409 create booking, refresh kalender jika perlu. Flow approval/payment tetap existing.

Tidak ada Flutter/UI, payment gateway, commit, push, atau deployment yang dilakukan. Master awal sengaja kosong; buat fasilitas melalui Superadmin sebelum assignment.

## Hasil validasi aktual

| Pemeriksaan | Command/bukti | Hasil akhir |
|---|---|---|
| Full Laravel Unit/Feature/API/regression | `php artisan test --compact` | PASS — 126 tes, 1.075 assertion |
| Real MySQL concurrency | `php artisan test --compact -c phpunit.mysql.xml` | PASS — 21 tes, 334 assertion |
| HTTP E2E lokal, MySQL terpisah | `php artisan test --compact -c phpunit.e2e.xml` | PASS — 5 skenario, 176 assertion |
| Formatter | `php vendor/bin/pint --dirty --format agent` | PASS |
| Dependency metadata | `composer validate --no-check-publish` | PASS |
| Dependency advisory | `composer audit --no-interaction --format=plain` | PASS — tidak ada advisory saat pemeriksaan |
| Diff whitespace | `git diff --check` | PASS |
| Route registration | `php artisan route:list --path=facilities --except-vendor` | PASS — 6 route baru |
| Migration lokal | `php artisan migrate --no-interaction`, `migrate:status` | PASS — migration fasilitas batch2 Ran |
| Kalender query plan MySQL | EXPLAIN query aktual service, GUID sintetis, tanpa mutasi data | quota `range` memakai unique destination/date; booking `ref` memakai quota/status index |

Suite MySQL membuktikan kasus oversell 24 proses/10 kursi, mixed quantities, retry key identik, cancellation/approval races, quota reduction, review/check-in regression, profile first-writer pada quota berbeda, facility replacement paralel, dan snapshot kalender versus perebutan kursi terakhir. Suite juga mempertahankan regresi konkurensi OTP/password reset existing tanpa mengubah modul auth.

Temuan pengujian dan perbaikannya:
- Harness Windows tertahan saat banyak subprocess memakai output handles. Worker kini menulis hasil JSON ke file per proses, output process dinonaktifkan, readiness barrier diwajibkan dan cleanup berjalan melalui finally. Ini perubahan test harness, bukan pembatasan jumlah competitor.
- Satu run suite awal gagal pada sebagian retry key identik. Duplicate INSERT claim dapat mengambil shared user FK lock yang berinteraksi dengan CAS profile. Claim sekarang melakukan existence check dalam transaksi pendek terpisah dengan retry; commit claim tetap mendahului lock booking transaction. Regresi penuh sesudah perbaikan PASS; fingerprint dan key scope existing dipertahankan.
- Tidak ada test failure tersisa pada hasil akhir di atas. Run awal yang gagal/interrupted tidak dihitung sebagai PASS.

Server HTTP E2E sementara di127.0.0.1:8099 sudah dihentikan. Server aplikasi/worker milik user tidak dihentikan. Reset schema hanya dilakukan oleh harness pada database khusus `_test`/`_e2e`; database aplikasi lokal hanya menerima migration additive fasilitas.

NOT VERIFIED: manual UI/Flutter, provider SMS/SMTP/FCM/Google nyata, staging/production, penetration test dan performa dengan volume produksi. EXPLAIN lokal membuktikan pemilihan index pada data lokal, bukan benchmark kapasitas produksi. Tidak ada screenshot/mockup dibuat.
