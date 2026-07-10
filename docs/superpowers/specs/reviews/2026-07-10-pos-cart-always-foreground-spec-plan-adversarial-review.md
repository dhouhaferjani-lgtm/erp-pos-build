# Adversarial review — cart-always-foreground spec + plan (pre-dispatch)

**Date:** 2026-07-10 · **Reviewed:** spec `fc2d3d764` + plan `4f9d8ec1a` on `feat/pos-cart-foreground`
**Reviewers:** 2 parallel (Claude, top-tier): UX/spec-fidelity lens; code-grounding lens
**Verdicts:** APPROVE-WITH-CHANGES × 2 — **0 BLOCKER, 5 MAJOR, 12 MINOR/INFO**
**Disposition:** all accepted findings folded into spec/plan Rev 2 (same branch, commit after this file). Architecture (pane replacement) unchallenged by both.

## Majors (all accepted → Rev 2)

| # | Lens | Finding | Resolution |
|---|---|---|---|
| U1 | UX | **Esc stacking collision** — every Modal binds its own window keydown Esc (Modal.tsx:31-39); Esc aimed at a dialog above the pane (variant picker, held, customer search, cash screen) also kills the pane. Directly falsifies spec §4 "held/recall: pane unaffected". | Pane Esc handler bails when any `[aria-modal="true"]` is mounted; test with dialog stub above pane (plan Task 2). |
| U2 | UX | **Pulse can fire off-screen** — cart list is `overflow-y-auto`, new lines append at bottom, no scrollIntoView anywhere; on 8+ line tickets the confirmation pulse is invisible — the exact "did it work?" failure the story exists to fix. | Scroll matching line into view on `lastAddedNonce` change; >6-line cart case added to device checklist (Task 6/8). |
| U3 | UX | **Stale customize-EDIT → silent no-op confirm** — cart is now interactive while composing; `updateLineModifiers` no-ops on missing line id (cartStore.ts:371-393); recall/clear/settle/line-removal leave `editingLineId` dangling → Confirm does nothing, no feedback. | Clear `modifierProduct`/`editingLineId` on replaceCart/clearCart/removal of edited line; validate line at confirm + toast (Task 3/4). Spec §4 corrected: "pane unaffected" holds for the DETAIL pane only. |
| U4 | UX | **Stale detail pane across sales** — spec extended the within-sale "post-add stays open" decision to post-settle without owner sign-off; next customer would face the previous customer's product (survives lock-after-sale). | **OWNER DECIDED 2026-07-10: close pane on settle/new-sale.** One line in handleNewSale; within-sale behavior untouched. |
| C1 | Code | **Task 5 missed 4th `onViewDetails={setDetailProduct}` call site** — ThemePreviewPage.tsx:855 (Tableau density section) absent from the enumeration (766/823/840); verbatim execution ends in a tsc failure with no guidance. | `:855` added to Task 5. |

## Minors (accepted → Rev 2 unless noted)

- **U5** Esc-cancels-customize is being REMOVED (today Modal closes on Esc) — spec now frames it as a deliberate behavior change; owner tests it knowingly (device checklist).
- **U6** grid `display:none` + TanStack Virtual scroll recovery is browser-behavioral, invisible to jsdom — fallback named in Task 3 (capture/restore scrollTop or `visibility` hiding) so a device failure has a plan.
- **U7** No focus management on pane open/close — stated as a deliberate decision (touch/scan terminal; scanner listens on window).
- **U8** `min-w-[680px]` inside `overflow-hidden` clips the tab strip AND close X at degenerate widths — pane wrapper gets `overflow-x-auto`.
- **U9** Customize-EDIT confirm doesn't pulse (goes through `updateLineModifiers`, not `addItem`) — pulse stamped on the edit path too; it is a pane-originated cart mutation the operator must confirm.
- **U10** §3 policy inventory incomplete (CardPaymentModal, VoucherTenderModal, CashDrawerModal, X/ZReportModal, EndOfDayPreviewModal, SaleDetailModal, RefundConfirmModal absent) — added under their classes; CustomerSearchModal noted as legitimate class-(a) candidate for v2.
- **C2** Task 3 copy range for `seedStores` truncates mid-statement — corrected to 216–354.
- **C3** Task 4 fixture citation drift (~:50-84, not :44-77) — corrected.
- **C4** Task 6 CartLineItem call sites are :261/:283; subscription anchor `confirmLineDelete` :96 / `cartPosition` :97 — corrected.
- **C5** Task 3(e) re-authors `bg-gray-50` (raw palette) into a touched line — migrated to `bg-surface-canvas` per repo rule 18 (HomePage isn't in tokenMigratedGlobs, but the PostToolUse hook would ding the implementer).
- **C6** `bccomp` takes no scale arg (decimal.ts:42) — Interfaces instruction scoped to bcadd/bcsum; code block was already correct.
- **C7** Task 5 count nit: 22 call sites, not ~21 — self-healing ("verify grep → 0") but corrected.
- **C8 (INFO)** reduced-motion latches the pulse overlay div mounted (animationend never fires) — acknowledged in plan, harmless.

## Verified sound (what the approvals rest on)

- **UX lens:** layout claims real (460px cart sibling, flex-[7] pane, both cartPosition values structural); pulse covers ALL add ingresses incl. merge-increment equivalents re-adds (`addItemGated` → `cartStore.addItem`, cartIngress.ts:85-107); tab-state move provably equivalent to old reset semantics; composer width does NOT degrade (old "full" Modal was max-w-4xl ≈896px; pane gives ≈840-890px + more height); confirm-closes-customize needs no new code; pulse reset correctly wired to replaceCart/clearCart reality.
- **Code lens:** ~60 citations exact (state/handlers/mounts/store returns/decimal utils/i18n keys); cartStore has NO persist/immer — new fields hydration-safe; Task 3 harness renders full real HomePage and the no-fixed-inset invariant is satisfiable AND non-vacuous; source-scan pins (ingress regex, accent scan) survive; importer sweep complete — no hidden consumer breaks; extraction parity diffed line-by-line, bcmath swap display-identical (and `bccomp` exact-cmp is stricter than the float original); ESLint reality checked (new file in tokenMigratedGlobs, both parseFloat hits removed); all 14 Task-8 vitest paths exist; task independence holds via Interfaces blocks.

## Gate

Execution may be dispatched once Rev 2 (spec + plan edits above) is committed. Standing rules apply: adversarial review at every implementation milestone; merge to LOCAL dev only.
