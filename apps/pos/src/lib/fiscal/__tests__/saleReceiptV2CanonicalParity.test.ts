import { describe, expect, it } from 'vitest';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import {
  buildSaleReceiptV2Payload,
} from '../payloads/SaleReceiptV2Payload';
import type { BuildSaleReceiptPayloadInput } from '../payloads/SaleReceiptPayload';
import type { CartItem } from '@/types/cart';

/**
 * SALE_RECEIPT V2 (M4) cross-language canonical parity.
 *
 * The golden fixture lives at
 * `apps/api/tests/Fixtures/Fiscal/sale-receipt-v2-golden.json` and is
 * consumed by BOTH sides:
 *   - this test proves the DEVICE encoder produces exactly the locked
 *     canonical payload bytes + SHA-256 for the golden V2 sale;
 *   - `Tests\Unit\Fiscal\SaleReceiptV2GoldenParityTest` (PHP) proves the
 *     SERVER accepts those exact bytes as a verified event_version=2
 *     payload and that the hash matches.
 * Device JS hash == server PHP hash is the M4 sign-off contract.
 */

function goldenInput(): BuildSaleReceiptPayloadInput {
  const variantItem: CartItem = {
    id: 'line-1',
    product: {
      id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      name: 'T-Shirt',
      sku: 'TSHIRT',
      price: '25.00',
      variant_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      variant_name: 'T-Shirt — Red / L',
      variant_sku: 'TSHIRT-RED-L',
    },
    quantity: 1,
    unit_price: '25.00',
    line_total: '25.00',
    tax_rate: '20',
    tax_amount: '4.17',
  };
  const plainItem: CartItem = {
    id: 'line-2',
    product: {
      id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
      name: 'Socks',
      sku: 'SOCKS',
      price: '5.00',
    },
    quantity: 1,
    unit_price: '5.00',
    line_total: '5.00',
    tax_rate: '20',
    tax_amount: '0.83',
  };

  return {
    receiptId: '00000000-0000-4000-8000-000000000001',
    terminalId: '33333333-3333-4333-8333-333333333333',
    operatorId: '11111111-1111-4111-8111-111111111111',
    operatorName: 'Default Cashier',
    shiftId: '22222222-2222-4222-8222-222222222222',
    currency: 'EUR',
    eventTimeDevice: new Date('2026-06-11T10:00:00.000Z'),
    businessDate: '2026-06-11',
    cartItems: [variantItem, plainItem],
    subtotalGross: '30.00',
    taxAmount: '5.00',
    total: '30.00',
    transactionDiscountAmount: '0.00',
    transactionDiscountReason: null,
    payments: [{ methodCode: 'CASH', amount: '30.00' }],
    isTraining: false,
    seller: {
      name: 'Default Seller',
      taxNumber: '12345678901234',
      countryCode: 'FR',
      street: '1 rue de la Paix',
      city: 'Paris',
      postalCode: '75001',
    },
  };
}

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
    path.resolve(__dirname, '../../../../../../apps/api/tests/Fixtures/Fiscal/sale-receipt-v2-golden.json'),
    path.resolve(__dirname, '../../../../../api/tests/Fixtures/Fiscal/sale-receipt-v2-golden.json'),
  ];
  const fixturePath = candidates.find((p) => fs.existsSync(p));
  if (!fixturePath) {
    throw new Error(`sale-receipt-v2-golden.json not found at any candidate path: ${candidates.join(', ')}`);
  }
  return JSON.parse(fs.readFileSync(fixturePath, 'utf8')) as GoldenFixture;
}

describe('SALE_RECEIPT V2 canonical parity (M4)', () => {
  it('encodes the golden V2 sale to the locked canonical bytes + hash', async () => {
    const encoder = new FiscalEventCanonicalEncoder();
    const fixture = readGoldenFixture();

    const payload = buildSaleReceiptV2Payload(goldenInput());
    const canonical = encoder.encode(payload);

    expect(canonical).toBe(fixture.expected_canonical_string);

    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical));
    const hex = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    expect(hex).toBe(fixture.expected_sha256_hex);
  });
});
