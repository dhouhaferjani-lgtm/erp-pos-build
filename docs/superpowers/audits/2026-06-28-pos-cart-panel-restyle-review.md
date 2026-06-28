# Adversarial Review — POS cart-panel restyle (declutter quick-actions + mono summary)

**Scope:** two commits only
- `973aa91ae` — declutter cart quick-actions toolbar (mock §5.1) + Reprendre count
- `3b61886d7` — mono typography on cart summary amounts

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.pos-caisse` · branch `feat/pos-caisse-redesign`
**Verdict:** No Blocking / No Important findings. Clean. Two Nits below.

Files touched:
- `apps/pos/src/components/molecules/QuickActions/QuickActions.tsx`
- `apps/pos/src/components/molecules/QuickActions/QuickActions.test.tsx` (new)
- `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`
- `apps/pos/src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx`
- `apps/pos/src/pages/HomePage.tsx`
- `apps/pos/src/components/pos/PaymentSummary.tsx`

---

## 1. QuickActions API change (removed `onReturns` prop) — CLEAN

The ONLY consumer of `molecules/QuickActions` is `organisms/TransactionCart`
(`TransactionCart.tsx:6` import; render at `:145`). It was updated in the same
commit to stop passing `onReturns` and to pass `recallCount` instead. No other
importer exists (grep over `apps/pos/src` for `molecules/QuickActions` returns
only `index.ts` re-export, the test, and TransactionCart).

The separate legacy `apps/pos/src/components/pos/QuickActions.tsx` is a distinct
file with its own local `QuickActionsProps` (still 4 actions incl. `onReturns`,
uses `cn`). It is **NOT imported anywhere** (grep for `components/pos/QuickActions`
/ `pos/QuickActions'` → zero importers) and is **NOT** the component TransactionCart
imports. So the API change cannot break it. → confirmed unrelated. (See Nit-1.)

No TypeScript break: `QuickActionsProps` no longer declares `onReturns`, and the
single call site no longer passes it. `recallCount?` is optional with a default.

## 2. Row B gating correctness — CLEAN (no dangling divider, no empty icon group, clear-button behavior preserved)

Outer guard (`TransactionCart.tsx:141`): `hasQuickActions || hasReturns || items.length > 0`.
- `hasQuickActions = Boolean(onDiscount && onHold && onRecall)` (`:98`)
- `hasReturns = Boolean(onReturns)` (`:99`)

Inner structure:
- Left labelled group renders iff `hasQuickActions` (`:143`).
- Right icon group `<div>` renders iff `hasReturns || items.length > 0` (`:155`).
  - Divider span renders iff `hasQuickActions` (`:157`).
  - Returns IconButton iff `hasReturns` (`:160`).
  - Clear IconButton iff `items.length > 0` (`:170`).

Enumerated cases:
- **(a) no quick-action props + empty cart** → outer `false||false||false = false`: Row B not rendered. No dangling. ✓
- **(b) quick actions wired + empty cart, no onReturns** → left group renders alone; right `<div>` guard `false||false = false` → not rendered; no divider. ✓
- **(b′) quick actions + onReturns wired + empty cart (real HomePage path)** → left group; right div renders; divider (hasQuickActions) + Returns; Clear hidden (items=0). Divider is followed by the Returns button → not dangling. ✓
- **(c) onReturns only, empty cart** (the new test) → left group hidden; right div renders; divider hidden (hasQuickActions false); Returns shown; Clear hidden. Single Returns button, no orphan divider. ✓
- **(d) items present, no returns** → left group (if wired) + divider + Clear. ✓; or items-only with no quick actions/returns → right div renders, divider hidden, Clear only. ✓ (preserves the existing "clear only when items present" test at test:89.)
- **(e) refund mode** (`isRefundMode` = return items present, items.length>0) → same as items-present; Row B renders; mono refund total additive. ✓

