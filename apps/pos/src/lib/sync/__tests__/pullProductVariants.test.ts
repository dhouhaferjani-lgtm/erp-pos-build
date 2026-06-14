/**
 * FV2 — pullProductVariants sync (delta + tombstone + product-cascade + updated_until window).
 *
 * Contract under test:
 *   - full pull (no cursor): fetchVariants called with no updated_since/updated_until on
 *     page 1; variants upserted; cursor `product_variants_as_of` persisted from `as_of`.
 *   - delta pull (cursor present): fetchVariants page 1 sends updated_since=cursor (no
 *     updated_until); `deleteVariantsById(deleted_ids)` applied.
 *   - multi-page: when meta says last_page=2, page 2 is fetched WITH `updated_until` =
 *     page-1's as_of (M1 fix H-1 — single snapshot window).
 *   - Menu tenants and non-standard gates short-circuit to {count: 0} without any API call.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';

// ── vi.mock calls (hoisted) ───────────────────────────────────────────────────

vi.mock('@/api/variantSyncApi', () => ({
  fetchVariants: vi.fn(),
}));

vi.mock('@/lib/db/repositories/variantRepository', () => ({
  upsertVariants: vi.fn(),
  deleteVariantsById: vi.fn(),
  deleteVariantsForProducts: vi.fn(),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn(),
  logSyncOperation: vi.fn(),
  cleanupOldSyncLogs: vi.fn(),
}));

// productStore mock for the catalog gate (standard tenant has POS module only)
vi.mock('@/stores/productStore', () => ({
  useProductStore: {
    getState: () => ({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['POS'],
      },
    }),
  },
  hasModule: (config: { all_enabled_modules: string[] }, mod: string) =>
    config.all_enabled_modules.includes(mod),
}));

// Stub DB helpers — not called by pullProductVariants directly but syncService
// imports them at module load.
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
}));

// Stub remaining syncService dependencies so the module resolves cleanly.
vi.mock('@/lib/db/repositories/locationStockRepository', () => ({
  upsertStockRows: vi.fn(),
  replaceAllStock: vi.fn(),
  replaceIncoming: vi.fn(),
  deleteForProducts: vi.fn(),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  upsertProducts: vi.fn(),
  deleteProducts: vi.fn(),
  reconcileMenuProducts: vi.fn(),
}));

vi.mock('@/api/productApi', () => ({
  fetchCompanyConfig: vi.fn(),
  fetchPOSProducts: vi.fn(),
  fetchProductByBarcode: vi.fn(),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

vi.mock('@/api/stockApi', () => ({
  fetchLocationStock: vi.fn(),
}));

// ── Imports (after mocks) ─────────────────────────────────────────────────────

import { pullProductVariants } from '@/lib/sync/syncService';
import { fetchVariants } from '@/api/variantSyncApi';
import type { VariantFeedPage } from '@/api/variantSyncApi';
import {
  upsertVariants,
  deleteVariantsById,
} from '@/lib/db/repositories/variantRepository';
import {
  getSyncMetadata,
  setSyncMetadata,
  logSyncOperation,
} from '@/lib/db/repositories/syncLogRepository';
import type { ServerVariantRow } from '@/lib/db/repositories/variantRepository';

// ── Helpers ───────────────────────────────────────────────────────────────────

const db = {} as Database;

const VARIANTS_CURSOR_KEY = 'product_variants_as_of';

function variantRow(id: string): ServerVariantRow {
  return {
    id,
    product_id: `product-${id}`,
    sku: `SKU-${id}`,
    barcode: null,
    name_suffix: 'Default',
    price_override: null,
    image_url: null,
    is_default: true,
    display_order: 0,
    updated_at: '2026-06-10T08:00:00Z',
  };
}

function makePage(args: {
  variants?: ServerVariantRow[];
  deleted_ids?: string[];
  asOf?: string;
  current?: number;
  last?: number;
  total?: number;
}): VariantFeedPage {
  return {
    data: {
      variants: args.variants ?? [],
      deleted_ids: args.deleted_ids ?? [],
      as_of: args.asOf ?? '2026-06-11T12:00:00Z',
    },
    meta: {
      pagination: {
        current_page: args.current ?? 1,
        last_page: args.last ?? 1,
        total: args.total ?? (args.variants?.length ?? 0),
      },
    },
  };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSyncMetadata).mockResolvedValue(null);
});

describe('pullProductVariants — full pull (no cursor)', () => {
  it('fetches page 1 with no updated_since / updated_until; upserts variants; persists cursor', async () => {
    // No cursor stored → full pull
    vi.mocked(getSyncMetadata).mockResolvedValue(null);
    vi.mocked(fetchVariants).mockResolvedValueOnce(
      makePage({ variants: [variantRow('v1')], asOf: '2026-06-11T12:00:00Z' }),
    );

    const result = await pullProductVariants(db);

    expect(result.count).toBe(1);
    expect(fetchVariants).toHaveBeenCalledTimes(1);

    const [params] = vi.mocked(fetchVariants).mock.calls[0]!;
    expect(params.updated_since).toBeUndefined();
    expect(params.updated_until).toBeUndefined();
    expect(params.page).toBe('1');

    expect(upsertVariants).toHaveBeenCalledWith(db, [variantRow('v1')]);

    const cursorWrites = vi
      .mocked(setSyncMetadata)
      .mock.calls.filter(([, key]) => key === VARIANTS_CURSOR_KEY);
    expect(cursorWrites).toHaveLength(1);
    expect(cursorWrites[0]![2]).toBe('2026-06-11T12:00:00Z');
  });
});

describe('pullProductVariants — delta pull (cursor present)', () => {
  it('sends updated_since=cursor; applies deleteVariantsById for deleted_ids; stores new as_of', async () => {
    const storedCursor = '2026-06-10T09:30:00Z';
    vi.mocked(getSyncMetadata).mockImplementation((_db, key) =>
      Promise.resolve(key === VARIANTS_CURSOR_KEY ? storedCursor : null),
    );
    vi.mocked(fetchVariants).mockResolvedValueOnce(
      makePage({
        variants: [variantRow('v2')],
        deleted_ids: ['dead-v1', 'dead-v2'],
        asOf: '2026-06-11T15:00:00Z',
      }),
    );

    const result = await pullProductVariants(db);

    expect(result.count).toBe(1);
    expect(fetchVariants).toHaveBeenCalledTimes(1);

    const [params] = vi.mocked(fetchVariants).mock.calls[0]!;
    expect(params.updated_since).toBe(storedCursor);
    // Page 1 has no updated_until (server mints as_of)
    expect(params.updated_until).toBeUndefined();

    expect(deleteVariantsById).toHaveBeenCalledWith(db, ['dead-v1', 'dead-v2']);
    expect(upsertVariants).toHaveBeenCalledWith(db, [variantRow('v2')]);

    const cursorWrites = vi
      .mocked(setSyncMetadata)
      .mock.calls.filter(([, key]) => key === VARIANTS_CURSOR_KEY);
    expect(cursorWrites[0]![2]).toBe('2026-06-11T15:00:00Z');
  });
});

describe('pullProductVariants — multi-page: updated_until threading (M1 fix H-1)', () => {
  it('page 2 receives updated_until = page-1 as_of to pin the snapshot window', async () => {
    const storedCursor = '2026-06-10T09:30:00Z';
    vi.mocked(getSyncMetadata).mockImplementation((_db, key) =>
      Promise.resolve(key === VARIANTS_CURSOR_KEY ? storedCursor : null),
    );

    const page1AsOf = '2026-06-11T12:00:00Z';

    vi.mocked(fetchVariants)
      .mockResolvedValueOnce(
        makePage({
          variants: [variantRow('v1')],
          deleted_ids: [],
          asOf: page1AsOf,
          current: 1,
          last: 2,
        }),
      )
      .mockResolvedValueOnce(
        makePage({
          variants: [variantRow('v2')],
          deleted_ids: [],
          asOf: '2026-06-11T12:00:05Z',
          current: 2,
          last: 2,
        }),
      );

    const result = await pullProductVariants(db);

    expect(result.count).toBe(2);
    expect(fetchVariants).toHaveBeenCalledTimes(2);

    // Page 1: no updated_until
    const [p1Params] = vi.mocked(fetchVariants).mock.calls[0]!;
    expect(p1Params.page).toBe('1');
    expect(p1Params.updated_since).toBe(storedCursor);
    expect(p1Params.updated_until).toBeUndefined();

    // Page 2: updated_until MUST equal page-1's as_of (snapshot window pinned)
    const [p2Params] = vi.mocked(fetchVariants).mock.calls[1]!;
    expect(p2Params.page).toBe('2');
    expect(p2Params.updated_since).toBe(storedCursor);
    expect(p2Params.updated_until).toBe(page1AsOf);

    // All variants accumulated in one write
    expect(upsertVariants).toHaveBeenCalledTimes(1);
    expect(upsertVariants).toHaveBeenCalledWith(db, [variantRow('v1'), variantRow('v2')]);

    // Cursor set to page-1's as_of
    const cursorWrites = vi
      .mocked(setSyncMetadata)
      .mock.calls.filter(([, key]) => key === VARIANTS_CURSOR_KEY);
    expect(cursorWrites).toHaveLength(1);
    expect(cursorWrites[0]![2]).toBe(page1AsOf);
  });
});

describe('pullProductVariants — success log', () => {
  it('logs a success entry after a clean pull', async () => {
    vi.mocked(fetchVariants).mockResolvedValueOnce(
      makePage({ variants: [variantRow('v1')] }),
    );

    await pullProductVariants(db);

    expect(logSyncOperation).toHaveBeenCalledWith(
      db,
      'pull',
      'product_variants',
      null,
      'success',
      expect.stringContaining('upserted'),
    );
  });
});
