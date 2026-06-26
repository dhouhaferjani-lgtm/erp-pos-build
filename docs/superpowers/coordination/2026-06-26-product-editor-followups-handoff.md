# Follow-up sessions handoff — IZI POS editor (2026-06-26)

Three independent follow-ups from owner review of the product editor. Each is its own
session. Shared context (worktree env, branch, the SDD ledger + briefs) lives in
`docs/superpowers/coordination/2026-06-25-product-editor-session2-handoff.md` — read its
"CRITICAL worktree ENV facts" section first regardless of which follow-up you pick.

Branch state at handoff: `feat/izipos-theme-product-editor` HEAD `465d0c624`
(feedback rounds shipped: status toggles, "Produit actif", create-mode image buffer).
Reconcile with `origin/dev` (it advanced) before finishing any branch (rule 21).

---

## 1. Save → STAY-ON-PAGE navigation (cross-cutting UX sweep) — FOCUSED SESSION
**Problem:** after creating a record you get bounced to the LIST, which is frustrating
(you lose your place, can't see what you just made — e.g. the product's just-uploaded
images). Known instance: product editor create path navigates to `/inventory/products`
(list); the UPDATE path already navigates to the record (`/inventory/products/{id}`).
**Goal:** consistent rule across ALL create/edit editors — **after save, stay on the
record** (its edit/detail view), not the list.
**Scope (audit every create flow's onSuccess navigation):** product, invoice, quote,
sales order, credit note, delivery note, purchase order, goods receipt, customer,
supplier, and any other entity editor. For each: does create() navigate to the list or
to the new record? Normalize to "stay on the record."
**Design:** define ONE canonical post-save navigation pattern (a small shared
helper/hook) so future editors inherit it; create → record edit/detail route; update →
stay. Flag any flow that intentionally should return to a list (e.g. bulk ops) and keep
those explicit. Add tests per editor asserting the post-create navigation target.
**Method:** brainstorm the canonical rule briefly → subagent-driven sweep (one editor
per task is fine) → review. Moderate scope (many files), purely FE routing + tests.

---

## 2. Editable, two-way-linked margin field — SESSION
Brief: `.superpowers/sdd/task-editable-margin-brief.md` (read it — has the exact math).
**Model = MARKUP on HT cost** (matches the existing `MarginService`:
`price = cost×(1+m/100)`, `m = (sell−cost)/cost×100`), seeded from
**`company.default_target_margin` (default 30%)** (and `default_minimum_margin` 15%).
Basis = `purchase_price`; bidirectional with the TTC `sale_price` via the selected tax
rate (HT↔TTC bridge); all math via `lib/decimal.ts` (no parseFloat — rule 19).
**Backend surface:** expose `default_target_margin` to the FE if it isn't already
(company-config DTO + `typescript:transform`); persist `target_margin_override`
(add validation `['sometimes','nullable','numeric','min:0','regex:/^\d+(\.\d{1,2})?$/']`).
Split BE (expose default + validate override) from FE (the linked field + compute).

## 3. Draft + autosave + status workflow — SESSION (start with brainstorming)
The big one — never-lose-work + a real draft/publish. Bonus: makes create-mode images
trivial (draft id exists → reuse `ProductImageSection`; retire the interim buffer).
**Findings (verified — don't re-investigate):** NO autosave/draft today; ProductForm
~line 456 says the draft/publish split is "stubbed" (both buttons submit). Backend
`Product` has NO status/draft concept. name+sku are required+unique → autosave can only
fire once both are valid.
**Real scope = "HIDE DRAFTS EVERYWHERE":** add a `ProductStatus` enum + migration; then
gate drafts OUT of product lists, search (Meilisearch/Scout), POS catalog sync, reports,
pickers, public/e-commerce. That plumbing is the bulk + the risk — make it the
acceptance bar. Also: debounced create-then-PATCH autosave (idempotent), draft→publish
transition, abandoned-draft policy (TTL?), and design it as a REUSABLE pattern (apply to
documents/quotes/orders too — owner: "do it wherever relevant").
**Method:** brainstorm → writing-plans → subagent-driven. OWN branch off `origin/dev`
(reaches well beyond the editor); coordinate with in-flight media/POS work.

---

## Also (not editor code)
`GET /api/v1/uom/units` → **403** for the Cafe Tunis demo user → unit dropdown empty.
Grant `uom.view` to that role/tenant before any demo.
