# PAMI Midtrans Core API Migration Implementation Plan

> **For agentic workers:** Setelah ada persetujuan implementasi eksplisit, gunakan Superpowers executing-plans atau subagent-driven-development untuk mengeksekusi task secara bertahap. Maksimal tiga subagent sesuai pembagian pengguna. Checklist dalam dokumen ini adalah rencana, bukan pekerjaan yang telah dijalankan.

**Status:** AUDIT SELESAI / IMPLEMENTATION PLAN ONLY — BELUM DIIMPLEMENTASIKAN  
**Tanggal snapshot audit:** 10 November 2026, Asia/Jakarta  
**Goal:** Midtrans menjadi satu-satunya payment gateway nyata untuk BCA VA, BNI VA, dan QRIS, dengan Flutter native serta booking, tiket, histori, dan DEMO tetap aman.  
**Architecture:** Gunakan kembali domain booking/payment/ticket yang ada. Pisahkan transport Midtrans dari penerapan settlement dan pembacaan ledger historis. Tidak memerlukan SDK Midtrans atau dependency baru.  
**Tech Stack:** Laravel 13 / PHP 8.3, MySQL, Pest, Flutter/Dart, Laravel HTTP client, queue dan scheduler existing.  
**Spec:** [PAMI-Midtrans-CoreAPI-Migration-Handoff-v1.0.md](../../prd/PAMI-Midtrans-CoreAPI-Migration-Handoff-v1.0.md).

**Backend:** https://github.com/joytimess/pami-wisata-daerahku-api  
**Flutter:** https://github.com/koyotot/pami-wisata-daearahku

Dokumen ini menyimpan hasil audit dan implementation plan yang telah disampaikan dalam percakapan. Pembuatan file Markdown ini diizinkan pengguna pada tindak lanjut; izin tersebut tidak mencakup implementasi, penghapusan file, migration, perubahan environment, transaksi, commit, push, atau deployment.

## Global Constraints

- Keputusan D01–D14 dan F-A/F-B/F-C pada spesifikasi final tetap terkunci.
- Core API standar /v2/charge; bukan Snap Checkout, WebView, atau BI-SNAP.
- Kanal rilis pertama hanya BCA VA, BNI VA, dan QRIS.
- Admin approve dahulu → awaiting_payment → verified settlement → confirmed.
- Satu deadline absolut 15 menit sejak approval; retry tidak memperpanjang deadline.
- Satu intent per booking; attempt hanya sekuensial; tidak ada dual active/payable attempts.
- Pending/unknown menahan kuota sampai bukti terminal atau settlement yang sah.
- Full refund only; VA manual, QRIS API hanya jika capability aktif.
- Tiket hanya untuk entitlement sah; verified full refund mencabut admission.
- DEMO terpisah dan hanya non-production; Rp0 tidak membuat Charge Midtrans.
- Tidak menghapus migration, ledger, audit trail, atau histori Git.
- Tidak menimpa provider historis menjadi midtrans.
- Tidak mengubah kebijakan lead time booking/approval, claim, atau H-5 QR.
- Tidak mengganti dependency tanpa persetujuan.
- Tidak melakukan implementasi sebelum instruksi eksplisit pengguna.
- Persetujuan implementasi tidak otomatis mengizinkan transaksi UAT, perubahan database nyata, Production, commit, push, atau deployment.

## Review Focus

Lima risiko berikut harus memiliki regression coverage pada fase pemiliknya:

1. Mengganti gateway aktif tidak boleh membatalkan bukti historis yang sah atau meloloskan Sandbox/DEMO ke admission Production — Phase 1.
2. Crash setelah provider menerima Charge tidak boleh menghasilkan order kedua — Phase 2.
3. Callback settlement yang datang sebelum response Charge tidak boleh didemote kembali ke pending — Phase 2–3.
4. Expiry dan settlement yang berlomba tidak boleh melepas kuota sekaligus menerbitkan tiket — Phase 4.
5. Refund yang sedang dikirim tidak boleh berlomba dengan check-in atau manual refund kedua — Phase 4.

---

## 1. Hasil audit repository

### 1.1 Git, branch, dan working tree

Remote refs diperiksa menggunakan git ls-remote, tanpa fetch, checkout, merge, atau perubahan ref.

| Repository/ref | HEAD terverifikasi | Hasil |
|---|---|---|
| Backend main / origin/main / remote main | bbf0a05ab34f42524b3d57c18485121c57174f69 | Sesuai snapshot spesifikasi |
| Backend codex/doku-task0-2-20261109 | 41eb50768c074f8ae1b2c1ea898da440e1b20e13 | Ancestor main; tertinggal 3 commit; tidak ada commit eksklusif |
| Backend feat/doku-multichannel-v2 | 69850798c9402797f58d7158935991e11bab05d1 | Ancestor main; tertinggal 11 commit; tidak ada commit eksklusif |
| Flutter main / origin/main / remote main | 7b2a8ff994581f7bdded24e8518eddb6ed10a9f3 | Sesuai snapshot; tidak ditemukan branch remote lain |

Tidak diperlukan cherry-pick dari branch DOKU lama: perubahan relevannya sudah terdapat pada backend main.

Lokasi yang diperiksa:

~~~text
Backend utama:
C:\laragon\www\pami-wisata-daerahku-api

Backend worktree lama:
C:\Users\itsba\.codex\worktrees\8224\pami-wisata-daerahku-api

Flutter:
C:\laragon\www\pami-mobile\pami-wisata-daearahku
~~~

Working tree pada audit awal dan akhir:

- Backend: .env.example dimodifikasi pengguna, dengan lima baris tambahan konfigurasi awal Midtrans; spesifikasi final masih untracked.
- Backend worktree lama: bersih.
- Flutter: bersih.
- Root doku_qris_probe.php ada tetapi ignored oleh Git. File ini tetap masuk sasaran cleanup dan tidak boleh dijalankan.

Perubahan pengguna harus dipertahankan saat implementasi. Status di atas adalah snapshot audit, bukan jaminan bahwa checkout tidak berubah setelah dokumen dibuat.

### 1.2 Dependency dan peta arsitektur

| Komponen | Versi terverifikasi |
|---|---|
| PHP CLI | 8.3.36 |
| Laravel terpasang | 13.39.0 |
| Pest / Laravel plugin | 4.7.8 / 4.1.0 |
| Pint | 1.32.1 |
| bacon/bacon-qr-code | 3.1.1 |
| barryvdh/laravel-dompdf | 3.1.2 |
| Flutter / Dart dari cache SDK | 3.44.4 / 3.12.2 |
| Flutter http | 1.6.0 |
| flutter_svg | 2.3.2 |
| mobile_scanner | 7.5.0 |
| url_launcher | 6.3.2 |
| phosphor_icons | 3.0.1 |

Flutter lock efektif membutuhkan Dart >=3.12.0 dan Flutter >=3.44.0, meskipun constraint nominal pubspec lebih longgar.

Backend menggunakan routes → FormRequest/controller → services → database. Queue dan scheduler menangani recovery serta publikasi dokumen/notifikasi. PaymentEligibility digunakan lintas booking, ticket, check-in, visit, refund, dan DEMO.

Graphify backend dibangun pada 41eb507 dan berisi 2.390 nodes / 6.193 edges, sehingga lebih lama daripada HEAD. Graph hanya digunakan sebagai petunjuk navigasi dan diverifikasi ke source. Tidak direbuild atau diupdate karena audit read-only. Tidak ada biaya/token rebuild graph pada audit ini.

.ai/rules tidak ditemukan pada checkout yang diperiksa. Audit menggunakan tiga subagent: architecture/dependency inventory; Laravel/database/security/QA; Flutter/native/contracts/tests. Skill Superpowers dan skill domain yang relevan digunakan.

### 1.3 Konflik implementasi dengan spesifikasi final

