/**
 * Task 11 — structural ingress pin (L9: canonicalize-before-state-machine-input).
 *
 * Every cart ingress in the POS UI must route through the gated funnel
 * (`addItemGated` / `updateQuantityGated` in lib/stock/cartIngress.ts) —
 * never through the raw cartStore actions. There is no HomePage render
 * harness in this codebase (existing HomePage tests are extracted-logic
 * tests), so this pin asserts the wiring STRUCTURALLY on the source: no
 * direct `addItem` / `addItemWithDefaults` / raw `updateQuantity` calls may
 * reappear in HomePage, and the gated funnel must be what it imports.
 *
 * If this test fails after an intentional change, route the new ingress
 * through cartIngress.ts instead of weakening the assertions.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const homePageSource = readFileSync(
  resolve(here, '../../../pages/HomePage.tsx'),
  'utf8',
);

describe('HomePage cart-ingress wiring (structural pin)', () => {
  it('imports the gated funnel', () => {
    expect(homePageSource).toMatch(
      /import\s*\{[^}]*addItemGated[^}]*\}\s*from\s*'@\/lib\/stock\/cartIngress'/,
    );
    expect(homePageSource).toMatch(/updateQuantityGated/);
  });

  it('never subscribes to or calls the raw cartStore add actions', () => {
    // Raw store ingress actions must not be referenced at all.
    expect(homePageSource).not.toMatch(/\bs\.addItem\b/);
    expect(homePageSource).not.toMatch(/\baddItem\(/);
    expect(homePageSource).not.toMatch(/\baddItemWithDefaults\(/);
    expect(homePageSource).not.toMatch(/getState\(\)\.addItem/);
  });

  it('never calls the raw quantity setter (only the gated wrapper)', () => {
    expect(homePageSource).not.toMatch(/\bs\.updateQuantity\b/);
    expect(homePageSource).not.toMatch(/getState\(\)\.updateQuantity/);
    // A raw `updateQuantity(` call (the gated funnel is `updateQuantityGated(`,
    // which this regex does not match) must never reappear.
    const rawCalls = homePageSource.match(/\bupdateQuantity\(/g) ?? [];
    expect(rawCalls).toHaveLength(0);
  });

  it('routes every add ingress through addItemGated (tile, variant, modifier, scan, recommendation)', () => {
    // Anchored to CALL shape (void/await prefix) so a comment mentioning
    // `addItemGated(` can never satisfy the floor.
    const gatedAdds = homePageSource.match(/(?:void|await) addItemGated\(/g) ?? [];
    // 1 tile (plain), 2 tile (withDefaults), 3 variant-picker confirm,
    // 4 modifier confirm, 5 scan/chooser add, 6 smart-prompts recommendation.
    expect(gatedAdds.length).toBeGreaterThanOrEqual(6);
  });
});
