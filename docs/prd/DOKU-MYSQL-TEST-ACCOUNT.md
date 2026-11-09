# Akun MySQL khusus test concurrency (DOKU / Payment Foundation)

Status: prosedur. Dijalankan **oleh administrator MySQL** (pemilik server), bukan oleh agent. Jangan pernah memakai credential `root` atau credential `.env` aplikasi untuk `phpunit.mysql.xml`.

## Tujuan dan batas

`tests/Mysql/DokuPaymentConcurrencyTest.php` dan `PaymentFoundationConcurrencyTest.php` membuat database acak `pami_doku_<12 alnum>_test` / `pami_phase1b_<12 alnum>_test`, menjalankan migration di sana, lalu `DROP`. Akun test hanya boleh menyentuh dua pola itu. Tidak ada akses ke schema aplikasi, schema `mysql`, atau privilege global.

## 1. Buat akun (jalankan sebagai admin, ganti password)

```sql
-- Placeholder koneksi awal. Kosong, tidak berisi data. Ikut pola agar tercakup grant.
CREATE DATABASE IF NOT EXISTS `pami_doku_bootstrap_test`;

CREATE USER 'pami_doku_test'@'127.0.0.1' IDENTIFIED BY '<PASSWORD-ACAK-PANJANG>';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES
  ON `pami\_doku\_%\_test`.* TO 'pami_doku_test'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES
  ON `pami\_phase1b\_%\_test`.* TO 'pami_doku_test'@'127.0.0.1';
```

Jangan menambahkan `GRANT OPTION`, `*.*`, `ALL ON *.*`, atau grant pada `wisata_daerahku*` / schema aplikasi. Privilege yang dipakai migration saat ini hanya DDL/DML standar (tidak ada trigger, view, procedure).

## 2. Konfigurasi proses test (variabel environment proses, bukan `.env`)

```bash
export APP_KEY="base64:<kunci-sementara>" JWT_SECRET="<>=32 karakter sementara>"
export DB_HOST=127.0.0.1 DB_PORT=3306 \
       DB_DATABASE=pami_doku_bootstrap_test \
       DB_USERNAME=pami_doku_test DB_PASSWORD='<PASSWORD-ACAK-PANJANG>'
php vendor/bin/pest --configuration=phpunit.mysql.xml \
  tests/Mysql/PaymentFoundationConcurrencyTest.php tests/Mysql/DokuPaymentConcurrencyTest.php --colors=never
```

`phpunit.mysql.xml` tidak menimpa variabel yang sudah diset di proses. Simpan password hanya di sesi shell / secret manager lokal; jangan di repo, chat, atau `.env`.

## 3. Guard keselamatan (otomatis, fail-closed)

`tests/Mysql/DisposableMysqlGuard.php::assertDisposableMysqlAccount()` dipanggil sebelum `CREATE DATABASE` apa pun dan melempar exception (bukan skip) bila:

- username kosong, `root`, atau mengandung `admin`;
- database bootstrap tidak cocok `pami_(doku|phase1b)_<alnum>_test`;
- `SHOW GRANTS FOR CURRENT_USER()` memuat grant selain `USAGE ON *.*` dan DDL/DML pada dua pola disposable, atau memuat `WITH GRANT OPTION`;
- `information_schema.schemata` menampilkan schema lain selain `information_schema`, `performance_schema`, dan pola disposable.

Tambahan sebelumnya: helper menolak `DB_URL`, replica read/write, dan config ter-cache; worker menolak nama database di luar pola; setelah `DROP`, test memverifikasi schema sudah hilang.

## 4. Validasi sesudah run

```sql
SHOW GRANTS FOR 'pami_doku_test'@'127.0.0.1';
SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'pami\_%\_test';
```

Yang diharapkan tersisa hanya `pami_doku_bootstrap_test`. Schema acak sisa (mis. run terhenti) boleh di-drop manual oleh admin.

## 5. Pencabutan

```sql
DROP USER 'pami_doku_test'@'127.0.0.1';
DROP DATABASE IF EXISTS `pami_doku_bootstrap_test`;
```

## Catatan

- Test `rolls the additive payment migration down and up...` di `PaymentFoundationConcurrencyTest.php` gagal di HEAD `de3cbf8` bersih (bukan akibat Task 0–2): `migrate:rollback --step 1 --path=…create_payment_tables` menurunkan migration terbaru (`2026_11_08_034113_add_reconciliation_to_payment_attempts`), bukan tabel payment. Perlu scope terpisah.
- Untuk server Laragon pengguna, akun di atas **belum dibuat**; hasil checkpoint terdokumentasi di laporan Task 0–2 berasal dari instance `mysqld` 8.4.3 sementara dengan prosedur yang sama.
