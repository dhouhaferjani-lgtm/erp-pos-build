# Independent FE-conventions merge gate — Request Hygiene Phase A, Task 7 (S-6 partial)

- Date: 2026-09-04
- Reviewer: independent frontend-conventions gate (orchestrator-commissioned; NOT the lane's own reviewer)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t7`, branch `lane/rh-t7-stock-dedupe`
- Range reviewed: `f85b7c0e9..62c4d0810` (5 commits: `7a41bc2d5`, `7f80e1b0f`, `613a3a8db`, `4202571bd`, `62c4d0810`)
- Target: `dev` = `5e1e54f696e6e8f825d64b24918745bd955ce2f8`
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 7`
- Handback re-verified, not trusted: `docs/handoff/HANDBACK-request-hygiene-T7-2026-09-04.md`
- No lane gate reports exist under `docs/superpowers/reviews/` matching `t7` in this worktree (`ls | grep -i t7` returned nothing); the r1/r2 gate content is embedded in the handback's fix-round sections, which I treated as claims and re-ran.

---

## VERDICT: MERGE

No BLOCKER. No MAJOR. Three MINOR / non-blocking notes below. The single promotion-owed item (browser network panel) is genuine and is recorded, but every arm of the plan's Step 5 network criterion is now asserted in CI, so it is confirmatory only.

---

## 1. Blocking findings

**None.**

---

## 2. Non-blocking findings

### MINOR-1 — disabled-state fallthrough can render a false "0 / Exceeds source availability"
`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:419-437`

The new gate adds `tenantId !== null && companyId !== null` to enablement (`apps/web/src/features/products/api/useProductStockLevels.ts:53-57`). When the query is *disabled*, TanStack v5 reports `isLoading === false` and `data === undefined`, so `AvailabilityCell` skips its loading branch (`:427`) and falls through to `const available = … ?? '0'` (`:435`) and `exceeds = bccomp(line.quantity, '0') > 0` (`:436`) — i.e. a hard "0" plus a red **Exceeds source availability** warning rather than the loading placeholder. Base code had no scope gate here, so this path is new.

Reachability is narrow: `currentCompanyId` is persisted (`src/stores/companyStore.ts:165,180-182`), so the null window is first-login-before-companies-resolve and the known CompanyProvider-reset class (F-BUG-1). Not worth holding the merge, and the plan mandated the gate.

Fix directive (follow-up lane): in `AvailabilityCell`, treat "gated off" as loading — e.g. render the loading placeholder when `stockQuery.fetchStatus === 'idle' && !stockQuery.isSuccess`, before the `available ?? '0'` fallback.

### MINOR-2 — `waitFor(() => expect(...).toEqual([...]))` is a first-success assertion, not a steady-state bound
`apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx:147-163`

Both two-URL assertions are inside `waitFor`, which stops at the first passing tick; a *later* stray request would not fail them. The lane already recorded this as r2 INFORMATIONAL, and the third-row arm (`:172-176`) is a bare `expect` that does bound the steady state at the end of the test, so the suite as a whole is not blind. Fix directive (optional): after the final bare `expect`, add one `await act(async () => {})` flush (or a short `waitFor` on an unrelated stable condition) before re-asserting the URL list.

### MINOR-3 — pre-existing quantity-display debt on this surface, unchanged and re-confirmed
`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:441` renders the raw scale-4 `available` string; `apps/web/src/features/stock-transfers/components/TransferSourceSuggestion.tsx:26` uses the deprecated locale `formatQuantity` from `@/lib/format` (2 of the 5 surviving eslint warnings on that file) instead of the unit-precision `formatQuantity` from `@/lib/decimal` + `getQuantityDecimals`. Both lines are byte-identical to base — the lane did not touch them and `pnpm audit:quantity` reports 0. Recorded so it is not re-discovered as new; fix directive belongs to a dedicated transfer-surface precision lane.

---

## 3. What held up (item by item against the commission)

### 3.1 Hook shape, gating, typing, quantities — HOLDS
`apps/web/src/features/products/api/useProductStockLevels.ts:44-59`:
- Key `tenantScopedKey(['stock-levels', 'product', productId, variantId])` (`:51`) — exactly the plan's shape (plan Step 2 snippet). Root literal is `stock-levels` at index 0.
- `enabled: requestedEnabled && productId !== '' && tenantId !== null && companyId !== null` (`:53-57`), with tenant/company read through **selector hooks** (`:48-49`) so bootstrap completion rerenders the hook — `tenantScopedKey` alone uses `getState()` (`src/lib/tenantScopedKey.ts:33-35`) and cannot.
- No `any`; `pnpm typecheck` exit 0; eslint 0 errors / 0 warnings on the new file.
- Quantities never parsed: `getProductStock` returns `ProductStockResponse` with string `available` (`src/features/products/api/productStock.ts:9-16`, DTO `packages/shared/types/generated.d.ts:1197-1214`); `AvailabilityCell` compares with `bccomp` (`CreateStockTransferPage.tsx:436`). Rule 19 respected.
- `staleTime: 15_000` is a **tightening**: global default is `staleTime: 300_000` (`src/lib/queryClient.ts`), which the old `AvailabilityCell` inherited. Handback's MINOR-5 correction verified.

### 3.2 Both consumers use the hook; nothing else fetches the endpoint in those files — HOLDS
`CreateStockTransferPage.tsx:419` and `TransferSourceSuggestion.tsx:15`. Grep for `stock-levels|apiGet` across both files returns **nothing else** (the `apiGet` import was removed, D3). Both are mounted in the same DataTable cell for the same row/product/variant (`CreateStockTransferPage.tsx:783-795`), so the dedupe is real on a live row, not theoretical.

### 3.3 Invalidation compatibility — HOLDS (verified against each matcher's source, not the handback table)
New key materialises as `['stock-levels', 'product', <productId>, <variantId|null>, <tenantId>, <companyId>]` (length 6).

| Consumer | Matcher (source) | Matches new key? |
|---|---|---|
| `useCreateStockTransfer` | `features/stock-transfers/api/queries.ts:37` `invalidateQueries({ queryKey: ['stock-levels'] })` | YES — TanStack v5 partial prefix match; suffix segments are irrelevant |
| `useCompleteStockTransfer` | `queries.ts:50` same | YES |
| `useCancelStockTransfer` | `queries.ts:64` same | YES |
| Goods receipt | `features/purchases/GoodsReceiptListPage.tsx:282` `scopedNamespacePredicate('stock-levels', tenantId, companyId)` (definition `k.length >= 3 && k[0] === ns && k[k.length-2] === tenantId && k[k.length-1] === companyId`) | YES — length 6, root at [0], tenant/company are the last two |
| `useCreateTransferAction` | `features/replenishment/api/queries.ts:17` `['stock-transfers','stock-levels']` namespaces | YES (same prefix mechanism) |
| Inventory predicate | `features/inventory/_invalidation.ts:23-37` `stockLevelsInvalidationPredicate` — root at [0] + tenant/company suffixes, **no positional assumption on [1]** | YES — inserting the `'product'` discriminator at index 1 breaks nothing |

Note for the record: the docstring at `features/stock-adjustments/_invalidation.ts:8-11` asserts the stock-transfer hooks "invalidate `['stock-levels']` and silently match nothing". That claim is **wrong** (v5 prefix matching ignores extra trailing segments) and it is pre-existing, untouched by this lane — but it is a live trap for the next reader of these key roots.

### 3.4 The `['product-stock', …]` sibling root is pre-existing, not introduced — HOLDS
All five files carrying that root (`features/inventory/components/ProductStockLevels.tsx:68`, `features/replenishment/components/RequestContextPanel.tsx:20`, `features/replenishment/components/CreateTransferDialog.tsx:46`, `features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx:162`, invalidated only by `features/inventory/components/ThresholdEditCell.tsx:15`) are **untouched by the diff** (`git diff --stat f85b7c0e9..62c4d0810` lists 6 files, none of them). The docstring at `useProductStockLevels.ts:18-29` declares the sibling root, names all four consumers, states the two roots do not cross-invalidate, and assigns unification to B-8. Declared second surface, not a discovered one.

**One-surface-per-concept: net improvement.** Before this lane the same endpoint was reached under **three** roots (`stock-levels` for availability, `product-stock-suggestion` for the suggestion panel, `product-stock` for the other four). After, **two**. `product-stock-suggestion` also matched *no* invalidation at all, so folding the suggestion under `stock-levels` fixes a real staleness hole.

### 3.5 View scope dropped from the suggestion key — HOLDS, confirmed against the backend
Handback §9 item 3 asked the reviewer to confirm. Confirmed twice:
- Client: `apps/web/src/lib/api.ts:251-274` sends only `Authorization`, `X-Company-Id`, `Accept-Language`. No location-scope header. Request identity is (url, `variant_id`, company).
- Server: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1029-1071` (`GET products/{product}/stock-levels`, route `app/Modules/Product/routes.php:62`) filters on `tenant_id`, `company_id`, `product_id` and optional `variant_id` only. **No location/view-scope filter.** The old `locationScopedKey(..., scope)` segment fragmented the cache without varying the response.

If that endpoint ever becomes scope-filtered, the key must carry the scope again — noted for B-8.

### 3.6 Tests assert exact URLs, and they are falsifying — VERIFIED BY RE-RUNNING, not read
`stockLevelUrls()` (`availability.test.tsx:65-76`) filters `mockApiGet` calls with `/^\/products\/[^/]+\/stock-levels$/`, so the `/variants` and `/batch-stock` traffic the same interaction produces cannot inflate or mask the count. All three arms use `toEqual([...])`, not `toHaveLength`.

**Falsification A (commissioned) — drop `productId` from the key.** Edited `useProductStockLevels.ts:51` to `tenantScopedKey(['stock-levels', 'product', variantId])`, re-ran, restored from a scratch copy; `git status` clean afterwards.
```
× still issues one stock-level request per DISTINCT product (fan-out is B-8, not Task 7)
AssertionError at CreateStockTransferPage.availability.test.tsx:158
- Expected                                  + Received
  [ "/products/prod-1/stock-levels",
-   "/products/prod-2/stock-levels",         ]
 Test Files  1 failed | 1 passed (2)
      Tests  1 failed | 3 passed (4)
```
The over-deduplication guard is real.

**Falsification B (my own, answers D1) — restore BASE consumer code under the NEW harness.** Overwrote `CreateStockTransferPage.tsx` and `TransferSourceSuggestion.tsx` with `git show f85b7c0e9:…`, keeping the lane's seeded/instrumented test file, then `git checkout --` to restore:
```
× shows available stock at the source and warns when the quantity exceeds it
× still issues one stock-level request per DISTINCT product (fan-out is B-8, not Task 7)
AssertionError: expected [ …(2) ] to deeply equal [ '/products/prod-1/stock-levels' ]
+   "/products/prod-1/stock-levels"      (twice)
 Test Files  1 failed (1)   Tests  2 failed (2)
```
**D1 answered empirically: the auth/company seeding does NOT weaken the test.** Under base code the harness still reads **2** requests and both arms fail. That is expected from the code as well — base `AvailabilityCell` had no tenant/company enablement gate, so the seeding is load-bearing only for the *new* hook and cannot suppress base traffic. The seeding also makes the harness match the real runtime (the page is unreachable without a tenant + company), so it is a fidelity increase, not a gate weakening.

### 3.7 Mechanism audit (how the metric improved) — CLEAN
The 2 → 1 improvement comes from two components sharing one TanStack key, proven by exact-URL identity in CI and by falsification B showing base = 2. No traffic suppression, no alias/indirection table, no renamed-but-equivalent literal. Diff grep for `eslint-disable | @ts-ignore | @ts-expect-error | istanbul ignore | .skip | .only` on added lines: **none**. `git diff --name-only` contains **no baseline file** — `apps/web/tools/audit-design-system-baseline.json` untouched, so no `--write-baseline` absorption.

### 3.8 Conventions — HOLDS
- `pnpm audit:keys` → `Gate C … : 0`, `0 acknowledged, 0 new, 0 stale`. Clean.
- No user-facing string added (the hook renders nothing; `TransferSourceSuggestion` copy unchanged and all via `t()`); no locale file touched.
- Design tokens: no color class added or changed; `TransferSourceSuggestion` token usage byte-identical apart from the removed query.
- No new `useEffect` + set-state; no new `any`.
- D3/D4 dead-code removal is safe: `ProductStockLevelLocation` / `ProductStockLevelsResponse` were **non-exported** locals; `grep -rn "ProductStockLevelsResponse\|ProductStockLevelLocation" src/` → no matches anywhere. `apiGet` import removed with zero remaining call sites in that file. No exported type another file imports was changed.
- `variantId` truthiness: `getProductStock` gates the param on truthiness while the deleted code used `!== null`; harmless because the page normalises the empty option to `null` (`CreateStockTransferPage.tsx:487-488`) and the type is `string | null`.
- Second-of-everything (conv. 09): no catalogue table, no unique key, no migration — N/A. Owner-ruled UI principles: no visual change, no new control, no copy — N/A.

---

## 4. Commands run and outputs

All run by me in the lane worktree unless stated. Default vitest pool, no `--singleFork`. No leftover vitest workers afterwards (`ps aux | grep [v]itest` → none).

```
$ git -C .worktrees/rh-t7 log --oneline f85b7c0e9..62c4d0810
62c4d0810 test(web request-hygiene t7): assert plan Step 5 arm (a) — same product on two rows = one request
4202571bd docs(request-hygiene t7): handback fix round 1 (gate r1 response)
613a3a8db fix(web request-hygiene t7): FE gate r1 fix round — distinct-product assertion, sibling-root docstring
7f80e1b0f docs(request-hygiene t7): handback
7a41bc2d5 fix(web request-hygiene t7): share stock-level queries between transfer components (S-6 partial)

$ git -C .worktrees/rh-t7 diff --stat f85b7c0e9..62c4d0810
 6 files changed, 621 insertions(+), 27 deletions(-)   (5 src files + the handback; no baseline, no apps/api)

$ pnpm --filter @autoerp/web typecheck
> tsc --noEmit          (no output, exit 0)

$ pnpm --filter @autoerp/web exec vitest run src/features/stock-transfers src/features/products
 Test Files  37 passed (37)
      Tests  149 passed (149)          # matches the handback's claimed 37/149

$ pnpm exec eslint -f json <the 5 touched files>
src/features/products/api/useProductStockLevels.ts                          err=0 warn=0
src/features/stock-transfers/__tests__/useProductStockLevels.test.tsx       err=0 warn=0
src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx err=0 warn=1
src/features/stock-transfers/components/TransferSourceSuggestion.tsx        err=0 warn=5
src/features/stock-transfers/pages/CreateStockTransferPage.tsx              err=0 warn=1

# base copies restored in-place as *.lintbase.* , linted, deleted (git status CLEAN after)
$ pnpm exec eslint <3 base copies>
availability.lintbase.test.tsx        0 errors, 1 warning   (no-unsafe-return :24)
TransferSourceSuggestion.lintbase.tsx 0 errors, 5 warnings  (no-deprecated x2, no-confusing-void-expression x3)
CreateStockTransferPage.lintbase.tsx  0 errors, 1 warning   (no-unsafe-type-assertion :977)
✖ 7 problems (0 errors, 7 warnings)
```
**Per-file lint delta vs `f85b7c0e9`: 0 errors, 0 new warnings** (1/5/1 before → 1/5/1 after; both new files 0/0). Confirms the handback table independently.

```
$ pnpm audit:keys      → Gate C: 0 violations; baseline 0/0/0                 PASS
$ pnpm audit:quantity  → 0 total (0 baselined, 0 new, 0 stale)                PASS
$ pnpm test:eslint-rules → all RuleTester cases passed (5 rules)              PASS
$ pnpm test:tools      → Test Files 8 passed (8) / Tests 160 passed (160)     PASS
$ pnpm audit:design-system → 810 violations; 796 acknowledged, 14 new, 11 stale   FAIL (inherited)
$ pnpm audit:i18n:local    → ar|uom burn-down, exit 1                             FAIL (inherited)
$ pnpm --filter @autoerp/web lint → exit 1 at audit:design-system
```

**Inheritance of the two red audits proved, not assumed.** Same commands run in the *main checkout at `dev` 5e1e54f69*:
```
$ (dev) node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 796 acknowledged, 14 new, 11 stale baseline entries   # byte-identical to the lane
$ (dev) pnpm audit:i18n:local  → same ar|uom burn-down, exit 1
```
and object-identity of every input:
```
IDENTICAL apps/web/src/features/import/pages/ImportWizardPage.tsx        (5e1e54f69 vs 62c4d0810)
IDENTICAL apps/web/tools/audit-design-system-baseline.json
IDENTICAL apps/web/tools/audit-design-system.mjs
IDENTICAL apps/web/tools/audit-i18n-completeness.mjs
```
`pnpm --filter @autoerp/web lint` therefore cannot go green on this lane — **`dev` itself is red**. All 14 "new" design-system violations are in `src/features/import/pages/ImportWizardPage.tsx`, which this lane never touches. **Orchestrator-owed dev-hygiene item; not a T7 finding.**

### Post-merge semantic check (beyond merge-tree's textual clean)
`dev` adds `LineItemEntryBar.tsx` (T5 debounce) and `WebSocketReconnectProvider.tsx` (T8) on top of the lane's base; `LineItemEntryBar` is consumed by `CreateStockTransferPage`. I materialised that combination in the worktree (copied dev's 4 files in, ran, restored, `git status` CLEAN):
```
$ pnpm exec vitest run src/features/stock-transfers src/components/molecules/line-items src/providers
 Test Files  18 passed (18)
      Tests  63 passed (63)
```
Textual clean merge is also semantically clean on the overlapping surface.

