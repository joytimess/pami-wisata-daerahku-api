# PRODUCT REQUIREMENTS DOCUMENT (PRD)
## Wisata Daerahku — DOKU Payment, Invoice, Email Receipt & E-Ticket H-5

| Atribut | Nilai |
|---|---|
| Versi | **3.0 — Product-approved baseline, implementation gated by source audit** |
| Tanggal | **8 November 2026** (Asia/Jakarta) |
| Produk | PAMI — Wisata Daerahku |
| Client | Flutter (native payment pages) |
| Backend | Laravel REST API + MySQL, queue & scheduler existing |
| Payment provider | DOKU Direct API / BI-SNAP |
| Rilis pertama | **BNI Virtual Account (DGPC, fixed bill) + QRIS dinamis** |
| Future-ready, bukan rilis pertama | BCA, BRI, Mandiri VA via adapter terpisah jika aktif dan diuji |
| Explicitly OUT | DANA Direct, ShopeePay Direct, OVO Direct, hosted DOKU Checkout |
| Sumber kebenaran | Backend Laravel dan hasil pembayaran terverifikasi DOKU |
| Status dokumen | **PRD siap untuk audit implementasi dan pembuatan execution plan; bukan tanda code/UAT/go-live selesai** |

> **Instruksi Codex:** Baca `AGENTS.md`, konfigurasi agent, skill lokal, *kedua working tree*, migrasi, routes, models, services, widgets, tests dan scheduler **sebelum coding**. Jangan menyamakan audit historis/PRD lama dengan kode yang saat ini ada. Lakukan *discovery and discrepancy report* terlebih dahulu. Jangan pernah menganggap akses repo publik atau UAT merchant sudah berhasil. PRD ini menyatukan semua keputusan produk user hingga 8 November 2026; jika PRD v1/v2 bertentangan, keputusan dalam dokumen ini menjadi scope produk terbaru, tanpa menghapus safety invariants lama.

---

## 1. Tujuan Produk dan Prinsip Tak Bisa Ditawar

**Masalah:** User membeli tiket destinasi, tetapi sistem payment harus memberikan pilihan bayar tanpa meminta input nominal, mengelola ketidakpastian provider, dan tidak menerbitkan QR tiket sebelum tanggal yang diizinkan.

**Outcome:** Setelah admin menyetujui booking, user memilih BNI VA atau QRIS, menerima tagihan dinamis dengan nominal yang sama persis dengan snapshot invoice Laravel, melihat countdown dan instruksi, dapat cek status, serta mendapat email bukti pembayaran. Setelah lunas, QR tiket unik untuk setiap pengunjung hanya diterbitkan sejak H-5 tanggal kunjungan (atau langsung setelah lunas jika H-5 s.d. H). User bisa melihat bukti pembayaran dan tiket di aplikasi.

**Invariants (P0):**
1. **Tidak ada input nominal pada Flutter.** Harga, biaya sah, jumlah tiket, total, currency, booking, deadline, dan identitas environment ditentukan server; seluruh field sensitif dari client ditolak jika disisipkan.
2. Satu booking/satu destinasi **satu invoice**. Bisa terdapat beberapa pengunjung/tiket per booking; QR masuk unik per pengunjung.
3. Payment baru bisa dibuat setelah admin approval dan booking menjadi eligible `awaiting_payment` menurut state machine existing.
4. `PAID` hanya melalui notification DOKU yang sah/terverifikasi atau query terminal resmi yang semantiknya terverifikasi; bukan redirect, HTTP create sukses, QR sudah tampil, timer nol, atau klaim Flutter.
5. Hanya **satu active/uncertain payment attempt per invoice** pada suatu waktu; switching tak boleh menghasilkan dua tuntutan pembayaran yang sama-sama aktif.
6. Booking/kuota/tiket direkonsiliasi atomik dengan *existing* domain/locks; tidak ada overselling, duplicate history, double revenue, duplicate receipt, atau tiket ganda.
7. **QRIS pembayaran dan QR e-ticket masuk destinasi berbeda total.** QR e-ticket tidak boleh diterbitkan sebelum H-5 walaupun booking telah `confirmed`.
8. Semua status `unknown`, pending, late paid, reversed, refund, konflik amount, dan provider timeout diwakili secara jujur; tidak ada fabricated success/failure.
9. Merchant credentials, secret, token, signature, private key, raw callback, dan konfigurasi environment tidak pernah berada di Flutter / repo publik / user log.
10. Deployment Sandbox dan Production terisolasi; go-live perlu merchant activation + signed E2E UAT, bukan hasil local mocks saja.

### Product non-goals
- Tidak membuat payment processor mandiri selain DOKU; tidak menerima transfer manual dan bukan Open Amount VA.
- Tidak menambah DANA, ShopeePay, atau OVO **Direct API**; pengguna DANA/GoPay tetap dapat memakai QRIS melalui aplikasi yang mendukung, sesuai kemampuan issuer.
- Tidak merekayasa ulang approval, quota, booking model, ticketing, atau check-in tanpa bukti gap dan persetujuan desain teknis.
- Tidak mengubah kode bisnis demo/test menjadi jalur penerimaan uang Production.
- Tidak mengaktifkan provider/channel hanya karena flag tersedia; capability harus diuji/diaktifkan merchant.

## 2. Source of Truth, Status Audit, dan Preflight

### 2.1 Repository target persis menurut user
- **Flutter:** `https://github.com/joytimess/pami-wisata-daerahku`
- **Laravel:** `https://github.com/joytimess/pami-wisata-daerahku-api`

Audit historis `PRD_DOKU_Direct_API_Wisata_Daerahku_v1.0.md` menulis Flutter di `koyotot/pami-wisata-daearahku` (owner dan ejaan berbeda). **Itu bukan izin memakai repo pengganti.** Codex wajib mendeteksi remote, branch, HEAD commit, path, dan membandingkannya dengan URL user. Jika repo Flutter yang user sebut tidak tersedia, tandai `BLOCKED: source provenance` dan jangan diam-diam memakai repo lain.

