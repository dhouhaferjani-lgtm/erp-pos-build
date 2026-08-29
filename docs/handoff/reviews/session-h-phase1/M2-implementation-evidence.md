# Session H Phase 1 — M2 implementation and TDD evidence

Date: 2026-08-29

Lane: `h1-cleanup`

Accepted M1 base: `ed9f69551`

Initial M2 commits: `a861f7dbe`, `0fb6f5b27`

Adversarial correction authority: `docs/handoff/reviews/session-h-phase1/M2-round1.md`

This artifact records the implementation-side command evidence that is otherwise not visible in a commit diff. It does not replace the controller-owned adversarial verdict or progress YAML.

## Initial M2 RED → GREEN record

All commands ran from `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/h1-cleanup`.

1. Customer create refuses a missing Nature without a request.
   - RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "refuses customer creation without Nature"`
   - Observed failure: the form submitted/navigated and no `Nature is required` error existed.
   - GREEN: the same command passed after the create-only Zod/RHF refinement was added.

2. Supplier create defaults to Company.
   - RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "defaults supplier creation Nature to Company"`
   - Observed failure: expected `business`; the select contained the empty option.
   - GREEN: the same command passed after the supplier-create default was added.

3. A legacy null-category partner with VAT remains B2B-visible.
   - RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "shows B2B fields for a legacy null Nature with a VAT number"`
   - Observed failure: `B2B Information` was absent.
   - GREEN: the same command passed after preserving the server `null` and adding the four-signal heuristic.

4. A legacy null walk-in remains B2B-hidden.
   - RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "hides B2B fields for a legacy null Nature with no B2B data"`
   - Observed failure against the intermediate null handling: `B2B Information` remained present.
   - GREEN: the same command passed after limiting the null branch to non-empty VAT/legal-name/registration/credit-limit signals.

