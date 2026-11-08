# PRODUCT REQUIREMENTS DOCUMENT
## Integrasi DOKU Direct API — Backend PAMI Wisata Daerahku

**Versi:** 1.0 (baseline requirements, pre-implementation)  
**Tanggal:** 8 November 2026  
**Status:** Draft untuk review dan persetujuan sebelum coding  
**Pemilik produk:** Tim Wisata Daerahku  
**Sistem utama:** Laravel REST API 13.x / PHP 8.3+ / MySQL  
**Klien:** Flutter Android (native checkout)  
**Referensi utama:** Percakapan kebutuhan, source publik repository `main` pada tanggal peninjauan, dokumentasi resmi DOKU

> **Keputusan utama:** Satu codebase Laravel, satu payment orchestration logic, dengan `DOKU_ENVIRONMENT=sandbox|production` yang memilih konfigurasi server-side. Flutter tetap mengonsumsi kontrak API Laravel. Simulator internal (`demo`) tetap terpisah dari transaksi DOKU. PRD ini **belum merupakan bukti bahwa channel atau kredensial Production aktif**.

---

# 1. Ringkasan Eksekutif

Wisata Daerahku sudah mempunyai alur booking dan komponen payment demo yang fungsional. Fitur yang diminta adalah implementasi **DOKU Direct API end-to-end di backend**, agar pengguna dapat memilih metode pembayaran pada **halaman native Flutter**; backend membuat instruksi bayar ke DOKU, menerima dan memverifikasi webhook, melakukan rekonsiliasi, lalu mengonfirmasi booking dan menerbitkan tiket setelah dana terverifikasi.

Penerapan harus mencakup **Sandbox dan Production tanpa duplikasi kode bisnis**. Environment aktif dipilih melalui konfigurasi deployment, **bukan** nilai yang dikirim Flutter. Perpindahan ke Production mensyaratkan merchant live, channel aktif, kredensial live, webhook HTTPS stabil, dan pengujian go-live; tidak cukup mengganti satu string pada `.env` bila prasyarat operasional belum lengkap.

**Prioritas implementasi:** (1) fondasi environment dan payment engine; (2) Virtual Account Dynamic Fixed Bill melalui channel SNAP yang cocok; (3) webhook + status query + rekonsiliasi; (4) kontrak native Flutter; (5) adaptasi QRIS dan e-wallet menurut kapabilitas channel yang aktif; (6) hardening dan production readiness.

**Kriteria keberhasilan akhir:** booking berbayar tidak pernah menjadi `confirmed` hanya karena frontend mengaku berhasil, tidak ada pembayaran ganda akibat request ulang, semua webhook diverifikasi, tidak ada transaksi antar-environment tercampur, dan QA end-to-end lolos di channel Sandbox yang didukung.

# 2. Latar Belakang dan Jejak Kebutuhan

## 2.1 Keputusan pengguna dari percakapan

1. Menggunakan **DOKU Direct API**, bukan DOKU-hosted Checkout sebagai alur utama.
2. Checkout **native Flutter**: QRIS tampil di aplikasi, Virtual Account dapat disalin, e-wallet boleh membuka aplikasi/halaman otorisasi eksternal bila diwajibkan channel.
3. Backend adalah satu-satunya pihak yang berkomunikasi dengan DOKU menggunakan merchant credentials; Flutter hanya berbicara dengan Laravel.
4. Backend Laravel **masih lokal** dengan `php artisan serve --host=127.0.0.1 --port=8000`; tidak perlu VPS pada tahap Sandbox.
5. Flutter berjalan pada **Android Emulator** dan berhasil mengakses `127.0.0.1:8000/api/v1` menggunakan `adb reverse tcp:8000 tcp:8000`.
6. Tunnel `localhost.run` berhasil menghasilkan HTTPS publik dan dapat meneruskan request ke Laravel lokal.
7. Akun Sandbox ada; pengguna telah mengambil **Client ID, Secret Key, DOKU Public Key**; seluruh VA terlihat `ACTIVE` pada menu Service.
8. Halaman **Settings → Payment Settings → Virtual Account** blank pada saat peninjauan; pengaturan Notification URL belum terbukti berhasil.
9. Endpoint webhook DOKU **belum diimplementasikan**; `404` adalah konsekuensi yang wajar.
10. Simulator payment demo existing wajib tetap bisa digunakan, tetapi **tidak boleh memproses atau mengonfirmasi transaksi DOKU**.
11. Environment Sandbox dan Production dipilih secara dinamis di backend dari `.env`, tetapi **satu business logic** dipakai pada kedua lingkungan.
12. Pengguna meminta spesifikasi backend end-to-end, termasuk kesiapan kontrak API untuk Flutter; belum meminta perubahan source pada tahap PRD.

## 2.2 Status setup yang sudah terbukti

| Area | Status | Bukti / catatan |
|---|---|---|
| Laravel lokal port 8000 | Teruji oleh pengguna | Endpoint destinasi dapat dibuka dari Windows |
| Android Emulator / ADB | Teruji oleh pengguna | `adb devices` dan `adb reverse` berhasil |
| Flutter → Laravel | Teruji oleh pengguna | Aplikasi dapat mengambil data API |
| DOKU Sandbox | Tersedia | Client ID, Secret Key, DOKU Public Key sudah diambil, **nilai tidak dibagikan** |
| VA service | ACTIVE menurut dashboard | Belum membuktikan SNAP request / nomor VA dapat dibuat |
| HTTPS tunnel | Teruji oleh pengguna | `localhost.run` meneruskan request ke backend; domain berubah antarsesi |
| Webhook route | Belum ada | 404 dari Laravel pada contoh URL webhook |
| DOKU Direct API adapter | Belum ada | Source masih memakai kemampuan placeholder |
| Production merchant/credential | Belum terkonfirmasi | Tidak diasumsikan tersedia |

**Perintah tunnel yang berhasil diuji:**

```powershell
ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -R 80:127.0.0.1:8000 nokey@localhost.run
```

URL sesi uji yang pernah didapat: `https://8d7df4ec01ab8f.lhr.life` — **contoh historis, jangan diasumsikan masih aktif dan jangan di-hardcode**. Sebelum membuka tunnel, `APP_DEBUG=false`, konfigurasi dibersihkan/dimuat ulang, data uji non-sensitif, dan tunnel ditutup seusai tes.

# 3. Audit Ringkas Existing Repository

**Backend:** https://github.com/joytimess/pami-wisata-daerahku-api  
**Flutter:** https://github.com/koyotot/pami-wisata-daearahku

Catatan: audit berdasarkan source publik `main` yang berhasil diakses; bukan verifikasi working tree Windows lokal dan bukan hasil test suite yang dijalankan. Rincian migration/index yang belum dipastikan adalah bagian discovery implementasi, bukan fakta yang telah lulus audit.

