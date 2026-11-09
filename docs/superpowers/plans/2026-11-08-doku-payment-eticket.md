# DOKU BNI/QRIS dan E-Ticket H-5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Rekomendasi untuk pekerjaan ini: root menjalankan `executing-plans`, backend engineer hanya menerima ownership tugas yang sedang aktif, architect/security read-only; maksimum tiga subagent. Belum ada mode eksekusi atau perubahan kode yang dijalankan.

**Goal:** Menyediakan checkout native BNI VA fixed bill dan QRIS dengan pembayaran server-authoritative, receipt/email setelah verified paid, serta QR entry per pengunjung sejak H-5.

**Architecture:** Perluas ledger/orchestrator, registry, invoice, notification outbox dan TicketService existing. Pisahkan expiry attempt dari deadline booking, payment QR dari entry QR, dan request refund dari bukti refund provider. Reuse contracts lama dengan additive fields; jangan membuat domain pembayaran kedua.

**Tech Stack:** PHP 8.3.36, Laravel 13.39.0, Pest 4.7.8, MySQL; DomPDF 3.1.2 dan BaconQrCode 3.1.1 sudah terpasang. Flutter/Dart checkout existing dengan `http`, secure storage, `flutter_svg`, timezone, url launcher dan Phosphor; tanpa dependency baru kecuali diminta/disetujui terpisah.

**Spec:** `docs/prd/PRD_Wisata_Daerahku_DOKU_Payment_Eticket_v3.0.md`.

**Audit:** `docs/audits/2026-11-08-doku-payment-eticket-gap-analysis.md` — source references, requirement matrix G01–G35 dan baseline. Baca keduanya sebelum eksekusi.

**Revisi dokumentasi:** 9 November 2026. HEAD dan API existing diverifikasi ulang; arsitektur, ownership, agent/skill dan urutan Task 0–9 tetap. Tidak ada source, test, migrasi, `.env`, atau konfigurasi provider diubah pada revisi ini.

## Global Constraints

- **Status: rencana untuk review.** PRD approved requirements; kode/migrasi aplikasi/aktivasi/go-live menunggu arahan eksplisit. Checkbox di dokumen ini belum dikerjakan.
- BE: `C:\laragon\www\pami-wisata-daerahku-api`, `main@de3cbf8a2989970f20587b89fc9b9ef017aa546f`.
- FE: `C:\laragon\www\pami-mobile\pami-wisata-daearahku`, `main@c75bd7b7d8e4f5d14b45b3ea9df6367607b7f38b`. User mengarahkan penggunaan checkout ini setelah diberi tahu actual origin `koyotot/pami-wisata-daearahku` berbeda dari expected `joytimess/pami-wisata-daerahku`. Jangan mengubah remote atau mengklaim identitas sama.
- **BNI Virtual Account (DGPC, fixed bill) + QRIS dinamis** saja untuk new issuance rilis awal. **DANA Direct, ShopeePay Direct, OVO Direct, hosted DOKU Checkout** di luar scope. Existing adapters/history/in-flight recovery dipertahankan.
- **Tidak ada input nominal pada Flutter.** Amount, currency, invoice, fee, provider/env dan deadline berasal Laravel; malicious sensitive fields ditolak.
- Satu invoice per booking/destinasi; satu canonical payment intent; satu active/unknown attempt. Timeout/clock zero tidak memberi izin create ulang.
- Paid/refunded hanya dari bukti provider terverifikasi. QRIS MDR tidak ditambahkan ke bill. Fee layanan baru tidak ditambahkan dalam rencana ini; service fee=0 dari server, subtotal=total snapshot existing.
- Booking approval/quota/lead time/cutoff existing dipertahankan. **PD-HDAY-01: deviasi product-policy terbuka** untuk target booking/payment hari H; lihat keputusan di bawah. Tidak melonggarkan `BookingRules` tanpa persetujuan policy terpisah dan tidak menganggap penolakan hari H sebagai pemenuhan requirement tersebut.
- QR H-5 mengikuti tanggal destination timezone snapshot, scheduler tiap menit. H-6 receipt tanpa QR; H-5 sampai H issuance bila entitlement/payment/cutoff eligible.
- Preserve DEMO / TEST_ONLY dan Sandbox/Production isolation. Kill switch menutup new issuance, bukan existing callbacks/reconciliation.
- Tidak reset DB, force migrate aplikasi, destructive rollback, commit/push/merge/deploy, Production transaction, secret dump, atau dependency install otomatis.
- Setiap code task: baca `AGENTS.md`, `.ai/rules/index.md` jika kemudian tersedia, applicable skill dan callers; RED→GREEN→REFACTOR; gunakan `systematic-debugging` untuk failure. Jangan memperbaiki unrelated baseline issue tanpa scope.
- PHP edits wajib `php vendor/bin/pint --dirty --format agent`, review diff formatter lalu affected tests. Jangan memakai Pint `--test`. UI mengikuti approved screen shell, brand/tokens, loading/error/a11y/responsive; baca Flutter custom-design-system + accessibility skills sebelum UI implementation.

## Deviasi product-policy dan status kesiapan

**PD-HDAY-01 — BLOCKED: target new booking/payment hari H belum didukung policy HEAD.** PRD menghendaki pembelian sampai hari H apabila kuota/jadwal mengizinkan. Source saat ini mensyaratkan creation >25h15m dan approval >75m sebelum midnight lokal tanggal kunjungan (`app/Support/BookingRules.php:17-25`, `app/Services/BookingService.php:77,259-267`); DOKU initiation/capture juga harus sebelum midnight tersebut (`app/Services/DokuPaymentService.php:185,366`). Karena itu belum ada window hari H yang bisa dilalui. Rencana ini mempertahankan policy sebagai kompatibilitas sementara. Target produk hari H tetap **belum terpenuhi**, bukan ditutup dengan test yang mengharapkan reject. Menutup deviasi memerlukan persetujuan terpisah untuk perubahan policy beserta cutoff/quota/approval/payment tests, atau persetujuan eksplisit menerima batasan rollout tersebut. Issuance/akses tiket pada hari H bagi booking yang sudah eligible/lunas sebelumnya adalah skenario berbeda dan tetap diuji pada Task 6.

Empat dimensi berikut dilaporkan terpisah **per channel dan environment**; satu label tidak menggantikan yang lain:

| Dimensi | Bukti minimum / arti |
| --- | --- |
| **CODE_COMPLETE** | Seluruh kode dalam scope channel dan shared contract selesai, review serta affected local tests dan checkpoint MySQL yang diwajibkan lulus. Fixture PASS hanya bukti lokal; code complete tidak membuktikan merchant activation atau UAT. Bagian kode yang kontraknya belum diketahui tetap partial |
| **PROVIDER_BLOCKED** | Ada kontrak terminal/signature/expiry/cancel/refund, merchant entitlement atau akses Sandbox yang belum tersedia/terverifikasi. Catat blocker spesifik dan pekerjaan lokal yang sudah selesai secara independen; jangan menebak mapping atau membuka issuance |
| **SANDBOX_UAT_PASSED** | Actual signed merchant Sandbox E2E untuk channel tersebut membuktikan create→verified outcome→booking/receipt/email/ticket gate dan recovery/idempotency. Fixture tests, dashboard ACTIVE, flag verified atau UAT channel lain tidak memenuhi label ini |
| **PRODUCTION_READY** | Code/review/MySQL dan Sandbox UAT channel lulus, seluruh blocker mandatory ditutup termasuk deviasi produk yang harus diselesaikan/diterima, merchant Production/callback/credentials terisolasi dan operasi worker/scheduler/reconciliation/email/monitoring/backup/rollout siap dengan evidence. Tetap bukan izin deploy, transaksi Production atau go-live; tindakan itu memerlukan arahan eksplisit terpisah |

