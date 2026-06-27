# Post-save "stay on the record" — canonical save-action & navigation standard (v2)

**Date:** 2026-06-26
**Status:** v3 — revised after two Codex adversarial reviews (round 1:
`docs/superpowers/reviews/2026-06-26-post-save-stay-on-record-codex-review.md`; round 2:
`docs/superpowers/reviews/2026-06-27-post-save-stay-on-record-codex-review-round2.md`).
Blocker-free; ready for implementation plan.
**Branch:** `feat/post-save-stay-on-record` (off `origin/dev`)
**Origin:** Follow-up §1 of `docs/superpowers/coordination/2026-06-26-product-editor-followups-handoff.md`

> **v2 changes (owner decisions on the 3 Blockers):**
> 1. **Post-save destination = the record's DETAIL route `/:id`** (read-only), not an edit route.
>    "Stay on the record" = land on its detail page (matches documents today). Editing is
>    re-entered via the detail page's Edit affordance.
> 2. **Payments dropped from Phase 1** (multi-outcome flow; its own scoped decision later).
> 3. **Minimal Product `status` (draft/published) added now** so Publish is a real transition —
>    it activates the already-present Save-draft / Publish / BeforePublishChecklist UI.
>
> **v3 changes (owner decisions after round-2 review — these SUPERSEDE conflicting text below):**
> A. **Product is NAV-ONLY this session.** Reverses v2 decision 3: all Product `status`/Publish
>    work (enum, migration, DTO, server publish-readiness guard, draft-sellability gating,
>    status badge) is **deferred to §3**. This session: Product create/update → detail `/:id`
>    + `<SaveSplitButton>`, and the existing **stubbed Publish button is hidden/disabled** (no
>    misleading action). No backend changes for Product. (Resolves round-2 blockers 2/3/4 +
>    majors 5/6.)
> B. **The unsaved-changes guard is `BrowserRouter`-compatible** — NOT `useBlocker`. The app
>    uses `<BrowserRouter>`/`<Routes>` (react-router-dom 7), where `useBlocker` is unavailable
>    without a data-router migration (out of scope). Phase-1 guard = `window` `beforeunload`
>    (tab close/refresh) **+ an explicit confirm modal on the editor's own Cancel/Back action**.
>    Full in-app link/sidebar route-blocking is **deferred** to a future data-router migration.
>    (Resolves round-2 blocker 1.) §5.3 below is amended accordingly.
> C. **SaveSplitButton submit mechanism (resolves round-2 major 7):** menu items set the intent
>    ref synchronously, then call `form.requestSubmit()` (or are rendered as `type="submit"`
>    with the same `form=` id) so RHF validation runs and the chosen intent survives async
>    validation → mutation success. Tested against Product's external `form="product-editor-form"`.
> D. **`useDraftAutoSave` extension (resolves round-2 major 8):** expose `autosavePending` and
>    `autosaveFailed` (+ `lastError`) so the Documents `DirtyState` is truthful for the
>    `beforeunload`/Cancel guard. Still required even without `useBlocker`.

---

## 1. Problem

After **creating** a record, several editors bounce the user back to the **list** instead of
the record they just made — you lose your place and can't see what you created. There is **no
shared post-save navigation pattern**; every form hardcodes `navigate()` in a mutation
`onSuccess`, so the bug recurs with each new editor.

### Audit (verified against code)

**Already correct — create lands on the record's detail route:** Documents
(`DocumentForm.tsx` — invoice, quote, sales order, credit note, delivery note, purchase
order), Contact, Expense, Batch, Stock Transfer, Work Order, Composite Item, Modifier Group.

**Offenders (create → list):** Product (`ProductForm.tsx:302`), Loyalty Program, Loyalty
Member (update already → record). Both create *and* update → list: Menu, Promotion, Coupon,
4 Parapharmacy reference catalogs.

**Route reality (load-bearing):** entity routes split into a **detail page at `/:id`** and a
**form at `/:id/edit`** (and `…/new`). E.g. `routes/index.tsx`: `/inventory/products/:id` →
`ProductDetailPage`, `…/new` + `…/:id/edit` → `ProductForm`; same split for loyalty
programs/members. **The canonical post-save destination is the detail route `/:id`.**

---

## 2. Canonical save-action model (system-wide standard)

Grounded in Salesforce, Shopify Polaris, Stripe, WordPress/Gutenberg, Atlassian, Carbon,
Cloudscape (see References). One model, every editable entity.

