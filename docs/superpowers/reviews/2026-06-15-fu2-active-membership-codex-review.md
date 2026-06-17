# Codex review — FU-2 active-membership authorization

**Date:** 2026-06-15. **Branch:** `feat/offline-approval-active-membership`.
**Reviewed commits:** `80aa097b3` (FU-2a), `1300ecdbf` (FU-2b). **Fix commit:** `7aae53247`.
**Reviewer:** Codex (gpt-5-codex via codex-companion).

Process note: Codex cannot write into this worktree sandbox, so the review was
emitted verbatim and is recorded here with dispositions. Owner-approved FU-2b
scope: active-company-member restriction + document the residual (not full
per-update authorship proof this round).

---

## Findings & dispositions

### HIGH — `RequiresCompanyAccess` still allowed suspended route-company access
`app/Modules/Accounting/Presentation/Concerns/RequiresCompanyAccess.php` checked
membership EXISTENCE for the route-segment `{companyId}` — independent of
CompanyContext. A user active in company A (passing CompanyContextMiddleware)
but only SUSPENDED in company C could reach company C's accounting routes
(PartnerBalanceController, AccountPurposeController, OpeningBalanceBatchController).

**Disposition: FIXED (`7aae53247`).** Added `->where('status', Active)` to the
trait; preserved the deliberate 404 (non-disclosure) failure mode. Added a TDD
regression in `AccountingTenantIsolationTest` (active-in-A, suspended-in-C → 404).

### LOW — tests missed `Pending` status
**Disposition: FIXED.** Added Pending-status cases to both
`CompanyContextActiveMembershipTest` and `PosAuthSyncPinsTest` — only `Active`
passes.

### Q1 FU-2a completeness — PASS (modulo the HIGH)
CompanyContextMiddleware path closed (default-company + explicit X-Company-Id both
active-only). `Pending` denied by construction. `OwnerReportScope` active-only.
Token tenant-claim middleware is orthogonal (compares tenant ability to
`User::tenant_id`, not membership). The only gap was `RequiresCompanyAccess`
(fixed).

### Q2 FU-2a blast radius — PASS
No app flow needs pending/suspended/revoked membership to grant company context;
registration/provisioning create active memberships and login rejects inactive
users before token issuance. Intentional.

### Q3 FU-2b correctness — PASS
`array_unique` fixes the duplicate-id cross-tenant count check; UUID strings
survive `pluck()->flip()->has()`; non-members and suspended members rejected
before writes. `422` consistent with the endpoint's existing batch-validation
style (`403` more semantic but not a blocker at this scope).

### Q4 FU-2b residual — accurately scoped
The in-code comment correctly scopes the residual (same-company active member
with `pos.operate_terminal` can still rewrite another active member's `pos_pin`).

Codex suggested a cheap extra narrowing: require each TARGET to also hold
`pos.operate_terminal`. **NOT adopted this round** — (a) owner-approved scope was
"active-company restriction + document residual"; (b) it risks the legit flow if
a manager who needs an approval PIN does not hold `operate_terminal` (unverified),
and (c) checking another user's Spatie permission needs per-user team-context
plumbing under db-per-tenant. Recorded as a candidate for the deeper follow-up
(per-update authorship proof / trusted device-sync identity).

### Q5 test adequacy — PASS (gaps closed)
Added the Pending cases and the `RequiresCompanyAccess` route regression Codex
asked for.

---

## Verification after fixes
- `CompanyContextActiveMembershipTest` + `PosAuthSyncPinsTest` +
  `AccountingTenantIsolationTest`: 45 tests green.
- Earlier scoped regression (auth, POS, accounting, contact, tenant, documents,
  PinVerifier): 88 tests green. Full PHPUnit suite intentionally NOT run (banned).
- `phpstan` (changed files): 0 errors. `pint`: pass.

## Residual / follow-up (owner-tracked)
Same-company authorship gap on `/pos/auth/sync-pins` — a same-company active
operator with `pos.operate_terminal` can still rewrite another active member's
`pos_pin`. Closing it needs per-update authorship proof or a trusted device-sync
identity (and would touch the multi-operator offline PIN flow). Candidate
narrowing: target must also hold `pos.operate_terminal` (verify it doesn't break
manager approval-PIN setup first).
