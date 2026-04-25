/**
 * Golden byte round-trip test for zReportHashService.
 *
 * The PHP test apps/api/tests/Feature/POS/HashGoldenByteTest.php
 * asserts the SAME golden byte value for the same canonical payload,
 * proving PHP ↔ TypeScript hash parity.
 *
 * Golden bytes pinned 2026-04-25 — if TS normalization changes, update
 * PHP normalizeForHash in ZReportHashService.php too.
 */

import { describe, it, expect } from 'vitest';
import { normalizeForHash, computeZReportHash } from '../zReportHashService';
import type { ZReportData } from '@/lib/offline/types';

/**
 * Canonical schema-2 payload exercising all normalized fields:
 *   - top-level monetary strings (opening_cash, expected_cash, gross_sales, net_sales, tax_amount)
 *   - payment_methods[].total_amount
 *   - cash_counts[].expected_amount / actual_amount / variance_amount
 *   - variance_summary.aggregate_amount
 *   - tolerance_summary.totalAmount
 *   - schema_version as integer
 *
 * Must exactly match the canonical payload in HashGoldenByteTest.php.
 */
function canonicalPayload(): Record<string, unknown> {
  return {
    schema_version: 2,
    opening_cash: '10.00',
    expected_cash: '820.00',
    gross_sales: '1432.50',
    net_sales: '1203.78',
    tax_amount: '228.72',
    sales_count: 3,
    refunds_count: 0,
    refunds_amount: '0.00',
    voided_count: 0,
    vat_breakdown: [
      { tax_rate: 20.0, net_amount: '1003.78', vat_amount: '200.76', gross_amount: '1204.54' },
      { tax_rate: 10.0, net_amount: '200.00', vat_amount: '20.00', gross_amount: '220.00' },
    ],
    payment_methods: [
      { payment_type: 'CASH', total_amount: '820.00', transaction_count: 2 },
      { payment_type: 'CARD', total_amount: '612.50', transaction_count: 1 },
    ],
    cash_counts: [
      {
        payment_method_id: '11111111-1111-1111-1111-111111111111',
        currency_code: 'EUR',
        expected_amount: '820.00',
        actual_amount: '820.00',
        variance_amount: '0.00',
        variance_direction: 'balanced',
        transaction_count: 2,
      },
    ],
    variance_summary: {
      aggregate_amount: '0.00',
      aggregate_direction: 'balanced',
      severity: 'none',
      currency_code: 'EUR',
    },
    tolerance_summary: {
      totalAmount: '0.000',
      currencyCode: 'EUR',
      writeoffCount: 0,
    },
    shift_fields: null,
  };
}

describe('normalizeForHash', () => {
  it('normalizes top-level monetary fields to scale 3', () => {
    const result = normalizeForHash(canonicalPayload());

    expect(result['opening_cash']).toBe('10.000');
    expect(result['expected_cash']).toBe('820.000');
    expect(result['gross_sales']).toBe('1432.500');
    expect(result['net_sales']).toBe('1203.780');
    expect(result['tax_amount']).toBe('228.720');
  });

  it('normalizes payment_methods[].total_amount to scale 3', () => {
    const result = normalizeForHash(canonicalPayload());
    const pms = result['payment_methods'] as Array<Record<string, unknown>>;

    expect(pms[0]!['total_amount']).toBe('820.000');
    expect(pms[1]!['total_amount']).toBe('612.500');
  });

  it('normalizes cash_counts[] monetary amounts to scale 3', () => {
    const result = normalizeForHash(canonicalPayload());
    const cc = result['cash_counts'] as Array<Record<string, unknown>>;

    expect(cc[0]!['expected_amount']).toBe('820.000');
    expect(cc[0]!['actual_amount']).toBe('820.000');
    expect(cc[0]!['variance_amount']).toBe('0.000');
  });

  it('normalizes variance_summary.aggregate_amount to scale 3', () => {
    const result = normalizeForHash(canonicalPayload());
    const vs = result['variance_summary'] as Record<string, unknown>;

    expect(vs['aggregate_amount']).toBe('0.000');
  });

  it('normalizes tolerance_summary.totalAmount to scale 3', () => {
    const result = normalizeForHash(canonicalPayload());
    const ts = result['tolerance_summary'] as Record<string, unknown>;

    expect(ts['totalAmount']).toBe('0.000');
  });

  it('does not mutate the input object', () => {
    const input = canonicalPayload();
    const original = JSON.stringify(input);
    normalizeForHash(input);

    expect(JSON.stringify(input)).toBe(original);
  });

  it('passes through v1 payloads unchanged', () => {
    const v1: Record<string, unknown> = { opening_cash: '10.00', gross_sales: '100.00' };
    const result = normalizeForHash(v1);

    expect(result['opening_cash']).toBe('10.00');
    expect(result['gross_sales']).toBe('100.00');
  });

  it('passes through payloads without schema_version unchanged', () => {
    const noVersion: Record<string, unknown> = { gross_sales: '50.00' };
    const result = normalizeForHash(noVersion);

    expect(result['gross_sales']).toBe('50.00');
  });
});

describe('computeZReportHash', () => {
  // Golden bytes pinned 2026-04-25 — matches PHP HashGoldenByteTest.php assertion.
  // If either PHP or TS normalization changes, both tests must be updated together.
  const GOLDEN_HASH = '0cd3b69874b40888b7f6c4132afb8d7cc26a5b8dd45568efba5a8f941ccf1679';

  it('produces a 64-char hex SHA-256 hash', async () => {
    const hash = await computeZReportHash({
      previousHash: 'GENESIS',
      zNumber: 1,
      terminalId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      generatedAt: '2026-04-25T10:00:00+00:00',
      reportData: canonicalPayload() as unknown as ZReportData,
    });

    expect(hash).toMatch(/^[0-9a-f]{64}$/);
  });

  it('golden byte — PHP ↔ TypeScript hash parity for canonical schema-2 payload', async () => {
    const hash = await computeZReportHash({
      previousHash: 'GENESIS',
      zNumber: 1,
      terminalId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      generatedAt: '2026-04-25T10:00:00+00:00',
      reportData: canonicalPayload() as unknown as ZReportData,
    });

    expect(hash).toBe(GOLDEN_HASH);
  });

  it('produces a different hash when previousHash differs', async () => {
    const base = {
      previousHash: 'GENESIS',
      zNumber: 1,
      terminalId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      generatedAt: '2026-04-25T10:00:00+00:00',
      reportData: canonicalPayload() as unknown as ZReportData,
    };

    const hashA = await computeZReportHash(base);
    const hashB = await computeZReportHash({ ...base, previousHash: 'different-previous' });

    expect(hashA).not.toBe(hashB);
  });

  it('produces deterministic output for the same input', async () => {
    const input = {
      previousHash: 'GENESIS',
      zNumber: 1,
      terminalId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      generatedAt: '2026-04-25T10:00:00+00:00',
      reportData: canonicalPayload() as unknown as ZReportData,
    };

    const hash1 = await computeZReportHash(input);
    const hash2 = await computeZReportHash(input);

    expect(hash1).toBe(hash2);
  });
});
