# PRD — Wisata Daerahku Laravel 13
## Phase 0 + Phase 1A: Backend Foundation, Admin Authorization, Shared Approval Queue & Operational Analytics

**Document version:** 1.0  
**Prepared:** 4 November 2026 (Asia/Jakarta)  
**Status:** Implementation-ready specification; **not** a claim that work has been coded or tested.  
**Backend repository:** https://github.com/joytimess/pami-wisata-daerahku-api  
**Flutter repository (contract reference only):** https://github.com/koyotot/pami-wisata-daearahku  
**Inspected public backend baseline:** `main` at `6c004a446b4e88306bc2733a0879d6a9a127905f` (commit timestamp 2026-11-03 13:58:53 UTC). Codex MUST re-inspect local HEAD/worktree and reconcile differences before modifying files.

> **Primary mandate:** Implement **Phase 0 and Phase 1A in the existing Laravel repository**, not a rewrite. Laravel is the authoritative source for request/response, authorization, status transitions, operational metrics and history. Flutter is **out of implementation scope** for this delivery. DOKU gateway integration is **disabled/deferred**; any payment screens are preview-only, with no fake paid status.

---

## 1. Outcomes and acceptance criteria

### Product goal
Enable role 1 (Admin) and role 2 (Superadmin) to consume accurate, assignment-aware dashboard and workflow APIs for bookings, approvals, destinations and visitor operations, while preparing a **DOKU-ready payment contract** without accessing a gateway.

### Phase 0 outcomes
1. Create a trustworthy repository baseline and a concise gap inventory: existing endpoints, auth, migrations, DTO/resources, status machine, scheduler, concurrency and payment guards.
2. Define a stable JSON API contract (data/meta/errors, pagination, date conventions, GUID keys) that the Flutter app can consume later.
3. Establish/refine an internal payment presentation contract and a **disabled** future DOKU adapter seam, without persisting a successful payment, creating a real gateway attempt or issuing tickets.
4. Preserve existing booking, approval, quota, QR, FCM and auth behavior except for planned authorization and queue enhancements.
5. Document compatibility/migration/rollback and the scope impact on existing Flutter requests.

### Phase 1A outcomes
1. Enforce destination-assignment authorization across **every role-1 admin access path** to protected booking/destination/quota data; role 2 can view globally per existing Superadmin policy.
2. Implement **shared approval queue** with exclusive claim, release, expiry, reassignment and attributable action history. Keep original 24-hour approval deadline unchanged.
3. Add server-side operational analytics: approval decisions/SLA, personal admin contributions, destination quota/booking performance, visitor check-ins, finalized no-show (if eligible data exists), and actionable pending work.
4. Expose fast, filterable and scoped dashboard APIs for Flutter Admin and Superadmin. Return financial measures as **unavailable/null**, not invented figures.
5. Preserve existing route behaviors where possible, and provide targeted proof for authorization boundaries and concurrent booking/claim safety.

### Explicit non-goals for this deliverable
- No Flutter implementation, no Figma, no new frontend screens.
- No DOKU account, credentials, API calls, webhook, production payment, settlement or refund execution.
- No automatic `paid`/`confirmed` transition or ticket QR issuance from mockup/payment-preview actions.
- No full Phase 1B financial dashboard; no Phase 2 targets/leaderboard/export/refunds/FCM alerts, no Phase 3 settlement/historical archiving work beyond the **minimal history needed now**.
- No new dependency/package, redesign of authentication, or rewrite of existing Smart Ticket QR functionality unless a focused defect blocks this scope.
- No LLM, vector AI or external intelligence API. All “intelligence” means deterministic Laravel/MySQL rules.

---

## 2. Verified existing-code baseline and reuse strategy

These items were inspected in the public repository and must be revalidated in the actual local working tree:

