# Phase 1B handoff — Wisata Daerahku Laravel

Tanggal pengerjaan: 5 November 2026. Laravel 13.39.0; PHP CLI 8.3.36.

## Delivery terkini: Demo Payment Simulator (keputusan user)

Bagian ini adalah kontrak terkini. Catatan sebelum simulator di bagian riwayat bawah dipertahankan sebagai bukti delivery sebelumnya; pernyataan lama bahwa tidak ada writer/eligibility demo telah digantikan oleh implementasi berikut. Tidak ada fase development baru.

| Bagian Phase 1B | Status | Batas bukti |
|---|---|---|
| 1B.1 Payment Foundation | DONE, dipertahankan | Ledger dan state contract existing direuse |
| 1B.2 Demo Simulator | DONE (terverifikasi lokal) | Initiation + success/failed/expired, real domain Laravel dan evidence demo; bukan uang nyata |
| 1B.2 DOKU Sandbox | BLOCKED | Belum ada akun/credential/channel/callback Sandbox aktual; DOKU tetap nonaktif |
| 1B.3 demo financial projection | DONE untuk simulator lokal; PARTIAL untuk sumber gateway nyata | Source simulator terpisah dari production; KPI TEST_ONLY / DEMO; DOKU belum tersedia |

### Aktivasi dan batas keamanan

- Default `DEMO_PAYMENTS_ENABLED=false` di config dan `.env.example`. Simulator hanya dapat diaktifkan dengan flag true **dan** runtime `APP_ENV=local`, `testing`, atau `demo`. Production, staging, dan environment lain ditolak 404 meskipun flag true. Actual `.env` tidak diubah pada delivery ini; aktivasi deployment demo kampus belum dilakukan.
- Untuk menjalankan demo kampus, gunakan instance/database demo terpisah, apply migration additive dengan proses deployment yang sesuai, set flag true pada environment yang diizinkan, dan refresh configuration cache bila dipakai. Jangan menunjuk database production lalu mengganti label environment. Tidak ada migration/reset pada database development selama pengerjaan.
- DOKU config tetap hard-disabled; tidak ada HTTP call, signature, webhook, payment instruction, QRIS, Virtual Account, URL checkout, atau invoice DOKU buatan.
- Simulator menggunakan `provider=demo`, `environment=demo`, `test_only=true`, `label=DEMO`. Booking, ticket collection, dan scanner menambahkan `payment_provider`, `payment_environment`, `payment_test_only`, dan `payment_label` agar provenance tidak hilang. Label demo tetap terlihat pada booking historis ketika gate mati.
- Gate dicek ulang pada PaymentEligibility. Demo yang sudah confirmed/ber-QR tetap `unverified` dan tidak dapat melakukan check-in baru saat flag dimatikan atau runtime berubah menjadi Production. H-5, local visit day, approval ownership, dan assignment scanner tetap memakai aturan existing. Historical check-in tidak dihapus; idempotent receipt historis bukan check-in baru.
- Notifikasi success berupa inbox owner dengan teks DEMO dan tanpa delivery email/FCM. Tidak ada publikasi/notifikasi eksternal dari simulator. Invoice existing tetap booking confirmation, secara eksplisit bukan bukti pembayaran; tidak menjadi invoice provider.

### API demo dan contoh kontrak

Semua endpoint di bawah memakai JWT existing; response private/no-store. Dua mutation demo memakai throttle 30/minute. Amount/currency diambil dari snapshot booking server. Owner wajib role 0 aktif; foreign owner/staff 404, missing auth 401, invalid payload/key 422, domain/idempotency conflict 409.

| Method | URI relatif `/api/v1` | Input/perilaku |
|---|---|---|
| POST | `/bookings/{booking}/demo-payment` | `Idempotency-Key` header; body kosong. Initiate/reuse pending atau retry aman setelah failed/expired |
| POST | `/bookings/{booking}/demo-payment/simulate` | `Idempotency-Key` header; JSON `attempt_guid`, `outcome=success|failed|expired` |
| GET | `/payments/methods?booking_guid={uuid}` | Hanya simulator eligible yang `can_pay=true` pada mode demo aktif; DOKU tidak menjadi aktif |
| GET | `/bookings/{booking}/payment-intent` | Status terkini + safe attempt metadata; gate off tidak memberi instruksi atau paid eligibility |
| GET | `/admin/dashboard/financial?environment=demo` | Role 1 current assignments / role 2 global; explicit TEST_ONLY / DEMO; historical read bukan aktivasi simulator |

