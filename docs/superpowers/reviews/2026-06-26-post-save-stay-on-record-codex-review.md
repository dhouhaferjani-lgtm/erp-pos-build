# Adversarial Review: post-save-stay-on-record design

> Codex adversarial review of `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md`, 2026-06-26. Grounded in actual code.

## Executive Summary

The desired product behavior is directionally sound, and the audit is correct that Product, Loyalty Program, and Loyalty Member currently bounce creates back to their lists. The spec is not ready to implement as-is because it conflates "record route" with "edit mode" even though Phase-1 routes generally render detail pages at `/:id` and form editors at `/:id/edit`. Payments is also not scoped to a single save flow, and documents have a draft autosave path that is not covered by the proposed guard contract. The shared primitives need sharper API boundaries for action intent, side-effect sequencing, cache hydration, accessibility, i18n, and test fixtures before multiple editors adopt them.

## Findings

---

### [Blocker] Finding 1: The core "stay in edit mode" destination conflicts with actual routes

**Concern:** The spec says primary Save after create navigates to the new record route in edit mode, and explicitly lists Product as `create -> /inventory/products/{id} (edit)`. In the actual route table, `/inventory/products/:id` renders `ProductDetailPage`, while `/inventory/products/:id/edit` renders `ProductForm`; loyalty has the same detail-vs-edit split, and most document creates navigate to the detail route, not the form route.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:78-84`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:143-147`; `apps/web/src/routes/index.tsx:863-877`, `apps/web/src/routes/index.tsx:2635-2652`, `apps/web/src/routes/index.tsx:2684-2701`; `apps/web/src/features/inventory/ProductForm.tsx:336-367`; `apps/web/src/features/documents/DocumentForm.tsx:269-276`.

**Why it matters:** Implementers can satisfy the literal path in the spec and still land users on read-only/detail pages, which violates the stated edit-mode standard and creates inconsistent Phase-1 behavior across Product, Loyalty, and Documents.

**Recommendation:** Decide and encode the canonical destination: either change the contract to "stay on the record detail page" or change Phase-1 destinations to `/.../{id}/edit` where an editor route exists. Update the spec table, examples, tests, and `recordPath` examples before implementation starts.

---

### [Blocker] Finding 2: Payments is in Phase 1 without a defined canonical create outcome

**Concern:** The spec says to "locate the payment record/create flow; bring onto the standard," but the actual payment form has multiple intentional post-create outcomes: return to invoice, purchase order, delivery note, treasury list, or remain on the form to show allocation controls.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:146`; `apps/web/src/routes/index.tsx:1476-1498`; `apps/web/src/features/treasury/PaymentForm.tsx:337-360`, `apps/web/src/features/treasury/PaymentForm.tsx:368-378`, `apps/web/src/features/treasury/PaymentForm.tsx:725-744`.

**Why it matters:** A generic "stay on payment record" rule can regress contextual payment workflows, especially document-origin payments and the smart allocation interstitial. The spec does not say whether those are exceptions, whether the payment detail route should be used, or whether allocation should still interrupt navigation.

**Recommendation:** Remove Payments from Phase 1 or add a payment-specific decision matrix covering standalone payment, invoice-origin payment, purchase-order payment, delivery-note payment, and open-invoice allocation. Each branch needs an explicit destination and test.

---

### [Blocker] Finding 3: Product lifecycle UI is already present but the status model is deferred

**Concern:** The spec defers Product publish/state-lock work, but ProductForm already renders both "Save draft" and "Publish product" as submit buttons, with a comment saying the draft/publish split is stubbed. The Product type has `is_active` flags but no `status` or draft/published lifecycle field, so a Phase-1 SaveSplitButton migration can leave a visible Publish action that still just saves.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:68-72`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:153-155`; `apps/web/src/features/inventory/ProductForm.tsx:43-72`, `apps/web/src/features/inventory/ProductForm.tsx:473-529`.

**Why it matters:** The spec's action model says lifecycle transitions are separate because they have different consequences, but Product's current Publish button has no separate consequence. Shipping Phase 1 without resolving this will preserve a misleading primary action next to the new save control.

**Recommendation:** Add an explicit Phase-1 Product decision: either remove/rename the current Publish button until the lifecycle session, or include a minimal product lifecycle contract now. Tests should assert that Publish is not a duplicate save.

---

### [Major] Finding 4: Status-driven editability is not mapped per Phase-1 entity

**Concern:** The spec states that read-only is derived from `status`, but Phase-1 entities differ materially: Product has no lifecycle status, Loyalty Program and Member have status fields but their forms do not use them to lock editing, and DocumentForm only applies status to one purchase-order child component.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:60-72`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:88-92`; `apps/web/src/features/loyalty/types/loyalty.ts:1-5`, `apps/web/src/features/loyalty/types/loyalty.ts:12-24`, `apps/web/src/features/loyalty/types/loyalty.ts:207-220`; `apps/web/src/features/loyalty/pages/ProgramDetailPage.tsx:45-58`; `apps/web/src/features/loyalty/pages/MemberDetailPage.tsx:194-210`; `apps/web/src/features/documents/DocumentForm.tsx:494-500`.

**Why it matters:** Implementers do not have enough entity-specific rules to know when SaveSplitButton should be enabled, when lifecycle buttons should render, or when fields should be locked. Status presence alone does not define editability.

**Recommendation:** Add a Phase-1 entity matrix: status field exists, editable statuses, lifecycle actions exposed now, read-only behavior now, and deferred behavior. For entities without a status-driven lock, say that SaveSplitButton is navigation-only for Phase 1.

---

### [Major] Finding 5: The unsaved-changes guard conflicts with document autosave unless it has a document-specific adapter

**Concern:** The spec says `useUnsavedChangesGuard` uses form dirty state and that autosave is complementary, but DocumentForm's data is split between React Hook Form fields and independent `lines` state. Autosave is debounced, only runs when at least one line exists, clears pending timers on cleanup, and the manual Save path does not use the hook's `saveNow`.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:129-132`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:184-186`; `apps/web/src/features/documents/DocumentForm.tsx:165-204`, `apps/web/src/features/documents/DocumentForm.tsx:259-330`, `apps/web/src/features/documents/DocumentForm.tsx:491-533`; `apps/web/src/hooks/useDraftAutoSave.ts:153-160`, `apps/web/src/hooks/useDraftAutoSave.ts:180-203`, `apps/web/src/hooks/useDraftAutoSave.ts:208-214`.

**Why it matters:** A guard based only on RHF `isDirty` can miss line edits; a guard based on draft data can prompt despite autosave having persisted the draft; and hard nav/tab close cannot wait for pending debounced saves. This is exactly where double prompts or false prompts will show up.

**Recommendation:** Define a `dirtyState` adapter contract for document editors that includes line changes, autosave pending/error state, and last-saved snapshots. For documents, decide when autosaved drafts suppress the guard and when failed/pending autosave should still warn.

---

### [Major] Finding 6: `useAfterSaveNavigation` is too "pure routing" for Product's create side effects and cache behavior

**Concern:** The proposed hook wires into mutation success and performs only routing, but Product create currently waits for invalidation, sequential buffered image upload, optional enrichment submission, and context-specific toasts before navigating. The new record detail query key is also separate from the invalidated list key and is not seeded from the create response.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:104-121`; `apps/web/src/features/inventory/ProductForm.tsx:227-235`, `apps/web/src/features/inventory/ProductForm.tsx:297-323`, `apps/web/src/features/inventory/ProductForm.tsx:340-367`; `apps/web/src/features/inventory/_invalidation.ts:1-21`.

