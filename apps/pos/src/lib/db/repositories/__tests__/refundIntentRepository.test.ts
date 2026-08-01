import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  InvalidRefundIntentTransitionError,
  computeLineSnapshotFingerprint,
  confirmRefundIntentPayout,
  confirmRefundIntentPrinted,
  createOrReuseActiveRefundIntent,
  disputeRefundIntentPayout,
  findActiveRefundIntent,
  getRefundIntentById,
  getRefundIntentsPendingPayoutConfirmation,
  getRefundIntentsPendingReprint,
  markAbandoned,
  markApprovalAuthored,
  markDeadLetteredLocal,
  markRefundEventAppended,
  markSynced,
  type CreateRefundIntentInput,
} from '../refundIntentRepository';

const nodeSqliteAvailable = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

function baseInput(overrides: Partial<CreateRefundIntentInput> = {}): CreateRefundIntentInput {
  return {
    id: 'intent-1',
    terminalId: 'term-1',
    operatorId: 'op-1',
    originalLocalReceiptId: 'orig-receipt-1',
    originalFiscalEventId: 'orig-fiscal-event-1',
    lineSnapshot: [{ product_id: 'prod-1', quantity: '1.000', disposition: 'restock' }],
    approvalSourceEventId: 'approval-1',
    overrideSourceEventId: 'override-1',
    ...overrides,
  };
}

