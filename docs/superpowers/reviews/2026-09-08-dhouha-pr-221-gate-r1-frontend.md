# Gate r1 (frontend half) — PR #221 "fix(inventory): count movements carry the CNT number and link to the counting"

- Reviewer: adversarial frontend-conventions gate
- Date: 2026-09-08
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-221` (branch `gate/pr-221`, HEAD `88af6df71`, base `origin/dev 5b126daac`)
- Scope: `apps/web` ONLY (`git diff 5b126daac..HEAD -- apps/web`). `apps/api`, `.github/workflows/ci.yml` and `apps/api/tests/feature-lane-manifest.json` are covered by the separate inventory reviewer and were NOT reviewed here.

## Verdict

**MERGE-WITH-FIXES** — no Blocker, no Major. Five Minor findings, all follow-up-able; the frontend half of the fix is correct, tested on both surfaces, and adds no design-system, i18n, query-key or typing debt.

---

## Gates re-run by me (nothing accepted on report)

| Gate | Command | Result |
|---|---|---|
| Targeted vitest | `pnpm vitest run src/features/inventory src/components/molecules src/lib/entityRoutes.test.ts --reporter=dot` | **74 files / 547 tests, 0 failures** — matches the PR claim exactly. Workers killed after (`pkill -f 'node (vitest'`, 0 survivors). |
| Typecheck | `pnpm typecheck` | **0 errors** (`tsc --noEmit`, clean output). |
| ESLint (11 touched files) | `npx eslint <11 files>` | **0 errors, 3 warnings** — `EntityLink.tsx:7` (`consistent-type-definitions`), `ProductMovementsTab.tsx:93` (`no-confusing-void-expression`), `ProductMovementsTab.test.tsx:152` (`no-unsafe-type-assertion`). All three sit on lines NOT present in any diff hunk → preexisting, confirmed by hunk inspection. |
| TanStack keys | `pnpm audit:keys` | Gate C: 0 acknowledged, **0 new**, 0 stale. |
| Design system | `pnpm audit:design-system` | **802 acknowledged, 0 new, 0 stale**. |

**Baseline honesty:** `git diff 5b126daac..HEAD -- apps/web/tools/` is **empty** — neither `audit-design-system-baseline.json` nor the key baseline was touched. No `--write-baseline` absorption. Nothing in the diff aliases or re-exports tokens, and no suppression comment was added; the 0-new result is genuine, not evasion.

---

## Verified-correct (the four things I was asked to prove, with citations)

1. **Route path matches the real router.** `apps/web/src/lib/entityRoutes.ts:127` emits `/inventory/counting/${id}`; the router mounts `path="counting/:id"` → `CountingDetailPage` under the `/inventory` parent at `apps/web/src/routes/index.tsx:1340` (gated `RequirePermission moduleKey="inventory"`). Not a mislinked route, and the target is a real, reachable, gated detail page.
2. **`movementSourceLinkTypeFromSource()` is a generalisation, not a second surface.** `apps/web/src/lib/entityRoutes.ts:149-158` *delegates* to `documentRouteTypeFromSource` (`:161`) — it does not re-implement the document switch. The nine-case switch stays single-sourced, and its five pure-document callers are untouched: `features/dashboard/Dashboard.tsx:370`, `features/inventory/components/ProductDocumentsTab.tsx:277`, `features/finance/pages/JournalEntryDetailPage.tsx:41`, `features/finance/pages/JournalEntryListPage.tsx:36`, `features/documents/components/RelatedDocumentsTab.tsx:48`. Conventions/11 satisfied.
3. **EntityLink renders a real link, and degrades to plain text with no id.** `components/molecules/EntityLink.tsx:17` (union), `:51-52` (resolution), `:60-62` returns a `<span>` when `resolveEntityHref` yields null (`:24` `if (!props.id) return null`). Covered by `EntityLink.test.tsx:16` (link → `/inventory/counting/counting-1`, label = CNT number) and `EntityLink.test.tsx:25` (id `null` → no link, text preserved). The movement-level equivalent is covered at `StockMovementsPage.countingLink.test.tsx:149`.
4. **The hand-rolled duplicate type really is deleted.** `features/inventory/components/ProductMovementsTab.tsx` old lines 29-52 (`interface StockMovement` + `interface StockMovementsResponse`) are gone; the tab now type-imports the page's exported shape at `:33`. Verified in the diff, not just claimed.

**No third movements surface was missed.** Only two non-test files read `source_document_type` for movements (`StockMovementsPage.tsx:362`, `ProductMovementsTab.tsx:235`); only two files `GET /stock-movements` (`StockMovementsPage.tsx:198`, `ProductMovementsTab.tsx:96`). `features/stock-adjustments/pages/StockAdjustmentDetailPage.tsx:253-255` renders a raw `movement_id` UUID but is not a reference-link surface (preexisting, out of scope). `src/types/document.ts:121` and `features/documents/components/DocumentHeader.tsx:111` are the *documents* concept and are unaffected by the backend overloading `source_document_type` on the movements endpoint.

**Convention sweep:** no hardcoded Tailwind colour added (the touched cells use `textColors.tertiary` / `cn(...)`; EntityLink uses `textColors.brand`); no new user-facing literal (the link label is data — the CNT number — and the column header stays `t('products.movementsTab.columns.reference')` at `StockMovementsPage.tsx:358`); no query key added or changed (`audit:keys` 0 new); no `any` and no type assertion introduced anywhere in the diff; no raw form control, no new `<table>` (the tab's raw `thead/tbody` inside `DataTable` at `ProductMovementsTab.tsx:204-294` is preexisting and untouched).

**Rule 7 / conventions-04:** there is **no generated `StockMovement` DTO** — `packages/shared/types/generated.d.ts` contains only `StockMovementReferenceType` (`:3192`). The controller emits an ad-hoc array, not a `#[TypeScript]`-tagged DTO, so the hand-rolled FE type is not shadowing a generated one. Rule 7 is not breached; see F-2 below for the one typing win available today.

