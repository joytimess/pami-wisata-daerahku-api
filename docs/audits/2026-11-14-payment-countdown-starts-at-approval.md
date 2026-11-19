# Audit: Countdown Pembayaran Dimulai Saat Approval (Seharusnya Saat Memilih Metode)

- **Tanggal:** 2026-11-14
- **Lingkup:** Backend `pami-wisata-daerahku-api` (+ dampak ke Flutter `pami-wisata-daearahku`)
- **Status:** Tahap 0�4 + notifikasi approval (7.4) + status tiket (7.2) + snapshot zona waktu di `decide()` **sudah diimplementasi** (belum di-commit). Tahap 5�10 belum dikerjakan; menunggu persetujuan.
- **Isi:** Masalah A (countdown dimulai saat approval) — bagian 1–5. Masalah B (lewat 15 menit tanpa bayar
  malah masuk review admin, seharusnya ulang dari awal) — bagian 6.

---

## 1. Ringkasan Masalah

**Perilaku saat ini:** begitu Admin menyetujui booking berbayar, status booking menjadi `awaiting_payment`
dan backend **langsung** mengisi `bookings.payment_expires_at = waktu_approval + 15 menit` (Midtrans) atau
`+ 60 menit` (DEMO). Field ini dikirim ke aplikasi dan dipakai sebagai countdown "Sisa waktu pembayaran".
Akibatnya customer yang belum membuka aplikasi (misal approval terjadi malam hari) sudah kehilangan
seluruh/ sebagian waktu bayarnya sebelum sempat memilih metode, lalu booking otomatis jatuh ke
`payment_verification` → `expired` dan kuota dilepas.

**Perilaku yang diharapkan:** approval hanya membuka akses pembayaran. **Countdown 15 menit baru
dimulai ketika customer memilih metode pembayaran** (VA BCA, VA BNI, atau QRIS), yaitu saat attempt
pembayaran pertama dibuat ke Midtrans (`POST bookings/{booking}/payment-attempts`).

---

## 2. Temuan (Root Cause & Titik-Titik yang Salah)

### 2.1 Akar masalah — deadline ditetapkan di approval

`app/Services/BookingService.php:264-272` (method `decide()`):

```php
$status = ! $approve ? 'rejected' : ($booking->total_amount == 0 ? 'confirmed' : 'awaiting_payment');
$updates = ['status' => $status, 'updated_at' => $now];
if ($status === 'awaiting_payment') {
    // One absolute deadline from the very instant recorded in the status history below; every attempt inherits it. Midtrans
    // approvals get 15 minutes; DEMO keeps its historical one hour window.
    $window = BookingRules::paymentWindowSeconds(app(PaymentGateway::class)->activeProvider());
    $updates['payment_expires_at'] = $now->copy()->addSeconds($window);
    $updates['notification_grace_expires_at'] = $now->copy()->addSeconds($window + BookingRules::PAYMENT_GRACE_SECONDS);
}
```

Ini adalah sumber bug. `payment_expires_at` dan `notification_grace_expires_at` dihitung dari `$now`
saat Admin approve.

### 2.2 Aturan & dokumentasi yang mengunci perilaku salah

| Lokasi | Isi |
|---|---|
| `app/Support/BookingRules.php:23-24` | Komentar: *"one absolute payment deadline ... counted from the authoritative approval time"* |
| `docs/prd/PRD-Wisata-Daerahku-Backend.md:122` | "deadline pembayaran 1 jam sejak approval" |
| `docs/superpowers/plans/2026-11-10-midtrans-coreapi-migration.md:23, 972` | "Satu deadline absolut 15 menit sejak approval" |

Artinya bug ini **by design** dari spesifikasi lama, bukan kesalahan implementasi tunggal — spesifikasi juga
harus direvisi.

### 2.3 Scheduler meng-expire berdasarkan deadline approval

`app/Services/BookingService.php:285-336` (`expireDue()` / `expireOne()`), dijalankan tiap menit
(`routes/console.php:11`):

- `awaiting_payment` + `payment_expires_at <= now` → `payment_verification`
- `payment_verification` + `notification_grace_expires_at <= now` → `payment_review`
- `PaymentSettlementService::expireIfTerminalUnpaid()` (`app/Services/PaymentSettlementService.php:153-186`)
  → booking tanpa attempt (`$intent` null) langsung `expired` dengan reason `payment_timeout`.

Jadi booking yang **belum pernah memilih metode** pun dimatikan 15 menit setelah approval.

### 2.4 Midtrans `custom_expiry` memakai waktu approval sebagai `order_time`

- `app/Services/MidtransPaymentService.php:166` → `$booking->payment_approved_at = $this->approvedAt($booking);`
- `app/Services/MidtransPaymentService.php:730-736` → `approvedAt()` diambil dari history `new_status = awaiting_payment`.
- `app/Services/Midtrans/MidtransClient.php:66-78` (`charge()`):

```php
$minutes = intdiv($expiresAt->getTimestamp() - $approvedAt->getTimestamp(), 60);
'custom_expiry' => ['order_time' => $approvedAt->...->format('Y-m-d H:i:s O'),
    'expiry_duration' => $minutes, 'unit' => 'minute'],
```

VA/QRIS yang diterbitkan Midtrans ikut kedaluwarsa relatif terhadap waktu approval, bukan waktu customer
memilih metode.

### 2.5 Instruksi pembayaran mewarisi deadline approval

`app/Services/PaymentService.php:175-182` (`instructionExpiresAt()`):

```php
$deadline = $booking->payment_expires_at ?? $startedAt;
$window = $this->gateway->activeProvider() === 'midtrans' ? CarbonImmutable::parse($deadline, 'UTC') : ...;
```

`instruction_expires_at` setiap attempt = `payment_expires_at` dari approval. Jika customer memilih metode
di menit ke-14, VA hanya hidup ±1 menit (atau ditolak oleh `expiryWindowReason()` minimum 30 detik).

### 2.6 Guard yang **akan rusak** bila `payment_expires_at` dibiarkan NULL setelah approval

Ini bukan bug saat ini, tetapi wajib diubah saat perbaikan karena semuanya menganggap NULL = "jendela tertutup":

