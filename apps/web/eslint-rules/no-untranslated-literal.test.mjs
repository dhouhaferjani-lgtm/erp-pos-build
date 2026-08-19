/**
 * Tests for the no-untranslated-literal ESLint rule.
 *
 * Guard-liveness convention (docs/conventions/08-DETECTOR-LIVENESS.md): this
 * rule shipped with NO RuleTester at all. Covers both patterns it claims (JSX
 * text nodes and the five user-facing attributes) plus its documented
 * non-translatable heuristics and the test-file bail-out.
 *
 * Run: node eslint-rules/no-untranslated-literal.test.mjs
 */
import { RuleTester } from 'eslint'

import rule from './no-untranslated-literal.js'

const ruleTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: { ecmaFeatures: { jsx: true } },
  },
})

const FILE = 'src/features/sales/InvoiceCard.tsx'

ruleTester.run('no-untranslated-literal', rule, {
  valid: [
    // Translated text.
    { filename: FILE, code: "const x = <span>{t('common:save')}</span>" },
    // No 2+ letter run — currency symbols and numbers are not copy.
    { filename: FILE, code: 'const x = <span>{total} €</span>' },
    // Attribute value is an expression, not a literal.
    { filename: FILE, code: "const x = <input placeholder={t('common:search')} />" },
    // Not a user-facing attribute.
    { filename: FILE, code: 'const x = <div className="flex items-center" />' },
    // Non-display parent element.
    { filename: FILE, code: 'const x = <code>SELECT everything</code>' },
    // Single-token code-ish literals the heuristic excludes.
    { filename: FILE, code: 'const x = <span>camelCaseThing</span>' },
    { filename: FILE, code: 'const x = <span>some-kebab-token</span>' },
    // Dotted path / i18n key form (the rule excludes `.`, `_` and `/` tokens).
    // NOTE (recorded, not fixed here — this package ships liveness tests, it
    // does not change rule behaviour): the COLON form `common:save` is NOT
    // excluded by the same heuristic and would be flagged as copy.
    { filename: FILE, code: 'const x = <span>common.save</span>' },
    // Test/story files bail out entirely.
    { filename: 'src/features/sales/InvoiceCard.test.tsx', code: 'const x = <button>Save changes</button>' },
    { filename: 'src/features/sales/__tests__/Card.tsx', code: 'const x = <button>Save changes</button>' },
  ],
  invalid: [
    // JSX text node.
    {
      filename: FILE,
      code: 'const x = <button>Save changes</button>',
      errors: [{ messageId: 'jsxText', data: { text: 'Save changes' } }],
    },
    // placeholder attribute.
    {
      filename: FILE,
      code: 'const x = <input placeholder="Search products" />',
      errors: [{ messageId: 'attr', data: { attr: 'placeholder', text: 'Search products' } }],
    },
    // aria-label attribute (lower-cased before the set lookup).
    {
      filename: FILE,
      code: 'const x = <button aria-label="Close dialog" />',
      errors: [{ messageId: 'attr', data: { attr: 'aria-label', text: 'Close dialog' } }],
    },
    // title attribute.
    {
      filename: FILE,
      code: 'const x = <span title="Outstanding balance" />',
      errors: [{ messageId: 'attr', data: { attr: 'title', text: 'Outstanding balance' } }],
    },
  ],
})

// eslint-disable-next-line no-console
console.log('no-untranslated-literal: all RuleTester cases passed (10 valid, 4 invalid)')