5. An over-limit partner renders the existing warning.
   - RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "renders the credit-limit warning when the partner is over the limit"`
   - Observed failure: no element with role `alert` was rendered.
   - GREEN: the same command passed after `CreditLimitWarning` was mounted below the credit limit control.

6. Exact partner-detail invalidation stays tenant/company scoped.
   - RED: `pnpm --filter @autoerp/web exec vitest run src/features/partners/__tests__/tenantScope.test.tsx -t "matches one tenant-scoped partner detail"`
   - Observed failure: `partnerDetailInvalidationPredicate is not a function`.
   - GREEN: the same command passed after the exact tenant/company predicate was added; `pnpm --filter @autoerp/web audit:keys` also passed with zero new/stale entries.

Initial consolidated GREEN evidence:

- `pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx` — 16/16 passed.
- `pnpm --filter @autoerp/web exec vitest run src/features/partners` — 11 files, 114/114 passed.
- `pnpm --filter @autoerp/web exec playwright test e2e/session-h/m1-data-shape-drift.spec.ts e2e/session-h/m2-partner-nature.spec.ts --workers=1` — 6/6 passed.

## M2 adversarial correction round 1 RED evidence

### Decimal-safe credit warning, TND boundaries, and Arabic copy

Command:

`pnpm --filter @autoerp/web exec vitest run src/features/partners/components/CreditLimitWarning.test.tsx`

Pre-fix result: **4 failed / 4**.

- Equal-to-limit expected `1 000,000 TND`; received `1,000.00` with no currency.
- One millime over expected `1 000,001 TND`; received `1,000.00` with no currency.
- Three-decimal approaching case expected `800,400 TND / 1 000,500 TND`; received `800.40 / 1,000.50`.
- Arabic warning expected `تم تجاوز حد الائتمان`; received the English fallback `Credit limit exceeded`.

Post-fix GREEN: the same command passed **4/4** after replacing `parseFloat`, JS-number comparison/division, and `Math.round` with `bccomp`/`bcdiv`/`bcmul`, formatting both amounts with the shared currency-aware `formatCurrency`, and adding AR warning keys.

### Supplier edit default isolation

Command:

`pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "does not default Nature to Company on a supplier edit"`

Pre-fix result: **failed** — expected blank, received `business` after a failed edit-detail request.

Post-fix result: **passed** after restricting the default to `!isEditing && isSupplierContext`.

### Nature-specific blank copy in EN/FR/AR

Command:

`pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "uses the Nature-specific blank option"`

Pre-fix result: **3 failed / 3** — the form exposed `Select category` / `Sélectionner une catégorie`, and Arabic fell back to English.

Post-fix result: **3 passed / 3** with `Select nature`, `Sélectionner la nature`, and `اختر طبيعة الشريك`.

### Unambiguous Arabic Nature validation

Command:

`pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "uses an unambiguous Arabic validation message for Nature"`

Pre-fix result: **failed** — the rendered error was the same `النوع مطلوب` used by Type.

Post-fix result: **passed** with the owner-prescribed label `النوع` retained and the distinct error `طبيعة الشريك مطلوبة`.

### UI blank preserves the legacy-null B2B heuristic

Command:

`pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "keeps legacy B2B fields visible when the UI blank option represents null Nature"`

Pre-fix result: **failed** — changing the loaded null select to its DOM blank value removed `B2B Information`.

Post-fix result: **passed** after treating `''` as the UI representation of legacy null for the four-signal visibility branch.

### Canonical net exposure

The final assertion was mutation-checked against the exact regression. With the production line temporarily restored to gross `receivable_balance`, this command was run:

`pnpm --filter @autoerp/web exec vitest run src/features/partners/PartnerForm.test.tsx -t "uses net customer exposure instead of gross receivables"`

Mutation RED: **failed** — expected `Approaching credit limit`; received `Credit limit exceeded` with gross `125,00 EUR / 100,00 EUR (125%)`.

Restored GREEN: **passed** after restoring `getNetBalance(partner, isCustomerContext)`, which uses the canonical `95.000` net exposure for the fixture.

## Round 1 browser evidence

Command:

`pnpm --filter @autoerp/web exec playwright test e2e/session-h/m2-partner-nature.spec.ts --workers=1`

Result: **3/3 passed in 31.1s** against worktree API `:8011` and Vite `:5174`.

- The create case captures the exact created response ID/name and deletes only that partner in `afterEach` through authenticated `DELETE /partners/{id}`; the cleanup assertion received `204`.
- The regenerated form-level screenshots visibly show the relevant branch transition:
  - `.playwright-mcp/session-h/m2/m2-legacy-company-visible.png` — 976×1739; `B2B Information` visible immediately after General Information.
  - `.playwright-mcp/session-h/m2/m2-legacy-walk-in-hidden.png` — 976×1170; Address follows General Information with no B2B section.

## Scope statement

The correction remains FE-only and shape-neutral. It changes no migration, database column, backend request/DTO, server enum/value, sealed payload, or canonical fiscal bytes. The generated `PartnerData` contract remains authoritative.

## Round 1 consolidated verification

- `pnpm --filter @autoerp/web exec vitest run src/features/partners` — **12 files, 125/125 passed**. Existing unrelated React `act(...)` and unmatched-route diagnostics remain non-failing.
- `pnpm --filter @autoerp/web typecheck` — **exit 0**.
- `pnpm --filter @autoerp/web lint` — **exit 0**; 0 errors / 6464 repository-baseline warnings, all audits green, and tool tests **160/160 passed**. The two `CreditLimitWarning` `precision/no-parsefloat-on-money` warnings identified by the review are gone.
- `npx react-doctor@latest --verbose --scope changed --base ed9f69551` — **91/100, no issues found**, 11 changed source files scanned.
- `cd apps/api && php tools/feature-lane-manifest-check.php` — **exit 0**, with the standing parked-lane/coverage-debt notices only.
- `git diff --check` — **exit 0**.
- `pgrep -af '[v]itest'` — no stray Vitest workers.
