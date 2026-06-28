# Round-2 Adversarial Review — Post-Save Stay-on-Record (v2)

> Codex round-2 re-review of `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md` (v2), 2026-06-27. Same Codex thread as round 1; grounded in actual code.

## Prior-Finding Verdict Table

| ID | Title | Verdict | Evidence |
|---|---|---|---|
| B1 | Core destination conflicted with actual detail/edit routes | RESOLVED | v2 changes "stay on record" to detail route `/:id`: spec:10-13, :80-99. Routes support split: `routes/index.tsx:862-880` (Product), `:2634-2656`/`:2683-2705` (Loyalty). |
| B2 | Payments in Phase 1 without canonical create outcome | RESOLVED | v2 drops Payments: spec:14, :237-239. |
| B3 | Product lifecycle UI existed while status model deferred | PARTIALLY RESOLVED | v2 adds minimal Product `status`: spec:15-16, :103-127, :221-229. No status yet in FE form/detail types, backend model/DTO, or migration. New blockers below. |
| M4 | Status-driven editability not mapped per entity | RESOLVED | Per-entity matrix; Product lock not Phase 1: spec:103-113, :237-241. |
| M5 | Guard conflicted with document autosave | PARTIALLY RESOLVED | `DirtyState` adapter added: spec:181-198. But `useDraftAutoSave` exposes no `autosaveFailed`/pending state: `useDraftAutoSave.ts:37-62,122-148,180-203`. |
| M6 | `useAfterSaveNavigation` too pure for side effects/cache | RESOLVED | Hook returns destination helpers called after side effects; fetch-on-destination default: spec:133-153. |
| M7 | SaveSplitButton API couldn't cover form patterns | PARTIALLY RESOLVED | Presentational + intent callbacks + `form`/`primaryType` + ref intent: spec:155-172. External `form=` submit mechanism still unspecified (below). |
| M8 | Split-button a11y underspecified | RESOLVED | Enumerated names/aria/focus/arrow/Escape/Tab/tokens/tests: spec:174-179, :272-273. |
| M9 | i18n keys implied not listed | RESOLVED IN SPEC / NOT IMPLEMENTED | Keys listed: spec:247-253. Locale files lack them yet. |
| M10 | Test plan missed integration paths | RESOLVED | Exact-destination tests per editor + guard/registry/a11y: spec:257-273. |
| m11 | Exceptions not executable | RESOLVED | `postSavePolicy`/`LIST_RETURN_EXCEPTIONS` registry: spec:202-215. |
| m12 | Loyalty must consume created IDs | RESOLVED IN SPEC / NOT IMPLEMENTED | spec:230-231, :268. Forms still nav to lists; APIs do return created records. |

---

## Part 2 — New Issues

### [Blocker] Finding 1: `useUnsavedChangesGuard` specced on `useBlocker`, but app is not a data router
**Concern:** v2 requires `useBlocker`; the app mounts plain `<BrowserRouter>` + `<Routes>`, not `createBrowserRouter`/`RouterProvider`. `useBlocker` is unbuildable as specced.
**Evidence:** spec:181-198; `main.tsx:4,16-23`; `routes/index.tsx:285-287`; react-router-dom 7 (`package.json:61`).
**Why:** Guard is one of three Phase-1 primitives (spec:221-223). If it can't mount, every editor adoption blocks or ships without the in-app guard.
**Recommendation:** Before implementation, choose: migrate to data router and prove `useBlocker`, or revise the guard to a `BrowserRouter`-compatible mechanism. Don't wire the guard until settled.

### [Blocker] Finding 2: Product "Save draft" as primary can silently unpublish existing products
**Concern:** If primary Save writes `status:'draft'`, a routine edit of a published product reverts it to draft (no Phase-1 lock).
**Evidence:** spec:58-60,107,120-121,229; `ProductForm.tsx:510-529`; `ProductForm.test.tsx:150-160`.
**Why:** Editing a published product to change price/SKU/tax would remove sellability state unintentionally — worse because draft hiding/locking is deferred.
**Recommendation:** Make primary Save status-preserving. Create defaults `published` unless explicit "Save draft"; update omits `status` unless an explicit lifecycle transition. Any "unpublish" is a separate explicit action.

