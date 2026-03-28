# Monetary Precision: Frontend & DB Column Fixes

**Date:** 2026-03-24
**Status:** Approved
**Depends on:** Backend `CurrencyScale::bcformat()` fix (already deployed to main)

## Problem

The backend IEEE 754 float conversion bug (5 TND displaying as 4.999) has been fixed. Three related issues remain:

1. **Frontend `lib/decimal.ts`** wraps `parseFloat()` + native JS arithmetic in bcmath-named functions, providing zero precision benefit. Every POS cart total, payment, and discount calculation is susceptible to IEEE 754 errors.
2. **~100+ hardcoded `.toFixed(2)` and `.toFixed(3)` calls** across ~45 frontend files assume 2-decimal currencies. TND uses 3 decimals — these truncate the millime.
3. **9 DB monetary columns** still use `decimal(X, 2)`, truncating TND's 3rd decimal place on write.

## Solution

### 1. Replace `lib/decimal.ts` internals with `big.js`

Install `big.js` (~6KB gzipped). Replace the implementation of all 7 exported functions without changing their signatures:

| Function | Implementation change |
|----------|----------------------|
| `bcadd(a, b, scale)` | `new Big(a).plus(b).toFixed(scale)` |
| `bcsub(a, b, scale)` | `new Big(a).minus(b).toFixed(scale)` |
| `bcmul(a, b, scale)` | `new Big(a).times(b).toFixed(scale)` |
| `bcdiv(a, b, scale)` | `new Big(a).div(b).toFixed(scale)` |
| `bccomp(a, b)` | `new Big(a).cmp(new Big(b))` |
| `calculateDiscountAmount` | No change (uses `bcdiv`/`bcmul` internally) |
| `applyDiscount` | No change (uses `bcsub` internally) |
| `formatCurrency(amount, ...)` | `new Big(amount).toFixed(decimals)` instead of `parseFloat(amount).toFixed(decimals)` |

Set `Big.RM = 1 // roundHalfUp` explicitly (matches PHP `round()` and PostgreSQL behavior). Note: `big.js` uses numeric constants for rounding modes, not named properties.

**Input sanitization:** Wrap `Big` construction with a guard for empty/falsy inputs: `new Big(value || '0')`. The current code silently converts empty strings to `NaN` via `parseFloat('')`; `new Big('')` throws. The guard makes the behavior safer than before.

Also fix `parseFloat()` in `lib/formatCurrency.ts` (lines 23, 61) which has the same float conversion issue in its fallback path.

**Zero consumer changes.** Every file that imports from `@/lib/decimal` works unchanged.

### 2. Replace hardcoded `.toFixed()` calls and `parseFloat` + `reduce` accumulation

Three categories of replacement:

**Category A — React components:** Add `const { decimals } = useCurrency()` (or destructure `toFixed` from it) and replace `.toFixed(2)` with `.toFixed(decimals)`. For components that also accumulate `parseFloat` values in `reduce()` loops, replace with `bcadd` from `lib/decimal.ts`.

**Category B — Non-component files** (e.g., `types/creditNote.ts`, `types/treasury.ts`): Accept `scale: number` as a parameter from the calling component, which passes `decimals` from `useCurrency()`. For files doing arithmetic (like `types/treasury.ts` `calculateManualAllocationsTotal`), use `bcadd`/`bcsub` from `lib/decimal.ts` instead of native `+`.

**Category C — Non-monetary values (SKIP):** Percentages, load averages, and margins stay at `.toFixed(2)` — percentages always use 2 decimals regardless of currency.

Files by module (monetary calls only):

| Module | Files | ~Calls |
|--------|-------|--------|
| POS (critical path) | POSPage (12+ calls on float results), PaymentPanel, AdvancedPaymentsModal, TransactionCart, CashTenderedModal, CheckoutSuccessDialog, ReturnItemsModal, ZReportDetailPage, POSTransactions, useDiscountPreview, ModifierSelectionModal | ~35 |
| Documents | CreateCreditNoteForm, CreditNoteList, CreditNoteDetail, CreateCreditNotePage, CreateReturnNotePage, ReturnNoteListPage, CreateReturnNoteForm, AdditionalCostsForm, LandedCostBreakdown, DocumentTotals | ~25 |
| Inventory | ProductForm, StockLevelsPage, ProductStockLevels, ProductPricingCard, PriceInputWithMargin, ProductDocumentsTab, BatchDetailPage, BatchListPage | ~25 |
| Treasury | PaymentForm, OpenInvoicesList, AllocationPreview, PaymentAllocationForm, ToleranceSettingsDisplay | ~8 |
| Withholding | WithholdingPreviewModal, WithholdingCertificatesList, WithholdingCertificateDetail, SalesWithholdingTrackingPage | ~10 |
| Types/Utils | types/creditNote.ts, types/treasury.ts, lib/formatCurrency.ts | ~6 |
| VAT Reporting | VatPeriodsPage | ~2 |
| Other | ExpenseCard, DeliveryNoteSearchSelect, RecordPaymentModal | ~3 |

