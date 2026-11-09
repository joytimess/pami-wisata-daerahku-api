# DOKU BNI SNAP: implementasi dan operasi

Tanggal: 8 November 2026. Acuan: PRD DOKU Direct API v1.0.

Kode menyediakan VA BNI SNAP v1.1 DGPC, penerimaan capture bertanda tangan, penyimpanan event, finalisasi booking, pemulihan instruksi dan worker inquiry. DOKU tetap nonaktif secara default. Pengujian lokal menggunakan HTTP fake dan database terisolasi; belum ada transaksi DOKU Sandbox atau Production yang dijalankan.

## Status delivery

| Milestone | Hasil |
| --- | --- |
| M0 | Spesifikasi resmi BNI create/token/signature/capture dipilih. Registrasi merchant key, BIN/Sub-BIN, dashboard Notification URL dan transaksi Sandbox belum diverifikasi. |
| M1 | Config sandbox/production, gate channel, pemeriksaan RSA key, TLS dan gate Production tersedia; diuji lokal. |
| M2 | Schema existing dipakai; migration additive menambah jadwal/count/error rekonsiliasi. Idempotensi, instruksi dan cache respons terenkripsi, locking dan dedupe diuji lokal serta MySQL sementara. |
| M3 | Adapter create BNI tersedia dan diuji dengan fixture resmi. Create VA Sandbox asli: NOT RUN. |
| M4 | Verifikasi raw JSON sesuai SNAP, event durable sebelum ACK, job replay, capture dan ticket eligibility diuji melalui HTTP lokal. Callback simulator DOKU: NOT RUN. |
| M5 | PARTIAL. Polling pending, lease, backoff/jitter dan replay event tersedia. Mapping inquiry terminal belum diaktifkan karena bentuk status akhir/waktu pembayaran belum terverifikasi. Reversal/refund ditangani pada domain dan diuji dengan fixture; parser notifikasi provider untuk adjustment belum tersedia. |
| M6 | Kontrak backend native VA tersedia; endpoint DEMO tetap ada. Source Flutter tidak diubah, pengujian emulator/device belum dijalankan. |
| M7 | PARTIAL (v2.0). Registry channel, VA BCA/BRI/Mandiri, DANA, ShopeePay dan adapter generate QRIS ada di kode dan diuji dengan fixture; belum ada transaksi Sandbox. OVO belum diimplementasikan (kontrak tidak terbaca). QRIS tidak dapat settle sehingga tetap unavailable (lihat `DOKU-CHANNEL-MATRIX.md`). |
| M8 | BLOCKED untuk go-live: menunggu bukti Sandbox, kontrak terminal/adjustment, merchant/channel live, HTTPS tetap, worker/scheduler, monitoring dan release smoke test. |

PRD penuh belum memenuhi Definition of Done. Jangan mengaktifkan Production dengan mengandalkan hasil fixture.

## Bukti verifikasi lokal

Hasil akhir pada 8 November 2026:

| Pemeriksaan | Hasil |
| --- | --- |
| Regresi terarah 11 file feature | PASS: 178 tes, 1.170 assertion. Mencakup PaymentFoundation, DemoPayment, DokuClient, DokuPayment, FinancialAnalytics, SmartTicketSmoke, BookingService, BookingApi, BookingNotification, BookingDocuments dan AdminOperations. |
| MySQL DOKU concurrency | PASS: 2 tes, 30 assertion. Tiga inisiasi bersamaan menghasilkan satu attempt/panggilan provider; duplicate capture diterapkan sekali; capture tepat waktu tetap confirmed saat expiry berjalan bersamaan. |
| Laravel Pint dirty | PASS. |
| `git diff --check` | PASS; hanya pemberitahuan normalisasi CRLF `.env.example`. |
| Route list dan schedule list | PASS: route owner/admin/webhook dan command tiap menit terdaftar. |
| Migration MySQL `--pretend` | PASS: empat kolom additive dan satu index; tanpa menjalankan perubahan database aplikasi. |
| Review arsitektur/keamanan | Temuan yang ditemukan sudah diperbaiki dan diuji: environment, superseded capture, status mundur, instruksi unsafe, query unpaid, JSON MySQL, clock skew dan antrean duplikat. Review source bukan clearance live. |
| DOKU Sandbox/Production, Flutter/device, load test dan monitoring live | NOT RUN. |

