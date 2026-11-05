# PRODUCT REQUIREMENTS DOCUMENT (PRD)

> **Amendment keputusan user - 5 November 2026 (versi 1.1, berlaku atas ketentuan yang bertentangan di bawah):** Prioritas Laravel 1B.2 adalah **Demo Payment Simulator** untuk demo kampus. DOKU Sandbox belum disetup, tetap **BLOCKED**, dan `DOKU_ENABLED=false`. Ini perubahan jalur provider dalam Phase 1B yang sama, bukan fase development baru. Hasil simulasi tidak pernah dinyatakan sebagai transaksi DOKU atau uang nyata.

### Ketentuan delivery Demo Simulator pada Phase 1B

1. **1B.1 tetap DONE:** reuse `payment_intents`, `payment_attempts`, `payment_events`, dan idempotency infrastructure existing; tidak mengulang foundation/Phase 0/1A.
2. **1B.2 Demo:** provider literal `demo`, environment literal `demo`; tidak memakai provider `doku` untuk hasil simulasi. Success yang diotorisasi server boleh mengonfirmasi booking dan menjadi evidence eligibility QR **hanya dalam mode demo yang diizinkan**. Failed/expired menutup attempt, bukan melepaskan held quota atau memajukan merchant booking deadline.
3. **Gate:** explicit `DEMO_PAYMENTS_ENABLED=true` serta runtime application environment tepat `local`, `testing`, atau `demo`. Default flag false; `production`, `staging`, atau environment lain menolak mutation walaupun flag true. Gate diperiksa ulang pada eligibility sehingga token QR demo yang sudah pernah terbit tidak dapat dipakai untuk check-in baru saat demo dimatikan/berpindah ke Production. Gunakan database demo/disposable terpisah untuk operasional demo kampus.
4. **API:** JWT owner role 0 aktif; `POST /api/v1/bookings/{booking}/demo-payment` untuk initiation dan retry aman; `POST /api/v1/bookings/{booking}/demo-payment/simulate` dengan `attempt_guid` dan `outcome=success|failed|expired`. Keduanya wajib header `Idempotency-Key`. Client tidak menentukan nominal, currency, provider, environment, paid timestamp, atau arbitrary status paid. Tidak ada webhook/signature/invoice DOKU buatan.
5. **Transaksi:** lock quota -> booking -> current actor -> idempotency -> intent -> attempt, sejalan parent lock expiry. Validasi owner, current role/status, approval history, amount/currency snapshot, provider/environment immutable, visit usability timezone destinasi, dan cutoff 1 jam dilakukan di server. Gunakan satu timestamp outcome untuk validasi cutoff dan evidence. Satu applied success, satu booking confirmation history, dan inbox DEMO yang deduplicated disimpan atomik. Notifikasi simulator tidak membuat delivery email/FCM.
6. **Idempotency:** record terpisah menurut actor/operation/key, hash memuat booking dan payload. Key sama/payload sama mengembalikan receipt snapshot tersimpan; payload berbeda 409. Pending attempt dipakai kembali; retry baru hanya setelah failed/expired dan sebelum cutoff. Unknown atau outcome bertentangan tidak membuka attempt/paid baru. Replay tetap memerlukan authorization dan gate; GET status menyediakan state terkini.
7. **Timing:** simulator merupakan hasil server langsung, tidak menerima `paid_at` dari client. Outcome baru sesudah cutoff ditolak, termasuk success; tidak ada emulasi late callback/grace atau rekonsiliasi DOKU. Booking expiry tetap 1 jam -> verification -> review setelah 15 menit. Booking gratis, pending approval, terminal, payment review, mismatch, atau visit tidak usable tidak boleh mendapat capture success baru.
8. **QR:** reuse aturan approval + confirmed + paid evidence + H-5/day-H/timezone existing, tanpa mock PaymentEligibility dalam jalur produk. Evidence harus cocok dengan applied demo intent/attempt/capture dan approval history; reversal/refund menutup eligibility. Booking, ticket, dan scanner membawa `payment_provider`, `payment_environment`, `payment_test_only`, serta `payment_label=DEMO` untuk provenance demo.
9. **1B.3 Demo:** laporan eksplisit `environment=demo` hanya membaca provider demo dan memberi `test_only=true`, `label=DEMO`, `source=simulated_demo_events`. Default production tidak mencampur satu pun nominal demo. Simulator aktif merupakan source siap untuk counter/zero sums demo walau belum ada capture; tanpa source production tetap null/unavailable. Fee/net/settlement tanpa source tetap null. Historical demo reports yang berlabel tetap read-only dan RBAC-scoped, bukan aktivasi payment provider.
10. **QA/DoD demo:** targeted feature dan MySQL disposable real-process verification mencakup success/failed/expired, idempotency, duplicate event/application, expiry race, held quota, approval ownership, QR timezone/gate, RBAC, serta finance separation. Demo DONE hanya membuktikan simulator lokal; DOKU Sandbox DoD tetap BLOCKED sampai akun/channel/callback dan integrasi aktual dapat diverifikasi. Flutter, Production payment, dependencies, commit/push otomatis, dan database reset development tetap di luar izin.

Ketentuan berikut yang menyebut larangan fake provider berlaku pada transaksi operasional nyata/DOKU dan preview presentasional. Pengecualian yang disetujui user hanya simulator terisolasi di atas; tidak mengubah definisi pembayaran nyata atau mengklaim keseluruhan DOKU Phase 1B selesai. Bagian DOKU di bawah tetap menjadi requirement tertunda untuk integrasi berikutnya dalam **1B.2**.

## WISATA DAERAHKU — LARAVEL 13 PHASE 1B
### Payment Gateway, Verified Booking & Financial Foundation