| Channel pada source HEAD | Local code | Provider evidence | Signed Sandbox UAT | Production |
| --- | --- | --- | --- | --- |
| BNI VA | PARTIAL: create/callback foundation ada; hardening dan terminal recovery belum CODE_COMPLETE | BLOCKED: terminal inquiry/unknown-create recovery, window contract dan merchant E2E belum terbukti | NOT RUN / belum PASSED | BLOCKED / belum READY |
| QRIS | PARTIAL: generate adapter ada; query/callback/settlement belum lengkap, `settles=false` | BLOCKED: verified terminal/signature/window/cancel/refund entitlement dan merchant E2E | NOT RUN / belum PASSED | BLOCKED / belum READY |

Business-event email idempotency dapat lulus secara lokal meskipun exactly-once pengiriman SMTP belum dapat dijamin. Batas transport dilaporkan sendiri; jangan menghapus hasil local business-idempotency yang benar atau mengklaim email fisik pasti tunggal.

## Review Focus

1. Callback datang setelah local timer habis tetapi provider occurrence time masih tepat waktu: tidak hilang paid fact atau confirm late capture secara salah; Task 2/3.
2. App restart dengan secure idempotency key tetapi provider create timeout: pulihkan attempt lama tanpa POST kedua; Task 7.
3. Akun/email berubah saat email queued, atau worker mati setelah mail diterima: recipient tetap authorized/verified, duplicate transport tidak disembunyikan; Task 5.
4. Payment ledger reversed saat ticket check-in/refund berjalan: parent locks sama, used-ticket policy tidak dilewati; Task 8/9.
5. H-5 midnight destination berbeda dari UTC dan invoice legacy sudah ada: exact local boundary, unique QR, invoice identity/history tetap; Task 1/6/9.

## Ownership dan dependencies

Root mengintegrasikan contract FE/BE dan menulis FE. Backend engineer memegang **satu task aktif** pada file BE yang tercantum, tidak paralel mengedit service yang sama. Architect/security read-only. QA dapat menggantikan satu slot, bukan menambah subagent keempat. Semua worker diberi pesan tidak sendirian di codebase dan dilarang me-revert perubahan orang lain.

Urutan: Task 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9. Task 3/4 memiliki external gate; pekerjaan lokal invoice/email/Flutter fixtures dapat berjalan setelah contract Task 1 disepakati, tetapi channel tetap disabled dan milestone provider tetap BLOCKED. **Checkpoint MySQL Task 2 wajib PASS setelah perubahan ledger/expiry dan sebelum integrasi Flutter final Task 7**; perubahan ledger berikutnya mengharuskan rerun checkpoint sebelum menerima integrasi final. Task 7 juga memakai artifacts Task 5/6. Task 9 tetap final regression dan gate merchant; tidak ada task yang dipindah atau dinomori ulang. Tidak ada global rename route/state untuk memudahkan FE.

Nama file **existing** di bawah berasal audit. Nama file bertanda **create/proposed** adalah usulan baru untuk implementasi, bukan klaim file sudah ada. Nama migration timestamp dihasilkan Artisan saat eksekusi, bukan ditebak pada audit.

## Task 0 — Isolasi dan checkpoint aman sebelum kode

**Owner:** root. **Inputs:** arahan implementasi eksplisit dan kedua checkout §Global Constraints. **Outputs:** workspace terisolasi atau keputusan existing workspace yang dicatat; source/PRD/dirty baseline baru.

- [ ] Periksa ulang kedua remote/branch/HEAD/status; preserve PRD untracked dan dokumen audit/plan. Jangan stash/reset/copy seluruh working tree secara otomatis.
- [ ] Baca dan jalankan `using-git-worktrees` sesuai izin yang sudah diberikan; cek git-dir/common-dir/submodule sebelum membuat worktree. Pakai native worktree tool bila tersedia. Tidak ada worktree dibuat saat audit ini; kebutuhan consent/directory rule diselesaikan saat execution. Jangan commit `.gitignore` tanpa izin hanya demi mengikuti default skill.
- [ ] Pastikan PRD dan rencana tersedia pada worktree yang dipakai, dependencies existing dapat digunakan tanpa install baru, dan DB test benar-benar disposable. Jangan menjalankan `composer setup` karena berisi migrate/install/key generation.
- [ ] Re-run baseline payment subset pada SQLite memory; catat current FE lint issue `payment_deadline.dart:2` secara terpisah. Jangan menganggap historical 178 tests atau audit 201 tests otomatis berlaku setelah HEAD berubah.

## Task 1 — Invoice canonical sebelum pembayaran dan contract additive

**Owner:** backend engineer; root review FE compatibility. **Gaps:** G01–G03, G15–G16, G34.

**Existing files:** `app/Services/BookingDocumentService.php`, `BookingService.php`, `PaymentService.php`, `DokuPaymentService.php`, `PaymentGateway.php`; `app/Http/Requests/DokuPaymentRequest.php`; `app/Http/Resources/BookingDocumentResource.php`; `tests/Feature/BookingDocumentsTest.php`, `DokuPaymentTest.php`, `PaymentFoundationTest.php`.

**Interfaces:** retain `PaymentService::methods(User,string): array`, `show(User,string): array`, `DokuPaymentService::initiate(User,string,array): array`, `response(object,object,object): array`. Proposed `BookingDocumentService::ensureInvoice(object $booking): object`: create-or-return existing unique invoice row with stable number and no PDF/network I/O. Call within approved booking transaction; also repair old payable booking on first explicit create transaction, never create payment from GET.

**Contract decisions:** existing booking snapshot remains price authority. Ensure invoice row before provider attempt and return `invoice_number`, `booking_number`, `quantity`, `unit_price`, `subtotal`, `service_fee=0`, `total_amount`, `paid_at` nullable, `applied_attempt_guid` nullable untuk success binding, `can_create`, `can_switch`, `switch_reason`, `receipt_available` additively. Preserve existing `amount`, `status`, nested attempt and top-level booking `expires_at`. Pending/unknown previous attempt gives `can_create=false, can_switch=false`; local expiry alone does not change that. Keep three-family legacy response shape; v3 client selects only BNI/QRIS. Make nested channel availability reflect booking/family eligibility too.

- [ ] RED in existing tests: awaiting_payment create has exactly one invoice with stable number across keys/retries; old invoice number unchanged; amount snapshot unchanged after destination price edit; no PDF in reservation lock. Assert `merchant_id`, `expiry`, `fee`, `total_amount`, `invoice_id`, `booking_guid`, `paid`, and existing prohibited fields return 422 when injected. No provider call/no attempt on invalid input.
- [ ] Run `php artisan test --compact tests/Feature/BookingDocumentsTest.php tests/Feature/DokuPaymentTest.php tests/Feature/PaymentFoundationTest.php`; inspect expected new-assertion failures rather than setup errors.
- [ ] GREEN implement helper and additive fields. Existing invoices already allow nullable `pdf_path`; separate issued invoice identity from PDF availability. Add `download_available` metadata so clients do not offer an unready file. Generate invoice PDF for awaiting_payment as well as confirmed through document queue, and regenerate missing PDF when an invoice row already exists; create group-ticket artifacts only under Task 6 rules. No duplicate invoice table or fee engine.
- [ ] Re-run above; assert owner 404/admin destination scope, no side effects from read-only methods/intent, no change to old DTO meanings; Pint and diff review.

