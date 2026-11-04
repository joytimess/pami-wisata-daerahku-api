# Phase 0 + Phase 1A backend handoff

## Baseline and scope

Inspected local `main` at `6c004a446b4e88306bc2733a0879d6a9a127905f`, origin `joytimess/pami-wisata-daerahku-api`. Initial worktree contained only the untracked source PRD; it was preserved. Installed Laravel 13.39.0, PHP constraint ^8.3, Pest 4.7.8, Pint 1.32.1. Read global/repo AGENTS, local Laravel/testing skill instructions; `.ai/rules` does not exist. Implementation stops at Phase 1A. No Flutter, dependency, credential, commit, push, or deployment changes.

| Area | Baseline | Delivery |
|---|---|---|
| JWT/OTP/Google/profile/avatar | Existing | Reused unchanged |
| Booking intent, quota, status/notification scheduler | Existing | Existing state machine and booking-create idempotency preserved |
| Smart tickets, scanner, assignment/history tables | Existing | Reused; scanner/assignment lock ordering aligned |
| API resources, data/links/meta, 409 domain errors | Existing | Additive operations resource and stable error codes for new endpoints |
| Admin destination authorization | Role checks; scanner already scoped | Central current assignment service; booking/catalog/gallery/facility/quota/document/inbox paths scoped |
| Destination creation by Admin | Existing gallery flow | Creator atomically assigned and assignment history appended |
| Approval claims | Missing | Unique booking claim, append-only history, expiry/release/reassign/compat decisions |
| Dashboard SQL metrics | Missing | Scoped operational/personal/visitor/destination/queue APIs |
| Payment presentation | Missing | Feature-disabled owner-only GET preview; no payment mutation |
| Gateway, verified payments/fees/refunds/settlement | Not integrated | Deferred Phase 1B; all financial measures unavailable/null |

## Schema and deployment

| Forward migration | Changes |
|---|---|
| `2026_11_04_152334_create_booking_approval_claim_tables.php` | `booking_approval_claims` UUID PK; unique `booking_guid` FK; nullable owner/times; owner-expiry index |
| Same migration | `booking_approval_claim_histories`: actor, from/to owner FKs, event, bounded reason, UTC timestamp; booking-time index |
| Same migration | Add decision-period and actor-period indexes to existing `booking_status_histories` |

Relationships: `destinations -> destination_quotas -> bookings -> booking_approval_claims (0..1)` and `bookings -> booking_approval_claim_histories (many)`. `users <-> destination_admin_assignments <-> destinations` remains current authorization; assignment histories remain historical evidence. Existing decision/assignment/gallery histories supply domain accountability; no duplicate generic audit table.

Apply with the normal reviewed `php artisan migrate --force` deployment procedure after backup and schema review, then rebuild config cache/restart long-lived workers under the existing release process. No work-database migration was run by this implementation. New constraints are additive and no booking/assignment backfill is needed. Index creation can lock a large history table: schedule appropriately for actual MySQL size. Rollback code first; rolling down this migration drops claim/history data and is destructive after use. Prefer a forward fix after rollout.

## Authorization

| Actor | Existing admin lists/read/write | Claims and decisions | Analytics |
|---|---|---|---|
| Role 0 | Denied | Denied | Denied |
| Active role 1 | Current assigned destinations only; zero assignments yields empty lists | Assigned destination plus active claim ownership; compat may auto-claim unowned work | Current assigned team aggregates; personal historical decisions by actual actor, including former assignments, without booking PII |
| Active role 2 | Global | Claim/release; explicit reason for releasing another owner; reassign only to active currently assigned role 1 | Global metrics, destination drilldown, bounded staff decision/workload facts |
| Inactive/demoted | Denied on fresh server checks | No stale claim privilege | Denied |

Direct foreign booking/document lookup and service scope checks return 404. Catalog/quota/facility FormRequest authorization returns 403 on an existing out-of-scope route-bound destination. Admin media uses the same request boundary. Public active catalog and visitor availability retain existing behavior. Legacy booking document admin access is now assignment-scoped. Admin notification recipients are scoped at creation, historical inbox list/read/unread rechecked, queued deliveries rechecked before external send. An assignment revoked after the last delivery check cannot recall an already-sent message; no DB locks are held across network calls.

## Routes

All paths below start `/api/v1`; existing JWT middleware is required.

