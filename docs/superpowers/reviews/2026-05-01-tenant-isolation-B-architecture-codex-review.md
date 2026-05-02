# Verdict (one line)

REQUIRES-DIFFERENT-APPROACH

# Top 3 risks

The proposed helper hard-codes `tenant_id + company_id` as the universal shape, but the audit set includes tenant-only and tenant-parent resources. `users` has `tenant_id` and no `company_id` (`apps/api/database/migrations/2025_11_30_000003_create_users_table.php:16`-`40`), and `companies` has `tenant_id` but no `company_id` (`apps/api/database/migrations/2025_11_30_104000_create_companies_table.php:23`-`27`). Applying `forCurrentTenant('users')` or `forCurrentTenant('companies')` would either SQL-error (`column company_id does not exist`) or force callers to avoid the helper at the highest-volume callsites. The required grep shows `users` is the largest family at 13 sites, so this is not an edge case.

The static `forCurrentTenant()` method violates the repo's explicit DI rule and creates a request-lifecycle dependency that the type signature hides. Rule #13 says "Constructor Injection Only" and "Never use `app()` helper" (`CLAUDE.md:60`-`61`; `docs/conventions/07-DEPENDENCY-INJECTION.md:6`-`9`, `39`-`60`). The proposed helper calls both `auth()` and `app(CompanyContext::class)`. In non-HTTP validation it either throws via `CompanyContext::requireCompanyId()` (`apps/api/app/Modules/Company/Services/CompanyContext.php:42`-`49`) or resolves a null user and emits an `IS NULL` tenant predicate. That is fail-closed, but it is an operational footgun, not a clean architecture boundary.

The sweep plan will miss real gaps because it is scoped to string `exists:` rules in `Presentation/Requests` and only one regression test per family. The required grep finds 34 sites, not "~60", and it misses controller inline validation such as `MultiPaymentController` (`apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:28`-`35`, `68`-`75`, `112`-`115`) and service writer lookups such as `ReceiptSyncService` (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:433`-`469`). A family-level test can pass for one module while another module still accepts unscoped IDs.

# Question-by-question findings (A1-A16)

## A1. Is `Shared/Validation/` actually the right home?

Finding: partial/refuted.

`Shared/` is real, but the existing layout is layered: `Shared/Application/DTOs`, `Shared/Contracts`, `Shared/Domain`, `Shared/Infrastructure`, `Shared/DTOs`, `Shared/Enums`, `Shared/Events`, and `Shared/TypeScript` (`find apps/api/app/Shared -maxdepth 3 -type f`). There is no existing `Shared/Validation` or `Shared/Presentation` surface. The architecture reference defines `Presentation` as controllers, requests, resources (`.claude/context/architecture.md:3`-`10`, `25`-`29`). A validation helper used only by FormRequests belongs under `App\Shared\Presentation\Validation`, not a new top-level `Shared\Validation` bucket that erases the layer.

The existing `Shared/Contracts` convention is for cross-module ports and immutable DTOs, e.g. `AccountingServiceInterface` explicitly documents module boundaries (`apps/api/app/Shared/Contracts/AccountingServiceInterface.php:10`-`15`) and compliance DTOs keep Compliance from touching POS models (`apps/api/app/Shared/Contracts/Compliance/DTOs/Nf525ReceiptData.php:9`-`16`). A static FormRequest helper is not a contract. It is acceptable in Shared only if it is presentation-scoped and has no module dependency.

Counter-placement: `apps/api/app/Shared/Presentation/Validation/ScopedExists.php`.

## A2. Does static factory + static `auth()` / `app()` violate Rule #13?

Finding: refuted.

Yes, it violates the rule in spirit and in text. The DI convention says all dependencies must be constructor-injected and calls out `app()` as hidden dependency/service-locator usage (`docs/conventions/07-DEPENDENCY-INJECTION.md:6`-`9`, `39`-`60`, `181`-`203`). It also says context services should be injected (`docs/conventions/07-DEPENDENCY-INJECTION.md:63`-`73`). Laravel FormRequests can use dependencies without a static locator: `FormRequest::validationRules()` calls `$this->container->call([$this, 'rules'])` (`apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php:151`-`154`), and the repo already constructor-injects `CompanyContext` into a FormRequest (`apps/api/app/Modules/Coupon/Presentation/Requests/UpdateCouponRequest.php:13`-`19`, `29`-`41`).

Clean alternative:

```php
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;

final class CreateBatchRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'product_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
            ],
        ];
    }
}
```

This is more idiomatic for this codebase than a static service locator because it matches the documented rule and the existing `UpdateCouponRequest` pattern.

## A3. Does `forCurrentTenant()` hide a request-lifecycle dependency?

Finding: verified.

`CompanyContext` is a singleton (`apps/api/app/Providers/AppServiceProvider.php:50`-`54`) whose state is set by `CompanyContextMiddleware` from an authenticated request (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:41`-`78`). `requireCompanyId()` throws if middleware has not set the context (`apps/api/app/Modules/Company/Services/CompanyContext.php:42`-`49`). In a queue job or console validator, `auth()->user()` may be null and `CompanyContext` may be unset, so the proposed `forCurrentTenant()` either throws at rule construction or creates a tenant `IS NULL` predicate if some code set company context but not auth.

