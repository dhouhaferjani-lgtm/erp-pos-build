# Gate t5a-gate-4 — Treasury Phase ⑤a Wave 4 frontend conventions review

You are reviewing `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5` on branch `feat/treasury-phase5` at HEAD.

## Reviewer persona (controlling)

Act as the **frontend-conventions-reviewer** for AutoERP (`apps/web`, React 19, strict TypeScript, Tailwind 4). Be adversarial and code-grounded. Cite `file:line` for every finding. Rank findings BLOCKER / MAJOR / MINOR. A BLOCKER or MAJOR means REJECT. Verify rather than trusting the request. You gate; you never merge or push.

Canonical requirements:

- Use canonical atoms/molecules (`Input`, `Select`, `Button`, `FormField`, `StatusBadge`, existing `BankPicker`); no parallel picker or raw control where an atom exists.
- Use react-hook-form with real inline validation. User-visible strings and validation messages are translated.
- Use only design tokens; never dynamically compose Tailwind variant/opacity strings.
- Every tenant-data query key uses `tenantScopedKey`; mutations invalidate the correct tenant-scoped namespaces.
- Money/quantity remain strings; no `parseFloat`, `Number`, float coercion, or uncontrolled numeric payload.
- EN/FR/AR remain in phase and valid. RTL must not rely on directional visual hacks.

## Authority and diff

Read:

1. `.claude/agents/frontend-conventions-reviewer.md`
2. `docs/handoff/CODEX-treasury-phase5-2026-07-18.md`
3. `docs/superpowers/plans/2026-07-18-treasury-phase5a-outbound-instruments.md`, Task 10 and Gate 4
4. the Rev 2 Treasury spec §4 and §9
5. `CLAUDE.md`, especially rules 4, 5, 13, 19, 20

Review:

```bash
git diff t5a-gate-3..HEAD -- \
  apps/web/src/features/expenses/components/PayExpenseDialog.tsx \
  apps/web/src/features/expenses/components/PayExpenseDialog.test.tsx \
  apps/web/src/features/expenses/hooks/useExpenses.ts \
  apps/web/src/features/expenses/hooks/usePayExpense.test.tsx \
  apps/web/src/features/expenses/types/index.ts \
  apps/web/src/features/treasury/InstrumentListPage.tsx \
  apps/web/src/features/treasury/PaymentMethodsPage.tsx \
  apps/web/src/features/treasury/__tests__/InstrumentListPage.filters.test.tsx \
  apps/web/src/features/treasury/hooks/usePaymentMethods.ts \
  apps/web/src/locales/ar/expenses.json \
  apps/web/src/locales/ar/treasury.json \
  apps/web/src/locales/en/expenses.json \
  apps/web/src/locales/en/treasury.json \
  apps/web/src/locales/fr/expenses.json \
  apps/web/src/locales/fr/treasury.json \
  packages/shared/types/generated.d.ts
```

Gate focus:

1. Confirm cash mode preserves its exact legacy request contract, and instrument mode sends exactly the backend discriminated contract with strings/nulls and no amount coercion.
2. Confirm the mode/kind changes cannot leave a stale cash repository or incompatible method selected; instrument repositories are bank-only and methods match cheque/effet.
3. Confirm effet maturity and all required instrument fields have accessible, translated inline errors; cheque does not send a phantom maturity date.
4. Confirm `BankPicker` is reused correctly and accessible; inspect whether fallback-bank UI creates a misleading value that is silently discarded.
5. Confirm the schedule renders two accessible direction groups, counts rows by direction/bucket, shows directional totals, and uses tokens rather than raw colors.
6. Confirm query keys/invalidation are tenant scoped and do not introduce a cross-company cache collision.
7. Confirm EN/FR/AR completeness and no hardcoded user-facing text.
8. Re-run guardrails and targeted tests; inspect tests for weak queries or false-green mocks.

Fresh evidence:

- Focused Vitest: 10/10 across pay dialog, mutation invalidation, and maturity grouping. Broader touched-feature run: all changed-feature tests passed; one unrelated existing `TreasuryTenantScope` provider-fixture failure originates in unchanged `AddRepositoryModal` coverage.
- Full `pnpm --filter @autoerp/web lint`: exit 0, 0 errors, 0 new query-key/design-system baseline entries; custom rule tests pass.
- `pnpm --filter @autoerp/web typecheck`: pass.
- Changed-file ESLint: 0 errors. `git diff --check`: pass.
- React Doctor branch scan reports existing repository diagnostics; the only Task 10 warning is the pre-existing reset-on-open dialog pattern, with no new critical Task 10 finding.

Use the default Vitest pool; never `--singleFork`. Do not change files. Do not accept a baseline update that absorbs new debt.

## Required output

First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`.

Then findings ordered by severity with `file:line` evidence. End with `VERDICT: APPROVE` or `VERDICT: REJECT` and one line stating what must be fixed before the ⑤a exit review.
