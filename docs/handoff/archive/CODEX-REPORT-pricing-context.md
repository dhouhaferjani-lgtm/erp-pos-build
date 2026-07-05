# Pricing Context Phase 2 Report

Branch: `feat/line-entry-pricing-context`  
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.pricingctx`  
Spec: `docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md` Revision 2, Phase 2

## Implemented

- Added `POST /api/v1/line-entry/pricing-context/bulk` under `LineEntryController`.
- Response is keyed by `product_id` or `product_id:variant_id`.
- Reads `products.cost_price` as current WAC and `products.last_purchase_cost` as last purchase cost.
- Looks up latest document sale to the requested `partner_id` through `document_lines` joined to `documents`.
- Uses `MarginService::getEffectiveMargins`, `getMarginLevel`, `getSuggestedPrice`, and `canSellAtPrice` for server-owned policy.
- Enforces tenant/company scoped product and partner validation.
- Added lazy pricing intel to `DocumentLineEditor` unit-price cells:
  - one tenant-scoped bulk query after a price field receives focus;
  - compact under-field hint;
  - details popover;
  - server-driven blocked/warning policy text;
  - `Use suggested` action;
  - optional `partnerId` passed from `DocumentForm` and manual credit-note entry.
- Preserved purchase bonus quantity controls and their existing tests.
- Added `t()` strings in `en`, `fr`, and `ar`.

## Red Output

Backend red:

```text
FAIL  Tests\Feature\Product\LineEntryPricingContextTest
⨯ bulk pricing context returns cost history partner sale and policy
⨯ bulk pricing context rejects foreign company products
Expected response status code [200] but received 404.
Expected response status code [422] but received 404.
Tests: 2 failed (2 assertions)
```

Frontend red:

```text
src/features/documents/components/__tests__/DocumentLineEditor.test.tsx (18 tests | 2 failed)
✓ 16 existing tests passed
× lazily fetches bulk pricing context on unit-price focus and renders the hint
  expected "spy" to be called with arguments: ["/line-entry/pricing-context/bulk", ...]
× shows server-driven blocked margin policy and applies suggested price
  Unable to find text: Margin blocked: pricing.sell_below_minimum_margin
```

## Green / Gates

```text
php artisan test tests/Feature/Product/LineEntryPricingContextTest.php
PASS  Tests\Feature\Product\LineEntryPricingContextTest
Tests: 2 passed (15 assertions)
```

```text
pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/documents/components/__tests__/DocumentLineEditor.quantityStep.test.tsx
Test Files: 2 passed (2)
Tests: 21 passed (21)
```

```text
pnpm --filter @autoerp/web typecheck
tsc --noEmit
exit 0
```

```text
pnpm --filter @autoerp/web audit:keys
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
```

```text
bash -lc 'set -o pipefail; ./vendor/bin/phpstan analyse app/Modules/Product/Presentation/Controllers/LineEntryController.php app/Modules/Product/routes.php --debug --no-progress --error-format=table 2>&1 | tee /tmp/phpstan-pricing-context.log; exit ${PIPESTATUS[0]}'
[OK] No errors
```

```text
git diff --check
exit 0
```

Note: the first PHPStan run without `--debug` failed before analysis because the sandbox disallowed PHPStan's worker TCP server (`Failed to listen on "tcp://127.0.0.1:0": EPERM`). The rerun used `--debug --no-progress` for single-process analysis and preserved the piped exit code with `pipefail`.

## Files Changed

- `apps/api/app/Modules/Product/Presentation/Controllers/LineEntryController.php`
- `apps/api/app/Modules/Product/routes.php`
- `apps/api/tests/Feature/Product/LineEntryPricingContextTest.php`
- `apps/web/src/features/documents/components/DocumentLineEditor.tsx`
- `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx`
- `apps/web/src/features/documents/DocumentForm.tsx`
- `apps/web/src/features/documents/CreateCreditNotePage.tsx`
- `apps/web/src/locales/en/sales.json`
- `apps/web/src/locales/fr/sales.json`
- `apps/web/src/locales/ar/sales.json`
- `docs/superpowers/plans/2026-07-02-pricing-context-phase-2.md`

No git commit created.