Feature tests memakai SQLite `:memory:`. MySQL tests membuat database sementara bernama `pami_doku_<random>_test`, menjalankan migration di sana, lalu menghapus hanya database tersebut. Tidak ada reset database development.

Perintah MySQL: `php vendor/bin/pest --configuration=phpunit.mysql.xml tests/Mysql/DokuPaymentConcurrencyTest.php --colors=never`. Pengujian ini memakai adapter fixture dalam proses terpisah; tidak menghubungi DOKU.

## Setup Sandbox

1. Terapkan migration baru pada database deployment yang telah ditinjau:

   ```powershell
   php artisan migrate --path=database/migrations/2026_11_08_034113_add_reconciliation_to_payment_attempts.php --no-interaction
   ```

   Migration ini sudah dijalankan pada database test SQLite dan database MySQL sementara. Database aplikasi pengguna belum dimigrasikan oleh pekerjaan ini.

2. Lengkapi nilai di `.env` lokal berdasarkan `.env.example`. Jangan mengirim nilai credential melalui chat atau memasukkannya ke Git. Simpan merchant RSA private key minimal 2048 bit di luar `public/`; batasi ACL kepada proses PHP dan administrator. Daftarkan public key pasangannya di DOKU.

   ```dotenv
   DOKU_ENABLED=false
   DOKU_ENVIRONMENT=sandbox
   DOKU_CLIENT_ID=
   DOKU_SECRET_KEY=
   DOKU_MERCHANT_PRIVATE_KEY_PATH=
   DOKU_MERCHANT_PRIVATE_KEY_PASSPHRASE=
   DOKU_BNI_PARTNER_SERVICE_ID=
   DOKU_BNI_CUSTOMER_PREFIX=
   DOKU_CHANNELS=va_bni
   DOKU_WEBHOOK_URL=
   DOKU_PRODUCTION_APPROVED=false
   DOKU_RECONCILIATION_ENABLED=true
   DEMO_PAYMENTS_ENABLED=false
   APP_DEBUG=false
   ```

   Partner service ID adalah BIN merchant BNI, customer prefix adalah Sub-BIN dari DOKU. Ketiganya, termasuk merchant private key, perlu dikonfirmasi; Client ID bukan BIN. Verifier VA menggunakan symmetric HMAC SNAP dengan secret dan bearer pada request provider. DOKU public key tidak dipakai oleh jalur symmetric ini.

3. Jalankan Laravel lokal dan tunnel sesuai PRD. Isi `DOKU_WEBHOOK_URL` dengan HTTPS yang aktif beserta path `/api/v1/webhooks/doku/va`. Path harus cocok persis; jangan menambah query string. Perubahan domain tunnel memerlukan pembaruan registrasi Notification URL di dashboard DOKU. Endpoint POST dilindungi signature, tanpa JWT aplikasi.
4. Bersihkan config development dengan `php artisan config:clear`, lalu restart worker setelah perubahan konfigurasi. Jalankan worker dan scheduler pada terminal/proses terpisah:

   ```powershell
   php artisan queue:work --timeout=75
   php artisan schedule:work
   ```

   Gunakan queue persistent seperti database dan cache bersama yang mendukung atomic locks; `sync`/cache array hanya cocok untuk pengujian. Kedua job pembayaran memakai unique key satu jam untuk membatasi antrean duplikat, selain lease/idempotensi database. `retry_after` queue harus lebih besar daripada timeout 75 detik. Jangan memasang worker sebelum tabel `jobs` deployment tersedia.
5. Setelah merchant/channel dan URL sesuai, aktifkan `DOKU_ENABLED=true` hanya untuk uji Sandbox. `GET payments/methods` harus menunjukkan VA BNI tersedia bagi owner booking yang masih eligible. Menampilkan `available` membuktikan pemeriksaan config lokal, belum membuktikan aktivasi provider.
6. Buat satu attempt, bayar melalui simulator VA DOKU, lalu pastikan signed callback tersimpan, intent `paid`, booking `confirmed`, hanya satu history konfirmasi, dan QR mengikuti jadwal penerbitan tiket existing. Ulangi callback dan restart aplikasi untuk memeriksa recovery. Rekam bukti tanpa menyimpan key/token/raw PII.
7. Tutup tunnel setelah pengujian. Jangan menguji menggunakan uang Production.

## Kontrak API

Semua endpoint owner memakai JWT aplikasi dan header respons `Cache-Control: private, no-store`.