| Method | Route | Contract |
|---|---|---|
| GET | `/bookings/{booking}/payment-preview` | Owner only; disabled flag returns 404; awaiting_payment preview metadata, otherwise unavailable |
| GET | `/admin/dashboard/overview` | Team decisions/current work, personal contribution, creation trend, visitor observations, financial unavailable |
| GET | `/admin/dashboard/approvals` | Team/personal decision KPI, daily series; role 2 staff decision/workload arrays bounded 100 |
| GET | `/admin/dashboard/destinations` | Paginated capacity, allocated/remaining, status counts, requested/approved/confirmed quantity, occupancy |
| GET | `/admin/dashboard/visitors` | Confirmed booking check-in observations; verified finalized attendance/no-show unavailable |
| GET | `/admin/dashboard/action-center` | Paginated approaching/passed deadline, pending queue, expired claim, upcoming confirmed visits |
| GET | `/admin/approval-queue` | Paginated pending before original approval deadline, authoritative current claim state |
| POST | `/admin/bookings/{booking}/claim` | Idempotency-Key required; one owner; stable claim_owned conflict |
| POST | `/admin/bookings/{booking}/release` | Idempotency-Key; owner or Superadmin; Superadmin override requires reason |
| POST | `/superadmin/bookings/{booking}/reassign` | Idempotency-Key; body `admin_guid`, required reason; currently assigned active role 1 target |
| POST | Existing `/admin/bookings/{booking}/approve`, `/reject` | Same payload and original transition; assignment plus compat/strict ownership check |

Existing route URIs, request callbacks, booking resource fields and approval/payment deadlines remain. Admin booking/catalog lists and quota/gallery/facility writes have intentionally narrower assignment access. Previously global Admin installations must explicitly assign existing destinations through existing Superadmin assignment API; zero assignment never means global.

## Envelope, filters and errors

New resources use `data`; collections retain Laravel `links`/`meta` pagination and add generated/last_updated timestamps, period, scope, metric_bases and financial availability in meta. `generated_at` is response generation time, not proof of an external provider refresh. Stable GUID ordering breaks pagination ties. per_page is 1..100. Staff arrays are bounded to 100; there is no unbounded export.

`from`/`to` must be supplied together as inclusive YYYY-MM-DD, ordered, at most 366 days apart. UTC event filters use [from 00:00, day-after-to 00:00). Visit filters use destination local calendar dates. Default reporting period is UTC month-start through today; action center defaults today through +30 days for upcoming visits while pending work is always a current snapshot.

Endpoint `date_basis`: overview/approvals `decision`; destinations/visitors/action-center `visit`; queue `created`. Omitted values default accordingly; mismatches return 422. Overview intentionally includes separately labeled metrics with decision/current-snapshot/created/visit bases; metadata describes each. Queue custom from/to filters booking creation, not the approval deadline. Action center pending urgency is current snapshot and upcoming visit dates use the given visit range. Upcoming includes the destination local visit day and future days, excludes finalized prior local days. SQL does not depend on installed MySQL timezone tables.

`destination_guid` is checked against current assignment. `admin_guid` is role-2-only and applies to decision metrics/series/staff attribution on overview/approvals; metadata makes this explicit. It is rejected with 422 on visitors/destinations/queue/action-center instead of silently ignored. Current team work/visitor facts do not become individual-actor data.

Errors: existing BookingException gives 409 `{message,code,context?}`. Claim codes include `claim_owned`, `claim_required`, `claim_not_owned`, `booking_not_pending`, `approval_deadline_passed`, `override_reason_required`, `idempotency_conflict`, `idempotency_key_expired`. New operations endpoints also give stable `validation_failed` 422, `unauthenticated` 401, `forbidden` 403, `not_found` 404, `rate_limited` 429 and sanitized `service_unavailable` 500. Existing endpoint envelopes are retained for compatibility.

Example illustrative claim response (identifiers abbreviated for readability):

```json
{"data":{"booking_guid":"booking-guid","claim_guid":"claim-guid","owner_user_guid":"admin-guid","state":"active","expires_at":"2026-11-04T09:15:00+00:00","approval_expires_at":"2026-11-05T08:00:00+00:00"},"meta":{"generated_at":"2026-11-04T09:00:00+00:00"}}
```

Example unavailable payment preview:

```json
{"data":{"booking_guid":"booking-guid","availability":"unavailable","reason":"booking_not_awaiting_payment","provider":"doku","integration_status":"not_configured","can_pay":false,"payment_status":"unverified","presentation_styles":["qris","virtual_account","ewallet"]},"meta":{"generated_at":"2026-11-04T09:00:00+00:00"}}
```

Illustrative overview fragments (real values are server SQL aggregates):

