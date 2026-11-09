# Audit DOKU Payment dan E-Ticket — 8 November 2026

Status: discovery source selesai untuk dua checkout yang diarahkan user; implementasi dan merchant UAT belum selesai. Scope rilis: BNI VA DGPC fixed bill dan QRIS. Dokumen ini tidak mengizinkan perubahan kode, migrasi aplikasi, aktivasi channel, atau go-live. Audit dimulai 8 November; pemeriksaan handoff dilanjutkan melewati tengah malam ke 9 November 2026, Asia/Jakarta, dengan HEAD yang sama.

Acuan produk: `docs/prd/PRD_Wisata_Daerahku_DOKU_Payment_Eticket_v3.0.md`, SHA-256 `B5DC354FCCEA8F80B066DFC1AE8AB30236F35981B3E9457906011BC9AD00F1AF`. File PRD sudah untracked sebelum audit dan tidak diubah.

## 1. Checkout dan provenance

| Item | Laravel / BE | Flutter / FE |
| --- | --- | --- |
| Path aktual | `C:\laragon\www\pami-wisata-daerahku-api` | `C:\laragon\www\pami-mobile\pami-wisata-daearahku` |
| Expected remote | `https://github.com/joytimess/pami-wisata-daerahku-api` | `https://github.com/joytimess/pami-wisata-daerahku` |
| Actual origin fetch dan push | `https://github.com/joytimess/pami-wisata-daerahku-api.git` | `https://github.com/koyotot/pami-wisata-daearahku.git` |
| Branch | `main` | `main` |
| HEAD | `de3cbf8a2989970f20587b89fc9b9ef017aa546f` | `c75bd7b7d8e4f5d14b45b3ea9df6367607b7f38b` |
| Status awal | Hanya PRD v3 untracked; tracked clean | Clean, termasuk untracked |
| Git dir / common dir | `.git` / `.git`, checkout biasa | `.git` / `.git`, checkout biasa |
| Tags lokal | Tidak ada output | Tidak ada output |

**verified-code — provenance mismatch.** Flutter awalnya `BLOCKED: source provenance`. Setelah mismatch disampaikan, user mengarahkan secara eksplisit ke path aktual di atas dan memberikan akses. Audit diteruskan pada checkout tersebut. Arahan ini mengizinkan inspeksi checkout; tidak membuktikan repo itu identik dengan `joytimess`, dan tidak mengubah remote. Jangan push hasil ke repository lain berdasarkan dugaan equivalence.

Tidak ada fetch, clone, stash, reset, commit, push, perubahan `.env`, atau worktree baru. Tidak ada file sumber Flutter/Laravel diubah. Log baseline lokal berada di `storage/logs/testing/doku-v3-audit/` dan diabaikan Git.

## 2. Aturan, tool, agent, dan skill

**verified-code.** BE mempunyai `AGENTS.md`. Tidak ditemukan `AGENTS.md` di root/berkas hasil pencarian FE atau parent `C:\laragon\www` dan `pami-mobile`. Instruksi global yang diberikan user tetap berlaku. `.ai/rules` tidak ada pada kedua checkout, sehingga tidak ada index/path rule tambahan untuk dibaca atau dicari. BE `.agents/skills` berisi `infer-conventions`, `laravel-best-practices`, `tailwindcss-development`, `testing-best-practices`.

