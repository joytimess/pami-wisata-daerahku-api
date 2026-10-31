# Wisata Daerahku Backend API

REST API berbasis Laravel 13 untuk aplikasi Flutter Wisata Daerahku. Backend ini menggantikan Firebase sebagai sumber data utama: seluruh data bisnis (akun, katalog, kuota, booking, notifikasi, dokumen, check-in, review) disimpan di MySQL dan dikelola oleh Laravel. Firebase hanya dipakai untuk dua hal: verifikasi ID token Google Sign-In dan pengiriman push notification (FCM).

> **Status:** migrasi Firebase → Laravel telah selesai hingga Phase 6, tetapi backend **belum siap produksi**. Integrasi payment gateway belum ada, kebijakan cutoff kunjungan masih sementara, dan beberapa integrasi eksternal (Firebase nyata, SMTP, FCM ke perangkat nyata) belum terverifikasi. Lihat [Known Limitations](#16-known-limitations).

## Daftar Isi

1. [Project Overview](#1-project-overview)
2. [Technology Stack](#2-technology-stack)
3. [System Architecture](#3-system-architecture)
4. [Key Features](#4-key-features)
5. [Project Structure](#5-project-structure)
6. [Requirements](#6-requirements)
7. [Installation & Local Development](#7-installation--local-development)
8. [Environment Configuration](#8-environment-configuration)
9. [Authentication & Authorization](#9-authentication--authorization)
10. [API Documentation](#10-api-documentation)
11. [Queue, Scheduler & Notifications](#11-queue-scheduler--notifications)
12. [Database & Booking Integrity](#12-database--booking-integrity)
13. [Testing](#13-testing)
14. [Development Workflow](#14-development-workflow)
15. [Security Considerations](#15-security-considerations)
16. [Known Limitations](#16-known-limitations)

---

## 1. Project Overview

Wisata Daerahku adalah aplikasi pemesanan tiket wisata daerah. Backend ini menyediakan:

- autentikasi dan manajemen sesi (JWT access token + refresh token yang dirotasi);
- katalog kategori dan destinasi wisata, termasuk gambar destinasi;
- kuota kunjungan per destinasi per tanggal;
- booking rombongan dengan idempotency, alur persetujuan admin, dan pelepasan kapasitas otomatis saat deadline terlewati;
- inbox notifikasi, email, dan push notification FCM;
- invoice konfirmasi dan tiket rombongan dalam bentuk PDF privat;
- favorit, check-in kunjungan, review, dan statistik profil.

Laravel adalah pusat business logic. Klien (Flutter) tidak menentukan harga, role, atau status; semuanya dihitung dan divalidasi di server.

## 2. Technology Stack

| Komponen | Teknologi |
| --- | --- |
| Framework | Laravel 13 (`laravel/framework ^13.17`) |
| Bahasa | PHP `^8.3` |
| Database | MySQL (utama); SQLite in-memory hanya untuk test Feature/Unit |
| Autentikasi API | JWT HS256 via `firebase/php-jwt ^7.2`, implementasi sendiri di `AuthService` |
| Google Sign-In | Verifikasi Firebase ID token via `kreait/firebase-php ^8.6` (`IdTokenVerifier`) |
| Push notification | FCM via `kreait/firebase-php` |
| PDF | `barryvdh/laravel-dompdf ^3.1` |
| Queue / cache / session | Driver `database` (default di `.env.example`) |
| Testing | Pest `^4.7` + `pest-plugin-laravel`, Laravel Pint untuk style |
| Tooling dev | `laravel/boost`, `laravel/pail`, `laravel/tinker` |

Frontend asset (Vite/Tailwind) hanya bawaan skeleton Laravel dan tidak dibutuhkan untuk menjalankan API.

## 3. System Architecture

```text
Flutter app ──HTTPS/JSON──▶ Laravel REST API (/api/v1) ──▶ MySQL
     │                           │   ├─ Queue worker (database) ─▶ SMTP (email)
     │                           │   │                          └▶ FCM (push)
     │                           │   └─ Scheduler (bookings:expire, notifications:dispatch, documents:dispatch)
     │                           └──▶ Storage privat (storage/app/private): PDF & gambar destinasi
     │
     └─ Google Sign-In ─▶ Firebase Auth ─▶ Firebase ID token ─▶ POST /auth/google
                                                                     │
                              Laravel memverifikasi token, lalu menerbitkan access token + refresh token milik Laravel
```

Alur utama:

1. **Autentikasi email/password:** Flutter memanggil `/auth/register` atau `/auth/login` dan menerima `access_token` (15 menit) serta `refresh_token` (30 hari).
2. **Google Sign-In:** Flutter login ke Firebase Auth, lalu mengirim Firebase ID token ke `POST /auth/google`. Laravel memverifikasi token terhadap `FIREBASE_PROJECT_ID`, mencari atau membuat user, lalu menerbitkan token Laravel. Token Firebase tidak dipakai untuk memanggil endpoint lain.
3. **Booking:** Flutter mengirim `POST /bookings` dengan `Idempotency-Key`. Server mengunci kuota, menghitung harga, dan membuat booking `pending_approval`. Admin menyetujui atau menolak; scheduler memajukan deadline yang terlewati.
4. **Notifikasi:** setiap event booking membuat record inbox dan record pengiriman (email + satu per perangkat FCM) dalam transaksi yang sama. Scheduler memasukkan pengiriman yang jatuh tempo ke queue; worker mengirimnya dengan retry dan backoff.
5. **Dokumen:** booking berstatus `confirmed` memicu pembuatan invoice konfirmasi dan tiket rombongan (PDF) oleh job di queue.

## 4. Key Features

### Pengguna (role 0)

- Register, login, refresh token, logout, login Google, verifikasi email, lupa/reset password.
- Ubah profil (`name`, `phone`), tutup akun (dengan autentikasi ulang), statistik profil.
- Melihat kategori, destinasi, gambar, review destinasi, dan ketersediaan kuota per tanggal.
- Booking dengan `Idempotency-Key`, melihat booking sendiri, membatalkan booking yang masih `pending_approval`, rekonsiliasi dan pembatalan *booking intent*.
- Mengunduh invoice dan tiket PDF milik sendiri.
- Favorit, inbox notifikasi, registrasi token perangkat FCM.
- Check-in pada tanggal kunjungan (zona waktu destinasi), lalu memberi review (rating 1–5 + komentar) satu kali per booking.

### Admin (role 1)

Seluruh kemampuan pengguna, ditambah:

- Membuat, mengubah, menonaktifkan destinasi dan mengunggah gambar destinasi (JPG/PNG/WebP, maks 5 MB).
- Melihat destinasi termasuk yang nonaktif, mengatur kuota per tanggal (`PUT /destinations/{destination}/quotas`).
- Melihat semua booking (filter `status`, `search`), menyetujui atau menolak booking.
- Melakukan check-in untuk booking pengguna lain.
- Mengakses dokumen booking milik pengguna mana pun.
- Menerima notifikasi booking baru, pembatalan, dan `payment_review`.

### Superadmin (role 2)

Seluruh kemampuan admin, ditambah:

- CRUD dan deaktivasi kategori destinasi.
- Membuat akun admin, melihat daftar akun, serta mengubah role/status akun. Superadmin tidak dapat mengubah role/status dirinya sendiri, dan superadmin aktif terakhir dilindungi dari demosi, suspend, atau penutupan akun.

## 5. Project Structure

```text
app/
├── Console/Commands/      # app:bootstrap-superadmin, bookings:expire, notifications:dispatch, documents:dispatch
├── Exceptions/            # BookingException (respons 409 dengan field `code`)
├── Http/
│   ├── Controllers/Api/V1/  # Auth, Account, AdminAccount, Booking, BookingDocument, Category,
│   │                        # Destination, Favorite, Inbox, Quota, Visit
│   ├── Middleware/          # AuthenticateApiToken (verifikasi JWT + sesi)
│   ├── Requests/            # Validasi dan otorisasi per role (+ Requests/Auth)
│   └── Resources/           # Bentuk respons JSON
├── Jobs/                  # DeliverBookingNotification, GenerateBookingDocuments
├── Models/                # User, Destination, DestinationCategory, DestinationQuota, Favorite,
│                          # Review, InboxNotification, DeviceToken
├── Notifications/         # QueuedVerifyEmail, QueuedResetPassword
├── Services/              # Business logic: Auth, Account, Admin, Booking, BookingDocument,
│                          # BookingNotification, Catalog, Favorite, Inbox, Quota, Visit
└── Support/BookingRules.php  # Konstanta status & aturan waktu booking
config/api_tokens.php      # Konfigurasi JWT, Firebase, URL reset password
database/migrations/       # Skema (users, katalog, booking, identitas, notifikasi, dokumen, kunjungan/review)
routes/api.php             # Seluruh rute API (prefix /api/v1)
routes/console.php         # Jadwal scheduler
tests/{Feature,Unit,Mysql,E2E}/
docs/{prd,handoff}/        # PRD, handoff, dan catatan phase migrasi
```

Business logic berada di `app/Services`; controller bersifat tipis, dan otorisasi per role dilakukan di FormRequest atau service.

## 6. Requirements

- PHP 8.3+ (diverifikasi pada PHP 8.3.36) dengan ekstensi yang dibutuhkan Laravel, `pdo_mysql`, serta ekstensi yang disyaratkan DomPDF (periksa dengan `composer install`).
- Composer 2.
- MySQL (Laragon menyediakan MySQL bawaan) dengan database khusus, kosong, dan terpisah dari data lain.
- Opsional: Node.js dan npm, hanya bila ingin menjalankan `composer setup`/`composer dev` yang ikut membangun asset Vite.
- Opsional untuk integrasi nyata: project Firebase (Google Sign-In), service account JSON (FCM), dan server SMTP.

## 7. Installation & Local Development

Langkah dari fresh clone di Windows/Laragon (PowerShell):

```powershell
# 1. Clone ke direktori yang dilayani Laragon, lalu masuk ke folder project
cd C:\laragon\www
git clone <url-repository> pami-wisata-daerahku-api
cd pami-wisata-daerahku-api

# 2. Install dependency PHP
composer install

# 3. Salin environment dan buat APP_KEY
Copy-Item .env.example .env
php artisan key:generate
```

Buat database kosong (misalnya `wisata_daerahku`) melalui HeidiSQL/phpMyAdmin Laragon, lalu edit `.env`:

- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` sesuai MySQL lokal;
- `JWT_SECRET` dengan string acak minimal 32 karakter. Contoh pembuatan nilai:

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

Lanjutkan:

```powershell
# 4. Migrasi database (hanya pada database yang memang disiapkan untuk project ini)
php artisan migrate

# 5. Buat superadmin pertama (interaktif; menolak bila superadmin sudah ada)
php artisan app:bootstrap-superadmin

# 6. Jalankan API
php artisan serve
```

API tersedia di `http://127.0.0.1:8000/api/v1`. Jika memakai virtual host Laragon, gunakan `APP_URL` bawaan `.env.example`: `http://pami-wisata-daerahku-api.test/api/v1`. Health check Laravel tersedia di `/up`.

Untuk proses di latar belakang, buka terminal PowerShell terpisah:

```powershell
php artisan queue:work      # memproses job email, FCM, dan PDF
php artisan schedule:work   # menjalankan scheduler tiap menit (perintah bawaan Laravel untuk lokal)
```

Alternatif: `composer dev` menjalankan `php artisan serve`, `queue:listen`, dan `npm run dev` sekaligus (membutuhkan Node.js dan `npm install`). Script `composer setup` mengotomatiskan install, `.env`, `key:generate`, `migrate --force`, dan build asset; periksa dulu `.env` karena script ini langsung menjalankan migrasi.

Migrasi users awal sudah diubah menjadi UUID. Database lama yang memakai migrasi skeleton Laravel memerlukan rencana migrasi tersendiri; jangan menjalankan ulang migrasi pada data yang sudah ada tanpa pemeriksaan.

## 8. Environment Configuration

Variabel berikut berasal dari `.env.example` dan `config/api_tokens.php`. Jangan pernah meng-commit `.env` atau file service account (`.env` sudah di-ignore oleh git).

| Variabel | Fungsi | Catatan |
| --- | --- | --- |
| `APP_KEY` | Kunci aplikasi Laravel | Dibuat dengan `php artisan key:generate` |
| `APP_URL` | URL aplikasi; default `JWT_ISSUER` | Contoh bawaan: `http://pami-wisata-daerahku-api.test` |
| `APP_DEBUG` | Mode debug | Set `false` di luar lokal |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Koneksi MySQL | Gunakan database khusus dan kosong |
| `JWT_SECRET` | Kunci penandatangan JWT HS256 | **Wajib**, minimal 32 karakter; kurang dari itu `AuthService` melempar error |
| `JWT_ISSUER` | Klaim `iss` | Default `${APP_URL}` |
| `JWT_AUDIENCE` | Klaim `aud` | Default `wisata-daerahku-api` |
| `FIREBASE_PROJECT_ID` | Project Firebase untuk verifikasi ID token Google | Wajib bila `/auth/google` dipakai; tanpa ini endpoint gagal |
| `FIREBASE_CREDENTIALS` | Path ke service account JSON untuk FCM | Wajib untuk push; kosong berarti pengiriman FCM ditunda dan dicoba ulang (`fcm_unconfigured`) |
| `PASSWORD_RESET_URL` | URL halaman reset password di klien | Dipakai oleh email reset password |
| `QUEUE_CONNECTION` | Driver queue | `database` (default `.env.example`) |
| `CACHE_STORE`, `SESSION_DRIVER` | Cache dan session | `database` |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Email | Default `MAIL_MAILER=log`, lihat catatan di bawah |
| `FILESYSTEM_DISK` | Disk penyimpanan | `local` → `storage/app/private` |

Catatan penting:

- Access token berlaku 15 menit dan refresh token 30 hari; nilai ini ditetapkan di `config/api_tokens.php`, bukan lewat `.env`.
- Dengan `MAIL_MAILER=log` (default), email verifikasi dan reset password hanya ditulis ke log. Pengiriman email notifikasi booking oleh `DeliverBookingNotification` **menolak transport `log`, `array`, dan `null`**: delivery ditandai gagal dengan kode `mail_unconfigured` dan dicoba ulang. Konfigurasikan transport SMTP nyata agar email notifikasi terkirim.
- Variabel AWS, Redis, dan Memcached di `.env.example` adalah bawaan skeleton dan tidak dipakai oleh fitur project ini.

## 9. Authentication & Authorization

### JWT lifecycle

1. **Login/register** (`/auth/register`, `/auth/login`, `/auth/google`) membuat sesi baru (`session_guid`) dan mengembalikan `access_token`, `refresh_token`, `token_type: Bearer`, `expires_in: 900`, dan `user`.
2. **Access token** adalah JWT HS256 dengan klaim `iss`, `aud`, `sub` (GUID user), `sid` (sesi), `iat`, `exp`. Middleware `AuthenticateApiToken` memverifikasi tanda tangan, `iss`, `aud`, bahwa user berstatus `active`, dan bahwa sesi masih punya refresh token yang belum dicabut dan belum kedaluwarsa.
3. **Refresh token** adalah 64 karakter hex acak; hanya hash SHA-256 yang disimpan. Setiap `POST /auth/refresh` menerbitkan pasangan token baru pada sesi yang sama dan mencabut token lama (*token rotation*).
4. **Deteksi reuse:** bila refresh token yang sudah dicabut dipakai ulang, seluruh sesi dicabut dan token perangkat (FCM) sesi itu dihapus.
5. **Logout** mencabut sesi dan menghapus token perangkat sesi tersebut. Reset password, perubahan role/status oleh superadmin, dan penutupan akun mencabut seluruh sesi terkait.

### Google Sign-In

`POST /auth/google` dengan `id_token` (Firebase ID token). Server memastikan `sign_in_provider` adalah `google.com`, `email_verified` benar, lalu:

- identitas Google yang sudah tertaut (`external_identities`) → login sebagai user tersebut;
- email belum terdaftar → user baru (role 0, email terverifikasi) dibuat dan ditautkan;
- email sudah terdaftar tanpa tautan Google → ditolak (422), karena penautan otomatis berdasarkan email tidak dilakukan;
- Google login hanya untuk role 0; akun admin/superadmin menerima 403.

Penutupan akun (`POST /profile/close`) memerlukan `password` atau `id_token` Google yang baru (`auth_time` maksimal 5 menit).

### Role-based access control

| Role | Nilai | Hak akses tambahan |
| --- | --- | --- |
| User | 0 | Endpoint bertanda terautentikasi; hanya data milik sendiri |
| Admin | 1 | Kelola destinasi, kuota, gambar; lihat/putuskan booking; check-in untuk pengguna lain |
| Superadmin | 2 | Kelola kategori dan akun admin |

Role selalu dibaca dari database Laravel, tidak pernah dari input klien (`role` dan `status` ditolak pada register dan update profil). Pembuatan superadmin pertama hanya lewat command `app:bootstrap-superadmin`.

## 10. API Documentation

**Base URL lokal:** `http://127.0.0.1:8000/api/v1` (atau `http://pami-wisata-daerahku-api.test/api/v1` bila memakai virtual host). Rute terlindungi memakai header `Authorization: Bearer <access_token>`. Daftar lengkap (58 rute saat README ini ditulis):

```powershell
php artisan route:list --path=api
```

Respons resource Laravel mengikuti format bawaan (`data`, serta `links`/`meta` untuk koleksi berpaginasi; parameter `per_page` maksimum 100). Kesalahan aturan bisnis booking mengembalikan **409** dengan bentuk `{"message": "...", "code": "...", "context": {...}}`.

### Kelompok endpoint

| Kelompok | Method & path | Akses |
| --- | --- | --- |
| Auth | `POST /auth/register`, `/auth/login`, `/auth/google`, `/auth/refresh`, `/auth/forgot-password`, `/auth/reset-password` | Publik, `throttle:10,1` |
| Auth | `GET /auth/email/verify/{id}/{hash}` | Signed URL, `throttle:6,1` |
| Auth | `GET /auth/me`, `POST /auth/logout`, `POST /auth/email/verification-notification` | Terautentikasi |
| Profil | `PATCH /profile`, `POST /profile/close`, `GET /profile/statistics` | Terautentikasi |
| Katalog publik | `GET /categories`, `GET /destinations`, `GET /destinations/{destination}`, `GET /destinations/{destination}/image`, `GET /destinations/{destination}/reviews` | Publik (hanya data aktif) |
| Kategori | `POST /categories`, `PATCH`/`DELETE /categories/{category}`, `GET /admin/categories` | Superadmin |
| Destinasi | `POST /destinations`, `PATCH`/`DELETE /destinations/{destination}`, `POST /destinations/{destination}/image`, `GET /admin/destinations[/{destination}[/image]]` | Admin+ |
| Kuota | `GET /destinations/{destination}/availability` | Terautentikasi |
| Kuota | `GET`/`PUT /destinations/{destination}/quotas` | Admin+ |
| Favorit | `GET /favorites`, `PUT`/`DELETE /favorites/{destination}` | Terautentikasi |
| Booking | `GET`/`POST /bookings`, `GET /bookings/{booking}`, `POST /bookings/{booking}/cancel` | Terautentikasi (milik sendiri) |
| Booking intent | `GET /booking-intents/{key}`, `POST /booking-intents/{key}/abandon` | Terautentikasi |
| Admin booking | `GET /admin/bookings`, `GET /admin/bookings/{booking}`, `POST /admin/bookings/{booking}/approve`, `/reject` | Admin+ |
| Dokumen | `GET /bookings/{booking}/documents`, `GET /bookings/{booking}/documents/{invoice\|ticket}` | Pemilik atau Admin+ |
| Kunjungan | `POST /bookings/{booking}/check-in`, `POST /bookings/{booking}/review`, `GET /reviews` | Terautentikasi |
| Notifikasi | `GET /notifications`, `GET /notifications/unread-count`, `PATCH /notifications/{guid}/read` | Terautentikasi |
| Perangkat | `PUT`/`DELETE /devices` (`token`, `platform`: `android`\|`ios`) | Terautentikasi |
| Akun admin | `GET`/`POST /admin/accounts`, `PATCH /admin/accounts/{user}` | Superadmin |

### Contoh request (PowerShell)

```powershell
$base = 'http://127.0.0.1:8000/api/v1'

# Register
$body = @{ name = 'Contoh'; email = 'person@example.test'; password = 'example-password'; password_confirmation = 'example-password' } | ConvertTo-Json
$auth = Invoke-RestMethod -Method Post -Uri "$base/auth/register" -ContentType 'application/json' -Body $body

# Header terautentikasi (token berada di dalam data bila respons dibungkus `data`)
$token = if ($auth.data) { $auth.data.access_token } else { $auth.access_token }
$h = @{ Authorization = "Bearer $token"; Accept = 'application/json' }

# Daftar destinasi
Invoke-RestMethod -Uri "$base/destinations?search=park" -Headers $h

# Refresh token
Invoke-RestMethod -Method Post -Uri "$base/auth/refresh" -ContentType 'application/json' -Body (@{ refresh_token = '<refresh_token>' } | ConvertTo-Json)
```

Atur kuota (Admin+): `PUT /destinations/{guid}/quotas` dengan `visit_date` (`Y-m-d`), `capacity` (integer), `booking_opens_at`, dan `booking_closes_at`. Timestamp **wajib membawa offset** (`Z` atau `+07:00`) dan jendela booking harus tutup sebelum awal hari kunjungan di zona waktu destinasi. Kapasitas tidak boleh lebih rendah dari jumlah yang sudah teralokasi.

Buat booking: `POST /bookings` dengan header `Idempotency-Key` (`[A-Za-z0-9._~-]`, maksimum 128 karakter):

```powershell
$h['Idempotency-Key'] = [guid]::NewGuid().ToString()
$booking = @{
  destination_guid = '<guid-destinasi>'; visit_date = '2026-11-20'; quantity = 5
  visitor_name = 'Nama Pengunjung'; visitor_email = 'visitor@example.test'; visitor_phone = '0812000000'
  notes = 'opsional'
} | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "$base/bookings" -Headers $h -ContentType 'application/json' -Body $booking
```

Harga (`unit_price`, `total_amount`) dihitung server dari `ticket_price` destinasi; klien tidak mengirim harga.

Review: `POST /bookings/{guid}/review` dengan `rating` (1–5) dan `comment` (maks 2000 karakter), hanya untuk booking `confirmed` yang sudah check-in.

## 11. Queue, Scheduler & Notifications

### Queue

`QUEUE_CONNECTION=database` memakai tabel `jobs`. Jalankan worker pada proses terpisah:

```powershell
php artisan queue:work
```

Job yang ada:

- `DeliverBookingNotification` — mengirim satu record pengiriman (email atau FCM). Retry diatur manual lewat kolom `attempts` dan `next_attempt_at` dengan backoff eksponensial (60 detik, hingga maksimum 3600 detik).
- `GenerateBookingDocuments` — membuat invoice dan tiket PDF; `tries = 5`, backoff 60/300/900/1800 detik.
- Notifikasi `QueuedVerifyEmail` dan `QueuedResetPassword` (email verifikasi dan reset password) juga diproses lewat queue.

### Scheduler

`routes/console.php` menjadwalkan tiga command setiap menit:

| Command | Fungsi |
| --- | --- |
| `bookings:expire` | Memajukan deadline: `pending_approval` → `expired`; `awaiting_payment` → `payment_verification`; `payment_verification` → `payment_review` |
| `notifications:dispatch` | Memasukkan record pengiriman yang jatuh tempo (atau macet `processing` lebih dari 15 menit) ke queue |
| `documents:dispatch` | Memasukkan job pembuatan dokumen untuk booking `confirmed` yang belum punya invoice/tiket |

Lokal: `php artisan schedule:work`. Server: jadwalkan `php artisan schedule:run` setiap menit melalui mekanisme penjadwal OS (tidak ada konfigurasi deployment di repository ini).

### Notifikasi

- Event: `created`, `approved`, `rejected`, `cancelled`, `expired`, `payment_review`. Event `created`, `cancelled`, dan `payment_review` ditujukan ke semua admin/superadmin aktif; sisanya ke pemilik booking.
- Setiap event membuat record inbox (`notifications`, unik per `event_key`) dan record pengiriman `email` serta satu `fcm` per token perangkat terdaftar.
- Payload FCM hanya berisi `notification_guid` dan `booking_guid` (tanpa data pribadi); klien mengambil detail lewat API terautentikasi. Token FCM yang dilaporkan tidak ditemukan (`NotFound`) otomatis dihapus.
- Pengiriman eksternal bersifat *at-least-once*, bukan *exactly-once*; duplikasi dimungkinkan bila acknowledgment hilang.

## 12. Database & Booking Integrity

- **GUID:** tabel domain memakai kolom `guid` (UUID) sebagai primary key; `User` memakai `guid` sebagai `primaryKey` non-incrementing. Tabel infrastruktur Laravel (`jobs`, `cache`, `sessions`) mempertahankan format vendor, dengan referensi user berbentuk UUID.
- **Tabel utama:** `users`, `external_identities`, `refresh_tokens`, `device_tokens`, `destination_categories`, `destinations`, `destination_quotas`, `favorites`, `bookings`, `booking_status_histories`, `idempotency_records`, `notifications`, `notification_deliveries`, `invoices`, `tickets`, `check_ins`, `reviews`.
- **Status booking** (`BookingRules::STATUSES`): `pending_approval`, `awaiting_payment`, `payment_verification`, `payment_review`, `confirmed`, `rejected`, `expired`, `cancelled`. Status yang menahan kapasitas (`HELD`): `pending_approval`, `awaiting_payment`, `payment_verification`, `payment_review`, `confirmed`.
- **Idempotency:** `Idempotency-Key` bersifat per pengguna dan per operasi. Key sama dengan input sama mengembalikan booking yang sama (status terkini); input berbeda → 409 `idempotency_conflict`. Key disimpan 7 hari; setelah itu ditolak (`idempotency_key_expired`) dan tidak dibuka kembali. `GET /booking-intents/{key}` merekonsiliasi create yang tidak pasti; `POST /booking-intents/{key}/abandon` menutup key agar request yang terlambat tidak membuat booking. Record idempotency tidak dibersihkan otomatis.
- **Concurrency:** pembuatan, persetujuan, pembatalan, dan expiry booking berjalan dalam `DB::transaction` (retry 3 kali) dengan urutan lock yang sama: baris kuota lebih dulu, lalu baris booking (`SELECT … FOR UPDATE`), sehingga alokasi kapasitas diserialisasi. Klaim key idempotency dilakukan dengan statement autocommit terpisah untuk menghindari deadlock MySQL (temuan 1213 saat uji konkurensi).
- **Aturan waktu:** timestamp disimpan UTC; tanggal kunjungan ditafsirkan pada zona waktu destinasi. Cutoff sementara: booking harus dibuat lebih dari **25 jam 15 menit** sebelum awal hari kunjungan, dan disetujui lebih dari **1 jam 15 menit** sebelumnya. Booking menunggu persetujuan selama 24 jam. Setelah disetujui, booking berbayar masuk `awaiting_payment` selama 1 jam, lalu 15 menit masa tenggang sebelum `payment_review`.
- **Booking gratis:** bila `total_amount` sama dengan 0, persetujuan admin langsung menghasilkan `confirmed`.
- **Dokumen:** PDF disimpan di disk `local` (`storage/app/private/booking-documents`), diunduh lewat endpoint terautentikasi dengan `Cache-Control: private, no-store`. Maksimal satu invoice dan satu tiket per booking. Invoice adalah **konfirmasi booking, bukan bukti pembayaran**.

## 13. Testing

```powershell
php artisan test                      # Feature + Unit, SQLite in-memory
vendor/bin/pint --test                # Pemeriksaan style (Laravel Pint)
composer test                         # config:clear lalu php artisan test
```

Suite SQLite tidak membuktikan row locking MySQL. Dua suite berikut berjalan pada MySQL nyata dan menjalankan `migrate:fresh`, sehingga **wajib** memakai database khusus yang dibuat manual dan kosong. Suite menolak berjalan (skip) bila nama database tidak berakhiran `_test` atau `_e2e`.

**Konkurensi MySQL** (database `wisata_daerahku_test`; proses PHP terpisah dilepas bersamaan lewat `tests/Mysql/worker.php`):

```powershell
php artisan test -c phpunit.mysql.xml
```

**E2E HTTP** (database `wisata_daerahku_e2e`, server lokal port 8099). Gunakan terminal terpisah untuk server:

```powershell
# Terminal 1: server terhadap database e2e
$env:DB_DATABASE = 'wisata_daerahku_e2e'
$env:APP_ENV = 'local'
$env:QUEUE_CONNECTION = 'database'
php artisan migrate:fresh --force
php -S 127.0.0.1:8099 -t public public/index.php

# Terminal 2: jalankan suite
php artisan test -c phpunit.e2e.xml
```

> `migrate:fresh` menghapus seluruh tabel. Jangan arahkan ke database pengembangan, staging, atau produksi.

Server `php -S` bersifat satu-thread: konkurensi dibuktikan pada tingkat service lintas proses (suite Mysql), bukan lewat banyak koneksi HTTP simultan.

Pengujian repository Flutter terhadap HTTP nyata memakai `tests/E2E/phase6_flutter_fixture.php`; langkah lengkapnya ada di `docs/phase6_firebase_cleanup.md`.

Pada `phpunit.xml`, `FIREBASE_CREDENTIALS` dikosongkan dan FCM dimock agar test tidak memanggil layanan Firebase nyata.

## 14. Development Workflow

1. Jalankan MySQL (Laragon) dan siapkan `.env` seperti pada [bagian 7](#7-installation--local-development).
2. Jalankan `php artisan serve`, `php artisan queue:work`, dan `php artisan schedule:work` pada terminal masing-masing (atau `composer dev`).
3. Jalankan aplikasi Flutter dan arahkan base URL ke API.
   - **Android Emulator:** alias `10.0.2.2` pada emulator menunjuk ke localhost mesin host, sehingga base URL `http://10.0.2.2:8000/api/v1` menjangkau `php artisan serve` pada port 8000.
   - **Perangkat fisik:** jalankan `php artisan serve --host=0.0.0.0` dan gunakan IP LAN mesin host (izinkan port 8000 pada firewall Windows).
   - Lalu jalankan klien, misalnya `flutter run`, dari repository Flutter.
4. Sebelum commit: `vendor/bin/pint --dirty` dan `php artisan test`.

Dokumen pendukung: `docs/prd/PRD-Wisata-Daerahku-Backend.md`, `docs/handoff/HANDOFF-BACKEND-WISATA-DAERAHKU.md`, `docs/handoff/PHASE-4-5-COMPLETION.md`, `docs/phase6_firebase_cleanup.md`.

## 15. Security Considerations

- **Credential management:** `.env`, `JWT_SECRET`, dan service account Firebase tidak boleh masuk repository. `JWT_SECRET` minimal 32 karakter. Test mengosongkan `FIREBASE_CREDENTIALS`.
- **Token:** refresh token disimpan sebagai hash SHA-256, dirotasi pada setiap pemakaian, dan reuse mencabut sesi. Status user dan sesi diperiksa pada setiap request terautentikasi.
- **Otorisasi:** role dibaca dari database; `role`/`status` ditolak dari input register dan profil. Query data pengguna selalu dibatasi `user_guid` pemilik, kecuali untuk Admin+ pada rute admin. Pemeriksaan role dilakukan di FormRequest dan/atau service.
- **Rate limiting:** `throttle:10,1` pada register, login, Google, refresh, forgot/reset password; `throttle:6,1` pada verifikasi email, kirim ulang verifikasi, dan penutupan akun.
- **Privasi file:** PDF dan gambar disimpan pada disk privat; path storage tidak dikembalikan ke klien. Gambar destinasi dikirim dengan `X-Content-Type-Options: nosniff`.
- **Validasi:** input divalidasi lewat FormRequest (tipe, panjang, format tanggal, ekstensi dan ukuran gambar). Pencarian meng-escape karakter wildcard `LIKE`.
- **Error:** respons untuk `api/*` selalu JSON. Matikan `APP_DEBUG` di luar lokal.
- **Penutupan akun** memerlukan autentikasi ulang dan mempertahankan data transaksi.

## 16. Known Limitations

Berdasarkan kode dan dokumen handoff pada repository ini:

- **Pembayaran belum ada.** Tidak ada payment provider, endpoint checkout, webhook, rekonsiliasi, maupun refund. Satu-satunya jalur ke `confirmed` di kode saat ini adalah persetujuan admin atas booking gratis (`total_amount = 0`); booking berbayar berhenti di `awaiting_payment` → `payment_verification` → `payment_review` dan tidak dapat dikonfirmasi lewat API. Alokasi kapasitas tidak dilepas pada status tersebut.
- **Pembatalan** hanya untuk booking `pending_approval`; kebijakan pembatalan/refund untuk booking terproses belum tersedia (409 `booking_not_cancellable`).
- **Cutoff kunjungan bersifat sementara** (25 j 15 m untuk create, 1 j 15 m untuk approve); persetujuan produk atas kebijakan final adalah syarat rilis. Durasi dan prosedur `payment_review`, semantik invoice final, serta aturan eligibility check-in/review juga masih keputusan terbuka.
- **Belum diverifikasi dengan layanan nyata:** Google Sign-In dengan Firebase ID token nyata (test memakai verifier yang dimock), pengiriman FCM ke perangkat, SMTP, worker/scheduler di lingkungan target, monitoring/alerting, dan deployment. Repository tidak memuat konfigurasi deployment.
- **Email notifikasi** tidak akan terkirim selama `MAIL_MAILER` bernilai `log`, `array`, atau `null`.
- **Dokumen PDF:** crash worker setelah file ditulis tetapi sebelum commit dapat meninggalkan file privat yatim; file yang dihapus setelah record dibuat tidak diregenerasi otomatis.
- **Record idempotency** tidak dibersihkan otomatis.
- **Data Firebase lama** tidak diimpor; tidak ada skrip migrasi data dari Firestore. Tidak ada seeder data demo; `DatabaseSeeder` bawaan skeleton hanya membuat satu user uji (`test@example.com`) dan tidak boleh dijalankan di luar lokal.
- **Penautan akun Google** ke akun email/password yang sudah ada belum didukung (ditolak 422).
- Uji konkurensi MySQL dan E2E hanya dapat dijalankan secara manual pada database `*_test`/`*_e2e`; belum ada konfigurasi CI di repository.