| Lokasi terverifikasi | Kondisi existing | Dampak PRD |
|---|---|---|
| `composer.json` | Laravel `^13.17`, PHP `^8.3`, Pest 4 | Ikuti stack, tidak perlu ganti framework |
| `routes/api.php` | `GET payments/methods`, `GET .../payment-intent`, `POST .../demo-payment`, `POST .../demo-payment/simulate` ada dalam auth middleware | Pertahankan endpoint dan bentuk respons existing; tambah route DOKU berautentikasi dan webhook tanpa JWT pengguna |
| `app/Http/Controllers/Api/V1/PaymentController.php` | Demo initiate/simulate + methods/show | Controller tipis; business logic berada di service |
| `app/Services/PaymentService.php` | `show()` membatasi intent DOKU ke `environment=sandbox`; eligibility/availability masih condong ke demo | Wajib refactor agar environment-aware dan tidak hardcoded |
| `app/Services/PaymentGateway.php` | DOKU placeholder `not_configured`, `can_pay=false`, environment hardcoded `sandbox` | Ganti menjadi capability resolver berdasarkan config, channel readiness, dan health |
| `app/Services/DemoPaymentService.php` | Memakai `payment_intents`, `payment_attempts`, `payment_events`, idempotency, status transisi, serta integrasi booking/tiket | Pertahankan simulator; ekstrak domain invariant bersama bila aman tanpa mengubah perilaku demo |
| `app/Services/PaymentEligibility.php` | `paid` verification masih memeriksa `provider=demo`, `environment=demo` | Wajib memperluas validasi payment provenance tanpa meloloskan bukti palsu |
| `app/Support/PaymentStates.php` | Intent: `requires_payment`, `processing`, `paid`, `expired`, `cancelled`, `needs_review`; attempt: `initiating`, `pending`, `unknown`, `failed`, `expired`, `paid`, `reversed` | Pakai state machine existing; tentukan matriks transisi provider event dan late payment |
| `.env.example` | `DEMO_PAYMENTS_ENABLED=false`, `DOKU_ENABLED=false`; `APP_URL` contoh `.test` | Tambah environment settings tanpa memaksakan perubahan `APP_URL` ke URL tunnel |
| Flutter `DemoCheckoutScreen` | Checkout demo, recovery/resume, test-only | Kontrak backend harus memungkinkan screen native DOKU tanpa merusak demo |
| Flutter `BookingPaymentSection` | Mengambil methods dan payment-intent; membuka checkout demo | Kapabilitas harus datang dari backend dan memilih UI sesuai `presentation_type` |

**Risiko audit penting:** jangan mengasumsikan semua migration/index/constraint cocok untuk DOKU sebelum mengaudit schema aktual (uniqueness, foreign keys, nullable fields, status enum/length, provider references, monetary precision).

# 4. Tujuan, Metrik, dan Non-Goals

## 4.1 Tujuan produk

- Menyediakan pembayaran tiket wisata yang konsisten, dapat diaudit, dan terverifikasi DOKU.
- Menjaga kontrol nominal, kuota, booking deadline, dan penerbitan tiket pada Laravel.
- Menyediakan satu kontrak pembayaran untuk Flutter, independen dari skema mentah DOKU.
- Mendukung Sandbox dan Production via konfigurasi, tanpa copy-paste service per environment.
- Mempertahankan kemampuan simulasi internal, terisolasi dari provider nyata.
- Menangani ketidakpastian jaringan, payment notification terlambat, event duplikat, dan recovery.

## 4.2 Indikator penerimaan

- Tidak ada booking berbayar yang dikonfirmasi tanpa event pembayaran terverifikasi atau rekonsiliasi sah.
- Retry dengan idempotency key yang sama tidak menghasilkan transaksi provider tambahan.
- Event webhook yang sama diproses maksimal satu kali secara efektif walau dikirim berulang.
- Sandbox/test records selalu dikenali sebagai `test_only`; tidak pernah diakui sebagai penerimaan uang Production.
- Ketika Flutter ditutup/dibuka ulang, user dapat melanjutkan instruksi payment yang masih valid.
- Semua skenario P0 di Matriks UAT berhasil pada sandbox channel yang tersedia.

## 4.3 Non-goals untuk rilis pertama

- Tidak membuat halaman DOKU Checkout hosted sebagai jalur utama.
- Tidak melakukan auto-switch Sandbox↔Production berdasarkan input user, header, atau URL dari Flutter.
- Tidak mengelola settlement DOKU sebagai saldo rekening merchant secara penuh.
- Tidak mengimplementasikan refund otomatis ke DOKU sampai channel refund dan proses bisnis disetujui; **mencatat dan menangani reversal/refund notification tetap wajib**.
- Tidak membuat ulang modul booking/kuota/tiket dari nol.
- Tidak mengirim merchant keys ke Flutter atau menyimpan data bisnis di Firebase.

# 5. Prinsip Arsitektur

## 5.1 Keputusan arsitektur

**Satu kode, satu orchestration logic, banyak konfigurasi deployment.** Laravel memilih environment aktif dari `config('doku.environment')`, dimuat dari `.env` saat proses boot; tidak membaca `env()` ad hoc di service dan tidak menerima environment dari request. Pilihan provider `demo` atau `doku` ditentukan server-side melalui capability/feature flag dan kebijakan booking.

```text
Flutter Android
  └─ Bearer JWT → Laravel API /api/v1
       ├─ Booking & Payment Orchestrator
       │    ├─ Payment Intent / Attempt / Event / Idempotency
       │    ├─ Demo Provider (hanya local/testing dan eksplisit enabled)
       │    └─ DOKU Provider Adapter (config environment)
       │         ├─ sandbox → Sandbox credentials + API + webhook verifier
       │         └─ production → Live credentials + API + webhook verifier
       ├─ Webhook ingress (tanpa JWT user; signature wajib)
       ├─ Reconciliation jobs → provider query/status
       └─ Verified outcome → booking/ticket transition + notifications
```

## 5.2 Isolasi environment

- Environment aktif per **deployment**, bukan per HTTP request, kecuali layanan multi-tenant/multi-merchant khusus yang di luar scope.
- `sandbox` dan `production` menggunakan **kredensial, private/public key, data uji, webhook URL, dan idealnya database/deployment berbeda**.
- Di local/staging simpan hanya Sandbox secrets; di live simpan hanya Production secrets. Jangan menyimpan semua Secret Key di laptop lokal.
- Setiap intent, attempt, dan event mencatat `provider` + `environment` secara immutable sebagai identitas transaksi.
- Event yang merujuk attempt environment berbeda **ditolak/dikarantina** tanpa mutasi booking.
- Jika konfigurasi berubah sementara ada transaksi pending pada database yang sama, jangan melakukan status query dengan konfigurasi baru. Terapkan prosedur drain/reconcile/arsip atau tetap gunakan deployment lama untuk menuntaskan transaksi lama.
- Production fail closed jika `APP_DEBUG=true`, secrets belum siap, webhook URL tidak HTTPS stabil, channel belum approved, atau konfigurasi bertentangan.
- DOKU Environment harus menggunakan nilai terenumerasi `sandbox` atau `production`; nilai lain menghentikan DOKU adapter (tidak fallback diam-diam).