## Task 2 — Expiry attempt 30 menit dan single-attempt safeguards

**Owner:** backend engineer. **Gaps:** G06–G08, G14–G16, G32. **Depends:** Task 1.

**Existing files:** `app/Services/DokuPaymentService.php`, `app/Services/Doku/DokuClient.php`, `DokuQrisAdapter.php`, `PaymentService.php`; `tests/Feature/DokuPaymentTest.php`, `DokuClientTest.php`, `DokuQrisTest.php`; `tests/Mysql/DokuPaymentConcurrencyTest.php`, `tests/Mysql/PaymentFoundationConcurrencyTest.php` untuk checkpoint awal ini dan rerun Task 9.

**Interfaces:** keep `initiate`, `saveInitiation`, `capture` signatures. Existing helper persis adalah `App\Support\BookingRules::visitStart(string $visitDate, string $timezone): CarbonImmutable` (`app/Support/BookingRules.php:23-25`). Pada locked booking, panggil `BookingRules::visitStart($booking->visit_date, $booking->destination_timezone)` dan hitung persisted `instruction_expires_at` sekali saat reservation sebagai `min(started_at+30 minutes, booking.payment_expires_at, hasil visitStart)`. `destination_timezone` tersebut diselesaikan dari snapshot, dengan fallback ke destination bila null (`TicketService.php:67-68`, dipakai `DokuPaymentService::lockedBooking():167-175`). Return helper adalah midnight lokal tanggal kunjungan dikonversi UTC, bukan jam `opening_hours` atau field visit-start baru. Create/approval/quota existing masih mengambil timezone destination langsung; jangan diam-diam mengganti policy/callers itu di task expiry. Preserve intent expiry=booking expiry. Provider response may only shorten effective attempt expiry. Provider-supported minimum/maximum expiry comes from Task 3/4 evidence; if remaining window cannot be honored, return stable unavailable reason before HTTP. Do not add guessed provider minimum.

- [ ] RED: booking deadline +60m yields attempt +30m; +12m deadline yields +12m only when provider contract permits; earlier provider expiry wins; reopening/replay never resets expiry. Invalid/too-short provider window sends no create request.
- [ ] RED: concurrent/new-key/switch request with old initiating/pending/unknown returns old attempt; expiry-time alone never allows second issuance. Callback at expiry is reviewed; before expiry can apply within existing grace; provider-paid after expiry remains unapplied/review.
- [ ] Run `php artisan test --compact tests/Feature/DokuPaymentTest.php tests/Feature/DokuClientTest.php tests/Feature/DokuQrisTest.php` and confirm RED.
- [ ] GREEN change shared reservation/save validation once; retain reserve→commit→HTTP→save lock boundary, quota holds and late-evidence logic. Keep `can_switch=false` until Task 3/4 proves prior instruction terminal or verified cancellation.
- [ ] Re-run the same tests and Pint; kemudian jalankan **checkpoint disposable MySQL ledger/expiry** sebelum integrasi Flutter final. Prasyarat: eksekusi test isolasi disetujui pada tahap implementasi, dedicated test connection, database acak kosong milik test dengan allowlist `pami_doku_<12 alnum>_test` / `pami_phase1b_<12 alnum>_test`, dan bukti tidak menunjuk schema aplikasi/shared. Review helper kedua file: actual CREATE/migrate/DROP terjadi di database disposable; bukan `--pretend`, dan bukan izin migrasi DB aplikasi.
- [ ] Run checkpoint: `php vendor/bin/pest --configuration=phpunit.mysql.xml tests/Mysql/PaymentFoundationConcurrencyTest.php tests/Mysql/DokuPaymentConcurrencyTest.php --colors=never`. Extend focused assertions untuk parallel create/new-key/switch, unknown attempt tetap terkunci, single invoice/intent/provider reference/event, callback-versus-expiry termasuk exact boundary dan timely late-delivery. Catat engine/version, schema isolasi, command, counts dan exit. **PASS** hanya bila semua required cases benar-benar dieksekusi/lulus dan cleanup terverifikasi; skip, MySQL tidak tersedia/izin belum ada, atau failure berarti gate integrasi final **BLOCKED**. SQLite PASS tidak membuka gate. UI fixture mandiri boleh lanjut tanpa klaim integrasi final. Task 9 mengulang checks ini setelah perubahan selanjutnya.

## Task 3 — BNI terminal recovery, merchant gate, launch restriction dan diagnostics

**Owner:** backend engineer; security reviewer read-only. **Gaps:** G08–G09, G11–G13, G27–G29. **Depends:** Task 2. **External gate:** terminal status/occurrence timestamp/recovery/minimum expiry contract and merchant Sandbox evidence. Unknown without a VA remains locked if provider cannot safely locate it.

**Existing files:** `app/Services/Doku/DokuClient.php`, `DokuChannelRegistry.php`, `DokuExchangeSanitizer.php`, `app/Services/DokuPaymentService.php`, `config/doku.php`; `tests/Feature/DokuClientTest.php`, `DokuChannelRegistryTest.php`, `DokuPaymentTest.php`. Update historical runbook/matrix only to document approved new behavior and actual evidence.

**Interfaces:** retain `DokuClient::create(object,object): array`, `status(object): array`, `verifyNotification(Request): array`, `DokuPaymentService::receive(array): string`. Existing normalized outcome keys: status, merchant/provider references, amount, currency, occurred_at, event_key, payload_hash. Populate terminal values only from verified channel semantics; do not copy ewallet status numbers into VA.

- [ ] Acquire official BNI terminal query and sanitized signed merchant fixtures, including occurrence time and no-VA recovery outcome. If unavailable, mark task provider portion BLOCKED and keep unknown/review; do not replace with a success fixture assumption.
- [ ] RED: Pending response with paidAmount does not settle; valid terminal capture applies once; failed/expired only become retry-safe when officially terminal; mismatched amount/time/ref/environment quarantined. Missing VA never creates anew. Test disable issuance still accepts existing callback/inquiry.
- [ ] RED: BNI config+allowlist alone is insufficient; require explicit existing verified-channel onboarding gate for BNI too. Restrict new issuance to `va_bni`/`qris` even if legacy wallet/bank flags are set, while legacy attempts still receive status/callback recovery. Production approval remains separate. Do not remove registry profiles/history.
- [ ] RED diagnostics: sentinel token/secret/signature, VA/customer number, email, QR content and arbitrary response prose never enter logs; oversized/invalid body has bounded metadata only. Put shared transport assertions in `DokuClientTest.php`, not new wallet functionality.
- [ ] Run `php artisan test --compact tests/Feature/DokuClientTest.php tests/Feature/DokuChannelRegistryTest.php tests/Feature/DokuPaymentTest.php`; implement GREEN only documented terminal mapping and bounded diagnostic allowlist; rerun/Pint/security review. Merchant proof is retained separately from config flags and fixture PASS.

## Task 4 — QRIS settlement dan verified cancellation

**Owner:** backend engineer; root freezes FE DTO. **Gaps:** G10–G11, G16. **Depends:** Tasks 1–3 shared contracts. **External gate:** QRIS notification signature/body, terminal query status values/payment occurrence time, window limits, cancel/refund entitlements from DOKU/merchant.

**Existing files:** `app/Services/Doku/DokuQrisAdapter.php`, `DokuClient.php`, `DokuChannelRegistry.php`, `app/Services/DokuPaymentService.php`, `routes/api.php`, `config/doku.php`, `tests/Feature/DokuQrisTest.php`, `DokuPaymentTest.php`.
**Create/proposed:** `app/Http/Controllers/Api/V1/DokuQrisWebhookController.php` only when signed notification contract is available; generate with Artisan.

