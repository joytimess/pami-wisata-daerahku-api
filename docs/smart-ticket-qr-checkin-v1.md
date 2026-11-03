# Smart Ticket QR Check-in V1

Implementasi mengikuti `docs/prd/PRD-Wisata-Daerahku-Smart-Ticket-QR-Checkin-V1.md` pada checkout Laravel dan Flutter lokal. QR adalah credential per unit tiket; PDF tiket rombongan dan invoice konfirmasi tetap mengikuti policy lama. Invoice bukan bukti lunas.

## Pembayaran: prerequisite yang masih terbuka

Checkout ini belum memiliki adapter DOKU Sandbox/Demo Simulator atau ledger pembayaran otoritatif. `App\Services\PaymentEligibility` menjadi guard integrasi dan secara default mengembalikan `unverified`. Status booking `confirmed`, harga nol, input Flutter, dan query parameter tidak dapat menjadikan pembayaran lunas.

Akibatnya, aplikasi nyata menampilkan **Menunggu pembayaran terverifikasi** dan tidak menerbitkan QR sampai adapter otoritatif dipasang. Tidak ada endpoint untuk memalsukan pembayaran. Implementasi payment berikutnya harus mengikat guard ini pada record pembayaran backend yang telah terverifikasi, serta menyelaraskan lock pembayaran dengan lock booking agar refund/reversal tidak berlomba dengan konfirmasi check-in. Stub pembayaran pada smoke test hanya berlaku di database test.

## Kontrak API

Seluruh path berikut memakai prefix `/api/v1`, Bearer session Laravel existing, dan wrapper JSON `{"data": ...}` kecuali respons SVG.

| Method | Path | Akses dan hasil |
| --- | --- | --- |
| GET | `/bookings/{booking}/tickets` | Pemilik; tanggal kunjungan, timezone, tanggal H-5, state, progress, daftar unit tanpa secret |
| GET | `/bookings/{booking}/tickets/{ticket}/qr` | Pemilik; SVG privat jika layak, `Cache-Control: private, no-store` |
| GET | `/admin/ticket-check-ins/scope` | Petugas; `{global, destinations:[{guid,name}]}` |
| POST | `/admin/ticket-check-ins/preview` | Petugas dalam scope; `{qr_payload}`; preview tanpa check-in |
| POST | `/admin/ticket-check-ins/confirm` | Petugas dalam scope; `{qr_payload}` dan header `Idempotency-Key`; atomic check-in |
| GET | `/admin/destinations/{destination}/admins` | Superadmin; `{user_guids:[...]}` |
| PUT | `/admin/destinations/{destination}/admins` | Superadmin; body `{user_guids:[...]}` mengganti assignment secara atomik |
| POST | `/bookings/{booking}/check-in` | Dinonaktifkan untuk seluruh role; tidak ada bypass self-check-in |

Payload scan berupa `WDR1.` diikuti base64url dari 32 random bytes. Secret tidak dikirim dalam JSON ticket list/preview, URL, notifikasi, atau dokumen publik. Lookup memakai SHA-256 dan render ulang memakai ciphertext Laravel Crypt. Pertahankan `APP_KEY`; menggantinya tanpa migrasi key membuat ciphertext lama tidak dapat dibaca. Secret yang rusak tidak diterbitkan ulang diam-diam.

Konfirmasi dari satu percobaan harus mempertahankan payload dan idempotency key yang sama ketika timeout. Key sama dengan payload berbeda menghasilkan `idempotency_conflict`; key berbeda untuk tiket terpakai menghasilkan `ticket_already_checked_in`. Preview tidak memberikan izin permanen: payment, approval, tanggal lokal, scope, dan penggunaan diperiksa kembali saat commit.

## State dan waktu

`ticket_state` menggunakan `awaiting_payment`, `awaiting_approval`, `scheduled`, `available`, `partially_checked_in`, `fully_checked_in`, `expired`, atau `revoked`. Setiap unit memiliki status dan `qr_available` dari server. Flutter tidak menentukan eligibility dari jam perangkat.

QR statis tersedia mulai H-5 kalender lokal destinasi sampai hari H, hanya setelah paid otoritatif dan booking confirmed. Scheduler menyiapkan secret; GET menjadi lazy fallback untuk pembayaran/persetujuan yang terlambat. Refresh tidak mengganti token. Scan hanya pada hari H. Tiket yang sudah digunakan atau tidak lagi layak tidak memberikan SVG aktif.