`payment-preview` existing tetap read-only/default OFF. Generic POST `payment-intent`/`payment-attempts` dan webhook DOKU tidak dibuka.

```http
POST /api/v1/bookings/{booking_guid}/demo-payment
Authorization: Bearer {owner_token}
Idempotency-Key: campus-demo-init-1
Content-Type: application/json

{}
```

```json
{
  "attempt_guid": "{attempt_guid_dari_initiation}",
  "outcome": "success"
}
```

Kirim body kedua ke endpoint `/demo-payment/simulate` dengan key operation berbeda, misalnya `campus-demo-outcome-1`. Respons `data` memuat `booking_guid`, `intent_guid`, `attempt_guid`, `status` attempt (`pending/paid/failed/expired`), `booking_status`, `payment_status`, snapshot amount/currency/expiry, dan `instruction.presentation_type=demo_simulator`; `provider=demo`, `environment=demo`, `test_only=true`, `label=DEMO` selalu eksplisit. Tidak ada transfer uang nyata. Payload amount/currency/provider/environment/status/paid_at client dilarang dan tidak mengendalikan ledger.

### Domain, idempotency, expiry, dan database

- Reuse migration `2026_11_05_040716_create_payment_tables.php` dan tabel `idempotency_records`; **tidak ada migration baru untuk simulator**. Provider/environment pada intent existing tidak dioverwrite; intent DOKU/scope lain menghasilkan conflict.
- Lock parent quota -> booking, lalu current actor -> idempotency -> intent -> attempt. Recheck auth, approval history, status awaiting_payment, amount > 0 IDR, expiry snapshot dan local visit usability. Server memakai satu timestamp immutable untuk validasi cutoff dan evidence, tanpa `paid_at` client.
- Initiation membuat satu pending attempt pada intent processing. Pending dipakai kembali; failed/expired dapat retry sebelum cutoff. Unknown tidak membuka attempt baru. Booking tetap HELD selama pending/failed/expired; failed/expired bukan booking expiry dan tidak menambah deadline.
- Success menghasilkan tepat satu capture event verified milik simulator, satu applied attempt, booking confirmed/history beralasan DEMO, dan satu inbox DEMO secara atomik. Metadata event memuat source `demo_simulator`, actor, label, dan test_only. Kata verified di sini adalah pemeriksaan domain simulator server, bukan verifikasi signature DOKU.
- Key idempotency terpisah menurut user/operation/key; payload hash mencakup booking serta attempt/outcome untuk simulasi. Same key/same payload replay receipt snapshot tersimpan, termasuk sesudah status bergerak; same key/different payload 409. Key kedaluwarsa tidak dibuka ulang. Gunakan GET untuk state terkini.
- Outcome baru setelah cutoff ditolak, tanpa capture. Grace 15 menit tetap untuk verification/review existing, bukan tambahan waktu simulasi membayar. Simulator tidak mengemulasi late callback, backdated timestamp, refund workflow, atau DOKU reconciliation. Contradictory/foreign/superseded attempt ditolak tanpa overwrite outcome.
- QR memakai PaymentEligibility dengan matching demo applied intent/attempt/capture + approval + amount/currency/timing, serta fail-closed pada refund/reversal atau gate mati. QR token/aturan H-5 dan timezone tidak diganti.

### Analytics demo

`environment=demo` hanya membaca provider demo; default financial dashboard tetap production/provider DOKU dan null/unavailable bila source tidak ada. Semua counter/amount demo memiliki `test_only=true`, `label=DEMO`, `source=simulated_demo_events`; tidak diiklankan sebagai revenue dunia nyata.

Collected, recognized gross, unapplied collected, unique transactions, attempts/conversion, dan quantity tickets diproyeksikan dari ledger yang sama. Source simulator aktif dapat mengembalikan zero sums demo walau belum ada capture, sehingga failed/expired/pending counters tetap terlihat. Fee/net/settlement tetap null; refund completeness tidak dikarang. Role 1 current assignments dan `EXISTS` menjaga scope tanpa fan-out assignment. Financial projection menggunakan UTC cohorts yang sudah diuji; ticket day tetap timezone destinasi. Laporan demo historis tetap read-only, berlabel, dan scope-authorized saat execution gate mati.

### Changed files pada iterasi simulator

Baru: `app/Services/DemoPaymentService.php`, `app/Http/Requests/DemoPaymentRequest.php`, `tests/Feature/DemoPaymentTest.php`, `tests/Mysql/DemoPaymentConcurrencyTest.php`, `tests/Mysql/demo-payment-worker.php`.

