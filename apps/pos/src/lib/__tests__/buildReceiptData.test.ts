import { describe, it, expect, vi } from 'vitest';

// buildReceiptData uses i18next.t() for receipt labels — stub it out so the
// test runs without the full i18n initialisation.
vi.mock('i18next', () => ({
  default: {
    t: (key: string) => key,
  },
}));

// decimal.ts imports currency.ts which imports useAuthStore at module level.
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import { buildEscPosReceiptData } from '../buildReceiptData';
import type { FullReceiptResponse } from '@/types/receipt';

/** Minimal but valid FullReceiptResponse fixture */
function makeReceipt(overrides: Partial<FullReceiptResponse> = {}): FullReceiptResponse {
  return {
    id: 'rcpt-001',
    receipt_number: 'RC-001',
    receipt_type: 'sale',
    posted_at: '2024-01-15T10:00:00Z',
    cashier_name: 'Alice',
    subtotal: '10.00',
    tax_amount: '0.00',
    discount_amount: '0.00',
    total: '10.00',
    tolerance_writeoff: null,
    currency: 'EUR',
    fiscal_hash: null,
    customer_name: null,
    notes: null,
    company: {
      name: 'Test Shop',
      address_street: null,
      address_street_2: null,
      address_city: null,
      address_postal_code: null,
      country_code: 'FR',
      tax_id: null,
      phone: null,
    },
    terminal: { id: 'term-1', name: 'Terminal 1', code: 'T1' },
    lines: [
      {
        id: 'line-1',
        line_number: 1,
        product_code: 'PROD-1',
        product_name: 'Coffee',
        quantity: '1',
        unit_price: '10.00',
        line_total: '10.00',
        tax_rate: '0.00',
        tax_amount: '0.00',
        discount_amount: '0.00',
        modifiers: null,
      },
    ],
    vat_details: [],
    payments: [
      {
        id: 'pay-1',
        payment_method_id: 'pm-1',
        payment_type: 'cash',
        amount: '10.00',
        payment_method: { id: 'pm-1', name: 'Cash', code: 'CASH' },
      },
    ],
    ...overrides,
  };
}

describe('buildEscPosReceiptData — tolerance write-off (Phase 2 / Task 10)', () => {
  it('formats tolerance_writeoff to currency display scale (EUR → 2 decimals)', () => {
    // Storage scale is 3 (TND-compatible). Display scale must match the currency.
    // EUR has 2 decimals, so '0.020' (storage) → '0.02' (display).
    const receipt = makeReceipt({
      currency: 'EUR',
      tolerance_writeoff: '0.020',
    });

    const result = buildEscPosReceiptData(receipt);

    expect(result.tolerance_writeoff).toBe('0.02');
  });

  it('passes tolerance_writeoff = null when receipt has no tolerance applied', () => {
    const receipt = makeReceipt({ tolerance_writeoff: null });

    const result = buildEscPosReceiptData(receipt);

    expect(result.tolerance_writeoff).toBeNull();
  });

  it('exposes the rounding label via labels.rounding (i18n key, not hardcoded)', () => {
    const receipt = makeReceipt({ tolerance_writeoff: '0.020' });

    const result = buildEscPosReceiptData(receipt);

    // The mocked i18next.t returns its key — we expect the receiptLabel.rounding key.
    expect(result.labels?.rounding).toBe('pos:receiptLabel.rounding');
  });

  it.each([
    ['0.020', true, 'positive write-off'],
    ['0.001', true, 'sub-cent positive write-off'],
  ])(
    'sets has_tolerance=true for %s (%s)',
    (writeoff, expected, _label) => {
      const receipt = makeReceipt({ tolerance_writeoff: writeoff });

      const result = buildEscPosReceiptData(receipt);

      expect(result.has_tolerance).toBe(expected);
    },
  );

  it.each([
    ['0', 'literal zero'],
    ['0.000', 'zero at scale 3'],
    ['', 'empty string'],
    [null, 'null'],
  ])(
    'sets has_tolerance=false for %s (%s)',
    (writeoff, _label) => {
      const receipt = makeReceipt({ tolerance_writeoff: writeoff });

      const result = buildEscPosReceiptData(receipt);

      expect(result.has_tolerance).toBe(false);
    },
  );
});

