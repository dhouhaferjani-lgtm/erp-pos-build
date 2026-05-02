# Verdict: A1-CLEAN-PROCEED-TO-B

## What changed since 4b8d7937 (your read)

`fc5c0763` only changes `ReceiptPaymentService.php` and `StoreReceiptPaymentsTenantIsolationTest.php`. The service now loads the receipt inside the transaction, requires CompanyContext, rejects receipt/company-context mismatch, scopes `customerId` through `Partner::query()->where(tenant_id)->where(company_id)->findOrFail()`, uses `$receipt->company_id` for repository/method lookups, and threads `$scopedCustomerId` into `Payment::create()`, `VoucherRedemptionRequest`, and `ReceiptCompleted`. The test file tightens the three HTTP negative cases, adds same-tenant/cross-company coverage, and adds the direct service-bypass regression test.

## Question-by-question findings (R1-R16)

### R1. Suggested edit #1: Partner scoping + receipt/context mismatch

Finding: verified-closed.

`processReceiptPayments()` enters `DB::transaction()` at `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:80`. Inside that closure it loads the receipt at `:81`, calls `CompanyContext::requireCompanyId()` at `:82`, throws `ModelNotFoundException` on company mismatch at `:92-93`, then scopes the partner at `:106-113`. No GL/payment writes happen until the payment loop starts at `:193`, with `Payment::create()` at `:252`. Fiscal advancement does not happen until `ReceiptFinalizationService::finalize()` at `:402`. The guard is therefore before any Treasury, GL, ReceiptPayment, or fiscal-status mutation. The initial `Receipt::with(['lines', 'vatDetails'])->findOrFail()` is an eager read only.

### R2. Suggested edit #2: use `$receipt->company_id` for instrument lookups

Finding: verified-closed.

Both writer-side lookups use the receipt company now. `PaymentRepository::query()` applies `where('tenant_id', $receipt->tenant_id)` and `where('company_id', $receipt->company_id)` at `ReceiptPaymentService.php:202-205`. `PaymentMethod::query()` applies the same receipt-derived predicates at `:222-225`. They no longer use `$companyId`.

### R3. Suggested edit #3: remove unscoped fallback in `withValidator()`

Finding: still-open-as-code-smell, verified-safe for the HTTP path.

The unscoped fallback still exists in `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php`: `$methodQuery = PaymentMethod::query()` at `:236`, with tenant/company predicates only added when `$receiptForScope !== null` at `:237-240`. This remains a cleanup miss from the prior review. It is not an exploitable HTTP bypass because the scoped `Rule::exists()` rules are created at `:112-117`, `:120-125`, and `:147-152`; FormRequest builds the default validator before registering `withValidator()` (`apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php:80-96`, `:116-125`), and Laravel validates normal rules before after-hooks (`apps/api/vendor/laravel/framework/src/Illuminate/Validation/Validator.php:469-503`). `ValidatesWhenResolvedTrait::validateResolved()` fails validation before the controller body (`apps/api/vendor/laravel/framework/src/Illuminate/Validation/ValidatesWhenResolvedTrait.php:17-35`). If the receipt is missing, the null-scoped `exists` rule fails closed before the controller; the unscoped after-hook can at most add extra errors.

### R4. Suggested edit #4: voucher redemption tenant scope

Finding: still-open, explicitly out of scope for A1.

`VoucherRedemptionService` still looks up vouchers globally by code: `Voucher::query()->where('code', $code)->lockForUpdate()->first()` at `apps/api/app/Modules/Voucher/Application/Services/VoucherRedemptionService.php:86-89`. No tenant/company predicates were added. This is unchanged and belongs to the Phase B sweep, per the commit message.

### R5. Service-bypass test origin

Finding: verified-closed, test could be tighter but exercises the intended guard.

