# Opus adversarial round-5 cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: d7184178
Round-1 verdict: BLOCK (7 findings)
Round-2 verdict: REQUEST-CHANGES
Round-3 verdict: BLOCK (Finding 14)
Round-4 verdict (yours): REQUEST-CHANGES (Finding 15)
Reviewer: opus  (advisory — not an accepted review.reviewer per schema)

## Verdict

APPROVE

Finding 15 is closed at both controller and service layers with the exact `tenant_id + company_id` symmetry the cluster invariant demands. The bare-where audit was honest: every match the hostile grep surfaces falls into one of the round-4 categories (CLEAN / CLEAN-VIA-STRUCTURE / CLEAN-VIA-CALLER-PATH / pre-existing tenant-only-scope NICE-TO-HAVE). The two new structural-SQL invariant tests genuinely pin the SQL shape via `DB::enableQueryLog()` rather than data-level behavior, which raises the regression bar substantially. All gates green, no scope creep, no forbidden patterns. Treasury locks; the hard gate opens.

## Finding 15 closure

- Status: **CLOSED**
- Evidence:
  - **Controller fix** at `apps/api/app/Modules/Treasury/Presentation/Controllers/SmartPaymentController.php:150-216` (`getOpenInvoices`): Partner resolved via `Partner::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->where('id', $partnerId)->first()` with structured `PARTNER_NOT_FOUND` 404 fallback (lines 161-174). Document read carries the same triple-predicate `tenant_id + company_id + partner_id` filter (lines 177-186). The `$tenantId` is sourced from `$this->companyContext->requireCompany()->tenant_id` (line 153) — same injection pattern as the round-3 Finding 14 fix.
  - **Service fix** at `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`:
    - `previewAllocation` (line 65) now resolves `$tenantId` from `CompanyContext::requireCompany()->tenant_id` and forwards to the private helper.
    - Private `getOpenInvoices(string $tenantId, string $companyId, string $partnerId, AllocationMethod $method)` signature (line 338) now requires `$tenantId`. Document query (lines 340-356) chains `->where('tenant_id', $tenantId)->where('company_id', $companyId)->where('partner_id', $partnerId)`.
    - All other internal Document/Payment lookups in `applyAllocation` (lines 91-95, 116-120, 198-201) are tenant+company scoped.
    - `previewManualAllocation` (lines 494-505) resolves `$tenantId` from CompanyContext and applies tenant+company scoping on Document::findOrFail.
  - **Tests** at `tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1769-2070` (5 new tests, +298 lines):
    1. `test_get_open_invoices_refuses_cross_tenant_partner_id` — cross-tenant denial (status in [403,404]) + leak-bait absence assertions on body (`assertStringNotContainsString` for `888.88` and `INV-LEAK-OPEN`).
    2. `test_get_open_invoices_accepts_same_tenant_partner_id` — same-tenant control, asserts seeded invoice id appears in `data` array.
    3. `test_get_open_invoices_filters_partner_and_document_by_tenant_id` — **structural-SQL invariant** using `DB::enableQueryLog()`, isolates Partner lookup query AND Document read query, asserts both contain `"tenant_id"` literal in the WHERE clause SQL. This is the bar-raising test.
    4. `test_payment_allocation_service_get_open_invoices_refuses_cross_tenant_partner_id` — service-tier forged call (tenant-A scope, tenant-B partnerId), asserts empty allocations + full amount falls to excess.
    5. `test_payment_allocation_service_get_open_invoices_filters_by_tenant_id` — **structural-SQL invariant** for the service-tier auto-allocation read, asserts SQL contains both `"tenant_id"` AND `"company_id"`.
  - Test run: `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter "open_invoices"` → **5 / 16 / 0**. Full file: **73 / 222 / 0**.

The structural-SQL invariant tests are the critical addition over round-4. They pin the SQL *shape* rather than just behavior, so a future refactor that drops `tenant_id` from the chain breaks the test even if the data-level cross-tenant denial still happens to hold for some other reason (e.g. UUID uniqueness). This closes the recurrence vector that has cost three rounds of reviewer time.

## Audit exhaustiveness verdict

- Result: **HONEST**

Methodology: ran the hostile grep recipe from the scanner-blind-spot doc verbatim:

```
/usr/bin/grep -rnE -- "->where\(['\"](id|partner_id|document_id|payment_id|repository_id|payment_method_id|user_id|instrument_id|account_id)['\"]" \
  app/Modules/Treasury/ --include='*.php' | grep -v '/tests/'
```

