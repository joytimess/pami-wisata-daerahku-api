# PRODUCT REQUIREMENTS DOCUMENT (PRD) — DOKU Multi-Channel Direct API

**Project:** PAMI Wisata Daerahku  
**Version:** 2.0 — Revised mandatory multi-channel scope  
**Date:** 2026-11-08  
**Status:** Approved scope baseline / implementation in progress (see 'Implementation status' under section 14)  
**Primary repository:** https://github.com/joytimess/pami-wisata-daerahku-api  
**Consumer:** https://github.com/koyotot/pami-wisata-daearahku  
**Owner intent:** QRIS + Virtual Account multi-bank + e-wallet are **required deliverables**, not optional post-MVP work.  
**Document format:** Markdown only.

> **Supersedes channel priorities of PRD v1.0, not the safety requirements.** PRD v1.0 began with BNI VA and deferred other channels. That prioritization did not match the intended complete DOKU integration. This v2.0 makes all three payment families mandatory for the backend implementation, while activation remains conditional on the real DOKU merchant capabilities and documented/tested contracts.

## 1. Executive decision

Build **one Laravel payment orchestration engine** with three **channel families** and a **server-controlled environment switch**:

- **QRIS (dynamic merchant-presented):** create, display payload, query, expire/cancel when supported, refund only against verified contract.
- **Virtual Account (multi-bank):** BCA, BNI, BRI, Mandiri as minimum named banks, with an extensible registry for additional supported banks (e.g. Permata); dynamic, **fixed-bill** VA for booking tickets.
- **E-wallet:** DANA, ShopeePay, and OVO. DANA/ShopeePay use provider redirect for user authorization; OVO account-binding/authorization is a distinct flow. No recurring charge is in initial product scope.

The public Flutter-facing API must stay unified, even if the channel-specific SNAP endpoints, tokens, signature, notification shapes, and supporting data differ. **Do not switch to hosted DOKU Checkout** without an explicit decision; native Flutter checkout remains a product requirement.

**Environment behavior:** `DOKU_ENVIRONMENT=sandbox|production` selects provider endpoints and credentials. Separate deployments carry their own secrets; transaction rows preserve immutable provider environment. Changing `.env` does not retroactively convert existing attempts to another environment. **Do not allow Flutter to choose the provider environment.**

## 2. Evidence and current checkpoint

The following facts are from the provided `DOKU-RUNBOOK.md` (8 November 2026) and prior implementation plan, **not an independent live test**:

| Area | Confirmed in runbook | Not confirmed |
| --- | --- | --- |
| Backend BNI SNAP v1.1 DGPC | Adapter, token/signature, encrypted instructions | Real merchant Sandbox VA issue and capture |
| Payment domain | `payment_intents`, `payment_attempts`, payment event persistence, durable verified webhook, replay | Multi-channel provider mapping |
| Integrity | Idempotency, booking/ticket finalization, MySQL concurrency tests | Cross-channel live race/UAT |
| Reconciliation | Worker/scheduler/backoff implemented | Terminal inquiry mapping; provider reversal/refund parser |
| Tests | 178 feature tests / 1,170 assertions; 2 MySQL concurrency / 30 assertions; Pint/route/scheduler/migration pretend PASS | DOKU live calls, Flutter device/emulator, monitoring/load |
| Ops | Laravel local port 8000, Android emulator `adb reverse`, temporary `localhost.run` tunnel demonstrated | Stable Production URL, dashboard Notification URL configuration |
| Credentials | Sandbox client ID/secret available; RSA merchant pair generated and public key registered (user report) | BNI BIN/Sub-BIN, production merchant activation |
| Dashboard | VA methods ACTIVE under Account/Service (user report) | VA SNAP and VA configuration pages remain blank |

**Checkpoint:** User reports a Git checkpoint already created. This PRD shall never overwrite the checkpoint or imply PRD code has been committed.

**Known caveat:** BNI configuration is *one VA adapter only*, **not** a global prerequisite for QRIS, e-wallet, or every bank VA. For BNI itself, required merchant parameters cannot be guessed or silently omitted.

## 3. Goals, non-goals and release definitions

### Goals

1. Flutter users can select and complete payment via **QRIS, a supported bank VA, or a supported e-wallet**, with results confirmed by the Laravel backend rather than client claims.
2. All enabled channels share booking eligibility, amount calculation, intent/attempt tracking, idempotency, immutable environment, webhook durable ingestion, event application, audit trail, and ticket rules.
3. Each channel has a **tested provider-specific transport + notification verifier + normalizer + inquiry/reversal strategy**.
4. Sandbox→Production uses the **same code and payment domain logic** with server-side config and independent keys/onboarding.
5. Provider or channel failures are fail-closed and visible to users/admin without prematurely confirming booking or losing already-issued transactions.

### Non-goals

