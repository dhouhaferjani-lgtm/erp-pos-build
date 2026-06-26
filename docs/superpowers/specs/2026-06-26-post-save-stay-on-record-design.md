# Post-save "stay on the record" — canonical save-action & navigation standard

**Date:** 2026-06-26
**Status:** Design approved (sections A–C), pending spec review → plan
**Branch:** `feat/post-save-stay-on-record` (off `origin/dev`)
**Origin:** Follow-up §1 of `docs/superpowers/coordination/2026-06-26-product-editor-followups-handoff.md`

---

## 1. Problem

After **creating** a record, many editors bounce the user back to the **list** instead of
keeping them on the record they just made. This is frustrating: you lose your place, can't
see what you just created (e.g. a product's just-uploaded images), and the behavior is
inconsistent — update flows usually already stay on the record, create flows often don't.

There is **no shared post-save navigation pattern** today; every form hardcodes its own
`navigate()` in a mutation `onSuccess`, so the bug recurs with each new editor.

### Audit (current behavior)

**Already correct — create stays on the record:** Documents (`DocumentForm.tsx` — invoice,
quote, sales order, credit note, delivery note, purchase order), Contact, Expense, Batch,
Stock Transfer, Work Order, Composite Item, Modifier Group.

**Offenders (create → list):**
- Inconsistent (update stays, create bounces): **Product** (`ProductForm.tsx:302`),
  **Loyalty Program**, **Loyalty Member**.
- Both create *and* update → list: **Menu**, **Promotion**, **Coupon**, and 4 **Parapharmacy**
  reference catalogs (Ingredient, Certification, Health Claim, Key Component).

---

## 2. Canonical save-action model (the system-wide standard)

Grounded in industry conventions (Salesforce, Shopify Polaris, Stripe, WordPress/Gutenberg,
Atlassian, Carbon, Cloudscape — see References). One model, every editable entity.

### 2.1 Button set

```
[ Save ▾ ]   [ Publish / Issue / Finalize ]            Cancel
   │
   ├─ Save & New      (batch / reference-data entry)
   └─ Save & Close    (→ back to list)
```

- **`Save`** — primary **split button**. Persists and **stays on the record in edit mode**.
  The caret holds *only variants of save*: **Save & New**, **Save & Close**.
- **Lifecycle transition** (`Publish` / `Issue` / `Finalize` / `Activate`) — a **separate**
  button, never inside the Save menu. Different consequence, usually needs confirmation
  (and, for documents, is fiscally significant).
- **`Cancel` / Back** — tertiary; triggers the unsaved-changes guard when dirty.

**Why a split button** (not three peers): design-system guidance groups one clear default
with minor variants under a single primary, and keeps different-consequence actions
(Publish / Delete) as separate buttons. Matches the owner's "one Save button with an arrow"
intent.

### 2.2 Editability is a function of lifecycle STATE, not a separate view

After a lifecycle transition (Publish/Finalize/Issue), the user **stays on the same record
route**; the screen becomes **read-only because the record's status changed** — not because
we redirected to a different "view page." (Stripe locks a finalized invoice; WordPress flips
"Publish" → "Update" in place.) A read-only "view mode" is therefore a *consequence of state*,
implemented as a status-driven lock on the same component/URL.

> **Entity nuance:** "Publish" requires a lifecycle state to exist. **Documents already have
> `draft → issued`** (and a `useDraftAutoSave` hook). **Products have no draft/published status
> yet** — that is the separate §3 draft/autosave session. So lifecycle locking lights up
> per-entity as the state exists; **Save / Save & Close / stay-on-record applies to all
> Phase-1 editors immediately**.

---

## 3. Post-save navigation contract

| Action | Destination | Mode | Dirty guard |
|---|---|---|---|
| **Save** (create) | the new record's route `…/{id}` | **edit** | n/a (saved) |
| **Save** (update) | stay in place | edit | n/a (saved) |
| **Save & New** | fresh blank create form | edit | bypassed (saved) |
| **Save & Close** | parent list | — | bypassed (saved) |
| **Publish / Issue / Finalize** | stay on `…/{id}` | **read-only (state-locked)** | n/a |
| **Cancel / Back** | parent list | — | **triggers guard if dirty** |
| **Browser close / nav-away while dirty** | — | — | **triggers guard** |

### Invariants
1. **Create and update converge** on the same destination (the record). Create never bounces
   to the list — this is the core fix.
2. **Read-only is derived from `status`**, not from a separate route. Same URL, same component,
   editable vs locked by state.
3. **"Return to list" is opt-in** via `Save & Close` — never the default for a primary `Save`.
4. **List-return exceptions are declared explicitly** per editor (visible, not accidental).
5. **One toast on stay-in-place save** so success is unambiguous.

The per-editor decision reduces to one declaration: *stay-on-record (default)* or
*declared list-return exception*, plus which lifecycle transition(s) it exposes.

---

## 4. Shared primitives (built once, `apps/web/src`)

### 4.1 `useAfterSaveNavigation`
Encodes the contract so future editors inherit it and cannot accidentally bounce to the list.

```ts
interface AfterSaveNavConfig {
  recordPath: (id: string) => string;   // e.g. (id) => `/inventory/products/${id}`
  listPath: string;                      // e.g. `/inventory/products`
  createPath?: string;                   // for Save & New (defaults to current create route)
  isListReturnException?: boolean;       // declared exceptions default Save → list
}
interface AfterSaveNavHandlers {
  goAfterCreate: (id: string) => void;   // → recordPath(id) (or list if exception)
  goAfterUpdate: (id: string) => void;   // stay in place: no navigation; caller clears dirty + toasts
  goSaveAndNew: () => void;              // → fresh create form
  goSaveAndClose: () => void;            // → list (bypasses guard)
}
```
Wires into each editor's mutation `onSuccess`. Pure routing; no data fetching.

### 4.2 `<SaveSplitButton>`
The `[ Save ▾ ]` UI. Primary `Save` + caret menu (`Save & New`, `Save & Close`). i18n keys via
`t()`; styled with design tokens (rule 18); accessible (Atlassian split-button a11y: caret has
its own label, primary action not duplicated in menu). Lifecycle buttons remain separate,
rendered by the host editor.

### 4.3 `useUnsavedChangesGuard`
`beforeunload` (browser) + react-router `useBlocker` (in-app). Dirty state comes from the
form. `Save & Close` / `Save & New` bypass it (already saved); `Cancel`/Back trigger a
confirmation modal. **Plan must first audit for any existing partial guard** before building.

---

## 5. Scope

### Phase 1 — this session (build all 3 primitives + wire these editors)
One editor per subagent task, **TDD asserting the post-create route** (and guard/button tests):

| Editor | Change |
|---|---|
| **Product** (`ProductForm.tsx`) | create → `/inventory/products/{id}` (edit); adopt split button + guard |
| **Loyalty Program** | create → record; adopt primitives |
| **Loyalty Member** | create → record; adopt primitives |
| **Payments** | locate the payment record/create flow; bring onto the standard |
| **Documents** (`DocumentForm.tsx`) | already stays on record ✓ — adopt `<SaveSplitButton>` for a real Save & Close + verify; coexist with `useDraftAutoSave` |

### Declared list-return exceptions (no behavior change; documented as intentional)
Menu, Promotion, Coupon, Parapharmacy ×4 (Ingredient, Certification, Health Claim, Key
Component) — batch / reference-data entry. They keep list-return and (later) gain `Save & New`.

### Deferred (NOT this session)
- Per-entity **Publish + state-lock** rollout (documents already have it; **product Publish =
  §3 draft/status session**).
- **Save & New** rollout to the reference-data catalogs.
- Migrating the remaining already-correct editors (Contact/Expense/Batch/…) onto
  `<SaveSplitButton>` for consistency — low-risk fast-follow, not required for the fix.

---

## 6. Testing

FE-only. Per editor:
- **Routing test:** after create, asserts navigation to `…/{id}` (not the list); update stays.
- **Exception test:** declared list-return editors assert create → list (regression lock).
- **Guard tests:** dirty nav-away triggers confirm; `Save & Close`/`Save & New` bypass.
- **Component test:** `<SaveSplitButton>` renders primary + menu items, fires correct handlers,
  is keyboard-accessible.

No backend changes in Phase 1 → no PHPUnit. (If Payments needs a backend touch, scope it
narrowly and run tests by path — never the full suite.)

---

## 7. Coordination notes

- The product editor (`feat/izipos-theme-product-editor`) **merged to `origin/dev`**
  (`a6c1fee7f`, 2026-06-26), so the Product fix is done directly here — no merge-timing
  dependency.
- Owner has additional product-form work planned; this sweep touches `ProductForm.tsx` only
  for post-save navigation + the save-button/guard wiring — keep the diff surgical to ease
  rebases.
- `useDraftAutoSave` already exists for documents; do not duplicate it — the guard and the
  autosave are complementary (autosave persists drafts; the guard protects un-autosaved edits
  on non-document editors).

---

## 8. References (industry standards)

- Atlassian — Button & Split button (one primary + minor variants; a11y).
- Carbon — Menu buttons (no-primary vs split).
- Shopify Polaris — Contextual Save Bar (single Save + Discard; collapse = success signal).
- Salesforce — create stays on the new record (inline-editable); Save & New for serial entry.
- Stripe Invoicing — draft is the only editable state; Finalize locks; no redirect.
- WordPress / Gutenberg — Save Draft vs Publish vs Update; Switch-to-Draft reverse transition.
- Cloudscape / Oracle ADF — unsaved-changes guard (`beforeunload` + in-app modal).