---

## Findings

### Minor

**F-1 — the wire type is published from a lazy route module instead of the module's declared types file (her deferred F-4; safe today, but the deferral is unnecessary).**
`apps/web/src/features/inventory/StockMovementsPage.tsx:42` and `:73` export `StockMovement` / `StockMovementsResponse`; `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:33` imports them from that page module.
*It is NOT a bundle or circular-import hazard*: `apps/web/tsconfig.json:13` sets `"verbatimModuleSyntax": true`, so the `import type` statement is erased at compile — no runtime edge, no chunk pulled — and `StockMovementsPage.tsx` imports nothing from `ProductMovementsTab.tsx`, so no cycle exists. The finding is placement: `apps/web/src/features/inventory/types.ts` already exists as this module's types home (its header reads "Inventory feature types — re-exports from generated backend DTOs") and already holds the exact sibling concept, `StockLevel` / `StockLevelsResponse` (`:14`, `:16`), which is the flow `docs/conventions/04-FRONTEND-TYPES.md` prescribes.
**Fix:** move both interfaces to `apps/web/src/features/inventory/types.ts` and re-export them from `StockMovementsPage.tsx` so the four existing test imports keep working; drop the page-module import from the tab.

**F-2 — `reference_type` is typed `string | null` beside a generated union that would type it exactly.**
`apps/web/src/features/inventory/StockMovementsPage.tsx:57` declares `reference_type: string | null` while `packages/shared/types/generated.d.ts:3192` already exports `StockMovementReferenceType = 'Document' | 'inventory_counting' | 'pos_receipt_return_scrap' | 'stock_adjustment' | 'supplier_goods_return_note' | 'batch_ledger_repair'`. Typing it would also make the `'inventory_counting'` literal at `entityRoutes.ts:151` checkable against the backend enum instead of being a free-floating string.
**Fix:** re-export the generated union through `features/inventory/types.ts` (as `types.ts:14` already does for `StockLevel`) and use it for `reference_type`. Note `source_document_type` legitimately cannot take that union today — it now carries `DocumentType ∪ 'inventory_counting'`, which is her deferred backend F-3.

**F-3 — `reference_id` is now REQUIRED on the wire type but read by zero frontend code.**
`apps/web/src/features/inventory/StockMovementsPage.tsx:59`. Grep across `apps/web/src` shows `reference_id` only in that declaration, in six fixtures (`StockMovementsPage.test.tsx:84`, `.companyScope.test.tsx:87`, `.reverseWriteOff.test.tsx:114`, `.countingLink.test.tsx:89,154,174`, `ProductMovementsTab.test.tsx:341`) and in unrelated features (`features/pos/api/discountApi.ts:28`, `features/batches/types.ts:98`). Required-ness therefore buys fixture churn and no covered behaviour — the link uses `source_document_id`, not `reference_id`.
**Fix:** keep the field but state in its docblock that it is contract documentation with no consumer yet, or make it optional until a surface reads it.