**Versi:** 1.0 — 5 November 2026  
**Status:** Implementation-ready dengan **DOKU Sandbox credential gate**  
**Repository sasaran:** https://github.com/joytimess/pami-wisata-daerahku-api  
**Baseline publik diaudit:** `main` @ `c43a5d18183a660ccf45830c2c51e6e2698ff322` (commit 4 Nov 2026 15:45 UTC). **Codex HARUS membaca local HEAD/worktree lagi, karena local bisa lebih baru.**  
**Scope:** **LARAVEL SAJA**. Tidak ada Flutter PRD, perubahan Flutter, atau fase baru.  
**Urutan dalam satu Phase 1B:** **Laravel 1B.1 → Laravel 1B.2 → Laravel 1B.3**. **Flutter Phase 1B baru sesudah keseluruhan backend 1B dapat diserahterimakan.**

> **Instruksi prioritas:** Jangan ubah urutan roadmap; jangan kembali mengimplementasikan Phase 0 atau 1A. Jangan menandai 1B.2 / 1B.3 selesai dengan provider palsu jika akun DOKU Sandbox atau bukti pembayaran terverifikasi tidak tersedia. Seluruh perubahan harus incremental, backward-compatible, dan tidak merusak booking, approval queue, quota, ticket QR, ataupun financial-null semantics yang sudah dipakai Flutter Phase 1A.

---

## 1. Ringkasan produk & hasil akhir

**Masalah:** Laravel sudah punya booking yang disetujui menjadi `awaiting_payment`, jatuh tempo 1 jam, toleransi verifikasi 15 menit, serta QR tiket dengan guard `PaymentEligibility`. Namun belum ada ledger/attempt, gateway DOKU, webhook verified, dan sumber KPI finansial. `PaymentEligibility::status()` saat audit tetap `unverified` dan financial dashboard selalu `unavailable/null`. `PaymentPreviewService` hanya GET owner-only yang tidak memproses pembayaran.

**Target Phase 1B utuh:**

1. **1B.1 — Payment Domain & API Contract:** domain intent/attempt/event, migration additive, provider seam, read-only preview dan request/status contract; simulasi presentasional tidak pernah mengubah booking menjadi `paid`/`confirmed`.
2. **1B.2 — DOKU Sandbox Verified Payment:** koneksi server-ke-server sesuai channel resmi yang tersedia, payment initiation, provider notification/status inquiry, verifikasi & idempotency, reconciliation, transisi booking atomik dan QR eligibility; tanpa real-money production.
3. **1B.3 — Scoped Financial Analytics:** metrik pembayaran **terverifikasi** dengan metode tanggal yang benar, RBAC destinasi, pemisahan Sandbox/Demo/Production, gross/fees/net hanya jika sumbernya ada, tanpa mengganggu KPI operasional Phase 1A.

**Definition of Done keseluruhan:** Backend Laravel bisa membuat payment instruction DOKU Sandbox yang sah dari booking owner yang eligible, memproses hasil otoritatif/duplikat/terlambat, menjaga konsistensi booking/QR/kuota, serta mengembalikan financial reports role-scoped yang dapat langsung dipakai Flutter nanti. Jika Sandbox belum tersedia, laporkan **1B.1 DONE, 1B.2/1B.3 BLOCKED/PARTIAL secara jujur**; jangan memalsukan DoD.

### 1.1 In scope

- Laravel PHP 8.3 / Laravel 13 / MySQL; existing `/api/v1` JWT, Form Requests, Resources, Services, DB transactions, queue/scheduler.
- Booking owner role 0, assigned Admin role 1, global Superadmin role 2; assignment history tetap dipertahankan.
- QRIS / Virtual Account / E-Wallet **hanya jika channel/fitur DOKU merchant Sandbox tersedia**; channel capabilities dikembalikan secara eksplisit.
- Payment states, provider references, booking transition, notifications, reconciliations dasar, financial counters.
- Contract dokumentasi & targeted safety tests termasuk MySQL concurrency yang relevan.

### 1.2 Out of scope / tidak boleh dicuri dari fase lain

- **Tidak mengubah satu file Flutter**, flow navigasi, UI, asset, atau `docs/DESIGN.md` Flutter.
- Tidak mengulang atau mendesain ulang Admin Performance/Approval Queue/operational endpoints Phase 1A.
- Tidak mengaktifkan DOKU Production atau melakukan pembayaran real-money. Production switch membutuhkan persetujuan dan security review tersendiri.
- Tidak mengimplementasikan Phase 2: management KPI target, leaderboard, workload fair ranking, export XLSX/PDF, alert escalation, workflow refund Admin-recommend/Superadmin-approve.
- Tidak mengimplementasikan Phase 3: settlement accounting, advanced reconciliation engine, archival lintas tahun, payout accounting.
- Refund **event ingestion bila disediakan gateway** boleh disimpan untuk perhitungan final yang benar; **menginisiasi refund atau membuat approval workflow refund bukan scope 1B**.
- Tidak membuat provider hasil sukses palsu untuk data operasional. Data testing boleh berada di database/test tenant terpisah dan jelas berlabel.

---

## 2. Audit kode existing & matriks risiko regresi (static source review)

**Bukti audit:** inspeksi source `main` pada baseline di atas; **tidak** ada test, migration, emulator, ataupun deployment yang dijalankan oleh penyusun PRD. Codex harus mengecek ulang local checkout sebelum mengubah kode.

