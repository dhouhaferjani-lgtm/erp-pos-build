/**
 * Lane C wave-2 fix wave — finding 19 (⚖️ orchestrator-adopted from the
 * wave-3 payout-cash-bound analysis §M1).
 *
 * The device-local cumulative backstop (finding 18) sums `refund_intents`
 * rows ONLY. Refunds settled through the LEGACY `/return` path — which
 * stays live on non-acknowledged terminals BY RULED DESIGN (§9.2's
 * dual-path store) — leave no `refund_intents` row at all; their only
 * device-local trace is `local_refund_records`, which the backstop never
 * consulted. So an original already refunded in full through the legacy
 * path could be refunded AGAIN, in full, offline, via v4: the device saw
 * zero prior refunds.
 *
 * `local_refund_records` has no per-line quantities and keys the original
 * by `original_receipt_number` (not the local receipt UUID), so this can
 * only ever be a RECEIPT-LEVEL, VALUE-BASED bound — coarser than the
 * per-line quantity cap, and accepted as such by the ruling. It converts
 * the hole from "invisible" to "bounded by the original's own value".
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  LegacyRefundRecordUnreadableError,
  sumLegacyRefundedValueForOriginalReceipt,
} from '../localRefundRecordRepository';

const nodeSqliteAvailable = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

const ORIGINAL_RECEIPT_NUMBER = 'MAIN-T01-2026-00000042';

d('sumLegacyRefundedValueForOriginalReceipt (finding 19)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  async function insertLegacyRefund(
    id: string,
    total: string,
    originalReceiptNumber = ORIGINAL_RECEIPT_NUMBER,
  ): Promise<void> {
    await adapter.execute(
      `INSERT INTO local_refund_records (
         id, receipt_number, original_receipt_number, shift_id, terminal_id,
         destination, total, cash_impact, currency, settled_at
       ) VALUES ($1, $2, $3, 'shift-1', 'term-1', 'cash', $4, $5, 'EUR', '2026-07-30T10:00:00Z')`,
      [id, `AVOIR-${id}`, originalReceiptNumber, total, total.replace('-', '')],
    );
  }

  it('returns canonical zero when the original has no legacy refunds', async () => {
    expect(
      await sumLegacyRefundedValueForOriginalReceipt(
        adapter.asDatabase(),
        ORIGINAL_RECEIPT_NUMBER,
        2,
      ),
    ).toBe('0.00');
  });

  it('sums the MAGNITUDE of every legacy refund of the same original receipt number', async () => {
    // `total` is stored signed-negative for a return (the server's own
    // convention) — the bound is over magnitudes.
    await insertLegacyRefund('r1', '-10.00');
    await insertLegacyRefund('r2', '-5.50');
    // A refund of a DIFFERENT original must not contribute.
    await insertLegacyRefund('r3', '-99.00', 'MAIN-T01-2026-00000099');

    expect(
      await sumLegacyRefundedValueForOriginalReceipt(
        adapter.asDatabase(),
        ORIGINAL_RECEIPT_NUMBER,
        2,
      ),
    ).toBe('15.50');
  });

  it('FAILS CLOSED on a malformed total (never skip-and-undercount)', async () => {
    await insertLegacyRefund('r1', '-10.00');
    await adapter.execute("UPDATE local_refund_records SET total = 'not-a-number' WHERE id = 'r1'");

    await expect(
      sumLegacyRefundedValueForOriginalReceipt(adapter.asDatabase(), ORIGINAL_RECEIPT_NUMBER, 2),
    ).rejects.toThrow(LegacyRefundRecordUnreadableError);
  });

  it('FAILS CLOSED on an empty total', async () => {
    await insertLegacyRefund('r1', '-10.00');
    await adapter.execute("UPDATE local_refund_records SET total = '' WHERE id = 'r1'");

    await expect(
      sumLegacyRefundedValueForOriginalReceipt(adapter.asDatabase(), ORIGINAL_RECEIPT_NUMBER, 2),
    ).rejects.toThrow(LegacyRefundRecordUnreadableError);
  });
});
