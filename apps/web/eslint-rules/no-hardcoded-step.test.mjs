/**
 * Tests for the no-hardcoded-step ESLint rule.
 *
 * Run: node eslint-rules/no-hardcoded-step.test.mjs
 */
import { RuleTester } from 'eslint'

import rule from './no-hardcoded-step.js'

const ruleTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: { ecmaFeatures: { jsx: true } },
  },
})

ruleTester.run('no-hardcoded-step', rule, {
  valid: [
    { code: 'const x = <input type="number" step={step} />' },
    { code: 'const x = <input type="number" step={0.01} />' },
    { code: 'const x = <input type="number" step="1" />' },
    { code: 'const x = <input type="text" step="0.01" />' },
    { code: 'const x = <input type="number" />' },
    { code: 'const x = <QuantityInput type="number" step="0.01" />' },
  ],
  invalid: [
    {
      code: 'const x = <input type="number" step="0.01" />',
      errors: [{ messageId: 'hardcodedStep', data: { step: '0.01' } }],
    },
    {
      code: 'const x = <input type={"number"} step={"0.001"} />',
      errors: [{ messageId: 'hardcodedStep', data: { step: '0.001' } }],
    },
    {
      code: 'const x = <input TYPE="number" STEP=".5" />',
      errors: [{ messageId: 'hardcodedStep', data: { step: '.5' } }],
    },
  ],
})

// eslint-disable-next-line no-console
console.log('no-hardcoded-step: all RuleTester cases passed (6 valid, 3 invalid)')