| Source existing / fakta yang diperiksa | Risiko ketika 1B diterapkan | Keputusan wajib |
|---|---|---|
| `app/Services/BookingService.php` — approval berbayar: `pending_approval → awaiting_payment`; pembayaran 1 jam + grace 15m; expiration: `awaiting_payment → payment_verification → payment_review` | webhook datang bersamaan dengan `bookings:expire`; status bisa tertimpa/QR terbit salah | Satu service domain transisi payment; ikuti lock ordering yang sudah ada, recheck state/amount/deadline dalam DB transaction |
| `app/Support/BookingRules.php` — `HELD` termasuk pending, awaiting, verification, review, confirmed | kuota lepas sebelum selesai, tertahan tanpa resolusi, atau double allocation | Jangan ganti `HELD` sembarangan; jaga timing dan manual resolution untuk exception |
| `app/Services/PaymentEligibility.php` — `unverified` | accidental QR paid saat mockup/client redirect | Bind hanya ke **verified ledger outcome**; default fail-closed, unknown tetap unverified |
| `app/Services/TicketService.php` — `paid` + `confirmed` + H-5/day-H destinasi | issue QR sebelum approval atau berdasarkan UI success | QR eligibility tidak diubah kecuali memperbaiki dependency authoritative; tidak menyentuh token issuance rules |
| `app/Services/TicketCheckInService.php` — scope Admin dan day-of-visit, requires payment | check-in tanpa verified paid | Reuse unchanged guard; regression test paid/unpaid/reversed/out-of-scope |
| `app/Services/ApprovalQueueService.php` — claim + audit, mode compat/strict | payment flow merusak ownership atau SLA 24h | Jangan menyentuh claim state; `APPROVAL_CLAIM_MODE` tetap mengikuti deployment config |
| `app/Services/OperationalDashboardService.php` — financial `unavailable`, `null` | revenue palsu, fan-out penghitungan akibat assignment join | Financial analytics terpisah, dipasang pada existing response tanpa breaking keys; EXISTS/filter scoped dan verified accounting only |
| `app/Services/PaymentPreviewService.php` + `config/operations.php` — preview off; doku hard-disabled | preview berubah menjadi payment side effect atau flag langsung membuka semua | Preview owner-only read-only tetap; payment commands baru dengan explicit sandbox gate |
| `app/Services/BookingNotificationService.php`, jobs, inbox | notifikasi duplikat/false payment success | Record event idempotently dan enqueue setelah commit; jangan menyebut paid sebelum verifikasi |
| `app/Services/BookingDocumentService.php` — invoice bukan bukti pembayaran | PDF jadi kwitansi palsu | Pertahankan makna invoice, tambahkan receipt verified hanya bila benar-benar diperlukan dan ada bukti source |
| `routes/api.php` + `BookingResource.php` | endpoint/field hilang, Flutter 1A patah | Additive routes + fields, pertahankan status, metadata, error envelope existing |
| `database/migrations/2026_10_29_080000_create_booking_tables.php`, `2026_11_04_152334_create_booking_approval_claim_tables.php` | migrasi destruktif, constraint existing tertimpa | Migration baru additive; UUID GUID, FK `*_guid`, unique index jelas; jangan edit migration lama yang sudah berjalan |
| `routes/console.php` — bookings expire, notifications, ticket QR schedule | race scheduler vs webhook | Konsisten lock ordering, retry bounded, reconciliation job without overlap; jangan matikan scheduler Phase 1A |

### 2.1 Test regression existing yang menjadi baseline (jalankan sesuai dampak)

- `tests/Feature/BookingApiTest.php`
- `tests/Feature/BookingServiceTest.php`
- `tests/Feature/AdminOperationsTest.php`
- `tests/Feature/SmartTicketSmokeTest.php`
- `tests/Feature/BookingDocumentsTest.php`
- `tests/Feature/BookingNotificationTest.php`
- `tests/Feature/FcmDeliveryTest.php` / `tests/Feature/InboxServiceTest.php` jika menyentuh notifikasi.
- `tests/Mysql/ConcurrencyTest.php`, `tests/Mysql/ClaimConcurrencyTest.php` untuk deadlock/race yang relevan.

**Aturan testing:** user meminta *tinjau regresi*, bukan menjalankan full regression suite untuk semua modul. Jalankan **targeted Pest + MySQL disposable concurrency** yang terdampak, jangan `migrate:fresh`/seed/alter database kerja. Jika risk baru menuntut test lain, jelaskan alasannya. Test tidak PASS sebelum benar-benar dieksekusi.

---

## 3. Aturan bisnis tak boleh berubah

1. Booking user dimulai `pending_approval`; approval Admin 24 jam. Approval hanya oleh owner claim sesuai policy Phase 1A, di destinasi assignment yang sah.
2. Approval booking **berbayar** → `awaiting_payment` selama **1 jam**. `notification_grace_expires_at` +15 menit adalah toleransi rekonsiliasi, bukan perpanjangan otomatis untuk pelanggan membayar.
3. Approval booking **gratis** dapat menjadi `confirmed` menurut aturan lama, namun `confirmed` **bukan bukti `paid`**. Kebijakan QR untuk booking gratis harus ditetapkan secara eksplisit dan aman; **jangan diam-diam menandai pembayaran gratis sebagai gateway-paid**.
4. Provider initiation hanya boleh untuk booking owner role 0, `awaiting_payment`, nominal >0, currency/metode eligible, deadline belum lewat. Nominal/currency diambil dari booking snapshot database, **bukan request Flutter**.
5. `payment_intent` (domain internal Laravel) berbeda dengan existing `booking-intents` untuk idempotency pembuatan booking. Jangan menggabungkannya.
6. Payment confirmation membutuhkan verifikasi signature/keaslian + mapping reference + amount/currency + merchant/environment + status authoritative. Return URL atau screenshot/user action tidak cukup.
7. Hanya transisi **valid verified paid** yang boleh `awaiting_payment/payment_verification → confirmed` secara atomik. `payment_review` memerlukan kebijakan penyelesaian aman; jangan auto-confirm melewati policy timing yang ditetapkan di 1B.2.
8. QR Ticket tersedia H-5 setelah paid + confirmed, jika approval terjadi setelah H-5 maka eligible segera, check-in hanya hari kunjungan menurut timezone destinasi. Reversal/refund/chargeback harus fail-closed/revoke bila relevan.
9. Jangan merombak kuota, booking invoice, status histories, Admin assignment, claim ownership, FCM, OTP/JWT.
10. **Sandbox adalah data uji**, tidak boleh diberi label real-world revenue. Semua data ledger dan laporan harus environment-tagged.

### 3.1 Aturan pembayaran terlambat / tidak pasti (wajib diimplementasikan dan dites)