| Existing location | What exists / expected reuse | Required action |
|---|---|---|
| `routes/api.php` | Existing `/api/v1/admin/bookings`, destinations, ticket scanner and Superadmin endpoints | Extend routes, do not duplicate old approve/reject actions |
| `app/Services/BookingService.php` | Booking create/list/show/approve/reject, quota→booking row locks, status history, payment expiry and idempotency | Reuse; enforce destination scope and claim ownership |
| `app/Services/TicketCheckInService.php` | Role/assignment scope for QR scans and Superadmin assignment writes | Extract/reuse a shared authorization pattern without weakening scanner |
| `app/Policies/DestinationPolicy.php` | Existing role-based gallery/facility policies | Extend to scope-aware, action-specific policies |
| `database/migrations/2026_11_03_130325_create_smart_ticket_tables.php` | `booking_tickets`, `ticket_check_ins`, `destination_admin_assignments`, assignment histories | **Do not recreate** these tables or change historical migrations |
| `database/migrations/2026_10_29_080000_create_booking_tables.php` | `bookings`, `booking_status_histories`, `idempotency_records` | Reuse history and idempotency; add forward-only migrations as needed |
| `app/Services/PaymentEligibility.php` | Returns `unverified` (fail-closed) | Keep fail-closed for Phase 0/1A |
| `app/Services/TicketService.php` | Per-ticket QR and payment eligibility gates | Do not weaken/replace |
| `app/Services/VisitService.php` | Legacy self-check-in disabled; reviews, legacy+ticket statistics | Use compatibility behavior for visitor analytics |
| `app/Services/QuotaService.php` | Capacity/allocated calculations and destination-local availability | Reuse allocation semantics; differentiate reserved vs sold |
| `app/Http/Resources/BookingResource.php` | Booking response includes `payment_status` and existing fields | Make only additive response changes, if needed |
| `app/Http/Requests/ListBookingsRequest.php`, `BookingDecisionRequest.php` | Request validation and role checks | Add filters and action-specific controls; service must still enforce access |
| `AGENTS.md` | Laravel/PHP 8.3, repository conventions, Pest, targeted tests and Pint instructions | **Read first** before editing code |

**Local-source precedence:** local `AGENTS.md`, applicable `.ai/rules/index.md` + matching rule files, and actual checked-out code take precedence over path assumptions in this PRD. Report any material deviation rather than manufacturing missing functionality.

### Existing role taxonomy
`users.role`: `0=user`, `1=admin`, `2=superadmin`. Treat actor activation/status and token validity as necessary authorization conditions. Preserve JWT/API middleware and existing route contracts.

### Existing booking/payment invariants
- Approval expiry is 24 hours by established business logic; approval **does not** gain time when claimed/reassigned.
- Approved paid booking enters `awaiting_payment`; existing code sets `payment_expires_at` to ~1 hour and `notification_grace_expires_at` to ~1h15m after approval.
- Existing expiry scheduler may move `awaiting_payment`→`payment_verification`→`payment_review`; do not invent an alternate state machine.
- Free booking behavior must remain consistent with existing rules; Phase 0 payment preview must not alter any paid/QR guard.
- `Booking Intent`/`idempotency_records` for booking creation are **not** `Payment Intent`; never rename or conflate them.
- Ticket check-in uses unique ticket identity with legacy fallback. Do not double-count legacy check-in and modern ticket check-in.

---

## 3. Cross-cutting architecture

**Server layers:** `routes/api.php` → JWT middleware → Form Request + Policy/scope guard → thin Controller → Service/query objects → transaction-safe MySQL → API Resource + `meta`.

**Engineering conventions:** Follow current Laravel 13/PHP 8.3 conventions and `AGENTS.md`; keep controllers thin, services domain-oriented, queries efficient, resource output stable, `*_guid` FK naming, English database/table names, database transaction and consistent ordering for locks; no new root architectural folder without need. Use versioned API `/api/v1`.

**Security:** Authorization in service/query **and** request/policy layers; no trust in Flutter filters, custom role headers, client-provided admin GUIDs or foreign destination GUIDs. Hide unauthorized record existence when appropriate (`404` for direct out-of-scope booking lookup), use `403` for forbidden operations on known accessible resources; document the chosen convention. Never log sensitive payment secrets, JWT, OTP, QR payloads, raw phone data or complete PII in audit.

**Data integrity:** No aggregate calculated from unbounded page results on the client. Avoid fan-out joins in metrics; prefer `EXISTS`, grouped subqueries, `DISTINCT` booking keys and indexed status/date columns. Never count an `attempt` as a unique successful booking.

**Performance:** Bound `from`/`to` and page size, stable sorting and cursor/page semantics, explicit nullable fields, query indexes, `EXPLAIN` only for suspect queries. No mandatory WebSocket. Return `generated_at`/`last_updated_at` where feasible to support polling/app resume.

---

## 4. Phase 0 — Implementation scope

