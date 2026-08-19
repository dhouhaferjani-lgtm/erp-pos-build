/**
 * Tests for the no-hardcoded-entity-route ESLint rule.
 *
 * Guard-liveness convention (docs/conventions/08-DETECTOR-LIVENESS.md): this
 * rule shipped with NO RuleTester at all. Covers each literal shape it resolves
 * (plain string, JSX expression string, template literal, `+` concatenation),
 * the `new`/`create` suffix allowance, the element allowlist, and the
 * entityRoutes.ts self-exemption.
 *
 * Run: node eslint-rules/no-hardcoded-entity-route.test.mjs
 */
import { RuleTester } from 'eslint'

import rule from './no-hardcoded-entity-route.js'

const ruleTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: { ecmaFeatures: { jsx: true } },
  },
})

const FILE = 'src/features/sales/InvoiceList.tsx'

ruleTester.run('no-hardcoded-entity-route', rule, {
  valid: [
    // Centralised helper.
    { filename: FILE, code: 'const x = <Link to={entityRoutes.invoice(id)} />' },
    // Allowed create suffixes.
    { filename: FILE, code: 'const x = <Link to="/sales/invoices/new" />' },
    { filename: FILE, code: 'const x = <Link to="/sales/quotes/create" />' },
    // Not an entity-detail prefix.
    { filename: FILE, code: 'const x = <Link to="/settings/company" />' },
    // Not a Link/NavLink element.
    { filename: FILE, code: 'const x = <a href="/sales/invoices/5" />' },
    { filename: FILE, code: 'const x = <Button to="/sales/invoices/5" />' },
    // Not the `to` prop.
    { filename: FILE, code: 'const x = <Link from="/sales/invoices/5" />' },
    // The route module itself is exempt.
    { filename: '/repo/apps/web/src/lib/entityRoutes.ts', code: 'const x = <Link to="/sales/invoices/5" />' },
  ],
  invalid: [
    // Plain string literal attribute.
    {
      filename: FILE,
      code: 'const x = <Link to="/sales/invoices/5" />',
      errors: [{ messageId: 'hardcodedEntityRoute', data: { prefix: '/sales/invoices/' } }],
    },
    // JSX expression container holding a string literal.
    {
      filename: FILE,
      code: 'const x = <NavLink to={"/inventory/products/42"} />',
      errors: [{ messageId: 'hardcodedEntityRoute', data: { prefix: '/inventory/products/' } }],
    },
    // Template literal — the first quasi carries the prefix.
    {
      filename: FILE,
      code: 'const x = <Link to={`/treasury/payments/${id}`} />',
      errors: [{ messageId: 'hardcodedEntityRoute', data: { prefix: '/treasury/payments/' } }],
    },
    // `+` concatenation — the left operand carries the prefix.
    {
      filename: FILE,
      code: "const x = <Link to={'/expenses/' + id} />",
      errors: [{ messageId: 'hardcodedEntityRoute', data: { prefix: '/expenses/' } }],
    },
  ],
})

// eslint-disable-next-line no-console
console.log('no-hardcoded-entity-route: all RuleTester cases passed (8 valid, 4 invalid)')