### [Blocker] Finding 3: Product status persistence/default semantics unconfirmed and underspecified for updates
**Concern:** No status exists yet anywhere; spec doesn't require "update omission preserves current status."
**Evidence:** spec:115-119; `create_products_table.php:16-40`; `Product.php:83-157`; `ProductData.php:22-98`; Create/UpdateProductRequest `:147-260`/`:148-262`; payload omits status (`productPayload.ts:17-40`). Non-editor creates omit status (`AddQuickProductModal.tsx:111-120`, `partsCatalog.ts:100-101`, `ProductService.php:54-63,103-109`). `ProductController.php:459-478` blindly updates validated fields.
**Recommendation:** Spec exact rules: migration non-null default `published`; create defaults missing→`published`; update `sometimes` + preserve when omitted; DTO/model/request support; tests for omitted create, omitted update on published, explicit draft create, explicit publish update.

### [Blocker] Finding 4: Publish gating is frontend-only; no server-side publish guard
**Concern:** Checklist is presentational; backend has no readiness validation for `status=published`.
**Evidence:** spec:107,120-121,264-266; `BeforePublishChecklist.tsx:20-60`; `ProductForm.tsx:391-432,522-529`; Create/UpdateProductRequest have no status/readiness rules.
**Why:** Client gating is bypassable (direct API, stale clients, imports, future UI).
**Recommendation:** Server-side publish guard: if `status=published`, require the checklist's readiness fields, with tests for rejection+success; FE Publish disables on same criteria. If deferred, Publish shouldn't be a real Phase-1 transition.

### [Major] Finding 5: v2 knowingly makes drafts sellable; POS/search/list confirm blast radius
**Concern:** Sellable feeds filter on `is_active`, not status. A draft with `is_active=true` still appears in list/search/POS.
**Evidence:** spec:122-127,237-241; `ProductController.php:87-104,212-215`; `SyncController.php:66-72` (`is_active=true`); `pos/api/productApi.ts:34-44,52-54`; `Product.php:252-255` (`isAvailable()` = is_active only).
**Why:** Phase 1 introduces a user-visible "Save draft" that doesn't mean "not live" — a high-risk semantic mismatch for ERP/POS.
**Recommendation:** Remove/rename Save draft until §3 gating, OR minimal mitigation: draft also sets `is_active=false`, or POS/list/search exclude `status=draft`. If drafts stay sellable, UI copy must not imply not-live.

### [Major] Finding 6: Detail destination exists/fetches, but doesn't show lifecycle status
**Concern:** ProductDetailPage shows active/inactive, not draft/published; after Save draft/Publish the landing page won't reflect state.
**Evidence:** spec:61-63,229; `routes/index.tsx:862-870`; `ProductDetailPage.tsx:74-82,32-50,179-187`.
**Recommendation:** Add `status` to DTO/API/types; render a distinct lifecycle badge (keep `is_active` separate).

### [Major] Finding 7: SaveSplitButton menu intents don't specify external RHF form submission
**Concern:** `form?` covers the primary, but not how Save & New / Save & Close submit a header-rendered external form while preserving RHF validation.
**Evidence:** spec:155-172; `ProductForm.tsx:473-479,510-524,565-566`; `ProductForm.test.tsx:150-160`.
**Recommendation:** Specify one mechanism + test with Product's external `form=`: render menu items as `type=submit` with same `form` after setting intent ref, or call `form.requestSubmit()` after setting intent. Assert RHF validation still runs and intent survives async.

### [Major] Finding 8: Document dirtyState adapter not implementable from current autosave state
**Concern:** `useDraftAutoSave` exposes no failed/pending state; DocumentForm discards autosave errors.
**Evidence:** spec:185-198,269-270; `useDraftAutoSave.ts:37-62,140-147,180-203`; `DocumentForm.tsx:191-204`.
**Recommendation:** Extend `useDraftAutoSave` first: expose `autosavePending`, `autosaveFailed`, `lastError`. Then build the document `DirtyState` from RHF + lines + autosave status.

### [Minor] Finding 9: i18n enumerated in v2 but locale files still lack keys
**Evidence:** spec:247-253; `locales/{en,fr,ar}/common.json`.
**Recommendation:** Add all keys to EN/FR/AR before adoption; render test so missing keys fail early.

---

## Final Verdict
Implementation-ready: **no**. Must-fix before planning:
1. **Router/guard** (`main.tsx:16-23`): data-router migration + prove `useBlocker`, or revise guard to `BrowserRouter`-compatible.
2. **Product save/status** (`ProductForm.tsx:510-529`): primary Save status-preserving; draft/published only on explicit intent.
3. **Product status persistence** (`UpdateProductRequest.php`): migration default `published`; create default published; update omission preserves; DTO/model/request + tests.
4. **Publish guard** (`CreateProductRequest.php`): server-side `status=published` readiness aligned with checklist, or don't ship Publish as real this phase.
