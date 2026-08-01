import { describe, expect, it } from 'vitest';
import {
  RETURN_LINE_DISPOSITIONS,
  RefundLineNotAReturnError,
  RefundPaymentNotSingleCashLegError,
  WholeReceiptDiscountRefundRefusedError,
  buildRefundReceiptV4Payload,
  type BuildRefundReceiptV4PayloadInput,
  type RefundLineInput,
} from '../RefundReceiptV4Payload';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import { CASH_ORIGINAL_PAYMENTS } from '@/lib/fiscal/payloads/__tests__/helpers/originalFiscalEventFixtures';
import type { CartItem } from '@/types/cart';

/**
 * v3-refund-chain-integration spec §3.2's normalization proof + §3.3's
 * parallel-array assertions + §3.5's discount-refusal cases.
 * §3.7's training-original refusal has its own dedicated file,
 * `RefundReceiptV4Payload.trainingRefusal.test.ts`.
 */

function originalView(overrides: Partial<OriginalFiscalEventLocalView> = {}): OriginalFiscalEventLocalView {
  return {
    fiscalEventId: '99999999-9999-4999-8999-999999999999',
    businessDate: '2026-05-19',
    lineItems: [],
    payments: CASH_ORIGINAL_PAYMENTS,
    trainingFlag: false,
    transactionDiscountAmount: '0.00',
    ...overrides,
  };
}

/** A single-line negative-signed return CartItem, discounted, matching
 *  hydrateFromReceipt.ts's exact output shape (negative quantity/
 *  line_total/tax_amount; positive unit_price; discount fields carried
 *  through unnegated). */
function returnCartItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 'return-line-0',
    product: { id: 'prod-1', name: 'Widget', sku: 'WGT-1', price: '12.00' },
    quantity: -1,
    unit_price: '12.00',
    line_total: '-11.00',
    tax_rate: '0.00',
    tax_amount: '-0.00',
    discount_amount: '1.00',
    discount_reason: 'loyalty discount',
    kind: 'return',
    ...overrides,
  };
}

function baseInput(overrides: Partial<BuildRefundReceiptV4PayloadInput> = {}): BuildRefundReceiptV4PayloadInput {
  const lines: RefundLineInput[] = [
    { cartItem: returnCartItem(), originalLineIndex: 0, disposition: 'restock' },
  ];
  return {
    receiptId: '00000000-0000-4000-8000-000000000016',
    terminalId: '33333333-3333-4333-8333-333333333333',
    operatorId: '11111111-1111-4111-8111-111111111111',
    operatorName: 'Default Cashier',
    shiftId: '22222222-2222-4222-8222-222222222222',
    currency: 'EUR',
    eventTimeDevice: new Date('2026-05-20T14:30:00.000Z'),
    businessDate: '2026-05-20',
    lines,
    payment: { methodCode: 'CASH', amount: '11.00' },
    seller: {
      name: 'Default Seller S.A.',
      taxNumber: '12345678901234',
      countryCode: 'FR',
      street: '1 rue de la Paix',
      city: 'Paris',
      postalCode: '75001',
    },
    refundReason: 'customer return',
    original: originalView(),
    originalReceiptUuid: '00000000-0000-4000-8000-000000000001',
    originalBusinessDate: '2026-05-19',
    ...overrides,
  };
}

