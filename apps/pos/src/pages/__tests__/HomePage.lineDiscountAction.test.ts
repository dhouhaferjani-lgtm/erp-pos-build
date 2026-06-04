import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

/**
 * Task 6 (Sub-Spec C): the HomePage line-discount handlers must delegate to the
 * `cartStore` `applyLineDiscount` / `removeLineDiscount` ACTIONS so the
 * `pos.line_discount_applied` audit emit (which lives inside the action) fires
 * even when discounts are driven from HomePage. The old direct
 * `useCartStore.setState(...)` line-discount mutation would bypass the emit, so
 * this guards against a regression that re-inlines that logic.
 */
const here = dirname(fileURLToPath(import.meta.url));
const homePageSource = readFileSync(resolve(here, '../HomePage.tsx'), 'utf8');

describe('HomePage line-discount → cartStore action', () => {
  it('calls the applyLineDiscount action (not a direct setState)', () => {
    expect(homePageSource).toContain('.applyLineDiscount(');
  });

  it('calls the removeLineDiscount action (not a direct setState)', () => {
    expect(homePageSource).toContain('.removeLineDiscount(');
  });

  it('no longer mutates line discounts via useCartStore.setState', () => {
    // The line-discount logic was MOVED into the store; HomePage must not
    // reach into the store internals with a raw setState for discount fields.
    expect(homePageSource).not.toContain('useCartStore.setState');
  });
});
