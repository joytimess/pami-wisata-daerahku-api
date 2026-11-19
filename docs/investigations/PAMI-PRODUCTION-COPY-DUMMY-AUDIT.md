# Audit teks produksi Wisata Daerahku

**24 temuan** berdasarkan masalah yang berbeda. **15 FIXED, 5 KEEP, 4 NEEDS_APPROVAL.** Tidak ditemukan P0. Revisi awal backend `7b976e4` dan Flutter `aee50e7`.

**Belum siap dinyatakan produksi.** Checkout gagal sudah menampilkan kesalahan tanpa membuka simulasi. Laporan keuangan masih membaca data uji dan 30 tes Flutter masih gagal.

## Alur yang ditelusuri

- **Pembayaran pengunjung.** Laravel memuat metode dan status transaksi di `routes/api.php:43` dan `app/Services/PaymentService.php:87`. Flutter membaca keduanya melalui `lib/data/business_repository.dart:462`. Saat pengguna melanjutkan pembayaran, checkout mengirim permintaan melalui `lib/data/business_repository.dart:509`. Sebelumnya kegagalan dapat membuka preview lokal tanpa instruksi resmi.
- **Profil, booking dan tiket.** Statistik profil berasal dari repository. Pesan fitur akun yang belum tersedia ditulis langsung di layar Flutter. Status booking, pembayaran dan label uji berasal dari `app/Http/Resources/BookingResource.php:24`. Flutter memakainya untuk detail pendaftaran, tiket, dan tindakan lanjutan.
- **Admin dan Superadmin.** Keduanya memakai shell operasional di `lib/screens/shared/session_destination.dart:23`. Endpoint `routes/api.php:56` mengembalikan sumber, cakupan dan lingkungan laporan. Flutter memeriksa ketiganya sebelum menampilkan angka.

## Perubahan