Failure mode:

```php
Validator::make(
    ['product_id' => $id],
    ['product_id' => [ScopedExists::forCurrentTenant('products')]]
)->passes();
```

Outside HTTP middleware, this can throw `RuntimeException('No company context set...')` before validation runs. That is not a security bypass, but it is a hidden lifecycle coupling.

## A4. Are two factory methods enough?

Finding: refuted.

Ten audited callsites do not fit two methods cleanly:

- `CreateBatchRequest::product_id` is current tenant + current company (`apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:17`-`25`): fits tenant+company.
- `StoreReceiptRequest::lines.*.product_id` and `customer_id` should be scoped to the terminal/receipt company, not blindly to a header if terminal is the route context (`apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:31`-`49`): needs parent-context scoping.
- `GenerateZReportRequest::cash_counts.*.payment_method_id` should be scoped to the terminal's tenant/company, not merely current company (`apps/api/app/Modules/POS/Presentation/Requests/GenerateZReportRequest.php:41`-`52`): needs parent-context scoping.
- `GenerateZReportRequest::manager_user_id` is user tenant-scoped plus permission logic, not company-scoped (`apps/api/app/Modules/POS/Presentation/Requests/GenerateZReportRequest.php:21`-`24`, `70`-`87`): needs tenant-only plus domain check.
- `VerifyManagerPinRequest::user_id` is tenant-only and likely permission/status filtered (`apps/api/app/Modules/POS/Presentation/Requests/VerifyManagerPinRequest.php:19`-`24`): tenant-only.
- `CreateCountingRequest` has products and users together (`apps/api/app/Modules/Inventory/Presentation/Requests/CreateCountingRequest.php:33`-`50`): products are company-scoped, users are tenant or membership-scoped.
- `CreateProgramRequest::company_ids.*` is not current-company; it is a tenant list of companies (`apps/api/app/Modules/Loyalty/Presentation/Requests/CreateProgramRequest.php:29`-`31`): tenant-only companies, probably membership/permission-aware.
- `CreateWithholdingCertificateRequest::partner_id` and `document_id` should be same company and mutually consistent (`apps/api/app/Modules/Taxation/Presentation/Requests/CreateWithholdingCertificateRequest.php:22`-`29`): tenant+company plus cross-field consistency.
- `GetLedgerRequest::partner_id` is a current-company filter (`apps/api/app/Modules/Accounting/Presentation/Requests/GetLedgerRequest.php:73`-`106`): fits tenant+company, but the same request also has unscoped `accounts`.
- `RefundPrepaymentRequest` has `payment_method_id` and `repository_id` (`grep` output): current company, but should also be checked against the payment/prepayment context.

The helper needs at least `tenantAndCompany()`, `tenantOnly()`, `companyOnly()` for tables such as `accounts` if tenant is not present, and a builder/closure escape hatch for parent-context/cross-field rules.

## A5. Does `Shared/Validation/ScopedExists` importing `CompanyContext` violate Rule #6?

Finding: partial.

Rule #6 permits cross-module communication through `Shared/Contracts`, events, or a module's public Service class (`CLAUDE.md:45`-`47`; `.claude/context/architecture.md:31`-`37`). `CompanyContext` is treated as a public context service throughout controllers, and `Shared\Infrastructure\CurrencyScaleResolver` already injects it (`apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:5`-`10`, `23`-`33`). So importing `CompanyContext` is not unprecedented.

