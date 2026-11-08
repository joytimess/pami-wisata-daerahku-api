# PRD — Fix DOKU DANA `provider_unavailable` / `unknown`

**Project:** PAMI Wisata Daerahku — Laravel Backend API  
**Document:** PRD-FIX-DOKU-DANA-001  
**Version:** 1.0  
**Date:** 8 November 2026  
**Status:** Ready for implementation review (not an implementation report)  
**Primary scope:** Backend observability, error classification, and safe diagnosis of DANA Sandbox payment initiation  
**Related baseline:** `docs/prd/PRD_DOKU_Multichannel_Backend_v2.0.md`, `docs/prd/DOKU-RUNBOOK.md`, `docs/prd/DOKU-CHANNEL-MATRIX.md`  
**Out of scope:** QRIS/other VA/OVO new adapters, Flutter redesign, Production activation, replay/new provider charge during diagnosis

---

## 1. Executive summary

An attempted DANA payment from the Flutter Android Emulator reaches Laravel, but ends with `status=unknown`, `last_error_code=provider_unavailable`, no `provider_reference`, and `presentation_type=unavailable`. The user sees **“Status pembayaran belum pasti. Periksa kembali; jangan membayar ulang.”**

The current error handling does **not** retain the DOKU HTTP status or safe diagnostic response codes. `provider_unavailable` therefore combines several possible causes: failed B2B token acquisition, non-2xx create response, malformed response, and an internal exception. **No exact root cause has been established** for the observed attempt.

**Goal:** Make the next controlled investigation diagnostic without exposing secrets, changing payment truth, creating duplicate payment attempts, or relaxing existing security controls.

## 2. Known evidence and unresolved questions

### Observed attempt (reported from local database / logs)

| Field | Value |
| --- | --- |
| `attempt_guid` | `2275f0af-6d08-4b62-ab8e-91288395f6ed` |
| Provider / environment | `doku` / `sandbox` |
| Method / channel | `ewallet` / `dana` |
| Amount | `25 IDR` |
| Status | `unknown` |
| `last_error_code` | `provider_unavailable` |
| Provider reference | Empty |
| Presentation type | `unavailable` |
| Local log | `DOKU initiation resolved`, status `unknown`, latency `710 ms` |

### Code trace reported by local investigation (to verify against current working tree)

1. `DokuPaymentService.php` around lines 106–110 calls `DokuClient::create()`; broad `catch (Throwable)` maps to `provider_unavailable`.
2. `DokuClient.php` around lines 114–115 dispatches to `DokuDirectDebitAdapter::create()`.
3. `DokuDirectDebitAdapter.php` around lines 59–65 classifies connection exceptions as `provider_timeout` and runtime/JSON exceptions as `provider_unavailable`.
4. `DokuClient.php` around lines 322–346 can throw on failed token acquisition or non-2xx / unusable payment response.
5. `DokuPaymentService.php` around lines 125 and 143 stores `unknown` and the generalized error code.

**Not yet known:** The original DOKU create HTTP status; `responseCode`; whether token issuance failed at that time; whether the payment endpoint returned a valid/invalid payload; or whether an internal exception occurred. A later B2B token request returned HTTP 200 / `2007300`, but is not proof about the earlier attempt. The Rp25 amount and absence of `X-IP-ADDRESS` are hypotheses, **not proven defects**.

A separate prior Laravel `bootstrap/cache/services.php` Windows `Access is denied (code: 5)` error occurred at another time. It must not be assumed to explain the DANA `unknown` without a shared trace.

## 3. Problem statement and expected outcome

**Today:** A failure at a specific network/application phase collapses into `provider_unavailable`, with too little safe evidence to diagnose it. The frontend correctly refrains from offering a blind retry.

**After the fix:** An authorized developer can identify whether the failure occurred at B2B token, payment create HTTP response, payment response decoding, redirect validation, or internal Laravel code; can see a **bounded, redacted** reason; and can make an informed recovery decision **without** sacrificing the `unknown` safety state.

### Measurable success criteria

- Every **new** DANA create failure produces an internal `phase`, stable `diagnostic_code`, `attempt_guid` (if known), `http_status` (if received), safe provider `responseCode` (if received), and a typed exception category where applicable.
- Never log raw request/response bodies, access tokens, client/secret keys, signatures, full redirect URLs, personal information, or payment instruments.
- All pre-existing payment state transitions, locking, idempotency and ticket-issuance rules continue to pass regression tests.
- The existing `unknown` attempt remains unchanged until verified provider reconciliation / authorized operator resolution; no new payment request is created by this PRD implementation.