Result: **19 matches**. Categorized each by reading the surrounding code (±20 lines, then traced upstream caller path where the match was inside a service method).

| Match | File:line | Category | Verified |
|---|---|---|---|
| 1 | `CloseInvoiceWithToleranceService.php:53` | CLEAN-VIA-CALLER-PATH | Only caller is `InvoiceController::closeWithTolerance` (`:738`) which pre-validates via `baseQuery()->ofType(Invoice)->find($invoice)`. `baseQuery()` chains `Document::forCompany($companyId)`. Codebase grep for `closeInvoiceWithToleranceService->close\|->close(` returned only InvoiceController. Service has no events/queue/listener entry points. **Verified safe**. (Tenant-only-scope at controller layer — pre-existing NICE-TO-HAVE noted in round-4.) |
| 2 | `BankReconciliationService.php:73` | CLEAN | `Payment::where('tenant_id', $tenantId)->where('repository_id', $repository->id)`. Already tenant-scoped; repository was tenant-scoped lookup. NICE-TO-HAVE (no company_id) per round-4. |
| 3 | `BankReconciliationService.php:101` | CLEAN-VIA-STRUCTURE | `BankReconciliationItem::where('reconciliation_id', $reconciliationId)->where('payment_id', $paymentId)`. Caller `BankReconciliationController::matchItem` (`:118-121`) pre-validates `BankReconciliation::where('tenant_id', $tenantId)->findOrFail($reconciliationId)`. Items are pre-bound to one reconciliation at startReconciliation time. **Verified safe**. |
| 4 | `BankReconciliationService.php:132` | CLEAN-VIA-STRUCTURE | Same pattern as `:101` (unmatchItem). Controller pre-validates at `:149-152`. **Verified safe**. |
| 5 | `PaymentAllocationService.php:343` | CLEAN | This is the **Finding 15 fix itself** — chained with `where('tenant_id', $tenantId)->where('company_id', $companyId)` upstream. The grep just landed on the third predicate. **Verified safe**. |
| 6 | `PaymentInstrument.php:190` | CLEAN-VIA-BUILDER-COMPOSITION | `scopeForPartner` Eloquent scope. Composes with caller chain. Codebase grep `forPartner` returns ZERO callers in Treasury — dead code from a defensive POV. **Verified safe**. |
| 7 | `Payment.php:202` | CLEAN-VIA-BUILDER-COMPOSITION | Same as above. ZERO callers. **Verified safe**. |
| 8 | `PaymentRefundService.php:210` | CLEAN-VIA-MODEL-PROPAGATION | `Payment::where('tenant_id', $payment->tenant_id)->where('partner_id', $payment->partner_id)`. Method takes `Payment $payment` as parameter; tenant_id read from already-scoped model. Round-4 NICE-TO-HAVE (no company_id). **Verified consistent with round-4 deferral**. |
| 9 | `PaymentRefundService.php:668` | CLEAN-VIA-MODEL-PROPAGATION | Same pattern as `:210` (findExistingFullRefund). **Verified consistent with round-4 deferral**. |
| 10 | `MultiPaymentService.php:220` | CLEAN | `Payment::where('tenant_id', $tenantId)->where('company_id', $companyId)->where('partner_id', $partnerId)` — the round-3 Finding 14 fix. Full triple-scope. **Verified safe**. |
| 11 | `MultiPaymentService.php:321` | CLEAN | Same triple-scope pattern. **Verified safe**. |
| 12 | `VendorRefundService.php:66` | CLEAN-VIA-CALLER-PATH | `PaymentAllocation::where('document_id', $lockedPo->id)`. `$lockedPo` is loaded via `Document::query()->where('tenant_id', $po->tenant_id)->where('company_id', $po->company_id)->lockForUpdate()->findOrFail($po->id)` at line 51-55. document_id is tenant+company-locked upstream. **Verified safe**. |
| 13 | `BankReconciliationController.php:39` | CLEAN-VIA-FILTER-COMPOSITION | Conditional `$query->where('repository_id', $request->input('repository_id'))` chained on top of `BankReconciliation::query()->where('tenant_id', $tenantId)`. Filter composes with tenant scope. NICE-TO-HAVE (no company_id) per round-4. |
| 14 | `PaymentRepositoryController.php:186` | CLEAN | `Payment::query()->where('tenant_id', $tenantId)->where('repository_id', $id)` — `$id` pre-validated at `:179-181`. NICE-TO-HAVE (no company_id) per round-4. |
| 15 | `PaymentController.php:57` | CLEAN-VIA-FILTER-COMPOSITION | Conditional partner_id filter on top of tenant-scoped index query. NICE-TO-HAVE per round-4. |
| 16 | `SmartPaymentController.php:164` | CLEAN | Finding 15 fix — line is the third predicate `where('id', $partnerId)` after `tenant_id + company_id`. **Verified safe**. |
| 17 | `SmartPaymentController.php:180` | CLEAN | Finding 15 fix — `where('partner_id', $partnerId)` after `tenant_id + company_id`. **Verified safe**. |
| 18 | `PaymentInstrumentController.php:44` | CLEAN-VIA-FILTER-COMPOSITION | Conditional partner_id filter on tenant-scoped query. NICE-TO-HAVE per round-4. |
| 19 | `PaymentInstrumentController.php:49` | CLEAN-VIA-FILTER-COMPOSITION | Conditional repository_id filter on tenant-scoped query. NICE-TO-HAVE per round-4. |

