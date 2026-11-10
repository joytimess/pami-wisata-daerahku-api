# PAMI Wisata Daerahku — Handoff Spesifikasi Final Migrasi DOKU → Midtrans Core API

**Status:** SPEC LOCKED — PLANNING / NO CODE CHANGES  
**Versi:** 1.0  
**Tanggal:** 10 November 2026 (Asia/Jakarta)  
**Pemilik keputusan:** Pengguna PAMI Wisata Daerahku  
**Target eksekutor:** Claude Code / Codex (Laravel backend + Flutter)  
**Basis audit repository:**
- Backend: [`joytimess/pami-wisata-daerahku-api`](https://github.com/joytimess/pami-wisata-daerahku-api), `main` commit `bbf0a05ab34f42524b3d57c18485121c57174f69`.
- Flutter: [`koyotot/pami-wisata-daearahku`](https://github.com/koyotot/pami-wisata-daearahku), `main` commit `7b2a8ff994581f7bdded24e8518eddb6ed10a9f3`.
- **Penting:** commit di atas adalah snapshot audit, bukan jaminan bahwa working tree lokal saat implementasi identik. Bandingkan dahulu Git status, semua branch relevan, HEAD, migration, dan tests lokal. Jangan menimpa perubahan pengguna.

## 1. Misi dan batasan

Ganti **seluruh integrasi payment gateway DOKU yang aktif** pada Laravel dan Flutter dengan **Midtrans Core API standar (`/v2/charge`)**. **Bukan** Midtrans Snap Checkout, **bukan** WebView, dan **bukan** Midtrans BI-SNAP CoreAPI. Midtrans menjadi **satu-satunya payment gateway nyata**. Pembayaran DEMO tetap sebagai mode terpisah yang hanya tersedia di lingkungan non-production.

**Definition of Done:** DOKU client/services/jobs/webhook/routes/config/tests/fixtures/docs khusus yang tak lagi relevan sudah tidak berada dalam working tree aktif; Laravel + Flutter memakai Midtrans BCA VA, BNI VA, QRIS; status bayar terverifikasi menghasilkan booking confirmed dan tiket tepat sekali; refund penuh sesuai metode; semua test/QA lolos; data finansial historis dan audit trail tetap utuh. Jangan menghapus seluruh domain payment/booking/ticket yang masih diperlukan.

**Tugas tahap sekarang: dokumentasi/handoff saja.** Jangan membuat, mengedit, atau menghapus source, migration, `.env`, database, Git branch/commit, ataupun melakukan request pembayaran saat hanya menyusun rencana. Eksekusi teknis dimulai setelah pengguna memberikan instruksi implementasi secara eksplisit.

## 2. Keputusan bisnis yang dikunci (14 + 3)

| ID | Area | Aturan final |
|---|---|---|
| D01 | Kanal rilis pertama | **BCA Virtual Account + BNI Virtual Account + QRIS** saja. E-wallet, bank lain, Snap, dan BI-SNAP di luar scope. |
| D02 | Konfirmasi booking | Booking berbayar **baru `confirmed` setelah pembayaran terverifikasi berhasil**. |
| D03 | Lama pembayaran | **15 menit**, satu tenggat absolut per booking. |
| D04 | Booking habis waktu | Pembayaran ditutup pada deadline; booking berakhir otomatis dan kuota dilepas **setelah** hasil provider aman/terminal terverifikasi. |
| D05 | Retry metode bayar | Boleh membuat attempt baru **pada booking yang sama** selama belum lewat deadline, hanya ketika attempt sebelumnya terbukti terminal/tidak payable; tidak boleh dual active attempts. |
| D06 | Refund | API bila channel mendukung dan aktif; selain itu manual dengan bukti, approval, audit. |
| D07 | Tiket | Hanya setelah settlement terverifikasi, booking confirmed, entitlement valid; tidak boleh double issue. |
| D08 | Native Flutter | Bank chooser, nomor VA/copy/instruksi; QRIS gambar QR dan simpan ke perangkat; **tanpa Snap/WebView**. |
| D09 | Konfirmasi status | Webhook + authenticated GET Status API + reconciliation fallback. |
| D10 | Transaksi DOKU lama | Periksa dan **tutup secara administratif dengan bukti dan audit trail**, termasuk unknown/pending; jangan asal ditandai gagal. |
| D11 | Data historis | Simpan payment intent, attempt, event, refund, booking, invoice, receipt, audit trail untuk kebutuhan audit. |
| D12 | Environment | Satu codebase; Sandbox/Production dipilih dari environment dan kredensial dengan guard yang fail-closed. |
| D13 | Bersih-bersih | Hapus seluruh implementasi DOKU: source, config, jobs, webhook, route, tests/fixtures, docs khusus dari working tree. **Histori Git + histori transaksi tidak dihapus.** |
| D14 | Kelulusan | UAT backend→provider→verified booking→ticket; Flutter native; webhook; refund penuh sesuai scope; regression/concurrency tests. |
| F-A | Urutan approval | **Admin approve dahulu** → booking `awaiting_payment` → pelanggan bayar → `confirmed`. |
| F-B | Deadline & stok | Stop metode bayar tepat pada menit ke-15; release stok **hanya setelah tidak ada pembayaran/provider pending yang belum terverifikasi terminal**. |
| F-C | Refund scope | **Full refund only**. Partial refund tidak tersedia di UI/API PAMI versi ini; partial adjustment provider tak terduga masuk review. |

### Interpretasi operasional final (agar tidak ambigu)

1. Booking dibuat `pending_approval` dan kapasitas mengikuti aturan reservasi saat ini. Approval admin berbayar mengubah status menjadi `awaiting_payment` dan mengunci `payment_expires_at = approved_at + 15 menit`; invoice boleh dibuat pada approval.
2. `confirmed` hanya untuk pembayaran valid (atau booking gratis dengan persetujuan admin mengikuti entitlement gratis saat ini). Jika approval ditolak/timeout, perilaku bisnis lama tetap berlaku kecuali perlu perubahan eksplisit.
3. Hanya **satu payment intent per booking** dan dapat memiliki banyak payment attempt **secara sekuensial**. Tidak boleh membuat BCA dan BNI VA/QRIS aktif bersamaan untuk satu booking.
4. Semua attempt mewarisi deadline booking yang sama; membuat attempt pada menit ke-8 berarti tersisa ~7 menit, **bukan** mulai 15 menit baru. Periksa minimum supported provider dan tolak attempt baru jika sisa waktu tak cukup.
5. Setelah deadline: `can_pay=false`, `can_create=false`, tombol retry diblokir. Jika provider masih pending/unknown, booking masuk status internal verifikasi/review sambil **menahan stok**. Jika provider dikonfirmasi terminal unpaid, tutup booking sebagai **expired karena payment timeout**, lepaskan kuota secara atomik. UI boleh menjelaskan sebagai “dibatalkan otomatis karena batas pembayaran”, tetapi gunakan status `expired` yang sudah didukung schema, bukan sembarang enum baru.
6. Jika Midtrans menunjukkan settlement yang terjadi **sebelum** deadline walaupun callback diterima sesudahnya, finalisasi booking tetap dimungkinkan selama bukti waktu provider dan snapshot lolos pemeriksaan. Jika settlement terjadi sesudah deadline, provider/status tidak pasti, atau stok sudah dilepas: **jangan auto-confirm atau auto-issue**; karantina event dan eskalasi/refund sesuai kebijakan tanpa overbooking.
7. Jika refund penuh telah benar-benar dibayarkan dan diverifikasi, entitlement tiket harus dicabut dan QR entry tidak boleh lagi dapat dipakai. Jangan menyatakan refunded hanya dari persetujuan admin atau request API.

## 3. Status fakta dan hal yang BELUM terbukti

- PoC terisolasi **Midtrans BCA VA Sandbox** berhasil: `/v2/charge` → Simulator BCA → authenticated `GET /v2/{order_id}/status` memberikan `settlement`. PoC **tidak lewat aplikasi PAMI** dan **tidak menguji webhook asli**.
- Perhitungan `signature_key` pada **respons GET Status PoC** menghasilkan mismatch terhadap rumus yang didokumentasikan untuk **webhook**. **Root cause UNKNOWN**, tidak terbukti terkait DOKU. Jangan mengubah atau mengabaikan rumus verifikasi, dan jangan berasumsi signature GET Status harus identik dengan aturan webhook jika provider tidak mendokumentasikannya dengan jelas.
- Mekanisme yang diterima Midtrans untuk memvalidasi notifikasi: tanda tangan notifikasi yang valid **atau** lookup `GET Status API` server-to-server menggunakan kredensial merchant sendiri. Untuk jalur fallback: payload webhook hanya pemicu; lakukan lookup order yang telah dikenal dari database, **jangan percaya status, amount, URL, atau identitas dari payload mentah**.
- UAT **BNI VA**, **QRIS**, **webhook asli**, **refund QRIS**, **expiry 15 menit**, **reconciliation**, serta **Flutter PAMI end-to-end** BELUM terbukti. Status dan izin Production juga belum terverifikasi.
- Refund standar Midtrans mendukung QRIS, **tidak mencantumkan bank transfer VA** sebagai metode refund otomatis. Terapkan manual refund untuk BCA/BNI VA dan refund API QRIS **hanya jika permission/capability dan merchant activation tersedia**. Saat ini key Sandbox uji awal dibuat untuk Core API Write; jangan menganggap Refund capability sudah diaktifkan.

## 4. Kontrak domain dan invariants keamanan

### Booking
- `pending_approval` → (admin approved, paid amount >0) `awaiting_payment` → (verified settlement) `confirmed`.
- Timeout setelah approval: `awaiting_payment` → `payment_verification` / `payment_review` bila ada ketidakpastian → `expired` hanya setelah provider terbukti terminal unpaid. Gunakan `BookingRules::HELD` untuk menjaga kuota pada status verifikasi/review.
- Perubahan booking, quota, intent, attempt, event, entitlement, dan tiket dilakukan dengan urutan locking konsisten **destination/quota → booking → intent → attempt/event** sesuai pola layanan existing; `DB::transaction` dan outbox/after-commit untuk side effects.
- Settlement vs expiry race harus deterministik; satu pihak tidak boleh melepas kuota saat pihak lain mengonfirmasi settlement.
- Data gratis (Rp0) tidak boleh diperlakukan sebagai payment Midtrans.

### Payment intent dan attempt
- Provider baru = `midtrans` untuk transaksi baru, `demo` hanya non-prod; **jangan buat charge Midtrans untuk booking yang memiliki intent historis provider lain** tanpa kebijakan pemulihan yang disetujui.
- Per attempt, gunakan Midtrans **order_id unik** di merchant/environment, simpan hubungan immutable dengan booking, intent dan attempt. Jangan gunakan nomor booking langsung jika dapat berbenturan antar-retry.
- Retry client dengan idempotency key yang sama harus mengambil ulang hasil attempt, bukan membuat charge baru.
- Sebelum `Charge` network request, simpan durable reservation attempt, lalu lakukan call di luar transaksi DB, dan finalisasi snapshot hasil dengan lock. Timeout/HTTP ambiguous → `unknown` dan status inquiry; **tidak boleh retry Charge otomatis dengan order_id lain**.
- Canonical monetary amount: IDR integer di database, konversi aman, `gross_amount` harus cocok dengan total booking dan snapshot amount attempt; tidak ada floating point untuk uang.
- Simpan `transaction_id`/`order_id`/bank/VA atau QR metadata secara minimal dan aman. Identitas provider dan environment tidak boleh dapat dipindahkan setelah attempt dibuat.

### Settlement & event
- `transaction_status=settlement` untuk tiga channel ini; jika `fraud_status` tersedia harus `accept`. Jangan menggunakan `pending` atau respons Charge sebagai bukti bayar.
- Verified evidence harus mengikat: `order_id`, `transaction_id` bila disediakan, `merchant_id` bila tersedia, metode/bank/acquirer sesuai, amount/currency, provider/environment, waktu pembayaran dan expiry, booking ownership, attempt aktif yang sah, serta status provider.
- `payment_events`: durable append-only/replayable dengan dedupe provider event key + payload hash; event duplicate dan out-of-order tidak boleh menimbulkan side effect kedua.
- `PaymentEligibility` harus mengandalkan ledger terverifikasi dan status booking/attempt konsisten, bukan hardcoded `doku_snap` atau presence sebuah webhook.
- Reversal, refund, chargeback/partial adjustment tidak boleh mengaktifkan kembali tiket; jika tidak sesuai kebijakan otomatis masuk `needs_review` dan audit.

### Webhook
- Endpoint publik khusus `POST /api/v1/webhooks/midtrans`, terlepas dari auth pelanggan, dengan pembatasan ukuran payload/rate limit.
- Validasi format; jangan lakukan aksi finansial berdasarkan payload tak dipercaya. Verifikasi signature webhook terhadap kredensial penandatangan yang sah; jika gagal/tidak bisa diverifikasi, gunakan authenticated GET Status dengan **order_id yang diambil dari database setelah lookup ketat** (bukan URL/host dari payload) atau fail-closed.
- Jika GET Status gagal/timeout, **jangan set paid**; simpan event unverified/quarantined dan jadwalkan reconciliation. Jangan membocorkan Server Key, request Basic Auth, data sensitif, dan raw payload ke log.
- Respons webhook cepat, pemrosesan durable idempotent via queue, scheduled replay jika dispatch gagal. Publikasikan tiket/notifikasi setelah commit.

### Deadline 15 menit (inti kompleksitas)
- Simpan `approved_at` atau sumber waktu approval yang otoritatif; hitung `payment_expires_at` mutlak dalam UTC untuk penyimpanan.
- Kirim `custom_expiry` pada Charge Midtrans BCA, BNI, QRIS dengan target timestamp yang **sama** (provider mereferensikan GMT+7 pada `order_time`). Periksa respons `expiry_time`/`transaction_time`; jangan tampilkan VA/QR yang masa berlakunya melebihi booking deadline.
- **Perhatikan** Midtrans punya batas minimal sisa expiry (dokumentasi menyebut transaksi dengan <20 detik dapat ditolak) dan potensi delay scheduler expiry; implementasikan guard waktu minimum yang aman + UAT. `cancel/expire` API bila diperlukan adalah best-effort, bukan bukti status terminal hingga Status API mengonfirmasi.
- Pada deadline, semua UI + server stop membuat transaksi baru. Jangan melepas kuota sampai seluruh attempt yang dapat masih settle dinyatakan terminal unpaid atau transaksi dibuktikan settled tepat waktu.

### Refund penuh
- VA BCA/BNI: admin approval → manual transfer dengan bukti dan reference unik → status `manual_refunded` setelah verifikasi manusia sesuai RBAC/audit.
- QRIS: refund API (jika aktif dan eligible) setelah admin approval; gunakan `refund_key` idempotent, ketentuan waktu/capability/balance provider, dan status final terverifikasi. Jika tidak didukung, jalur manual.
- Nominal selalu full outstanding captured amount; refund partial tidak boleh bisa diminta oleh customer; provider partial refund tak terduga = escalation.
- Tolak otomatis jika tiket sudah dipakai/check-in kecuali ada proses pengecualian manual terotorisasi dan tercatat. Hindari refund ganda/duplikasi manual+provider.

### Konfigurasi
- `MIDTRANS_ENABLED=false` default, `MIDTRANS_ENVIRONMENT=sandbox|production`, `MIDTRANS_SERVER_KEY` rahasia backend, `MIDTRANS_MERCHANT_ID` untuk memvalidasi expected merchant pada respons (wajib untuk Production), `MIDTRANS_CHANNELS=va_bca,va_bni,qris` (atau konvensi registry internal), Production approval gate explicit.
- Semua base URL, timeout, retry policy, keys, capability flags dari config teruji. Production harus **fail-closed** jika kredensial Sandbox dipasang, approval false, channel belum verified, atau endpoint salah. Jangan gunakan Server Key di Flutter. Setelah perubahan env deployment, ingat `config:cache`.
- Payment DEMO tetap terisolasi, tidak boleh membuat transaksi nyata, dan tidak boleh aktif di Production.

## 5. Mapping repository — Laravel

### Hapus pada tahap final cleanup, bukan sebelum penggantinya aman

| Kelompok | Path aktual pada commit audit |
|---|---|
| DOKU HTTP adapter | `app/Services/Doku/` seluruhnya (`DokuClient`, `DokuChannelRegistry`, `DokuQrisAdapter`, `DokuDirectDebitAdapter`, `DokuExchangeSanitizer`) |
| DOKU orchestration | `app/Services/DokuPaymentService.php` |
| DOKU webhook | `app/Http/Controllers/Api/V1/DokuWebhookController.php`, `app/Http/Middleware/ObserveDokuWebhook.php` |
| DOKU request/error | `app/Http/Requests/DokuPaymentRequest.php`, `app/Exceptions/DokuRequestException.php` |
| DOKU background | `app/Jobs/ProcessDokuPaymentEvent.php`, `app/Jobs/ReconcileDokuPayment.php`, `app/Console/Commands/ReconcileDokuPayments.php`, `app/Console/Commands/ProbeDokuDanaCreate.php` |
| Konfigurasi | `config/doku.php`; referensi DOKU di `config/operations.php`, `.env.example`, middleware, bootstrap/DI, routes/scheduler |
| DOKU tests | `tests/Feature/Doku*.php`, `tests/Mysql/DokuPaymentConcurrencyTest.php`, `tests/Mysql/doku-payment-worker.php`, `tests/Fixtures/doku/` |
| Dokumen DOKU | `docs/prd/DOKU-*.md`, `docs/prd/PRD*DOKU*.md`, `docs/audits/*doku*.md`, `docs/superpowers/plans/*doku*.md` setelah inventaris semua rujukan; pertahankan riwayat Git |

**Jangan pakai blind recursive delete:** lakukan `rg -n -i 'doku|doku_snap|DOKU_' .` dengan exclude `.git`, vendor, build, generated, lock files; klasifikasikan tiap hit. Referensi terhadap **data historis** boleh tetap ada dalam database/log/audit; runtime source harus provider-neutral dan tidak lagi memanggil DOKU. Jangan menghapus migration lama atau merombak catatan audit.

### Refactor wajib

| Path | Perubahan |
|---|---|
| `app/Services/PaymentGateway.php` | Saat ini impor `DokuChannelRegistry`/`DokuClient`, capabilities DOKU; ganti registry BCA, BNI, QRIS Midtrans; DEMO tetap terpisah. |
| `app/Services/PaymentService.php` | Saat ini provider default DOKU, expiry attempt 30 menit, `can_switch=false`; ganti Midtrans dan aturan attempt sekuensial sampai deadline 15 menit. |
| `app/Services/PaymentEligibility.php` | Saat ini `provider=doku`, `DokuChannelRegistry::settlingPairs`, `metadata.source=doku_snap`; ganti ledger verifikasi Midtrans + pembacaan historis provider-neutral yang aman. |
| `app/Http/Controllers/Api/V1/PaymentController.php` | Saat ini `initiateDoku`, `refreshDoku`, QR SVG; ganti actions Midtrans sambil mempertahankan HTTP contract publik bila memungkinkan. |
| `routes/api.php` | Hapus DOKU webhook route, pasang Midtrans webhook; pertahankan endpoint payment/booking Flutter yang sudah digunakan. |
| `routes/console.php` | Hapus `payments:reconcile-doku`, tambah scheduler Midtrans. |
| `app/Services/BookingService.php` | Approval sekarang set `payment_expires_at=now()+1 jam`; ubah 15 menit. Expiry sekarang masuk `payment_verification` lalu `payment_review`; tambah terminal-unpaid closure & release stok aman. |
| `app/Support/BookingRules.php` | Konfirmasi status yang menahan stok (`HELD`) dan transisi final expired; jangan release quota jika status provider unknown. |
| `app/Services/BookingRefundService.php` | Saat ini hardcoded intent `provider=doku`; buat full-only refund Midtrans, manual VA + conditional QRIS API. |
| `app/Services/PaymentReceiptService.php` | Saat ini hardcoded `where provider=doku`; provider-neutral receipt + audit historical. |
| `app/Services/FinancialAnalyticsService.php` | Hindari filter DOKU pada laporan aktif/historis; jangan hilangkan angka lama. |
| `app/Services/BookingNotificationService.php` | Ganti label, event messages, expiry, refunds; no misleading DOKU branding. |
| `app/Support/PaymentStates.php` | Audit transitions settlement/refund/expire/unknown; tambah state dengan migration **hanya bila benar-benar perlu**. |
| `app/Services/TicketService.php`, `app/Services/BookingDocumentService.php` | Pertahankan business logic, tambahkan regression tests integrasi settlement/refund. |
| `config/operations.php`, `.env.example` | Hapus stale DOKU flags, tambahkan Midtrans config non-secret. Jangan edit `.env` sebenarnya tanpa persetujuan pengguna. |

### Komponen baru (nama rancangan dapat disesuaikan)

- `config/midtrans.php` — environment, endpoint, channel gate, permission, timeout, production guard.
- `app/Services/Midtrans/MidtransClient.php` — Basic Auth, `POST /v2/charge`, `GET /v2/{order_id}/status`, cancel/expire/refund bila dibutuhkan; HTTP timeouts; sanitasi response.
- `app/Services/Midtrans/MidtransStatusMapper.php` — provider status → internal states dengan strict schema.
- `app/Services/MidtransPaymentService.php` — payment attempt reservation/idempotency, VA/QR instruction, Charge response.
- `app/Services/PaymentSettlementService.php` (opsional) — ambil kembali domain settlement dari DOKU tanpa runtime dependency pada DOKU.
- `app/Http/Controllers/Api/V1/MidtransWebhookController.php` — receive-only + validation + async durable processing.
- `app/Http/Requests/MidtransPaymentRequest.php` — channel selection and idempotency request validation.
- `app/Jobs/ProcessMidtransPaymentEvent.php`, `app/Jobs/ReconcileMidtransPayment.php`, `app/Console/Commands/ReconcileMidtransPayments.php` — queue & scheduler recovery.
- `tests/Feature/Midtrans*.php`, `tests/Mysql/MidtransPaymentConcurrencyTest.php`, fixtures tersanitasi, tests refund dan expiry; helper mock responses.

### Database: jangan destructive

Pada commit audit, migration `database/migrations/2026_11_05_040716_create_payment_tables.php` mendefinisikan:
- `payment_intents.booking_guid` **UNIQUE**;
- provider/environment dalam intents, attempts, events;
- unique per provider/environment/merchant_reference;
- payment_events verification + dedupe;
- payment attempt status memiliki `initiating`, `pending`, `failed`, `expired`, `unknown`, `paid`, `reversed`.

Lakukan **additive schema migration** hanya jika perlu (misalnya metadata provider expiry / refund evidence). **Jangan pernah mengedit migration historis yang sudah diterapkan, drop payment ledger, overwrite provider `doku` menjadi `midtrans`, atau membuat booking historis terlihat dibayar oleh Midtrans**.

## 6. Mapping repository — Flutter

| Path | Perubahan |
|---|---|
| `lib/data/payment_models.dart` | Hilangkan `isDoku`; pilihan saat ini hardcoded BNI+QRIS; gunakan daftar channel dari backend, support BCA/BNI/QRIS, `isMidtrans` bila perlu tetapi utamakan status backend. |
| `lib/data/booking_payment_state.dart` | Hilangkan DOKU-specific guards; deadline 15m; retry attempt terminal; unknown verification; paid hanya dari backend; tidak mendobel create. |
| `lib/screens/booking_payment_screen.dart` | Ganti heading/instruksi BNI hardcoded jadi dinamis; native VA copy + QRIS display/save; status inquiry UI. |
| `lib/widgets/booking_payment_section.dart` | Hapus `_dokuSeen` dan provider hardcodes; DEMO tetap terisolasi. |
| `lib/data/business_repository.dart` | Pertahankan endpoint publik bila bisa; perbarui request/response untuk Midtrans; jangan panggil Midtrans langsung dari Flutter. |
| `lib/services/payment_qr_export.dart` | Sekarang ekspektasi SVG; implementasi QRIS Midtrans pakai PNG (atau secure normalized image dari Laravel), simpan gambar aman. |
| `lib/widgets/payment_deadline.dart` | Countdown memakai waktu server absolut, bukan menambah 15 menit lokal tiap reopen. |
| `lib/data/ewallet_payment_state.dart`, `lib/widgets/ewallet_payment_section.dart` | Hapus bila hanya untuk DOKU e-wallet. Verifikasi references dan tests. |
| `lib/screens/payment_return_screen.dart`, `lib/data/payment_return_link.dart` | Hapus bila hanya untuk redirect DOKU; pertahankan hanya komponen yang dipakai fitur lain. |
| `lib/services/api_client.dart` | Tidak menyimpan Midtrans Server Key/Merchant secret; semua request via Laravel. |
| `test/booking_payment_test.dart`, `test/business_booking_widgets_test.dart`, tests terkait | Ganti expectations DOKU, tambah tests BCA, BNI, QRIS, expiry, retries, UI status, refund, dan screenshot/QR export. |

**Flutter native acceptance:** BCA/BNI chooser → POST backend → tampil nomor VA & copy → instruksi sesuai bank; QRIS → tampil gambar autentik dari Laravel → simpan sebagai PNG; polling manual/otomatis terkendali; tutup pembayaran lewat 15 menit; layar pending/verification/refund/paid sesuai snapshot backend; tiket entry tidak berubah sebelum valid settlement.

## 7. HTTP contract PAMI yang harus dijaga

Perubahan implementasi internal tidak boleh secara diam-diam mematahkan interface Flutter.

| Endpoint | Makna sesudah migrasi |
|---|---|
| `GET /api/v1/payments/methods?booking_guid=...` | `provider=midtrans`, `environment`, BCA/BNI VA + QRIS availability dan alasan unavailable yang jujur. |
| `POST /api/v1/bookings/{booking}/payment-attempts` | Create attempt via Midtrans dengan idempotency key, validasi booking, channel, deadline. |
| `POST /api/v1/bookings/{booking}/payment-attempts/{attempt}/refresh` | Authenticated Status API/reconciliation, rate limit. |
| `GET /api/v1/bookings/{booking}/payment-attempts/{attempt}/qr` | QR image untuk attempt yang dimiliki user; MIME PNG/gambar tervalidasi, no unrestricted redirect/SSRF. |
| `GET /api/v1/bookings/{booking}/payment-intent` | Server snapshot status, deadline, instruksi yang masih valid, approval/entitlement/refund. |
| `GET /api/v1/admin/bookings/{booking}/payment-intent` | Audit attempts/events/needs_review, authorization sesuai destination scope. |
| `POST /api/v1/webhooks/midtrans` | Callback publik yang aman, idempotent, dan memakai out-of-band verification. |
| Existing DEMO endpoints | Tetap hanya local/testing/demo, no Production. |

**Contoh normalisasi method pilihan:** `virtual_account/bca`, `virtual_account/bni`, `qris/qris` sebagai kontrak internal PAMI; `payment_type` ke Midtrans `bank_transfer` dan `qris`. Periksa aktual API response. `instruction.presentation_type` harus provider-neutral; jangan expose credential/secret/provider debugging detail.

## 8. Rencana eksekusi per fase dan gates

### Phase 0 — Preflight & inventory (READ-ONLY)
- Inventaris HEAD semua branch, local working tree, references DOKU di Laravel/Flutter, baseline tests/lints, dependency graph/Graphify bila tersedia.
- Pemetaan booking/payment/refund/ticket state dan semua API clients; periksa status transaksi DOKU historis di database secara aman (tanpa perubahan). Daftarkan unknown/pending/unsettled dan bukti kebutuhan penutupan.
- Deliverable: inventory **DELETE / MODIFY / ADD / PRESERVE**, matriks risiko, rencana migration/data audit, baseline tests dan diffs. **STOP untuk review sebelum file deletion.**

### Phase 1 — Test harness & payment domain refactor
- Tambah fixtures Midtrans dan failing tests untuk 15m, attempts, idempotency, late notification, entitlement, refund, records lama.
- Extract general settlement/events/locking from `DokuPaymentService` sebagai code yang provider-neutral. Jangan aktifkan Midtrans di aplikasi sebelum coverage dan guard siap.
- No destructive schema, no demo regressions.
- Gate: Payment foundation, booking, ticket, demo, concurrency tests hijau atau baseline deviations terdokumentasi.

### Phase 2 — Midtrans client & Create Charge (Sandbox)
- Implement config fail-closed dan client `Core API standard`, Basic Auth (Server Key as username, blank password), no Snap/BI-SNAP.
- Implement BCA VA, BNI VA dan QRIS create, unique order ID, exact expiry, instructions/QR image normalization. Mock first, then Sandbox by user approval.
- Gate: mocked mapping + real Sandbox Create VA/QRIS return pending and expiry correct; no secrets; no DOKU API calls in Midtrans flow.

### Phase 3 — Verified webhook, reconciliation & settlement
- Register Sandbox Notification URL, implement raw webhook capture/outbox, signature verification against *actual webhook*, authenticated GET Status fallback, duplicate handling and strict binding.
- Status mapper & reconciliation worker/scheduler; transactional booking confirm and ticket after commit.
- Gate: real BCA, BNI, QRIS settlement; webhook authenticity; missing/late/duplicate/out-of-order scenarios; injected failures recover without false paid.

### Phase 4 — Expiry, quota, retry & refund penuh
- Change approval window 60m→15m, attempt duration→absolute booking deadline, cutoff UI/backend, terminal verification before release, race tests.
- Full refund QRIS if merchant capability active; manual VA and QRIS fallback; permissions & evidence; revoke QR entitlement on verified adjustment.
- Gate: exact scenario tests, no duplicate refund, no overbooking, correct expired/refunded states. If Refund permission is absent, API refund unavailable but manual flow must operate; automatic QRIS refund acceptance remains blocked until permission UAT.

### Phase 5 — Flutter native
- Integrate existing native page and state. Dynamic VA methods, QR PNG, export, status polling, deadline based on server, correct error/refund/verification screens.
- Gate: Flutter tests, manual emulator run, app restart/timeouts, Android save-to-gallery/files, booking paid → ticket detail/QR.

### Phase 6 — DOKU administrative closure & complete cleanup
- Periksa seluruh transaksi DOKU lama: pending/unknown/paid/reversed dan catatan refund. Tutup kasus ambigu secara administratif dengan referensi dan bukti, *bukan mass-update*. Siapkan historical generic read-path dan reports.
- Setelah semua data aman: hapus source/config/routes/jobs/test/docs DOKU pada working tree, hapus Flutter DOKU/ewallet/redirect-only code. Refactor tests yang masih bergantung pada DOKU; lakukan global `rg` audit.
- Gate: tidak ada active DOKU runtime path; data historis tetap terbaca, report total cocok sebelum/sesudah; migrations tetap utuh; tests tetap hijau. Tidak ada akses DOKU lagi, termasuk maintenance job yang tersembunyi.

### Phase 7 — UAT penuh, security review & Production-readiness
- End-to-end Laravel + Flutter Sandbox: admin approval → BCA/BNI/QRIS → settlement → ticket; expiry; retries; refunds; duplicate webhook; delayed API status; concurrency MySQL; worker/scheduler recovery; role/scope authorization.
- Review credentials, no secrets in logs or Flutter, production gate off; API permissions, merchant production activation, official Notification URL, HTTPS, queue monitoring, rollout/rollback.
- Gate: documented signed-off UAT evidence + baseline regression resolved; Production **NOT** activated automatically.

**Suggested milestone commits:** `test(midtrans-foundation)`, `feat(midtrans-coreapi)`, `feat(midtrans-webhook-settlement)`, `feat(midtrans-expiry-refund)`, `feat(flutter-native-midtrans)`, `refactor(remove-doku)`, `test(uat-security)`. Jangan commit/push tanpa perintah pengguna.

## 9. Test matrix minimum

| Test | Expected |
|---|---|
| Approval berbayar | Deadline approval+15m, `awaiting_payment`, invoice valid, quota held. |
| Free approval | Confirmed free sesuai aturan lama, tanpa Midtrans/intent. |
| BCA / BNI / QRIS create | Satu attempt, nomor VA/QR instruction, expiry ≤ deadline. |
| Dua klik/timeout/replay | Satu Charge untuk satu idempotency key; unknown di-inquiry, tidak buat order kedua. |
| Ganti metode saat prior pending | 409/blocked; no dual payable attempts. |
| Attempt terminal sebelum deadline | Attempt baru allowed, deadline tidak reset. |
| Countdown habis | No new attempt; pending/unknown retains quota; verified unpaid releases quota. |
| Paid sebelum expiry tapi notification terlambat | Verified payment can confirm if all invariants and quota lock hold. |
| Paid sesudah expiry | Quarantine/review/refund; no auto ticket/overbooking. |
| Fake webhook / signature mismatch | Does not settle from webhook; trusted GET Status only for locally known order. |
| Duplicate/out-of-order webhook | Event idempotency; single booking confirmation and ticket. |
| GET Status down | No false paid; retry worker with backoff; escalation. |
| QRIS image | Authenticated safe PNG, no SSRF/HTML, export works. |
| Refund penuh BCA/BNI | Manual approval+proof+verification only; no provider API assumption. |
| Refund penuh QRIS | If API capability active verify provider outcome; else manual; no double refund. |
| Refund after check-in | Block/review per policy; no ticket reactivation. |
| Historical DOKU records | Financial totals, original provider and evidence survive; no DOKU HTTP calls. |
| DEMO regression | Non-prod only, no mixing with Midtrans. |
| Production config failure | Default deny: sandbox key in production / approval false / wrong host / missing channel. |
| MySQL race | Settlement-vs-expiry, double refund, double create, ticket issue exactly once. |

## 10. Explicit non-goals

- Tidak menjalankan DOKU dan Midtrans berdampingan sebagai arsitektur final.
- Tidak menggunakan Midtrans Snap UI, WebView, BI-SNAP token flow, atau membuat flow e-wallet rilis pertama.
- Tidak menghapus riwayat payment atau migration lama.
- Tidak mengganti provider `doku` menjadi `midtrans` pada data historis.
- Tidak membuat data paid/settled/ticket tanpa bukti provider yang terverifikasi.
- Tidak mengaktifkan Midtrans Production secara otomatis.
- Tidak melakukan perubahan database nyata atau penggunaan credential Production tanpa persetujuan.

## 11. Perintah untuk Claude Code / Codex (copy-paste)

> Anda bertugas merencanakan migrasi **PAMI Wisata Daerahku DOKU → Midtrans Core API standar** berdasarkan dokumen ini. Spesifikasi bisnis D01–D14 + F-A/B/C sudah final. Gunakan workflow Superpowers yang relevan, maksimal tiga subagent paralel (arsitektur/inventory, backend/security/QA, Flutter/API contracts). Pertama inspeksi kedua repository dan seluruh branch/working tree terbaru; bandingkan dengan SHA audit. **MODE READ-ONLY / PLAN ONLY** untuk tahap ini: jangan edit file, branch, commit, push, query transaksi yang mengubah state, atau mengubah `.env`/database. Outputkan: (1) impact matrix file path DELETE/MODIFY/ADD/PRESERVE, (2) daftar konflik requirement vs code dengan referensi file/line, (3) desain state machine & concurrency/expiry safeguards, (4) desain Midtrans Core API BCA/BNI/QRIS, authentic notification via GET Status/signature, refund full-only, (5) granular milestone implementation & test matrix, (6) risiko data historis DOKU dan prosedur administrative closure, (7) daftar blockers external merchant capability. Tidak boleh ada active DOKU runtime setelah migrasi final; jangan memusnahkan data audit atau merusak DEMO. Berhenti setelah menyerahkan **implementation plan**, tunggu perintah eksplisit untuk melakukan perubahan source.

## 12. Referensi resmi & audit

- Midtrans [Core API Bank Transfer Integration](https://docs.midtrans.com/docs/coreapi-core-api-bank-transfer-integration)
- Midtrans [Charge Transactions / Custom Expiry](https://docs.midtrans.com/reference/charge-transactions-1)
- Midtrans [Custom Expiry Object](https://docs.midtrans.com/reference/custom-expiry-object)
- Midtrans [QRIS Core API](https://docs.midtrans.com/reference/qris)
- Midtrans [Get Transaction Status](https://docs.midtrans.com/reference/get-transaction-status)
- Midtrans [HTTP(S) Notification / Webhooks](https://docs.midtrans.com/docs/https-notification-webhooks)
- Midtrans [Refund Transactions](https://docs.midtrans.com/reference/refund-transaction)
- Midtrans [Bank Transfer Object](https://docs.midtrans.com/reference/bank-transfer-object)
- Backend GitHub HEAD audited: [`bbf0a05`](https://github.com/joytimess/pami-wisata-daerahku-api/commit/bbf0a05ab34f42524b3d57c18485121c57174f69)
- Flutter GitHub HEAD audited: [`7b2a8ff`](https://github.com/koyotot/pami-wisata-daearahku/commit/7b2a8ff994581f7bdded24e8518eddb6ed10a9f3)

> **End of handoff.** Sampai ada persetujuan implementasi, tidak ada kode yang diubah.
