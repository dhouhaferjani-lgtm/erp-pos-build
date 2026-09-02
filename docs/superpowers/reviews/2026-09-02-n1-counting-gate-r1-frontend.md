# N-1 (counting) — adversarial gate r1, FRONTEND (`apps/web`)

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n-inventory-mobile`
- Branch `test/N-inventory-mobile`, reviewed commit `a43f70f04`, base `dev` `3615cab8f`
- Scope reviewed: `git diff dev..HEAD -- apps/web` (9 files, +198/-0). Backend read-only, for contract verification.
- Reviewer: adversarial FE-conventions gate. Nothing modified.

## Verdict: **MERGE-WITH-CONDITIONS**

Conditions (all cheap, all inside the files this diff already touches):
1. Fix **IMPORTANT-1** — do not print "Blocked" for scope types the block engine never blocks.
2. Fix **IMPORTANT-2** — pin the non-zone (`Yes`) branch and a non-default window in the review-step test.
3. MINORs at reviewer's discretion (M-1 `text-end`, M-2 blocked-mode window, M-3 type comment).

## Gate evidence (re-run by me, nothing taken on report)

| Gate | Command (from `apps/web` in the worktree) | Result |
|---|---|---|
| typecheck | `pnpm typecheck` | **PASS** — `tsc --noEmit`, 0 errors |
| unit tests | `pnpm vitest run src/features/inventory-counting` (default pool) | **PASS** — 10 files / **56 tests**, incl. new `CountingDetailSalesMode.test.tsx` (2) and `CreateCountingZoneScope.test.tsx` (5). No zombie workers left (`ps aux \| grep -c '[n]ode (vitest'` = 0) |
| eslint (changed files) | `pnpm exec eslint <6 changed files>` | **0 errors**, 14 warnings — every warning is on a pre-existing line (`CountingDetailPage.tsx:118,126,442`; `CreateCountingPage.tsx:139,145,211,218,601,616,729,817,828,849`; `api/__tests__/countingApi.test.ts:16`). No warning falls in the added ranges (`CountingDetailPage.tsx:290-305`, `CreateCountingPage.tsx:886-899`) |
| `audit:keys` | `node tools/audit-tanstack-keys.mjs` | exit **1**, 1 violation: `src/features/uom/hooks/useUnits.ts:53`. **PRE-EXISTING**: same command on the main `dev` checkout reproduces the identical violation; `git diff dev..HEAD -- apps/web/src/features/uom/hooks/useUnits.ts` is empty; last touched by `5edc719a9` (K-8/K-9 imports/uom lane). Not this lane. This lane adds no query keys. |
| `audit:design-system` | `node tools/audit-design-system.mjs` | exit **1** — `811 violations / 796 acknowledged / 15 new / 11 stale`. **Byte-identical numbers on `dev`**; `inventory-counting` appears **0** times in the output. This lane adds zero DS debt. |
| `audit:quantity` | `node tools/audit-quantity-display.mjs` | **PASS** — 0 total |
| `audit:i18n:local` | `bash ../../scripts/i18n-baseline-authority.sh` | exit **1** — identical output on `dev` (residual `ar\|uom\|*` + `fr\|import\|plural`). No new gap from this lane. |
| baseline honesty | `git diff dev..HEAD -- apps/web/tools apps/web/src/lib` | **empty** — no baseline file, no `designTokens.ts`, no detector touched. No `--write-baseline`, no alias table, no suppression comment. Mechanism audit clean. |

**Promotion note (not a finding against N-1):** `pnpm --filter @autoerp/web lint` is currently RED on `dev` itself (audit:keys + audit:design-system + audit:i18n all exit 1 with identical counts). N-1 neither adds nor removes that debt, but a "green lint" promotion precondition cannot be met by this lane alone.

## Claim-by-claim verification

**Claim 1 — types (`types.ts:84-90`) — VERIFIED.**
`block_sales`/`ambiguity_window_minutes` are emitted unconditionally by `InventoryCountingController::transformCounting` (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:591-593`), which feeds index/dashboard/show/create/activate/cancel/draft (`:210,211,244,274,308,396,415,480,705,930,1015`), plus the my-count payload at `:366-368`. Those lines are untouched by this branch's API diff, i.e. the payload has always carried them — no stale-cache hazard. Columns are NOT NULL with defaults (`database/migrations/tenant/2026_07_06_200003_add_live_counting_columns.php:37-40`) and cast (`Domain/InventoryCounting.php:118-121`), so non-optional `boolean`/`number` is correct. Every FE consumer of the type is fed by `transformCounting` (`api/countingApi.ts:21,55,60,65`); `my-drafts` is not typed as `InventoryCounting` in web. `tsc --noEmit` clean, and the report fixture was updated (`api/__tests__/countingApi.test.ts:80-83`).

