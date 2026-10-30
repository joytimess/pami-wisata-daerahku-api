# Phase 4 & 5 — penyelesaian backend

Dokumen ini melengkapi `HANDOFF-BACKEND-WISATA-DAERAHKU.md`. Kontrak API lengkap ada di `docs/api_contract_phase4_5.md` pada repo Flutter.

## Perubahan perilaku

| Area | Sebelum | Sekarang |
| --- | --- | --- |
| Error 409 | Satu bentuk `{message}` | `{message, code, context?}` dengan kode terpisah (kuota tak tersedia, kapasitas kurang, jendela ditutup, terlalu dekat, konflik idempotensi, key kedaluwarsa/ditinggalkan, booking sudah diproses, batas persetujuan, tidak dapat dibatalkan, kategori dipakai). Kode ada di `App\Exceptions\BookingException`. |
| Ketersediaan | Tidak ada endpoint pengguna | `GET destinations/{guid}/availability` — `capacity`, `allocated`, `remaining`, `status`, jendela booking; hanya agregat. |
| Kuota admin | Hanya kapasitas total | Menambah `allocated` dan `remaining`; timestamp wajib beserta offset; jendela harus tutup sebelum hari kunjungan (zona waktu destinasi). |
| Pembatalan | Tidak ada | `POST bookings/{guid}/cancel` (pemilik, hanya `pending_approval`); kapasitas dilepas atomic satu kali. Booking terproses → 409 `booking_not_cancellable` (belum ada kontrak pembayaran/refund). |
| Idempotensi | Replay mengembalikan snapshot awal; kedaluwarsa tidak ditegakkan | Replay membaca status **terkini**; key melewati 7 hari ditolak (`idempotency_key_expired`), tidak dibuka kembali. Klaim key dibuat di statement autocommit terpisah (lihat "Temuan MySQL"). |
| Rekonsiliasi | Tidak ada | `GET booking-intents/{key}` (terikat pengguna+key) dan `POST booking-intents/{key}/abandon` (tombstone: create terlambat dengan key itu ditolak). |
| Admin booking | Daftar saja | Filter `status`/`search`/`per_page`, `GET admin/bookings/{guid}`. |
| Destinasi | Hanya aktif | `GET admin/destinations[/{guid}]` (termasuk nonaktif), reaktivasi lewat `PATCH is_active`, blokir aktivasi bila kategori nonaktif. |
| Kategori | Nonaktif tanpa pemeriksaan | `GET admin/categories`; nonaktif ditolak (409 `category_in_use`) selama destinasi aktif masih memakai. |
| Gambar | `image_path` (path storage) bocor di resource | Resource memuat `has_image` dan `image_url` relatif & berversi; `GET destinations/{guid}/image` (publik, aktif), `GET admin/destinations/{guid}/image`, `POST destinations/{guid}/image`. `image_path` tidak dikembalikan. |
| Akun admin | Create/update | `GET admin/accounts` (role 2); tidak boleh mengubah role/status sendiri. |
| `Idempotency-Key` | Karakter ASCII bebas | `[A-Za-z0-9._~-]`, ≤128 agar dapat direkonsiliasi lewat path. |

## Temuan MySQL (hanya terlihat di MySQL nyata)

`INSERT IGNORE` pada key idempotensi yang sudah ada mengambil shared lock; menaikkannya ke `SELECT … FOR UPDATE` dalam satu transaksi menimbulkan deadlock (1213) saat banyak retry bersamaan — 3 dari 8 permintaan identik gagal. Klaim key sekarang di-autocommit dahulu, baru transaksi mengunci barisnya. Baris `processing` yang tertinggal berarti tidak ada booking yang di-commit untuk key itu; payload baru boleh mengadopsinya (aman karena baris terkunci).

## Menjalankan pengujian

```
php artisan test                                 # SQLite, cepat
php artisan test -c phpunit.mysql.xml            # konkurensi, MySQL nyata, DB wisata_daerahku_test
# E2E HTTP: DB khusus *_e2e + server lokal
DB_DATABASE=wisata_daerahku_e2e php artisan migrate:fresh --force
DB_DATABASE=wisata_daerahku_e2e APP_ENV=local QUEUE_CONNECTION=database php -S 127.0.0.1:8099 -t public public/index.php
php artisan test -c phpunit.e2e.xml
```

Database `*_test` dan `*_e2e` harus dibuat manual, kosong, dan terpisah dari database dev. Suite Mysql/E2E menolak berjalan bila nama database tidak berakhiran `_test`/`_e2e` karena memakai `migrate:fresh`. Worker konkurensi (`tests/Mysql/worker.php`) menjalankan proses PHP terpisah yang dilepas bersamaan lewat barrier file.

## Batas yang belum selesai

- Pembayaran/refund/webhook, check-in, review: tidak ada kontrak; pembatalan hanya `pending_approval`.
- Cutoff kunjungan masih sementara (25 j 15 m create, 1 j 15 m approve).
- Record idempotensi tidak dibersihkan (tetap sebagai penanda, tanpa job pembersihan).
- Server `php -S` bersifat satu-thread: konkurensi dibuktikan di tingkat service lintas proses (suite Mysql), bukan lewat banyak koneksi HTTP simultan.
- Google Login admin ditolak 403 di `AuthService` (diuji dengan verifier Firebase yang di-mock); belum diuji dengan ID token Firebase nyata.
- FCM/SMTP nyata, worker queue dan scheduler produksi: belum diverifikasi.