| Lokasi | Kondisi saat ini |
|---|---|
| `app/Services/PaymentService.php:120` (`bookingContract`) | `! $booking->payment_expires_at \|\| now()->gte(...)` → `payment_window_closed` |
| `app/Services/PaymentService.php:148` (`invoiceContract`) | `$eligible` mensyaratkan `payment_expires_at` terisi → `can_create = false` |
| `app/Services/PaymentService.php:177` (`instructionExpiresAt`) | `?? $startedAt` → jendela 0 detik |
| `app/Services/MidtransPaymentService.php:744` (`assertPayable`) | NULL → `payment_window_closed` |
| `app/Services/MidtransPaymentService.php:788` (`response`) | `CarbonImmutable::parse($booking->payment_expires_at)` |
| `app/Services/DemoPaymentService.php:87, 110, 135, 225` | Sama, untuk provider DEMO |
| `app/Services/PaymentSettlementService.php:163` | NULL → tidak pernah expire (aman, tapi perlu aturan baru, lihat 3.3) |

### 2.7 Dampak di Flutter

| Lokasi | Perilaku |
|---|---|
| `lib/data/business_repository.dart:141-142` | `paymentExpiresAt` dibaca dari `payment_expires_at` booking |
| `lib/screens/user/bukti_pendaftaran_screen.dart:1000-1008` | Menampilkan `PaymentDeadline` segera setelah status `awaiting_payment` (countdown tampil sejak approval) |
| `lib/screens/user/booking_payment_screen.dart:482-492, 1608-1609` | Countdown ditampilkan **bersama chooser metode** (`if (p.chooserVisible) ...[_countdown(), _chooser()]`) |
| `lib/data/booking_payment_state.dart:90-104` | `windowOpen` mensyaratkan `paymentDeadline != null`; bila NULL → `retryOpen`/polling mati |

---

## 3. Rancangan Perbaikan

### 3.1 Aturan bisnis baru

1. **Approve (berbayar)** → status `awaiting_payment`, `payment_expires_at = NULL`,
   `notification_grace_expires_at = NULL`. Tidak ada countdown.
2. **Customer memilih metode (attempt pertama dibuat)** → di transaksi yang sama (booking ter-lock):
   - `payment_started_at = now` (kolom baru),
   - `payment_expires_at = min(now + PAYMENT_WINDOW_SECONDS, visitStart)`,
   - `notification_grace_expires_at = payment_expires_at + PAYMENT_GRACE_SECONDS`,
   - `payment_intents.expires_at = payment_expires_at`.
3. **Ganti metode / retry di dalam 15 menit yang sama** → tetap memakai deadline absolut dari pemilihan
   pertama di siklus itu (tidak diperpanjang). *(Keputusan D2.)*
4. **15 menit habis tanpa bayar** → siklus pembayaran diulang dari awal (bukan review admin). Lihat bagian 6.
5. **Booking yang tidak pernah membayar** tetap dibatasi oleh batas pemilihan metode
   **24 jam sejak approval** (dibatasi `visitStart`) agar kuota tidak tertahan selamanya. *(Keputusan D1.)*

### 3.2 Perubahan per file (backend)

**Migration baru** (jangan ubah migration historis):
```php
$table->timestamp('payment_started_at')->nullable()->after('approval_expires_at');
$table->timestamp('payment_selection_expires_at')->nullable()->after('payment_started_at');
$table->index(['status', 'payment_selection_expires_at']);
```

**`app/Support/BookingRules.php`**
- Perbarui komentar `PAYMENT_WINDOW_SECONDS`: dihitung dari **pemilihan metode pertama**.
- Tambah konstanta batas pemilihan metode, misal `PAYMENT_SELECTION_WINDOW_SECONDS` (nilai: lihat D1).
- Tambah helper `paymentDeadlineFrom(CarbonInterface $startedAt, CarbonImmutable $visitStart, string $provider)`.

**`app/Services/BookingService.php::decide()` (L266-272)**
- Hapus pengisian `payment_expires_at` dan `notification_grace_expires_at`.
- Isi `payment_selection_expires_at = min(now + PAYMENT_SELECTION_WINDOW_SECONDS, visitStart)`.

**`app/Services/BookingService.php::expireDue()/expireOne()` (L285-336)**
- Tambah rule: `awaiting_payment` + `payment_started_at IS NULL` + `payment_selection_expires_at <= now`
  → `expired` (reason `payment_method_not_selected`), lepas kuota, kirim notifikasi `expired`.
- Rule `awaiting_payment` + `payment_expires_at <= now` tetap (hanya berlaku setelah metode dipilih;
  `NULL <= now` di SQL bernilai false sehingga aman).

**`app/Services/PaymentSettlementService.php::expireIfTerminalUnpaid()` (L153-186)**
- Tidak perlu diubah logikanya (NULL → return false), tetapi pastikan booking tanpa intent ditangani
  oleh rule baru di `expireOne()`.

**`app/Services/PaymentService.php`**
- `bookingContract()` L120: ubah menjadi
  - `payment_expires_at` NULL → cek `payment_selection_expires_at` (lewat → `payment_selection_closed`);
  - `payment_expires_at` terisi dan lewat → `payment_window_closed`.
- `invoiceContract()` L148: `$eligible` tidak lagi mensyaratkan `payment_expires_at` terisi; gunakan
  "deadline efektif" (`payment_expires_at ?? payment_selection_expires_at`).
- `instructionExpiresAt()` L175-182: bila `payment_expires_at` NULL, hitung
  `BookingRules::paymentDeadlineFrom($startedAt, ...)` alih-alih `?? $startedAt`.
- Field `expires_at` di response: `null` sebelum metode dipilih (agar app tidak menampilkan countdown).
  Pertimbangkan field tambahan `selection_expires_at` untuk info non-countdown.

**`app/Services/MidtransPaymentService.php::initiate()` (L113-168)**
- Sebelum membuat intent/attempt pertama, bila `payment_started_at` NULL: set `payment_started_at`,
  `payment_expires_at`, `notification_grace_expires_at` pada booking (sudah dalam `lockedBooking`),
  refresh `$booking` lalu hitung `$expiresAt`.
- `intent.expires_at` diisi dari nilai baru tersebut (L142) → `assertSnapshot()` L762 tetap konsisten.
- `assertPayable()` L738-752: NULL `payment_expires_at` bukan lagi alasan tutup; cek
  `payment_selection_expires_at` untuk attempt pertama.
- L166: ganti `payment_approved_at = approvedAt()` → `payment_started_at` (atau `started_at` attempt pertama).
- `response()` L788: null-safe.

