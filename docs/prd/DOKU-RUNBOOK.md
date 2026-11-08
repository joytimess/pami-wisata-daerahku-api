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
| M7 | PARTIAL (v2.0). Registry channel dan VA BCA/BRI/Mandiri tersedia di kode, diuji dengan fixture; belum ada transaksi Sandbox. QRIS dan e-wallet belum diimplementasikan karena kontrak notifikasi resmi belum lengkap (lihat `DOKU-CHANNEL-MATRIX.md`); capability tetap unavailable. |
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
| `qris`, `ewallet_dana`, `ewallet_shopeepay`, `ewallet_ovo` | Belum ada adapter | Tidak (`channel_not_implemented`) | Tidak |

Gate fail-closed (`DokuChannelRegistry`): adapter ada, kontrak notifikasi terverifikasi, ada di `DOKU_CHANNELS`, ada di `DOKU_VERIFIED_CHANNELS` (kecuali BNI), parameter merchant valid, DOKU aktif. Parameter BNI yang hilang hanya menonaktifkan BNI. `GET payments/methods` tetap berisi tiga family (`qris`, `virtual_account`, `ewallet`) dan menambah daftar `channels` per family; key `channel` lama pada `virtual_account` dipertahankan.

Variabel baru (semuanya kosong secara default): `DOKU_VERIFIED_CHANNELS`, `DOKU_{BCA,BRI,MANDIRI}_PARTNER_SERVICE_ID`, `DOKU_{BCA,BRI,MANDIRI}_CUSTOMER_PREFIX`, `DOKU_{BCA,BRI,MANDIRI}_NOTIFICATION_CHANNEL_ID`. Jangan memakai nilai BNI atau contoh dokumentasi untuk bank lain.

Catatan lingkungan lokal: nilai `.env` yang berisi path Windows dalam tanda kutip ganda (misalnya `DOKU_MERCHANT_PRIVATE_KEY_PATH="C:\laragon\..."`) membuat dotenv gagal diparse sehingga artisan tidak dapat boot. Gunakan tanda kutip tunggal atau garis miring depan.

## Sumber protokol

- [BNI SNAP v1.1 dan OpenAPI DGPC/notification](https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/bni-virtual-account)
- [B2B token](https://developers.doku.com/get-started-with-doku-api/get-token-api/b2b)
- [Symmetric signature SNAP](https://developers.doku.com/get-started-with-doku-api/signature-component/snap/symmetric-signature)
- [Inquiry SNAP](https://developers.doku.com/get-started-with-doku-api/check-status-api/snap)

Dokumentasi inquiry menyebut respons mirip notification dan menyediakan contoh BNI Pending. Implementasi sengaja menahan bentuk terminal yang belum tervalidasi. Fixture HTTP membuktikan implementasi lokal mengikuti kontrak yang diuji; perilaku merchant nyata tetap perlu UAT.
