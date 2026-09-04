## Verdict

**REJECT.** Task 13 is dispatch-ready. Task 12 has one remaining plan-text defect: its actual Step 2 test uses a substring assertion, contradicting revision 10’s required exact regex.

| Task | Dispatch-ready? | Result |
|---|---:|---|
| Task 12 | No | RED proof and implementation instructions are present, but the test code does not use `/^0\.000$/` as claimed. |
| Task 13 | Yes | Migration bootstrap, dual-collision recovery, imports, and self-contained PG command are executable. |

## r8 disposition audit

| Finding | Resolved? | Evidence |
|---|---:|---|
| Task 12 assertion exists and is RED against current float behavior | Yes | Assertion exists at [plan:2577](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2577). Current code parses and adds floats at [SplitPaymentForm.tsx:101](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:101), then subtracts at [SplitPaymentForm.tsx:106](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:106). `0.300-(0.100+0.200)` is `-5.551115123125783e-17`; with Step 2’s `String(value)` mock at [plan:2472](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2472), that residue is rendered verbatim. The current remaining element at [SplitPaymentForm.tsx:191](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:191) also lacks the test id. |
| Task 12 Step 4 prescribes test id and string formatting | Yes | Step 4 requires `data-testid="split-payment-remaining"` and `{formatCurrency(remaining)}` at [plan:2698](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2698); string-only arithmetic is prescribed at [plan:2643](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2643)–[2695](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2695). |
| Task 12 Step 2 uses the claimed exact regex | **No** | Actual code is `toHaveTextContent('0.000')` at [plan:2577](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2577), while Step 4 and the r9 disposition claim `/^0\.000$/` at [plan:2698](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2698) and [plan:3819](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3819). |
| Task 12 canonical hook path | Yes | Planned import is `@/hooks/useIdempotencyKey` at [plan:2594](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2594); implementation exists at [useIdempotencyKey.ts:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/useIdempotencyKey.ts:10). |
| Task 12 named consumers exist | Yes | `PaymentForm` is routed at [routes/index.tsx:1844](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1844) and posts at [PaymentForm.tsx:679](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/PaymentForm.tsx:679). Split payment posts at [SplitPaymentForm.tsx:89](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/treasury/SplitPaymentForm.tsx:89); deprecated wrapper passes through at [SplitPaymentModal.tsx:120](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:120). `RecordPaymentModal` posts at [RecordPaymentModal.tsx:324](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:324) and is hosted at [InvoiceDetailPage.tsx:895](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:895), [SalesOrderDetailPage.tsx:780](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:780), and [PurchaseOrderDetailPage.tsx:709](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:709). |
| Payment backend accepts named header/body key | Yes | There is controller-level handling rather than generic middleware. `PaymentController` prefers `Idempotency-Key`, then body `idempotency_key`, at [PaymentController.php:128](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:128)–[150](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:150), applies the replay lookup at [PaymentController.php:358](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:358), and persists it at [PaymentController.php:961](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:961). Split payments mirror this at [MultiPaymentController.php:52](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:52)–[75](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:75) and [MultiPaymentController.php:129](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:129). Routes exist at [routes.php:189](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/routes.php:189) and [routes.php:220](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Presentation/routes.php:220). |
| `TenantStanclFlipTest` exists and supplies the source pattern | Yes | Class exists at [TenantStanclFlipTest.php:30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Tenant/TenantStanclFlipTest.php:30). It has inline schema-check/migration logic—not a helper—at [TenantStanclFlipTest.php:47](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Tenant/TenantStanclFlipTest.php:47)–[51](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Tenant/TenantStanclFlipTest.php:51). Revision 10 now says “extracted” at [plan:2925](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2925)–[2930](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2930). |
| Task 13 helper is defined with required imports | Yes | `Artisan` and `Schema` imports are present at [plan:2900](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2900) and [plan:2902](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2902); helper body is complete at [plan:2998](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2998)–[3003](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3003). |
| Empty PG database receives central and tenant schemas | Yes | `phpunit-pgsql.xml` forces `APP_ENV=testing` and `DB_CONNECTION=pgsql` at [phpunit-pgsql.xml:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/phpunit-pgsql.xml:50) and [phpunit-pgsql.xml:57](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/phpunit-pgsql.xml:57). The exact command sets default and central database names equal. The central connection consumes `DB_CENTRAL_DATABASE` at [database.php:124](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/database.php:124)–[133](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/database.php:133). During testing, `AppServiceProvider::boot()` registers tenant migrations at [AppServiceProvider.php:155](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:155)–[157](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:157) and [AppServiceProvider.php:239](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:239)–[245](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:245). Therefore `migrate --force` runs root and tenant migration paths on the same empty database. |
| Task 13 catches any unique violation with a key and rereads by key | Yes | Catch contains no constraint-name check and rereads by tenant/company/key at [plan:2827](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2827)–[2845](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2845); null key or missing replay row rethrows the original exception. |
| Task 13 forces the real dual collision | Yes | Competing writer inserts the exact `$transferNumber` and same key at [plan:3138](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3138)–[3161](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3161). Real constraints are tenant/company/number and tenant/company/key at [migration:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:66)–[67](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:67). |
| Task 13 exact PG command | Yes | It includes `cd apps/api &&`, both database variables, config, and test path at [plan:3342](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3342). The preceding clause explicitly creates the reserved database if missing. |