**Spot-checks performed (deep traces):**
- `CloseInvoiceWithToleranceService::close` caller-path verified: codebase grep `CloseInvoiceWithToleranceService\|closeInvoiceWithToleranceService->\|->close(` produced exactly one entry into the service from `InvoiceController.php:738`. No event listeners, no queue jobs, no scheduled commands. The CLEAN-VIA-CALLER-PATH claim holds.
- `BankReconciliationService::matchItem` CLEAN-VIA-STRUCTURE verified: `BankReconciliationController::matchItem` (`:110-138`) pre-validates `BankReconciliation::where('tenant_id', $tenantId)->findOrFail($reconciliationId)` BEFORE calling the service. Item lookup `(reconciliation_id, payment_id)` is structurally bound to one tenant-scoped reconciliation row.
- `Payment::scopeForPartner` and `PaymentInstrument::scopeForPartner` CLEAN-VIA-BUILDER-COMPOSITION verified: `grep -rn "forPartner" app/` shows zero Treasury callers (only Accounting + Vehicle have their own `scopeForPartner` definitions). The Treasury scope methods are unused — defensively safe.

**Categories validated:** CLEAN, CLEAN-VIA-STRUCTURE, CLEAN-VIA-BUILDER-COMPOSITION, CLEAN-VIA-CALLER-PATH, CLEAN-VIA-FILTER-COMPOSITION, CLEAN-VIA-MODEL-PROPAGATION (the latter is a small extension of the round-4 categories — model-property-driven scoping where tenant_id is read from a passed-in model). All 19 grep hits accounted for, zero new Finding-14/15 class instances surface.

## NEW findings hunt (beyond bare-where)

Performed extra hostile sweeps as instructed in section C of the brief:

1. **Raw SQL / `DB::table()` calls** (`grep -rn "DB::table\|DB::raw\|DB::statement" app/Modules/Treasury/`):
   - `PaymentToleranceQueryService.php:184` — `DB::table('pos_receipts')` query scoped by `$shift->terminal_id`. Caller `ReportGenerationService::generateZReport` passes a tenant-scoped Shift. The Shift model itself has no tenant_id global scope (anchored on terminal_id), but this is **out of scope for the Treasury cluster** — it's a cross-module POS-shift-scoping question that pre-dates this work and would need its own review. **Not a Treasury-cluster finding.**
   - `AuditDiscountsCommand.php:62, :114` — Console command for audit purposes. Console commands run as super-admin and intentionally cross tenants. **Not a route-anchored read; properly out of scope.**
2. **`whereIn` chains with caller-supplied lists** (`grep -rn "->whereIn\(['\"]id"`):
   - `PaymentRefundService.php:337` — `Payment::where('company_id', $originalReceipt->company_id)->whereIn('id', subquery)`. Subquery filters `pos_receipt_payments.receipt_id = $originalReceipt->id`. The `$originalReceipt` is a passed-in model parameter (CLEAN-VIA-MODEL-PROPAGATION pattern — same class as `getRefundHistory`). Pre-existing NICE-TO-HAVE; round-4 already documented this at table line 53.
3. **Joins** (`grep -rn "->join\|->leftJoin\|->rightJoin"`):
   - Only `AuditDiscountsCommand.php:63` (console command, super-admin). No service/controller joins. **Clean.**
4. **Service methods that take a Model as parameter without re-verifying tenant**: documented at points 8/9/2-of-`whereIn`. These are CLEAN-VIA-MODEL-PROPAGATION (round-4 NICE-TO-HAVE category). Not new findings.

