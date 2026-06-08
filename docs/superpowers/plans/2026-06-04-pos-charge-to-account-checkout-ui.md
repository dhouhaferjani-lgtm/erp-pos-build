# POS Charge-To-Account Checkout UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the already-built `ACCOUNT_CHARGE` feature reachable from the POS checkout: a "Charge to Account" mode inside `AdvancedPaymentsModal`, a credit-decision confirmation with manager-PIN override, a `processAccountCharge` store action, an ESC/POS charge receipt, and a tax-inclusive `unit_price` fixture correction.

**Architecture:** All fiscal plumbing (payload contract, parser, validator, `authorAccountCharge`, projections, Treasury/Document bridges) is merged to `dev`. This plan adds only the device-side UI/store wiring that calls `authorAccountCharge(db, input)` and renders/prints the result, plus a correctness fix to existing fixtures. The charge is whole-cart and non-collected (no payment lines; `amount_charged_to_account === total`), so it is mutually exclusive with tenders. Offline-first sync is preserved by `authorAccountCharge` itself (`incrementPendingCount` + `triggerSync`); the new store action must not bypass or duplicate it.

**Tech Stack:** Tauri POS, TypeScript (strict), React 19, Zustand, Vitest, react-i18next, `@tauri-apps/plugin-sql` (SQLite); Laravel/PHPUnit only for the fixture-correction task.

**Spec:** `docs/superpowers/specs/2026-06-04-pos-charge-to-account-checkout-ui-spec.md`

**Working directory:** worktree `/Users/houssamr/Projects/syneriva/apps/erp.charge-checkout-ui` (branch `feat/pos-charge-to-account-checkout-ui` off `dev`). All `apps/pos` commands run from `apps/pos`.

---

## File Structure

New files:

- `apps/pos/src/lib/accountCharge/accountChargeCartMapper.ts` — pure: `CartItem[]` + totals → `AccountChargeLineInput[]` + `AccountChargeVatBreakdownInput[]` + transaction-discount, using the tax-inclusive formulas.
- `apps/pos/src/lib/accountCharge/__tests__/accountChargeCartMapper.test.ts`
- `apps/pos/src/components/customers/AccountChargeConfirmation.tsx` — credit-decision UI + manager-PIN override.
- `apps/pos/src/components/customers/__tests__/AccountChargeConfirmation.test.tsx`

Modified files:

- `apps/pos/src/lib/buildReceiptData.ts` — add `buildEscPosAccountChargeReceiptData`.
- `apps/pos/src/lib/buildReceiptData.test.ts` (or existing test file) — cover the new builder.
- `apps/pos/src/stores/paymentStore.ts` — add `processAccountCharge` action + `createAccountChargeLocalFirst` helper.
- `apps/pos/src/stores/__tests__/paymentStore.*.test.ts` — cover `processAccountCharge`.
- `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx` — "On Account" tile + account-charge mode + `onChargeToAccount` prop.
- `apps/pos/src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx`
- `apps/pos/src/pages/HomePage.tsx` — `onChargeToAccount` handler + success-modal wiring.
- `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json` — new keys.

Fixture correction (Task 8): `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts` (golden payload + golden bytes) and the PHP account-charge fixtures/golden bytes enumerated in Task 8.

---

## Non-Negotiable Gates

POS tasks (1–7):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.charge-checkout-ui/apps/pos
pnpm test
pnpm typecheck
pnpm lint
```

Fixture-correction task (8) also runs:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.charge-checkout-ui/apps/api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/ tests/Feature/Fiscal/
```

Final task (9) also runs the chokepoint sentinel:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.charge-checkout-ui
bash apps/api/scripts/check-accountCharge-chokepoints.sh
```

Stage explicit files only. Never `git add -A`.

---

## Task 1: Shared cart→line mapper (tax-inclusive)

**Files:**
- Create: `apps/pos/src/lib/accountCharge/accountChargeCartMapper.ts`
- Test: `apps/pos/src/lib/accountCharge/__tests__/accountChargeCartMapper.test.ts`

Context: `AccountChargeLineInput` / `AccountChargeVatBreakdownInput` are defined in `apps/pos/src/lib/accountCharge/accountChargeService.ts`. The SALE_RECEIPT mapper (`buildSaleReceiptPayload` in `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:155-181`) writes `unit_price = item.unit_price` (gross/inclusive verbatim), `line_subtotal = line_total − tax_amount` (net), `line_vat = tax_amount`. `CartItem` carries `id`, `unit_price`, `line_total`, `tax_amount`, `tax_rate`, `discount_amount?`, `discount_reason?`, `quantity`, `product.{id,name,sku}`. `computeLineTotals(cartItems)` returns `{ subtotal, taxAmount }` and is already exported/used in `lib/offline/receiptService.ts`. Decimal helpers: `bcformat`, `bcsub`, `bcadd`, `bcmul`, `bcdiv`, `bccomp` from `@/lib/decimal`. `getCurrencyDecimals` from `@/lib/currency`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest';
import type { CartItem } from '@/stores/cartStore';
import { buildSaleReceiptPayload } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { buildAccountChargeCart } from '../accountChargeCartMapper';

function cart(): CartItem[] {
  return [
    {
      id: 'line-1',
      product: { id: 'prod-1', name: 'Widget', sku: 'SKU-1' },
      quantity: 1,
      unit_price: '119.000',   // gross / tax-inclusive
      tax_rate: '19.00',
      line_total: '119.000',
      tax_amount: '19.000',
    } as unknown as CartItem,
  ];
}

describe('buildAccountChargeCart', () => {
  it('authors gross/inclusive unit_price and net line_subtotal', () => {
    const { lines } = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: null });
    expect(lines).toHaveLength(1);
    expect(lines[0].unitPrice).toBe('119.000');     // gross verbatim
    expect(lines[0].lineSubtotal).toBe('100.000');  // net
    expect(lines[0].lineVat).toBe('19.000');
    expect(lines[0].quantity).toBe('1.000');
    expect(lines[0].vatRate).toBe('19.00');
    expect(lines[0].productId).toBe('prod-1');
  });

  it('matches the SALE_RECEIPT line mapping for the same cart (no inclusive drift)', () => {
    const { lines } = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: null });
    const sale = buildSaleReceiptPayload({
      receiptId: '00000000-0000-4000-8000-000000000001',
      terminalId: 't', operatorId: 'o', operatorName: 'O', shiftId: 's',
      currency: 'TND', eventTimeDevice: new Date('2026-06-04T00:00:00.000Z'), businessDate: '2026-06-04',
      cartItems: cart(), subtotalGross: '119.000', taxAmount: '19.000', total: '119.000',
      transactionDiscountAmount: '0', transactionDiscountReason: null, payments: [],
      consumptionMode: null, tableId: null, isTraining: false,
      seller: { name: 'S', taxNumber: '1234567AM000', countryCode: 'TN', street: '1 rue', city: 'Tunis', postalCode: '1000' },
      approvalReferences: [],
    });
    const s = sale.line_items[0];
    const a = lines[0];
    expect([a.unitPrice, a.lineSubtotal, a.lineVat, a.vatRate, a.quantity])
      .toEqual([s.unit_price, s.line_subtotal, s.line_vat, s.vat_rate, s.quantity]);
  });

  it('builds vat breakdown grouped by (rate, taxCategoryCode)', () => {
    const { vatBreakdown } = buildAccountChargeCart({ cartItems: cart(), currency: 'TND', transactionDiscount: null });
    expect(vatBreakdown).toEqual([
      { rate: '19.00', taxCategoryCode: '', netAmount: '100.000', vatAmount: '19.000', grossAmount: '119.000' },
    ]);
  });

  it('requires a line discount reason when a line discount is present', () => {
    const items = cart();
    (items[0] as unknown as { discount_amount: string }).discount_amount = '5.000';
    expect(() => buildAccountChargeCart({ cartItems: items, currency: 'TND', transactionDiscount: null }))
      .toThrow(/discount reason/i);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- accountChargeCartMapper.test.ts`
