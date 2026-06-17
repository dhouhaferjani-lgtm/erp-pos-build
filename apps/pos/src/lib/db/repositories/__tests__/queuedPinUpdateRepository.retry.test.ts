/**
 * Retry-path coverage for queued PIN updates.
 *
 * The original `getPendingPinUpdates` selected only `status = 'pending'`, while
 * `markPinUpdateFailed` flips a row to `'failed'` — so a single transient
 * push failure stranded the queued PIN forever (it was never re-selected for
 * retry). This mirrors the fiscal-event / cash-drawer / audit outbox pattern:
 * pending AND failed rows under a retry cap are retried; rows past the cap are
 * dead-lettered (left for a recovery path, not silently retried forever).
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  enqueuePinUpdate,
  getPendingPinUpdates,
  markPinUpdateFailed,
} from '../queuedPinUpdateRepository';

describe('queuedPinUpdateRepository — retry path', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('re-selects a failed row under the retry cap', async () => {
    const db = adapter.asDatabase();
    await enqueuePinUpdate(db, { userId: 'u1', pinHash: 'h1' });
    const [row] = await getPendingPinUpdates(db);
    await markPinUpdateFailed(db, row!.id, 'network blip');

    const pending = await getPendingPinUpdates(db);

    expect(pending).toHaveLength(1);
    expect(pending[0]?.userId).toBe('u1');
    expect(pending[0]?.status).toBe('failed');
    expect(pending[0]?.retryCount).toBe(1);
  });

  it('dead-letters a row once it reaches the retry cap (5)', async () => {
    const db = adapter.asDatabase();
    await enqueuePinUpdate(db, { userId: 'u1', pinHash: 'h1' });
    const [row] = await getPendingPinUpdates(db);
    for (let i = 0; i < 5; i++) {
      await markPinUpdateFailed(db, row!.id, 'down');
    }

    const pending = await getPendingPinUpdates(db);

    expect(pending).toHaveLength(0);
  });

  it('still returns brand-new pending rows', async () => {
    const db = adapter.asDatabase();
    await enqueuePinUpdate(db, { userId: 'u1', pinHash: 'h1' });

    const pending = await getPendingPinUpdates(db);

    expect(pending).toHaveLength(1);
    expect(pending[0]?.status).toBe('pending');
  });
});
