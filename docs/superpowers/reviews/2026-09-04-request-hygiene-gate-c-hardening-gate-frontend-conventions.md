# Gate — request-hygiene Gate C hardening (`placeholderData` pairing)

- Lane: `lane/rh-gate-c-hardening` @ `ceb46e4e0` (code `0686ab2dc`), base `e829444d5` (= `dev`)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-gatec`
- Reviewer: adversarial frontend-conventions gate, 2026-09-04
- Scope reviewed: `apps/web/tools/audit-tanstack-keys.mjs`, its tests + 6 fixtures, `docs/conventions/05-REACT-QUERY.md`, handback doc. No `src/` file is touched by the lane (`git diff --numstat` confirms).

## VERDICT: APPROVE-WITH-FIXES

No BLOCKER. The four MAJOR-A false-negative classes are genuinely closed, red-first is
reproduced independently (7 failed / 56 passed with the base tool + new tests), the tree
is clean in both directions, and the rule is falsifiable on real files. Two residual
false-negative shapes and three false-positive shapes are recorded below as follow-ups;
none is a regression versus base (base was a whole-file `text.includes` check, i.e.
strictly weaker in every one of these shapes), and none exists in `src/` today.

---

## 1. False-negative direction — the four MAJOR-A classes ARE closed (red-first replayed)

Every fixture scanned with the BASE tool (`git show e829444d5:apps/web/tools/audit-tanstack-keys.mjs`)
and with the lane tool, same entrypoint (`scanCode`):

| fixture | BASE findings | LANE findings |
|---|---|---|
| `identifier-scoped-unguarded.ts` (class 1, `currentCompanyId`) | 0 | 1 (line 14) |
| `store-object-scoped-unguarded.ts` (class 1b, `companyStore.currentCompanyId`) | 0 | 1 (line 12) |
| `use-queries-entry-unguarded.ts` (class 2, `useQueries.queries[]`) | 0 | 1 (line 19) |
| `comment-mention-only.ts` (class 3, comment/string/dead import) | 0 | 1 (line 18) |
| `two-reads-one-guarded.ts` (class 4, per-call-site) | 0 | 1 (line 22, `UnguardedList`) |
| `paired-variants.ts` (negative, 5 legit shapes) | 0 | 0 |

Test-level red-first replay (base tool swapped in, new test file unchanged):
`Tests 7 failed | 56 passed (63)` — exactly the handback's claim. Restored: `63 passed`.

`pnpm vitest run tools/__tests__` → `8 files, 175 tests passed` (claim verified, not accepted).

Mechanism audit: the trigger predicate widened from `isTenantScopedFactoryCall` to
`queryKeyCarriesTenantScope()`, which is a strict SUPERSET (same `APPROVED_FACTORY_CALLS`
branch plus the array-literal-with-approved-scope branch) — no narrowing was used to keep
the tree green. `BASELINED_VIOLATION_KEYS` is untouched (`new Set([])`, run reports
`0 acknowledged, 0 new, 0 stale`). Test diff is `124 / 0` (additive only; nothing weakened
or deleted).

## 2. Falsification on real files (re-run once, restored)

`perl -pi -e 's/usePlaceholderScopeGuard\(/noopGuard(/g'` on the only two production
call sites, then `node tools/audit-tanstack-keys.mjs`:

```
[sweep-progress] Gate C ... : 2
  src/features/inventory/StockMovementsPage.tsx:204:5  useQuery({ queryKey: locationScopedKey([...]), placeholderData }) ...
  src/features/treasury/PaymentListPage.tsx:124:5      useQuery({ queryKey: tenantScopedKey([...]),   placeholderData }) ...
