import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import {
  enqueuePinUpdate,
  getPendingPinUpdates,
  markPinUpdateSynced,
  deletePinUpdate,
} from '../queuedPinUpdateRepository';
import { queryAll, execute } from '@/lib/db';

describe('queuedPinUpdateRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('enqueues a pending PIN update', async () => {
    await enqueuePinUpdate(db, { userId: 'user-1', pinHash: '$2a$10$abc' });

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/INSERT INTO queued_pin_updates/);
    expect(params).toEqual(expect.arrayContaining(['user-1', '$2a$10$abc', 'pending']));
  });

  it('selects pending and failed rows under the retry cap', async () => {
    vi.mocked(queryAll).mockResolvedValue([
      { id: 1, user_id: 'u1', pin_hash: 'h1', status: 'pending', created_at: '...', synced_at: null, retry_count: 0 },
    ]);

    const rows = await getPendingPinUpdates(db);

    expect(rows).toHaveLength(1);
    expect(rows[0]?.userId).toBe('u1');
    const [, sql] = vi.mocked(queryAll).mock.calls[0]!;
    expect(sql).toMatch(/WHERE status IN \('pending', 'failed'\)/);
    expect(sql).toMatch(/retry_count < \$1/);
  });

  it('marks a row as synced and records synced_at', async () => {
    await markPinUpdateSynced(db, 42);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/UPDATE queued_pin_updates SET status = 'synced'/);
    expect(params).toContain(42);
  });

  it('deletes a row by id', async () => {
    await deletePinUpdate(db, 7);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/DELETE FROM queued_pin_updates WHERE id = \$1/);
    expect(params).toEqual([7]);
  });
});