| Area | Evidence aktual | Perubahan wajib |
|---|---|---|
| Approval berbayar | app/Services/BookingService.php:264 memilih awaiting_payment; gratis confirmed | Pertahankan urutan approval serta entitlement gratis |
| Deadline approval | app/Services/BookingService.php:267–268 memberi +1 jam dan grace +15 menit | Approval +900 detik |
| Deadline attempt | app/Services/PaymentService.php:171–175 menghitung attempt +30 menit | Semua attempt memakai deadline booking yang sama |
| Expiry booking | app/Services/BookingService.php:282–320 berhenti di payment_verification → payment_review | Tambahkan terminal-unpaid closure dan pelepasan kuota atomik |
| Retry metode | app/Services/PaymentService.php menetapkan can_switch=false | Izinkan hanya setelah prior attempt terbukti tidak payable |
| Reservation | app/Services/DokuPaymentService.php:35–140 sudah memisahkan commit reservation dari network | Gunakan kembali pola aman, termasuk recovery crash |
| Kelayakan historis | app/Services/PaymentEligibility.php:22–70,110+ mengikat DOKU, active environment dan doku_snap | Generic strict ledger read tanpa kehilangan bukti historis |
| Receipt/finance | app/Services/PaymentReceiptService.php:27; FinancialAnalyticsService.php:23,103 memfilter DOKU | Pertahankan sumber historis dan transaksi Midtrans per environment |
| Settlement | DokuPaymentService.php:384–426 sudah memiliki snapshot/time checks dan side effects | Extract menjadi provider-neutral, lengkapi Midtrans binding |
| Refund | BookingRefundService.php:63 DOKU-only; keputusan selalu manual | Full-only; VA manual, QRIS API bersyarat |
| Refund/check-in | PaymentEligibility::entitlement hanya memblokir refunded/manual_refunded | Admission hold sebelum dispatch API |
| Reconciliation | Hanya open payment attempts | Inbox recovery dan refund pada paid attempts juga harus diproses |
| Flutter provider guard | payment_models.dart:52,139; booking_payment_state.dart:168,206; booking_payment_section.dart:33,133 | Hapus ketergantungan isDoku |
| Flutter channel | payment_models.dart:208 hardcoded BNI+QRIS; screen:456 instruksi BNI | BCA/BNI/QRIS dari capability backend |
| Flutter cutoff | Screen:300 memakai attempt expiry; widget memakai device clock | Deadline booking dengan server clock offset |
| QR pembayaran | State:718 hanya menerima SVG; export merasterisasi SVG | PNG autentik melalui endpoint Laravel |
| Finance Flutter | operations_financial.dart:117 menolak provider selain doku | Historical/mixed ledger contract |
| Copy runtime | operations_presentation.dart:391; operations_financial.dart:444; refund_models.dart:89 | Provider-neutral dan tidak menyatakan refund selesai prematur |
| Native save | Android bridge tersedia; exporter hanya mendukung Android | Reuse Android; iOS menjadi gate bila termasuk target |
| Recovery 422 | booking_payment_state.dart:532 menghapus operation key pada seluruh 422 | Hanya clear untuk penolakan definitif sebelum reservation |
| Flutter DEMO config | AppConfig.validate belum menolak production+DEMO flag | Tambahkan fail-closed check |

docs/DESIGN.md Flutter memiliki klaim arsitektur yang kedaluwarsa. Implementasi harus mengikuti source aktif, AppColors/AppSpacing/AppIcons, reusable components, dan approved screen, bukan menganggap inventaris lama masih berlaku.

## 2. Manifest perubahan file

Path berikut relatif terhadap root masing-masing repository. Manifest ini mengklasifikasikan file yang relevan untuk migrasi pada snapshot audit. Seluruh file lain dipertahankan.

DELETE hanya dilakukan setelah pengganti, replacement tests, dan administrative closure lulus gate. Daftar DELETE bukan izin penghapusan saat planning.

### 2.1 Laravel — DELETE

Implementasi DOKU dan return flow e-wallet:

~~~text
app/Services/Doku/DokuClient.php
app/Services/Doku/DokuChannelRegistry.php
app/Services/Doku/DokuDirectDebitAdapter.php
app/Services/Doku/DokuExchangeSanitizer.php
app/Services/Doku/DokuQrisAdapter.php
app/Services/DokuPaymentService.php
app/Http/Controllers/Api/V1/DokuWebhookController.php
app/Http/Controllers/PaymentReturnController.php
app/Http/Middleware/ObserveDokuWebhook.php
app/Http/Requests/DokuPaymentRequest.php
app/Exceptions/DokuRequestException.php
app/Jobs/ProcessDokuPaymentEvent.php
app/Jobs/ReconcileDokuPayment.php
app/Console/Commands/ReconcileDokuPayments.php
app/Console/Commands/ProbeDokuDanaCreate.php
config/doku.php
resources/views/payment-return.blade.php
doku_qris_probe.php
~~~

Tests dan fixture khusus provider, setelah coverage pengganti tersedia:

~~~text
tests/Feature/DokuBcaStatusTest.php
tests/Feature/DokuBcaWebhookLocalTest.php
tests/Feature/DokuChannelRegistryTest.php
tests/Feature/DokuClientTest.php
tests/Feature/DokuEwalletTest.php
tests/Feature/DokuPaymentTest.php
tests/Feature/DokuProbeCommandTest.php
tests/Feature/DokuQrisTest.php
tests/Feature/DokuVaBanksTest.php
tests/Feature/DokuWebhookObserverTest.php
tests/Mysql/DokuPaymentConcurrencyTest.php
tests/Mysql/doku-payment-worker.php
tests/Fixtures/doku/bca-status-success.json
~~~

Dokumen khusus integrasi DOKU:

~~~text
docs/prd/DOKU-CHANNEL-MATRIX.md
docs/prd/DOKU-EXPIRY-EVIDENCE.md
docs/prd/DOKU-implementation-plan.md
docs/prd/DOKU-MYSQL-TEST-ACCOUNT.md
docs/prd/DOKU-RUNBOOK.md
docs/prd/DOKU-SANDBOX-UAT-PLAN.md
docs/prd/PRD_DOKU_Direct_API_Wisata_Daerahku_v1.0.md
docs/prd/PRD_DOKU_Multichannel_Backend_v2.0.md
docs/prd/PRD_FIX_DOKU_DANA_PROVIDER_UNAVAILABLE_v1.0.md
docs/prd/PRD_Wisata_Daerahku_DOKU_Payment_Eticket_v3.0.md
docs/audits/2026-11-08-doku-payment-eticket-gap-analysis.md
docs/superpowers/plans/2026-11-08-doku-payment-eticket.md
~~~

Histori Git dan bukti transaksi/audit tidak ikut dihapus.

### 2.2 Laravel — MODIFY

Runtime, konfigurasi, dan dokumentasi aktif:

~~~text
app/Services/PaymentGateway.php
app/Services/PaymentService.php
app/Services/PaymentEligibility.php
app/Services/PaymentPreviewService.php
app/Services/BookingService.php
app/Services/BookingRefundService.php
app/Services/PaymentReceiptService.php
app/Services/FinancialAnalyticsService.php
app/Services/BookingNotificationService.php
app/Http/Controllers/Api/V1/PaymentController.php
app/Support/PaymentStates.php
app/Support/BookingRules.php
routes/api.php
routes/console.php
routes/web.php
bootstrap/app.php
config/operations.php
config/logging.php
config/mobile.php
.env.example
.gitignore
README.md
~~~

Tanggung jawab:

- PaymentGateway: capability tiga channel Midtrans serta DEMO terpisah.
- PaymentService: historical snapshot, deadline, retry dan provider-neutral instructions.
- PaymentEligibility: verified ledger binding, environment isolation, temporary refund hold.
- BookingService: approval +900 detik dan terminal expiry.
- BookingRules: shared deadline constant bila dibutuhkan; preserve HELD dan lead time.
- BookingRefundService: conditional QRIS dispatch/recovery dan manual VA.
- Receipt/analytics: histori tetap terbaca, dana terverifikasi tidak bercampur dengan biaya/refund yang belum diketahui.
- PaymentPreviewService: hilangkan hardcode DOKU/e-wallet walaupun preview disabled.
- Routes/bootstrap/logging: hapus DOKU handlers, exception matchers, schedule dan diagnostics.
- routes/web.php/config/mobile.php: hapus return-only hooks/comments; preserve ticket links/assetlinks.
- .env.example: integrasikan tambahan pengguna; jangan menimpanya.
- .gitignore: hapus entri probe usang setelah file terkait dihapus.

Tests/harness:

~~~text
phpunit.xml
phpunit.mysql.xml
tests/Mysql/DisposableMysqlGuard.php
tests/Mysql/PaymentFoundationConcurrencyTest.php
tests/Mysql/payment-foundation-worker.php
tests/Mysql/DemoPaymentConcurrencyTest.php
tests/Mysql/demo-payment-worker.php
tests/Feature/PaymentFoundationTest.php
tests/Feature/DemoPaymentTest.php
tests/Feature/AdminOperationsTest.php
tests/Feature/BookingServiceTest.php
tests/Feature/BookingApiTest.php
tests/Feature/BookingDocumentsTest.php
tests/Feature/BookingNotificationTest.php
tests/Feature/BookingRefundTest.php
tests/Feature/BusinessAdjustmentApiTest.php
tests/Feature/FinancialAnalyticsTest.php
tests/Feature/SmartTicketSmokeTest.php
tests/Feature/PaymentReturnFlowTest.php
~~~

