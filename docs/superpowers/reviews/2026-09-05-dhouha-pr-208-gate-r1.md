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

---
---

# Gate r2 — PR #208 fix round 1

| Field | Value |
|---|---|
| Re-gated sha | `e2cc7146c6026658c63186e5607a2381297f38b9` (branch `gate/pr-208`) |
| Fix commit | `0f9292bf1` `fix(finance): decimal-zero guard + scale-4 GL fixtures (PR #208 gate r1)` |
| Handback | `docs/superpowers/reviews/2026-09-05-dhouha-pr-208-fix-round-1-handback.md` (in-worktree) |
| Delta vs r1 gate sha `0c3f5c3d1` | 3 code/test files + 1 doc; `LedgerTable.tsx` +13/-3, `GeneralLedgerPage.test.tsx` +106/-20, `__fixtures__/generalLedger.ts` +10/-7 |
| Reviewer | Fable adversarial merge gate, 2026-09-05 |

## Verdict: **MERGE**

All three blocking findings are closed, each against the primary source rather
than against the gate report's summary of it. No new defect found; no scope
creep; no baseline touched.

---

## Finding-by-finding re-check

### r1 finding 1 (MAJOR, dead zero-guard) — **CLOSED**
`apps/web/src/features/finance/components/LedgerTable.tsx:30-32`
```ts
const formatAmount = (value: string): string => {
  if (bccomp(value, '0') === 0) return ''
  return formatReportCurrency(value, company)
}
```
`bccomp` (`apps/web/src/lib/decimal.ts:115-117`) is `safeBig(a).cmp(safeBig(b))` on
**big.js** — no `parseFloat`, no `Number(`, no `toFixed` on a JS number anywhere
on the path. Rule 19 holds.

Input-safety re-checked at the real choke point, `safeBig`
(`apps/web/src/lib/decimal.ts:30-37`):
- `"0.0000"` → `Big(0)` → `cmp` 0 → **blank**. ✔
- `"-0.0000"` → big.js normalises the sign of zero → `cmp` 0 → **blank**. ✔
- `""` / whitespace-only → guarded by `if (!value || value.trim() === '')` → `Big(0)` → blank. ✔
- `undefined`/`null` (not reachable through `LedgerLine.debit: string`, `types.ts:154-156`, but the guard is falsy-safe) → `Big(0)` → blank, no throw. ✔
- Unparseable (`"abc"`) → `try/catch` → `Big(0)` → blank. Behaviour change from the old code (which would have rendered `$abc`), and it is the safer direction; see r2 finding A below.

The block comment at `LedgerTable.tsx:19-29` records *why* the comparison must be
numeric and cites the backend scale — the next reader cannot re-introduce a string
compare by accident.

### r1 finding 2 (MAJOR, fixture scale) — **CLOSED**
Both fixture sources moved to the wire format:
- `apps/web/src/features/finance/pages/GeneralLedgerPage.test.tsx:64-66` — `debit: '100.0000'`, `credit: '0.0000'`, `balance: '250.0000'`, with a docblock at `:46-56` citing `GeneralLedgerReportService.php` (`DECIMAL_SCALE = 4`, `CurrencyScale::bcformat($v, 4)`) and the `LedgerData.php` example payload.
- `apps/web/src/features/finance/__fixtures__/generalLedger.ts:24-29,55-58` — `makeLedgerLine` and `makeLedgerReport` both at scale 4, comment citing the same source.

Consumer sweep: `grep -rl 'makeLedgerLine\|makeLedgerReport'` → only
`src/features/finance/GeneralLedgerPage.test.tsx` and the fixture file itself. The
twin suite still passes (6/6, below), so the scale change caused no collateral.

### r1 finding 3 (MINOR, tautology / no non-TND case) — **CLOSED**
- The tautology is gone: `GeneralLedgerPage.test.tsx:118` now asserts the **literal** `'100,000 TND'`, and `:127-129` separately cross-checks that the shared formatter agrees with that literal (an equivalence check, not the assertion itself).
- A real EUR case exists at `:161-174`: `companyRef.current = { currency: 'EUR', locale: 'fr_FR' }` → asserts `'100,00 EUR'` **and** `not.toContain('100,000 EUR')`, which is exactly the "scale is not hardcoded to TND's 3" falsifier that was missing in r1.
- The `useCompany` mock is switchable per test via `vi.hoisted` (`:15-24`) with a `beforeEach` reset to TND (`:99`), so tests cannot leak tenant state into each other.

I independently reproduced the expected renders through `Intl`: `fr-TN` decimal
separator `","`, currency code trailing → `100,000 TND`; `fr-FR` + `getDecimals('EUR') = 2`
(`currencyMeta.ts:4`) → `100,00 EUR`. Both literals in the tests are correct.