## 4. Scope and non-goals

### In scope

- Trace and categorize failure in the reusable DOKU HTTP/token client and DANA adapter.
- Structured safe diagnostics correlated to `attempt_guid` and a request/correlation ID.
- Typed internal errors, with backward-compatible external API and persisted attempt semantics.
- Explicit distinction between **no request sent**, **request possibly sent**, and **provider response received** (issuance uncertainty).
- Automated unit, integration, transport fake and security regression tests; runbook updates.
- Documentation for an operator to reconcile an `unknown` attempt before requesting a new one.

### Explicitly out of scope

- Retrying `create` automatically, creating a replacement attempt, or clearing `unknown` on unsupported evidence.
- Changing Laravel `.env`, public tunneling, Sandbox credentials, database application records, or Production deployment.
- Changing the Flutter UI or opening the DANA page by forging a redirect.
- Reclassifying a redirect or HTTP 2xx response as proof of `paid`.
- Adding a new database migration unless demonstrably necessary and separately approved.
- Fixing the 21 pre-existing unrelated booking/admin test failures under this change.

## 5. Functional requirements

| ID | Priority | Requirement | Acceptance criterion |
| --- | --- | --- | --- |
| FR-01 | P0 | Emit a safe structured record at DOKU B2B token and DANA create boundaries. | Failed request identifies `b2b_token` or `payment_create` phase; log record has safe correlation. |
| FR-02 | P0 | Preserve received HTTP status on error. | For 400/401/422/429/5xx fixtures, logs distinguish status and phase. |
| FR-03 | P0 | Parse `responseCode` only from bounded JSON with expected structure. | Well-formed safe code surfaced; malformed/HTML response classified without logging body. |
| FR-04 | P0 | Separate failure categories. | Token failure, HTTP failure, invalid JSON/schema, connection timeout, redirect rejection, and internal exception have distinguishable diagnostic codes. |
| FR-05 | P0 | Keep external API and persisted status backward compatible. | `unknown`, current `payment_status`, idempotency and client copy remain valid; no unsafe new `failed`/`paid` transition. |
| FR-06 | P0 | Keep provider request strictly outside long DB transactions. | Concurrent create uses the existing single-attempt reservation and does not create multiple provider charges. |
| FR-07 | P0 | Enforce strict log redaction and bounded retention. | No secrets or PII in logs under successful/error fixtures, including malformed content and exception text. |
| FR-08 | P0 | Do not re-initiate unresolved attempt automatically. | Diagnostic change makes zero provider create calls for existing `unknown` attempts. |
| FR-09 | P1 | Provide an actionable runbook for unresolved requests. | Flow distinguishes reconcile/status query, unavailable provider reference and manual provider escalation. |
| FR-10 | P1 | Preserve channel and environment isolation. | DANA diagnostics cannot settle another channel, or mix Sandbox and Production. |

## 6. Proposed error taxonomy (internal only)

These are **proposed stable internal diagnostic codes**; inspect existing exception types and codes before finalizing names.

| Diagnostic code | Example | Payment issuance certainty | Status behavior |
| --- | --- | --- | --- |
| `doku_token_http_error` | Token endpoint returns 401/500 | Payment create not attempted **if trace confirms no create dispatch** | Preserve existing API/state policy; report phase |
| `doku_token_response_invalid` | Token HTTP 200 but missing usable token | Payment create not attempted if not dispatched | Preserve policy |
| `doku_payment_http_error` | Payment endpoint HTTP 400/422/500 | **Possibly issued** unless authoritative evidence says otherwise | Keep `unknown` by default |
| `doku_payment_response_invalid` | HTTP 2xx, invalid JSON/contract | **Possibly issued** | Keep `unknown` |
| `doku_payment_transport_timeout` | Timeout after request may have been sent | **Possibly issued** | Keep `unknown` |
| `doku_redirect_host_rejected` | Unallowlisted / non-HTTPS payment URL | **Possibly issued** | Keep safe hold; no redirect to Flutter |
| `doku_internal_exception` | Unexpected TypeError / bug | Depends on exact phase; default conservative | Keep `unknown` unless pre-dispatch proven |

**Important:** Error classification improves observability; **it must not by itself authorize a new attempt**. Do not assume that HTTP 4xx necessarily proves payment was not created; use documented provider semantics or reliable inquiry evidence.

### Public vs internal reason

- Public Flutter response: keep existing safe `reason`/`status` contract, including “don't pay again” for unresolved outcomes.
- Persisted attempt: keep `last_error_code` contract unless a controlled backwards-compatible migration is justified; store diagnostic breakdown in application logs or sanctioned diagnostic fields without exposing provider messages to end users.
- Operator logs: stable diagnostic code + bounded provider code only, never raw details.