`test_service_rejects_cross_tenant_customer_id_when_form_request_is_bypassed()` sets `CompanyContext` to `$this->companyB->id` at `apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php:357`, and the receipt is seeded for company B at `:352` via `seedReceiptForTenantB()` (`:415-418`). Therefore the mismatch guard at `ReceiptPaymentService.php:92-93` cannot be the source of the exception. The next new guard is the `Partner` scoped lookup at `:106-113`, so the thrown `ModelNotFoundException` is from the intended partner path. The test still only asserts exception type at `StoreReceiptPaymentsTenantIsolationTest.php:359`; a non-blocking hardening would assert `$e->getModel() === Partner::class`.

### R6. Same-tenant/cross-company test

Finding: verified-closed.

`test_same_tenant_cross_company_is_rejected()` creates company B2 under the same tenant B at `StoreReceiptPaymentsTenantIsolationTest.php:285-289`, creates method/repository rows with `tenant_id = tenantB` and `company_id = companyB2` at `:296-309`, then pays a receipt on company B at `:311-319`. The response asserts both field names and the custom `exists` messages at `:322-327`. This isolates the company predicate from the tenant predicate; tenant is equal and company differs.

### R7. Tightened body-string assertions

Finding: verified-safe enough for current custom envelope, but still looser than structured path assertions.

The custom renderer returns validation errors in `error.errors` at `apps/api/bootstrap/app.php:139-149`. The FormRequest custom messages are the exact text asserted by the tests: `Payment method does not exist` at `StoreReceiptPaymentsRequest.php:284`, `Payment repository does not exist` at `:290`, and `Customer does not exist` at `:299`. Because the tests assert both 422 and the custom message, a random 422 mentioning only a field name would not pass. This is acceptable for the current envelope. A better future assertion would decode JSON and assert `error.errors.payments.0.payment_method_id[0]`.

### R8. Coverage gap audit

Finding: verified-mostly-covered; recommend two non-blocking tests.

Cross-tenant tests still differ by tenant and company (`StoreReceiptPaymentsTenantIsolationTest.php:90-119`, `:122-154`), but UUID company ids cannot collide in the fixture and the same-tenant/cross-company test now isolates the company predicate. There is still no test that explicitly asserts the mismatch guard path by setting CompanyContext to company B2 and passing a company B receipt directly to the service. There is also no new tenant-isolation-specific voucher test proving `processReceiptPayments()` with a same-tenant `store_voucher` tender still succeeds after `$scopedCustomerId` threading, although existing voucher tests cover that behavior: `Tests\Feature\POS\ReceiptPaymentServiceVoucherRedemptionTest` passed in the POS and Receipt filters.

### R9. Mismatch-guard ordering and receipt retrieval side effects

Finding: verified-safe.

The guard runs after `Receipt::with(...)->findOrFail()` (`ReceiptPaymentService.php:81-93`) and before writes. I searched for Receipt model retrieval observers and listeners with `rg 'Receipt::observe|observe\(Receipt|ReceiptObserver|retrieved\(' app -S`; no Receipt observer or `retrieved()` hook was found. `apps/api/app/Modules/POS/Domain/Receipt.php` defines relationships and scopes, but no `boot()`, `booted()`, or retrieved lifecycle callback. The pre-guard read should not mutate state.

### R10. Missing CompanyContext behavior

Finding: verified-safe but operational footgun.

`CompanyContext::requireCompanyId()` throws `RuntimeException` when unset (`apps/api/app/Modules/Company/Services/CompanyContext.php:42-48`). That throw happens at `ReceiptPaymentService.php:82`, before any writes. HTTP routes normally get `CompanyContextMiddleware` via `apps/api/bootstrap/app.php:61-65`, but console/queue/programmatic callers must set context. Since `RuntimeException` has no renderer in `bootstrap/app.php` while `ModelNotFoundException` does (`:125-137`), an HTTP path missing middleware would surface as 500. This is not a new A1 tenant-isolation bypass, but a future service API should either require an explicit company id parameter or convert missing context to a typed operational exception.

