# Opus adversarial round-4 cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: 707284e6
Round-1 verdict: BLOCK (7 findings)
Round-2 verdict: REQUEST-CHANGES (1 NEW IMPORTANT)
Round-3 verdict: BLOCK (1 NEW CRITICAL — Finding 14)
Reviewer: opus  (advisory — not an accepted review.reviewer per schema)

## Verdict

REQUEST-CHANGES

Codex round-3 Finding 14 is **CLOSED** — the MultiPayment partner-balance fix is correctly placed at both controller and service layers, the four new tests use real two-tenant fixtures with leak-bait assertions, and all required gates are green.

However, the round-4 meta-sweep surfaced one NEW IMPORTANT finding (Finding 15) of the same structural class Codex hunted in round 3. The exact same `/partners/{partner}/...` route pattern + bare-where-on-partner-id read exists in `SmartPaymentController::getOpenInvoices`, with the additional inline service-equivalent at `PaymentAllocationService::getOpenInvoices`. This route was touched in the same Section-7 phase-1 commit (`b09c7ac6`) that fixed the bare exists validators, but the read path was not re-scoped. While UUID uniqueness keeps it from being an exploitable cross-tenant leak today, it violates the explicit defense-in-depth standard that Codex set in the Finding 14 remediation: BOTH `tenant_id` AND `company_id` predicates on every cross-FK read where the FK was supplied by the route. Closing this in the same round keeps the cluster-level invariant honest before the Treasury hard gate opens.

I am also flagging two NICE-TO-HAVE residuals (PaymentRefundController tenant-only scoping, BankReconciliation index/show tenant-only scoping) that are pre-existing and not regressions from this remediation; they are documented for the inventory but should not block this PR.

## Codex Finding 14 closure status

- Status: **CLOSED**
- Evidence:
  - Controller defense-in-depth at `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:191-213` (`getUnallocatedBalance`) and `:287-309` (`getPartnerAccountBalance`) — both resolve `Partner::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->find($partnerId)` and return a structured `PARTNER_NOT_FOUND` 404 if null. The injected `CompanyContext` provides both ids via `requireCompanyId()` + `requireCompany()->tenant_id`.
  - Service defense-in-depth at `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:217-224` and `:318-325` — both `Payment::query()` chains now include `where('tenant_id', $tenantId)` AND `where('company_id', $companyId)` AND `where('partner_id', $partnerId)`. Method signatures (`:211-216` and `:310-315`) take both ids as required string parameters.
  - All call sites updated: route → controller (only entry point) at `MultiPaymentController.php:216-221`, `:312-317`; internal `recordPaymentOnAccount` self-call at `MultiPaymentService.php:289`; internal `getPartnerAccountBalance` → `getUnallocatedDepositBalance` at `:316`; test calls at `tests/Feature/Treasury/MultiPaymentTest.php:426-431`, `:456-461`, `:534-539`. App-code grep for `getUnallocatedDepositBalance|getPartnerAccountBalance` outside this file returns zero unscoped callers.
  - Four new tests at `tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1625-1770` with real two-tenant fixtures (`tenantA/tenantB`, `companyA/companyB`, `partnerA/partnerB`, real Payment rows seeded with leak-bait amounts `777.77`/`999.99`). Cross-tenant tests assert response is in `[403,404]` AND that the leak amount + payment id are NOT in the response body (`assertStringNotContainsString` on both). Same-tenant controls assert 200 + correct balance + correct deposit count, including the seeded baseline payment.
  - Test run: `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter "balance"` → 4 / 16 / 0. Full file: 68 / 206 / 0. `MultiPaymentTest.php`: 16 / 57 / 0 (signature changes only).

## Bare-where pattern sweep (Treasury-wide)

Methodology: ripgrep for `where('partner_id'`, `where('document_id'`, `where('payment_id'`, `where('repository_id'`, `where('id'`, etc. in `apps/api/app/Modules/Treasury/` excluding tests, then read each Service + Controller with surviving matches and trace upstream callers.