**`app/Services/Midtrans/MidtransClient.php::charge()` (L66-78)**
- `order_time` dan perhitungan `expiry_duration` memakai `payment_started_at`, bukan waktu approval.
  Untuk attempt ke-2 dst (ganti metode), lebih aman `order_time = attempt.started_at` dan
  `expiry_duration = floor((payment_expires_at - attempt.started_at)/60)` agar Midtrans tidak menerima
  `order_time` jauh di masa lalu.

**`app/Services/DemoPaymentService.php`** (L87, 110, 135, 225)
- Terapkan pola yang sama (set deadline saat attempt DEMO pertama).

**`app/Services/BookingNotificationService.php:14`**
- Opsional: teks notifikasi `approved` → "Booking disetujui. Silakan pilih metode pembayaran sebelum {selection deadline}."

**`app/Http/Resources/BookingResource.php:43-45`**
- Tambah `payment_started_at` dan `payment_selection_expires_at`.

**Dokumentasi**
- Revisi `docs/prd/PRD-Wisata-Daerahku-Backend.md:122` dan
  `docs/superpowers/plans/2026-11-10-midtrans-coreapi-migration.md:23`.

### 3.3 Perubahan di Flutter

- `bukti_pendaftaran_screen.dart:1000-1008`: tampilkan `PaymentDeadline` hanya bila
  `paymentExpiresAt != null`; jika NULL tampilkan **teks biasa** "Pilih metode pembayaran sebelum {tanggal jam}"
  (dari `payment_selection_expires_at`) memakai komponen teks/typography yang **sudah ada** di project —
  tanpa countdown, tanpa komponen baru. *(Keputusan D3.)*
- `booking_payment_screen.dart:1609`: jangan render `_countdown()` di samping chooser bila deadline belum
  dimulai (`snapshot.bookingExpiresAt == null`).
- `booking_payment_state.dart:90-104`: `windowOpen` harus true saat `paymentDeadline == null` **dan**
  `can_create == true` (fase pilih metode), agar chooser/retry tetap berfungsi.
- `business_repository.dart`: parse `payment_selection_expires_at` dan `payment_started_at`.

### 3.4 Data existing (saat deploy)

- Booking `awaiting_payment` **tanpa** `payment_intents`: set `payment_expires_at = NULL`,
  `notification_grace_expires_at = NULL`, `payment_selection_expires_at = <kebijakan D1>` agar mereka
  mendapat countdown penuh saat memilih metode.
- Booking yang **sudah punya** intent/attempt: jangan diubah (deadline sudah terikat ke Midtrans);
  isi `payment_started_at` dari `MIN(payment_attempts.started_at)`.
- Booking yang sudah `expired` karena bug ini: tidak dihidupkan otomatis (kuota mungkin sudah dipakai).

---

## 4. Keputusan (sudah dikonfirmasi user, 2026-11-14)

| # | Pertanyaan | Keputusan |
|---|---|---|
| D1 | Berapa lama customer boleh menunda pembayaran setelah approval? | **24 jam sejak approval**, dibatasi `visitStart`. Lewat batas ini tanpa pembayaran → booking `expired`, kuota dilepas |
| D2 | Ganti metode (VA BCA → QRIS) di dalam 15 menit mereset countdown? | **Tidak.** Deadline siklus tetap dari pemilihan metode pertama di siklus itu |
| D3 | Batas pemilihan metode (D1) ditampilkan di app? | **Teks biasa** memakai komponen yang sudah tersedia di project; bukan countdown |
| D4 | Lewat 15 menit tanpa bayar? | **Ulang dari awal** (pilih metode lagi, countdown 15 menit baru), bukan ke review admin. Lihat bagian 6 |

---

## 5. Rencana Uji (Regression)

Test yang saat ini mengandalkan deadline-dari-approval dan perlu disesuaikan:
`tests/Feature/MidtransExpiryTest.php`, `MidtransPaymentTest.php`, `MidtransNativePaymentTest.php`,
`MidtransClientTest.php`, `MidtransReconciliationTest.php`, `PaymentFoundationTest.php`,
`PaymentHardeningTest.php`, `PaymentRecoveryTest.php`, `DemoPaymentTest.php`, `BookingRefundTest.php`,
`HistoricalPaymentCompatibilityTest.php`, `tests/Feature/Support/MidtransFixtures.php`,
`tests/Mysql/*PaymentConcurrencyTest.php`, `tests/Mysql/StaleSnapshotConcurrencyTest.php`,
`tests/E2E/MidtransLiveSandboxUatTest.php`.

Kasus uji baru minimum:

1. Approve booking berbayar → `payment_expires_at` NULL, `payment_selection_expires_at` terisi, `can_pay = true`, `expires_at` response = null.
2. Jalankan `bookings:expire` 20 menit setelah approval tanpa memilih metode → booking **tetap** `awaiting_payment`.
3. Pilih VA BCA 2 jam setelah approval → `payment_expires_at = started_at + 15 menit`; `custom_expiry.order_time` = waktu pemilihan.
4. Lewat 15 menit setelah memilih metode tanpa bayar → alur lama (`payment_verification` → `expired`/`payment_review`) tetap berjalan.
5. Ganti metode di menit ke-10 → deadline tidak berubah (sesuai D2).
6. Lewat `payment_selection_expires_at` tanpa memilih metode → `expired`, kuota dilepas, notifikasi terkirim.
7. Deadline tidak pernah melewati `visitStart`.
8. Concurrency: dua request `payment-attempts` paralel pada booking yang belum dimulai → hanya satu yang menetapkan `payment_started_at`.
9. Idempotency replay attempt pertama tidak menggeser deadline.

Kasus uji tambahan untuk Masalah B ada di bagian 6.6.

---

## 6. Masalah B — Lewat 15 Menit Tanpa Bayar Malah Masuk Review Admin

### 6.1 Perilaku saat ini

Customer memilih VA/QRIS, tidak membayar, countdown 15 menit habis. Yang terjadi:

```
awaiting_payment ──(payment_expires_at lewat)──▶ payment_verification
                 ──(+15 menit grace)──────────▶ payment_review  ──▶ notifikasi "payment_review" ke Admin/Superadmin
                                                                 ──▶ muncul di antrean recovery admin
```

Booking tertahan di `payment_review`, kuota tetap terkunci, customer tidak bisa bayar lagi, dan admin
harus menangani kasus yang sebenarnya bukan anomali (customer hanya tidak membayar).

