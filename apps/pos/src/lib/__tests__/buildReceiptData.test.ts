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
  it('passes tolerance_writeoff through unchanged when set on the receipt', () => {
    const receipt = makeReceipt({
      tolerance_writeoff: '0.020',
    });

    const result = buildEscPosReceiptData(receipt);

    expect(result.tolerance_writeoff).toBe('0.020');
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