## Blocking findings

### B1 — Task 12’s actual test does not enforce exact `"0.000"`

**Falsifying scenario:** `toHaveTextContent('0.000')` performs substring matching; the installed matcher uses `textToMatch.includes(...)` for string expectations at [matchers-98b869c1.js:156](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/node_modules/@testing-library/jest-dom/dist/matchers-98b869c1.js:156). Therefore `-0.000`, `10.000`, or `0.000 TND` can satisfy the assertion despite not being exactly `"0.000"`.

**Exact plan edit:** replace [plan:2577](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2577) with:

```tsx
expect(screen.getByTestId('split-payment-remaining')).toHaveTextContent(/^0\.000$/)
```

No other Task 12 edit is required by this gate.

## Non-blocking findings

- Primary Task 12/13 anchors now match, including `SplitPaymentForm.tsx:42`, modal lines 42/73/122, all three RecordPaymentModal hosts, and replenishment’s real `initiate()` call at [ReplenishmentFulfillmentService.php:102](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:102).

- The out-of-lane supplier writer anchor `supplier-invoices/api.ts:478` points to its JSDoc; the actual `/payments` call is at [api.ts:487](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/purchases/supplier-invoices/api.ts:487). Its owning submit handler is correctly anchored at [SupplierInvoiceDetailPage.tsx:302](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:302).

- Consumer census found the intentionally excluded adjustment consumer at [QuickStockAdjustmentModal.tsx:124](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/stock-adjustments/components/QuickStockAdjustmentModal.tsx:124), plus direct E2E writers at [w2b-support.ts:381](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w2b-support.ts:381) and [w4-support.ts:612](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/e2e/money-campaign/w4-support.ts:612). No matching POS writer was found. These exclusions are recorded in the plan.

- Planned `app()` calls are confined to tests; no production step requires service-location. No TypeScript `any` or PHP `mixed` was found in Tasks 12–13.

- The Task 12 `@ts-expect-error` test at [plan:2484](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:2484) asserts code shape. Task 13’s seam-call counters at [plan:3058](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3058) and [plan:3097](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3097) are implementation-coupled, but accompanied by real database outcome and transaction-level assertions.

- The historical Gate r8 disposition still says the helper was “copied” at [plan:3810](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3810); revision 10’s operative Task 13 text correctly says it was extracted from inline logic.

## Verification performed

- `node -e …` float probe:
  - `currentTotal`: `0.30000000000000004`
  - `remaining`: `-5.551115123125783e-17`
  - tolerance result: accepted
  - planned `String(value)` rendering: `"-5.551115123125783e-17"`

- Targeted `rg` census:
  - Found the four production payment writers: PaymentForm, SplitPaymentForm, RecordPaymentModal, and the recorded out-of-lane supplier writer.
  - Found CreateStockTransferPage, CreateStockAdjustmentPage, excluded QuickStockAdjustmentModal, two E2E helpers, and no POS writer.
  - Found no anchored regex in Step 2; only the unanchored string assertion.

- `pnpm --dir apps/web typecheck`
  - Exit 0; `tsc --noEmit` passed on the current tree.

- `php -l` on current `StockTransferService.php` and `TenantStanclFlipTest.php`
  - Both reported no syntax errors.

- `cd apps/api && php artisan --version`
  - Exit 0; `Laravel Framework 12.58.0`, confirming the corrected command prefix.

- The planned PG collision class was not executed because it exists only in plan text and running its migration/fixture bootstrap would mutate the reserved database, contrary to this read-only gate.

- No files were changed. `git status --short` showed two pre-existing untracked documentation files.