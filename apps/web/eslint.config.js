import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'
import noHardcodedStep from './eslint-rules/no-hardcoded-step.js'
import noHardcodedEntityRoute from './eslint-rules/no-hardcoded-entity-route.js'
import noDeadTailwindTokenInterpolation from './eslint-rules/no-dead-tailwind-token-interpolation.js'
import noParseFloatOnMoney from './eslint-rules/no-parsefloat-on-money.js'
import noUntranslatedLiteral from './eslint-rules/no-untranslated-literal.js'

// Local Phase-11 precision-guard plugin. Two custom rules discourage the
// float-precision anti-patterns the precision-drift remediation eliminates:
// hardcoded fractional `step` literals on number inputs (use MoneyInput /
// QuantityInput) and parseFloat()/Number() coercion of money/quantity-named
// values (keep the canonical decimal string). Both are WARN level so the
// lint-warning ratchet counts the pre-existing legacy debt instead of
// hard-failing CI.
const precisionPlugin = {
  rules: {
    'no-hardcoded-step': noHardcodedStep,
    'no-parsefloat-on-money': noParseFloatOnMoney,
  },
}

// Local i18n guard plugin. `no-untranslated-literal` flags user-facing string
// literals (JSX text + user-facing attributes) that bypass react-i18next
// `t()`. WARN on the legacy surface (ratcheted by scripts/lint-ratchet.mjs);
// promoted to ERROR for i18n-clean feature dirs in the override block below so
// they can never regress.
const localPlugin = {
  rules: {
    'no-dead-tailwind-token-interpolation': noDeadTailwindTokenInterpolation,
    'no-hardcoded-entity-route': noHardcodedEntityRoute,
    'no-untranslated-literal': noUntranslatedLiteral,
  },
}