**verified-code.** Definisi agent berada di `C:\Users\itsba\.codex\agents\`: `master_orchestrator.toml`, `system_architect.toml`, `backend_engineer.toml`, `security_auditor.toml`, `qa_engineer.toml` dibaca sebelum dispatch. Agent tambahan yang benar-benar ditemukan: `flutter_engineer`, `frontend_engineer`, `devops_engineer`, `figcraft_designer`, `figcraft_critic`, `vrchat-unity-developer`. Nama tambahan itu tidak dipakai untuk membuat tim baru. `C:\Users\itsba\.codex\config.toml` dibaca secara selektif; blok features mencantumkan memories, tanpa `multi_agent=true` eksplisit. Tool runtime `collaboration.spawn_agent` tersedia dan tiga pemanggilan berhasil; runtime adalah bukti availability, bukan asumsi dari config.

Workflow yang benar-benar dijalankan: root mengaudit Flutter, menjalankan baseline, menggabungkan temuan dan menulis dua dokumen. Tiga subagent paralel, semuanya read-only dan tanpa child agent:

| Task runtime | Agent | Ownership inspeksi |
| --- | --- | --- |
| `/root/domain_audit` | `system_architect` | Booking, quota, H-5, dokumen, email, schema dan locks |
| `/root/provider_audit` | `backend_engineer` | Provider adapters, API, expiry, readiness, legacy evidence |
| `/root/security_audit` | `security_auditor` | Signature, authorization, replay, logging, uncertainty, adjustments |

QA ditangani root; tidak ada subagent keempat. Tidak ada ownership write aplikasi yang diberikan.

Kedua root skill nyata tersedia: `C:\Users\itsba\.codex\skills\superpowers\skills\` dan `C:\Users\itsba\.agents\skills\superpowers\skills\`. Kedua checkout Superpowers melaporkan commit `22f0b463e7a5e579904dd6211a520321035526f7`. Setiap nama berikut mempunyai `<root>\<nama>\SKILL.md`; metadata seluruhnya diperiksa. Isi skill dibaca sebelum dipakai, memakai root `.agents`.

| Skill terpasang pada kedua root | Penggunaan pass ini |
| --- | --- |
| `using-superpowers` | Dipakai; termasuk reference Codex tools |
| `brainstorming` | Dipakai untuk validasi PRD approved terhadap source; tanpa interview ulang |
| `dispatching-parallel-agents` | Dipakai untuk tiga inspeksi independen |
| `using-git-worktrees` | Prosedur dibaca; pembuatan ditunda sampai eksekusi kode diizinkan |
| `writing-plans` | Dipakai sesudah gap report |
| `verification-before-completion` | Dipakai untuk memeriksa bukti command dan diff |
| `systematic-debugging` | Dibaca saat analyzer menemukan baseline issue; tidak ada fix |
| `test-driven-development` | Terpasang, tersedia untuk implementasi; RED/GREEN belum dijalankan karena tidak ada kode baru |
| `subagent-driven-development` / `executing-plans` | Keduanya terpasang; mode eksekusi belum dijalankan |
| `requesting-code-review` / `receiving-code-review` | Terpasang; belum ada code-change review yang diklaim |
| `finishing-a-development-branch` | Terpasang; belum berlaku karena belum ada development branch/change yang selesai |

Skill pendukung yang dibaca/dipakai: BE `laravel-best-practices`, `testing-best-practices` beserta isolation rule; Ponytail full dari plugin lokal; `humanizer` dari `.agents/skills`. Tidak memasang skill/dependency/config baru. Skill implementation yang deferred harus dibaca penuh kembali sebelum dijalankan.

## 3. Arsitektur source aktual

BE memakai PHP **8.3.36**, Laravel **13.39.0**, Pest **4.7.8**, Pint **1.32.1**, DomPDF **3.1.2**, BaconQrCode **3.1.1** (`composer show --direct`). Model booking/payment/ticket domain diakses lewat query builder, bukan class Eloquent `Payment`/`Booking` yang diasumsikan. `app/Models/User.php`, `Destination.php`, `DestinationQuota.php` adalah bagian model yang benar-benar ada; ledger dan ticket units didefinisikan migrations.

```text
FE BuktiPendaftaranScreen / BerandaUserScreen
  -> BookingPaymentSection -> EwalletPaymentSection (current DANA/ShopeePay UI)
  -> BusinessRepository -> ApiClient -> AuthSession -> BE /api/v1
BE auth/request -> PaymentService (read) / DokuPaymentService (orchestrate)
  -> PaymentGateway -> DokuChannelRegistry -> DokuClient / DokuQrisAdapter
  -> payment_intents / payment_attempts / payment_events
VA signed webhook -> durable event -> ProcessDokuPaymentEvent -> verified capture
  -> booking confirmed + notification outbox
  -> tickets:issue-qr / GET tickets -> TicketService H-5 -> per-admission QR