The problem is direction and hidden resolution: a Shared presentation utility should not service-locate a Company module concrete. The cleanest fix is not moving `CompanyContext` into `Shared/Contracts`; it is making the helper explicit and dependency-free:

```php
ScopedExists::tenantAndCompany('products', $this->user()?->tenant_id, $companyId);
ScopedExists::tenant('users', $this->user()?->tenant_id);
ScopedExists::tenant('companies', $this->user()?->tenant_id);
```

That avoids breaking callsites and keeps CompanyContext injection at the FormRequest/controller boundary where the repo already accepts it.

## A6. Does this scoping rule belong in Domain instead?

Finding: partial.

Tenant/company ownership is a domain invariant, and the models already expose manual scopes: `PaymentMethod::scopeForTenant()` / `scopeForCompany()` (`apps/api/app/Modules/Treasury/Domain/PaymentMethod.php:161`-`214`), `PaymentRepository::scopeForTenant()` / `scopeForCompany()` (`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:128`-`170`), `Partner::scopeForTenant()` / `scopeForCompany()` (`apps/api/app/Modules/Partner/Domain/Partner.php:232`-`252`), and `Product::scopeForTenant()` / `scopeForCompany()` (`apps/api/app/Modules/Product/Domain/Product.php:219`-`239`).

But Laravel `Rule::exists()` is Presentation-tier validation. The helper should not replace model scopes or add global scopes; it should standardize the Presentation adapter's database presence checks. Services still need explicit model scopes because they operate after validation and often in queued/programmatic flows.

## A7. Verify Claim 4 with the actual Laravel version.

Finding: verified, but with a caveat.

The repo resolves Laravel Framework `v12.56.0` (`apps/api/composer.lock:2647`-`2656`; `apps/api/composer.json:15`). In this version, `DatabaseRule::where($column, null)` routes to `whereNull()` (`apps/api/vendor/laravel/framework/src/Illuminate/Validation/Rules/DatabaseRule.php:89`-`101`), `whereNull()` records the sentinel `'NULL'` (`apps/api/vendor/laravel/framework/src/Illuminate/Validation/Rules/DatabaseRule.php:134`-`137`), and `DatabasePresenceVerifier::addWhere()` translates `'NULL'` to `$query->whereNull($key)` (`apps/api/vendor/laravel/framework/src/Illuminate/Validation/DatabasePresenceVerifier.php:102`-`107`). So it generates SQL `IS NULL`, not `= NULL`.

For tables where the column is actually non-null, this fails closed. `company_id` was made non-null for `partners`, `products`, `documents`, `payment_methods`, and `payment_repositories` (`apps/api/database/migrations/2025_11_30_134000_make_company_id_required.php:24`-`62`). The caveat is that this proof only applies to tables that have the column. It does not save `users` or `companies`.

## A8. What about cross-company-within-same-tenant?

Finding: refuted as proposed.

The resource families are not uniform:

- `payment_methods`: tenant + company (`apps/api/app/Modules/Treasury/Domain/PaymentMethod.php:21`-`44`; company made required at `apps/api/database/migrations/2025_11_30_134000_make_company_id_required.php:54`-`57`).
- `payment_repositories`: tenant + company (`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:23`-`46`; required at `apps/api/database/migrations/2025_11_30_134000_make_company_id_required.php:59`-`62`).
- `partners`: tenant + company (`apps/api/app/Modules/Partner/Domain/Partner.php:27`-`70`; required at `apps/api/database/migrations/2025_11_30_134000_make_company_id_required.php:24`-`27`).
- `products`: tenant + company (`apps/api/app/Modules/Product/Domain/Product.php:27`-`60`; required at `apps/api/database/migrations/2025_11_30_134000_make_company_id_required.php:29`-`32`).
- `documents`: tenant + company (`apps/api/app/Modules/Document/Domain/Document.php:36`-`90`; required at `apps/api/database/migrations/2025_11_30_134000_make_company_id_required.php:39`-`42`).
- `users`: tenant only, no `company_id` (`apps/api/app/Modules/Identity/Domain/User.php:21`-`44`, `74`-`90`; migration at `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:16`-`40`).
- `companies`: tenant only, no `company_id` (`apps/api/database/migrations/2025_11_30_104000_create_companies_table.php:23`-`27`).

