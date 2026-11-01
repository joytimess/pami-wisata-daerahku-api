# PRD — Email Verification OTP untuk Registrasi User (Laravel)

**Project:** Wisata Daerahku  
**Platform:** Laravel REST API (backend-only)  
**Status dokumen:** Siap diserahkan ke Codex; **belum diimplementasikan melalui dokumen ini**  
**Prioritas:** Authentication / Email Verification  
**Batas cakupan:** Registrasi email/password **user `role = 0` saja**

## 1. Tujuan

Ganti mekanisme **verifikasi email pada registrasi user email/password** menjadi OTP numerik **6 digit yang dikirim melalui email (Gmail SMTP existing)**. Backend harus membuat, mengirim, memvalidasi, dan mengonsumsi OTP secara aman serta menandai `email_verified_at` hanya setelah verifikasi berhasil.

**Tidak ada perubahan implementasi Flutter dalam pekerjaan ini.** Backend harus menyediakan kontrak API yang siap dipakai `RegisterView`, `AuthSession`, dan halaman OTP Flutter pada fase berikutnya.

## 2. Keputusan Produk dan Scope

| Alur | Kebijakan |
| --- | --- |
| Registrasi publik email/password `role = 0` | **OTP email wajib** sebelum akun dianggap terverifikasi |
| Login user email/password yang belum verifikasi | Ikuti kebijakan existing yang aman; **jangan izinkan akses ke fitur yang mensyaratkan email terverifikasi** |
| Google Sign-In user `role = 0` | **Tidak diberi OTP tambahan** jika backend telah memvalidasi Firebase ID token dan claim email terverifikasi; pertahankan logika existing |
| Pembuatan akun admin `role = 1` | **Tidak menggunakan OTP registrasi user**, alur provisioning/admin existing tidak diubah |
| Superadmin `role = 2` | **Tidak menggunakan OTP registrasi user**, alur existing tidak diubah |
| Forgot/Reset Password | **Tetap tautan reset email**; dilarang mengubah alur yang telah diperkuat |
| Pengiriman email | Gunakan Gmail SMTP dan mekanisme queue yang **sudah dikonfigurasi**, tanpa menimpa `.env` atau menyimpan kredensial di Git |

**Keamanan role:** Endpoint registrasi publik tidak boleh menerima atau mempercayai role dari client. Selalu set `role = 0` di server. OTP tidak boleh menjadi cara mengubah atau menaikkan role.

## 3. Instruksi Wajib untuk Codex Agents

Lakukan inspeksi repository terlebih dahulu, khususnya `routes/api.php`, controller/request/resource auth, `AuthService`/`AccountService`, model `User`, `AuthSession` kontrak API yang mungkin terdokumentasi, email verification existing, queue, serta migration.

- **`.codex/agents/backend_engineer.toml`**: pimpin desain implementasi minimal, controllers tipis, services memegang business logic, requests menangani validasi, resources membentuk JSON, migration, notification + queue, dan pengujian.
- **`.codex/agents/security_auditor.toml`**: audit threat model, role isolation, OTP brute force, account enumeration, kebocoran OTP, replay/race condition, queue encryption, dan kepatuhan acceptance criteria; laporkan temuan Critical/High dan perbaikannya.

**Jika file agent tidak ditemukan**, laporkan dengan jujur dan gunakan kapabilitas spesialis yang tersedia tanpa mengklaim file agent telah dipakai. **Jangan melakukan commit, push, deploy, atau modifikasi Flutter.**

## 4. UX/API Flow yang Harus Didukung Backend

1. `POST register` existing berhasil dengan email/password user baru.
2. Laravel membuat akun `role = 0`, `email_verified_at = null` dan menerbitkan OTP 6 digit yang aman.
3. Setelah transaksi pendaftaran commit, queued notification email mengirim OTP ke email terdaftar.
4. Respons register menyatakan **verifikasi diperlukan**, bukan mengklaim pesan sudah sampai inbox.
5. Flutter (fase selanjutnya) menampilkan layar input OTP. Backend menerima verifikasi kode dan mengonfirmasi akun.
6. Jika benar, backend mengisi `email_verified_at`, mengonsumsi OTP secara atomik, dan mengembalikan respons sukses standar.
7. Jika OTP kedaluwarsa/tidak valid/sudah dipakai, kembalikan pesan aman yang sesuai tanpa membocorkan data sensitif.
8. Endpoint resend menerbitkan OTP baru setelah cooldown dan membatalkan OTP sebelumnya.

**Catatan sesi:** Audit apakah register existing langsung menerbitkan JWT/access/refresh. Jangan membuka akses fitur protected hanya karena token telah diterbitkan. Pertahankan kontrak sesi bila mungkin dengan pembatasan verifikasi; jika perlu perubahan kontrak, dokumentasikan dampaknya untuk Flutter. Jangan membuat kebijakan token baru tanpa membaca implementasi existing.