**F-4 — two operator-visible label forms now share one column.**
The replay path writes the bare `CNT-2026-0010` while the legacy path deliberately keeps `COUNTING:CNT-2026-0010` (PR body: byte-for-byte preserved as the legacy idempotency key). Both are rendered verbatim as the *link label* by `StockMovementsPage.tsx:369,377` and `ProductMovementsTab.tsx:277,285`, so the same table can show the same counting under two spellings.
**Fix (frontend follow-up):** strip a leading `COUNTING:` when composing the label so the reference column reads one way, or normalise server-side in a later slice.

**F-5 — the second surface lacks the negative.**
`ProductMovementsTab` has the counting-link test (`components/__tests__/ProductMovementsTab.test.tsx:323`) and preexisting document/non-document tests (`:202`, `:253`), but no counting-row-without-resolved-source case; that negative exists only on the first surface (`StockMovementsPage.countingLink.test.tsx:149`). Given the whole point of this PR is that the two surfaces drifted, the tab should carry the same pair.
**Fix:** add a row with `reference_type: 'inventory_counting'`, `source_document_id: null`, `source_document_type: null` to the tab test and assert plain text, no link.

### Informational (no action required in this PR)

- The link is one-way: a movement now reaches its counting, but `features/inventory-counting/pages/CountingDetailPage.tsx` has no "view the stock movements this counting produced" link. Operator search on `reference` covers it for now; a symmetric cross-link is a reasonable later slice.
- Neither "Stock movement" nor "Inventory counting" has its own row in `docs/glossary.md` (only mentions inside other rows, e.g. `:41`, `:44`). This PR introduces no new domain noun — `MovementSourceLinkType` is a frontend-internal shape — so conventions/11 is not breached here; the missing rows are preexisting debt.

---

## Owner-ruled UI principles

Checked, nothing to flag: no added colour, badge or accent competing with the screen's primary element (the change is a link where inert text stood); no new decorative band; no disabled/coming-soon control; no brand string in copy; no blending of refunds into sales; no blind-counting exposure (the movements list shows *applied* quantities after finalization, not expected counts pre-count); no gate weakened — the link target is behind `RequirePermission moduleKey="inventory"` (`routes/index.tsx:1340`); no overstated guarantee in copy.

---

# r2 delta re-gate (frontend) — HEAD `3d463890b`

Scope of this section: **only** `git diff 88af6df71..3d463890b -- apps/web` (4 files, +96/−47). The intervening commit `a3b443aab` is CI/manifest comments and was not reviewed. Commit under review: `3d463890b refactor(web): movement wire type lives in features/inventory/types.ts; reference_type uses the generated union; ProductMovementsTab plain-text negative`.

## Verdict: **MERGE**

All three fixes land as directed; nothing else changed; no new debt.

### (a) F-1 — wire type moved. RESOLVED.
`apps/web/src/features/inventory/types.ts:29` now declares `StockMovement` and `:61` `StockMovementsResponse`, alongside the sibling `StockLevel` (`:14`) — the module's declared types home per `docs/conventions/04-FRONTEND-TYPES.md`. `StockMovementsPage.tsx:34` type-imports them and `:40` re-exports (`export type { StockMovement, StockMovementsResponse }`), so the four existing test importers keep working. `ProductMovementsTab.tsx:33` now type-imports from `'../types'` — the component no longer reaches into a lazy route module. The 47 deleted lines in the page are exactly the two moved interfaces; the moved text is byte-equivalent apart from the `reference_type` retyping in (b). No behavioural change.

### (b) F-2 — `reference_type` uses the generated union. RESOLVED, and it is the real generated global.
`types.ts:21` aliases `App.Shared.Domain.Enums.StockMovementReferenceType`, and `:44` types `reference_type: StockMovementReferenceType | null`. **Mechanism-audited, not taken on trust:** that namespace really exists in the generated file — `packages/shared/types/generated.d.ts:3189` `declare namespace App.Shared.Domain.Enums {` containing `:3192 export type StockMovementReferenceType = 'Document' | 'inventory_counting' | …`. It is an ambient re-export, **not** a hand-copied literal union, so a backend enum change now breaks the frontend at compile — which was the point of the finding. The pre-existing narrowing at `StockMovementsPage.tsx:84-85` (`movement.reference_type === null || !(… as readonly string[]).includes(movement.reference_type)`) still typechecks because the null branch guards `.includes`, which no longer accepts `null` under the tighter type.

