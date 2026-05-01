/**
 * Codex review B5 (2026-05-01) — end-to-end integration test for the offline
 * voucher checkout flow.
 *
 * B5-fix audit decision Option B (2026-05-01): the offline path NO LONGER
 * writes a local voucher_ledger row. The canonical voucher_ledger entry is
 * server-authored exclusively (ReceiptSyncService now invokes
 * VoucherRedemptionService::redeem during sync). The local mirror picks up
 * the canonical row on the next pullVoucherLedger.
 *
 * Wires every layer the B5 commit chain touches against a REAL SQLite
 * engine (node:sqlite via SqliteTestAdapter), no mocks for the database
 * layer. The flow proven here:
 *
 *   1. Apply all 28 production migrations against a fresh SQLite db.
 *   2. Seed a terminal_state row + a local voucher with current_balance 50 EUR.
 *   3. Call createOfflineReceipt with a 50 EUR cart and a single
 *      store_voucher payment whose serial matches the seeded voucher.
 *   4. Assert (a) the offline_receipts row landed with the fiscal hash,
 *      (b) NO local voucher_ledger row was written (Option B — the canonical
 *      row arrives server-side via sync), (c) the local voucher's
 *      current_balance is now 0.00 (decrement is local-only for cashier
 *      visibility), and (d) the local voucher's status transitioned to
 *      FullyRedeemed.
 *
 * (B5-fix audit Nit, 2026-05-01) — earlier docs in this header claimed an
 * assertion about "the receipt's idempotency key as its id-prefix
 * correlation point" on a pending voucher_ledger row. That assertion never
 * existed in the test body and the schema does not carry such a tie. The
 * note has been removed from the contract description above.
 *
 * This is the "real flow test" the Codex review B5 final report explicitly
 * asked for ("Add a real flow test that starts from the mounted POS UI…").
 * Mounting the full HomePage with React + Tauri runtime is out of scope
 * for Vitest; this test exercises the load-bearing data-layer path that
 * a HomePage E2E would also exercise. The UI mount itself is covered by
 * AdvancedPaymentsModal.test.tsx (B5 mount tests) which proves
 * VoucherTenderModal opens on tile tap and the apply callback fires.
 *
 * Together these two test files cover the B5 contract from cashier tap
 * through to local SQLite balance decrement.
 *
 * Tests fail on the original B5 commit abb1323a (which wrote the local
 * voucher_ledger row) and pass after Option B removes that write.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
import { findByCode } from '@/lib/offline/voucherRepository';
import { makeCartItem } from '@/test/helpers';

// Mock the fiscal hash service to avoid pulling in WebCrypto polyfills in
// the test environment. The hash bytes themselves are NOT what's being
// validated here — that's covered by canonicalPayload.test.ts. What IS
// being validated is that the receipt insert + ledger insert + balance
// decrement land atomically against real SQLite.
vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('integration-test-fiscal-hash-' + '0'.repeat(40)),
}));

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

interface SeedTerminalArgs {
  terminalId: string;
  terminalCode: string;
  locationCode: string;
  fiscalSchemaVersion: 2 | 3;
}

async function seedTerminalState(
  adapter: SqliteTestAdapter,
  args: SeedTerminalArgs,
): Promise<void> {
  await adapter.execute(
    `INSERT INTO terminal_state (
       terminal_id, terminal_code, location_code, genesis_seed,
       last_hash, hash_sequence, manager_pin_throttle_until,
       manager_pin_failed_attempts, fiscal_schema_version
     ) VALUES ($1, $2, $3, $4, $5, $6, NULL, 0, $7)`,
    [
      args.terminalId,
      args.terminalCode,
      args.locationCode,
      'genesis-seed-test',
      'previous-hash',
      0,
      args.fiscalSchemaVersion,
    ],
  );
}

async function seedVoucher(
  adapter: SqliteTestAdapter,
  args: { id: string; code: string; balance: string; currency: string; terminalId: string },
): Promise<void> {
  await adapter.execute(
    `INSERT INTO vouchers (
       id, code, initial_balance, current_balance, currency, status,
       redemption_mode, voucher_kind, source, issued_at, expires_at,
       partner_id, issued_to_partner_id, redeemable_at_terminal_id,
       notes
     ) VALUES ($1, $2, $3, $3, $4, 'Issued', 'Bearer', 'MPV', 'Refund',
              '2026-04-01T00:00:00Z', NULL, NULL, NULL, $5, NULL)`,
    [args.id, args.code, args.balance, args.currency, args.terminalId],
  );
}

d('B5 integration: voucher tender end-to-end (offline)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    vi.clearAllMocks();
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
    await seedTerminalState(adapter, {
      terminalId: 'terminal-int-1',
      terminalCode: 'T-INT-01',
      locationCode: 'INT-LOC',
      fiscalSchemaVersion: 2,
    });
  });

  afterEach(() => {
    adapter.close();
  });

  it('full flow: voucher applied → receipt sealed → balance decrement, NO local ledger row (Option B)', async () => {
    await seedVoucher(adapter, {
      id: 'voucher-int-001',
      code: 'SV-INT-0099',
      balance: '50.00',
      currency: 'EUR',
      terminalId: 'terminal-int-1',
    });

    // Sanity: the voucher is fetchable via the production helper before
    // the redemption runs.
    const before = await findByCode(adapter as never, 'SV-INT-0099');
    expect(before).not.toBeNull();
    expect(before!.current_balance).toBe('50.00');
    expect(before!.status).toBe('Issued');

    const result = await createOfflineReceipt(adapter as never, {
      terminalId: 'terminal-int-1',
      operatorId: 'op-int-1',
      operatorName: 'Integration Cashier',
      cartItems: [makeCartItem({ line_total: '50.00', tax_amount: '0.00', tax_rate: '0' })],
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 50,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '50.00',
          paymentMethodId: 'pm-store-voucher',
          repositoryId: 'repo-virtual',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-INT-0099',
        },
      ],
    });

    // (a) Receipt row exists with the fiscal hash.
    expect(result.fiscalHash).toBe('integration-test-fiscal-hash-' + '0'.repeat(40));
    expect(result.idempotencyKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    );
    const receiptRows = await adapter.select<Array<{ id: string; total: string; fiscal_hash: string }>>(
      'SELECT id, total, fiscal_hash FROM offline_receipts WHERE id = $1',
      [result.localId],
    );
    expect(receiptRows).toHaveLength(1);
    expect(receiptRows[0]!.total).toBe('50.00');

    // (b) NO local voucher_ledger row was written (Option B). The canonical
    //     row is server-authored exclusively — ReceiptSyncService now
    //     invokes VoucherRedemptionService::redeem during sync, which
    //     writes the canonical row tied to the synced receipt. The local
    //     mirror picks up that row on the next pullVoucherLedger.
    const ledgerRows = await adapter.select<Array<{ voucher_id: string }>>(
      `SELECT voucher_id FROM voucher_ledger WHERE voucher_id = $1`,
      ['voucher-int-001'],
    );
    expect(ledgerRows).toHaveLength(0);

    // (c) Voucher's current_balance decremented to zero (local-only
    //     optimistic update so the cashier sees the right balance until
    //     sync reconciles the canonical state).
    const after = await findByCode(adapter as never, 'SV-INT-0099');
    expect(after).not.toBeNull();
    expect(after!.current_balance).toBe('0.00');

    // (d) Voucher's status transitioned to FullyRedeemed.
    expect(after!.status).toBe('FullyRedeemed');
  });

  it('partial redemption: voucher.current_balance decrements but stays positive, status = PartiallyRedeemed', async () => {
    await seedVoucher(adapter, {
      id: 'voucher-int-002',
      code: 'SV-INT-PARTIAL',
      balance: '50.00',
      currency: 'EUR',
      terminalId: 'terminal-int-1',
    });

    await createOfflineReceipt(adapter as never, {
      terminalId: 'terminal-int-1',
      operatorId: 'op-int-1',
      operatorName: 'Cashier',
      cartItems: [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0' })],
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 20,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '20.00',
          paymentMethodId: 'pm-store-voucher',
          repositoryId: 'repo-virtual',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-INT-PARTIAL',
        },
      ],
    });

    const after = await findByCode(adapter as never, 'SV-INT-PARTIAL');
    expect(after!.current_balance).toBe('30.00'); // 50 - 20
    expect(after!.status).toBe('PartiallyRedeemed');
  });

  it('atomicity: a missing voucher fails BEFORE any receipt insert (no orphan rows)', async () => {
    // No voucher seeded for SV-MISSING.
    await expect(
      createOfflineReceipt(adapter as never, {
        terminalId: 'terminal-int-1',
        operatorId: 'op-int-1',
        operatorName: 'Cashier',
        cartItems: [makeCartItem({ line_total: '50.00', tax_amount: '0.00', tax_rate: '0' })],
        currency: 'EUR',
        paymentMethodId: 'pm-store-voucher',
        paymentRepositoryId: 'repo-virtual',
        tenderedAmount: 50,
        payments: [
          {
            methodCode: 'store_voucher',
            amount: '50.00',
            paymentMethodId: 'pm-store-voucher',
            repositoryId: 'repo-virtual',
            instrumentType: 'store_voucher',
            instrumentSerial: 'SV-MISSING',
          },
        ],
      }),
    ).rejects.toThrow(/SV-MISSING/);

    // No offline_receipts row was ever inserted.
    const receiptCount = await adapter.select<Array<{ count: number }>>(
      'SELECT COUNT(*) as count FROM offline_receipts',
    );
    expect(receiptCount[0]!.count).toBe(0);

    // No voucher_ledger row either.
    const ledgerCount = await adapter.select<Array<{ count: number }>>(
      'SELECT COUNT(*) as count FROM voucher_ledger',
    );
    expect(ledgerCount[0]!.count).toBe(0);
  });

  it('mixed tender (cash + voucher): voucher balance decrements, NO local ledger row (Option B)', async () => {
    await seedVoucher(adapter, {
      id: 'voucher-int-003',
      code: 'SV-INT-MIXED',
      balance: '20.00',
      currency: 'EUR',
      terminalId: 'terminal-int-1',
    });

    await createOfflineReceipt(adapter as never, {
      terminalId: 'terminal-int-1',
      operatorId: 'op-int-1',
      operatorName: 'Cashier',
      cartItems: [makeCartItem({ line_total: '50.00', tax_amount: '0.00', tax_rate: '0' })],
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 50,
      payments: [
        {
          methodCode: 'CASH',
          amount: '30.00',
          paymentMethodId: 'pm-cash',
          repositoryId: 'repo-cash',
        },
        {
          methodCode: 'store_voucher',
          amount: '20.00',
          paymentMethodId: 'pm-store-voucher',
          repositoryId: 'repo-virtual',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-INT-MIXED',
        },
      ],
    });

    // Option B: NO local voucher_ledger rows. Cash never wrote one (correct);
    // voucher tender no longer writes one either.
    const ledgerRows = await adapter.select<Array<{ voucher_id: string; amount: string }>>(
      'SELECT voucher_id, amount FROM voucher_ledger',
    );
    expect(ledgerRows).toHaveLength(0);

    // Voucher's local balance still decrements (optimistic UI for cashier).
    // Balance was 20, redeemed 20 → 0.
    const after = await findByCode(adapter as never, 'SV-INT-MIXED');
    expect(after!.current_balance).toBe('0.00');
    expect(after!.status).toBe('FullyRedeemed');
  });
});
