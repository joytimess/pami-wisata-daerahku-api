# Multiple Destination Images — implementation handoff

Tanggal: 2 November 2026. Backend: `C:\laragon\www\pami-wisata-daerahku-api`. Flutter: `C:\laragon\www\pami-mobile\pami-wisata-daearahku`.

## Status

| Fase | Status |
|---|---|
| 0 — Audit/contract | Selesai; PRD, source kedua repo, DESIGN, storage/auth dan konfigurasi agent dibaca |
| 1 — Laravel | Implementasi dan pengujian lokal selesai |
| 2 — Editor Flutter | Implementasi, widget test dan smoke test Android dengan API nyata selesai |
| 3 — Viewer Flutter | Implementasi, gesture test dan smoke test Android selesai |
| 4 — QA/security | Pengujian terkait galeri lulus; suite Flutter penuh belum hijau dan matriks perangkat lengkap belum diverifikasi |

Definition of Done keseluruhan belum dinyatakan terpenuhi: enam kegagalan auth/V4 masih ada pada suite Flutter penuh, dan pemeriksaan seluruh ukuran/font/perangkat serta simulasi process crash/full-disk belum lengkap. Implementasi tidak melakukan commit, push, deploy atau perubahan database produksi. Perubahan workspace yang sudah ada dipertahankan.

Main thread menangani orkestrasi/integrasi. Agent `system_architect`, `backend_engineer`, `flutter_engineer`, `qa_engineer`, `security_auditor`, dan `master_orchestrator` benar-benar dijalankan. Konfigurasi ditemukan di `C:\Users\itsba\.codex\agents`; folder `.codex/agents` lokal tidak tersedia. Skill Ponytail, Laravel best practices, testing best practices, dan taste frontend digunakan. Skill taste yang tersedia bersifat umum untuk app/web, bukan skill khusus Flutter mobile; komponen mengikuti `docs/DESIGN.md` dan source V4.

## Perilaku dan kontrak

- Galeri 1–10 foto; JPEG/PNG/WebP, maksimal 5 MiB/file; validasi signature/MIME, ekstensi, corrupt image, maksimal 24 MP dan sisi 12.000 px. Request dibatasi 55 MiB, write diberi throttle.
- GD mengoreksi orientasi EXIF, membuang metadata, mempertahankan rasio dan transparansi, tanpa upscale. Varian sisi terpanjang maksimal 480/1280/2048 px; WebP dengan fallback PNG.
- `destination_images` memiliki GUID immutable dan unique `(destination_guid, sequence)`. Urutan pertama menjadi cover. Destinasi memiliki `gallery_version`.
- Create: `POST /api/v1/destinations`, multipart `images[]`, field destinasi existing dan UUID header `Idempotency-Key`.
- Update: `POST /api/v1/destinations/{guid}` dengan `_method=PATCH`, `gallery_version`, `gallery_manifest` JSON berurutan, `new_images[key]`, dan header yang sama. Metadata dan galeri disimpan dalam satu transaksi database.
- Manifest berisi `{image_guid: ...}` atau `{upload_key: ...}`. Foto existing yang tidak lagi muncul di manifest dihapus dari galeri setelah commit.
- Replay actor/key/payload yang sama mengembalikan snapshot yang sama; perubahan payload dengan key sama atau versi stale menghasilkan `409`. Row actor dan destinasi dikunci.
- GET menambah `images`, `gallery_version`, `cover_image_url`; `image_url` dan `has_image` tetap tersedia. Varian berada di `destinations/{destination}/images/{image}/{thumbnail|display|fullscreen}`. Media admin memakai prefix `admin/`, JWT melalui header.
- Admin GET menyertakan `last_gallery_operation_key` untuk rekonsiliasi hasil request yang tidak pasti. Draft/editor mempertahankan key yang sama saat retry; perubahan draft membuat operasi baru.
- Upload single-image legacy tetap diterima, tetapi mutation kini memerlukan idempotency key dan update memerlukan versi. Client lama yang menulis tanpa keduanya perlu diperbarui; kompatibilitas field baca dipertahankan.
- Kebijakan existing adalah shared catalog untuk Admin aktif role >=1. Tidak ditemukan schema assignment/owner destinasi; implementasi tidak mengarang assignment baru.
- Editor: multi-picker, grid 3 kolom, long-press reorder, cover/nomor, preview, batas jumlah, satu simpan, manual retry, dialog konflik, konfirmasi membuang draft.
- Viewer: carousel, counter/dots, fullscreen pada indeks aktif, thumbnail, pinch/double-tap zoom, pan dan guard swipe, sinkronisasi Back. Cache/prefetch menggunakan transport existing, immutable URL dan session-generation guard.
- Fasilitas tetap memakai alur existing yang terpisah; jaminan atomic save baru mencakup metadata destinasi dan galeri.

