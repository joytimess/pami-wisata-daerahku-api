# DOKU Sandbox UAT — rencana persiapan (BNI VA + QRIS dinamis)

Status: **persiapan saja**. Tidak ada transaksi merchant, tidak ada credential merchant, tidak ada perubahan `.env`/gate yang dilakukan. Eksekusi memerlukan izin eksplisit terpisah dari pemilik proyek.

## Level kesiapan (jangan dicampur)

| Level | Arti | Status saat ini |
|---|---|---|
| `CODE_COMPLETE` | Kode + test lokal (SQLite, MySQL sekali pakai, Flutter widget test) lulus | Task 0–8 selesai, kecuali bagian PROVIDER_BLOCKED |
| `PROVIDER_BLOCKED` | Butuh bukti/kontrak DOKU yang belum ada; fail-closed | BNI terminal inquiry/unknown-create, QRIS signature+body notifikasi, query, cancel/refund, window expiry BNI & QRIS, refund otomatis |
| `SANDBOX_UAT_PASSED` | Skenario di bawah lulus di merchant Sandbox nyata dengan bukti tersimpan | **Belum dijalankan** |
| `PRODUCTION_READY` | Sandbox UAT lulus + persetujuan produksi (`DOKU_PRODUCTION_APPROVED`) + monitoring + rollback diuji | **Tidak diklaim** |

## 1. Preflight jaringan (sudah dijalankan, tanpa credential)

Permintaan GET polos ke root host, tanpa header autentikasi dan tanpa payload:

| Host | Hasil (VPN aktif) |
|---|---|
| `api-sandbox.doku.com` | HTTP 200, TLS valid |
| `sandbox.doku.com` | HTTP 302, TLS valid |
| `api.doku.com` | HTTP 200, TLS valid |

Artinya hanya konektivitas dan TLS dari mesin pengembang yang terbukti. Ini **bukan** bukti bahwa merchant aktif, bahwa channel BNI/QRIS diaktifkan, atau bahwa IP whitelist (bila disyaratkan DOKU) sudah benar. Ulangi preflight dari host yang akan menjalankan UAT (staging), bukan dari laptop.

## 2. Prasyarat (harus tersedia sebelum izin eksekusi diminta)

1. Izin tertulis pemilik proyek untuk Sandbox UAT (terpisah dari izin persiapan ini).
2. Credential Sandbox merchant diberikan lewat **variabel environment proses** pada host UAT. Jangan ke repo, chat, atau `.env` worktree: `DOKU_CLIENT_ID`, `DOKU_SECRET_KEY`, `DOKU_MERCHANT_PRIVATE_KEY_PATH` (+ passphrase), `DOKU_BNI_PARTNER_SERVICE_ID`, `DOKU_BNI_CUSTOMER_PREFIX`, `DOKU_QRIS_MERCHANT_ID`, `DOKU_QRIS_TERMINAL_ID`, `DOKU_QRIS_POSTAL_CODE`.
3. URL webhook publik HTTPS (`DOKU_WEBHOOK_URL`) yang menjangkau staging; localhost tidak bisa menerima notifikasi. URL didaftarkan di dashboard DOKU oleh pemilik merchant.
4. Database staging terpisah dari produksi dan dari data pengguna nyata; `DOKU_ENVIRONMENT=sandbox`, `DEMO_PAYMENTS_ENABLED=false`, `DOKU_PRODUCTION_APPROVED=false`.
5. Channel BNI VA dan QRIS sudah diaktifkan di merchant Sandbox (bukti: screenshot/konfirmasi DOKU). Hanya setelah itu `DOKU_VERIFIED_CHANNELS` diisi per channel, satu per satu, dan hanya selama sesi UAT.
6. SMTP nyata untuk uji email (bukan `log/array`), akun penerima UAT dengan `email_verified_at` terisi.
7. Penanggung jawab menjalankan UAT dan menyimpan bukti (lihat bagian 5).

## 3. Kontrak provider yang perlu dibuktikan (PROVIDER_BLOCKED → bukti)

Untuk tiap butir, simpan request/response mentah (redacted) dan referensi dokumentasi DOKU. Sampai bukti ada, kode tetap fail-closed dan tidak boleh "menebak".

| # | Bukti yang dibutuhkan | Dipakai oleh |
|---|---|---|
| E1 | Window `expiredDate` BNI VA yang diterima DOKU (min/max, zona waktu, perilaku di luar window) | `DokuClient::expiryWindowReason('va_bni')` |
| E2 | Window `validityPeriod` QRIS dinamis (min/max) | `expiryWindowReason('qris')` |
| E3 | Respons create-VA BNI saat timeout/duplikat (`unknown` create), idempotensi `trxId`/`virtualAccountNo` | pemulihan unknown-create |
| E4 | Status inquiry BNI (`/orders/v1.0/transfer-va/status`): nilai status terminal (paid, expired, cancelled), `latestTransactionStatus` | `DokuClient::status`, reconcile |
| E5 | Notifikasi BNI nyata: header (`CHANNEL-ID`, `X-TIMESTAMP`, `X-SIGNATURE`, `X-EXTERNAL-ID`), body, response sukses yang diharapkan DOKU, perilaku retry | verifikasi signature, idempotensi |
| E6 | QRIS: format notifikasi, string-to-sign/signature, field nominal & referensi, response yang diharapkan | webhook QRIS (saat ini tidak diimplementasi) |
| E7 | QRIS: query status, status terminal, cancel/refund endpoint & kontrak | rekonsiliasi QRIS, refund |
| E8 | Refund/reversal: apakah refund penuh via API tersedia untuk BNI VA/QRIS di merchant ini, bentuk event pembatalan | `BookingRefundService` (saat ini manual) |
| E9 | Rate limit, IP whitelist, dan SLA retry notifikasi DOKU | konfigurasi throttle, runbook |