- Provider status `PENDING`/timeout/request unknown → **jangan paid**. Simpan attempt `unknown` bila perlu dan reconcile.
- Callback dapat datang setelah merchant cutoff 1 jam. Verifikasi **provider paid_at yang dapat dibuktikan** dan merchant expiry; jika paid_at <= payment_expires_at, masih dalam grace, dan booking/kuota valid → eligible untuk resolve. Jika callback datang sesudah grace, payment status boleh dicatat verified, tetapi booking **tidak langsung confirmed**: tandai exception `payment_review` dan butuh reconciliation/manual safe resolution.
- Provider paid_at **setelah** `payment_expires_at`, tidak ada waktu tepercaya, state sudah cancelled/rejected/expired/visit unusable, atau nominal mismatch → jangan konfirmasi booking; simpan exception/audit dan flag review. Jangan kehilangan uang yang telah dibayar; jangan menganggap booking terjual.
- Webhook/reconcile vs expire scheduler mengambil lock dengan urutan yang identik, memeriksa status terkini, dan memiliki satu sumber kebenaran transisi.
- Tidak mengubah booking menjadi `expired` semata-mata karena payment attempt gagal sebelum merchant booking deadline; retry boleh dilakukan selama window valid dan tidak membuat double payment.
- Beberapa attempts untuk satu booking: maksimal **satu pembayaran otoritatif yang diaplikasikan** ke booking; double-paid gateway instances ditandai discrepancy dan ditangani secara aman, bukan double revenue atau double QR.
- **Policy settlement/finance:** `collected` (verified money movement) ≠ `recognized_booking_revenue` (paid booking successfully fulfilled). Keduanya dibedakan jika ada late-paid exception. Jangan mengakui pendapatan booking sukses tanpa valid fulfillment.

---

## 4. Laravel 1B.1 — Payment Intent Architecture & Safe Mockup Contract

### 4.1 Hasil wajib 1B.1

- Payment domain dengan skema data additive dan state machine eksplisit.
- Kontrak API untuk payment methods/intent/instructions/status yang tidak pernah mengandalkan nilai client untuk nominal.
- Provider abstraction secukupnya sesuai real DOKU adapter yang akan dibutuhkan; tidak membuat banyak interface/driver spekulatif.
- Sandbox gate default OFF, no secrets, no network calls bila DOKU belum diaktifkan.
- Existing `GET /bookings/{booking}/payment-preview` dipertahankan **read-only**, owner-only, disabled 404 default. `preview_only` tidak pernah mengubah ledger, booking, atau QR.
- **Output 1B.1 dapat dinyatakan DONE walaupun DOKU credentials belum tersedia**, dengan tes non-payment and contract, tetapi bukan bukti pembayaran sukses.

### 4.2 Usulan database — additif & minimal

**Semua GUID FK memakai `*_guid` dan bukan `*_id`.** Gunakan `unsignedBigInteger` rupiah/integer minor-unit sesuai currency; jangan gunakan float/double untuk uang. Schema final perlu disesuaikan constraint real database.

| Tabel | Kolom pokok dan constraint |
|---|---|
| `payment_intents` | `guid` UUID PK; `booking_guid` FK unique untuk 1 intent logis per booking; `provider`, `environment`, `currency`, `amount`, `status`, `merchant_reference` unique by env/provider; `expires_at`, `verified_paid_at` nullable; timestamps |
| `payment_attempts` | `guid` UUID PK; `payment_intent_guid` FK; `attempt_number`, method/channel, provider request/reference, status, presentation type, instruction expiry, started/finished time; unique `(payment_intent_guid, attempt_number)` and unique provider reference scoped env when nonnull |
| `payment_events` | `guid` UUID PK; nullable `payment_intent_guid`, `payment_attempt_guid`, `provider`, `environment`, `provider_event_key`, verification/disposition/result, masked or encrypted payload metadata, received/provider timestamps, unique `(provider,environment,provider_event_key)` when reliable; immutable/auditable |
| `payment_reconciliation_records` | Minimal append-only discrepancy / status inquiry evidence for 1B.2; entity not necessary until functionality built |

**Design caution:** Unique `payment_intents.booking_guid` dipakai jika konsep satu intent per booking dipilih. Banyak upaya/channel ditempatkan di `payment_attempts` tanpa menambah intent ganda. Jika provider merchant reference perlu unik per attempt, simpan di attempts, bukan mengandalkan satu reference untuk semua upaya. Perhatikan batas panjang nomor invoice DOKU per integrasi/channel (Checkout dokumentasi menyebut batas berbeda). Jangan paksa `booking_number` sebagai DOKU invoice jika formatnya tidak memenuhi constraint.

**Data integrity:** FK terindeks, unique key idempotent, UTC timestamp, encrypted/redacted provider data, request hash, environment immutable per payment record. Semua migration baru bersifat additive; tidak edit migration Phase 0/1A.

### 4.3 Payment state machine — dua sumber status berbeda

- Intent: `requires_payment`, `processing`, `paid`, `expired`, `needs_review`, `cancelled` (boleh penyesuaian nama dengan dokumentasi konsisten).
- Attempt: `initiating`, `pending`, `failed`, `expired`, `unknown`, `paid`, `reversed`.
- Booking: **tetap** menggunakan status existing; jangan menambahkan `paid` sebagai booking status tanpa alasan migrasi kompatibilitas.
- `PaymentEligibility` membaca verified authoritative intent/attempt hasil 1B.2; sampai provider siap tetap `unverified`.
- Satu booking dapat memiliki >1 attempt, tetapi satu applied success dan satu financial recognition. Booking bisa `payment_review` sementara provider ledger paid: tidak boleh QR eligible.

### 4.4 API owner (usulan, tetap `/api/v1`)

| Method | URI | Kondisi | Output |
|---|---|---|---|
| GET | `/bookings/{booking}/payment-preview` (existing) | Owner, flag | Metadata preview-only, `can_pay=false` jika DOKU belum siap |
| GET | `/payments/methods?booking_guid=...` | Owner, booking eligible | List method capabilities `available/unavailable`, `presentation_type`, `reason`; jangan hardcode unactivated channel |
| POST | `/bookings/{booking}/payment-intent` | Owner, requires Idempotency-Key; Sandbox gate for live provider | Intent/attempt state + instructions/redirect metadata apabila berhasil membuat provider request |
| GET | `/bookings/{booking}/payment-intent` | Owner | Authoritative current payment status, last attempt, expiry, instructions if valid and safe |
| POST | `/bookings/{booking}/payment-attempts` | Owner, Idempotency-Key; eligible retry | Create next attempt hanya jika retry benar-benar aman |
| GET | `/admin/dashboard/financial` | Role 1 assigned / role 2 global, 1B.3 | Verifiable financial summary with basis and availability |

