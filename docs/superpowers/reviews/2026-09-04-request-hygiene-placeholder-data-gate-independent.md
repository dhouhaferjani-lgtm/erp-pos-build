# Independent merge gate — request-hygiene placeholder-data audit

- Date: 2026-09-04
- Reviewer: frontend-conventions-reviewer (independent orchestrator gate, not the lane's self-gate)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-placeholder`
- Branch: `lane/rh-placeholder-data-audit` @ `4c08d7553`; base `9c28b430a`; commits `b58229472`, `39779205b`, `90c4c424a`, `4c08d7553`
- Read-only review. No file in the worktree was left modified (`git status --porcelain` clean after every falsification).

## VERDICT: MERGE

No blocking findings. The two scope-widening items (the new CI detector and the POS cart change) both hold up under independent verification. Six non-blocking follow-ups below; browser checks are promotion-owed.

---

## 1. Blocking findings

**None.**

---

## 2. Non-blocking findings

### MAJOR-A — the new Gate C pairing rule has four proven false-negative classes

`apps/web/tools/audit-tanstack-keys.mjs:461-492`. Probed empirically against the shipped `scanCode` export:

| Case | Result |
|---|---|
| `useQuery({ queryKey: ['payments', page, currentCompanyId], placeholderData })` | **0 findings** — the pairing branch is gated on `isTenantScopedFactoryCall` (`:456-459`), but `APPROVED_SCOPE_IDENTIFIERS` (`:82-86`) makes the identifier form an equally legal, equally leaky scoped key |
| `useQueries({ queries: [{ queryKey: tenantScopedKey([...]), placeholderData }] })` | **0 findings** — `checkUseQueriesOptions` passes `factoryName = 'useQueries.queries[]'` (`:585`), which is not in `PLACEHOLDER_BEARING_FACTORIES` (`:120-124`) |
| a comment containing the literal string `usePlaceholderScopeGuard` above an unguarded read | **0 findings** — the guard test is `sourceFile.text.includes(...)` (`:470`), a substring match, so a comment suppresses the rule |
| two scoped `placeholderData` reads in one file, only one guarded | **0 findings** — documented per-file granularity (`:112-116`), but a reviewer cannot see the unguarded one |

Falsifying scenario for the worst of these: a future lane writes `useQuery({ queryKey: ['payments', page, currentCompanyId], placeholderData: keepPreviousData })` — Gate C passes, `pnpm lint` is green, and the previous company's payment rows render across the switch exactly as before this lane.

Fix directive: add `'useQueries.queries[]'` to `PLACEHOLDER_BEARING_FACTORIES`; extend the pairing branch to keys approved via `APPROVED_SCOPE_IDENTIFIERS`/`APPROVED_STORE_OBJECTS`; replace `sourceFile.text.includes(...)` with an AST check for an actual `usePlaceholderScopeGuard(...)` call expression.

Not blocking because: the convention doc's enforcement sentence (`docs/conventions/05-REACT-QUERY.md`, "`placeholderData` on a `tenantScopedKey`/`locationScopedKey` read **in a file that does not use** `usePlaceholderScopeGuard` fails Gate C") is scoped precisely to what the detector does — no overstated guarantee — and both real call sites in the tree are genuinely fixed, verified below.

### MAJOR-B — the POS 500 ms debounce window still shows the previous cart's discount as settled

`apps/web/src/features/pos/hooks/useDiscountPreview.ts:73-80` debounces `debouncedRequest`. For 500 ms after every cart edit the query key is unchanged, so `isLoading` is `false`, `data` is the settled breakdown **for the previous cart**, and `apps/web/src/features/pos/organisms/TransactionCart/TransactionCart.tsx:335-350` renders that old promotion badge and savings line with no busy state.

This is exactly the failure the lane's own rationale cites when dropping `placeholderData` (`useDiscountPreview.ts:93-95`: "the key also changes on every cart edit, so a placeholder is a discount computed for a DIFFERENT cart even within one company") — the debounce window reproduces it with settled rather than placeholder data.

Falsifying scenario: cart with a quantity-tiered promotion; bump the quantity. For ~500 ms the tier-1 savings figure is displayed as final, no skeleton, then it jumps.

Pre-existing (the debounce is untouched by this diff) and display-only — no money path — so non-blocking. Fix directive: derive the busy flag from `request !== debouncedRequest || isLoading` and expose it as `isDiscountPreviewLoading`.

### MINOR-C — the gated header count asserts a definite "0" during the in-flight window

`apps/web/src/features/treasury/PaymentListPage.tsx:133` (`const total = meta?.total ?? payments.length`) feeding the subtitle at `:227-231`, and `apps/web/src/features/inventory/StockMovementsPage.tsx:418` (`t('movements.subtitle', { count: meta?.total ?? 0 })`). With the guard active, `meta` is `undefined`, so the PageHeader states "0 payments total" / "0 movements" while the rows are still a skeleton — an emptiness claim the app does not know to be true. The lane's own new doc section says "Gate **every** derived value on `isStaleScopeData`, not just the rows: header counts…"; the letter is followed but the `?? 0` fallback re-introduces the wrong statement. Neither new test asserts the subtitle.

Fix directive: render the subtitle count as a placeholder (em-dash / omit the clause) while `isStaleScopeData || isLoading`, and add one assertion per suite.

### MINOR-D — the busy region announces nothing to a screen reader

`apps/web/src/features/pos/organisms/TransactionCart/TransactionCart.tsx:322-330` — `aria-busy="true" aria-live="polite"` on a region whose only children are two pulse divs. Nothing is announced.

Fix directive: add `<span className="sr-only">{t('pos:cart.computingDiscounts')}</span>` inside the region (new key, `t()`-routed).

### MINOR-E — the plan-revision list is incomplete: four occurrences, not two

`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` prescribes `placeholderData: keepPreviousData` at lines **848, 1095, 1657, 1742**. The handback (§7.2 and §10.3) names only **1657** (Task 5, `LineItemEntryBar`) and **1742** (Task 6, `DocumentLineEditor`).

The two it misses are the surfaces this lane just guarded:
- **`:848`** — the StockMovementsPage spec; the same snippet also prescribes `const movements = data?.data ?? []` (`:851`) and `{data?.meta ? (` (`:854`), i.e. the exact ungated reads this lane replaced.
- **`:1095`** — the PaymentListPage spec, in a block that also prescribes the `useEffect(() => setPage(1), [search])` form that `:93` now supersedes.

Fix directive for the orchestrator: revise all four, and in the `:848`/`:1095` blocks replace the ungated reads with the `usePlaceholderScopeGuard` form from `docs/conventions/05-REACT-QUERY.md`.

### MINOR-F — the web `POSPage` is exported but not routed

`apps/web/src/features/pos/index.ts:40` exports `POSPage`; `apps/web/src/routes/index.tsx` lazily imports eleven other `features/pos` pages (`:102`, `:286-295`) but **not** `POSPage`. The POS half of this lane therefore cannot be browser-verified and has no production blast radius today. Corroborates handback §10.1. Recorded, not a defect of this diff — but it means the "computing vs no promotion" UX change is unobservable until the page is mounted (owner rule: anything that works must be reachable — this one is not, and is not made reachable here).

### NOTE-G — `pnpm lint` (full) is red in this tree, and it is red at base

`audit:design-system` → `810 violations / 796 acknowledged / 14 new / 11 stale`, every one of them in `src/features/import/pages/ImportWizardPage.tsx`. `audit:i18n:local` → `ar|uom|*` missing keys.

Independently confirmed as **not** this lane's: `git diff --stat 9c28b430a dev -- apps/web/src/features/import/pages/ImportWizardPage.tsx apps/web/tools/audit-design-system-baseline.json` is **empty** (byte-identical base↔dev), and `git diff --name-only 9c28b430a 4c08d7553 | grep -c 'ImportWizard\|design-system-baseline'` → `0`. No lane file appears in either audit's output. Record the lane as "lint no-worse-than-base", as the handback already does. The red itself is dev debt the orchestrator owns.

---

## 3. What held up

**Guard semantics — correct.** `apps/web/src/hooks/usePlaceholderScopeGuard.ts:79-88`. The TanStack invariant is real in the **installed** build, not just in the lane's prose: `@tanstack/query-core@5.90.11` `build/modern/queryObserver.js:265` reads

```js
if (options.placeholderData !== void 0 && data === void 0 && status === "pending") {
```

with `#lastQueryWithDefinedData` consumed at `:272-273` and assigned at `:380`. So the placeholder branch is taken only when the **current** key has no data — settled data can never sit under a scope it was not fetched for, which is what makes `!isPlaceholderData && hasData` valid proof of scope. (The lane cites `queryObserver.js:266`; the statement is at `:265`. Off by one, harmless.)

**Version-pin ruling: no pin needed.** `apps/web/package.json:56` is `"@tanstack/react-query": "^5.90.11"`, so any 5.x minor can land, and the invariant is an internal implementation detail with no public contract. But the two page suites drive a **real** `QueryClient` and a real `useQuery`, mocking only the transport (`PaymentListPage.companyScope.test.tsx:28-34`, `StockMovementsPage.companyScope.test.tsx:64`), so a minor that changed placeholder timing turns the five leak tests red on upgrade. The hook's own unit test drives booleans directly (`usePlaceholderScopeGuard.test.tsx:14-40`) and would **not** catch it — a limitation the hook's docblock already states. Recommendation (non-blocking): one line in the docblock naming the two page suites as the upgrade tripwire. A version pin would be worse — it would freeze the dependency without pinning the behaviour.

**React 19 StrictMode — safe.** The render-phase `setSettledScopeSignature` is idempotent and self-guarded (`usePlaceholderScopeGuard.ts:85-87`), so a discarded StrictMode pass re-runs it to the same value. The same pattern in the offset reset (`PaymentListPage.tsx:94-97`, `StockMovementsPage.tsx:166-169`) cannot emit a stale-offset request either, because `useQuery` does not fetch during render — empirically pinned by the two "restarts traversal at page one when the company changes, without requesting the stale offset" tests, which the lane also falsified in the over-broad direction.

**Scope signature covers every dimension the key carries.** `PaymentListPage.tsx:93` `filterSignature = JSON.stringify([search, tenantId, companyId])`; `StockMovementsPage.tsx:157-163` adds `normalizeViewScope(scope)`. Both blocks sit above their `useQuery`. The location dimension is signed through the **same** normaliser the key uses (`StockMovementsPage.tsx:213-215` → `lib/locationScopedKey.ts:13`), so a permuted-but-equal selection cannot desynchronise guard and key. `additionalScope` is compared by `JSON.stringify` of already-normalised values — deterministic.

**Every derived read is gated.** StockMovements `:243` rows, `:248` meta, `:418` subtitle (via meta), `:453` isLoading, `:475-482` pagination. Payments `:131-133`, `:253`, `:271-278`. (The `?? 0` fallback is MINOR-C above; nothing leaks.)

**POS money path — untouched, and no fiscal field reads the preview.** `TransactionCart.tsx:100-102` derives `subtotal` from `items` via `bcadd`; `:359-367` hands `PaymentPanel` `items` plus `transactionDiscountAmount` (the operator's **manual** discount), never the preview. `discountBreakdown` / `discountSavings` are consumed only at `:335-350` to render badges and a savings line. `grep -rn 'discountPreview|discountBreakdown|totalSavings|discountSavings' src/features/pos` returns no receipt, no payload, no fiscal consumer. **fiscal-pos-reviewer is not required.**

**"Computing" vs "no promotion" is now distinguishable.** `TransactionCart.tsx:322-330` (skeleton, `aria-busy`) vs `:335` (settled-empty → nothing rendered), covered by three tests including the empty-cart negative.

**Tokens and i18n on the new markup.** `colors.neutral[200]` and `borderColors.light` are imported from `@/lib/designTokens` (`TransactionCart.tsx:4`; `designTokens.ts:59` → `'bg-gray-200'`). No interpolated variant prefix, no opacity modifier, no composed-at-runtime class. No new literal text, so no `t()` is owed (except MINOR-D's sr-only suggestion). Gray pulse — blends, adds no accent competing with the cart's primary action (OQ-5 / one-main-element clean). No dead control, no hardcoded brand string.

**The `useViewScope` rebind weakens nothing.** The mock at `StockMovementsPage.companyScope.test.tsx:35-50` is file-local; vitest module mocks do not cross files, and the diff modifies **no** pre-existing test file except an append to `TransactionCart.test.tsx`. `StockMovementsPage.test.tsx` and `StockMovementsPage.reverseWriteOff.test.tsx` are untouched and carry their own mocks.

**Detector wiring is real.** `apps/web/package.json:10` (`lint` → `audit:keys`), `:13` (`test:tools` → `vitest run tools/__tests__`, so the six scanner tests run inside `pnpm lint`), `scripts/preflight.sh:178`, `.github/workflows/ci.yml:2409`.

**No false positive on the merged tree.** On `dev`, the only real `placeholderData:` **properties** are the same three files this lane handles; `DocumentLineEditor.tsx:413` and `LineItemEntryBar.tsx:89,104` are comments only. `apps/pos/src` has zero `placeholderData` hits and is outside the scanner root anyway (`SRC_ROOT` = `apps/web/src`, `tools/audit-tanstack-keys.mjs:47`) — no POS false positives, and no POS coverage (folded into MAJOR-A's scope note).

**Cross-cutting conventions 09/10/11:** no catalogue entity, no table, no unique key, no new user-facing flow, no new noun beyond one shared hook with no parallel surface. Not applicable; nothing owed.

---

## 4. Commands and outputs (all re-run by this gate, nothing accepted as reported)

```
$ cd <worktree>/apps/web && pnpm audit:keys
[sweep-progress] Gate C — …: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
exit 0
```

```
$ pnpm vitest run src/features/treasury src/features/inventory src/features/pos \
    src/hooks/__tests__/usePlaceholderScopeGuard.test.tsx tools/__tests__
 Test Files  155 passed (155)
      Tests  1272 passed (1272)
```

```
$ pnpm typecheck            # tsc --noEmit
exit 0
```

**Detector falsification (mine, not the lane's).** `sed 's/usePlaceholderScopeGuard/useRenamedScopeGuard/g' src/features/inventory/StockMovementsPage.tsx`:

```
[sweep-progress] Gate C — …: 1
  src/features/inventory/StockMovementsPage.tsx:204:5 useQuery({ queryKey: locationScopedKey([...]), placeholderData })
  renders the PREVIOUS tenant/company payload after a scope switch: … (factory=useQuery, symbol=StockMovementsPage)
EXIT_CODE=1
# restored -> git status clean; EXIT_CODE_CLEAN=0
```

**Guard falsification (mine).** `usePlaceholderScopeGuard.ts:88` → `return false`:

```
 × usePlaceholderScopeGuard > flags a placeholder that predates a company change
 × … predates a change in an additional scope dimension
 × … never records a scope from a query that has no data
 × … clears once the new scope settles, and re-flags on a further change
 × … flags a placeholder on the way BACK to a previously-seen company
 × PaymentListPage    > renders no company-one row while company two is still loading
 × PaymentListPage    > renders no previous-tenant row while the new tenant is still loading
 × StockMovementsPage > renders no company-one movement while company two is still loading
 × StockMovementsPage > renders no previous-tenant movement while the new tenant is still loading
 × StockMovementsPage > renders no location-one movement while location two is still loading
 ✓ usePlaceholderScopeGuard > passes a placeholder through when the scope has not changed (the paging case)
 ✓ PaymentListPage    > restarts traversal at page one when the company changes, without requesting the stale offset
 ✓ PaymentListPage    > keeps the previous page visible while the next page of the SAME company loads
 ✓ StockMovementsPage > restarts traversal at page one when the company changes, without requesting the stale offset
 ✓ StockMovementsPage > keeps the previous page visible while the next page of the SAME company loads
 Test Files  3 failed (3)   Tests  10 failed | 5 passed (15)
# restored -> git status clean
```

Exactly the five page-level leak tests red, all four paging positive controls green. Non-vacuous.

**Detector false-negative probe (mine),** via the exported `scanCode`:

```
A: approved-identifier scope + placeholderData (no guard) => findings: 0
B: comment merely MENTIONING the guard name suppresses    => findings: 0
C: useQueries entry with scoped key + placeholderData      => findings: 0
D: two scoped reads, only ONE guarded                      => findings: 0
E: key via variable (const key = tenantScopedKey)          => findings: 1  (fails closed — correct)
```

**Lint, per file, base-vs-HEAD measured in place** (`git show 9c28b430a:<path> > <path>`, `eslint -f json`, `git checkout -- <path>`, `git status --porcelain` clean both times):

| File | base | HEAD | rule-id multiset |
|---|---|---|---|
| `src/features/inventory/StockMovementsPage.tsx` | 0E 0W | 0E 0W | identical |
| `src/features/pos/hooks/useDiscountPreview.ts` | 0E 2W | 0E 2W | identical |
| `src/features/pos/organisms/TransactionCart/TransactionCart.test.tsx` | 0E 1W | 0E 1W | identical |
| `src/features/pos/organisms/TransactionCart/TransactionCart.tsx` | 0E 4W | 0E 4W | identical |
| `src/features/pos/pages/POSPage/POSPage.tsx` | 0E 9W | 0E 9W | identical |
| `src/features/treasury/PaymentListPage.tsx` | 0E 5W | 0E 5W | identical |
| `tools/audit-tanstack-keys.mjs` | 0E 0W | 0E 0W | identical |
| 6 new files (guard, guard test, 3 scope tests, scanner test) | — | 0E 0W | — |
| **TOTAL** | **0E 21W** | **0E 21W** | **0 new warnings** |

Confirms the lane's claim exactly.

## 5. Merge-tree

`git merge-tree --write-tree dev lane/rh-placeholder-data-audit` (dev `f57d6b307`) → exit 0, single tree OID `127acdfd77adbf2f2382392f06bb369aa6ac0f8e`, **no conflicts** — the T12/T6/T14-pending treasury/documents changes on dev do not collide (`DocumentLineEditor.tsx` carries a comment-only mention of `placeholderData`, no property).

## 6. Promotion-owed

**Browser checks are owed at promotion, not here.** No stack was brought up for this gate. The three passes to run: (a) `/treasury/payments` and (b) `/inventory/stock-movements` — switch company from page 2+ and confirm the table goes to skeleton (not stale rows), the pagination bar disappears, and the list lands on page 1; on stock-movements also switch **location** and confirm the same; (c) the POS applied-discounts skeleton — only once `POSPage` is actually routed (MINOR-F), otherwise it is unreachable. Add a check that the PageHeader subtitle does not read a hard "0" during the switch (MINOR-C).