---

## 5. merge-tree

Run read-only from the main checkout `/Users/houssamr/Projects/syneriva/apps/erp`:
```
$ git merge-tree --write-tree dev lane/rh-t7-stock-dedupe
a89236ca737eae1dab6588e1cdd532450272bc80
exit=0     # single tree OID, no conflict section, 0 CONFLICT lines
$ git rev-parse dev lane/rh-t7-stock-dedupe
5e1e54f696e6e8f825d64b24918745bd955ce2f8
62c4d08103aeedc70706a2df6fa85627bfdfe69f
```
**No conflicts.** `f85b7c0e9` is an ancestor of `dev`; the only `apps/web` deltas between them are `LineItemEntryBar.{tsx,test.tsx}` and `WebSocketReconnectProvider.{tsx,test.tsx}` — disjoint from this lane's 5 files. (Note: `CreateStockTransferPage.lineEntry.test.tsx` is *not* among dev's deltas — T5's edits landed in `LineItemEntryBar.test.tsx`; the lineEntry harness was already at the lane's base and passes both before and after the simulated merge.)

---

## 6. Promotion-owed

**The browser network-panel check (plan Step 5) was NOT performed by this gate and remains promotion-owed.** No local stack is up for this worktree. On a live stack, before promoting, confirm on `/stock-transfers/new`:
1. one row with a product selected → **1** `GET /products/{id}/stock-levels`;
2. a second row with the **same** product → **still 1**;
3. a third row with a **distinct** product → **2** total (its absence would mean over-deduplication).

It is now confirmatory only: all three arms are asserted in CI with exact URL lists (`availability.test.tsx:126`, `:157-163`, `:172-176`), and I falsified two of them by hand.

**Also promotion-owed (not this lane):** `pnpm --filter @autoerp/web lint` is red on `dev` itself — `ImportWizardPage.tsx` design-system debt needs baselining-or-fixing and the i18n baseline needs a re-pin.

## 7. Scope statement carried forward
This lane is **not** full S-6 closure. Per-distinct-product fan-out remains (one request per distinct product/variant) and is now enforced as a boundary by test, not merely documented; its removal needs the B-8 bulk endpoint. Root unification (`stock-levels` vs `product-stock`) is likewise B-8.