describe('buildEscPosReceiptData', () => {
  it('returns change_due as a plain decimal string when payment equals total', () => {
    const receipt = makeReceipt();
    const result = buildEscPosReceiptData(receipt);

    // No change due — result must be a string formatted to the currency's
    // decimal places (2 for EUR), not the result of calling .toFixed() on a
    // string (which would have thrown "toFixed is not a function").
    expect(typeof result.change_due).toBe('string');
    expect(result.change_due).toBe('0.00');
  });

  it('returns correct change_due string when overpayment occurs', () => {
    // Customer pays 20.00 on a 10.00 total — change should be 10.00
    const receipt = makeReceipt({
      payments: [
        {
          id: 'pay-1',
          payment_method_id: 'pm-1',
          payment_type: 'cash',
          amount: '20.00',
          payment_method: { id: 'pm-1', name: 'Cash', code: 'CASH' },
        },
      ],
    });

    const result = buildEscPosReceiptData(receipt);

    expect(typeof result.change_due).toBe('string');
    expect(result.change_due).toBe('10.00');
  });

  it('change_due is a string and not the result of .toFixed() on a string', () => {
    // This test directly guards the original bug: changeDue was already a
    // string from bcsub() / (0).toFixed() — calling .toFixed() on it would
    // throw "changeDue.toFixed is not a function" at runtime.
    // If the function returns without throwing, the bug is fixed.
    const receipt = makeReceipt({
      total: '15.50',
      payments: [
        {
          id: 'pay-1',
          payment_method_id: 'pm-1',
          payment_type: 'cash',
          amount: '20.00',
          payment_method: { id: 'pm-1', name: 'Cash', code: 'CASH' },
        },
      ],
    });

    expect(() => buildEscPosReceiptData(receipt)).not.toThrow();
    const result = buildEscPosReceiptData(receipt);
    expect(result.change_due).toBe('4.50');
  });

  it('handles multiple payments summing to exact total', () => {
    const receipt = makeReceipt({
      total: '30.00',
      payments: [
        {
          id: 'pay-1',
          payment_method_id: 'pm-1',
          payment_type: 'cash',
          amount: '20.00',
          payment_method: { id: 'pm-1', name: 'Cash', code: 'CASH' },
        },
        {
          id: 'pay-2',
          payment_method_id: 'pm-2',
          payment_type: 'card',
          amount: '10.00',
          payment_method: { id: 'pm-2', name: 'Card', code: 'CARD' },
        },
      ],
    });

    const result = buildEscPosReceiptData(receipt);
    expect(result.change_due).toBe('0.00');
  });

  it('sets is_reprint=true when isReprint flag is passed', () => {
    const receipt = makeReceipt();
    const result = buildEscPosReceiptData(receipt, undefined, true);
    expect(result.is_reprint).toBe(true);
  });

  it('leaves is_reprint undefined by default', () => {
    const receipt = makeReceipt();
    const result = buildEscPosReceiptData(receipt);
    expect(result.is_reprint).toBeUndefined();
  });
});

// ── Currency-aware display scale ──────────────────────────────────────────────
// Storage scale is always 3 (TND-compatible). Receipt DISPLAY scale must match
// the currency's ISO 4217 native precision: EUR=2, TND=3, JPY=0, etc.
// This test suite guards against the regression where scale-3 strings are
// printed verbatim on EUR receipts (e.g., "€0.020" instead of "€0.02").