notifications:dispatch -> DeliverBookingNotification (generic mail)
documents:dispatch -> BookingDocumentService (legacy invoice + group ticket PDF)
payments:reconcile-doku -> ReconcileDokuPayment (inquiry, no new issuance)
```

FE source map: `lib/services/api_client.dart:14` dan `lib/services/auth_session.dart:507` menyediakan JSON/authenticated binary; `lib/data/business_repository.dart:378` menyediakan methods/intent, `:387` create **ewallet saja**, `:396` refresh, `:471` tickets, `:506` dokumen. Navigasi memakai `lib/main.dart:173`, `lib/screens/main_navigation_screen.dart`, `lib/screens/bukti_pendaftaran_screen.dart:1317`, dan `lib/screens/beranda_user_screen.dart:188`. Tidak ada router/checkout BNI-QRIS yang sudah memenuhi v3.

FE design source yang ditemukan: `lib/theme/app_colors.dart`, `lib/theme/app_icons.dart`, screen shell/detail yang ada. Tidak ditemukan token file terpusat untuk seluruh spacing/radius atau house Pressable dalam pencarian terbatas. Implementasi UI nanti harus menginventarisasi reuse lebih lanjut dan memakai skala konsisten 4px bila belum ada; audit ini tidak menyetujui redesign screen existing. `pubspec.yaml` memasang `flutter_svg`, `flutter_secure_storage`, `url_launcher`; belum ada dependency QR rendering/export/share. `MainActivity.kt:15` saat ini hanya mempunyai MethodChannel maps, bukan QR export.

## 4. Requirement-to-source dan gap

Semua path BE relatif terhadap root backend, FE terhadap root Flutter di §1. `verified-code` berarti terbukti dari source pada HEAD ini, bukan jaminan runtime atau provider. `documented-historical` berarti catatan lama. `hypothesis` perlu pengujian tambahan. `provider-blocked` memerlukan kontrak/entitlement/evidence merchant.

| ID / prioritas | PRD | Evidence dan hasil |
| --- | --- | --- |
| G01 P0, verified-code | §1,5,10 server amount/approval/owner | `app/Services/BookingService.php:85`, `PaymentService.php:14`, `DokuPaymentService.php:178`, `app/Http/Requests/DokuPaymentRequest.php:31`. Snapshot integer harga/qty/total/currency tersedia; owner role-0 aktif, approval dan window dicek. Amount/currency/provider/environment/status/paid_at ditolak. `merchant_id`, `expiry`, invoice/fee override lain diabaikan `validated()` tetapi belum **ditolak eksplisit** seperti invariant v3. |
| G02 P0, verified-code | §1,5 satu invoice | `database/migrations/2026_10_30_010000_create_document_tables.php:13`, `app/Services/BookingDocumentService.php:20`, `app/Console/Commands/DispatchBookingDocuments.php:17`. Unique invoice per booking sudah ada, tetapi baru dibuat setelah confirmed. Belum ada invoice number saat awaiting_payment/CTA. Reuse tabel invoice yang sama dan snapshot booking; jangan bikin ledger kedua. |
| G03 reuse, verified-code | §5 harga/fee | Booking snapshot menyimpan unit price, quantity, total dan IDR (`BookingService.php:85`). Belum ada fee breakdown. Tidak ditemukan penambahan QRIS MDR ke bill. Untuk rollout tanpa fee baru, subtotal=total dan service fee=0 dari server; biaya baru memerlukan dasar kontrak/legal. |
| G04 reuse, verified-code | §5,11 quota/locks | `BookingService.php:62`, `app/Services/QuotaService.php:128`, `app/Support/BookingRules.php:12`. Quota lock menserialisasi kapasitas; approval/payment_verification/payment_review tetap memegang quota. Jangan release hanya karena timer nol. |
| G05 policy boundary, verified-code | §5,6 hari H bersyarat | `BookingRules.php:16-30`, `BookingService.php:73,258`, `QuotaService.php:124`. Create harus >25h15m sebelum midnight lokal tanggal visit; approval >75m; payment sebelum midnight. Hari-H booking/pay saat ini **tidak eligible**. PRD mengizinkan hari H hanya bila existing cutoff mengizinkan; pertahankan policy, jangan menganggap ini izin melonggarkannya. Check-in hari H untuk booking sebelumnya tetap valid. |
| G06 P0, verified-code | §5 30m target expiry | `BookingService.php:267` memberi booking deadline +1h dan grace +15m. `DokuPaymentService.php:92` mewariskan deadline intent ke attempt. Belum ada min(create+30m, booking deadline, visit cutoff, provider expiry) atau minimum provider window. Intent deadline harus tetap sama booking deadline; expiry attempt adalah field terpisah. |
| G07 reuse, verified-code | §11 satu open/unknown attempt | `DokuPaymentService.php:33-107`, migration payment `2026_11_05_040716_create_payment_tables.php:13,50`. Parent quota→booking→intent lock; reserve durable sebelum HTTP. Open attempt dikembalikan meski key/channel berbeda. Timeout tetap unknown, tidak di-create ulang. DB belum memiliki unique filtered-active constraint; correctness bergantung shared orchestration. |
| G08 P0, provider-blocked | §9,11 recovery terminal BNI | `app/Services/Doku/DokuClient.php:196-223`: inquiry perlu VA yang tersimpan dan hanya mengenali pending. Terminal menjadi `provider_terminal_contract_unverified`. Timeout sebelum VA diketahui tidak dapat dicari dengan jalur ini. Wajib investigated/replayed verified evidence; jangan auto-reissue. |
| G09 reuse, verified-code | §9 BNI closed bill | `DokuClient.php:131-169`: DGPC, `virtualAccountTrxType=C`, nonreusable, exact `.00` IDR snapshot, response reference/amount/expiry validation. Source dan fixtures tersedia; real merchant E2E belum dijalankan. |
| G10 P0, verified-code + provider-blocked | §9 QRIS | `app/Services/Doku/DokuQrisAdapter.php:19-62` sudah generate dengan amount fixed. Registry `DokuChannelRegistry.php:29,89` menetapkan `settles=false`/409 `channel_contract_unverified`. `DokuClient.php:186` belum query terminal. Tidak ada webhook QRIS/query/expire/refund transport. Generate-only berbeda dari settlement lengkap. |
| G11 P0, verified-code | §9,14 readiness | `DokuChannelRegistry.php:25,82-98`: BNI grandfathered melewati verified-channel list. Gate hanya validasi lokal; bukan bukti merchant aktif, callback registered, worker sehat, signed E2E. BNI dan QRIS sama-sama perlu evidence merchant/environment sebelum issuance untuk rollout v3. |
| G12 P0, verified-code | §1,9 launch scope | Registry/payment request dan FE masih mencakup multibank/DANA/ShopeePay (`DokuChannelRegistry.php:25`, `PaymentGateway.php:39`, FE `payment_models.dart:62`, `booking_payment_section.dart:159`). Itu existing source, bukan scope rilis baru. Batasi **new issuance** v3 ke BNI/QRIS; pertahankan penanganan in-flight legacy dan compatibility. Jangan menambah/mengaktifkan adapter lain atau menghapus history. |
| G13 reuse, verified-code | §11 signature/replay/durable ACK | `DokuClient.php:232-272`, `app/Http/Controllers/Api/V1/DokuWebhookController.php:18`, `DokuPaymentService.php:249-270`. Merchant/channel/ref, HMAC minified JSON, timestamp ±300s, size/depth, semantic unique event, event durable sebelum dispatch/ACK tersedia. |
| G14 reuse, verified-code | §11 capture/late/mismatch | `DokuPaymentService.php:283-389`. Provider/env/amount/currency/time/current attempt dicek; duplicate tidak confirm dua kali. Event tepat waktu dalam grace dapat apply; late/superseded/mismatch masuk review. Capture tepat timestamp expiry tidak apply karena batas strict `<`. |
| G15 reuse + gap, verified-code | §4,10 manual/polling | GET intent read-only (`PaymentService.php:60`). Inquiry existing **POST** attempt refresh (`routes/api.php:43`, `DokuPaymentService.php:398`), minimum BNI/QRIS 10s, route throttle 20/min; scheduler setiap menit. Jangan mengganti route menjadi GET yang diam-diam memanggil provider. Reconcile open attempts belum memiliki batas umur eskalasi operasional. |
| G16 P0 UX contract, verified-code | §4,11 switching | `DokuPaymentService.php:63-66` mengembalikan attempt lama ketika masih open. Aman dari dua issuance, tetapi belum `can_switch`/reason/cancel API/verified cancellation. Local expiry bukan bukti provider terminal. |
| G17 reuse, verified-code | §6 H-6/H-5 | `app/Services/TicketService.php:25,73,146-167`, `BookingService.php:100`. Entitlement dibuat sebanyak quantity; QR belum dibuat sebelum H-5 lokal. Risiko PRD R02 berupa unconditional smart QR after-paid **tidak terbukti** pada HEAD. |
| G18 reuse, verified-code | §6 schedule/timezone | `routes/console.php:18`, `TicketService.php:163,206`, `config/app.php:68`: tiap menit, `withoutOverlapping`, app UTC, gate tanggal dari destination timezone snapshot. H-5 dimulai saat tanggal lokal berganti; tidak ada release-hour lain. Runtime worker/scheduler hosting belum diverifikasi. |
| G19 P0 acceptance gap, verified-code | §6 paid H-4 immediate via queue | `DokuPaymentService.php:373-379` belum enqueue ticket issuance khusus. Scheduler berikutnya atau GET tickets (`TicketService.php:106`) memanggil issue. Reuse issue idempotent dan tambah hook queue setelah capture eligible; H-6 tetap menunggu. |
| G20 reuse, verified-code | §6 unique entry QR/scan | `2026_11_03_130325_create_smart_ticket_tables.php:18,25`, `TicketService.php:81,113`, `app/Services/TicketCheckInService.php:45-145`: unique booking+sequence/hash, token random 32 byte `WDR1.*`, encrypted payload, private SVG owner, staff assignment/local visit date/single redemption. QRIS tidak cocok format entry scanner. Jangan menambah PII pengunjung wajib. |
| G21 P0 presentation conflict, verified-code | §6,7 legacy ticket | `BookingDocumentService.php:40-48,79-100` membuat **satu group-ticket PDF/token** segera confirmed; download tidak recheck H-5/payment. Scanner modern tidak memakai token ini, jadi ini bukan bukti bypass scanner. FE `bukti_pendaftaran_screen.dart:1378` masih menyebutnya “Tiket”. Rekonsiliasi label/access/artifact dengan per-visitor smart QR tanpa menghapus legacy records. |
| G22 P0 acceptance gap, verified-code | §7 receipt | `BookingDocumentService.php:32` menyebut invoice bukan bukti pembayaran; `routes/api.php:222` hanya invoice/ticket. Belum downloadable paid receipt dengan invoice/ref/method/paid_at/adjustment. Owner/admin scoped document access yang ada dapat dipakai ulang (`BookingDocumentService.php:66`). |
| G23 P0 acceptance gap, verified-code | §7 payment email | `DokuPaymentService.php:379`, `app/Services/BookingNotificationService.php:19-54`. Production doku_paid memakai generic notification; sandbox_paid/demo_paid melewati deliveries. Belum field receipt lengkap atau Sandbox signed-E2E email path yang diperlukan. Queue failure tidak boleh membatalkan paid. |
| G24 P0 acceptance gap, verified-code | §7 separate ticket email | `TicketService.php:73-96`, `BookingNotificationService.php:12-22`: belum event ticket-issued atau delivery terpisah setelah H-5. Outbox notification/delivery existing reusable dan memiliki unique business keys. |
| G25 P1, verified-code + hypothesis | §7 email delivery | `app/Jobs/DeliverBookingNotification.php:35-38,79-100`: email akun dipakai, bukan visitor_email; API role-0 memerlukan verified email (`AuthenticateApiToken.php:17`). Delivery belum recheck `email_verified_at` setelah perubahan akun. **hypothesis:** crash setelah mail diterima sebelum DB sent dapat menduplikasi transport; unique outbox sendiri bukan exactly-once email. Butuh delivery idempotency/evidence transport atau batas at-least-once yang disetujui. |
| G26 P0 acceptance gap, verified-code + provider-blocked | §12 refund request/review | Tidak ada refund route/request/domain table/admin workflow/manual_action_required. Owner cancel hanya pending_approval (`BookingService.php:208`). Provider refund/cancel/partial entitlement belum dibuktikan. Implement request/admin audit + truthful manual fallback; jangan mengklaim BNI auto-refund. |
| G27 P1, verified-code | §11,12 reversal/revocation | `DokuPaymentService.php:321-328,382`: normalized refund/reversal dapat revoke unused QR, menjaga history; VA normalizer selalu paid (`DokuClient.php:269`), paid attempts tidak direconcile. Tidak ada provider adjustment intake. `tests/Feature/DokuPaymentTest.php:211` hanya synthetic normalized input. Analytics partial receipt fixture (`FinancialAnalyticsTest.php:145`) bukan workflow partial refund. |
| G28 P1, verified-code | §13 logs | `app/Services/Doku/DokuExchangeSanitizer.php:24-25,52-89`, `DokuClient.php:398`, `config/logging.php:69`. Credential keys dikenal disamarkan, tetapi full JSON/provider prose serta VA/customer/contact field lain dapat tersimpan; JSON tidak dibatasi ukuran. Minimalkan ke bounded diagnostic allowlist. Tidak ada payload/log live dibuka atau kebocoran nyata diklaim. |
| G29 reuse, verified-code | §9 shutdown/env/DEMO | `DokuClient.php:183,234`, `PaymentGateway.php:9`, `DemoPaymentService.php:94`. Disable issuance tidak membuang callback/inquiry existing; environment immutable, DEMO gated local/testing/demo dan tidak settle ledger DOKU. TLS host tetap, redirects off, no create retry (`DokuClient.php:321-325`). |
| G30 P0, verified-code | §4 FE payment selection/instruction | FE `BookingPaymentSection:159` mount wallet section; `EwalletPaymentSection:91` hanya tombol DANA/ShopeePay. Tidak ada dua radio BNI/QRIS, VA copy, instruction checkout native, QRIS render/save/share, atau verified success+receipt screen. FE existing create body hanya method/channel, tanpa amount (`business_repository.dart:387`). |
| G31 reuse + gap, verified-code | §4 FE recovery/lifecycle | `ewallet_payment_state.dart:220,311,366,380,419`: read-before-write, secure per-user operation key, session generation, pointer, unknown no retry, bounded foreground polling 5s/3m. Ini wallet-specific; BNI/QRIS tidak mengadopsi pointer, dianggap otherMethod (`:158`). Reuse pola dan existing repo/session; jangan menampilkan wallet CTA untuk rilis v3. |
| G32 P0, verified-code | §4 FE countdown | `widgets/payment_deadline.dart:8,41` clock hanya display dan refresh saat nol; bagus. Caller `bukti_pendaftaran_screen.dart:968` memakai **booking** expiry. `PaymentSnapshot` belum getter attempt expiry. Harus bedakan booking deadline dari `last_attempt.expires_at`, bukan restart timer 30m ketika layar dibuka. |
| G33 reuse + gap, verified-code | §4 FE history/home/tickets | `beranda_user_screen.dart:18,188,407` memprioritaskan awaiting_payment dan membuka booking; history/detail sudah ada. `booking_ticket_qr_section.dart:179,219`, `ticket_models.dart:19,28` sudah per-ticket SVG, scheduled H-5, provenance dan semantics. Success wording/home “confirmed” perlu tidak menjanjikan QR sudah terbit; receipt/refund belum didukung. |
| G34 P0, verified-code | §4 states/API DTO | FE `payment_models.dart:5,62` membedakan intent vs attempt namun masih wallet-only capabilities; `ewallet_payment_state.dart:148` success menuntut booking confirmed+payment paid+intent paid+attempt paid. Pertahankan kesepakatan semua state itu. Tambah mapping BNI/QRIS/review/refund tanpa menyimpulkan success dari redirect/QR/timer. |
| G35 P0 verification, provider-blocked | §14,15 release | Tidak ada signed merchant Sandbox E2E pada pass ini, maupun Production/device E2E. Fixture callback, QR dummy, dashboard ACTIVE, atau command schedule:list tidak membuktikan callback registration/settlement/worker runtime. Aktivasi dan go-live adalah gate terpisah. |

## 5. Kontrak API dan migrasi

**verified-code.** Route list aktual menampilkan 96 routes API. Pertahankan `GET payments/methods`, `GET bookings/{booking}/payment-intent`, `POST .../payment-attempts`, `POST .../{attempt}/refresh`, `POST webhooks/doku/va`, scoped admin intent, documents dan smart tickets. Gunakan envelope `data` existing, `Cache-Control: private, no-store`, 404 owner isolation, 409 domain conflict, 422 validation, 429 rate limit. Webhook QRIS/refund/receipt baru harus additive setelah kontraknya disetujui.

| Perbedaan DTO actual | Implikasi kompatibilitas |
| --- | --- |
| Create `status`=attempt; `method_code`; `expires_at`=attempt | Jangan membaca sebagai intent atau paid proof |
| GET `status`=intent; `last_attempt.status/method/channel/expires_at`; top-level `expires_at`=booking | Countdown attempt harus memakai nested expiry; jangan mengubah arti top-level untuk old clients |
| QRIS instruction sekarang `presentation_type=qr_payload`, `qr_content` | Contoh PRD `qris`/`qr_payload` bukan actual contract; pertahankan old fields atau tambah alias explicit |
| Same-key create replay=encrypted original response | Follow dengan GET latest; replay pending bukan latest status |
| `methods` tiga family dan nested channels | Pertahankan schema legacy; v3 UI hanya BNI/QRIS. `can_pay && family.available && channel.available` wajib; nested available currently tidak diubah ketika family ineligible |
| Belum invoice number/paid_at/receipt/breakdown/can_switch | Tambahkan tanpa mengganti field lama; nullable/unavailable berbeda dari nol |

Schema penting: `2026_10_29_080000_create_booking_tables.php`, `2026_10_30_000000_create_notification_tables.php`, `2026_10_30_010000_create_document_tables.php`, `2026_11_03_130325_create_smart_ticket_tables.php`, `2026_11_04_152334_create_booking_approval_claim_tables.php`, `2026_11_05_040716_create_payment_tables.php`, `2026_11_08_034113_add_reconciliation_to_payment_attempts.php`. Preserve unique invoice booking, canonical intent booking, provider/environment references, composite applied-attempt FK, semantic events, ticket sequence/hash dan single check-in.

Intent enum actual: `requires_payment,processing,paid,expired,needs_review,cancelled`. Attempt enum: `initiating,pending,failed,expired,unknown,paid,reversed`. Jangan menulis `refund_pending`, `refunded` atau `cancelled` attempt sebelum schema/state-contract design; workflow refund dapat memiliki status sendiri sambil mempertahankan verified payment facts. Snapshot rupiah berada pada booking/intent; payment events menyimpan evidence. Tidak perlu migration ticket visitor domain baru.

Lock order source: approval destination→quota→booking→actor/claim; payment quota→booking→intent→attempt→event; issue quota→booking→ticket; check-in idempotency→destination→quota→booking→ticket→staff. Refund/check-in harus bertemu di parent quota/booking dan ticket locks. Provider HTTP dan PDF/image generation tidak boleh ditambahkan ke critical locks.

Migration strategy: additive nullable/reference fields atau tabel workflow hanya bila diperlukan; preserve historical paid records; bounded restartable backfill. Audit ini hanya menjalankan **pretend SQLite in-memory**. Command exit 0 bukan bukti SQL upgrade MySQL valid: pretend SQLite tidak benar-benar membuat tabel sebelumnya sehingga rekonstruksi composite FK mencetak SQL placeholder yang tidak executable. Actual tests berhasil membuat schema SQLite; MySQL upgrade/preserve-data verification masih NOT RUN. Jangan memakai down/rollback destruktif sebagai rollback production.

## 6. Baseline aktual dan batas bukti

Sebelum Artisan/tests, environment proses dipaksa: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=` kosong, `CACHE_STORE=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`. Baseline test juga `DOKU_ENABLED=false`, `DEMO_PAYMENTS_ENABLED=false`, diagnostic log khusus testing. Test provider mengaktifkan config fixture sendiri dan memakai mocks/HTTP fakes. `bootstrap/cache` tidak mempunyai cached config. Tidak membaca/menampilkan nilai `.env` atau credential.