| Surface | Result | Notes |
|---|---|---|
| `MultiPaymentController::createSplitPayment` (`:28-78`) | CLEAN | Document scoped tenant+company at `:52-55`, all FK ids ScopedExists. |
| `MultiPaymentController::recordDeposit` (`:83-137`) | CLEAN | Partner + payment_method + repository + instrument all ScopedExists. |
| `MultiPaymentController::applyDeposit` (`:142-186`) | CLEAN | Payment + Document scoped tenant+company at `:155-164`. |
| `MultiPaymentController::getUnallocatedBalance` (`:191-235`) | CLEAN | Finding 14 fix verified. |
| `MultiPaymentController::recordPaymentOnAccount` (`:240-282`) | CLEAN | Partner ScopedExists. |
| `MultiPaymentController::getPartnerAccountBalance` (`:287-327`) | CLEAN | Finding 14 fix verified. |
| `MultiPaymentService` (writes + Finding 14 reads) | CLEAN | All `Payment::where(...)` chains have tenant+company predicates. |
| `SmartPaymentController::previewAllocation` (`:61-94`) | CLEAN-CALLER-PATH | partner_id ScopedExists tenant+company at `:67-71`; payment_id under same rule on `applyAllocation`. Caller-path safe but service-internal `getOpenInvoices` is not re-scoped — see NEW finding 15. |
| `SmartPaymentController::getOpenInvoices` (`:150-206`) | **NEW FINDING 15** | Bare-where on (`company_id`, `partner_id`) with no `tenant_id` predicate at both partner-scope check (`:155-157`) and document read (`:169-176`). Same structural pattern Codex flagged in round-3. |
| `SmartPaymentController::applyAllocation` (`:110-141`) | CLEAN | payment_id ScopedExists tenant+company at `:116-120`. |
| `PaymentAllocationService::previewAllocation` → `getOpenInvoices` (`:323-351`) | **NEW FINDING 15** (sibling) | Inline `Document::where('company_id', $companyId)->where('partner_id', $partnerId)` private helper, called from a controller path that already validates partner_id by tenant+company. Defense-in-depth gap identical to Finding 15. |
| `PaymentAllocationService::applyAllocation` (`:75-300`) | CLEAN | All Document/Payment lookups scoped tenant+company at `:83-87`, `:108-112`, `:190-193`. |
| `PaymentAllocationService::previewManualAllocation` (`:477-512`) | CLEAN | Document scoped tenant+company at `:485-488`. |
| `PaymentRefundController::findPaymentOrFail` (`:28-36`) | NICE-TO-HAVE | Tenant-only scoping (no `company_id`). Cross-company-within-tenant possible: a user in company A can refund a payment in company B of same tenant. Pre-existing pattern, not a regression from this PR. |
| `PaymentRefundController::findDocumentOrFail` (`:41-49`) | NICE-TO-HAVE | Same — tenant-only. Used by `refundPrepayment`. |
| `PaymentRefundService::getRefundHistory` (`:209-213`) | NICE-TO-HAVE | `Payment::where('tenant_id', $payment->tenant_id)->where('partner_id', $payment->partner_id)`. Tenant-id is from a scoped Payment row so trustworthy; missing company_id is a defense-in-depth symmetry gap. |
| `PaymentRefundService::reversePayment` → `PaymentAllocation::where('payment_id')` (`:261`) | CLEAN | DELETE inside transaction, payment_id from already-scoped Payment row. |
| `PaymentRefundService::prorate` → `Payment::where('company_id', $originalReceipt->company_id)` (`:335-345`) | CLEAN | company_id sourced from a scoped Receipt model passed in by the caller; the inner `whereIn` filters by that receipt's id; downstream `findExistingProrationRows` (`:688-694`) shares the same pattern. Tenant_id symmetry would be belt-and-braces but the company anchor is from a scoped object. |
| `PaymentRefundService::findExistingFullRefund` (`:667-672`) | NICE-TO-HAVE | Same as `getRefundHistory` — tenant-only from scoped Payment, defense-in-depth missing company_id. |
| `PaymentRefundService::refundPrepayment` (Finding 12 path) | CLEAN | Verified clean in round-3 — uses locked PO + `where('tenant_id', $po->tenant_id)->where('company_id', $po->company_id)` consistently at `:51-55`, `:86-96`, `:139-145`. |
| `VendorRefundService` | CLEAN | All payment_method + repository lookups scoped tenant+company; tenant/company anchors come from the locked PO at `:52-55`, never from caller-supplied scope. |
| `BankReconciliationController::index` (`:27-51`) | NICE-TO-HAVE | `BankReconciliation::where('tenant_id', $tenantId)` — no company_id; cross-company-within-tenant list leak. Repository_id filter at `:38-40` accepts unvalidated request input. Pre-existing, out of cluster scope for this PR. |
| `BankReconciliationController::show/matchItem/unmatchItem/complete/cancel/summary` | NICE-TO-HAVE | All scope by tenant_id only. matchItem/unmatchItem are structurally protected because the inner item lookup must match `(reconciliation_id, payment_id)` and items are only created for payments tied to that repository (i.e. that company). |
| `BankReconciliationService::matchItem/unmatchItem` (`:99-103`, `:130-133`) | CLEAN-VIA-STRUCTURE | Bare reconciliation_id+payment_id query but item rows are pre-bound to one company at startReconciliation time; controller verifies reconciliation tenant. |
| `BankReconciliationService::startReconciliation` (`:71-83`) | NICE-TO-HAVE | `Payment::where('tenant_id', $tenantId)->where('repository_id', $repository->id)` — no company_id. Repository was already tenant-scoped lookup, so payments tied to that repository_id are implicitly company-scoped. Defense-in-depth symmetry missing. |
| `CloseInvoiceWithToleranceService::close` (`:48-55`) | NICE-TO-HAVE | `Document::query()->where('id', $invoiceId)->lockForUpdate()->firstOrFail()` — NO scope. Caller (`InvoiceController::closeWithTolerance`) pre-validates document via `baseQuery()->find($invoice)` → `Document::forCompany($companyId)`. So caller-path safe, but service has zero scope. Same Codex round-3 anti-pattern: caller validates, service re-queries without scope. |
| `PaymentController::index/show` (`:45-86`) | NICE-TO-HAVE | Tenant-only scoping. Cross-company-within-tenant list/detail leak. Pre-existing pattern across module. |
| `PaymentInstrumentController::index/show/etc.` | NICE-TO-HAVE | Same tenant-only pattern. |

