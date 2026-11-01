# PRD — Forgot & Reset Password via Email OTP

**Project:** Wisata Daerahku  
**Repository target:** `C:\laragon\www\pami-wisata-daerahku-api`  
**Stack:** Laravel API-only · MySQL · Gmail SMTP · Database Queue  
**Date:** 2026-11-01  
**Implementation status:** SPECIFICATION ONLY — not yet implemented  
**Scope:** Laravel backend authentication only; Flutter changes explicitly excluded.

## 1. Objective

Replace the existing **forgot-password reset-link** workflow with an **email OTP** workflow without disturbing the existing JWT login, refresh rotation, Google/Firebase exchange, user-registration email verification OTP, or non-auth business modules. Users complete password recovery within the mobile app without navigating to a web reset link.

Scope is **forgot/reset password**, not account registration. Do not infer that role 1/2 should be excluded from password recovery merely because registration OTP is specific to role 0. Audit and preserve existing eligible-account and account-status policies, and document any role-specific behavior.

## 2. Mandatory agent assignment

Read global agent profiles, if available, from:

- **Primary implementer:** `C:\Users\itsba\.codex\agents\backend_engineer.toml`
- **Security gate:** `C:\Users\itsba\.codex\agents\security_auditor.toml`
- **QA / concurrency tests:** `C:\Users\itsba\.codex\agents\qa_engineer.toml`
- **Architecture consultation, only if needed:** `C:\Users\itsba\.codex\agents\system_architect.toml`

Use specialists through capabilities available to the Codex runtime; do **not** claim a TOML file was loaded as an agent configuration if the runtime cannot do so. The files are **global**, not project-local `.codex/agents/` files. Keep context use efficient; avoid unrelated audit or mass refactor.

## 3. Existing implementation to audit before edits

Inspect actual code and routes; do not assume file names below are exhaustive:

- `app/Services/AccountService.php` and actual authentication services
- `app/Notifications/QueuedResetPassword.php` and any reset notification/job
- `app/Http/Controllers/Api/V1/AccountController.php`
- `routes/api.php`; request and resource classes
- `app/Services/EmailVerificationOtpService.php` and registration verification OTP storage and queue code
- Existing JWT access/refresh rotation, `sessionGeneration` equivalent, device token revocation
- `config/auth.php`, `config/mail.php`, `config/queue.php`, `.env.example`, README
- Existing password broker/token repository and migration schema
- Related feature tests and `tests/Mysql/` concurrency infrastructure

Preserve thin controllers → Request validation → Service business logic → Resource/envelope output. Determine actual user primary key (`*_guid` etc.) from database schema.

## 4. Target user flow

1. Flutter requests password recovery using email.
2. Backend responds generically regardless of whether an eligible email exists; eligible account gets a fresh 6-digit reset OTP queued via Gmail.
3. Flutter presents OTP input. Backend validates the OTP with attempt limit and atomic single-use consumption.
4. Once verified, backend grants a **short-lived single-use opaque reset ticket**. Flutter holds this ticket only in memory while displaying the new-password screen.
5. Flutter submits ticket and new password to backend. Backend atomically consumes ticket, updates the password and revokes existing refresh/device sessions.
6. Flutter returns user to login. Backend does not implicitly create a new logged-in session.

This is a **three-step API flow**: request, verify, reset. Resend is a separate supplemental endpoint.

## 5. Proposed API contract — audit existing routes and minimize breaking changes

All responses must retain the existing `{ "data": ... }` envelope and existing validation conventions. Use existing endpoint names if appropriate; document final actual paths and payloads.

### A. `POST /api/v1/auth/forgot-password`

Body: `{ "email": "user@example.com" }`

Return `200` or an existing accepted-status response with generic message such as *"If the email is eligible, a verification code will be sent."* Do **not** expose account existence through response body, status, latency-sensitive alternate flows, or different rate-limit behavior. On the Flutter side, do not promise delivery; an accepted request is not delivery confirmation.

### B. `POST /api/v1/auth/password-reset/resend-otp`

Body: `{ "email": "user@example.com" }`

Return generic acknowledgement during cooldown and for unknown emails. Respect per-email cooldown and IP/email rate limits; successful resend replaces the previous code and invalidates outstanding grants for the same reset flow.

### C. `POST /api/v1/auth/password-reset/verify-otp`