### 2.1 Button set (lives on the create/edit FORM)

```
[ Save ▾ ]   [ Publish / Issue / Finalize ]            Cancel
   │
   ├─ Save & New      (batch / reference-data entry)
   └─ Save & Close    (→ back to list)
```

- **`Save`** — primary **split button**. Persists, then **navigates to the record's detail
  route `/:id`** with a success toast. The caret holds *only variants of save*: **Save & New**
  (→ fresh create form), **Save & Close** (→ list).
- **Lifecycle transition** (`Publish` / `Issue` / `Finalize` / `Activate`) — a **separate**
  button, never inside the Save menu. Performs the status transition, then lands on `/:id`
  (which reflects the new state). For documents this is fiscally significant and confirmed.
- **`Cancel` / Back** — tertiary; triggers the unsaved-changes guard when dirty.

**Why a split button** (not three peers): design-system guidance groups a clear default with
minor variants under one primary, and keeps different-consequence actions (Publish/Delete) as
separate buttons.

### 2.2 Editability & read-only are a function of lifecycle STATE

The **detail route `/:id` is inherently read-only**; the **`/:id/edit` form is editable**.
After a transition (Publish/Finalize/Issue), the record's `status` changes and the editor
route enforces the lock (e.g. an issued document cannot be edited). We do **not** introduce a
separate "view mode" screen — read-only is the detail page; locked-editing is enforced by
status in the form. (Stripe/WordPress pattern.)

---

## 3. Post-save navigation contract

| Action | Destination | Dirty guard |
|---|---|---|
| **Save** (create) | record detail `/:id` (toast) | n/a (saved) |
| **Save** (update) | record detail `/:id` (toast) | n/a (saved) |
| **Save & New** | fresh blank create form | bypassed (saved) |
| **Save & Close** | parent list | bypassed (saved) |
| **Publish / Issue / Finalize** | record detail `/:id` (now reflecting new status) | n/a |
| **Cancel / Back** | parent list | **triggers guard if dirty** |
| **Browser close / nav-away while dirty** | — | **triggers guard** |

### Invariants
1. **Create and update converge** on the record detail `/:id`. Create never bounces to the
   list — the core fix.
2. **Navigation happens after editor-specific post-create side effects resolve** (e.g.
   Product buffered-image upload + enrichment) so the detail page shows the finished record.
3. **"Return to list" is opt-in** via `Save & Close`.
4. **List-return exceptions are declared in a registry** (§6), not ad-hoc flags.
5. **One success toast** per save so success is unambiguous.

---

## 4. Per-Phase-1-entity matrix (resolves review F3/F4)

| Entity | Status field | Phase-1 behavior | Lifecycle button | Read-only lock |
|---|---|---|---|---|
| **Product** | **NEW** `status` enum `draft`/`published` (this session) | Save draft → `draft`; Publish → `published` (gated by `BeforePublishChecklist`). Create/update → detail `/:id` | **Publish** = real transition | Not enforced in Phase 1 (form stays editable for both states); enforcement deferred to §3 |
| **Loyalty Program** | exists (`status`) but form does not lock on it | Nav-only: create/update → detail `/:id`. SaveSplitButton, **no** lifecycle button | none added | none (unchanged) |
| **Loyalty Member** | exists but not used to lock | Nav-only: create/update → detail `/:id` | none added | none (unchanged) |
| **Documents** | existing `draft`→`issued` lifecycle + `useDraftAutoSave` | Already lands on detail ✓; adopt `<SaveSplitButton>` for a real **Save & Close**; keep existing Issue/Finalize buttons + status lock | existing (unchanged) | existing (unchanged) |

**Rule for entities without a status lock:** `<SaveSplitButton>` is **navigation-only** in
Phase 1 — no lifecycle button, no field locking.

### 4.1 Minimal Product status — scope & explicit caveat
- **Backend:** `ProductStatus` PHP enum (`draft`,`published`) (rule 9); migration adding
  `status` to `products` **defaulting existing rows + non-editor creates to `published`**
  (no behavior change for current data); `ProductData` DTO + Create/UpdateProductRequest
  validation (`in:draft,published`); persist on store/update; `php artisan typescript:transform`.
- **Frontend:** wire existing `catalog:editor.actions.saveDraft` → `status:'draft'` and
  `…publish` → `status:'published'` (Publish gated by `BeforePublishChecklist` completeness).