PaymentReturnFlowTest.php tidak boleh dihapus seluruhnya: file tersebut juga menguji /.well-known/assetlinks.json untuk App Links tiket. Hapus hanya return-only coverage, pertahankan assetlinks coverage.

Perubahan test generic menyesuaikan fixture/deadline dan menambah assertion integrasi yang material; tidak menulis ulang suite yang masih sesuai.

### 2.3 Laravel — ADD

~~~text
config/midtrans.php
app/Services/Midtrans/MidtransClient.php
app/Services/Midtrans/MidtransChannelRegistry.php
app/Services/Midtrans/MidtransStatusMapper.php
app/Services/MidtransPaymentService.php
app/Services/PaymentSettlementService.php
app/Http/Controllers/Api/V1/MidtransWebhookController.php
app/Http/Requests/MidtransPaymentRequest.php
app/Exceptions/MidtransRequestException.php
app/Jobs/ProcessMidtransPaymentEvent.php
app/Jobs/ReconcileMidtransPayment.php
app/Jobs/ProcessMidtransRefund.php
app/Console/Commands/ReconcileMidtransPayments.php
database/migrations/2026_11_10_000001_add_midtrans_processing_and_refund_fields.php
tests/Feature/MidtransClientTest.php
tests/Feature/MidtransPaymentTest.php
tests/Feature/MidtransWebhookTest.php
tests/Feature/MidtransExpiryTest.php
tests/Feature/MidtransRefundTest.php
tests/Feature/MidtransReconciliationTest.php
tests/Feature/HistoricalPaymentCompatibilityTest.php
tests/Mysql/MidtransPaymentConcurrencyTest.php
tests/Mysql/midtrans-payment-worker.php
tests/Fixtures/midtrans/charge-bca-pending.json
tests/Fixtures/midtrans/charge-bni-pending.json
tests/Fixtures/midtrans/charge-qris-pending.json
tests/Fixtures/midtrans/notification-settlement.json
tests/Fixtures/midtrans/status-settlement.json
tests/Fixtures/midtrans/status-expired.json
tests/Fixtures/midtrans/status-refund.json
~~~

Timestamp migration disesuaikan saat implementasi jika perlu. Migration hanya menambah kebutuhan yang terbukti belum dipenuhi schema existing.

PaymentSettlementService mengambil penerapan settlement/reversal dari DokuPaymentService. Tidak ditambahkan generic gateway plugin framework atau abstraction berlapis untuk satu gateway nyata.

### 2.4 Laravel — PRESERVE

Seluruh migration historis, termasuk:

~~~text
database/migrations/2026_11_05_040716_create_payment_tables.php
database/migrations/2026_11_08_034113_add_reconciliation_to_payment_attempts.php
database/migrations/2026_11_08_184659_create_notification_delivery_attempts_table.php
database/migrations/2026_11_08_190053_create_booking_refund_requests_table.php
database/migrations/2026_11_09_030710_add_escalation_to_payment_attempts.php
~~~

Domain dan infrastruktur existing:

~~~text
app/Services/DemoPaymentService.php
app/Services/AdminDestinationScopeService.php
app/Services/ApprovalQueueService.php
app/Services/TicketService.php
app/Services/TicketCheckInService.php
app/Services/BookingDocumentService.php
app/Jobs/IssueBookingTicketQr.php
app/Jobs/GenerateBookingDocuments.php
app/Jobs/DeliverBookingNotification.php
app/Console/Commands/IssueEligibleTicketQr.php
app/Console/Commands/DispatchBookingDocuments.php
app/Console/Commands/DispatchBookingNotifications.php
app/Console/Commands/ExpireBookings.php
app/Http/Controllers/AppLinkController.php
app/Http/Controllers/TicketLinkController.php
resources/views/ticket-link.blade.php
~~~

Preserve juga:

- .env, APP_KEY, credentials, ciphertext dan private documents.
- Payment intents/attempts/events/refunds, provider/environment asli, invoice/receipt, audit, dan histori Git.
- Booking/approval/claim/RBAC/destination scope, lead time, free entitlement dan H-5 QR.
- composer.json, composer.lock, package.json, package-lock.json.
- Spesifikasi final Midtrans dan dokumen domain/handoff campuran.
- Seluruh file di luar manifest perubahan.

TicketService dan TicketCheckInService memakai eligibility bersama; jangan menulis ulang scanner/token/issuance. Perubahan source tambahan hanya jika ditemukan kebutuhan konkret saat implementasi, lalu perbarui manifest.

### 2.5 Flutter — DELETE

~~~text
lib/data/ewallet_payment_state.dart
lib/widgets/ewallet_payment_section.dart
lib/screens/payment_return_screen.dart
lib/data/payment_return_link.dart
test/ewallet_return_test.dart
~~~

File tersebut khusus flow e-wallet/redirect yang tidak masuk rilis ini.

### 2.6 Flutter — MODIFY

~~~text
lib/data/payment_models.dart
lib/data/booking_payment_state.dart
lib/data/business_repository.dart
lib/data/refund_models.dart
lib/screens/booking_payment_screen.dart
lib/widgets/booking_payment_section.dart
lib/widgets/payment_deadline.dart
lib/services/payment_qr_export.dart
lib/screens/operations_financial.dart
lib/screens/operations_presentation.dart
lib/core/config/app_config.dart
lib/main.dart
android/app/src/main/AndroidManifest.xml
android/app/build.gradle.kts
test/booking_payment_test.dart
test/booking_refund_test.dart
test/demo_payment_disabled_test.dart
test/business_booking_widgets_test.dart
test/operations_queue_finance_regression_test.dart
test/app_config_test.dart
README.md
~~~

Rincian:

- DTO: server-driven BCA/BNI/QRIS; hapus isDoku, redirect URL dan wallet channels.
- State: preserve ChangeNotifier, session generation, idempotency secure storage, coalescing, polling pause/backoff dan stale-session protection.
- PNG bytes menggantikan qrSvg/rasterisasi; clear bytes saat deadline, attempt atau session berubah.
- Deadline berdasarkan server time, bukan reset 15 menit setiap reopen.
- HTTP 422 hanya menghapus operation key jika penolakan definitif sebelum reservation; timeout/ambiguous outcome tidak boleh kehilangan key.
- Native screen: bank-specific heading/copy/semantics/instructions, authenticated PNG display/export.
- Booking section: hapus _dokuSeen/wallet branch; gunakan capabilities dan historic read-only mode.
- Main/manifest: hapus payment-return route/import/path saja; preserve ticket App Links.
- Gradle: host configuration tetap untuk tiket; perbarui komentar return-only.
- Finance: coordinated historical/mixed ledger parser; tetap validasi scope/environment/test_only/null.
- Refund: approved/pending tidak diklaim dana sudah kembali, bedakan API versus manual.
- AppConfig: production+DEMO flag gagal/ditolak; backend capability tetap wajib.

Jika iOS termasuk target rilis:

~~~text
ios/Runner/AppDelegate.swift
~~~

Tambahkan native document/share sheet melalui engine integration existing untuk menyimpan PNG ke Files tanpa dependency baru. Build/device iOS pada macOS diperlukan; jangan mengklaim iOS PASS dari Android.

### 2.7 Flutter — ADD

~~~text
test/midtrans_payment_live_test.dart
~~~

Reuse pola test/phase6_live_api_test.dart dengan flutter_test dan http yang sudah ada. Opt-in, fixture terisolasi, dan transaksi hanya setelah izin UAT eksplisit.

Tidak perlu screen baru, SDK Midtrans, WebView/Snap SDK, package state-management, bank-logo assets, rasterizer baru, atau integration_test dependency hanya untuk kontrak HTTP.

### 2.8 Flutter — PRESERVE