**Interfaces proposed:** adapter `status(DokuClient $client, object $attempt): array`, `cancel(DokuClient $client, object $attempt): array`, `verifyNotification(DokuClient $client, Request $request): array` matching normalized service outcome. Preserve create instruction `presentation_type=qr_payload`, `qr_content`; add `qr_payload` alias if needed, never silently rename. Provider HTTP remains outside quota/booking locks. Keep `settles=false` until required protocol pieces and verification are complete.

- [ ] Pin exact provider statuses/timestamps/signature/cancel outcome from evidence. Official page documents generate/query/refund/cancel paths; it does not establish merchant entitlement or safe terminal mapping by itself. No guessed code/body fields are authorized by this plan.
- [ ] RED in existing tests: fixed IDR amount/provider payload, exact merchant ref, provider validity response validated, signed capture durable before ACK, invalid signature/mismatch/replay, timeout unknown. Add query terminal and cancel-vs-paid race: paid fact wins, cancellation uncertainty blocks switching; expired/cancelled confirmed makes retry possible only if booking still eligible.
- [ ] Run `php artisan test --compact tests/Feature/DokuQrisTest.php tests/Feature/DokuPaymentTest.php tests/Feature/DokuChannelRegistryTest.php`; confirm new tests fail for missing functionality.
- [ ] GREEN wire existing adapter/transport/receive/apply, additive `POST /api/v1/webhooks/doku/qris`, owner-only cancellation action only if proven supported; return `can_switch` evidence consistently. Normalize cancelled through a reviewed compatibility mapping rather than writing invalid enum values; use existing failed/expired only if semantics match, otherwise additive schema/transition changes must be reviewed first.
- [ ] Rerun/Pint; keep issuance disabled before real merchant signed Sandbox E2E Task 9. Refund initiation is Task 8, not assumed from availability of a documented endpoint.

## Task 5 — Paid receipt dan payment email melalui outbox

**Owner:** backend engineer. **Gaps:** G22–G25. **Depends:** Task 1 contract and verified-capture boundary.

**Existing files:** `app/Services/BookingDocumentService.php`, `BookingNotificationService.php`, `DokuPaymentService.php`; `app/Jobs/DeliverBookingNotification.php`; `app/Http/Resources/BookingDocumentResource.php`; `app/Http/Controllers/Api/V1/BookingDocumentController.php`; `routes/api.php`; `tests/Feature/BookingDocumentsTest.php`, `BookingNotificationTest.php`, `DokuPaymentTest.php`.

**Interfaces:** extend existing owner/admin `download(User,string,string): StreamedResponse` to additive type `receipt`; existing invoice retains its identity and purpose. Generate receipt from persisted invoice+verified applied attempt/event+booking snapshots; do not trust cached Flutter. Reuse `BookingNotificationService::record(string,string,string): void` business key and durable delivery rows. Add structured receipt data/rendering without sending SMTP inside callback/settlement transaction.

**Email delivery contract:**

- Payment-receipt event dan ticket-issued event mempunyai **event key berbeda, stabil dan diturunkan dari fakta server**: applied verified payment/attempt untuk receipt, entitlement issuance yang sama untuk tiket. Duplicate callback, replay dan job retry memakai identitas bisnis yang sama; jangan memakai job UUID, waktu retry atau message ID baru sebagai dedupe key. Pertahankan existing unique `(user_guid,event_key)` dan `(notification_guid,channel,target_key)` (`2026_10_30_000000_create_notification_tables.php:21,43-45`). Perluas `record` untuk event baru dengan key domain yang tepat; jangan menambah email generic paid kedua di samping receipt untuk pembayaran yang sama.
- Catat notification/outbox atomik bersama paid application atau QR issuance, lalu dispatch setelah commit dengan existing dispatcher sebagai recovery. Duplicate event tidak menambah delivery; retry mengolah delivery yang sama, tanpa reapply payment atau rotate QR. DB rollback tidak boleh meninggalkan email job yang mengklaim paid.
- Reuse durable claim dan retry existing: stale processing 15 menit, exponential retry mulai 60 detik dan maksimum 3.600 detik (`DeliverBookingNotification.php:27-31,94-100`), scheduler `notifications:dispatch`. Claim/in-flight harus mencegah dua worker mengirim delivery yang sama secara bersamaan, termasuk slow SMTP/stale claim. Kegagalan mail tidak mengubah paid/ticket state; retry tidak menyusun business event baru.
- Delivery audit mengaitkan event key, notification/delivery GUID, attempt count dan hasil setiap percobaan, waktu claim/submission/transport acceptance/failure, next retry, sanitized error, serta provider message reference bila tersedia. Existing scalar status/counter/next-attempt/error fields dipakai ulang; kebutuhan histori/waktu/correlation yang belum tersimpan harus dipenuhi secara additive dalam domain delivery, bukan dengan raw body/token/email PII di log. Periksa ulang active owner dan verified recipient saat delivery; metadata audit tidak menjadi izin kirim ulang otomatis ke alamat baru yang belum diverifikasi.
- **Tepat satu business event/delivery row dapat diuji; SMTP biasa tidak menjamin exactly-once email fisik.** Crash setelah SMTP menerima email sebelum DB menandai sent menyisakan outcome ambigu; retry dapat mengirim duplikat. `sent`/SMTP accepted berarti diterima transport, bukan pasti tiba di inbox. Catat ambiguity pada audit; jangan mengklaim delivered atau mengganti status paid. Gunakan idempotency/delivery reconciliation hanya bila transport mendukung dan sudah dibuktikan. Bila acceptance mensyaratkan nol duplikat fisik, gate transport tetap **BLOCKED** sampai ada bukti atau penerimaan eksplisit batas at-least-once; local business-idempotency tetap dapat PASS sendiri.

- [ ] RED: pending/unknown/unapplied capture cannot download paid receipt. Verified paid receipt includes invoice+booking/destination/date/quantity/exact total/currency/method/provider-safe ref/paid_at with timezone; owner/admin scope enforced; reversal shows truthful adjustment history, not fresh unqualified paid proof.
- [ ] RED: paid H-6 creates one payment-receipt event/delivery and no QR attachment; duplicate callback/job/replay shares the same event/target keys. SMTP failure leaves paid intact and retries the same delivery with incremented attempt/audit and bounded backoff; atomic paid/outbox rollback, parallel-worker claim and stale/slow SMTP behavior are covered. Sandbox E2E can deliver a prominently Sandbox/TEST_ONLY email to the authorized account without enabling DEMO/Production confusion.
- [ ] RED: changed/unverified/disabled recipient at delivery is held/cancelled according to account policy; never send to arbitrary visitor_email. Inject crash-after-SMTP-accept-before-DB-sent: preserve ambiguity/evidence and demonstrate the ordinary SMTP duplicate window without falsely asserting exactly-once. Test idempotent transport/reconciliation only when actually supported. Report local event-idempotency result separately from unresolved physical-delivery guarantee.
- [ ] Run `php artisan test --compact tests/Feature/BookingDocumentsTest.php tests/Feature/BookingNotificationTest.php tests/Feature/DokuPaymentTest.php`; implement GREEN using current outbox/queue and account ownership. Invoice PDF can be asynchronous; receipt download is owner-protected and never a public link with secrets.
- [ ] Re-run/Pint. No schema change is required solely to generate an on-demand receipt from existing immutable ledger; add persistence only if a concrete immutable field is missing and approved.