Key invariant verified: the divider (`:157`/`158`) renders only inside the right
`<div>`, which itself only renders when `hasReturns || items>0` — so the divider is
always followed by at least one icon button. **No standalone/dangling divider and
no empty icon group is reachable.** The pre-existing clear-button visibility contract
(hidden when empty, shown when items) is unchanged.

## 3. recallCount plumbing — CLEAN

- `QuickActions`: `recallCount = 0` default (`QuickActions.tsx:22`); badge renders only
  when `action.count > 0` (`:83`). `count` is set to `recallCount` only on the Recall
  action; discount/hold carry literal `count: 0` (`:38`,`:47`) so `action.count` is always
  a number (no `undefined > 0`). ✓
- `TransactionCart`: `recallCount = 0` default (`:73` prop), threaded to QuickActions (`:149`). ✓
- `HomePage.tsx:1430`: `recallCount={heldTransactions.length}` — correct source (parked sales). ✓
- Tests assert badge present when `recallCount=3` and absent when `0`. ✓

## 4. a11y — CLEAN

Both new/relocated icon-only controls are `IconButton`s with both `aria-label` and `title`:
- Returns: `aria-label={t('receiptLocator.entryButton')}` (`:165`/`166`).
- Clear: `aria-label={t('cart.clear')}` (`:175`/`176`).
No nested interactive elements: the recall count badge is a non-interactive `<span>`
inside the Button; dividers are `<span aria-hidden="true">`. ✓

## 5. i18n — CLEAN

No new hardcoded user-facing strings. All labels via `t()`. The two keys used on the
relocated icons (`receiptLocator.entryButton`, `cart.clear`) already existed (they were
in use before this change). The recall badge renders a numeric count, not translatable text. ✓

## 6. Tokens — CLEAN (manually checked; TransactionCart not in ESLint token guard)

New classes introduced are all design tokens, no raw `bg-blue-*/gray-*/red-*` literals:
- Badge: `bg-accent-tint`, `text-accent-strong`, `rounded-pill` — all defined in
  `apps/pos/src/index.css` (`--color-accent-tint`/`--color-accent-strong` at :295-296;
  `rounded-pill` used across `ui/Pill.tsx`, `StockBadge`, etc.).
- Divider: `bg-border-subtle` (existing token, reused).
- Returns icon uses `variant="secondary"` / Clear `variant="destructive"` (IconButton variants).
`font-mono` / `tabular-nums` are layout/utility classes, not colors. ✓

## 7. Mono typography (commit 3b61886d7) — CLEAN / additive

Purely additive `font-mono` on PaymentSummary subtotal/tax/discount/total
(`PaymentSummary.tsx:62,68,76,95`) and the TransactionCart refund net-total
(`TransactionCart.tsx:293`). No conditional logic, sign handling, or formatting
changed (`format(...)`, `netTotal < -0.005` opacity branch all intact). No behavior risk. ✓

---

## Nits (non-blocking, optional)

- **Nit-1 — dead divergent duplicate.** `apps/pos/src/components/pos/QuickActions.tsx`
  is unused (no importers) and still encodes the OLD 4-action design (incl. `onReturns`).
  Not introduced by these commits, but now that the canonical molecule dropped Returns,
  this stale twin is extra-confusing. Consider deleting it in a follow-up cleanup.
- **Nit-2 — uniform-shape literals.** `count: 0` on the discount/hold action objects
  (`QuickActions.tsx:38,47`) exists only to keep the array element shape uniform for
  `action.count > 0`. Harmless; could alternatively be `count?: number` with `?? 0`.
  No action needed.

## Test coverage assessment

Good. New `QuickActions.test.tsx` covers: exactly-three-actions (Returns absent),
empty-cart disabled state with Recall still enabled, badge shown at count>0, badge
omitted at 0, and callback firing. TransactionCart gains an empty-cart Returns-icon
test and retains the clear-button visibility contract. The gating matrix from §2 is
adequately (not exhaustively) exercised; case (b′) divider-with-returns is implied by
HomePage wiring but not unit-asserted — acceptable.
