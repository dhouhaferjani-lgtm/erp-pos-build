/**
 * Tests for the no-literal-decimal-places ESLint rule.
 *
 * The `eslint-rules/` directory is outside vitest's `include` globs, so this
 * file is a standalone RuleTester script: ESLint's RuleTester runs its cases
 * eagerly and throws on the first failing assertion, so `node <thisfile>`
 * exits non-zero on failure and prints the summary below on success.
 *
 * Run: node eslint-rules/no-literal-decimal-places.test.mjs
 */
import { RuleTester } from 'eslint'

import rule from './no-literal-decimal-places.js'

const ruleTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: { ecmaFeatures: { jsx: true } },
  },
})

// Filenames must sit inside / outside the rule's INCLUDED_DIRS allowlist.
const REPLENISHMENT = '/repo/apps/web/src/features/replenishment/pages/Foo.tsx'
const PURCHASES = '/repo/apps/web/src/features/purchases/pages/Bar.tsx'
// ProductInventorySection is deliberately EXCLUDED from the rule scope.
const EXCLUDED = '/repo/apps/web/src/features/products/sections/ProductInventorySection.tsx'
const OUTSIDE = '/repo/apps/web/src/features/settings/pages/Baz.tsx'
// features/inventory-counting must NOT be caught by the features/inventory prefix.
const INVENTORY_COUNTING =
  '/repo/apps/web/src/features/inventory-counting/components/ManualOverrideDialog.tsx'
const INVENTORY = '/repo/apps/web/src/features/inventory/pages/Qux.tsx'

ruleTester.run('no-literal-decimal-places', rule, {
  valid: [
    // MoneyInput (not QuantityInput) with a literal is out of scope — the rule
    // only guards the QuantityInput atom.
    { code: 'const x = <MoneyInput decimalPlaces={2} />', filename: REPLENISHMENT },
    // Literal QuantityInput in an EXCLUDED dir (ProductInventorySection) — allowed.
    { code: 'const x = <QuantityInput decimalPlaces={4} />', filename: EXCLUDED },
    // Literal QuantityInput fully outside the included dirs — allowed.
    { code: 'const x = <QuantityInput decimalPlaces={4} />', filename: OUTSIDE },
    // Derived (non-literal) decimalPlaces inside an included dir — the correct pattern.
    {
      code: 'const x = <QuantityInput decimalPlaces={getQuantityDecimals(line.product)} />',
      filename: REPLENISHMENT,
    },
    // QuantityInput with no decimalPlaces attribute at all — nothing to flag.
    { code: 'const x = <QuantityInput value={q} />', filename: PURCHASES },
    // features/inventory-counting is a SEPARATE feature — the features/inventory
    // prefix must not leak into it (dir-boundary anchoring).
    { code: 'const x = <QuantityInput decimalPlaces={0} />', filename: INVENTORY_COUNTING },
  ],
  invalid: [
    // Literal decimalPlaces on a QuantityInput inside the replenishment dir.
    {
      code: 'const x = <QuantityInput decimalPlaces={4} />',
      filename: REPLENISHMENT,
      errors: [{ messageId: 'literalDecimalPlaces' }],
    },
    // Exact features/inventory dir IS in scope.
    {
      code: 'const x = <QuantityInput decimalPlaces={2} />',
      filename: INVENTORY,
      errors: [{ messageId: 'literalDecimalPlaces' }],
    },
    // Literal decimalPlaces on a QuantityInput inside the purchases dir.
    {
      code: 'const x = <QuantityInput value={q} decimalPlaces={0} />',
      filename: PURCHASES,
      errors: [{ messageId: 'literalDecimalPlaces' }],
    },
  ],
})

// eslint-disable-next-line no-console
console.log('no-literal-decimal-places: all RuleTester cases passed (6 valid, 3 invalid)')
