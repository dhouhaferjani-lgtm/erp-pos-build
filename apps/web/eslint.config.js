import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'

export default tseslint.config(
  { ignores: ['dist', 'e2e', '*.d.ts', '*.config.ts', '*.config.js'] },
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
      'react-refresh/only-export-components': [
        'warn',
        { allowConstantExport: true },
      ],
      // No 'any' type - per CLAUDE.md Rule #3
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-unsafe-assignment': 'error',
      '@typescript-eslint/no-unsafe-call': 'error',
      '@typescript-eslint/no-unsafe-member-access': 'error',
      '@typescript-eslint/no-unsafe-return': 'error',
      // Require explicit return types
      '@typescript-eslint/explicit-function-return-type': 'off',
      '@typescript-eslint/explicit-module-boundary-types': 'off',
      // Allow unused vars with underscore prefix
      '@typescript-eslint/no-unused-vars': ['error', {
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
      }],
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
  // Stricter design token enforcement for new feature directories (created after March 2026)
  {
    files: [
      'src/features/marketing/**/*.{ts,tsx}',
      'src/features/workshop-technicians/**/*.{ts,tsx}',
    ],
    rules: {
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
