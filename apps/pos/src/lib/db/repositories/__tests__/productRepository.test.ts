import { describe, it, expect, vi, beforeEach } from 'vitest';
import { deleteProducts, upsertProducts, getAllProducts } from '../productRepository';
import type { POSProduct } from '@/types/product';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import { execute, queryAll } from '@/lib/db';

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
    expect(sql).toContain('DELETE FROM products WHERE id IN ($1, $2, $3)');
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

describe('productRepository — brand + parapharmacy_metadata', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  // ── (a) Read-path round-trip ──────────────────────────────────────────────

  it('rowToProduct parses brand_id, brand_name, and parapharmacy_metadata JSON', async () => {
    const meta = {
      suitable_skin_types: ['dry', 'sensitive'],
      equivalent_product_ids: ['eq-1'],
      complement_product_ids: [],
      routine_refs: [{ routine_id: 'r1', step_order: 1, step_label: 'Cleanser' }],
    };

    vi.mocked(queryAll).mockResolvedValueOnce([
      {
        id: 'prod-1',
        name: 'Vichy Test',
        sku: 'VCH-001',
        barcode: null,
        sale_price: '10.000',
        stock_quantity: 5,
        category: null,
        image_url: null,
        tax_rate: null,
        sellable_type: 'product',
        modifier_groups: null,
        sellable_id: null,
        menu_category_id: null,
        is_physical: 1,
        has_variants: 0,
        brand_id: 'brand-uuid',
        brand_name: 'Vichy',
        parapharmacy_metadata: JSON.stringify(meta),
      },
    ]);

    const products = await getAllProducts(db);
    expect(products[0]).toMatchObject({
      brand_id: 'brand-uuid',
      brand_name: 'Vichy',
      parapharmacy_metadata: meta,
    });
  });

  it('rowToProduct tolerates null brand / meta columns (non-parapharmacy product)', async () => {
    vi.mocked(queryAll).mockResolvedValueOnce([
      {
        id: 'prod-2',
        name: 'Generic Product',
        sku: 'GEN-001',
        barcode: null,
        sale_price: '5.000',
        stock_quantity: 10,
        category: null,
        image_url: null,
        tax_rate: null,
        sellable_type: 'product',
        modifier_groups: null,
        sellable_id: null,
        menu_category_id: null,
        is_physical: 1,
        has_variants: 0,
        brand_id: null,
        brand_name: null,
        parapharmacy_metadata: null,
      },
    ]);

    const products = await getAllProducts(db);
    expect(products[0]?.brand_id).toBeNull();
    expect(products[0]?.brand_name).toBeNull();
    expect(products[0]?.parapharmacy_metadata).toBeNull();
  });

  // ── (b) Write-path: flat fields ───────────────────────────────────────────

  it('upsertProducts writes brand_id, brand_name, and parapharmacy_metadata to params', async () => {
    const meta = {
      suitable_skin_types: ['oily'],
      equivalent_product_ids: [],
      complement_product_ids: ['c-1'],
      routine_refs: [],
    };
    const product: POSProduct = {
      id: 'prod-3',
      name: 'Avène Cream',
      sku: 'AVN-001',
      barcode: null,
      sale_price: '20.000',
      stock_quantity: 2,
      is_physical: true,
      brand_id: 'avene-brand-id',
      brand_name: 'Avène',
      parapharmacy_metadata: meta,
    };

    await upsertProducts(db, [product]);

    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('brand_id');
    expect(sql).toContain('brand_name');
    expect(sql).toContain('parapharmacy_metadata');
    // 19 data params per row (15 legacy + brand_id + brand_name + parapharmacy_metadata + quantity_decimals)
    expect((params as unknown[]).length).toBe(19);
    expect((params as unknown[])[15]).toBe('avene-brand-id');
    expect((params as unknown[])[16]).toBe('Avène');
    expect((params as unknown[])[17]).toBe(JSON.stringify(meta));
  });

  // ── (b) C-1 guard: nested brand object on the write path ─────────────────

  it('[C-1] flattens nested brand:{id,name} payload to brand_id/brand_name on upsert', async () => {
    // Server API payload with nested brand (not flat brand_id/brand_name)
    const product = {
      id: 'prod-4',
      name: 'La Roche-Posay Gel',
      sku: 'LRP-001',
      barcode: null,
      sale_price: '25.000',
      stock_quantity: 1,
      is_physical: true,
      brand: { id: 'lrp-brand-id', name: 'La Roche-Posay' },
      // deliberately no flat brand_id / brand_name
    } as POSProduct & { brand?: { id: string; name: string } | null };

    await upsertProducts(db, [product]);

    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('brand_id');
    expect((params as unknown[])[15]).toBe('lrp-brand-id');
    expect((params as unknown[])[16]).toBe('La Roche-Posay');
  });
});

