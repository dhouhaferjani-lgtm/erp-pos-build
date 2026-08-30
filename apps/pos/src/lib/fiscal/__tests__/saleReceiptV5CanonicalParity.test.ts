import { describe, expect, it } from 'vitest';

import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import type { BuildSaleReceiptPayloadInput } from '../payloads/SaleReceiptPayload';
import { buildSaleReceiptV5Payload } from '../payloads/SaleReceiptV5Payload';
import type { CartItem } from '@/types/cart';

/**
 * SALE_RECEIPT V5 (D-1, owner ruling 2026-08-25) cross-language canonical
 * parity — the GOLDEN VECTOR for the post-remise VAT base.
 *
 * The fixture lives at
 * `apps/api/tests/Fixtures/Fiscal/sale-receipt-v5-golden.json` and is consumed
 * by BOTH sides:
 *   - this test proves the DEVICE encoder produces exactly the locked canonical
 *     payload bytes + SHA-256 for the golden discounted sale;
 *   - `Tests\Unit\Fiscal\SaleReceiptV5GoldenParityTest` (PHP) proves the SERVER
 *     accepts those exact bytes as a verified `event_version = 5` payload, that
 *     the hash matches, and that the SAME bytes are REFUSED at v3 (where the
 *     identity still adds the remise back).
 *
 * Device JS hash == server PHP hash is the D-1 sign-off contract, and the
 * fixture is the artefact a future reviewer diffs to see whether sealed
 * semantics moved. Regenerate ONLY on a deliberate version bump.
 *
 * The cart is the ruling's worked example: 7 / 13 / 19 % plus an exempt line,
 * Σ gross 640.000 TND, a 50.000 remise, sealed base 524.547 + VAT 65.453.
 */

function line(id: string, lineTotal: string, rate: string, vat: string): CartItem {
  return {
    id,
    product: {
      id: `aaaaaaaa-aaaa-4aaa-8aaa-00000000000${id}`,
      name: `Article ${rate}`,
      sku: `SKU-${rate}`,
      price: lineTotal,
    },
    quantity: 1,
    unit_price: lineTotal,
    line_total: lineTotal,
    tax_rate: rate,
    tax_amount: vat,
  };
}

function goldenInput(): BuildSaleReceiptPayloadInput {
  return {
    receiptId: '00000000-0000-4000-8000-000000000001',
    terminalId: '33333333-3333-4333-8333-333333333333',
    operatorId: '11111111-1111-4111-8111-111111111111',
    operatorName: 'Default Cashier',
    shiftId: '22222222-2222-4222-8222-222222222222',
    currency: 'TND',
    eventTimeDevice: new Date('2026-08-25T10:00:00.000Z'),
    businessDate: '2026-08-25',
    cartItems: [
      line('1', '238.000', '19', '38.000'),
      line('2', '226.000', '13', '26.000'),
      line('3', '107.000', '7', '7.000'),
      line('4', '69.000', '0', '0.000'),
    ],
    subtotalGross: '640.000',
    taxAmount: '71.000',
    total: '590.000',
    transactionDiscountAmount: '50.000',
    transactionDiscountReason: 'Geste commercial',
    payments: [{ methodCode: 'CASH', amount: '590.000' }],
    isTraining: false,
    seller: {
      name: 'Cafe Tunis SARL',
      taxNumber: '1234567AM000',
      countryCode: 'TN',
      street: '1 avenue Habib Bourguiba',
      city: 'Tunis',
      postalCode: '1000',
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
    path.resolve(__dirname, '../../../../../../apps/api/tests/Fixtures/Fiscal/sale-receipt-v5-golden.json'),
    path.resolve(__dirname, '../../../../../api/tests/Fixtures/Fiscal/sale-receipt-v5-golden.json'),
  ];
  const fixturePath = candidates.find((p) => fs.existsSync(p));
  if (!fixturePath) {
    throw new Error(`sale-receipt-v5-golden.json not found at any candidate path: ${candidates.join(', ')}`);
  }

  return JSON.parse(fs.readFileSync(fixturePath, 'utf8')) as GoldenFixture;
}

describe('SALE_RECEIPT V5 canonical parity (D-1)', () => {
  it('encodes the golden discounted sale to the locked canonical bytes + hash', async () => {
    const encoder = new FiscalEventCanonicalEncoder();
    const fixture = readGoldenFixture();

    const payload = buildSaleReceiptV5Payload(goldenInput(), {
      exactTotal: '590.000',
      roundedTotal: '590.000',
      adjustment: '0.000',
      denomination: '0.000',
    });
    const canonical = encoder.encode(payload);

    expect(canonical).toBe(fixture.expected_canonical_string);

    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical));
    const hex = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    expect(hex).toBe(fixture.expected_sha256_hex);
  });

  it('seals the POST-remise base — the vector would be identical under v3 only if the ruling were (b)', () => {
    const payload = buildSaleReceiptV5Payload(goldenInput(), {
      exactTotal: '590.000',
      roundedTotal: '590.000',
      adjustment: '0.000',
      denomination: '0.000',
    });

    expect(payload.subtotal).toBe('524.547');
    expect(payload.vat_total).toBe('65.453');
    expect(payload.transaction_discount_amount).toBe('50.000');
  });
});
