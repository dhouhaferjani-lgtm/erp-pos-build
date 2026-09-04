# HANDBACK — Gate C `placeholderData` pairing hardening (2026-09-04)

Lane `lane/rh-gate-c-hardening`, base `e829444d5`. Closes MAJOR-A of
`docs/superpowers/reviews/2026-09-04-request-hygiene-placeholder-data-gate-independent.md`
(the four proven false-negative classes at `audit-tanstack-keys.mjs:461-492`).

## What changed

`apps/web/tools/audit-tanstack-keys.mjs` — the `placeholderData` pairing rule only:

1. **`useQueries` entries are now placeholder-bearing.** `'useQueries.queries[]'`
   added to `PLACEHOLDER_BEARING_FACTORIES`; `checkUseQueriesOptions` forwards the
   `useQueries(...)` call node and the entry index.
2. **Scope detection widened for the trigger.** New `queryKeyCarriesTenantScope()`:
   an approved factory call (`tenantScopedKey` / `locationScopedKey`) **or** an
   array literal containing an approved scope expression (bare
   `tenantId`/`currentCompanyId`/`companyId`, or `companyStore.<id>`). The
   `'admin'`/`'super-admin'` namespace prefix is deliberately NOT a tenant scope,
   so it does not trigger the pairing rule.
3. **Text search replaced by an AST, per-call-site check.** `readHasPairedScopeGuard()`
   requires (a) the read's result to be bound — object destructuring of
   `isPlaceholderData` (renames honoured), a whole-result identifier, or the
   array-destructured element at the `useQueries` entry index — and (b) a real
   `usePlaceholderScopeGuard(...)` **CallExpression** inside the read's nearest
   enclosing function that passes one of those bound names as an argument.
   The old `sourceFile.text.includes(...)` is gone.

Rule documented in the tool header ("Rule 2" block) and in
`docs/conventions/05-REACT-QUERY.md` ("What Gate C actually checks").

## Evidence

- **Red first.** With the fixtures in place and the tool untouched: **7 failed / 56 passed**.
  Each of the four false-negative classes produced **0 findings** before the fix.
- **Green.** `pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs` →
  **63 passed** = 54 pre-existing + 9 new (7 of the 9 were red before the fix; the
  other 2 — the all-legitimate-shapes negative and a nested-callback pairing —
  passed already and pin behaviour that must not regress).
  `pnpm test:tools` → **8 files, 175 tests passed**.
- **Real tree.** `node tools/audit-tanstack-keys.mjs` → `0` violations, `0` new,
  `0` stale baseline entries, **exit 0**. `--json` → `{"violations":[]}`.
- **Falsification on real files** (proves the rule is live, not vacuous): replacing
  only the guard CALL (`usePlaceholderScopeGuard(` → `noopGuard(`) while leaving the
  comment mentions in place now yields
  `src/features/treasury/PaymentListPage.tsx:124` and
  `src/features/inventory/StockMovementsPage.tsx:204`. Unmodified: 0.
  `src/features/pos/hooks/useDiscountPreview.ts` stays at 0 both ways (it dropped
  `placeholderData`, as the convention prescribes).
- **eslint** on `tools/audit-tanstack-keys.mjs` + its test: 0 errors, 0 warnings.
  `@ts-check` error count under an ad-hoc `tsc --checkJs`: **15, identical to base**.

## Fixtures

`apps/web/tools/__fixtures__/audit-tanstack-keys/placeholder-pairing/`

| Fixture | Class | Expected |
|---|---|---|
| `identifier-scoped-unguarded.ts` | 1 — bare `currentCompanyId` scope | 1 |
| `store-object-scoped-unguarded.ts` | 1b — `companyStore.currentCompanyId` | 1 |
| `use-queries-entry-unguarded.ts` | 2 — `useQueries` entry | 1 |
| `comment-mention-only.ts` | 3 — comment/string/dead-import mention | 1 |
| `two-reads-one-guarded.ts` | 4 — per-file pairing | exactly 1 |
| `paired-variants.ts` | negative — 5 legitimate pairing shapes | 0 |

## Baseline

`BASELINED_VIOLATION_KEYS` is still `new Set([])`. **Nothing absorbed** — the
hardened scanner finds nothing on `dev`, so there was nothing to absorb.

## Open items for the orchestrator

- None from the real tree: no unguarded scoped `placeholderData` read exists on
  `dev`. No `src/` file was touched by this lane.
- Judgement call worth knowing: a read inside a custom hook whose consumer applies
  the guard would now be flagged (the guard search stops at the read's enclosing
  function). No such shape exists today; if one lands, the options are to move the
  guard into the hook or to widen the search scope deliberately.

---

# Fix round 1 (2026-09-04) — response to the independent gate

Gate report: `docs/superpowers/reviews/2026-09-04-request-hygiene-gate-c-hardening-gate-frontend-conventions.md`
(verdict APPROVE-WITH-FIXES, no BLOCKER). Closed here: MAJOR-1, MAJOR-2 (with one
scoped deviation, below), MINOR-1, MINOR-2, MINOR-4. MINOR-3 documented.