describe('productRepository — quantity_decimals (v62)', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  // ── Write path: quantity_decimals is the last data param (index 18) ────────

  it('upsertProducts writes quantity_decimals as the appended-last data param', async () => {
    const product: POSProduct = {
      id: 'prod-qd-0',
      name: 'Bandage Box',
      sku: 'BND-001',
      barcode: null,
      sale_price: '3.000',
      stock_quantity: 8,
      is_physical: true,
      quantity_decimals: 0,
    };

    await upsertProducts(db, [product]);

    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('quantity_decimals');
    expect(sql).toContain('quantity_decimals = excluded.quantity_decimals');
    // Appended AFTER parapharmacy_metadata (index 17) → new index 18, so the
    // existing brand/meta positional asserts (15/16/17) stay valid.
    expect((params as unknown[]).length).toBe(19);
    expect((params as unknown[])[18]).toBe(0);
  });

  it('upsertProducts defaults an absent quantity_decimals to null (older server payload)', async () => {
    const product: POSProduct = {
      id: 'prod-qd-absent',
      name: 'Legacy Product',
      sku: 'LEG-001',
      barcode: null,
      sale_price: '5.000',
      stock_quantity: 2,
      is_physical: true,
      // quantity_decimals intentionally absent
    };

    await upsertProducts(db, [product]);

    const [, , params] = vi.mocked(execute).mock.calls[0]!;
    expect((params as unknown[]).length).toBe(19);
    expect((params as unknown[])[18]).toBeNull();
  });

  // ── Read path: rowToProduct maps quantity_decimals, absent → null ──────────

  it('rowToProduct maps quantity_decimals and preserves every other field', async () => {
    const meta = {
      suitable_skin_types: ['normal'],
      equivalent_product_ids: ['eq-9'],
      complement_product_ids: ['c-9'],
      routine_refs: [{ routine_id: 'r9', step_order: 2, step_label: 'Serum' }],
    };

    vi.mocked(queryAll).mockResolvedValueOnce([
      {
        id: 'prod-qd-rt',
        name: 'Round Trip',
        sku: 'RT-001',
        barcode: 'BC-RT',
        sale_price: '12.500',
        stock_quantity: 7,
        category: 'Care',
        image_url: 'https://example.test/rt.png',
        tax_rate: '19',
        sellable_type: 'product',
        modifier_groups: null,
        sellable_id: null,
        menu_category_id: null,
        is_physical: 1,
        has_variants: 0,
        brand_id: 'brand-rt',
        brand_name: 'RT Brand',
        parapharmacy_metadata: JSON.stringify(meta),
        quantity_decimals: 0,
      },
    ]);

    const products = await getAllProducts(db);
    expect(products[0]).toMatchObject({
      id: 'prod-qd-rt',
      name: 'Round Trip',
      sku: 'RT-001',
      barcode: 'BC-RT',
      sale_price: '12.500',
      stock_quantity: 7,
      category: 'Care',
      image_url: 'https://example.test/rt.png',
      tax_rate: '19',
      sellableType: 'product',
      is_physical: true,
      brand_id: 'brand-rt',
      brand_name: 'RT Brand',
      parapharmacy_metadata: meta,
      quantity_decimals: 0,
    });
  });

  it('rowToProduct maps an absent quantity_decimals column to null', async () => {
    // Older device schema (pre-v62): the row object has no quantity_decimals key.
    vi.mocked(queryAll).mockResolvedValueOnce([
      {
        id: 'prod-qd-null',
        name: 'No Decimals',
        sku: 'ND-001',
        barcode: null,
        sale_price: '1.000',
        stock_quantity: 1,
        category: null,
        image_url: null,
        tax_rate: null,
        sellable_type: 'product',
        modifier_groups: null,
        sellable_id: null,
        menu_category_id: null,
        is_physical: 1,
        has_variants: 0,
        brand_id: null,
        brand_name: null,
        parapharmacy_metadata: null,
      },
    ] as never);

    const products = await getAllProducts(db);
    expect(products[0]?.quantity_decimals).toBeNull();
  });
});
