/**
 * Task 10 — availability selector tests.
 *
 * Pure core (`effectiveAvailable`): no mocks, all inputs explicit.
 * Assembler (`getEffectiveAvailable`): real SQLite engine via
 * SqliteTestAdapter (sibling idiom — locationStockRepository.test.ts), with
 * real `location_stock` + `offline_receipts` rows so the unsynced predicate
 * and JSON line parsing are exercised against the actual schema.
 *
 * `is_physical` exemption (spec §4.4): `is_physical === false` returns null
 * (exempt); `is_physical` absent/undefined is treated as physical (stock-
 * checked) — fail toward enforcement. The gap noted in previous versions of
 * this file is now closed (migration v51 + upsertProducts projection).
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import { upsertStockRows } from '@/lib/db/repositories/locationStockRepository';
import type { POSProduct } from '@/types/product';
import {
  effectiveAvailable,
  getEffectiveAvailable,
  type AvailabilityCartLine,
} from '../availability';

// ──────────────────────────────────────────────────────────────────────────────
// Pure core
// ──────────────────────────────────────────────────────────────────────────────

/** Minimal stock-managed product slice. */
const PRODUCT: { sellableType?: 'product' | 'composite_item' } = {
  sellableType: 'product',
};

describe('effectiveAvailable (pure core)', () => {
  it('returns the server available when nothing is pending', () => {
    expect(
      effectiveAvailable({
        product: PRODUCT,
        stockRow: { available: '10.0000' },
        pendingSaleQty: '0',
        cartQty: '0',
      }),
    ).toBe('10.0000');
  });

  it('subtracts pending receipts and cart quantities', () => {
    expect(
      effectiveAvailable({
        product: PRODUCT,
        stockRow: { available: '10.0000' },
        pendingSaleQty: '3.0000',
        cartQty: '2.0000',
      }),
    ).toBe('5.0000');
  });

  it('clamps at zero — never negative', () => {
    expect(
      effectiveAvailable({
        product: PRODUCT,
        stockRow: { available: '2.0000' },
        pendingSaleQty: '5.0000',
        cartQty: '1.0000',
      }),
    ).toBe('0.0000');
  });

  it('treats a missing stock row as server available 0 (clamped)', () => {
    expect(
      effectiveAvailable({
        product: PRODUCT,
        stockRow: null,
        pendingSaleQty: '0',
        cartQty: '0',
      }),
    ).toBe('0.0000');

    // Still subject to the clamp when pendings exist.
    expect(
      effectiveAvailable({
        product: PRODUCT,
        stockRow: null,
        pendingSaleQty: '3.0000',
        cartQty: '0',
      }),
    ).toBe('0.0000');
  });

  it('returns null (exempt) for composite_item sellables regardless of stock', () => {
    expect(
      effectiveAvailable({
        product: { sellableType: 'composite_item' },
        stockRow: { available: '10.0000' },
        pendingSaleQty: '0',
        cartQty: '0',
      }),
    ).toBeNull();
  });

  it('treats an absent sellableType as a stock-managed product', () => {
    expect(
      effectiveAvailable({
        product: {},
        stockRow: { available: '4.0000' },
        pendingSaleQty: '1.0000',
        cartQty: '0',
      }),
    ).toBe('3.0000');
  });

  it('handles mixed-scale inputs numerically (zeroed-style "3" vs scale-4)', () => {
    expect(
      effectiveAvailable({
        product: PRODUCT,
        stockRow: { available: '10.0000' },
        pendingSaleQty: '3',
        cartQty: '0',
      }),
    ).toBe('7.0000');
  });

  it('returns null (exempt) when is_physical is false — service product', () => {
    expect(
      effectiveAvailable({
        product: { sellableType: 'product', is_physical: false },
        stockRow: { available: '10.0000' },
        pendingSaleQty: '0',
        cartQty: '0',
      }),
    ).toBeNull();
  });

  it('remains stock-checked when is_physical is undefined (absent field)', () => {
    // Absent is_physical must NOT exempt — fail toward enforcement.
    expect(
      effectiveAvailable({
        product: { sellableType: 'product' },
        stockRow: { available: '5.0000' },
        pendingSaleQty: '0',
        cartQty: '0',
      }),
    ).toBe('5.0000');
  });

  it('remains stock-checked when is_physical is true', () => {
    expect(
      effectiveAvailable({
        product: { sellableType: 'product', is_physical: true },
        stockRow: { available: '3.0000' },
        pendingSaleQty: '1.0000',
        cartQty: '0',
      }),
    ).toBe('2.0000');
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Assembler — real SQLite
// ──────────────────────────────────────────────────────────────────────────────

async function applyAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const migration of migrations) {
    if (migration.run) {
      await migration.run(adapter.asDatabase());
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

interface PersistedLineFixture {
  product_id?: string;
  composite_item_id?: string;
  variant_id?: string;
  quantity: number;
}

interface ReceiptFixture {
  id: string;
  status: 'pending' | 'syncing' | 'synced' | 'failed';
  lines: PersistedLineFixture[];
  voided?: 0 | 1;
  isTraining?: 0 | 1;
}

let receiptSeq = 0;

async function insertReceiptFixture(
  adapter: SqliteTestAdapter,
  fixture: ReceiptFixture,
): Promise<void> {
  receiptSeq += 1;
  await adapter.execute(
    `INSERT INTO offline_receipts (
       id, idempotency_key, receipt_number, terminal_id, terminal_code,
       operator_id, operator_name, lines, subtotal, tax_amount, total,
       currency, fiscal_hash, previous_hash, hash_sequence,
       payment_method_id, payment_repository_id, status, voided, is_training
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20)`,
    [
      fixture.id,
      `idem-${fixture.id}`,
      `R-${String(receiptSeq).padStart(4, '0')}`,
      'term-1',
      'T01',
      'op-1',
      'Operator One',
      JSON.stringify(fixture.lines),
      '0.000',
      '0.000',
      '0.000',
      'EUR',
      `hash-${receiptSeq}`,
      `prev-${receiptSeq}`,
      receiptSeq,
      'pm-1',
      'repo-1',
      fixture.status,
      fixture.voided ?? 0,
      fixture.isTraining ?? 0,
    ],
  );
}

function posProduct(overrides: Partial<POSProduct> & { id: string }): POSProduct {
  return {
    name: 'Test product',
    sku: 'SKU-1',
    sale_price: '10.000',
    stock_quantity: 0,
    sellableType: 'product',
    ...overrides,
  };
}

function cartLine(
  productId: string,
  quantity: number,
  variantId?: string,
  kind?: 'sale' | 'return',
): AvailabilityCartLine {
  return {
    product: { id: productId, ...(variantId !== undefined ? { variant_id: variantId } : {}) },
    quantity,
    ...(kind !== undefined ? { kind } : {}),
  };
}

describe('getEffectiveAvailable (assembler — real SQLite)', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('composes server stock − unsynced receipts − cart, with full filtering', async () => {
    await upsertStockRows(db, [
      {
        product_id: 'p1',
        variant_id: null,
        quantity: '12.0000',
        reserved: '2.0000',
        available: '10.0000',
        updated_at: '2026-06-12T10:00:00.000Z',
      },
    ]);

    // Unsynced sale (counts: 3).
    await insertReceiptFixture(adapter, {
      id: 'r-pending',
      status: 'pending',
      lines: [{ product_id: 'p1', quantity: 3 }],
    });
    // Failed = still unsynced (counts: 1).
    await insertReceiptFixture(adapter, {
      id: 'r-failed',
      status: 'failed',
      lines: [{ product_id: 'p1', quantity: 1 }],
    });
    // Already synced — server snapshot reflects it (ignored).
    await insertReceiptFixture(adapter, {
      id: 'r-synced',
      status: 'synced',
      lines: [{ product_id: 'p1', quantity: 5 }],
    });
    // Voided receipt — sale reversed (ignored).
    await insertReceiptFixture(adapter, {
      id: 'r-voided',
      status: 'pending',
      voided: 1,
      lines: [{ product_id: 'p1', quantity: 2 }],
    });
    // Training receipt — never moves real stock (ignored).
    await insertReceiptFixture(adapter, {
      id: 'r-training',
      status: 'pending',
      isTraining: 1,
      lines: [{ product_id: 'p1', quantity: 2 }],
    });
    // Different product (ignored) + negative refund-style line (ignored —
    // refunds never add availability back).
    await insertReceiptFixture(adapter, {
      id: 'r-other',
      status: 'pending',
      lines: [
        { product_id: 'p2', quantity: 4 },
        { product_id: 'p1', quantity: -2 },
      ],
    });

    const cart: AvailabilityCartLine[] = [
      cartLine('p1', 2), // counts
      cartLine('p1', 1, undefined, 'return'), // return line — ignored
      cartLine('p2', 4), // other product — ignored
    ];

    // 10 − (3 + 1 pending) − 2 cart = 4
    const result = await getEffectiveAvailable(db, posProduct({ id: 'p1' }), null, cart);
    expect(result).toBe('4.0000');
  });

  it('keeps variant grains independent and normalizes ""/null both directions', async () => {
    await upsertStockRows(db, [
      {
        product_id: 'p1',
        variant_id: null,
        quantity: '10.0000',
        reserved: '0',
        available: '10.0000',
        updated_at: null,
      },
      {
        product_id: 'p1',
        variant_id: 'vA',
        quantity: '8.0000',
        reserved: '0',
        available: '8.0000',
        updated_at: null,
      },
      {
        product_id: 'p1',
        variant_id: 'vB',
        quantity: '6.0000',
        reserved: '0',
        available: '6.0000',
        updated_at: null,
      },
    ]);

    // Pending sale of 3 × variant A only.
    await insertReceiptFixture(adapter, {
      id: 'r-variant-a',
      status: 'pending',
      lines: [{ product_id: 'p1', variant_id: 'vA', quantity: 3 }],
    });

    const product = posProduct({ id: 'p1', has_variants: true });

    // Variant A: 8 − 3 = 5.
    expect(await getEffectiveAvailable(db, product, 'vA', [])).toBe('5.0000');
    // Variant B untouched.
    expect(await getEffectiveAvailable(db, product, 'vB', [])).toBe('6.0000');
    // Product grain untouched by variant-grain pending.
    expect(await getEffectiveAvailable(db, product, null, [])).toBe('10.0000');
    // '' normalizes to the product grain (both directions).
    expect(await getEffectiveAvailable(db, product, '', [])).toBe('10.0000');

    // Product-grain pending line (variant_id absent) subtracts from the
    // product grain whether the caller passes null or ''.
    await insertReceiptFixture(adapter, {
      id: 'r-product-grain',
      status: 'pending',
      lines: [{ product_id: 'p1', quantity: 4 }],
    });
    expect(await getEffectiveAvailable(db, product, null, [])).toBe('6.0000');
    expect(await getEffectiveAvailable(db, product, '', [])).toBe('6.0000');
    // …and never bleeds into the variant grains.
    expect(await getEffectiveAvailable(db, product, 'vA', [])).toBe('5.0000');
  });

  it('returns 0.0000 when no stock row exists locally', async () => {
    const result = await getEffectiveAvailable(db, posProduct({ id: 'p-unknown' }), null, []);
    expect(result).toBe('0.0000');
  });

  it('returns null for composite sellables without consulting stock', async () => {
    // Even with a (bogus) stock row for the composite id, exemption wins.
    await upsertStockRows(db, [
      {
        product_id: 'menu-1',
        variant_id: null,
        quantity: '5.0000',
        reserved: '0',
        available: '5.0000',
        updated_at: null,
      },
    ]);

    const result = await getEffectiveAvailable(
      db,
      posProduct({ id: 'menu-1', sellableType: 'composite_item' }),
      null,
      [],
    );
    expect(result).toBeNull();
  });

  it('matches pending lines via the bare sellable_id for Menu-mode composite ids', async () => {
    // C2 — Menu-mode rows carry a composite `id` and the bare sellable UUID
    // in `sellable_id`; server stock is keyed by the bare UUID while local
    // receipt lines persist the local cart product id.
    await upsertStockRows(db, [
      {
        product_id: 'bare-uuid',
        variant_id: null,
        quantity: '10.0000',
        reserved: '0',
        available: '10.0000',
        updated_at: null,
      },
    ]);

    await insertReceiptFixture(adapter, {
      id: 'r-composite-id',
      status: 'pending',
      lines: [{ product_id: 'cat-1::bare-uuid', quantity: 2 }],
    });

    const product = posProduct({ id: 'cat-1::bare-uuid', sellable_id: 'bare-uuid' });
    const result = await getEffectiveAvailable(db, product, null, [
      cartLine('cat-1::bare-uuid', 1),
    ]);
    // 10 − 2 pending − 1 cart = 7
    expect(result).toBe('7.0000');
  });

  it('returns null for a non-physical (service) product without consulting stock', async () => {
    // Even with a stock row present, is_physical: false exempts the product.
    // This is the core automotive-vertical labour-line scenario.
    await upsertStockRows(db, [
      {
        product_id: 'svc-1',
        variant_id: null,
        quantity: '0.0000',
        reserved: '0',
        available: '0.0000',
        updated_at: null,
      },
    ]);

    const result = await getEffectiveAvailable(
      db,
      posProduct({ id: 'svc-1', is_physical: false }),
      null,
      [],
    );
    expect(result).toBeNull();
  });

  it('remains stock-checked when is_physical is undefined (absent — fail toward enforcement)', async () => {
    await upsertStockRows(db, [
      {
        product_id: 'p-absent-phys',
        variant_id: null,
        quantity: '4.0000',
        reserved: '0',
        available: '4.0000',
        updated_at: null,
      },
    ]);

    const result = await getEffectiveAvailable(
      db,
      // No is_physical field — mirrors Menu flatten path.
      posProduct({ id: 'p-absent-phys' }),
      null,
      [],
    );
    expect(result).toBe('4.0000');
  });
});
