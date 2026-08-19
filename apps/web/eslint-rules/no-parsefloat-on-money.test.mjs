/**
 * Tests for the no-parsefloat-on-money ESLint rule.
 *
 * Guard-liveness convention (docs/conventions/08-DETECTOR-LIVENESS.md): this
 * rule shipped with NO RuleTester at all, so nothing proved it still fired.
 * One valid case + one invalid case per distinct pattern the rule claims.
 *
 * Run: node eslint-rules/no-parsefloat-on-money.test.mjs
 */
import { RuleTester } from 'eslint'

import rule from './no-parsefloat-on-money.js'

const ruleTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: { ecmaFeatures: { jsx: true } },
  },
})

ruleTester.run('no-parsefloat-on-money', rule, {
  valid: [
    // Non-monetary identifier names.
    { code: 'const n = parseFloat(width)' },
    { code: 'const n = Number(pageIndex)' },
    // `count` must not trip the `cost` alternative.
    { code: 'const n = Number(count)' },
    // Callee is neither parseFloat nor Number.
    { code: 'const n = parseInt(amount, 10)' },
    // Member callee — the rule only matches bare identifiers.
    { code: 'const n = utils.parseFloat(amount)' },
    // Argument is not an identifier/member (literal, call, template).
    { code: 'const n = parseFloat("1.25")' },
    { code: 'const n = Number(readAmount())' },
    // No argument at all.
    { code: 'const n = Number()' },
  ],
  invalid: [
    // Bare identifier.
    {
      code: 'const n = parseFloat(amount)',
      errors: [{ messageId: 'floatCoercion', data: { callee: 'parseFloat', arg: 'amount' } }],
    },
    // Number() is equally lossy.
    {
      code: 'const n = Number(total)',
      errors: [{ messageId: 'floatCoercion', data: { callee: 'Number', arg: 'total' } }],
    },
    // Static member access — the property name is what is matched.
    {
      code: 'const n = parseFloat(line.unitPrice)',
      errors: [{ messageId: 'floatCoercion', data: { callee: 'parseFloat', arg: 'unitPrice' } }],
    },
    // Computed member access with a string literal key.
    {
      code: "const n = Number(row['qty'])",
      errors: [{ messageId: 'floatCoercion', data: { callee: 'Number', arg: 'qty' } }],
    },
    // Case-insensitive name match.
    {
      code: 'const n = parseFloat(openingBalance)',
      errors: [{ messageId: 'floatCoercion', data: { callee: 'parseFloat', arg: 'openingBalance' } }],
    },
  ],
})

// eslint-disable-next-line no-console
console.log('no-parsefloat-on-money: all RuleTester cases passed (8 valid, 5 invalid)')
