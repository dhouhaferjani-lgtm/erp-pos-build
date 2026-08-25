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

import {
  buildEscPosAccountPaymentReceiptData,
  buildEscPosAccountChargeReceiptData,
  buildEscPosReceiptData,
} from '../buildReceiptData';
import type { AccountChargePrintable } from '@/lib/accountCharge/accountChargePrintable';
import type { FullReceiptResponse } from '@/types/receipt';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';
import { goldenAccountPaymentPayload } from '@/lib/fiscal/payloads/AccountPaymentPayload';

/**
 * Minimal but valid CheckoutResult fixture for offline-receipt tests.
 * Phase 1 Task 27 Pass 1 (spec §14.3): `onlineReceipt` / `onlinePayment` are
 * gone from `CheckoutResult` — the device authors every sale locally now,
 * so there's no online response payload to carry. `isOffline` is retained
 * but its meaning shifted: it now describes the SYNC posture (true when
 * the device was offline at checkout time and the immediate flush was
 * skipped), not the authoring path (which is always local).
 */
function makeOfflineCheckoutResult(overrides: Partial<CheckoutResult> = {}): CheckoutResult {
  return {
    isOffline: true,
    receiptId: 'offline-rcpt-001',
    receiptNumber: 'R-T1-2026-00000001',
    total: '20.00',
    subtotal: '20.00',
    taxAmount: '0.00',
    discountAmount: '0.00',
    vatBreakdown: [],
    changeDue: '0.00',
    currency: 'EUR',
    // fiscalHash is optional (string | undefined)
    ...overrides,
  };
}

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
        product_id: null,
        composite_item_id: null,
        menu_category_id: null,
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

describe('buildEscPosReceiptData — cash rounding (spec §4.3, Task 10)', () => {
  it('formats cash_rounding_adjustment to currency display scale and keeps the sign', () => {
    const receipt = makeReceipt({ currency: 'EUR', cash_rounding_adjustment: '-0.020' });

    const result = buildEscPosReceiptData(receipt);

    expect(result.cash_rounding_adjustment).toBe('-0.02');
    expect(result.has_cash_rounding).toBe(true);
  });

  it('keeps a 3-decimal adjustment intact for a 3-decimal currency', () => {
    const receipt = makeReceipt({ currency: 'TND', cash_rounding_adjustment: '-0.020' });

    const result = buildEscPosReceiptData(receipt);

    expect(result.cash_rounding_adjustment).toBe('-0.020');
  });

  it.each([
    ['0.020', 'positive adjustment (rounded up)'],
    ['-0.020', 'negative adjustment (rounded down)'],
    ['0.001', 'sub-cent adjustment'],
  ])('sets has_cash_rounding=true for %s (%s)', (adjustment, _label) => {
    const receipt = makeReceipt({ currency: 'TND', cash_rounding_adjustment: adjustment });

    expect(buildEscPosReceiptData(receipt).has_cash_rounding).toBe(true);
  });

  it.each([
    ['0', 'literal zero'],
    ['0.000', 'zero at scale 3'],
    ['', 'empty string'],
    [null, 'null'],
    [undefined, 'absent (legacy receipt)'],
  ])('sets has_cash_rounding=false for %s (%s)', (adjustment, _label) => {
    const receipt = makeReceipt({ cash_rounding_adjustment: adjustment });

    const result = buildEscPosReceiptData(receipt);

    expect(result.has_cash_rounding).toBe(false);
    expect(result.cash_rounding_adjustment).toBeNull();
  });

  it('exposes a tolerance label distinct from the rounding label (two different lines)', () => {
    const result = buildEscPosReceiptData(makeReceipt());

    expect(result.labels?.rounding).toBe('pos:receiptLabel.rounding');
    expect(result.labels?.tolerance).toBe('pos:receiptLabel.tolerance');
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
    const result = buildEscPosReceiptData(receipt, { isReprint: true });
    expect(result.is_reprint).toBe(true);
  });

  it('leaves is_reprint undefined by default', () => {
    const receipt = makeReceipt();
    const result = buildEscPosReceiptData(receipt);
    expect(result.is_reprint).toBeUndefined();
  });

  it('keeps blank company tax_id as an empty string for Rust-side omission', () => {
    const receipt = makeReceipt({
      company: {
        ...makeReceipt().company,
        tax_id: null,
      },
    });

    const result = buildEscPosReceiptData(receipt);

    expect(result.company.tax_id).toBe('');
  });
});

// ── Atomic seller identity on the printed header (spec 2026-06-11 §4.6) ──────