| Endpoint | Perilaku |
| --- | --- |
| `GET /api/v1/payments/methods?booking_guid={guid}` | Capability berdasarkan konfigurasi dan booking. Hanya BNI VA yang dapat diaktifkan. |
| `POST /api/v1/bookings/{guid}/payment-attempts` | Header `Idempotency-Key`; body `{"method_code":"virtual_account","channel":"bni"}`. Amount/currency/provider/environment/status dari client ditolak. |
| `GET /api/v1/bookings/{guid}/payment-intent` | Status persisted, `last_attempt` dan instruksi VA yang aman dipulihkan tanpa memanggil provider. |
| `POST /api/v1/bookings/{guid}/payment-attempts/{attempt}/refresh` | Owner-only. Memicu status inquiry DOKU terautentikasi untuk attempt yang masih `initiating/pending/unknown`, menerapkan event verified, lalu mengembalikan payload yang sama dengan `GET payment-intent`. Minimum 10 detik antar-inquiry per attempt (panggilan cepat berulang tidak memanggil DOKU lagi), throttle 20/menit, attempt terminal tidak memanggil DOKU. |
| `POST /api/v1/webhooks/doku/va` | Verifikasi HMAC raw-body minification SNAP, timestamp, merchant, channel dan reference. ACK `2002500` sesudah event durable. |
| `GET /api/v1/admin/bookings/{guid}/payment-intent` | Investigasi read-only bagi role 2 atau role 1 yang ditugaskan ke destinasi; maksimal 20 attempts/events terbaru, tanpa nomor VA atau raw payload. |

Create mengembalikan `booking_guid`, `intent_guid`, `attempt_guid`, `provider`, `environment`, `test_only`, `status`, `payment_status`, `amount`, `currency`, `method_code`, `channel`, `expires_at`, `reason`, `instruction`. Instruksi VA berisi `presentation_type=virtual_account`, `bank_code=bni`, `va_number`, `copyable=true`. Metadata query provider disimpan terenkripsi dan dibuang dari respons publik.

Request concurrent mendapatkan GUID attempt yang sama. Selama outbound pertama berjalan, status dapat masih `initiating`. Retry sesudah selesai mengembalikan snapshot respons untuk key yang sama; gunakan GET untuk status terkini. Jangan menganggap respons create atau redirect sebagai bukti paid.

Instruksi disembunyikan setelah deadline, status terminal, booking selesai/dibatalkan, environment berubah atau intent perlu review. Kill switch issuance tetap mengizinkan pemulihan instruksi pending yang valid dan pemrosesan callback existing.

## Recovery dan investigasi

`payments:reconcile-doku --limit=100` terjadwal setiap menit. Command menerapkan kembali event verified yang `recorded`, lalu mengantrekan inquiry attempts yang due. Claim inquiry disimpan atomik, request dilakukan di luar transaksi, dan interval retry memakai backoff serta jitter. Worker tidak pernah memanggil create ulang.

- Create timeout sebelum nomor VA diterima: `unknown`, kode `provider_reference_unavailable` ketika inquiry dicoba. Inquiry resmi membutuhkan nomor VA. Eskalasi merchant reference/request ID ke DOKU dan minta resend notifikasi; jangan menghapus attempt atau membuat ulang pembayaran otomatis.
- Inquiry `Pending`: tetap pending; `paidAmount` pada respons inquiry sendiri tidak cukup sebagai bukti capture karena contoh resmi Pending juga memuat field tersebut.
- Inquiry terminal yang belum dikenal: `unknown`, `provider_terminal_contract_unverified`. Minta fixture resmi/Sandbox beserta payment ID, waktu pembayaran dan semantics status sebelum menambah mapping.
- Queue down: callback yang sudah durable tetap dapat di-ACK; event `recorded` diproses ulang oleh command. Pantau kegagalan queue dan restart worker setelah perbaikan.
- Pembayaran terjadi sebelum deadline dan callback masuk selama status yang menahan kuota: boleh confirmed setelah seluruh invariant lolos dan visit belum dimulai.
- Pembayaran terjadi sesudah deadline, booking tidak eligible, attempt sudah superseded, nominal/reference tidak cocok: intent `needs_review`, tanpa tiket baru. Dana valid yang tidak dapat diterapkan dicatat `unapplied`, terpisah dari revenue.
- Occurrence provider maju maksimal lima menit: event menunggu waktu tersebut sebelum diterapkan. Jangan mengubah jam booking untuk meloloskan callback.
- Duplicate callback dengan payload yang setara: semantic dedupe, ACK aman. JSON key order/whitespace berbeda tidak menggandakan konfirmasi.
- Reversal/refund: domain memblokir eligibility dan mencabut QR yang belum digunakan; integrasi parser provider masih perlu kontrak resmi. Tidak tersedia endpoint admin untuk menandai paid atau mengirim normalized outcome manual.