Body: `{ "email": "user@example.com", "otp": "012345" }`

Success (200):

```json
{
  "data": {
    "reset_ticket": "<opaque-random-value>",
    "expires_in_seconds": 300
  }
}
```

The six-digit OTP is always a **string** to preserve leading zeroes. Invalid, expired, consumed, or locked OTP returns generic `422` without revealing which condition applies. Rate-limit where appropriate. OTP verification must consume OTP **atomically** and issue exactly one reset ticket.

### D. `POST /api/v1/auth/reset-password`

Body:

```json
{
  "email": "user@example.com",
  "reset_ticket": "<opaque-random-value>",
  "password": "new-password",
  "password_confirmation": "new-password"
}
```

Success: existing standard envelope and no JWT token. Invalid/expired/consumed reset ticket returns generic error. Do not accept the former `token` URL-reset payload as a parallel recovery bypass. Document transition behavior and old in-flight tokens.

The route names in this section are a **proposed contract** for Codex to reconcile against real current routes; avoid creating duplicate legacy and new paths without purpose.

## 6. OTP rules and storage

- Six digits from a cryptographically secure random generator; preserve leading zeroes.
- TTL **600 seconds** from issuance; maximum **5 incorrect attempts** per code.
- Resend cooldown **60 seconds**, with layered IP and normalized-email rate limiting and sensible longer-window caps to prevent email flooding.
- Store only a keyed HMAC (bound to user ID, normalized email, purpose `password_reset`, and challenge identity/version) or equivalently strong keyed verifier. Never store plaintext OTP in database, cache, logs, exceptions, analytics, or normal queue payloads.
- Do not reuse the registration-verification OTP namespace, token, row or validation path without strict purpose isolation. An email verification OTP must never authorize password reset, and vice versa.
- OTP is invalidated by successful verify, resend, expiry, attempt exhaustion, or other invalidation policy.
- Keep expiration and retry timestamps authoritative on the server; Flutter countdown is visual only.
- Reset ticket: cryptographically random >= 256 bits, store only hash, TTL **300 seconds**, single-use and bound to account and reset purpose; expose only in successful verify response. Never persist the ticket on the mobile device; document recovery from process loss (restart request flow).

## 7. Transaction boundaries, races and session revocation

- Ensure **real MySQL atomicity**, using DB transactions and consistent row locks / atomic conditional update. Lock before validation and consume OTP in the **same transaction** that issues its reset ticket.
- For reset, lock and validate the reset-ticket row before consuming it; password update, reset-ticket consumption, and session/device-token revocation must be in one consistent transaction on the intended connection.
- Test concurrent verify/verify, verify/resend, resend/resend, reset/reset, and reset/resend with separate real MySQL processes/connections. Exactly one reset succeeds per ticket; no stale OTP can override a newer flow.
- Do **not** perform SMTP network I/O while holding database row locks. Persist challenge, commit, then queue for sending after commit.
- Match existing refresh token rotation/reuse detection, existing device-token revocation and any access-token invalidation guarantees. Verify what happens to previously issued stateless access JWTs; if revocation is not immediate, state the limitation and assess existing policy without secretly redesigning JWT.
- Maintain replay protection and rollback consistency after DB exceptions; no grant should be reusable after successful reset.

## 8. Gmail SMTP and queue security

- Reuse existing real Gmail SMTP in `.env`; no real credentials in source control.
- Queue OTP messages **after commit**; Laravel supports `afterCommit`/`after_commit` semantics. Do not block API requests waiting for SMTP.
- Encrypt queued job payloads that must contain OTP plaintext; ensure `APP_KEY` is configured. Prefer a job whose plaintext never lands in job metadata, exception output, failed jobs, or logs.
- Worker should detect superseded/expired challenges before sending whenever possible, without holding an account lock during SMTP. Recognize that in-flight email delivery can reorder; only newest unexpired code is accepted.
- Reject unsafe mail transports (`log` or insecure fallbacks) for OTP delivery in the same spirit as existing registration OTP logic.
- Document `php artisan queue:work --tries=3 --backoff=60 -v` and `php artisan queue:failed`, and provide safe handling of retry/duplicate jobs.
- Replace old **reset-link email** for new requests with an email containing just a six-digit OTP, its expiry, and an anti-phishing notice. No `PASSWORD_RESET_URL` is required for this new flow.

