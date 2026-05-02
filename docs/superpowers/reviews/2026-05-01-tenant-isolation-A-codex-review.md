# Verdict: EXPLOIT-STILL-OPEN

The HTTP validator fix closes the originally reported cross-tenant request path for `payment_method_id`, `repository_id`, and `customer_id`. The exploit is still open at the service layer: `ReceiptPaymentService::processReceiptPayments()` accepts an arbitrary `$customerId` from programmatic callers and persists it to `treasury_payments.partner_id` without tenant/company scoping.

## Adversarial findings (numbered)

1. **Bypass via missing receipt: verified-safe.**
   `StoreReceiptPaymentsRequest::rules()` scopes `payment_methods`, `payment_repositories`, and `partners` with nullable values from `resolveReceipt()` when the receipt is missing (`apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php:106`, `:112`, `:120`, `:147`). Laravel 12.56.0 turns `Rule::exists()->where('tenant_id', null)` into a `whereNull` condition, not a skipped condition: `vendor/laravel/framework/src/Illuminate/Validation/Rules/DatabaseRule.php:89` calls `whereNull()` for null, and `vendor/laravel/framework/src/Illuminate/Validation/DatabasePresenceVerifier.php:102` translates the stored `"NULL"` marker into `$query->whereNull($key)`. The relevant treasury tables use non-null UUID tenant columns (`apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:15`, `:52`; company is made required in `apps/api/database/migrations/tenant/2025_11_30_134000_make_company_id_required.php:26`, `:56`, `:61`), so this fails closed.

2. **Bypass via `authorize()` short-circuit: verified-safe for HTTP.**
   `authorize()` does return true early for non-string route ids, malformed payments, and missing receipts (`StoreReceiptPaymentsRequest.php:55`, `:60`, `:65`). That does not reach the controller before validation. Laravel resolves a FormRequest through `ValidatesWhenResolvedTrait::validateResolved()`: prepare, authorize, build validator/rules, fail validation if needed, then controller (`vendor/laravel/framework/src/Illuminate/Validation/ValidatesWhenResolvedTrait.php:17`). `FormRequest::getValidatorInstance()` invokes `rules()` and `withValidator()` before validation failure can throw (`vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php:80`, `:94`, `:116`). The controller body at `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:494` is therefore not entered on these validation failures.

3. **Bypass via `$request->validated('customer_id')` when omitted: verified-safe.**
   `FormRequest::validated($key, $default)` delegates to `data_get($this->validator->validated(), $key, $default)` (`vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php:243`). `Validator::validated()` only includes keys present in the validated input (`vendor/laravel/framework/src/Illuminate/Validation/Validator.php:642`), and `data_get()` returns the default, null here, when the key is absent (`vendor/laravel/framework/src/Illuminate/Collections/helpers.php:76`). An omitted `customer_id` becomes null and is not converted into an unvalidated value.

4. **Cross-company-within-same-tenant: verified-safe in the validator, insufficiently covered.**
   The request rules scope all three foreign ids by both `tenant_id` and `company_id` derived from the receipt (`StoreReceiptPaymentsRequest.php:112`, `:120`, `:147`). A payment method on tenant T/company C2 cannot satisfy a receipt on tenant T/company C1. The service also scopes payment repositories and methods by `$receipt->tenant_id` plus the current company context (`ReceiptPaymentService.php:169`, `:189`), but it does not verify that the current company context equals `$receipt->company_id` before using it. Add the cross-company test below to prove this path stays closed.

5. **Service-layer bypass for programmatic callers: gap-found.**
   `ReceiptPaymentService::processReceiptPayments()` loads the receipt unscoped (`apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:80`) and accepts `$customerId` as a raw argument (`:70`). It scopes `PaymentRepository` (`:169`) and `PaymentMethod` (`:189`) but never scopes or even loads `Partner`. The raw `$customerId` is persisted directly as `partner_id` in `Payment::create()` (`:219`, `:223`) and is also passed into voucher redemption (`:318`). Internal callers can therefore bypass `StoreReceiptPaymentsRequest`, pass tenant B receipt/method/repository plus tenant A partner id, and create a tenant B payment referencing tenant A's partner. I did not find other `PaymentMethod::find*` or `PaymentRepository::find*` calls in this service beyond the newly scoped lookups; the missed model in this service is `Partner`.

