import { describe, expect, it } from 'vitest';
import { FiscalEventCanonicalEncoder } from '@/lib/fiscal/FiscalEventCanonicalEncoder';
import {
  buildRefundReceiptV4Payload,
  type BuildRefundReceiptV4PayloadInput,
  type RefundLineInput,
} from '../RefundReceiptV4Payload';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import type { CartItem } from '@/types/cart';

/**
 * Wave-2 review fix (FISCAL IMPORTANT) — the byte/hash golden EXISTS
 * (`apps/api/tests/Fixtures/Fiscal/sale-receipt-v4-refund-golden.json`,
 * wave 1) and carries `expected_canonical_string` +
 * `expected_sha256_hex`. Consumed here exactly like the established
 * `saleReceiptV2CanonicalParity.test.ts` pattern: build the payload,
 * encode via the SAME `FiscalEventCanonicalEncoder` the chain uses,
 * compare the canonical string and its SHA-256 to the committed golden
 * values.
 */

interface GoldenFixture {
  expected_canonical_string: string;
  expected_sha256_hex: string;
}

function readGoldenFixture(): GoldenFixture {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(__dirname, '../../../../../../../apps/api/tests/Fixtures/Fiscal/sale-receipt-v4-refund-golden.json'),
    path.resolve(__dirname, '../../../../../../api/tests/Fixtures/Fiscal/sale-receipt-v4-refund-golden.json'),
  ];
  const fixturePath = candidates.find((p) => fs.existsSync(p));
  if (!fixturePath) {
    throw new Error(`sale-receipt-v4-refund-golden.json not found at any candidate path: ${candidates.join(', ')}`);
  }
  return JSON.parse(fs.readFileSync(fixturePath, 'utf8')) as GoldenFixture;
}

/** Reconstructs the exact logical refund `sale-receipt-v4-refund-golden.json`
 *  encodes: one cash-tendered v4 refund citing original line 0
 *  (disposition=restock), 10.00 net / 2.00 VAT / 12.00 gross, EUR. */
function goldenInput(): BuildRefundReceiptV4PayloadInput {
  // Fix round 2 (see docs/sessions/LANE-C-wave2-fix-verify-report.md §7/§8)
  // — unit_price: '12.00' is the CORRECT device-side value per rule 19 /
  // docs/architecture/precision-contract.md (POS unit_price is GROSS/TTC,
  // written verbatim from the cart), matching the established
  // `sale-receipt-v2-golden.json` convention (its line 1 pins
  // unit_price:'25.00' == line_total, GROSS, not line_subtotal:'20.83'
  // NET) and required by `buildLineItems()`'s arithmetic invariant
  // (`line_total === unit_price × quantity − discount`).
  // `apps/api/tests/Helpers/Fiscal/GoldenFixtureBuilder.php::f16RefundV4Cash()`
  // previously pinned `unit_price: '10.00'` (NET) — the opposite
  // convention — a PHP-side fixture-authoring bug, now corrected there
  // (orchestrator-ruled fix round 2), with
  // `sale-receipt-v4-refund-golden.json`'s `expected_canonical_string`/
  // `expected_sha256_hex` regenerated to match via
  // `GoldenFixtureBuilder::jcsCanonicalEncode()` (the same generator
  // `FiscalPayloadConstraintValidatorTest::test_f16_matches_committed_fixture_bytes`
  // uses). `line_subtotal`/`line_vat`/every other byte is unchanged.
  const cartItem: CartItem = {
    id: 'return-line-0',
    product: { id: 'prod-default', name: 'Default item', sku: 'SKU-DEFAULT', price: '12.00' },
    quantity: -1,
    unit_price: '12.00',
    line_total: '-12.00',
    tax_rate: '20.00',
    tax_amount: '-2.00',
    kind: 'return',
  };

  const original: OriginalFiscalEventLocalView = {
    fiscalEventId: '99999999-9999-4999-8999-999999999999',
    lineItems: [],
    payments: [],
    trainingFlag: false,
    transactionDiscountAmount: '0.00',
  };

  const lines: RefundLineInput[] = [
    { cartItem, originalLineIndex: 0, disposition: 'restock' },
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

describe('RefundReceiptV4Payload cross-language byte/hash parity (wave-2)', () => {
  it('encodes the golden v4 refund to the locked canonical bytes + hash', async () => {
    const encoder = new FiscalEventCanonicalEncoder();
    const fixture = readGoldenFixture();

    const payload = buildRefundReceiptV4Payload(goldenInput());
    const canonical = encoder.encode(payload);

    expect(canonical).toBe(fixture.expected_canonical_string);

    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical));
    const hex = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    expect(hex).toBe(fixture.expected_sha256_hex);
  });
});