export default tseslint.config(
  {
    ignores: [
      'dist',
      'e2e',
      '*.d.ts',
      '*.config.ts',
      '*.config.js',
      // Files not included in tsconfig.json project references — can't be
      // type-checked by ESLint's project-aware parser. Either the files are
      // scaffolded but not wired up yet, or tsconfig needs updating. Tracked
      // as tech debt; ignored here so CI can gate on the main surface.
      '**/src/features/inventory-counting/api/countingApi.ts',
      '**/src/features/inventory-counting/api/queries.ts',
      '**/src/features/inventory-counting/pages/CountingDashboardPage.tsx',
      '**/src/features/inventory-counting/pages/CountingListPage.tsx',
      '**/src/features/pos/organisms/index.ts',
      // Fixtures consumed by the audit-pos-local-cache scanner test suite.
      // Synthetic .ts inputs (raw SQL strings, sync-envelope shapes) outside
      // any tsconfig project on purpose.
      'tools/__fixtures__/**',
    ],
  },
  {
    extends: [js.configs.recommended, ...tseslint.configs.strictTypeChecked],
    files: ['**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2022,
      globals: globals.browser,
      parserOptions: {
        project: ['./tsconfig.json', './tsconfig.node.json'],
        tsconfigRootDir: import.meta.dirname,
      },
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
      precision: precisionPlugin,
      local: localPlugin,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      // i18n guard — WARN on the legacy surface (ratcheted). Cleaned feature
      // dirs promote it to ERROR in the i18n-clean override block at the end.
      'local/no-hardcoded-entity-route': 'warn',
      'local/no-dead-tailwind-token-interpolation': 'error',
      'local/no-untranslated-literal': 'warn',
      // Phase-11 precision-guard rules — WARN level (ratcheted, not hard-fail).
      'precision/no-hardcoded-step': 'warn',
      'precision/no-parsefloat-on-money': 'warn',
      // Downgrade pre-existing react-hooks violations to warn so CI gates on
      // regressions only. New feature directories re-enable these as errors
      // in the strict override block below.
      'react-hooks/exhaustive-deps': 'warn',
      // EXCEPTION: rules-of-hooks is a hard error everywhere. Unlike the
      // heuristic rules below, a conditional / after-early-return hook call is
      // ALWAYS a guaranteed runtime crash ("Rendered more hooks than during the
      // previous render"). The repo is clean (0 violations), so this gates CI
      // with no blast radius and permanently blocks the class. See the
      // ProductDetailPage regression (2026-06-09 parapharmacy e2e).
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/set-state-in-effect': 'warn',
      'react-hooks/set-state-in-render': 'warn',
      'react-hooks/refs': 'warn',
      'react-hooks/component-hook-factories': 'warn',
      'react-hooks/static-components': 'warn',
      'react-hooks/preserve-manual-memoization': 'warn',
      'react-hooks/incompatible-library': 'warn',
      'react-hooks/immutability': 'warn',
      'react-hooks/globals': 'warn',
      'react-hooks/error-boundaries': 'warn',
      'react-hooks/unsupported-syntax': 'warn',
      'react-hooks/use-memo': 'warn',
      'react-hooks/purity': 'warn',
      'react-hooks/fbt': 'warn',
      'react-hooks/gating': 'warn',
      'react-hooks/config': 'warn',
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true },
      ],
      // No 'any' type - per CLAUDE.md Rule #3
      // These are demoted to 'warn' for the legacy surface so CI can gate on
      // regressions only; strict-type-checked issues accumulated across the
      // codebase before the CI infrastructure existed and are being burned
      // down in follow-up passes. New feature directories keep them as
      // 'error' via the override block below.
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unsafe-assignment': 'warn',
      '@typescript-eslint/no-unsafe-call': 'warn',
      '@typescript-eslint/no-unsafe-member-access': 'warn',
      '@typescript-eslint/no-unsafe-return': 'warn',
      '@typescript-eslint/no-unsafe-argument': 'warn',
      '@typescript-eslint/no-floating-promises': 'warn',
      '@typescript-eslint/no-misused-promises': 'warn',
      '@typescript-eslint/no-unnecessary-condition': 'warn',
      '@typescript-eslint/no-unnecessary-type-assertion': 'warn',
      '@typescript-eslint/no-unnecessary-type-conversion': 'warn',
      '@typescript-eslint/no-non-null-assertion': 'warn',
      '@typescript-eslint/no-confusing-void-expression': 'warn',
      '@typescript-eslint/no-invalid-void-type': 'warn',
      '@typescript-eslint/no-empty-object-type': 'warn',
      '@typescript-eslint/no-base-to-string': 'warn',
      '@typescript-eslint/no-deprecated': 'warn',
      '@typescript-eslint/restrict-template-expressions': 'warn',
      '@typescript-eslint/unbound-method': 'warn',
      '@typescript-eslint/require-await': 'warn',
      '@typescript-eslint/await-thenable': 'warn',
      '@typescript-eslint/prefer-promise-reject-errors': 'warn',
      '@typescript-eslint/only-throw-error': 'warn',
      '@typescript-eslint/restrict-plus-operands': 'warn',
      '@typescript-eslint/no-redundant-type-constituents': 'warn',
      '@typescript-eslint/no-duplicate-type-constituents': 'warn',
      '@typescript-eslint/no-misused-spread': 'warn',
      '@typescript-eslint/no-dynamic-delete': 'warn',
      '@typescript-eslint/no-unsafe-enum-comparison': 'warn',
      '@typescript-eslint/no-unsafe-function-type': 'warn',
      '@typescript-eslint/no-unsafe-type-assertion': 'warn',
      '@typescript-eslint/prefer-nullish-coalescing': 'warn',
      '@typescript-eslint/prefer-optional-chain': 'warn',
      '@typescript-eslint/prefer-reduce-type-parameter': 'warn',
      '@typescript-eslint/prefer-regexp-exec': 'warn',
      '@typescript-eslint/prefer-string-starts-ends-with': 'warn',
      '@typescript-eslint/prefer-includes': 'warn',
      '@typescript-eslint/prefer-for-of': 'warn',
      '@typescript-eslint/consistent-type-definitions': 'warn',
      '@typescript-eslint/consistent-indexed-object-style': 'warn',
      '@typescript-eslint/consistent-generic-constructors': 'warn',
      '@typescript-eslint/array-type': 'warn',
      '@typescript-eslint/dot-notation': 'warn',
      '@typescript-eslint/no-for-in-array': 'warn',
      '@typescript-eslint/no-useless-constructor': 'warn',
      '@typescript-eslint/no-extraneous-class': 'warn',
      '@typescript-eslint/no-require-imports': 'warn',
      '@typescript-eslint/no-this-alias': 'warn',
      '@typescript-eslint/no-wrapper-object-types': 'warn',
      // Require explicit return types
      '@typescript-eslint/explicit-function-return-type': 'off',
      '@typescript-eslint/explicit-module-boundary-types': 'off',
      // Allow unused vars with underscore prefix. Demoted to warn for the
      // legacy surface; new-feature override re-enables as error.
      '@typescript-eslint/no-unused-vars': ['warn', {
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
      }],
      'no-unused-vars': 'off',
      '@typescript-eslint/no-unnecessary-template-expression': 'warn',
      'no-case-declarations': 'warn',
      '@typescript-eslint/return-await': 'warn',
      // Warn against hardcoded Tailwind color classes in legacy files
      // Encourage use of design tokens from lib/designTokens.ts
      'no-restricted-syntax': [
        'warn',
        {
          selector: 'Literal[value=/\\b(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-(\\d{2,3})\\b/]',
          message: 'Avoid hardcoded Tailwind color classes. Use design tokens from lib/designTokens.ts instead. Example: tokens.input.base, tokens.button.primary',
        },
        {
          selector: 'TemplateElement[value.raw=/\\b(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-(\\d{2,3})\\b/]',
          message: 'Avoid hardcoded Tailwind color classes. Use design tokens from lib/designTokens.ts instead. Example: tokens.input.base, tokens.button.primary',
        },
      ],
    },
  },
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: [
      'src/features/documents/**/*.{ts,tsx}',
      'src/features/admin/**/*.{ts,tsx}',
      'src/lib/designTokens.ts',
    ],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['**/designTokens', '@/lib/designTokens'],
              importNames: ['colorClasses'],
              message: 'colorClasses is quarantined for the Wave 5 documents/admin sweep. Use semantic design tokens instead.',
            },
          ],
        },
      ],
    },
  },
  // Stricter enforcement for new feature directories scaffolded under the
  // AutoSpecs plan. These dirs will be created clean and must stay clean —
  // the full strict-type-checked preset applies with no baselining.
  //
  // NOTE: `marketing/` is NOT listed here — it has pre-existing design-token
  // violations. `vehicles/` was migrated to design tokens + type guards by
  // the autospecs-design-audit pass (Apr 2026), so it now carries the
  // strict override alongside the four Plan A.5–D feature dirs.
  {
    files: [
      'src/features/autospecs/**/*.{ts,tsx}',
      'src/features/scheduling/**/*.{ts,tsx}',
      'src/features/vehicles/**/*.{ts,tsx}',
      'src/features/workshop-bundles/**/*.{ts,tsx}',
      'src/features/workshop-technicians/**/*.{ts,tsx}',
      'src/features/workshop-work-orders/**/*.{ts,tsx}',
      'src/features/document-ingestions/**/*.{ts,tsx}',
    ],
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-unsafe-assignment': 'error',
      '@typescript-eslint/no-unsafe-call': 'error',
      '@typescript-eslint/no-unsafe-member-access': 'error',
      '@typescript-eslint/no-unsafe-return': 'error',
      '@typescript-eslint/no-unsafe-argument': 'error',
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/no-misused-promises': 'error',
      '@typescript-eslint/no-non-null-assertion': 'error',
      '@typescript-eslint/no-unused-vars': ['error', {
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
      }],
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/\\b(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in new features. Use design tokens from lib/designTokens.ts instead. Example: tokens.input.base, tokens.button.primary',
        },
        {
          selector: 'TemplateElement[value.raw=/\\b(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in new features. Use design tokens from lib/designTokens.ts instead. Example: tokens.input.base, tokens.button.primary',
        },
      ],
    },
  },
  // Scheduling-specific tightening — the scheduler uses a wider palette
  // (slate/sky/amber/violet/emerald/stone/rose/zinc) for status nuance, so
  // semantic tokens (tokens.statusBadge.*, tokens.utilizationBar.*,
  // tokens.toggleButton.*) were added in designTokens.ts to cover them.
  // This override forbids the extra palettes as hardcoded literals in
  // scheduling source files to prevent regressions.
  {
    files: ['src/features/scheduling/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo|slate|sky|amber|violet|emerald|stone|rose|zinc|teal|cyan|lime|orange|fuchsia|neutral)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in scheduling. Use design tokens from lib/designTokens.ts (tokens.statusBadge.*, tokens.utilizationBar.*, tokens.toggleButton.*).',
        },
        {
          selector: 'TemplateElement[value.raw=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo|slate|sky|amber|violet|emerald|stone|rose|zinc|teal|cyan|lime|orange|fuchsia|neutral)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in scheduling. Use design tokens from lib/designTokens.ts (tokens.statusBadge.*, tokens.utilizationBar.*, tokens.toggleButton.*).',
        },
      ],
    },
  },
  // Workshop-specific tightening (UI-consistency Phase 3.6) — the workshop
  // dirs previously exploited the base color rule's GAP (it only flags
  // red/blue/green/yellow/gray/purple/pink/indigo) to ship saturated with
  // off-theme palettes. They were swept to 0 off-theme literals (StatusPill →
  // StatusBadge, bespoke modals → Modal organism, all colors → tokens), so the
  // full palette is now banned as ERROR here to prevent the gap from reopening.
  {
    files: ['src/features/workshop-{work-orders,technicians,bundles}/**/*.{ts,tsx}'],
    ignores: ['**/*.test.{ts,tsx}', '**/__tests__/**'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo|slate|sky|amber|violet|emerald|stone|rose|zinc|teal|cyan|lime|orange|fuchsia|neutral)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in workshop-*. Use design tokens from lib/designTokens.ts (StatusBadge/statusTone, tokens.*, colors.*, textColors.*, borderColors.*).',
        },
        {
          selector: 'TemplateElement[value.raw=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo|slate|sky|amber|violet|emerald|stone|rose|zinc|teal|cyan|lime|orange|fuchsia|neutral)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in workshop-*. Use design tokens from lib/designTokens.ts (StatusBadge/statusTone, tokens.*, colors.*, textColors.*, borderColors.*).',
        },
      ],
    },
  },
  // Wave 5 design-system sweep: migrated directories have been reduced to 0
  // hardcoded color literals in production source. Keep the broader
  // C7-equivalent utility/palette regex as ERROR here so the next sweep does
  // not inherit regressions.
  {
    files: [
      'src/features/documents/**/*.{ts,tsx}',
      'src/features/admin/**/*.{ts,tsx}',
      'src/features/import/**/*.{ts,tsx}',
      'src/features/inventory-counting/**/*.{ts,tsx}',
      'src/features/opening-balances/**/*.{ts,tsx}',
      'src/features/partners/**/*.{ts,tsx}',
      'src/features/compliance/**/*.{ts,tsx}',
      'src/features/loyalty/**/*.{ts,tsx}',
      'src/features/parts-catalog/**/*.{ts,tsx}',
      'src/features/auth/**/*.{ts,tsx}',
      'src/features/services/**/*.{ts,tsx}',
      'src/features/batches/**/*.{ts,tsx}',
      'src/features/parapharmacy/**/*.{ts,tsx}',
      'src/features/pricing/**/*.{ts,tsx}',
      'src/features/vat-reporting/**/*.{ts,tsx}',
      'src/features/vouchers/**/*.{ts,tsx}',
      'src/features/categories/**/*.{ts,tsx}',
      'src/features/crm/**/*.{ts,tsx}',
      'src/features/products/**/*.{ts,tsx}',
      'src/features/dashboard/**/*.{ts,tsx}',
      'src/features/reports/**/*.{ts,tsx}',
      'src/features/uom/**/*.{ts,tsx}',
      'src/features/pos/**/*.{ts,tsx}',
      'src/features/expenses/**/*.{ts,tsx}',
      'src/features/coupons/**/*.{ts,tsx}',
      'src/features/promotions/**/*.{ts,tsx}',
      'src/features/stock-transfers/**/*.{ts,tsx}',
      'src/features/settings/**/*.{ts,tsx}',
      'src/features/catalog/**/*.{ts,tsx}',
      'src/features/vehicles/**/*.{ts,tsx}',
      'src/features/customer-history-audit/**/*.{ts,tsx}',
      'src/features/locations/**/*.{ts,tsx}',
      'src/features/company/**/*.{ts,tsx}',
      'src/features/inventory/**/*.{ts,tsx}',
      'src/pages/legal/**/*.{ts,tsx}',
    ],
    ignores: ['**/*.test.{ts,tsx}', '**/__tests__/**', '**/*.stories.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/\\b(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone|sky|violet|teal|cyan|lime|fuchsia)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in Wave 5 migrated directories. Use semantic design tokens from lib/designTokens.ts.',
        },
        {
          selector: 'TemplateElement[value.raw=/\\b(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone|sky|violet|teal|cyan|lime|fuchsia)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in Wave 5 migrated directories. Use semantic design tokens from lib/designTokens.ts.',
        },
        {
          selector: 'TemplateElement[value.raw=/(hover|focus|focus-within|focus-visible|group-hover|disabled|placeholder|active|dark|file):$/]',
          message: 'Do not compose Tailwind variants with token interpolation. Add the complete class literal to designTokens.ts and reference that token.',
        },
      ],
    },
  },
  // i18n-clean dirs — fully EN/FR translated in the 2026-06 sweep; no
  // untranslated user-facing literal may regress here. The internal
  // super-admin panel (src/features/admin) is intentionally NOT listed yet
  // (deferred — staff-only surface), so it stays WARN-ratcheted.
  // See docs/i18n/README.md + docs/i18n/i18n-tracker.yaml.
  {
    files: [
      'src/components/**/*.{ts,tsx}',
      'src/pages/**/*.{ts,tsx}',
      'src/features/inventory/**/*.{ts,tsx}',
      'src/features/documents/**/*.{ts,tsx}',
      'src/features/finance/**/*.{ts,tsx}',
      'src/features/settings/**/*.{ts,tsx}',
      'src/features/vehicles/**/*.{ts,tsx}',
      'src/features/pos/**/*.{ts,tsx}',
      'src/features/compliance/**/*.{ts,tsx}',
      'src/features/treasury/**/*.{ts,tsx}',
      'src/features/pricing/**/*.{ts,tsx}',
      'src/features/company/**/*.{ts,tsx}',
      'src/features/parapharmacy/**/*.{ts,tsx}',
      'src/features/services/**/*.{ts,tsx}',
      'src/features/uom/**/*.{ts,tsx}',
      'src/features/import/**/*.{ts,tsx}',
      'src/features/loyalty/**/*.{ts,tsx}',
      'src/features/products/**/*.{ts,tsx}',
      'src/features/catalog/**/*.{ts,tsx}',
      'src/features/partners/**/*.{ts,tsx}',
      'src/features/parts-catalog/**/*.{ts,tsx}',
      'src/features/withholding/**/*.{ts,tsx}',
      'src/features/workshop-technicians/**/*.{ts,tsx}',
      'src/features/document-ingestions/**/*.{ts,tsx}',
    ],
    rules: { 'local/no-untranslated-literal': 'error' },
  },
)