**POS `parseFloat` + `reduce` loops (IN SCOPE):** The following accumulation patterns use raw `parseFloat` + `+` in `reduce()` and do NOT call `lib/decimal.ts`. They must be converted to use `bcadd`:

- `POSPage.tsx:86` — `cartItems.reduce((sum, item) => sum + parseFloat(item.line_total), 0)`
- `POSPage.tsx:175` — `selectedModifiers.reduce((sum, m) => sum + parseFloat(m.price_adjustment), 0)`
- `PaymentPanel.tsx:48-58` — subtotal, discount, tax accumulation
- `AdvancedPaymentsModal.tsx:163-173` — duplicate of PaymentPanel logic
- `TransactionCart.tsx:91` — subtotal calculation

Also add missing 3-decimal currencies to frontend `CURRENCY_DECIMALS` map in `useCurrency.ts`: BHD, IQD, JOD, KWD, OMR.

**Test mocks:** ~10 test files mock `useCurrency` with hardcoded `.toFixed(2)`. These mocks need updating to use currency-appropriate decimals.

### 3. DB migration — widen remaining monetary columns

Single migration: `2026_03_24_100000_widen_remaining_monetary_columns_to_scale_3.php`

| Table | Column | From | To |
|-------|--------|------|-----|
| `documents` | `line_tax_amount` | `decimal(15, 2)` | `decimal(15, 3)` |
| `loyalty_enrollments` | `current_balance` | `decimal(15, 2)` | `decimal(15, 3)` |
| `loyalty_enrollments` | `lifetime_earned` | `decimal(15, 2)` | `decimal(15, 3)` |
| `loyalty_enrollments` | `lifetime_redeemed` | `decimal(15, 2)` | `decimal(15, 3)` |
| `payment_methods` | `fee_fixed` | `decimal(10, 2)` | `decimal(10, 3)` |
| `subscription_plans` | `price_yearly` | `decimal(10, 2)` | `decimal(10, 3)` |
| `plans` | `price_monthly` | `decimal(10, 2)` | `decimal(10, 3)` |
| `tenant_subscriptions` | `price` | `decimal(10, 2)` | `decimal(10, 3)` |

Note: Verify actual table name for `payment_methods.fee_fixed` against treasury migration before implementation.

Not touched: All `decimal(5, 2)` percentage/rate columns (tax_rate, discount_percent, commission_rate, etc.). Percentages are always 2 decimal places.

Widening is additive: `10.50` becomes `10.500`. No data loss, no downtime, fully reversible.

## Testing

### `lib/decimal.ts`
- TDD: write failing tests first proving `bcadd('0.1', '0.2', 3)` returns `'0.300'`
- Test all 7 functions with IEEE 754 problematic values (0.1+0.2, 1.005*100, etc.)
- Test edge cases: division by zero, empty strings, negative values, large numbers
- Test input sanitization: empty string, undefined, null → treated as '0'

### `.toFixed()` replacements
- Run full frontend test suite (`pnpm test`) to catch regressions
- Update ~10 test files that mock `useCurrency` with hardcoded `.toFixed(2)` to use currency-appropriate decimals
- Existing component tests already assert rendered amounts

### DB migration
- Run `php artisan migrate` and verify column definitions
- No data transformation logic to test

## Out of Scope

- `Billing/ValueObjects/Money.php` float refactor (platform billing, separate concern)
- Remainder allocation pattern (Fowler's "allocate") — future enhancement
- Removing `ITL` from currency maps — cosmetic, separate PR

## Execution Order

1. DB migration (independent, deploy immediately)
2. `lib/decimal.ts` big.js replacement (TDD, isolated change)
3. `useCurrency.ts` — add missing currencies
4. `.toFixed()` fixes and `reduce` loop fixes by module (parallelizable via agents, each module independent)
5. Test mock updates
6. Full test suite verification