## Hasil pemeriksaan aktual

| Pemeriksaan | PASS | FAIL | SKIP | Catatan |
|---|---:|---:|---:|---|
| Baseline Laravel sebelum implementasi | 126 | 0 | 0 | 1.075 assertions |
| Laravel unit/feature final | 158 | 0 | 0 | 1.362 assertions; 32 tes feature galeri |
| Seluruh suite MySQL | 24 | 0 | 0 | 368 assertions; termasuk 3 race galeri / 32 assertions, PHP process/connection independen |
| Seluruh E2E HTTP/MySQL | 6 | 0 | 0 | 197 assertions; server PHP nyata, DB `_e2e` |
| Flutter galeri/editor/form terarah final | 15 | 0 | 0 | Termasuk gesture bertahap dan pinch dua pointer |
| Flutter API live final | 1 | 0 | 0 | AuthSession/BusinessRepository ke Laravel nyata, tanpa mock HTTP |
| Flutter suite penuh final | 345 | 6 | 2 | Log `artifacts/gallery_full_tests_final.log` |
| Flutter baseline pembanding | 14 | 6 | 0 | Empat file auth/V4 pada isolated HEAD `586871c`, enam kegagalan yang sama |

`flutter analyze`: 0 error, 3 warning, 114 info (exit nonzero); tidak ada temuan pada file galeri yang baru/diubah. Warning unused-field dan info existing tidak dibersihkan di luar scope. Pint dan `git diff --check` lulus. APK debug berhasil dibangun dan dipasang pada `emulator-5554`.

Enam kegagalan Flutter terdapat di:

- `auth_prd_redesign_test.dart`: scrolled header/status-bar inset.
- `v4_navigation_notifications_test.dart`: posisi badge pada bell.
- `v4_visual_test.dart`: root tab/back dan komponen compact.
- `v4_booking_success_navigation_test.dart`: icon-only tab dan bell badge.

Snapshot baseline dibuat oleh aktivitas workspace lain selama sesi dan sudah memuat implementasi galeri awal; pembandingan membuktikan enam kegagalan yang sama pada source auth/V4 yang tidak diubah oleh pekerjaan galeri, bukan baseline bersih sebelum seluruh sesi.

Smoke test Android memakai database `wisata_daerahku_e2e`, 1080×2400/density 420:

1. Login Admin, buka editor 3 foto, simpan melalui API nyata.
2. Long-press DOWN–hold–MOVE–UP memindahkan foto pertama ke terakhir; DB berubah `[A,B,C]` menjadi `[B,C,A]`, versi 4→5, sequence 1/2/3 dan cover B.
3. Android Photo Picker memilih dua asset test dalam satu selection; satu save menghasilkan 5 foto, versi 6, sequence 1..5.
4. Hero swipe 1→2, tap membuka indeks yang benar; fullscreen swipe dan thumbnail berpindah; Android Back mengembalikan indeks hero terakhir.
5. Perubahan remote lalu save stale memunculkan dialog konflik; memilih pertahankan draft tetap menampilkan 5/10 foto dan Coba Lagi.

Konflik swipe fullscreen yang hanya muncul pada emulator diperbaiki dengan navigasi single-pointer saat InteractiveViewer mengambil gesture; pinch/pan zoom tetap diprioritaskan. `adb draganddrop` awal tidak memberikan hold yang dapat diandalkan; DOWN–hold–MOVE–UP terpisah berhasil tanpa perubahan editor.