### 2.2 Evidence existing (dokumen historis, BUKAN audit HEAD terbaru)
- Audit terdahulu mendokumentasikan `routes/api.php`, `PaymentController`, `PaymentService`, `PaymentGateway`, `PaymentEligibility`, `DemoPaymentService`, `PaymentStates`, Flutter `DemoCheckoutScreen` dan `BookingPaymentSection`.
- `DOKU-RUNBOOK.md` tanggal 8 Nov 2026 mencatat BNI SNAP v1.1 DGPC (auth, create, durable webhook, idempotensi, replay, reconciling), tested di fixture/isolated DB; DOKU Sandbox/Production live belum dijalankan. QRIS belum diimplementasikan menurut checkpoint tersebut. Terlapor 178 feature tests/1170 assertions dan 2 MySQL concurrency tests/30 assertions; **jangan klaim pass pada HEAD saat ini tanpa rerun**.
- `PRD_DOKU_Multichannel_Backend_v2.0.md` mewajibkan QRIS + multi-bank + e-wallet; **superseded soal channel scope** oleh keputusan user yang lebih baru. Safety invariants seperti idempotensi, provenance, audit dan environment isolation tetap dipakai.

### 2.3 Audit wajib di repo aktual sebelum implementation plan
1. `git status`, branch/commit, clean/dirty files, tags, remotes, `AGENTS.md`, dependency versions, CI; jangan mengubah existing changes.
2. Inventaris route Flutter navigation, booking details/history, payment widgets/screens, state management, API client/DTO, app lifecycle resume, QR display/sharing, email settings yang digunakan.
3. Inventaris Laravel route/provider middleware, booking lifecycle, approval, inventory/stock reservation, payment deadline & visit cutoff, harga/fee snapshot, invoice & receipt, transaction/event schema/index, DOKU config/transport/adapter, queue, scheduler, notification, ticket issuance, scan/check-in, refund.
4. Verifikasi scheduler existing untuk aturan H-5 (termasuk timezone/jam), mekanisme tiket per pengunjung, apakah QR direpresentasikan sebagai opaque token atau gambar, bagaimana ticket lifecycle/reference sekarang bekerja.
5. Audit `PaymentEligibility`, DOKU and DEMO provenance, callback locks, demo regressions, webhook idempotency and transaction wrapping. Apabila nama file berbeda dari referensi historis, ikuti source saat ini.
6. Jalankan test **baseline** tanpa menyentuh DB asli, backup, env secret, atau mereset database. Catat commands dan hasil, bukan mengarang.
7. Keluarkan `docs/audits/YYYY-MM-DD-payment-existing-gap-analysis.md` berisi: source paths + line/commit, existing behavior, gaps vs PRD, risks, migration constraints, blocker/owner. **Jika sumber code tidak terbaca: STOP sebelum code.**

## 3. Role dan Domain Ownership

| Role | Hak/perilaku |
|---|---|
| Wisatawan (authenticated owner) | Melihat booking sendiri, pilih pembayaran, meninjau nominal, membayar, cek status, unduh bukti, melihat tiket eligible, mengajukan refund |
| Admin/manager yang berwenang | Approve booking, review refund, investigasi transaction status dengan audit trail; tidak boleh *force paid* tanpa verified provider event |
| Laravel | Pengendali state, amount, invoice, booking cutoff, quota, idempotency, DOKU API, webhook, ticket issuance, email, refund policy |
| DOKU | Menghasilkan VA/QRIS & outcome pembayaran sesuai kontrak channel/merchant |
| Flutter | Menampilkan UI berdasarkan server data, countdown lokal dari `expires_at`, manual check, resume/recovery; bukan sumber `paid` |
| Petugas tiket existing | Melakukan validasi QR sesuai aturan scan/check-in yang sudah ada; tidak menerima QR pembayaran sebagai tiket masuk |

## 4. User Journeys dan UX Flutter (P0)

### 4.1 Flow utama
`Pilih destinasi & tanggal → buat booking → admin approve → awaiting_payment → buka halaman pembayaran → pilih metode → Lanjut Bayar → Laravel buat attempt ke DOKU → tampil instruksi & countdown → webhook/query verified → PAID → halaman success + email receipt → e-ticket menurut H-5 → email e-ticket.`

#### Screen A — Pembayaran Booking (`PaymentMethodSelectionScreen`, nama final ikuti convention code)
- Hanya bisa diakses owner setelah admin approval & eligibility validated; jika belum approved, tampilkan state *Menunggu persetujuan*, bukan tombol bayar.
- Ringkasan destinasi, tanggal kunjungan, jumlah pengunjung, nomor booking/invoice, subtotal, biaya layanan sah jika ada, total akhir, IDR.
- **Dua section/card radio**, bukan input: `Virtual Account → Bank BNI` dan `QRIS`; hanya tampil clickable ketika `GET payment methods` menyatakan available. Tampilkan alasan bila unavailable.
- CTA `Lanjut Bayar` baru menginisiasi provider attempt **setelah pilihan user**; disable double-tap; use idempotency key; jangan otomatis membuat attempt saat screen dibuka.
- `Back` atau app kill sebelum create tidak boleh membuat tagihan.