### P0-01. Repository and invariant audit
- Snapshot `git status`, branch and HEAD; do not overwrite uncommitted work.
- Review `routes/api.php`, `BookingService`, `TicketCheckInService`, `CatalogService`, `QuotaService`, `TicketService`, `PaymentEligibility`, `VisitService`, policies, requests, resources, migrations and scoped tests.
- Record exact current endpoint signature and JSON shapes, dependencies and status flows. Include a gap matrix: existing / extend / new / future.
- Inspect `.ai/rules` if present **before** code edits. Confirm package versions via Composer before using uncertain APIs.

### P0-02. API contract baseline
Define and document:
- Response envelope: existing API Resource style first (`data`, `links`, `meta`) with additive `meta.generated_at` and scope/period details as necessary.
- Error mapping: malformed filters `422`; unauthenticated `401`; authorization `403/404` per established convention; state/ownership collision `409`; rate limit `429`.
- Dates: UTC RFC3339 timestamps for event instants; YYYY-MM-DD for visit dates; destination timezone for visit-day interpretation; explicit `date_basis` for grouped KPI periods.
- Pagination per_page max 100 (or stricter existing limits), stable sort and filter keys; no unbounded large exports.
- Backward compatibility inventory of existing Flutter-facing Admin routes.

### P0-03. DOKU-ready, disabled payment presentation contract
- **Keep `PaymentEligibility::status()` returning `unverified`** until authoritative verified provider implementation in later Phase 1B.
- Propose config flags e.g. `DOKU_ENABLED=false`, `PAYMENT_PREVIEW_ENABLED=true` (actual names can conform to established config). No secrets required.
- Provide an internal value object/interface or small service **only if warranted after audit** for supported presentation styles: `qris`, `virtual_account`, `ewallet`; result `preview_only`, `provider=doku`, `integration_status=not_configured`, `can_pay=false`.
- Implement a **read-only, authenticated, booking-owner-scoped** endpoint `GET /api/v1/bookings/{booking}/payment-preview` returning display-only metadata when booking is `awaiting_payment`; on other statuses return a stable non-mutating unavailable response. Gate it behind a preview feature flag (disabled by default outside development/demo).
- For this preview: **never** persist payment intent/attempt, return payment credentials/real QR, mark paid, create webhook receipt, mutate booking, or issue QR ticket. `preview` success/failure toggles belong to Flutter-only UI; must not be modeled as actual backend payment states.
- Mark future DOKU adapter integration with bounded `TODO[DOKU]` comments/documentation rather than writing uncallable pseudo-implementations.

### P0-04. Deliverables
- Repository audit / gap matrix and API response conventions as documentation scoped to this explicitly requested PRD.
- Safe/configurable preview contract (where justified) with feature-disabled guard and small targeted tests if a route is created.
- Migration/backward-compatibility plan and Phase 1A build order.

**Phase 0 DoD:** Existing booking/payment/QR states unchanged by preview; no DOKU network call or credential requirement; API contract and baseline captured; existing DB schema untouched unless a truly necessary forward-only migration is justified.

---

## 5. Phase 1A — Detailed implementation scope

### P1A-01. Centralized destination assignment authorization (P0 critical)
Create or extract `AdminDestinationScopeService` / Laravel Policy consistent with existing conventions; authority remains server-side.

**Policy matrix:**

| Operation | Role 0 | Role 1 | Role 2 |
|---|---|---|---|
| Admin booking list/detail | Denied | Current assigned destinations only | Global |
| Approve/reject | Denied | Assigned + valid claim ownership | Global with explicit override rules |
| Admin destination list/detail and protected media | Denied | Current assigned only | Global |
| Destination edit, gallery, facilities, quota | Denied | Current assigned + action permission | Global |
| Admin assignment management | Denied | Denied | Global |
| Ticket scanner | Denied | Existing assigned scope | Existing global scope |
| Dashboard operational metrics | Denied | Assigned scope + own contribution | Global, includes drill-down |
| Individual metrics of other Admins | Denied | Denied | Allowed |
| Financial metrics before 1B | Unavailable | Unavailable | Unavailable |