| Prioritas | Temuan | Hasil | Lokasi |
|---|---|---|---|
| P1 | **Checkout biasa.** HTTP 500 membuka instruksi prototype dan timer lokal 15 menit. | Kesalahan muncul di checkout. Instruksi VA/QR hanya muncul melalui jalur pembayaran asli. Regresi memeriksa satu POST dan penggunaan kembali kunci setelah restart. | `lib/screens/user/booking_payment_screen.dart:121` |
| P1 | **Asal angka keuangan.** Laporan lengkap Admin berlabel DEMO walau meminta sandbox. Superadmin harus membuka rincian untuk melihat sumbernya. | Kedua laporan menandai respons uji yang lolos validasi dengan SANDBOX / TEST_ONLY. Ringkasan DEMO tetap berlabel DEMO. | `lib/screens/admin/operations_dashboard/operations_financial.dart:255` |
| P1 | **Pembayaran uji pengunjung.** Header checkout menyebut konfirmasi server tanpa penanda sandbox. Detail tanpa label memakai DEMO secara otomatis. | Checkout mengenali snapshot Midtrans uji. Detail memakai “Pembayaran uji” jika API hanya memastikan test_only. | `lib/screens/user/booking_payment_screen.dart:1869`<br>`lib/screens/user/bukti_pendaftaran_screen.dart:1203` |
| P2 | **Metode tidak tersedia.** Kode alasan seperti provider_expiry_window_unverified dan pesan “gate simulator” muncul ke pengunjung. | Tampilkan alasan yang dapat dibaca. Kode baru mendapat pesan netral, tanpa mengaktifkan metode. | `lib/screens/user/booking_payment_screen.dart:740`<br>`lib/data/payment_models.dart:300` |
| P2 | **Status pembayaran terakhir.** “Attempt terakhir” menampilkan enum mentah. | Label Indonesia membedakan penerimaan oleh penyedia, verifikasi, dan hasil yang belum diketahui. | `lib/widgets/booking_payment_section.dart:226`<br>`lib/data/payment_models.dart:278` |
| P2 | **Instruksi dan konfirmasi bayar.** “Memeriksa metode pembayaran dari server” dan “setelah server memverifikasinya”. | Teks memisahkan laporan penyedia dari konfirmasi pendaftaran dan menyebut nominal serta batas waktu pada instruksi. | `lib/widgets/booking_payment_section.dart:220`<br>`lib/screens/user/booking_payment_screen.dart:1480` |
| P2 | **Profil dan keluar.** “Belum tersedia untuk akun API” dan “Pencabutan sesi server”. | Perubahan kata sandi dan penghapusan akun menyatakan batasan masing-masing. Pesan keluar membedakan perangkat dari sesi akun. | `lib/screens/user/profil_user_screen.dart:279`<br>`lib/screens/user/profil_user_screen.dart:204` |
| P2 | **Booking dan pemulihan.** “Memerlukan akun dan destinasi Laravel”, “ditutup di server”, serta status pembatalan teknis. | Pesan menjelaskan cara memilih destinasi kembali, status pengiriman, dan hasil pembatalan. | `lib/screens/user/daftar_kunjungan_screen.dart:358`<br>`lib/screens/user/bukti_pendaftaran_screen.dart:1938` |
| P2 | **Tiket dan unduhan.** Pemeriksaan tiket “ke server”, “Waktu server” dan toast berisi path lokal serta keterbatasan pembaca PDF. | Tampilkan proses pemeriksaan, waktu check-in, atau “Dokumen berhasil diunduh” setelah penyimpanan sukses. | `lib/screens/shared/ticket_link_screen.dart:170`<br>`lib/widgets/booking_ticket_qr_section.dart:342`<br>`lib/screens/user/bukti_pendaftaran_screen.dart:1946` |
| P2 | **Keputusan dan penanganan admin.** Pesan menyebut server, backend dan validasi claim. | Petugas melihat status terakhir, pemegang penanganan, batas waktu dan apakah keputusan sudah terkonfirmasi. | `lib/screens/admin/operations_booking_screen.dart:619`<br>`lib/data/admin_state.dart:488`<br>`lib/data/approval_queue_state.dart:225` |
| P2 | **Kuota, fasilitas dan penugasan.** Validasi backend, perbedaan data server, dan GUID pengganti nama destinasi. | Jelaskan pemeriksaan saat simpan dan perbedaan hasil. Gunakan nama netral ketika destinasi tidak dapat diidentifikasi. | `lib/screens/superadmin/kelola_kuota_view.dart:897`<br>`lib/data/facility_state.dart:141`<br>`lib/screens/superadmin/destination_admin_assignments_screen.dart:178` |
| P2 | **Analitik, filter dan laporan.** “Perbandingan berbasis data Laravel”, “dari server”, scope, snapshot dan istilah attempt. | Nama laporan merujuk destinasi, kinerja admin dan kunjungan. Label keuangan menjelaskan cakupan dan percobaan pembayaran. | `lib/screens/admin/operations_dashboard/operations_dashboard.dart:856`<br>`lib/screens/admin/operations_dashboard/operations_reports.dart:365`<br>`lib/screens/admin/operations_dashboard/operations_filters.dart:222`<br>`lib/screens/admin/operations_dashboard/operations_financial.dart:895` |
| P3 | **Keadaan kosong.** Janji destinasi dan data audit akan hadir serta klaim gateway belum terhubung. | Jelaskan data yang belum tersedia. Layar audit tetap menyediakan tombol kembali. | `lib/screens/user/beranda_user_screen.dart:274`<br>`lib/screens/admin/operations_dashboard/operations_presentation.dart:262` |
| P2 | **Halaman publik backend.** GET / menampilkan Laravel, Laracasts dan Deploy now. | Halaman berbahasa Indonesia mengarahkan pengunjung ke aplikasi Wisata Daerahku. | `resources/views/welcome.blade.php:18` |
| P3 | **Nama dan deskripsi aplikasi.** A new Flutter project. dan wisata_daerahku terlihat pada metadata web atau judul desktop. | Nama tampilan Wisata Daerahku dan deskripsi fungsi aplikasi. Nama executable dan identifier tetap mengikuti konfigurasi build. | `web/manifest.json:8`<br>`web/index.html:26`<br>`linux/runner/my_application.cc:48`<br>`windows/runner/main.cpp:30`<br>`windows/runner/Runner.rc:98` |

## Dipertahankan

| Prioritas | Temuan | Hasil | Lokasi |
|---|---|---|---|
| — | **Checkout DEMO terproteksi.** Layar simulator memuat istilah teknis dan skenario uji. | Satu pemanggil UI. Build production menolak flag demo meski diminta. Tombol juga memerlukan kedua snapshot demo. Laravel hanya mengizinkan local/testing/demo dengan flag aktif. | `lib/core/config/app_config.dart:20`<br>`lib/widgets/booking_payment_section.dart:240`<br>`app/Services/PaymentGateway.php:11`<br>`app/Services/DemoPaymentService.php:26` |
| — | **Preview instruksi dan endpoint lama.** Widget simulasi lokal dan GET payment-preview adalah dua implementasi terpisah. | Widget disimpan untuk tooling dan tidak lagi dipanggil oleh lib produksi. Endpoint tetap dikontrol flag, memeriksa pemilik booking dan mengembalikan can_pay=false. | `lib/screens/user/payment_instruction_preview_screen.dart:12`<br>`app/Services/PaymentPreviewService.php:13` |
| — | **Provenans transaksi uji.** DEMO / TEST_ONLY, SANDBOX, notifikasi transaksi uji dan riwayat transaksi nonproduksi. | Pertahankan penanda agar uang uji dapat dikenali. Data historis tidak ditulis ulang. | `app/Services/BookingNotificationService.php:29`<br>`app/Services/PaymentEligibility.php:130` |
| — | **Form dan pratinjau konten.** Contoh alamat, nama, harga, pratinjau gambar dan sudut pandang pengunjung. | Hint membantu pengisian. Pratinjau konten admin memperlihatkan destinasi yang dipilih. | `lib/screens/admin/tambah_wisata_screen.dart:965`<br>`lib/screens/admin/daftar_wisata_screen.dart:338` |
| — | **Data pengembangan.** DevelopmentSeeder berisi destinasi (Dev). DatabaseSeeder dapat membuat Test User saat dijalankan. | Pertahankan tooling. Seeder pengembangan memeriksa environment. Seed, akun dan isi database produksi tidak diperiksa. | `database/seeders/DevelopmentSeeder.php:60`<br>`database/seeders/DatabaseSeeder.php:21` |