#### Screen B — Menunggu Pembayaran / Instruksi (P0)
- Header status (pending/initiating/unknown), ringkasan nominal, `expires_at` countdown **30 menit target** dihitung dari server/provider absolute timestamp, bukan 30 menit sejak setiap screen dibuka.
- **BNI VA:** nama bank, VA number (copy), nominal fixed, langkah membayar melalui mobile/internet banking/ATM, expiration, `Cek Status Pembayaran`.
- **QRIS:** render QR dinamis dari `qr_payload`/provider instruction yang sah, jumlah tagihan fixed, `Simpan QR` dan `Bagikan QR` (ke aplikasi lain), petunjuk scan/pay menggunakan aplikasi QRIS; `Cek Status Pembayaran`. QR tampil dapat dipulihkan jika app resume, tanpa generate ulang.
- Status polling otomatis **hanya saat screen aktif** dengan bounded interval/backoff; tombol `Cek Status` memakai endpoint backend yang sama dan cooldown rate limit; resume dari background memuat status terbaru.
- `Ganti Metode` hanya diizinkan jika backend memverifikasi previous attempt terminal atau pembatalan berhasil dan tak lagi payable; untuk VA yang tak mendukung pembatalan terverifikasi tampilkan penjelasan dan blok switching sementara, bukan create dua attempt.
- Timer sampai 0 memicu refresh/reconcile; **jangan otomatis menandai gagal** jika status provider masih uncertain. Jika sudah expired sah, tampilkan `Pembayaran Kedaluwarsa`, retry hanya saat eligible.
- Jika app ditutup, booking/payment intent dapat dipulihkan dari **Riwayat Booking**, dan pengingat di beranda ketika ada active payment; tidak menduplikasi create call.
- Gagal/unknown/expired cukup ditampilkan di layar pembayaran dengan pesan jelas dan CTA sesuai status; jangan fake redirect success.

#### Screen C — Pembayaran Berhasil (P0)
- Dibuka **hanya** jika backend `paid` verified, termasuk setelah app kembali dari background.
- Fields: status, nomor booking, nomor invoice/provider reference yang aman, nama destinasi, tanggal kunjungan, jumlah tiket, total dibayar, metode (`BNI VA`/`QRIS`), waktu bayar `paid_at` timezone user/destinasi yang jelas.
- CTA `Unduh Bukti Pembayaran`, `Lihat Booking`, dan `Lihat Tiket` (jika QR belum eligible, tampilkan tanggal tersedia).
- Bukti/receipt server-generated memuat data authoritative, tidak dibuat hanya dari cached Flutter.
- Pembayaran bukan bukti tiket QR telah terbit; tampilkan keterpisahan dua status.

### 4.2 Flutter states yang harus ditangani
| Backend normalized state | UI |
|---|---|
| `requires_payment` | Pilih metode |
| `initiating` | Membuat instruksi, loading yang dapat dipulihkan |
| `pending` | QR/VA, timer, instruksi, status check |
| `unknown` / `needs_review` | Verifikasi berjalan, tidak memberikan tombol create berulang |
| `paid` | Success screen dengan receipt |
| `expired` / `failed` | Penjelasan dan retry bila backend eligible |
| `cancelled` | Instruksi lama tidak aktif; kembali ke metode bila eligible |
| `reversed` / `refunded` / `refund_pending` | Status penyesuaian pembayaran, tiket revoked bila relevan |

State names final mengikuti schema existing; mapping DTO dibuat eksplisit dan diuji Flutter.

## 5. Booking, Invoice, Harga, Kuota, Deadline

- 1 booking hanya 1 destinasi; 1 invoice untuk booking tersebut. `booking_guid` dan `invoice_id` stabil; 1 canonical `payment_intent` per kewajiban pembayaran; attempt boleh bertambah hanya setelah old attempt aman terminal.
- Laravel memotret `unit_price`, `quantity`, `subtotal`, biaya layanan sah (jika ada), `total_amount`, IDR, booking/destination and visit date. Jangan mengambil harga mutable dari kartu destinasi ketika user sedang membayar invoice yang sudah final.
- Payment amount ke DOKU **harus sama** invoice total final dan verified provider callback nominal; mismatch → `needs_review`.
- Biaya admin terpisah harus legal dan transparan sebelum CTA (lihat §13); **QRIS MDR tidak boleh dibebankan ke konsumen**.
- Booking hanya dapat di-pay setelah approval dan selama quota/eligibility tetap valid; existing approval/deadline/cutoff tetap authoritative.
- Permintaan user bahwa booking dan bayar boleh sampai **hari H jika kuota dan jadwal mengizinkan**, bukan wajib menerima semua booking hari H. Jangan membuat deadline 24 jam, H-1 atau aturan jam baru tanpa source existing.
- Efektif `payment_expires_at = min(provider_expiry, attempt_created_at + 30 menit, booking_payment_deadline, visit_start/cutoff applicable)`. Jika provider/channel tidak bisa menerima window yang tersisa sesuai kontrak minimalnya, **jangan issue attempt** dan tampilkan alasan; jangan silently shorten dengan provider yang tak mendukung.
- Quota reservation/stock release/paid finalization mengikuti model dan lock order existing. `paid_at` menyimpan waktu event provider terverifikasi, bukan waktu UI callback.

## 6. Aturan E-Ticket H-5 (P0)