Therefore a hard-coded company filter would reject or crash legitimate same-tenant user/company validation. The helper needs a tenant-only variant and some callsites need membership/permission checks beyond an `exists` rule.

## A9. What about resources that are not actually multi-tenant?

Finding: verified risk.

All seven families have `tenant_id`, but not all have `company_id`. `users` and `companies` lack `company_id` as shown above. The proposed helper would generate SQL against a nonexistent column on those two tables. That is a hard correctness bug, not just an architectural preference.

## A10. Is one commit per resource family right?

Finding: partial.

One commit per family is reviewable for `payment_methods` and `payment_repositories`, but it is too coarse for `users`, `documents`, `products`, and `partners` because those families span different modules and different parent contexts. For example, `GenerateZReportRequest` needs terminal-context payment method scoping (`apps/api/app/Modules/POS/Presentation/Requests/GenerateZReportRequest.php:41`-`52`), while `RefundPrepaymentRequest` is Treasury current-company context. A rollback of all `users` would conflate manager PIN validation, inventory counting assignees, and shift cashier lookups.

Recommended granularity: one commit per module/callsite cluster, with a small shared helper first. Example: POS payments/Z-report/manager user cluster; Inventory counting/batch cluster; Taxation withholding cluster; Treasury payment/deposit cluster; Loyalty company cluster. This keeps rollback atomic around user workflows.

## A11. Is one regression test per resource family sufficient?

Finding: refuted.

No. The same family appears in multiple behaviors. A `products` test in BatchExpiry would not cover POS receipt lines (`apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:31`-`49`) or Inventory counting filters (`apps/api/app/Modules/Inventory/Presentation/Requests/CreateCountingRequest.php:33`-`50`). The Option A test is strong because it tests all three actual IDs for that endpoint and asserts no write/fiscalization (`apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php:177`-`265`). That shape should be repeated per endpoint cluster, not per table.

Add a static regression test that fails on new bare rules, plus targeted feature tests for mutating endpoints. Static tests catch all callsites; feature tests prove the important workflows fail closed and leave no state.

## A12. Does the sweep need a CI gate?

Finding: verified.

Yes. Without a gate, future PRs can reintroduce `'exists:payment_methods,id'`. This repo uses PHPUnit (`apps/api/composer.json:41`-`43`, `71`-`74`) and has no Pest arch test surface under `apps/api/tests`. Add a PHPUnit static architecture test:

```php
<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TenantScopedExistsRulesTest extends TestCase
{
    /** @var list<string> */
    private const GUARDED_TABLES = [
        'payment_methods',
        'payment_repositories',
        'partners',
        'products',
        'documents',
        'users',
        'companies',
    ];

    public function test_presentation_code_does_not_use_bare_exists_for_tenant_resources(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules';
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (! str_contains($path, '/Presentation/')) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            foreach (self::GUARDED_TABLES as $table) {
                if (preg_match("/exists:{$table}\\b/", $contents) === 1) {
                    $violations[] = str_replace($root.'/', '', $path)." uses bare exists:{$table}";
                }
            }
        }

        self::assertSame([], $violations);
    }
}
```

This should live at `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`. It intentionally scans all `Presentation`, not only `Presentation/Requests`, because current code has inline controller validation.

## A13. Audit `find()` / `findOrFail()` patterns.

Finding: verified, and the proposed "hand-scope as encountered" is incomplete.

The required command returns 73 matches. Many are unscoped entry-point or user-input lookups. Representative gaps:

- `PartnerBalanceService::refreshPartnerBalance()` and `getCachedOrCalculateBalance()` accept `$companyId` but call `Partner::findOrFail($partnerId)` without checking company (`apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:288`-`318`, `357`-`367`).
- `DocumentConversionController` repeatedly loads route documents with bare `Document::findOrFail()` (`apps/api/app/Modules/Document/Presentation/Controllers/DocumentConversionController.php:23`-`28`, `51`-`62`, `88`-`93`, `109`-`111`, `136`-`138`, `168`-`188`).
- `RefundController` repeatedly loads route documents with bare `Document::findOrFail()` (`apps/api/app/Modules/Document/Presentation/Controllers/RefundController.php:24`-`36`, `80`-`93`, `150`-`198`).
- `DocumentPdfController` downloads/previews/generates paths from bare document IDs (`apps/api/app/Modules/Document/Presentation/Controllers/DocumentPdfController.php:22`-`50`).
- `MultiPaymentController` combines bare `exists` and bare `Document::findOrFail()` (`apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:28`-`39`, `112`-`120`).
- `PaymentController` uses bare document/repository lookups during payment allocation and GL handling (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:140`-`145`, `210`-`214`, `325`-`358`, `421`-`423`, `628`-`651`, `760`-`764`).
- `PaymentInstrumentController` validates a repository with bare `exists` and then bare `PaymentRepository::findOrFail()` (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php:145`-`152`).
- POS creation/order/sync code resolves partners/products/users/payment methods by bare ID (`apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:93`-`103`, `173`-`183`; `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:522`-`528`, `842`-`848`; `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:196`-`218`, `433`-`469`).
- Pricing and inventory services use bare products in contexts that have a company or user (`apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:61`-`68`; `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:365`-`378`; `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:355`-`368`; `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:463`-`475`).