## 7. Safe telemetry schema

Example **design**, not observed output:

```json
{
  "event": "doku.provider_request_failed",
  "attempt_guid": "<uuid>",
  "provider": "doku",
  "environment": "sandbox",
  "channel": "dana",
  "phase": "payment_create",
  "http_status": 422,
  "provider_response_code": "<sanitized-code-or-null>",
  "diagnostic_code": "doku_payment_http_error",
  "exception_class": null,
  "duration_ms": 710,
  "request_sent": true,
  "outcome_certainty": "unknown"
}
```

Rules:

- `provider_response_code`: only scalar string/number matching a strict safe character/length allowlist (e.g. `^[A-Za-z0-9_-]{1,32}$`); otherwise `null`.
- **Do not log raw `responseMessage`** by default. If useful, map a curated set of documented provider codes to internal non-sensitive descriptions; never pass arbitrary provider message directly into logs or user response.
- `exception_class`: class/controlled category only; not exception message, stack trace, request body, URL with query, or headers.
- `request_sent`: `false|true|unknown` based on actual transport boundaries, not inferred from elapsed time alone.
- Log only bounded identifiers; enforce application log access controls and retention in deployment policy.
- Never log the B2B bearer token or full HTTP authorization/signature headers, even on debugging modes.
- Avoid logging original raw JSON on signature or parsing failure.

## 8. Error-flow behavior

1. At orchestration entry, correlate the **existing** `attempt_guid` and deterministic request identifier; no new attempt generated.
2. During token acquisition, log safe phase/status diagnostics; distinguish provider rejection from transport/shape errors.
3. During payment creation, record a safe HTTP status and sanitized `responseCode` before a non-2xx response is converted into an exception.
4. Parse successful response and validate `webRedirectUrl` against existing HTTPS/domain allowlist; preserve current `provider_response_mismatch` behavior if invalid.
5. Exceptions propagate as typed internal outcomes to orchestration; remove blanket classification that hides the type, **without** exposing secrets or changing existing booking state transitions.
6. Store and emit `unknown` for uncertain issuance; show safe frontend recovery message; do not blindly retry.
7. Inquiry/reconciliation may run only using **already supported, authenticated provider reference/query contract**. If reference unavailable, mark for investigation and do not call create again.

## 9. Files/modules to inspect and likely update

Use the **current local source** as truth for paths, signatures, and line numbers. Candidate files based on reported investigation:

- `DokuClient.php` — token/create HTTP transport, safe response introspection before exceptions.
- `DokuDirectDebitAdapter.php` — DANA response checks and typed propagation; keep allowlist/redirect verification.
- `DokuPaymentService.php` — consistent outcome classification, attempt correlation, no blanket masking of internal errors.
- Existing DOKU transport and DANA payment Pest tests — red/green tests for the new failure taxonomy.
- `docs/prd/DOKU-RUNBOOK.md` and `docs/prd/DOKU-CHANNEL-MATRIX.md` — operational troubleshooting and provenance updates.

Do not hardcode new hostnames, disable validation, or alter `.env` within this change.

## 10. Test plan / acceptance matrix

| Test ID | Stimulus | Expected |
| --- | --- | --- |
| T01 | Token HTTP 401/500 | Phase `b2b_token`, safe HTTP status, no payment create dispatched |
| T02 | Token HTTP 200 with invalid JSON/missing token | `doku_token_response_invalid`; no payment create |
| T03 | Payment HTTP 400/401/422/429/500/503 | `payment_create` with exact HTTP status, safe code; attempt remains `unknown` by default |
| T04 | Payment HTTP 2xx with malformed JSON or wrong structure | `doku_payment_response_invalid`; no misleading success |
| T05 | Payment HTTP 2xx with non-success `responseCode` | Existing `provider_create_unresolved` behavior preserved; diagnostic includes safe provider code |
| T06 | Valid response with off-allowlist / HTTP redirect | Redirect rejected; never sent to Flutter; no paid |
| T07 | Timeout / connection exception | Separate transport diagnostic; `unknown`; no automatic extra create |
| T08 | Unexpected TypeError / RuntimeException | Internal exception category; no secret/leaky exception text in logs |
| T09 | Secret/PIN/PII injected into provider error body | Logs and API omit raw content, headers, tokens and signatures |
| T10 | Duplicate POST same idempotency key | Reuse existing attempt; at most one provider create dispatch |
| T11 | Unknown attempt recovery | Status query/investigation only; zero new create calls |
| T12 | Cross-channel and environment mismatch | No other channel/environment can settle or mutate attempt |
| T13 | Valid authenticated paid inquiry | Unchanged pre-existing verified settlement and ticket behavior |
| T14 | Run all payment/booking/ticket tests | No new failing tests versus documented baseline |
| T15 | MySQL race tests (when local DB available) | Existing guarantees still hold; capture actual test output |