EXIT=1
```
`git checkout --` restored; re-run → `0 violations, EXIT=0`; `git status --short` empty.
The rule is live, not vacuous.

## 3. False-positive audit (the "blocks every future lane" direction)

All `placeholderData`/`keepPreviousData` occurrences on this branch
(`rg -n "placeholderData|keepPreviousData" src`): 18 hits, of which exactly **two** are real
options-object uses — `src/features/treasury/PaymentListPage.tsx:124` and
`src/features/inventory/StockMovementsPage.tsx:204` (both guarded); the rest are comments,
tests and the guard hook's own docblock. Scanner on the untouched tree: **0 findings, exit 0**;
`--json` → `{"violations":[]}`.

Ten probe files were created under `src/__gate_probe_tmp__/`, scanned, then deleted
(`git status --short` empty afterwards, scanner back to 0):

| probe | shape | flagged? | verdict |
|---|---|---|---|
| P1 | `const q = useQuery(...)`, `const guard = usePlaceholderScopeGuard(q.isPlaceholderData, ...)`, `guard` consumed | no | correct |
| P2 | read inside custom hook, CONSUMER applies the guard | **yes** | design constraint (see ruling) |
| P3 | `useQueries({ queries, combine })` destructured into combine's own field names, guard applied | **yes** | FALSE POSITIVE, workaround exists (P10) |
| P4 | guard imported aliased (`usePlaceholderScopeGuard as useScopeGuard`) | **yes** | FALSE POSITIVE, trivial workaround |
| P5 | `queryKey` passed as a shorthand const | yes | correct (shorthand resolution works) |
| P6 | guard CALLED but verdict never applied to the rows | no | false negative (silencer) |
| P7 | thin expression-bodied wrapper hook returning `useQuery(...)` directly | yes | design constraint |
| P8 | `const results = useQueries(...)`, guard on `results[1].isPlaceholderData` | no | correct |
| P9 | `placeholderData` arriving via `...spreadOptions` | no | false negative |
| P10 | same as P3 but combined result bound to ONE identifier | no | correct — the P3 workaround |

Inline probes: `['admin', ...]` namespace + `placeholderData` → 0 (deliberate, matches the
documented exemption); `useInfiniteQuery` unguarded → 1; guard inside a nested component in
the same function → 0 (documented over-approval); `let r; r = useQuery(...)` → 1 (assignment,
not declaration — rare FP with a trivial workaround).

**Ruling on the custom-hook constraint (P2/P7):** ACCEPTABLE AS A DESIGN CONSTRAINT, keep the
guard inside the hook — the scanner cannot follow `isPlaceholderData` across a module
boundary, the constraint fails CLOSED (a forgetful consumer cannot leak), zero such shapes
exist in `src/` today, and the prescribed pattern (hook applies the guard and returns rows
already blanked, or returns the verdict) is strictly safer than trusting each consumer. It
must, however, be written down in `docs/conventions/05-REACT-QUERY.md` — see MINOR-1.

## 4. Findings

### MAJOR-1 — one guard clears ALL entries of a `useQueries` bound to a single identifier
`apps/web/tools/audit-tanstack-keys.mjs:570-583` (`readResultBindingNames`, identifier branch
returns before `entryIndex` is consulted).
Falsifying scenario (verified, 0 findings — should be 1):
```ts
const results = useQueries({ queries: [
  { queryKey: tenantScopedKey(['a', page]), queryFn: fa, placeholderData: keepPreviousData },
  { queryKey: tenantScopedKey(['b', page]), queryFn: fb, placeholderData: keepPreviousData },
]})
const stale = usePlaceholderScopeGuard(results[0].isPlaceholderData, results[0].data !== undefined)
```
Entry `['b']` is unguarded and leaks the previous company's rows, yet the scanner reports
nothing. This is class 4 ("one guard must not clear a sibling read") surviving inside one
`useQueries` call — the array-destructured form of the same code IS correctly flagged.
The tool header (`:35-72`) and `docs/conventions/05-REACT-QUERY.md:137-138 + :156-158` both advertise
per-call-site pairing, so the doc currently overstates the guarantee.
Fix directive: when `entryIndex !== null` and the binding is a bare identifier, require the
guard argument to contain an element access at that index (`results[<entryIndex>]`), and add a
fixture for the two-entry/one-guard identifier shape.

### MAJOR-2 — `placeholderData` supplied through a spread is invisible
`apps/web/tools/audit-tanstack-keys.mjs:721-727` (`options.properties.find(... p.name.text === 'placeholderData')` — a `SpreadAssignment` has no `name`).
Falsifying scenario (verified, 0 findings):
```ts
const listOptions = { placeholderData: keepPreviousData }
useQuery({ queryKey: tenantScopedKey(['p9', page]), queryFn: f, ...listOptions })
```
Not a regression (base had the same hole) and no such shape exists in `src` today, but it is
the cheapest accidental re-admission of the company-leak defect and it is not mentioned in
either rule text. Fix directive: when the key carries a tenant scope and the options object
contains any `SpreadAssignment`, treat the read as unpaired-unknown and report it (or, at
minimum, document the hole in the "Rule 2" header and in `05-REACT-QUERY.md`).

### MINOR-1 — the custom-hook constraint is not in the convention doc
`docs/conventions/05-REACT-QUERY.md:159-161` says only "inside the read's nearest enclosing
function". Add one sentence: a read inside a custom hook must be guarded INSIDE that hook
(return blanked rows or the verdict); a consumer-side guard will be flagged. Handback
`docs/handoff/HANDBACK-request-hygiene-gate-c-hardening-2026-09-04.md:74-77` already states it
— it belongs in the convention the next lane reads.

### MINOR-2 — an aliased guard import is a false positive
`apps/web/tools/audit-tanstack-keys.mjs:663-670` matches the call name literally
(`node.expression.text === PLACEHOLDER_SCOPE_GUARD`). `import { usePlaceholderScopeGuard as
useScopeGuard }` + a correct guard call is reported, and the message ("a comment or import
naming the guard does not count") actively misleads in that case. Fix directive: resolve the
local name from the import specifier, or state "import it under its canonical name" in the doc.

### MINOR-3 — a guard call whose verdict is discarded pairs successfully
`apps/web/tools/audit-tanstack-keys.mjs:655-675`. `usePlaceholderScopeGuard(isPlaceholderData,
data !== undefined);` as a bare expression statement clears the rule while the rows stay
unblanked (P6, verified 0 findings). Cheap silencer for a future lane. Fix directive: require
the guard call's result to be bound/used, or note the residual reviewer duty in the rule text.

### MINOR-4 — `let r; r = useQuery(...)` is a false positive
`apps/web/tools/audit-tanstack-keys.mjs:544-560` (`findResultBindingDeclaration` stops at the
enclosing `ExpressionStatement`). Rare; workaround is a `const` declaration. Note only.

## 5. Non-blocking verifications

- **Rule text vs implementation** (two spot-checks, both exact):
  (a) `05-REACT-QUERY.md:146-150` "a bare `tenantId`/`currentCompanyId`/`companyId`, or
  `companyStore.<one of those>` … `['admin', …]` is not a tenant scope" matches
  `APPROVED_SCOPE_IDENTIFIERS` (`:123-127`), `APPROVED_STORE_OBJECTS` (`:134-136`),
  `isApprovedScopeExpression` (`:219-237`) and `queryKeyCarriesTenantScope` (`:493-503`,
  element-wise, so the `'admin'` prefix branch of `arrayHasApprovedScope` is deliberately not
  reachable) — confirmed empirically (admin-namespace probe → 0).
  (b) `05-REACT-QUERY.md:156-158` "a read whose result is never bound can never be paired and
  is always reported" matches `readResultBindingNames`/`readHasPairedScopeGuard`
  (`:655-658`) and the inline test at `tools/__tests__/audit-tanstack-keys.test.mjs:694-706`.
  The only mismatch found is the per-call-site claim in the MAJOR-1 shape.
- **Fixtures are outside the scanned tree** (`SRC_ROOT` = `apps/web/src`), so the deliberately
  violating fixtures cannot poison `pnpm audit:keys` — confirmed by the clean run.
- **Fixtures are eslint-ignored** (`File ignored because of a matching ignore pattern`) and
  outside `tsconfig.json` `include: ["src"]`, so they add no lint/typecheck noise. `.ts`
  (JSX-free) fixtures + inline `'inline.tsx'` coverage of the TSX `ScriptKind` path: accepted.
- **`@ts-check` parity**: base 15 errors / lane 15 errors, and the error CLASSES are
  byte-identical after normalising positions (`diff` of the sorted uniq'd messages → identical).
- **eslint**: `tools/audit-tanstack-keys.mjs` and `tools/__tests__/audit-tanstack-keys.test.mjs`
  → 0 problems each. Repo-wide `pnpm lint:eslint` → `0 errors, 6450 warnings` (pre-existing).
- **`pnpm typecheck`** → clean.
- **`pnpm lint` fails, but NOT because of this lane**: it aborts at `audit:design-system`
  with `810 violations / 796 acknowledged / 14 new / 11 stale` — the SAME numbers reproduce at
  base `e829444d5` in the main checkout, all 14 in `src/features/import/pages/ImportWizardPage.tsx`,
  a file this lane does not touch. `audit:i18n:local` also fails on the lane (locale files
  untouched by the lane). Both are pre-existing `dev` debt owed elsewhere; they are NOT
  attributable to, nor cleared by, this lane.
- `pnpm audit:quantity` → 0 total / 0 new. `pnpm test:eslint-rules` → all RuleTester suites pass.

## 6. Commands run

```
node tools/audit-tanstack-keys.mjs                                  # 0 violations, exit 0
node tools/audit-tanstack-keys.mjs --json                           # {"violations":[]}
pnpm vitest run tools/__tests__                                     # 8 files / 175 passed
pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs        # 63 passed
  (with base tool swapped in)                                       # 7 failed | 56 passed
pnpm typecheck                                                      # clean
pnpm lint:eslint                                                    # 0 errors, 6450 warnings
pnpm audit:quantity / pnpm test:eslint-rules                        # clean
pnpm lint                                                           # FAILS at audit:design-system (pre-existing, identical at base)
tsc --checkJs on tool (base vs lane)                                # 15 vs 15, identical classes
10 probe files under src/__gate_probe_tmp__ + 5 inline probes       # table in §3; deleted, git status clean
```

## 7. Merge-tree

```
$ git merge-tree --write-tree dev lane/rh-gate-c-hardening   # dev = e829444d5
39bc948118af5abbad9144594d3d93dceadfcafb
```
Exit 0, single line, **no conflicts**. `git merge-base --is-ancestor dev lane/rh-gate-c-hardening`
succeeds — the lane is a clean fast-forward of `dev`.
