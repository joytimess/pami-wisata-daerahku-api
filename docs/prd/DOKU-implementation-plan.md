# DOKU implementation plan

PRD v1.0 implementation, authorized 8 November 2026. No commit or deployment.

1. Audit existing payment schema, quota lock order, booking expiry, ticket and notification outbox. Reuse these contracts; retain DEMO behavior.
2. Implement and test fail-closed DOKU config and official BNI SNAP v1.1 DGPC transport/signatures. Keep channel flags disabled by default.
3. Add create-attempt route/request, durable initiating reservation, idempotency and encrypted instruction recovery. Perform provider requests outside database transactions. Test ownership, retries, expiry, conflicts and uncertain responses.
4. Persist verified, deduplicated notifications before acknowledgement; apply via retryable job. Test exact snapshot/reference/environment matching, timely and late capture, reversal, duplicate effects and ticket provenance.
5. Add bounded scheduled reconciliation and durable event replay with safe backoff. Unknown issuance without a VA remains unresolved: the official inquiry requires a VA, so never recreate automatically.
6. Run narrow payment/booking/ticket/finance regressions, isolated MySQL races when available, Pint, route inspection and independent security review. Document actual evidence and Sandbox/Production blockers.

Owned files: root payment orchestration, eligibility, API/controller/request, migration, jobs/command/schedule, tests and runbook; backend agent DOKU transport/config and transport tests; architect/security agents read-only.

Live channel onboarding, key registration, Notification URL and Sandbox simulator execution require external merchant setup. Automated fixtures cannot prove those requirements. QRIS/e-wallet stay unavailable until separately verified and implemented.