**Test baseline:** The reported latest full suite was **428 passed / 449 total**, with 21 pre-existing failures unrelated to this diagnostic change. Record a fresh baseline before editing; “no regression” means the same failures, not that the entire suite is green. Prior runbook results are historical evidence, not a substitute for rerunning tests.

## 11. Rollout and investigation gates

### Gate A — Diagnostic patch (approved scope)

- Implement tests and logging/classification, run Pint, `git diff --check`, relevant routes, and targeted payment regressions.
- Keep existing attempt `2275f0af-6d08-4b62-ab8e-91288395f6ed` untouched.
- No new real provider create calls, no new payment attempts, no Production toggle, and no `.env` / application database changes.

### Gate B — Safe assessment of the existing unknown attempt

- Query its persisted state without sensitive data.
- If a provider reference or trusted lookup route exists, use **authenticated** inquiry in read-only diagnostic mode; no auto-creation.
- If the create request might have reached DOKU and there is no queryable reference, document **unresolved issuance** and escalate through the available merchant/DOKU resolution channel. Do not infer non-issuance from missing local reference.
- Any operator state resolution requires authorized evidence and existing audited procedures; no manual forced `paid`.

### Gate C — Controlled Sandbox retry (requires a separate explicit approval)

- Only after prior uncertainty is resolved by authoritative evidence or a reviewed safe-recovery procedure.
- Ensure merchant service/channel, HTTPS return, redirect allowlist and Sandbox configuration are valid.
- Run **one** authorized new DANA payment test with traceable correlation; capture only the safe telemetry fields and post-result inquiry.
- Success for this gate requires an actual DOKU Sandbox create response and valid redirect; **end-to-end success** additionally requires authenticated paid proof, confirmed booking and expected ticket behavior.

## 12. Definition of Done

- [ ] The original failure category can be diagnosed from **new** safe logs, without secret disclosure.
- [ ] Token errors, create HTTP errors, response parse errors, redirect failures and internal exceptions are distinguishable.
- [ ] DANA/BNI/ShopeePay and DEMO regressions pass; no new failures beyond the known baseline.
- [ ] Unknown status, locks, dedupe, retries, reconciliation, settlement and ticket invariants remain intact.
- [ ] No changes to `.env`, credentials, provider keys, application DB or Production settings as part of the fix.
- [ ] No new DOKU payment attempt triggered by diagnostics.
- [ ] Runbook updated with troubleshooting and safe next-step decision tree.
- [ ] Actual provider root cause remains **unconfirmed** until a legitimate subsequent trace or authoritative provider evidence is captured.

## 13. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Secret/PII leakage in diagnostics | Strict field allowlist; no raw body/message/headers; dedicated redaction tests |
| Incorrectly retrying an issued payment | Keep `unknown`, preserve idempotency, require authoritative resolution |
| Misclassifying HTTP errors as guaranteed failed | Separate diagnostic reason from payment state; conservative uncertainty |
| Changing shared client breaks BNI or ShopeePay | Shared transport regression suite + per-channel tests |
| Cache/Windows filesystem errors confused with provider errors | Independent trace, phase correlation and distinct internal exception category |
| Excessive logs | Emit on failure/limited completion only, constrain volume, retention and access |

## 14. Codex / Claude Code execution instructions

1. Inspect latest `main` working tree; do not overwrite or discard uncommitted changes.
2. Follow project `AGENTS.md`, installed local Superpowers skills if present, and test-first workflow. Use up to three actual locally available subagents if useful; do not claim usage otherwise.
3. Establish baseline: `git status`, payment tests, current relevant code and prior 21 baseline failures.
4. Implement **Gate A only**, using small, reviewable changes and safe logs.
5. Run targeted Pest, security/redaction tests, Pint, `git diff --check`; run MySQL concurrency only if environment available and without extracting/copying credentials.
6. Report changes, failing/passing checks, diagnostic example using **synthetic sanitized data**, and whether existing unknown attempt remains untouched.
7. **Stop before Gate C**. Request permission for any new DOKU payment attempt or live external action.

---

**Final decision:** Fix diagnostic visibility first, keep current `unknown` attempt protected, and do not use a new payment attempt as a shortcut to discover the provider's error.