**Claim 2 — detail row (`pages/CountingDetailPage.tsx:290-305`) — VERIFIED with one honesty defect (IMPORTANT-1).**
`data-testid="counting-sales-mode"` present; interpolation `{ minutes: counting.ambiguity_window_minutes }` matches `{{minutes}}` in all three bundles: `locales/en/inventory.json` `counting.detail.salesMode*` = "Sales during count" / "Blocked" / "Live (±{{minutes}} min window)", plus `fr` and `ar` (verified by parsing all three JSONs — keys land inside the existing `counting.detail` block, no duplicate keys). No hardcoded user-facing string. Colours: `colorTokens.text.subtle` only, where `colorTokens` is an import alias for the canonical `semanticColorTokens` (`CountingDetailPage.tsx:22`) — not an alias table, and it is the file's existing convention. No interpolated variant prefix, no `/opacity` on a token. No `StatusBadge`/accent added, so the "one main element per screen" rule is respected.
Zone copy: for zone scope the backend forbids blocking (`CreateCountingRequest.php:145-147`) and only issues POS *advisories* (`CountingBlockService::zoneAdvisoriesFor`), while the ±window still governs finalisation replay (`ApplyStockAdjustmentsOnCountingCompleted.php:89`, `CountingReplayPreviewService.php:79` — both scope-agnostic). So "Live (±N min window)" is **truthful for zone**. The misleading case is not zone — see IMPORTANT-1.

**Claim 3 — review rows (`pages/CreateCountingPage.tsx:890-899`) — VERIFIED.**
`review-block-sales` renders `data.scope_type !== 'zone' && data.block_sales`, which is boolean-equivalent to the submit coercion `formData.scope_type === 'zone' ? false : !!formData.block_sales` (`:140`). `review-ambiguity-window` renders `data.ambiguity_window_minutes ?? DEFAULT_AMBIGUITY_WINDOW_MINUTES` (`:31`, form default at `:56`, input coerces NaN→0 at `:706-711`), so it always equals the submitted value including the legitimate `0`. `dl`/`dt`/`dd` pairing in the `grid-cols-2` list is structurally correct. RHF is not used on this wizard (pre-existing local-state stepper) — untouched, no new raw form control introduced by the diff.

**Claim 4 — tests — PARTIALLY VERIFIED (see IMPORTANT-2).**
`__tests__/CountingDetailSalesMode.test.tsx:96-121` asserts rendered meaning for BOTH modes, including the interpolation payload (`'counting.detail.salesModeLive:{"minutes":20}'`), and the fixture sets `allow_unexpected_items: true` while `block_sales: false` in the second case, so a wire-crossing regression (reading the wrong boolean) fails. Mocking `../api/queries` (3 hooks) is the accepted component-test pattern here and does not weaken the assertion. The `react-i18next` echo mock (`:22-27`) means a mistyped locale key would NOT fail this test — I verified the three keys exist in en/fr/ar by hand and `audit:i18n:local` is unchanged, so no live defect; note it as a known blind spot.
The `CreateCountingZoneScope.test.tsx:219-244` addition is the weak one — IMPORTANT-2.

**Claim 5 — conventions — VERIFIED.**
No raw `<table>`, no new picker, no `PageHeader`/`StickyFormFooter` obligation triggered (rows added to existing surfaces), query keys untouched and already `tenantScopedKey(...)` (`api/queries.ts:45,56,66,76,87`), no money/quantity path touched (minutes are an integer count, correctly typed `number` with an explanatory comment at `types.ts:379-383` — `audit:quantity` 0). The `audit:keys` violation is pre-existing on `dev` (proof above). No new noun introduced, so no glossary/one-surface obligation; no catalogue table or unique key touched, so second-of-everything does not apply to the web part.

## Findings

### IMPORTANT-1 — the new "Blocked" label states a guarantee the system does not enforce for `product` and `category` scopes
`apps/web/src/features/inventory-counting/pages/CountingDetailPage.tsx:299-303` (and the same predicate at `pages/CreateCountingPage.tsx:892`).

The FE block-sales toggle is disabled **only** for `zone` (`CreateCountingPage.tsx:680-681`), and the backend rejects `block_sales:true` **only** for `zone` (`CreateCountingRequest.php:145-147`). But the enforcement engine covers three scope types only:
`CountingBlockService::activeBlockFor` filters `whereIn('scope_type', [location, full_inventory, product_location])` (`apps/api/app/Modules/Inventory/Application/Services/CountingBlockService.php:74-79`) and `scopeCoversLocation` returns `default => false` for anything else (`:147-158`). `activeBlockFor` is the sole enforcement path: `TerminalResource.php:66-67` → `apps/pos/src/lib/stock/stockGate.ts:66-69` (hard cart-ingress refusal) and `PosCoreReceiptProjection.php:1815` (late-sale flagging).