| Check / command | Actual result |
| --- | --- |
| `composer show --direct`; `php --version` | PASS, versions pada §3; tanpa update/install |
| `php artisan test --compact tests/Feature/DokuClientTest.php tests/Feature/DokuChannelRegistryTest.php tests/Feature/DokuPaymentTest.php tests/Feature/DokuQrisTest.php tests/Feature/DokuVaBanksTest.php tests/Feature/PaymentFoundationTest.php tests/Feature/DemoPaymentTest.php tests/Feature/PaymentReturnFlowTest.php tests/Feature/BookingServiceTest.php tests/Feature/BookingApiTest.php tests/Feature/BookingDocumentsTest.php tests/Feature/BookingNotificationTest.php tests/Feature/SmartTicketSmokeTest.php --no-ansi` | **PASS exit 0: 201 tests, 1,227 assertions, 51,171ms**. Local `backend-tests.log`; VA-other-bank tests hanya existing regression, tidak memberi izin aktivasi |
| `php artisan route:list --path=api --except-vendor --no-ansi` | PASS exit 0, 96 routes, `routes.log` |
| `php artisan schedule:list --no-ansi` | PASS exit 0, tujuh command setiap menit, `schedule.log`; tidak mengeksekusi scheduler |
| `php artisan migrate --pretend --database=sqlite --no-interaction --no-ansi` | PASS command exit 0, SQLite `:memory:`, `migration-pretend.log`; limit SQL pada §5 |
| `php -l <file>` untuk seluruh PHP `app`, `routes`, `database/migrations`, `tests/Feature`, `tests/Mysql` via `rg --files` | PASS exit 0, **227 files / 0 failures**, `php-lint.log`; syntax check, bukan Pint/style audit |
| `flutter test --no-pub --reporter expanded test/ewallet_return_test.dart test/demo_payment_disabled_test.dart test/smart_ticket_test.dart test/home_dashboard_test.dart` | **PASS: `+37: All tests passed!`**, `flutter-tests.log`. Tool wait sempat diinterupsi user; proses terus berjalan dan final log dibaca setelah resume. Tidak menyatakan exit code yang tidak tertangkap |
| `flutter analyze --no-pub lib/data/payment_models.dart lib/data/ewallet_payment_state.dart lib/data/business_repository.dart lib/widgets/booking_payment_section.dart lib/widgets/ewallet_payment_section.dart lib/widgets/payment_deadline.dart lib/widgets/booking_ticket_qr_section.dart lib/screens/payment_return_screen.dart` | **FAIL exit 1**, satu info `unnecessary_import` di `lib/widgets/payment_deadline.dart:2`, `flutter-analyze.log`. Existing import `dart:ui` juga diekspor Material. Tidak diperbaiki pada audit |
| Pint / full Flutter analyze / full suites / builds | NOT RUN. Tidak ada PHP edit sehingga dirty formatter tidak relevan; read-only baseline tidak menjalankan formatter yang memodifikasi file |
| MySQL concurrency / MySQL upgrade dry run | NOT RUN pada pass ini. Existing helper membuat disposable schema lalu actual `migrate --force`; tidak dijalankan dalam scope migration-pretend-only ini. SQLite PASS bukan bukti row locking MySQL |
| Merchant BNI/QRIS Sandbox E2E, SMTP delivery, QR export/device, staging, Production, monitoring/load | NOT RUN; tidak ada transaksi provider atau klaim UAT |