**Why it matters:** If navigation is moved too early, the user can land on a record before buffered images finish or before image upload failures are surfaced. If no detail cache is seeded or invalidated, the edit/detail page must refetch immediately, which may be acceptable but should be explicit and tested.

**Recommendation:** Make the hook return action intent/destination helpers that are called after editor-specific post-create side effects complete. Add an optional cache strategy: seed the detail key from the mutation response when shape-compatible, or deliberately fetch on destination and test the loading path.

---

### [Major] Finding 7: One SaveSplitButton API cannot yet cover the three Phase-1 form patterns

**Concern:** The spec defines the UI shape but not how the component drives submission. Product has header buttons outside the form using `form="product-editor-form"`, DocumentForm has buttons inside `StickyFormFooter`, and Loyalty forms use mutation per-call `onSuccess` handlers inside `onSubmit`.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:123-127`; `apps/web/src/features/inventory/ProductForm.tsx:498-529`, `apps/web/src/features/inventory/ProductForm.tsx:565-566`; `apps/web/src/features/documents/DocumentForm.tsx:503-533`; `apps/web/src/features/loyalty/pages/ProgramFormPage.tsx:61-80`, `apps/web/src/features/loyalty/pages/ProgramFormPage.tsx:156-166`; `apps/web/src/features/loyalty/pages/MemberFormPage.tsx:57-75`, `apps/web/src/features/loyalty/pages/MemberFormPage.tsx:131-140`.

**Why it matters:** Without an action-intent API, Save, Save & New, and Save & Close will either duplicate submit code, leak navigation concerns into the button, or be unable to distinguish which menu item caused the submit.

**Recommendation:** Specify SaveSplitButton as presentational plus intentful callbacks: `onPrimarySave`, `onSaveAndNew`, `onSaveAndClose`, `isPending`, `disabled`, and optionally `form`/`type="submit"` support. Define how forms persist the chosen intent through async validation and mutation success.

---

### [Major] Finding 8: Split-button accessibility is underspecified, and the nearest existing menu primitive is not sufficient

**Concern:** The spec only says the split button is accessible and that the caret has its own label. The existing `ActionMenu` has `menu`/`menuitem` roles and Escape/click-outside handling, but it does not implement arrow-key navigation, initial focus placement, focus return to trigger, or token-compliant colors.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:123-126`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:168-169`; `apps/web/src/components/ui/ActionMenu.tsx:171-207`; `CLAUDE.md:66-67`.

**Why it matters:** A system-wide save control is high-frequency UI. Keyboard and screen-reader regressions here affect every editor, and reusing current menu styles as-is conflicts with the repo's new-code token rule.

**Recommendation:** Add explicit requirements: separate primary and menu trigger names, `aria-haspopup="menu"`, `aria-expanded`, focus moves into menu on open, ArrowUp/ArrowDown/Home/End navigate items, Escape closes and returns focus, Tab behavior is defined, and all colors come from design tokens. Add component tests for these behaviors.

---

### [Major] Finding 9: The spec implies new i18n keys but does not list them, and they are not present today

**Concern:** SaveSplitButton and the guard need labels beyond the existing `Save`, `Cancel`, `Close`, and one generic unsaved-changes message. The spec says "i18n keys via t()" but does not enumerate keys for Save & New, Save & Close, the split-menu trigger label, the unsaved modal title/body/confirm/stay actions, or document-specific autosave warning copy.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:123-132`; `apps/web/src/locales/en/common.json:6-23`, `apps/web/src/locales/en/common.json:400-402`, `apps/web/src/locales/fr/common.json:6-23`, `apps/web/src/locales/fr/common.json:390-391`, `apps/web/src/locales/ar/common.json:6-49`, `apps/web/src/locales/ar/common.json:354`.