### Falsifiability (reasoned from the tests, then cross-checked against the handback)
Reverting only the guard to `value === '0.00' || value === '0'`:
- `:135` "blanks a zero credit…" → credit cell would be `'0,000 TND'`, expected `''` → **RED**
- `:150` "renders a non-zero credit…" → debit cell `'0,000 TND'`, expected `''` → **RED**
- `:161` "derives the scale from the tenant currency…" → credit cell `'0,00 EUR'`, expected `''` → **RED**
- `:110` "right-aligns a money cell", `:107` "renders exactly one h1", the QueryError case → green.
**3 of 6 red** — which is exactly the run pasted in the handback (§ Finding 1,
including the three `expected '0,000 TND' to be ''` messages). The claim is
independently reproducible by reasoning and I accept it.

### r1 findings 4, 5, 6 — deliberately left, correctly disclosed
The handback lists 4 (dead Export button, OQ-11), 5 (hand-rolled `LedgerLine` vs
generated `LedgerLineData`) and 6 (twin test files) as follow-ups. All three were
MINOR/pre-existing in r1 and none was mislabelled as fixed. Accepted.

---

## New findings in r2

### A. MINOR (informational, not blocking) — malformed money now blanks silently
`LedgerTable.tsx:31` via `safeBig` (`decimal.ts:30-37`): a value big.js cannot parse
degrades to `0` and therefore renders an **empty cell**, indistinguishable from a
legitimate zero. Given `LedgerLine.debit/credit` are typed `string` and the backend
formats through `CurrencyScale::bcformat`, this is unreachable in practice, and
silent-blank is safer than rendering garbage. Recorded, not actioned.

### B. MINOR (informational) — the balance column is deliberately not zero-blanked
`LedgerTable.tsx:81` still calls `formatReportCurrency(line.balance, company)`
unconditionally, so a zero running balance renders `0,000 TND`. That is the correct
and pre-existing behaviour (a ledger's running balance must always show), and it is
consistent with the r1 report. No change wanted.

---

## Guardrail evidence (verbatim, re-run by the reviewer at `e2cc7146c`)

`./node_modules/.bin/vitest run src/features/finance/pages/GeneralLedgerPage.test.tsx src/features/finance/GeneralLedgerPage.test.tsx`:
```
 ✓ src/features/finance/pages/GeneralLedgerPage.test.tsx (6 tests) 118ms
 ✓ src/features/finance/GeneralLedgerPage.test.tsx (6 tests) 219ms
 Test Files  2 passed (2)
      Tests  12 passed (12)
   Duration  1.86s (transform 451ms, setup 829ms, collect 913ms, tests 337ms, environment 833ms, prepare 147ms)
```
(9 → 12 tests; the twin suite is unaffected by the scale-4 fixture change.)

`./node_modules/.bin/eslint src/features/finance/components/LedgerTable.tsx src/features/finance/pages/GeneralLedgerPage.tsx src/features/finance/pages/GeneralLedgerPage.test.tsx src/features/finance/__fixtures__/generalLedger.ts`:
```
/…/src/features/finance/pages/GeneralLedgerPage.tsx
  29:17  warning  Promise-returning function provided to attribute where a void return was expected  @typescript-eslint/no-misused-promises

✖ 1 problem (0 errors, 1 warning)
```
**0 errors.** Same single pre-existing warning as r1 (untouched line 29); the fix
round added no new warning and the newly-touched fixture file is clean.

`./node_modules/.bin/tsc --noEmit`:
```
TSC EXIT: 0
```
(no output)

Audits:
```
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```
Baseline honesty: `git diff dev HEAD --stat -- apps/web/tools/` is **empty** — no
baseline file exists in the diff at any point in this branch. Mechanism audit: the
improvement is a real behavioural change (string compare → big.js compare) plus
fixtures moved *toward* the wire format; no alias table, no suppression comment, no
renamed-equivalent literal, nothing that could defeat a detector.

Scope: 3 code/test files + 1 in-worktree handback doc. No production file outside
`features/finance/` touched. Commit is conventional and its subject matches its content.

## Could not verify (unchanged from r1)
- The **DEV-QA registry is still not in this repo** (`grep -rl 'DEV-QA-043'` → nothing).
- No live API payload captured; the scale-4 premise remains a code-read of
  `GeneralLedgerReportService.php:61,494-497` + `LedgerData.php`, now cited in-code.
- Still **not manually recette'd in a browser** on a TND tenant.
- The falsification run in the handback was **reproduced by reasoning**, not by
  re-running a reverted tree (read-only review; no code modified).

## Merge to local dev: **YES**