Nominal biaya gateway, settlement, net revenue dan kelengkapan refund tetap unavailable bila tidak ada bukti provider. Data Sandbox memiliki `test_only=true` dan tidak menjadi revenue Production.

## Diagnosis `unknown` / `provider_unavailable` (PRD-FIX-DOKU-DANA-001, Gate A)

`last_error_code=provider_unavailable` hanya menyatakan bahwa create gagal sebelum respons yang dapat dipakai diterima. Penyebab tepatnya ada di log `DOKU provider request failed.` (`event=doku.provider_request_failed`, level warning), dikorelasikan lewat `attempt_guid`. Field yang dicatat: `phase`, `diagnostic_code`, `http_status`, `provider_response_code` (hanya bila cocok `^[A-Za-z0-9_-]{1,32}$`), `exception_class`, `duration_ms`, `request_sent`, `outcome_certainty`. Body, header, token, signature, URL redirect, `responseMessage` dan pesan exception tidak pernah dicatat. Attempt lama sebelum patch ini tidak punya record tersebut; penyebabnya tidak dapat direkonstruksi dan harus dianggap belum terkonfirmasi.

| `diagnostic_code` | `phase` | Arti | Pembayaran mungkin terbit? |
| --- | --- | --- | --- |
| `doku_token_http_error` | `b2b_token` | Endpoint token membalas non-2xx (credential/key salah, outage). | Tidak (`not_issued`) |
| `doku_token_response_invalid` | `b2b_token` | Token HTTP 2xx tetapi `responseCode`/`tokenType`/`accessToken` tidak valid. | Tidak |
| `doku_token_transport_error` | `b2b_token` | Timeout/koneksi saat meminta token. | Tidak |
| `doku_payment_http_error` | `payment_create` | Endpoint pembayaran membalas non-2xx; lihat `http_status` dan `provider_response_code`. | Mungkin (`unknown`) |
| `doku_payment_response_invalid` | `payment_create` | HTTP 2xx tetapi body bukan JSON object atau `partnerReferenceNo` tidak cocok. | Mungkin |
| `doku_payment_not_accepted` | `payment_create` | HTTP 2xx dengan `responseCode` bukan sukses (bukan `200xx00`). | Mungkin |
| `doku_payment_transport_timeout` | `payment_create` | Timeout/koneksi setelah request pembayaran mungkin terkirim. | Mungkin |
| `doku_redirect_host_rejected` | `payment_create` | `webRedirectUrl` bukan HTTPS atau host di luar `DOKU_EWALLET_REDIRECT_HOSTS`. | Mungkin; jangan arahkan pelanggan |
| `doku_internal_exception` | `internal`, `b2b_token`, `payment_create` | Kesalahan internal Laravel (kelas exception saja). | Tergantung fase; anggap mungkin |

Keputusan operator:

1. Klasifikasi tidak mengizinkan attempt baru. Status tetap `unknown`; frontend tetap menampilkan "jangan membayar ulang".
2. Kode `4xx` pada create tidak membuktikan pembayaran tidak terbit. Hanya `phase=b2b_token` yang membuktikan create tidak pernah dikirim.
3. Untuk DANA/ShopeePay, `payments:reconcile-doku` memakai inquiry `/orders/v1.0/debit/status` dengan `merchant_reference`; hasilnya satu-satunya jalan keluar dari `unknown` secara otomatis. Jangan memaksa `paid` atau menghapus attempt.
4. Bila inquiry tidak menemukan transaksi (`07`) atau tidak terselesaikan, eskalasi `attempt_guid`, `merchant_reference`, waktu dan `provider_response_code` ke DOKU. Percobaan Sandbox baru memerlukan persetujuan terpisah (Gate C) setelah ketidakpastian attempt lama diselesaikan dengan bukti otoritatif.

## Monitoring dan rollback

