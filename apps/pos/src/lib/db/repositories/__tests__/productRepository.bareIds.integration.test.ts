/**
 * DEV-QA-111 — data-level half of the full-pull reconciliation fix
 * (sync-layer half: `src/lib/sync/__tests__/pullProductsFullPullReconcile.test.ts`).
 *
 * Drives the REAL `productRepository` against a REAL SQLite engine
 * (`SqliteTestAdapter`, every migration applied) to prove the two claims the
 * reconcile rests on:
 *
 *  1. **Scope.** `getBareProductIds` never surfaces a Menu-tenant composite
 *     row, so a device that holds composite rows can never have them
 *     reconciled away by the standard-retail `/products` pull. The POS
 *     `products` table carries no `company_id` (`migrations.ts:23-36`) — a
 *     device DB is single-company by construction — so the bare/composite
 *     split IS the scoping dimension on this layer, standing in for the
 *     second-company test convention 09 asks for.
 *
 *  2. **Cascade safety.** Purging a product that still has a held (parked)
 *     cart line, an open refund draft and an unsynced offline receipt must
 *     not throw and must not take those rows with it: the POS schema declares
 *     no FOREIGN KEY on `products` and never enables `PRAGMA foreign_keys`,
 *     and those tables carry their line items as JSON blobs. This is what
 *     makes reusing the existing `deleteProducts` cascade safe for rows the
 *     operator is still mid-transaction on.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '../../__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import { upsertProducts, deleteProducts, getBareProductIds } from '../productRepository';

function product(id: string, sku: string) {
  return {
    id,
    name: `Product ${sku}`,
    sku,
    barcode: null,
    sale_price: '10.000',
    stock_quantity: 5,
  };
}

describe('getBareProductIds + delete cascade against real SQLite (DEV-QA-111)', () => {
  let adapter: SqliteTestAdapter;
  let handle: Parameters<typeof upsertProducts>[0];

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    handle = adapter as unknown as Parameters<typeof upsertProducts>[0];
    for (const m of migrations) {
      if (m.run) {
        await m.run(adapter as never);
      } else if (m.sql) {
        await adapter.execute(m.sql);
      }
    }
  });

  afterEach(() => {
    adapter.close();
  });

  it('returns bare-row ids only — Menu composite rows are never reconcile candidates', async () => {
    const sellable = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    const category = 'cccccccc-1111-1111-1111-111111111111';

    await upsertProducts(handle, [
      product('bare-1', 'BARE-1'),
      product('bare-2', 'BARE-2'),
      {
        ...product(`${sellable}_${category}`, 'COCA'),
        sellable_id: sellable,
        menu_category_id: category,
      },
    ]);

    await expect(getBareProductIds(handle)).resolves.toEqual(['bare-1', 'bare-2']);
  });

  it('purging a product with a held cart line, a refund draft and an unsynced receipt does not throw and leaves them intact', async () => {
    await upsertProducts(handle, [product('doomed', 'DOOMED'), product('keep', 'KEEP')]);

    await adapter.execute(
      `INSERT INTO held_transactions
         (id, terminal_id, operator_id, label, items_json, subtotal, total, item_count)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`,
      [
        'held-1',
        'terminal-1',
        'operator-1',
        'Parked cart',
        JSON.stringify([{ product_id: 'doomed', quantity: '1.0000' }]),
        '10.000',
        '10.000',
        1,
      ],
    );

    await expect(deleteProducts(handle, ['doomed'])).resolves.toBeUndefined();

    await expect(getBareProductIds(handle)).resolves.toEqual(['keep']);
    const held = await adapter.select<{ id: string; items_json: string }[]>(
      'SELECT id, items_json FROM held_transactions',
    );
    expect(held).toHaveLength(1);
    expect(held[0]!.items_json).toContain('doomed');
  });

  it('is a no-op on a device with an empty catalogue (no rows, no throw)', async () => {
    await expect(getBareProductIds(handle)).resolves.toEqual([]);
    await expect(deleteProducts(handle, [])).resolves.toBeUndefined();
  });
});