## New findings

### Finding 15 — SmartPaymentController::getOpenInvoices reads partner+document without `tenant_id` symmetry (same structural class as Codex round-3 Finding 14)

- Severity: IMPORTANT  (defense-in-depth gap; not currently exploitable thanks to UUID uniqueness, but identical structural pattern Codex BLOCK'd round-3)
- Location:
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/SmartPaymentController.php:150-206` (route `GET /api/v1/partners/{partner}/open-invoices`, defined at `apps/api/app/Modules/Treasury/Presentation/routes.php:179-181`)
  - Sibling: `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:323-351` (`getOpenInvoices` private method, called from the controller-validated `previewAllocation` path)
- Issue: The route `/partners/{partner}/open-invoices` accepts a raw partner UUID and runs `Partner::where('company_id', $companyId)->where('id', $partnerId)->first()` followed by `Document::where('company_id', $companyId)->where('partner_id', $partnerId)->where('type', DocumentType::Invoice)...->get()`. Neither query carries a `tenant_id` predicate. This is the same pattern shape as Codex round-3 Finding 14 (route partner_id → unscoped read), just one step less leaky because the company_id predicate exists. UUID uniqueness across `partners.id` and `documents.id` (PostgreSQL UUID column, no inter-tenant collision risk in practice) prevents this from being an exploitable cross-tenant leak today, but the cluster invariant Codex established for the round-3 fix is "BOTH `tenant_id` AND `company_id` on every read whose anchor came from a route param." This endpoint does not meet that bar. The Section-7 phase-1 commit `b09c7ac6` touched this controller for the bare exists fix but did not re-scope the read path.
- Why this matters for the round-4 hard-gate decision: Codex's round-3 BLOCK was specifically about hostile hunting for unscoped FK reads under the `/partners/{partner}/...` route family. Closing the cluster with one surviving sibling endpoint of the same family + same anti-pattern leaves the round-4 reviewer without a clean signal that the bare-where audit is complete. The fix is mechanical and matches the established template.
- Fix:
  1. In `SmartPaymentController::getOpenInvoices`, replace `Partner::where('company_id', $companyId)` with `Partner::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)`, and same for the `Document::where(...)` chain.
  2. In `PaymentAllocationService::getOpenInvoices`, change the private helper signature to take `tenantId` (or accept a Company/Tenant aggregate) and re-apply the predicate. Update the two callers (`previewAllocation` at `:60` and `previewAutoAllocation` at `:364`) to pass it. `applyAllocation` already pulls tenantId from `$this->companyContext->requireCompany()->tenant_id` at `:80`, so the change is local to the auto-allocation read path.
  3. Add cross-tenant denial test for `GET /partners/{partner}/open-invoices` (404 + leak-amount NOT in body) and same-tenant control. This will also pin the inventory entry that is currently missing for this endpoint.
  4. Add a sweep-inventory entry for `api.treasury.smartpayment.get_open_invoices` (the scanner Gate B does not catch this because the controller uses `->first()` + manual null check rather than `findOrFail()`).

## Inventory + scanner blind spot (sub-finding 15a, INFORMATIONAL)

The `getOpenInvoices` endpoint, like the two endpoints Codex flagged in Finding 14, is invisible to both Gate A (scans `Rule::exists` validators) and Gate B (scans `find()`/`findOrFail()` on guarded models). The fixed Finding-14 endpoints used `find()` + manual null but the FIX still bypassed the scanner because the read happened at the service layer through method-extracted `where(...)` chains. The Section-7 cluster sweep is therefore relying on human review to catch this entire class of vulnerability. Recommend (post-this-cluster): extend Gate B to also flag `where('id', $routeParam)` patterns on guarded models (Partner, Document, Payment, Repository, Instrument, Method, BankReconciliation) when the chain lacks both tenant_id and company_id predicates within the same expression. This would have caught Finding 14 and Finding 15.

## What looks good

- Finding 14 fix is exemplary defense-in-depth: controller resolves partner under tenant+company → 404 with explicit error code, service signature now takes `tenantId` + `companyId` as required parameters, both `Payment::query()` chains include all three predicates (tenant + company + partner). Test assertions check both status code AND response-body absence of leak amounts and payment ids.
- Method-signature change rippled cleanly: 5 internal call sites all updated, 16 unit tests in MultiPaymentTest pass with signature changes only (no behavior change).
- All required gates green at tip 707284e6: 68 Treasury isolation tests pass, PHPStan clean, Pint clean, sweep-progress Gate A=98 / Gate B=102 (unchanged as expected — scanners don't see bare-where), inventory verify-history shows 489 events / 262 callsites / 0 problems, no forbidden POS/Voucher diffs.
- Codex round-3 deviations on Findings 8/9 (`structurally_protected_by_upstream_guard`) and the round-3 `findOrFail`-prefixed services pattern remain undisturbed by this PR.

## Verification run

| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` | OK, 68 tests / 206 assertions |
| `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter "balance"` | OK, 4 tests / 16 assertions (the four Finding-14 regression tests) |
| `vendor/bin/phpunit tests/Feature/Treasury/MultiPaymentTest.php` | OK, 16 tests / 57 assertions (signature-change pin) |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` | OK — no errors |
| `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/` | pass |
| `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` | OK, Gate A=98, Gate B=102, 2 tests / 4 assertions (unchanged from round-3) |
| `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` | verified 489 events across 262 callsites, 0 problems |
| `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` | empty (no forbidden diffs) |

## Recommendation

Do not open the Treasury hard gate at tip 707284e6. Apply the Finding 15 fix template to `SmartPaymentController::getOpenInvoices` + `PaymentAllocationService::getOpenInvoices` in the same PR (one new commit), add the parallel cross-tenant denial test + same-tenant control (mirroring `tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1625-1770`), and add the inventory entry the scanners can't generate. After that, the cluster is structurally clean against the round-3-established `/partners/{partner}/...` invariant and ready for round-5 sign-off.

The pre-existing tenant-only scoping in `PaymentRefundController`, `PaymentController`, `BankReconciliationController` index/show, and the various services consuming caller-anchored `company_id` are NICE-TO-HAVE residuals; flag them in the inventory but do not gate this PR on them — they belong in a follow-up sweep covering the "tenant-only scope, no company_id" anti-pattern across the cluster.
