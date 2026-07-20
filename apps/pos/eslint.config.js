import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import noUntranslatedLiteral from './eslint-rules/no-untranslated-literal.js';
import noParseFloatOnMoney from './eslint-rules/no-parsefloat-on-money.js';
import noHardcodedStep from './eslint-rules/no-hardcoded-step.js';
import noRawQuantityInput from './eslint-rules/no-raw-quantity-input.js';

// Local i18n guard plugin — flags user-facing string literals that bypass
// react-i18next `t()`. WARN on the legacy surface (ratcheted by
// scripts/lint-ratchet.mjs); cleaned dirs promote to ERROR via an override.
const localPlugin = {
  rules: {
    'no-untranslated-literal': noUntranslatedLiteral,
  },
};

// Precision guard plugin (2026-07-01 desktop precision sweep). Flags
// parseFloat()/Number() coercion of monetary/quantity-named values — float
// coercion reintroduces the precision drift the sweep eliminated. Money/qty
// must stay decimal strings and go through lib/decimal (big.js). WARN level so
// the lint-ratchet counts the remaining legacy debt instead of hard-failing.
const precisionPlugin = {
  rules: {
    'no-parsefloat-on-money': noParseFloatOnMoney,
    // UoM display-precision guards (2026-07-20): hardcoded fractional step
    // literals on number inputs and raw <input inputMode="decimal"> quantity
    // inputs that bypass the <QuantityInput> atom.
    'no-hardcoded-step': noHardcodedStep,
    'no-raw-quantity-input': noRawQuantityInput,
  },
};

/**
 * FU-2 cart-mutator selectors — the raw cartStore actions must only be called
 * through the gated funnel (addItemGated / updateQuantityGated). Extracted to a
 * shared const so any block that re-declares `no-restricted-syntax` (flat config
 * REPLACES the rule across overlapping file globs rather than merging) can
 * re-include them and keep the guard alive.
 */
const cartMutatorSelectors = [
  {
    selector: "CallExpression[callee.property.name='addItem']",
    message:
      'Do not call cartStore.addItem directly — route cart adds through addItemGated() in lib/stock/cartIngress.ts so the availability gate runs.',
  },
  {
    selector: "CallExpression[callee.property.name='updateQuantity']",
    message:
      'Do not call cartStore.updateQuantity directly — route quantity changes through updateQuantityGated() in lib/stock/cartIngress.ts so the availability gate runs.',
  },
  {
    selector: "CallExpression[callee.type='Identifier'][callee.name='addItem']",
    message:
      'Do not call the raw addItem action directly — route cart adds through addItemGated() in lib/stock/cartIngress.ts so the availability gate runs.',
  },
  {
    selector: "CallExpression[callee.type='Identifier'][callee.name='updateQuantity']",
    message:
      'Do not call the raw updateQuantity action directly — route quantity changes through updateQuantityGated() in lib/stock/cartIngress.ts so the availability gate runs.',
  },
];

/**
 * Hardcoded-color guard (design-language remediation, 2026-06-13). Flags raw
 * Tailwind palette classes; code must use the semantic tokens defined in
 * src/index.css @theme (bg-surface-*, text-ink*, bg-action, bg-success/
 * warning/danger*, …) and the recipes in lib/designTokens.ts.
 */
const hardcodedColorSelector = {
  selector:
    'Literal[value=/\\b(bg|text|border|ring)-(red|blue|green|yellow|gray|purple|pink|indigo|slate|sky|amber|violet|emerald|stone|rose|zinc|teal|cyan|lime|orange|fuchsia|neutral)-(\\d{2,3})\\b/]',
  message:
    'Avoid hardcoded Tailwind color classes. Use semantic tokens (bg-surface-*, text-ink*, bg-action, bg-success/warning/danger*) from src/index.css @theme, or recipes in lib/designTokens.ts.',
};

/**
 * Directories migrated to semantic tokens — enforced at ERROR (color + cart).
 * The hardcoded-color rule cannot be a global `warn` because `no-restricted-
 * syntax` already carries the FU-2 cart guard at `error` on the same file glob,
 * and flat config can't mix severities within one rule. So instead of warn-on-
 * all-legacy, we enforce color as ERROR on the dirs we've actually cleaned, and
 * ratchet this list as more dirs are migrated. Untouched legacy dirs keep only
 * the cart guard until they're migrated.
 */