**Usulan bukan route existing.** Final path, JSON and HTTP semantics harus konsisten dengan API existing dan didokumentasikan. Tidak perlu membuat endpoint POST yang mengubah status sebelum DOKU integration gate tersedia. `GET /payments/methods` bisa melaporkan availability=false selama 1B.1.

### 4.5 Contoh kontrak — ilustratif, BUKAN respons real DOKU

```json
{
  "data": {
    "booking_guid": "uuid-booking",
    "intent_guid": null,
    "environment": "sandbox",
    "availability": "preview_only",
    "can_pay": false,
    "payment_status": "unverified",
    "expires_at": "2026-11-05T08:00:00Z",
    "methods": [
      {"code": "qris", "available": false, "reason": "doku_not_configured", "presentation_type": "qr_payload"},
      {"code": "virtual_account", "available": false, "reason": "doku_not_configured", "presentation_type": "account_number"},
      {"code": "ewallet", "available": false, "reason": "doku_not_configured", "presentation_type": "redirect"}
    ]
  },
  "meta": {"generated_at": "2026-11-05T07:00:00Z"}
}
```

**Tidak boleh:** membuat QRIS payload/nomor VA/redirect palsu atau mengembalikan `can_pay=true` dari preview-only.

---

## 5. Laravel 1B.2 — DOKU Sandbox & Authoritative Payment

### 5.1 Gate sebelum panggilan keluar

- Merchant **Sandbox** account & actual credentials sudah tersedia, channel dibuka/diizinkan, callback URL bisa menerima notifikasi DOKU di host Sandbox, secret disimpan via `.env`/secret manager backend, tidak masuk Git/Flutter/log.
- Pilih secara eksplisit **satu mode primary**: **Direct API per channel** bila native channel-specific instructions benar-benar didukung merchant; **DOKU Checkout hosted URL** bila channel direct belum memungkinkan. Kedua mode **bukan** satu API yang sama. Jangan berpura-pura Checkout URL adalah QRIS payload atau VA number.
- Periksa dokumentasi resmi & contoh API/sandbox aktual untuk request signature yang dipakai. **Non-SNAP Checkout** memakai `Client-Id`, `Request-Id`, `Request-Timestamp`, `Signature`, endpoint `POST https://api-sandbox.doku.com/checkout/v1/payment`; **SNAP Direct** punya B2B/B2C token dan signature flow berbeda. Jangan menyalin signing scheme antar-integrasi.
- Ketersediaan e-wallet DANA/OVO/ShopeePay, VA bank dan QRIS dikonfirmasi melalui channel merchant, bukan daftar statis umum; response method capabilities mengikuti channel yang benar-benar enabled.
- Bila syarat belum ada, jangan mengklaim live gateway integration DONE; teruskan offline implementation/test fixtures yang aman, catat blocker secara tepat.

### 5.2 Alur authoritative payment

```
Booking owner -> GET methods/status (owner scoped)
             -> POST intent/attempt (Idempotency-Key; booking awaiting_payment)
Laravel validates current booking amount/currency/expiry/status
             -> local intent+attempt initiated/locked (commit)
             -> DOKU Sandbox request OUTSIDE transaction (unique provider request reference)
             -> persist provider instruction/unknown result (short transaction)
User completes gateway flow (NOT an authority for paid)
             -> DOKU notification signed OR approved status inquiry
             -> authenticity + env + merchant + amount/currency/reference checks
             -> dedupe durable provider event
             -> destination/quota/booking/payment lock in documented order
             -> verified paid ledger + booking confirmed OR safe payment_review
             -> booking status history + outbox/notifications after commit
             -> PaymentEligibility returns paid only for correctly applied verified outcome
             -> ticket issue scheduler or safe eligible issuance (H-5)
             -> financial SQL projection
```

### 5.3 Security & reliability requirements

1. **Notification endpoint public but authenticated cryptographically**, never depends on visitor JWT. Validate exact raw body signature, identity, scheme-specific headers and timestamp/replay as docs require. Do not trust IP allowlisting alone or return-URL proof.
2. Verify provider reference maps to exactly one local attempt, merchant ID, environment, amount, currency, and signed status; unknown reference/event stored sanitized as discrepancy, never applied.
3. **Event dedupe durable**: unique provider event/request identifier or stable derived event fingerprint only if provider lacks ID; duplicate should ACK safely without duplicated effects; same ID/different payload flagged.
4. **Network isolation:** DOKU calls outside MySQL transaction; timeout => unknown/reconcile, no duplicate new payment. HTTP client timeouts, retry budget/backoff, webhook fast ACK; use queue for slow inquiry and email/FCM dispatch after commit.
5. **Idempotency:** client key actor+operation+payload; provider request IDs persisted across retries; no new attempt when unknown outcome unresolved. Prevent double application to booking and double recognized revenue using database constraints.
6. **Money & references:** exact integer amount and currency comparison, immutable booking snapshot, no float, no implicit conversion. DOKU invoice length/content must satisfy chosen integration.
7. **Expiry:** provider due date <= booking payment_expires_at, not client-provided; grace period is for evidence, not spending extension; late success handled via §3.1.
8. **Webhook order:** failed after paid cannot demote paid; duplicate paid no-op; reversal after confirmed triggers explicit secured adjustment/revocation, not delete audit. No arbitrary state rewind.
9. **Sensitive data:** encrypt provider secrets/payload segments where needed, redact PII/account numbers in logs, never log raw signature/card details/tokens; enforce TLS and outbound hostname allowlist.
10. **Never assume provider response is success** from HTTP 200 alone; use provider-specific documented response status and subsequent final verification.

