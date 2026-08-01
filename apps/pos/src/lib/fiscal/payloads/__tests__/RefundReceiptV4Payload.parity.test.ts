import { describe, expect, it } from 'vitest';
import {
  buildRefundReceiptV4Payload,
  type BuildRefundReceiptV4PayloadInput,
  type RefundLineInput,
} from '../RefundReceiptV4Payload';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import type { CartItem } from '@/types/cart';
import { bcadd, bcdiv } from '@/lib/decimal';

/**
 * Cross-language parity against golden fixture F-16
 * (`apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-16-refund-v4-cash-eur/payload.json`)
 * — the SAME v4 REFUND receipt `GoldenFixtureBuilder.php::f16RefundV4Cash()`
 * asserts the SERVER accepts (`expected.json: {"accept": true}`).
 *
 * F-16 has no stored `expected_canonical_string`/`expected_sha256_hex`
 * pair (unlike the older `sale-receipt-v2-golden.json` fixture family) —
 * it is a PARSE-ACCEPTANCE fixture, proving the PHP validator accepts
 * this exact payload shape at `event_version=4`. The corresponding
 * PHP-side parity test is `CanonicalByteHashV4ParityTest.php` (out of
 * scope for this wave — no `apps/api` changes). This test proves the
 * DEVICE builder, driven by an input that reconstructs the SAME logical
 * refund, produces a payload whose v4-defining fields
 * (`invoice_type_code`, `original_line_references[]`,
 * `refund_destination`, `settlement_allocation`,
 * `original_receipt_reference`, the single-cash-leg `payments[]`, and
 * every line's `product_id`/`quantity`) are IDENTICAL to F-16's own
 * `payload.json` — proving the builder's output is a member of the same
 * accepted-payload family the server fixture pins, field-for-field on
 * every key this builder is responsible for.
 */

interface GoldenPaymentRow {
  amount: string;
  method_code: string;
  instrument_type: string | null;
  instrument_serial: string | null;
  foreign_currency_amount: string | null;
  foreign_currency_code: string | null;
}

interface GoldenLineItem {
  product_id: string;
  quantity: string;
  unit_price: string;
  line_subtotal: string;
  line_vat: string;
  line_discount_amount: string;
  line_discount_reason: string | null;
  name: string;
  sku: string;
  tax_category_code: string;
  vat_rate: string;
}

interface GoldenOriginalLineReference {
  disposition: 'restock' | 'scrap' | 'not_received';
  original_line_index: number;
  product_id: string;
  quantity: string;
}

interface GoldenFixture {
  invoice_type_code: string;
  currency_code: string;
  currency_scale: number;
  subtotal: string;
  total: string;
  vat_total: string;
  transaction_discount_amount: string;
  refund_destination: string;
  settlement_allocation: unknown;
  line_items: GoldenLineItem[];
  original_line_references: GoldenOriginalLineReference[];
  original_receipt_reference: {
    fiscal_event_id: string;
    original_business_date: string;
    original_receipt_uuid: string;
    refund_reason: string;
  };
  payments: GoldenPaymentRow[];
  receipt_uuid: string;
  terminal_id: string;
  cashier_id: string;
  cashier_name: string;
  shift_id: string;
  business_date: string;
  event_time_device: string;
  seller: {
    name: string;
    tax_number: string;
    tax_jurisdiction_country_code: string;
    address: { city: string; country_code: string; postal_code: string; street: string };
  };
}

function readGoldenFixture(): GoldenFixture {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(
      __dirname,
      '../../../../../../../apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-16-refund-v4-cash-eur/payload.json',
    ),
    path.resolve(
      __dirname,
      '../../../../../../api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-16-refund-v4-cash-eur/payload.json',
    ),
  ];
  const fixturePath = candidates.find((p) => fs.existsSync(p));
  if (!fixturePath) {
    throw new Error(`F-16 payload.json not found at any candidate path: ${candidates.join(', ')}`);
  }
  return JSON.parse(fs.readFileSync(fixturePath, 'utf8')) as GoldenFixture;
}