## What changed in the tool

| Finding | Status | Change |
|---|---|---|
| MAJOR-1 — one guard cleared ALL entries of an identifier-bound `useQueries` | **FIXED** | `readResultBinding` now returns an `indexedHolder` instead of the bare array name when `entryIndex !== null`. Pairing then requires an element access AT THAT INDEX inside a guard argument — `results[1]` or `results.at(1)`. The bare `results` handle pairs nothing (fails closed). |
| MAJOR-2 — `placeholderData` through `...spreadOptions` invisible | **FIXED for resolvable payloads** (see deviation) | `spreadPayloadPlaceholderVerdict` resolves a spread payload inside the file — object literals, `()`/`as`/`satisfies` wrappers, conditional and `&&`/`\|\|`/`??` combinations, an identifier bound to a local object literal, and a call to a locally declared function's returns. A payload that declares `placeholderData` triggers the rule and is reported as `unpaired-unknown (spread options — declare placeholderData inline or guard)`. |
| MINOR-1 — custom-hook constraint not in the convention | **FIXED** | New "Where the gate stops" block in `docs/conventions/05-REACT-QUERY.md`: guard INSIDE the hook, a consumer-side guard is not followed across a module boundary, the rule fails closed. |
| MINOR-2 — aliased guard import = false positive | **FIXED** | `guardLocalNames` resolves aliases from the file's named imports (`import { usePlaceholderScopeGuard as useScopeGuard }`). A same-named LOCAL helper still does not pair. |
| MINOR-3 — discarded guard verdict pairs | **DOCUMENTED** | Recorded as a known limitation (fails open) in the tool header, the convention doc, and pinned by a test. |
| MINOR-4 — `let r; r = useQuery(...)` = false positive | **FIXED** | `findResultBindingName` accepts an assignment-expression binding to an identifier as well as a variable declaration. |

## Deviation on MAJOR-2 — measured, deliberate

The directive was "ANY `SpreadAssignment` … reported unless a guard is paired".
Implemented as directed for spreads whose payload can be resolved; an
**unresolvable** payload (a caller-supplied `options` parameter, an imported
options object) is a documented known limitation instead. Reason, measured, not
assumed:

- 8 scoped reads on `dev` spread into their options object
  (`catalog/api/queries.ts` ×3, `import/api/queries.ts` ×1,
  `owner-dashboard/hooks/useOwnerReports.ts` ×4).
- The resolver proves 5 of them free of `placeholderData`
  (`...buildOwnerReportRefreshOptions(params)` → a conditional of two object
  literals; `...(cond && { refetchInterval })`).
- The remaining 3 — `useCategories` / `useCategoryTree` / `useCategory`, which
  spread a caller-supplied `Omit<UseQueryOptions, 'queryKey' | 'queryFn'>` —
  are unresolvable. Firing on them was verified to produce **3 findings on the
  real tree** (run with the rule widened to `!== 'no'`), i.e. a red gate on `dev`.
- Closing that hole needs a `src/` change (guard inside the hook, or drop
  `placeholderData` from the accepted options type). This lane must not touch
  `src/`, and the baseline escape hatch is unusable: `violationBaselineKey`
  embeds a byte offset, so baselined entries go stale on any edit above them.

**Follow-up owed (new, P2):** an `src/` lane for
`src/features/catalog/api/queries.ts` — those three hooks accept a pass-through
options object on a tenant-scoped key. Once they are fixed, widen the rule from
"resolvable payload declares `placeholderData`" to "any unresolvable spread"
(one-line change: `=== 'yes'` → `!== 'no'`).

## New fixtures / tests

| Fixture | Finding | Expected |
|---|---|---|
| `use-queries-identifier-partial-guard.ts` | MAJOR-1 — identifier-bound `useQueries`, guard on entry 0 only | exactly 1 (entry 1, `receipts`) |
| `spread-options-unpaired.ts` | MAJOR-2 — `placeholderData` via `...listOptions` | 1 |

Plus 12 inline tests in `tools/__tests__/audit-tanstack-keys.test.mjs`
(`placeholderData pairing — gate fix round 1`), including three that PIN the
known limitations (unresolvable spread, discarded verdict, local same-named
helper) so a future lane that closes one fails the test on purpose.

## Verification

```
red first (new tests, pre-fix tool)   6 failed | 71 passed (77)
pnpm vitest run tools/__tests__       8 files / 189 tests passed
pnpm test:tools                       8 files / 189 tests passed
node tools/audit-tanstack-keys.mjs    0 violations, 0 new, 0 stale, exit 0
  falsification (guard call renamed)  2 findings, exit 1; restored -> 0, exit 0; git status clean
eslint (tool + test)                  0 errors, 0 warnings
tsc --checkJs on the tool             15 errors, error classes byte-identical to ceb46e4e0
pnpm typecheck                        clean
```