## Perlu keputusan

| Prioritas | Temuan | Hasil | Lokasi |
|---|---|---|---|
| P1 | **Sumber keuangan produksi.** Beranda Admin meminta demo. Laporan lengkap Admin/Superadmin meminta sandbox termasuk pada build production. Beranda Superadmin hanya membuka menu. | Pemilik produk perlu menetapkan sumber laporan produksi. Mengganti parameter environment mengubah populasi transaksi dan angka laporan. | `lib/screens/admin/operations_dashboard/operations_financial.dart:29`<br>`app/Services/FinancialAnalyticsService.php:14` |
| P2 | **Pesan dinamis dari API.** Konflik 409 dan detail validasi 422 dapat meneruskan message atau pesan per-field dari backend. Notifikasi menampilkan konten yang disimpan. | Perlu kesepakatan pemetaan kode dan bahasa untuk respons bebas, termasuk klien lain. Menimpa semua pesan dapat menghilangkan petunjuk koreksi kolom. | `lib/services/auth_session.dart:195`<br>`lib/services/api_client.dart:13` |
| P2 | **Fitur yang belum tersedia.** Ganti kata sandi dari profil, hapus akun dan riwayat audit global masih tampil sebagai fitur yang belum tersedia. | Teks sudah jujur. Menghapus menu yang disetujui atau menyediakan layanan baru memerlukan keputusan produk. | `lib/screens/user/profil_user_screen.dart:928`<br>`lib/screens/admin/operations_navigation_shell.dart:272` |
| P3 | **Identitas penerbit desktop.** com.example pada metadata perusahaan/hak cipta Windows dan macOS. | Nama pemegang hak belum dikonfirmasi. Identifier penandatanganan dan nama penerbit tidak ditebak. | `windows/runner/Runner.rc:92`<br>`macos/Runner/Configs/AppInfo.xcconfig:14` |

## Verifikasi

| Pemeriksaan | Hasil |
|---|---|
| Flutter, 11 file tes terkait | **216 lulus, 30 gagal**. Revisi awal dengan konfigurasi yang sama menghasilkan 210 lulus dan 35 gagal. Semua 30 nama tes yang masih gagal juga gagal pada revisi awal. |
| Regresi checkout HTTP 500 | **Lulus.** Pesan kesalahan terlihat, preview tidak terbuka, dan pengiriman ulang setelah restart memakai kunci yang sama. Tes ini gagal sebelum perbaikan. |
| Build flag produksi | **Lulus pada widget test.** Dengan `APP_ENV=production` dan `DEMO_PAYMENT_ENABLED=true`, respons DEMO tidak menampilkan tombol simulator atau memicu POST demo. |
| Keuangan | **Lulus.** Lima kasus provider memeriksa respons sandbox dan penolakan sumber yang salah. Tes lama mencari pemilih sandbox yang sudah tidak ada. |
| Laravel | **Lulus.** Pest dengan `phpunit.mysql.xml` dan schema `wisata_daerahku_test`, 1 tes rute publik, 3 assertion. Pint lulus. SQLite tidak dijalankan. |
| Visual | Layar gagal diperiksa pada render widget 393 × 852 dengan font pengganti. Pesan terlihat di atas ringkasan. Hitung mundur dari snapshot booking tetap dapat tampil. |
| Verifikasi langsung | Belum diuji pada perangkat dengan akun tiga peran atau transaksi penyedia nyata. Label penerbit desktop juga belum diverifikasi melalui paket terpasang. |

Kegagalan tersisa per file: `booking_payment_test.dart` (4), `operations_booking_test.dart` (2), `operations_queue_finance_regression_test.dart` (2), `ticket_contract_test.dart` (8), `user_booking_form_test.dart` (14).
