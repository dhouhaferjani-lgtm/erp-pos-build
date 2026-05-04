# Codex adversarial round-5 cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: d7184178
Cumulative review rounds: 5
Reviewer: codex (second-layer review of Opus round-5 APPROVE)

Verdict: APPROVE
Commit reviewed: b09c7ac6

## Verdict
APPROVE

Opus's hard-gate approval is honest on the runtime isolation question: Finding 15 is closed, the independent bare-where audit still returns 19 known matches with zero new Finding-14/15 class instances, and every required verification gate passed. One minor evidence caveat: Opus overstated the controller SQL-invariant test. The controller structural test asserts `tenant_id` on both captured queries, but does not also assert `company_id`; the service structural test asserts both `tenant_id` and `company_id`. The implementation itself does carry both predicates, so this is a NICE-TO-HAVE test hardening item, not a gate blocker.

## Finding 15 closure verification
- Status: CLOSED
- Evidence:
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/SmartPaymentController.php:150-216`: `getOpenInvoices()` obtains `companyId` with `requireCompanyId()` and `tenantId` from `requireCompany()->tenant_id`. Partner resolution applies `tenant_id + company_id + id` and returns structured `PARTNER_NOT_FOUND` 404 when absent. The open-invoice `Document` read applies `tenant_id + company_id + partner_id`.
  - `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:52-75`: `previewAllocation()` resolves `tenantId` from `CompanyContext` and passes it to the private helper.
  - `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:338-367`: `getOpenInvoices(string $tenantId, string $companyId, string $partnerId, AllocationMethod $method)` requires tenant and company ids and applies both predicates before `partner_id`.
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1797-2068`: five new tests cover cross-tenant denial, same-tenant controller success, service cross-tenant denial, and two SQL-log structural checks. The service SQL-log test asserts both `"tenant_id"` and `"company_id"`. The controller SQL-log test asserts `"tenant_id"` on Partner and Document SQL but does not assert `"company_id"`; the runtime code still has both.
  - Focused command passed: `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter "open_invoices" 2>&1 | tail -10` -> OK, 5 tests, 16 assertions.

## Audit exhaustiveness — independent verification
- Hostile grep result count: 19. BSD `grep` required an equivalent `rg`/`grep --` form because the pattern begins with `->`; the matched production Treasury callsites are the same 19 Opus listed.

| Category | Count | Matches |
|---|---:|---|
| CLEAN | 5 | `PaymentAllocationService.php:343`, `MultiPaymentService.php:220`, `MultiPaymentService.php:321`, `SmartPaymentController.php:164`, `SmartPaymentController.php:180` |
| CLEAN-VIA-STRUCTURE | 4 | `BankReconciliationService.php:73`, `BankReconciliationService.php:101`, `BankReconciliationService.php:132`, `VendorRefundService.php:66` |
| CLEAN-VIA-BUILDER-COMPOSITION | 2 | `Payment.php:202`, `PaymentInstrument.php:190` |
| CLEAN-VIA-CALLER-PATH | 1 | `CloseInvoiceWithToleranceService.php:53` |
| NICE-TO-HAVE | 7 | `PaymentRefundService.php:210`, `PaymentRefundService.php:668`, `BankReconciliationController.php:39`, `PaymentRepositoryController.php:186`, `PaymentController.php:57`, `PaymentInstrumentController.php:44`, `PaymentInstrumentController.php:49` |
| NEW FINDING | 0 | none |

- Spot-checks performed:
  - `SmartPaymentController::getOpenInvoices`: verified partner and document reads are scoped by `tenant_id + company_id`.
  - `PaymentAllocationService::getOpenInvoices`: verified helper signature and document query require both scope ids.
  - `CloseInvoiceWithToleranceService::close`: service re-queries by id, but production caller first loads the invoice through `InvoiceController::baseQuery()->ofType(Invoice)->find($invoice)`; no queue/event/scheduler entry point found.
  - `BankReconciliationService::matchItem` and `unmatchItem`: controller validates the reconciliation under tenant before service lookup; items are structurally anchored to that reconciliation.
  - `MultiPaymentService` partner-balance methods: both retain the round-3 fix with `tenant_id + company_id + partner_id`.
  - `VendorRefundService::refundPrepayment`: locks the PO under `tenant_id + company_id` before document allocation reads.
  - `PaymentRefundService::getRefundHistory` / `findExistingFullRefund`: tenant is read from a caller-provided Payment model; still missing company predicate, but this is the round-4 deferred NICE-TO-HAVE class.
  - Raw SQL / joins / whereIn sweep: no new Treasury finding. `PaymentToleranceQueryService` raw `pos_receipts` read is consumed from POS Z-report generation after terminal/company validation; `AuditDiscountsCommand` raw reads are console audit surfaces; the lone `whereIn(id)` is the existing POS receipt payment proration path anchored on a scoped receipt.

## Dead code claim verification
- Payment::scopeForPartner: verified dead in Treasury. Grep found only the scope definition at `apps/api/app/Modules/Treasury/Domain/Payment.php:200`.
- PaymentInstrument::scopeForPartner: verified dead in Treasury. Grep found only the scope definition at `apps/api/app/Modules/Treasury/Domain/PaymentInstrument.php:188`.

## CloseInvoiceWithToleranceService caller path
- Single production caller: verified. Grep found constructor injection and call from `apps/api/app/Modules/Document/Presentation/Controllers/InvoiceController.php`, with the actual call at line 738. Additional matches were comments/tests/result DTO references, not app entry points.
- Caller path: `InvoiceController::closeWithTolerance()` first resolves the invoice through `baseQuery()->ofType(DocumentType::Invoice)->find($invoice)`; `baseQuery()` returns `Document::forCompany($companyId)`. No event listener, queue job, command, or scheduler path calls the service directly.

## NEW findings (if any)
- Severity: NICE-TO-HAVE
- Location: `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1879-1934`
- Issue: the controller SQL-invariant test uses `DB::enableQueryLog()` and asserts `"tenant_id"` on the Partner and Document SQL, but does not also assert `"company_id"`. Opus's statement that both structural tests literally pin `tenant_id + company_id` is therefore partially overstated.
- Fix: add `"company_id"` assertions for `$partnerQuery` and `$documentQuery`. This is test hardening only; the controller implementation already applies both predicates.

## Verification run
| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter "open_invoices" 2>&1 \| tail -10` | OK, 5 tests, 16 assertions |
| `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` | OK, 73 tests, 222 assertions |
| `vendor/bin/phpunit tests/Feature/Treasury` | OK, 272 tests, 833 assertions, 18 PHPUnit deprecations |
| `vendor/bin/phpunit tests/Unit/Treasury` | OK, 70 tests, 259 assertions, 11 PHPUnit deprecations |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` | OK, no errors |
| `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/ tests/Unit/Treasury/` | `{"result":"pass"}` |
| `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` | OK, 2 tests, 4 assertions; Gate A 98, Gate B 102 |
| `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` | verified 489 events across 262 callsites; 0 problems |
| `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` | empty |
| Forbidden diff greps for added production `app(`, `@phpstan-ignore`, and `: mixed` in `707284e6..d7184178` | empty |

## What looks good
- Finding 15 now follows the established round-3 template: route-supplied partner id is resolved under tenant and company in the controller, and the service helper independently requires tenant and company ids.
- The manual bare-where sweep is complete for the requested route-anchor columns; all 19 matches are accounted for and none are new IMPORTANT/CRITICAL isolation misses.
- The scanner-gap document is accurate and actionable: it identifies why `where(...)` chains evade the current exists/find scanners and gives a concrete Gate C direction for the post-cluster sweep.
