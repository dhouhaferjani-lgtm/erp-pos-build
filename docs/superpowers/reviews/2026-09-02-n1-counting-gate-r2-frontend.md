# N-1 (counting) — adversarial gate r2 (condition check), FRONTEND (`apps/web`)

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n-inventory-mobile`
- Branch `test/N-inventory-mobile`, reviewed commit `24e06d507` (web fix round) on top of `34172a675` / `a43f70f04`; base `dev` `3615cab8f`
- r1 review: `docs/superpowers/reviews/2026-09-02-n1-counting-gate-r1-frontend.md`
- Reviewer: adversarial FE-conventions gate. Nothing modified.

## Verdict: **MERGE-WITH-CONDITIONS**

All five r1 conditions are **CLOSED**. One condition remains, created by this fix round itself:

1. **NEW-1 (MINOR, baseline honesty)** — re-pin the ONE design-system baseline entry whose literal this diff rewrote (`tools/audit-design-system-baseline.json:134`). Entry-level edit only; no `--write-baseline` sweep.

MINOR NEW-2 / NEW-3 are reviewer's discretion.

## Condition-by-condition

### IMPORTANT-1 — **CLOSED**

Single predicate, four consumers, and the set is provably the complement of the unenforced scopes.

- `apps/web/src/features/inventory-counting/blockSales.ts:21-27` — `BLOCK_SALES_ENFORCED_SCOPES = ['location','full_inventory','product_location']`, `isBlockSalesEnforced(scope|undefined)`; the docblock (`:3-19`) cites the backend query and the "no late-sale flag either" consequence.
- Exported at `index.ts:5` (`export * from './blockSales'`).
- **Wizard toggle** `pages/CreateCountingPage.tsx:683-684` — `checked={isBlockSalesEnforced(...) && !!data.block_sales}`, `disabled={!isBlockSalesEnforced(...)}`.
- **Hints** `pages/CreateCountingPage.tsx:695-699` — `zone` keeps `blockSalesZoneDisabledHint` (it explains node-count timestamp reconciliation), `product`/`category` get the new generic `blockSalesScopeDisabledHint`, enforced scopes keep `blockSalesHelp`. Implementer's call, and it is the right one: the zone copy carries information the generic string loses, and both keys exist in all three bundles (verified below).
- **Submit coercion** `pages/CreateCountingPage.tsx:143` — `block_sales: isBlockSalesEnforced(formData.scope_type) && !!formData.block_sales`.
- **Review row** `pages/CreateCountingPage.tsx:901` — same predicate, so review == payload by construction.
- **Detail row** `pages/CountingDetailPage.tsx:305-311` — `counting.block_sales && isBlockSalesEnforced(counting.scope_type)` → `salesModeBlockedWithWindow`, else `salesModeLive`. This also covers rows persisted before the API rule existed.

**Set matches the enforcement engine.** `CountingBlockService::activeBlockFor` filters `whereIn('scope_type', [Location, FullInventory, ProductLocation])` (`apps/api/app/Modules/Inventory/Application/Services/CountingBlockService.php:72-80`) and `scopeCoversLocation` returns `default => false` (`:147-161`). `CountingScopeType` has exactly six cases (`apps/api/app/Modules/Inventory/Domain/Enums/CountingScopeType.php:9-14`) and the FE union has the same six (`types.ts:8-14`), so the FE predicate's complement is exactly `{product, category, zone}` — no scope is unclassified.

**Matches the new API validation.** `CreateCountingRequest.php:117-123` rejects `block_sales:true` for `Product`/`Category`; `:162-163` still rejects it for `zone`. So FE-disabled ≡ API-refused ≡ engine-unenforced, all three sets identical. Single write path confirmed: the only persistence of the column is `InventoryCountingService.php:90` from validated data (grep of `block_sales` across `apps/api/app` shows no update/draft path — `CreateDraftCountingRequest` still exposes no such field), so no second writer can falsify the FE claim.

**Test coverage — present and non-vacuous.**
- `__tests__/CountingDetailSalesMode.test.tsx:132-149` — `it.each(['category','product','zone'])` asserts the row shows `salesModeLive:{"minutes":15}` **and** `expect(row.textContent).not.toContain('counting.detail.salesModeBlocked')`. Because the echo mock renders raw keys and `salesModeBlockedWithWindow` contains `salesModeBlocked` as a prefix, this guard also catches the with-window variant — the negative assertion is real, not decorative.
- `:151-166` — `it.each(['location','full_inventory','product_location'])` asserts `salesModeBlockedWithWindow:{"minutes":15}`. Fixture default `scope_type: 'location'`, `block_sales: true` (`:41-56`) with per-case overrides.
- `__tests__/CreateCountingZoneScope.test.tsx:234-253` — `product`/`category` toggle `toBeDisabled()`, `not.toBeChecked()`, generic hint rendered; `:255-265` — `location` toggle `toBeEnabled()`.

### IMPORTANT-2 — **CLOSED** (one residual weakness, see NEW-2)

`__tests__/CreateCountingZoneScope.test.tsx:299-326`: `location` scope → select location → **tick the toggle** → `clear` + `type('30')` into the window → assignment → review; asserts `review-block-sales` = `yes`, `review-ambiguity-window` = `30`, then submits and asserts `mockMutate.mock.calls[0][0]` has `block_sales === true` and `ambiguity_window_minutes === 30` (number, so the input's numeric coercion is pinned too). Both r1 escape hatches are gone: hardcoding `t('no')` at `:901` or `DEFAULT_AMBIGUITY_WINDOW_MINUTES` at `:907` now fails.

**Mocks are not vacuous.** They are faithful to the page's real props and the real component contracts:
- `LineItemEntryBar` mock (`:47-53`) destructures `onAddProduct` — the page passes exactly that (`CreateCountingPage.tsx:407-410`) and the real component's signature is `onAddProduct(product, meta)` (`components/molecules/line-items/LineItemEntryBar.tsx:29`); the page handler only reads `product.id` (`:344-348`), so the one-arg mock exercises the same state transition.
- `CategorySelector` mock (`:67-72`) calls `onChange([7])`; the real prop is `onChange: (ids: number[]) => void` (`features/categories/components/CategorySelector.tsx:16-17`) and the page maps to strings (`CreateCountingPage.tsx:456-465`).
- Because the wizard only advances when the selection step is satisfied, a broken mock would leave the test on the selection step and `getByRole('checkbox', …)` would throw — the step-advance is itself an assertion.
- `mockMutate` is a `vi.fn` returned by the mocked `useCreateCounting` (`:29-35`) with `vi.clearAllMocks()` in `beforeEach` (`:153`), so `calls[0][0]` is this test's payload.

### MINOR-1 — **CLOSED**
`pages/CountingDetailPage.tsx:299` — `className="font-medium text-end"` (was `text-right`).

### MINOR-2 — **CLOSED**
`salesModeBlockedWithWindow` present in all three bundles with the `{{minutes}}` placeholder (`locales/en/inventory.json:779`, `fr/inventory.json:779`, `ar/inventory.json:184`; the hints at `en/fr:749`, `ar:154`), rendered in the blocked branch (`CountingDetailPage.tsx:306-308`), and pinned by two tests (`CountingDetailSalesMode.test.tsx:111-125`, window `5` and `15`). Detail and wizard-review now agree, and `window = 0` stays visible.

### MINOR-3 — **CLOSED**
`types.ts:84-93` — comment corrected ("the KEY is always present, it is its VALUE that is decided at item generation") plus an explicit "never write a `??` fallback" warning. Field left optional with the reason stated.

### i18n / tokens / types — **CLEAN**
- Machine-checked every new and adjacent key across `en|fr|ar`: `counting.create.blockSalesScopeDisabledHint`, `counting.create.blockSalesZoneDisabledHint`, `counting.detail.salesModeBlockedWithWindow`, `counting.detail.salesModeLive`, `counting.detail.salesModeBlocked` — all present in all three, and placeholder sets are identical per key (`["minutes"]` for the two window strings, `[]` for the hints). Each new key appears exactly once per file (no duplicate-key shadowing).
- No hardcoded user-facing string in the diff; every rendered string goes through `t()`.
- No new colour class: the added markup uses `colorTokens.text.subtle` / `textColors.tertiary` (`CountingDetailPage.tsx:296-297`, `CreateCountingPage.tsx:694`) and pure layout utilities (`text-end`, `ms-6`, `me-2`, `disabled:opacity-50`). **No interpolated variant prefix or opacity modifier on a token** anywhere in the diff — I grepped the added lines for `` ${``-inside-variant patterns; none.
- No hand-rolled type duplication: `blockSales.ts` imports the existing `CountingScopeType` and adds no new noun/interface; the predicate is one surface consumed by all four call sites (one-surface-per-concept satisfied for this rule).
- Owner rules: no new competing accent/badge (plain `dd` text), no high-contrast band, no brand literal, no refund/sales blending, no expected-quantity leak (the row exposes configuration only), no orphan route. The toggle is *conditionally* disabled with an explanatory hint — that is contextual form state for a shipped feature, not an OQ-11 dead control (nothing is "coming soon"); flagging only so the owner can rule if they'd rather hide it, in which case the "sales continue and are reconciled by replay" explanation must survive elsewhere.

## Gate evidence (re-run by me in the worktree; nothing taken on report)

| Gate | Command (from `apps/web`) | Result |
|---|---|---|
| unit tests | `pnpm vitest run src/features/inventory-counting` (default pool) | **PASS** — 10 files / **68 tests**, incl. `CountingDetailSalesMode.test.tsx` (9) and `CreateCountingZoneScope.test.tsx` (10). Zombie check `ps aux \| grep -c '[n]ode (vitest'` = **0** |
| typecheck | `pnpm typecheck` | **PASS** — `tsc --noEmit`, 0 errors |
| eslint (7 changed files) | `pnpm exec eslint …` | **0 errors**, 13 warnings — all on pre-existing lines, shifted by the added import/comments: `CountingDetailPage.tsx:119,127,450` (r1: 118,126,442) and `CreateCountingPage.tsx:142,148,214,221,604,619,738,826,837,858` (r1: 139,145,211,218,601,616,729,817,828,849). `blockSales.ts`, `index.ts`, `types.ts` and both test files are warning-free |
| `audit:keys` | `node tools/audit-tanstack-keys.mjs` | 1 violation, **identical to `dev`**: `src/features/uom/hooks/useUnits.ts:53`. Lane adds no query key. Nothing new |
| `audit:quantity` | `node tools/audit-quantity-display.mjs` | **PASS** — 0 total |
| `audit:i18n:local` | `bash ../../scripts/i18n-baseline-authority.sh` | 64 residual lines — **byte-count identical to `dev`** (64). No new i18n gap |
| `audit:design-system` | `node tools/audit-design-system.mjs` | 811 violations both sides, but **795 ack / 16 new / 12 stale** vs `dev`'s **796 ack / 15 new / 11 stale** → **NEW-1** below |
| mechanism audit | `git diff --stat dev..HEAD -- apps/web/tools apps/web/src/lib apps/web/eslint.config.js` | **empty** — no baseline file, no `designTokens.ts`, no detector, no eslint config touched. No alias table, no suppression comment, no renamed-equivalent literal. Clean |

## New findings

### NEW-1 — MINOR — the diff moves the design-system ratchet backwards by one new + one stale entry
`apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:681` and `apps/web/tools/audit-design-system-baseline.json:134`.

The block-sales checkbox is a **raw `<input>`** (C2 debt) that was already acknowledged in the baseline — but the baseline keys on the exact source literal:

```
"C2|src/features/inventory-counting/pages/CreateCountingPage.tsx|<input type=\"checkbox\" checked={data.scope_type === 'zone' ? false : !!data.block_sales} disabled={data.scope_type === 'zone'} …"
```

Rewriting the predicate to `isBlockSalesEnforced(...)` changed that literal, so the audit now reports the *same* raw input as a **new** violation and the old entry as **stale**: `795/16/12` here vs `796/15/11` on `dev`, with `inventory-counting` appearing twice in the output (once as new, once as stale) where r1 measured **0** occurrences.

Failure scenario: the next lane that reads the DS gate sees a fresh `inventory-counting` "new violation" and either (a) treats N-1 as having introduced raw-form-control debt it did not introduce, or (b) clears it with a blanket `--write-baseline`, absorbing whatever genuinely-new debt is in flight at that moment. It also permanently blocks any attempt to drive `new` to 0 without an unexplained entry.

Fix directive: edit **that one baseline entry** to carry the new literal (same file, same C2 rule, same acknowledged debt — this is a re-pin, not an absorption); do not run `--write-baseline` across the file, and do not convert the checkbox to the `Input` atom in this lane (out of scope, and radios/checkboxes have no atom yet).

### NEW-2 — MINOR — the test named "coerces block_sales to false" cannot fail on removal of the coercion
`apps/web/src/features/inventory-counting/__tests__/CreateCountingZoneScope.test.tsx:328-345`.

The category path never ticks the toggle (it is disabled), so `formData.block_sales` is already `false` when `createCountingSession` runs; deleting the coercion at `pages/CreateCountingPage.tsx:143` and shipping `block_sales: !!formData.block_sales` keeps this test green.

The coercion is genuinely load-bearing on a path no test covers: the scope button writes `setFormData({ ...formData, scope_type })` (`pages/CreateCountingPage.tsx:215`) and **does not reset `block_sales`**. So: pick `location` → tick the toggle → `back` to step 1 → pick `category` → submit. `formData.block_sales` is still `true`; only line 143 prevents a payload the API now 422s (`CreateCountingRequest.php:117-123`) — i.e. a hard "Sales blocking is only available for…" error on the last click of a five-step wizard. Behaviour is correct today; it is the regression guard that is missing.

Fix directive: replace (or extend) the category case with that back-navigation sequence and assert `mockMutate.mock.calls[0][0].block_sales === false`.

### NEW-3 — MINOR (hygiene) — `counting.detail.salesModeBlocked` is now a dead key in three bundles
`locales/en/inventory.json:777`, `fr/inventory.json:777`, `ar/inventory.json:182`. After the MINOR-2 fix the only remaining occurrence in `src/` is the substring guard inside `CountingDetailSalesMode.test.tsx:149`; no `t('counting.detail.salesModeBlocked')` call site survives. `audit:i18n:local` does not detect unused keys (its count is unchanged), so this will simply rot. Fix directive: delete the key from all three bundles, or keep it deliberately and say so in the detail-page comment.

### NEW-4 — MINOR (doc drift) — the review-step comment still names `zone` as the only forced-false scope
`apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:895-898`: "Zone scope forces block_sales to false (the backend 422s otherwise)". The sibling comment at `:137-141` was updated to name all three unenforced scopes; this one was not, and it sits directly above the line that now uses `isBlockSalesEnforced`. A reader auditing why `product` shows `no` will look here and conclude the predicate is broader than intended. Fix directive: extend the comment to "zone / product / category" (one line, same file).

## Informational
- The r1 informational items still hold: `InventoryCounting` remains a hand-rolled FE interface with no generated DTO counterpart (backlog line, not a finding), and mobile drafts still cannot set `block_sales` (`CreateDraftCountingRequest` exposes no such field), so a mobile-created counting always renders the live-window copy — truthful.
- `pnpm --filter @autoerp/web lint` remains RED on `dev` itself (audit:keys + audit:design-system + audit:i18n). N-1 does not fix that; with NEW-1 unresolved it nudges one counter the wrong way. A "green lint" promotion precondition cannot be met by this lane alone.
