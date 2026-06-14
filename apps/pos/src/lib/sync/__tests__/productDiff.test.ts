import { describe, it, expect } from 'vitest';
import { diffProducts } from '../productDiff';
import type { POSProduct } from '@/types/product';

const makeProduct = (id: string, price: string): POSProduct => ({
  id, name: `Product ${id}`, sku: `SKU-${id}`, sale_price: price,
  stock_quantity: 10, category: 'Test',
});

describe('diffProducts', () => {
  it('returns same references for unchanged products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(false);
    expect(result.products[0]).toBe(current[0]); // same reference
    expect(result.products[1]).toBe(current[1]); // same reference
  });

  it('replaces only changed products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '25.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(current[0]); // unchanged — same ref
    expect(result.products[1]).not.toBe(current[1]); // changed — new ref
    expect(result.products[1]!.sale_price).toBe('25.00');
  });

  it('detects added products', () => {
    const current = [makeProduct('1', '10.00')];
    const fetched = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(true);
    expect(result.products).toHaveLength(2);
  });

  it('detects removed products', () => {
    const current = [makeProduct('1', '10.00'), makeProduct('2', '20.00')];
    const fetched = [makeProduct('1', '10.00')];
    const result = diffProducts(current, fetched);
    expect(result.changed).toBe(true);
    expect(result.products).toHaveLength(1);
    expect(result.products.find(p => p.id === '2')).toBeUndefined();
  });

  it('C2 Day 2: detects newly populated menu_category_id during the v30 migration backfill window (Codex r3 P2)', () => {
    // Pre-v30 cached row carries id 'cola' but no menu_category_id.
    // Post-v30 sync emits the same id with the backfilled
    // menu_category_id. Without this field in COMPARE_FIELDS, diff
    // would treat the rows as equal and downstream canonicalization
    // would never see the fresh id — chooser-row category label and
    // ID-based dedupe would stay disabled until another visible
    // field flips.
    const cached: POSProduct = { ...makeProduct('cola', '3.00') };
    const fresh: POSProduct = {
      ...makeProduct('cola', '3.00'),
      menu_category_id: 'cat-drinks',
    };
    const result = diffProducts([cached], [fresh]);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(fresh);
  });

  it('C2 Day 2: detects newly populated sellable_id during the v30 migration backfill window', () => {
    const cached: POSProduct = { ...makeProduct('cola', '3.00') };
    const fresh: POSProduct = {
      ...makeProduct('cola', '3.00'),
      sellable_id: 'sellable-cola',
    };
    const result = diffProducts([cached], [fresh]);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(fresh);
  });

  it('handles modifier_groups comparison via JSON', () => {
    const p1 = { ...makeProduct('1', '10.00'), modifier_groups: [{ id: 'mg1', name: 'Size', selection_type: 'single' as const, min_selections: 0, max_selections: 1, is_required: false, position: 0, modifiers: [] }] };
    const p2 = { ...makeProduct('1', '10.00'), modifier_groups: [{ id: 'mg1', name: 'Size', selection_type: 'single' as const, min_selections: 0, max_selections: 1, is_required: false, position: 0, modifiers: [] }] };
    const result = diffProducts([p1], [p2]);
    expect(result.changed).toBe(false);
    expect(result.products[0]).toBe(p1);
  });

  // HIGH-2: has_variants must be in COMPARE_FIELDS so existing products
  // whose flag flips (false → true or true → false) are re-projected into
  // the in-memory catalog and the VariantPickerModal gating is re-evaluated.
  it('HIGH-2: detects has_variants flipping false → true on an existing product', () => {
    // Simulates the v54 re-sync scenario: stale cached row has has_variants
    // absent (undefined == false); fresh server row returns has_variants: true.
    // Without has_variants in COMPARE_FIELDS, productEquals would return true
    // and tile-tap/scan would keep calling addItemGated directly, skipping
    // the picker.
    const stale: POSProduct = { ...makeProduct('tire-205-55-r16', '89.00') };
    const fresh: POSProduct = {
      ...makeProduct('tire-205-55-r16', '89.00'),
      has_variants: true,
    };
    const result = diffProducts([stale], [fresh]);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(fresh); // merged carries the server-authoritative flag
    expect(result.products[0]!.has_variants).toBe(true);
  });

  it('HIGH-2: detects has_variants flipping true → false on an existing product', () => {
    // Covers the reverse: a variant product whose variants are collapsed
    // back to a single option — server returns has_variants: false but the
    // cached row still has has_variants: true. Without has_variants in
    // COMPARE_FIELDS the picker would still open, causing a confusing UX.
    const stale: POSProduct = {
      ...makeProduct('oil-filter', '12.00'),
      has_variants: true,
    };
    const fresh: POSProduct = {
      ...makeProduct('oil-filter', '12.00'),
      has_variants: false,
    };
    const result = diffProducts([stale], [fresh]);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(fresh);
    expect(result.products[0]!.has_variants).toBe(false);
  });

  it('HIGH-2: treats two products with the same has_variants value as equal (no spurious change)', () => {
    // Verify the COMPARE_FIELDS addition does not break the no-diff case for
    // variant products: if both cached and fresh have has_variants: true and
    // nothing else changed, diffProducts must return changed: false and
    // preserve the cached reference.
    const cached: POSProduct = { ...makeProduct('brake-pad', '34.00'), has_variants: true };
    const fetched: POSProduct = { ...makeProduct('brake-pad', '34.00'), has_variants: true };
    const result = diffProducts([cached], [fetched]);
    expect(result.changed).toBe(false);
    expect(result.products[0]).toBe(cached); // same reference preserved
  });

  // v51 migration: is_physical must be in COMPARE_FIELDS so a product
  // flipping is_physical true↔false is re-projected into the in-memory
  // catalog. Without this, stock enforcement reads the stale flag value.
  it('v51: detects is_physical flipping false → true on an existing product', () => {
    // Simulates the v51 re-sync scenario: stale cached row has is_physical
    // absent (undefined == false); fresh server row returns is_physical: true.
    // Without is_physical in COMPARE_FIELDS, productEquals would return true
    // and the in-memory product would keep the stale value — stock enforcement
    // would treat the product as non-physical and skip reservation checks.
    const stale: POSProduct = { ...makeProduct('widget', '15.00') };
    const fresh: POSProduct = { ...makeProduct('widget', '15.00'), is_physical: true };
    const result = diffProducts([stale], [fresh]);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(fresh); // merged carries the server-authoritative flag
    expect(result.products[0]!.is_physical).toBe(true);
  });

  it('v51: detects is_physical flipping true → false on an existing product', () => {
    // Covers the reverse: a product whose is_physical flag is revoked on the
    // server — cached row has is_physical: true but fresh row returns false.
    // Without is_physical in COMPARE_FIELDS the stale true value persists.
    const stale: POSProduct = { ...makeProduct('service-fee', '5.00'), is_physical: true };
    const fresh: POSProduct = { ...makeProduct('service-fee', '5.00'), is_physical: false };
    const result = diffProducts([stale], [fresh]);
    expect(result.changed).toBe(true);
    expect(result.products[0]).toBe(fresh);
    expect(result.products[0]!.is_physical).toBe(false);
  });
});