Expected: FAIL — `buildAccountChargeCart` is not defined.

- [ ] **Step 3: Write minimal implementation**

```ts
import type { CartItem, TransactionDiscount } from '@/stores/cartStore';
import { computeLineTotals } from '@/stores/cartStore';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcdiv, bcformat, bcmul, bcsub } from '@/lib/decimal';
import type {
  AccountChargeLineInput,
  AccountChargeVatBreakdownInput,
} from './accountChargeService';

export class AccountChargeCartError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'AccountChargeCartError';
  }
}

export interface BuildAccountChargeCartInput {
  cartItems: CartItem[];
  currency: string;
  transactionDiscount: TransactionDiscount | null;
}

export interface BuildAccountChargeCartResult {
  lines: AccountChargeLineInput[];
  vatBreakdown: AccountChargeVatBreakdownInput[];
  subtotal: string;
  vatTotal: string;
  total: string;
  transactionDiscountAmount: string;
  transactionDiscountReason: string | null;
}

export function buildAccountChargeCart(
  input: BuildAccountChargeCartInput,
): BuildAccountChargeCartResult {
  const scale = getCurrencyDecimals(input.currency);
  const { subtotal, taxAmount } = computeLineTotals(input.cartItems);

  const lines: AccountChargeLineInput[] = input.cartItems.map((item) => {
    const discountAmount = bcformat(item.discount_amount ?? '0', scale);
    const discountReason = item.discount_reason ?? null;
    if (bccomp(discountAmount, '0') > 0 && (discountReason === null || discountReason === '')) {
      throw new AccountChargeCartError(
        `line discount reason is required for product ${item.product.id}.`,
      );
    }
    const lineVat = bcformat(item.tax_amount, scale);
    const lineSubtotal = bcformat(bcsub(item.line_total, item.tax_amount), scale);
    return {
      lineUuid: crypto.randomUUID(),
      productId: item.product.id,
      sku: item.product.sku ?? null,
      name: item.product.name,
      quantity: bcformat(String(item.quantity), 3),
      unitPrice: bcformat(item.unit_price, scale), // gross / tax-inclusive, verbatim
      lineSubtotal,                                 // net
      lineVat,
      lineDiscountAmount: discountAmount,
      lineDiscountReason: bccomp(discountAmount, '0') === 0 ? null : discountReason,
      vatRate: bcformat(item.tax_rate, 2),
      taxCategoryCode: '',
      gtin: null,
    };
  });

  const groups = new Map<string, AccountChargeVatBreakdownInput>();
  for (const line of lines) {
    const key = `${line.vatRate}|${line.taxCategoryCode ?? ''}`;
    const current = groups.get(key) ?? {
      rate: line.vatRate,
      taxCategoryCode: line.taxCategoryCode ?? '',
      netAmount: bcformat('0', scale),
      vatAmount: bcformat('0', scale),
      grossAmount: bcformat('0', scale),
    };
    current.netAmount = bcformat(bcadd(current.netAmount, line.lineSubtotal), scale);
    current.vatAmount = bcformat(bcadd(current.vatAmount, line.lineVat), scale);
    current.grossAmount = bcformat(bcadd(current.netAmount, current.vatAmount), scale);
    groups.set(key, current);
  }

  let transactionDiscountAmount = '0';
  if (input.transactionDiscount) {
    transactionDiscountAmount = input.transactionDiscount.type === 'percentage'
      ? bcdiv(bcmul(subtotal, input.transactionDiscount.value), '100')
      : input.transactionDiscount.value;
  }
  const rawTotal = bcsub(bcadd(subtotal, taxAmount), transactionDiscountAmount);
  const total = bccomp(rawTotal, '0') >= 0 ? rawTotal : '0';

  return {
    lines,
    vatBreakdown: [...groups.values()],
    subtotal: bcformat(subtotal, scale),
    vatTotal: bcformat(taxAmount, scale),
    total: bcformat(total, scale),
    transactionDiscountAmount: bcformat(transactionDiscountAmount, scale),
    transactionDiscountReason: input.transactionDiscount?.reason ?? null,
  };
}
```

Note: if `computeLineTotals` or `TransactionDiscount` are not exported from `cartStore.ts`, export them (they are already used cross-module by `receiptService.ts`). Verify the exact `TransactionDiscount` shape (`{ type: 'percentage' | 'fixed'; value: string; reason?: string | null }`) and adjust the import if the name differs.

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test -- accountChargeCartMapper.test.ts`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/accountCharge/accountChargeCartMapper.ts \
  apps/pos/src/lib/accountCharge/__tests__/accountChargeCartMapper.test.ts \
  apps/pos/src/stores/cartStore.ts
git commit -m "feat(pos): account-charge cart→line mapper with tax-inclusive unit_price"
```

