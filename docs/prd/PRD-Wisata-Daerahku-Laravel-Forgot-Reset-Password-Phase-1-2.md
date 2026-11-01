# PRD — Forgot & Reset Password Laravel (Gabungan Phase 1 + Phase 2)

**Proyek:** Wisata Daerahku  
**Platform:** Laravel REST API (backend-only)  
**Status:** Siap diserahkan ke Codex; belum diimplementasikan melalui dokumen ini  
**Prioritas:** Security Critical / Authentication  
**Database verifikasi:** MySQL nyata (sesuai lingkungan proyek)  

## 0. Instruksi Wajib untuk Codex Agents

Gunakan **dua agent yang sudah tersedia dalam repository**, bukan agent generik:

- **Backend Engineer:** `.codex/agents/backend_engineer.toml` — pemilik implementasi, perubahan kode, migrasi bila benar-benar diperlukan, dan pengujian.
- **Security Auditor:** `.codex/agents/security_auditor.toml` — audit independen terhadap desain awal, perubahan keamanan, race condition, sesi/JWT, dan hasil verifikasi.

**Workflow wajib:**
1. Baca kedua file agent terlebih dahulu dan patuhi instruksinya jika tersedia.
2. `backend_engineer` melakukan audit terarah atas alur existing, lalu mengajukan desain perbaikan minimal.
3. `security_auditor` menelaah desain, khususnya strategi atomic single-use token, sebelum perubahan keamanan difinalisasi.
4. `backend_engineer` mengimplementasikan dan menjalankan pengujian.
5. `security_auditor` mengaudit diff serta hasil pengujian dan melaporkan temuan berdasarkan severity. Perbaiki temuan critical/high sebelum menyatakan selesai.
6. Jangan melakukan commit, push, deploy, atau mengubah kredensial nyata tanpa instruksi eksplisit.

Jika mekanisme delegasi sub-agent tidak tersedia di runtime Codex, jangan mengklaim agent sudah dijalankan. Lakukan review terpisah dengan sudut pandang kedua peran dan laporkan keterbatasannya.

## 1. Latar Belakang

Audit existing mengidentifikasi hal-hal berikut (verifikasi kembali terhadap repo saat pengerjaan):

- Endpoint forgot password menerima email, membuat token memakai Laravel password broker, dan mengantrekan notifikasi `QueuedResetPassword`.
- Endpoint reset menerima email, token, dan password baru; memperbarui password dan mencabut sesi/token perangkat.
- Token disimpan sebagai hash, kedaluwarsa dalam **60 menit**, dan permintaan token per email di-throttle **60 detik**.
- Respons forgot password bersifat generik untuk mencegah enumerasi akun.
- Pada `app/Services/AccountService.php` sekitar baris 94, validasi token diduga terjadi sebelum transaksi yang mengunci user; dua request serentak berpotensi menggunakan token sama.
- `app/Notifications/QueuedResetPassword.php` bergantung pada mail transport, konfigurasi `PASSWORD_RESET_URL`, dan queue worker.
- Flutter belum memiliki destination/reset handler yang terbukti; itu pekerjaan terpisah. Backend hanya menyiapkan kontrak dan URL.
- Tes khusus forgot/reset password dan uji konkurensi MySQL belum terbukti tersedia.

**Catatan:** Nomor baris, route, serta nama class bersifat petunjuk audit, bukan izin untuk mengasumsikan struktur repo belum berubah.

## 2. Tujuan

1. Memastikan **satu token reset hanya dapat menghasilkan satu reset sukses**, termasuk pada request paralel dari koneksi/proses MySQL berbeda.
2. Menjaga hash token, TTL 60 menit, throttle 60 detik, dan respons anti-enumerasi.
3. Memastikan password baru tersimpan aman dan seluruh sesi akses/refresh serta token perangkat yang relevan dicabut sesuai implementasi auth existing.
4. Menyiapkan konfigurasi SMTP Gmail, URL reset klien, worker queue, kegagalan pengiriman, dan runbook operasional.
5. Menetapkan kontrak API dan format HTTPS reset link untuk integrasi Flutter di tahap berikutnya.
6. Menambahkan pengujian fitur, negatif, regresi, konkurensi MySQL, dan pemeriksaan keamanan.

## 3. Batasan Pekerjaan

**In scope:** `AccountService`, request/response API existing, password broker/repository, `QueuedResetPassword`, konfigurasi mail/queue/reset URL, dokumentasi `.env.example`/README, tes backend, monitoring failed jobs, dan perbaikan keamanan yang dibutuhkan.

**Out of scope:** kode Flutter, UI login/forgot/reset, implementasi Android App Links di manifest, publikasi domain/hosting, penggantian arsitektur JWT, migrasi Firebase, perubahan business logic wisata/booking/admin, atau provider email lain. Jangan mengganti kontrak API existing secara breaking tanpa kebutuhan yang dibuktikan.

