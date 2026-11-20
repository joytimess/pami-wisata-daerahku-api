# PRD Wisata Daerahku · Keuangan, pesan kesalahan, dan pengaturan akun

**Versi 1.0 · 19 November 2026 · Laravel API dan Flutter Android**

## 1. Tujuan dan batas pekerjaan

Admin dan Superadmin perlu mengetahui asal angka keuangan yang mereka lihat. Pengguna perlu mengetahui tindakan yang bisa diambil saat permintaan gagal, mengganti kata sandi dari Profil, dan menutup akun sendiri setelah verifikasi identitas.

| Prioritas | Hasil yang disepakati |
|---|---|
| P1 · Environment keuangan | Laravel menentukan lingkungan laporan. Ringkasan dan laporan lengkap mengikuti konfigurasi aktif pada pemuatan berikutnya, dengan build Flutter yang sama. |
| P2-A · Pesan API | Flutter mengubah status HTTP, kode domain, dan validasi per kolom menjadi pesan Indonesia yang dapat ditindaklanjuti. |
| P2-B · Ubah Kata Sandi | Pengguna membuka alur OTP email dari Profil dan masuk kembali setelah perubahan berhasil. |
| P2-C · Hapus Akun | Flutter menghubungkan konfirmasi dan autentikasi ulang ke layanan penutupan akun Laravel. |

**Keputusan menu.** Aktivitas Audit Global ditunda. Sembunyikan entri placeholder dari Superadmin. Sembunyikan pilihan jenis notifikasi dan pilihan bahasa lain yang belum berfungsi. Izin notifikasi perangkat dan Bahasa Indonesia tetap berlaku. P3, implementasi feed audit, penggantian gateway, dan perubahan aturan booking atau tiket berada di luar fase ini.

## 2. Baseline yang ditinjau

Tinjauan statis terhadap branch `main`. Tabel membedakan perilaku kode saat ini dari target implementasi.

| Area | Perilaku saat ini | Implikasi |
|---|---|---|
| Sumber environment | `config/midtrans.php` membaca `MIDTRANS_ENVIRONMENT`. `PaymentGateway::activeEnvironment()` memilih demo bila flag simulasi aktif pada lingkungan aplikasi yang diizinkan, atau memakai konfigurasi Midtrans. | Gunakan resolver yang sudah ada. `APP_ENV` sendiri tidak menentukan sandbox Midtrans. |
| Laporan Laravel | `FinancialDashboardRequest` menerima filter environment. `FinancialAnalyticsService` memakai filter tersebut atau default `production`. Query sudah membatasi environment pada intent dan attempt, scope destinasi, dan sumber pembayaran. | Ganti pemilihan environment pada laporan. Pertahankan perhitungan dan otorisasi. |
| Laporan Flutter | `operations_financial.dart` memilih `demo` untuk preview dan `sandbox` untuk laporan penuh, mengirimkannya dalam query, lalu memvalidasi respons terhadap pilihannya. Kartu beranda Superadmin hanya membuka laporan. | Hilangkan pilihan lokal di semua pemanggil dan pertahankan validasi metadata respons. |
| Kesalahan | `AuthSession` menyimpan status, code, context, dan fieldErrors. Jalur 409 dapat meneruskan `decoded['message']`. Jalur 422 menggabungkan string validasi. Form reset juga membaca fieldErrors langsung. | Perbaiki produsen pesan dan seluruh konsumen terdampak, termasuk mapper booking dan inline error. |
| Profil | Aksi ubah kata sandi dan hapus akun di `profil_user_screen.dart` masih memberi pemberitahuan fitur belum tersedia. Layar forgot/verify/reset dan endpoint OTP sudah ada. | Sambungkan titik masuk Profil ke alur nyata. |
| Penutupan | `POST /api/v1/profile/close` sudah mengunci baris terkait, memeriksa autentikasi ulang, menjaga Superadmin aktif terakhir, menandai akun `closed`, mencabut refresh token, dan menghapus device token. | Pakai endpoint dan arti “tutup akun” tersebut. Jelaskan konsekuensinya sebelum pengguna mengonfirmasi. |

## 3. P1 · Laporan keuangan mengikuti Laravel

**Kontrak baca.** Gunakan `GET /api/v1/admin/dashboard/financial` yang sudah dipakai Flutter. Setelah otorisasi, Laravel memperoleh environment efektif dari `PaymentGateway::activeEnvironment()`, memvalidasi nilainya, lalu menggunakannya untuk seluruh agregasi dan metadata dalam satu respons. Filter periode dan destinasi tetap tersedia. Jangan menambahkan konfigurasi pembayaran pada Flutter, selector pengguna, atau panggilan konfigurasi terpisah.

