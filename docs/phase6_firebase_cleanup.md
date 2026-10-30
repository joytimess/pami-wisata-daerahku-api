# Phase 6 — Firebase cleanup

Kontrak, inventaris historis, audit dependency, hasil pemeriksaan lintas stack,
dan laporan agent tersedia pada checkout Flutter:
`C:/laragon/www/pami-mobile/pami-wisata-daearahku/docs/phase6_firebase_cleanup.md`.

Backend menambah `check_ins` dan `reviews` melalui migration
`2026_10_30_020000_create_visit_review_tables.php`. Tidak mengubah data Firebase,
alur pembayaran, token, atau kuota booking. Jalankan migrasi sebelum memakai
Flutter versi Phase 6 melalui prosedur deployment normal.

Pemeriksaan lokal:

```powershell
php artisan test
php artisan test -c phpunit.mysql.xml
php artisan test -c phpunit.e2e.xml
vendor/bin/pint --dirty
git diff --check
```

Suite MySQL memakai database `*_test`; suite HTTP memakai `*_e2e` dan server
lokal port 8099. Keduanya menjalankan `migrate:fresh` dan wajib terisolasi.
Jangan arahkan ke database pengembangan, staging, atau produksi.

Untuk pengujian repository Flutter melalui HTTP nyata, setelah suite E2E dan
server terhubung ke database `*_e2e` yang sama:

```powershell
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='wisata_daerahku_e2e'
$fixture = php tests/E2E/phase6_flutter_fixture.php
if ($LASTEXITCODE -ne 0) { throw 'Fixture failed' }
[IO.File]::WriteAllText("$env:TEMP/phase6-fixture.json", ($fixture -join "`n"), [Text.UTF8Encoding]::new($false))
# Di checkout Flutter, dengan server Laravel tetap berjalan:
$env:PHASE6_FIXTURE="$env:TEMP/phase6-fixture.json"
$env:PHASE6_BASE_URL='http://127.0.0.1:8099/api/v1'
flutter test --no-pub test/phase6_live_api_test.dart
```

Fixture membuat akun/destinasi/booking khusus pengujian dan memindahkan tanggal
kuota fixture ke hari ini sesudah approval. Tidak menyediakan jalan pintas
check-in atau pembayaran dalam API. Hapus variabel `PHASE6_FIXTURE` sesudah
pengujian supaya suite default tetap melewati pengujian live yang opt-in ini.