Already contextual or lower-risk examples:

- `InvoicePostedListener` consumes a domain event invoice ID, not user input (`apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php:18`-`33`).
- `Nf525DataProvider` and verify-chain flows query by company/terminal IDs, not arbitrary resource IDs (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:72`-`113`, `268`-`365`).
- Several document conversion service lookups are based on product IDs already stored on same-document lines (`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:261`-`315`, `507`-`510`; `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php:253`-`256`, `388`-`390`, `549`-`550`). These still would be safer as relationship/tenant-checked lookups, but they are not the same threat as accepting a request ID.

I would not claim an exact final gap count without a code-changing audit because some matches are route-protected admin/role flows or event-local. But at least 30 of the 73 are plausible gaps and the number is too high for an unstructured hand sweep.

## A14. Should there be a service-layer helper analogous to ScopedExists?

Finding: partial.

A generic `TenantScopedFinder` is tempting, but it can hide context just like the proposed validation helper. The leaner option is a small dependency-free query helper for explicit builder constraints, plus local service methods where workflows need semantics.

Example shared helper:

```php
namespace App\Shared\Application\Database;

use Illuminate\Database\Eloquent\Builder;

final class ScopeQuery
{
    /** @template TModel of \Illuminate\Database\Eloquent\Model
     *  @param Builder<TModel> $query
     *  @return Builder<TModel>
     */
    public static function tenantAndCompany(Builder $query, string $tenantId, string $companyId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('company_id', $companyId);
    }
}
```

But for most Application services, explicit local code is better:

```php
$document = Document::query()
    ->where('tenant_id', $tenantId)
    ->where('company_id', $companyId)
    ->findOrFail($documentId);
```

Use a helper only to reduce repeated low-level builder syntax; do not make it resolve auth/company context.

## A15. Things the sweep must not touch.

Finding: partial.

Rule #4 says one task at a time and no scope creep (`CLAUDE.md:28`-`31`). The author's Claim 8 is mostly right: do not add global scopes, model traits, or broad authorization rewrites during a validation sweep.

There are legitimate exceptions where a controller's local query must change because the validation fix alone is insufficient. For example, `PaymentInstrumentController` validates and then bare-loads the repository (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php:145`-`152`), and `MultiPaymentController` bare-loads documents after validation (`apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:112`-`120`). Those are in scope because they are the same trust boundary. Route definitions, domain model boot methods, traits, and fiscal hash code should stay untouched unless a callsite proves they are the lookup boundary.

## A16. Fiscal-chain integrity.

Finding: verified risk area, not a reason for global scopes.

Global scopes should be rejected. Fiscal verification/export code intentionally walks terminal/company chains and should not be silently narrowed by current auth/company context. `Nf525DataProvider::buildExportSnapshot()` scopes exports by explicit company (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:72`-`113`), while `verifyReceiptChain()` and `verifyZReportChain()` walk an explicit terminal (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:268`-`365`). The CLI verification command also walks terminal chains explicitly (`apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:161`-`181`, `194`-`215`).

