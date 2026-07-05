# Bonus Quantities FE Tail Report

Date: 2026-07-02
Branch: `feat/bonus-qty-fe`
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.bonusfe`

## Completed

- PO line editor bonus UI gated by `hasModule('PurchaseBonus')` and `companyConfig.purchase_bonus_enabled`.
- `free_quantity` editing with `QuantityInput`, unit-price/total-paid `price_entry_mode` toggle, effective unit cost, and bonus savings display.
- Document autosave/submit carries `free_quantity`, `line_total`, and `price_entry_mode` as string-safe values.
- Goods receipt dialog shows paid/free split, accepts free-only receipt rows, and submits `free_quantities`.
- PO PDF print renders a French gratuité sub-row with physical expected quantity.
- Generated shared TypeScript definitions via `CACHE_STORE=array php artisan typescript:transform`.
- Added `tenant:reconcile-modules` for existing tenant module-cache refresh after vertical default changes.
- Supplier credit-note bonus return path:
  - decrements `free_quantity_invoiced`, not paid `quantity_invoiced`;
  - issues stock at current diluted WAC using string bcmath;
  - posts no supplier-payable leg for pure bonus returns;
  - debits purchase expenses and credits inventory for the returned bonus stock value.

## Red Outputs

Frontend red:

```text
pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/purchases/components/ReceiveGoodsDialog.test.tsx

FAIL src/features/purchases/components/ReceiveGoodsDialog.test.tsx
FAIL src/features/documents/components/__tests__/DocumentLineEditor.test.tsx
Test Files  2 failed (2)
Tests  4 failed | 14 passed (18)
```

PDF/reconcile red:

```text
php artisan test tests/Feature/Modules/Document/DocumentPdfRenderTest.php tests/Feature/Tenant/ReconcileModulesCommandTest.php

FAILED Tests\Feature\Modules\Document\DocumentPdfRenderTest
missing: dont gratuité : +1.00 unité gratuite

FAILED Tests\Feature\Tenant\ReconcileModulesCommandTest
CommandNotFoundException: The command "tenant:reconcile-modules" does not exist.
```

Supplier credit-note bonus-return red:

```text
php artisan test tests/Feature/Accounting/SupplierCreditNoteGlTest.php --filter=bonus_goods_return

FAILED Tests\Feature\Accounting\SupplierCreditNoteGlTest > bonus goods return...
Failed asserting that two strings are identical.
-'20.0000'
+'19.0000'
at tests/Feature/Accounting/SupplierCreditNoteGlTest.php:479
```

## Green Outputs

Frontend focused tests:

```text
pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/purchases/components/ReceiveGoodsDialog.test.tsx

Test Files  2 passed (2)
Tests  18 passed (18)
```

Backend focused tests:

```text
php artisan test tests/Feature/Modules/Document/DocumentPdfRenderTest.php tests/Feature/Tenant/ReconcileModulesCommandTest.php tests/Feature/Accounting/SupplierCreditNoteGlTest.php

PASS Tests\Feature\Modules\Document\DocumentPdfRenderTest
PASS Tests\Feature\Tenant\ReconcileModulesCommandTest
PASS Tests\Feature\Accounting\SupplierCreditNoteGlTest
Tests: 26 passed (142 assertions)
```

Type generation:

```text
CACHE_STORE=array php artisan typescript:transform
Transformed 362 PHP types to TypeScript
```

Additional verification:

```text
pnpm --filter @autoerp/web typecheck
tsc --noEmit

./vendor/bin/phpstan analyse app/Modules/Procurement/Application/SupplierCreditNotePostingService.php app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Tenant/Application/Commands/ReconcileModulesCommand.php tests/Feature/Accounting/SupplierCreditNoteGlTest.php tests/Feature/Modules/Document/DocumentPdfRenderTest.php tests/Feature/Tenant/ReconcileModulesCommandTest.php
[OK] No errors

pnpm --filter @autoerp/web audit:keys
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries

git diff --check
no output
```

ESLint was also run through `pnpm --filter @autoerp/web lint:eslint -- ...`; the package script expanded to full-project `eslint .` and exited 0 with warnings only. The warnings are existing broad lint debt, not blocking errors.

## Notes

- No commit was made.
- Full `pnpm build`, full `pnpm test`, full `composer test`, and E2E were not run.
- `PurchaseExpenses` is used as the current debit account for returned bonus stock value; accountant sign-off may still choose a more specific purchase variance/bonus-return account later.