## Task 6 — Idempotent immediate QR job, H-5 email, legacy ticket reconciliation

**Owner:** backend engineer. **Gaps:** G17–G21, G24–G25. **Depends:** Tasks 1/5.

**Existing files:** `app/Services/TicketService.php`, `DokuPaymentService.php`, `BookingDocumentService.php`, `BookingNotificationService.php`; `app/Console/Commands/IssueEligibleTicketQr.php`; `routes/console.php`; `tests/Feature/SmartTicketSmokeTest.php`, `DokuPaymentTest.php`, `BookingDocumentsTest.php`, `BookingNotificationTest.php`.
**Create/proposed:** `app/Jobs/IssueBookingTicketQr.php` via Artisan, constructor `__construct(public string $bookingGuid)`, `handle(TicketService $tickets): void`, unique booking job key. Existing scheduler remains recovery path.

**Interfaces:** retain `TicketService::issue(string): array`, `state(object): string`, `issueDue(): int`. After capture commits, queue the idempotent issue job when eligible; H-6 state remains scheduled. Emit ticket-issued business event in issuance transaction only once when valid QR units become ready; outbox delivery can retry without QR rotation. No new visitor model/PII/table is needed.

- [ ] RED with **real verified ledger**, not only mocked PaymentEligibility: H-6 paid has quantity entitlements but zero QR and only payment email; local H-5 boundary issues exactly quantity QR and one ticket-email event; H-4 paid enqueues immediate issuance; repeated scheduler/job/GET/callback keeps same tokens/events. Test Asia/Jakarta midnight against UTC.
- [ ] RED: day-H issuance/access for previously eligible paid booking follows existing rules; new day-H create/pay remains rejected by current cutoff. Record that rejection only as compatibility coverage for **PD-HDAY-01**, not proof the PRD day-H purchase target is fulfilled; separate policy approval is required to change it. Invalid/corrupt/revoked/checked-in tokens are not regenerated. QRIS payload never accepted by entry scanner.
- [ ] RED legacy artifact: confirmed H-6 metadata/download must not present old group token as an entry ticket. Preserve legacy rows/history, suppress misleading admission access and serve modern ticket availability. If generating a downloadable entry-ticket PDF, build from valid per-unit QR only when eligible; owner/download must recheck eligibility and adjustment, including files already on disk.
- [ ] Run `php artisan test --compact tests/Feature/SmartTicketSmokeTest.php tests/Feature/DokuPaymentTest.php tests/Feature/BookingDocumentsTest.php tests/Feature/BookingNotificationTest.php`; implement GREEN retaining quota→booking→ticket lock order and existing encrypted WDR1 scheme. Keep PDF rendering/file IO outside those locks.
- [ ] RED/GREEN: ticket-issued event has its own stable key and is atomic with issuance; repeated scheduler/job/GET/callback makes one ticket business delivery, separate from payment receipt. Apply Task 5 retry/audit/SMTP limitations unchanged. Re-run/Pint. Do not delete legacy token/ticket records or change scanner admission semantics to make documents convenient.

## Task 7 — Flutter BNI/QRIS native checkout, recovery, success dan export

**Owner:** root; BE QR image endpoint serialized with backend engineer. **Gaps:** G30–G34. **Depends:** contract Task 1/2; receipt/ticket Task 5/6; **checkpoint disposable MySQL ledger/expiry Task 2 PASS atas revisi backend yang diintegrasikan** sebelum integrasi Flutter final diterima. Provider-disabled states dan UI fixture mandiri boleh dikembangkan saat provider gate BLOCKED, tanpa klaim final integration/UAT.

**FE existing files:** `lib/data/payment_models.dart`, `business_repository.dart`, `booking_state.dart`; `lib/widgets/booking_payment_section.dart`, `payment_deadline.dart`, `booking_ticket_qr_section.dart`; `lib/screens/bukti_pendaftaran_screen.dart`, `beranda_user_screen.dart`; `lib/theme/app_colors.dart`, `app_icons.dart`; tests `demo_payment_disabled_test.dart`, `home_dashboard_test.dart`, `smart_ticket_test.dart`, `ewallet_return_test.dart` (legacy preservation).
**FE create/proposed:** `lib/data/booking_payment_state.dart`, `lib/screens/booking_payment_screen.dart`, `lib/services/payment_qr_export.dart`, `test/booking_payment_test.dart`. Keep legacy wallet return state/routes callable for already in-flight payments; new v3 checkout never offers wallet creation.

**Proposed new FE method:** `BusinessRepository.initiatePayment(String guid,String key,{required String methodCode,required String channel}) -> Future<Json>` sends method/channel and header only; method ini **belum ada di HEAD**. Preserve existing `initiateEwalletPayment(String guid,String key,String channel) -> Future<Json>` untuk legacy callers. New `BookingPaymentState` follows existing session-generation/secure operation/read-before-write lifecycle pattern, without forwarding BNI/QRIS into wallet-only EwalletPointer. `PaymentSnapshot` gains nullable invoice/paid/attempt-expiry getters and explicit payable BNI/QRIS selection; never coerce missing amount/time to zero.

**QR rendering/export decision:** avoid a new QR package. BE already has BaconQrCode and FE `flutter_svg`. Add **proposed, belum existing** owner-only `GET /api/v1/bookings/{booking}/payment-attempts/{attempt}/qr` returning private no-store SVG of **persisted provider QRIS payload**, with active/expiry/owner/attempt binding; proposed `DokuPaymentService::qrisSvg(User,string,string): string` and controller action. Reuse renderer pattern in `TicketService.php:130`, not entry credentials. FE can rasterize the rendered SVG to PNG for export. Android export uses platform save/share intents through a small dedicated MethodChannel, scoped temporary image URI/FileProvider, no broad storage permission; this is proposed work, current map MethodChannel is unrelated.

**Polling contract v3 (proposed behavior):** selama halaman pembayaran masih route aktif/terlihat, app resumed, sesi valid dan attempt yang sama masih pending, polling harus terus berjalan terkendali; **tidak ada cutoff permanen tiga menit**. Gunakan satu per-attempt in-flight/cooldown gate untuk polling, tombol manual dan resume: maksimal satu request bersamaan, interval awal minimal 10 detik, dengan backoff/jitter hingga 60 detik untuk pending lama/error dan hormati `Retry-After`/429 serta offline pause/recovery. Request error/429 tetap menutup cooldown agar tidak terjadi retry storm; manual check tidak mem-bypass gate atau memulai create. Backend existing memberi cooldown inquiry BNI/QRIS 10 detik dan refresh route 20 request/menit (`DokuPaymentService.php:24,398-411`, `routes/api.php:42-43`); batas server lebih ketat selalu menang. Gunakan existing `refreshPaymentAttempt` POST yang sudah mengembalikan latest snapshot; GET intent untuk recovery adalah read-only, bukan create/query DOKU langsung. Pada initiating/unknown, lanjutkan pengecekan terkontrol untuk recovery yang sama, tanpa reissue; needs_review tetap tampil dan hanya dipantau melalui read-only snapshot, bukan loop provider refresh yang tidak menyelesaikan review. Stop/pause saat route tertutup/tertutup route lain, background, dispose, session berubah atau provider terminal terverifikasi; resume route/app mengambil snapshot terkini lalu melanjutkan bila masih unresolved. Local timer nol hanya meminta refresh melalui gate, bukan menandai terminal. Legacy wallet state 5s/3m adalah pola lifecycle yang boleh dipelajari, **bukan kebijakan polling untuk BNI/QRIS**.