**Perilaku yang diharapkan:** jika 15 menit habis tanpa pembayaran, siklus pembayaran **diulang dari awal**:
booking tetap `awaiting_payment`, countdown hilang, customer bisa memilih metode lagi dan mendapat
15 menit baru, selama masih di dalam batas 24 jam (D1).

### 6.2 Temuan / root cause

**(a) Scheduler memaksa transisi ke verification/review saat deadline lewat**
`app/Services/BookingService.php:315-324` (`expireOne()`):

```php
if (in_array($booking->status, ['awaiting_payment', 'payment_verification', 'payment_review'], true) && ...
    && app(PaymentSettlementService::class)->expireIfTerminalUnpaid($guid)) {
    return;
}
$next = match (true) {
    ...
    $booking->status === 'awaiting_payment' && $now->gte($booking->payment_expires_at) => 'payment_verification',
    $booking->status === 'payment_verification' && $now->gte($booking->notification_grace_expires_at) => 'payment_review',
```

`expireIfTerminalUnpaid()` hanya berhasil bila **semua** attempt sudah `failed`/`expired` secara lokal.
Tepat saat deadline, attempt biasanya **masih `pending`** di database lokal karena:
- notifikasi `expire` dari Midtrans datang terlambat (scheduler expiry Midtrans bisa lag), dan
- reconcile terjadwal memakai backoff hingga 300 detik (`MidtransPaymentService.php:486-488`), lalu
  900 detik setelah overdue.

Karena itu `expireOne()` jatuh ke `match` dan memindahkan booking ke `payment_verification`, lalu
15 menit kemudian ke `payment_review` + notifikasi (`BookingService.php:331-333`).

**(b) Jalur "berhasil" pun salah: booking di-`expired`, bukan diulang**
`app/Services/PaymentSettlementService.php:153-186` (`expireIfTerminalUnpaid()`): ketika semua attempt
terbukti expired, booking langsung `expired` (reason `payment_timeout`), intent `expired`, kuota dilepas.
Tidak ada jalur kembali ke pemilihan metode. Dipanggil juga dari `closeAfterOutcome()` setelah setiap
evidence (`PaymentSettlementService.php:141`, `MidtransPaymentService.php:261`).

**(c) State machine intent tidak mendukung siklus ulang**
`app/Support/PaymentStates.php:13`: intent `expired` hanya boleh ke `needs_review`. `payment_intents.booking_guid`
unik (1 intent per booking), dan `intent.expires_at` disamakan dengan `booking.payment_expires_at` di banyak
pengecekan snapshot:
- `MidtransPaymentService.php:762` (`assertSnapshot`)
- `PaymentSettlementService.php:90` (`apply`)
- `PaymentEligibility.php:27-30` (`i.expires_at = b.payment_expires_at`, `verified_paid_at < b.payment_expires_at`)
- `DemoPaymentService.php:97, 149`

Jika deadline booking berubah untuk siklus baru tanpa menyesuaikan ini, semua evidence akan dianggap
`snapshot_mismatch` → `review()` → **masuk review admin lagi**.

**(d) Eskalasi attempt overdue**
`MidtransPaymentService.php:657-669` (`escalateOverdue()`) menandai setiap attempt yang masih open setelah
`instruction_expires_at` sebagai `escalated` → tampil di antrean recovery admin, walau penyebabnya hanya
lag notifikasi `expire`.

### 6.3 Rancangan perbaikan

**Prinsip:** "tidak bayar" ≠ "anomali pembayaran". Review admin (`payment_review` / `needs_review` /
recovery queue) hanya untuk anomali nyata: uang masuk setelah deadline, nominal/identitas tidak cocok,
konflik `transaction_id`, refund/chargeback, atau status Midtrans tetap tidak diketahui setelah jendela
recovery panjang.

**Alur baru:**

```
approve ─▶ awaiting_payment (tanpa countdown, batas 24 jam)
   │
   └─ pilih metode ─▶ siklus #n: countdown 15 menit (ganti metode tidak mereset)
          │
          ├─ bayar ───────────────────────────────▶ confirmed
          │
          └─ 15 menit habis ─▶ cek status Midtrans segera
                 ├─ expire/failed/cancel ─▶ RESET: awaiting_payment, countdown hilang,
                 │                           customer pilih metode lagi (siklus #n+1)
                 ├─ settlement (bayar di detik akhir) ─▶ confirmed
                 └─ masih pending (lag Midtrans) ─▶ tetap awaiting_payment, UI "memverifikasi",
                                                    tombol pilih metode dikunci, cek ulang tiap ±1 menit

batas 24 jam lewat & tidak ada siklus aktif ─▶ expired, kuota dilepas
```

**Perubahan backend:**

1. **Kolom baru** (migration baru): `bookings.payment_cycle` (unsignedSmallInteger, default 0) dan
   `payment_attempts.payment_cycle`. Setiap pemilihan metode di siklus baru menaikkan `payment_cycle`.

2. **`BookingService::expireOne()` (L306-336)** — untuk booking dengan intent Midtrans:
   - Hapus transisi `awaiting_payment → payment_verification → payment_review` berbasis waktu.
   - Saat `payment_expires_at` lewat: panggil `resetCycleIfTerminalUnpaid()` (baru, lihat 3). Jika attempt
     masih open → picu lookup Midtrans (`reconcile` mode `scheduled`, set `next_reconcile_at = now`) dan
     biarkan booking tetap `awaiting_payment`.
   - Rule DOKU/DEMO legacy boleh dipertahankan bila masih dibutuhkan untuk data historis.
   - Rule batas 24 jam (bagian 3.2) yang meng-`expired` booking.