## 5.3 Provider abstraction

Pisahkan empat tanggung jawab: (a) payment orchestration/eligibility dan domain state; (b) DOKU transport/authentication; (c) channel handlers VA/QRIS/e-wallet; (d) verified event application. Kontrak provider minimal: `createPayment`, `getPaymentStatus` (bila channel mendukung), `verifyNotification`, `normalizeInstruction`, `normalizeOutcome`.

SNAP dan channel-specific variations boleh mempunyai implementasi teknis terpisah; **yang disatukan adalah domain logic dan output internal**, bukan memaksakan seluruh bank punya payload sama.

# 6. Functional Requirements (FR)

| ID | Priority | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-01 | P0 | Config switch `DOKU_ENVIRONMENT` | Mode sandbox/production mengarahkan base URL, auth, key, provider tag, dan verifier yang tepat; invalid config fail closed |
| FR-02 | P0 | Payment capability discovery | `GET /payments/methods` mengembalikan hanya channel enable/eligible berdasarkan booking dan environment; disabled reason jelas |
| FR-03 | P0 | Booking payment eligibility | Owner sah, `awaiting_payment`, nominal IDR positif, visit belum dimulai, deadline belum lewat, kuota snapshot valid |
| FR-04 | P0 | Idempotent payment initiation | Key sama + request sama → respons sama; key sama + payload berbeda → conflict; concurrent request tidak menduplikasi provider call |
| FR-05 | P0 | Payment Intent | Satu kewajiban pembayaran untuk booking dengan immutable amount/currency/provider/env snapshot dan status domain |
| FR-06 | P0 | Payment Attempt | Attempt bernomor, referensi merchant/provider unik, channel/method, timestamps, instruction expiry, status tersimpan |
| FR-07 | P0 | Dynamic Virtual Account | DOKU mengembalikan nomor VA asli per channel Sandbox yang aktif, amount sesuai booking; instruksi aman dan dapat dipulihkan |
| FR-08 | P0 | Verified DOKU webhook | Raw body + signature diverifikasi menurut protokol channel; event invalid ditolak; duplicate tidak menggandakan side effect |
| FR-09 | P0 | Finalization | Booking baru `confirmed` dan tiket terbit setelah paid verified serta semua invariant terpenuhi, dalam atomic transition/outbox |
| FR-10 | P0 | Unknown/recovery | Timeout/unknown tidak menciptakan attempt kedua sebelum rekonsiliasi; API dapat mengembalikan attempt lama |
| FR-11 | P0 | Reconciliation | Pending/unknown diperiksa ulang terjadwal sesuai provider/channel; mismatch masuk `needs_review` |
| FR-12 | P0 | Test/prod isolation | Event Sandbox tak dapat menyelesaikan Production intent; demo tak dapat menyelesaikan DOKU intent |
| FR-13 | P0 | Payment history/audit | Provider refs, event outcome, alasan ignore/reject, correlation ID, chronology dan redacted metadata tercatat |
| FR-14 | P0 | Security/logging | No secrets dalam API/log, data sensitif redacted, auth & rate limit konsisten, error non-verbose |
| FR-15 | P1 | QRIS Direct API | Jika channel diaktifkan, normalisasi `qr_payload` dan expiry; Sandbox tidak memalsukan QRIS DOKU |
| FR-16 | P1 | E-wallet Direct API | Jika channel aktif, instruksi `ewallet_redirect` serta return recovery; redirect bukan bukti paid |
| FR-17 | P1 | Retry/change method | Hanya jika attempt sebelumnya final failed/expired menurut verifikasi dan booking masih payable |
| FR-18 | P1 | Operations visibility | Admin melihat status, environment, channel, reconciliation state tanpa dapat menandai paid sembarangan |
| FR-19 | P1 | Release toggles/kill switch | Nonaktifkan channel baru tanpa menghilangkan ability webhook/reconcile transaksi yang sudah issued |
| FR-20 | P1 | Documentation/runbook | Setup Sandbox lokal, webhook tunnel, test simulator, rotate keys, production go-live & rollback tersedia |

# 7. Alur Bisnis dan State Machine

## 7.1 Happy path VA

1. Wisatawan membuat booking; admin menyetujui sesuai proses existing sehingga booking `awaiting_payment`.
2. Flutter membaca metode & payment intent yang tersedia.
3. User memilih bank VA dan mengirim request create attempt dengan idempotency key.
4. Laravel memverifikasi owner, booking, amount/currency, cutoff, snapshot, serta current attempt dalam DB transaction singkat.
5. Laravel menandai attempt `initiating` dan mencatat operasi yang boleh di-retry; **HTTP call ke DOKU dilakukan di luar lock transaksi DB panjang**.
6. DOKU menerbitkan VA (skenario Dynamic/Fix Bill: default DGPC bila channel mendukung; DIPC/MGPC bukan asumsi).
7. Laravel menyimpan provider reference, nomor VA yang aman ditampilkan, expiry, status `pending`; Flutter menampilkan instruksi.
8. User membayar melalui bank/simulator DOKU Sandbox.
9. DOKU mengirim webhook ke HTTPS endpoint; Laravel memverifikasi signature, environment, reference, amount, currency, status, event uniqueness.
10. Event terverifikasi diterapkan secara atomik; intent `paid`, booking `confirmed`, penerbitan tiket tepat sekali; notifikasi asynchronous.
11. Flutter membuka ulang/recover lewat `GET /payment-intent` dan memperoleh status akhir.

## 7.2 Gagal/timeout/retry

- Request timeout saat generate VA → simpan `unknown`; lakukan query/reconcile dengan merchant reference sama. Tidak otomatis request pembuatan baru.
- Provider menyatakan gagal → attempt `failed`; dapat initiate ulang bila booking masih eligible dan tidak ada pembayaran unresolved.
- Provider menyatakan expired → attempt `expired`; hanya retry jika booking/visit window masih terbuka.
- Duplicate same webhook → audit `duplicate/ignored`, HTTP success bila sah dan sudah diproses; tidak menerbitkan tiket kedua.
- Invalid signature → reject, tidak mutate state, alert/log sanitized; ikuti HTTP response yang diwajibkan protokol.
- Late paid setelah booking expired/cancelled → `needs_review` dan operasional penanganan pembayaran terlambat, **jangan langsung keluarkan tiket**.
- Refund/reversal setelah paid → payment review/reversed status dengan proses penanganan tiket sesuai bisnis; jangan diam-diam menghapus histori atau membuka kuota tanpa aturan.