### (c) F-5 — second-surface negative added. RESOLVED.
`features/inventory/components/__tests__/ProductMovementsTab.test.tsx:361` `renders a counting movement as plain text when no source document is resolved` — fixture `reference_type: 'inventory_counting'`, `reference_id: null`, `source_document_id: null`, `source_document_type: null`; asserts `findByText('COUNT_REPLAY')` present **and** `queryByRole('link', { name: 'COUNT_REPLAY' })` absent. That is the exact mirror of `StockMovementsPage.countingLink.test.tsx:149`, so both movements surfaces now carry the same positive/negative pair.

### (d) Gates re-run at `3d463890b`

- **`pnpm typecheck` → 0 errors.** Notable: the union retyping did not break the six fixtures or the `NON_REVERSIBLE_REFERENCE_TYPES` comparison.
- **ESLint** on the four delta files → **0 errors**, 2 warnings, both the same preexisting ones already documented at r1 (`ProductMovementsTab.tsx:93`, `ProductMovementsTab.test.tsx:152`); the new `types.ts` is clean.
- **`pnpm vitest run src/features/inventory src/components/molecules src/lib/entityRoutes.test.ts`** — ran it **three times**. Best result `1 failed | 73 passed (74 files)`, `2 failed | 546 passed (548 tests)`; worst `7 failed | 67 passed`. **Every failure is load-induced flake in files this PR never touches, and I verified that rather than asserting it:**
  - The residual pair is `features/inventory/__tests__/ProductForm.serverValidation.test.tsx` (`DEV-QA-026`, `DEV-QA-056`), both `Error: Test timed out in 5000ms.` That file appears in **no commit of the PR** (`git diff --name-only 5b126daac..3d463890b | grep -i ProductForm` → empty) and contains no reference to `StockMovement` or `features/inventory/types`. Run in isolation it is **2 passed** (18.8s — i.e. it sits right on the 5s-per-test boundary even unloaded, so it is inherently timeout-fragile).
  - The wider run's extra failures (`PartnerPicker.test.tsx`, `ProductFormInvalidSubmit`, `ProductFormCreateModeBuffer`, `ProductForm.opening`, `InventoryHubPage`) are all timeouts in untouched files, in a run that took 204s against the 62s the identical command took at r1.
  - **Every file the PR touches passes in isolation:** `ProductMovementsTab.test.tsx` 14/14, `StockMovementsPage.countingLink.test.tsx` + `PartnerPicker.test.tsx` 16/16 together.
  - Machine state at r2: concurrent Codex/computer-use node processes were resident (93 node PIDs), consistent with the known swap-pressure flake class. **Honest statement of evidence: I did not obtain a fully green 74/74 run of that exact command at r2 on this machine**, whereas I did at r1 (74 files / 547 tests). I attribute the delta to machine load, on the evidence above — not to `3d463890b`. Anyone wanting a clean number should re-run the command on an idle machine or in CI.

### Findings still open (both Minor, both non-gating, carried from r1)

- **F-3** — `types.ts:46` `reference_id` remains required on the wire type with zero frontend consumers (declaration + 7 fixtures only). Follow-up: document it as contract-only or drop it until read.
- **F-4** — the two operator-visible label forms (`CNT-2026-0010` vs legacy `COUNTING:CNT-2026-0010`) still both render as link labels on both surfaces. Follow-up slice.
- **New nit (no action required):** `StockMovementsPage.tsx:40`'s `export type { … }` is a compatibility shim for the four page test files that still import the types from `./StockMovementsPage` (`StockMovementsPage.test.tsx:3`, `.companyScope.test.tsx:15`, `.countingLink.test.tsx:12`, `.reverseWriteOff.test.tsx:14`). Production code no longer uses it. Point those imports at `./types` and delete the shim whenever those files are next touched.

**Final frontend verdict: MERGE.** No Blocker, no Major, at either round. Not merged by me — gate only.