## 4. Workstream A — Atomic Password Reset (Critical)

### A.1 Audit terlebih dahulu

Periksa alur lengkap `AccountService`, controller/request, `Password::broker` atau pemanggilan broker aktual, repository token, koneksi database model pengguna dan tabel `password_reset_tokens`, serta mekanisme revokasi session/JWT/refresh/device token.

### A.2 Keamanan transaksi

Implementasikan **satu critical section atomik** untuk satu akun/token. Strategi yang direkomendasikan untuk dievaluasi:

1. Mulai transaksi database pada koneksi MySQL yang benar.
2. Temukan baris user dan kunci menggunakan `SELECT ... FOR UPDATE` / `lockForUpdate()` **sebelum** memvalidasi token melalui password broker.
3. Di dalam critical section yang sama, jalankan pemeriksaan token/expiry, update hash password, revokasi kredensial tersimpan, dan penghapusan/konsumsi token.
4. Pastikan token **dikonsumsi sebelum transaksi commit**; request paralel berikutnya baru boleh memeriksa token setelah pemegang lock pertama commit/rollback.
5. Pastikan semua operasi database yang diharapkan atomik menggunakan koneksi/transaction boundary yang kompatibel; bila token repository berada di koneksi berbeda, rancang atomic claim/consume alternatif yang teruji.
6. Saat validasi gagal, jangan mengubah password atau mencabut sesi.
7. Jika transaksi gagal, pastikan perubahan keamanan yang sebagian berhasil tidak meninggalkan state inkonsisten. Side effect eksternal perlu ditangani setelah commit atau memakai strategi aman yang dijelaskan.

**Larangan:** memanggil validasi broker di luar lock lalu hanya memindahkan `deleteToken()` ke dalam callback; mengandalkan tes SQLite untuk membuktikan konkurensi; menambahkan lock in-memory saja; menyelesaikan hanya dengan throttle rate limit.

**Definisi sukses:** untuk dua atau lebih reset paralel dengan email/token yang sama, tepat **satu** dapat sukses dan hanya password dari request yang sukses menjadi password akhir. Semua token reset yang telah dikonsumsi tidak dapat dipakai ulang.

### A.3 Stabilitas autentikasi

Pertahankan kontrak auth existing. Verifikasi logout/revokasi setelah reset untuk access/refresh token, refresh-token reuse detection, dan device/FCM registration sesuai skema aktual. Jangan mengirim password baru atau token mentah ke log. Jika terdapat JWT stateless yang tidak dapat dicabut langsung, audit mekanisme pembatalan dan jelaskan jaminan yang benar-benar ada; jangan klaim seluruh access token invalid tanpa pembuktian.

## 5. Workstream B — Gmail SMTP, Queue, dan Reset Link (High)

### B.1 Configuration contract

Audit `config/mail.php`, `config/queue.php`, `config/auth.php` dan environment loader yang benar. Tambahkan contoh aman di `.env.example` (tanpa credential sungguhan):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-gmail@gmail.com
MAIL_PASSWORD=YOUR_GOOGLE_APP_PASSWORD
MAIL_FROM_ADDRESS=your-gmail@gmail.com
MAIL_FROM_NAME="Wisata Daerahku"