## 9. Legacy reset-link transition

- Audit `QueuedResetPassword`, password broker and all related routes. **Disable or deprecate legacy link issuance and consumption** for forgot-password once OTP is active; otherwise legacy token consumption could bypass OTP controls.
- Determine handling of still-unexpired broker tokens issued before migration; recommend revoking/invalidating them during cutover and documenting user impact. Do not leave the old reset endpoint secretly functional.
- Keep other signed-link functionality (for example, legacy email verification) intact unless unrelated to this feature.
- Document `PASSWORD_RESET_URL` as no longer used for OTP password recovery; do not conflate this with `APP_URL` or account-verification settings.

## 10. Security requirements

- Generic responses to prevent account enumeration; avoid differentiated logs accessible to clients.
- Protection against brute force, replay, timing leakage, concurrent requests, and mail bombing.
- Constant-time digest comparison where applicable and purpose-specific authentication proofs.
- Never log OTP, `reset_ticket`, password, OAuth tokens or SMTP credentials; redact request bodies and failure traces as necessary.
- Validate email normalization and password strength through existing FormRequests; preserve existing account state and role policy.
- Rate limiting should be enforced server-side with atomic counters for high concurrency.
- Do not expose reset tickets to GET URLs; API only.
- Security auditor must flag and resolve Critical/High findings before marking ready.

## 11. Tests and gates

**Feature tests:** request response for registered/unregistered emails; OTP format and duration; invalid, expired, reused and exhausted OTP; 60-second resend; invalidation on resend; verified ticket issued only once; ticket expiry/reuse; password confirmation validation; password changed and old password rejected; old refresh/device tokens invalidated; no implicit login; exact response envelopes.

**Race tests on actual MySQL:** independent workers/connections, repeated verify/verify, reset/reset, verify/resend, resend/resend, reset/resend; deadlock/retry and rollback behavior; same DB connection for token and account updates.

**Queue tests:** fake mailer with encrypted queue payload and after-commit semantics, no leakage to `log`/failed jobs, stale jobs ignored, retries safe, SMTP errors correctly reported to operator, not to user as delivery success.

**Regression:** register/email-verification OTP for role 0, Google/Firebase exchange, unified admin/user login, JWT refresh rotation, session guards, logout, all existing Laravel tests.

**Local E2E still required:** real Gmail reception of a *password reset OTP* and end-to-end Android recovery only after Flutter implementation. Mock tests must not be labelled E2E PASS.

**Verification commands (adapt to project tooling):**

```powershell
php artisan test
php artisan route:list --path=auth
php artisan queue:failed
vendor\bin\pint --test
```

Run the existing MySQL concurrency suite on **isolated test DB**. Do not run destructive database operations against primary development or production data.

## 12. Deliverables

1. List of modified files with minimal reasons for changes.
2. Final contract (actual paths, request/response examples, HTTP statuses) ready for Flutter handoff.
3. Transaction and concurrency design plus MySQL test output.
4. Security audit findings / remediations.
5. Results for full Laravel tests, focused OTP tests, Pint, and diff check.
6. Queue & Gmail operator runbook.
7. Exact notes on retirement of reset-link route and `PASSWORD_RESET_URL`.
8. Status table: PASS / FAIL / NOT VERIFIED / BLOCKED, especially for real email delivery and Android E2E.

## 13. Restrictions

- Laravel API-only: no web reset page, no Flutter changes, no Cloudflare tunnel/deep-link work.
- Do not alter registration OTP, Google Sign-In, admin authentication policy, JWT internals (beyond preserving required reset revocation), or business modules (catalog, booking, favorites, payments, check-in, reviews).
- Do not commit, push, deploy, or change production secrets.
- Inspect before changing; don't blindly recreate existing services, tables, queues, or endpoints.
- No claim of COMPLETE from mock-only tests or unavailable environment checks.

## 14. Codex execution request

Implement this PRD **only** in the local Laravel backend. Load/check available global agent profiles listed in Section 2; assign backend implementation to `backend_engineer`, independent security review to `security_auditor`, and feature/MySQL concurrency coverage to `qa_engineer`. Reconcile API paths with current routes, prefer minimal changes, preserve the existing auth system, test thoroughly, and finish with a precise Flutter handoff and honest verification statuses. Do not touch Flutter or unrelated modules.