describe('buildEscPosReceiptData — branch identity header', () => {
  const companyBlock = {
    name: 'Test Shop',
    address_street: '1 Rue Compagnie',
    address_street_2: 'Bâtiment B',
    address_city: 'Paris',
    address_postal_code: '75001',
    country_code: 'FR',
    tax_id: 'COMPANY-FR-TAX',
    phone: null,
  };

  const completeLocation = {
    tax_id: 'BRANCH-FR-TAX',
    vat_number: 'FRBRANCHVAT',
    legal_identifiers: { siret: '55210055400014' },
    address_street: '9 Rue Succursale',
    address_city: 'Lyon',
    address_postal_code: '69001',
    address_country: 'fr',
  };

  it('prints the FULL branch identity (tax_id + address + vat_number + legal identifiers) when the location is fiscally complete', () => {
    const receipt = makeReceipt({ company: companyBlock });

    const result = buildEscPosReceiptData(receipt, { sellerLocation: completeLocation });

    expect(result.company.tax_id).toBe('BRANCH-FR-TAX');
    expect(result.company.address_line1).toBe('9 Rue Succursale');
    expect(result.company.address_line2).toBeNull();
    expect(result.company.city).toBe('Lyon');
    expect(result.company.postal_code).toBe('69001');
    expect(result.company.country).toBe('FR');
    expect(result.company.vat_number).toBe('FRBRANCHVAT');
    expect(result.company.legal_identifier_lines).toEqual(['SIRET: 55210055400014']);
    // The name stays the company name — an establishment shares the registered name.
    expect(result.company.name).toBe('Test Shop');
  });

  it('prints the WHOLESALE company header when the location has a tax_id but no address (atomic — no mixing)', () => {
    const receipt = makeReceipt({ company: companyBlock });

    const result = buildEscPosReceiptData(receipt, {
      sellerLocation: {
        ...completeLocation,
        address_street: null,
        address_city: null,
        address_postal_code: null,
        address_country: null,
      },
    });

    expect(result.company.tax_id).toBe('COMPANY-FR-TAX');
    expect(result.company.address_line1).toBe('1 Rue Compagnie');
    expect(result.company.address_line2).toBe('Bâtiment B');
    expect(result.company.city).toBe('Paris');
    expect(result.company.postal_code).toBe('75001');
    expect(result.company.country).toBe('FR');
    expect(result.company.vat_number).toBeNull();
    expect(result.company.legal_identifier_lines).toBeNull();
  });

  it('prints the company header when no location is provided (back-compat)', () => {
    const receipt = makeReceipt({ company: companyBlock });

    const result = buildEscPosReceiptData(receipt);

    expect(result.company.tax_id).toBe('COMPANY-FR-TAX');
    expect(result.company.address_line1).toBe('1 Rue Compagnie');
    expect(result.company.vat_number).toBeNull();
    expect(result.company.legal_identifier_lines).toBeNull();
  });
});

// ── Phase H Block 2 — receipt QR token + refund metadata ─────────────────────

