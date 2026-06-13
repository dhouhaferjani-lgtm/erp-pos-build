/**
 * T0.2 + Codex review F-1 (2026-05-08) — real-SQLite integration test for the
 * idempotency-key-per-retry contract.
 *
 * The unit test in paymentStore.offlineFirst.test.ts mocks createOfflineReceipt
 * entirely, so it cannot detect the load-bearing failure mode where the local
 * SQLite UNIQUE(idempotency_key) constraint throws on the second call. This
 * suite exercises the real persistence layer:
 *
 *   1. Boot SqliteTestAdapter (node:sqlite in-memory).
 *   2. Run all production migrations against it.
 *   3. Seed a terminal_state row.
 *   4. Call createOfflineReceipt with idempotencyKey = K → row R1 lands.
 *   5. Call createOfflineReceipt AGAIN with the SAME idempotencyKey K and a
 *      different cart payload → assert:
 *        (a) NO SQLITE_CONSTRAINT_UNIQUE thrown,
 *        (b) the result equals R1's receipt_number / total / fiscal_hash
 *            (not a fresh insert with R2's cart),
 *        (c) only ONE row exists in offline_receipts for that key,
 *        (d) the fiscal hash chain is NOT advanced twice (hash_sequence
 *            stays at the value R1 set).
 *
 * This catches the regression Codex flagged: pre-fix, step (5) would throw at
 * the INSERT layer because offline_receipts.idempotency_key has UNIQUE.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';
import { migrations } from '@/lib/db/migrations';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
import { makeCartItem } from '@/test/helpers';

vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('integration-test-fiscal-hash-' + '0'.repeat(40)),
}));

const TEST_TENANT_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const TEST_COMPANY_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const TEST_TERMINAL_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const TEST_OPERATOR_ID = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
const TEST_SHIFT_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

const fiscalReceiptContext = {
  tenantId: TEST_TENANT_ID,
  companyId: TEST_COMPANY_ID,
  shiftId: TEST_SHIFT_ID,
  seller: {
    name: 'Integration Seller SA',
    taxNumber: '123456789',
    countryCode: 'FR',
    street: '1 Rue Integration',
    city: 'Paris',
    postalCode: '75001',
  },
} as const;

const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

async function seedTerminalState(adapter: SqliteTestAdapter): Promise<void> {
  await adapter.execute(
    `INSERT INTO terminal_state (
       terminal_id, terminal_code, location_code, genesis_seed,
       last_hash, hash_sequence, manager_pin_throttle_until,
       manager_pin_failed_attempts, fiscal_schema_version,
       fiscal_event_genesis_seed, fiscal_event_last_hash, fiscal_event_sequence
     ) VALUES ($1, $2, $3, $4, $5, $6, NULL, 0, $7, $8, $8, 0)`,
    [
      TEST_TERMINAL_ID,
      'T-T02-01',
      'T02-LOC',
      'genesis-seed',
      'previous-hash',
      0,
      2,
      '1'.repeat(64),
    ],
  );
}

d('T0.2 integration: idempotency-key retry against real SQLite', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    vi.clearAllMocks();
    const { __resetFiscalEventEngineForTesting } = await import('@/lib/fiscal/instance');
    __resetFiscalEventEngineForTesting();
    adapter = new SqliteTestAdapter();
    __resetWriteGateForTesting();
    setWriter(adapter as unknown as SqlSurface);
    await runAllMigrations(adapter);
    await seedTerminalState(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('second call with the same idempotencyKey returns the existing receipt without throwing', async () => {
    const sharedKey = 'shared-idempotency-key-t02-test';

    // First attempt: writes row R1.
    const first = await createOfflineReceipt(adapter as never, {
      ...fiscalReceiptContext,
      terminalId: TEST_TERMINAL_ID,
      operatorId: TEST_OPERATOR_ID,
      operatorName: 'Integration Cashier',
      cartItems: [makeCartItem({ unit_price: '50.00', line_total: '50.00', tax_amount: '0.00', tax_rate: '0' })],
      currency: 'EUR',
      paymentMethodId: 'pm-cash',
      paymentRepositoryId: 'repo-cash',
      tenderedAmount: 50,
      idempotencyKey: sharedKey,
      payments: [
        {
          methodCode: 'CASH',
          amount: '50.00',
          paymentMethodId: 'pm-cash',
          repositoryId: 'repo-cash',
        },
      ],
    });

    expect(first.idempotencyKey).toBe(sharedKey);
    expect(first.total).toBe('50.00');

    // Second attempt with the SAME key but a DIFFERENT cart. Per T0.2 contract
    // ("Same key MUST be reused even if cart contents change between attempts —
    // the key is bound to 'this submission attempt', not 'this cart state'"),
    // this MUST NOT throw and MUST return R1's data verbatim.
    const second = await createOfflineReceipt(adapter as never, {
      ...fiscalReceiptContext,
      terminalId: TEST_TERMINAL_ID,
      operatorId: TEST_OPERATOR_ID,
      operatorName: 'Integration Cashier',
      // Different cart — but the contract says the FIRST attempt's data is canonical.
      cartItems: [makeCartItem({ unit_price: '99.00', line_total: '99.00', tax_amount: '0.00', tax_rate: '0' })],
      currency: 'EUR',
      paymentMethodId: 'pm-cash',
      paymentRepositoryId: 'repo-cash',
      tenderedAmount: 100,
      idempotencyKey: sharedKey,
      payments: [
        {
          methodCode: 'CASH',
          amount: '99.00',
          paymentMethodId: 'pm-cash',
          repositoryId: 'repo-cash',
        },
      ],
    });

    // (a) Did not throw.
    // (b) Returns R1's data, not a new row's data.
    expect(second.idempotencyKey).toBe(first.idempotencyKey);
    expect(second.localId).toBe(first.localId);
    expect(second.receiptNumber).toBe(first.receiptNumber);
    expect(second.total).toBe('50.00'); // R1's cart total, not R2's 99.00
    expect(second.fiscalHash).toBe(first.fiscalHash);

    // (c) Exactly one row in offline_receipts for that key.
    const rows = await adapter.select<Array<{ id: string; idempotency_key: string; total: string }>>(
      'SELECT id, idempotency_key, total FROM offline_receipts WHERE idempotency_key = $1',
      [sharedKey],
    );
    expect(rows).toHaveLength(1);
    expect(rows[0]!.total).toBe('50.00');

    // (d) Fiscal hash chain advanced exactly once (sequence = 1, not 2).
    const terminalRows = await adapter.select<Array<{ hash_sequence: number; last_hash: string }>>(
      'SELECT fiscal_event_sequence AS hash_sequence, fiscal_event_last_hash AS last_hash FROM terminal_state WHERE terminal_id = $1',
      [TEST_TERMINAL_ID],
    );
    expect(terminalRows[0]!.hash_sequence).toBe(1);
  });

  it('different idempotencyKeys produce two distinct receipts and advance the chain twice', async () => {
    // Sanity check: the dedup is keyed strictly by idempotencyKey, not by
    // cart contents or some other attribute. Two genuinely different sales
    // (different keys) must each land their own row and advance the chain.
    const first = await createOfflineReceipt(adapter as never, {
      ...fiscalReceiptContext,
      terminalId: TEST_TERMINAL_ID,
      operatorId: TEST_OPERATOR_ID,
      operatorName: 'Integration Cashier',
      cartItems: [makeCartItem({ line_total: '10.00', tax_amount: '0.00', tax_rate: '0' })],
      currency: 'EUR',
      paymentMethodId: 'pm-cash',
      paymentRepositoryId: 'repo-cash',
      tenderedAmount: 10,
      idempotencyKey: 'distinct-key-A',
      payments: [
        { methodCode: 'CASH', amount: '10.00', paymentMethodId: 'pm-cash', repositoryId: 'repo-cash' },
      ],
    });

    const second = await createOfflineReceipt(adapter as never, {
      ...fiscalReceiptContext,
      terminalId: TEST_TERMINAL_ID,
      operatorId: TEST_OPERATOR_ID,
      operatorName: 'Integration Cashier',
      cartItems: [makeCartItem({ unit_price: '20.00', line_total: '20.00', tax_amount: '0.00', tax_rate: '0' })],
      currency: 'EUR',
      paymentMethodId: 'pm-cash',
      paymentRepositoryId: 'repo-cash',
      tenderedAmount: 20,
      idempotencyKey: 'distinct-key-B',
      payments: [
        { methodCode: 'CASH', amount: '20.00', paymentMethodId: 'pm-cash', repositoryId: 'repo-cash' },
      ],
    });

    expect(first.localId).not.toBe(second.localId);
    expect(first.idempotencyKey).not.toBe(second.idempotencyKey);

    const rows = await adapter.select<Array<{ idempotency_key: string }>>(
      'SELECT idempotency_key FROM offline_receipts ORDER BY hash_sequence ASC',
    );
    expect(rows).toHaveLength(2);

    const terminalRows = await adapter.select<Array<{ hash_sequence: number }>>(
      'SELECT fiscal_event_sequence AS hash_sequence FROM terminal_state WHERE terminal_id = $1',
      [TEST_TERMINAL_ID],
    );
    expect(terminalRows[0]!.hash_sequence).toBe(2);
  });
});