Role 1 memerlukan assignment eksplisit; destinasi lama tidak otomatis ditugaskan ke seluruh Admin. Role 2 memiliki scope global. Superadmin mengatur penugasan lewat UI pengelolaan destinasi Flutter. Otorisasi akhir selalu di Laravel.

## Migrasi dan operasi

Migration baru menambah unit tiket, audit check-in, dan assignment tanpa menghapus `check_ins` legacy. Jalankan migrasi sebelum melayani versi aplikasi baru, lalu backfill booking existing. Backfill memakai batching dan dapat diulang; satu legacy booking check-in berarti seluruh quantity sudah masuk, dengan waktu dan actor historis yang sama. Tiket tersebut tidak mendapatkan QR baru.

Review tetap satu per booking setelah sedikitnya satu tiket masuk. Legacy check-in mempertahankan eligibility historis; jumlah kunjungan profil tetap dihitung per booking, sehingga quantity tidak menggandakan statistik.

Urutan setup dari direktori Laravel:

```powershell
composer install
php artisan migrate --no-interaction
php artisan tickets:backfill --no-interaction
php artisan tickets:issue-qr --no-interaction
php artisan schedule:work
```

Migration `2026_11_03_130325_create_smart_ticket_tables.php` menambah `bookings.destination_timezone_snapshot`, `booking_tickets`, `ticket_check_ins`, `destination_admin_assignments`, dan `destination_admin_assignment_histories`. FK mempertahankan audit; tidak ada cascade delete. Snapshot diisi untuk booking baru; booking lama memakai timezone destinasi existing tanpa mengubah tanggal kunjungannya.

`tickets:issue-qr` dijadwalkan setiap menit dengan overlap protection. Untuk server target, scheduler Laravel tetap dipicu melalui `schedule:run` setiap menit atau mekanisme hosting existing. Tidak ada worker khusus QR; worker notifikasi dan dokumen existing tetap dibutuhkan. Jalankan backfill sebelum aplikasi baru dibuka. Jangan melakukan rollback migration setelah ada audit check-in baru tanpa rencana preservasi data.

Dependency baru yang terkunci: `bacon/bacon-qr-code` **3.1.1** (SVG tanpa Imagick; membutuhkan `ext-iconv` dan `ext-xmlwriter` sesuai platform package), Flutter `mobile_scanner` **7.5.0** dan `flutter_svg` **2.3.2**. Flutter memakai client/session existing untuk SVG bytes, tanpa `qr_flutter` atau client HTTP tambahan. Android menambah izin kamera dan deklarasi camera hardware optional; iOS menambah alasan penggunaan kamera. Registrant macOS mengikuti plugin yang terpasang.

## Batas verifikasi

Smoke test dengan adapter pembayaran test membuktikan logika lokal, bukan pembayaran DOKU atau scan kamera fisik. Row lock dan unique constraint diterapkan untuk single-use; smoke berurutan bukan bukti perlombaan dua koneksi MySQL. Static QR dapat digunakan terlebih dahulu oleh pemegang screenshot; petugas tetap memeriksa preview sebelum konfirmasi.

## Peta file implementasi

Laravel:

- `app/Services/TicketService.php`: inisialisasi unit, backfill, eligibility, issuance, token Crypt/hash, SVG, list owner.
- `app/Services/TicketCheckInService.php`: scope, preview, transaksi confirm, replay idempotent, penugasan dan audit assignment.
- `app/Services/PaymentEligibility.php`: guard pembayaran fail-closed yang dapat diikat pada adapter backend otoritatif.
- `app/Services/BookingService.php`, `VisitService.php`, `app/Http/Resources/BookingResource.php`: unit saat create, agregasi, review, statistik, dan penutupan self-check-in.
- `app/Http/Controllers/Api/V1/TicketController.php`, `TicketCheckInController.php`, `VisitController.php`; `app/Http/Requests/TicketScanRequest.php`, `DestinationAdminsRequest.php`; `app/Exceptions/TicketException.php`: boundary API dan error terkontrol.
- `app/Console/Commands/BackfillBookingTickets.php`, `IssueEligibleTicketQr.php`; `routes/api.php`, `routes/console.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`: command, jadwal, throttle petugas/IP, dan sanitasi error QR.
- Migration smart ticket, `composer.json`/`composer.lock`, smoke dan fixture kunjungan yang terdampak.

Flutter (checkout `C:\laragon\www\pami-mobile\pami-wisata-daearahku`):