describe('buildEscPosReceiptData — Phase H Block 2 (sale QR / refund header / cross-refs)', () => {
  it('emits qr_token on a sale receipt when one is provided', () => {
    const receipt = makeReceipt();
    const token =
      'v:k1:c0ffee00-1111-2222-3333-444455556666:f00dbeefcafe1234abcd5678fedcba98';

    const result = buildEscPosReceiptData(receipt, { extras: { qrToken: token } });

    expect(result.qr_token).toBe(token);
    expect(result.receipt_kind).toBe('sale');
    expect(result.original_receipt_number).toBeNull();
    expect(result.original_receipt_qr_token).toBeNull();
  });

  it('emits qr_token = null on a sale receipt when none is provided (back-compat)', () => {
    const receipt = makeReceipt();
    const result = buildEscPosReceiptData(receipt);

    expect(result.qr_token).toBeNull();
    expect(result.receipt_kind).toBe('sale');
  });

  it('infers receipt_kind = "refund" from receipt_type === "return" and surfaces all refund cross-refs', () => {
    const refundToken =
      'v:k1:c0ffee00-1111-2222-3333-444455556666:f00dbeefcafe1234abcd5678fedcba98';
    const originalToken =
      'v:k1:abadcafe-aaaa-bbbb-cccc-dddddddddddd:1234567890abcdef1234567890abcdef';

    const receipt = makeReceipt({
      receipt_type: 'return',
      total: '-10.00',
      subtotal: '-10.00',
    });

    const result = buildEscPosReceiptData(receipt, {
      extras: {
        qrToken: refundToken,
        originalReceiptNumber: 'R-T1-2026-00000123',
        originalReceiptQrToken: originalToken,
      },
    });

    expect(result.receipt_kind).toBe('refund');
    expect(result.qr_token).toBe(refundToken);
    expect(result.original_receipt_number).toBe('R-T1-2026-00000123');
    expect(result.original_receipt_qr_token).toBe(originalToken);
  });

  it('honours an explicit receiptKind override even when receipt_type says "sale"', () => {
    const receipt = makeReceipt({ receipt_type: 'sale' });

    const result = buildEscPosReceiptData(receipt, {
      extras: {
        receiptKind: 'refund',
        originalReceiptNumber: 'R-T1-2026-00000999',
      },
    });

    expect(result.receipt_kind).toBe('refund');
    expect(result.original_receipt_number).toBe('R-T1-2026-00000999');
  });

  it('round-trips a v:kid:uuid:mac token through parseReceiptUuidFromQrToken', async () => {
    // The QR token format is the contract between the backend signer and the
    // local receipt_qr_index. Verify the canonical 4-segment shape we feed
    // into the printer parses back to the same UUID.
    const { parseReceiptUuidFromQrToken } = await import(
      '@/lib/offline/voucherRepository'
    );
    const uuid = 'c0ffee00-1111-2222-3333-444455556666';
    const token = `v:k1:${uuid}:f00dbeefcafe1234abcd5678fedcba98`;

    const receipt = makeReceipt();
    const result = buildEscPosReceiptData(receipt, { extras: { qrToken: token } });

    expect(result.qr_token).toBe(token);
    expect(parseReceiptUuidFromQrToken(token)).toBe(uuid);
  });

  it('strips qr_token from buildEscPosFromOfflineReceipt when no extras are passed', async () => {
    const { buildEscPosFromOfflineReceipt } = await import('../buildReceiptData');

    const result = buildEscPosFromOfflineReceipt(
      makeOfflineCheckoutResult(),
      [],
      'Acme',
      'T1',
      'Alice',
      'Cash',
    );

    expect(result.qr_token).toBeNull();
    expect(result.receipt_kind).toBe('sale');
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
          product_id: null,
          composite_item_id: null,
          menu_category_id: null,
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

    // D-1 gate r1 finding 8: this fixture's vat_details carry no
    // `discount_allocated`, i.e. a PRE-D-1 receipt, so a reprint stays
    // byte-faithful and prints `receipts.subtotal` verbatim. The gross-TTC
    // derivation applies only to post-remise receipts (pinned below).
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

    // Pre-D-1 reprint — see the EUR case above.
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

/**
   * D-1 gate r1 finding 8 — the era decides which `Subtotal` the ticket prints.
   *
   * On a POST-remise receipt `receipts.subtotal` is the taxable base, so the
   * ticket prints the GROSS (TTC) before the remise and `Subtotal − Remise`
   * lands on TOTAL. On a pre-D-1 receipt it was the pre-discount NET, and a
   * reprint must stay byte-faithful to what the customer was handed.
   */
  it('prints the gross TTC subtotal on a POST-remise receipt, and the stored net on a pre-D-1 one', () => {
    const base = makeStorageScaleReceipt('TND');

    const preD1 = buildEscPosReceiptData(base);
    expect(preD1.subtotal).toBe('10.000');

    const postRemise = buildEscPosReceiptData({
      ...base,
      discount_amount: '2.000',
      total: '9.900',
      vat_details: base.vat_details.map((v) => ({ ...v, discount_allocated: '2.000' })),
    });
    // total + discount = 11.900 gross TTC, and 11.900 − 2.000 = 9.900 = TOTAL.
    expect(postRemise.subtotal).toBe('11.900');
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
          product_id: null,
          composite_item_id: null,
          menu_category_id: null,
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

    // Pre-D-1 reprint — see the EUR case above.
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

  it('maps sealed ACCOUNT_PAYMENT metadata into printable receipt data', () => {
    const payload = goldenAccountPaymentPayload();
    payload.training_flag = true;
    payload.customer = {
      ...payload.customer,
      phone: '+21611111111',
    };
    const result = buildEscPosAccountPaymentReceiptData({
      payload,
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      fiscalHash: 'b'.repeat(64),
      terminalName: 'Front T1',
    });

    expect(result.receipt_kind).toBe('account_payment');
    expect(result.business_date).toBe(payload.business_date);
    expect(result.terminal_id).toBe(payload.terminal_id);
    expect(result.shift_id).toBe(payload.shift_id);
    expect(result.training_flag).toBe(true);
    expect(result.customer_account_id).toBe(payload.customer.customer_id);
    expect(result.customer_phone).toBe('+21611111111');
    expect(result.account_balance_before).toBe('300.000');
    expect(result.account_balance_after).toBe('200.000');
  });
});

// ── Task 2: buildEscPosAccountChargeReceiptData ───────────────────────────────

function makeAccountChargePrintable(
  overrides: Partial<AccountChargePrintable> = {},
): AccountChargePrintable {
  return {
    title: 'ACCOUNT CHARGE RECEIPT',
    accountChargeUuid: '66666666-6666-4666-8666-666666666666',
    fiscalEventId: 'fe-1',
    fiscalHash: 'hash-1',
    terminalName: 'Till 1',
    terminalId: 't',
    shiftId: 's',
    cashierName: 'Sam',
    businessDate: '2026-06-04',
    eventTimeDevice: '2026-06-04T10:15:30.000Z',
    sellerName: 'Default Seller',
    sellerTaxNumber: '1234567AM000',
    sellerAddress: '1 rue, Tunis',
    customerName: 'Mariam',
    customerPhone: '+21611111111',
    customerCategory: 'individual',
    accountIdentifier: 'CUST-0001',
    amountChargedToAccount: '119.000',
    chargeAmount: '119.000',
    subtotal: '100.000',
    vatTotal: '19.000',
    balanceBefore: '300.000',
    balanceAfter: '419.000',
    creditLimit: '500.000',
    creditAvailableBefore: '200.000',
    creditAvailableAfter: '81.000',
    dueDate: '2026-07-04',
    termsLabel: 'Net 30',
    customerSnapshotStale: false,
    balanceSnapshotStale: false,
    stalenessReason: null,
    trainingFlag: false,
    lines: [
      {
        name: 'Widget',
        quantity: '1.000',
        unitPrice: '119.000',
        lineSubtotal: '100.000',
        lineVat: '19.000',
        lineTotal: '119.000',
      },
    ],
    vatBreakdown: [
      {
        rate: '19.00',
        taxCategoryCode: '',
        netAmount: '100.000',
        vatAmount: '19.000',
        grossAmount: '119.000',
      },
    ],
    ...overrides,
  };
}

describe('buildEscPosAccountChargeReceiptData', () => {
  it('renders charge totals with no payment lines', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable(),
      currencyCode: 'TND',
    });
    expect(data.receipt_number).toBe('66666666-6666-4666-8666-666666666666');
    expect(data.total).toBe('119.000');
    expect(data.payments).toEqual([]);
    expect(data.lines).toHaveLength(1);
    expect(data.company.name).toBe('Default Seller');
  });

  it('maps subtotal and tax_amount from printable', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable(),
      currencyCode: 'TND',
    });
    expect(data.subtotal).toBe('100.000');
    expect(data.tax_amount).toBe('19.000');
    expect(data.discount_amount).toBe('0.000');
  });

  it('maps line fields to ReceiptLine shape', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable(),
      currencyCode: 'TND',
    });
    const line = data.lines[0];
    expect(line).toBeDefined();
    expect(line!.name).toBe('Widget');
    expect(line!.quantity).toBe('1.000');
    expect(line!.unit_price).toBe('119.000');
    expect(line!.line_total).toBe('119.000');
  });

  it('maps vat_breakdown to VatBreakdownLine shape (rate / taxable / tax)', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable(),
      currencyCode: 'TND',
    });
    const vat = data.vat_breakdown[0];
    expect(vat).toBeDefined();
    expect(vat!.rate).toBe('19.00');
    expect(vat!.taxable).toBe('100.000');
    expect(vat!.tax).toBe('19.000');
  });

  it('sets receipt_kind to account_charge', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable(),
      currencyCode: 'TND',
    });
    expect(data.receipt_kind).toBe('account_charge');
  });

  it('carries account balance and staleness fields', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable(),
      currencyCode: 'TND',
    });
    expect(data.account_balance_before).toBe('300.000');
    expect(data.account_balance_after).toBe('419.000');
    expect(data.account_snapshot_stale).toBe(false);
  });

  it('formats EUR amounts to 2 decimal places', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable({
        amountChargedToAccount: '119.000',
        subtotal: '100.000',
        vatTotal: '19.000',
        balanceBefore: '300.000',
        balanceAfter: '419.000',
      }),
      currencyCode: 'EUR',
    });
    expect(data.total).toBe('119.00');
    expect(data.subtotal).toBe('100.00');
    expect(data.tax_amount).toBe('19.00');
    expect(data.account_balance_before).toBe('300.00');
    expect(data.account_balance_after).toBe('419.00');
  });

  it('forwards accountIdentifier as customer_account_id', () => {
    // The human-readable account code (e.g. 'CUST-0001') must appear in the
    // receipt data so the Rust formatter can print it in the customer section.
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable({ accountIdentifier: 'CUST-0001' }),
      currencyCode: 'TND',
    });
    expect(data.customer_account_id).toBe('CUST-0001');
  });

  it('sets customer_account_id to null when accountIdentifier is null', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable({ accountIdentifier: null }),
      currencyCode: 'TND',
    });
    expect(data.customer_account_id).toBeNull();
  });
});