Bukti gambar/log berada di Flutter `artifacts/destination-gallery/`, `artifacts/gallery_targeted_tests_final.log`, `artifacts/gallery_android_gestures.log`, `artifacts/gallery_analyze_final.log`, dan `artifacts/gallery_baseline_tests.log`. Fixture gambar hitam kecil digunakan pada tes HTTP; multi-picker Android juga menggunakan asset Borobudur/Kuta existing. Bukti ini tidak mengkalibrasi kualitas foto high-resolution pada perangkat fisik.

## Security dan cleanup

Review menemukan malformed multipart `new_images` scalar dapat menghasilkan TypeError/500 pada after-validation hook. Hook kini berhenti saat validasi dasar gagal. Lima kasus regresi scalar/nested malformed menghasilkan 422 tanpa file/operasi/galeri tersimpan.

Pemeriksaan source dan tes mencakup role/status, media IDOR, GUID gambar lintas destinasi, whitelist varian, ownership manifest, spoof/corrupt/dimensi, stale version, replay serta reference-safe cleanup. Tidak ditemukan temuan high/critical dalam jalur yang direview; ini bukan klaim semua risiko keamanan telah diuji.

Filesystem tidak menjadi bagian transaksi MySQL. Derivative baru dibuat pada path immutable sebelum aktivasi; outbox staging mencatat cleanup jika proses terputus. Referensi dan metadata galeri berubah atomik di DB. Setelah commit, cleanup outbox dapat dicoba ulang melalui command terjadwal; file generated yang masih direferensikan dan file legacy tidak dihapus. Tes membuktikan rollback, cleanup gagal lalu retry, dan perlindungan shared reference. Kill-process/power loss/full-disk nyata belum disimulasikan.

## Migration/backfill dan pengujian lokal

Migration additive dan backfill sudah diverifikasi pada DB testing MySQL/SQLite terisolasi. Database aplikasi existing dan produksi belum dimigrasikan oleh pekerjaan ini. Backfill mengoptimasi foto lama sebagai sequence 1, dapat diulang tanpa duplikasi, melewati destinasi yang sudah mempunyai galeri, serta mempertahankan original. Flutter membaca fallback single-image versi nol saat backfill belum selesai; editor perlu foto galeri valid atau foto pengganti, tidak mengirim GUID sintetis legacy.

Untuk database lokal yang sudah dipastikan targetnya dan dibackup:

```powershell
php artisan migrate --no-interaction
php artisan destinations:backfill-images --no-interaction
php artisan destinations:backfill-images --no-interaction
php artisan destinations:cleanup-images --no-interaction
```

Periksa error per GUID, hitungan gambar, URL/eksistensi file dan jumlah record sebelum/sesudah rerun. Jangan memakai `migrate:fresh` pada database aplikasi. Rollback aplikasi harus mempertahankan tabel/kolom baru dan original; `migrate:rollback` akan menghapus metadata galeri dan bukan rollback tanpa kehilangan data. Backup database/storage dan forward fix lebih aman.

Aktifkan scheduler existing (`schedule:run` setiap menit) agar cleanup dijalankan. CLI/dev server yang diuji memiliki GD/WebP/EXIF, memory 512M, serta upload/post limit 2G. Limit PHP-FPM/Apache/Nginx/reverse proxy hosting, disk quota, codec dan timeout deployment belum diverifikasi.

Perintah tes yang digunakan:

```powershell
php artisan test --compact
php artisan test -c phpunit.mysql.xml --compact
php artisan test -c phpunit.e2e.xml --compact
vendor/bin/pint --dirty --format agent
```

MySQL memakai database dedicated `_test`/`_e2e` dan guard suffix. Jangan menjalankan dua suite feature yang memakai fake disk `local` dalam process terpisah bersamaan karena direktori fake storage dipakai bersama.

Untuk mengulang HTTP/Flutter live pada fixture disposable `_e2e`, jalankan server dengan env testing, mysql database `wisata_daerahku_e2e`, queue database, cache array, mail array:

```powershell
php -S 127.0.0.1:8099 -t public
php artisan test -c phpunit.e2e.xml tests/E2E/DestinationGalleryHttpTest.php --compact
```