~~~text
lib/services/api_client.dart
lib/services/auth_session.dart
lib/data/booking_refund_state.dart
lib/widgets/booking_refund_section.dart
lib/data/demo_payment_state.dart
lib/screens/demo_checkout_screen.dart
lib/data/booking_intent.dart
lib/data/booking_state.dart
lib/data/ticket_models.dart
lib/data/ticket_scanner_state.dart
lib/data/ticket_link.dart
lib/widgets/booking_ticket_qr_section.dart
lib/screens/ticket_link_screen.dart
lib/screens/admin_ticket_scanner_screen.dart
lib/screens/bukti_pendaftaran_screen.dart
lib/screens/main_navigation_screen.dart
lib/screens/operations_navigation_shell.dart
lib/theme/app_colors.dart
lib/theme/app_icons.dart
lib/services/destination_directions.dart
android/app/src/main/kotlin/com/example/wisata_daerahku/MainActivity.kt
android/app/src/main/res/xml/payment_qr_paths.xml
ios/Runner/Info.plist
ios/Runner/SceneDelegate.swift
pubspec.yaml
pubspec.lock
test/ticket_contract_test.dart
test/smart_ticket_test.dart
test/admin_ticket_scanner_camera_test.dart
~~~

Preserve seluruh .env*, assets, approved wireframes dan dokumen referensi/handoff. File di luar manifest perubahan juga dipertahankan.

flutter_svg masih dipakai QR tiket masuk. url_launcher masih dipakai directions. Android save/share bridge menerima PNG dan dapat dipakai kembali. Contoh /payment/return sebagai invalid ticket path dalam parser test bukan active payment return flow.

## 3. Kontrak HTTP dan desain Midtrans client

### 3.1 Endpoint publik PAMI

Pertahankan URL, auth, OperationalResource.data envelope dan Idempotency-Key existing.

| Endpoint | Makna setelah migrasi |
|---|---|
| GET /api/v1/payments/methods?booking_guid=... | Capability BCA/BNI/QRIS beserta alasan unavailable |
| GET /api/v1/bookings/{booking}/payment-intent | Snapshot booking, ledger, deadline, instructions dan refund |
| POST /api/v1/bookings/{booking}/payment-attempts | Reserve attempt lalu Charge |
| POST /api/v1/bookings/{booking}/payment-attempts/{attempt}/refresh | Authenticated Status lookup, rate-limited |
| GET /api/v1/bookings/{booking}/payment-attempts/{attempt}/qr | PNG privat milik user |
| GET /api/v1/admin/bookings/{booking}/payment-intent | Audit sesuai destination scope |
| Existing refund endpoints | Request, scoped decision dan verifikasi manual |
| Existing ticket/document endpoints | Tetap server authoritative |
| POST /api/v1/webhooks/midtrans | Public durable notification inbox |
| Existing DEMO endpoints | Hanya non-production |

Request attempt:

~~~json
{"method_code":"virtual_account","channel":"bca"}
~~~

Pilihan lainnya virtual_account/bni dan qris/qris. Amount/currency/provider/environment/expiry/status/merchant tidak diterima sebagai input tepercaya Flutter.

Response additions:

- server_time UTC.
- expires_at = absolute booking deadline.
- last_attempt.expires_at tidak melewati booking deadline.
- can_pay, can_create, can_switch, switch_reason diputuskan backend.
- VA: presentation_type=virtual_account, bank_code, va_number.
- QRIS: presentation_type=qr_image, image_available, image_content_type=image/png.
- Tidak mengirim Server Key atau arbitrary provider image URL ke Flutter.

### 3.2 Midtrans HTTP client

Gunakan Laravel HTTP client:

- Sandbox: https://api.sandbox.midtrans.com.
- Production: https://api.midtrans.com.
- Basic Auth: Server Key username, password kosong.
- POST /v2/charge.
- GET /v2/{stored_order_id}/status.
- POST /v2/{stored_order_id}/expire bila diperlukan.
- POST /v2/{stored_order_id_or_transaction_id}/refund untuk QRIS eligible.
- Tidak ada automatic retry Charge.

BCA/BNI memakai payment_type=bank_transfer dengan bank_transfer.bank=bca|bni. QRIS memakai payment_type=qris dan acquirer yang aktif pada merchant.

