# Handoff Backend Wisata Daerahku

Tanggal handoff: 29 Oktober 2026  
Acuan: `PRD-Wisata-Daerahku-Backend.md` dan implementasi pada working tree saat dokumen ini dibuat.

## Status

Backend masih dalam pengembangan dan **belum siap dirilis ke produksi**. Modul independen berikut telah diimplementasikan, tetapi integrasi payment gateway dan pengujian konkurensi MySQL belum selesai. Perubahan masih berada di working tree dan belum berarti sudah di-commit atau di-deploy.

## Implementasi yang tersedia

### Identitas dan akun

- Register dan login email/password; register publik tidak dapat menentukan role atau status.
- Pertukaran Firebase ID token Google untuk JWT API, tanpa otomatis menggabungkan akun berdasarkan email yang sama.
- JWT access berlaku 15 menit; refresh token berlaku 30 hari, disimpan sebagai hash dan dirotasi. Reuse mencabut sesi terkait.
- Endpoint profil, verifikasi email melalui signed URL, serta forgot/reset password. Email menggunakan antrean.
- Logout, reset password, perubahan privilege/status dan penutupan akun mencabut sesi serta token perangkat sesuai cakupannya. Penutupan akun memerlukan autentikasi ulang dan mempertahankan data transaksi.
- Superadmin dapat membuat admin dan mengubah role/status. Superadmin aktif terakhir dilindungi. Bootstrap pertama melalui command interaktif `php artisan app:bootstrap-superadmin`.

Kode utama: `app/Services/AuthService.php`, `AccountService.php`, `AdminService.php`, `app/Http/Middleware/AuthenticateApiToken.php`, serta `config/api_tokens.php`.

### Katalog, kuota dan favorit

- Daftar kategori aktif serta CRUD/deaktivasi oleh superadmin.
- Daftar/detail, pencarian dan filter kategori destinasi; admin dapat membuat, mengubah dan menonaktifkan destinasi. Upload gambar divalidasi.
- Admin mengatur kuota per destinasi dan tanggal. Kapasitas tidak dapat diturunkan di bawah jumlah orang yang telah dialokasikan.
- Pengguna dapat melihat, menambahkan dan menghapus favorit secara idempotent.

Kode utama: `app/Services/CatalogService.php`, `QuotaService.php`, dan `FavoriteService.php`.

### Booking dan alokasi

- Pembuatan booking memerlukan header `Idempotency-Key`. Key yang sama dan input sama mengembalikan hasil awal; input berbeda menghasilkan konflik.
- Harga dan total dihitung server. Booking menyimpan snapshot nama destinasi, harga dan kontak pengunjung.
- Kuota dihitung berdasarkan jumlah orang pada status yang masih mengalokasikan kapasitas. Perubahan alokasi menggunakan transaksi dan mengunci kuota sebelum booking.
- Pengguna melihat booking miliknya; admin dapat melihat daftar dan menyetujui atau menolak booking. Approval melewati deadline ditolak.
- Booking gratis yang disetujui menjadi `confirmed`. Booking berbayar menjadi `awaiting_payment`, lalu `payment_verification` dan `payment_review` setelah deadline dan grace. Tanpa provider yang dapat memastikan status, alokasi tidak dilepas sebagai unpaid.
- Cutoff saat ini bersifat **sementara dan konservatif**: pembuatan harus lebih dari 25 jam 15 menit sebelum awal tanggal kunjungan di zona waktu destinasi; approval lebih dari 1 jam 15 menit sebelumnya. Kebijakan final perlu keputusan produk sebelum rilis.

Kode utama: `app/Services/BookingService.php`, `app/Console/Commands/ExpireBookings.php`, dan migrasi `2026_10_29_080000_create_booking_tables.php`.

### Notifikasi dan perangkat

- Inbox notifikasi disimpan di database; pengguna dapat melihat daftar, jumlah belum dibaca dan menandai notifikasi miliknya sebagai dibaca.
- Event booking dan record pengiriman dibuat dalam transaksi domain. Dispatcher dan job memproses email/FCM dengan retry dan backoff.
- Token perangkat dikaitkan dengan pengguna dan sesi. Payload FCM hanya memuat GUID notifikasi dan booking; perangkat mengambil detail melalui API yang memerlukan autentikasi.
- Pengiriman eksternal dapat terduplikasi jika acknowledgment hilang. Push yang sudah dalam proses pengiriman dapat tiba setelah logout; payload tidak berisi data pribadi.

Kode utama: `app/Services/BookingNotificationService.php`, `InboxService.php`, `app/Jobs/DeliverBookingNotification.php`, dan `app/Console/Commands/DispatchBookingNotifications.php`.

### Dokumen privat

- Booking `confirmed` diproses untuk menghasilkan invoice konfirmasi dan tiket rombongan PDF, maksimal satu record masing-masing per booking.
- Invoice saat ini adalah **konfirmasi booking, bukan bukti pembayaran**. Waktu dan semantik invoice final masih perlu disepakati.
- Metadata dan unduhan dibatasi berdasarkan pemilik atau role admin; PDF disimpan pada disk `local` privat. Scheduler menyapu dokumen yang belum dibuat dan job dapat dicoba ulang.
- Crash setelah menulis file tetapi sebelum commit dapat meninggalkan file privat yang tidak terpakai. File yang terhapus setelah record dibuat belum otomatis diregenerasi.