1. QRIS = instruksi **bayar**; QR e-ticket = kredensial **masuk destinasi**. Data model, presentation, revocation, dan validasinya terpisah.
2. Booking baru `paid` pada **H-6 atau lebih awal**: catat booking confirmed dan entitlement tiket, **belum menghasilkan QR entry**; langsung kirim email pembayaran. Tampilkan `Tiket tersedia mulai H-5 (tanggal lokal destinasi)`.
3. Pada **H-5**, existing scheduler/queue menerbitkan QR tiket **satu QR unik per pengunjung**, idempotent, dengan tanggal kunjungan, booking, dan entitlement yang tepat; kirim **email tiket terpisah**.
4. Pembayaran verified terjadi **H-5, H-4, H-3, H-2, H-1, atau hari H**: lakukan issuance segera via queue saat eligible; UI menampilkan `Tiket sedang diterbitkan` jika job belum selesai, lalu QR saat siap. Booking hari H tetap wajib belum melewati cutoff/visit start.
5. Jam persis scheduler H-5 dan local timezone **diambil dari kode booking/ticket existing**. Jangan membuat aturan baru 00.00 tanpa source audit; jika tidak ada jam yang ditetapkan, tandai sebagai gap dan pilih default terukur dalam design review tanpa melempar ulang pertanyaan umum kepada user.
6. Jumlah QR efektif harus sama dengan jumlah admission/entitlement yang valid; id unik per pengunjung/tiket. Jika existing schema tidak menyimpan nama pengunjung, jangan menambah mandatory PII baru tanpa dasar; QR unik tetap wajib.
7. QR harus opaque/random/signature-protected atau sesuai secure ticketing existing, **tidak boleh** berisi JWT user, credential pembayaran, atau data sensitif. Verifikasi online/scan sesuai existing access rules; no double-entry jika aturan tiket single-entry.
8. Jika refund/cancel/reschedule: tiket eligible yang belum dipakai dibatalkan/revoked dan issuance baru mengikuti aturan existing; jangan menghapus payment history. Tiket sudah dipakai harus memblok auto-refund kecuali ada kebijakan operator yang jelas.
9. Duplicate provider callbacks, worker retries, rescan, reopen app, email retry tidak boleh menerbitkan QR kedua untuk entitlement yang sama.

## 7. Email, Notifikasi, Bukti Pembayaran

### 7.1 Payment success email
- Trigger **setelah verified `paid` applied sekali**, via durable business event/outbox/queue (bukan langsung dari callback HTTP thread).
- Kirim ke email akun yang sah/terverifikasi sesuai existing policy; hindari mengirim ke email sembarang dari payload pengguna tidak terverifikasi.
- Subject contoh: `Pembayaran Wisata Daerahku Berhasil — {booking_number}`.
- Wajib: nomor booking, nomor invoice, destinasi, visit date, nominal rupiah dan rincian biaya, metode, `paid_at` (zona waktu eksplisit), tautan `Lihat Booking`/`Unduh Bukti Pembayaran`. **Jika belum H-5, jangan lampirkan QR e-ticket.**
- Dedupe email per verified payment event, retry dengan queue + monitor failure, jangan menurunkan status `paid` bila email gagal.

### 7.2 E-ticket email terpisah
- Trigger tepat saat QR entry terbit (H-5 atau segera setelah paid jika sudah H-5), dedupe per booking/entitlement issuance event.
- Sertakan destinasi, nomor booking, tanggal kunjungan, jumlah pengunjung, QR unik per pengunjung (atau secure download link), instruksi check-in, kebijakan tiket. Jangan pernah lampirkan QRIS payment sebagai tiket.
- Jika email gagal, tiket tetap dapat diakses dari Flutter dan email di-retry; mekanisme resend harus dibatasi dan diaudit.

### 7.3 Receipt/bukti pembayaran
- Server-generated downloadable receipt (format menyesuaikan fitur PDF/booking-documents existing), invoice number, payment ref aman, amount, currency, time, method; akses hanya booking owner/admin berwenang; revoked/adjustment disajikan dengan histori yang benar.
- Receipt tidak boleh menyatakan `paid` untuk `pending`/`unknown`.

## 8. Payment Backend: Arsitektur Target

```text
Flutter (booking detail / history / payment / success / tickets)
    │ JWT + idempotency-key, NO amount or merchant credentials
    ▼
Laravel API routes + authorization
    │  ├─ Booking / Approval / Quota / Invoice (existing)
    │  ├─ Payment eligibility + orchestration (existing, extend)
    │  └─ Payment intent / attempts / events / idempotency (existing)
    ▼
DOKU Gateway Facade / channel registry
    ├─ DokuEnvironmentConfig & shared SNAP auth/signature primitives
    ├─ BNI VA DGPC closed amount adapter
    └─ QRIS dynamic MPM adapter
    ▼
DOKU Sandbox or Production (distinct deployment/credentials)
    │
    ├─ Signed channel notification -> durable event store -> queue application
    └─ Verified inquiry/reconciliation -> normalized terminal outcome
    ▼
Finalize payment once -> Booking confirmed, quota integrity
    ├─ Payment receipt outbox/email
    └─ Ticket entitlement -> H-5 scheduler / immediate eligible issuance
          └─ QR per visitor -> e-ticket email / Flutter tickets
```

- Avoid a second payment domain. **Extend current `PaymentService`, `PaymentEligibility`, `PaymentGateway`, `DemoPaymentService` contracts where present.** Keep DEMO sandbox separate, no DOKU settlement via simulation route.
- Shared payment orchestrator drives amount, eligibility, idempotency, reference, event handling, refund status, finalization; channel adapter handles bank/QR protocol differences.
- Bank extensibility: `va_bni` now; create bank-registry interface for future `va_bca`, `va_bri`, `va_mandiri`; they are **not** implemented or activated in current release unless separately authorized/verified.
- No external DOKU HTTP request while holding long-lived booking/stock DB locks.

## 9. DOKU Channel Requirements (P0)

### 9.1 BNI VA
- DGPC generated by DOKU, **`virtualAccountTrxType=C`** / Closed Amount (Fixed Bill); amount integer IDR snapshot serialized per provider protocol.
- Create: documented path `POST /virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va` (verify exact SNAP spec/current code). Correct B2B access token, header signing, partner service/BIN/SubBIN, merchant fields, unique invoice/reference, expiry; never use mock/default keys in live.
- Response normalized `presentation_type=virtual_account`, `bank_code=bni`, `va_number`, display name, amount, `expires_at`, safe instructions. Encrypted at rest and only visible to owner.
- Signed callback and inquiry status mapping per official verified BNI contract. `paidAmount` in `Pending` sample is not sufficient proof of capture. Unknown/terminal unsupported mapping remains `unknown`/review.
- **Do not assert BNI VA cancel/delete support** until verified against merchant/provider; if unverified, wait safe expiry before switching methods.