**Guard details:**
- `role=1` with zero assigned destinations sees an empty admin list/dashboard, never global data; direct out-of-scope operation denied.
- Validate active actor on every protected operation, including stale JWT/role changes.
- Anchor destinations to `destination_admin_assignments` for current access, **not** assignment history.
- Reuse `destination_admin_assignment_histories` for historical attribution; reassignments never rewrite past approval actors.
- For booking joins, resolve destination through `bookings.destination_quota_guid`→`destination_quotas.destination_guid`, never trust a body/query `destination_guid` to authorize a booking.
- Re-check scope during mutations, not only when fetching a list. Examine lock order with assignment changes and existing quota→booking locks to avoid deadlocks/TOCTOU.
- Ensure role-1 gallery/image/private detail, quota writes, destination facilities, and relevant catalog admin endpoints are not bypasses. Public Visitor catalog remains public as currently intended.
- Preserve currently allowed destination-creation workflow only if an Admin can be atomically assigned to the created destination (or propose a compatibility-safe restriction with explanation); never leave role-1 creating inaccessible orphan destinations.
- Global category/facility master-data operations should follow existing Superadmin-only behavior rather than treating them as a specific destination assignment.

### P1A-02. Shared approval queue + ownership
**Core model:** pending booking belongs to one destination queue; more than one Admin may be assigned to that destination, but only one may actively own the booking claim.

**Suggested forward-only schema** (only create if not already present; adjust to actual repo conventions):

`booking_approval_claims`
- `guid` UUID PK; `booking_guid` UUID FK UNIQUE.
- `claimed_by_user_guid` UUID nullable FK; `claimed_at` timestamp nullable; `expires_at` timestamp nullable.
- `version` unsigned int for CAS/optimistic checks if needed; `created_at`,`updated_at`.
- Index `(claimed_by_user_guid, expires_at)`; unique booking identity ensures one active claim per booking.

`booking_approval_claim_histories`
- `guid` UUID PK; `booking_guid` FK; `actor_guid` FK; `from_user_guid`/`to_user_guid` nullable FKs; `event` enum-like string (`claimed`, `released`, `expired`, `reassigned`, `completed`); `reason` nullable bounded text; `created_at` UTC; index `(booking_guid, created_at)`.

`admin_activity_events` (only if no reusable audit log already exists)
- UUID PK; `actor_guid`, optional `booking_guid`/`destination_guid`; `action`, `entity_type`, `entity_guid`, non-sensitive JSON change summary, created_at; indexes by destination/time, actor/time. Never store secrets/QR tokens.

**State machine and concurrency rules:**
1. Claim allowed only for `pending_approval` before `approval_expires_at`, with assignment valid at time of operation.
2. Concurrent claims: exactly one winner. Use transaction/unique constraint/row locks or CAS; second different Admin receives stable `409 claim_owned` and owner/expiry metadata they are permitted to see.
3. Same Admin reclaim/retry with same idempotency key yields equivalent result; different keys cannot create duplicate active ownership or audit events.
4. Expired claim can be atomically reclaimed, but does not extend approval deadline. Avoid requiring minute-by-minute cleanup for correctness; lazy expiry must work.
5. Release only by owner or authorized Superadmin. Reassignment only by Superadmin to a currently active Admin assigned to destination; requires reason and audit.
6. Approve/reject revalidates ownership, booking status, approval deadline, role and assignment **inside the transaction**. Consume/close active claim as part of a successful decision; write status history attributable to the actual decision actor.
7. Booking cancellation/expiration invalidates claim. A stale claim may remain in history but must not be actionable; clean up idempotently.
8. **Lock-order design review is mandatory:** existing booking decisions lock quota then booking. Assignment writes lock destination then assignments. Compose a single documented order across claim/decision/expiry/assignment, and verify in targeted MySQL concurrency tests; do not add inversions blindly.
9. When role-1 Admin loses assignment or becomes inactive, their claim must no longer grant write access. Reassign/expire under safe rules.
10. Superadmin override must have a defined reason and audit; cannot silently bypass financial/payment guards.

**Existing Flutter compatibility plan:** Current Flutter may call `POST /admin/bookings/{booking}/approve|reject` without a claim. Do not break abruptly. Implement rollout mode such as `APPROVAL_CLAIM_MODE=compat|strict`:
- `compat`: on a valid unclaimed booking, an authorized assigned Admin may atomically auto-claim-and-decide via legacy route; reject attempts if another active owner exists. Record auto-claim event and decision. No bypass of assignment/SLA/state checks.
- `strict`: explicit claim is required before role-1 decisions; Superadmin override remains explicit/audited.
- Default mode must be documented; handoff must state **exactly when to switch to strict** after Flutter implementation. Never silently let all Admins decide another Admin's claimed booking.

