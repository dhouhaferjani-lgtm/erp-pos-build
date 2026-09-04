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