**Why it matters:** Missing translation keys usually surface late as raw keys in tests or UI. This repo has English, French, and Arabic bundles, so the shared component should not land with ad hoc per-editor copy.

**Recommendation:** Add an i18n section listing every new key and target namespace before implementation. Minimum implied keys: `actions.saveAndNew`, `actions.saveAndClose`, `actions.openSaveMenu`, `confirmation.unsavedChangesTitle`, `confirmation.unsavedChangesBody`, `confirmation.leaveWithoutSaving`, `confirmation.stayOnPage`, and any autosave-specific document warning.

---

### [Major] Finding 10: The test plan misses the integration paths most likely to regress

**Concern:** The spec asks for routing, guard, and component tests, but does not call out route exactness (`/:id` vs `/:id/edit`), mutation-response IDs, cache seeding/refetch, Product buffered-image sequencing, document autosave state, or payment allocation paths. Existing Phase-1 form tests currently assert layout only.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:162-172`; `apps/web/src/features/inventory/ProductForm.test.tsx:150-160`; `apps/web/src/features/documents/DocumentForm.test.tsx:76-80`; `apps/web/src/features/loyalty/pages/__tests__/ProgramFormPage.test.tsx:53-57`; `apps/web/src/features/loyalty/pages/__tests__/MemberFormPage.test.tsx:53-57`.

**Why it matters:** The change is primarily behavioral, but the existing tests would pass while save still returns to a list or lands on a detail page instead of edit. The guard/autosave interaction also needs isolation because `useBlocker` state and autosave state can change independently.

**Recommendation:** Add integration tests that drive successful mutations and assert exact destination per action, including Save, Save & New, Save & Close, Cancel/Back, and browser unload where feasible. Add document-specific tests for line-only dirty state, pending autosave, failed autosave, and guard bypass after explicit save actions.

---

### [Minor] Finding 11: Declared list-return exceptions are not actually declared anywhere executable

**Concern:** The spec says list-return exceptions must be visible and declared explicitly, but the only proposed mechanism is an optional `isListReturnException` flag passed editor-by-editor. There is no registry, manifest, or testable source of truth for Menu, Promotion, Coupon, and the four Parapharmacy catalogs.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:88-98`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:108-119`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:149-151`.

**Why it matters:** Optional per-editor flags are easy to omit, and future editors can silently reintroduce list-return behavior without making the exception visible.

**Recommendation:** Create a small declarative registry or route-level helper for post-save policy, with tests that assert every exception is named and every non-exception defaults to stay-on-record.

---

### [Minor] Finding 12: Loyalty create can navigate to record only if per-call handlers consume mutation data

**Concern:** The loyalty APIs return created records, but the current form-level create handlers ignore the returned entity and navigate to list. The shared hook requires an ID, so implementation must change both the mutation callback signature and the form tests.

**Evidence:** `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:114-119`, `docs/superpowers/specs/2026-06-26-post-save-stay-on-record-design.md:144-145`; `apps/web/src/features/loyalty/api/programApi.ts:12-18`; `apps/web/src/features/loyalty/api/memberApi.ts:29-35`; `apps/web/src/features/loyalty/pages/ProgramFormPage.tsx:77-79`; `apps/web/src/features/loyalty/pages/MemberFormPage.tsx:72-74`.

**Why it matters:** This is straightforward, but if missed, the new primitive cannot compute a record destination for loyalty creates.

**Recommendation:** Update the spec examples to show `onSuccess: (created) => nav.goAfterCreate(created.id)` for loyalty and add tests that fail if the created ID is ignored.

---

## Verdict

This spec needs targeted fixes before implementation, not a full redesign. The standard should survive, but Phase 1 must first resolve edit-route exactness, payment scope, product lifecycle semantics, document autosave/guard behavior, and the SaveSplitButton API contract. Once those are pinned down, the implementation can be split safely across editors without each subagent guessing a different interpretation.