| Environment efektif | Provider laporan yang valid | Source saat angka tersedia | Tampilan yang dituju |
|---|---|---|---|
| `sandbox` | `midtrans`, `doku`, `mixed` sesuai data historis | `verified_sandbox_events` | **Sandbox · Data uji** |
| `production` | `midtrans`, `doku`, `mixed` sesuai data historis | `verified_production_events` | **Produksi** |
| `demo` | `demo` | `simulated_demo_events` | **Demo · Data simulasi** |

**Kompatibilitas yang diusulkan.** Selama transisi, permintaan lama dengan query environment yang dikenal tetap diterima. Laravel mengabaikannya untuk pemilihan sumber dan mengembalikan environment efektif. Rilis Flutter yang bisa memvalidasi metadata dinamis bersama perubahan backend. Build lama dapat menolak metadata yang berbeda dari environment pesanannya. Periksa kombinasi versi saat peluncuran.

**Aturan penyajian**

- Ringkasan Admin dan laporan penuh menyampaikan asal data yang sama untuk konfigurasi yang sama. Bandingkan nominal hanya untuk periode, destinasi, dan scope yang identik. Kartu navigasi Superadmin tetap membuka laporan global.
- Validasi `environment`, `provider`, `source`, `test_only`, `currency=IDR`, `availability`, dan `scope` sebelum menampilkan angka. `test_only=true` untuk demo/sandbox. Scope Admin adalah `assigned_destinations`; Superadmin `global`. Cocokkan `destination_guid` dengan filter yang diminta.
- Dengan `availability=unavailable` dan `source=unavailable`, tampilkan “Data keuangan belum tersedia untuk periode ini.” Pertahankan nilai null. Dengan `partial`, tampilkan hanya metrik yang tersedia dan nyatakan keterbatasannya, misalnya biaya gateway. Label sumber mengikuti metadata tervalidasi.
- Bila konfigurasi tidak dikenal, metadata bertentangan, akses berubah, atau respons terlambat dari sesi/filter lama, kosongkan angka lama dan berikan pesan aman serta aksi muat ulang jika relevan. Jangan mencoba environment lain.
- Riwayat DOKU yang sah dapat berkontribusi pada laporan environment terkait. Perubahan ini tidak mengaktifkan DOKU untuk pembayaran baru. Angka demo, sandbox, dan produksi harus terisolasi meskipun periode dan destinasi sama.

## 4. P2-A · Kontrak pesan kesalahan

Laravel mempertahankan status HTTP dan kode domain stabil. Flutter memakai satu pemetaan yang dapat digunakan oleh sesi, repository, dan layar. Pertahankan status, code, konteks terpilih, serta nama kolom untuk logika aplikasi. Pesan tampilan berasal dari katalog Indonesia. Untuk situasi yang perlu dibedakan tetapi belum memiliki kode stabil, tambahkan kode secara aditif di Laravel. Hindari ketergantungan pada isi kalimat Inggris.

| Kondisi | Target UX dan tindakan |
|---|---|
| 422 + kolom dikenal | Tampilkan petunjuk di kolom yang perlu diperbaiki. Contoh konfirmasi tidak cocok: “Ulangi kata sandi yang sama.” Pakai sebab spesifik hanya bila terbukti oleh rule/code atau validasi lokal. |
| Kode domain dikenal | Utamakan tindakan spesifik. Contoh `insufficient_capacity`: “Kuota tidak mencukupi. Kurangi jumlah pengunjung atau pilih tanggal lain.” |
| 401 | Bedakan login gagal, autentikasi ulang aksi sensitif gagal, dan sesi berakhir. Salah kata sandi pada penutupan akun harus mengizinkan koreksi tanpa langsung mengeluarkan pengguna. |
| 403 / 404 | Jelaskan keterbatasan akses atau data yang sudah tidak tersedia. Jangan menawarkan aksi yang memerlukan akses yang sama. |
| 409 tanpa kode dikenal | “Data sudah berubah. Muat ulang untuk melihat kondisi terbaru.” Jangan menampilkan message backend. |
| 422 tanpa rule/kolom dikenal | “Periksa kembali data yang diisi.” Pertahankan isian yang masih valid. |
| 429 | “Terlalu banyak percobaan. Coba lagi nanti.” Bila tersedia, gunakan `Retry-After` untuk waktu tunggu. |
| Timeout, jaringan, 5xx, respons rusak | Beri pesan sesuai sebab. Pembacaan boleh dimuat ulang. Untuk mutasi yang mungkin sudah diterima, jelaskan hasil belum diketahui dan periksa status sebelum mengulang. |
| Kode baru atau tak dikenal | Gunakan fallback status yang aman. Jangan menampilkan JSON, nama exception, token, pesan provider, atau isi mentah `errors[field]`. |