Log mencatat GUID attempt/event, provider/environment/channel, status, latency, duplicate delivery dan alasan review tanpa key/token/nomor VA/raw notification. Endpoint investigasi menunjukkan jadwal/count/error inquiry. Pantau event `recorded` menua, pending/unknown menua, `needs_review`, antrean gagal, HTTP 401 webhook dan worker/scheduler mati. Alarm/collector produksi belum dipasang dalam pekerjaan ini; gunakan access log gateway untuk tingkat penolakan signature.

Untuk menghentikan transaksi baru, set `DOKU_ENABLED=false`, refresh config dan restart worker. Pertahankan environment, secret, webhook dan worker lama untuk menyelesaikan transaksi issued. Jangan mengganti Sandbox menjadi Production pada deployment/database yang masih mempunyai transaksi unresolved.

Rotasi secret/key harus dikoordinasikan dengan DOKU dan transaksi in-flight. Simpan `APP_KEY`/previous keys dengan aman agar instruksi serta snapshot idempotensi lama tetap dapat didekripsi. Jangan menjalankan migration rollback yang menghapus kolom recovery setelah transaksi nyata tersimpan; utamakan forward fix. Rollback kode tidak boleh menghapus callback handler yang dibutuhkan transaksi pending.

## Multi-channel v2.0 (status kode)

Sumber kontrak per channel dan sel yang masih `BLOCKED_EXTERIOR` ada di `docs/prd/DOKU-CHANNEL-MATRIX.md`.

| Channel | Kode | Dapat membuat pembayaran | Dapat settle (paid) |
| --- | --- | --- | --- |
| `va_bni` | Ada, tidak berubah | Ya, bila config BNI valid (grandfathered, tanpa `DOKU_VERIFIED_CHANNELS`) | Ya |
| `va_bca`, `va_bri`, `va_mandiri` | Ada (profil bank pada `DokuClient::vaBank`) | Ya, bila ada di `DOKU_CHANNELS`, `DOKU_VERIFIED_CHANNELS`, dan BIN/prefix bank valid | Hanya bila `DOKU_<BANK>_NOTIFICATION_CHANNEL_ID` diisi dari notifikasi Sandbox asli |
| `ewallet_dana` | Ada (`DokuDirectDebitAdapter`) | Ya, bila return URL, `DOKU_EWALLET_REDIRECT_HOSTS`, `DOKU_CHANNELS` dan `DOKU_VERIFIED_CHANNELS` terisi | Ya, hanya dari status query terautentikasi (`00`); polling maksimum 3 menit |
| `ewallet_shopeepay` | Ada | Sama, ditambah `DOKU_SHOPEEPAY_PAYMENT_PATH` eksplisit (v1 atau v2, belum dikonfirmasi DOKU) | Sama dengan DANA |
| `qris` | Adapter generate ada (`DokuQrisAdapter`) | Tidak: `channel_contract_unverified` | Tidak: notifikasi dan status QRIS tidak terdokumentasi |
| `ewallet_ovo` | Belum ada adapter | Tidak (`channel_not_implemented`) | Tidak |

Gate fail-closed (`DokuChannelRegistry`): adapter ada, kontrak notifikasi terverifikasi, ada di `DOKU_CHANNELS`, ada di `DOKU_VERIFIED_CHANNELS` (kecuali BNI), parameter merchant valid, DOKU aktif. Parameter BNI yang hilang hanya menonaktifkan BNI. `GET payments/methods` tetap berisi tiga family (`qris`, `virtual_account`, `ewallet`) dan menambah daftar `channels` per family; key `channel` lama pada `virtual_account` dipertahankan.

Variabel baru (semuanya kosong secara default): `DOKU_VERIFIED_CHANNELS`, `DOKU_QRIS_{MERCHANT_ID,TERMINAL_ID,POSTAL_CODE}`, `DOKU_EWALLET_RETURN_URL`, `DOKU_EWALLET_REDIRECT_HOSTS`, `DOKU_SHOPEEPAY_PAYMENT_PATH`, `DOKU_{BCA,BRI,MANDIRI}_PARTNER_SERVICE_ID`, `DOKU_{BCA,BRI,MANDIRI}_CUSTOMER_PREFIX`, `DOKU_{BCA,BRI,MANDIRI}_NOTIFICATION_CHANNEL_ID`. Jangan memakai nilai BNI atau contoh dokumentasi untuk bank lain.