## 4. Skenario UAT (dijalankan hanya setelah izin)

Setiap skenario: nominal kecil Sandbox, satu booking baru, kunci idempotensi baru. Hentikan sesi jika ada hasil tidak terduga (bagian 6).

| ID | Skenario | Hasil yang harus terbukti |
|---|---|---|
| U1 | Create BNI VA, bayar via simulator DOKU | attempt `pending` → `paid`, booking `confirmed`, invoice sama, nominal & waktu dari event terverifikasi |
| U2 | Notifikasi sama dikirim ulang (retry DOKU) | tidak ada capture ganda, response sama |
| U3 | Notifikasi dengan signature salah / timestamp usang / CHANNEL-ID salah | ditolak, tidak ada perubahan state, tidak ada kebocoran info |
| U4 | VA kedaluwarsa tanpa bayar | attempt `expired`, booking tetap konsisten; pembayaran terlambat → `needs_review`, bukan `confirmed` |
| U5 | Create timeout (simulasi jaringan) lalu retry dengan kunci sama | satu attempt, satu VA; status `unknown` dipulihkan lewat inquiry |
| U6 | Create QRIS, tampilkan QR di app, bayar via simulator | QR hanya milik pemilik, hilang saat kedaluwarsa; capture terverifikasi |
| U7 | Kwitansi + email | satu email `payment_receipt` berlabel SANDBOX/TEST ONLY ke alamat terverifikasi; PDF kwitansi sesuai; tanpa VA/QR penuh |
| U8 | Booking H-6 vs H-4 | H-6: entitlement tanpa QR; H-4: QR segera + satu email `ticket_issued`; pada tengah malam lokal H-5 scheduler menerbitkan sekali |
| U9 | Refund: request → approve admin → proses manual di dashboard DOKU → event refund terverifikasi | status `manual_action_required` sampai event terverifikasi, lalu `refunded`; QR dicabut; kwitansi `adjusted` |
| U10 | Refund pada tiket yang sudah dipakai | ditolak / `needs_review` |
| U11 | Kegagalan SMTP lalu pulih | pembayaran tetap paid; delivery sama di-retry; audit percobaan terisi |
| U12 | Android: checkout BNI/QRIS, ekspor QR, resume setelah app dibunuh | status benar dari server, tidak ada pembayaran ganda |

## 5. Penyimpanan bukti

Folder bukti di luar repo; redaksi: `X-SIGNATURE`, secret, nomor VA penuh, payload QR, email penerima. Simpan: waktu UTC, `payment_events.guid`, hash payload, hasil query DB sebelum/sesudah. Jangan menyimpan private key atau secret.

## 5a. Verifikasi tambahan untuk keputusan produk (tidak dijalankan; butuh persetujuan Sandbox terpisah)

- **Attempt melewati jendela instruksi:** buat satu attempt Sandbox, jangan bayar, tunggu lewat `instruction_expires_at`; verifikasi `verification_pending=true`, `payments:reconcile-doku` meng-eskalasi tepat sekali, inquiry DOKU yang sebenarnya (apakah mengembalikan status terminal untuk VA/QRIS kedaluwarsa = bukti E1–E9), dan attempt tidak pernah dibuat ulang otomatis.
- **Refund provider setelah settlement manual:** hanya bila DOKU menyediakan refund Sandbox; verifikasi `duplicate_refund_detected` tanpa mengubah ledger.
- **Tautan e-ticket:** di perangkat nyata, verifikasi App Link `/tickets/{guid}` (assetlinks + sidik jari), cold/warm start, akun non-pemilik mendapat 403 tanpa kebocoran, dan email tidak berisi QR.

## 6. Kriteria berhenti (stop conditions)

Hentikan segera dan laporkan bila: ada transaksi di luar skenario, environment ≠ sandbox, nominal tidak cocok, signature divalidasi tetapi state berubah tidak sesuai, notifikasi memicu error 5xx berulang, atau ada data pengguna nyata di database. Kembalikan `DOKU_VERIFIED_CHANNELS` ke kosong setelah sesi.

## 7. Kriteria keluar

`SANDBOX_UAT_PASSED` hanya bila U1–U12 lulus dengan bukti, E1–E9 yang relevan terdokumentasi dan `expiryWindowReason` diperbarui lewat perubahan kode + test terpisah (bukan lewat config). `PRODUCTION_READY` tidak ditetapkan oleh dokumen ini.