## 7.3 Invariant domain

- Amount sumber kebenaran dari snapshot booking di server (`total_amount` integer IDR), bukan request Flutter.
- Payment snapshot harus cocok amount, currency, owner, booking, provider, environment, reference.
- Verified paid event hanya dapat diterapkan **sekali**; `applied_attempt_guid` dan `verified_paid_at` menjadi sumber audit.
- E-wallet return/redirect, sukses HTTP create VA, dan halaman Flutter `success` **bukan** bukti dana diterima.
- Pembayaran tidak boleh menyebabkan kuota overbook; gunakan existing lock order dan review side effects pada `DemoPaymentService`/`TicketService`.
- Effective instruction expiry ≤ minimum booking payment deadline, visit start, dan provider expiry (dengan aturan channel; jika provider tidak mendukung batas ketat, jangan tawarkan transaksi bila deadline tak dapat dipenuhi).

# 8. Kontrak API Laravel ↔ Flutter (Usulan; Belum Diimplementasikan)

Semua endpoint existing harus tetap kompatibel. Response sukses mengikuti pola existing `{"data": {...}}`/`OperationalResource`. Gunakan GUID booking existing dan JWT pada endpoint user. Seluruh response pembayaran `Cache-Control: private, no-store`.

| Method | Route | Auth | Fungsi |
|---|---|---|---|
| GET | `/api/v1/payments/methods?booking_guid={guid}` | JWT owner | Metode yang tersedia & eligibility |
| GET | `/api/v1/bookings/{booking}/payment-intent` | JWT owner | Status intent, current attempt, instruksi recovery |
| POST | `/api/v1/bookings/{booking}/payment-attempts` | JWT owner | Create/reuse attempt provider DOKU |
| POST | `/api/v1/bookings/{booking}/demo-payment` | JWT owner; local/test only | Endpoint demo legacy tetap ada |
| POST | `/api/v1/bookings/{booking}/demo-payment/simulate` | JWT owner; local/test only | Test demo legacy; tak dapat mutate DOKU |
| POST | `/api/v1/webhooks/doku/va` (provisional) | Provider signature, no user JWT | Callback VA, path final diverifikasi berdasarkan channel/protokol |
| POST | `/api/v1/webhooks/doku/qris` (conditional) | Provider signature | Callback QRIS ketika disupport |
| POST | `/api/v1/webhooks/doku/ewallet` (conditional) | Provider signature | Callback e-wallet ketika disupport |

**Create attempt request (ilustratif, final harus disesuaikan dengan pola request validation existing):**

```http
POST /api/v1/bookings/{booking_guid}/payment-attempts
Authorization: Bearer <JWT>
Idempotency-Key: <uuid-v4>
Content-Type: application/json

{"method_code":"virtual_account","channel":"bni"}
```

**Normalized response yang dijanjikan backend (contoh kontrak internal, BUKAN respons mentah DOKU):**

```json
{
  "data": {
    "booking_guid": "booking-guid",
    "intent_guid": "intent-guid",
    "attempt_guid": "attempt-guid",
    "provider": "doku",
    "environment": "sandbox",
    "test_only": true,
    "status": "pending",
    "payment_status": "pending",
    "amount": 150000,
    "currency": "IDR",
    "method_code": "virtual_account",
    "channel": "bni",
    "expires_at": "2026-11-08T10:00:00+07:00",
    "instruction": {
      "presentation_type": "virtual_account",
      "bank_code": "bni",
      "va_number": "EXAMPLE-NOT-REAL-VA",
      "copyable": true
    }
  }
}
```

`instruction.presentation_type` di-enum `virtual_account | qr_payload | ewallet_redirect | demo_simulator | unavailable`. QR payload dan redirect URL hanya ditampilkan jika benar-benar dikembalikan/diizinkan oleh channel. `test_only` adalah tag provenance, bukan tombol pengalihan mode. Dilarang mengekspos secret, token provider, signature, private key, atau raw webhook di respons publik.

**Response error:** kode stabil untuk `booking_not_awaiting_payment`, `payment_window_closed`, `unsupported_currency`, `channel_unavailable`, `idempotency_conflict`, `payment_provider_conflict`, `payment_pending_reconciliation`, `provider_unavailable`, `payment_needs_review`, `payment_environment_mismatch`. HTTP 401/403/404/409/422/429/502/503 dipilih konsisten dengan kebijakan API existing; jangan expose exception path/stack.

# 9. Database & Persistence Requirements

Audit migration schema terlebih dahulu. Jangan membuat schema paralel tanpa perlu. Migration harus non-destructive, index-aware, dan dapat di-rollback secara aman sebelum data live.

## 9.1 Existing yang harus dimanfaatkan

- `payment_intents`: booking payment obligation, immutable snapshot dan `applied_attempt_guid`.
- `payment_attempts`: setiap percobaan, status, nomor urut, provider/channel/reference.
- `payment_events`: kejadian provider, verification, disposition, waktu/provider occurred at.
- `idempotency_records`: request create/retry yang deterministic.
- `bookings`, `booking_status_histories`, `destination_quotas`, tabel tiket dan notifikasi existing.

## 9.2 Penambahan/penyesuaian yang wajib dievaluasi

| Entitas | Field / constraint yang diharapkan | Catatan |
|---|---|---|
| Intent | `provider`, `environment`, immutable amount/currency, status, applied attempt | Validasi unique booking policy existing; jangan izinkan demo & DOKU saling menimpa |
| Attempt | `provider_transaction_id`, `merchant_reference`, `method`, `channel`, `provider_expires_at`, `unknown_reason`, `verified_paid_at` | Unique `(provider, environment, merchant_reference)` dan provider reference yang tidak null sesuai channel |
| Event | event ID/reference, raw digest, provider occurrence timestamp, normalized event type, verification/disposition, received timestamp | Unique semantic dedupe key `(provider, environment, event_key)`; jangan bergantung pada random UUID setiap delivery |
| Provider request | correlation/request-id, retriable state, retry count, last error sanitized | Dapat berupa attempt metadata/outbox; jangan simpan secret/token sebagai log |
| Reconciliation | last checked, next check, attempt count, result, terminal flag | Bisa ditaruh di attempt atau job metadata sesuai schema |
| Outbox/side effect | unique business event `booking.confirmed:{guid}` dan notification status | Mencegah tiket/notifikasi duplikat saat queue retry |

**Kebijakan penyimpanan:** simpan nomor VA/instruction hanya seperlunya untuk recovery, mask di log, enkripsi jika perlu sesuai klasifikasi data; jangan menyimpan private key/token API di database transaksi. Metadata webhook direduksi dan redacted, ada retention policy operasional yang disetujui.

# 10. DOKU Protocol & Channel Strategy

