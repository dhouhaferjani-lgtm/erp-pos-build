## Verdict

**REJECT.** Both r8 blockers remain incompletely resolved.

| Task | Dispatch-ready? | Reason |
|---|---:|---|
| Task 12 | No | The new decimal assertion is RED, but Step 4 never instructs the implementer to add `data-testid="split-payment-remaining"`. |
| Task 13 | No | The proposed test calls an undefined migration helper, and its exact PG command fails from the repository root. |

## r8 disposition audit

| Finding | Resolved? | Evidence |
|---|---:|---|
| Task 12 positive decimal test is not RED | **Partially** | The assertion is present at [plan:2557](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2557) and [plan:2577](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2577). Current code sums floats at [SplitPaymentForm.tsx:101](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:101), subtracts them at [SplitPaymentForm.tsx:106](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:106), and renders through the formatter at [SplitPaymentForm.tsx:191](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:191). With rev 9’s `String(value)` mock, `0.300 - (0.100 + 0.200)` renders `-5.551115123125783e-17`, not `0.000`; moreover, the current element has no test id. Thus the test is genuinely RED. |
| Task 12 implementation prescribes test id + string formatter | **No** | The string formatter is prescribed at [plan:2698](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2698), but the implementation step [plan:2640](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2640)–[2698](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2698) never adds `data-testid`. Only a test comment requests it. |
| Task 12 canonical hook path | **Yes** | Plan import is `@/hooks/useIdempotencyKey` at [plan:2594](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2594); implementation exists at [useIdempotencyKey.ts:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/useIdempotencyKey.ts:10). No obsolete path occurs in Tasks 12–13. |
| Task 12 named consumers and backend acceptance | **Yes** | `PaymentForm` is routed at [routes/index.tsx:1844](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1844); SplitPaymentForm posts at [SplitPaymentForm.tsx:89](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:89); its wrapper delegates at [SplitPaymentModal.tsx:120](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:120); RecordPaymentModal posts at [RecordPaymentModal.tsx:324](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:324) and is hosted at [InvoiceDetailPage.tsx:895](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:895), [SalesOrderDetailPage.tsx:780](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:780), and [PurchaseOrderDetailPage.tsx:709](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:709). Routes are [Treasury routes:189](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/routes.php:189) and [Treasury routes:220](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/routes.php:220). Both controllers prefer `Idempotency-Key` and accept body `idempotency_key`: [PaymentController.php:128](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:128), [MultiPaymentController.php:52](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:52). |
| Task 13 copied migration helper | **No** | `TenantStanclFlipTest` exists at [TenantStanclFlipTest.php:30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Tenant/TenantStanclFlipTest.php:30), but has no `ensureCentralSchemaMigrated()` helper. It contains only inline migration logic at [TenantStanclFlipTest.php:47](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Tenant/TenantStanclFlipTest.php:47). Rev 9 calls an undefined method at [plan:2928](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2928), without importing `Artisan` or `Schema`. |
| Task 13 empty-DB bootstrap migrates both schemas | **No** | No helper body exists in the plan. The intended `migrate --force` approach would load tenant migrations in testing through [AppServiceProvider.php:227](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:227)–[245](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:245), but the proposed class cannot invoke nonexistent code. |
| Task 13 catches any unique violation and rereads by key | **Yes** | The outer catch contains no constraint-name filtering and rereads by tenant/company/key at [plan:2827](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2827)–[2845](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2845). The no-row case rethrows the original exception. |
| Task 13 real dual collision | **Yes** | Both DTOs omit `transferNumber` at [plan:3024](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3024) and [plan:3057](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3057). The competing writer uses the exact received `$transferNumber` and same key at [plan:3123](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3123)–[3146](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3146). The real unique constraints cover tenant/company/number and tenant/company/key at [migration:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:66). |
| Task 13 exact PG command is self-contained | **No** | The command at [plan:3327](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3327) lacks `cd apps/api &&`; executed from the plan’s repository-root context, it returns `Could not open input file: artisan`. It would also stop in `setUp()` at the undefined helper. |

## Blocking findings

### 1. Task 12 — implementation omits the test selector