Catatan lingkungan lokal: nilai `.env` yang berisi path Windows dalam tanda kutip ganda (misalnya `DOKU_MERCHANT_PRIVATE_KEY_PATH="C:\laragon\..."`) membuat dotenv gagal diparse sehingga artisan tidak dapat boot. Gunakan tanda kutip tunggal atau garis miring depan.

## Return flow e-wallet (DANA, ShopeePay)

Alur: app `POST payment-attempts` → membuka `redirect_url` DOKU di browser/aplikasi e-wallet → e-wallet mengarahkan pelanggan ke `DOKU_EWALLET_RETURN_URL` → app dibuka kembali → app memanggil `POST .../payment-attempts/{attempt}/refresh` dan menampilkan hasil dari server.

**Nilai yang benar:**

```
DOKU_EWALLET_RETURN_URL=https://<domain-publik-API>/payment/return
```

- Harus HTTPS (adapter menolak selain itu), domain publik yang sama dengan host App Link app (`APP_LINK_HOST`), path persis `/payment/return` (route `payment.return`, `GET`). Tanpa `/api`, tanpa `/v1`, tanpa query, tanpa trailing slash, tanpa fragment, maksimum 255 karakter.
- Contoh: bila API di `https://api.wisatadaerahku.id`, isi `https://api.wisatadaerahku.id/payment/return`. Jangan memakai custom scheme (`wisatadaerahku://`) atau `http://`: DOKU `isDeepLink` dikirim `N` dan adapter hanya menerima HTTPS.
- Sandbox lokal: DOKU harus dapat menjangkau URL ini dari browser pelanggan, jadi gunakan domain HTTPS publik/tunnel, bukan `http://*.test`.
- Verifikasi route: `php artisan route:list --path=payment/return` harus menampilkan `GET|HEAD payment/return › payment.return`; `curl -i <URL>` harus `200` dengan `Cache-Control: no-store`, tanpa `Set-Cookie`. Tes `PaymentReturnFlowTest` mengunci bahwa path dari config sama dengan route.

**Prinsip keamanan:** halaman return statis, tanpa sesi/cookie, tanpa baca/tulis database dan tanpa memanggil DOKU. Parameter `result` (`success|cancel|error`) hanya petunjuk teks yang di-whitelist; parameter lain diabaikan, tidak pernah dipantulkan, dan DOKU tidak terdokumentasi menambahkannya. Redirect, deep link atau `result=success` tidak pernah mengonfirmasi booking; hanya status inquiry terautentikasi (via `refresh`, `payments:reconcile-doku`) yang dapat menghasilkan `paid`.

**Android App Links** (supaya return membuka app langsung):

1. Isi `MOBILE_ANDROID_SHA256_FINGERPRINTS` (comma list, uppercase, `AA:BB:..`) dengan sertifikat penandatangan app: `keytool -list -v -keystore <keystore> -alias <alias>` untuk upload key/debug, dan sertifikat *App signing key* dari Play Console (Setup → App signing) untuk build store. `MOBILE_ANDROID_PACKAGE` default `id.wisatadaerahku.app`.
2. `GET /.well-known/assetlinks.json` harus `200 application/json` pada domain yang sama, tanpa redirect. Bila fingerprint kosong/tidak valid endpoint menjawab `404` (tidak ada klaim palsu).
3. Build app dengan host yang sama lewat properti gradle `APP_LINK_HOST`. `flutter build` tidak menerima `-P` langsung, jadi gunakan environment variable (sudah diuji menghasilkan intent-filter yang benar pada manifest hasil merge): `ORG_GRADLE_PROJECT_APP_LINK_HOST=<domain-publik-API> flutter build apk --dart-define=APP_ENV=... --dart-define=API_BASE_URL=...` (PowerShell: `$env:ORG_GRADLE_PROJECT_APP_LINK_HOST='<domain>'`), atau tulis `APP_LINK_HOST=<domain>` di `android/gradle.properties`. Default `wisatadaerahku.invalid` tidak pernah cocok sehingga build tanpa nilai ini tidak membuka app dari return URL. Cek di perangkat: `adb shell pm get-app-links id.wisatadaerahku.app` harus `verified`.
4. Cadangan bila verifikasi belum selesai: tombol "Buka aplikasi" di halaman return memakai intent eksplisit `intent://<host>/payment/return#Intent;scheme=https;package=id.wisatadaerahku.app;end`, yang tetap membuka app yang benar.

