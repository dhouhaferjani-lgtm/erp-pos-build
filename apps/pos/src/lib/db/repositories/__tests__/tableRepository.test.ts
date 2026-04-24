import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import {
  upsertFloors,
  upsertTables,
  getAllFloorsWithTables,
  deleteFloors,
  deleteTables,
} from '../tableRepository';
import { queryAll, execute } from '@/lib/db';

describe('tableRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => { vi.clearAllMocks(); });

  it('upsertFloors is a no-op when empty', async () => {
    await upsertFloors(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('upsertFloors writes one row per floor', async () => {
    await upsertFloors(db, [
      { id: 'f1', name: 'Main', position: 0, is_active: true, updated_at: '2026-04-23T00:00:00Z' },
      { id: 'f2', name: 'Patio', position: 1, is_active: true, updated_at: '2026-04-23T00:00:00Z' },
    ]);
    expect(execute).toHaveBeenCalledTimes(2);
    expect(vi.mocked(execute).mock.calls[0]![1]).toMatch(/INSERT INTO floors/);
  });

  it('upsertTables writes one row per table', async () => {
    await upsertTables(db, [
      {
        id: 't1', floor_id: 'f1', table_number: '1', label: null, seats: 4,
        status: 'available', shape: null, position_x: null, position_y: null,
        width: null, height: null, current_order_id: null,
        updated_at: '2026-04-23T00:00:00Z',
      },
    ]);
    expect(execute).toHaveBeenCalledTimes(1);
    expect(vi.mocked(execute).mock.calls[0]![1]).toMatch(/INSERT INTO tables/);
  });

  it('getAllFloorsWithTables hydrates floors with their tables', async () => {
    vi.mocked(queryAll).mockResolvedValueOnce([
      { id: 'f1', name: 'Main', position: 0, is_active: 1, updated_at: 'x' },
    ]);
    vi.mocked(queryAll).mockResolvedValueOnce([
      { id: 't1', floor_id: 'f1', table_number: '1', label: null, seats: 4, status: 'available', shape: null, position_x: null, position_y: null, width: null, height: null, current_order_id: null, updated_at: 'x' },
    ]);

    const floors = await getAllFloorsWithTables(db);

    expect(floors).toHaveLength(1);
    expect(floors[0]?.tables).toHaveLength(1);
    expect(floors[0]?.tables?.[0]?.floor_id).toBe('f1');
  });

  it('deleteFloors/deleteTables batch up to 200 ids per statement', async () => {
    const ids = Array.from({ length: 250 }, (_, i) => `id-${i}`);
    await deleteFloors(db, ids);
    expect(execute).toHaveBeenCalledTimes(2);

    vi.clearAllMocks();
    await deleteTables(db, ids);
    expect(execute).toHaveBeenCalledTimes(2);
  });
});