- No client-side credential storage or direct Flutter merchant API requests.
- No DOKU hosted checkout replacing native checkout.
- No automatic Production activation, cash settlement reconciliation, or transfers/disbursement scope without verified requirements.
- No assumption that all bank services or QRIS/e-wallet are enabled on the merchant just because the dashboard service list says ACTIVE.
- No manual admin “force paid” endpoint.
- No fabricated QR/VA/provider redirect or invented successful payment for unavailable channels.

### Three completion states (not interchangeable)

1. **Code complete:** all required adapters, tests, normalized API, webhooks, and documentation are implemented; disabled channel contracts are clearly recorded where external evidence is missing.
2. **Sandbox UAT complete:** a real Sandbox end-to-end transaction is demonstrated for every channel the merchant can test. Unsupported/merchant-blocked tests are marked **blocked**, not PASS.
3. **Go-live ready:** stable HTTPS, production keys/channel activation, monitoring, tested callback/reconciliation, and signed-off rollout. A green local fixture suite alone is insufficient.

## 4. Channel matrix and real provider prerequisites

| Code | Family | User presentation | Protocol target | Provider-specific dependencies | Required backend delivery |
| --- | --- | --- | --- | --- | --- |
| `qris` | QRIS | `qr_payload` | SNAP QR MPM | Verified merchant ID, terminal ID, channel activation, callback/query contract | Generate, query, expire/cancel if supported, verified paid outcome, tests |
| `va_bca` | VA | `virtual_account` | BI-SNAP VA DGPC | Channel-specific bank/merchant parameters and activation | Create fixed-bill VA, display, status, notification, inquiry, tests |
| `va_bni` | VA | `virtual_account` | Existing BNI SNAP DGPC | BNI BIN/Sub-BIN as required by existing adapter | Preserve/refactor without regression; no universal BNI dependency |
| `va_bri` | VA | `virtual_account` | BI-SNAP VA DGPC | Bank-specific parameters/activation | Same complete VA lifecycle and tests |
| `va_mandiri` | VA | `virtual_account` | BI-SNAP VA DGPC | Bank-specific parameters/activation | Same complete VA lifecycle and tests |
| `va_permata` | VA | `virtual_account` | BI-SNAP VA DGPC (conditional) | Confirm channel contract/merchant entitlement | Extensibility target, enable after verified |
| `ewallet_dana` | E-wallet | `ewallet_redirect` | SNAP direct debit one-time | Channel configuration and redirect/callback contract | Create authorization, validate return, verify notification, status tests |
| `ewallet_shopeepay` | E-wallet | `ewallet_redirect` | SNAP direct debit one-time | Channel configuration and redirect/callback contract | Same; do not treat redirect as paid |
| `ewallet_ovo` | E-wallet | `ewallet_redirect` after binding | SNAP account binding + one-time payment | Binding/auth state, scoped token storage, callback | Binding lifecycle, one-time payment, unbind/error handling, tests |

**Channel availability rule:** Display a method as payable only when (1) code adapter is implemented and tested; (2) environment config valid; (3) merchant/channel capability verified; (4) booking eligible; and (5) required callback/status handling operational. Each method may be disabled independently. `DOKU_CHANNELS` is an allowlist, **not proof of provider activation**.

**Contract evidence:** Official DOKU docs cover QRIS SNAP generate/query/refund/cancel, VA SNAP DGPC bank endpoints, DANA/ShopeePay host-to-host redirect, and OVO account binding. Do not copy sample merchant IDs, BINs, terminal IDs, tokens, or sample statuses as live merchant configuration.

## 5. Target architecture

```text
Flutter booking checkout
   |
   | JWT + idempotency key; server sets amount and environment
   v
Laravel PaymentController -> PaymentOrchestrator / PaymentEligibility
   |   |-- persisted intent, attempts, events, idempotency records
   |   |-- booking/stock/approval/ticket transaction rules
   |
   v
DokuProviderFacade
   |-- DokuEnvironmentConfig (sandbox|production, strict fail-closed)
   |-- SnapAuth/Token/Signature shared primitives where protocol compatible
   |-- ChannelRegistry + capability/merchant activation gates
   |-- QRIS adapter
   |-- VA adapters: BCA, BNI, BRI, Mandiri, optional others
   |-- E-wallet adapters: DANA, ShopeePay, OVO binding
   |
   +--> DOKU API (outside DB transaction)

DOKU callbacks -> channel-specific signed webhook endpoints
   -> verify raw body/signature/reference/environment
   -> persist verified event DURABLY before ACK
   -> queue apply job with locks/idempotency
   -> update attempt/intent; booking confirmed only if eligible
   -> issue ticket once according to existing rules

Scheduled worker -> replay durable events + inquiry with bounded retries
Admin investigation -> read-only, redacted metadata and reconciliation status
```

### Separation of responsibility