Dilanjutkan/diperbarui: `PaymentController.php`, `PaymentGateway.php`, `PaymentService.php`, `PaymentEligibility.php`, `FinancialAnalyticsService.php`, `BookingService.php`, `BookingResource.php`, `TicketService.php`, `TicketCheckInService.php`, `BookingNotificationService.php`, `config/operations.php`, `.env.example`, `routes/api.php`, `bootstrap/app.php`, `tests/Feature/FinancialAnalyticsTest.php`, PRD yang sama, dan handoff ini. File foundation/migration/tes lain yang dirty sejak sesi sebelumnya dipertahankan; daftar kumulatif sebelumnya berada di riwayat. Tidak ada Flutter, dependency, commit, staging, push, deployment, atau perubahan actual `.env`.

### QA aktual iterasi simulator

| Pemeriksaan | Hasil aktual |
|---|---|
| Targeted simulator feature `php artisan test --compact tests/Feature/DemoPaymentTest.php` | PASS 44 tests / 290 assertions; SQLite `:memory:` |
| Batch feature gabungan 12 file (perintah di bawah) | PASS QA 157 tests / 1304 assertions / 13.245s; SQLite `:memory:`; dimulai sebelum fix RR terakhir, simulator diuji ulang setelah fix di bawah |
| Rerun simulator setelah fix RR terakhir | PASS QA 44 tests / 290 assertions / 4.370s pada final writer; feature lain tidak berubah setelah batch gabungan |
| Duplicate-success MySQL rerun sesudah fix | PASS 1 test / 13 assertions / 7.933s |
| `php artisan test -c phpunit.mysql.xml --compact tests/Mysql/DemoPaymentConcurrencyTest.php tests/Mysql/PaymentFoundationConcurrencyTest.php tests/Mysql/ClaimConcurrencyTest.php` dengan disposable override | PASS QA 14 tests / 186 assertions / 97.612s pada final writer |
| `php artisan test -c phpunit.mysql.xml --compact tests/Mysql/ConcurrencyTest.php --filter=...` (9 kasus booking/quota/check-in/idempotency/approval/cancel/availability) | PASS QA 9 tests / 164 assertions / 66.646s; review/facility/phone races yang tidak terdampak tidak diulang |
| `vendor/bin/pint --dirty --format agent` | PASS; formatter dijalankan pada PHP dirty |
| `php artisan route:list --path=demo-payment --except-vendor -v` | PASS tepat dua POST, JWT, throttle 30/minute |
| `git diff --check` | PASS |
| `php artisan config:show operations` | Default simulator OFF, DOKU false; runtime environment tidak diubah |
| DOKU Sandbox / provider signatures / production / deployment | BLOCKED atau NOT RUN |

Perintah feature gabungan: `php artisan test --compact tests/Feature/PaymentFoundationTest.php tests/Feature/FinancialAnalyticsTest.php tests/Feature/DemoPaymentTest.php tests/Feature/BookingApiTest.php tests/Feature/BookingServiceTest.php tests/Feature/AdminOperationsTest.php tests/Feature/SmartTicketSmokeTest.php tests/Feature/BookingDocumentsTest.php tests/Feature/BookingNotificationTest.php tests/Feature/FcmDeliveryTest.php tests/Feature/InboxServiceTest.php tests/Feature/BusinessAdjustmentApiTest.php`.

QA menggunakan SQLite in-memory untuk feature, dan MySQL disposable dengan override parent database serta environment child workers untuk race. Tidak memakai default database development. Counts antar-run overlap dan tidak dijumlahkan menjadi assertion unik.

Parent akhir `pami_phase1b_demofinal_test` beserta private helper databases sudah dihapus melalui cleanup. Audit read-only `information_schema` mengembalikan tidak ada schema tersisa dengan prefix `pami_phase1b_` atau `phase1a_`. MySQL demo memakai proses/koneksi berbeda untuk duplicate initiation, duplicate success, success-vs-failed, success lewat cutoff-vs-expiry, dan success-vs-cancellation; capture/history/inbox/finance tidak tergandakan dan held quota tetap konsisten. Foundation migration down/up dan approval claim replay/ownership juga lulus pada batch 14 kasus.

Auditor security membaca ulang implementation akhir termasuk fix RR, gate Production, provenance, dan scope analytics; tidak menemukan temuan demonstrable tambahan pada surface yang diperiksa. Tidak ada provider security clearance. HEAD tetap `c43a5d18183a660ccf45830c2c51e6e2698ff322`, tidak ada staged changes/commit/push.