Kode utama: `app/Services/BookingDocumentService.php`, `app/Jobs/GenerateBookingDocuments.php`, `app/Console/Commands/DispatchBookingDocuments.php`, dan migrasi `2026_10_30_010000_create_document_tables.php`.

## API yang tersedia

Prefix seluruh rute adalah `/api/v1`. Rute terlindungi memakai `Authorization: Bearer <access_token>`. Definisi dan middleware lengkap ada di `routes/api.php`; daftar aktual dapat diperiksa dengan `php artisan route:list --path=api`.

| Area | Rute utama |
| --- | --- |
| Auth | `POST /auth/register`, `/auth/login`, `/auth/google`, `/auth/refresh`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password`; `GET /auth/me`; rute verifikasi email |
| Profil dan admin | `PATCH /profile`, `POST /profile/close`, `POST /admin/accounts`, `PATCH /admin/accounts/{user}` |
| Katalog | `GET /categories`; `GET /destinations`, `GET /destinations/{destination}`; rute create/update/deactivate sesuai role |
| Kuota | `GET` dan `PUT /destinations/{destination}/quotas` |
| Favorit | `GET /favorites`; `PUT` dan `DELETE /favorites/{destination}` |
| Booking | `GET` dan `POST /bookings`, `GET /bookings/{booking}`, `GET /admin/bookings`, `POST /admin/bookings/{booking}/approve` atau `/reject` |
| Dokumen | `GET /bookings/{booking}/documents`, `GET /bookings/{booking}/documents/{invoice|ticket}` |
| Notifikasi dan perangkat | `GET /notifications`, `GET /notifications/unread-count`, `PATCH /notifications/{guid}/read`, `PUT` dan `DELETE /devices` |

Contoh payload setup dan booking tersedia di `README.md`. Tidak ada endpoint checkout, webhook payment, review atau check-in saat handoff ini dibuat.

## Setup dan operasi lokal

1. Siapkan PHP 8.3+, Composer, dan database MySQL khusus yang kosong. Jalankan `composer install`, salin `.env.example` menjadi `.env`, lalu `php artisan key:generate`.
2. Isi konfigurasi database serta `JWT_SECRET` acak minimal 32 karakter. Sesuaikan `JWT_ISSUER`, `JWT_AUDIENCE`, `FIREBASE_PROJECT_ID`, `FIREBASE_CREDENTIALS` untuk FCM, konfigurasi SMTP dan `PASSWORD_RESET_URL` untuk aplikasi klien. Jangan menyimpan rahasia di repository.
3. Jalankan `php artisan migrate` pada database yang memang disiapkan untuk proyek ini, lalu `php artisan app:bootstrap-superadmin` secara interaktif.
4. Gunakan `QUEUE_CONNECTION=database`, jalankan `php artisan queue:work`, dan jadwalkan `php artisan schedule:run` setiap menit. Scheduler memanggil `bookings:expire`, `notifications:dispatch`, dan `documents:dispatch`.

Migrasi users awal telah diubah dari skeleton menjadi UUID. Database yang sebelumnya sudah memakai migrasi lama memerlukan rencana migrasi tersendiri; jangan menganggap menjalankan migrasi ulang aman untuk data yang ada. Tabel infrastruktur Laravel mempertahankan format vendor yang relevan.

## Pemeriksaan yang pernah dijalankan

Hasil pada implementasi sebelumnya, 29 Oktober 2026:

- **PASS** `php artisan test --compact`: 22 tes, 67 assertion, menggunakan SQLite.
- **PASS** `vendor/bin/pint --dirty --test`, `composer audit --no-interaction`, dan `git diff --check`.
- **PASS** pemeriksaan daftar rute dan scheduler lokal; daftar scheduler diperiksa dengan cache array.
- **NOT RUN** uji race/concurrency pada MySQL nyata. Database MySQL yang dikonfigurasi belum tersedia saat pemeriksaan; tes SQLite tidak membuktikan row locking.
- **NOT RUN** verifikasi Firebase/FCM dengan kredensial dan perangkat nyata, SMTP nyata, sandbox gateway, staging, dan produksi. Tes FCM yang ada menggunakan mock.

Hasil di atas adalah catatan pemeriksaan sebelumnya, bukan hasil tes ulang saat dokumen ini ditulis.

## Pekerjaan dan keputusan lanjutan

- Pilih provider pembayaran, lalu implementasikan checkout, webhook terverifikasi, rekonsiliasi dan penanganan pembayaran terlambat/konflik sesuai PRD. Mock bukan integrasi produksi.
- Finalisasi cutoff kunjungan, durasi dan prosedur `payment_review`, refund/cancellation, semantik invoice, impor data Firebase lama, check-in dan eligibility review.
- Tambahkan dan jalankan uji MySQL untuk oversell, approval versus expiry, payment versus release, idempotency dan kasus konkurensi lain sesuai acceptance PRD.
- Verifikasi kredensial dan pengiriman Firebase/FCM serta SMTP, worker, scheduler dan monitoring pada lingkungan target. Pastikan strategi operasional untuk orphan atau kehilangan file PDF.
- Susun migrasi untuk database yang sudah terisi sebelum menjalankan perubahan schema pada lingkungan tersebut.
