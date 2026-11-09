# Bukti expiry window BNI VA dan QRIS, serta Sandbox UAT terkendali

Status: prosedur. Gate `DokuClient::expiryWindowReason()` **fail-closed** untuk `va_bni` dan `qris` (`provider_expiry_window_unverified`) dan **tetap demikian** sampai bukti di bawah lengkap, ditinjau, dan disetujui. Jangan menghapus, mengecualikan, atau menimpa gate itu agar checkout terlihat berfungsi. Dokumen ini bukan izin aktivasi, transaksi Production, atau panggilan provider.

## Apa yang dikunci gate ini

Backend menghitung `instruction_expires_at = min(started_at + 30 menit, booking.payment_expires_at, awal hari kunjungan)`. Nilai itu dikirim ke provider: `expiredDate` (BNI VA, ISO-8601) di `DokuClient::create`, dan `validityPeriod` (QRIS, `Asia/Jakarta`) di `DokuQrisAdapter::create`. Respons provider hanya boleh memperpendek; perpanjangan atau expiry tak valid menjadikan attempt `unknown`. Yang belum terbukti adalah **batas minimum/maksimum window yang diterima merchant**, perilaku saat window lebih pendek dari minimum, dan nilai status terminal expired. Kode tidak boleh menebak angka minimum.

## Bukti yang harus dikumpulkan (per channel dan per environment)

| # | Bukti | Sumber yang sah | Dipakai untuk |
| --- | --- | --- | --- |
| 1 | Minimum dan maksimum `expiredDate` (BNI VA) / `validityPeriod` (QRIS) yang diterima untuk merchant ini | Dokumentasi resmi DOKU + konfirmasi tertulis DOKU/merchant support (nomor tiket) | Isi `expiryWindowReason()` dengan aturan terverifikasi |
| 2 | Respons provider saat window di bawah minimum dan di atas maksimum (kode, pesan, apakah VA/QR tetap dibuat) | Satu panggilan Sandbox terkendali per kasus (lihat bawah) | Memastikan "tolak sebelum HTTP" cocok dengan perilaku provider |
| 3 | Apakah `expiredDate`/`validityPeriod` dibulatkan atau dipotong provider, dan format yang dikembalikan | Respons Sandbox yang disanitasi | Aturan "provider hanya boleh memperpendek" |
| 4 | Status terminal saat expired (inquiry dan notifikasi) dan timestamp kejadian | Dokumentasi + fixture bertanda tangan dari Sandbox | Task 3/4 (recovery terminal); jangan salin status e-wallet |
| 5 | Zona waktu/offset yang dipakai provider untuk window | Respons Sandbox | Menghindari selisih jam di batas tengah malam kunjungan |

Matriks yang ada menandai ini `BLOCKED_EXTERIOR` (`DOKU-CHANNEL-MATRIX.md`: expiry value for status, QRIS status values, terminal VA inquiry). Perbarui matriks hanya dengan bukti nyata.

## Cara memperoleh bukti

1. Minta ke DOKU (kanal merchant support) secara tertulis: batas minimum/maksimum expiry untuk BNI VA fixed bill (DGPC) dan QRIS dinamis pada Client ID Sandbox dan Production, serta daftar status terminal expired/cancelled. Simpan jawaban sebagai bukti (tanpa credential).
2. Bandingkan dengan dokumentasi resmi pada `docs/prd/DOKU-RUNBOOK.md` §Sumber protokol. Dokumentasi saja tidak cukup untuk membuka gate karena tidak membuktikan entitlement merchant.
3. Setelah jawaban tertulis ada, siapkan perubahan terpisah (di luar Task 0–2) yang mengganti hardcode `expiryWindowReason()` menjadi aturan yang bersumber dari konfigurasi terverifikasi, dengan test RED→GREEN untuk: window di bawah minimum (tanpa HTTP), tepat di batas, di atas maksimum, dan tidak ada perubahan perilaku untuk channel lain.

## Sandbox UAT terkendali (hanya setelah poin 1–3 disetujui)

Prasyarat: persetujuan tertulis pemilik produk; merchant **Sandbox** DOKU aktif untuk channel yang diuji; credential Sandbox disimpan di secret store, tidak di repo/chat; `DOKU_ENVIRONMENT=sandbox`; `DEMO_PAYMENTS_ENABLED=false`; database **non-production** dengan data uji; allowlist akun uji; kill switch issuance siap dimatikan.

Langkah, satu channel pada satu waktu (BNI dan QRIS tidak saling mewakili):

1. Aktifkan hanya channel yang diuji pada konfigurasi Sandbox, untuk akun uji yang di-allowlist. Production tetap nonaktif.
2. Buat booking uji dan satu attempt dengan window **valid** (≤ 30 menit). Catat `attempt_guid`, referensi provider tersanitasi, dan `expires_at`.
3. Satu kasus negatif per batas (di bawah minimum / di atas maksimum) hanya jika bukti #1 menyatakan batasnya, untuk memastikan perilaku provider sama dengan gate lokal. Tidak ada percobaan acak.
4. Bayar melalui simulator/alat Sandbox resmi DOKU, lalu verifikasi: callback bertanda tangan atau inquiry terverifikasi → booking `confirmed` → ledger sekali → idempotensi pada callback duplikat.
5. Uji expiry: biarkan satu attempt kedaluwarsa; pastikan tidak ada create ulang otomatis, `can_create` mengikuti kontrak, dan pembayaran terlambat masuk review.
6. Simpan bukti: nama environment, referensi attempt/event tersanitasi, waktu kejadian, hasil worker/scheduler. Jangan simpan signature, token, nomor VA/QR penuh, atau PII.

Larangan: tidak ada transaksi Production, tidak ada panggilan provider dari test otomatis, tidak mengisi fixture dengan respons karangan, dan tidak menandai `SANDBOX_UAT_PASSED` dari dashboard ACTIVE, flag konfigurasi, atau callback palsu.

## Definisi selesai

Gate boleh dilonggarkan untuk satu channel hanya bila bukti #1–#5 channel itu lengkap, aturan baru lolos test lokal dan checkpoint MySQL, Sandbox UAT channel itu lulus dengan bukti tersimpan, dan pemilik produk menyetujui secara eksplisit. Production memerlukan keputusan terpisah.