6. **VoucherRedemptionService chain: gap-found/risk.**
   `processReceiptPayments()` calls `VoucherRedemptionService::redeem()` for store voucher tenders (`ReceiptPaymentService.php:312`) and passes receipt/cashier/terminal ids from the receipt plus raw `$customerId` (`:315`, `:318`, `:320`). `VoucherRedemptionService` looks up vouchers by normalized code only (`apps/api/app/Modules/Treasury/Application/Services/VoucherRedemptionService.php:81`, `:86`) and does not scope the lookup by tenant/company. It later checks terminal match, currency, status, expiry, and customer binding (`:99`, `:118`, `:127`), but those are not tenant/company scopes. GL account lookup is scoped through the voucher company: `GeneralLedgerService::createVoucherLedgerEntry()` uses `$voucher->company_id` (`apps/api/app/Modules/Treasury/Application/Services/GeneralLedgerService.php:932`, `:953`). If a cross-tenant terminal id were threaded into a receipt elsewhere, voucher redemption would write ledger/journal rows under the voucher tenant/company while storing caller-supplied receipt/cashier/terminal provenance (`VoucherRedemptionService.php:186`, `:197`, `:215`). This is not the original exploit path because voucher codes are globally unique (`apps/api/database/migrations/tenant/2026_05_02_000001_create_vouchers_table.php:19`), but the service is not tenant-isolation-defensive.

7. **`Rule::exists` with `tenant_id=""`: verified-safe/fail-closed.**
   Laravel only special-cases actual null values into `whereNull`; an empty string is stored as an equality condition (`DatabaseRule.php:89`). Against UUID tenant columns, `tenant_id = ''` either matches no row or raises an invalid UUID value, but it does not silently drop the tenant predicate. There is no bypass unless the database itself contains corrupted empty-string tenant ids in a non-UUID schema.

8. **Test coverage soundness: gap-found in coverage, not in the HTTP fix.**
   `StoreReceiptPaymentsTenantIsolationTest` exercises the negative request paths, but the negative data always differs by both tenant and company (`apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php:75`, `:109`, `:177`, `:210`, `:238`). The assertions allow either 404 or 422 and do not pin the validation error key (`:191`, `:222`, `:251`), so a failure could come from an unintended controller exception or a company-only rejection rather than the intended scoped rule. The same-tenant control (`:267`) is useful and would catch an over-aggressive fail-closed rule for the normal single-company path, but it does not cover same-tenant/cross-company rejection.

9. **Regression hunt - fiscal-chain integrity: verified-safe except known pre-existing failure.**
   With 4b8d7937 applied:
   - `php artisan test --filter=Fiscal`: 156 passed, 7 skipped, 2 incomplete.
   - `php artisan test --filter=ReceiptChainVerification`: 6 passed.
   - `php artisan test --filter=HashGolden`: `Tests\Feature\POS\HashGoldenByteTest::php side json bytes match the golden fixture` failed on decimal byte formatting. This is the known pre-existing HashGoldenByteTest failure called out in the review prompt and was ignored.
   - `php artisan test --filter=Voucher`: 222 passed, 6 skipped, 1 incomplete.
   I did not identify a new fiscal-chain failure attributable to this patch.

10. **PHPStan + Pint cleanliness: mixed.**
    `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` initially hit the sandbox TCP listen restriction, then passed when rerun with approval: `[OK] No errors`. Whole-repo `./vendor/bin/pint --test` fails in an unrelated file, `tests/Feature/Identity/UserManagement/SetPosPinTest.php`, with formatting issues. The patch files themselves pass Pint when checked directly: `app/Modules/POS/Presentation/Requests/StoreReceiptPaymentsRequest.php`, `app/Modules/POS/Application/Services/ReceiptPaymentService.php`, and `tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php`.