**Navigation/status contract:** failed, expired, unknown, needs_review/review dan tawaran retry tetap inline di halaman pembayaran yang sama, dengan alasan dan CTA server-authoritative. Retry hanya aksi eksplisit dengan `can_create=true` dari backend setelah previous attempt aman terminal; busy/unknown/review atau local expiry tidak mengizinkannya. **Hanya verified paid** dengan booking confirmed, payment eligibility paid, intent paid dan applied attempt paid yang cocok booking/attempt/provider/environment boleh membuka success screen. Redirect, create 200, QR tampil, timer nol dan klaim client tidak pernah cukup. Status refund/reversal setelah paid memperbarui state authoritative; tidak mempertahankan success seolah paid tetap berlaku.

**Additional owned files for Android export:** existing `android/app/src/main/kotlin/com/example/wisata_daerahku/MainActivity.kt`, `android/app/src/main/AndroidManifest.xml`; proposed `android/app/src/main/res/xml/payment_qr_paths.xml`. Other platform targets must be inspected before claiming parity; implement their platform save/share capability if release target includes them, otherwise show truthful unavailable state.

- [ ] RED in new `booking_payment_test.dart`: screen open/back makes zero create calls; two radio sections VA→BNI and QRIS only; unavailable reason shown; no amount input. CTA exactly once with persisted key; timeout/restart/restoration never duplicates create; active old attempt blocks switching.
- [ ] RED: VA copy displays server number/instructions; QR render/save/share uses same provider payload; export denied/cancelled shows correct state. Countdown uses `last_attempt.expires_at`, survives reopen, zero refreshes without fake expired. Advance fake clock melewati tiga menit sementara halaman aktif+pending dan assert polling tetap berlanjut; assert >=10s shared cooldown, no overlap, backoff, manual/resume coalescing, 429/Retry-After, offline recovery, route-cover/background pause, foreground resume dan dispose/session stop. Timer elapsed/manual tidak membuat request tambahan di luar gate atau create baru.
- [ ] RED: assert failed, expired, unknown, needs_review/review dan retry tidak pernah menavigasi success dan tetap dirender pada payment page; local timer zero dan redirect hint juga tidak membuka success. Retry memerlukan explicit CTA + fresh `can_create` dan aman dari double-tap. Verified paid consistency membuka success sekali dengan authoritative invoice/method/paid_at/total; receipt reuse authenticated binary/document flow. H-6 success has scheduled-ticket date, H-4 shows pending issuance then per-visitor QR; home/history reopen same attempt, adjustment tidak disembunyikan.
- [ ] Run `flutter test --no-pub --reporter expanded test/booking_payment_test.dart`; confirm RED, then implement minimal new checkout using existing screen shell/colors/icons. Change payment entry/copy only; no redesign unrelated history/home. Keep current DEMO route/gates and legacy return behavior.
- [ ] GREEN backend QR-image tests in `DokuQrisTest.php` assert owner isolation, expired/reversed/other-method rejection, no payload leak in logs, and generated SVG encodes provider payload. Test Flutter native channel mocks, then real Android save/share/resume after full rebuild/reinstall; hot reload cannot validate Kotlin handlers.
- [ ] Run new tests plus the four baseline FE files; run targeted analyze on changed files, distinguish pre-existing `payment_deadline.dart:2` info from introduced issues. Visually inspect 320/393/430px, large text, semantics/focus/tap targets, loading/long VA/error/offline/permission states, and separate payment/entry QR labels. No broad suite unless explicitly authorized for final QA scope.

## Task 8 — Refund request/admin audit dan truthful adjustment

**Owner:** backend engineer, root FE integration, architect/security read-only. **Gaps:** G26–G27. **Depends:** ledger/receipt/ticket tasks. **External gate:** actual full-refund capability/policy for channel; BNI automatic refund not assumed. No partial refund is introduced.

**Existing files:** `routes/api.php`, `app/Services/DokuPaymentService.php`, `PaymentEligibility.php`, `FinancialAnalyticsService.php`, `TicketCheckInService.php`, `app/Support/PaymentStates.php`; tests `DokuPaymentTest.php`, `FinancialAnalyticsTest.php`, `SmartTicketSmokeTest.php`.
**Create/proposed:** `app/Services/BookingRefundService.php`; `app/Http/Controllers/Api/V1/BookingRefundController.php`; request classes for owner request/admin decision; `tests/Feature/BookingRefundTest.php`; one additive migration generated via `php artisan make:migration create_booking_refund_requests_table --no-interaction` (generated timestamp recorded at execution).

**Minimal workflow:** one full-refund request lifecycle per eligible paid booking; store actor/reason/request/decision timestamps and audit, provider correlation and immutable payment reference. Workflow states proposed `requested,rejected,approved,manual_action_required,refund_pending,refunded,needs_review`. These belong to refund workflow, not arbitrary new payment-attempt enum values. **Proposed routes, belum existing:** owner `POST /api/v1/bookings/{booking}/refund-requests`; scoped admin `POST /api/v1/admin/bookings/{booking}/refund-requests/{refund}/decision`; owner status additive to booking/payment contract. Service proposed `request(User,string,array): array`, `decide(User,string,string,array): array`; no `forcePaid/forceRefunded` action.

- [ ] RED: non-owner/assigned-admin boundary, duplicate request/key idempotency, used-ticket rejection under quota→booking→ticket locks, amount not client-controlled, approval alone never marks refunded. Unsupported/unverified provider path yields `manual_action_required` with audit and truthful customer status; unknown provider initiation remains refund_pending/review without retries causing duplicate refund.
- [ ] RED: verified full adjustment preserves paid evidence/history, revokes unused QR and updates receipt/finance once; refund/check-in race cannot both bypass usage policy. Partial provider amount is reviewed, not invented full refund. If existing ticket policy cannot decide an edge, hold review rather than auto-refund used admission.
- [ ] Run `php artisan test --compact tests/Feature/BookingRefundTest.php tests/Feature/DokuPaymentTest.php tests/Feature/FinancialAnalyticsTest.php tests/Feature/SmartTicketSmokeTest.php`; confirm RED.
- [ ] GREEN implement request/review/manual fallback first. Add provider refund transport only after evidence gate; its outcome enters existing verified event application, never a UI/admin claimed settlement. No manual process may mark refunded without independently verified payment evidence. Add FE request/status to existing booking detail and extend `booking_payment_test.dart` for request/admin-pending/manual-action/refunded display.
- [ ] Re-run/Pint; migration pretend on disposable matching MySQL and compatibility tests in Task 9. Provider entitlement blocked means auto-refund milestone stays BLOCKED, even if manual workflow tests pass.

## Task 9 — Review, isolated MySQL, merchant Sandbox E2E dan handoff

**Owner:** root. One existing subagent slot may be replaced by QA; maximum three. **Depends:** previous task results and explicit QA scope. **Existing files:** `tests/Mysql/DokuPaymentConcurrencyTest.php`, `PaymentFoundationConcurrencyTest.php`, `phpunit.mysql.xml`, tests changed above; `docs/prd/DOKU-RUNBOOK.md`, `DOKU-CHANNEL-MATRIX.md`. No release action is implied.