- Orchestrator owns money/booking semantics; adapters never directly confirm booking.
- Transport owns external HTTP and signature; channel adapter owns DOKU payload shape, response and notification mapping.
- Verification chooses protocol from a **trusted, configured route/channel**, never from untrusted body alone.
- Normalizer produces immutable channel-neutral snapshots; channel-specific instruction data is encrypted at rest and redacted from logs.
- Keep current DEMO service isolated; it cannot simulate or settle a `provider=doku` attempt.
- Prefer additive migrations and preserving existing BNI data. No destructive rollback against live payment records.

## 6. Domain invariants and concurrency

1. Only booking owner (or current authorized user policy) may initiate/read customer payment; admin investigation has separate read-only authorization.
2. Laravel determines authoritative booking, payment amount, IDR currency, valid booking window, expiry, and channel eligibility.
3. Exactly one canonical payment intent per due booking; multiple attempts only when prior attempt is terminal/safely resolved per existing domain rules.
4. One active or uncertain provider attempt per booking; overlapping channels must not issue parallel payment demands that might both capture.
5. Payment creation idempotency key + unique merchant reference + atomic reservation; outbound HTTP is **outside** long DB locks.
6. HTTP timeout after sending create is **unknown**, not automatically failed; never recreate blindly without verified absence of earlier provider transaction.
7. A paid booking requires verified provider capture, exact amount/currency/merchant reference, matching channel/environment, event time validity and valid stock/booking invariants.
8. Duplicate/out-of-order callbacks and retries cannot duplicate booking history, revenue or ticket issuance.
9. Late/superseded/mismatched captures become `needs_review`/`unapplied`, not automatically confirmed or counted as settled revenue.
10. A provider redirect/return URL or QR generation is **not** paid evidence.
11. Event `paid` must not transition back to pending/unknown; refund/reversal is a distinct adjustment affecting ticket eligibility and reporting.
12. Existing issued attempts must continue to receive verified webhooks/reconciliation when issuance kill-switch is off.
13. Environment changes are deployment events, not mutation of existing attempts. Prefer separate DB/deployments for Sandbox vs Production; never flip an in-flight database to a different environment.
14. `test_only=true` Sandbox payments must never contribute to Production revenue.
15. Never expose provider keys, OTP, token, full raw webhook or sensitive account identifiers in customer/admin logs or API.

## 7. Functional requirements

| ID | Priority | Requirement | Acceptance check |
| --- | --- | --- | --- |
| FR-01 | Must | Strict multi-channel configuration and immutable environment | Sandbox vs Production credentials distinct; invalid config fail-closed |
| FR-02 | Must | Dynamic capability discovery | Only implemented + verified + eligible channels can pay; disabled reason stable |
| FR-03 | Must | Common create attempt endpoint | Same contract QRIS, VA, wallet; client amount/provider/environment rejected |
| FR-04 | Must | QRIS generate & expire | Valid payload and true expiry stored and returned only for active QRIS |
| FR-05 | Must | QRIS status & callback | Only authenticated provider outcome may set paid; query/reconcile supported |
| FR-06 | Must | BCA VA DGPC fixed bill | Bank-specific validation and verified end-to-end lifecycle |
| FR-07 | Must | BNI VA no regression | Existing BNI flow retained; missing BNI merchant config disables BNI only |
| FR-08 | Must | BRI VA DGPC fixed bill | Bank-specific validation and verified end-to-end lifecycle |
| FR-09 | Must | Mandiri VA DGPC fixed bill | Bank-specific validation and verified end-to-end lifecycle |
| FR-10 | Must | DANA one-time payment | Redirect + verified callback + status recovery |
| FR-11 | Must | ShopeePay one-time payment | Redirect + verified callback + status recovery |
| FR-12 | Must | OVO one-time via account binding | Secure bind/auth/payment/unbind flows, errors and expiry tested |
| FR-13 | Must | Durable event intake across channels | Verification before DB durable ACK; dupes only one application |
| FR-14 | Must | Channel-level inquiry/reconciliation | Known terminal states parsed; unknown escalates without fabricated outcome |
| FR-15 | Must | Existing booking/quota/ticket invariants | Only verified paid eligible attempts confirm/issue once |
| FR-16 | Must | Admin investigation | Role/assignment checked; redacted refs and statuses visible |
| FR-17 | Must | DEMO isolation | DEMO remains independently gated; cannot settle DOKU |
| FR-18 | Must | Secret hygiene and environment safeguards | `APP_DEBUG=false` public tunnel; no secret in source/Flutter/logs |
| FR-19 | Must | Flutter-native contract | QR/VA/redirect semantics and failure modes normalized |
| FR-20 | Must | Automated and channel UAT evidence | Signed fixture tests and Sandbox proof when merchant provides access |
| FR-21 | Must | Refund/reversal provider parsing | Only verified official shapes mapped; unknown adjustment quarantined |
| FR-22 | Should | Additional VA banks | Registry supports further banks without cloning payment domain logic |
| FR-23 | Should | Refund initiation API | Only after explicit business approval and verified refund contracts; no fake success |
| FR-24 | Should | Operational metrics/alarms | Event lag, reconciliation failures, unknown attempts, webhook rejection monitored |