### 9.2 QRIS
- DOKU SNAP MPM dynamic `POST /snap-adapter/b2b/v1.0/qr/qr-mpm-generate`; amount fixed `IDR`, merchant ID, terminal ID, unique `partnerReferenceNo`, provider validity period; don't hardcode sample merchant/terminal IDs.
- Normalize provider QR payload into `presentation_type=qris` / `qr_payload` plus `expires_at`, IDR amount, refs. QR PNG is **presentation of returned payload**, not a fabricated static payment QR.
- Verify documented status-query `/snap-adapter/b2b/v1.0/qr/qr-mpm-query`, expiry `/snap-adapter/b2b/v1.0/qr/qr-expire`, refund `/snap-adapter/b2b/v1.0/qr/qr-mpm-refund`, callbacks and response status semantics against official docs and real sandbox fixture before enabling.
- Cancel/switch only after provider cancellation is confirmed and reconciliation race has been handled.
- Expose QRIS method only after adapter, config, merchant activation, webhook, tests and availability gate are complete.

### 9.3 Provider readiness rules
- `methods.available=true` iff: code implemented + validated config + channel active for merchant/environment + booking eligible + callback/reconcile operational.
- `DOKU_ENABLED=false` and per-channel flags stop **new issuance** but must not discard callbacks/inquiry for existing in-flight attempts.
- If QRIS unavailable but BNI active, show only BNI payable; if both unavailable, no payment initiation, clear user-facing reason.

## 10. API Kontrak Flutter ↔ Laravel (proposed shape; reconcile with existing routing)

**Do not rename existing routes without compatibility shim.** Existing runbook names are authoritative starting points; verify actual `route:list` before implementation.

| HTTP | Path konseptual | Responsibility |
|---|---|---|
| `GET` | `/api/v1/payments/methods?booking_guid={guid}` | Available/disabled method + reason; owner-auth |
| `GET` | `/api/v1/bookings/{guid}/payment-intent` | Canonical state, invoice amount, safe instruction & `expires_at`; no reissue |
| `POST` | `/api/v1/bookings/{guid}/payment-attempts` | Idempotent create BNI VA or QRIS; user sends only method & channel |
| `GET` | `/api/v1/bookings/{guid}` (existing) | Booking approval / visit / destination / ticket eligibility |
| `GET` | existing receipt route or additive owner-only | PDF/document download after verified paid |
| `POST` | existing authenticated refund request route or additive | User refund request based on policy |
| `POST` | `/api/v1/webhooks/doku/va` | Channel-specific authenticated provider notification (no user JWT) |
| `POST` | `/api/v1/webhooks/doku/qris` | Channel-specific authenticated provider notification (no user JWT) |

**Create example only (existing contract):**
```http
POST /api/v1/bookings/{booking_guid}/payment-attempts
Authorization: Bearer <user-jwt>
Idempotency-Key: <uuid>
Content-Type: application/json

{"method_code":"virtual_account","channel":"bni"}
```
QRIS variant: `{"method_code":"qris","channel":"qris"}` if approved by actual Laravel request validation. **Do not add `amount`, `paid`, `provider`, `environment`, `merchant_id`, `expiry` from client.**

**Proposed response fields** (DTO precise final from repo audit):
```json
{
  "data": {
    "booking_guid": "<guid>",
    "invoice_number": "<server-invoice-number>",
    "intent_guid": "<guid>",
    "attempt_guid": "<guid>",
    "payment_status": "pending",
    "amount": 250000,
    "currency": "IDR",
    "method_code": "virtual_account",
    "channel": "bni",
    "expires_at": "2026-11-08T23:30:00+07:00",
    "instruction": {
      "presentation_type": "virtual_account",
      "bank_code": "bni",
      "va_number": "<provider-issued-value>"
    }
  }
}
```
Illustrative fields/values only — not a claim of current response or live VA. For QRIS use `presentation_type=qris` and provider-returned `qr_payload` instead of VA.

**Status action:** `Cek Status` initially calls existing `GET payment-intent`; backend decides whether immediate inquiry may be scheduled/rate-limited. Do not make Flutter query DOKU directly. Polling never creates new attempts.

**Error contract** (normalize without leaking raw provider payload): `booking_not_approved`, `payment_not_eligible`, `channel_unavailable`, `active_attempt_exists`, `provider_pending_confirmation`, `provider_unavailable`, `payment_expired`, `payment_under_review`, `invoice_mismatch`, `retry_not_allowed`, `rate_limited`. Mapping must be derived from actual status/schema, with stable test coverage.

## 11. State Machine & Concurrency

| Event | Required response |
|---|---|
| Create double-tap/retry same key | Same attempt and provider call maximum once |
| Create response timeout before reference known | `unknown`, preserve reservation & merchant reference, investigate; don't recreate blindly |
| Provider pending | `pending`; not paid even if provider response includes amount fields |
| Callback valid paid before deadline, quota eligible | Mark `paid` once, booking confirmed, receipt queued, ticket per H-5 |
| Callback invalid signature, mismatched amount/reference/env | Reject/quarantine; no paid/ticket |
| Duplicate callback / reordered events | Semantic dedupe; no downgrade `paid`, no duplicate booking/email/ticket |
| Provider says failed/expired and no other unresolved attempt | Safe retry allowed if booking eligible |
| Timer zero but status unresolved | Query/reconcile and show pending verification; never force failure |
| Late paid after booking deadline, visit start or superseded attempt | `needs_review` + possible unapplied payment; no automatic ticket issuance |
| Switch VA ↔ QRIS | Only when old attempt confirmed cancelled/terminal; no simultaneous payable instruction |
| Cancel/refund after paid | Adjustment history & ticket revocation as policy requires; do not overwrite historical paid event |
| Worker offline | Durable signed notification persists for replay; no data loss |
| Sandbox vs Production mismatch | Reject/quarantine and preserve immutable attempt environment |