Existing smart-ticket H-5 smoke test memalsukan `PaymentEligibility` (`tests/Feature/SmartTicketSmokeTest.php:24`), sehingga passing smoke bukan integrasi ledger→H-5→email. Test DOKU “real transport normalization” tetap memakai `Http::fake` (`DokuPaymentTest.php:387`). Existing wallet regression membuktikan current wallet safety, bukan UI BNI/QRIS baru.

## 7. Evidence historis dan referensi primer

**documented-historical.** `docs/prd/DOKU-RUNBOOK.md:29-40` mencatat 178/1,170 feature dan 2/30 MySQL pada checkpoint lama, dengan live NOT RUN. Append multichannel di runbook/`DOKU-implementation-plan.md:16` lebih baru daripada headline BNI-only; tabel endpoint runbook `:92` masih menyebut hanya BNI dapat aktif. `PRD_DOKU_Direct_API_Wisata_Daerahku_v1.0.md:68` memakai remote Flutter lama. `PRD_DOKU_Multichannel_Backend_v2.0.md:49` dan `PRD_FIX_DOKU_DANA_PROVIDER_UNAVAILABLE_v1.0.md` adalah historical scope, bukan instruksi membangun wallet pada rollout v3.

Dokumentasi primer dibuka pada pass ini; bukan merchant UAT:

- [DOKU BNI VA](https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/bni-virtual-account): DGPC dan path create v1.1 didokumentasikan. Ini tidak membuktikan merchant parameters/cancellation/terminal recovery akun user.
- [DOKU QRIS](https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/qris): generate, query, refund dan cancel didokumentasikan. Dukungan nominal/window, notification/status semantics dan entitlement merchant harus dibuktikan sebelum adapter settle/enable.
- [Bank Indonesia: MDR QRIS](https://www.bi.go.id/id/publikasi/ruang-media/cerita-bi/Pages/mdr-QRIS.aspx), 13 Oktober 2026: MDR ditanggung merchant dan tidak boleh dialihkan kepada konsumen. Fee layanan lain tetap memerlukan dasar legal/kontrak tersendiri.

**provider-blocked inputs yang diperlukan:** kontrak/sanitized real Sandbox evidence terminal BNI dan occurrence timestamp, recovery unknown create, QRIS signed notification/query mapping/expiry minimum/cancel race/refund entitlement, merchant activation per environment, registered permanent callback, serta durable worker/email runtime evidence. Simpan credential melalui secret store/lokal, bukan laporan/chat.

## 8. Urutan keputusan

Rencana terperinci ada di `docs/superpowers/plans/2026-11-08-doku-payment-eticket.md`: contract/invoice/30m/readiness terlebih dahulu, provider recovery dan QRIS, receipt/email/H-5, Flutter native checkout/export, lalu refund/adjustment dan verifikasi terpadu. Existing booking cutoff, quota, DEMO, scanner dan payment history dipertahankan. Perubahan kebijakan hari H tidak diasumsikan.

Gate selanjutnya adalah review rencana dan arahan eksplisit implementasi. Source mismatch Flutter sudah diakui user untuk audit; identitas remote tujuan integrasi/Git tetap harus disebut ketika nanti diminta push/PR. Tidak ada go-live approval pada pass ini.