### P1A-03. Approval & admin performance analytics
Operational metric rules must be documented, implemented in backend SQL and testable:

| Metric | Definition, unit and event basis |
|---|---|
| `pending_approvals` | Count distinct bookings currently `pending_approval` and in current authorized destinations; current snapshot, not historical approved count |
| `approved_decisions` | Count booking-status-history transitions made to `awaiting_payment` or `confirmed` by actual actor, within decision-time period; dedupe by booking decision |
| `rejected_decisions` | Count transitions to `rejected` within decision-time period |
| `approval_rate_percent` | `approved / (approved + rejected) * 100`; null if denominator zero |
| `avg_response_seconds` | Average actual decision time minus booking created time for decided bookings, not claim time |
| `sla_compliance_percent` | Of **decisions completed in filter period**, % made before original `approval_expires_at`; null when no decisions. Report `expired_pending` separately to expose SLA misses |
| `personal_approvals` | Actor GUID in authoritative booking status history, never each Admin assigned to same destination |
| `team_operational_totals` | Distinct booking/destination aggregates for Admin's assignments; do not reveal other Admins' named individual metrics to role 1 |
| `assigned_workload` | Current unclaimed/claimed counts and personal claims, without duplicating shared-queue bookings |

Historical Admin contribution survives changes in current assignment, but individual role-1 historical access may be restricted to own actions; global historical reporting is Superadmin-only. Define active/current-scope vs past personal-action scope explicitly to avoid data leaks.

### P1A-04. Destination & visitor analytics
- Count booking states per destination, quantity requested, approved tickets, confirmed/eligible tickets **separately**; never label unverified booked quantities as `tickets_sold`.
- `allocated` vs `capacity` vs `remaining` use existing quota logic; occupancy labels must distinguish **reserved** and **confirmed** occupancy. For multi-date totals use denominator `SUM(configured capacity)` for eligible quota rows; document exclusions (e.g. unconfigured/zero capacity).
- Check-in counts per `booking_tickets.checked_in_at`; for pre-migration legacy `check_ins`, count booking quantity only when **no ticket rows exist**; use existing `BookingService::format`/`VisitService` semantics to prevent double counting.
- `attendance_rate` and `no_show_count` only for eligible finalized visit windows. If insufficient payment verification or date data exists, return `null` + reason `insufficient_verified_data`, **not 0**.
- Use destination timezone for visit-day boundaries; UTC instants for approvals. Multi-destination aggregation must reconcile timezone differences explicitly.
- Avoid counting non-finalized bookings, cancellations or expiry as sold/attended. Do not infer real payment amounts from booking total snapshots.
- Label financial metrics unavailable pending DOKU (Phase 1B).

### P1A-05. Dashboard overview & Action Center
- Admin dashboard: assigned destinations count, pending approvals, personal approval/rejection, SLA, actionable queue, checked-in tickets, visit/booking trends, freshness time, financial unavailable.
- Superadmin dashboard: global same metrics, destination comparison, staff workload/decision attribution, critical pending operations, ability to filter by destination and staff (where permitted).
- Action center prioritizes approaching approval deadline, pending queue, expired claims, upcoming visits and known QR issuance errors **only when grounded in existing error records**. Do not fabricate payment reconciliation alerts before 1B.
- Data calculated by Laravel; paginated lists; no expensive all-record loading in Flutter.
- Stable filter contract: `from`, `to`, `destination_guid`, `date_basis`, `per_page`, optional `admin_guid` Superadmin-only. Reject or ignore unauthorized scope requests **explicitly**; never expand scope.
- Prefer service separation: `AdminDestinationScopeService`, `ApprovalQueueService`, `ApprovalAnalyticsService`, `AdminPerformanceService`, `DestinationAnalyticsService`, `OperationalDashboardService`; reuse existing code patterns and avoid over-abstraction.

---

## 6. Proposed API request/response contract (additive)

All paths below are relative to `/api/v1`, protected with current auth middleware. Check actual path conflicts before creating them.