### R11. Partner scoping discards the model

Finding: verified-safe.

The service intentionally uses `Partner::query()->where(...)->findOrFail($customerId)` as an existence/scope guard, then stores the id in `$scopedCustomerId` (`ReceiptPaymentService.php:106-113`). That is sufficient for tenant/company integrity because the query proves the id belongs to the receipt tenant/company. Keeping only the id avoids passing an Eloquent model into downstream DTOs/events. The argument for using the model is audit clarity and avoiding stale id/model mismatches if more partner fields are needed later, but the current downstream contracts only accept ids.

### R12. Voucher redemption + `$scopedCustomerId`

Finding: verified-safe for customer-bound vouchers; tenant voucher lookup remains Phase B.

`ReceiptPaymentService` passes `$scopedCustomerId` into `VoucherRedemptionRequest::partnerId` at `ReceiptPaymentService.php:345-353`. `VoucherRedemptionService` enforces customer-bound vouchers by comparing `$request->partnerId` with `$voucher->issued_to_partner_id` and throws `VoucherNotForThisCustomerException` on null or mismatch at `apps/api/app/Modules/Voucher/Application/Services/VoucherRedemptionService.php:127-135`. Therefore a validated but wrong customer cannot redeem a customer-bound voucher. The voucher lookup itself remains global by code (`:86-89`), as noted in R4.

### R13. Regression hunt

Finding: verified-no-new-A1-regression; suite has one known and one unrelated pre-existing failure.

Current `fc5c0763`:
- `php artisan test --filter=POS`: 1137 passed, 24 skipped, 2 incomplete, 1 failed. The failure is the known `Tests\Integration\POS\HashGoldenByteTest::php side json bytes match the golden fixture`.
- `php artisan test --filter=Voucher`: first run had one failure in `Tests\Feature\POS\ReceiptSyncServiceVoucherRedemptionTest::offline sync with store voucher payment invokes redemption service`; the same test passed in isolation, baseline `4b8d7937` full Voucher passed, and a rerun at `fc5c0763` passed with 222 passed, 6 skipped, 1 incomplete. I do not classify the first failure as a persistent A1 regression, but it is worth watching.
- `php artisan test --filter=Refund`: 96 passed, 1 failed: `Tests\Feature\Authorization\RefundFlowPermissionsTest::manager does not have admin only permissions` because manager has `pos.extend_voucher_expiry`. I checked baseline `4b8d7937` with the manager-filtered test and the same failure reproduces there, so it is pre-existing and not introduced by A1.
- `php artisan test --filter=Treasury`: 271 passed.
- `php artisan test --filter=Receipt`: 320 passed, 8 skipped.

No test that was green on `4b8d7937` is consistently red on `fc5c0763`.

### R14. PHPStan + Pint cleanliness

Finding: verified-clean.

`./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/POS/Application/Services/ReceiptPaymentService.php tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php` passed with `[OK] No errors`. `./vendor/bin/pint --test app/Modules/POS/Application/Services/ReceiptPaymentService.php tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php` returned `{"result":"pass"}`.

### R15. Out-of-scope items still open

Finding: verified-still-open, no scope drift.

The deferred Phase B items are still present at HEAD:
- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:434`: `PaymentMethod::findOrFail($entry['payment_method_id'])`.
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:523`: `Partner::find($customerId)`.
- `apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:97`: `Partner::find($partnerId)`.
- `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:56`: `Terminal::lockForUpdate()->findOrFail($receipt->terminal_id)`.
- `apps/api/app/Modules/Voucher/Application/Services/VoucherLookupService.php:90` and `:145`: `Voucher::where('code', $code)->first()`.
- `apps/api/app/Modules/Voucher/Application/Services/VoucherRedemptionService.php:86-89`: voucher lookup global by code.

`git show --stat fc5c0763` shows only the two expected files changed, so there was no hidden Phase B scope creep.