**No new Finding-14/15 class issues surface.** Zero new IMPORTANT or CRITICAL findings.

## Forbidden patterns

- `git diff 707284e6..d7184178 -- apps/api/app/ | grep -E '^\+.*app\('` — production code: zero matches. Test code: 6 matches in `TreasuryTenantIsolationTest.php` for `app(PermissionRegistrar::class)`, `app(CompanyContext::class)`, `app(PaymentAllocationService::class)`. These are test-code service resolution (allowed; constructor-injection rule applies to controllers/services, not tests). **No finding.**
- `@phpstan-ignore` additions: zero.
- `: mixed` additions: zero.
- POS/Voucher scope creep (`git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`): zero.

## Pre-existing NICE-TO-HAVE residuals (round-4 list)

Round-4 explicitly recommended NOT gating Treasury on the tenant-only-scope NICE-TO-HAVEs. Spot-checked two of them; both still safely deferable:

- `BankReconciliationController::index/show/matchItem/etc.` — all use `BankReconciliation::where('tenant_id', $tenantId)`. Cross-company-within-tenant exposure is the residual: a user authenticated to company-A within tenant-T can list / show / match items on a bank reconciliation owned by company-B within the same tenant-T. Whether this is a real risk depends on the org structure (typically tenants = organizations, companies = sub-accounts of one org → low risk; tenants = service providers, companies = unrelated customers → high risk). For Synerivia / AutoERP the tenant-org / company-suborg model holds, so the deferral is defensible.
- `PaymentController::index/show` — same tenant-only-scope pattern. Same deferral logic holds.

The audit work in this round did not surface new urgency on any of these. They remain in the post-Treasury-hard-gate sweep queue. Recommend the orchestrator track them as a separate "tenant-only scope, no company_id" cluster sweep after the bare-where AST scanner extension (Gate C) lands per `docs/superpowers/audits/2026-05-04-bare-where-scanner-gap.md` § "How to track this".

The scanner-blind-spot follow-up doc itself is solid: it correctly diagnoses the structural class (chained `where('partner_id', ...)` on guarded models without tenant predicates), proposes a concrete `PhpAstWhereChainScanner` extension to Gate B, and sets clear false-positive handling rules. Recommend implementation in a follow-up PR per the doc's section "How to track this for the post-cluster sweep".

## What looks good

- Finding 15 fix is structurally identical to the round-3 Finding 14 template: controller resolves under `tenant_id + company_id` with explicit 404, service signature now takes `tenantId` as required parameter, both Document and Partner reads carry triple-predicate scope. Defense-in-depth is symmetric across both layers.
- The two new structural-SQL invariant tests (`test_get_open_invoices_filters_partner_and_document_by_tenant_id` and `test_payment_allocation_service_get_open_invoices_filters_by_tenant_id`) raise the regression bar substantially — they pin the SQL shape via `DB::enableQueryLog()` rather than data-level behavior. This is the right pattern for the rest of the sweep to inherit.
- Scanner-blind-spot follow-up doc (`docs/superpowers/audits/2026-05-04-bare-where-scanner-gap.md`) is thorough and actionable: clear root cause, clear false-positive handling, clear rollout plan, explicit cluster-by-cluster TODO list.
- Audit exhaustiveness held under independent hostile grep — every match in 19 hits trace-validated.

## Verification run

| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter "open_invoices"` | OK — 5 / 16 / 0 |
| `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` | **OK — 73 / 222 / 0** |
| `vendor/bin/phpunit tests/Feature/Treasury` | **OK — 272 / 833 / 0** (18 PHPUnit deprecations, no failures) |
| `vendor/bin/phpunit tests/Unit/Treasury` | **OK — 70 / 259 / 0** (11 PHPUnit deprecations, no failures) |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` | OK — no errors |
| `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/ tests/Unit/Treasury/` | `{"result":"pass"}` |
| `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` | OK — 2 tests / 4 assertions; Gate A=98, Gate B=102 (unchanged — scanners don't see bare-where, as documented in the blind-spot audit) |
| `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` | `verified 489 event(s) across 262 callsite(s); 0 problem(s)` |
| `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` | empty — no scope creep |
| Hostile grep for bare-where on route-anchor columns in Treasury | 19 hits, all categorized as CLEAN / CLEAN-VIA-* / pre-existing NICE-TO-HAVE |

**Treasury cluster locks. Hard gate opens.** Every cluster reviewed after Treasury inherits this remediation template: bare-where audit + structural-SQL invariant tests + tenant_id+company_id symmetry on every route-anchored read.