- **⚠ Deferred-gating caveat (must be surfaced):** minimal status does **NOT** hide `draft`
  products from lists, search (Scout/Meilisearch), POS catalog sync, reports, pickers, or
  e-commerce — that "hide drafts everywhere" plumbing is the **§3 session**. Until §3, a
  product explicitly saved as `draft` remains visible/sellable. Mitigation: only the explicit
  "Save draft" action produces a draft; everything else is `published`. Owner accepts this gap
  for Phase 1.

---

## 5. Shared primitives (built once, `apps/web/src`)

### 5.1 `useAfterSaveNavigation` — intent/destination helpers (resolves F6)
Returns **destination helpers the editor calls AFTER its own post-success side effects**
(invalidation, image upload, toasts). It does not itself sit inside the mutation or sequence
side effects.

```ts
interface AfterSaveNavConfig {
  recordPath: (id: string) => string;   // detail route, e.g. (id) => `/inventory/products/${id}`
  listPath: string;
  createPath?: string;                   // for Save & New (defaults to current create route)
}
interface AfterSaveNav {
  goToRecord: (id: string) => void;      // → recordPath(id)  (create & update)
  goToNew: () => void;                   // → fresh create form (Save & New)
  goToList: () => void;                  // → list (Save & Close)
}
```
**Cache strategy:** the detail route already fetches `/:id`; default is **fetch-on-destination**
(the detail page's own query). Optionally seed the detail query key from the create/update
response when the response shape matches the detail DTO — note this per editor and test the
loading path either way. (Product's detail key differs from the invalidated list key — F6.)

### 5.2 `<SaveSplitButton>` — presentational + intent callbacks (resolves F7)
Pure presentational; the host form owns submission and records which intent fired.

```ts
interface SaveSplitButtonProps {
  onPrimarySave: () => void;
  onSaveAndNew?: () => void;             // omit → menu item hidden
  onSaveAndClose?: () => void;
  isPending?: boolean;
  disabled?: boolean;
  primaryLabel?: string;                 // e.g. Product passes "Save draft"
  form?: string;                         // supports header-rendered submit (Product uses form="product-editor-form")
  primaryType?: 'submit' | 'button';
}
```
The form persists the chosen intent (`useRef<'save'|'new'|'close'>`) across async validation +
mutation success so `onSuccess` can branch to the right `AfterSaveNav` helper. Lifecycle
(Publish/Issue) buttons are rendered by the host, not this component.

**Accessibility (resolves F8) — required, with tests:** distinct accessible names for the
primary and the caret trigger; caret `aria-haspopup="menu"` + `aria-expanded`; opening moves
focus into the menu; ArrowUp/ArrowDown/Home/End navigate items; Escape closes and returns
focus to the trigger; defined Tab behaviour; **all colors from `lib/designTokens`** (rule 18).
The existing `ActionMenu` lacks arrow-key nav/focus-return — either extend it to meet these or
build a dedicated control; do not ship the current menu styles as-is.

### 5.3 `useUnsavedChangesGuard` — with a `dirtyState` adapter (resolves F5)
`beforeunload` (browser) + react-router `useBlocker` (in-app). **First audit for any existing
guard** before building.

```ts
interface DirtyState {
  isDirty: boolean;                      // form fields AND any side state (e.g. document lines)
  autosavePending?: boolean;             // a debounced save is queued/in-flight
  autosaveFailed?: boolean;              // last autosave errored
}
useUnsavedChangesGuard(dirty: DirtyState, opts?: { bypassRefs?: ... })
```
- Explicit `Save & Close` / `Save & New` / Publish **bypass** the guard (already persisting).
- **Documents:** `isDirty` must include the independent `lines` state (RHF `isDirty` alone
  misses line edits). A clean, **autosaved** draft (`!autosavePending && !autosaveFailed`)
  **suppresses** the guard; `autosavePending` or `autosaveFailed` **still warns** (debounced
  saves cannot complete on hard nav/tab close). Do not duplicate `useDraftAutoSave`; consume
  its state.

---

## 6. Post-save policy registry (resolves F11)
A single declarative source of truth instead of scattered optional flags:

```ts
// apps/web/src/lib/postSavePolicy.ts
export const LIST_RETURN_EXCEPTIONS = [
  'menu', 'promotion', 'coupon',
  'parapharmacy.ingredient', 'parapharmacy.certification',
  'parapharmacy.healthClaim', 'parapharmacy.keyComponent',
] as const;
```
A test asserts: every listed exception keeps create → list, and **every other editor wired to
the primitives defaults to stay-on-record** (regression lock). New editors must either inherit
the default or be added here explicitly.

---

## 7. Scope

### Phase 1 — this session
**Primitives:** `useAfterSaveNavigation`, `<SaveSplitButton>` (a11y), `useUnsavedChangesGuard`
(+ `dirtyState` adapter), `postSavePolicy` registry.
**Minimal Product status** (backend slice in §4.1).
**Editors** (one per subagent task, TDD asserting exact destination):

| Editor | Change |
|---|---|
| **Product** | create/update → detail `/inventory/products/{id}`; SaveSplitButton (primary "Save draft") + real **Publish** (status transition, checklist-gated); navigate after image-buffer/enrichment side effects |
| **Loyalty Program** | create → detail `/:id` (consume created record id — F12); adopt primitives (nav-only) |
| **Loyalty Member** | create → detail `/:id` (consume created id); adopt primitives (nav-only) |
| **Documents** | already lands on detail ✓; adopt `<SaveSplitButton>` for Save & Close; wire guard via `dirtyState` adapter (lines + autosave); keep existing Issue/Finalize + status lock |

### Declared list-return exceptions (no change; in the registry)
Menu, Promotion, Coupon, Parapharmacy ×4.

### Deferred (NOT this session)
- **Payments** — multi-outcome (invoice/PO/delivery-note origin, allocation interstitial);
  needs its own destination matrix.
- **§3 draft "hide drafts everywhere"** gating (lists/search/POS/reports/pickers/e-commerce)
  and product read-only lock enforcement.
- **Save & New** rollout to reference-data catalogs; migrating already-correct editors
  (Contact/Expense/…) onto `<SaveSplitButton>` for consistency.

---

## 8. i18n keys (resolves F9 — EN/FR/AR bundles)
New keys to add to all three locales before wiring:
`actions.saveAndNew`, `actions.saveAndClose`, `actions.openSaveMenu`,
`confirmation.unsavedChangesTitle`, `confirmation.unsavedChangesBody`,
`confirmation.leaveWithoutSaving`, `confirmation.stayOnPage`,
and a document autosave-pending warning key (e.g. `documents.unsavedAutosavePending`).
Reuse existing `actions.save`, `actions.cancel`, `catalog:editor.actions.saveDraft/publish`.

---

## 9. Testing (resolves F10) — FE-only except the Product backend slice

Per editor, **integration tests driving a successful mutation and asserting the EXACT
destination** (not layout): Save → `/:id`, Save & New → create form, Save & Close → list,
Cancel-while-dirty → guard fires. Existing form tests assert layout only and would pass while
the bug persists — these must assert routes/`navigate` mocks.

- **Product:** create → `/inventory/products/{id}`; Publish sets `status:'published'` and is
  **not** a duplicate save (distinct from Save draft); navigation waits for buffered-image
  upload; backend `ProductEditorContractTest`-style cases for `status` validation/persistence
  (run **by path**, never the full suite).
- **Loyalty ×2:** test fails if the created record id is ignored (F12).
- **Documents:** guard fires on line-only dirty; suppressed when autosaved-clean; still warns
  on `autosavePending`/`autosaveFailed`; `Save & Close` bypasses.
- **Registry:** every exception → list; every wired non-exception → stay-on-record.
- **`<SaveSplitButton>`:** renders primary + menu items, fires the correct intent, full
  keyboard a11y (§5.2).

---

## 10. Coordination notes
- Product editor merged to `origin/dev` (`a6c1fee7f`); Product fix is direct here.
- The product form is a hot area (parallel sessions: opening-balance, margin-hierarchy). Keep
  the Product diff surgical (nav + save buttons + the small status slice) to ease rebases.
- Do not duplicate `useDraftAutoSave`; the guard consumes its state.

---

## 11. References (industry standards)
Atlassian Button & Split button · Carbon Menu buttons · Shopify Polaris Contextual Save Bar ·
Salesforce (stay on new record) · Stripe Invoicing (status-gated editability) ·
WordPress/Gutenberg (Save Draft vs Publish vs Update) · Cloudscape / Oracle ADF (unsaved-changes guard).