11. **Comment correctness: verified-safe.**
    The patch comment that `PaymentMethod` has no global tenant/company scope is correct. `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php:48` uses only `HasFactory` and `HasUuids`; there is no `boot()`, `booted()`, or `addGlobalScope`. Tenant/company helpers are explicit local scopes (`:167`, `:211`). `PaymentRepository` is the same shape (`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:50`, `:134`, `:167`).

12. **Out-of-scope creep: verified-safe.**
    `git show --stat 4b8d7937` reports exactly three files changed: `ReceiptPaymentService.php`, `StoreReceiptPaymentsRequest.php`, and `StoreReceiptPaymentsTenantIsolationTest.php`. No unrelated production files or broad refactors were included.

## New tests you added or recommend adding

I did not add tests to the branch. These are the minimum tests I recommend adding before proceeding.

1. Add this to `apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php` to pin the service-layer customer gap. It currently fails because no exception is thrown and a cross-tenant `partner_id` can be persisted.

```php
public function test_service_rejects_cross_tenant_customer_id_when_form_request_is_bypassed(): void
{
    $receipt = $this->seedReceiptForTenantB('10.000');

    app(\App\Modules\Company\Services\CompanyContext::class)->setCompanyId($this->companyB->id);

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    app(\App\Modules\POS\Application\Services\ReceiptPaymentService::class)->processReceiptPayments(
        receiptId: $receipt->id,
        payments: [[
            'amount' => '10.000',
            'payment_method_id' => $this->methodB->id,
            'repository_id' => $this->repoB->id,
        ]],
        customerId: $this->partnerA->id,
    );
}
```

2. Add same-tenant/cross-company coverage to prove the company predicate is doing real work.

```php
public function test_store_payments_rejects_payment_instruments_from_another_company_in_same_tenant(): void
{
    $companyC = Company::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Other Company',
        'code' => 'TB2',
        'base_currency' => 'TND',
    ]);

    app(ChartOfAccountsService::class)->seedForCompany($companyC);

    $cashGlC = Account::query()
        ->where('tenant_id', $this->tenantB->id)
        ->where('company_id', $companyC->id)
        ->where('code', '1000')
        ->firstOrFail();

    $methodC = PaymentMethod::query()->create([
        'tenant_id' => $this->tenantB->id,
        'company_id' => $companyC->id,
        'code' => 'CASH_C',
        'name' => 'Cash C',
        'type' => PaymentMethodType::CASH->value,
        'currency' => 'TND',
        'requires_instrument' => false,
        'is_active' => true,
    ]);

    $repoC = PaymentRepository::query()->create([
        'tenant_id' => $this->tenantB->id,
        'company_id' => $companyC->id,
        'code' => 'DRAWER_C',
        'name' => 'Drawer C',
        'type' => PaymentRepositoryType::CASH_DRAWER->value,
        'currency' => 'TND',
        'gl_account_id' => $cashGlC->id,
        'is_active' => true,
    ]);

    $receipt = $this->seedReceiptForTenantB('10.000');

    $response = $this->actingAs($this->userB)->postJson(route('pos.receipts.payments.store', $receipt->id), [
        'payments' => [[
            'amount' => '10.000',
            'payment_method_id' => $methodC->id,
            'repository_id' => $repoC->id,
        ]],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors([
        'payments.0.payment_method_id',
        'payments.0.repository_id',
    ]);
}
```

3. Tighten the existing tenant-isolation negative tests. Replace broad status assertions with key-specific validation assertions:

```php
$response->assertStatus(422);
$response->assertJsonValidationErrors(['payments.0.payment_method_id']);
```

```php
$response->assertStatus(422);
$response->assertJsonValidationErrors(['payments.0.repository_id']);
```

```php
$response->assertStatus(422);
$response->assertJsonValidationErrors(['customer_id']);
```