describe('buildRefundReceiptV4Payload — §3.2 normalization', () => {
  it('normalizes the negative-signed return cart to positive magnitudes and composes v4 fields', () => {
    const payload = buildRefundReceiptV4Payload(baseInput());

    expect(payload.invoice_type_code).toBe('REFUND');
    expect(payload.line_items).toHaveLength(1);

    const line = payload.line_items[0];
    expect(line?.quantity).toBe('1.000'); // positive, canonical 3dp
    expect(line?.unit_price).toBe('12.00'); // unit_price is already positive, unchanged
    expect(line?.line_discount_amount).toBe('1.00'); // discount passed through unchanged, no bcabs
    expect(line?.line_discount_reason).toBe('loyalty discount');
    expect(line?.line_subtotal).toBe('11.00'); // positive net (0% VAT in this fixture)
    expect(line?.line_vat).toBe('0.00');

    // No negative money anywhere in the signed payload (spec §3.1).
    expect(payload.subtotal).toBe('11.00');
    expect(payload.total).toBe('11.00');
    expect(payload.vat_total).toBe('0.00');
    expect(payload.transaction_discount_amount).toBe('0.00'); // §3.5 structural zero

    // §3.4
    expect(payload.refund_destination).toBe('cash');
    expect(payload.settlement_allocation).toBeNull();

    // §3.3 — strict parallel-array positional alignment.
    expect(payload.original_line_references).toHaveLength(1);
    const ref = payload.original_line_references[0];
    expect(ref?.original_line_index).toBe(0);
    expect(ref?.disposition).toBe('restock');
    expect(ref?.product_id).toBe(line?.product_id);
    expect(ref?.quantity).toBe(line?.quantity);

    expect(payload.original_receipt_reference).toEqual({
      fiscal_event_id: '99999999-9999-4999-8999-999999999999',
      original_business_date: '2026-05-19',
      original_receipt_uuid: '00000000-0000-4000-8000-000000000001',
      refund_reason: 'customer return',
    });

    // §3.7 single cash leg.
    expect(payload.payments).toHaveLength(1);
    expect(payload.payments[0]?.method_code).toBe('CASH');
    expect(payload.payments[0]?.instrument_type).toBeNull();
  });

  it('normalizes a multi-line cart preserving strict positional alignment', () => {
    const lines: RefundLineInput[] = [
      {
        cartItem: returnCartItem({
          id: 'return-line-0',
          product: { id: 'prod-1', name: 'Widget', sku: 'WGT-1', price: '5.00' },
          quantity: -2,
          unit_price: '5.00',
          line_total: '-10.00',
          tax_amount: '-0.00',
          discount_amount: undefined,
          discount_reason: undefined,
        }),
        originalLineIndex: 0,
        disposition: 'restock',
      },
      {
        cartItem: returnCartItem({
          id: 'return-line-1',
          product: { id: 'prod-2', name: 'Gadget', sku: 'GDG-1', price: '3.00' },
          quantity: -1,
          unit_price: '3.00',
          line_total: '-3.00',
          tax_amount: '-0.00',
          discount_amount: undefined,
          discount_reason: undefined,
        }),
        originalLineIndex: 1,
        disposition: 'scrap',
      },
    ];
    const payload = buildRefundReceiptV4Payload(
      baseInput({ lines, payment: { methodCode: 'CASH', amount: '13.00' } }),
    );

    expect(payload.line_items).toHaveLength(2);
    expect(payload.original_line_references).toHaveLength(2);
    expect(payload.line_items[0]?.quantity).toBe('2.000');
    expect(payload.line_items[1]?.quantity).toBe('1.000');
    expect(payload.original_line_references[1]?.original_line_index).toBe(1);
    expect(payload.original_line_references[1]?.disposition).toBe('scrap');
    expect(payload.original_line_references[1]?.product_id).toBe('prod-2');
    expect(payload.subtotal).toBe('13.00');
    expect(payload.total).toBe('13.00');
  });

  it('rejects a v4 payload it built for itself if a line is not kind=return (defense-in-depth)', () => {
    const input = baseInput({
      lines: [{ cartItem: returnCartItem({ kind: 'sale' }), originalLineIndex: 0, disposition: 'restock' }],
    });
    expect(() => buildRefundReceiptV4Payload(input)).toThrow(RefundLineNotAReturnError);
  });

  it('§3.5 — refuses a refund whose original transaction_discount_amount is non-zero', () => {
    const input = baseInput({
      original: originalView({ transactionDiscountAmount: '2.00' }),
    });
    expect(() => buildRefundReceiptV4Payload(input)).toThrow(WholeReceiptDiscountRefundRefusedError);
  });

  it('§3.5 — does not refuse when the original transaction_discount_amount is canonical zero', () => {
    const input = baseInput({ original: originalView({ transactionDiscountAmount: '0.00' }) });
    expect(() => buildRefundReceiptV4Payload(input)).not.toThrow();
  });

  it('§3.7 — rejects a payment whose method_code is not CASH', () => {
    const input = baseInput({ payment: { methodCode: 'VOUCHER', amount: '11.00' } });
    expect(() => buildRefundReceiptV4Payload(input)).toThrow(RefundPaymentNotSingleCashLegError);
  });

  it('§3.7 — rejects a payment carrying a non-null instrumentType', () => {
    const input = baseInput({
      payment: { methodCode: 'CASH', amount: '11.00', instrumentType: 'gift_card' },
    });
    expect(() => buildRefundReceiptV4Payload(input)).toThrow(RefundPaymentNotSingleCashLegError);
  });

  it('RETURN_LINE_DISPOSITIONS mirrors the PHP ReturnLineDisposition enum verbatim', () => {
    expect([...RETURN_LINE_DISPOSITIONS]).toEqual(['restock', 'scrap', 'not_received']);
  });
});