describe('buildEscPosReceiptData — currency-aware display scale', () => {
  /** Build a receipt where every monetary value comes in at storage scale (3). */
  function makeStorageScaleReceipt(
    currency: string,
    overrides: Partial<FullReceiptResponse> = {},
  ): FullReceiptResponse {
    return makeReceipt({
      currency,
      subtotal: '10.000',
      tax_amount: '1.900',
      discount_amount: '0.000',
      total: '11.900',
      tolerance_writeoff: '0.020',
      lines: [
        {
          id: 'line-1',
          line_number: 1,
          product_code: 'P1',
          product_name: 'Widget',
          quantity: '2.000',
          unit_price: '5.000',
          line_total: '10.000',
          tax_rate: '19.000',
          tax_amount: '1.900',
          discount_amount: '0.000',
          modifiers: null,
        },
      ],
      vat_details: [
        {
          tax_rate: '19.000',
          net_amount: '10.000',
          vat_amount: '1.900',
          gross_amount: '11.900',
        },
      ],
      payments: [
        {
          id: 'pay-1',
          payment_method_id: 'pm-1',
          payment_type: 'cash',
          amount: '12.000',
          payment_method: { id: 'pm-1', name: 'Cash', code: 'CASH' },
        },
      ],
      ...overrides,
    });
  }

  it('formats all monetary strings to 2 decimals for EUR', () => {
    const receipt = makeStorageScaleReceipt('EUR');
    const result = buildEscPosReceiptData(receipt);

    // Top-level totals
    expect(result.subtotal).toBe('10.00');
    expect(result.tax_amount).toBe('1.90');
    expect(result.discount_amount).toBe('0.00');
    expect(result.total).toBe('11.90');

    // Payment amounts
    expect(result.payments[0]!.amount).toBe('12.00');

    // Change due (12.00 - 11.90 = 0.10)
    expect(result.change_due).toBe('0.10');

    // Tolerance write-off: 0.020 stored, displayed as 0.02 for EUR
    expect(result.tolerance_writeoff).toBe('0.02');

    // Line item fields
    expect(result.lines[0]!.unit_price).toBe('5.00');
    expect(result.lines[0]!.line_total).toBe('10.00');

    // VAT breakdown
    expect(result.vat_breakdown[0]!.taxable).toBe('10.00');
    expect(result.vat_breakdown[0]!.tax).toBe('1.90');
  });

  it('formats all monetary strings to 3 decimals for TND', () => {
    const receipt = makeStorageScaleReceipt('TND');
    const result = buildEscPosReceiptData(receipt);

    // Top-level totals — scale 3 for TND
    expect(result.subtotal).toBe('10.000');
    expect(result.tax_amount).toBe('1.900');
    expect(result.discount_amount).toBe('0.000');
    expect(result.total).toBe('11.900');

    // Payment amounts
    expect(result.payments[0]!.amount).toBe('12.000');

    // Change due (12.000 - 11.900 = 0.100)
    expect(result.change_due).toBe('0.100');

    // Tolerance write-off stays at 3 decimals for TND
    expect(result.tolerance_writeoff).toBe('0.020');

    // Line item fields
    expect(result.lines[0]!.unit_price).toBe('5.000');
    expect(result.lines[0]!.line_total).toBe('10.000');

    // VAT breakdown
    expect(result.vat_breakdown[0]!.taxable).toBe('10.000');
    expect(result.vat_breakdown[0]!.tax).toBe('1.900');
  });

  it('formats all monetary strings to 0 decimals for JPY', () => {
    const receipt = makeStorageScaleReceipt('JPY', {
      subtotal: '1000.000',
      tax_amount: '100.000',
      discount_amount: '0.000',
      total: '1100.000',
      tolerance_writeoff: null,
      lines: [
        {
          id: 'line-1',
          line_number: 1,
          product_code: 'P1',
          product_name: 'Item',
          quantity: '1.000',
          unit_price: '1000.000',
          line_total: '1000.000',
          tax_rate: '10.000',
          tax_amount: '100.000',
          discount_amount: '0.000',
          modifiers: null,
        },
      ],
      vat_details: [],
      payments: [
        {
          id: 'pay-1',
          payment_method_id: 'pm-1',
          payment_type: 'cash',
          amount: '1100.000',
          payment_method: { id: 'pm-1', name: 'Cash', code: 'CASH' },
        },
      ],
    });
    const result = buildEscPosReceiptData(receipt);

    expect(result.subtotal).toBe('1000');
    expect(result.tax_amount).toBe('100');
    expect(result.total).toBe('1100');
    expect(result.payments[0]!.amount).toBe('1100');
    expect(result.change_due).toBe('0');
    expect(result.lines[0]!.unit_price).toBe('1000');
    expect(result.lines[0]!.line_total).toBe('1000');
  });

  it('leaves tolerance_writeoff null when receipt has no tolerance', () => {
    const receipt = makeStorageScaleReceipt('EUR', { tolerance_writeoff: null });
    const result = buildEscPosReceiptData(receipt);
    expect(result.tolerance_writeoff).toBeNull();
  });
});