## Suggested edits

1. Scope `customerId` in `ReceiptPaymentService` and reject company-context/receipt mismatches before persisting anything.

```diff
diff --git a/apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php b/apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php
@@
 use App\Modules\Partner\Domain\Company;
+use App\Modules\Partner\Domain\Partner;
@@
-            $receipt = Receipt::findOrFail($receiptId);
-            $companyId = $this->companyContext->requireCompanyId();
+            $receipt = Receipt::findOrFail($receiptId);
+            $companyId = $this->companyContext->requireCompanyId();
+
+            if ($companyId !== $receipt->company_id) {
+                throw (new ModelNotFoundException())->setModel(Receipt::class, [$receiptId]);
+            }
+
+            $scopedCustomerId = null;
+            if ($customerId !== null) {
+                Partner::query()
+                    ->where('tenant_id', $receipt->tenant_id)
+                    ->where('company_id', $receipt->company_id)
+                    ->findOrFail($customerId);
+
+                $scopedCustomerId = $customerId;
+            }
@@
-                'partner_id' => $customerId,
+                'partner_id' => $scopedCustomerId,
@@
-                            customerId: $customerId,
+                            customerId: $scopedCustomerId,
@@
-                event(new ReceiptCompleted($receiptId, $companyId, $customerId));
+                event(new ReceiptCompleted($receiptId, $companyId, $scopedCustomerId));
```

2. Prefer the receipt company id in scoped instrument lookups after the context/receipt equality check.

```diff
@@
                 $repository = PaymentRepository::query()
                     ->where('tenant_id', $receipt->tenant_id)
-                    ->where('company_id', $companyId)
+                    ->where('company_id', $receipt->company_id)
                     ->findOrFail($paymentData['repository_id']);
@@
                 $method = PaymentMethod::query()
                     ->where('tenant_id', $receipt->tenant_id)
-                    ->where('company_id', $companyId)
+                    ->where('company_id', $receipt->company_id)
                     ->findOrFail($paymentData['payment_method_id']);
```

3. Remove the remaining unscoped fallback in the FormRequest `withValidator()` path. It is not exploitable after the base `exists` failure, but it contradicts the fail-closed intent.

```diff
@@
-                $methodQuery = PaymentMethod::query();
-
                 $receiptForScope = $receipt ?? $this->resolveReceipt();
                 if ($receiptForScope !== null) {
-                    $methodQuery->where('tenant_id', $receiptForScope->tenant_id)
-                        ->where('company_id', $receiptForScope->company_id);
+                    $method = PaymentMethod::query()
+                        ->where('tenant_id', $receiptForScope->tenant_id)
+                        ->where('company_id', $receiptForScope->company_id)
+                        ->find($methodId);
+                } else {
+                    continue;
                 }
-
-                $method = $methodQuery->find($methodId);
```

4. Harden voucher redemption by passing expected tenant/company into the redemption request and scoping the voucher lookup. Exact field names can vary, but the lookup must become tenant/company constrained.

```diff
@@
             $voucher = Voucher::query()
                 ->where('code', $normalizedCode)
+                ->where('tenant_id', $request->tenantId)
+                ->where('company_id', $request->companyId)
                 ->lockForUpdate()
                 ->first();
```

## Out-of-scope items you noticed but did not investigate

- `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:434` has an unscoped `PaymentMethod::findOrFail()` path for sync payloads.
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:523` and `apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:97` use unscoped `Partner::find()` lookups.
- `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:56` uses unscoped `Terminal::findOrFail()` while finalizing receipts.
- `apps/api/app/Modules/Treasury/Application/Services/VoucherLookupService.php` appears to contain code-based voucher lookups that should be checked for tenant/company scoping in the next sweep.
- Voucher tenders currently pass through `createPOSPaymentEntry()` before voucher redemption in `ReceiptPaymentService.php:235`; there is a local comment that voucher journals "net out". I did not audit whether that accounting treatment is correct.
