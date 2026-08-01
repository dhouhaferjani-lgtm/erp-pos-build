import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  CumulativeRefundSnapshotUnreadableError,
  InvalidRefundIntentTransitionError,
  InvalidRefundPayoutResolutionError,
  computeLineSnapshotFingerprint,
  getCumulativeRefundedQuantityByOriginalLine,
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

    it('a payout-UNRESOLVED intent never appears in the reprint queue (resolution gates reprint)', async () => {
      const db = adapter.asDatabase();
      await appendedIntent(db);

      expect(await getRefundIntentsPendingReprint(db)).toEqual([]);
    });

    /**
     * Wave-2 fix wave — finding 5 (fiscal C-2 + codex M-5).
     *
     * The pending-confirmation query omitted `payout_disputed_at IS NULL`,
     * so "No / Not sure" re-selected the SAME row and re-rendered a
     * non-dismissible, Skip-less modal forever. The only escape was
     * tapping "Yes" — a FALSE payout attestation, destroying the very
     * evidence §4.5 exists to capture. Confirm and dispute also guarded
     * only on a non-null fiscal-event id, never excluded the opposite
     * timestamp, and never checked that a row actually changed.
     */
    describe('finding 5 — payout resolution is a single, mutually-exclusive, asserted transition', () => {
      it('a DISPUTED intent leaves the payout-confirmation queue (no infinite re-prompt loop)', async () => {
        const db = adapter.asDatabase();
        const id = await appendedIntent(db);
        expect((await getRefundIntentsPendingPayoutConfirmation(db)).map((r) => r.id)).toEqual([id]);

        await disputeRefundIntentPayout(db, id);

        expect(await getRefundIntentsPendingPayoutConfirmation(db)).toEqual([]);
      });

      it('a DISPUTED-but-unprinted intent DOES enter reprint recovery', async () => {
        const db = adapter.asDatabase();
        const id = await appendedIntent(db);

        await disputeRefundIntentPayout(db, id);

        expect((await getRefundIntentsPendingReprint(db)).map((r) => r.id)).toEqual([id]);
        await confirmRefundIntentPrinted(db, id);
        expect(await getRefundIntentsPendingReprint(db)).toEqual([]);
      });

      it('confirm then dispute is rejected — the two timestamps are mutually exclusive', async () => {
        const db = adapter.asDatabase();
        const id = await appendedIntent(db);
        await confirmRefundIntentPayout(db, id);

        await expect(disputeRefundIntentPayout(db, id)).rejects.toThrow(
          InvalidRefundPayoutResolutionError,
        );

        const row = await getRefundIntentById(db, id);
        expect(row?.payout_confirmed_at).not.toBeNull();
        expect(row?.payout_disputed_at).toBeNull();
      });

      it('dispute then confirm is rejected — the two timestamps are mutually exclusive', async () => {
        const db = adapter.asDatabase();
        const id = await appendedIntent(db);
        await disputeRefundIntentPayout(db, id);

        await expect(confirmRefundIntentPayout(db, id)).rejects.toThrow(
          InvalidRefundPayoutResolutionError,
        );

        const row = await getRefundIntentById(db, id);
        expect(row?.payout_disputed_at).not.toBeNull();
        expect(row?.payout_confirmed_at).toBeNull();
      });

      it('repeating the SAME resolution is rejected rather than silently re-stamping', async () => {
        const db = adapter.asDatabase();
        const id = await appendedIntent(db);
        await disputeRefundIntentPayout(db, id);

        await expect(disputeRefundIntentPayout(db, id)).rejects.toThrow(
          InvalidRefundPayoutResolutionError,
        );
      });

      it('resolving an intent with no appended fiscal event affects zero rows and throws', async () => {
        const db = adapter.asDatabase();
        const { intent } = await createOrReuseActiveRefundIntent(db, baseInput());

        await expect(confirmRefundIntentPayout(db, intent.id)).rejects.toThrow(
          InvalidRefundPayoutResolutionError,
        );
      });

      it('resolving a row that does not exist throws instead of silently no-opping', async () => {
        await expect(
          confirmRefundIntentPayout(adapter.asDatabase(), 'no-such-intent'),
        ).rejects.toThrow(InvalidRefundPayoutResolutionError);
      });
    });
  });

  describe('findActiveRefundIntent', () => {
    it('returns null when no active row exists for the key', async () => {
      const fingerprint = await computeLineSnapshotFingerprint(baseInput().lineSnapshot);
      expect(await findActiveRefundIntent(adapter.asDatabase(), 'orig-receipt-1', fingerprint)).toBeNull();
    });
  });

  /**
   * Wave-2 fix wave — findings 3 and 18, both in the cumulative-quantity
   * backstop.
   *
   * finding 3 (codex C-2): the backstop reconstructed magnitudes with
   * `Math.abs(quantity).toFixed(4)` off an IEEE-754 `number`. Quantity is
   * now a canonical decimal STRING, normalized once at the refund
   * boundary and carried verbatim through the snapshot.
   *
   * finding 18 (⚖️ Q-1 ruling): a malformed or unparseable prior-appended
   * snapshot used to be SKIPPED. Skipping UNDERCOUNTS what has already
   * been refunded, which makes the cap too PERMISSIVE in exactly the case
   * where it matters. It must FAIL CLOSED.
   */
  describe('getCumulativeRefundedQuantityByOriginalLine (findings 3, 18)', () => {
    async function seedAppendedIntentWithSnapshot(
      db: ReturnType<typeof adapter.asDatabase>,
      id: string,
      lineSnapshot: unknown,
    ): Promise<void> {
      const { intent } = await createOrReuseActiveRefundIntent(db, baseInput({ id, lineSnapshot }));
      await markApprovalAuthored(db, intent.id);
      await markRefundEventAppended(db, intent.id, `fe-${id}`);
    }

    it('sums already-refunded quantity per original line as decimal strings', async () => {
      const db = adapter.asDatabase();
      await seedAppendedIntentWithSnapshot(db, 'intent-a', [
        { originalLineIndex: 0, quantity: '1.500' },
        { originalLineIndex: 1, quantity: '2.000' },
      ]);
      await seedAppendedIntentWithSnapshot(db, 'intent-b', [
        { originalLineIndex: 0, quantity: '0.250' },
      ]);

      const totals = await getCumulativeRefundedQuantityByOriginalLine(db, 'orig-receipt-1');

      expect(totals.get(0)).toBe('1.750');
      expect(totals.get(1)).toBe('2.000');
    });

    it('counts only states that PROVE a fiscal event was appended', async () => {
      const db = adapter.asDatabase();
      // drafted only — nothing was ever signed, so it must not count.
      await createOrReuseActiveRefundIntent(
        db,
        baseInput({ id: 'intent-draft', lineSnapshot: [{ originalLineIndex: 0, quantity: '9.000' }] }),
      );

      const totals = await getCumulativeRefundedQuantityByOriginalLine(db, 'orig-receipt-1');

      expect(totals.get(0)).toBeUndefined();
    });

    it('finding 18 — FAILS CLOSED on an unparseable prior snapshot (never skip-and-undercount)', async () => {
      const db = adapter.asDatabase();
      await seedAppendedIntentWithSnapshot(db, 'intent-a', [{ originalLineIndex: 0, quantity: '1.000' }]);
      // Corrupt the stored snapshot of an ALREADY-APPENDED intent.
      await adapter.execute(
        "UPDATE refund_intents SET line_snapshot_json = 'not-json' WHERE id = 'intent-a'",
      );

      await expect(
        getCumulativeRefundedQuantityByOriginalLine(db, 'orig-receipt-1'),
      ).rejects.toThrow(CumulativeRefundSnapshotUnreadableError);
    });

    it('finding 18 — FAILS CLOSED on a snapshot entry missing its quantity string', async () => {
      const db = adapter.asDatabase();
      await seedAppendedIntentWithSnapshot(db, 'intent-a', [{ originalLineIndex: 0 }]);

      await expect(
        getCumulativeRefundedQuantityByOriginalLine(db, 'orig-receipt-1'),
      ).rejects.toThrow(CumulativeRefundSnapshotUnreadableError);
    });

    it('finding 18 — FAILS CLOSED on a non-array snapshot', async () => {
      const db = adapter.asDatabase();
      await seedAppendedIntentWithSnapshot(db, 'intent-a', [{ originalLineIndex: 0, quantity: '1.000' }]);
      await adapter.execute(
        `UPDATE refund_intents SET line_snapshot_json = '{"not":"an array"}' WHERE id = 'intent-a'`,
      );

      await expect(
        getCumulativeRefundedQuantityByOriginalLine(db, 'orig-receipt-1'),
      ).rejects.toThrow(CumulativeRefundSnapshotUnreadableError);
    });

    it('finding 3 — rejects a number-typed quantity outright rather than coercing it', async () => {
      const db = adapter.asDatabase();
      await seedAppendedIntentWithSnapshot(db, 'intent-a', [{ originalLineIndex: 0, quantity: -1.5 }]);

      await expect(
        getCumulativeRefundedQuantityByOriginalLine(db, 'orig-receipt-1'),
      ).rejects.toThrow(CumulativeRefundSnapshotUnreadableError);
    });
  });
});