Temuan aktual simulator: run awal MySQL menemukan urutan pembacaan yang membuat REPEATABLE READ mengambil snapshot sebelum worker menunggu quota lock. Worker berikutnya dapat melihat booking paid melalui locking read tetapi evidence lama melalui consistent read, sehingga duplicate success mendapat conflict. Perbaikan mengambil quota reference sebelum transaksi, mengunci quota sebelum consistent read pertama, lalu membaca/revalidasi booking di bawah lock yang sama; bukan melonggarkan paid guard. Rerun kasus gagal lulus. Satu strict array-order assertion test juga disesuaikan dengan normalisasi JSON MySQL, tanpa melonggarkan pemeriksaan nilai ledger.

### Batas delivery

Demo merupakan simulator untuk demonstrasi lokal; tidak membuktikan transaksi gateway atau real-money revenue. DOKU Sandbox, request signing, provider notification/replay, status inquiry, late callback, reversal provider aktual, staging/production, dan deployment demo kampus belum diverifikasi. Tidak ada test ID DOKU aktual. Seluruh DOKU requirement pada PRD tetap pending dalam 1B.2 yang sama.

## Riwayat handoff sebelum simulator (bukan kontrak runtime terkini)

Bagian di bawah adalah snapshot delivery sebelum keputusan user mengizinkan Demo Simulator; hanya dipertahankan untuk jejak baseline dan hasil tes lama.

## Keputusan delivery dan gate

Urutan pekerjaan: **1B.1 Payment Foundation → 1B.2 DOKU Sandbox gate → 1B.3 Financial Analytics**.

| Bagian | Status | Batas bukti |
|---|---|---|
| 1B.1 | **DONE** | Skema additive, state contract, API baca owner-only dan fail-closed terverifikasi lokal; tidak mencakup payment initiation |
| 1B.2 | **BLOCKED** | Kredensial Sandbox, channel merchant, dan host callback belum tersedia |
| 1B.3 | **PARTIAL** | Proyeksi ledger dan analytics lokal terverifikasi dengan fixture; belum ada sumber event gateway aktual |

**Keseluruhan Phase 1B belum memenuhi DoD integrasi.** Tidak ada transaksi DOKU aktual, invoice provider, webhook tervalidasi, atau status inquiry aktual pada delivery ini. Backend belum membuka gate implementasi Flutter Phase 1B.

Inspeksi aman `.env` hanya melaporkan keberadaan konfigurasi: tidak ditemukan client/merchant secret DOKU, channel merchant, atau callback host; `DOKU_ENABLED=false`. Tidak ada nilai credential disalin ke dokumen/log. Mengisi environment flag saja tidak mengaktifkan pembayaran.

## Baseline dan batas perubahan

- HEAD awal: `c43a5d18183a660ccf45830c2c51e6e2698ff322`.
- HEAD akhir: tetap sama; tidak ada commit, staging, push, atau deployment.
- Riwayat worktree sesi implementasi pertama menurut handoff sebelumnya: hanya `?? docs/prd/PRD-Wisata-Daerahku-Laravel-Phase-1B.md` milik pengguna. File tersebut dipertahankan.
- Sesi lanjutan mengaudit seluruh perubahan tracked dan untracked di atas HEAD sebelum bekerja; implementasi yang sudah ada dipertahankan. Baseline resume sudah mencakup file payment/finance serta handoff ini, bukan checkout bersih.
- Instruksi global, repository AGENTS.md, dan TOML aktual `backend_engineer`, `security_auditor`, `qa_engineer` dibaca. Tepat tiga subagent digunakan pada sesi lanjutan; tidak ada nested agent. `.ai/rules` tidak tersedia.
- Flutter, approval claim, BookingRules::HELD, token QR, notifikasi/outbox, invoice, scheduler, dan database kerja tidak diubah.

Changed files kumulatif di working tree (termasuk implementasi sebelum resume):

| Area | File |
|---|---|
| API | `app/Http/Controllers/Api/V1/PaymentController.php`, `FinancialDashboardController.php`, `PaymentPreviewController.php` |
| Request | `app/Http/Requests/PaymentMethodsRequest.php`, `FinancialDashboardRequest.php` |
| Domain/projection | `app/Services/PaymentGateway.php`, `PaymentService.php`, `PaymentPreviewService.php`, `FinancialAnalyticsService.php`, `OperationalDashboardService.php`, `app/Support/PaymentStates.php` |
| Routing/error envelope | `routes/api.php`, `bootstrap/app.php` |
| Migration | `database/migrations/2026_11_05_040716_create_payment_tables.php` |
| Feature tests | `tests/Feature/PaymentFoundationTest.php`, `FinancialAnalyticsTest.php` |
| MySQL tests | `tests/Mysql/PaymentFoundationConcurrencyTest.php`, `payment-foundation-worker.php`, `ConcurrencyTest.php` |
| Handoff | `docs/phase-1b-handoff.md` |

