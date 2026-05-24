# Phase 4 Opus Second-Pass Blockers

Date: 2026-05-23

Reviewer result: Not approved.

Findings accepted for fix:

1. Discount approvals still used legacy `/pos/auth/verify-pin` and dropped approval evidence before receipt authoring.
2. Account-charge override evidence could be supplied without locally authoring the approval and override fiscal events.
3. Cash drawer deposit/payout controls accepted nullable/pass-through approval evidence, lacked offline parity, and online payloads missed `shift_id`.
4. Offline PIN data exposed current-company/current-terminal approval scope to every tenant PIN user.
5. Phase 4 server and device payload validation did not strictly validate account-status, approval, and override payloads.
6. Account-status transitions allowed states outside the locked Phase 4 lifecycle.
7. Tender tolerance and void/return hooks were not wired or fail-closed.
8. Verification/CI coverage missed the PG-only virtual-admin terminal test filter and had weak concurrency coverage.

Fix status in this branch:

- Discount modals now use scoped manager-PIN verification and preserve override evidence into cart/receipt authoring.
- Account-charge authoring rejects externally supplied override evidence unless an approval input is present and the local `OPERATOR_APPROVAL_GRANTED -> OVERRIDE_* -> ACCOUNT_CHARGE` chain is authored atomically.
- Cash drawer deposit/payout now require locally authored `cash_drawer_control` approval evidence; the backend validates the referenced fiscal event, target hash, tenant/company/terminal, cashier, supervisor, shift, amount, reason, operation type, and idempotency-bound target reference. The POS also emits `SAFE_DROP`/`CASH_OUT` fiscal movement events for deposit/payout.
- `/pos/auth/pin-data` now filters PIN operators by `UserCompanyMembership` for the active company and validates requested terminals in-company/non-virtual.
- Server and device fiscal payload validators now have Phase 4 branches for `ACCOUNT_STATUS_CHANGED`, `OPERATOR_APPROVAL_GRANTED`, and `OVERRIDE_*`.
- Account-status lifecycle now matches the locked matrix, with `closed` terminal.
- Void/return flows now require scoped manager approval and pass approval evidence to the legacy API; local unsynced voids fail closed until the receipt is synced.
- Tender tolerance now has an approved path in advanced payments: under-tender checkout requires a scoped manager PIN and authors `OPERATOR_APPROVAL_GRANTED -> OVERRIDE_TENDER_TOLERANCE` before receipt creation. Quick cash checkout still rejects under-tender attempts.
- SALE_RECEIPT now carries all approval/override references in `approval_references` instead of collapsing transaction/line/tender approvals to a single `reference_event_id`.
- CI PG-only filter now includes `VirtualAdminTerminalResolverTest`.