## 10.1 SNAP readiness gate

DOKU menganjurkan / mensyaratkan SNAP untuk Direct API sesuai channel dan migrasi. Integrator harus memastikan format autentikasi yang benar **per channel** sebelum mengkode endpoint: kebutuhan B2B token, asymmetric/symmetric signature, merchant key pair, DOKU Public Key, timestamp, digest, request-id dan endpoint resmi. **Jangan menganggap Client ID + Secret Key + DOKU Public Key yang telah diperoleh sudah cukup untuk seluruh SNAP channel.** Merchant Public Key/private key atau konfigurasi tambahan mungkin diperlukan dan harus dikonfirmasi di dokumentasi channel dan dashboard.

Periksa terlebih dahulu fitur Dynamic VA **DGPC (DOKU Generated Payment Code)**, yang menjadi default menurut dokumentasi DOKU. Jangan salah memilih DIPC/MGPC yang mempunyai model transaksi berbeda dan mungkin membutuhkan aktivasi khusus. Tentukan satu bank awal (contoh BNI) berdasarkan bukti transaksi Sandbox yang berhasil, bukan status menu ACTIVE saja.

## 10.2 Channel rollout

| Channel | Target | Sandbox rule | Production rule |
|---|---|---|---|
| Dynamic VA (BNI sebagai kandidat awal) | P0 | Create VA + simulator + webhook + reconciliation end-to-end | Aktifkan ketika channel live/credential/notification verified |
| VA bank lainnya | P1 | Tambah via channel adapter + contract tests per bank | Enable per channel, limit/rule berbeda |
| QRIS | P1 | **Tidak diklaim tersedia pada DOKU Sandbox**; gunakan demo/internal test dan mock adapter | Direct API QRIS hanya jika merchant/channel live diaktifkan; validasi URL dan signature sesuai spec |
| E-wallet | P1 | Uji channel yang benar-benar diizinkan provider; redirect dapat dibutuhkan | Native method-selection, auth/redirect eksternal bila diwajibkan provider |

`GET payments/methods` hanya mengembalikan `available=true` jika konfigurasi, channel activation, environment, dan eligibility booking telah lolos. Tidak boleh menawarkan metode provider yang cuma terdaftar di daftar statis.

# 11. Konfigurasi `.env` dan Deployment Contract

**Contoh rancangan environment variables (jangan masukkan nilai rahasia ke PRD/repository):**

```dotenv
DOKU_ENABLED=true
DOKU_ENVIRONMENT=sandbox
DOKU_CLIENT_ID=
DOKU_SECRET_KEY=
DOKU_PUBLIC_KEY_PATH=
DOKU_MERCHANT_PRIVATE_KEY_PATH=
DOKU_CHANNELS=va_bni
DOKU_HTTP_TIMEOUT_SECONDS=15
DOKU_RECONCILIATION_ENABLED=true
DEMO_PAYMENTS_ENABLED=false
```

- `config/doku.php` memilih endpoint resmi/credential set untuk environment terpasang, memvalidasi nilai dan memberikan typed config ke service. Endpoint Production **jangan diterka**; ambil dari dokumentasi channel saat implementasi dan uji.
- Di local: `.env` berisi Sandbox credential; **di Production deployment**: `.env` punya Live credential yang berbeda. Nama environment tetap sama, kode logic sama.
- `APP_ENV` Laravel dan `DOKU_ENVIRONMENT` tidak identik: `APP_ENV=local` boleh untuk DOKU Sandbox lokal, tetapi `DOKU_ENVIRONMENT=production` di local **harus ditolak** kecuali proses onboarding/allowlist operasional yang sangat eksplisit dan disetujui.
- Setelah mengubah `.env`, lakukan `php artisan config:clear` (development) atau cache ulang config/deploy restart sesuai prosedur, serta restart queue workers; config bukan saklar live yang berubah pada setiap request.
- Production require HTTPS domain permanen, queue worker, scheduler, DB persistent, protected secrets, backup, alerting, dan webhook registration. `localhost.run` **hanya untuk dev/test**.
- Bila `DOKU_ENABLED=false`, endpoint request transaksi DOKU fail closed dengan alasan stabil; callback verifikasi dan reconciliation transaksi existing tetap dikelola sesuai kebijakan shutdown yang aman, bukan di-drop begitu saja.

# 12. Webhook dan Rekonsiliasi

## 12.1 Webhook ingestion

- Route webhook POST di luar `AuthenticateApiToken`, tetapi tetap wajib melewati verifier DOKU spesifik channel.
- Ambil **raw body** asli untuk signature sebelum parsing/normalisasi, mengikuti spesifikasi kanal.
- Cocokkan signature dengan key **environment yang terikat deployment**, verifikasi merchant/account, reference/amount/currency, event code, event timestamp, dan idempotency key.
- Event invalid: jangan mengubah booking; catat alasan minimal/sanitized, beri respons sesuai standar provider.
- Event valid: simpan terdurable + unique dedupe; respons 2xx cepat setelah penerimaan aman; mutasi booking dikerjakan idempotent di DB transaction/queue sesuai reliability contract.
- Jika queue gagal, event tersimpan dan dapat di-retry. Jangan mengembalikan sukses sebelum persistence yang dibutuhkan terjamin.
- Bila webhook telah diterima tapi status provider ambigu, gunakan status query; jangan hanya mempercayai `success` string dari payload tanpa kontrak sign.

## 12.2 Rekonsiliasi

- Worker terjadwal memeriksa attempt `initiating/pending/unknown` yang melewati interval tanpa update.
- Gunakan merchant reference yang **sama**, kanal & environment tercatat pada attempt; jangan pernah membuat pembayaran baru dalam reconciliation.
- Backoff, jitter, timeout, circuit breaker sederhana, dan error budget operasional.
- Terminal statuses, late paid, provider discrepancy, duplicate paid, or booking conflicts masuk `needs_review` dengan reason code.
- Beri admin read-only investigation view/log dan prosedur eskalasi DOKU; mutasi manual uang/tiket harus berotorisasi serta audit-trail, di luar MVP jika tidak tersedia.

# 13. Booking, Kuota, Tiket, dan Konsistensi

Implementasi harus mengikuti aturan booking/approval existing, termasuk `awaiting_payment`, batas kedaluwarsa, local visit timezone, kuota, dan status history. Tinjau lock order yang dipakai `DemoPaymentService` agar callback DOKU tidak melanggar order atau memicu deadlock.

**Atomic paid finalization:** lock booking/related records dengan urutan deterministik; verifikasi amount/provider/env/pending state; terapkan attempt dan intent `paid` tepat sekali; ubah booking `confirmed` hanya jika eligible; catat status history dan enqueue event penerbitan tiket/outbox dengan unique business key. Bila payment valid tetapi booking tidak lagi eligible, simpan `needs_review` dan prosedur refund/manual investigation, **bukan** memaksakan confirmed.