## 5. Aturan OTP (Acceptance Default)

| Parameter | Kebijakan |
| --- | --- |
| Format | **6 digit numerik**, termasuk kemungkinan nol di awal |
| Generator | CSPRNG (`random_int` atau mekanisme kriptografi setara), bukan `rand()` |
| Berlaku | **10 menit** sejak penerbitan |
| Cooldown kirim ulang | **60 detik** per akun/email; batasi juga frekuensi berdasarkan IP |
| Salah input | **Maksimal 5 percobaan per OTP**; habis jatah -> OTP tidak berlaku |
| Penggunaan | **Single-use**, transaksi atomik pada DB aktual |
| Resend | Membatalkan OTP lama secara atomik; kode baru menjadi satu-satunya kode aktif |
| Penyimpanan | **Jangan plaintext**; simpan digest aman menggunakan HMAC kunci server atau hash password yang sesuai, dengan pembatasan percobaan dan lock |
| Scope | OTP terikat ke **user, email, dan tujuan `verify_email`**; tidak valid untuk reset password |
| Logs/API | **Jangan log atau tampilkan OTP/digest**, termasuk pada exception dan respons produksi |

**Pengiriman antrian:** OTP ada dalam isi email secara inheren. Jika notifikasi/job membawa OTP mentah dalam payload queue, **wajib enkripsi payload job/notifikasi di storage queue** dan kirim **setelah commit**. Gunakan mekanisme Laravel yang cocok (contohnya `ShouldBeEncrypted` + `afterCommit()`) setelah memeriksa versi/framework existing. Pastikan job lama yang tertunda tidak mengirim OTP yang sudah digantikan (verifikasi versi/issuance aktif sebelum kirim, atau strategi konsisten lain). Jangan bocorkan nilai OTP melalui failed job logs.

## 6. Desain Data dan Atomicity

Boleh membuat tabel khusus, misalnya `email_verification_otps`, atau memakai mekanisme yang telah ada **jika benar-benar memenuhi seluruh persyaratan**. Ikuti konvensi schema proyek (termasuk pola GUID dan foreign key existing); **jangan mengasumsikan nama kolom `user_id` jika repo memakai `user_guid`**.

Data minimal yang harus dapat direpresentasikan:

- Relasi ke user/identitas email yang diverifikasi.
- Purpose `verify_email`.
- Digest OTP, batas waktu kedaluwarsa, jumlah percobaan gagal.
- Waktu terakhir dikirim, status konsumsi atau penghapusan atomik, dan identitas penerbitan untuk menangani resend/job terlambat.
- Index/constraint agar hanya satu OTP aktif untuk user + purpose (atau invariant setara melalui locking).

**Concurrency contract (wajib):** Verifikasi dan resend memakai transaksi serta penguncian baris yang konsisten. Semua validasi OTP (termasuk expiry/attempts), konsumsi OTP, dan update `email_verified_at` harus terjadi pada critical section yang sama. Dua request dengan OTP sama tidak boleh sama-sama sukses. Resend yang berbarengan dengan verify tidak boleh menghidupkan ulang OTP lama atau menimpa status terverifikasi.

Pastikan koneksi Eloquent, transaksi, dan repository OTP sama; jangan mengklaim aman hanya berdasarkan tes SQLite.

## 7. Kontrak API untuk Flutter (Usulan; sesuaikan route existing)

**Jangan duplikasi endpoint bila proyek sudah punya endpoint serupa.** Inventaris route dan respons API yang ada dahulu; gunakan namespace `/api/v1` sesuai struktur proyek.

### A. Register existing

`POST /api/v1/auth/register`

- Pertahankan field registrasi dan validasi existing.
- User publik wajib dibuat `role = 0` dan `email_verified_at = null`.
- Respons harus menyediakan indikator seperti `verification_required: true`, dengan copy aman: "Akun dibuat. Periksa email untuk kode verifikasi." Jangan mengembalikan OTP.
- Kebijakan token/sesi tetap mengikuti audit existing dan gate verifikasi.

### B. Verify OTP

**Usulan:** `POST /api/v1/auth/email-verification/verify-otp`

Payload konseptual:

```json
{ "email": "user@example.com", "otp": "012345" }
```

Jika existing registration memiliki challenge/verification-scoped credential yang lebih aman, gunakan itu sebagai pengikat akun, dan dokumentasikan kebutuhan Flutter. Tidak boleh memakai role dari request.

Sukses: status email terverifikasi (mengacu pada response envelope/resource existing). Gagal: error validasi/generic sesuai kebijakan, tanpa membocorkan digest atau informasi account yang tidak perlu.

