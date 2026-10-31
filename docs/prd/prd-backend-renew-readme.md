# Task: Update README.md — Wisata Daerahku Laravel Backend

Lakukan audit repository Laravel saat ini secara menyeluruh, kemudian perbarui file `README.md` agar mencerminkan arsitektur, implementasi, dan fitur aktual setelah penyelesaian migrasi Firebase → Laravel hingga Phase 6.

## Instruksi Utama

1. Periksa keseluruhan struktur dan source code repository terlebih dahulu sebelum menulis dokumentasi.
2. Gunakan implementasi aktual sebagai sumber kebenaran utama (*single source of truth*).
3. Baca dokumen PRD, dokumentasi phase migrasi, dan handoff yang tersedia sebagai referensi pendukung.
4. Jika diperlukan, gunakan sub-agent yang tersedia di `.codex/agents/`, terutama `backend_engineer`, `system_architect`, dan `master_orchestrator`.
5. Jangan mengarang endpoint, konfigurasi environment, fitur, status implementasi, maupun command yang tidak ditemukan di repository.
6. Dokumentasi menggunakan **Bahasa Indonesia profesional**, dengan istilah teknis tetap dalam bahasa Inggris.
7. Kerjakan hanya dokumentasi `README.md`. Jangan mengubah source code, dependency, migration, konfigurasi, maupun business logic.
8. Jangan membuat commit Git.

## Konteks Arsitektur

Project Wisata Daerahku menggunakan Laravel 13 sebagai REST API backend untuk aplikasi Flutter.

Migrasi Firebase → Laravel telah diselesaikan melalui Phase 1–6.

Arsitektur yang harus diperiksa dan dijelaskan:

- Laravel sebagai backend utama dan pusat business logic.
- MySQL sebagai database utama.
- JWT authentication, termasuk access token, refresh token, token rotation, dan session management.
- Google Sign-In melalui Firebase Authentication, kemudian pertukaran Firebase ID token menjadi token autentikasi Laravel.
- Firebase Cloud Messaging (FCM) untuk push notification.
- Role-based access control: User (0), Admin (1), Superadmin (2).
- Booking dengan idempotency dan concurrency protection.
- Kuota destinasi berdasarkan tanggal.
- Email notification dan asynchronous queue.
- Profile, favorites, reviews, check-in, dan private ticket PDF.
- Laravel sebagai sumber data utama, bukan Firestore.

Seluruh informasi tersebut harus diverifikasi terhadap implementasi aktual. Jangan menyatakan integrasi atau fitur siap produksi jika belum terbukti.

## Struktur README.md

Susun dokumentasi dengan bagian berikut:

1. **Project Overview** — Deskripsi, tujuan, dan fungsi backend Wisata Daerahku.
2. **Technology Stack** — Framework, PHP, database, authentication, integrasi Firebase, queue, serta dependency penting berdasarkan `composer.json`.
3. **System Architecture** — Alur Flutter → Laravel REST API → MySQL, termasuk Google Authentication dan FCM.
4. **Key Features** — Fitur pengguna, admin, dan superadmin berdasarkan source code.
5. **Project Structure** — Struktur folder aktual, termasuk Controllers, Services, Requests, Resources, Models, Jobs, Middleware, dan Routes jika tersedia.
6. **Requirements** — PHP, Composer, MySQL, dan kebutuhan development lainnya.
7. **Installation & Local Development** — Petunjuk setup di Windows/Laragon, konfigurasi `.env`, `composer install`, migrasi, serta perintah menjalankan API.
8. **Environment Configuration** — Variabel penting berdasarkan `.env.example` dan pembacaan konfigurasi, tanpa mengekspos credentials atau secrets.
9. **Authentication & Authorization** — JWT lifecycle, Google token exchange, dan role-based authorization.
10. **API Documentation** — Ringkasan kelompok endpoint berdasarkan file routes aktual, HTTP methods, autentikasi, dan contoh request yang valid.
11. **Queue, Scheduler & Notifications** — Cara menjalankan worker, scheduler, email, dan FCM sesuai konfigurasi.
12. **Database & Booking Integrity** — GUID, hubungan data, idempotency, transaksi, dan proteksi race condition.
13. **Testing** — Perintah menjalankan test Laravel, HTTP E2E, dan MySQL concurrency apabila tersedia.
14. **Development Workflow** — Menjalankan backend lokal dan menghubungkannya ke Android Emulator melalui Flutter.
15. **Security Considerations** — Credential management, ownership, authorization, dan perlindungan endpoint.
16. **Known Limitations** — Fitur belum selesai, belum terverifikasi, atau membutuhkan layanan eksternal, jika ditemukan.

## Persyaratan Dokumentasi

- Gunakan Markdown yang rapi, konsisten, dan mudah dibaca.
- Sertakan command PowerShell yang benar-benar sesuai dengan repository.
- Sediakan langkah setup dari fresh clone sampai API bisa dijalankan.
- Cantumkan contoh base URL API sesuai route prefix aktual.
- Jelaskan konfigurasi queue dan scheduler berdasarkan source code, bukan asumsi.
- Jangan memasukkan secrets, private keys, atau nilai sensitif dari `.env`.
- Jangan memberikan klaim palsu tentang integrasi payment gateway, webhook, deployment, atau production readiness.
- Pertahankan informasi README lama yang masih relevan.
- Hindari penjelasan terlalu umum; utamakan panduan operasional yang bisa langsung diikuti developer.

## Expected Output

Perbarui `README.md` secara langsung di repository.

Setelah selesai, laporkan:
- Ringkasan perubahan README.
- Bagian dokumentasi yang ditambahkan atau diperbaiki.
- Informasi yang belum dapat diverifikasi.
- Konfirmasi bahwa tidak ada source code atau konfigurasi aplikasi yang diubah.

Tidak perlu membuat commit, screenshot, atau menjalankan keseluruhan test suite. Pastikan semua command, path, dan fitur yang didokumentasikan memiliki dasar dari repository aktual.