Tiket tidak boleh diterbitkan ulang karena webhook duplicate, job retry, app reopen, atau admin refresh. Check-in dan akses tiket hanya berdasarkan payment provenance valid, bukan label `confirmed` saja untuk booking berbayar.

# 14. Security & Privacy Requirements

- Kredensial DOKU, merchant private keys, JWT secrets dan OTP hanya di backend environment/secret manager. Jangan print, upload, kirim via chat, atau commit.
- HTTPS/TLS verification wajib; dilarang `verify=false` dan fallback HTTP.
- Production `APP_DEBUG=false`, error stack trace tidak pernah ditampilkan publik, redaksi PII/secrets di logs.
- Hanya authenticated booking owner yang boleh membaca detail pembayaran, nomor VA, dan status; admin terikat role/permission yang sah.
- Webhook verifier mengikat signature dengan raw body, key environment, waktu, reference dan anti-replay sesuai provider specification; tidak menggunakan JWT aplikasi.
- Event/transaction ID harus unik; semua side effect idempotent; duplicate notification aman.
- Mencegah SSRF: redirect URL/host provider harus di-allowlist sesuai kanal, jangan mengikuti URL mentah yang berasal dari user.
- Konfigurasi status `paid` dan jumlah pembayaran tidak boleh diterima dari input Flutter.
- Cek lifecycle secret rotation, key permission pada filesystem, audit log, retention dan prosedur rollback.
- Jalankan security review terhadap concurrency, late payment, signature forgery, request replay, tenant/ownership bypass, admin privilege escalation, dan race kondisi booking expired.

# 15. Observability & Operasional

**Structured logs:** request ID, booking GUID (hashed/redacted sesuai kebijakan), payment intent/attempt GUID, provider, environment, channel, provider reference yang di-mask bila perlu, normalized status, event disposition, latency, reconciliation count. Jangan memasukkan full raw keys, Authorization, sensitive notification fields.

**Metrik utama:** initiation success/fail/unknown rate, webhook verification pass/reject, webhook processing age, queue backlog, pending payment age, reconciliation stuck count, booking confirmed without valid payment (harus 0), late payment review count, environment mismatch (harus 0), issued ticket duplicate (harus 0).

**Alert:** webhook 5xx berulang, invalid signatures spike, pending reconciliation melebihi threshold, double-application attempt, production channel unauthorized, payment events gagal diproses, queue worker mati.

**Runbook:** setup Sandbox, tunnel lifecycle URL rotate, Notification URL update, simulator VA, triage mismatch, query provider status, resending notification via DOKU dashboard, secret rotation, failover, deploy and rollback, manual review late paid.

# 16. Non-Functional Requirements

| ID | Kebutuhan | Target / cara verifikasi |
|---|---|---|
| NFR-01 | Reliability | Tidak ada double charge/provider duplicate untuk key yang sama; concurrency test |
| NFR-02 | Durability | Webhook tersimpan sebelum acknowledged; test kill worker dan replay |
| NFR-03 | Resilience | Provider timeout → `unknown`, query/reconcile; tidak auto-create ulang |
| NFR-04 | Performance | P95 GET payment status responsif pada test load; threshold disepakati dengan baseline sesudah implementasi; jangan bergantung sync DOKU untuk setiap GET |
| NFR-05 | Secure defaults | Production fail-closed untuk invalid env, debug on, missing secrets/keys |
| NFR-06 | Data integrity | FK, unique indexes, DB transactions, lock ordering, audit event immutability |
| NFR-07 | Backward compatibility | Existing demo endpoints dan Flutter legacy tidak rusak selama migration/feature rollout |
| NFR-08 | Maintainability | Semua provider/channel ditest via HTTP fake/fixtures; controller tipis, config central |
| NFR-09 | Testability | Unit + feature + contract + simulator Sandbox + UAT; tidak perlu uang live pada test otomatis |
| NFR-10 | Recoverability | Pending/error event dapat di-replay dan direkonsiliasi tanpa perubahan status semu |

# 17. QA / Acceptance Test Matrix

| Test ID | Prioritas | Scenario | Expected |
|---|---|---|---|
| T-01 | P0 | Sandbox create Dynamic VA | Provider VA number tersimpan, `test_only=true`, pending |
| T-02 | P0 | Sandbox simulator paid + signed webhook | Intent paid, booking confirmed, tiket sekali |
| T-03 | P0 | Duplicate webhook 2–10x | Satu finalization, tidak ada tiket/event applied duplikat |
| T-04 | P0 | Modified invalid signature/body | Reject; tidak ada mutasi booking |
| T-05 | P0 | Same idempotency key concurrent | Satu attempt/provider call dan respons konsisten |
| T-06 | P0 | Same key different payload | `idempotency_conflict` |
| T-07 | P0 | Timeout setelah provider menerima create | `unknown`, reconcile existing reference, tidak create ulang |
| T-08 | P0 | Booking expired sebelum payment initiated | Block, no provider call |
| T-09 | P0 | Paid late setelah expired/cancelled | Needs review; tiket tidak diterbitkan |
| T-10 | P0 | Wrong owner/token | 401/403/404 sesuai policy; VA tersembunyi |
| T-11 | P0 | Wrong amount/currency/reference | Reject/quarantine/reconcile; booking tak confirmed |
| T-12 | P0 | Event Sandbox terhadap Production record | Reject environment mismatch |
| T-13 | P0 | DEMO simulate terhadap DOKU intent | Reject tanpa mutasi provider intent |
| T-14 | P0 | Flutter resume / recovery after kill | GET status/instruction yang sama, no duplicate create |
| T-15 | P0 | Production config missing/APP_DEBUG=true | Fail closed sebelum transaksi dibuat |
| T-16 | P0 | Payment already paid then repeated create | Return final status/conflict; no new attempt |
| T-17 | P1 | Change bank after verified failed/expired | New attempt hanya jika eligible |
| T-18 | P1 | Provider reversal/refund notice | Audit and defined review/ticket handling |
| T-19 | P1 | Queue down during webhook | Event durably received, can reprocess safely |
| T-20 | P1 | QRIS unsupported in Sandbox | Not advertised as real DOKU QRIS; demo remains available only as test |
| T-21 | P1 | E-wallet external redirect return | No confirmed before verified callback/query |
| T-22 | P1 | Old Sandbox data after app moved to Production | Never queried/verified using Production keys |

**Test strategy:** Pest unit tests (state and validation), Laravel feature tests (JWT, route/middleware/DB), fake DOKU HTTP contract fixtures (signature/error/timeout), concurrency and race test (MySQL preferred), external Sandbox Payment Simulator UAT, release smoke test + monitoring. Jangan menggunakan real Production card/bank payment untuk automated CI.