const tokenMigratedGlobs = [
  'src/components/molecules/ProductCard/**/*.{ts,tsx}',
  'src/components/organisms/ProductGrid/**/*.{ts,tsx}',
  'src/components/Header.tsx',
  'src/components/AppShell.tsx',
  'src/components/PageHeader.tsx',
  'src/pages/ZReportListPage.tsx',
  'src/components/fiscal/UnsyncedRiskIndicator.tsx',
  'src/components/customers/CustomerSearchInput.tsx',
  'src/components/customers/CustomerAttachPanel.tsx',
  'src/components/customers/CustomerSearchModal.tsx',
  'src/components/pos/Modal.tsx',
  'src/components/pos/CardPaymentModal.tsx',
  'src/components/pos/CheckoutSuccessModal.tsx',
  'src/components/pos/ReportsMenu.tsx',
  'src/components/pos/TodaySalesPanel.tsx',
  'src/components/pos/HeldTransactionsModal.tsx',
  'src/components/pos/RefundCheckoutFlow.tsx',
  'src/components/pos/ZReportModal.tsx',
  'src/components/pos/EndOfDayPreviewModal.tsx',
  'src/components/pos/CashDrawerModal.tsx',
  'src/components/pos/XReportModal.tsx',
  'src/components/pos/CloseShiftModal.tsx',
  'src/components/pos/VoucherTenderModal.tsx',
  'src/components/pos/CashTenderedModal.tsx',
  'src/components/pos/ReceiptLocatorScreen.tsx',
  'src/components/pos/ReceiptScanConfirmationSheet.tsx',
  'src/components/pos/RefundConfirmModal.tsx',
  'src/components/pos/RefundDestinationPicker.tsx',
  'src/components/pos/ResumeRefundDraftBanner.tsx',
  'src/components/pos/QuantityNumpad.tsx',
  'src/components/pos/atoms/CurrencyNumpad.tsx',
  'src/components/pos/CashReconciliationSection.tsx',
  'src/components/pos/organisms/CashCountTable.tsx',
  'src/components/pos/OpenShiftScreen.tsx',
  'src/components/pos/molecules/ManagerPinPanel.tsx',
  'src/components/pos/VariantPickerModal.tsx',
  'src/components/pos/molecules/ToleranceDrillDown.tsx',
  'src/components/organisms/ModifierSelectionModal/**/*.{ts,tsx}',
  'src/components/organisms/CashPaymentScreen/**/*.{ts,tsx}',
  'src/components/organisms/AdvancedPaymentsModal/**/*.{ts,tsx}',
  'src/components/organisms/LineDiscountModal/**/*.{ts,tsx}',
  'src/components/organisms/DiscountModal/**/*.{ts,tsx}',
  'src/components/molecules/NumPad/**/*.{ts,tsx}',
  'src/pages/SettingsPage.tsx',
  'src/components/organisms/TransactionCart/**/*.{ts,tsx}',
  'src/components/molecules/QuickActions/**/*.{ts,tsx}',
  'src/components/customers/CartCustomerControl.tsx',
  'src/components/pos/PaymentSummary.tsx',
  'src/components/molecules/CartLineItem/**/*.{ts,tsx}',
  'src/components/ui/**/*.{ts,tsx}',
];

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
      local: localPlugin,
      precision: precisionPlugin,
    },
    rules: {
      // i18n guard — promoted to ERROR for apps/pos (all 7 flagged files
      // translated in the EN/FR sweep). No legacy surface remains.
      'local/no-untranslated-literal': 'error',
      // Precision guard (desktop precision sweep) — WARN, ratcheted. Keeps
      // money/qty as decimal strings; blocks new parseFloat()/Number() drift.
      'precision/no-parsefloat-on-money': 'warn',
      // UoM display-precision guards (2026-07-20) — WARN, ratcheted. Hardcoded
      // fractional step literals + raw <input inputMode="decimal"> quantity
      // inputs that bypass the <QuantityInput> atom. Promoted to ERROR for the
      // precision-cleaned files in the strict override block below.
      'precision/no-hardcoded-step': 'warn',
      'precision/no-raw-quantity-input': 'warn',
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
  // FU-2 — cart-mutator guard. The raw cartStore actions (`addItem` /
  // `updateQuantity`) must only be invoked through the gated funnel in
  // `lib/stock/cartIngress.ts` (`addItemGated` / `updateQuantityGated`) so the
  // location-stock availability gate always runs (lesson L9: one cart ingress).
  // A future caller that reaches the store directly would silently bypass the
  // gate. ERROR severity: the production surface is clean today (every ingress
  // already routes through the funnel), so this is a pure ratchet. Precedent:
  // the `no-parsefloat-on-money` precision-guard in apps/web. Complements — and
  // is intended to eventually replace — the HomePage source-pin
  // (`lib/stock/__tests__/homePageIngressPin.test.ts`), which additionally
  // covers the bare-destructured `addItem`/`updateQuantity` form.
  //
  // Exemptions (this block's `ignores`):
  //   - `lib/stock/**`          — the gated funnel itself calls the raw actions;
  //   - `stores/cartStore.ts`   — the store composes its own actions;
  //   - test files              — set cart state directly via the store.
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: [
      'src/lib/stock/**',
      'src/stores/cartStore.ts',
      '**/*.test.{ts,tsx}',
      'src/**/__tests__/**',
    ],
    rules: {
      'no-restricted-syntax': ['error', ...cartMutatorSelectors],
    },
  },
  // Token-migrated dirs — ERROR on hardcoded colors (design-language guardrail).
  // Re-includes the cart selectors because flat config REPLACES `no-restricted-
  // syntax` for files matched by a later block; omitting them would silently
  // drop the FU-2 guard on these dirs. Tests are excluded (they set cart state
  // directly and aren't user-facing surface).
  {
    files: tokenMigratedGlobs,
    ignores: ['**/*.test.{ts,tsx}', 'src/**/__tests__/**'],
    rules: {
      'no-restricted-syntax': [
        'error',
        ...cartMutatorSelectors,
        hardcodedColorSelector,
      ],
    },
  },
  {
    // Single-writer architecture guard (2026-06-12 design spec): SQLite
    // transactions MUST go through writeGate's withWriteTransaction on the
    // Rust writer connection. A BEGIN/COMMIT/ROLLBACK issued through the
    // pooled tauri-plugin-sql handle splits across physical connections
    // (self-deadlock + transaction poisoning) — the root cause of the
    // "database is locked" checkout failure. Exempt: writeGate.ts (owns the
    // statements), migrations.ts (runs on the writer during the boot gate
    // job), and tests (adapters/harnesses).
    files: ['src/**/*.{ts,tsx}'],
    ignores: [
      'src/lib/db/writeGate.ts',
      'src/lib/db/migrations.ts',
      '**/*.test.{ts,tsx}',
      'src/**/__tests__/**',
    ],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector:
            "CallExpression[callee.property.name='execute'] > Literal[value=/^\\s*(BEGIN|COMMIT|ROLLBACK)/i]",
          message:
            'Never issue BEGIN/COMMIT/ROLLBACK through a pooled DB handle — the tauri-plugin-sql pool splits a transaction across physical connections (self-deadlock + tx poisoning). Use withWriteTransaction() from @/lib/db/writeGate.',
        },
      ],
    },
  },
  {
    // Precision lock (desktop precision sweep, 2026-07-01): files cleaned by
    // the sweep are promoted to ERROR so a re-introduced parseFloat()/Number()
    // on a money/quantity value hard-fails CI and cannot regress. Legacy sites
    // elsewhere stay WARN (burn-down ratchet). Add files here as they're cleaned.
    files: [
      'src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx',
      'src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx',
      'src/components/pos/CashTenderedModal.tsx',
      'src/components/pos/TodaySalesPanel.tsx',
      'src/stores/cartStore.ts',
      'src/stores/holdStore.ts',
      'src/stores/refundDraftStore.ts',
      'src/stores/paymentStore.ts',
      'src/lib/offline/receiptService.ts',
    ],
    plugins: { precision: precisionPlugin },
    rules: {
      'precision/no-parsefloat-on-money': 'error',
      // UoM display-precision guards — hard ERROR on the precision-cleaned files
      // so a re-introduced hardcoded step / raw decimal quantity input can never
      // regress here.
      'precision/no-hardcoded-step': 'error',
      'precision/no-raw-quantity-input': 'error',
    },
  },
);