The proposed validation helper itself should not affect Fixture-01/Fixture-08 or v2 hash paths because it does not touch hash computation. `ReceiptFinalizationService` selects v2/v3 hash code from the terminal schema version (`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:68`-`78`). The risk is the service-layer sweep: `ReceiptSyncService` still does a live `PaymentMethod::findOrFail()` while writing synced payments (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:433`-`469`). If that lookup is tightened incorrectly, offline sync could fail; if it remains loose, a cross-tenant method could still affect the stored `payment_type`. This needs a dedicated POS sync tenant-isolation test, not a global scope.

# Counter-proposal (if any)

Use an explicit-scope validation helper under the Presentation layer, no auth/app/company resolution inside the helper, and add a static gate that forbids bare `exists:` for guarded tables.

Proposed helper:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class ScopedExists
{
    public static function tenantAndCompany(
        string $table,
        ?string $tenantId,
        ?string $companyId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);
    }

    public static function tenant(
        string $table,
        ?string $tenantId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('tenant_id', $tenantId);
    }

    public static function company(
        string $table,
        ?string $companyId,
        string $column = 'id',
    ): Exists {
        return Rule::exists($table, $column)
            ->where('company_id', $companyId);
    }
}
```

Usage examples:

```php
// Company-scoped product/partner/payment resources.
ScopedExists::tenantAndCompany('products', $this->user()?->tenant_id, $companyId)

// Tenant-scoped users and companies.
ScopedExists::tenant('users', $this->user()?->tenant_id)
ScopedExists::tenant('companies', $this->user()?->tenant_id)

// Nested POS payment uses the parent receipt/terminal, not auth header context.
ScopedExists::tenantAndCompany('payment_methods', $receipt?->tenant_id, $receipt?->company_id)
```

Sweep plan:

1. Add `App\Shared\Presentation\Validation\ScopedExists` with the three explicit methods above.
2. Add `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php` to fail on bare `exists:` in all `Presentation` code for the seven guarded tables.
3. Replace by module/callsite cluster, not by resource family:
   - POS receipt/payment/Z-report/manager PIN/sync.
   - Inventory counting/batch/stock flows.
   - Treasury payments/repositories/instruments.
   - Taxation withholding.
   - Document conversion/refund/PDF route document lookups.
   - Loyalty company membership/program flows.
   - Accounting ledger/partner balance.
4. For each cluster, fix validation and the immediate resolver/writer lookup in the same commit.
5. Add feature tests for mutating endpoints and one or more static/architecture tests for coverage.
6. Audit the 73 `find()/findOrFail()` matches as a separate checklist in the PR description. Do not touch fiscal hash builders, model global scopes, route definitions, or unrelated authorization policy unless the lookup is the trust boundary being fixed.

# Diff to the proposed helper

Not applicable because the verdict is `REQUIRES-DIFFERENT-APPROACH`. Replace the proposed helper rather than editing it in place. The substantive diff is: move namespace from `App\Shared\Validation` to `App\Shared\Presentation\Validation`; delete `forCurrentTenant()`; delete `auth()` and `app(CompanyContext::class)` usage; add `tenant()`, `company()`, and `tenantAndCompany()` explicit factories.

# Confidence gradient

- Helper placement: 4/5. The unanswered question that would change this is whether maintainers intentionally want a new top-level `Shared\Validation` layer despite the current Shared layout.
- API surface: 5/5. The schema proof for `users` and `companies` makes the proposed two-method API objectively insufficient.
- Sweep granularity: 4/5. This could change if the team wants a mechanically large PR, but module/callsite clusters are easier to review and roll back.
- Test strategy: 5/5. One test per family is not enough because the current grep shows multi-module callsites and inline controller validation.
- CI gate: 5/5. The repo has no existing guard, and the simplest PHPUnit architecture test will catch future bare `exists:` regressions.

# Out-of-scope items you noticed but did not investigate

- Existing DI violations in FormRequests (`app(CompanyContext::class)` appears in Service and Accounting/Catalog request files) are related but broader than Option B.
- `PaymentRepositoryController` scopes repository queries by tenant but not always by company (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:28`-`32`, `45`-`48`, `111`-`113`, `153`-`155`); this may be deliberate tenant-level treasury behavior or a separate company-isolation gap.
- Several non-target tables in current validators are also tenant/company-sensitive (`accounts`, `locations`, `payment_instruments`, `document_lines`, `pos_terminals`, `contacts`, `modifiers`, `modifier_groups`, `services`). They were outside the seven-family diagnostic but should not be assumed safe.
- `ReceiptSyncService` uses a live `PaymentMethod` lookup even though the sync contract says `method_code` is the hash-input snapshot. That may be harmless for `payment_type`, but it deserves a POS sync-specific tenant-isolation review.