Periksa snackbar, dialog, banner, dan error inline pada alur terdampak. Kegagalan pembayaran biasa tetap membuka penanganan error yang aman. Pertahankan idempotensi, pemulihan status transaksi, dan instruksi VA/QR yang benar-benar diterbitkan. Diagnostik internal cukup memuat status, kode, dan identitas permintaan yang aman bila tersedia.

## 5. P2-B · Ubah Kata Sandi dari Profil

1. Aksi **Ubah Kata Sandi** membuka alur dengan email akun aktif yang terikat ke sesi. Pengguna melihat alamat tujuan sebelum meminta kode. Dari Profil, alamat tujuan tidak dapat dialihkan ke akun lain.
2. Pakai `POST /api/v1/auth/forgot-password`, resend melalui `auth/password-reset/resend-otp`, lalu `auth/password-reset/verify-otp` dengan email dan OTP. Respons awal tetap generik. Hormati pembatasan percobaan dan permintaan ulang.
3. Setelah verifikasi, gunakan `reset_ticket` untuk `POST /api/v1/auth/reset-password` dengan email, password, dan password_confirmation. Kontrak saat ini menetapkan minimal 8 karakter, konfirmasi cocok, serta tiket berumur 300 detik. Tampilkan tindakan minta kode baru ketika tiket kedaluwarsa.
4. Setelah sukses, bersihkan state sesi dan navigasi privat, lalu arahkan ke login. Laravel sudah mencabut refresh token dan menghapus device token. Membatalkan sebelum berhasil mengembalikan pengguna ke Profil tanpa mengubah kata sandi.
5. Untuk akun Google-only, jelaskan bahwa kata sandi Google dikelola melalui Google. Akun yang memiliki kredensial lokal dapat menggunakan alur OTP sesuai kelayakan backend. **Usulan teknis** bila Flutter memerlukan pembeda, expose kapabilitas minimal dari sesi terautentikasi dan periksa kelayakan di Laravel. `google_avatar_available` hanya menunjukkan ketersediaan foto. Jangan menggunakannya untuk menentukan metode autentikasi.

## 6. P2-C · Hapus Akun

Titik masuk **Hapus Akun** membuka konfirmasi yang menjelaskan “Akun akan ditutup dan Anda akan keluar. Anda tidak dapat masuk kembali dengan akun ini.” Sediakan **Batal** dan aksi eksplisit **Tutup akun**. Hindari janji penghapusan seluruh transaksi atau riwayat.

| Tahap | Perilaku |
|---|---|
| Verifikasi | Kirim Bearer sesi aktif ke `POST /api/v1/profile/close` dengan tepat satu bukti. Akun lokal memakai `password`. Untuk Google, minta autentikasi ulang dan kirim Firebase `id_token` baru. Laravel memeriksa provider Google, subject tertaut, dan usia autentikasi maksimal 300 detik. |
| Pengiriman | Nonaktifkan tombol selama permintaan berjalan. Pembatalan dialog atau pembatalan login Google tidak mengirim permintaan penutupan. |
| Berhasil | Tampilkan konfirmasi, bersihkan kredensial dan cache privat, kembali ke login. Tombol kembali tidak membuka Profil. Riwayat booking dan pembayaran tetap mereferensikan akun tertutup. |
| Ditolak | Bukti keliru/kedaluwarsa meminta verifikasi ulang. Superadmin aktif terakhir melihat “Akun ini adalah Superadmin aktif terakhir dan tidak dapat ditutup.” Usulkan kode domain stabil bagi kasus ini, sambil mempertahankan 422 dan kolom `account`. |
| Hasil belum pasti | Bila respons hilang setelah submit, jangan mengklaim akun sudah ditutup. Periksa status sesi/akun melalui backend. Tampilkan hasil yang dapat dipastikan dan minta pengguna masuk kembali bila aksesnya sudah berakhir. |

## 7. Kriteria penerimaan dan bukti