```json
{"metrics":{"pending_approvals":0,"approved_decisions":0,"rejected_decisions":0,"approval_rate_percent":null,"avg_response_seconds":null,"sla_compliance_percent":null,"date_basis":"decision","pending_date_basis":"current_snapshot"},"financial":{"availability":"unavailable","reason":"payment_gateway_not_integrated","gross_revenue":null,"net_revenue":null,"gateway_fees":null,"refunds":null,"settlement":null}}
```

## Claim state, idempotency and locking

Claim duration is 15 minutes, capped at original approval_expires_at; claiming never extends the original 24-hour approval deadline. Unique booking FK enforces one claim record. Expired/revoked-owner claims are lazily closed on mutation without waiting for a scheduler; queue/presentation immediately excludes inactive or unassigned owners. Active owner repeated claims with a new key do not renew duration or duplicate claim events. Reassignment to the same owner is explicitly audited without extending the lease. Successful decision closes the claim in the same transaction; cancellation/expiry closes it idempotently. Release and reassignment never change booking/payment status.

Idempotency identity is actor + operation + key (1..128 string characters). Fingerprint includes booking, target and reason; same key/different input is 409. Key insertion commits in a short transaction; execution locks its row. Successful key retention is 30 days; expired keys cannot reopen. Retries authorize current actor/assignment first and return current claim presentation rather than stale cached active ownership. They do not execute state changes or append duplicate events.

Lock order for new claim/decision: short idempotency insert (committed), then optional idempotency row -> destination gate -> quota -> booking -> fresh actor/assignment -> claim/owner validation. Scanner confirmation acquires destination before existing quota -> booking -> ticket locks. Assignment writes acquire destination before staff/target/assignment locks. Gallery acquires destination before actor for existing destinations. Quota writes acquire destination -> quota -> actor. Existing create/cancel/expiry/ticket issuance retain quota -> booking without acquiring a destination lock afterward; this preserves allocation serialization and avoids reversing destination/quota order. Bounded DB transaction retries are retained. A full proof of every possible lock combination is not implied by the targeted two-process claim smoke.

## Rollout and payment guard

```dotenv
APPROVAL_CLAIM_MODE=compat
PAYMENT_PREVIEW_ENABLED=false
DOKU_ENABLED=false
```

`config/operations.php` forces doku_enabled=false regardless of environment; there is no gateway adapter/call/secret/webhook in this phase. Preview requires explicit flag enablement in an approved development/demo environment. It only SELECTs an owner's booking, emits style names rather than QR/payment credentials, persists no attempts, and never invokes ticket issuance. PaymentEligibility::status remains unverified. Existing free-booking approval may still become confirmed by its original rule; this does not establish paid eligibility or unlock QR.

Compat is the default: authorized legacy approve/reject atomically auto-claims an unowned booking and records `legacy_compat_auto_claim`, then decides. Another active owner always conflicts, including for Superadmin. Strict requires explicit claim for decisions. Superadmin can first release another owner with a reason, then explicitly claim; or reassign with reason to a currently assigned active Admin. Switch to strict only after all supported Flutter Admin versions implement claim/release/reassign, show ownership/expiry/conflicts, preserve retry keys and reconcile current server state. Rebuild config cache after changing mode. Keep compat during older supported-client rollout; switching back restores auto-claim semantics without deleting audit history.

## Metric definitions

- Decisions come only from pending_approval -> awaiting_payment/confirmed/rejected history, attributed to the actual actor and deduplicated by booking decision. Personal history survives reassignment; current team scopes use EXISTS assignment, never assignment fan-out.
- Approval rate = approved/(approved+rejected)*100, null on no decisions. Response time = history timestamp minus booking creation, not claim time. SLA = decisions before original approval_expires_at / period decisions; expired pending is a separate current snapshot count.
- Requested quantities include requests; approved and confirmed quantities are separate operational states. No field claims tickets_sold or real payment revenue. Allocated uses BookingRules::HELD. Remaining=max(0,sum configured capacity-allocated); reserved/confirmed occupancy use configured quota capacity in the visit period; absent/zero capacity yields null occupancy.
- Check-ins count confirmed bookings only: checked booking_tickets if any ticket row exists, otherwise legacy check_ins contributes booking quantity. No double-count when both sources exist. These are observed check-ins, not a verified paid cohort. Finalized attendance_rate/no_show_count/eligible_tickets remain null with insufficient_verified_data while authoritative payment verification is unavailable.
- Action center has no fabricated QR-error/reconciliation alert: baseline has no durable matching error source. It contains only real pending/deadline/claim/upcoming-visit facts.

## Verification and remaining integration

Observed verification from the main session:

- PASS: targeted 12-file Pest run, **114 tests / 1193 assertions** (before final extra metadata assertions).
- PASS: final `php artisan test --compact tests/Feature/AdminOperationsTest.php`, **12 tests / 126 assertions**, including endpoint effective metadata assertions.
- PASS: disposable MySQL smoke, **3 tests / 52 assertions**, including different-Admin race, same-key concurrent replay, same-actor/different-keys current-owner response, plus all six dashboard query paths against MySQL.
- PASS: `vendor/bin/pint --dirty --format agent` after final PHP edits, and `git diff --check`.

```powershell
php artisan test --compact tests/Feature/AdminOperationsTest.php tests/Feature/BookingApiTest.php tests/Feature/AdminApiTest.php tests/Feature/BookingServiceTest.php tests/Feature/SmartTicketSmokeTest.php tests/Feature/DestinationGalleryTest.php tests/Feature/BusinessAdjustmentApiTest.php tests/Feature/CatalogServiceTest.php tests/Feature/BookingNotificationTest.php tests/Feature/InboxServiceTest.php tests/Feature/FcmDeliveryTest.php tests/Feature/BookingDocumentsTest.php
php artisan test --compact -c phpunit.mysql.xml tests/Mysql/ClaimConcurrencyTest.php
vendor/bin/pint --dirty --format agent
git diff --check
```
 Focused suites cover assignment boundaries, claim replay/expiry/revocation/compat/strict, transitions, SQL actor attribution and legacy attendance fallback, notification scope and read-only preview. A disposable MySQL smoke starts two independent PHP workers for different-owner and same-key claim races and removes only its generated test database. No working-database wipe/migrate:fresh/forced seed is used. Full regression, Flutter build/analyze, device, staging and production verification are outside this run.

Flutter follow-up: consume the new scoped pagination/errors/meta, wire operational dashboards/queue/claim workflow, retain original payload/key on retries, handle unclaimed/active/expired/revoked states and 409 conflicts, then coordinate strict activation. Flutter remains unchanged here.

Phase 1B follow-up: authoritative DOKU credentials/account onboarding in secured deployment configuration; verified callback authentication/replay protection; provider idempotency; transactional booking payment status; reconciliation of uncertain outcomes; actual fees/refunds/settlement capabilities; verified payment-to-ticket issuance tests. Replace TODO[DOKU] seams only after those guarantees exist. Do not infer payment success from a preview, booking amount, free approval, local UI, or cached response.

## Changed files

The original untracked PRD is preserved as source input. Implementation, rollout example, handoff and regression fixtures:

```text
.env.example
app/Http/Controllers/Api/V1/DestinationController.php
app/Http/Controllers/Api/V1/FacilityController.php
app/Http/Controllers/Api/V1/QuotaController.php
app/Http/Requests/BookingDecisionRequest.php
app/Http/Requests/CatalogRequest.php
app/Http/Requests/QuotaRequest.php
app/Jobs/DeliverBookingNotification.php
app/Policies/DestinationPolicy.php
app/Services/BookingDocumentService.php
app/Services/BookingNotificationService.php
app/Services/BookingService.php
app/Services/CatalogService.php
app/Services/DestinationGalleryService.php
app/Services/FacilityService.php
app/Services/InboxService.php
app/Services/PaymentEligibility.php
app/Services/QuotaService.php
app/Services/TicketCheckInService.php
bootstrap/app.php
routes/api.php
tests/Feature/AdminApiTest.php
tests/Feature/BookingApiTest.php
tests/Feature/BookingNotificationTest.php
tests/Feature/BookingServiceTest.php
tests/Feature/BusinessAdjustmentApiTest.php
tests/Feature/DestinationGalleryTest.php
tests/Feature/FcmDeliveryTest.php
tests/Mysql/worker.php
tests/Pest.php
app/Http/Controllers/Api/V1/ApprovalQueueController.php
app/Http/Controllers/Api/V1/OperationalDashboardController.php
app/Http/Controllers/Api/V1/PaymentPreviewController.php
app/Http/Requests/ApprovalClaimRequest.php
app/Http/Requests/DashboardRequest.php
app/Http/Resources/OperationalResource.php
app/Services/AdminDestinationScopeService.php
app/Services/ApprovalQueueService.php
app/Services/OperationalDashboardService.php
app/Services/PaymentPreviewService.php
config/operations.php
database/migrations/2026_11_04_152334_create_booking_approval_claim_tables.php
docs/phase-0-1a-handoff.md
tests/Feature/AdminOperationsTest.php
tests/Mysql/ClaimConcurrencyTest.php
```