Failure scenario: a manager picks scope **Category** (offered at `CreateCountingPage.tsx:33-40`), ticks "Block sales during this count", and submits. `InventoryCountingService.php:90` persists `block_sales = true`. `activeBlockFor` returns null forever, so no terminal ever receives `active_counting_block`, the till keeps selling the counted category, and **no late-sale flag is recorded either** (flagging is gated on an active block). The manager opens the counting and this diff tells him, authoritatively, "Sales during count: **Blocked**" — and the wizard review told him "Block sales during this count: **Yes**". He counts under a false invariant and books the resulting variance as shrinkage. Same for scope `product`.

This is the owner rule "UI must not overstate system guarantees". The hole predates the diff (the toggle was already enabled for those scopes), but this diff is what converts it into an explicit post-creation claim, which is the lane's own stated purpose.

Fix directive: mirror the enforceable set on the FE — treat `block_sales` as effective only for `location | full_inventory | product_location`; render the live/window mode otherwise (and, preferably, disable the toggle for `product`/`category` with the existing hint pattern at `CreateCountingPage.tsx:688-690`). Add one detail-page test with `scope_type: 'category', block_sales: true` asserting the row does NOT claim "Blocked".

### IMPORTANT-2 — the new review-step test cannot fail on the regression it exists to prevent
`apps/web/src/features/inventory-counting/__tests__/CreateCountingZoneScope.test.tsx:219-244`.

The test drives only the **zone** path, where `review-block-sales` is forced to `no`, and never changes the ambiguity window from its default `15`. Replacing `CreateCountingPage.tsx:892` with a literal `{t('no')}` and `:898` with a literal `{DEFAULT_AMBIGUITY_WINDOW_MINUTES}` keeps this test green — and no other test in the suite asserts either testid (grep: the only `review-block-sales` / `review-ambiguity-window` references are these two lines). The stated intent in the test's own docblock ("the review must report the value that will actually be submitted") is therefore unpinned for the value that can actually vary.

Fix directive: add a non-zone case (e.g. `location` scope, toggle ticked) asserting `review-block-sales` → `yes`, and set the window input to a non-default value (e.g. `30`) before asserting `review-ambiguity-window`.

### MINOR-1 — physical `text-right` in an RTL-shipping surface
`apps/web/src/features/inventory-counting/pages/CountingDetailPage.tsx:298`. The app ships an `ar` bundle (this diff adds Arabic copy) and the same file uses logical spacing (`me-1`, `ms-6`); the codebase prefers logical `text-end` (307 occurrences vs 193 legacy `text-right`). The Arabic value "مستمرة (نافذة ±15 دقيقة)" is the longest string in this narrow sidebar `dl`, so it is the one most likely to wrap and mis-align. Fix: `text-end`.

### MINOR-2 — the ±window is hidden exactly where it still applies
`apps/web/src/features/inventory-counting/pages/CountingDetailPage.tsx:299-303` shows only "Blocked" when `block_sales` is true, yet `ambiguity_window_minutes` still drives review-time replay for blocked countings (`ApplyStockAdjustmentsOnCountingCompleted.php:89`, `CountingReplayPreviewService.php:79` — gated on status, not on `block_sales`). The wizard review (`CreateCountingPage.tsx:896-899`) *does* show the window for blocked counts, so the two surfaces disagree and an operator cannot verify post-creation what the review promised — including the meaningful `window = 0` (replay disabled) choice. Fix: render the window in both branches (e.g. "Blocked (±{{minutes}} min replay)") or add a separate row.

### MINOR-3 — inaccurate doc comment on the new optional field
`apps/web/src/features/inventory-counting/types.ts:84-89`: "`includes_zero_stock` only exists once items are generated". The column is NOT NULL with `default(false)` (`2026_07_06_200003_add_live_counting_columns.php:40`) and is emitted unconditionally at `InventoryCountingController.php:593`; it is the *value*, not the key, that is decided at item generation. Optional typing is harmless (no consumer today) but the comment will mislead the next reader into a defensive `?? ` branch. Fix the comment, or drop the `?` and make it required like the two fields beside it.

### Informational
- `InventoryCounting` is a hand-rolled FE interface with no generated DTO counterpart (`packages/shared/types/generated.d.ts` contains no `InventoryCounting*`), so extending it by hand is currently the only option and does not violate rule 7 — but the concept has no generated surface, which is worth a backlog line under one-surface-per-concept.
- Mobile/offline drafts cannot set `block_sales` (`CreateDraftCountingRequest` exposes no such field), so a mobile-created counting will always render "Live (±15 min window)" — truthful for the persisted config; flagged only so the mobile lane does not read the new row as configurable there.
- No dead control, no coming-soon disabled state, no empty ternary branch, no brand literal, no refund/sales blending, no blind-counting leak (the detail row exposes configuration, never expected quantities) introduced by this diff.