- [ ] Read `requesting-code-review`; have independent architect/security examine full connected callers, enums, locks and contracts, not only diff. Read `receiving-code-review` before implementing feedback. Address concrete findings; run affected tests again. This is future code review, not an assertion that audit already cleared release.
- [ ] Prove disposable MySQL isolation before any schema write: dedicated test connection, generated allowlisted database, no user/shared data. Review existing helper: `DokuPaymentConcurrencyTest.php:17-27` creates/drops its own random DB and runs actual migration internally. Execute only when the approved next-stage test scope permits that; do not treat current pretend-only authorization as approval for an app migration.
- [ ] **Rerun final**, bukan first MySQL checkpoint: `php vendor/bin/pest --configuration=phpunit.mysql.xml tests/Mysql/PaymentFoundationConcurrencyTest.php tests/Mysql/DokuPaymentConcurrencyTest.php --colors=never`; add/run focused refund-vs-check-in dan receipt/QR race coverage setelah perubahan Task 5/6/8 dengan isolation safeguards yang sama. Task 2 PASS adalah prerequisite integrasi Flutter final, bukan alasan melewati rerun ini. Require single provider issuance/history/event/QR, no overbook, no double-refund. Document SQL engine/version, counts, skips dan actual exits.
- [ ] Migration validation: additive generated migrations only; inspect `--pretend` against representative disposable MySQL schema, then authorized isolated apply with seeded legacy paid/events/tickets and assert identity/history survives. No `migrate:fresh`, forced application migration or destructive rollback on user DB. SQLite pretend exit 0 from audit does not establish MySQL SQL validity.
- [ ] Run relevant backend feature suites, FE payment tests/analyze and device native export/lifecycle/scanner checks; explicitly record PASS/FAIL/NOT RUN. Resolve one pre-existing unnecessary-import lint info only if included in authorized changes; do not hide analyzer exit 1.
- [ ] In a dedicated merchant Sandbox environment, obtain independent BNI **and** QRIS create→provider paid→signed callback/verified inquiry→booking paid→receipt email→H-5 ticket/email evidence. Test duplicate callback, missing callback recovery, timeout/no reissue, expiry and late payment, disabled issuance with in-flight recovery, cancellation race and verified/manual refund. Record CODE_COMPLETE, PROVIDER_BLOCKED, SANDBOX_UAT_PASSED dan PRODUCTION_READY sebagai dimensi terpisah per channel/environment sesuai definisi di atas. No Production money movement. Channel remains unavailable without its own signed E2E evidence; lulus BNI tidak membuat QRIS lulus atau sebaliknya.
- [ ] Record environment names only, sanitized attempt/event references and occurrence times, worker/scheduler execution, notification outcome, QR counts and receipt artifacts. Raw signatures/tokens/callback PII and real credentials stay out of repo/chat. Dashboard ACTIVE/config flag or faked callback is not UAT.
- [ ] Read `verification-before-completion`; rerun necessary checks after final edits, inspect both dirty trees and produce truthful diff/migration/API summary. Read `finishing-a-development-branch` only when implementation/tests are actually finished; present integration choices for the actual remotes, without committing/pushing/merging/deploying automatically.

## Verifikasi existing methods dan API pada HEAD

Verifikasi dokumentasi 9 November 2026: BE HEAD `de3cbf8a2989970f20587b89fc9b9ef017aa546f`, FE HEAD `c75bd7b7d8e4f5d14b45b3ea9df6367607b7f38b`; tidak ada source diff pada kedua checkout. `php artisan route:list --path=api --except-vendor --json --no-ansi` dijalankan read-only dengan process environment testing/SQLite memory; definitions dan middleware dicocokkan dengan `routes/api.php`, bukan diasumsikan dari PRD. Tidak ada migrasi/test/provider call dijalankan untuk revisi ini.

Pada signature BE, `User` adalah `App\Models\User`, `Request` adalah `Illuminate\Http\Request`, `CarbonImmutable` adalah `Carbon\CarbonImmutable`, dan `StreamedResponse` adalah `Symfony\Component\HttpFoundation\StreamedResponse`.

| Method existing yang dipakai rencana | Source HEAD |
| --- | --- |
| `PaymentService::methods(User $owner, string $guid): array`; `show(User $owner, string $guid): array` | `app/Services/PaymentService.php:27,60` |
| `DokuPaymentService::initiate(User $owner, string $guid, array $input): array` | `app/Services/DokuPaymentService.php:29` |
| private `DokuPaymentService::saveInitiation(object $attempt, object $booking, array $result): array` | `app/Services/DokuPaymentService.php:120` |
| private `DokuPaymentService::lockedBooking(string $guid, string $quota): object` | `app/Services/DokuPaymentService.php:167` |
| `DokuPaymentService::response(object $booking, object $intent, object $attempt): array`; `receive(array $outcome): string` | `app/Services/DokuPaymentService.php:207,232` |
| private `DokuPaymentService::capture(object $booking, object $intent, object $attempt, object $event, array $outcome): void` | `app/Services/DokuPaymentService.php:349` |
| `DokuPaymentService::refresh(User $owner, string $guid, string $attemptGuid): void`; `reconcile(string $guid): void` | `app/Services/DokuPaymentService.php:398,418` |
| `DokuClient::create(object $attempt, object $booking): array`; `status(object $attempt): array`; `verifyNotification(Request $request): array` | `app/Services/Doku/DokuClient.php:113,178,232` |
| `DokuQrisAdapter::create(DokuClient $client, object $attempt, object $booking): array` | `app/Services/Doku/DokuQrisAdapter.php:22`; adapter saat ini hanya mempunyai create |
| `BookingDocumentService::download(User $user, string $guid, string $type): StreamedResponse` | `app/Services/BookingDocumentService.php:89`; type existing invoice/ticket |
| `BookingNotificationService::record(string $bookingGuid, string $ownerGuid, string $event): void` | `app/Services/BookingNotificationService.php:10` |
| `TicketService::booking(string $guid, bool $lock = false): object`; `issue(string $guid): array`; `state(object $booking): string`; `issueDue(): int` | `app/Services/TicketService.php:52,73,146,206` |
| static `App\Support\BookingRules::visitStart(string $visitDate, string $timezone): CarbonImmutable` | `app/Support/BookingRules.php:23-25`; invocation lengkap dan snapshot fallback pada Task 2 |
| FE `BusinessRepository.paymentMethods(String guid)` / `paymentIntent(String guid)` → `Future<PaymentSnapshot>` | `lib/data/business_repository.dart:378,381` |
| FE `BusinessRepository.initiateEwalletPayment(String guid, String key, String channel)` → `Future<Json>`; `refreshPaymentAttempt(String guid, String attemptGuid)` → `Future<PaymentSnapshot>` | `lib/data/business_repository.dart:387,396`; FE repo terverifikasi di atas |

`BookingRules::visitStart` juga dipakai create `BookingService.php:77`, approval `:259`, quota `QuotaService.php:45,103,124`, read contract `PaymentService.php:106`, DOKU initiation/capture `DokuPaymentService.php:185,366`. Create/approval/quota memakai timezone destination langsung; payment read/locked booking memakai snapshot fallback. Perbedaan existing ini dicatat, bukan otomatis diubah menjadi kebijakan timezone/visit-start baru.

