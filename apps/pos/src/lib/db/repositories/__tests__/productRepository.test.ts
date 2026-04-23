import { describe, it, expect, vi, beforeEach } from 'vitest';
import { deleteProducts } from '../productRepository';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import { execute } from '@/lib/db';

describe('productRepository.deleteProducts', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('is a no-op when ids is empty', async () => {
    await deleteProducts(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('issues a single DELETE for all ids', async () => {
    await deleteProducts(db, ['p1', 'p2', 'p3']);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/DELETE FROM products WHERE id IN \(\$1, \$2, \$3\)/);
    expect(params).toEqual(['p1', 'p2', 'p3']);
  });

  it('batches deletions when there are more than 200 ids', async () => {
    const ids = Array.from({ length: 250 }, (_, i) => `p${i}`);
    await deleteProducts(db, ids);

    expect(execute).toHaveBeenCalledTimes(2);
    const firstBatchParams = vi.mocked(execute).mock.calls[0]![2] as unknown[];
    const secondBatchParams = vi.mocked(execute).mock.calls[1]![2] as unknown[];
    expect(firstBatchParams.length).toBe(200);
    expect(secondBatchParams.length).toBe(50);
  });
});
