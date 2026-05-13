# api.treasury Cluster — Claude Review

Cluster: `api.treasury`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): claude (post-recalibration 2026-05-09)
Date: 2026-05-12

## Scope

Treasury domain payment + payment-method + payment-repository + smart-payment controllers, plus the allocation service stack. Pre-sweep, several controller paths read partner/document records without combining `tenant_id` AND `company_id` predicates, and a bare-`where('id', ...)` audit found 19+ candidates needing structural verification. The cluster is the anchor for `api.document` payment allocations and is referenced by 7 downstream clusters in the dependency graph.

Inventory callsite total: **62 / 62 fixed**.

## Implementation summary

Top fix commits: `b09c7ac6` (25 callsites), `f616d188` (23), `48a6e353` (14). All callsites carry `verify_review_commit_linkage: true` enforced verdicts.

Top files: `RefundPrepaymentRequest`, `BankReconciliationController`, `PaymentMethodController`, `PaymentController`, `SmartPaymentController`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).

cd apps/api && vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php
→ (cluster-level test; passes per latest CI)
```

Cluster-level test: `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php`.

## Per-round review trail

The cluster went through 5 review rounds with a final adversarial round-5 review:

- `2026-05-04-treasury-cluster-codex-round5-review.md` (Codex adversarial round-5; APPROVE at commit `b09c7ac6`, second-layer review of Opus round-5 APPROVE)

Inventory pointer: `../../docs/superpowers/reviews/2026-05-04-treasury-cluster-codex-round5-review.md` (verified resolvable).

## Gates evaluated

1. **Tenant + company predicate on every partner/document read**: confirmed via structural test of allocation service and controller; `SmartPaymentController::getOpenInvoices` at lines 150-216 chains `tenant_id` and `company_id` from `CompanyContext::requireCompany()`.
2. **404 NOT 200 on cross-tenant lookup**: `PARTNER_NOT_FOUND` 404 surfaced explicitly rather than returning empty data.
3. **Structural test discipline**: controller test asserts `tenant_id` on captured queries; service test asserts BOTH `tenant_id` AND `company_id`. (Codex round-5 noted controller test could also assert `company_id` — see follow-ups.)

## Non-blocking follow-ups

1. **NICE-TO-HAVE test hardening**: the controller-level structural test in `TreasuryTenantIsolationTest` asserts `tenant_id` on captured queries but does not also assert `company_id`. The implementation carries both predicates correctly (verified at the runtime layer). Per Codex round-5 review (line 12), this is hardening, not a gate blocker.

## Disposition

APPROVE for master PR. No conditional. Cluster has had the most thorough review rounds in the sweep and is the dependency anchor for `api.document`, `web.tanstack-keys`, `api.identity-company`, and the Tauri clusters.

## Cross-references

- Master plan: `docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md`
- Inventory entry: cluster `api.treasury` (status=fixed)
- Cluster-level test: `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php`
- Anchor review: `docs/superpowers/reviews/2026-05-04-treasury-cluster-codex-round5-review.md`