---

## Task 2: ESC/POS account-charge receipt builder

**Files:**
- Modify: `apps/pos/src/lib/buildReceiptData.ts`
- Test: `apps/pos/src/lib/buildReceiptData.test.ts` (create if absent)

Context: `buildEscPosAccountPaymentReceiptData(input)` at `buildReceiptData.ts:287` returns `ReceiptData`. The charge printable type is `AccountChargePrintable` (`apps/pos/src/lib/accountCharge/accountChargePrintable.ts:23`) with fields `title`, `accountChargeUuid`, `eventTimeDevice`, `terminalName`, `cashierName`, `sellerName/TaxNumber/Address`, `customerName/Phone`, `amountChargedToAccount`, `subtotal`, `vatTotal`, `lines[]` (`{name,quantity,unitPrice,lineSubtotal,lineVat,lineTotal}`), `vatBreakdown[]` (`{rate,taxCategoryCode,netAmount,vatAmount,grossAmount}`), `balanceBefore`, `balanceAfter`, etc. The `ReceiptData` shape used by `buildEscPosAccountPaymentReceiptData` has `company`, `receipt_number`, `date_time`, `terminal_name`, `operator_name`, `lines`, `subtotal`, `discount_amount`, `tax_amount`, `total`, `currency_symbol`, `vat_breakdown`, `payments`, `change_due`, etc. `getCurrencySymbol` and `bcformat` are already imported in `buildReceiptData.ts`. The charge has **no payments** — `payments: []`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest';
import type { AccountChargePrintable } from '@/lib/accountCharge/accountChargePrintable';
import { buildEscPosAccountChargeReceiptData } from './buildReceiptData';

function printable(): AccountChargePrintable {
  return {
    title: 'ACCOUNT CHARGE RECEIPT',
    accountChargeUuid: '66666666-6666-4666-8666-666666666666',
    fiscalEventId: 'fe-1', fiscalHash: 'hash-1',
    terminalName: 'Till 1', terminalId: 't', shiftId: 's',
    cashierName: 'Sam', businessDate: '2026-06-04', eventTimeDevice: '2026-06-04T10:15:30.000Z',
    sellerName: 'Default Seller', sellerTaxNumber: '1234567AM000', sellerAddress: '1 rue, Tunis',
    customerName: 'Mariam', customerPhone: '+21611111111', customerCategory: 'individual',
    accountIdentifier: 'CUST-0001',
    amountChargedToAccount: '119.000', chargeAmount: '119.000',
    subtotal: '100.000', vatTotal: '19.000',
    balanceBefore: '300.000', balanceAfter: '419.000',
    creditLimit: '500.000', creditAvailableBefore: '200.000', creditAvailableAfter: '81.000',
    dueDate: '2026-07-04', termsLabel: 'Net 30',
    customerSnapshotStale: false, balanceSnapshotStale: false, stalenessReason: null, trainingFlag: false,
    lines: [{ name: 'Widget', quantity: '1.000', unitPrice: '119.000', lineSubtotal: '100.000', lineVat: '19.000', lineTotal: '119.000' }],
    vatBreakdown: [{ rate: '19.00', taxCategoryCode: '', netAmount: '100.000', vatAmount: '19.000', grossAmount: '119.000' }],
  };
}

