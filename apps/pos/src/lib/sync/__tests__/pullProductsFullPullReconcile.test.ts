/**
 * DEV-QA-111 — a product soft-deleted on the web stayed on the POS device
 * forever, rendered as a permanently greyed « Rupture » tile.
 *
 * Root cause (see `docs/sessions/2026-09-18-pos-deleted-products/root-cause.md`):
 * `pullProductsCore` is upsert-only, and the server emits `deleted_ids` ONLY
 * when the device sends an `updated_since` cursor
 * (`ProductController.php:186-203`). Migrations v57 / v58
 * (`apps/pos/src/lib/db/migrations.ts:1788, 1827`) both
 * `DELETE FROM sync_metadata WHERE key = 'products_last_sync'`, so the pull
 * right after an app update is cursor-less — no tombstone is ever sent, and
 * the id is in no later `data` page either (it is soft-deleted). The
 * "full re-fetch" that was supposed to be the self-healing path was in fact
 * "upsert forever".
 *
 * Fix (root-cause report option A, POS-side): on a **completed, cursor-less**
 * pull the returned id set is authoritative for ABSENCE too — cached bare rows
 * missing from it are run through the SAME delete cascade `deleted_ids` uses.
 *
 * These tests pin the four boundaries of that reconcile:
 *   1. completed full pull purges an id the server stopped returning;
 *   2. an INCREMENTAL pull never purges on absence (the server is only sending
 *      a delta there — every unreturned id is simply unchanged, not deleted);
 *   3. a failed / partial full pull purges nothing (a mid-pull throw must never
 *      wipe a live catalogue);
 *   4. a successful but EMPTY full pull purges nothing (defensive no-op, same
 *      guard as `pruneStaleCompositeRows`, productRepository.ts:308-310).
 *
 * The data-level half — the reconcile's scoping boundary and cascade safety
 * against a REAL SQLite engine — lives in
 * `src/lib/db/repositories/__tests__/productRepository.bareIds.integration.test.ts`
 * (this file mocks `@/lib/db`, so it cannot drive the real repository).
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return { ...actual, apiGet: vi.fn() };
});

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  upsertProducts: vi.fn().mockResolvedValue(undefined),
  deleteProducts: vi.fn().mockResolvedValue(undefined),
  getBareProductIds: vi.fn().mockResolvedValue([]),
  reconcileMenuProducts: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/locationStockRepository', () => ({
  deleteForProducts: vi.fn().mockResolvedValue(undefined),
  upsertStockRows: vi.fn().mockResolvedValue(undefined),
  replaceAllStock: vi.fn().mockResolvedValue(undefined),
  replaceIncoming: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/crossLocationStockRepository', () => ({
  deleteDistributionForProducts: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/variantRepository', () => ({
  upsertVariants: vi.fn().mockResolvedValue(undefined),
  deleteVariantsById: vi.fn().mockResolvedValue(undefined),
  deleteVariantsForProducts: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
  cleanupOldSyncLogs: vi.fn().mockResolvedValue(undefined),
}));

import { pullProductsCore, PullProductsError } from '@/lib/sync/syncService';
import { apiGet, ApiRequestError } from '@/lib/api';
import {
  upsertProducts,
  deleteProducts,
  getBareProductIds,
} from '@/lib/db/repositories/productRepository';
import { deleteForProducts } from '@/lib/db/repositories/locationStockRepository';
import { deleteDistributionForProducts } from '@/lib/db/repositories/crossLocationStockRepository';
import { deleteVariantsForProducts } from '@/lib/db/repositories/variantRepository';
import { getSyncMetadata, setSyncMetadata } from '@/lib/db/repositories/syncLogRepository';

const db = {} as Parameters<typeof pullProductsCore>[0];

function product(id: string, name = 'Widget') {
  return {
    id,
    name,
    sku: `SKU-${id}`,
    barcode: null,
    sale_price: '10.000',
    stock_quantity: 5,
    is_physical: true,
  };
}

describe('pullProductsCore — full-pull reconciliation (DEV-QA-111)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(getSyncMetadata).mockResolvedValue(null);
    vi.mocked(getBareProductIds).mockResolvedValue([]);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('sends NO updated_since when the cursor is absent (the pull really is a full pull)', async () => {
    vi.mocked(apiGet).mockResolvedValue({ data: [product('keep-1')] });

    await pullProductsCore(db);

    expect(apiGet).toHaveBeenCalledWith(
      '/products',
      expect.not.objectContaining({ updated_since: expect.anything() }),
      expect.anything(),
    );
  });

  it('purges a cached product the server no longer returns on a COMPLETED cursor-less pull', async () => {
    // The PharmaBio case: `deleted-on-web` was soft-deleted while the device
    // had no cursor, so it comes back neither in `data` nor in `deleted_ids`.
    vi.mocked(apiGet).mockResolvedValue({ data: [product('keep-1')] });
    vi.mocked(getBareProductIds).mockResolvedValue(['keep-1', 'deleted-on-web']);

    await pullProductsCore(db);

    expect(upsertProducts).toHaveBeenCalledWith(db, [product('keep-1')]);
    expect(deleteProducts).toHaveBeenCalledWith(db, ['deleted-on-web']);
  });

  it('runs the SAME cascade deleted_ids uses (location stock, distribution cache, variants)', async () => {
    vi.mocked(apiGet).mockResolvedValue({ data: [product('keep-1')] });
    vi.mocked(getBareProductIds).mockResolvedValue(['keep-1', 'deleted-on-web']);

    await pullProductsCore(db);

    expect(deleteForProducts).toHaveBeenCalledWith(db, ['deleted-on-web']);
    expect(deleteDistributionForProducts).toHaveBeenCalledWith(db, ['deleted-on-web']);
    expect(deleteVariantsForProducts).toHaveBeenCalledWith(db, ['deleted-on-web']);
  });

  it('reconciles across ALL pages of a multi-page full pull (a page-2 id is not a stale id)', async () => {
    const pageOne = Array.from({ length: 500 }, (_, i) => product(`p${i}`));
    vi.mocked(apiGet)
      .mockResolvedValueOnce({ data: pageOne })
      .mockResolvedValueOnce({ data: [product('p500')] });
    vi.mocked(getBareProductIds).mockResolvedValue(['p0', 'p500', 'deleted-on-web']);

    await pullProductsCore(db);

    expect(deleteProducts).toHaveBeenCalledWith(db, ['deleted-on-web']);
  });

  it('merges reconciled ids with server deleted_ids without duplicating an id present in both', async () => {
    vi.mocked(apiGet).mockResolvedValue({
      data: [product('keep-1')],
      deleted_ids: ['tombstoned-1', 'in-both'],
    });
    vi.mocked(getBareProductIds).mockResolvedValue(['keep-1', 'in-both', 'only-reconciled']);

    await pullProductsCore(db);

    const ids = vi.mocked(deleteProducts).mock.calls[0]![1];
    expect([...ids].sort()).toEqual(['in-both', 'only-reconciled', 'tombstoned-1']);
    expect(ids.filter((id) => id === 'in-both')).toHaveLength(1);
  });

  it('advances the products_last_sync cursor when the pull only reconciled (no upserts, no deleted_ids)', async () => {
    vi.mocked(apiGet).mockResolvedValue({ data: [product('keep-1')] });
    vi.mocked(getBareProductIds).mockResolvedValue(['keep-1', 'deleted-on-web']);

    await pullProductsCore(db);

    expect(setSyncMetadata).toHaveBeenCalledWith(db, 'products_last_sync', expect.any(String));
  });

  describe('boundaries — the reconcile must NOT fire', () => {
    it('does NOT purge on an INCREMENTAL pull: absence from a delta means "unchanged", not "deleted"', async () => {
      vi.mocked(getSyncMetadata).mockResolvedValue('2026-09-17T10:00:00.000Z');
      vi.mocked(apiGet).mockResolvedValue({ data: [product('keep-1')] });
      vi.mocked(getBareProductIds).mockResolvedValue(['keep-1', 'not-in-this-delta']);

      await pullProductsCore(db);

      expect(apiGet).toHaveBeenCalledWith(
        '/products',
        expect.objectContaining({ updated_since: '2026-09-17T10:00:00.000Z' }),
        expect.anything(),
      );
      expect(deleteProducts).not.toHaveBeenCalled();
      // Not even read: an incremental pull must not pay for a cache scan.
      expect(getBareProductIds).not.toHaveBeenCalled();
    });

    it('still honours deleted_ids on an incremental pull (the delta path is unchanged)', async () => {
      vi.mocked(getSyncMetadata).mockResolvedValue('2026-09-17T10:00:00.000Z');
      vi.mocked(apiGet).mockResolvedValue({
        data: [product('keep-1')],
        deleted_ids: ['gone-1'],
      });
      vi.mocked(getBareProductIds).mockResolvedValue(['keep-1', 'not-in-this-delta']);

      await pullProductsCore(db);

      expect(deleteProducts).toHaveBeenCalledWith(db, ['gone-1']);
    });

    it('purges NOTHING when a full pull throws mid-loop (partial page must never wipe a live catalogue)', async () => {
      const pageOne = Array.from({ length: 500 }, (_, i) => product(`p${i}`));
      vi.mocked(apiGet)
        .mockResolvedValueOnce({ data: pageOne })
        .mockRejectedValueOnce(new ApiRequestError(503, 'boom', 'server_error'));
      vi.mocked(getBareProductIds).mockResolvedValue(['p0', 'deleted-on-web']);

      await expect(pullProductsCore(db)).rejects.toBeInstanceOf(PullProductsError);

      expect(deleteProducts).not.toHaveBeenCalled();
      expect(deleteForProducts).not.toHaveBeenCalled();
      expect(setSyncMetadata).not.toHaveBeenCalled();
    });

    it('purges NOTHING when a full pull succeeds but returns an EMPTY catalogue (defensive no-op)', async () => {
      vi.mocked(apiGet).mockResolvedValue({ data: [] });
      vi.mocked(getBareProductIds).mockResolvedValue(['p1', 'p2']);

      await pullProductsCore(db);

      expect(deleteProducts).not.toHaveBeenCalled();
    });
  });
});