## 8. Public Laravel API (versioned; reuse existing routes)

Base: `/api/v1`; customer endpoints use existing app JWT, ownership, throttling and `Cache-Control: private, no-store`. Paths below are target contracts; route names and controller wiring must be checked against the *current local repository* before editing.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/payments/methods?booking_guid={guid}` | Server-verified channel capabilities, codes, fee disclosure if verified, reason when unavailable |
| POST | `/bookings/{guid}/payment-attempts` | Initiate supported channel; `Idempotency-Key` required |
| GET | `/bookings/{guid}/payment-intent` | Canonical payment state and recoverable instruction for current attempt |
| GET | `/admin/bookings/{guid}/payment-intent` | Read-only investigation; existing policy applies |
| POST | `/webhooks/doku/va` | Existing signed VA webhook route; retain compatibility |
| POST | `/webhooks/doku/qris` | QRIS signed/authorized notification, once official contract confirmed |
| POST | `/webhooks/doku/ewallet` | Wallet notification, verifier selected from trusted channel configuration |
| POST | Existing DEMO endpoints | Keep separate, disabled in Production |

### Example create requests (illustrative internal contract)

```http
POST /api/v1/bookings/{guid}/payment-attempts
Authorization: Bearer <application-jwt>
Idempotency-Key: <unique-per-user-action>
Content-Type: application/json
```

```json
{"method_code":"qris","channel":"qris"}
```

```json
{"method_code":"virtual_account","channel":"bca"}
```

```json
{"method_code":"e_wallet","channel":"dana"}
```

**Do not accept** request body values for amount, currency, provider, environment, `paid`, redirect callback success, expiry override, or merchant credentials.

### Normalized response shape (illustrative)

```json
{
  "data": {
    "booking_guid": "<uuid>",
    "intent_guid": "<uuid>",
    "attempt_guid": "<uuid>",
    "provider": "doku",
    "environment": "sandbox",
    "test_only": true,
    "method_code": "qris",
    "channel": "qris",
    "status": "pending",
    "payment_status": "unpaid",
    "amount": 150000,
    "currency": "IDR",
    "expires_at": "2026-11-08T12:00:00+07:00",
    "instruction": {
      "presentation_type": "qr_payload",
      "qr_content": "<payload-from-provider>",
      "copyable": false
    }
  }
}
```

Other instructions:

- VA: `{"presentation_type":"virtual_account","bank_code":"bca","va_number":"...","copyable":true}`.
- E-wallet: `{"presentation_type":"ewallet_redirect","redirect_url":"<allowlisted-https-url>","requires_external_authorization":true}`; OVO may also require a prepayment binding step.
- Attempt initializing: `status=initiating`; `instruction=null`, query after processing.
- No provider result/unknown: `status=unknown` / `needs_review`, no fabricated user instructions.
- Invalid/disabled method: typed error `channel_unavailable` with no provider side effect.

**API stability:** Add fields instead of changing existing IDs/keys when extending BNI. Keep the DEMO and `GET payment-intent` behavior compatible. Client capabilities reflect *actual backend support*, not method names hardcoded in Flutter.

### Flutter contract acceptance

QRIS screen renders provider payload, countdown, recheck; VA screen shows bank/number/copy/instructions; wallets open approved redirect/deep link and refresh Laravel status on resume; no client-only “payment successful”. Flutter implementation/UAT remains a dependent workstream after backend responses stabilize, not evidence backend is go-live ready.

## 9. Provider adapters, signatures and webhooks

### SNAP transport

A common auth/token signer may be reused only when official protocol requirements are identical. Each channel must implement validated request body/header maps; do not assume BNI fields are universal. DOKU docs show shared Sandbox `https://api-sandbox.doku.com` and Production `https://api.doku.com`, but full endpoint path and shape remain channel-specific.

- **VA:** BI-SNAP `POST /virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va`; use bank-specific `additionalInfo`, closed amount and validated BIN/service fields where required. Keep BNI path intact as reference implementation.
- **QRIS:** `POST /snap-adapter/b2b/v1.0/qr/qr-mpm-generate`; separate query and refund/expire when verified. Merchant ID/terminal ID are **not** fabricated or copied from docs.
- **DANA/ShopeePay:** SNAP one-time host-to-host payment at `/direct-debit/core/v1/debit/payment-host-to-host`, with redirect URL. Validate URL destination allowlist and expiry.
- **OVO:** Account binding/tokenized first, one-time `SALE` payment and customer OTP/PIN provider flow. Never request/store wallet credentials/OTP in Laravel.

### Webhook ingress: fail-closed checklist