Tes fixture tersebut melakukan fresh migration hanya pada DB `_e2e` dan menyediakan factory Admin `gallery-http@example.test` / `password`. Ini akun testing, bukan kredensial aplikasi existing. Di Flutter:

```powershell
flutter test
flutter analyze
flutter test test/destination_gallery_live_test.dart --dart-define=GALLERY_LIVE_TEST=true
adb reverse tcp:8099 tcp:8099
flutter run -d emulator-5554 --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://127.0.0.1:8099/api/v1
```

`adb.exe` tersedia di `C:\Users\itsba\AppData\Local\Android\Sdk\platform-tools` bila tidak ada di PATH. Jalankan fixture setelah suite E2E lainnya karena suite tersebut mereset database testing. Lakukan manual create 1/10 foto, mixed add/delete/reorder, network failure/manual retry, konflik dua editor, carousel/fullscreen, pinch/pan dan Back; lanjutkan matriks 320/390/430dp, font scaling, landscape serta perangkat fisik.

Contoh curl multipart berikut benar-benar dijalankan pada fixture lokal, menghasilkan HTTP 200, versi 8 dan 4 foto. `manifest.json` berisi image GUID existing dan satu `{ "upload_key": "curl-photo" }`; gunakan versi/key baru untuk operasi baru, key/payload sama saat retry:

```powershell
curl.exe -H "Authorization: Bearer $token" -H "Accept: application/json" `
  -H "Idempotency-Key: $operationKey" -F "_method=PATCH" `
  -F "gallery_version=7" -F "gallery_manifest=<manifest.json" `
  -F "new_images[curl-photo]=@assets/images/Candi_Borobudur.jpg" `
  http://127.0.0.1:8099/api/v1/destinations/94a58aee-f0fc-42d3-a546-c322b8d33fdd
```

## File aktual yang disentuh

Laravel dibuat:

- `database/migrations/2026_11_02_111333_create_destination_gallery_tables.php`
- `app/Models/DestinationImage.php`
- `app/Http/Requests/DestinationGalleryRequest.php`
- `app/Http/Resources/DestinationImageResource.php`
- `app/Services/DestinationGalleryService.php`
- `app/Services/DestinationImageProcessor.php`
- `app/Console/Commands/BackfillDestinationImages.php`
- `app/Console/Commands/CleanupDestinationImages.php`
- `tests/Feature/DestinationGalleryTest.php`
- `tests/Mysql/GalleryConcurrencyTest.php`
- `tests/Mysql/gallery_worker.php`
- `tests/E2E/DestinationGalleryHttpTest.php`
- `docs/multiple-destination-images-handoff.md`

Laravel diubah:

- `app/Http/Controllers/Api/V1/DestinationController.php`
- `app/Http/Resources/DestinationResource.php`
- `app/Models/Destination.php`
- `app/Policies/DestinationPolicy.php`
- `app/Services/CatalogService.php`
- `routes/api.php`
- `routes/console.php`
- `tests/Feature/AdminApiTest.php`
- `tests/E2E/EndToEndTest.php`

Flutter dibuat:

- `lib/data/gallery_draft.dart`
- `lib/widgets/admin_destination_gallery_editor.dart`
- `lib/widgets/destination_hero_gallery.dart`
- `test/destination_gallery_test.dart`
- `test/destination_gallery_editor_test.dart`
- `test/gallery_test_support.dart`
- `test/destination_gallery_live_test.dart`

Flutter diubah:

- `lib/data/business_repository.dart`
- `lib/data/app_data.dart`
- `lib/services/auth_session.dart`
- `lib/widgets/api_image.dart`
- `lib/screens/tambah_wisata_screen.dart`
- `lib/screens/detail_wisata_screen.dart`
- `test/admin_destination_form_test.dart`
- `test/business_integration_destination_test.dart`

Sebagian perubahan Flutter sudah masuk snapshot HEAD oleh aktivitas workspace lain selama sesi; daftar ini berdasarkan file yang benar-benar dikerjakan, bukan hanya dirty diff terakhir. PRD input milik user tidak diubah. Tidak ada dependency baru.