| Method | Path | Actor | Response/use |
|---|---|---|---|
| GET | `/admin/dashboard/overview` | 1 scoped, 2 global | KPI, period, scope, financial unavailable |
| GET | `/admin/dashboard/approvals` | 1 scoped/personal, 2 global | SLA + period series + actor stats |
| GET | `/admin/dashboard/destinations` | 1 scoped, 2 global | Paginated per-destination metrics |
| GET | `/admin/dashboard/visitors` | 1 scoped, 2 global | Check-in/attendance/finalized no-show |
| GET | `/admin/dashboard/action-center` | 1 scoped, 2 global | Prioritized actions (paginated) |
| GET | `/admin/approval-queue` | 1 scoped, 2 global | Queue with claim state, expires_at and pagination |
| POST | `/admin/bookings/{booking}/claim` | 1 assigned, 2 authorized | Claim; require idempotency key |
| POST | `/admin/bookings/{booking}/release` | Owner or 2 | Release active claim; idempotent |
| POST | `/superadmin/bookings/{booking}/reassign` | 2 only | Reassign claim to active scoped Admin; require reason and key |
| GET | `/bookings/{booking}/payment-preview` | Booking owner | Feature-flagged view-only DOKU-ready preview |

Existing `POST /admin/bookings/{booking}/approve` and `/reject` **remain**, with additional assignment/claim check and compatibility rollout mode. Existing resource fields stay present.

### Sample request
`GET /api/v1/admin/dashboard/overview?from=2026-11-01&to=2026-11-30&date_basis=decision`

### Sample `200` response — illustrative only
```json
{
  "data": {
    "role": "admin",
    "scope": {"mode": "assigned_destinations", "destination_guids": []},
    "period": {"from": "2026-11-01", "to": "2026-11-30", "date_basis": "decision"},
    "metrics": {
      "pending_approvals": 0,
      "approved_decisions": 0,
      "rejected_decisions": 0,
      "approval_rate_percent": null,
      "avg_response_seconds": null,
      "sla_compliance_percent": null,
      "checked_in_tickets": 0
    },
    "financial": {
      "availability": "unavailable",
      "reason": "payment_gateway_not_integrated",
      "gross_revenue": null,
      "net_revenue": null
    }
  },
  "meta": {"generated_at": "2026-11-04T14:00:00Z"}
}
```
**Important:** Zero counts above are illustrative of an empty authorized dataset. In a real response, distinguish `0` from `null` (not computable/unavailable). Do not return sensitive raw admin identifiers in role-1 team views unless needed and allowed.

### Claim endpoint illustrative contract
`POST /api/v1/admin/bookings/{booking_guid}/claim` with `Authorization: Bearer ...`, `Idempotency-Key: <uuid>`, body optionally `{}`.

Success `200/201`: `data.booking_guid`, `data.claim_guid`, `data.owner_user_guid` (only if authorized), `data.expires_at`, `data.approval_expires_at`, `data.state="active"`; `meta.generated_at`.

Conflict `409` response should follow existing error conventions, with stable `code` e.g. `claim_owned`, `claim_expired`, `booking_not_pending`, `approval_deadline_passed`, `claim_not_owned`, `claim_conflict`; never expose other-user personal information unnecessarily. Retry of the same idempotent request should not duplicate claim or audit events.

### Filter semantics
- `date_basis=decision` for decisions/SLA; `created` for creation counts; `visit` for attendance; avoid silently mixing bases.
- All custom date ranges inclusive as calendar dates at clearly specified time boundaries; return basis and timezone in response.
- `destination_guid` for role 1 must be in active assignment. `admin_guid` filter is Superadmin-only; for role-1 personal stats infer actor from JWT.
- If a requested metric cannot be computed, return `null` and `reason`/`availability`, not an invented zero.

---

## 7. Migration and concurrency strategy

1. No editing historical migrations. Use timestamped forward-only migrations for claim state/history and minimal audit if absent. Add FK, unique and indexes aligned with existing UUID/GUID schema. Protect existing data and support rollback where safe.
2. Do **not** duplicate `destination_admin_assignments`, `destination_admin_assignment_histories`, `booking_status_histories`, `idempotency_records`, `booking_tickets`, or `ticket_check_ins`.
3. Define consistent lock ordering across `QuotaService`, `BookingService`, ticket services and assignment writes; preserve existing quota→booking lock where applicable, but review destination lock acquisition interactions before implementation.
4. Claim creation needs uniqueness / compare-and-set against concurrent inserts and expiry. Use bounded DB deadlock retry as per existing pattern. Only one winning claim.
5. Validate role/assignment freshness during claim and final decision; permission check alone prior to DB transaction is insufficient.
6. Ensure booking cancel/expiry invalidates actionable claim. Claim record/history should not alter booking status by itself.
7. Aggregate queries require period/status/date indexes as justified by EXPLAIN/data volume, avoiding speculative wide indexes.
8. Historical audit must be append-only for domain actions and preserve actual actor; no secret/PII snapshots.

