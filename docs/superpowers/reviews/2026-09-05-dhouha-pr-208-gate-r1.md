# Gate r1 — PR #208 "fix(finance): format General Ledger amounts with tenant currency (DEV-QA-043)"

| Field | Value |
|---|---|
| PR | #208 · `dhouhaferjani-lgtm` · head `fix/gl-currency-format` · base `dev` |
| PR head sha | `50cb08d6f11aa31b4c9c05bc18519657ae105bfe` |
| Merged (gate) sha | `0c3f5c3d151e7ece973a166e8b4283483578f8b8` (merge of local dev `d56d62535` + PR) |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-208` |
| Reviewer | Fable adversarial merge gate, 2026-09-05 |
| Files | `apps/web/src/features/finance/components/LedgerTable.tsx`, `.../pages/GeneralLedgerPage.tsx`, `.../pages/GeneralLedgerPage.test.tsx` |

## Verdict: **CHANGES**

The core defect is real and the fix is the right one: the GL was the only finance
surface with a hardcoded `$`, and it now goes through the same
`formatReportCurrency(amount, company)` path as every sibling report. Merge is
blocked on a small fix round: the retained zero-guard is provably dead against
the real API contract (the backend emits **scale-4** strings), so the PR body's
behaviour claim is false and the test fixtures do not match the wire format —
which is the same FE/BE contract-drift class of bug this PR exists to fix.

---

## Verified facts (with citations)

**Currency source is the company context, not hardcoded.**
`GeneralLedgerPage.tsx:16` `const { currentCompany } = useCompany()` →
`GeneralLedgerPage.tsx:53-57` threads it into `LedgerTable`. `useCompany`
(`src/hooks/useCompany.ts:9-32`) subscribes via `useCompanyStore` selectors
(`currentCompanyId`, `companies`), so it **is reactive on company switch** — it
does not use the non-reactive `getState()` fallback that `src/lib/format.ts:20-49`
warns about. This matches the sibling pattern exactly
(`TrialBalancePage.tsx:12,22,32`).

**No float touches money.** `formatReportCurrency`
(`src/features/finance/pages/reportPageUtils.ts:27-37`) → `formatCurrency`
(`src/lib/format.ts:118-137`) → `formatDecimalAmount` (`src/lib/format.ts:81-95`)
which uses `Big.js` (`safeDecimal`, `src/lib/format.ts:60-67`). No `parseFloat`,
no `Number(`, no `toFixed` on a JS number anywhere on the touched lines. Rule 19
satisfied.

**Scale is currency-derived, not hardcoded.** `getDecimals('TND') = 3`
(`src/lib/currencyMeta.ts:2`), `getLocale('TND') = 'fr-TN'`
(`src/lib/currencyMeta.ts:18`). Verified the rendered form empirically:
`Intl.NumberFormat('fr-TN')` decimal separator = `","`, currency code trails the
number → `formatCurrency('100.00', {currency:'TND', locale:'fr-TN'})` = `"100,000 TND"`.

**Column coverage.** Debit (`LedgerTable.tsx:57`), credit (`LedgerTable.tsx:64`)
and balance (`LedgerTable.tsx:71`) are all covered. There is **no footer/total
row and no CSV/export path** to cover: `LedgerData.total_debits` /
`closing_balance` exist in `src/features/finance/api.ts:91-100` but are never
rendered (grep over `src/features/finance/` shows uses only in fixtures), and
`handleExport` is an empty stub (`GeneralLedgerPage.tsx:21-23`). Grep for
remaining hardcoded `` `$${ `` in `src/features/finance/` → **zero hits**. The
`$`-prefix bug is fully eradicated in this feature.

**Backend already returns strings.**
`GeneralLedgerReportService.php:494-495` → `CurrencyScale::bcformat($line->debit, 4)`
and `balance` is a `bcadd/bcsub` result at `DECIMAL_SCALE = 4`
(`GeneralLedgerReportService.php:61,480-482,497`). `LedgerData` documents
`"debit": "500.0000"` / `"credit": "0.0000"`
(`apps/api/app/Modules/Accounting/Application/DTOs/Reports/LedgerData.php:56-59`).
No transformation happens in between: `getLedger` returns `apiGet<LedgerData>(url)`
directly (`src/features/finance/api.ts:128`, single unwrap — convention 01 OK) and
`useLedger` passes it through with a `tenantScopedKey(['ledger', filters])` key
(`src/features/finance/hooks/useLedger.ts:13` — convention 05 OK).

**Design tokens.** Touched lines keep `textColors.primary` / `textColors.tertiary`
(`LedgerTable.tsx:3,43,70`). No new literal colour classes. Design-system audit:
810 acknowledged / **0 new**, baseline file untouched by this diff.

---

## Findings

### 1. MAJOR — the retained zero-guard is dead code against the real payload; the PR body's "zero renders blank" claim is false
`apps/web/src/features/finance/components/LedgerTable.tsx:18-21`
```ts
const formatAmount = (value: string): string => {
  if (value === '0.00' || value === '0') return ''
  return formatReportCurrency(value, company)
}
```
The API emits **4-decimal** strings (`GeneralLedgerReportService.php:494-495`,
`LedgerData.php:56-59`): a zero credit arrives as `"0.0000"`, which matches
neither `'0.00'` nor `'0'`. So in production every zero debit/credit cell renders
`0,000 TND` (verified: `Big('0.0000').toFixed(3)` = `"0.000"` → `"0,000 TND"` at
`fr-TN`), not blank. The PR body states "Zero debit/credit still render blank" —
that is not true against the live contract. This is **not a regression** (the old
code rendered `$0.0000`), but it is an unexamined claim on a code path this PR
owns, and it fills a two-sided ledger with competing `0,000 TND` noise (owner
"one main element per screen" / signal-overload).
**Fix:** compare numerically, e.g. `if (new Big(safe(value)).eq(0)) return ''`
(or reuse an existing `isZeroAmount` helper), and correct the PR body claim.

### 2. MAJOR — test fixtures use a money scale the API never emits, so the suite cannot catch finding 1
`apps/web/src/features/finance/pages/GeneralLedgerPage.test.tsx:44-46`
(`debit: '100.00'`, `credit: '0.00'`, `balance: '250.00'`) and the shared factory
`apps/web/src/features/finance/__fixtures__/generalLedger.ts:24-26` (`'1000.00'`,
`'0.00'`, `'1000.00'`). The backend emits `'100.0000'` / `'0.0000'` / `'250.0000'`.
The PR's whole reason for existing is an FE/BE presentation-contract mismatch;
shipping fixtures that disagree with the wire format leaves the next mismatch
invisible. (The *formatted* output happens to be identical for the non-zero case,
so the currency assertion still holds — but the zero case silently diverges.)
**Fix:** move the fixtures to 4-decimal strings and add a zero-credit row that
asserts the intended rendering.

### 3. MINOR — the currency assertion is partly tautological and does not prove the scale is not hardcoded
`apps/web/src/features/finance/pages/GeneralLedgerPage.test.tsx:83-88` computes the
expectation with the same `formatCurrency` the component uses, so it proves
*wiring* but not *scale*. The genuine falsifier is
`expect(container.textContent).not.toContain('$')` (line 92) — that one is red
without the fix, so the test IS falsifiable. Still, there is **no non-TND case**,
so a hypothetical hardcoded `3` would pass.
**Fix:** add a second case with `currency: 'EUR'` asserting a 2-decimal render
(e.g. `100,00 EUR`), proving scale follows the currency.

### 4. MINOR — dead "Export" control shipped to users on a touched file (owner OQ-11)
`apps/web/src/features/finance/pages/GeneralLedgerPage.tsx:21-23` (`handleExport`
is `{ /* Export functionality to be implemented */ }`) wired to a visible enabled
button at `:40-43`. Owner ruling OQ-11: controls whose backend does not exist are
**hidden**, not shipped inert. Pre-existing, not introduced here, but the file is
in the diff.
**Fix (follow-up lane, not this PR):** hide the button until a GL export endpoint exists.

### 5. MINOR — hand-rolled `LedgerLine` shadows a generated DTO (rule 7 / one-surface-per-concept)
`apps/web/src/features/finance/types.ts:147-159` vs generated
`packages/shared/types/generated.d.ts:151-164` (`LedgerLineData`). The FE copy also
**drops `partner_name`** which the DTO carries. The drift is knowingly documented
(`types.ts:1-30`, "Task 2.3 DRIFT AUDIT ONLY", blocked by a generated-types
ambient-resolution plumbing issue), so this is acknowledged debt, not new debt
from this PR. Recorded so the gate does not silently ratify it.

### 6. MINOR — two competing test files for the same page
`apps/web/src/features/finance/GeneralLedgerPage.test.tsx` (6 tests, mocks
`@/lib/api` + `renderWithProviders`) and
`apps/web/src/features/finance/pages/GeneralLedgerPage.test.tsx` (3 tests, mocks
hooks). Both pass. The twin does **not** mock `useCompany`, so it renders the
`'EUR'` fallback path (`reportPageUtils.ts:34`) — harmless today, but two
divergent suites for one page is a duplicate surface.
**Fix (follow-up):** collapse to one.

---

## Guardrail evidence (verbatim, re-run by the reviewer in the merged worktree)

`cd .worktrees/pr-208/apps/web && ./node_modules/.bin/vitest run src/features/finance/pages/GeneralLedgerPage.test.tsx src/features/finance/GeneralLedgerPage.test.tsx` (tail):
```
 ✓ src/features/finance/GeneralLedgerPage.test.tsx (6 tests) 413ms

 Test Files  2 passed (2)
      Tests  9 passed (9)
   Start at  14:45:34
   Duration  3.14s (transform 826ms, setup 1.68s, collect 1.44s, tests 564ms, environment 1.23s, prepare 163ms)
```

`./node_modules/.bin/eslint src/features/finance/components/LedgerTable.tsx src/features/finance/pages/GeneralLedgerPage.tsx src/features/finance/pages/GeneralLedgerPage.test.tsx`:
```
/…/src/features/finance/pages/GeneralLedgerPage.tsx
  29:17  warning  Promise-returning function provided to attribute where a void return was expected  @typescript-eslint/no-misused-promises

✖ 1 problem (0 errors, 1 warning)
```
(0 errors. The single warning is on `QueryError onRetry={refetch}` at line 29 — an untouched pre-existing line.)

`./node_modules/.bin/tsc --noEmit`:
```
TSC EXIT: 0
```
(no output)

Audit tools (`node tools/audit-tanstack-keys.mjs`, `audit-design-system.mjs`, `audit-quantity-display.mjs`):
```
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```
Baseline honesty: **no baseline file appears in the diff** (`git diff dev HEAD --stat` = 3 files, all under `features/finance/`). No `--write-baseline` absorption. No detector-evading indirection (no alias table, no suppression comment, no renamed-equivalent literal).

Merge cleanliness: `git diff-tree --cc 0c3f5c3d1` is **empty** → no conflict
resolution was needed. `git log 4d5b8812e..d56d62535 -- <the 2 source files>` is
empty → the dev-side eslint-autofix commit `5524b9a69` did **not** touch
`LedgerTable.tsx` or `GeneralLedgerPage.tsx`; the merge is semantically clean.

Commit hygiene: single commit, conventional prefix, accurate subject, co-author
trailer present. Scope creep: none — 3 files, all in scope.

## Could not verify
- **The DEV-QA registry is not in this repo.** `grep -rl 'DEV-QA-043'` across the
  whole checkout returns nothing, so the ticket's original wording, priority and
  reporter could not be read. DEV-QA-043 is taken at face value from the PR body.
- No live API payload was captured; the scale-4 conclusion (findings 1-2) is read
  from `GeneralLedgerReportService.php:494-495` + `DECIMAL_SCALE = 4` (line 61) +
  the `LedgerData` docblock, not from an HTTP response.
- **Not manually recette'd in a browser** (the author says the same). No TND-tenant
  screenshot of the GL exists.
- The PR body's "full finance suite green: 25 files / 135 tests" was **not**
  re-run (instructed to run by file only); only the 2 GL test files were verified.
- No RTL/Arabic rendering check of the currency string.

## Merge to local dev: **NO** — pending fix round on findings 1-3.