Referensi: [Bank Transfer Integration](https://docs.midtrans.com/docs/coreapi-core-api-bank-transfer-integration), [QRIS](https://docs.midtrans.com/reference/qris), [Status API](https://docs.midtrans.com/reference/get-transaction-status).

### 3.3 Identitas dan reservation

| Field existing | Penggunaan |
|---|---|
| payment_attempts.merchant_reference | Midtrans order_id unik per attempt |
| provider_reference | Midtrans transaction_id |
| provider_request_id | Identitas request internal |
| provider/environment | Immutable setelah reservation |
| instruction_ciphertext | VA/QR metadata minimum, terenkripsi |

Order ID tidak memakai booking number sendirian. Harus unik terhadap merchant/environment dan attempt.

Urutan create:

1. Validasi owner, approval, amount, channel, deadline dan historical intent.
2. Lock domain; persist reservation attempt dan hubungan idempotency.
3. Commit.
4. Panggil Midtrans di luar DB transaction.
5. Lock ulang; simpan hasil tanpa mendemote settlement yang sudah diterapkan.
6. Timeout/ambiguous outcome menjadi unknown; lanjut Status inquiry, bukan order baru.

Same key + request berbeda menghasilkan conflict. Same key setelah timeout mengembalikan attempt yang sama.

### 3.4 Interface rancangan

~~~php
MidtransClient::charge(object $attempt, object $booking): array
MidtransClient::status(object $attempt): array
MidtransClient::expire(object $attempt): array
MidtransClient::refund(object $attempt, object $refund): array

MidtransStatusMapper::normalize(
    array $response,
    object $attempt,
    string $evidenceSource
): array

MidtransPaymentService::initiate(
    User $owner,
    string $bookingGuid,
    array $input
): array
MidtransPaymentService::refresh(
    User $owner,
    string $bookingGuid,
    string $attemptGuid
): void
MidtransPaymentService::qrisImage(
    User $owner,
    string $bookingGuid,
    string $attemptGuid
): string
MidtransPaymentService::receiveNotification(array $payload, string $rawBody): string
MidtransPaymentService::reconcile(string $attemptGuid): void

PaymentSettlementService::recordVerifiedOutcome(object $attempt, array $outcome): string
PaymentSettlementService::apply(string $eventGuid): void
PaymentSettlementService::expireIfTerminalUnpaid(string $bookingGuid): void

BookingRefundService::processProviderRefund(string $refundGuid): void
BookingRefundService::reconcileProviderRefund(string $refundGuid): void
~~~

Detail array shape didokumentasikan melalui PHPDoc saat implementasi mengikuti pola sibling files. Tidak menambah abstraction di luar kebutuhan kontrak ini.

## 4. State machine, deadline dan concurrency

### 4.1 Booking lifecycle

~~~text
pending_approval
  ├─ reject / approval timeout → perilaku existing
  ├─ approved Rp0 → confirmed, free entitlement, tanpa Charge
  └─ approved paid → awaiting_payment
                        ├─ verified timely settlement → confirmed
                        └─ deadline
                             ├─ pending/unknown → payment_verification
                             │                    → payment_review bila perlu
                             └─ verified terminal unpaid → expired
~~~

BookingRules::HELD tetap mencakup pending_approval, awaiting_payment, payment_verification, payment_review dan confirmed.

Perubahan payment tidak boleh mengubah CREATE_LEAD_MINUTES, APPROVE_LEAD_MINUTES, claim admin, atau H-5 QR.

### 4.2 Satu deadline absolut

Gunakan timestamp approval otoritatif dalam booking_status_histories. Tidak wajib menambah kolom approved_at.

~~~text
payment_expires_at = waktu approval + 900 detik
~~~

Booking, intent dan seluruh attempt mengacu pada deadline yang sama. Attempt pada menit ke-8 hanya memiliki sisa sekitar 7 menit.

Charge:

~~~json
{
  "custom_expiry": {
    "order_time": "<waktu approval dalam GMT+7>",
    "expiry_duration": 15,
    "unit": "minute"
  }
}
~~~

BCA VA, BNI VA dan QRIS mendukung custom expiry. order_time menggunakan GMT+7. Respons expiry_time/transaction_time harus diperiksa sebelum instructions ditampilkan.

Referensi: [Charge Transactions](https://docs.midtrans.com/reference/charge-transactions-1), [Custom Expiry Object](https://docs.midtrans.com/reference/custom-expiry-object).

Guard awal yang direncanakan: tolak create ketika sisa waktu <=30 detik, HTTP timeout 10 detik. Ini adalah pilihan teknis awal di atas minimum provider 20 detik; buktikan melalui UAT dan konfigurasi yang diuji. Bukan klaim hasil UAT.

Referensi: [Expiry Threshold](https://docs.midtrans.com/reference/deployment).

Saat deadline:

- Backend menolak create/switch dan tidak menyajikan instruksi payable.
- Flutter menghentikan create/retry/copy-pay/export serta menyembunyikan instructions.
- Timer Flutter tidak menandai booking expired.
- Pending/unknown tetap menahan kuota.
- Tanpa attempt: expire di bawah booking lock setelah memastikan reservation tidak berlomba.
- Attempt yang pernah dikirim: tutup hanya setelah bukti terminal yang memadai.
- 404, timeout, atau ambiguous cancel/expire response bukan bukti unpaid.

Scheduler provider dapat terlambat. Cutoff PAMI serta release stok tidak bergantung pada callback expiry tepat waktu. Cancel/expire merupakan best-effort yang diikuti Status verification.

Referensi: [Technical FAQ](https://docs.midtrans.com/docs/technical-faq).

### 4.3 Payment states dan settlement

Gunakan statuses existing; tidak perlu enum baru hanya untuk channel Midtrans.

- Reservation: initiating.
- Charge accepted dengan instruksi valid: pending.
- Ambiguous request/status: unknown.
- Verified expire: expired.
- Verified deny/cancel/non-payable failure: failed dengan original provider status tersimpan.
- Verified eligible settlement: paid.
- Verified reversal/refund: penerapan adjustment, entitlement tidak diaktifkan kembali.

Transisi dari initiating langsung ke paid diperlukan untuk callback-before-create-response dan hanya boleh melalui jalur verified evidence. Perubahan PaymentStates tidak menggantikan validasi bukti.

Untuk tiga channel ini kandidat paid adalah transaction_status=settlement. fraud_status bila tersedia harus accept.

Evidence wajib mengikat:

- Stored order ID dan transaction ID bila tersedia.
- Provider, environment dan expected merchant.
- Method, bank/acquirer.
- Amount IDR integer tanpa float dan currency.
- Booking, intent, attempt dan ownership.
- Waktu settlement dan expiry.
- Applied attempt serta kapasitas yang masih sah.

Settlement sebelum deadline yang baru diterima sesudah deadline dapat diterapkan jika seluruh invariant valid dan kapasitas masih ditahan. Settlement >= deadline, waktu yang tidak terbukti, atau kapasitas yang sudah dilepas masuk review. Dana tetap dicatat sebagai verified/unapplied bila terbukti, tanpa auto-confirm/ticket.

### 4.4 Lock order

~~~text
destination/scope → quota → booking → intent → attempt/event → refund/tickets
~~~

Gunakan urutan yang sama pada create, settlement, expiry, refund dan check-in. Tempatkan idempotency locks secara konsisten agar tidak membentuk inversi.

Network, PDF rendering dan notifikasi tidak dilakukan sambil menahan lock domain. Gunakan outbox/after-commit dan uniqueness existing.

### 4.5 Sequential attempts

Attempt baru hanya diizinkan jika:

- Booking masih dalam deadline dan sisa waktu mencukupi.
- Prior attempt terbukti terminal/tidak payable.
- Tidak ada initiating/pending/unknown atau paid/reversed yang menghalangi.
- Provider/environment intent tidak berubah.
- Semua snapshot amount/currency/deadline tetap cocok.

Late settlement dari attempt lama setelah retry tidak boleh otomatis mengonfirmasi booking atau menerbitkan tiket kedua.

## 5. Webhook, Status API dan reconciliation

### 5.1 Durable notification inbox

POST /api/v1/webhooks/midtrans publik tanpa auth pelanggan. Terapkan format validation, payload size limit dan rate limit.

1. Persist receipt: raw hash, encrypted payload yang diperlukan, received_at dan correlation.
2. Acknowledge setelah persistence sukses.
3. Dispatch queue processing.
4. Scheduler memulihkan receipt bila dispatch gagal.

Unknown order tidak memicu provider request. Jangan menggunakan URL, host, atau credential selection dari payload mentah.

### 5.2 Signature dan authenticated Status

Rumus notification signature tetap:

~~~text
SHA512(order_id + status_code + gross_amount + ServerKey)
~~~

Gunakan string asli gross_amount saat menghitung signature dan constant-time comparison. Mismatch pada signature respons GET Status PoC bukan alasan mengubah rumus webhook.

Pilihan teknis plan: financial finalization menggunakan authenticated GET Status terhadap order yang telah dikenal database. Signature notification diverifikasi dan dicatat; notification adalah trigger, bukan status finansial yang langsung dipercaya.

Known local order → immutable environment/merchant binding → stored order ID → pinned Midtrans endpoint → Status evidence.

Jika lookup gagal, event tetap unverified/quarantined dan direconcile. Tidak ada paid/refunded palsu.

Referensi: [Notification Authenticity](https://docs.midtrans.com/docs/https-notification-webhooks).

### 5.3 Ledger dan dedupe

- Receipt mentah dan verified normalized evidence merupakan record terpisah dengan correlation.
- Original raw hash, source dan historical evidence tidak ditulis ulang.
- Dedupe key memakai provider/environment dan fakta stabil event; raw payload hash tetap tersimpan.
- Jangan dedupe hanya dengan order ID: pending, settlement dan refund berbeda.
- Processing markers dapat berubah; financial evidence tetap dapat diaudit.
- Pending setelah paid tidak mendemote status.
- Settlement setelah reversal/refund tidak mengaktifkan tiket lagi.
- Conflicting event identity/amount tidak dianggap duplicate yang aman; quarantine/review.

### 5.4 Reconciliation

Bounded batches setiap menit, withoutOverlapping, durable DB claims, backoff dan jitter.

Tiga scan terpisah:

1. Notification inbox dan verified event yang belum diterapkan.
2. Initiating/pending/unknown attempts, termasuk sesudah deadline.
3. Pending/unknown refunds pada attempt yang sudah paid.

Manual refresh dan worker berbagi rate limit/claim. Retry hanya safe operations, bukan new Charge atau refund key baru.

## 6. QRIS PNG dan Flutter native

Laravel mengambil QR image hanya dari provider response yang telah divalidasi:

- Binding attempt dan image action harus sesuai.
- HTTPS host/path allowlist; tidak menerima arbitrary user URL.
- Redirect diblokir atau divalidasi ulang.
- Batas ukuran, timeout, MIME dan bytes PNG diperiksa.
- Tidak mengirim provider credentials kepada Flutter.
- Endpoint Laravel tetap owner-authorized, private/no-store dan nosniff.
- Jangan tampilkan QR setelah cutoff atau ketika instructions tidak valid.

Midtrans mendokumentasikan generate-qr-code dan generate-qr-code-v2 sebagai PNG. Format/provider action yang digunakan harus dibuktikan pada Sandbox merchant.

Referensi: [QRIS](https://docs.midtrans.com/reference/qris).

Flutter:

- Native chooser dari backend channels.
- Nomor VA sebagai string, copy tidak mengubah leading zero.
- Instruksi sesuai bank aktual.
- Image.memory/PNG bytes untuk pembayaran QRIS.
- Simpan/share bytes autentik melalui Android bridge existing.
- Countdown dari server_time dan elapsed lokal monotonic bila tersedia.
- Resume/reopen mengambil snapshot; tidak menambah deadline atau auto-create.
- Poll hanya ketika route/app aktif, dengan coalescing/backoff.
- Confirmed/paid hanya jika booking, intent, attempt dan applied attempt konsisten.
- Pending/unknown menampilkan verifikasi tanpa retry Charge.
- Refund approved/pending tidak ditampilkan sebagai dana telah kembali.
- Semua request ke Laravel, bukan Midtrans langsung.

Preserve AppColors/AppSpacing/AppIcons, approved shell, navigation dan existing components. Tidak ada redesign.

## 7. Refund penuh dan admission safety

### 7.1 BCA/BNI VA

~~~text
customer request → scoped admin approval → manual transfer
                 → evidence/reference verification → manual_refunded
~~~

Nominal harus sama dengan full outstanding captured amount. Unique reference, actor, timestamp, note dan bukti diaudit.

### 7.2 QRIS

API refund hanya jika permission, channel, refund window dan saldo/capability mendukung.

- Persist refund_key sebelum network.
- Retry request sama menggunakan refund_key sama.
- Amount dihitung backend, full-only.
- Accepted response tidak otomatis berarti dana telah kembali.
- Verifikasi final provider outcome.
- Timeout mempertahankan pending/unknown.
- Jangan beralih manual ketika hasil API masih ambigu.
- Jika API capability tidak tersedia sebelum dispatch, gunakan manual workflow.
- Provider partial adjustment tidak menjadi partial-refund feature PAMI; masuk review.

Midtrans mencantumkan QRIS untuk refund standar; bank transfer VA tidak dicantumkan. Refund capability yang tidak tersedia memerlukan aktivasi.

Referensi: [Refund Transactions](https://docs.midtrans.com/reference/refund-transaction).

### 7.3 Refund versus check-in

Sebelum dispatch API, dengan lock booking yang konsisten:

1. Periksa tiket belum digunakan.
2. Persist refund dispatch claim.
3. Hold admission selama outcome belum pasti.
4. Commit, baru request provider.

Check-in lebih dahulu berhasil → refund otomatis ditolak/review. Refund claim lebih dahulu berhasil → check-in diblokir selama hold.

Full refund terverifikasi mencabut entitlement dan QR secara permanen. Timeout tidak mencabut hold. Pelepasan hold memerlukan hasil definitif serta pemeriksaan ulang eligibility.

Refund setelah tiket digunakan, reversal/partial adjustment tak terduga, atau provider refund sesudah manual settlement masuk review/audit. Tidak ada refund ganda atau ticket reactivation.

## 8. Schema, historical read-path dan cutover

### 8.1 Existing schema yang digunakan kembali

- payment_intents.booking_guid UNIQUE.
- Provider/environment, amount unsigned bigint dan currency.
- Attempt sequence dan unique merchant/request/provider reference.
- Cross-intent applied-attempt FK.
- Payment events verification/disposition/hash dan metadata.
- Reconciliation fields dan escalation.
- Refund request active-slot uniqueness, full amount, manual evidence/reference dan audit.
- Booking statuses string; expired/verification/review tidak membutuhkan enum baru.

### 8.2 Additive migration minimum

| Kebutuhan | Tambahan |
|---|---|
| Merchant binding | Snapshot merchant identity bagi Midtrans baru |
| Inbox/replay scan | Index provider/environment/verification/disposition/received_at |
| Refund idempotency | refund_key, unik dalam scope attempt |
| Refund identity | provider_refund_reference |
| Dispatch recovery | Claim timestamp/lease |
| Refund reconciliation | Next/last reconcile, count dan error |

Gunakan metadata encrypted yang sesuai untuk immutable receipt payload. Jangan menambah order_id/transaction_id column duplikat.

Tidak mengubah migration historis, overwrite provider, drop ledger, atau reset expiry lama. Approval timestamp memakai history existing.

### 8.3 Historical eligibility dan finance

PaymentEligibility, receipt dan analytics membaca verified ledger plus full original binding tanpa active gateway config atau metadata.source=doku_snap hardcode.

- Histori Sandbox/DEMO tetap terbaca dan berlabel benar.
- Admission Production memerlukan evidence Production yang sah.
- Mengganti gateway tidak melegitimasi test evidence.
- Unknown biaya/provider refund completeness tetap null/unavailable.
- Jangan mengubah angka historis hanya karena gateway baru aktif.

Finance mengagregasi real providers per environment. Provider tunggal jika hanya satu sumber; provider=mixed jika beberapa. Detail transaksi tetap membawa original provenance. Flutter parser diperbarui bersama backend, tanpa melemahkan scope/environment/source validation.

### 8.4 Administrative closure DOKU

Sebelum final deletion:

1. Inventaris read-only intent, attempt, event, refund dan queued/failed DOKU jobs.
2. Catat jumlah serta total baseline per environment/status.
3. Kelompokkan paid, terminal unpaid, pending/unknown, reversed dan open refunds.
4. Selesaikan pending/unknown memakai bukti provider/dashboard dan keputusan terotorisasi yang diaudit.
5. Jangan mass-update unknown menjadi failed.
6. Jangan membuat intent Midtrans pada booking dengan intent DOKU/DEMO.
7. Legacy booking tanpa intent dan deadline satu jam tidak otomatis mendapat deadline baru. Drain/tutup atau pulihkan melalui keputusan terotorisasi sebelum menjadi payable Midtrans.
8. Cocokkan kembali total, receipt dan entitlement setelah closure.

Administrative closure tidak menggantikan terminal proof untuk quota release. Kasus ambigu yang belum selesai menghalangi cutover.

Drain/karantina serialized DOKU jobs sebelum class dihapus. Jangan sampai deployment baru mencoba memanggil class yang sudah tidak tersedia. Audit logs, evidence dan financial history tetap disimpan.

## 9. Rencana eksekusi per fase

Semua checklist berikut belum dijalankan. Perintah verifikasi hanya untuk tahap implementasi yang diizinkan.

### Phase 0 — Bekukan baseline dan siapkan isolasi

**Dependency:** instruksi implementasi eksplisit.

**Files:** manifest di bagian 2; phpunit.mysql.xml; DisposableMysqlGuard dan harness terkait.

- [ ] Periksa ulang HEAD, dirty tree, manifest dan user diff.
- [ ] Bekukan public contract dan invariants.
- [ ] Inventaris runtime schema/ledger DOKU secara read-only.
- [ ] Perketat disposable test guard/harness sebelum migration test.
- [ ] Jalankan baseline targeted di database test terisolasi.

**Gate:** target database dan privileges terbukti; baseline PASS atau deviations tercatat; tidak ada deletion.

### Phase 1 — Historical foundation dan shared settlement

**Dependency:** Phase 0.

**Files:** PaymentSettlementService; PaymentEligibility; PaymentService; PaymentReceiptService; FinancialAnalyticsService; additive migration; HistoricalPaymentCompatibilityTest dan shared tests.

**Consumes:** existing ledger/booking rules.  
**Produces:** provider-neutral strict eligibility dan transactional apply service.

- [ ] Tambah failing regression historical paid/reversed/manual-refunded, environment isolation dan duplicate evidence.
- [ ] Jalankan file test terkait dan pastikan RED berasal dari assertion perilaku.
- [ ] Extract shared settlement/reversal tanpa mengubah approval/free/DEMO policy.
- [ ] Implement historical receipt/finance dan minimal additive schema.
- [ ] Jalankan targeted tests sampai GREEN.
- [ ] Bandingkan totals, provenance dan entitlement historis.

**Gate:** shared domain tidak bergantung DOKU transport; histori tetap sama; Midtrans belum aktif.

### Phase 2 — Midtrans client, reservation dan Charge

**Dependency:** Phase 1.

**Files:** config/midtrans.php; Midtrans client/registry/mapper/service/request/exception; PaymentController; PaymentGateway; MidtransClientTest dan MidtransPaymentTest.

**Consumes:** shared domain dan frozen API contract.  
**Produces:** native VA/QR instruction contract dan durable Midtrans attempts.

- [ ] Tambah failing tests BCA/BNI/QRIS mapping, fail-closed config, idempotency dan unknown.
- [ ] Pastikan timeout/crash test tidak membuat order kedua.
- [ ] Implement config, client, reservation dan safe instruction normalization.
- [ ] Tambah callback-before-create-response regression.
- [ ] Jalankan targeted tests sampai GREEN.

**Gate:** satu active attempt/Charge; no automatic Charge retry; no credentials in response/log/Flutter.

### Phase 3 — Notification, Status dan recovery

**Dependency:** Phase 2.

**Files:** MidtransWebhookController; ProcessMidtransPaymentEvent; ReconcileMidtransPayment; ReconcileMidtransPayments; routes; MidtransWebhookTest; MidtransReconciliationTest.

**Consumes:** stored immutable order/merchant/environment binding.  
**Produces:** verified normalized events dan recovery scheduler.

- [ ] Tambah failing signature/status-fallback, identity mismatch, duplicate/out-of-order dan dispatch-failure tests.
- [ ] Implement durable receipt inbox dan authenticated known-order lookup.
- [ ] Implement idempotent apply, bounded queue/scheduler recovery.
- [ ] Jalankan targeted tests sampai GREEN.
- [ ] Setelah izin UAT, buktikan webhook asli dan missing-webhook recovery.

**Gate:** verified settlement menghasilkan satu confirmation/receipt/ticket entitlement; forged/unverified payload tidak paid.

### Phase 4 — Deadline, retry, expiry dan full refund

**Dependency:** Phase 3.

**Files:** BookingService; BookingRules; PaymentService; PaymentStates; BookingRefundService; ProcessMidtransRefund; MidtransExpiryTest; MidtransRefundTest; MySQL concurrency tests/workers.

**Consumes:** verified outcomes, immutable deadline, shared lock order.  
**Produces:** safe terminal expiry/retry dan full-refund dispatch/recovery.

- [ ] Tambah RED tests approval+900s, retry t+8 tetap deadline sama, cutoff tepat t+15 dan minimum-window guard.
- [ ] Tambah RED tests settlement/expiry, no-attempt/reservation dan refund/check-in races.
- [ ] Implement 15-minute approval dan terminal-unpaid closure.
- [ ] Implement sequential attempts dan server capability cutoff.
- [ ] Implement QRIS capability gate, persisted refund key/claim dan paid-attempt refund recovery.
- [ ] Implement temporary admission hold serta verified permanent revoke.
- [ ] Jalankan feature tests sampai GREEN.
- [ ] Jalankan targeted real-MySQL races setelah isolasi terbukti.

**Gate:** tidak ada overbooking, dual payable attempts, duplicate refund atau admission saat refund claim belum pasti.

### Phase 5 — Flutter native integration

**Dependency:** kontrak Phase 2–4 stabil.

**Files:** seluruh Flutter MODIFY/ADD pada manifest.

**Consumes:** frozen PAMI endpoints dan normalized DTO.  
**Produces:** native BCA/BNI/QRIS flow dengan cutoff/recovery benar.

- [ ] Refactor fixture tests untuk Midtrans, PNG, server_time dan historical finance.
- [ ] Pastikan assertions RED menguji old behavior.
- [ ] Update DTO/repository/state sambil mempertahankan idempotency/lifecycle architecture.
- [ ] Implement chooser/instruksi/copy bank dinamis.
- [ ] Render dan export authentic PNG.
- [ ] Implement clock offset, cutoff, verifying/refund/paid states.
- [ ] Update finance, DEMO config guard dan provider-neutral copy.
- [ ] Jalankan targeted tests sampai GREEN.
- [ ] Periksa native lifecycle dan visual pada ukuran/text scale sasaran.

**Gate:** no Snap/WebView, reopen/resume tidak auto-create, paid hanya dari backend konsisten, Android save/share terbukti.

### Phase 6 — Closure dan complete DOKU cleanup

**Dependency:** Phase 1–5 lulus dan legacy cases selesai.

**Files:** DELETE manifest; mixed route/tests; .gitignore/logging/config.

- [ ] Tutup kasus lama dengan bukti dan audit.
- [ ] Drain/karantina DOKU serialized jobs.
- [ ] Hapus exact DELETE manifest setelah replacement coverage tersedia.
- [ ] Bersihkan imports/routes/config/schedule/probe/return hooks.
- [ ] Preserve assetlinks/ticket deep links.
- [ ] Audit remaining DOKU references dan klasifikasikan setiap hit.
- [ ] Perbarui Graphify setelah write diizinkan.
- [ ] Jalankan targeted regression dan cocokkan historical totals.

**Gate:** tidak ada active DOKU HTTP/runtime, data historis tetap utuh dan terbaca.

Pencarian substring doku juga dapat cocok dengan kata dokumen. Gunakan pencarian kelas/namespace/provider yang tepat dan review hasil; jangan blind recursive delete. Locked handoff dan historical fixtures dapat menyebut provider lama sebagai data/referensi tanpa membentuk active DOKU integration.

### Phase 7 — UAT, security review dan Production readiness

**Dependency:** Phase 6 serta merchant capabilities.

- [ ] Buktikan Laravel → provider → verified booking → ticket untuk BCA/BNI/QRIS.
- [ ] Buktikan genuine webhook, expiry, missing notification dan recovery.
- [ ] Buktikan full refund per channel/capability.
- [ ] Jalankan targeted native Android UAT; iOS bila menjadi target.
- [ ] Review authorization, secrets/log sanitization, scheduler/queue recovery.
- [ ] Siapkan approved cutover dan rollback evidence.

**Gate:** documented UAT sign-off. Production tidak aktif otomatis.

Commit/push tidak merupakan langkah otomatis dari plan ini. Milestone dapat dicatat sebagai perubahan lokal sampai pengguna mengizinkan Git actions.

## 10. Test matrix

| Area | Skenario | Acceptance |
|---|---|---|
| Approval | Paid/free/reject/timeout | Paid awaiting +900s; gratis tanpa Charge; aturan lain tetap |
| Channel | BCA/BNI/QRIS | Identitas/instruksi benar; expiry <= booking |
| Idempotency | Double tap/same key/conflicting request | Satu attempt/Charge; conflict eksplisit |
| Recovery | Timeout/crash setelah request terkirim | Unknown; recover order sama |
| Retry | Pending/unknown versus terminal | Pending diblokir; terminal boleh sebelum deadline |
| Deadline | t+8, minimum window, tepat t+15 | Deadline tidak reset; cutoff server tegas |
| Expiry | No attempt/terminal/pending/404 | Release hanya kondisi aman |
| Settlement | Timely delayed/late/no reliable time | Timely valid boleh confirm; lainnya review |
| Security | Wrong amount/currency/order/merchant/bank/acquirer/env | No paid; quarantine |
| Notification | Valid signature/mismatch/provider down/unknown order | No raw payload trust; known-order Status only |
| Ordering | Duplicate/pending after paid/settlement after reversal | No duplicate side effects/reactivation |
| Queue | Dispatch failure/worker crash | Durable inbox/replay recovery |
| QR image | Ownership/MIME/size/redirect/SSRF/expiry | Authentic private PNG; no arbitrary URL |
| VA refund | Exact full amount/evidence/RBAC/reference replay | One verified manual_refunded |
| QRIS refund | Permission on/off/timeout/same key | Conditional API; no duplicate manual fallback |
| Admission | Refund claim versus check-in | Deterministic winner under lock |
| Historical | DOKU paid/reversed/manual-refunded | Provenance/totals/receipt/evidence survive |
| Environment | DEMO/Sandbox/Production | Isolation; Production fail-closed |
| Flutter | Offline/restart/background/stale response | No auto-create/false paid |
| Native/visual | 320–430dp, 1x–2x text, save cancellation/error | Tokens/shell retained, no overflow, correct feedback |

### 10.1 Real MySQL concurrency wajib

Proses/koneksi terpisah dengan controlled barriers:

- Same-key dan different-key double create.
- Reservation versus deadline expiry.
- Settlement versus quota release.
- Callback versus Charge response.
- Duplicate apply dan ticket issue.
- Refund worker versus worker lain.
- Provider refund versus manual settlement.
- Refund claim versus check-in.

Assertions: attempt/HTTP Charge count, captured/applied events, booking state, quota, refund, ticket dan outbox uniqueness.

### 10.2 Test isolation blocker

phpunit.mysql.xml memakai default wisata_daerahku_test, sedangkan guard payment memakai pola pami_(doku|phase1b)_..._test. Jangan jalankan as-is atau melemahkan guard.

DisposableMysqlGuard harus diperbarui konsisten pada bootstrap name, grants dan visible-schema checks untuk namespace Midtrans/provider-neutral. Harness DEMO masih hanya memeriksa suffix _test dan juga perlu diperketat.

Syarat sebelum migration test:

- Dedicated non-root/non-admin account.
- Privilege terbatas pada disposable schemas.
- Tidak dapat melihat schema aplikasi.
- Reject URL/read/write overrides dan cached production config.
- Verifikasi SELECT DATABASE(), schema kosong dan migration exit code.
- Workers memakai schema test yang sama.
- Cleanup hanya schema yang dibuat test dan terverifikasi hilang.
- Jangan menjalankan seluruh MySQL suite lama tanpa meninjau harness terkait.

SQLite mock PASS bukan bukti locking MySQL.

### 10.3 Perintah saat implementasi

Belum dijalankan:

~~~text
php artisan test --compact <target file>
vendor/bin/pest --configuration=phpunit.mysql.xml tests/Mysql/MidtransPaymentConcurrencyTest.php
vendor/bin/pint --dirty --format agent
git diff --check

flutter test test/booking_payment_test.dart
flutter test test/booking_refund_test.dart test/demo_payment_disabled_test.dart
flutter test test/business_booking_widgets_test.dart test/ticket_contract_test.dart
flutter test test/operations_queue_finance_regression_test.dart
flutter test test/app_config_test.dart
flutter test test/midtrans_payment_live_test.dart
~~~

MySQL command hanya setelah dedicated config/account/isolation terbukti. Live Flutter test hanya setelah izin UAT, fixture isolation dan explicit opt-in. Production+DEMO AppConfig negative test membutuhkan konfigurasi build/test yang sesuai.

Tidak menjalankan broad Flutter analyze/regression suite. Native method-channel change memerlukan full rebuild/reinstall/restart, bukan hot reload saja.

Visual inspection: 320/360/393/430dp, text 1x/1.3x/2x, long VA/reason, keyboard/safe area, touch targets/semantics, reduced motion, serta membedakan QR pembayaran dan QR tiket masuk. Accessibility tidak memperpanjang deadline bisnis.

## 11. Environment guards dan blocker eksternal

### 11.1 Konfigurasi

- MIDTRANS_ENABLED=false default.
- MIDTRANS_ENVIRONMENT=sandbox|production.
- MIDTRANS_SERVER_KEY backend secret.
- MIDTRANS_MERCHANT_ID untuk expected identity; wajib Production.
- MIDTRANS_CHANNELS untuk BCA/BNI/QRIS internal registry.
- Explicit Production approval dan per-channel verified readiness.
- Endpoint, timeout, retry dan capability policy melalui config teruji.
- Production menolak Sandbox key, wrong host, missing identity dan unapproved channel.
- Server Key tidak masuk Flutter, fixture, response atau log.
- DEMO tidak boleh aktif Production.
- Deployment config:cache hanya dalam tahap deployment yang disetujui.

Kill switch new Charge tidak boleh mematikan Status/webhook/reconciliation transaksi yang sudah diterima provider. Jalur existing-order verification tetap harus fail-closed terhadap credentials/identity yang tidak cocok.

### 11.2 Blocker dan evidence

| Blocker | Evidence diperlukan | Dampak |
|---|---|---|
| QRIS Refund permission | Capability aktif pada account/key yang digunakan | API refund belum dapat dinyatakan lulus |
| QRIS acquirer/window/balance | Channel eligible dan saldo tersedia | API versus manual availability |
| Production Core API standar | Aktivasi/konfirmasi merchant untuk endpoint yang diminta | Menghalangi Production |
| BCA/BNI/QRIS Production | Aktivasi masing-masing channel | Belum boleh dinyatakan ready |
| Server Key/Merchant ID | Identity dan environment cocok | Fail-closed jika mismatch |
| Notification URL | HTTPS public, MAP config dan genuine delivery | Webhook belum terbukti |
| Short remaining expiry | UAT absolute expiry serta expire/status | Tidak boleh mengklaim provider cutoff presisi tanpa bukti |
| Legacy DOKU unknown/pending | Evidence per kasus dan authorized closure | Menghalangi cleanup/cutover |
| iOS bila ditargetkan | Native bridge + macOS/iOS build/device evidence | Save QR iOS belum lulus |

Midtrans menyatakan Core API Production memerlukan permintaan aktivasi. Sandbox tidak membuktikan Production readiness.

Referensi: [Core API Activation](https://docs.midtrans.com/docs/coreapi-advanced-features).

PoC BCA Sandbox yang disebut handoff adalah evidence yang dilaporkan spesifikasi, bukan transaksi yang diverifikasi ulang pada audit ini. PoC tersebut tidak melalui PAMI dan tidak membuktikan webhook asli. BNI, QRIS, refund QRIS, expiry/reconciliation serta Flutter end-to-end masih memerlukan UAT.

## 12. Rollback strategy

1. Hentikan new Charge dan new refund dispatch.
2. Pertahankan notification inbox, Status lookup, replay dan reconciliation.
3. Pertahankan additive schema, ledger, APP_KEY dan ciphertext.
4. Jangan menyalakan DOKU kembali.
5. Jangan rollback ke aplikasi yang tidak memahami Midtrans records.
6. Jangan restore database lama hingga menghapus settlement/refund setelah backup.
7. Gunakan release kompatibel Midtrans dengan create disabled; forward-fix untuk kasus finansial.
8. Pertahankan receipt, histori dan tiket sah.
9. Karantina outcome ambigu tanpa false paid atau unsafe quota release.
10. Sebelum reopen create, cocokkan in-flight orders/refunds dengan provider evidence dan audit.

Rollback source tidak dapat membatalkan perpindahan uang yang sudah terjadi.

## 13. Definition of Done

- [ ] Midtrans satu-satunya real gateway dan tidak ada active DOKU runtime.
- [ ] BCA/BNI/QRIS lulus approval → verified payment → confirmed → ticket.
- [ ] Deadline 15 menit, sequential attempts dan quota safety terbukti.
- [ ] Full refund/admission revoke sesuai channel terbukti.
- [ ] Historical DOKU ledger/receipt/audit/totals tetap utuh.
- [ ] DEMO/free/RBAC/claim/H-5/ticket links tidak regresi.
- [ ] Targeted tests, real-MySQL concurrency, native QA dan provider UAT memiliki evidence.
- [ ] Production tidak diaktifkan otomatis.

### 13.1 Traceability spesifikasi

| Keputusan final | Bagian/task pemilik |
|---|---|
| D01 tiga channel | 3.2, Phase 2 dan 5 |
| D02 confirmed hanya verified payment | 4.3, Phase 1 dan 3 |
| D03 15 menit | 4.2, Phase 4 |
| D04 safe expiry/quota | 4.1–4.4, Phase 4 |
| D05 sequential retry | 3.3 dan 4.5, Phase 2/4 |
| D06 refund capability/manual | 7, Phase 4 |
| D07 exactly-once ticket | 4.3–4.4 dan 7.3, Phase 1/3/4 |
| D08 native Flutter | 6, Phase 5 |
| D09 webhook/Status/reconcile | 5, Phase 3 |
| D10 administrative closure | 8.4, Phase 6 |
| D11 historical data | 8.1–8.4, Phase 1/6 |
| D12 environment guards | 11.1, Phase 2/7 |
| D13 cleanup | 2 dan Phase 6 |
| D14 UAT/regression/concurrency | 10, Phase 7 |
| F-A approval dahulu | 4.1, Phase 1/4 |
| F-B cutoff/stock hold | 4.2–4.4, Phase 4 |
| F-C full-only refund | 7, Phase 4 |

## 14. Pemeriksaan aktual dan batas evidence

| Pemeriksaan audit | Status |
|---|---|
| Dokumen final dan source comparison kedua repository | PASS |
| Git lokal/worktrees/relevant branches/live remote refs | PASS |
| Dependency/schema migration/file inventory | PASS |
| git diff --check kedua checkout utama | PASS |
| Working tree akhir audit sesuai awal | PASS |
| Tests/Pint/build/install/visual runtime | NOT RUN |
| Runtime database/transaction ledger inventory | NOT RUN |
| Provider requests/UAT/merchant permissions | NOT RUN |
| Graphify rebuild/update | NOT RUN |

Tabel ini melaporkan audit planning sebelumnya. Pembuatan file Markdown pada tindak lanjut bukan implementasi dan tidak mengubah status test/UAT menjadi PASS.

Tidak ada source yang diubah/dihapus, migration dijalankan, .env diubah, transaksi dibuat, commit/push/deployment dilakukan pada audit. Tindak lanjut hanya menyimpan dokumen plan ini.

## 15. Referensi

- [Spesifikasi final PAMI](../../prd/PAMI-Midtrans-CoreAPI-Migration-Handoff-v1.0.md).
- [Midtrans Bank Transfer Integration](https://docs.midtrans.com/docs/coreapi-core-api-bank-transfer-integration).
- [Charge Transactions / Custom Expiry](https://docs.midtrans.com/reference/charge-transactions-1).
- [Custom Expiry Object](https://docs.midtrans.com/reference/custom-expiry-object).
- [QRIS](https://docs.midtrans.com/reference/qris).
- [Get Transaction Status](https://docs.midtrans.com/reference/get-transaction-status).
- [HTTP(S) Notification / Webhooks](https://docs.midtrans.com/docs/https-notification-webhooks).
- [Refund Transactions](https://docs.midtrans.com/reference/refund-transaction).
- [Bank Transfer Object](https://docs.midtrans.com/reference/bank-transfer-object).
- [Expiry Threshold / Deployment Notice](https://docs.midtrans.com/reference/deployment).
- [Technical FAQ](https://docs.midtrans.com/docs/technical-faq).
- [Core API Activation / Advanced Features](https://docs.midtrans.com/docs/coreapi-advanced-features).
- [Backend audited commit](https://github.com/joytimess/pami-wisata-daerahku-api/commit/bbf0a05ab34f42524b3d57c18485121c57174f69).
- [Flutter audited commit](https://github.com/koyotot/pami-wisata-daearahku/commit/7b2a8ff994581f7bdded24e8518eddb6ed10a9f3).

---

**STOP: implementasi menunggu persetujuan eksplisit pengguna.**

Belum diperiksa melalui eksekusi: tests, database nyata, provider UAT, Production permissions dan native runtime.
Risiko utama: historical eligibility, DOKU administrative closure, refund/check-in race, serta izin QRIS Refund dan aktivasi Production.