# 18. Implementation Breakdown / Delivery Milestones

Ini **delivery roadmap dalam PRD**, belum rencana coding langkah-per-baris. Setelah PRD disetujui, pecah menjadi implementation plans kecil dengan file paths, failing test, code, passing test, code review, dan commits sesuai metode Superpowers.

| Milestone | Isi | Definition of Done |
|---|---|---|
| M0 — Discovery / spec lock | Audit schema/routes, SNAP per VA channel, key pair, dashboard blank, approved channel | Keputusan protocol & endpoint sah; no unknown critical blocker |
| M1 — Config & security | `config/doku.php`, env validation, secrets, environment isolation, capability flag | Config tests + invalid-env fail-closed |
| M2 — Payment domain | Provider interface, intent/attempt event snapshots, idempotency, schema migration | Unit/concurrency tests; demo regression clean |
| M3 — Dynamic VA adapter | BNI candidate via valid SNAP flow; normalization, inquiry | Fake contract tests + DOKU Sandbox create VA |
| M4 — Verified webhook | Signature validation, persistence/dedupe, async apply and booking/ticket | Signed simulator callback → one verified paid outcome |
| M5 — Reconciliation & operations | Unknown/pending jobs, audit, admin read, alerting | Timeout/missed webhook recovered without duplicate |
| M6 — Flutter API compatibility | Final schema/methods/instruction, docs/OpenAPI examples | Contract test; Flutter can render VA native without provider credentials |
| M7 — More channels | Additional VA, QRIS production gated, e-wallet redirect | Each enabled channel has verified contract + tests |
| M8 — Production readiness | Live merchant/keys, stable HTTPS, webhook, CI/QA/security, rollback | Go-live checklist signed; controlled pilot & monitoring |

**Go / No-Go:** No live release before M0–M6 P0 tests pass, Production credentials/channel approval verified, current live webhook configured, debug off, queue/scheduler running, and rollback plan documented. M7 channels may launch individually using flags if approved.

# 19. Proposed File/Module Touchpoints

Paths below are **design proposals**, not claims that files already exist:

| Area | Likely location / task |
|---|---|
| Config | `config/doku.php`, `.env.example`, `config/operations.php` review |
| Auth & transport | `app/Services/Doku/*` (client, signer/token, environment config, channel adapter) |
| Orchestration | Extend/restructure `PaymentService`, `PaymentGateway`; shared application service for initiate/status/finalize |
| Eligibility | Update `PaymentEligibility` to verified provider/env provenance |
| Controller / validation | Extend `PaymentController`; new request class for DOKU attempt; new webhook controller and verifier |
| Routing | Extend `routes/api.php` preserving v1; webhook outside JWT middleware |
| Persistence | New additive migration/index if schema audit requires; event/outbox/reconcile jobs |
| Background processing | `app/Jobs/*`, scheduler bindings/commands as needed |
| Testing | `tests/Feature/Payments/*`, `tests/Unit/Payments/*` using Pest and HTTP fakes |
| Documentation | API contract, webhook runbook, DOKU env setup, incident/rollback, QA evidence |

**Design constraint:** gunakan arsitektur existing terlebih dahulu. Hindari framework abstraction yang berlebihan; satu adapter/provider interface dengan channel variants yang diperlukan lebih tepat daripada menduplikasi service per environment.

# 20. Risiko, Dependency, dan Keputusan Terbuka

| ID | Risiko / dependency | Dampak | Tindakan / owner |
|---|---|---|---|
| R-01 | Payment Settings VA blank di dashboard | Tidak dapat daftar Notification URL | Uji browser/akses; eskalasi DOKU sebelum webhook E2E |
| R-02 | SNAP merchant key pair / token requirements belum dipastikan | Signature/auth request gagal | Konfirmasi spec channel dan key registration saat M0 |
| R-03 | Status VA ACTIVE ≠ API transaksi berhasil | Implementasi bisa tertahan | PoC create VA satu channel via Sandbox sebelum ekspansi |
| R-04 | Production merchant/keys belum tersedia | Tidak bisa live | Merchant onboarding, channel approval, production test plan |
| R-05 | QRIS tidak disupport DOKU Sandbox | Tidak bisa E2E DOKU QRIS Sandbox | Tetap mock/demo; live QRIS diuji sesuai izin merchant |
| R-06 | Existing schema unique/index belum diaudit | DB conflicts/double apply | Migration review + tests sebelum coding final |
| R-07 | Existing PaymentEligibility hanya demo | Potensi tiket verified palsu/false negative | Refactor provider-aware, invariant cross-check |
| R-08 | Existing PaymentService hardcoded sandbox | Production GET status gagal | Hapus hardcode dan query berdasarkan immutable env |
| R-09 | Tunnel domain sementara / exposes whole dev API | Webhook lost dan informasi internal bocor | Gunakan hanya dev, debug off; update notification URL, stop tunnel |
| R-10 | Late paid/failed→paid after expiration | Financial loss/manual dispute | Needs review and explicit operations runbook |
| R-11 | Long DB locks during external calls | Deadlock/stuck booking | Two-phase persistence, short transactions, status reconciliation |
| R-12 | Provider response/protocol differs per channel | Adapter bugs | Channel-specific fixtures, never generic signature guessing |

**Open decisions sebelum code final:** bank VA pertama berdasarkan API Sandbox, official channel endpoint and auth spec, notification URL registration, supported inquiry method, final merchant reference format/constraints, siapa menanggung biaya transaksi (default: tidak menambah nominal user sebelum kebijakan disetujui), ops handling late paid/refund, data retention, dan whether live deployment/database dipisah.

# 21. Local Dev, Tunnel, Sandbox & Production Runbook (Ringkas)

## Local/Sandbox

1. Laravel: `php artisan serve --host=127.0.0.1 --port=8000`.
2. Android Emulator: `adb reverse tcp:8000 tcp:8000`; Flutter API tetap `http://127.0.0.1:8000/api/v1` selama reverse aktif.
3. Laravel `.env`: Sandbox-only secrets, `DOKU_ENVIRONMENT=sandbox`, `APP_DEBUG=false` jika tunnel dibuka; reload config setelah perubahan.
4. Tunnel: jalankan SSH `localhost.run` command yang sudah berhasil; URL `.lhr.life` bisa berubah.
5. Uji GET publik non-sensitif via URL tunnel untuk memastikan forwarding; **jangan menggunakan GET ke webhook POST sebagai indikator kemampuan menerima webhook**.
6. Setelah webhook route dan verifier siap, daftarkan HTTPS Notification URL yang **saat itu aktif** di DOKU sesuai channel dan cek dashboard yang sebelumnya blank.
7. Gunakan Payment Simulator DOKU yang sesuai channel VA untuk E2E; simpan hasil uji dan cek ticket hanya setelah verified callback/reconcile.
8. Tutup tunnel usai pengujian. Domain baru saat restart perlu re-register Notification URL.