---

## 8. Targeted verification (no broad QA loops)

Repository `AGENTS.md` requires behavior tests and Pint for PHP changes. Run **only the narrowest affected** Pest tests, not the entire regression suite by default. For concurrency correctness, targeted MySQL-backed scenarios are warranted.

### Minimum high-value cases
- Role 0 cannot access any admin dashboard/queue/action; inactive role 1/2 blocked.
- Role 1 with zero assignment receives scoped empty list and cannot access other destination booking or image/quota mutation.
- Role 1 assigned to destination A cannot read/mutate destination B; role 2 can where permission allows.
- Assignment removal between list and approve prevents approval; reassignment history preserved.
- Two Admins claiming same pending booking concurrently produce exactly one active claim; race loser gets stable 409.
- Retry same claim/release/reassign key does not duplicate state/events.
- Claim expires, new eligible Admin can claim; approval 24-hour expiry unchanged.
- Approve/reject cannot race with another decision, booking cancellation, expiry or assignment change.
- Legacy approve/reject compatibility in `compat` mode respects existing claim ownership; `strict` requires explicit claim.
- KPI cannot double-count a booking due to multiple Admin assignments or ticket rows.
- Legacy check-in fallback does not duplicate per-ticket check-ins; no-show remains unavailable until eligible finalized data.
- Payment preview **never** writes paid, confirmed or QR issuance and never invokes DOKU.

Commands following repo conventions (adapt paths to created tests):
```bash
php artisan test --compact tests/Feature/AdminApiTest.php
php artisan test --compact tests/Feature/BookingApiTest.php
php artisan test --compact tests/Feature/SmartTicketSmokeTest.php
# New narrow feature tests only where corresponding behavior is changed.
# MySQL concurrency test for claim/approval race, with an isolated test database.
vendor/bin/pint --dirty --format agent
```
Do not launch full-suite regression/analyze or invasive data reset without explicit need. No `migrate:fresh`, `db:wipe`, `db:seed --force` on non-disposable databases. If tests/environment are unavailable, report blockers and exact unrun verification rather than claiming PASS.

---

## 9. Work sequence and checkpoints

| Step | Work package | Gate |
|---|---|---|
| P0.1 | Local worktree audit, `AGENTS.md`, `.ai/rules`, baseline schema/response inventory | Verified current code and scope |
| P0.2 | Contract decisions, payment preview non-mutating seam, compatibility report | No DOKU calls, existing payment guard unchanged |
| P1A.1 | Centralized Admin destination-scope authorization | Role 1 leaks closed across read/write paths |
| P1A.2 | Shared approval claim, release, expiry, Superadmin reassignment | One owner, safe concurrent decision, history |
| P1A.3 | Decision/assignment audit and KPI semantic service | Historical actor attribution preserved |
| P1A.4 | Destination/visitor/SLA analytics, dashboard/action center endpoints | Scoped, time-bounded, correct metric definitions |
| P1A.5 | Narrow tests, API examples, route list, handoff and migration note | Flutter-ready server contract; no 1B implementation |

### Definition of done — Phase 0
- Audit/gap/compatibility artifacts exist; confirmed local code versions.
- Payment contract documented; DOKU disabled, no provider secrets/calls or fake state transitions.
- No regression to legacy booking/QR status behavior.

### Definition of done — Phase 1A
- All Admin-sensitive existing endpoints checked for assignment scope; policy and service enforced.
- One claim owner enforced with atomic concurrency and audit; safe migration path for existing Flutter.
- Operational KPI and action center API respond accurately for role 1 and 2, with proper filters/limits/date basis.
- Financial data unavailable/null; no simulated revenue presented as real revenue.
- Focused tests/format completed or explicitly reported unrun; old routes not silently removed.
- Handoff includes route table, example JSON, migration list, changed files, rollout config and remaining Phase 1B dependencies.

---

## 10. Coverage traceability — canonical 25 decisions

