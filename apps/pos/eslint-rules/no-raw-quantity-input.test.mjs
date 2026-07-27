/**
 * Tests for the no-raw-quantity-input ESLint rule.
 *
 * The `eslint-rules/` directory is outside vitest's `include` globs, so this
 * file is a standalone RuleTester script: ESLint's RuleTester runs its cases
 * eagerly and throws on the first failing assertion, so `node <thisfile>`
 * exits non-zero on failure and prints the summary below on success.
 *
 * Run: node eslint-rules/no-raw-quantity-input.test.mjs
 */
import { RuleTester } from 'eslint'

import rule from './no-raw-quantity-input.js'

const ruleTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: { ecmaFeatures: { jsx: true } },
  },
})

// Allowlisted atoms / money surfaces vs. an ordinary quantity surface.
const MONEY_INPUT = '/repo/apps/pos/src/components/atoms/MoneyInput.tsx'
const QUANTITY_INPUT = '/repo/apps/pos/src/components/atoms/QuantityInput.tsx'
const SHIFT_CLOSURE = '/repo/apps/pos/src/pages/ShiftClosurePage.tsx'
const CUSTOMER_ATTACH = '/repo/apps/pos/src/components/customers/CustomerAttachPanel.tsx'
const REFILL_SHEET = '/repo/apps/pos/src/components/pos/RequestRefillSheet.tsx'

ruleTester.run('no-raw-quantity-input', rule, {
  valid: [
    // The QuantityInput / MoneyInput atoms own the raw <input> — allowlisted.
    { code: 'const x = <input inputMode="decimal" />', filename: MONEY_INPUT },
    { code: 'const x = <input inputMode="decimal" />', filename: QUANTITY_INPUT },
    // Cash-counting (money) + non-quantity decimal surfaces — allowlisted.
    { code: 'const x = <input inputMode="decimal" />', filename: SHIFT_CLOSURE },
    { code: 'const x = <input inputMode="decimal" />', filename: CUSTOMER_ATTACH },
    // A non-decimal input mode is never a raw quantity input.
    { code: 'const x = <input inputMode="text" />', filename: REFILL_SHEET },
    // A non-native element (the atom itself) is not a raw <input>.
    { code: 'const x = <QuantityInput inputMode="decimal" />', filename: REFILL_SHEET },
  ],
  invalid: [
    // Raw decimal <input> in an ordinary quantity surface — must use <QuantityInput>.
    {
      code: 'const x = <input inputMode="decimal" value={qty} />',
      filename: REFILL_SHEET,
      errors: [{ messageId: 'rawQuantityInput' }],
    },
  ],
})

// eslint-disable-next-line no-console
console.log('no-raw-quantity-input: all RuleTester cases passed (6 valid, 1 invalid)')
