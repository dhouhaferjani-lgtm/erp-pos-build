import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useMostSoldCounts } from '../useMostSoldCounts';

vi.mock('@/lib/db/repositories/productSalesAggregateRepository', () => ({
  aggregateProductSales: vi.fn(async () => new Map([['p1', 5], ['p2', 2]])),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({} as never)),
}));

describe('useMostSoldCounts', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns counts on mount when enabled', async () => {
    const { result } = renderHook(() => useMostSoldCounts({ companyId: 'c1', enabled: true }));
    await waitFor(() => {
      expect(result.current.counts.size).toBe(2);
    });
    expect(result.current.counts.get('p1')).toBe(5);
  });

  it('returns an empty Map and does NOT read the DB when disabled', async () => {
    const repo = await import('@/lib/db/repositories/productSalesAggregateRepository');
    const { result } = renderHook(() => useMostSoldCounts({ companyId: 'c1', enabled: false }));
    await waitFor(() => {
      expect(result.current.counts.size).toBe(0);
    });
    expect(repo.aggregateProductSales).not.toHaveBeenCalled();
  });
});
