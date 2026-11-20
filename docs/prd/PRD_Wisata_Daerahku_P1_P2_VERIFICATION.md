# Verifikasi PRD Wisata Daerahku P1–P2

19 November 2026

Verifikasi menggunakan perubahan lokal di atas Laravel `78c6200` dan Flutter `5843d62`. Kedua worktree diperiksa sebelum implementasi. Tidak ada commit atau deployment baru.

MySQL 8.4.13 berjalan dalam kontainer terisolasi dengan skema `pami_p1p2_test`. Akun database uji hanya memiliki akses ke skema tersebut. Pixel 7 memakai AVD terpisah dan APK debug dengan `APP_ENV=local`, alamat API `http://10.0.2.2:18089/api/v1`, dan simulator checkout nonaktif. OTP dikirim ke Mailpit lokal. Ledger pembayaran berisi fixture dengan bukti tangkapan yang ditanam langsung dari helper tes. Seluruh pengujian laporan dilakukan tanpa transaksi gateway.

| ID | Status | Bukti |
|---|---|---|
| F1 | PASS | APK Android yang sama menampilkan **Sandbox · Data uji** dan **Rp20.000**, lalu **Produksi** dan **Rp40.000** setelah konfigurasi server diganti dan laporan dimuat ulang. APK final juga menyegarkan laporan dari produksi ke sandbox ketika aplikasi dibuka kembali tanpa instal ulang. API mengembalikan `verified_sandbox_events` dan `verified_production_events` sesuai konfigurasi. |
| F2 | PASS | Uji MySQL menanam nominal Rp20.000 sandbox, Rp30.000 demo, dan Rp40.000 produksi pada destinasi serta tanggal yang sama. Filter legacy tidak mengubah sumber. Guard demo tetap berlaku dan konfigurasi tak dikenal mengembalikan 503. |
| F3 | PASS | Ringkasan dan laporan Admin di Android menampilkan Rp20.000 dalam sandbox dan Rp40.000 dalam produksi dengan cakupan penugasan yang sama. Tes MySQL menolak destinasi di luar penugasan dan mengizinkan cakupan global Superadmin. |
| F4 | PASS | Widget test menolak sumber, `test_only`, dan scope yang bertentangan. Respons kosong menampilkan status belum tersedia tanpa angka. Respons tertunda dari pemuatan sebelumnya tidak menggantikan angka terbaru. |
| E1 | PASS | Tes Flutter memeriksa fallback konflik dan kode baru, validasi inline, 401 kredensial, serta 429 dengan `Retry-After`. Teks mentah server tidak muncul pada pesan atau kolom yang diuji. |
| E2 | PASS | Widget test penutupan akun memeriksa satu pengiriman, pemeriksaan sesi setelah respons rusak, dan tidak adanya klaim sukses. Regresi checkout memeriksa pemulihan status, pengiriman sekali, dan pilihan pembayaran tanpa pengalihan ke demo. |
| A1 | PASS | Android membuka OTP dari Profil dengan email terkunci, menerima OTP dari Mailpit, menyimpan kata sandi, dan kembali ke login. API menolak kata sandi lama dan menerima kata sandi baru. Tes backend memeriksa refresh token lama dan widget test mencakup alur lupa kata sandi dari login. |
| A2 | PARTIAL | OTP salah, kedaluwarsa dan terpakai, pembatasan resend, tiket bekas, konfirmasi, dan pembatalan tercakup dalam tes terarah. Backend menolak OTP untuk akun Google-only sambil menjaga respons awal generik. Penjelasan untuk akun Google-only belum diverifikasi melalui login Google Android aktual. |
| A3 | PARTIAL | Android menutup akun lokal dan kembali ke login. Navigasi Kembali meninggalkan aplikasi. Bearer sebelumnya ditolak dengan 401. Backend menerima klaim Google segar dari subject yang tertaut dalam tes integrasi, tetapi autentikasi Google aktual pada emulator belum dijalankan. |
| A4 | PASS | Bukti kata sandi salah tidak mengakhiri sesi. Tes backend menolak bukti Google lama, subject lain, dan dua bukti dalam satu permintaan. Widget test mencakup batal dan tombol yang dinonaktifkan selama POST. Dua proses MySQL terpisah mencoba menutup dua akun Superadmin. Satu berhasil dan satu menerima `last_active_superadmin`. |
| U1 | PASS | Android menampilkan aksi akun dan Bahasa Indonesia di Profil. Entri alternatif bahasa dan jenis notifikasi telah dilepas. Pemeriksaan kode menu Superadmin memastikan entri audit global sudah tidak terhubung ke navigasi. |

## Hasil alat

- Laravel Feature PASS, 125 tes dan 804 assertion. MySQL terarah PASS, 4 tes dan 60 assertion.
- Flutter terarah PASS, 174 tes. Lima kasus yang gagal juga pada checkout bersih `5843d62` dikeluarkan dari hitungan PASS. Kasus tersebut mencakup pemulihan antrean, judul countdown pembayaran, overflow layar status lunas 320 px, dan dua skenario penerbitan atau jadwal tiket.
- Pint, analisis Dart terarah, dan build APK debug lulus. Analisis Dart masih melaporkan info linter. SHA-256 APK final `d803e82aa5d63825afa996a35f8f923d70930bcab0b4509c99be8a9f1cfae420`.

Backend dan APK perlu dirilis dengan kontrak environment yang sejalan. APK lama masih dapat menolak metadata yang berbeda dari environment yang dahulu dikirimnya. Verifikasi login Google Android membutuhkan akun Google uji yang terhubung.