3. **`PaymentSettlementService`** — tambah `resetCycleIfTerminalUnpaid(string $bookingGuid)`:
   - Lock order sama (quota → booking → intent → attempts).
   - Syarat: status `awaiting_payment`, `payment_expires_at <= now`, `applied_attempt_guid` NULL, intent
     `requires_payment|processing`, semua attempt siklus ini `failed|expired`.
   - Aksi: booking `payment_started_at = NULL`, `payment_expires_at = NULL`,
     `notification_grace_expires_at = NULL`; intent `status = requires_payment`, `expires_at = NULL`;
     catat `booking_status_histories` (old/new `awaiting_payment`, reason `payment_cycle_reset`);
     notifikasi user tipe baru `payment_cycle_expired` ("Waktu pembayaran habis. Silakan pilih metode
     pembayaran lagi sebelum {batas 24 jam}").
   - Jika batas 24 jam sudah lewat → jalankan perilaku lama `expireIfTerminalUnpaid()` (booking `expired`).
   - `closeAfterOutcome()` (L189-196) memanggil reset ini lebih dulu, baru expire.

4. **Snapshot per siklus, bukan per booking** — ganti perbandingan `intent.expires_at === booking.payment_expires_at`
   dengan pengecekan per attempt:
   - `PaymentSettlementService.php:90` → cocokkan `attempt.payment_cycle` dan `attempt.instruction_expires_at`;
     capture valid bila `provider_occurred_at < attempt.instruction_expires_at`.
   - `PaymentEligibility.php:27-30` → `verified_paid_at < a.instruction_expires_at` (hapus ketergantungan ke
     `b.payment_expires_at` / `i.expires_at`).
   - `MidtransPaymentService::assertSnapshot()` L762 → hapus perbandingan `expires_at`; saat memulai siklus
     baru set `intent.expires_at` = deadline siklus baru.
   - `DemoPaymentService.php:97, 149` → pola yang sama.
   - `PaymentStates.php` → tidak perlu transisi baru karena intent kembali ke `requires_payment` dari
     `processing` (sudah diizinkan); intent tidak pernah ditandai `expired` selama batas 24 jam masih berlaku.

5. **Pembayaran terlambat dari siklus lama** (VA lama dibayar setelah reset — seharusnya ditolak Midtrans
   karena `custom_expiry`): tetap ke review admin seperti sekarang (`review()` → `needs_review`). Ini
   anomali nyata yang memang butuh admin/refund.

6. **`escalateOverdue()` (`MidtransPaymentService.php:657`)** — hanya eskalasi attempt yang masih open
   setelah tenggang wajar (mis. `instruction_expires_at + 30 menit`), bukan tepat saat overdue. Attempt yang
   lag beberapa menit tidak boleh muncul di antrean admin.

7. **Percepat resolusi saat deadline** — `scheduleNextLookup()` (`MidtransPaymentService.php:464-489`):
   untuk attempt yang baru melewati `instruction_expires_at`, jadwalkan lookup segera (±60 detik) beberapa
   kali sebelum masuk backoff 900 detik, agar reset terjadi dalam 1–2 menit.

8. **`PaymentService::invoiceContract()` L145-158** — `can_create` true setelah reset (intent
   `requires_payment`, tidak ada attempt open). `can_switch` hanya relevan di dalam siklus aktif.

9. **Notifikasi** — `BookingNotificationService.php`: tambah tipe `payment_cycle_expired`; jangan kirim
   `payment_review` untuk kasus timeout biasa.

### 6.4 Perubahan Flutter

- `booking_payment_state.dart`: setelah countdown habis (`onCountdownElapsed`, L616) tetap polling; ketika
  snapshot kembali dengan `expires_at == null` dan `can_create == true` → tampilkan chooser lagi (fase
  `choose`), bukan fase `expired`/review.
- Selama attempt lama masih `pending` setelah deadline → tampilkan status "Memverifikasi pembayaran…"
  (teks biasa, komponen yang ada), chooser dikunci.
- `bukti_pendaftaran_screen.dart`: booking `awaiting_payment` tanpa deadline → teks "Pilih metode
  pembayaran sebelum {batas 24 jam}" (D3).
- Pastikan status `payment_review` tidak lagi muncul untuk kasus timeout.

### 6.5 Data existing (saat deploy)

Booking Midtrans yang saat ini `payment_verification` / `payment_review` **karena timeout biasa**:
1. Jalankan lookup Midtrans untuk semua attempt open-nya.
2. Semua attempt `failed|expired`, tidak ada capture, intent bukan `needs_review`:
   - masih dalam 24 jam sejak approval & sebelum `visitStart` → reset ke `awaiting_payment` (siklus baru);
   - di luar itu → `expired`, kuota dilepas.
3. Ada capture / evidence konflik / intent `needs_review` → biarkan di review admin (anomali nyata).

Lakukan via command artisan sekali jalan dengan mode `--dry-run` lebih dulu.

### 6.6 Kasus uji tambahan

1. Pilih VA BCA, tidak bayar, Midtrans kirim `expire` tepat waktu → booking tetap `awaiting_payment`,
   `payment_expires_at` NULL, `can_create = true`, history `payment_cycle_reset`, notifikasi `payment_cycle_expired`.
2. Deadline lewat tetapi notifikasi `expire` belum datang (attempt `pending`) → booking **tidak** pindah ke
   `payment_verification`/`payment_review`; setelah lookup mengembalikan `expire` → reset.
3. Setelah reset, pilih QRIS → `payment_cycle = 2`, countdown 15 menit baru dari waktu pemilihan.
4. Ganti metode di dalam siklus yang sama → deadline siklus tidak berubah (D2).
5. Settlement yang `transaction_time`-nya sebelum deadline tetapi datang setelah deadline → `confirmed`.
6. Settlement dari attempt siklus lama setelah reset → `needs_review` (anomali), bukan konfirmasi ganda.
7. Reset saat batas 24 jam sudah lewat → booking `expired`, kuota dilepas.
8. `escalateOverdue()` tidak mengeskalasi attempt yang hanya terlambat beberapa menit.
9. Concurrency: reset siklus vs settlement paralel → hanya satu yang menang (lock order quota → booking → intent → attempts).
10. Booking DOKU/DEMO historis tidak terpengaruh.

---

## 7. Temuan Tambahan (dikonfirmasi user, 2026-11-14)

### 7.1 Customer tidak bisa membatalkan booking / membatalkan metode pembayaran yang salah pilih

**Temuan**

- `app/Services/BookingService.php:208-233` (`cancel()`): pembatalan hanya diizinkan saat `pending_approval`
  (`booking_not_cancellable` untuk status lain). Setelah approval, customer terjebak: kuota tetap tertahan
  sampai batas 24 jam (D1) habis.
- Tidak ada cara membatalkan metode yang sudah dipilih. Contoh: customer salah pencet **VA BCA**, ingin ganti
  ke **QRIS**. Saat ini ganti metode (`can_switch`, `app/Services/PaymentService.php:157-158`) hanya terbuka
  bila attempt sebelumnya sudah `failed`/`expired` — jadi customer harus menunggu VA BCA kedaluwarsa.
- `app/Services/Midtrans/MidtransClient.php` tidak punya pemanggilan Midtrans **Cancel API**
  (`POST /v2/{order_id}/cancel`). Mapper sudah siap: status Midtrans `cancel` → attempt `failed`
  (`app/Services/Midtrans/MidtransStatusMapper.php:65`).

**Perbaikan A — Batalkan metode pembayaran (ganti metode)**

1. Endpoint baru: `POST bookings/{booking}/payment-attempts/{attempt}/cancel` (owner, throttle, Idempotency-Key).
2. Syarat: booking `awaiting_payment`, attempt milik booking, status attempt `pending` (instruksi VA/QR sudah
   terbit), intent bukan `needs_review`.
3. `MidtransClient::cancel(object $attempt)` → `POST /v2/{merchant_reference}/cancel`.
4. **Jangan percaya respons cancel saja.** Setelah cancel, jalankan lookup status (`lookup(..., 'refresh')`)
   dan terapkan evidence seperti biasa:
   - `cancel` → attempt `failed` (reason `provider_cancel`) → intent kembali `requires_payment` →
     `can_create`/`can_switch` = true → customer pilih metode lain.
   - `settlement` (customer ternyata sudah bayar di detik yang sama) → booking `confirmed`; cancel ditolak
     dengan pesan "Pembayaran sudah diterima".
   - Midtrans menolak cancel / respons tidak jelas → attempt tetap open, chooser tetap terkunci, tampilkan
     "Sedang memverifikasi…" (tidak ada attempt baru sampai status jelas — mencegah bayar dobel).
5. **Countdown tidak di-reset (D2):** metode baru memakai `payment_expires_at` siklus yang sama.
6. Audit: catat di `payment_events` (event `cancel_requested`) + aktor customer.
7. Flutter: tombol "Ganti metode pembayaran" di layar instruksi VA/QRIS (`booking_payment_screen.dart`,
   `_instruction()`), konfirmasi memakai **confirm bottom sheet yang sudah ada**, lalu kembali ke chooser
   dengan countdown tetap berjalan.

**Perbaikan B — Batalkan booking setelah approval**

1. `cancel()` mengizinkan status `awaiting_payment` (selain `pending_approval`).
2. Jika ada attempt `pending`: jalankan Perbaikan A dulu (Cancel API + lookup). Booking hanya dibatalkan
   setelah **semua** attempt terbukti `failed`/`expired`.
   - Jika ternyata `settlement` → booking `confirmed`, pembatalan ditolak (`booking_already_paid`), customer
     diarahkan ke alur refund.
   - Jika status attempt belum jelas → tolak sementara (`payment_verification_pending`), bukan dibatalkan.
3. Aksi: booking `cancelled`, intent `cancelled` (transisi `requires_payment|processing → cancelled` sudah
   diizinkan di `app/Support/PaymentStates.php`), kuota otomatis lepas (status `cancelled` tidak termasuk
   `BookingRules::HELD`), history + notifikasi `cancelled` ke admin.
4. Lock order sama dengan settlement (quota → booking → intent → attempts) agar cancel vs pembayaran
   paralel tidak bisa sama-sama menang.
5. Status `payment_verification`/`payment_review`/`confirmed` **tidak** bisa dibatalkan lewat endpoint ini
   (`confirmed` → alur refund).
6. Flutter: tombol "Batalkan booking" juga tampil untuk `awaiting_payment` (konfirmasi via bottom sheet yang ada).

**Uji minimum:** cancel VA BCA lalu pilih QRIS di siklus sama (deadline tetap); cancel saat Midtrans sudah
settlement (→ confirmed); cancel booking tanpa attempt; cancel booking dengan attempt pending; cancel vs
webhook settlement paralel (MySQL concurrency test); replay Idempotency-Key.

### 7.2 Status tiket salah saat booking masih menunggu approval

**Temuan** — `app/Services/TicketService.php:168-172` (`state()`):

```php
if (! in_array($payment, ['paid', 'free'], true)) {
    return 'awaiting_payment';
}
if ($booking->status !== 'confirmed') {
    return 'awaiting_approval';
}
```

Pembayaran dicek sebelum approval. Booking berbayar yang masih `pending_approval` (belum boleh bayar)
ditampilkan `awaiting_payment`.

**Perbaikan** — urutan dibalik mengikuti flow (approval → bayar):

```php
if ($booking->status === 'pending_approval') {
    return 'awaiting_approval';
}
if (! in_array($payment, ['paid', 'free'], true)) {
    return 'awaiting_payment';
}
if ($booking->status !== 'confirmed') {
    return 'awaiting_payment';   // awaiting_payment / payment_verification: belum lunas terverifikasi
}
```

Cek juga label yang sama di `BookingDocumentService` / Flutter (`ticket_state`) agar konsisten.

### 7.3 Alur refund tidak mengirim notifikasi

**Temuan** — `app/Services/BookingRefundService.php` sama sekali tidak memanggil `BookingNotificationService`.
`BookingNotificationService::record()` (`app/Services/BookingNotificationService.php:12-21`) juga belum punya
tipe refund. Akibatnya:
- Admin tidak tahu ada permintaan refund baru (bisa terlewat).
- Customer tidak tahu refund disetujui, ditolak, atau dananya sudah kembali.

**Perbaikan** — tambah tipe notifikasi dan panggil di transisi yang sudah ada (di dalam transaksi yang sama,
dedupe key `booking:{guid}:refund:{refund_guid}:{event}` agar retry tidak dobel):

| Event | Penerima | Dipanggil di | Teks usulan |
|---|---|---|---|
| `refund_requested` | Admin destinasi + Superadmin | `request()` setelah insert | "Permintaan refund baru untuk booking {nomor}." |
| `refund_approved` | Customer | `decide()` saat disetujui | "Permintaan refund Anda disetujui dan sedang diproses." |
| `refund_rejected` | Customer | `decide()` saat ditolak | "Permintaan refund Anda ditolak. Alasan: {alasan}." |
| `refunded` | Customer | `recordVerifiedAdjustment()` & `settleManually()` | "Dana refund sebesar {nominal} telah dikembalikan." |
| `refund_manual_action` | Admin/Superadmin | saat status menjadi `manual_action_required` | "Refund booking {nomor} perlu diproses manual." |

Catatan: `BookingNotificationService::record()` perlu menerima parameter tambahan (refund guid, alasan,
nominal) atau dibuat method baru `recordRefund()`.

### 7.4 Notifikasi approval tidak menyuruh customer membayar

**Temuan** — `app/Services/BookingNotificationService.php:14`: `'approved' => 'Booking Anda telah disetujui.'`
untuk semua booking, berbayar maupun gratis.

**Perbaikan** (wajib, bukan opsional):
- Booking **berbayar** (`awaiting_payment`): tipe `approved_awaiting_payment` →
  "Booking disetujui. Silakan pilih metode pembayaran sebelum {payment_selection_expires_at, waktu lokal destinasi}."
- Booking **gratis** (`confirmed`): tetap `approved` → "Booking Anda telah disetujui. Tiket dapat dilihat di aplikasi."
- Siklus pembayaran direset (bagian 6): `payment_cycle_expired` →
  "Waktu pembayaran habis. Silakan pilih metode pembayaran lagi sebelum {batas 24 jam}."
- Format waktu memakai zona waktu snapshot destinasi (lihat 7.5 setelah dikonfirmasi).

### 7.5 Zona waktu tidak konsisten antar pengecekan

**Konteks**

- Setiap destinasi punya `destinations.timezone` (mis. `Asia/Jakarta` WIB, `Asia/Makassar` WITA,
  `Asia/Jayapura` WIT) yang **bisa diubah** superadmin (`app/Http/Requests/CatalogRequest.php:66`).
- Saat booking dibuat, nilainya disalin ke `bookings.destination_timezone_snapshot`
  (`app/Services/BookingService.php:95`).
- Semua deadline dihitung dari `BookingRules::visitStart()` = 00:00 waktu lokal tanggal kunjungan, disimpan
  dalam UTC. Patokan ini dipakai untuk: batas approve (75 menit sebelum), batas bayar, jendela QR H-5,
  dan tiket kedaluwarsa.

**Temuan**

Sebagian besar jalur sudah memakai snapshot (`TicketService::booking()` L67-68,
`PaymentService::bookingContract()` L121, `invoiceContract()` L149, `instructionExpiresAt()` L181,
`BookingService::format()` L392). Yang **masih membaca zona waktu live**:

| Lokasi | Dampak |
|---|---|
| `app/Services/BookingService.php:258-261` (`decide()`) | Cek "kunjungan terlalu dekat untuk di-approve" memakai zona waktu destinasi saat ini |
| `app/Services/BookingService.php:371` (`query()`) | Kolom `destination_timezone` dari live; aman selama `format()` menimpanya dengan snapshot, tapi rawan dipakai langsung |
| `app/Services/PaymentService.php:21` (`ownerBooking()`) | Sama: `d.timezone as destination_timezone`; aman hanya karena pemanggil memakai `snapshot ?? ...` |

Contoh: booking dibuat saat destinasi tercatat WIB, lalu dikoreksi ke WITA. `decide()` menganggap kunjungan
mulai 19 Nov 16:00 UTC, sedangkan pembayaran/tiket menganggap 19 Nov 17:00 UTC. Satu booking punya dua
"jam mulai kunjungan".

**Keputusan (dikonfirmasi user, 2026-11-14)**

1. **Snapshot booking adalah satu-satunya sumber zona waktu** untuk booking yang sudah dibuat.
2. Perubahan zona waktu destinasi **hanya berlaku untuk booking baru**.
3. **Tidak ada koreksi otomatis** untuk booking aktif bila zona waktu destinasi ternyata salah input;
   superadmin diberi daftar booking aktif terdampak untuk ditangani manual (deadline yang sudah dikirim
   ke Midtrans tidak bisa diubah).

**Perbaikan**

1. Helper tunggal, mis. `BookingRules::bookingTimezone(object $booking): string` → `destination_timezone_snapshot`,
   fallback ke live **hanya** untuk data lama yang belum punya snapshot. Semua pemanggil `visitStart()` untuk
   booking existing memakai helper ini.
2. `BookingService::decide()` L258: ganti query `destinations.timezone` dengan snapshot booking.
3. `query()` L371 dan `ownerBooking()` L21: alias kolom live diganti nama (mis. `destination_timezone_live`)
   agar tidak bisa terpakai tanpa sengaja.
4. Migration/command sekali jalan: isi `destination_timezone_snapshot` yang NULL dari `destinations.timezone`
   (dengan `--dry-run`), lalu pertimbangkan kolom dibuat NOT NULL.
5. Form edit destinasi (superadmin): bila `timezone` berubah, response menyertakan jumlah booking aktif
   (`pending_approval`, `awaiting_payment`, `payment_verification`, `payment_review`, `confirmed` dengan
   tanggal kunjungan ≥ hari ini) dan Flutter menampilkan peringatan teks biasa: "Perubahan zona waktu hanya
   berlaku untuk booking baru. {X} booking aktif tetap memakai zona waktu lama."
6. Endpoint/daftar superadmin: booking aktif yang `destination_timezone_snapshot` ≠ `destinations.timezone`
   (read-only, untuk penanganan manual).
7. Tampilan waktu di notifikasi & app (mis. "bayar sebelum 15 Nov 14:00 WITA") memakai zona waktu snapshot
   destinasi, bukan zona waktu HP, dan selalu menyertakan label WIB/WITA/WIT.

**Uji minimum:** ubah zona waktu destinasi setelah booking dibuat → approve, pembayaran, QR H-5, dan
kedaluwarsa tiket tetap memakai snapshot; booking baru memakai zona waktu baru; booking lama tanpa snapshot
ter-backfill; daftar booking terdampak muncul untuk superadmin.

---

## 8. Langkah Selanjutnya (urutan implementasi yang disarankan)

Urutan dibuat agar setiap tahap bisa diuji dan dirilis sendiri, dan tahap fondasi (kolom & aturan waktu)
selesai lebih dulu sebelum flow pembayaran diubah.

| Tahap | Isi | Bagian | Alasan urutan |
|---|---|---|---|
| 0 | Revisi spesifikasi: `docs/prd/PRD-Wisata-Daerahku-Backend.md:122`, `docs/superpowers/plans/2026-11-10-midtrans-coreapi-migration.md` | 2.2, 4 | Kontrak bisnis disepakati sebelum kode |
| 1 | Zona waktu: helper snapshot, `decide()`, backfill snapshot, peringatan form & daftar terdampak | 7.5 | Fondasi semua deadline; risiko rendah |
| 2 | Status tiket (`TicketService::state()`) | 7.2 | Perubahan kecil, independen |
| 3 | Migration kolom baru: `payment_started_at`, `payment_selection_expires_at`, `payment_cycle` (bookings & attempts) | 3.2, 6.3 | Dibutuhkan tahap 4–6 |
| 4 | Countdown mulai saat pilih metode + batas 24 jam | 3, 4 | Inti Masalah A |
| 5 | Siklus ulang setelah 15 menit (tanpa review admin), snapshot per siklus, eskalasi & reconcile lebih cepat | 6 | Bergantung tahap 3–4 |
| 6 | Cancel metode (Midtrans Cancel API) & cancel booking `awaiting_payment` | 7.1 | Bergantung siklus & `can_switch` tahap 5 |
| 7 | Notifikasi: approval berbayar, siklus habis, refund | 7.3, 7.4 | Teks bergantung field & zona waktu tahap 1, 4, 5 |
| 8 | Flutter: countdown kondisional, teks batas 24 jam, chooser setelah reset, tombol ganti metode & batalkan, peringatan zona waktu | 3.3, 6.4, 7.1, 7.5 | Setelah kontrak API backend stabil |
| 9 | Data existing: backfill booking `awaiting_payment` tanpa intent, booking yang tertahan di `payment_verification`/`payment_review` karena timeout (command `--dry-run` dulu) | 3.4, 6.5 | Dijalankan saat deploy, setelah kode tahap 1–7 rilis |
| 10 | Regression: suite Feature + MySQL concurrency + Midtrans sandbox UAT (`tests/E2E/MidtransLiveSandboxUatTest.php`) | 5, 6.6, 7.x | Gate sebelum production |

**Catatan rilis**

- Backend tahap 3–7 sebaiknya dirilis **bersamaan dengan** update Flutter tahap 8, karena kontrak
  `expires_at` berubah (bisa `null`) dan app versi lama mengartikan `null` sebagai "jendela tertutup"
  (`lib/data/booking_payment_state.dart:90-104`). Alternatif: tambah field baru dan pertahankan perilaku
  lama sampai app versi baru tersebar.
- Jalankan `bookings:expire` dan `payments:reconcile-midtrans` di staging selama minimal satu siklus penuh
  (approval → pilih metode → biarkan habis → reset → bayar) sebelum production.
- Belum ada kode yang diubah; dokumen ini adalah dasar implementasi.

---

## 10. Status Implementasi (diperbarui 2026-11-14, belum di-commit)

| Tahap | Isi | Status |
|---|---|---|
| 0–4 | Countdown mulai saat memilih metode (kolom baru, `startPaymentWindow`, `order_time` dari attempt) | Selesai, teruji SQLite + MySQL sekali pakai |
| 5 | Siklus tak terbayar di-reset (bukan review) selama jendela pilih metode terbuka | Selesai, teruji |
| 6 | Batalkan metode / booking `awaiting_payment` (`POST …/payment-attempts/{attempt}/cancel`) | Selesai di backend; **lihat temuan Cancel API di bawah** |
| 7 | Notifikasi refund, `timezone_impact`, daftar drift superadmin, backfill snapshot zona | Selesai, teruji |
| 8 | Flutter: countdown setelah pilih metode, ganti metode, zona waktu destinasi, peringatan form, layar drift | Selesai; tes terarah lulus, 14 overflow layout lama sudah diperbaiki |
| 9 | `payments:recover-stuck [--dry-run]` untuk booking Midtrans yang diparkir di `payment_verification`/`payment_review` oleh timeout lama | Selesai, teruji (`StuckPaymentRecoveryTest`) |
| 10 | UAT Sandbox Midtrans | **Sebagian** — lihat di bawah |

### 10.1 Temuan UAT Sandbox (2026-11-14)

- Charge VA BCA ke Sandbox sungguhan berhasil; sebelum memilih metode `payment_started_at` dan `payment_expires_at` NULL, setelah memilih deadline tepat 15 menit.
- **Cancel API ditolak Midtrans Sandbox dengan HTTP 403 `Access denied`** untuk order VA BCA, begitu pula `expire`, baik lewat aplikasi maupun curl langsung dengan server key akun ini. Aplikasi gagal-tertutup: attempt tetap `pending`, pelanggan menerima 409, tidak ada perubahan uang. Artinya "Ganti metode pembayaran" belum bisa dipakai pada akun Sandbox ini sampai akses Cancel/Expire diaktifkan di Midtrans, atau keputusan produk lain diambil (mis. mengizinkan attempt baru sementara yang lama dibiarkan kedaluwarsa sendiri).
- Simulator pembayaran Sandbox (`simulator.sandbox.midtrans.com`) tidak terjangkau dari lingkungan ini (timeout), sehingga alur *bayar → settlement → tiket* tidak diuji ulang pada kode baru.
- Webhook asli Midtrans tetap **belum terverifikasi** (tidak ada tunnel publik); QRIS bayar dan refund QRIS juga belum.
- **Reset siklus pada Sandbox sungguhan: lulus.** Setelah tenggat 15 menit lewat dan Midtrans menutup order, satu pass `expireDue` tidak mengubah booking (tetap `awaiting_payment`). Setelah pengecekan status live, attempt menjadi `expired` (event `verified/applied`), intent kembali `requires_payment`, dan booking `awaiting_payment` tanpa countdown (`payment_started_at` dan `payment_expires_at` NULL). Riwayat `payment_cycle_reset`, notifikasi `payment_cycle_expired`, kuota tetap tertahan (1 sebelum dan sesudah), tanpa masuk `payment_review`. Pass kedua tidak mengubah apa pun.
- **Keputusan (2026-11-14): "Ganti metode pembayaran" di-skip sementara.** Diagnosis terarah: 403 `Access denied` datang dari gateway Midtrans (`istio-envoy`) pada path Cancel/Expire, juga untuk order yang tidak ada, sementara Status berhasil dan kunci salah menghasilkan 401. Request curl independen identik dengan request Laravel, jadi ini pembatasan akun/merchant di sisi Midtrans, bukan bug aplikasi. Di Flutter, tombol diganti teks "Penggantian metode pembayaran harus menunggu 15 menit" (flag `kPaymentSwitchEnabled = false` di `booking_payment_screen.dart`); kode cancel tetap ada dan endpoint backend gagal-tertutup. Aktifkan kembali setelah Midtrans mengaktifkan akses Cancel/Expire, lalu ulang UAT `cycle`.