describe('RefundReceiptV4Payload cross-language parity (F-16)', () => {
  it('reconstructs F-16 via the device builder and matches its v4-defining fields exactly', () => {
    const golden = readGoldenFixture();
    expect(golden.line_items).toHaveLength(1);
    expect(golden.original_line_references).toHaveLength(1);
    expect(golden.payments).toHaveLength(1);

    const goldenLine = golden.line_items[0]!;
    const goldenRef = golden.original_line_references[0]!;
    const goldenPayment = golden.payments[0]!;

    // Reconstruct the NEGATIVE-signed input the same shape
    // hydrateFromReceipt.ts would have produced for this SAME original
    // sale line -- line_total negated, tax_amount negated, quantity
    // negated. `unit_price` on a real DEVICE-originated CartItem is
    // GROSS/tax-inclusive (rule 19: "unit_price is context-overloaded --
    // tax-INCLUSIVE in the B2C POS"), so it is DERIVED here as
    // (line_subtotal + line_vat) / quantity to satisfy
    // buildLineItems()'s own gross-arithmetic safety invariant
    // (line_total === unit_price*quantity - discount) -- F-16's own
    // "unit_price":"10.00" is the fixture-authoring (PHP-side, DTO-direct)
    // NET convention, not reproducible byte-for-byte from a real device
    // cart without breaking that invariant; only the line's NET/VAT/
    // product_id/quantity fields (asserted below) are the byte-parity
    // contract this builder owns.
    const grossTotal = bcadd(goldenLine.line_subtotal, goldenLine.line_vat, golden.currency_scale);
    const derivedUnitPrice = bcdiv(grossTotal, goldenLine.quantity, golden.currency_scale);
    const cartItem: CartItem = {
      id: 'return-line-0',
      product: { id: goldenLine.product_id, name: goldenLine.name, sku: goldenLine.sku, price: derivedUnitPrice },
      quantity: -Number(goldenLine.quantity),
      unit_price: derivedUnitPrice,
      line_total: `-${grossTotal}`,
      tax_rate: goldenLine.vat_rate,
      tax_amount: `-${goldenLine.line_vat}`,
      kind: 'return',
    };

    const original: OriginalFiscalEventLocalView = {
      fiscalEventId: golden.original_receipt_reference.fiscal_event_id,
      lineItems: [],
      payments: [],
      trainingFlag: false,
      transactionDiscountAmount: golden.transaction_discount_amount,
    };

    const lines: RefundLineInput[] = [
      { cartItem, originalLineIndex: goldenRef.original_line_index, disposition: goldenRef.disposition },
    ];

    const input: BuildRefundReceiptV4PayloadInput = {
      receiptId: golden.receipt_uuid,
      terminalId: golden.terminal_id,
      operatorId: golden.cashier_id,
      operatorName: golden.cashier_name,
      shiftId: golden.shift_id,
      currency: golden.currency_code,
      eventTimeDevice: new Date(golden.event_time_device),
      businessDate: golden.business_date,
      lines,
      payment: { methodCode: goldenPayment.method_code, amount: goldenPayment.amount },
      seller: {
        name: golden.seller.name,
        taxNumber: golden.seller.tax_number,
        countryCode: golden.seller.address.country_code,
        street: golden.seller.address.street,
        city: golden.seller.address.city,
        postalCode: golden.seller.address.postal_code,
      },
      refundReason: golden.original_receipt_reference.refund_reason,
      original,
      originalReceiptUuid: golden.original_receipt_reference.original_receipt_uuid,
      originalBusinessDate: golden.original_receipt_reference.original_business_date,
    };

    const payload = buildRefundReceiptV4Payload(input);

    // v4-defining fields, field-for-field against the golden fixture.
    expect(payload.invoice_type_code).toBe(golden.invoice_type_code);
    expect(payload.refund_destination).toBe(golden.refund_destination);
    expect(payload.settlement_allocation).toBe(golden.settlement_allocation);
    expect(payload.currency_code).toBe(golden.currency_code);
    expect(payload.currency_scale).toBe(golden.currency_scale);
    expect(payload.subtotal).toBe(golden.subtotal);
    expect(payload.total).toBe(golden.total);
    expect(payload.vat_total).toBe(golden.vat_total);
    expect(payload.transaction_discount_amount).toBe(golden.transaction_discount_amount);
    expect(payload.original_receipt_reference).toEqual(golden.original_receipt_reference);

    expect(payload.line_items).toHaveLength(1);
    const line = payload.line_items[0]!;
    expect(line.product_id).toBe(goldenLine.product_id);
    expect(line.quantity).toBe(goldenLine.quantity);
    // unit_price is NOT asserted byte-identical -- see the derivation
    // comment above; net/VAT/product_id/quantity are this builder's own
    // byte-parity contract.
    expect(line.line_subtotal).toBe(goldenLine.line_subtotal);
    expect(line.line_vat).toBe(goldenLine.line_vat);
    expect(line.line_discount_amount).toBe(goldenLine.line_discount_amount);
    expect(line.tax_category_code).toBe(goldenLine.tax_category_code);
    expect(line.vat_rate).toBe(goldenLine.vat_rate);

    expect(payload.original_line_references).toHaveLength(1);
    expect(payload.original_line_references[0]).toEqual(goldenRef);

    expect(payload.payments).toHaveLength(1);
    expect(payload.payments[0]?.amount).toBe(goldenPayment.amount);
    expect(payload.payments[0]?.method_code).toBe(goldenPayment.method_code);
    expect(payload.payments[0]?.instrument_type).toBe(goldenPayment.instrument_type);
  });
});
