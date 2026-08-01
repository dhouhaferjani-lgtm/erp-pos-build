import { describe, expect, it } from 'vitest';
import {
  TrainingOriginalRefundRefusedError,
  buildRefundReceiptV4Payload,
  type BuildRefundReceiptV4PayloadInput,
  type RefundLineInput,
} from '../RefundReceiptV4Payload';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import { CASH_ORIGINAL_PAYMENTS } from '@/lib/fiscal/payloads/__tests__/helpers/originalFiscalEventFixtures';
import type { CartItem } from '@/types/cart';

/**
 * v3-refund-chain-integration spec §3.7 errata T4 — the training-original
 * refusal reads `training_flag` from the RESOLVED ORIGINAL's own signed
 * `payload.training_flag` (via `OriginalFiscalEventLocalView.trainingFlag`)
 * -- NEVER from the refunding session's CURRENT training-mode context
 * (`PosOverrideContext.isTraining`, an unrelated concept). This file
 * proves both halves: a training original is refused regardless of the
 * CURRENT session's mode, and a non-training original is NOT incorrectly
 * refused when the current session happens to be in training mode (the
 * two concepts must never be conflated).
 */

function returnCartItem(): CartItem {
  return {
    id: 'return-line-0',
    product: { id: 'prod-1', name: 'Widget', sku: 'WGT-1', price: '12.00' },
    quantity: -1,
    unit_price: '12.00',
    line_total: '-12.00',
    tax_rate: '0.00',
    tax_amount: '-0.00',
    kind: 'return',
  };
}

function baseInput(original: OriginalFiscalEventLocalView): BuildRefundReceiptV4PayloadInput {
  const lines: RefundLineInput[] = [
    { cartItem: returnCartItem(), originalLineIndex: 0, disposition: 'restock', quantity: '1.000' },
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
    payment: { methodCode: 'CASH', amount: '12.00' },
    seller: {
      name: 'Default Seller S.A.',
      taxNumber: '12345678901234',
      countryCode: 'FR',
      street: '1 rue de la Paix',
      city: 'Paris',
      postalCode: '75001',
    },
    refundReason: 'customer return',
    original,
    originalReceiptUuid: '00000000-0000-4000-8000-000000000001',
    originalBusinessDate: '2026-05-19',
  };
}

describe('buildRefundReceiptV4Payload — §3.7 training-original refusal', () => {
  it('refuses a refund whose resolved original has payload.training_flag=true', () => {
    const original: OriginalFiscalEventLocalView = {
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      businessDate: '2026-05-19',
      lineItems: [],
      payments: CASH_ORIGINAL_PAYMENTS,
      trainingFlag: true,
      transactionDiscountAmount: '0.00',
    };

    expect(() => buildRefundReceiptV4Payload(baseInput(original))).toThrow(
      TrainingOriginalRefundRefusedError,
    );
  });

  it('refuses a training original even when nothing about the CURRENT session is training-flagged (this builder receives no session context at all)', () => {
    // This builder's input contract (BuildRefundReceiptV4PayloadInput)
    // carries no PosOverrideContext/session field whatsoever -- the ONLY
    // source of truth it can possibly read is `original.trainingFlag`,
    // structurally proving the two concepts cannot be conflated at this
    // layer: there is no current-session flag to accidentally read.
    const original: OriginalFiscalEventLocalView = {
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      businessDate: '2026-05-19',
      lineItems: [],
      payments: CASH_ORIGINAL_PAYMENTS,
      trainingFlag: true,
      transactionDiscountAmount: '0.00',
    };

    expect(() => buildRefundReceiptV4Payload(baseInput(original))).toThrow(
      /payload\.training_flag=true/,
    );
  });

  it('does NOT refuse a refund of a non-training original (the common case)', () => {
    const original: OriginalFiscalEventLocalView = {
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      businessDate: '2026-05-19',
      lineItems: [],
      payments: CASH_ORIGINAL_PAYMENTS,
      trainingFlag: false,
      transactionDiscountAmount: '0.00',
    };

    expect(() => buildRefundReceiptV4Payload(baseInput(original))).not.toThrow();
  });

  it('the refusal error carries a stable i18nKey for the UI layer', () => {
    const original: OriginalFiscalEventLocalView = {
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      businessDate: '2026-05-19',
      lineItems: [],
      payments: CASH_ORIGINAL_PAYMENTS,
      trainingFlag: true,
      transactionDiscountAmount: '0.00',
    };

    try {
      buildRefundReceiptV4Payload(baseInput(original));
      expect.unreachable('expected buildRefundReceiptV4Payload to throw');
    } catch (error) {
      expect(error).toBeInstanceOf(TrainingOriginalRefundRefusedError);
      expect((error as TrainingOriginalRefundRefusedError).i18nKey).toBe(
        'refundFlow.trainingOriginalRefused',
      );
    }
  });
});