| ID | Skenario verifikasi | Hasil lulus |
|---|---|---|
| F1 | Jalankan build Flutter yang sama pada sandbox lalu produksi setelah konfigurasi efektif Laravel dimuat ulang | Request baru tanpa override. Label dan angka mengikuti sumber masing-masing tanpa rebuild. |
| F2 | Isi data demo, sandbox, dan produksi pada tanggal/destinasi yang sama dengan nominal berbeda | Tiap laporan hanya menjumlahkan nominal environment aktif. Query legacy yang berbeda tidak mengalihkan sumber. Demo hanya aktif sesuai guard Laravel. |
| F3 | Bandingkan preview Admin dan laporan dengan filter sama, lalu uji admin lintas penugasan dan Superadmin | Nominal konsisten, akses lintas penugasan ditolak, laporan global hanya tersedia bagi role yang berhak. |
| F4 | Kirim sumber tidak cocok, test_only keliru, data parsial, tanpa sumber, dan respons lama setelah pergantian filter | Respons invalid tidak merender angka. Parsial dan kosong tampil berbeda. Angka lama tidak muncul kembali. |
| E1 | Uji tiap kelompok tabel error, termasuk 409/422 dengan kalimat mentah dan code baru | Semua permukaan menampilkan pesan Indonesia aman. Kolom yang dikenal tetap mendapat petunjuk. |
| E2 | Timeout setelah submit pembayaran atau aksi akun | Tidak terjadi submit ganda otomatis atau klaim sukses palsu. Checkout biasa tidak berpindah ke simulasi. |
| A1 | Dari Profil, selesaikan OTP dan ganti kata sandi akun lokal | Kata sandi baru berhasil untuk login, kata sandi lama gagal, refresh token lama ditolak. Alur lupa kata sandi dari halaman login tetap berjalan. |
| A2 | Coba OTP salah/kedaluwarsa, resend dibatasi, tiket dipakai ulang, konfirmasi salah, batal, dan akun Google-only | Setiap kasus punya tindakan yang sesuai. Tidak ada perubahan kata sandi tanpa verifikasi yang sah. |
| A3 | Tutup akun lokal dan akun Google melalui Android, kemudian coba akses ulang | Masing-masing memakai bukti yang tepat, akun menjadi closed, sesi dibersihkan dan akses privat ditolak. |
| A4 | Uji bukti salah, token Google lama atau milik akun lain, klik ganda, batal, dan dua penutupan Superadmin bersamaan | Tidak ada penutupan tak sah. Sedikitnya satu Superadmin aktif tersisa. |
| U1 | Periksa menu Profil dan Superadmin setelah perubahan | Dua aksi akun berfungsi pada pengguna yang memenuhi syarat. Placeholder Audit Global, jenis notifikasi, dan bahasa lain tersembunyi. |

**Urutan implementasi.** Selesaikan kontrak environment Laravel dan konsumennya di Flutter sebagai satu perubahan lintas repo. Untuk P2, dahulukan mapper error, sambungkan alur OTP dan tutup akun, lalu verifikasi lewat layar Profil. Gunakan pengujian Laravel terarah pada MySQL, termasuk konkurensi untuk proteksi Superadmin terakhir, serta widget test dan uji Android untuk navigasi, autentikasi ulang, dan pembersihan sesi. Catat commit, konfigurasi uji nonrahasia, serta hasil per ID. Seluruh gate di atas adalah target implementasi. Tinjauan PRD ini tidak menjalankan pengujian runtime.

## 8. Referensi kode

**Laravel · `7b976e4`**

- [Routing API](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/routes/api.php) · [Konfigurasi Midtrans](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/config/midtrans.php) · [Resolver gateway](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Services/PaymentGateway.php)
- [Validasi filter](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Http/Requests/FinancialDashboardRequest.php) · [Agregasi laporan](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Services/FinancialAnalyticsService.php) · [Tes laporan](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/tests/Feature/FinancialAnalyticsTest.php)
- [OTP kata sandi](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Services/PasswordResetOtpService.php) · [Kontrak reset](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Http/Requests/Auth/ResetPasswordRequest.php) · [Identitas profil](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Http/Resources/UserResource.php)
- [Kontrak penutupan](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Http/Requests/Auth/CloseAccountRequest.php) · [Layanan akun](https://github.com/joytimess/pami-wisata-daerahku-api/blob/7b976e4a7f9c5f65e988ea2236f7dfad78eab16d/app/Services/AccountService.php)

**Flutter · `5843d62`**

- [Dashboard keuangan](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/lib/screens/admin/operations_dashboard/operations_financial.dart) · [Tes regresi keuangan](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/test/operations_queue_finance_regression_test.dart)
- [Penanganan sesi dan respons API](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/lib/services/auth_session.dart) · [Mapper booking](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/lib/data/booking_errors.dart)
- [Profil](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/lib/screens/user/profil_user_screen.dart) · [Permintaan OTP](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/lib/screens/auth/forgot_password_screen.dart) · [Verifikasi OTP](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/lib/screens/auth/verify_reset_otp_screen.dart) · [Form reset](https://github.com/koyotot/pami-wisata-daearahku/blob/5843d62e99d30335965a7f0ac36675e38657c6b1/lib/screens/auth/reset_password_screen.dart)