### R16. New gaps introduced by A1

Finding: verified-no-new-tenant-isolation-gap.

Threading `$scopedCustomerId` increases safety rather than reducing it: null remains null, a cross-tenant/company id fails before writes, and a valid but voucher-wrong partner is rejected by `VoucherRedemptionService` at `:127-135`. The mismatch guard is ordered before writes and after only a passive receipt read; no Receipt retrieved observer was found. The remaining risks are pre-existing/out-of-scope or operational exception shape, not a new A1 tenant-isolation bypass.

## Diff to the current patch (if any)

No blocking diff required for A1.

Optional test hardening:

```diff
diff --git a/apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php b/apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php
@@
-        $this->expectException(ModelNotFoundException::class);
-
         try {
             app(ReceiptPaymentService::class)->processReceiptPayments(
@@
                 customerId: $this->partnerA->id,
             );
+            $this->fail('Expected cross-tenant customer_id to throw ModelNotFoundException');
+        } catch (ModelNotFoundException $e) {
+            $this->assertSame(Partner::class, $e->getModel());
         } finally {
```

Recommended additional non-blocking service test:

```php
public function test_service_rejects_receipt_company_context_mismatch_before_writes(): void
{
    $companyB2 = Company::factory()->create(['tenant_id' => $this->tenantB->id]);
    $receipt = $this->seedReceiptForTenantB('10.000');

    app(CompanyContext::class)->setCompanyId($companyB2->id);

    try {
        app(ReceiptPaymentService::class)->processReceiptPayments(
            receiptId: $receipt->id,
            payments: [[
                'amount' => '10.000',
                'payment_method_id' => $this->methodB->id,
                'repository_id' => $this->repoB->id,
            ]],
            customerId: null,
        );
        $this->fail('Expected receipt/company context mismatch to throw ModelNotFoundException');
    } catch (ModelNotFoundException $e) {
        $this->assertSame(Receipt::class, $e->getModel());
    }

    $this->assertSame(0, ReceiptPayment::query()->where('receipt_id', $receipt->id)->count());
}
```

## Confidence gradient

- HTTP-path closure: 5/5. The request rules are tenant/company scoped and FormRequest validation order was re-verified. The only thing that would change this rating is a custom request resolver that bypasses normal FormRequest validation.
- Service-bypass closure: 5/5. The customer, repository, and method are scoped before writes. The only thing that would change this rating is a hidden programmatic caller passing already-mutated receipt state into a different writer path.
- Mismatch-guard ordering: 4/5. No Receipt retrieved observer exists and the guard precedes writes. This would drop if a new observer/listener is registered for `Receipt` retrieval or eager-loaded relation retrieval.
- Test-coverage soundness: 4/5. The new tests cover the original gap, the service bypass, and same-tenant/cross-company. It would be 5/5 with explicit exception-model assertions and a direct mismatch-guard test.
- Regression hunt: 3/5. A1-specific suites are clean after rerun, but the requested Refund filter has a pre-existing failure and the first Voucher run had a transient failure that did not reproduce. This rating would improve after the refund permission fixture is fixed and Voucher is stable across repeated full-filter runs.

## Out-of-scope items still open

- `ReceiptSyncService::PaymentMethod::findOrFail` remains unscoped at `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:434`.
- `ReceiptCreationService::Partner::find` remains unscoped at `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:523`.
- `OrderManagementService::Partner::find` remains unscoped at `apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:97`.
- `ReceiptFinalizationService::Terminal::findOrFail` remains unscoped at `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:56`.
- `VoucherLookupService` still performs code-based voucher lookups without tenant/company predicates at `apps/api/app/Modules/Voucher/Application/Services/VoucherLookupService.php:90` and `:145`.
- `VoucherRedemptionService` still performs global voucher lookup by code at `apps/api/app/Modules/Voucher/Application/Services/VoucherRedemptionService.php:86-89`.