| Decision | Phase 0/1A treatment | Future dependency |
|---|---|---|
| 01. Destination + personal Admin metrics | 1A scoped aggregates and actor attribution | Full historical comparisons later |
| 02. Financial overview | 0 unavailable contract | 1B verified payment/fees/refunds |
| 03. Approval performance | 1A complete core metrics/SLA | Expanded reports in 2 |
| 04. Role-based visibility | 1A mandatory RBAC | Granular financial scope in 1B |
| 05. Many-to-many assignment | Reuse existing schema, 1A enforce | Long-term historical analysis in 3 |
| 06. Period analytics | 1A standardized filters/date basis | Comparisons/growth in 2 |
| 07. Destination & visitor | 1A operational analytics | Advanced benchmarking in 2 |
| 08. Payment intelligence | 0 display contract only | 1B–2 |
| 09. Refund flow | No financial mutations | 2, gated by gateway support |
| 10. Smart action center | 1A core operations | Alerts/escalation in 2 |
| 11. Executive oversight | 1A Superadmin overview/drilldowns | Expanded executive analytics in 2 |
| 12. Financial reporting/export | 0 response/schema readiness | 2 after 1B |
| 13. Alerts/escalation | 1A action-center readiness | 2 |
| 14. Workload fairness | 1A claim/workload facts | 2 workload intelligence |
| 15. Audit/accountability | 1A minimal domain audit | 2–3 advanced auditing |
| 16. Dashboard hub/detail | 1A APIs only; Flutter later | Flutter delivery |
| 17. Near-real-time refresh | 1A freshness metadata / polling-friendly | Adaptive Flutter polling |
| 18. Financial reconciliation | 0 safe seam and `unverified` | 1B base, 3 advanced |
| 19. Settlement transparency | Unavailable marker | 3 gateway dependent |
| 20. Financial RBAC | 1A generic scope foundation | 1B financial layers |
| 21. KPI targets | 1A metric definitions | 2 target config |
| 22. Fair leaderboard | 1A actor-level data accuracy | 2 |
| 23. Shared queue ownership | 1A full | 2 fairness enhancements |
| 24. Historical integrity | 1A claim/status/assignment audit | 3 lifecycle/archive |
| 25. Phased development | Enforced stop at Phase 1A | 1B → 2 → 3 |

---

## 11. Codex subagent governance (MAX 3)

**IMPORTANT:** This document does **not** claim local Windows agents have been invoked. They must be loaded and delegated **by Codex running on the user's Windows machine**.

- Read `C:\Users\itsba\.codex\Agents.md` when present, the repository's `AGENTS.md`, applicable `.ai/rules`, and the agent definitions in `C:\Users\itsba\.codex\agents\`.
- **Use at most THREE subagents total** (not three at a time plus extras):
  1. `system_architect` — baseline audit, schema/lock-order/contract design and compatibility review.
  2. `backend_engineer` — Laravel implementation for Phase 0 + 1A.
  3. `security_auditor` — targeted RBAC/claim/idempotency/payment-guard review.
- Main Codex session is the orchestrator. **Do not spawn `master_orchestrator` as a fourth subagent**. No Flutter/frontend/devops/QA subagent is needed for this backend-only scope.
- The screenshot shows `.toml` agent definition files (Windows may hide extensions); verify real filenames exist rather than inventing them. Use Codex's supported multi-agent/subagent mechanism. If it is unavailable, work sequentially in the main session and report the limitation; never claim delegation occurred when it did not.
- Restrict each subagent's work and avoid simultaneous edits to the same file. Architect establishes boundaries; backend engineer edits; security auditor reviews the exact diff and high-risk cases.
- Do not commit/push to remote unless separately authorized. Do not touch Flutter code. Avoid wholesale refactors, dependency changes and full regression test loops.

## 12. Final implementation handoff template

At completion, Codex should report concisely:
1. Local branch + HEAD and changed files.
2. Phase 0 items implemented / docs/contracts only, with reasons.
3. Phase 1A modules implemented and endpoints added/changed.
4. Schema/migration changes and any manual deployment steps.
5. Exact role 1 vs role 2 authorization behavior.
6. Claim state machine and legacy `compat|strict` rollout.
7. KPI definitions, nullable/unavailable financial fields, relevant indexes.
8. Focused test commands/results and unrun tests; formatting results.
9. Remaining Phase 1B DOKU integration prerequisites and Flutter integration TODOs.
10. Known gaps/risks; no invented 'all tests passed' statements.

**Scope boundary: STOP after Phase 0 + Phase 1A.** Phase 1B, Phase 2 and Phase 3 are only architectural considerations here, not permission to implement them.
