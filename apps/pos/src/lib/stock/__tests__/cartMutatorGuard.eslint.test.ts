/**
 * FU-2 — ESLint guard: raw cart mutators may only be called through the gated
 * funnel in `lib/stock/cartIngress.ts`.
 *
 * The stock-guard funnel (`addItemGated` / `updateQuantityGated`) is the single
 * cart ingress (lesson L9). A future caller that reaches for the raw store
 * actions (`cartStore.addItem(` / `.updateQuantity(`) would bypass the
 * availability gate. This test pins the ESLint *configuration* that bans those
 * raw calls outside `src/lib/stock/`, modeled on the `no-parsefloat-on-money`
 * precision-guard precedent in apps/web.
 *
 * Exemptions baked into the config (asserted here):
 *   - `src/lib/stock/**`        — the gated funnel itself calls the raw actions.
 *   - `src/stores/cartStore.ts` — the store composes its own actions.
 *   - test files                — set cart state directly via the store.
 */
import { describe, it, expect, beforeAll } from 'vitest';
import { ESLint } from 'eslint';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const posRoot = resolve(here, '../../../../'); // __tests__ -> stock -> lib -> src -> posRoot

const BYPASS_SNIPPET = `
const cart = useCartStore.getState();
cart.addItem(product);
cart.updateQuantity(itemId, 2);
`;

let eslint: ESLint;

async function restrictedSyntaxMessages(relPath: string): Promise<string[]> {
  const results = await eslint.lintText(BYPASS_SNIPPET, {
    filePath: resolve(posRoot, relPath),
  });
  const [result] = results;
  return (result?.messages ?? [])
    .filter((m) => m.ruleId === 'no-restricted-syntax')
    .map((m) => m.message);
}

describe('FU-2 cart-mutator ESLint guard', () => {
  beforeAll(() => {
    eslint = new ESLint({ cwd: posRoot });
  });

  it('flags raw cartStore.addItem / updateQuantity outside lib/stock', async () => {
    const messages = await restrictedSyntaxMessages('src/pages/__fu2_fixture__.tsx');
    // Both the addItem and updateQuantity calls must be flagged.
    expect(messages.length).toBeGreaterThanOrEqual(2);
    expect(messages.join('\n')).toMatch(/cartIngress/);
  });

  it('allows the raw actions inside lib/stock (the gated funnel lives there)', async () => {
    const messages = await restrictedSyntaxMessages('src/lib/stock/__fu2_fixture__.ts');
    expect(messages).toHaveLength(0);
  });

  it('allows the raw actions inside the cartStore definition', async () => {
    const messages = await restrictedSyntaxMessages('src/stores/cartStore.ts');
    expect(messages).toHaveLength(0);
  });

  it('does not flag test files (they set cart state directly)', async () => {
    const messages = await restrictedSyntaxMessages('src/pages/__tests__/foo.test.tsx');
    expect(messages).toHaveLength(0);
  });
});