describe('buildEscPosAccountChargeReceiptData', () => {
  it('renders charge totals with no payment lines', () => {
    const data = buildEscPosAccountChargeReceiptData({ payload: printable(), currencyCode: 'TND' });
    expect(data.receipt_number).toBe('66666666-6666-4666-8666-666666666666');
    expect(data.total).toBe('119.000');
    expect(data.payments).toEqual([]);
    expect(data.lines).toHaveLength(1);
    expect(data.company.name).toBe('Default Seller');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- buildReceiptData.test.ts`
Expected: FAIL — `buildEscPosAccountChargeReceiptData` not exported.

- [ ] **Step 3: Write minimal implementation**

Add to `buildReceiptData.ts` (mirror `buildEscPosAccountPaymentReceiptData`; read the existing `ReceiptData` line shape there and match field names exactly):

```ts
export interface BuildAccountChargeReceiptDataInput {
  payload: AccountChargePrintable;
  currencyCode: string;
}

export function buildEscPosAccountChargeReceiptData(
  input: BuildAccountChargeReceiptDataInput,
): ReceiptData {
  const p = input.payload;
  const currencySymbol = getCurrencySymbol(input.currencyCode);
  return {
    company: {
      name: p.sellerName,
      address_line1: p.sellerAddress,
      address_line2: null,
      city: null,
      postal_code: null,
      country: null,
      tax_id: p.sellerTaxNumber,
      phone: null,
    },
    receipt_number: p.accountChargeUuid,
    date_time: p.eventTimeDevice,
    terminal_name: p.terminalName,
    operator_name: p.cashierName,
    lines: p.lines.map((l) => ({
      name: l.name,
      quantity: l.quantity,
      unit_price: l.unitPrice,
      line_total: l.lineTotal,
    })),
    subtotal: p.subtotal,
    discount_amount: '0',
    tax_amount: p.vatTotal,
    total: p.amountChargedToAccount,
    currency_symbol: currencySymbol,
    vat_breakdown: p.vatBreakdown.map((v) => ({
      rate: v.rate,
      net_amount: v.netAmount,
      vat_amount: v.vatAmount,
      gross_amount: v.grossAmount,
    })),
    payments: [],
    change_due: '0',
    tolerance_writeoff: null,
    has_tolerance: false,
  };
}
```

Match the exact `ReceiptData` line and `vat_breakdown` field names used by `buildEscPosAccountPaymentReceiptData` / `buildEscPosReceiptData`; adjust the literal field names above to whatever those builders use (e.g. discount/scale fields). Import `AccountChargePrintable` at the top of `buildReceiptData.ts`.

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test -- buildReceiptData.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/lib/buildReceiptData.ts apps/pos/src/lib/buildReceiptData.test.ts
git commit -m "feat(pos): ESC/POS account-charge receipt builder"
```

---

## Task 3: `processAccountCharge` store action

**Files:**
- Modify: `apps/pos/src/stores/paymentStore.ts`
- Test: `apps/pos/src/stores/__tests__/paymentStore.accountCharge.test.ts` (create)

Context: mirror `processAccountPayment` (`paymentStore.ts:1091`) and `createAccountPaymentLocalFirst` (`paymentStore.ts:600`). Identity sources: `useAuthStore` (companyId, companies→currency+seller via `companyField`, `user.tenantId`, `user.id/name`), `useOperatorStore` (operator), `useTerminalStore` (terminal, shift, `is_training_mode`). `getDatabase(companyId)` for the DB. `authorAccountCharge` + `AuthorAccountChargeInput` + `AccountChargeOverrideApprovalInput` + `AccountChargeResult` from `@/lib/accountCharge/accountChargeService`. Mapper from Task 1. ESC/POS builder from Task 2. `isBalanceStale` from `@/lib/db/repositories/customerRepository`. `authorAccountCharge` already calls `incrementPendingCount` + `triggerSync` — do not re-trigger.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';

const authorAccountCharge = vi.fn();
vi.mock('@/lib/accountCharge/accountChargeService', () => ({ authorAccountCharge }));
vi.mock('@/lib/buildReceiptData', () => ({
  buildEscPosAccountChargeReceiptData: () => ({ receipt_number: 'r', payments: [] }),
}));
// ...mock getDatabase, useAuthStore/useOperatorStore/useTerminalStore getState, cartStore, isBalanceStale
// to supply a tenant/company-matched eligible customer + a single-line cart (see processAccountPayment tests for the established mock harness).

import { usePaymentStore } from '../paymentStore';

describe('processAccountCharge', () => {
  beforeEach(() => { authorAccountCharge.mockReset(); /* reset store + mocks */ });

  it('authors a charge with no payment lines, sets print data, clears cart, updates balance', async () => {
    authorAccountCharge.mockResolvedValue({
      accountChargeUuid: 'ac-1', fiscalEventId: 'fe-1', total: '119.000', currency: 'TND',
      fiscalHash: 'h', sequenceNumber: 1, canonicalBytes: '{}',
      payload: { event_time_device: '2026-06-04T10:00:00.000Z', local_balance_snapshot: { projected_receivable_balance_after: '419.000', projected_credit_balance_after: '0.000' } },
      printable: { title: 'ACCOUNT CHARGE RECEIPT' },
      fiscalEvent: { id: 'fe-1' },
    });
    const result = await usePaymentStore.getState().processAccountCharge('terminal-1');
    expect(result?.fiscalEventId).toBe('fe-1');
    const callArg = authorAccountCharge.mock.calls[0][1];
    expect(callArg.payments).toBeUndefined();
    expect(usePaymentStore.getState().lastReceiptPrintData).not.toBeNull();
    expect(usePaymentStore.getState().selectedCustomer?.receivable_balance).toBe('419.000');
  });

  it('does not author when no eligible customer is attached', async () => {
    usePaymentStore.setState({ selectedCustomer: null });
    await expect(usePaymentStore.getState().processAccountCharge('terminal-1')).rejects.toThrow();
    expect(authorAccountCharge).not.toHaveBeenCalled();
  });

  it('passes overrideApproval through to the service', async () => {
    authorAccountCharge.mockResolvedValue(/* same shape as above */);
    const override = { approvalId: 'a', approvalScope: 'credit_limit_override', cashierUserId: 'c', reasonCode: 'r', reasonText: null, requestedAtDevice: new Date(), resolvedAtDevice: new Date(), supervisorUserId: 's', supervisorUserSnapshot: {} };
    await usePaymentStore.getState().processAccountCharge('terminal-1', { overrideApproval: override });
    expect(authorAccountCharge.mock.calls[0][1].overrideApproval).toBe(override);
  });
});
```

Reuse the existing mock harness from the `processAccountPayment` store tests (same file or a sibling). Keep the new tests in their own file to avoid disturbing existing suites.

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- paymentStore.accountCharge.test.ts`
Expected: FAIL — `processAccountCharge` is not a function.

- [ ] **Step 3: Add the action type to the store interface**

In the `PaymentStore` interface (near `processAccountPayment`):

```ts
processAccountCharge: (
  terminalId: string,
  options?: { overrideApproval?: AccountChargeOverrideApprovalInput | null },
) => Promise<AccountChargeResult | null>;
```

Add imports at the top of `paymentStore.ts`:

```ts
import { authorAccountCharge } from '@/lib/accountCharge/accountChargeService';
import type {
  AccountChargeOverrideApprovalInput,
  AccountChargeResult,
} from '@/lib/accountCharge/accountChargeService';
import { buildAccountChargeCart } from '@/lib/accountCharge/accountChargeCartMapper';
import { buildEscPosAccountChargeReceiptData } from '@/lib/buildReceiptData';
import { isBalanceStale } from '@/lib/db/repositories/customerRepository';
```

- [ ] **Step 4: Implement `createAccountChargeLocalFirst` helper**

Add near `createAccountPaymentLocalFirst`:

```ts
async function createAccountChargeLocalFirst(
  terminalId: string,
  selectedCustomer: AttachedCheckoutCustomer,
  options?: { overrideApproval?: AccountChargeOverrideApprovalInput | null },
): Promise<AccountChargeResult> {
  const authState = useAuthStore.getState();
  const operatorState = useOperatorStore.getState();

  const companyId = authState.companyId;
  if (!companyId) throw new Error(i18n.t('errors.noCompanySelected', { ns: 'pos' }));
  const company = authState.companies.find((c) => c.id === companyId);
  const currency = company?.currency ?? 'EUR';
  const tenantId = authState.user?.tenantId;
  if (!tenantId) throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));
  if (selectedCustomer.tenant_id !== tenantId || selectedCustomer.company_id !== companyId) {
    throw new Error('Customer belongs to a different tenant or company.');
  }

  const operator = operatorState.operator;
  const operatorId = operator?.id ?? authState.user?.id;
  const operatorName = operator?.name ?? authState.user?.name;
  if (!operatorId || !operatorName) throw new Error(i18n.t('errors.noOperatorIdentified', { ns: 'pos' }));

  const terminalState = useTerminalStore.getState();
  const terminal = terminalState.terminal;
  const shift = terminalState.shift;
  if (!terminal || terminal.id !== terminalId || !shift) throw new ActiveTerminalRequiredError();

  const cartState = useCartStore.getState();
  const cart = buildAccountChargeCart({
    cartItems: cartState.items,
    currency,
    transactionDiscount: cartState.transactionDiscount,
  });

  const stale = isBalanceStale(
    {
      ...selectedCustomer,
      is_active: 1,
      sync_version: null,
      updated_at: null,
      synced_at: selectedCustomer.balance_updated_at ?? new Date().toISOString(),
    },
    new Date(),
    30,
  );

  const db = await getDatabase(companyId);
  return authorAccountCharge(db, {
    tenantId,
    companyId,
    terminalId,
    terminalName: terminal.name,
    operatorId,
    operatorName,
    shiftId: shift.id,
    currency,
    seller: {
      name: companyField(company, 'legalName', 'legal_name') ?? company?.name ?? null,
      taxNumber: companyField(company, 'taxId', 'tax_id'),
      countryCode: companyField(company, 'countryCode', 'country_code'),
      street: companyField(company, 'addressStreet', 'address_street'),
      city: companyField(company, 'addressCity', 'address_city'),
      postalCode: companyField(company, 'addressPostalCode', 'address_postal_code'),
    },
    customer: selectedCustomer,
    lines: cart.lines,
    vatBreakdown: cart.vatBreakdown,
    subtotal: cart.subtotal,
    vatTotal: cart.vatTotal,
    total: cart.total,
    transactionDiscountAmount: cart.transactionDiscountAmount,
    transactionDiscountReason: cart.transactionDiscountReason,
    isTraining: terminal.is_training_mode === true,
    balanceSnapshotStale: stale,
    overrideApproval: options?.overrideApproval ?? null,
  });
}
```

- [ ] **Step 5: Implement the `processAccountCharge` action**

```ts
processAccountCharge: async (terminalId, options) => {
  const { selectedCustomer } = get();
  if (!selectedCustomer) {
    const msg = i18n.t('account_charge.errors.customer_required', { ns: 'pos', defaultValue: 'Customer is required for account charge.' });
    set({ error: msg });
    throw new Error(msg);
  }
  const enabled = selectedCustomer.charge_account_enabled === true || selectedCustomer.charge_account_enabled === 1;
  if (!enabled) {
    const msg = i18n.t('account_charge.errors.not_enabled', { ns: 'pos', defaultValue: 'This customer is not enabled for account charge.' });
    set({ error: msg });
    throw new Error(msg);
  }

  let alreadyInFlight = false;
  set((state) => {
    if (state.isProcessing) { alreadyInFlight = true; return state; }
    return { isProcessing: true, error: null };
  });
  if (alreadyInFlight) return null;

  try {
    const result = await createAccountChargeLocalFirst(terminalId, selectedCustomer, options);
    const snapshot = result.payload.local_balance_snapshot;
    set({
      isProcessing: false,
      changeDue: 0,
      lastReceipt: {
        id: result.fiscalEventId,
        receipt_number: result.accountChargeUuid,
        total: result.total,
        subtotal: '0', tax_amount: '0', discount_amount: '0',
        currency: result.currency,
      } satisfies CreateReceiptResponse,
      lastReceiptIdempotencyKey: null,
      lastReceiptServerId: null,
      lastReceiptPrintData: buildEscPosAccountChargeReceiptData({ payload: result.printable, currencyCode: result.currency }),
      selectedCustomer: {
        ...selectedCustomer,
        receivable_balance: snapshot.projected_receivable_balance_after,
        credit_balance: snapshot.projected_credit_balance_after,
        balance_updated_at: result.payload.event_time_device,
      },
    });
    useCartStore.getState().clearCart();
    return result;
  } catch (error) {
    set({ isProcessing: false, error: formatCheckoutError(error) });
    throw error;
  }
},
```

- [ ] **Step 6: Run tests + gates**

Run:
```bash
pnpm test -- paymentStore.accountCharge.test.ts
pnpm typecheck
pnpm lint
```
Expected: PASS / clean.

- [ ] **Step 7: Commit**

```bash
git add apps/pos/src/stores/paymentStore.ts apps/pos/src/stores/__tests__/paymentStore.accountCharge.test.ts
git commit -m "feat(pos): processAccountCharge store action"
```

---

## Task 4: `AccountChargeConfirmation` component

**Files:**
- Create: `apps/pos/src/components/customers/AccountChargeConfirmation.tsx`
- Test: `apps/pos/src/components/customers/__tests__/AccountChargeConfirmation.test.tsx`

Context: `evaluateAccountChargeCreditDecision` + `AccountChargeRejectionCode` from `@/lib/accountCharge/creditRulesEngine`. `AccountChargeOverrideApprovalInput` from `@/lib/accountCharge/accountChargeService`. `ManagerPinPanel` from `@/components/pos/molecules/ManagerPinPanel` (`authorizedManagers`, `excludeUserId`, `onVerify`, `onSuccess(userId,name)`, `throttle`, `onThrottleUpdate`). `fetchAuthorizedManagers` + `AuthorizedManager` from `@/api/managersApi`. `verifyManagerPin` from `@/api/managerPinApi`. Component reads `selectedCustomer` from `usePaymentStore`, cashier id from `useAuthStore`/`useOperatorStore`. The overridable rejection→scope map: `credit_limit_exceeded → 'credit_limit_override'`; `account_suspended` / `account_disputed → 'account_status_override'`; everything else is a hard block.

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { AccountChargeConfirmation } from '../AccountChargeConfirmation';

// vi.mock react-i18next, managersApi.fetchAuthorizedManagers (→ [{id:'m1',name:'Mgr'}]),
// managerPinApi.verifyManagerPin (→ {valid:true}), and usePaymentStore (selectedCustomer eligible).

describe('AccountChargeConfirmation', () => {
  it('shows balance impact and enables confirm for an approved decision', async () => {
    const onConfirm = vi.fn().mockResolvedValue(undefined);
    render(<AccountChargeConfirmation total="119.000" currency="TND" cashierUserId="c1" onConfirm={onConfirm} onCancel={vi.fn()} isProcessing={false} />);
    const confirm = await screen.findByRole('button', { name: /charge to account/i });
    expect(confirm).toBeEnabled();
    fireEvent.click(confirm);
    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(null));
  });

  it('shows manager PIN on credit-limit rejection and confirms with override after PIN', async () => {
    const onConfirm = vi.fn().mockResolvedValue(undefined);
    // customer mocked with credit_limit '100.000', receivable '90.000' → credit_limit_exceeded for 119
    render(<AccountChargeConfirmation total="119.000" currency="TND" cashierUserId="c1" onConfirm={onConfirm} onCancel={vi.fn()} isProcessing={false} />);
    expect(await screen.findByText(/credit limit/i)).toBeInTheDocument();
    expect(screen.getByTestId('manager-pin-panel')).toBeInTheDocument();
    // drive ManagerPinPanel to success, then confirm
    // ...select manager, enter pin, verify → onConfirm called with an override object whose approvalScope === 'credit_limit_override'
  });

  it('blocks with a message and no PIN on a hard rejection (account closed)', async () => {
    render(<AccountChargeConfirmation total="119.000" currency="TND" cashierUserId="c1" onConfirm={vi.fn()} onCancel={vi.fn()} isProcessing={false} />);
    expect(await screen.findByText(/closed/i)).toBeInTheDocument();
    expect(screen.queryByTestId('manager-pin-panel')).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- AccountChargeConfirmation.test.tsx`
Expected: FAIL — component not found.

- [ ] **Step 3: Implement the component**

```tsx
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { usePaymentStore } from '@/stores/paymentStore';
import { getCurrencyDecimals } from '@/lib/currency';
import { evaluateAccountChargeCreditDecision, type AccountChargeRejectionCode } from '@/lib/accountCharge/creditRulesEngine';
import type { AccountChargeOverrideApprovalInput } from '@/lib/accountCharge/accountChargeService';
import { ManagerPinPanel } from '@/components/pos/molecules/ManagerPinPanel';
import { fetchAuthorizedManagers, type AuthorizedManager } from '@/api/managersApi';
import { verifyManagerPin } from '@/api/managerPinApi';

const OVERRIDE_SCOPE: Partial<Record<AccountChargeRejectionCode, AccountChargeOverrideApprovalInput['approvalScope']>> = {
  credit_limit_exceeded: 'credit_limit_override',
  account_suspended: 'account_status_override',
  account_disputed: 'account_status_override',
};

export interface AccountChargeConfirmationProps {
  total: string;
  currency: string;
  cashierUserId: string;
  onConfirm: (overrideApproval: AccountChargeOverrideApprovalInput | null) => Promise<void>;
  onCancel: () => void;
  isProcessing: boolean;
  now?: () => Date;
}

export function AccountChargeConfirmation(props: AccountChargeConfirmationProps) {
  const { t } = useTranslation('pos');
  const customer = usePaymentStore((s) => s.selectedCustomer);
  const now = props.now ?? (() => new Date());
  const [managers, setManagers] = useState<AuthorizedManager[]>([]);
  const [throttle, setThrottle] = useState({ until: null as string | null, failedAttempts: 0 });
  const [override, setOverride] = useState<AccountChargeOverrideApprovalInput | null>(null);
  const scale = getCurrencyDecimals(props.currency);

  const decision = useMemo(() => {
    if (!customer) return null;
    return evaluateAccountChargeCreditDecision({
      tenant_id: customer.tenant_id, company_id: customer.company_id,
      expected_tenant_id: customer.tenant_id, expected_company_id: customer.company_id,
      customer_id: customer.id, customer_sync_status: customer.customer_sync_status, alias_candidates: [],
      is_active: customer.is_active, account_status: customer.account_status,
      charge_account_enabled: customer.charge_account_enabled, charge_policy_version: customer.charge_policy_version,
      receivable_balance: customer.receivable_balance, credit_balance: customer.credit_balance, credit_limit: customer.credit_limit,
      charge_amount: props.total, currency_scale: scale as 0 | 2 | 3, balance_updated_at: customer.balance_updated_at,
      now: now(), hard_stale_after_minutes: 240, override_evidence: null,
    });
  }, [customer, props.total, scale]);

  const rejection = decision && !decision.ok ? decision.error.code : null;
  const overridableScope = rejection ? OVERRIDE_SCOPE[rejection] ?? null : null;

  useEffect(() => {
    if (overridableScope && managers.length === 0) {
      void fetchAuthorizedManagers().then(setManagers).catch(() => setManagers([]));
    }
  }, [overridableScope, managers.length]);

  const confirmable = decision?.ok === true || override !== null;

  const handlePinSuccess = (supervisorUserId: string, supervisorName: string) => {
    if (!overridableScope) return;
    const at = now();
    setOverride({
      approvalId: crypto.randomUUID(),
      approvalScope: overridableScope,
      cashierUserId: props.cashierUserId,
      reasonCode: rejection ?? 'override',
      reasonText: null,
      requestedAtDevice: at,
      resolvedAtDevice: at,
      supervisorUserId,
      supervisorUserSnapshot: { name: supervisorName },
    });
  };

  // Render: customer + amount + current/projected balance + credit available;
  // if rejection && !override: rejection message (t('account_charge.reject.<code>'));
  //   if overridableScope: <ManagerPinPanel ... onSuccess={handlePinSuccess} throttle={throttle} onThrottleUpdate={setThrottle} excludeUserId={props.cashierUserId} authorizedManagers={managers} onVerify={verifyManagerPin} />
  // Confirm button: disabled={!confirmable || props.isProcessing}; onClick={() => void props.onConfirm(override)}
  // Cancel button: onClick={props.onCancel}
  return (/* JSX per above; all strings via t(); fixed layout */);
}
```

Implement the JSX fully (no placeholder comment in the shipped file): a fixed-size panel showing customer name, charge amount, current → projected receivable balance, credit limit + available before/after, due date/terms, and the rejection/override region. The Confirm button label is `t('account_charge.confirm', { defaultValue: 'Charge to account' })`. Every visible string uses `t()` with the keys added in Task 7.

- [ ] **Step 4: Run tests + gates**

Run:
```bash
pnpm test -- AccountChargeConfirmation.test.tsx
pnpm typecheck
pnpm lint
```
Expected: PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/customers/AccountChargeConfirmation.tsx \
  apps/pos/src/components/customers/__tests__/AccountChargeConfirmation.test.tsx
git commit -m "feat(pos): account-charge credit-decision confirmation with manager-PIN override"
```

---

## Task 5: "On Account" mode in AdvancedPaymentsModal

**Files:**
- Modify: `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
- Test: `apps/pos/src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx`

Context: the modal renders `activeMethods.map(...)` tiles (around line 494) and already reads `usePaymentStore` (`voucherTenders`). Add a new optional prop and a synthetic "On Account" tile, gated on an eligible attached customer + positive total. Selecting it switches to account-charge mode and renders `AccountChargeConfirmation` in place of the tender working area, preserving the modal's fixed dimensions (per the modal-sizing rule).

- [ ] **Step 1: Write the failing test**

```tsx
// In the existing AdvancedPaymentsModal test file, add:
it('shows the On Account tile only when an eligible customer is attached and total > 0', () => {
  usePaymentStore.setState({ selectedCustomer: { /* eligible: charge_account_enabled true */ } as never });
  render(<AdvancedPaymentsModal {...baseProps} total={119} onChargeToAccount={vi.fn()} />);
  expect(screen.getByRole('button', { name: /on account/i })).toBeInTheDocument();
});

it('hides the On Account tile when no eligible customer is attached', () => {
  usePaymentStore.setState({ selectedCustomer: null });
  render(<AdvancedPaymentsModal {...baseProps} total={119} onChargeToAccount={vi.fn()} />);
  expect(screen.queryByRole('button', { name: /on account/i })).not.toBeInTheDocument();
});

it('switches to charge confirmation when On Account is tapped', () => {
  usePaymentStore.setState({ selectedCustomer: { /* eligible */ } as never });
  render(<AdvancedPaymentsModal {...baseProps} total={119} onChargeToAccount={vi.fn()} />);
  fireEvent.click(screen.getByRole('button', { name: /on account/i }));
  expect(screen.getByText(/charge to account/i)).toBeInTheDocument();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- AdvancedPaymentsModal.test.tsx`
Expected: FAIL — no On Account tile / prop.

- [ ] **Step 3: Implement**

Add to `AdvancedPaymentsModalProps`:

```ts
onChargeToAccount?: (overrideApproval?: AccountChargeOverrideApprovalInput | null) => Promise<void>;
```

Inside the component:

```ts
const selectedCustomer = usePaymentStore((s) => s.selectedCustomer);
const chargeEligible = !!props.onChargeToAccount && selectedCustomer != null
  && (selectedCustomer.charge_account_enabled === true || selectedCustomer.charge_account_enabled === 1)
  && total > 0;
const [accountChargeMode, setAccountChargeMode] = useState(false);
const cashierUserId = useAuthStore.getState().user?.id ?? '';
```

Render an "On Account" tile after the `activeMethods.map(...)` tiles, only when `chargeEligible`, that calls `setAccountChargeMode(true)`. When `accountChargeMode`, render `<AccountChargeConfirmation total={String(total)} currency={currency} cashierUserId={cashierUserId} isProcessing={isProcessing} onCancel={() => setAccountChargeMode(false)} onConfirm={async (o) => { await props.onChargeToAccount!(o); }} />` in place of the center/right tender working area. Reset `accountChargeMode` to false in the existing modal-close/reset path. Keep the dialog container's fixed width/height classes unchanged.

- [ ] **Step 4: Run tests + gates**

Run:
```bash
pnpm test -- AdvancedPaymentsModal.test.tsx
pnpm typecheck
pnpm lint
```
Expected: PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx \
  apps/pos/src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx
git commit -m "feat(pos): On Account mode in AdvancedPaymentsModal"
```

---

## Task 6: HomePage wiring

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx`

Context: `AdvancedPaymentsModal` is mounted around `HomePage.tsx:1190`. `processAccountCharge` is on `paymentStore`. After a successful charge, close the modal and open `CheckoutSuccessModal` (the print loader already prefers `lastReceiptPrintData`).

- [ ] **Step 1: Add the handler**

```tsx
const processAccountCharge = usePaymentStore((s) => s.processAccountCharge);

const handleChargeToAccount = useCallback(
  async (overrideApproval?: AccountChargeOverrideApprovalInput | null) => {
    if (!terminal) return;
    try {
      const result = await processAccountCharge(terminal.id, { overrideApproval: overrideApproval ?? null });
      if (result) {
        setShowAdvancedModal(false); // use the existing state setter that controls AdvancedPaymentsModal visibility
        setShowSuccessModal(true);
      }
    } catch (error) {
      console.error('[POS][HomePage][handleChargeToAccount] failed', { ...serializeErrorForLog(error) });
      // error surfaces via paymentStore.error already shown in the modal
    }
  },
  [terminal, processAccountCharge],
);
```

Use the actual state setter HomePage uses to show/hide `AdvancedPaymentsModal` (find the `isOpen` prop binding near line 1190 and reuse that setter). Import `AccountChargeOverrideApprovalInput` from `@/lib/accountCharge/accountChargeService`.

- [ ] **Step 2: Pass the prop**

On the `<AdvancedPaymentsModal ... />` element add:

```tsx
onChargeToAccount={handleChargeToAccount}
```

- [ ] **Step 3: Run gates**

Run:
```bash
pnpm test
pnpm typecheck
pnpm lint
```
Expected: PASS / clean (whole POS suite).

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/pages/HomePage.tsx
git commit -m "feat(pos): wire charge-to-account into HomePage checkout"
```

---

## Task 7: i18n keys

**Files:**
- Modify: `apps/pos/src/locales/en/pos.json`
- Modify: `apps/pos/src/locales/fr/pos.json`

- [ ] **Step 1: Add keys to both locales**

Add an `account_charge` block (merge into existing structure; keep keys sorted to match the file's convention). English:

```json
"account_charge": {
  "tile": "On Account",
  "confirm": "Charge to account",
  "cancel": "Cancel",
  "amount": "Amount to charge",
  "current_balance": "Current balance",
  "projected_balance": "Projected balance",
  "credit_available": "Credit available",
  "due_date": "Due date",
  "stale_warning": "Customer balance may be out of date",
  "errors": {
    "customer_required": "Customer is required for account charge.",
    "not_enabled": "This customer is not enabled for account charge."
  },
  "reject": {
    "credit_limit_exceeded": "Credit limit exceeded — manager approval required.",
    "account_suspended": "Account suspended — manager approval required.",
    "account_disputed": "Account in dispute — manager approval required.",
    "account_closed": "Account is closed and cannot be charged.",
    "customer_inactive": "Customer is inactive.",
    "charge_account_disabled": "Account charging is disabled for this customer.",
    "charge_policy_missing": "No credit policy configured for this customer.",
    "balance_snapshot_missing": "Customer balance is unavailable.",
    "balance_snapshot_invalid": "Customer balance is invalid.",
    "balance_snapshot_hard_stale": "Customer balance is too old — sync required.",
    "customer_alias_ambiguous": "Customer identity is ambiguous.",
    "customer_tenant_mismatch": "Customer belongs to a different tenant.",
    "customer_company_mismatch": "Customer belongs to a different company.",
    "money_scale_invalid": "Amount precision is invalid."
  }
}
```

Provide the French translations for every key in `fr/pos.json` (e.g. `"tile": "À crédit"`, `"confirm": "Mettre sur le compte"`, etc.).

- [ ] **Step 2: Run gates**

Run:
```bash
pnpm test
pnpm typecheck
pnpm lint
```
Expected: PASS / clean. If the repo has an i18n-parity test, it must pass (en/fr key sets equal).

- [ ] **Step 3: Commit**

```bash
git add apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json
git commit -m "feat(pos): i18n keys for charge-to-account"
```

---

## Task 8: Correct account-charge fixtures to tax-inclusive unit_price

**Files (TS):**
- Modify: `apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts` (`goldenAccountChargePayload` line `unit_price: '100.000'` → `'119.000'`; and the `goldenAccountChargeCanonicalBytes` substring `"unit_price":"100.000"` → `"unit_price":"119.000"`).

**Files (PHP) — discover and correct each account-charge fixture whose line uses `unit_price` equal to `line_subtotal` (NET placeholder):**
- `apps/api/tests/Unit/Fiscal/CanonicalPayloadReaderTest.php`
- `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php`
- `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php`
- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php`
- `apps/api/tests/Feature/Fiscal/AccountChargeProjectionTest.php`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php`
- `apps/api/tests/Feature/Fiscal/DocumentAccountChargeFactureBridgeTest.php`
- `apps/api/tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php`
- `apps/api/tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` (only if it contains an example/fixture payload, not a runtime invariant).

Safety gate (already satisfied 2026-06-04): no caller of `authorAccountCharge` exists on any branch and `ACCOUNT_CHARGE` is device-authored only, so there are zero production `ACCOUNT_CHARGE` events to migrate.

- [ ] **Step 1: Confirm no validator couples unit_price to line totals**

Run:
```bash
cd apps/api
grep -n "unit_price" app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php
```
Expected: `unit_price` appears only as a money-format-validated field (regex/scale), with **no** assertion equating it to `line_subtotal`/`line_subtotal + line_vat`. SALE_RECEIPT already carries inclusive `unit_price` with net `line_subtotal`, proving no such coupling. If any coupling exists, STOP and surface it before changing fixtures.

- [ ] **Step 2: Enumerate the exact NET-placeholder lines**

Run:
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.charge-checkout-ui
grep -rn "unit_price" apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts \
  apps/api/tests/Unit/Fiscal apps/api/tests/Feature/Fiscal apps/api/tests/Feature/Accounting \
  | grep -i "charge\|100.000\|account"
```
For each account-charge line where `unit_price == line_subtotal` (the NET placeholder, typically `100.000` paired with `line_vat 19.000`), the correction is `unit_price = line_subtotal + line_vat` per unit for `quantity == 1` (i.e. the gross/inclusive price). For multi-unit lines, set `unit_price` to the gross per-unit price (`(line_subtotal + line_vat) / quantity`). Do **not** change `line_subtotal`, `line_vat`, `quantity`, or totals.

- [ ] **Step 3: Apply the corrections**

Edit each fixture so `unit_price` is the gross/inclusive per-unit price. For the canonical TS golden bytes and any hardcoded PHP golden bytes strings, update the `"unit_price":"…"` substring to match. (Changing `unit_price` is the only canonical-byte delta, since canonical keys are sorted and `unit_price` appears once per line.)

- [ ] **Step 4: Regenerate / verify canonical parity**

Run (TS):
```bash
cd apps/pos
pnpm test -- accountChargeCanonicalParity.test.ts AccountChargePayload.test.ts
```
If the test compares the encoder output to `goldenAccountChargeCanonicalBytes`, ensure the golden bytes constant was updated in Step 3 so it matches the new `unit_price`.

Run (PHP):
```bash
cd ../api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/ tests/Feature/Fiscal/
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php
```
Expected: all green. If a PHP test recomputes canonical bytes from a corrected helper, it passes automatically; if it hardcodes bytes, update that string.

- [ ] **Step 5: Run POS suite + Pint**

Run:
```bash
cd apps/pos && pnpm test && pnpm typecheck && pnpm lint
cd ../api && ./vendor/bin/pint --test app/Modules/Fiscal tests/Unit/Fiscal tests/Feature/Fiscal tests/Feature/Accounting
```
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/lib/fiscal/payloads/AccountChargePayload.ts \
  apps/api/tests/Unit/Fiscal apps/api/tests/Feature/Fiscal apps/api/tests/Feature/Accounting
git commit -m "fix(fiscal): account-charge fixtures use tax-inclusive unit_price"
```

---

## Task 9: Full verification, chokepoint, PR

**Files:** none (verification + PR).

- [ ] **Step 1: Run full gates**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.charge-checkout-ui/apps/pos
pnpm test
pnpm typecheck
pnpm lint

cd ../api
APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Unit/Fiscal/ tests/Feature/Fiscal/

cd /Users/houssamr/Projects/syneriva/apps/erp.charge-checkout-ui
bash apps/api/scripts/check-accountCharge-chokepoints.sh
```
Expected: all green; chokepoint confirms charge authored only through `FiscalEventEngine.append`, never receipt/payment routes.

- [ ] **Step 2: Manual smoke (if a dev terminal is available)**

Attach a credit-eligible customer → open "More" (AdvancedPaymentsModal) → tap "On Account" → review balance impact → Charge to account → confirm success modal prints `ACCOUNT CHARGE RECEIPT` → confirm a pending fiscal event is enqueued and sync fires.

- [ ] **Step 3: Push + PR**

```bash
git push origin feat/pos-charge-to-account-checkout-ui
gh pr create --base dev --head feat/pos-charge-to-account-checkout-ui \
  --title "POS charge-to-account checkout UI" \
  --body "Wires the cashier-facing checkout for the already-built ACCOUNT_CHARGE feature: On Account mode in AdvancedPaymentsModal, credit-decision confirmation with manager-PIN override, processAccountCharge store action, ESC/POS charge receipt, and tax-inclusive unit_price fixture correction. Spec: docs/superpowers/specs/2026-06-04-pos-charge-to-account-checkout-ui-spec.md"
```

Do not auto-merge; leave the PR for review (offline-first sync is exercised by `authorAccountCharge`).
```
