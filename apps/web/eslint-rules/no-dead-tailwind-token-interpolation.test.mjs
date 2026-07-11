/**
 * Tests for the no-dead-tailwind-token-interpolation ESLint rule.
 *
 * The `eslint-rules/` directory is outside vitest's `include` globs, so this
 * file is a standalone RuleTester script: ESLint's RuleTester runs its cases
 * eagerly and throws on the first failing assertion, so `node <thisfile>`
 * exits non-zero on failure and prints the summary below on success.
 *
 * Run: node eslint-rules/no-dead-tailwind-token-interpolation.test.mjs
 */
import { RuleTester } from 'eslint'

import rule from './no-dead-tailwind-token-interpolation.js'

const ruleTester = new RuleTester({
  languageOptions: { ecmaVersion: 2022, sourceType: 'module' },
})

ruleTester.run('no-dead-tailwind-token-interpolation', rule, {
  valid: [
    // Plain interpolations with no glued variant/opacity.
    { code: 'const c = `${a} ${b}`' },
    // A literal (non-variant) prefix glued to an interpolation is fine.
    { code: 'const c = `px-2 ${token}`' },
    // Opacity on a full literal class (not glued to the interpolation) is fine.
    { code: 'const c = `${token} bg-white/50`' },
    // Fractional utility with no interpolation at all.
    { code: 'const c = `w-1/2`' },
    // Leading quasi starting with `/digit` is NOT after an interpolation → not a hit.
    { code: 'const c = `/50${token}`' },
  ],
  invalid: [
    // BL-1: variant prefix glued before an interpolation.
    {
      code: 'const c = `hover:${token}`',
      errors: [{ messageId: 'variantInterpolation' }],
    },
    {
      code: 'const c = `group-hover:${token}`',
      errors: [{ messageId: 'variantInterpolation' }],
    },
    // BL-2: opacity modifier glued after an interpolation.
    {
      code: 'const c = `${token}/50`',
      errors: [{ messageId: 'opacityInterpolation' }],
    },
    {
      code: 'const c = `${bg}/75 text-white`',
      errors: [{ messageId: 'opacityInterpolation' }],
    },
    // Both patterns in one template literal.
    {
      code: 'const c = `focus:${a} ${b}/75`',
      errors: [{ messageId: 'variantInterpolation' }, { messageId: 'opacityInterpolation' }],
    },
  ],
})

// eslint-disable-next-line no-console
console.log(
  'no-dead-tailwind-token-interpolation: all RuleTester cases passed (5 valid, 5 invalid)',
)
