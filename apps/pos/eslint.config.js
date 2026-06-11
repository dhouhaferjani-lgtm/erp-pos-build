import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';

/**
 * T2.6 — flat ESLint v9 config for apps/pos.
 *
 * Replaces the missing `.eslintrc.*` that ESLint v9 stopped supporting.
 * Pattern mirrors `apps/web/eslint.config.js` but with conservative rule
 * severities — apps/pos has never had ESLint enforcement, so most
 * existing code violates strict rules. Everything that would block CI
 * is demoted to `warn` so the gate only catches regressions on rules
 * that were previously zero-error (currently: nothing — the gate is a
 * pure ratchet that lands the suite green and lets us tighten over
 * time).
 *
 * Flat config has no `--ext` flag; files are picked up from the
 * `files` glob in each block.
 */
export default tseslint.config(
  {
    ignores: [
      'dist',
      'src-tauri/target',
      '**/*.d.ts',
      '*.config.ts',
      '*.config.js',
      'scripts/**',
    ],
  },
  {
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    files: ['src/**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2022,
      globals: { ...globals.browser, ...globals.node },
      parserOptions: {
        // Lightweight (non-type-checked) parser — apps/pos has not
        // been baselined against the type-checked rule set; switching
        // it on now would surface thousands of warnings. Type checking
        // is already enforced via `tsc --noEmit` in the typecheck
        // gate; ESLint here covers stylistic / hooks / refresh rules.
        ecmaVersion: 'latest',
        sourceType: 'module',
      },
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      // React Hooks — recommended preset, demoted to warn for the
      // legacy surface (apps/pos has accumulated violations across
      // many files predating this config). New code lands clean
      // because typecheck + tests gate first.
      ...reactHooks.configs.recommended.rules,
      'react-hooks/exhaustive-deps': 'warn',
      // rules-of-hooks is a hard error: a conditional / after-early-return hook
      // call is ALWAYS a guaranteed runtime crash. Repo is clean (0 violations),
      // so this gates CI with no blast radius. Mirrors apps/web.
      'react-hooks/rules-of-hooks': 'error',
      // Newer hooks rules (v7+) — same warn treatment so the legacy
      // surface doesn't block CI on rule additions.
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

      // React Refresh — fast-refresh hygiene for Vite HMR.
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true },
      ],

      // typescript-eslint — recommended preset, all demoted to warn.
      // Strict-type-checked is OFF (no project-aware parser
      // configured here; that's the typecheck gate's job). Keeping
      // these as warn lets CI ratchet down rule-by-rule without a
      // big-bang migration.
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': [
        'warn',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
      'no-unused-vars': 'off',

      // Misc rules that conflict with the codebase's existing style.
      'no-empty-pattern': 'warn',
      'no-case-declarations': 'warn',
      'no-useless-escape': 'warn',
      'prefer-const': 'warn',
      'no-async-promise-executor': 'warn',
      'no-control-regex': 'warn',
      'no-irregular-whitespace': 'warn',
      // require() in test mocks is a legitimate pattern (e.g. detecting
      // optional Node-only modules with try/require). Demoted to warn
      // for the legacy surface; new code can switch to dynamic
      // import() if/when this gets ratcheted.
      '@typescript-eslint/no-require-imports': 'warn',
      // Bare Function type in test fixtures — demoted; new code
      // should prefer specific function signatures.
      '@typescript-eslint/no-unsafe-function-type': 'warn',
    },
  },
);