QUEUE_CONNECTION=database
PASSWORD_RESET_URL=https://YOUR-HTTPS-CLIENT-DOMAIN/reset-password
```

Gunakan **Google App Password**, bukan password Gmail utama. Sesuaikan TLS/`MAIL_SCHEME` atau `MAIL_ENCRYPTION` **berdasarkan `config/mail.php` yang benar-benar dipakai**, jangan membuat variabel yang tidak dibaca Laravel. Pilihan domain di atas hanya placeholder dan tidak boleh lolos sebagai konfigurasi production.

Dokumentasikan prasyarat tabel `jobs`, `failed_jobs` dan token reset sesuai migration existing. Tidak boleh meng-commit `.env` atau secret. Jika backend belum memiliki domain reset klien HTTPS, tandai pengiriman link produksi sebagai **blocked by external configuration**, jangan membuat domain palsu.

### B.2 Queue delivery dan observability

- Pertahankan queued notification, inspeksi `QueuedResetPassword` serta pemilihan queue/connection; jangan membuat job duplikat.
- Pastikan worker memproses queue yang benar dan dapat dijalankan terus-menerus lewat mekanisme deployment yang tersedia (local `queue:work`; production proses/cron yang memang didukung hosting).
- Jika dispatch dilakukan dalam transaksi, gunakan mekanisme `after_commit` untuk menghindari job dikirim sebelum data terkait commit.
- Tangani dan dokumentasikan `queue:failed`, retry terbatas/terkendali, worker restart sesudah deploy, dan cara mendeteksi email gagal terkirim.
- Respons forgot password menyatakan **permintaan diterima**, bukan jaminan email sudah delivered; tetap generik untuk email tidak terdaftar.
- Lindungi log/error report dari token/reset URL penuh, password, App Password, dan email sensitif yang tidak perlu.

### B.3 Format tautan dan kontrak client

Backend menghasilkan tautan HTTPS yang terkonfigurasi, contoh struktur:

```text
https://YOUR-HTTPS-CLIENT-DOMAIN/reset-password?token=<URL_ENCODED_TOKEN>&email=<URL_ENCODED_EMAIL>
```

- URL dibuat dari `PASSWORD_RESET_URL` yang tervalidasi, **bukan** dari `Host` atau `Origin` request pengguna.
- Gunakan pembangun query/URL yang benar agar email, `+`, `@` dan token aman di-encode.
- Token/email harus dapat diterima oleh klien Flutter yang nanti men-submit ke endpoint reset existing; cantumkan exact method/path/request/response berdasarkan route yang ditemukan dalam repo.
- Jika proyek memakai custom `QueuedResetPassword`, sesuaikan pembangun URL di situ; bila memakai notification Laravel standar, evaluasi `ResetPassword::createUrlUsing`. Hindari dua sumber URL yang saling bertentangan.
- Membuka tautan via HTTP GET **tidak boleh** mengonsumsi token; konsumsi token hanya pada submit reset valid.
- Hindari mengirim token ke analytics, referrer pihak ketiga, atau log akses yang tak terlindungi. Dokumentasikan kebutuhan `Referrer-Policy: no-referrer` pada halaman klien nanti.
- Kontrak Android App Links/assetlinks dan layar reset Flutter adalah **handoff**, bukan perubahan dalam PRD backend ini. Jika domain belum ada, dokumentasikan dependensinya.

## 6. Workstream C — Pengujian Backend (Wajib)

Buat suite test terfokus menggunakan test database sesuai proyek dan jalankan pula pengujian **MySQL nyata** untuk race condition. Minimal skenario:

| ID | Skenario | Ekspektasi |
|---|---|---|
| T01 | Forgot password email terdaftar | Respons generik; token dibuat dan notifikasi di-queue |
| T02 | Forgot password email tidak terdaftar | Respons setara; tidak membocorkan keberadaan akun |
| T03 | Permintaan dalam 60 detik | Throttle mengikuti kebijakan existing tanpa enumerasi |
| T04 | Token valid + password valid | Reset sukses; password hash baru tersimpan; token terhapus |
| T05 | Token tidak valid | Gagal aman; password/sesi tidak berubah |
| T06 | Token melewati 60 menit | Gagal aman; token tidak diterima |
| T07 | Token digunakan ulang berurutan | Hanya permintaan pertama sukses |
| T08 | Dua atau lebih request reset paralel dengan token sama, koneksi MySQL terpisah | **Tepat satu sukses**, sisanya gagal; final password deterministik |
| T09 | Reset sukses | Sesi, refresh, dan token perangkat existing direvokasi sesuai skema; token lama tidak bisa dipakai |
| T10 | Kegagalan transaksi setelah sebagian langkah | Rollback konsisten; tidak ada partial reset |
| T11 | Notification URL | HTTPS, base URL sesuai config, parameter `email`/`token` ter-encode benar |
| T12 | Konfigurasi URL invalid/placeholder production | Ditolak / fail-fast pada titik operasional yang sesuai |
| T13 | Queue/mail failure | Dapat dipantau; tidak mengklaim email delivered; retry sesuai kebijakan |
| T14 | Regresi login dan refresh setelah reset | Password lama gagal, password baru berhasil; sesi lama tidak hidup kembali |

Kriteria uji konkurensi: gunakan MySQL/InnoDB nyata, dua transaksi/proses/koneksi berbeda, barrier/sinkronisasi agar request benar-benar overlap, timeout terkendali, dan pengulangan cukup untuk mendeteksi race. **Jangan** mengklaim tes konkurensi lulus jika yang berjalan hanya SQLite atau tes sekuensial.

Lakukan test regresi auth existing dan `php artisan test` sesuai fasilitas repo. Catat angka passed/skipped/failed secara jujur.

## 7. Security Review Checklist — Security Auditor

Auditor memverifikasi:

- Validasi token dan consume benar-benar berada dalam atomic boundary yang sama.
- Tidak ada deadlock tak tertangani, lock order tidak konsisten, maupun race saat generate token ulang vs reset.
- Expiry, throttling, generic response, dan hashing tetap berlaku.
- Tidak ada user enumeration dari status code/isi respons yang berbeda secara material.
- Password dikirim hanya lewat HTTPS dan tidak dilog; hash sesuai hasher Laravel.
- Session/JWT/refresh/device revocation efektif pada jalur otentikasi yang nyata.
- Queue tidak mengirim email reset sebelum state siap; error/failure observable.
- URL klien tidak bisa dipengaruhi request Host injection/open redirect; token tidak bocor ke log atau telemetry.
- Tidak ada secrets di source code, README, hasil tes, `.env.example`, atau Git diff.
- Pengujian konkurensi MySQL menggunakan paralelisme nyata, bukan mock/SQLite.

Temuan **Critical/High** harus diselesaikan dan diaudit ulang sebelum status COMPLETE.

## 8. File yang Diperkirakan Tersentuh

Hanya jika hasil audit membutuhkan:

- `app/Services/AccountService.php`
- `app/Notifications/QueuedResetPassword.php`
- Controller/Request existing forgot/reset (cari path aktual)
- `app/Models/User.php` atau service auth terkait **hanya** bila dibutuhkan untuk revokasi
- `config/mail.php`, `config/queue.php`, `config/auth.php` (jika benar-benar diperlukan)
- `.env.example`, `README.md` / dokumentasi operasional
- `tests/Feature/...` dan `tests/Integration/...` atau direktori pengujian aktual

Jangan membuat migrasi baru jika tabel existing sudah memadai. Jangan mengubah endpoint/path atau JSON contract yang telah dipakai aplikasi kecuali ada alasan keamanan kuat dan didokumentasikan.

## 9. Operational Runbook

Di README sertakan prosedur yang dapat dijalankan pada Laravel lokal:

```powershell
php artisan config:clear
php artisan queue:work
php artisan queue:failed
```

Sertakan cara memeriksa queue table, mail transport, dan failed jobs; langkah tes kirim email ke akun **uji milik pengembang**; prosedur worker production berdasar dukungan hosting; serta langkah restart worker setelah deploy.

Tes Gmail SMTP nyata adalah **manual integration check**, bukan pengganti unit/feature tests. Bedakan secara eksplisit hasil `Notification::fake`/`Mail::fake`, queue job processed, SMTP accepted, dan email benar-benar diterima inbox.

## 10. Acceptance Criteria / Definition of Done

- [ ] `backend_engineer` melakukan audit existing dan implementasi minimal.
- [ ] `security_auditor` meninjau desain dan hasil patch secara independen.
- [ ] Concurrent reset MySQL dengan token sama: **exactly one successful reset**.
- [ ] Token yang digunakan tidak bisa dipakai kembali; TTL/throttle/anti-enumeration utuh.
- [ ] Password baru dan pembatalan sesi/token existing terbukti lewat tes.
- [ ] `PASSWORD_RESET_URL` aman, URL query valid, dan konfigurasi production invalid tidak dibiarkan diam-diam.
- [ ] Konfigurasi Gmail SMTP/queue terdokumentasi tanpa secrets.
- [ ] Test backend terkait dan regresi auth lulus; jika belum, laporkan blokir.
- [ ] Pengujian email nyata, worker, dan URL pada domain nyata dilaporkan **PASS / FAIL / NOT VERIFIED**, jangan diasumsikan PASS.
- [ ] Handoff ke Flutter mencantumkan method/path, request/response, format link, dan persyaratan Android App Links.
- [ ] Tidak ada perubahan kode Flutter, UI, atau business logic wisata.
- [ ] Tidak ada commit/push/deployment otomatis.

## 11. Format Laporan Akhir Codex

Serahkan ringkasan dengan urutan:

1. Audit existing dan penyebab utama race condition.
2. Strategi atomicity yang dipilih, termasuk urutan lock → broker validation → update/revoke → token deletion → commit, atau penjelasan alternatif yang setara.
3. Perubahan per file dan alasan singkat.
4. Test matrix dengan hasil nyata, terutama MySQL concurrent reset (jelaskan setup paralelisme).
5. Audit independen `security_auditor`: temuan dan status perbaikan.
6. Panduan konfigurasi `.env` **tanpa menampilkan secret** dan perintah menjalankan worker.
7. API & reset-link contract untuk tim Flutter.
8. Hal yang masih `NOT VERIFIED` atau `BLOCKED` (mis. domain/reset page/SMTP credentials/Android App Links).

**Larangan final:** jangan tulis “selesai total” bila SMTP riil, worker, MySQL concurrent test, atau pengiriman link di perangkat belum diverifikasi.

## 12. Referensi Teknis Resmi

- Laravel Password Reset: https://laravel.com/framework/docs/passwords
- Laravel Queues, transactions/after_commit, worker, failed jobs: https://laravel.com/framework/docs/queues
- Google App Passwords: https://myaccount.google.com/apppasswords