All payment side effects use DB transaction + locks + unique business event keys, retaining existing lock order. Use provider-supplied occurrence time & audit provenance, with conservative time skew validation.

## 12. Refund & Cancellation

- Request flow: user requests refund for own eligible booking → backend records reason/request → authorized admin reviews/approves or rejects → after approval, backend checks ticket usage and policy → issue provider refund only when **official API semantics AND merchant entitlement confirmed** → track `refund_pending`, `refunded`, failed/needs_review.
- QRIS refund API exists in docs, but live merchant support, full/partial, fees, time limits and response mapping must be verified via sandbox/contract tests. BNI automatic refund is **not assumed** supported via VA API.
- If provider refund initiation isn't supported/verified, **do not fabricate auto-refund**: retain approved refund in admin queue with `manual_action_required`, audit trail and customer status truthful. Never secretly mark refunded.
- Full vs partial refund capability/used-ticket behavior must be read from existing booking policies; do not assert partial refund now. No auto-refund after redeemed QR without policy approval.
- Refund confirmation/reversal updates eligibility, revokes unredeemed tickets as appropriate, and triggers receipt/notification adjustment, but does not erase invoice/payment records.

## 13. Fees, Compliance & Security

- All final amounts agreed **before** attempting payment; if a legitimate service fee is permitted, display as separate line item; calculate on Laravel and persist the invoice snapshot. Never silently increase total after user selected a method.
- **Bank Indonesia rule (13 Oct 2026): QRIS Merchant Discount Rate (MDR) is borne by merchant and may not be passed to consumer.** Do not charge MDR QRIS as an explicit checkout surcharge. Confirm other admin charges with DOKU contract and applicable law before activation.
- Laravel authorization for booking owner, admin permission & destination assignment; rate limit initiate/status, CSRF as appropriate for surface, raw-body signature + timestamp + merchant/reference/environment validation on webhook.
- Provider notification must be durably stored before ACK; sanitize logs; rotate credentials safely; immutable environment per attempt. Secret references only through environment/secret store.
- Secure temporary QR image/share save path (user-selected destination/permissions); do not expose other users' instructions or tickets. Receipt/email links short-lived/owner-protected.
- Use integer minor/IDR amount representation in domain; formatting in adapter; currency and amount exact match; no floating rounding surprises.
- Backup, migration pretend/dry-run, additive schema modifications, safe rollouts, no destructive rollback of paid transactions.

## 14. Test Matrix, Evidence & Acceptance Criteria

### Backend automated (P0)
1. Approval gate: pre-approved/non-owner denied; payable booking accepted; amount/currency/env cannot be submitted/overridden by client.
2. Invoice: one per booking/destination, price snapshot, quantity, fee legality; no overbook on concurrent bookings.
3. Create: idempotent same key, parallel create single provider issuance; provider timeout `unknown`; no reissue while unresolved.
4. BNI: exact closed amount, token/signature request, expiry, response/VA retrieval; signed paid callback; invalid signature & replay.
5. QRIS: fixed amount dynamic payload, expiry, correct signature, query, expired/cancel/refund supported fixtures; disabled when merchant unavailable.
6. Effective expiry: 30-minute max without violating booking deadline/visit start; callback vs expiry race and same-second capture; late payment needs review.
7. Switching: active VA → QRIS blocked until terminal; QRIS cancel webhook race; no double debit/ticket.
8. H-5: `paid@H-6 → no QR until H-5`; scheduler issues once; `paid@H-4 → immediate QR`; `paid@H → if still eligible`; exact multiple ticket count; timezone boundaries/daylight where relevant.
9. Notification: one payment receipt email after paid, second ticket email at H-5, duplicate callbacks + queue retry don't duplicate deliveries, email outage doesn't undo paid.
10. Refund: user request, admin authorization, provider unknown/manual fallback, redeemed ticket, revocation, no false refunded status.
11. Security: owner/admin isolation, rate-limit, env mismatch, corrupted instruction, secrets redaction; DEMO can't settle DOKU.
12. MySQL concurrency and invariants: attempt-create races; webhook vs expiry vs issuance; refund vs check-in; semantic dedupe keys; migrations preserve legacy records.

### Flutter automated/manual (P0)
1. Render BNI/QRIS two sections; no amount input; disabled/unavailable states.
2. Exactly one create call on CTA; double tap blocked; same payment restored after restart; available/disabled from backend.
3. BNI number copy; QR render/save/share same provider payload; payment instructions; 30-min countdown from timestamp.
4. Foreground polling + manual check; no DOKU direct HTTP and no client-side fake successful state; expiry/unknown status UX.
5. Navigation: approved booking → pay → verified success; source of invoice/paid_at; receipt download; ticket H-6/H-4 UI.
6. Accessibility (semantics), responsive display, offline/errors, permission denied saving QR, app lifecycle, race after resuming.

### External Sandbox & Production gates
- At least one **actual signed end-to-end Sandbox test per enabled channel** using merchant activation and valid Notification URL: create→provider paid→signed callback or verified inquiry→booking paid→email→ticket gating.
- Demonstrate tests for QRIS and BNI independently; blocked merchant channel is `BLOCKED`, **never** `PASS` from fixture only.
- Production: merchant account/channel activation, correct BIN/SubBIN/terminal/keys, permanent HTTPS, registered callback, workers+schedule, debug off, monitoring/alerts, staging data isolation, backup, controlled real transaction, reconciliation and rollback playbook.
- No Production deployment or money movement by Codex agent without explicit user authorization.