1. Accept only HTTPS POST and bounded body size with expected content type.
2. Choose verifier by trusted route + configured merchant/channel; verify signature against **raw request bytes**, strict timestamps and correct environment; use protocol-specific canonicalization.
3. Match merchant reference, provider reference, amount, currency, existing attempt, immutable channel/environment. Do not trust a claimed paid status before verification.
4. Record a deduplicated verified event transactionally before ACK; respond according to official channel ACK contract. Persist failed processing for replay; do not queue-only ACK.
5. Process replay idempotently under locks; reject state regression and unrelated payment attempts. Use separate adjustment outcomes for reversal/refund.
6. For unsupported or contradictory payloads, record safely (`unknown`/`needs_review`), alert and never issue tickets.

### Reconciliation

Keep existing `payments:reconcile-doku --limit=100` with backoff, leases and jitter. Add per-channel query mapping based on verified official responses. For attempts with unknown issuance and **no provider reference**, do not regenerate; escalate via merchant reference/request ID. Do not treat an unverified inquiry field (`paidAmount`) as capture evidence. Only mapped, authenticated terminal results may affect booking. Concurrency tests must cover webhook↔expiry, cross-channel retry, timeout↔new method, duplicate callback, provider terminal contradiction, refund↔ticket, and worker outage.

## 10. Persistence and migrations

Reuse existing `payment_intents`, `payment_attempts`, `payment_events`, `idempotency_records`, and additive reconciliation migration. Audit current field names and unique indexes before modifying anything.

Required semantics (add fields only if not already represented):

- Attempt: `provider=doku`, immutable `environment`, canonical `channel`, `method_code`, immutable amount/currency, booking/intent GUIDs, `merchant_reference`, provider reference, provider expiry, latest verified status, unknown/reconciliation reason, source event provenance.
- Instruction: channel-specific encrypted-at-rest blob/fields with controlled decryption for owner only; `qr_content`/VA/redirect are not logged.
- Events: verified raw semantic fingerprint or secure encrypted provenance; channel, environment, version, applied timestamp, adjustment metadata; deterministic unique constraint/dedupe.
- Channel config: allowlist and verified onboarding flags stored server-side, not trusting client. No global BNI BIN requirement.
- Attempts: unique appropriate merchant/provider identifiers scoped by environment and merchant; concurrency-safe single active/uncertain issuance.
- Booking/ticket: source attempt and verified event provenance, no multiple issuance or double counted financial records.

Migration workflow: **test `--pretend` first**, verify indexes/data preservation, backup deployment, run additive migration; do not reset user's database or run destructive down migration against payment history.

## 11. Environment configuration and security

**Existing `.env` remains compatible** for BNI. Recommended *target* additions use per-channel fields, resolved through `config/doku.php` and typed channel config; final names must be reconciled with local code, not blindly copy/pasted:

```dotenv
DOKU_ENABLED=false
DOKU_ENVIRONMENT=sandbox
DOKU_CLIENT_ID=
DOKU_SECRET_KEY=
DOKU_MERCHANT_PRIVATE_KEY_PATH=
DOKU_MERCHANT_PRIVATE_KEY_PASSPHRASE=
DOKU_CHANNELS=qris,va_bca,va_bni,va_bri,va_mandiri,ewallet_dana,ewallet_shopeepay,ewallet_ovo
DOKU_VERIFIED_CHANNELS=
DOKU_WEBHOOK_URL=
DOKU_PRODUCTION_APPROVED=false
DOKU_HTTP_TIMEOUT_SECONDS=15
DOKU_RECONCILIATION_ENABLED=true
DEMO_PAYMENTS_ENABLED=false
APP_DEBUG=false

# Only if configured for QRIS merchant (DO NOT invent values)
DOKU_QRIS_MERCHANT_ID=
DOKU_QRIS_TERMINAL_ID=

# Bank-specific merchant values; implement per actual official contract
DOKU_BNI_PARTNER_SERVICE_ID=
DOKU_BNI_CUSTOMER_PREFIX=
```

- The above `DOKU_CHANNELS` lists **intended development targets**, not safe switches to turn on today. New channels default unavailable until adapter + verified merchant config + tests + webhook all pass. `DOKU_VERIFIED_CHANNELS` is a proposed additional flag, not existing implementation.
- Do not place Production secrets on local development where not needed. A release moves **deployment config** and keys, not codepaths.
- A running payment attempt retains its original environment and verification context; if a deployment changes environments with unresolved attempts, block switch or isolate deployments/databases.
- `DOKU_ENABLED=false` stops **new issuance**, not verified callbacks or necessary reconciliation of already-issued attempts.
- Environment and keys cannot be supplied in HTTP body or query. Avoid dynamic endpoint override and SSRF.
- AES/RSA private key outside repo/public, ACL restricted; log no secrets; keep `APP_KEY` / previous keys for decryptability during rotation.
- `APP_DEBUG=false` for externally reachable tunnel, even with `APP_ENV=local`.

## 12. Local Sandbox and Production operations

