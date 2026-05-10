/**
 * C2 Day 1 — multi-category round-trip integration test.
 *
 * The kickoff's anchor case for the C2 fix: a Menu-mode tenant with the
 * SAME sellable cross-listed across two categories must surface as TWO
 * distinct rows after upsert (composite primary key) — and the round-
 * tripped POSProducts must carry distinct ids and category context.
 *
 * Drives the real `productRepository.upsertProducts` + `getAllProducts`
 * through `SqliteTestAdapter` (no `vi.mock`), so this test catches both
 * a bad SQL clause and a missing column projection.
 *
 * Standard-retail regression guard: a bare-id row still round-trips
 * with `sellable_id` / `menu_category_id` projected as undefined.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '../../__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import {
  upsertProducts,
  getAllProducts,
  getProductsByBarcode,
  deleteStaleBareSellableRows,
  pruneStaleCompositeRows,
  reconcileMenuProducts,
  wipeAllBareRows,
  wipeAllCompositeRows,
  wipeAllProductRows,
} from '../productRepository';

const nodeSqliteAvailable = (() => {
  try {
    // node:sqlite is the canonical real-engine for these round-trip tests;
    // the require() form mirrors the surrounding migration tests'
    // availability gate.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

d('productRepository.upsertProducts + getAllProducts (multi-category round-trip)', () => {
  let adapter: SqliteTestAdapter;
  // The repo functions accept `Database` from the Tauri plugin; the test
  // adapter satisfies the same execute/select surface.
  let handle: Parameters<typeof upsertProducts>[0];

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    handle = adapter as unknown as Parameters<typeof upsertProducts>[0];
    for (const m of migrations) {
      if (m.run) {
        await m.run(adapter);
      } else if (m.sql) {
        await adapter.execute(m.sql);
      }
    }
  });

  afterEach(() => {
    adapter.close();
  });

  it('writes two distinct rows for one sellable cross-listed in two categories (C2 collapse-on-upsert pathology fixed)', async () => {
    const sellable = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    const categoryA = 'cccccccc-1111-1111-1111-111111111111';
    const categoryB = 'cccccccc-2222-2222-2222-222222222222';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${categoryA}`,
        name: 'Coca (Drinks)',
        sku: 'COCA',
        sale_price: '3.00',
        stock_quantity: 999,
        category: 'Drinks',
        sellable_id: sellable,
        menu_category_id: categoryA,
      },
      {
        id: `${sellable}_${categoryB}`,
        name: 'Coca (Combo Specials)',
        sku: 'COCA',
        sale_price: '2.50',
        stock_quantity: 999,
        category: 'Combo Specials',
        sellable_id: sellable,
        menu_category_id: categoryB,
      },
    ]);

    const all = await getAllProducts(handle);

    const drinksRow = all.find((p) => p.id === `${sellable}_${categoryA}`);
    const combosRow = all.find((p) => p.id === `${sellable}_${categoryB}`);

    expect(drinksRow, 'Drinks-category row must exist').toBeDefined();
    expect(combosRow, 'Combo-Specials-category row must exist').toBeDefined();
    expect(drinksRow!.sellable_id).toBe(sellable);
    expect(combosRow!.sellable_id).toBe(sellable);
    expect(drinksRow!.menu_category_id).toBe(categoryA);
    expect(combosRow!.menu_category_id).toBe(categoryB);
    // Different prices per category (Combo Specials runs a discounted Coca).
    expect(drinksRow!.sale_price).toBe('3.00');
    expect(combosRow!.sale_price).toBe('2.50');
  });

  it('Codex r1 P1: deleteStaleBareSellableRows removes pre-Day-1 bare rows but leaves composite siblings intact', async () => {
    // Simulate the pre-Day-1 cache state: a Menu-tenant sellable cached
    // as a bare-id row (id = sellable_id, menu_category_id IS NULL).
    const sellable = '99999999-aaaa-bbbb-cccc-dddddddddddd';
    const categoryA = 'aaaaaaaa-1111-1111-1111-111111111111';

    await upsertProducts(handle, [
      // Pre-Day-1 bare row (manually constructed to mirror the legacy
      // cache shape — `id = sellable_id` with no composite columns).
      {
        id: sellable,
        name: 'Coca (cached pre-Day-1)',
        sku: 'COCA',
        sale_price: '3.00',
        stock_quantity: 999,
      },
    ]);

    // Then write the new Day-1 composite row for the same sellable.
    await upsertProducts(handle, [
      {
        id: `${sellable}_${categoryA}`,
        name: 'Coca (Drinks)',
        sku: 'COCA',
        sale_price: '3.00',
        stock_quantity: 999,
        category: 'Drinks',
        sellable_id: sellable,
        menu_category_id: categoryA,
      },
    ]);

    // Pre-sweep: both rows coexist.
    const beforeSweep = await getAllProducts(handle);
    expect(beforeSweep).toHaveLength(2);

    // Sweep based on the composite row's sellable_id.
    await deleteStaleBareSellableRows(handle, [sellable]);

    const afterSweep = await getAllProducts(handle);
    expect(afterSweep).toHaveLength(1);
    expect(afterSweep[0]!.id).toBe(`${sellable}_${categoryA}`);
    // The composite row's `menu_category_id` survived the sweep.
    expect(afterSweep[0]!.menu_category_id).toBe(categoryA);
  });

  it('Codex r1 P1: deleteStaleBareSellableRows is a no-op when there are no stale bare rows', async () => {
    const sellable = '88888888-aaaa-bbbb-cccc-dddddddddddd';
    const categoryA = 'bbbbbbbb-1111-1111-1111-111111111111';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${categoryA}`,
        name: 'Espresso',
        sku: 'ESP',
        sale_price: '2.00',
        stock_quantity: 999,
        category: 'Drinks',
        sellable_id: sellable,
        menu_category_id: categoryA,
      },
    ]);

    await deleteStaleBareSellableRows(handle, [sellable]);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(`${sellable}_${categoryA}`);
  });

  it('Codex r2 P2: pruneStaleCompositeRows deletes composite rows whose id is no longer in the fresh active-menu set', async () => {
    // Simulate two composite rows for the same sellable across two
    // categories. Then a fresh menu fetch only returns the second
    // category — the first composite row is stale and must be pruned.
    const sellable = '77777777-aaaa-bbbb-cccc-dddddddddddd';
    const removedCategory = '11111111-1111-1111-1111-111111111111';
    const keptCategory = '22222222-2222-2222-2222-222222222222';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${removedCategory}`,
        name: 'Coca (was Drinks)',
        sku: 'COCA',
        sale_price: '3.00',
        stock_quantity: 999,
        category: 'Drinks',
        sellable_id: sellable,
        menu_category_id: removedCategory,
      },
      {
        id: `${sellable}_${keptCategory}`,
        name: 'Coca (Combo)',
        sku: 'COCA',
        sale_price: '2.50',
        stock_quantity: 999,
        category: 'Combo Specials',
        sellable_id: sellable,
        menu_category_id: keptCategory,
      },
    ]);

    // Fresh fetch only contains the kept-category id — Drinks row is
    // stale and must be pruned.
    await pruneStaleCompositeRows(handle, [`${sellable}_${keptCategory}`]);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(`${sellable}_${keptCategory}`);
    expect(after[0]!.menu_category_id).toBe(keptCategory);
  });

  it('Codex r2 P2: pruneStaleCompositeRows is a defensive no-op on an empty fresh set (transient empty fetch)', async () => {
    // A transient empty fetch from /active-menu must not destructively
    // wipe the cached catalog. The helper short-circuits.
    const sellable = '66666666-aaaa-bbbb-cccc-dddddddddddd';
    const category = '33333333-3333-3333-3333-333333333333';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${category}`,
        name: 'Espresso',
        sku: 'ESP',
        sale_price: '2.00',
        stock_quantity: 999,
        category: 'Drinks',
        sellable_id: sellable,
        menu_category_id: category,
      },
    ]);

    await pruneStaleCompositeRows(handle, []);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(`${sellable}_${category}`);
  });

  it('Codex r2 P2: pruneStaleCompositeRows leaves bare-id rows untouched (only composites are eligible)', async () => {
    // A standard-retail bare-id row coexisting with a Menu-tenant
    // composite row (rare but possible during a tenant's Menu→non-Menu
    // transition window) must NOT be pruned by the composite sweep.
    const bareId = 'bare-uuid-keep-me';
    const sellable = '55555555-aaaa-bbbb-cccc-dddddddddddd';
    const category = '44444444-4444-4444-4444-444444444444';

    await upsertProducts(handle, [
      { id: bareId, name: 'Bare Product', sku: 'BARE', sale_price: '5.00', stock_quantity: 10 },
      {
        id: `${sellable}_${category}`,
        name: 'Menu Product',
        sku: 'MENU',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: category,
      },
    ]);

    // Fresh set is empty for the composite sellable — the menu wiped
    // this entry but the bare row should survive.
    await pruneStaleCompositeRows(handle, ['some-other-composite-id-not-in-db']);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(bareId);
  });

  it('Codex r3 P2: wipeAllCompositeRows removes every composite row but leaves bare-id rows intact', async () => {
    // Successful empty active-menu response: server says no items.
    // Composite rows must go; bare-id rows (e.g. a standard-retail
    // orphan during a tenant Mode→non-Menu transition) survive.
    const sellable = '44444444-aaaa-bbbb-cccc-dddddddddddd';
    const categoryA = 'aaaa-1111-1111-1111-111111111111';
    const categoryB = 'aaaa-2222-2222-2222-222222222222';
    const bareId = 'standalone-bare-uuid-survivor';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${categoryA}`,
        name: 'Coca (Drinks)',
        sku: 'COCA',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: categoryA,
      },
      {
        id: `${sellable}_${categoryB}`,
        name: 'Coca (Combo)',
        sku: 'COCA',
        sale_price: '2.50',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: categoryB,
      },
      {
        id: bareId,
        name: 'Bare Survivor',
        sku: 'BARE',
        sale_price: '5.00',
        stock_quantity: 5,
      },
    ]);

    await wipeAllCompositeRows(handle);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(bareId);
  });

  it('Codex r4 P2: getProductsByBarcode returns every cross-listed match, not just the first', async () => {
    // The C2 collapse-on-upsert pathology produced one row per
    // sellable; post-Day-1 we have one row per (sellable, category)
    // and the same `barcode` lives on multiple rows. The plural
    // lookup MUST surface every match so the scan resolver can route
    // to the chooser modal — returning a single row would auto-add
    // the wrong category-priced version.
    const sellable = '99999999-1234-1234-1234-123456789012';
    const categoryA = '11111111-aaaa-bbbb-cccc-dddddddddddd';
    const categoryB = '22222222-aaaa-bbbb-cccc-dddddddddddd';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${categoryA}`,
        name: 'Coca (Drinks)',
        sku: 'COCA',
        barcode: '5449000000996',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: categoryA,
      },
      {
        id: `${sellable}_${categoryB}`,
        name: 'Coca (Combo)',
        sku: 'COCA',
        barcode: '5449000000996',
        sale_price: '2.50',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: categoryB,
      },
    ]);

    const matches = await getProductsByBarcode(handle, '5449000000996');

    expect(matches).toHaveLength(2);
    const ids = matches.map((p) => p.id).sort();
    expect(ids).toEqual([
      `${sellable}_${categoryA}`,
      `${sellable}_${categoryB}`,
    ]);
  });

  it('Codex r4 P2: getProductsByBarcode returns a single match for a non-cross-listed sellable (regression)', async () => {
    await upsertProducts(handle, [
      {
        id: 'standalone-uuid-1',
        name: 'Standalone',
        sku: 'STD',
        barcode: '1234567890123',
        sale_price: '5.00',
        stock_quantity: 10,
      },
    ]);

    const matches = await getProductsByBarcode(handle, '1234567890123');

    expect(matches).toHaveLength(1);
    expect(matches[0]!.id).toBe('standalone-uuid-1');
  });

  it('Codex r4 P2: wipeAllProductRows clears bare AND composite rows (Menu-tenant success-empty contract)', async () => {
    const sellable = '88888888-1234-1234-1234-123456789012';
    const category = '33333333-aaaa-bbbb-cccc-dddddddddddd';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${category}`,
        name: 'Composite Row',
        sku: 'COMP',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: category,
      },
      {
        id: 'pre-c2-bare-orphan',
        name: 'Pre-C2 Bare',
        sku: 'BARE',
        sale_price: '5.00',
        stock_quantity: 5,
      },
    ]);

    await wipeAllProductRows(handle);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(0);
  });

  it('Codex r5 P2: wipeAllBareRows removes EVERY bare row (including orphans for sellables removed from the menu) but leaves composite rows intact', async () => {
    // Seed a mix of:
    //   - composite rows for the current menu (must survive)
    //   - bare-id row for a sellable IN the menu (pre-C2 cache)
    //   - bare-id row for a sellable NOT in the menu (pre-C2 orphan)
    const sellableInMenu = '11111111-aaaa-bbbb-cccc-dddddddddddd';
    const sellableNotInMenu = '22222222-aaaa-bbbb-cccc-dddddddddddd';
    const category = 'cccc-1111-2222-3333-444444444444';

    await upsertProducts(handle, [
      {
        id: `${sellableInMenu}_${category}`,
        name: 'Composite Survivor',
        sku: 'COMP',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellableInMenu,
        menu_category_id: category,
      },
      {
        id: sellableInMenu,
        name: 'Pre-C2 Bare for in-menu sellable',
        sku: 'BARE-IN',
        sale_price: '3.00',
        stock_quantity: 999,
      },
      {
        id: sellableNotInMenu,
        name: 'Pre-C2 Bare for removed sellable (orphan)',
        sku: 'BARE-OUT',
        sale_price: '5.00',
        stock_quantity: 5,
      },
    ]);

    await wipeAllBareRows(handle);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(`${sellableInMenu}_${category}`);
    expect(after[0]!.menu_category_id).toBe(category);
  });

  it('Codex r5 P2: wipeAllBareRows is a no-op when there are no bare rows (composite-only post-Day-1 steady state)', async () => {
    const sellable = '88888888-aaaa-bbbb-cccc-dddddddddddd';
    const category = '77777777-aaaa-bbbb-cccc-dddddddddddd';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${category}`,
        name: 'Composite-only',
        sku: 'COMP',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: category,
      },
    ]);

    await wipeAllBareRows(handle);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(`${sellable}_${category}`);
  });

  it('Codex r6 P1: reconcileMenuProducts (success-non-empty) writes composites + sweeps bare orphans + prunes stale composites in a single call', async () => {
    // Pre-state: a stale composite (sellable removed from this fresh
    // pull), a bare orphan, and a yet-unseen sellable that's about to
    // arrive in the fresh menu.
    const sellable = '11111111-aaaa-bbbb-cccc-dddddddddddd';
    const oldCategory = 'aaaa-1111-1111-1111-111111111111';
    const newCategory = 'aaaa-2222-2222-2222-222222222222';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${oldCategory}`,
        name: 'Coca (Old Cat)',
        sku: 'COCA',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: oldCategory,
      },
      {
        id: 'pre-c2-bare-orphan',
        name: 'Bare Orphan',
        sku: 'BARE',
        sale_price: '5.00',
        stock_quantity: 5,
      },
    ]);

    // Fresh menu only contains sellable in newCategory.
    await reconcileMenuProducts(handle, [
      {
        id: `${sellable}_${newCategory}`,
        name: 'Coca (New Cat)',
        sku: 'COCA',
        sale_price: '2.50',
        stock_quantity: 999,
        category: 'New',
        sellable_id: sellable,
        menu_category_id: newCategory,
      },
    ]);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(1);
    expect(after[0]!.id).toBe(`${sellable}_${newCategory}`);
    expect(after[0]!.menu_category_id).toBe(newCategory);
    expect(after[0]!.sale_price).toBe('2.50');
  });

  it('Codex r6 P1: reconcileMenuProducts (success-empty) wipes everything', async () => {
    const sellable = '22222222-aaaa-bbbb-cccc-dddddddddddd';
    const category = 'bbbb-1111-1111-1111-111111111111';

    await upsertProducts(handle, [
      {
        id: `${sellable}_${category}`,
        name: 'Composite',
        sku: 'COMP',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: sellable,
        menu_category_id: category,
      },
      {
        id: 'pre-c2-bare-orphan',
        name: 'Bare Orphan',
        sku: 'BARE',
        sale_price: '5.00',
        stock_quantity: 5,
      },
    ]);

    await reconcileMenuProducts(handle, []);

    const after = await getAllProducts(handle);
    expect(after).toHaveLength(0);
  });

  it('standard-retail regression: bare-id rows round-trip with sellable_id and menu_category_id projected as undefined', async () => {
    await upsertProducts(handle, [
      {
        id: 'standard-uuid-1',
        name: 'Standard Widget',
        sku: 'WIDGET-1',
        sale_price: '10.00',
        stock_quantity: 50,
      },
    ]);

    const all = await getAllProducts(handle);
    const row = all.find((p) => p.id === 'standard-uuid-1');

    expect(row, 'Standard-retail row must exist').toBeDefined();
    expect(row!.sellable_id).toBeUndefined();
    expect(row!.menu_category_id).toBeUndefined();
  });
});