**Minimum Definition of Done:** All P0 automated tests pass on actual checkout, no unapproved git changes, code review/security issues addressed, DOKU live-environment UAT evidence for all channels to enable, Flutter device E2E verified, ticket + email time rules verified, no scope creep. Tests/migrations/route commands and sanitized outputs attached to final report.

## 15. Delivery Plan dan Milestones (Codex wajib membuat plan terperinci sesudah audit)

| Milestone | Output | Gate |
|---|---|---|
| M0 — Source discovery | Actual HEAD audit Flutter+Laravel; conflict matrix; baseline tests; verified agents/skills | No code until green-light and gaps mapped |
| M1 — Contract & architecture | DTO status/instruction/expiry, state-transition table, payment capability and invoice snapshot design | Backward compatibility DEMO/booking/tickets |
| M2 — BNI hardening | Reuse BNI adapter, migration safety, callback, status recovery, expiry 30m, channel verification | Contract/MySQL tests and merchant Sandbox evidence |
| M3 — QRIS adapter | Generate/query/cancel/refund where verified, callback and tests; disabled-by-default gate | QRIS isolated E2E Sandbox proof if merchant supports |
| M4 — Flutter UX | Payment selection, VA/QRIS instruction, polling, status check, timer, recovery, success, receipt | Flutter tests / emulator |
| M5 — Email & H-5 e-ticket | Outbox, payment receipt email, ticket release schedule, unique QR per visitor, e-ticket email | H-6/H-5/H-4 tests, duplicate prevention |
| M6 — Refund, finance, ops | User request/admin approval, provider or manual fallback, logs, reconciliation, alerts, runbook | Security review and refund fixture/UAT |
| M7 — Release | Full regression + Sandbox UAT, controlled Production checklist | User must approve go-live separately |

Tasks must be broken into independently verifiable small increments in `docs/superpowers/plans/YYYY-MM-DD-doku-payment-eticket.md` **after** M0. Do not treat this PRD as a code-level plan with exact unverified paths.

## 16. Codex CLI: Agents Tercatat, Skills, dan Workflow Wajib