Known working local architecture:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
adb reverse tcp:8000 tcp:8000
ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -R 80:127.0.0.1:8000 nokey@localhost.run
```

- Flutter emulator continues to use `http://127.0.0.1:8000/api/v1` through ADB reverse; does not use tunnel.
- Tunnel forwards external provider callbacks to local Laravel. Past sample domain `8d7df4ec01ab8f.lhr.life` was ephemeral; **never store it as permanently valid**.
- Webhook registration in DOKU dashboard is still **unverified** because VA SNAP/Non-SNAP settings pages were blank. Merchant `Account → Service: ACTIVE` does not replace Notification URL registration. Treat UAT as externally blocked if provider cannot deliver signed callbacks.
- Skip immediate support follow-up per user choice; do **not** skip verification or call blocked scenarios PASSED.
- Local worker: `php artisan queue:work --timeout=75`; scheduler: `php artisan schedule:work` with persistent queue/atomic cache. Ensure queue `retry_after > 75` and jobs table ready.
- Sandbox readiness: real create for each supported channel; provider simulator or officially supported test path; signed callback to tunnel; one booking+one ticket; duplicate callback; retry; restart recovery.
- Production readiness: separate HTTPS stable deployment and database/credentials, verified merchant activation and all channel notification URLs, migration backup, runbook, alerting, release smoke test, deliberate launch approval.

## 13. Test strategy and minimum matrix

TDD with contract-first fixtures from official DOKU documentation; **fixtures are not equivalent to merchant Sandbox proof**. Preserve all 178 feature/2 concurrency baseline tests, then add:

| Test ID | Channel(s) | Scenario | Expected |
| --- | --- | --- | --- |
| T01 | All | Invalid/unknown channel | 422/unavailable; zero provider request |
| T02 | All | Environment switch | Correct endpoint/keys; immutable existing attempts |
| T03 | All | Toggle OFF after issuance | No new attempts; old verified callback processes |
| T04 | All | Dual-click/new requests same key | One attempt and outbound call |
| T05 | All | Two different channels concurrently | Never two active/unknown payment demands |
| T06 | All | Create timeout | Unknown, no blind recreate |
| T07 | QRIS | Generate + signed paid | Real payload; verified booking confirmation once |
| T08 | QRIS | Expire/cancel | No stale QR instruction shown, no false paid |
| T09 | BCA | VA create/notify/query | Fixed bill and exact references verified |
| T10 | BNI | Existing regression | No test breakage; BIN absence disables BNI only |
| T11 | BRI | VA create/notify/query | Bank-specific shape verified |
| T12 | Mandiri | VA create/notify/query | Bank-specific shape verified |
| T13 | DANA | Redirect/return/callback | Return does not confirm booking; verified notification does |
| T14 | ShopeePay | Redirect/return/callback | Same; safe allowlist and expiry |
| T15 | OVO | Binding, authorize, pay, unbind | OTP remains provider-side; one-time success verified |
| T16 | All | Tampered/missing signature | Reject, zero state change |
| T17 | All | Bad merchant/environment/amount | Reject or review, no ticket |
| T18 | All | Duplicate semantically equal callback | One event application and ticket |
| T19 | All | Callback before/after deadline | Timely confirmed if eligible; late review/unapplied |
| T20 | All | Event queue down | Durable ACK and later replay once |
| T21 | All | Inquiry pending/unknown/terminal | Only verified terminal outcomes cause final state |
| T22 | All | Reversal/refund | Verified adjustment changes eligibility safely |
| T23 | All | Flutter resume/status read | No duplicate attempt, correct instruction/status |
| T24 | All | Production gate on local/debug | Fail closed |
| T25 | All | Admin unauthorized | Access forbidden; no raw PII exposure |
| T26 | All | Privacy/secret scan | No credentials, key, redirect token in logs/repo |
| T27 | All | MySQL races | Lock/idempotency in MySQL, not only SQLite |
| T28 | All | Live Sandbox UAT | Evidence with redacted request IDs and provider callback; blocked if channel unavailable |

Run `php vendor/bin/pest`, targeted MySQL concurrency suite, `vendor/bin/pint --test` (or project Pint workflow), route and scheduler listing, migration `--pretend`, and static/secret checks. Save actual counts/date/environment; **never assert PASS for tests not executed**.

## 14. Delivery workstreams: all three channel families required

### M0 — Baseline preservation and channel spec discovery

- Confirm checkpoint branch/commit, test baseline, current repo tree, `AGENTS.md` and installed Superpowers skills.
- Inventory existing BNI adapter, payment domain, webhook routes/jobs and tables without destructive refactor.
- Create per-channel official doc/payload/response/notification/inquiry/adjustment matrix with source URLs. Mark missing contract as blocker, **never fill by guess**.
- Exit: baseline tests reproduced and matrix signed off.

### M1 — Shared channel abstraction, capability and environment gates