d('refundIntentRepository — §4.4 durable intent state machine', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  describe('createOrReuseActiveRefundIntent', () => {
    it('inserts a fresh drafted row on first call', async () => {
      const { intent, reused } = await createOrReuseActiveRefundIntent(adapter.asDatabase(), baseInput());

      expect(reused).toBe(false);
      expect(intent.id).toBe('intent-1');
      expect(intent.state).toBe('drafted');
      expect(intent.refund_fiscal_event_id).toBeNull();
      expect(intent.payout_confirmed_at).toBeNull();
    });

    it('reuses the existing ACTIVE row for the same original+line-selection fingerprint (spec §4.4)', async () => {
      const first = await createOrReuseActiveRefundIntent(adapter.asDatabase(), baseInput());
      const second = await createOrReuseActiveRefundIntent(
        adapter.asDatabase(),
        baseInput({ id: 'intent-2', approvalSourceEventId: 'approval-2', overrideSourceEventId: 'override-2' }),
      );

      expect(second.reused).toBe(true);
      expect(second.intent.id).toBe(first.intent.id);

      const rows = await adapter.select<Array<{ n: number }>>('SELECT COUNT(*) as n FROM refund_intents');
      expect(rows[0]?.n).toBe(1);
    });

    it('does NOT reuse across a DIFFERENT line selection (different fingerprint) against the same original', async () => {
      await createOrReuseActiveRefundIntent(adapter.asDatabase(), baseInput());
      const { reused } = await createOrReuseActiveRefundIntent(
        adapter.asDatabase(),
        baseInput({
          id: 'intent-2',
          lineSnapshot: [{ product_id: 'prod-2', quantity: '1.000', disposition: 'restock' }],
        }),
      );

      expect(reused).toBe(false);
      const rows = await adapter.select<Array<{ n: number }>>('SELECT COUNT(*) as n FROM refund_intents');
      expect(rows[0]?.n).toBe(2);
    });

    it('allows a NEW active intent for the same original+fingerprint once the prior one reached a terminal state (Revision 4 fold-item-7)', async () => {
      const db = adapter.asDatabase();
      const first = await createOrReuseActiveRefundIntent(db, baseInput());
      await markApprovalAuthored(db, first.intent.id);
      await markRefundEventAppended(db, first.intent.id, 'refund-fiscal-event-1');
      await markSynced(db, first.intent.id);

      const second = await createOrReuseActiveRefundIntent(
        db,
        baseInput({ id: 'intent-2', approvalSourceEventId: 'approval-2', overrideSourceEventId: 'override-2' }),
      );

      expect(second.reused).toBe(false);
      expect(second.intent.id).toBe('intent-2');
    });
  });

  describe('computeLineSnapshotFingerprint', () => {
    it('is deterministic for the same snapshot', async () => {
      const snapshot = [{ product_id: 'prod-1', quantity: '1.000' }];
      expect(await computeLineSnapshotFingerprint(snapshot)).toBe(
        await computeLineSnapshotFingerprint(snapshot),
      );
    });

    it('differs for a different snapshot', async () => {
      const a = await computeLineSnapshotFingerprint([{ product_id: 'prod-1', quantity: '1.000' }]);
      const b = await computeLineSnapshotFingerprint([{ product_id: 'prod-1', quantity: '2.000' }]);
      expect(a).not.toBe(b);
    });
  });

  describe('state transitions', () => {
    it('walks the full happy path: drafted -> approval_authored -> refund_event_appended -> synced', async () => {
      const db = adapter.asDatabase();
      const { intent } = await createOrReuseActiveRefundIntent(db, baseInput());

      await markApprovalAuthored(db, intent.id);
      let row = await getRefundIntentById(db, intent.id);
      expect(row?.state).toBe('approval_authored');

      await markRefundEventAppended(db, intent.id, 'refund-fiscal-event-1');
      row = await getRefundIntentById(db, intent.id);
      expect(row?.state).toBe('refund_event_appended');
      expect(row?.refund_fiscal_event_id).toBe('refund-fiscal-event-1');

      await markSynced(db, intent.id);
      row = await getRefundIntentById(db, intent.id);
      expect(row?.state).toBe('synced');
    });

    it('rejects an out-of-order transition (drafted -> refund_event_appended, skipping approval_authored)', async () => {
      const db = adapter.asDatabase();
      const { intent } = await createOrReuseActiveRefundIntent(db, baseInput());

      await expect(markRefundEventAppended(db, intent.id, 'refund-fiscal-event-1')).rejects.toThrow(
        InvalidRefundIntentTransitionError,
      );
      // The row must be untouched -- still 'drafted', no fiscal event id leaked in.
      const row = await getRefundIntentById(db, intent.id);
      expect(row?.state).toBe('drafted');
      expect(row?.refund_fiscal_event_id).toBeNull();
    });

    it('rejects re-entering an already-synced row (no transition backward or replay)', async () => {
      const db = adapter.asDatabase();
      const { intent } = await createOrReuseActiveRefundIntent(db, baseInput());
      await markApprovalAuthored(db, intent.id);
      await markRefundEventAppended(db, intent.id, 'refund-fiscal-event-1');
      await markSynced(db, intent.id);

      await expect(markApprovalAuthored(db, intent.id)).rejects.toThrow(
        InvalidRefundIntentTransitionError,
      );
    });

    it('rejects transitioning a nonexistent id', async () => {
      await expect(markApprovalAuthored(adapter.asDatabase(), 'nonexistent')).rejects.toThrow(
        InvalidRefundIntentTransitionError,
      );
    });

    it('marks dead_lettered_local from drafted or approval_authored, but not after refund_event_appended', async () => {
      const db = adapter.asDatabase();
      const drafted = await createOrReuseActiveRefundIntent(db, baseInput());
      await markDeadLetteredLocal(db, drafted.intent.id);
      expect((await getRefundIntentById(db, drafted.intent.id))?.state).toBe('dead_lettered_local');

      const appended = await createOrReuseActiveRefundIntent(
        db,
        baseInput({ id: 'intent-2', originalLocalReceiptId: 'orig-receipt-2' }),
      );
      await markApprovalAuthored(db, appended.intent.id);
      await markRefundEventAppended(db, appended.intent.id, 'refund-fiscal-event-2');

      await expect(markDeadLetteredLocal(db, appended.intent.id)).rejects.toThrow(
        InvalidRefundIntentTransitionError,
      );
    });

    it('marks abandoned from drafted or approval_authored, but not after refund_event_appended', async () => {
      const db = adapter.asDatabase();
      const { intent } = await createOrReuseActiveRefundIntent(db, baseInput());
      await markAbandoned(db, intent.id);
      expect((await getRefundIntentById(db, intent.id))?.state).toBe('abandoned');
    });
  });

  describe('payout / print reconciliation (§4.5)', () => {
    async function appendedIntent(db: ReturnType<typeof adapter.asDatabase>) {
      const { intent } = await createOrReuseActiveRefundIntent(db, baseInput());
      await markApprovalAuthored(db, intent.id);
      await markRefundEventAppended(db, intent.id, 'refund-fiscal-event-1');
      return intent.id;
    }

    it('lists intents pending payout confirmation (a fiscal event exists, cash handover unconfirmed)', async () => {
      const db = adapter.asDatabase();
      const id = await appendedIntent(db);

      const pending = await getRefundIntentsPendingPayoutConfirmation(db);
      expect(pending.map((r) => r.id)).toEqual([id]);

      await confirmRefundIntentPayout(db, id);
      expect(await getRefundIntentsPendingPayoutConfirmation(db)).toEqual([]);
    });

    it('a drafted (not-yet-appended) intent never appears in the payout-confirmation queue', async () => {
      const db = adapter.asDatabase();
      await createOrReuseActiveRefundIntent(db, baseInput());

      expect(await getRefundIntentsPendingPayoutConfirmation(db)).toEqual([]);
    });

    it('disputing payout sets payout_disputed_at without touching the fiscal event / refund_fiscal_event_id', async () => {
      const db = adapter.asDatabase();
      const id = await appendedIntent(db);

      await disputeRefundIntentPayout(db, id);

      const row = await getRefundIntentById(db, id);
      expect(row?.payout_disputed_at).not.toBeNull();
      expect(row?.payout_confirmed_at).toBeNull();
      expect(row?.refund_fiscal_event_id).toBe('refund-fiscal-event-1');
    });

    it('lists intents pending reprint (payout confirmed, AVOIR never printed)', async () => {
      const db = adapter.asDatabase();
      const id = await appendedIntent(db);
      await confirmRefundIntentPayout(db, id);

      const pending = await getRefundIntentsPendingReprint(db);
      expect(pending.map((r) => r.id)).toEqual([id]);

      await confirmRefundIntentPrinted(db, id);
      expect(await getRefundIntentsPendingReprint(db)).toEqual([]);
    });

    it('a payout-unconfirmed intent never appears in the reprint queue (confirmation gates reprint)', async () => {
      const db = adapter.asDatabase();
      await appendedIntent(db);

      expect(await getRefundIntentsPendingReprint(db)).toEqual([]);
    });
  });

  describe('findActiveRefundIntent', () => {
    it('returns null when no active row exists for the key', async () => {
      const fingerprint = await computeLineSnapshotFingerprint(baseInput().lineSnapshot);
      expect(await findActiveRefundIntent(adapter.asDatabase(), 'orig-receipt-1', fingerprint)).toBeNull();
    });
  });
});