## Production

1. Deploy Laravel di HTTPS domain permanen dengan DB/worker/scheduler terpisah dari Sandbox.
2. Konfigurasi `DOKU_ENVIRONMENT=production`, live secrets, key pair/permissions, enable hanya channel yang diizinkan.
3. Verifikasi webhook live dan outbound DOKU API, `APP_DEBUG=false`, log redaction, alerting aktif.
4. Jalankan smoke test terkontrol pada metode live yang disetujui; **jangan hidupkan semua channel sekaligus**.
5. Jika rollback aplikasi, pastikan in-flight Production payment tetap bisa menerima webhook dan reconciliation — rollback kode tidak boleh meninggalkan uang pending tanpa handler.

# 22. Review Protocol: Superpowers dan Subagents (Batas Maksimal 3)

Pengguna mempunyai agent definitions lokal pada `C:\Users\itsba\.codex\agents` (screenshot), antara lain `system_architect`, `backend_engineer`, `security_auditor`, `qa_engineer`, dan `master_orchestrator`; pengguna juga menyebut lokal skills `C:\Users\itsba\.agents\skills` dan Superpowers. **Folder Windows tidak ter-mount ke sesi pembuat PRD ini, jadi definisi lokalnya belum dibaca/dijalankan dan tidak ada subagent yang diklaim sudah bekerja.**

Untuk eksekusi di Codex CLI pengguna nanti, pakai **maksimal tiga subagents yang benar-benar dibutuhkan** (orchestrator utama tidak dihitung subagent):

1. `system_architect` — audit keputusan provider/environment isolation, state machine, data migrations, API contract, fail-closed logic.
2. `backend_engineer` — implement Laravel config, client/channel adapter, orchestration, persistence, queue, webhook, tests secara TDD.
3. `security_auditor` — review signature/SNAP, secrets, environment mismatch, ownership, replay, concurrency, timeout, live gating; hasil findings wajib diluluskan sebelum M8.

QA test matrix tetap dimiliki orchestrator utama; jika perlu `qa_engineer`, harus **menggantikan** satu dari tiga agent tersebut, bukan menambah agent keempat. Di mesin pengguna, baca dulu `AGENTS.md`/agent TOML dan setiap SKILL.md dari direktori lokal yang relevan; ikuti workflow Superpowers **brainstorming → desain/PRD approval → writing-plans → TDD → code review → verification-before-completion**. PRD ini adalah tahap desain; belum menjalankan coding atau subagent-driven execution.

# 23. Definition of Ready & Definition of Done

## Definition of Ready (implementasi)

- [ ] PRD disetujui dan scope channel disepakati.
- [ ] Schema payment/booking aktual dan tests baseline diaudit.
- [ ] SNAP VA channel pertama + auth/signature/key requirements tervalidasi.
- [ ] DOKU Sandbox create VA dan callback behavior bisa diuji.
- [ ] Payment Settings blank telah diselesaikan atau ada jalur konfigurasi resmi dari DOKU.
- [ ] Error/status mapping dan DTO final disepakati.
- [ ] Tidak ada kredensial sensitif di source/PRD.

## Definition of Done (Backend DOKU)

- [ ] `DOKU_ENVIRONMENT=sandbox` end-to-end VA bisa create → simulator paid → verified webhook → ticket.
- [ ] Source yang sama dapat di-deploy `production` dengan live config terpisah tanpa perubahan kode domain.
- [ ] `PaymentService`, `PaymentGateway`, `PaymentEligibility` tidak hardcoded sandbox/demo sebagai jalur DOKU.
- [ ] Stable API contract untuk Flutter dengan native VA, QRIS, e-wallet representations.
- [ ] Unknown, retry, duplicate event, invalid signature, expired/late paid, environment mismatch aman.
- [ ] Semua test P0, TDD, migration review, security review, dan operasional runbook lulus.
- [ ] Production rollout dijaga oleh merchant/channel approval, domain HTTPS stabil, logs/alerts, dan rollback.

# 24. Sumber dan Dasar Verifikasi

**Repository (source publik yang diakses):**

- Backend root: https://github.com/joytimess/pami-wisata-daerahku-api
- `routes/api.php`: https://raw.githubusercontent.com/joytimess/pami-wisata-daerahku-api/main/routes/api.php
- `PaymentController.php`: https://raw.githubusercontent.com/joytimess/pami-wisata-daerahku-api/main/app/Http/Controllers/Api/V1/PaymentController.php
- `PaymentService.php`: https://raw.githubusercontent.com/joytimess/pami-wisata-daerahku-api/main/app/Services/PaymentService.php
- `PaymentGateway.php`: https://raw.githubusercontent.com/joytimess/pami-wisata-daerahku-api/main/app/Services/PaymentGateway.php
- `PaymentEligibility.php`: https://raw.githubusercontent.com/joytimess/pami-wisata-daerahku-api/main/app/Services/PaymentEligibility.php
- `DemoPaymentService.php`: https://raw.githubusercontent.com/joytimess/pami-wisata-daerahku-api/main/app/Services/DemoPaymentService.php
- `PaymentStates.php`: https://raw.githubusercontent.com/joytimess/pami-wisata-daerahku-api/main/app/Support/PaymentStates.php
- Flutter root: https://github.com/koyotot/pami-wisata-daearahku
- Flutter `DemoCheckoutScreen`: https://raw.githubusercontent.com/koyotot/pami-wisata-daearahku/main/lib/screens/demo_checkout_screen.dart
- Flutter `BookingPaymentSection`: https://raw.githubusercontent.com/koyotot/pami-wisata-daearahku/main/lib/widgets/booking_payment_section.dart

**DOKU resmi:**

- Direct API: https://docs.doku.com/accept-payments/integration-tools/direct-api
- Integration setup/API Keys: https://docs.doku.com/get-started/manage-business/set-up-integration
- Webhook/Notification: https://docs.doku.com/get-started/manage-business/set-up-integration/webhook-payment-notification
- Payment method management/DGPC: https://docs.doku.com/get-started/manage-business/manage-payment-methods
- SNAP migration: https://docs.doku.com/miscellaneous/snap-migration
- Payment limitations: https://docs.doku.com/accept-payments/payment-methods/requirements-and-limitations

**Superpowers (metodologi publik):**

- https://github.com/obra/superpowers
- https://raw.githubusercontent.com/obra/superpowers/main/skills/brainstorming/SKILL.md
- https://raw.githubusercontent.com/obra/superpowers/main/skills/writing-plans/SKILL.md

---

**Status PRD:** Siap untuk review desain dan penguncian channel pertama. **Belum ada kode Laravel/Flutter yang diubah, tidak ada webhook DOKU yang didaftarkan, tidak ada transaksi Production yang dilakukan.**