`docs/prd/PRD-Wisata-Daerahku-Laravel-Phase-1B.md` tetap untracked milik pengguna dan tidak diedit. Pada resume, perubahan substansial dibatasi ke finance service/tests, validasi pasangan tanggal finance, disposable-test guards/migration check, dua fixture assignment MySQL lama, dan handoff. Pint merapikan file PHP yang dirty. File lain adalah implementasi yang dilanjutkan dari working tree, bukan diulang.

## DOKU: keputusan integrasi

Mode/channel **belum dipilih untuk merchant**, karena capability merchant belum dapat diverifikasi. Seam hanya melaporkan unavailable. Tidak ada channel ditandai aktif, payload QRIS, nomor VA, checkout URL, atau hasil paid buatan. Pemeriksaan konfigurasi pada resume kembali mengonfirmasi DOKU false serta tidak adanya konfigurasi client ID, secret/private key, merchant, channel, dan callback; hanya boolean presence dilaporkan.

Referensi resmi yang dicatat pada pengerjaan sebelumnya/PRD berikut disediakan untuk implementasi berikutnya; sesi resume tidak memverifikasi ulang protokol provider dan tidak memakai referensi ini sebagai bukti merchant siap:

- [Checkout backend](https://developers.doku.com/accept-payments/doku-checkout/integration-guide/backend-integration): hosted Checkout merupakan integrasi berbeda dari Direct/SNAP.
- [Notifications](https://developers.doku.com/get-started-with-doku-api/notification) dan [notification best practice](https://developers.doku.com/get-started-with-doku-api/notification/best-practice).
- [Non-SNAP status inquiry](https://developers.doku.com/get-started-with-doku-api/check-status-api/non-snap).
- [Request signatures](https://developers.doku.com/get-started-with-doku-api/signature-component/non-snap/signature-component-from-request-header.md) dan [response signatures](https://developers.doku.com/get-started-with-doku-api/signature-component/non-snap/signature-componen-from-response-header.md).

Pengembangan 1B.2 yang masih diblokir: initiate/retry command + actor/operation/payload idempotency, adapter signing, response verification, authenticated webhook, inquiry/reconciliation evidence, bounded retries, atomik paid application, reversal/refund ingestion, dan bukti Sandbox aktual. Tidak ada endpoint webhook publik atau command yang menerima status sukses client. `PaymentEligibility` tetap `unverified` sampai jalur authoritative ini selesai.

## Kontrak API ke Flutter (tanpa perubahan Flutter)

Semua route berprefix `/api/v1`, memakai JWT existing, envelope `data` dan `meta.generated_at`. Respons payment bersifat private/no-store. GET tidak membuat intent/attempt/event, menjalankan inquiry, menerbitkan instruksi, atau mengubah booking.

| Method | Route | Akses/perilaku |
|---|---|---|
| GET | `/payments/methods?booking_guid={uuid}` | Role 0 aktif, booking sendiri; channel unavailable |
| GET | `/bookings/{booking}/payment-intent` | Role 0 aktif, booking sendiri; snapshot status dan instruksi unavailable |
| GET | `/bookings/{booking}/payment-preview` | Route existing; flag preview default OFF → 404; owner-only read-only |
| GET | `/admin/dashboard/financial` | Role 1 assignment saat ini; role 2 global; source availability dijelaskan di bawah |

Payment amount/currency berasal dari snapshot booking. Foreign booking/admin pada owner API → 404; JWT tidak ada/tidak berlaku → 401. Method query tanpa UUID valid → 422. Finance role 0 → 403; destination di luar assignment → 404. Error envelope tetap `message`, `code`, opsional `errors`, tanpa detail exception saat debug aktif.

POST intent/attempt **belum diekspos**, sesuai izin eksplisit PRD §4.4 saat provider gate belum tersedia. Karena itu client tidak boleh menganggap Idempotency-Key telah memulai pembayaran. API command tersebut beserta deterministic replay/conflict masih bagian 1B.2 yang diblokir; existing booking-intents tidak digabung dengan payment intents.

Kontrak status yang diuji untuk owner booking berbayar approved, tanpa intent:

```json
{
  "data": {
    "booking_status": "awaiting_payment",
    "availability": "unavailable",
    "reason": "doku_not_configured",
    "amount": 30000,
    "currency": "IDR",
    "payment_status": "unverified",
    "provider": "doku",
    "environment": "sandbox",
    "integration_status": "not_configured",
    "can_pay": false,
    "intent_guid": null,
    "status": "not_created",
    "last_attempt": null,
    "instruction": {"presentation_type": "unavailable"}
  }
}
```

Contoh merupakan subset respons fixture test, bukan transaksi provider; respons lengkap menambahkan GUID, expiry, methods, dan `meta.generated_at`. Nominal 30000 hanya fixture disposable. Query amount/currency/status client tidak mengubah snapshot.

## Skema, deployment, dan rollback

Migration `2026_11_05_040716_create_payment_tables.php` baru additive membuat `payment_intents`, `payment_attempts`, `payment_events`; migration existing tidak diubah. Semua primary/FK memakai UUID/GUID. Nominal berupa unsigned integer rupiah, currency eksplisit. Satu intent per booking; attempt number, merchant/provider/request reference dan event key memiliki constraint dedupe sesuai environment/provider. Applied-attempt pointer membatasi aplikasi yang diakui satu kali, dengan composite FK agar attempt berasal dari intent yang sama. Indeks mencakup provider/environment/verified paid time dan attempt/verification/event type.

Tidak ada backfill, seed paid, atau pengisian otomatis histori keuangan. Field instruction disediakan untuk ciphertext; tidak ada writer yang menyimpan payload provider mentah. Event metadata hanya untuk data tersanitasi, bukan card/bank/credential/signature.

Migration dijalankan hanya pada SQLite test dan database MySQL disposable saat verifikasi. Deployment database kerja belum dilakukan. Pada deployment berikutnya, jalankan migration additive sebelum aplikasi baru melayani route ledger. DDL MySQL tidak sepenuhnya transactional; lakukan review/backup melalui proses deployment yang berlaku. Rollback migration menghapus ledger, sehingga setelah ada transaksi harus memakai forward fix dan kebijakan retensi, bukan rollback data finansial.

## State dan concurrency

Booking state existing dipertahankan. Approval berbayar tetap `awaiting_payment` 1 jam, lalu `payment_verification`, lalu `payment_review` setelah grace 15 menit. Grace bukan tambahan waktu pelanggan membayar. Booking gratis yang confirmed tetap **bukan gateway-paid** dan tidak otomatis mendapatkan QR.

State intent/attempt terpisah dari booking; seluruh outcome provider masih diblokir. Bukan `confirmed`, field request, snapshot UI, atau redirect sukses yang menjadi bukti pembayaran.

Lock order untuk writer 1B.2 mendatang:

```text
destination (jika dibutuhkan) → quota → booking → intent → attempt → event/tickets
```

Expiry existing mengambil quota → booking dan membaca ulang state di dalam lock. Jangan mengambil intent sebelum quota/booking. Domain service baru harus menggunakan parent locks yang sama; jangan memakai BookingService::decide() untuk konfirmasi provider.

Policy yang wajib diterapkan saat 1B.2 tersedia: verified paid_at <= cutoff + notification masih dalam grace + booking/visit valid boleh diterapkan satu kali. Setelah grace, timestamp tidak tepercaya, pembayaran lewat cutoff, mismatch, status terminal, atau double capture → audit/exception, tidak auto-confirm/QR. Unknown outcome menahan retry attempt baru. Reversal/refund harus menutup eligibility tanpa menghapus checked-in history. Policy ini belum dibuktikan terhadap provider karena writer belum tersedia.

## Financial definitions dan availability

Proyeksi membaca ledger, bukan total booking confirmed atau jumlah QR. Sandbox/demo/production difilter terpisah; default laporan/dashboard adalah production. Sandbox diberi `test_only=true`, tidak diberi label pendapatan dunia nyata.

Tanpa verified source pada scope/environment, financial `availability=unavailable`, nominal tetap null. Keberadaan baris berlabel paid tanpa capture evidence tidak cukup. Tidak ada revenue dari failed/unknown/preview/free booking.

Definisi proyeksi lokal:

- `gross_collected`: jumlah nominal per attempt dengan capture event verified yang cocok pada intent, provider, environment, amount, currency, dan verified paid timestamp. Event replay pada attempt yang sama tidak menambah sum karena query memakai `EXISTS`.
- `gross_revenue`: gross applied capture untuk intent paid, applied attempt yang cocok, booking confirmed dengan riwayat approval berbayar, serta tanpa verified reversal yang diterapkan. Satu applied pointer per intent menjaga satu recognition walaupun ada beberapa attempts.
- `unapplied_collected`: collected dikurangi recognized gross; mencakup late/unapplied/duplicate capture atau capture yang recognition-nya ditutup. Ini bukan pendapatan booking sukses.
- `successful_payment_attempts`: capture attempts dalam paid-date cohort; `successful_transactions` dan `tickets_sold`: applied recognized bookings dan total quantity masing-masing, bukan jumlah QR.
- `attempt_conversion`: successful verified completed attempts / completed attempts, cohort `attempt_started_utc`; failed/expired terpisah, unknown bukan completed. Label paid tanpa capture evidence tidak menjadi successful.
- `booking_conversion`: paid recognized bookings / approved payable bookings dengan intent pada environment yang diminta, cohort `booking_created_utc`. Denominator scope dikembalikan eksplisit; booking tanpa environment intent tidak dicampurkan antar-environment.
- `refunds`: jumlah receipt refund verified/applied yang cocok dengan capture, berdasarkan `refund_utc`, hanya bila receipt tersedia; tanpa source refund tetap null. `refunds_basis=verified_receipts`, `refunds_complete=false` dan missing-field metadata menegaskan kelengkapan belum terbukti. Refund parsial tidak menghapus gross capture; refund bukan workflow refund baru. Writer provider dan kelengkapan source belum tersedia.
- `gateway_fees`, `net_revenue`, dan `settlement` tetap null. Tidak ada estimasi fee, klaim settlement, atau angka net sebelum sumber seluruh komponen otoritatif tersedia.
- `method_mix` dikelompokkan per method/channel; query mengambil maksimal 101 grup dan mengembalikan maksimal 100 dengan `method_mix_limit`/`method_mix_truncated` eksplisit. Aggregate total tetap mencakup seluruh grup. Timing rata-rata hanya paid timestamp terverifikasi yang tidak mendahului attempt start.

Periode finance memakai UTC, batas tanggal inclusive maksimal 366 hari, dan kedua parameter `from`/`to` wajib berpasangan. Batas akhir SQL adalah awal hari berikutnya secara eksklusif. Tanpa filter, periode awal bulan UTC sampai hari ini. Scope menggunakan assignment saat ini melalui `EXISTS`; assignment majemuk tidak menggandakan angka. Filter personal Admin tidak diterapkan pada keuangan team destinasi. Kehadiran verified capture di scope/environment menentukan source availability; jika source sudah ada tetapi cohort periode kosong, sum dapat 0 dan denominator nol menghasilkan rate null.

## Verification log

| Perintah | Hasil aktual |
|---|---|
| `composer show --direct` | PASS; Laravel 13.39.0, Pest 4.7.8, Pint 1.32.1; dependency tidak ditambah |
| Riwayat baseline sebelum resume, menurut handoff sebelumnya: `php artisan test --compact tests/Feature/BookingServiceTest.php tests/Feature/AdminOperationsTest.php tests/Feature/SmartTicketSmokeTest.php` | Tercatat PASS 18 tests, 221 assertions; ketiga file dijalankan kembali dalam batch QA final di bawah |
| `php artisan test --compact tests/Feature/FinancialAnalyticsTest.php` (SQLite `:memory:`) | PASS backend: 27 tests, 142 assertions |
| Targeted feature batch akhir (11 file di bawah, SQLite `:memory:`, DOKU false) | PASS QA: 113 tests, 1014 assertions, 8.597s |
| `php artisan test -c phpunit.mysql.xml --compact tests/Mysql/ConcurrencyTest.php tests/Mysql/ClaimConcurrencyTest.php tests/Mysql/PaymentFoundationConcurrencyTest.php` (disposable DB override) | Run awal QA: 20 tests, 18 PASS, 1 FAIL, 1 ERROR, 283 assertions, 127.226s; dua fixture legacy dikoreksi dan dites ulang di bawah |
| Rerun dua kasus `ConcurrencyTest.php` yang fixture assignment-nya dikoreksi, dengan `--filter` | PASS QA: 2 tests, 47 assertions, 13.855s |
| `php artisan test -c phpunit.mysql.xml --compact tests/Mysql/PaymentFoundationConcurrencyTest.php` (final disposable DB override) | PASS QA: 6 tests, 68 assertions, 37.631s |
| `vendor/bin/pint --dirty --format agent` | PASS; memperbaiki formatting/import pada empat file dirty |
| `php artisan route:list --path=api/v1/payments --except-vendor -v`, `--path=payment-intent`, `--path=payment-preview`, `--path=admin/dashboard` | PASS; route baca, JWT middleware, throttle route baru, dashboard existing tetap ada |
| `git diff --check` | PASS |
| DOKU Sandbox E2E / webhook vs expiry / provider reconciliation | BLOCKED / NOT RUN |
| Full regression, Flutter, staging, production | NOT RUN |

Data paid dalam tes hanya fixture di database pengujian yang terisolasi. Fixture PASS bukan bukti provider payment berhasil. Tidak ada DOKU Sandbox test ID aktual yang dapat dicantumkan.

Batch feature final: `php artisan test --compact tests/Feature/PaymentFoundationTest.php tests/Feature/FinancialAnalyticsTest.php tests/Feature/BookingApiTest.php tests/Feature/BookingServiceTest.php tests/Feature/AdminOperationsTest.php tests/Feature/SmartTicketSmokeTest.php tests/Feature/BookingDocumentsTest.php tests/Feature/BookingNotificationTest.php tests/Feature/FcmDeliveryTest.php tests/Feature/InboxServiceTest.php tests/Feature/BusinessAdjustmentApiTest.php`.

Runtime feature dipaksa `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=''`, `DOKU_ENABLED=false`. Outbound provider dilarang/diperiksa pada foundation tests; FCM/mail tests memakai fasilitas fake yang sudah ada. Tidak ada full-suite atau panggilan notifikasi eksternal.

MySQL memakai konfigurasi koneksi lokal dengan `DB_DATABASE` di-override ke parent disposable `pami_phase1b_20261105qa_test`, `pami_phase1b_fixturefix_test`, dan `pami_phase1b_finalqa_test`, bukan default development atau sekadar default `phpunit.mysql.xml`. Child workers menerima database disposable yang sama secara eksplisit. Helper payment membuat private `pami_phase1b_{random12}_test`; helper claim existing membuat private `phase1a_{random12}_test`. `migrate:fresh` pada regression booking existing hanya berjalan pada parent disposable yang sudah diverifikasi; database development tidak dimigrasikan/reset. Seluruh parent/private database dibersihkan; audit read-only `information_schema` tidak menemukan schema tersisa dengan prefix `pami_phase1b_` atau `phase1a_`.

Enam tes MySQL payment final membuktikan satu intent per booking, unique event key, unique provider reference dengan proses/koneksi terpisah, read-only payment vs expiry tanpa melepaskan held quota/QR, collected vs recognized projection pada MySQL, dan payment migration `down()`/`up()` dengan booking/claim/history tetap utuh. Ini **bukan** tes webhook-vs-expiry, karena writer provider belum tersedia. Hasil run awal yang lulus untuk booking/claim/quota/check-in tetap dicatat; hanya dua fixture gagal dan file payment/finance yang berubah diuji ulang, tanpa mengklaim rerun seluruh suite MySQL final. Hitungan antar-run tidak dijumlahkan sebagai assertion unik.

Temuan dan koreksi resume:

1. Finance `sometimes|required_with` melewatkan tanggal pasangan yang absen. Aturan request diperbaiki; hanya-from, hanya-to, tanggal invalid/terbalik, 367 hari ditolak 422, dan 366 hari inclusive diterima.
2. Refund parsial sebelumnya menghapus seluruh gross recognition. Kini gross dan receipt refund dipisah; net tetap null karena fee/refund completeness belum tersedia.
3. Recognition membutuhkan approved history dan applied capture evidence yang cocok; label paid tanpa evidence tidak menjadi successful/completed attempt. Scope, environment, event replay, multi-attempt, reversal, cohort UTC, serta truncation method mix punya regression coverage.
4. Run MySQL awal menemukan dua fixture legacy memakai Admin role 1 tanpa assignment Phase 1A. Hanya fixture assignment pada `ConcurrencyTest.php` diperbaiki; otorisasi produk dipertahankan. Rerun kedua kasus: PASS 2 tests, 47 assertions, 13.855s.

Auditor security memeriksa ulang kode akhir secara read-only: tidak ada temuan demonstrable tambahan pada surface saat ini. Ini bukan security clearance untuk integrasi provider yang belum tersedia.

## Risiko dan blocker

1. Butuh merchant Sandbox, credential backend, bukti channel aktif, host notification HTTPS yang dapat diakses DOKU, dan keputusan Checkout/Direct sebelum 1B.2 dapat diselesaikan.
2. Source verified gateway belum ada; angka keuangan operasional tetap unavailable. Finance fixture membuktikan matematika/query saja.
3. Race provider vs expiry, crash recovery paid/outbox, replay signature, chargeback/reversal aktual belum dapat dibuktikan tanpa writer/integrasi.
4. Tidak ada migrasi database kerja atau deployment. Jangan mengaktifkan Production atau menandai keseluruhan Phase 1B DONE dari hasil lokal ini.