### C. Resend OTP

**Usulan:** `POST /api/v1/auth/email-verification/resend-otp`

Payload: email atau verification-scoped context dari registrasi sesuai desain aman yang dipilih. Jangan mengirim email untuk role 1/2 atau akun Google yang tidak membutuhkan OTP. Tanggapi email yang tak dikenal secara generik untuk mencegah enumerasi; terapkan limit akun/IP. Cooldown dapat dikomunikasikan sebagai `retry_after_seconds` tanpa membocorkan keberadaan akun.

### D. Error, validasi, dan dokumentasi

- Validasi OTP sebagai **string enam digit**, bukan integer (agar `012345` tidak hilang nol di depan).
- Gunakan HTTP status dan envelope existing; jangan mengubah bentuk respons aplikasi secara sepihak.
- Dokumentasikan `expires_in_seconds`, `resend_after_seconds`, limit percobaan, dan edge cases.
- Android/Flutter **bukan** bagian implementasi PRD ini; tulis handoff nama route, payload, contoh response, dan flow untuk Flutter.

## 8. Komponen Laravel yang Perlu Diaudit / Dikerjakan

1. `routes/api.php` dan route auth v1 existing.
2. Register/auth controller yang ada — **hanya delegasi** ke service dan resource.
3. Service utama Auth/Account — register, issue OTP, verify, resend, transaksi, queue.
4. Form Request khusus verify/resend atau perubahan request existing.
5. Resource JSON/response existing.
6. Eloquent model + migration baru bila diperlukan.
7. Notification email OTP (queue encrypted), template email berisi kode, masa berlaku, dan peringatan mengabaikan pesan jika bukan pengguna yang meminta.
8. Konfigurasi email + queue existing, `.env.example`, README dan runbook.
9. Middleware/authorization `verified` dan kebijakan role existing (jangan menyebabkan regression login admin/Google).
10. Test fitur, integrasi queue, dan concurrency MySQL nyata.

## 9. Email dan Operasional

- **SMTP Gmail sudah dikonfigurasi oleh pemilik proyek di `.env`; jangan overwrite atau meminta App Password dicantumkan di laporan.**
- Audit konfigurasi `MAIL_*`, `QUEUE_CONNECTION`, notifikasi queue, worker, migrasi tabel queue/failed jobs sesuai environment aktual.
- Jangan mengklaim "email berhasil dikirim" hanya karena job berhasil di-dispatch.
- Pastikan `php artisan config:clear`, worker `php artisan queue:work --tries=3 --backoff=60`, `php artisan queue:failed`, dan prosedur retry dijelaskan sesuai setup.
- Pengiriman SMTP nyata dan penerimaan inbox dinyatakan **NOT VERIFIED** sampai benar-benar diuji. Jangan kirim email ke alamat sembarang tanpa izin.
- Pastikan kode OTP tidak tampil di log/exception/telemetry produksi.

## 10. Skenario Uji Wajib

### Feature/Integration

1. Register email/password menghasilkan user `role = 0`, belum verified, dan queued OTP, tanpa bocor OTP pada respons.
2. OTP enam digit dan validasi zero-padding (misalnya `012345`).
3. OTP valid mengisi `email_verified_at`; OTP lama tidak dapat dipakai ulang.
4. OTP salah, tidak ada, dan kedaluwarsa (10 menit) ditolak.
5. Lima kali salah menonaktifkan OTP; percobaan keenam tetap ditolak.
6. Resend sebelum 60 detik dibatasi; setelahnya membuat OTP baru dan membatalkan lama.
7. Resend/verifikasi untuk akun tidak dikenal tidak membuka account enumeration.
8. Registrasi publik mengabaikan/menolak input `role = 1`/`2` dan tidak pernah membuat admin.
9. Provisioning/login admin (`role = 1/2`) tetap berfungsi **tanpa OTP user**.
10. Google Sign-In user valid tetap berfungsi tanpa OTP tambahan, dengan verifikasi claim Firebase tetap dijaga.
11. User belum verified tidak dapat mengakses rute yang mensyaratkan email verified; user verified bisa.
12. OTP tidak diterima untuk forgot/reset password; flow password reset link existing tetap PASS.
13. Rollback transaksi tidak meninggalkan verified flag/OTP yang inkonsisten; job queued **setelah commit**.
14. Payload queue terenkripsi dan tidak membocorkan OTP mentah melalui persistence/log; job tertunda usang tidak mengirim OTP yang batal.
15. Rate limit resend dan verify berlaku efektif (akun dan IP).
16. Validasi email/OTP mengikuti bentuk error API existing; jangan bocorkan hash.
17. Regresi JWT login, refresh rotation, logout, dan revokasi token tetap PASS.