### 16.1 Konfigurasi lokal — wajib ditemukan, bukan diasumsikan
- **Agent names reported in historical local Codex context:** `master_orchestrator`, `system_architect`, `backend_engineer`, `security_auditor`, `qa_engineer`.
- **Penting:** nama tersebut **tercatat historis**, bukan konfirmasi isi agent config saat PRD dibuat. Codex harus memeriksa file aktual (mis. `%USERPROFILE%\.codex\agents\`, `AGENTS.md`, repo `.codex/agents/` sesuai instalasi). Jangan memanggil agent yang tidak ditemukan; jika nama/role berbeda, mapping ke agent yang benar-benar ada dan catat evidence.
- **Skill source:** [obra/superpowers](https://github.com/obra/superpowers). Periksa pada path real yang user gunakan (`%USERPROFILE%\.codex\skills\`, `%USERPROFILE%\.agents\skills\` atau lokasi terpasang); read `SKILL.md` sebelum use. Repo `.agents/skills` dan `AGENTS.md` bisa menambah instruksi.
- Jangan menyatakan `skills/agents available` hanya dari nama dalam PRD. Daftar skill aktual ditentukan dari filesystem/runtime Codex, dengan lokasi dan versi/commit jika ada.

### 16.2 Skills yang diinstruksikan untuk digunakan **jika tersedia**
- `using-superpowers` — baca aturan skill dan pemilihannya di awal.
- `brainstorming` — konfirmasi desain ini sebagai **approved baseline** tanpa mengulang 20 pertanyaan yang telah dijawab; hanya bahas konflik yang dibuktikan source.
- `using-git-worktrees` — isolasi kerja dan baseline test setelah audit.
- `writing-plans` — membuat *task-by-task implementation plan* dengan paths nyata, test dan verification command.
- `subagent-driven-development` **atau** `executing-plans` — implement plan dengan review per task; jangan dua mode sekaligus.
- `dispatching-parallel-agents` — hanya bila pekerjaan independen dan agent tersedia.
- `test-driven-development` — RED→GREEN→REFACTOR, termasuk negative/security cases.
- `systematic-debugging` — root-cause discipline ketika gagal.
- `requesting-code-review` dan `receiving-code-review` — verifikasi fungsional & keamanan.
- `verification-before-completion` — rerun actual tests, migrations, lint, device & UAT evidence sebelum klaim selesai.
- `finishing-a-development-branch` — rangkum PR/merge pilihan; jangan push/merge/deploy tanpa perintah user.

### 16.3 Pembagian agent (maksimal **3 subagent paralel**; root bukan subagent)
| Role | Mapping agent *jika file nyata ada* | Tanggung jawab |
|---|---|---|
| Root orchestrator | `master_orchestrator` atau Codex session utama | Memimpin audit 2 repo, integrasi Flutter, merge hasil, plan & test akhir, ownership file dan laporan |
| Architecture review (read-only) | `system_architect` | Booking/payment/ticket state machine, cutoff, DB constraints, QR H-5 and lock order; tulis findings, jangan edit shared files |
| Backend implementer | `backend_engineer` | DOKU BNI/QRIS adapters, service, route, models, jobs, Pest; edit hanya file yang di-assign root |
| Security review (read-only) | `security_auditor` | Secrets, signatures, callback replay, auth, race, idempotency, refund, environment; findings & severity |
| QA (replacement option) | `qa_engineer` | Test matrix/Flutter-device & concurrency review; **ganti satu slot subagent**, bukan subagent keempat |

**Concurrency rule:** Total maksimum 3 subagents pada waktu yang sama, dengan file ownership eksklusif. Read-only architect/security dapat paralel dengan backend jika source snapshot stabil; pekerjaan Flutter UI ditangani root atau implementer yang benar-benar tersedia dan ditugaskan eksplisit. Jangan membuat agent `flutter_engineer` fiktif. Agent review tidak menyatakan audit selesai tanpa membaca HEAD.

### 16.4 Mandatory workflow in Codex
1. Preflight: buka kedua clone di checkout user; verifikasi remote/branch/dirty changes, `AGENTS.md`, installed agent definitions, installed `SKILL.md`. Ambil SHA HEAD, laporkan bagian yang tak bisa diakses.
2. Read-only `M0` source discovery: audit naming/status/migrations/test/scheduler, diff dengan PRD; output risk register (severity, file paths+line number, evidence, patch intent).
3. Jika gap/konflik bisa ditentukan existing code, ikuti existing standards; **jangan mengulang requirement questionnaire**. Tanyakan user hanya jika ada konflik bisnis mustahil diselesaikan dari repo.
4. Jalankan test baseline safely; checkpoint existing work (tanpa overwrite/stash paksa). Buat worktree hanya dengan izin & skill procedure.
5. `writing-plans`: pisahkan backend, Flutter, tickets/emails, ops, dengan concrete file paths yang sudah diverifikasi dan test plans; mark unknown provider dependencies.
6. `test-driven-development`: implement incremental, no long-lived concurrent edits in same files, stage migrations additively, test after each segment.
7. Security + QA review independent, address findings, rerun full suite & Flutter analysis/device tests. Dokumentasikan executed vs blocked E2E.
8. Final handoff: completed diff, commit hashes, migration list, test commands + counts, env variables **names only**, DOKU UAT blockers, runtime operations, rollout plan. **No automatic deploy/Production, no fake tests.**

## 17. Release Dependencies & Risk Register

| ID | Risiko / conflict | Severity | Mitigasi/acceptance |
|---|---|---|---|
| R01 | Flutter URL terbaru beda dengan owner/repo audit lama | P0 | Verify remote origin/HEAD; never silently use older repo |
| R02 | Existing ticket finalization issues QR immediately after paid | P0 | Separate ticket entitlement vs QR issuance; H-6/H-5/H-4 tests |
| R03 | VA and QRIS both payable after switching | P0 | One active/unknown attempt, verified cancellation or wait terminal |
| R04 | 30m countdown conflicts with booking cutoff/visit start | P0 | min effective expiry; reject unsupported short windows |
| R05 | Late capture/duplicate callback confirms expired booking | P0 | transaction locks, eligible window, needs_review, dedupe |
| R06 | QRIS not implemented/activated (runbook checkpoint) | P0 | adapter+real merchant UAT prior to enable |
| R07 | BNI VA status inquiry terminal/reversal not confirmed | P0 | no fabricated status, official fixtures & sandbox evidence |
| R08 | Email success/ticket double-delivered | P1 | durable outbox, unique event keys, retry semantics |
| R09 | QR per visitor absent in schema | P1 | audit and additive model migration only if required |
| R10 | DOKU refund unsupported for BNI / merchant QRIS | P1 | manual_action_required audited fallback; never fake refund |
| R11 | Fee admin includes unlawful QRIS MDR surcharge | P0 | enforce BI prohibition, transparent permitted fees only |
| R12 | Old PRD still demands wallets/multi-bank as launch requirements | P1 | supersede scope; keep future bank adapter extensibility |
| R13 | Demo/sandbox/production provenance conflated | P0 | immutable env, separate deployments/DB, demo isolation |
| R14 | Flutter app restart creates second provider transaction | P0 | persisted intent & safe recovery; no create on resume |
| R15 | Ticket QR mistaken for payment QR in UI or email | P1 | separate fields/routes/screens, QR scopes and tests |
| R16 | Payment settings/notification URL merchant unavailable | P0 (go-live) | DOKU support/config, stable HTTPS, signed callback live evidence |
| R17 | Existing migrations/index/locks incompatible with new channel | P0 | schema inventory, dry-run, concurrency test, additive only |

## 18. Acceptance Sign-Off & Change Control

**Spec-approved by user's conversation:** booking approved first; BNI VA/QRIS only on launch; two section radio; generate on CTA; 30-min target expiry; QR save/share; safe payment method switch; auto+manual status; verify expiry before final; resume via history/home reminder; payment success detail + receipt; pending/failed UI inline; legitimate optional admin fee; user request/admin-approved provider refund if supported; payment email immediate; H-5 QR unique per visitor; separate ticket email; day-H booking if allowed.

**Needs verification, not new business questions:** exact existing route+model names, local H-5 scheduler time, current check-in policy, cost/legal terms, DB constraints, status query mapping, refund channel entitlement, merchant activation, source remote identity and latest SHA.

**Ready:** Design PRD is implementable *after* M0. **Not ready:** claim of completed repo audit, code complete, actual Sandbox/Production UAT, or launch. Codex should produce M0 report and plan first. Change scope only with explicit user approval, not based on old PRD or agent assumptions.

## 19. References and Provenance

### Project documents (internal historical references)
- `DOKU-RUNBOOK.md` — 8 Nov 2026 BNI integration and test evidence, actual DOKU tests NOT RUN.
- `DOKU-implementation-plan.md` — existing BNI implementation scope.
- `PRD_DOKU_Direct_API_Wisata_Daerahku_v1.0.md` — historical source audit (Flutter owner mismatch).
- `PRD_DOKU_Multichannel_Backend_v2.0.md` — prior multi-channel design; **channel scope superseded**.

### Primary documentation
- DOKU SNAP QRIS: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/qris
- DOKU BNI VA: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/bni-virtual-account
- DOKU VA billing types: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account
- Bank Indonesia MDR QRIS (13 Oct 2026): https://www.bi.go.id/id/publikasi/ruang-media/cerita-bi/Pages/mdr-QRIS.aspx
- Superpowers Codex skills: https://github.com/obra/superpowers

**END OF PRD — do not infer that any implementation, live payment or local Codex agent execution has occurred from creation of this file.**