**Perilaku saat pelanggan membatalkan atau error di e-wallet:** DOKU tidak mendokumentasikan pembatalan sebagai event terpisah. Attempt tetap `pending` sampai status query menjawab `05/06` (gagal) atau `validUpTo` lewat, lalu intent kembali `requires_payment`. App tidak membuat attempt baru selama attempt lama belum terminal (idempotensi); selama masih valid pelanggan dapat membuka ulang `redirect_url` yang sama.

**Belum dibuktikan (perlu UAT Sandbox/perangkat):** apakah DANA/ShopeePay benar-benar mengarahkan ke return URL dan apakah ada parameter tambahan; perilaku App Links di Chrome Custom Tab/WebView e-wallet pada perangkat nyata. Fixture dan tes widget tidak membuktikan keduanya.

## Payment + e-ticket v3.0 (Task 3–8, status kode)

Status: kode lokal + test; **belum** diverifikasi di merchant Sandbox. BNI/QRIS tetap fail-closed (`DokuClient::expiryWindowReason`) sampai bukti expiry ada (`DOKU-EXPIRY-EVIDENCE.md`).

| Area | Perilaku |
|---|---|
| Gerbang channel | `doku.verified_channels` (config) wajib memuat kode channel (`va_bni`, `qris`) agar *penerbitan baru* diizinkan. Callback/settlement channel yang sudah berjalan tidak ikut gerbang ini. Scope rilis: hanya BNI VA + QRIS. |
| QRIS payment QR | `GET /api/v1/bookings/{b}/payment-attempts/{a}/qr` -> SVG private/no-store, hanya pemilik, attempt QRIS `pending`, belum kedaluwarsa. Payload tidak pernah masuk log/JSON. |
| Kwitansi | `payment_receipt` event (satu per attempt terverifikasi, atomik dengan apply payment) + email via outbox. `GET /bookings/{b}/documents/receipt` (PDF dibuat on-demand dari ledger terverifikasi; `adjusted` bila ada refund/reversal terverifikasi). Sandbox diberi label `SANDBOX / TEST ONLY`. |
| Email | `notification_delivery_attempts` = audit append-only per percobaan; claim yang hilang (worker mati) ditandai `ambiguous` sebagai bukti dan delivery tetap di-retry, sehingga email ganda mungkin terjadi (SMTP tidak bisa dijamin exactly-once). Maksimal 10 percobaan lalu `failed/max_attempts_exceeded`. Email receipt/ticket hanya ke alamat `email_verified_at`; selain itu `cancelled/email_unverified`. |
| QR tiket | Per pengunjung, mulai tengah malam lokal H-5 (timezone destinasi). Setelah pembayaran terverifikasi dan jendela terbuka, job `IssueBookingTicketQr` (unique per booking) dijalankan after-commit; kegagalan antrean tidak membatalkan pembayaran; `tickets:issue-qr` (tiap menit) = jalur pemulihan. Event `ticket_issued` (key `booking:{b}:ticket_issued`) sekali; berisi booking, destinasi, tanggal, jumlah, cara masuk, tanpa isi QR. |
| Tiket grup legacy | Baris `tickets` lama dipertahankan sebagai riwayat dan tidak lagi dibuat. `documents.ticket` tetap ada (kompatibel): `{guid, status, issued_at, legacy:true, admission:false, download_available}`; untuk booking `confirmed` tanpa baris lama berisi `{status:'confirmation', legacy:false, admission:false, download_available:true}`. `GET /documents/ticket` mengunduh PDF **Konfirmasi Booking** on-demand ("BUKAN tiket masuk", tanpa token) selama booking `confirmed` dan tiket tidak `revoked`; selain itu 404. Token lama tidak pernah dikirim/diunduh. Ketersediaan QR ditentukan `entry_tickets.state`, bukan status dokumen grup. |
| Entitlement | `entry_tickets.entitlement` dan `tickets.entitlement`: `paid` (ledger terverifikasi), `free` (booking Rp0, IDR, `confirmed` lewat persetujuan admin (riwayat `pending_approval->confirmed` dengan aktor), tanpa `payment_intent` sama sekali), `refunded` (refund settled), atau status pembayaran apa adanya. `free` tidak membuat intent DOKU dan tidak pernah dianggap `PAID` provider; `payment_status` tidak berubah. QR per pengunjung terbit dari H-5 dengan aturan kuota/approval yang sama; scanner check-in menerima `paid` atau `free`. |
| Email e-ticket | `ticket_issued` memuat tautan `{TICKET_LINK_BASE_URL}/tickets/{booking_guid}` hanya bila env HTTPS valid diisi. Tautan tidak membawa token/QR; halaman web tidak membaca data booking, hanya membuka aplikasi. Autentikasi dan kepemilikan dipaksa `GET /api/v1/bookings/{b}/tickets` (401 / 403 non-pemilik). Email pembayaran dan email tiket tetap dua event bisnis terpisah, masing-masing idempoten. App Link manifest memuat `pathPrefix="/tickets/"`. |
| Refund | `POST /bookings/{b}/refund-requests` (Idempotency-Key, hanya `reason`, nominal = capture penuh dari server), admin ter-scope `POST /admin/bookings/{b}/refund-requests/{r}/decision`. Approve -> `manual_action_required` (belum ada transport refund provider; approve **bukan** uang kembali). `refunded` HANYA dari event refund/reversal terverifikasi; tiket yang sudah dipakai -> `needs_review`. |
| Settlement refund manual | `POST /admin/bookings/{b}/refund-requests/{r}/manual-settlement` (admin ter-scope/superadmin): `settlement_method` (`bank_transfer|ewallet_transfer|cash_disbursement`), `settlement_reference` (unik global, 6-128 karakter), `settlement_amount` (harus = nominal request), `settled_at` (tidak di masa depan, tidak sebelum request), `evidence_note` (>=10 karakter), `proof_verified=true`. Hanya dari `manual_action_required` -> `manual_refunded` (`refund_channel='manual'`, aktor + waktu + audit event). Tidak menulis `payment_*` dan tidak membuat event DOKU palsu. QR yang belum dipakai dicabut dalam transaksi yang sama; admission sudah terpakai -> request ke `needs_review` dan settlement ditolak (409 `ticket_already_used`). Pencegahan double refund: request baru ditolak (`refund_already_settled`), settlement setelah refund provider ditolak, dan refund provider yang datang setelah settlement manual hanya menandai `review_reason='duplicate_refund_detected'` + audit + log error (status tetap `manual_refunded`). |
| Attempt lewat jendela instruksi | Attempt `initiating/pending/unknown` yang melewati `instruction_expires_at` tanpa bukti terminal tetap terbuka: `last_attempt.verification_pending=true`, initiate ulang mengembalikan attempt yang sama (tanpa attempt baru, tanpa instruksi VA/QR), booking tetap menahan kuota, rekonsiliasi terus berjalan. `payments:reconcile-doku` meng-eskalasi sekali (`payment_attempts.escalated_at/escalation_reason`, log error terstruktur `DOKU payment attempt unresolved after its instruction window`, `last_attempt.escalated=true`). Bukti DOKU yang datang terlambat tetap diproses normal. Operator: cari `escalated_at IS NOT NULL` pada attempt status terbuka. |