### MySQL concurrency nyata (wajib)

18. Dua proses/koneksi terpisah memverifikasi OTP sama bersamaan -> **tepat satu sukses**.
19. Verify dan resend paralel -> tidak ada token lama yang diterima sesudah invalidasi; tidak ada user verified dengan OTP aktif yang bisa dimanfaatkan.
20. Dua resend paralel -> satu OTP terbaru yang valid, tidak ada dua record aktif.

Gunakan test database terpisah. **Jangan membersihkan database atau file untracked yang bukan milik pengerjaan ini tanpa izin.**

## 11. Definisi Selesai / Acceptance Criteria

- [ ] Registrasi user email/password `role = 0` mengirim OTP 6 digit lewat queued email, tanpa signed verification link untuk pendaftaran baru.
- [ ] OTP verified -> `email_verified_at` terisi; OTP invalid/replay/expired/blocked tidak mengubah akun.
- [ ] Single-use dan invalidasi resend **terbukti** lewat pengujian konkurensi MySQL nyata.
- [ ] Penyimpanan aman, percobaan terbatas, cooldown resend dan rate limit aktif, job payload terenkripsi.
- [ ] Admin/superadmin tidak terpengaruh; Google Sign-In tidak diberi OTP tambahan.
- [ ] Forgot/Reset Password tetap lewat tautan email, tanpa regression.
- [ ] Tests Laravel dan regresi terkait PASS, formatting/check PASS.
- [ ] Endpoint + contoh request/response + status SMTP/worker nyata terdokumentasi.
- [ ] Audit `security_auditor` menyatakan tidak ada temuan Critical/High terbuka pada cakupan perubahan.

**Klasifikasi hasil:** `CODE COMPLETE` boleh jika acceptance teknis dan tests lulus; `E2E VERIFIED` hanya jika email benar-benar terkirim dan kode dipakai dari client pada perangkat. Jangan samakan pengujian mailer fake/array dengan pengiriman Gmail nyata.

## 12. Larangan Perubahan / Non-goals

- Tidak mengubah source Flutter (`RegisterView`, `AuthSession`, UI OTP) pada tugas ini.
- Tidak mengubah forgot/reset password dari link ke OTP.
- Tidak mengganti login admin, register/provisioning admin, atau Google auth existing.
- Tidak mengubah JWT arsitektur, catalog, booking, payment, notification FCM, atau business logic lain.
- Tidak menambahkan dependency yang tidak diperlukan.
- Tidak melakukan Git commit/push/deploy tanpa instruksi eksplisit.
- Jangan menghapus dukungan legacy signed verification bagi akun lama **sebelum** mengaudit dampak/migrasi; pastikan pengguna baru memakai OTP, dokumentasikan kebijakan kompatibilitas.

## 13. Handoff dan Laporan Akhir Codex

Wajib laporkan:

1. File/agent yang tersedia dan benar-benar digunakan; file agent yang tidak ditemukan.
2. Analisis auth/email verification existing serta keputusan kompatibilitas.
3. Daftar file berubah dan fungsi tiap perubahan.
4. Skema OTP, cara digest, enkripsi queue, lock/transaksi, dan validasi role.
5. Kontrak route/register/verify/resend untuk Flutter beserta sample JSON **tanpa OTP nyata**.
6. Output tes Laravel, MySQL konkurensi, serta regresi auth.
7. Review keamanan: Critical/High resolved atau status blocker.
8. Status Gmail SMTP (configured vs real inbox delivery), queue worker dan error monitoring.
9. Status akhir: `COMPLETE`, `PARTIAL`, `BLOCKED`, dengan alasan konkret dan hal yang belum diverifikasi.

---

### Prompt Codex (copy-paste)

> Implementasikan PRD `PRD-Wisata-Daerahku-Backend-Email-Verification-OTP.md` pada repository Laravel aktif. Prioritas: OTP email 6 digit untuk registrasi email/password user `role=0` saja. Wajib gunakan `.codex/agents/backend_engineer.toml` dan `.codex/agents/security_auditor.toml` jika tersedia; jika tidak, laporkan dan gunakan spesialis yang tersedia. Audit source existing sebelum mengubah kode. Pertahankan login admin/superadmin, Firebase Google login dan forgot/reset password via link. Ikuti pola controllers tipis + services inti + requests validasi + resources JSON; enkripsi queue, after-commit, expiry 10m, cooldown 60s, 5 percobaan, atomic single-use dan uji konkurensi MySQL nyata. Jangan mengubah Flutter, `.env` dengan rahasia, melakukan commit, push, atau deploy. Akhiri dengan laporan implementasi, tes, security audit dan kontrak API Flutter.