### 5.4 Reconciliation dasar dalam 1B.2

- For `unknown`, `pending` near deadline, late notification, mismatch, reversal, and unsuccessful inquiry: record reason, next retry time, provider reference, last verified status, attempt count.
- Use DOKU documented status/inquiry endpoint **for the actual selected integration**, with conservative intervals (DOKU Non-SNAP docs say status inquiry after ~60 seconds from payment completion); honor provider throttling.
- Persist `inquiry` evidence; no fabricated finalized status; admin-visible anomaly can be surfaced as a genuine action center fact **only after durable source exists**.
- Reconcile safely after app crashes or worker restarts; no runaway retries. Real refund execution/settlement is **not** required in this phase.

### 5.5 Terminal / conflict responses

Reuse existing error envelope (`message`, `code`, optional `context`) and status conventions:

| Case | Expected |
|---|---|
| No JWT | 401 |
| Role not owner / booking foreign | 404 to prevent enumeration |
| Booking pending approval, cancelled, expired, wrong currency/method | 409/422 with stable code |
| Same client idempotency key same payload | deterministic replay/current authoritative state |
| Same key different payload | 409 `idempotency_conflict` |
| Provider request unknown | response `unknown/pending` with reconciliation metadata, **never success** |
| Duplicate verified notification | safe 2xx ACK, no second transition/event effect |
| Invalid signature | 401/403 (or documented reject response), no DB paid mutation |
| Amount / merchant / environment mismatch | audited exception, no confirm |
| Payment after expiry | recorded verified exception, no auto QR/confirmed |
| Gateway disabled | stable unavailable response, no outgoing call |

### 5.6 Payment status integration with existing booking

- Write one domain service coordinating verification, booking transition, history, notification, and eligibility under DB locks; don't call `BookingService::decide()` (it is for Admin approval only).
- `payment_verification` and `payment_review` already exist. Define exact accepted transitory states and late-event policy in tests. Never introduce a separate “paid booking status” without compatibility plan.
- After verified valid payment, `BookingResource.payment_status` becomes `paid`, booking becomes `confirmed`; `tickets:issue-qr` still enforces date and time. `BookingDocumentService` may generate confirmed booking document (not proof of charge).
- For reversal, refund or disputed payment: payment status must no longer satisfy QR `paid`; handle already checked-in tickets as audited exception rather than rewriting history.

---

## 6. Laravel 1B.3 — Verified Financial Analytics & Reporting APIs

**Tujuan:** mengisi financial fields yang sekarang `null/unavailable` di `OperationalDashboardService` tanpa merusak existing structure, dan menambah endpoint drill-down finance bila dibutuhkan.

### 6.1 Wajib tersedia

| KPI | Aturan sumber dan anti-double-count |
|---|---|
| Verified paid transactions | COUNT DISTINCT payment intent/booking yang verified dan applied sesuai definisi; attempts terpisah |
| Successful payment attempts | Count distinct successful attempts; jangan disamakan dengan unique bookings |
| Payment attempts, failure, expiry | Count attempt rows per verified environment/method; unknown separate |
| Payment conversion | Dua penyebut eksplisit: successful attempts / completed attempts; paid bookings / payment-eligible bookings. Jangan satu angka ambiguous |
| Gross collected | SUM verified successful payment captures per provider/environment; distinguish from recognized booking revenue if late/unapplied |
| Recognized gross booking revenue | SUM applicable verified paid amount untuk booking confirmed/fulfilled; exclude failed/unknown/unapplied duplicate |
| Confirmed refunds | Kurangi hanya kalau receipt refund gateway verified; refund workflow belum ada |
| Gateway fees | `null/unavailable` jika provider tidak memberikan data tepercaya; jangan estimasi tanpa label |
| Net revenue | `gross - confirmed_refunds - verified_fees` only when components authoritative; otherwise `null/partial` dengan missing fields |
| Tickets sold | SUM booking quantity hanya untuk paid + confirmed/valid cohort; tidak dari count attempts atau issued QR semata |
| Method mix | Group by provider method/channel verified; reference `attempt_guid` yang accepted |
| Payment timing | paid_at minus intent/attempt start, only if time verified |
| Unapplied/exception payment | Verified collected but booking not confirmed; tampil terpisah, bukan revenue sukses |

### 6.2 Scope, period, dan access

- Role 0 tidak dapat finance admin APIs.
- Active role 1: hanya destinasi currently assigned. Finance aggregate **per destinasi** ditampilkan untuk team, **bukan mengklaim pembayaran sebagai “pendapatan pribadi Admin”**. Personal approval performance tetap terpisah.
- Active role 2: global + filter destination + inspect anomalies yang terotorisasi. Jangan mengekspos PII / bank detail di aggregate response.
- Unique per booking / per payment event agar many-to-many assignment tidak menggandakan revenue.
- `date_basis=paid_utc` untuk payment revenue, `booking_created_utc` untuk booking funnel, `visit_destination_local` untuk attendance; permintaan from/to seperti Phase 1A (inclusive, <=366 hari), timezone metadata jelas.
- Sandbox/Demo/Production **harus dipisah pada data dan query**. Untuk project ini production metrics default `unavailable` jika tidak ada transaksi production; Sandbox monetary totals ditandai `test_only`, tidak dipasarkan sebagai pendapatan nyata.
- Filter `destination_guid` harus diotorisasi backend. Jika cohort kosong, rate nullable jika denominator nol; sums 0 hanya bila sumber data benar-benar ready dan tidak ada transaksi. Metrik yang belum dapat dibuktikan tetap `null`.

### 6.3 Kontrak dashboard compatible

Pertahankan `GET /admin/dashboard/overview`, `/approvals`, `/destinations`, `/visitors`, `/action-center`, `/admin/approval-queue` existing. Jangan hilangkan field `financial` atau mengubah `metrics` operasional. Tambah finance projection via service dedicated untuk menghindari query besar dan breaking changes.

**Contoh JSON ilustratif**, bukan data pembayaran aktual:

```json
{
  "data": {
    "financial": {
      "availability": "partial",
      "environment": "sandbox",
      "test_only": true,
      "currency": "IDR",
      "gross_revenue": 250000,
      "gross_collected": 250000,
      "net_revenue": null,
      "gateway_fees": null,
      "refunds": 0,
      "successful_transactions": 2,
      "payment_attempts": 3,
      "tickets_sold": 3,
      "date_basis": "paid_utc",
      "missing_fields": ["gateway_fees"]
    }
  },
  "meta": {"generated_at": "2026-11-05T10:00:00Z", "source": "verified_sandbox_events"}
}
```

Jika belum ada verified events, pertahankan `availability=unavailable` dan `gross_revenue/net_revenue/gateway_fees/refunds/settlement=null`; jangan ubah ke nol. Jika provider tidak punya fee/settlement, jangan buat kolom yang memberi kesan data tersedia. **Settlement masih Phase 3**.

### 6.4 Presisi dan efisiensi

- Query aggregation di SQL berdasarkan snapshot/payment ledger; hindari join langsung `bookings × attempts × events × assignments` yang melipatgandakan jumlah.
- Gunakan subquery distinct / accepted attempt grouping, indeks paid time + destination + environment; pagination for detail, bounded query, performance budgets yang diukur nyata.
- Caching boleh hanya setelah query benar dan invalidation jelas; jangan memperkenalkan engine analytics kedua.

---

## 7. API contract minimum dan non-breaking guarantees

- Prefix `/api/v1`, JWT owner for payment-owner endpoints, role-scope Laravel for finance; webhook provider-auth tanpa JWT.
- `data` JSON; `meta.generated_at`, `availability`, environment, method `presentation_type` and status; `null` semantik dipertahankan.
- `Idempotency-Key` required for payment-initiate/retry commands; GET safe; no amount/price client-provided.
- Payment instructions carry **type discriminator** (`checkout_url`, `qr_payload`, `virtual_account`, `ewallet_redirect`, `unavailable`) with only documented supported fields; URLs must be provider-validated.
- Avoid exposing raw provider credentials, provider webhook payload, phone/email in finance report.
- Acceptable statuses/errors and code mapping documented as machine-readable examples. Existing BookingResource optional fields additive only.
- Use Laravel queued side effects after commit; repeated GET does not trigger mutation or new gateway requests.

### 7.1 Payment instruction example

```json
{
  "data": {
    "booking_guid": "uuid-booking",
    "intent_guid": "uuid-intent",
    "attempt_guid": "uuid-attempt",
    "environment": "sandbox",
    "status": "pending",
    "payment_status": "unverified",
    "amount": 150000,
    "currency": "IDR",
    "expires_at": "2026-11-05T11:00:00Z",
    "instruction": {
      "presentation_type": "checkout_url",
      "checkout_url": "https://example.invalid/illustrative-only"
    }
  }
}
```

**Wajib:** contoh URL adalah placeholder dokumentasi, tidak boleh dipakai runtime. Pada Direct API instruksi berbeda menurut channel; jangan mengubah tipe hasil Direct menjadi bentuk Checkout URL secara tidak benar.

---

## 8. Regression verification plan — *wajib* untuk implementasi Laravel Phase 1B

### 8.1 Payment domain / API tests

- [ ] Owner role 0 bisa GET status sendiri; foreign booking 404; admin tidak dapat menginitiate payment untuk orang lain.
- [ ] Booking `pending_approval`/`rejected`/`cancelled`/`expired`/gratis tidak dibuatkan paid attempt secara ilegal.
- [ ] DOKU disabled + payment preview default-off => **zero provider HTTP calls, zero mutating paid outcome, zero QR**.
- [ ] Nominal dan currency berasal dari booking snapshot; manipulasi nilai body ditolak/diabaikan aman.
- [ ] Same idempotency key/payload replays; same key different payload 409; unknown provider outcome tidak memulai attempt baru tanpa resolve.
- [ ] Provider invoice/reference unik, multi-attempt tidak double paid; orphan/unknown events tertahan.

### 8.2 Signed webhook / reconciliation tests

- [ ] Valid Sandbox signature, merchant, currency, amount, reference + final success => one verified applied result.
- [ ] Invalid signature/tampered body, wrong merchant/currency/env/amount/reference => no booking confirm/QR.
- [ ] Duplicate, reordered, contradictory paid→failed, late callback, missing provider paid_at => deterministic outcomes.
- [ ] Provider timeout/HTTP 5xx/inquiry unknown => queued reconciliation, not `paid`.
- [ ] Payment at/after cutoff vs grace vs after grace + scheduler race => safe status, review path and no capacity leak.
- [ ] Recovery after worker interruption/outbox retry does not duplicate user notifications or revenue.

### 8.3 Booking & QR tests

- [ ] Existing `BookingService` approval 24h + claim policy preserved; payment expiry 1h/grace 15m preserved.
- [ ] `BookingRules::HELD` count unaffected by attempts; no oversell on webhook/expiry.
- [ ] QR issued only when paid+confirmed+H-5 (or late approval after H-5); on visit day check-in within destination timezone only.
- [ ] Reversed/refunded/chargeback verification fails QR guard; no reissue after revocation; checked-in history immutable.
- [ ] Invoice still not falsely treated as payment receipt.

### 8.4 Financial/reporting tests

- [ ] Verified Sandbox financial report correctly labeled test data; production unavailable remains unavailable.
- [ ] Distinguish `gross_collected`, booked recognized gross, refund, fees, net; unknown fees lead to null net.
- [ ] No duplicate revenue due to retries, webhook duplicate, attempts, admin many-to-many assignment.
- [ ] Role 1 assigned-only, role 2 global; revoked assignment loses current scope, historical actor data policy unchanged.
- [ ] paid_at/date-basis, timezone, period, pagination, filters, zero denominator and null semantics.
- [ ] Existing Admin dashboard API schema/queue and Flutter Phase 1A fields unchanged.

### 8.5 Test execution boundaries