Pemulihan operator: email gagal -> lihat `notification_deliveries.last_error_code` dan `notification_delivery_attempts`; QR belum terbit -> `php artisan tickets:issue-qr`; refund `manual_action_required` -> transfer manual atau refund di dashboard DOKU. Transfer manual dicatat lewat endpoint `manual-settlement` (jangan mengubah status di DB); refund DOKU menunggu notifikasi/inquiry terverifikasi.

**PROVIDER_BLOCKED** (tidak ada bukti DOKU, tidak diimplementasi): terminal inquiry/unknown-create recovery BNI, QRIS notification signature + body, QRIS query/cancel/refund, window expiry min/max BNI & QRIS, transport refund otomatis, jaminan SMTP exactly-once.

## Sumber protokol

- [BNI SNAP v1.1 dan OpenAPI DGPC/notification](https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/bni-virtual-account)
- [B2B token](https://developers.doku.com/get-started-with-doku-api/get-token-api/b2b)
- [Symmetric signature SNAP](https://developers.doku.com/get-started-with-doku-api/signature-component/snap/symmetric-signature)
- [Inquiry SNAP](https://developers.doku.com/get-started-with-doku-api/check-status-api/snap)

Dokumentasi inquiry menyebut respons mirip notification dan menyediakan contoh BNI Pending. Implementasi sengaja menahan bentuk terminal yang belum tervalidasi. Fixture HTTP membuktikan implementasi lokal mengikuti kontrak yang diuji; perilaku merchant nyata tetap perlu UAT.