**Falsifying scenario:** Follow Step 4 literally. The bcmath/string behavior is implemented correctly, but no test id is added. `getByTestId('split-payment-remaining')` still fails, leaving no executable green step.

**Exact plan edit:** Append to Step 4:

```tsx
Add data-testid="split-payment-remaining" to the existing remaining-amount <div>
and keep its child exactly {formatCurrency(remaining)}.
```

Prefer an exact assertion such as:

```tsx
expect(screen.getByTestId('split-payment-remaining')).toHaveTextContent(/^0\.000$/)
```

### 2. Task 13 — nonexistent migration helper

**Falsifying scenario:** Copy the planned test class into the repository and run it against empty `autoerp_test_t13`. `setUp()` invokes an undefined method before `Tenant::create()`; no schema is migrated.

**Exact plan edit:** Add `Artisan` and `Schema` imports and include the full helper in the proposed class:

```php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

private function ensureCentralSchemaMigrated(): void
{
    if (! Schema::connection('central')->hasTable('tenants')) {
        Artisan::call('migrate', ['--force' => true]);
    }
}
```

Also change “copied from `TenantStanclFlipTest`” to “extracted from its inline bootstrap”; that source has no helper.

### 3. Task 13 — PG command lacks its working directory

**Falsifying scenario:** Execute the exact Step 6 command from repository root. PHP cannot locate `artisan`.

**Exact plan edit:**

```bash
cd apps/api && DB_DATABASE=autoerp_test_t13 DB_CENTRAL_DATABASE=autoerp_test_t13 \
php artisan test -c phpunit-pgsql.xml \
tests/Feature/Inventory/StockTransferIdempotencyCollisionPostgresTest.php
```

## Non-blocking findings

- Stale anchor: `SplitPaymentForm.tsx:39` in [plan:2416](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2416); `totalAmount` is currently at [SplitPaymentForm.tsx:42](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:42). Other named Task 12 anchors match.
- “All three active payment surfaces” is incomplete. `SupplierInvoiceDetailPage` is another active `/payments` writer at [SupplierInvoiceDetailPage.tsx:302](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:302), through [supplier-invoices/api.ts:478](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/purchases/supplier-invoices/api.ts:478); it has neither a key nor synchronous ref lock.
- `QuickStockAdjustmentModal` is another active `/stock-adjustments` writer at [QuickStockAdjustmentModal.tsx:124](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/components/QuickStockAdjustmentModal.tsx:124) and [QuickStockAdjustmentModal.tsx:187](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/components/QuickStockAdjustmentModal.tsx:187). The plan explicitly leaves it untouched.
- E2E helpers also post without keys: [w2b-support.ts:381](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w2b-support.ts:381) and [w4-support.ts:612](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w4-support.ts:612). No matching POS writer was found for these endpoints.
- The `@ts-expect-error` prop test at [plan:2484](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2484) asserts a type/code-shape contract. Task 13’s seam-call counters at [plan:3043](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3043) are also implementation-coupled, although backed by real database outcome assertions.
- Planned `app()` calls occur only in tests. No production `app()` requirement, TypeScript `any`, or PHP `mixed` type was found in Tasks 12–13.

## Verification performed

- `node -e …` decimal probe:
  - total: `0.30000000000000004`
  - remaining: `-5.551115123125783e-17`
  - rev 9 mock rendering: `"-5.551115123125783e-17"`
- `rg -n "function ensureCentralSchemaMigrated|ensureCentralSchemaMigrated" …`
  - Found only the plan’s call and prose; no function definition.
- `php artisan --version` from repository root:
  - Exit `1`: `Could not open input file: artisan`
- `pnpm --dir apps/web typecheck`
  - PASS on the current tree. This is baseline evidence only; planned tests are not on disk.
- `php -l` on current `StockTransferService.php` and `TenantStanclFlipTest.php`
  - Both report no syntax errors.
- Consumer census used `rg` across `apps/web/src`, `apps/pos`, and `apps/web/e2e`.
- PG collision test was not run: the class exists only in plan text, and running its intended bootstrap would mutate the reserved database, contrary to this read-only gate.
- `git status --short` remained unchanged, showing only two pre-existing untracked documentation files.