- `lib/data/business_repository.dart`, `booking_state.dart`, `ticket_models.dart`, `ticket_scanner_state.dart`: kontrak tiket dan state session, preview/confirm, retry key yang sama.
- `lib/widgets/booking_ticket_qr_section.dart`: selector unit, SVG terpilih, progress/status individual, refresh resume, penghapusan QR saat session berubah/dispose.
- `lib/screens/bukti_pendaftaran_screen.dart`: integrasi ke detail existing, penghapusan self-check-in; PDF dan form ulasan tetap mengikuti server.
- `lib/screens/admin_ticket_scanner_screen.dart`, `destination_admin_assignments_screen.dart`, `admin_dashboard.dart`: kamera QR, konfirmasi eksplisit, hasil server, izin kamera, penugasan Superadmin, dan entry dashboard.
- `pubspec.yaml`/`pubspec.lock`, Android manifest, iOS Info.plist, macOS generated plugin registrant, serta smoke/fixture Flutter terkait.

## Hasil lokal

- **PASS**: migration tambahan pada environment `local`, batch 6; `tickets:backfill` memproses 4 booking existing.
- **PASS**: `tickets:issue-qr` berjalan dan memproses 0 booking confirmed; tidak membuat pembayaran atau QR palsu.
- **PASS**: registrasi 5 route tiket/scanner, 2 route assignment, dan jadwal issuance setiap menit diperiksa lewat Artisan.
- **PASS**: `SmartTicketSmokeTest` dan fixture `VisitReviewApiTest` yang terdampak, total 24 test/225 assertions pada database SQLite terisolasi (~2,2 detik). Skenario mencakup paid guard khusus test, uniqueness/stabilitas SVG, H-5/hari H/timezone, preview tanpa mutasi, confirm/replay/conflict, scope, reversal setelah preview, dan legacy review/statistik. Fixture worker MySQL diperbarui, tetapi suite MySQL tidak dijalankan.
- **PASS**: Laravel Pint `--dirty --format agent`, `composer validate`, dan `git diff --check` kedua checkout.
- **PASS**: analisis terfokus 11 file Dart/test berubah tanpa issue; dashboard memiliki dua warning palette unused yang sudah ada. Format Dart lulus. Smoke Flutter awal 19 PASS/1 kegagalan timing mock; setelah perbaikan async flush, rerun dua smoke Smart Ticket lulus (2 PASS/0 FAIL). Tidak ada referensi API self-check-in lama pada `lib/` dan `test/`.
- **PASS**: satu build native `flutter build apk --debug --no-pub` (~123 detik), menghasilkan `build/app/outputs/flutter-apk/app-debug.apk`. Build memuat plugin scanner; APK belum diinstal untuk pembuktian kamera. Ada warning kompatibilitas Kotlin Gradle existing untuk Flutter mendatang; build versi lokal berhasil.
- **NOT VERIFIED**: pembayaran DOKU, kamera Android fisik, concurrency dua koneksi MySQL, serta staging/produksi. Tidak menjalankan full regression, full Flutter test/analyze, commit, push, atau deploy.

## Smoke manual setelah adapter pembayaran tersedia

1. Superadmin menugaskan Admin aktif ke satu destinasi melalui menu Penugasan Destinasi. Gunakan booking quantity 2 dengan pembayaran sandbox yang sah dan approval, serta tanggal H-5 sampai H. Detail Tiket harus menampilkan dua QR berbeda yang tetap sama setelah refresh. Sebelum H-5, hanya tanggal ketersediaan yang muncul.
2. Pada hari H lokal destinasi, buka Scan Tiket dengan Admin assigned. Scan tiket pertama: preview muncul dan progress masih 0/2. Tekan Konfirmasi Check-in: sukses dan progress 1/2. Muat ulang Detail Tiket pemilik untuk status individual.
3. Scan ulang tiket pertama harus ditolak sebagai sudah check-in; tiket kedua tetap dapat dipakai. Jika confirm timeout, gunakan Coba Konfirmasi Lagi agar payload/key tetap sama.
4. Pastikan Admin di luar scope dan role User ditolak, H-1/H+1 ditolak, serta reversal/refund setelah preview ditolak saat konfirmasi. Offline atau izin kamera ditolak harus memberi tindakan retry/pengaturan tanpa menulis check-in lokal.
5. Periksa satu kunjungan legacy: seluruh quantity sudah masuk, ulasan tetap eligible sesuai status historis, jumlah kunjungan profil tetap satu booking, dan unduhan PDF existing tetap mengikuti policy server.