| HTTP method dan full existing path | Handler / bukti source |
| --- | --- |
| `GET /api/v1/payments/methods?booking_guid={guid}` | `PaymentController@methods`, `routes/api.php:44`, throttle 60/min; query `booking_guid`, bukan path parameter |
| `GET /api/v1/bookings/{booking}/payment-intent` | `PaymentController@show`, `routes/api.php:45`, throttle 60/min; persisted read-only |
| `POST /api/v1/bookings/{booking}/payment-attempts` | `PaymentController@initiateDoku`, `routes/api.php:41`, throttle 30/min; idempotency header |
| `POST /api/v1/bookings/{booking}/payment-attempts/{attempt}/refresh` | `PaymentController@refreshDoku`, `routes/api.php:42-43`, UUID attempt, throttle 20/min; controller `:24-29` memanggil refresh lalu mengembalikan `PaymentService::show` |
| `GET /api/v1/admin/bookings/{booking}/payment-intent` | `PaymentController@adminShow`, `routes/api.php:46`, scoped admin, throttle 60/min |
| `POST /api/v1/webhooks/doku/va` | `DokuWebhookController`, `routes/api.php:39`, signature, throttle 300/min; di luar user-auth group |
| `GET /api/v1/bookings/{booking}` | `BookingController@show`, `routes/api.php:214` |
| `GET /api/v1/bookings/{booking}/documents`; `GET /api/v1/bookings/{booking}/documents/{type}` | `BookingDocumentController@show/download`, `routes/api.php:218-222`; hanya type `invoice` atau `ticket` |
| `GET /api/v1/bookings/{booking}/tickets`; `GET /api/v1/bookings/{booking}/tickets/{ticket}/qr` | `TicketController@index/qr`, `routes/api.php:226-227`; entry QR, bukan payment QR |
| `POST /api/v1/bookings/{booking}/demo-payment`; `POST /api/v1/bookings/{booking}/demo-payment/simulate` | `PaymentController@initiateDemo/simulateDemo`, `routes/api.php:47-48`; DEMO gates tetap |
| `GET /payment/return` | `PaymentReturnController`, `routes/web.php:19`; legacy untrusted return hint, bukan paid evidence |

Existing API GET juga terdaftar sebagai HEAD oleh Laravel. Selain webhook, route API di tabel memakai `AuthenticateApiToken`; pengecekan owner/scoped-admin tetap pada request/service. Tidak ada GET existing yang diganti menjadi payment create atau direct provider inquiry.

**Belum existing / usulan additive:** `POST /api/v1/webhooks/doku/qris` (Task 4), type `GET /api/v1/bookings/{booking}/documents/receipt` (Task 5; perlu perluasan allowlist), `GET /api/v1/bookings/{booking}/payment-attempts/{attempt}/qr` (Task 7), kedua refund POST full path (Task 8), dan cancellation action Task 4. Method/path cancellation final hanya ditetapkan sesudah provider contract jelas; belum ada endpoint cancellation yang diklaim tersedia. `ensureInvoice`, QRIS adapter `status/cancel/verifyNotification`, `qrisSvg`, FE `initiatePayment`, `BookingPaymentState`, `IssueBookingTicketQr`, serta `BookingRefundService::request/decide` semuanya **proposed**, bukan API existing. Status/field baru juga usulan kontrak, bukan klaim respons HEAD sudah memuatnya.

## Plan coverage dan batas saat ini

PRD §1/3/5/10/11 → Tasks 1–3; §4 Flutter → Task 7; §6 H-5/check-in → Task 6; §7 email/receipt → Tasks 5/6; §8/9 adapters/readiness → Tasks 2–4; §12 refund → Task 8; §13 fees/security → Tasks 1/3/7/8; §14 external/local evidence → Task 9; §2/15/16 provenance/process → Task 0 dan audit; §17/18 sign-off → Task 9. §19 references dibuka dalam audit, tidak menggantikan merchant evidence.

Baseline audit 8–9 November saat rencana awal dibuat (historical untuk revisi dokumentasi ini, **tidak direrun**): backend 201 tests/1,227 assertions PASS; Flutter empat file 37 tests PASS; PHP 227 syntax checks PASS; routes/schedule/SQLite migration-pretend command PASS. Targeted FE analyze FAIL exit 1 dengan satu info existing unnecessary import. MySQL, merchant Sandbox E2E, device export, SMTP delivery dan Production NOT RUN. Revisi ini hanya memverifikasi source/API/isi dokumen dan diff; tidak menambah klaim code-complete atau UAT.

Provider portions Task 3/4/8 belum dapat difinalkan menjadi exact status/notification field mapping tanpa bukti yang disebut di gate. Jangan mengisi bagian itu dengan tebakan saat eksekusi. Local implementation boleh dilaporkan parsial; acceptance rollout BNI+QRIS tetap belum terpenuhi sampai kedua channel lolos gate.

Tidak ada checkbox implementasi yang sudah selesai pada pass ini. Langkah user yang diperlukan berikutnya hanya review rencana dan arahan eksplisit perubahan kode; go-live tetap keputusan terpisah.

## Changelog revisi 9 November 2026

- Menetapkan **PD-HDAY-01** sebagai deviasi produk terbuka dengan gate persetujuan policy/rollout tersendiri; kompatibilitas reject hari H tidak dihitung sebagai pemenuhan target produk.
- Mengganti penghentian polling setelah tiga menit dengan polling selama halaman aktif+pending, lifecycle-aware, shared cooldown/in-flight, backoff, rate limit dan recovery tanpa reissue.
- Memisahkan CODE_COMPLETE, PROVIDER_BLOCKED, SANDBOX_UAT_PASSED dan PRODUCTION_READY per channel/environment; fixture PASS tidak membuka readiness.
- Memperjelas event-key email, outbox atomik, retry/audit dan batas exactly-once SMTP tanpa menghilangkan local business-idempotency result.
- Menambah checkpoint disposable MySQL pada Task 2 sebelum integrasi Flutter final Task 7, dengan rerun akhir tetap pada Task 9.
- Menegaskan failed/expired/unknown/review/retry tetap inline pada payment page; hanya verified paid yang konsisten membuka success.
- Memverifikasi existing method/path terhadap HEAD dan route list, melengkapi signature/invocation `visitStart`, serta memisahkan seluruh method/endpoint proposed. Arsitektur, ownership, agent, skills dan urutan Task 0–9 dipertahankan.

## Gate yang masih BLOCKED

| Gate | Yang harus tersedia sebelum ditutup |
| --- | --- |
| PD-HDAY-01 | Persetujuan perubahan policy hari H beserta tests, atau penerimaan eksplisit deviasi rollout; policy existing tidak diubah oleh revisi ini |
| LEDGER_MYSQL_CHECKPOINT | Perubahan ledger/expiry diimplementasikan setelah izin, lalu actual disposable MySQL races PASS tanpa skips; belum dijalankan, integrasi Flutter final belum diterima |
| BNI_PROVIDER | Kontrak/evidence terminal inquiry, unknown-create recovery dan expiry window serta merchant/channel setup; kode foundation/fixture belum cukup |
| QRIS_PROVIDER | Signed callback/query mapping, expiry/cancel/refund entitlement dan merchant setup; generate-only dengan `settles=false` belum settlement lengkap |
| EMAIL_TRANSPORT_GUARANTEE | Bukti idempotent transport/reconciliation atau penerimaan eksplisit batas at-least-once bila mensyaratkan nol duplikat fisik; ordinary SMTP saja tidak membuktikan exactly-once. Business-event idempotency/retry/audit masih harus diimplementasikan/diuji secara terpisah |
| SANDBOX_UAT_BNI / SANDBOX_UAT_QRIS | Actual signed merchant E2E masing-masing channel dengan receipt/email/H-5/recovery evidence; keduanya NOT RUN |
| PRODUCTION_READINESS / GO_LIVE | Seluruh gate mandatory dan operasional Production dibuktikan, kemudian arahan go-live terpisah. Tidak ada implementasi, migrasi, `.env` change, commit/push/deploy atau transaksi Production pada revisi dokumentasi ini |