- Implement channel registry + typed method selection, verified capability gates, shared normalized result interface, per-channel configs.
- Preserve `va_bni` and DEMO behavior; no BNI BIN requirement at gateway level.
- Exit: tests show BNI disabled independently while QRIS/wallet capabilities remain configurable (only if implemented/verified).

### M2 — QRIS (mandatory)

- Implement official SNAP token/signed generate/query, response schema/QR payload, expiry/stop, webhook/inquiry, idempotency and error parser.
- Add server-only merchant ID/terminal ID config and allowlisted channel endpoints.
- Exit: signed fixtures + full domain concurrency tests. Provider Sandbox UAT required if merchant channel supports it; otherwise BLOCKED external and cannot call live-ready.

### M3 — Multi-bank VA (mandatory)

- Refactor existing BNI into shared VA adapter without regression; add BCA, BRI, Mandiri fixed-bill DGPC, with optional Permata implementation behind verified gate.
- Per-bank official field mapping, configs, notification normalization and inquiry; optional bank configuration **must not** block other channels.
- Exit: each mandatory bank adapter has contract tests; real Sandbox UAT when activated.

### M4 — E-wallet (mandatory)

- Implement DANA + ShopeePay host-to-host one-time redirect with approved allowlist and signed notifications.
- Implement OVO account binding/unbinding and one-time SALE authorization/payment with secure token lifecycle; no recurring.
- Exit: each wallet contract, timeout, redirect, callback, unknown state and duplicate tests pass; Sandbox UAT documented.

### M5 — Cross-channel integrity and reconciliation

- Update event/verifier registry, durable callbacks, query mapping and authenticated terminal/reversal/refund parsers; no fabricated paid state.
- Test channel switching races, unknown issuance, late capture, booking expiry, quota, tickets, refunds and idempotent replay.
- Exit: all integrity tests pass; unresolved provider contracts quarantined with documented blocker.

### M6 — Native Flutter integration contract and UAT dependency

- Finalize route shape; return QRIS/VA/wallet instructions; ensure on-resume recovery and accessibility.
- Backend passes contract tests and sample payloads to Flutter workstream; Android emulator tested via `adb reverse` once Flutter is updated.
- Exit: no client-side paid confirmation; complete end-to-end evidence once channel test path available.

### M7 — Release gate / operations

- Perform full regression, migrations, worker/scheduler, callback HTTPS, alerts, isolated production creds and rollback/forward-fix rehearsal.
- Exit: **code complete only when QRIS + BCA/BNI/BRI/Mandiri + DANA/ShopeePay/OVO are actually implemented and tested**. **Go-live** remains blocked until merchant, production and signed E2E proofs pass.

### Implementation status (8 November 2026, from tests actually run)

| Milestone | State | Evidence |
| --- | --- | --- |
| M0 | Done | Baseline reproduced (178 tests / 1,170 assertions). Matrix in `DOKU-CHANNEL-MATRIX.md` |
| M1 | Done | `DokuChannelRegistry`, fail-closed gates, additive `channels` in `payments/methods` |
| M2 QRIS | PARTIAL | Generate adapter + 14 tests. No notification or enumerated query status in the official page, so the channel cannot settle and stays unavailable |
| M3 VA | Code complete, no Sandbox | BCA/BRI/Mandiri via bank profiles + 17 tests; each settles only after its notification CHANNEL-ID is configured |
| M4 E-wallet | PARTIAL | DANA and ShopeePay (31 tests) settle from the authenticated status query; ShopeePay path unconfirmed; OVO not implemented (fields unreadable) |
| M5 | PARTIAL | Existing durable events, locks and reconciliation reused. Reversal/refund parsers and QRIS/e-wallet notification ingress blocked by missing contracts |
| M6 | Not started | Flutter contract examples pending |
| M7 | Not met | MySQL concurrency not re-run, no Sandbox UAT, no Production approval |

### Work priority order versus requirement priority

Implementation order may be M1→M2→M3→M4 for manageable incremental PRs, but QRIS and wallets are **not optional** and cannot be silently deferred beyond the defined release. Channel-specific merchant blockers are tracked independently and do not justify replacing full multi-channel scope with only BNI.

## 15. Review protocol: Superpowers and maximum three subagents

Read local `AGENTS.md`, project skills under `.agents/skills`, and the user-installed `C:\Users\itsba\.agents\skills` / Superpowers skill installation in the **local Codex environment**. This chat environment cannot directly execute files from the user's Windows paths.

Recommended workflow: `brainstorming` (already specified by this PRD) → `writing-plans` (tasks/file ownership) → `using-git-worktrees` (isolate implementation) → `test-driven-development` (red/green/refactor) → `requesting-code-review` / `verification-before-completion`. Consult installed skill instructions and do not infer their behavior solely from names.

Use **at most 3 subagents concurrently**, selected from actual available local agent filenames, **not invented**:

1. Architecture reviewer (read-only): channel boundaries, invariant/state machine, migration, environment isolation.
2. Backend implementer (owned file set): SNAP adapters, transport, orchestrator and tests; coordinate with root to avoid simultaneous edits.
3. Security/QA reviewer (read-only): signature canonicalization, webhook replay, SSRF/redirect, keys, concurrency, QA matrices.

Root agent owns final integration, docs, test evidence, and commits. If agent roles/names are unavailable, map to available agents; never claim a nonexistent subagent ran.

## 16. Risk register

| Risk | Severity | Mitigation |
| --- | --- | --- |
| VA settings dashboard blank; Notification URL not configurable | High external | Continue adapter/fixture work; Sandbox webhook UAT BLOCKED until configured; no fake PASS |
| QRIS may have Sandbox account/channel restrictions | High external | Probe only with approved merchant config; no assumptions based on docs having a Sandbox URL |
| OVO binding scope/tokens misimplemented | High | Separate binding from direct debit; encrypt tokens; no OTP storage; protocol fixtures |
| Bank-specific required BIN/service data missing | High for that bank | Gate **that bank only**; never infer from Client ID or examples |
| One booking multiple payable attempts across channels | Critical | Shared active/unknown attempt lock; cross-channel concurrency tests |
| Bad signature or wrong canonicalization | Critical | Official raw-body vectors, immutable input, fail closed, independent review |
| Provider timeout causes repeat issuance | Critical | `unknown` + reconcile; never retry create blindly |
| Callback arrives late or after refund | Critical | Verified event time, monotonic domain transitions, review/unapplied pathway |
| Public tunnel exposes dev exception or secrets | High | `APP_DEBUG=false`, credentials hidden, short-lived tunnel, restrict env |
| SANDBOX→PRODUCTION .env toggle with open attempts | Critical | Isolated deployments/db; immutable transaction environment, block switch |
| Float rounding / wrong settlement claims | High | Minor units/integer amount, exact currency, never infer fee/settlement |
| Flutter assumes return=paid | High | Status refresh and signed backend confirmation only |

## 17. Definition of Done and explicit blockers

### Code-complete requirements (all mandatory)

- [ ] QRIS adapter and full notification/query lifecycle implemented and tested.
- [ ] VA BCA, BNI, BRI, Mandiri implemented with per-bank fixtures, notification/query and fixed-bill behavior.
- [ ] DANA, ShopeePay one-time + OVO one-time binding flow implemented and tested.
- [ ] Unified eligibility, intent/attempt, encrypted instruction, idempotency, immutable environment and generic Flutter contract operate for **all families**.
- [ ] Cross-channel webhook verified/durable, replay and terminal adjustment parsers follow official contract, safe `unknown/needs_review` otherwise.
- [ ] Existing DEMO, booking/quota/tickets and previous BNI tests stay green.
- [ ] MySQL race tests, security review, no secrets, migration safety and runbook updated.
- [ ] API examples and Flutter implementation requirements delivered.

### Provider-verified UAT requirements

- [ ] Signed callback received over configured HTTPS from actual DOKU provider for each activated channel family.
- [ ] At least one real Sandbox test case per available target channel with redacted evidence; unavailable channels remain marked BLOCKED rather than PASSED.
- [ ] Unresolved inquiry/reversal/refund contracts have evidence and risk decision; no unverified parser enabled.

### Production release approval

- [ ] Merchant/channel Production activation, stable HTTPS notifications, appropriate fees/terms, secure keys, monitoring and rollback controls.
- [ ] Production approval explicitly reviewed; no Production activation via accidental `.env` change.
- [ ] Smoke test and booking/ticket consistency evidence complete.

**Go-live is explicitly NOT satisfied now.** The uploaded runbook reports no real DOKU Sandbox/Production/Flutter UAT and missing provider inquiry/refund terminal contracts.

## 18. Source index and verification provenance

**Provided by user (primary project evidence):** `DOKU-RUNBOOK.md` and `DOKU-implementation-plan.md` dated 2026-11-08, PRD v1.0 Markdown, and conversation-confirmed local tunnel/RSA/merchant setup. Code changes after the user's local checkpoint are **not accessible in this chat**; the local checkout remains authoritative.

**Official DOKU references (protocol existence, not proof of merchant activation):**

- QRIS SNAP: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/qris
- VA SNAP overview and bank rules: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account
- BCA VA: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/bca-virtual-account
- BRI VA: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/bri-virtual-account
- Mandiri VA: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/mandiri-virtual-account
- BNI VA: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/bni-virtual-account
- DANA: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/e-wallet/dana
- ShopeePay: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/e-wallet/shopeepay
- OVO: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/e-wallet/ovo
- DOKU SNAP notification examples: https://developers.doku.com/get-started-with-doku-api/notification/http-notification-sample-snap
- Superpowers methodology: https://github.com/obra/superpowers

**Last note:** This is a specification. It does not assert that multi-channel source changes or provider UAT have already been executed.
