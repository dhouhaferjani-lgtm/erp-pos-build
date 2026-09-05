# Fix round 1 handback — PR #208 "fix(finance): format General Ledger amounts with tenant currency (DEV-QA-043)"

| Field | Value |
|---|---|
| Gate report answered | `docs/superpowers/reviews/2026-09-05-dhouha-pr-208-gate-r1.md` (verdict **CHANGES**) |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-208` |
| Branch | `gate/pr-208` (merge base `d56d62535` + PR merge `0c3f5c3d1`) |
| Fix commit | `0f9292bf1` — `fix(finance): decimal-zero guard + scale-4 GL fixtures (PR #208 gate r1)` |
| Author of fix round | Claude Opus 5, 2026-09-05 |
| Findings addressed | 1 (MAJOR), 2 (MAJOR), 3 (MINOR) |
| Findings deliberately left | 4, 5, 6 (documented follow-ups below) |
| Merge decision | **not merged** — handed back for re-gate |

---

## Finding 1 (MAJOR) — dead zero-guard against the real scale-4 payload

**Change.** `apps/web/src/features/finance/components/LedgerTable.tsx:18-32` —
the string compare is replaced by the repo's big.js-backed decimal comparison:

```ts
// before
if (value === '0.00' || value === '0') return ''
// after
if (bccomp(value, '0') === 0) return ''
```

`bccomp` is imported from `apps/web/src/lib/decimal.ts:115` (`safeBig(a).cmp(safeBig(b))`),
which is the pattern already used for decimal-zero tests across the app
(`CreateStockAdjustmentPage.tsx:261`, `SupplierInvoiceCreatePage.tsx:285`,
`GoodsReceiptListPage.tsx:152`, …). No `parseFloat`, no `Number(...)`, no float
touches the money string — CLAUDE.md rule 19 holds. A block comment on the
helper records why the comparison must be numeric.

**Proof the gate's premise is real (re-verified against the backend, not taken on trust).**
- `apps/api/app/Modules/Accounting/Application/Services/Reports/GeneralLedgerReportService.php:61`
  `private const DECIMAL_SCALE = 4;`
- same file, the line assembly: `'debit' => CurrencyScale::bcformat($line->debit, 4)`,
  `'credit' => CurrencyScale::bcformat($line->credit, 4)`, and `balance` is the
  running `bcadd(...)/bcsub(..., self::DECIMAL_SCALE)` result.
- `apps/api/app/Modules/Accounting/Application/DTOs/Reports/LedgerData.php` example
  payload: `"debit": "500.0000"`, `"credit": "0.0000"`, `"balance": "1500.0000"`.

So a zero credit arrives as `"0.0000"`, matched neither `'0.00'` nor `'0'`, and
rendered `0,000 TND`. Confirmed empirically by the falsification run below.

**Falsifiability.** Reverting *only* this line (keeping the new tests) turns
3 of the 6 tests in `pages/GeneralLedgerPage.test.tsx` red:

```
   ✓ GeneralLedgerPage > renders exactly one h1 104ms
   ✓ GeneralLedgerPage > right-aligns a money cell with tabular-nums 8ms
   × GeneralLedgerPage > blanks a zero credit and formats a non-zero debit on the SAME scale-4 row 8ms
     → expected '0,000 TND' to be '' // Object.is equality
   × GeneralLedgerPage > renders a non-zero credit (the blank rule only fires on zero) 5ms
     → expected '0,000 TND' to be '' // Object.is equality
   × GeneralLedgerPage > derives the scale from the tenant currency (EUR tenant renders 2 decimals) 7ms
     → expected '0,00 EUR' to be '' // Object.is equality
   ✓ GeneralLedgerPage > renders the error state through QueryError when the query fails 9ms
      Tests  3 failed | 3 passed (6)
```

**PR body correction owed to the author:** the claim "Zero debit/credit still
render blank" was false against the live contract before this round; it is true now.

---

## Finding 2 (MAJOR) — fixtures at a scale the API never emits

**Change.**
- `apps/web/src/features/finance/pages/GeneralLedgerPage.test.tsx:57-72` — `makeLine()`
  now returns `debit: '100.0000'`, `credit: '0.0000'`, `balance: '250.0000'`, with a
  docblock citing `GeneralLedgerReportService.php` (`DECIMAL_SCALE = 4`,
  `CurrencyScale::bcformat($v, 4)`) and the `LedgerData.php` example payload.
- `apps/web/src/features/finance/__fixtures__/generalLedger.ts:24-29` — `makeLedgerLine()`
  now returns `'1000.0000' / '0.0000' / '1000.0000'`; `makeLedgerReport()` (`:52-57`)
  now returns `opening_balance '0.0000'`, `closing_balance '1000.0000'`,
  `total_debits '1000.0000'`, `total_credits '0.0000'`.

**New behavioural tests** (`pages/GeneralLedgerPage.test.tsx`):
- `blanks a zero credit and formats a non-zero debit on the SAME scale-4 row` —
  asserts the credit cell's `textContent` is `''` on `"0.0000"`, the debit cell is
  `'100,000 TND'` on `"100.0000"`, and no `0,000 TND` node exists.
- `renders a non-zero credit (the blank rule only fires on zero)` — the mirror case,
  so the guard cannot be "fixed" by blanking the whole column.

Cells are addressed positionally from the end of the row (`balance` last, `credit`
second-to-last), which survives a leading selection column if `DataTable` ever adds one.

---

## Finding 3 (MINOR) — tautological currency assertion, no non-TND case

**Change.** `apps/web/src/features/finance/pages/GeneralLedgerPage.test.tsx:15-28` —
`useCompany` is now mocked through a `vi.hoisted` ref so a test can switch tenant
currency; `beforeEach` resets it to TND.

- The TND assertions use **expected literals** (`'100,000 TND'`) rather than
  recomputing with the component's own formatter; one line still cross-checks that
  `formatCurrency('100.0000', {currency:'TND', locale:'fr-TN'})` equals that literal,
  so a formatter change is caught rather than silently absorbed.
- New test `derives the scale from the tenant currency (EUR tenant renders 2 decimals)`
  sets `{ currency: 'EUR', locale: 'fr_FR' }` and asserts the debit cell is exactly
  `'100,00 EUR'`, plus `not.toContain('100,000 EUR')` (a hardcoded TND scale of 3)
  and `not.toContain('$')`.
- Literals verified against the actual `Intl` behaviour on this machine before being
  written: `fr-TN`/`fr-FR` decimal separator `","`, currency code trails the number
  for both, `getDecimals('TND') = 3` / `getDecimals('EUR') = 2`
  (`apps/web/src/lib/currencyMeta.ts:2-3`).

---

## Verbatim guardrail evidence (this worktree, after the fix commit)

`cd .worktrees/pr-208/apps/web && ./node_modules/.bin/vitest run src/features/finance/pages/GeneralLedgerPage.test.tsx src/features/finance/GeneralLedgerPage.test.tsx`
```
 ✓ src/features/finance/GeneralLedgerPage.test.tsx (6 tests) 212ms

 Test Files  2 passed (2)
      Tests  12 passed (12)
   Start at  15:16:16
   Duration  1.69s (transform 426ms, setup 689ms, collect 870ms, tests 324ms, environment 736ms, prepare 130ms)
```
(9 tests before this round, 12 after: 3 new cases in `pages/GeneralLedgerPage.test.tsx`.)

`./node_modules/.bin/eslint src/features/finance/components/LedgerTable.tsx src/features/finance/pages/GeneralLedgerPage.test.tsx src/features/finance/__fixtures__/generalLedger.ts`
```
ESLINT EXIT: 0
```
(no output at all — **0 errors, 0 warnings**. The gate's pre-existing
`no-misused-promises` warning is on `GeneralLedgerPage.tsx`, which this round did
not touch and did not re-lint.)

`./node_modules/.bin/tsc --noEmit`
```
TSC EXIT: 0
```

Audit tools:
```
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```

`pnpm -s audit:i18n:local`
```
i18n completeness OK — 55 namespaces, authored keys: en=9501, fr=9518, ar=5146 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
```
(No i18n keys were added or changed by this round.)

**Baseline honesty.** No baseline file appears in the diff. `git show --stat 0f9292bf1`
is 3 files, all under `apps/web/src/features/finance/`. No `--write-baseline`, no
suppression comment, no alias indirection.

**Design tokens.** The only touched `.tsx` render lines keep `textColors.primary` /
`textColors.tertiary`; no literal colour class was introduced (rule 18). Design-system
audit: 810 acknowledged, 0 new.

---

## Deliberately NOT changed (follow-ups for a separate lane)

1. **Gate finding 4 — dead "Export" button** (`GeneralLedgerPage.tsx:21-23,40-43`,
   `handleExport` is an empty stub wired to an enabled button). Owner ruling OQ-11 says
   controls without a backend are hidden, not shipped inert. Pre-existing; untouched here.
2. **Gate finding 5 — hand-rolled `LedgerLine`** (`apps/web/src/features/finance/types.ts:147-159`)
   shadowing generated `LedgerLineData` (`packages/shared/types/generated.d.ts:151-164`),
   dropping `partner_name`. Knowingly documented drift, blocked on the generated-types
   ambient-resolution plumbing issue.
3. **Gate finding 6 — two competing test files for one page**
   (`src/features/finance/GeneralLedgerPage.test.tsx` vs
   `src/features/finance/pages/GeneralLedgerPage.test.tsx`). Left in place per the
   fix-round brief.
   **New sub-item created by this round:** the twin file's *inline* fixture overrides
   (`GeneralLedgerPage.test.tsx:49-51,62-64,69-71`) still carry scale-2 literals
   (`'1000.00'`, `'0.00'`). They render identically under the new guard (both blank a
   zero) so nothing is red, but they are the same unrealism finding 2 was about and
   should be folded into the dedup lane rather than fixed in isolation here.

## Not verified in this round
- No live API payload was captured; the scale-4 conclusion is still read from
  `GeneralLedgerReportService.php` + `LedgerData.php`, not from an HTTP response.
- No browser recette; no TND-tenant screenshot of the GL.
- Only the two GL test files were run (run-by-file rule); the wider finance suite was not.
- No PHP was run and no PHP file was changed.
- No RTL/Arabic rendering check of the currency string.