Run **targeted Pest** including new `Payment*Test` and impacted existing tests. MySQL concurrency in **isolated disposable test database** for webhook vs expire, duplicate events, multi-attempt race. Run `vendor/bin/pint --dirty --format agent`, `git diff --check`, route inspection. No database kerja `migrate:fresh`, no destructive seed, no full Flutter tests; no production credentials in test. Never assert E2E PASS if sandbox not actually used.

---

## 9. Delivery sequence within the ONLY Phase 1B

### Laravel 1B.1 — ready to run without DOKU

- [ ] Local baseline + `git status`, read AGENTS/rules, trace current flows.
- [ ] Migration additive/payment domain & constraints; state machine + exact money types.
- [ ] API methods/status/intent contract + immutable owner-scoped preview.
- [ ] Provider adapter seam and explicit disabled Sandbox capability.
- [ ] Idempotency and no-paid-no-QR focused tests.
- [ ] Notes to Flutter: API contract only (no Flutter edits).

### Laravel 1B.2 — requires actual DOKU Sandbox merchant capability

- [ ] Validate available merchant channels & choose Checkout vs Direct with evidence.
- [ ] Server-side credential handling, request signature/token scheme, timeout policy.
- [ ] Actual Sandbox payment initiation, valid provider instruction.
- [ ] Signed webhook processing/event ledger/idempotency/verified inquiry.
- [ ] Booking+QR domain transition, late events, expiry races, reconciliation.
- [ ] Real Sandbox test evidence, or **BLOCKED** with exact missing credentials/channels/callback host.

### Laravel 1B.3 — verified data dependent

- [ ] Financial SQL projections with environmental isolation and RBAC.
- [ ] Payment methods, attempts vs success, conversion, gross, refunds, fee/net availability semantics.
- [ ] Existing dashboard financial compatibility + optional financial drill-down API.
- [ ] Targeted financial tests (fixtures labeled) and, if available, Sandbox verified-event cross-check.
- [ ] In handoff clearly separate **code implementation**, **fixture test pass**, and **actual DOKU Sandbox integration verified**.

**Do not introduce 1A Hardening, 1B.4, 1C, any new milestones, or revisit finished Phase 0/1A.** Necessary fixes discovered during work belong to relevant Phase 1B item and are documented in its regression log.

---

## 10. Constraints, agent execution, and handoff

### 10.1 Agent orchestration (Codex LOCAL ONLY)

- Read `C:\Users\itsba\.codex\Agents.md` and inspect actual TOML entries in `C:\Users\itsba\.codex\agents\`; do **not** assume agent filenames exist merely because PRD suggests a role.
- **Max three subagents total**: choose actual available agents matching (1) Laravel backend engineer/payment integration, (2) security reviewer for webhook/RBAC/concurrency, (3) database/architecture reviewer for ledger/financial math. Main Codex session orchestrates; do not spawn an additional orchestrator subagent. If only 1–2 exact matches exist, use 1–2. No agents outside that limit.
- Read project `AGENTS.md`, `.ai/rules/index.md` if directory exists, and relevant installed skills including Laravel/testing; follow repo conventions. Use existing dependencies where possible; request approval before adding composer packages.
- Distribute read-only reviews and non-overlapping work; avoid simultaneous edits to BookingService or migrations from two agents.

### 10.2 Mandatory final handoff

Create `docs/phase-1b-handoff.md` only as explicitly requested documentation for this PRD implementation; include:

1. Local HEAD before/after, git status, changed files; **no auto commit/push**.
2. Completed 1B.1/1B.2/1B.3 checklists; actual blocked items with reasons.
3. Chosen DOKU mode, activated channel capabilities, official docs/version/date, no credentials.
4. Migration names/schema/indexes, safe deploy/rollback, no destructive change to working DB.
5. API routes + request/response examples + error handling; backward-compatibility audit.
6. Payment state transitions and lock order diagram; idempotency/late-callback behavior.
7. Verification evidence: real commands and passing counts, MySQL isolated race, external DOKU Sandbox test IDs **redacted**; do not claim PASS without running.
8. Financial definitions, Sandbox/Demo/Production isolation, unavailable/null semantics.
9. Known risks, actual Flutter integration contract handoff, and dependency on genuine DOKU credentials.

### 10.3 Go / No-Go controls

- **1B.1 gate:** architecture + ledger + API/preview + tests safe, DOKU disabled.
- **1B.2 gate:** signed verification plus real Sandbox (not fixture) and verified booking/QR lifecycle. Without access, mark blocked rather than bypass.
- **1B.3 gate:** accounting query correctness tests and source-availability semantics. Do not call fully verified if no real gateway event source.
- **Flutter gate:** **only after backend Phase 1B** implementation/handoff decision. Do not edit Flutter now.

---

## 11. Official DOKU references and repository evidence

These URLs are **external documentation for implementation verification**, not secrets and not evidence that the merchant account has a channel enabled. Check latest docs before coding exact signing/payload.

1. DOKU Checkout backend: https://developers.doku.com/accept-payments/doku-checkout/integration-guide/backend-integration
2. DOKU Direct API (overview): https://developers.doku.com/accept-payments/direct-api
3. DOKU SNAP QRIS: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/qris
4. DOKU SNAP E-Wallet: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/e-wallet
5. DOKU HTTP Notifications: https://developers.doku.com/get-started-with-doku-api/notification
6. DOKU Non-SNAP Check Status API: https://developers.doku.com/get-started-with-doku-api/check-status-api/non-snap
7. Laravel current main: https://github.com/joytimess/pami-wisata-daerahku-api/tree/c43a5d18183a660ccf45830c2c51e6e2698ff322
8. Laravel 1A handoff: https://github.com/joytimess/pami-wisata-daerahku-api/blob/main/docs/phase-0-1a-handoff.md

**Final invariant:** Laravel Phase 1B.1 → Laravel Phase 1B.2 → Laravel Phase 1B.3 → *baru* Flutter Phase 1B. DOKU verified evidence is the only route to real paid state. Tidak ada perubahan roadmap tanpa persetujuan pengguna.
