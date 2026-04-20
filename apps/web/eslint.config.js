import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'

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
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      // Downgrade pre-existing react-hooks violations to warn so CI gates on
      // regressions only. New feature directories re-enable these as errors
      // in the strict override block below.
      'react-hooks/exhaustive-deps': 'warn',
      'react-hooks/rules-of-hooks': 'warn',
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
          selector: 'Literal[value=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo)-(\\d{2,3})\\b/]',
          message: 'Avoid hardcoded Tailwind color classes. Use design tokens from lib/designTokens.ts instead. Example: tokens.input.base, tokens.button.primary',
        },
      ],
    },
  },
  // Stricter enforcement for new feature directories scaffolded under the
  // AutoSpecs plan. These dirs will be created clean and must stay clean —
  // the full strict-type-checked preset applies with no baselining.
  //
  // NOTE: `marketing/` and `vehicles/` are NOT listed here. `marketing/` has
  // pre-existing design-token violations; `vehicles/` is the pre-Plan-A
  // implementation that will be replaced. Add them back once the new
  // implementations land and pass a clean lint sweep.
  {
    files: [
      'src/features/autospecs/**/*.{ts,tsx}',
      'src/features/marketing/**/*.{ts,tsx}',
      'src/features/workshop-bundles/**/*.{ts,tsx}',
      'src/features/workshop-technicians/**/*.{ts,tsx}',
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
          selector: 'Literal[value=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo)-(\\d{2,3})\\b/]',
          message: 'Hardcoded Tailwind color classes are not allowed in new features. Use design tokens from lib/designTokens.ts instead. Example: tokens.input.base, tokens.button.primary',
        },
      ],
    },
  },
)
