# Adversarial code review — POS cart-line mis-tap guards + Header restyle

**Date:** 2026-06-28
**Branch:** `feat/pos-caisse-redesign` (worktree `apps/erp.pos-caisse`)
**Scope (reviewed ONLY these three commits' diffs):**
- `834d73a33` — cart-line mis-tap guards (move remove into expanded controls + configurable confirm-on-delete)
- `16664cec8` — Header restyle (P2 §5.0)
- `791209cc5` — cart-line `prevOpen` via ref (react-doctor perf)

Touched files read for context: `CartLineItem.tsx` (+test), `TransactionCart.tsx` (+test), `Header.tsx` (+`Header.test.tsx`), `settingsStore.ts` (+test), `SettingsPage.tsx`, `en/pos.json`, `fr/pos.json`, plus supporting atoms (`Avatar`, `Divider`, `ProductThumb`, `StatusPill`, `IconButton`) and `index.css` tokens.

**Verdict:** No Blocking issues. The arm/disarm state machine is correct, no double-fire, no path that loses the ability to remove a line, accordion intact, Header logic fully preserved and `Header.test.tsx` invariants hold, i18n parity is clean, all colours are semantic tokens, and the new controls meet the 48px floor. One **Important** lint/idiom regression in `791209cc5` and a few Minor/Nit items below.

---

## Findings

### Important

**I1 — `791209cc5` ref-during-render introduces 2 eslint warnings and reverts a clean, React-documented pattern.** `CartLineItem.tsx:63-67`
```tsx
const prevOpenRef = useRef(isOpen);
if (prevOpenRef.current !== isOpen) {     // react-hooks/refs: "Cannot access ref value during render"
  prevOpenRef.current = isOpen;           // react-hooks/refs: "Cannot update ref during render"
  if (!isOpen && removeArmed) setRemoveArmed(false);
}
```
**True positive (evidence: `npx eslint` on the file emits exactly these 2 warnings):**
```
64:7  warning  Cannot access ref value during render   react-hooks/refs
65:5  warning  Cannot update ref during render         react-hooks/refs
```
The prior commit (`834d73a33`) used the React-sanctioned "store previous prop in state and adjust during render" pattern (`useState` + `setPrevOpen`), which is **lint-clean** (no ref, so `react-hooks/refs` cannot fire) and is the exact pattern the React docs endorse. The refactor traded that for a ref mutation the `react-hooks` plugin explicitly warns against — the warning text even says it "can cause your component not to update as expected." The perf win is marginal (one render-bookkeeping state slot whose `set` never triggered an extra commit because the visible update is `setRemoveArmed`). **Behaviour is in fact identical** (final committed output is the same under StrictMode in both versions), so this is not a correctness bug — but it is a real quality regression done in the name of "react-doctor perf" that introduced new react-hooks warnings. `pnpm lint` is `eslint .` with no `--max-warnings 0`, so CI won't hard-fail today, but react-doctor will flag it. **Recommend reverting `791209cc5`** and keeping the `useState` version.

### Minor

**M2 — New header "online dot" is a second visual connectivity signal alongside the B3 StatusPill.** `Header.tsx:552-559`
The decorative dot (`aria-hidden`, `title` online/offline, `bg-success`/`bg-danger`) renders connectivity in the LEFT cluster while `StatusPill` already renders connectivity+sync in the CENTER. **True positive** as a design-decision conflict: B3 standardised on a single status pill. The accessibility concern is mitigated (`aria-hidden` so screen readers see one source of truth, and the canonical state stays in `StatusPill`), so this is a judgment call rather than a defect — but it visually reintroduces what B3 removed. Surface to owner; not a code defect.

**M3 — `confirmLineDelete` guard does not cover decrement-to-zero.** `CartLineItem.tsx:95-98`
```tsx
const handleDecrement = () => {
  if (item.quantity === 1) onRemove(item.id);   // bypasses the confirm guard
  else onUpdateQuantity(item.id, item.quantity - 1);
};
```
**True positive:** with `confirmLineDelete` on, tapping the stepper minus at qty 1 still removes the line with no confirmation, whereas the trash control requires two taps. Defensible (the minus button is a deliberate gesture in the expanded controls, and the owner feedback was specifically about the X next to the expand chevron), but a user who enabled "Confirm before removing a line" may expect consistency. Low severity / arguably by-design — flag for owner.

**M4 — `Divider` renders even when `operator` is null, yielding two adjacent dividers around the Switch button.** `Header.tsx:597,600-607,619`
When `operator` is null the right zone renders: shift · `<Divider>` · (no avatar/name) · Switch · `<Divider>` · Lock — a divider with nothing between the shift cluster and Switch. Purely cosmetic; **true positive** but trivial.

### Nit

**N5 — Armed remove control: redundant label.** `CartLineItem.tsx:208-212` — `aria-label`, `title`, and the visible `<span>` are all `t('cart.confirmRemoveItem')`. Harmless (slightly verbose), icon stays `Trash2`. Fine.

**N6 — Full-row header `<button>` accessible name is verbose.** `CartLineItem.tsx:150-158` — the collapsible header button's computed accessible name now concatenates product name + "×qty" + line total (chevron is `aria-hidden`). Acceptable, but a tester relying on accessible name should note it is no longer just the product name.

---

## Targeted checklist results

- **(a) arm/disarm state machine — CLEAN.** `handleRemoveClick` (`CartLineItem.tsx:75-86`): off → immediate `onRemove`; on → first tap `setRemoveArmed(true)` (no remove), second tap `setRemoveArmed(false)` then single `onRemove`. **No double-fire.** No stale arming: collapse resets via the render-time guard (`:64-67`); the `useEffect` 3s timer (`:69-73`) re-runs on every `removeArmed` change and its cleanup `clearTimeout`s correctly — no leak, no late fire after disarm/collapse. **StrictMode-safe:** the render-time ref/state mutation is idempotent (guard short-circuits once `prev === isOpen`); both the old `useState` and new ref versions commit identical output. (Lint caveat: see I1.)
- **(b) confirmDelete bypass / line-unremovable — NONE (except the by-design M3).** Both `<CartLineItem>` render sites in `TransactionCart.tsx` (`:211`, `:233`) pass `confirmDelete={confirmLineDelete}` (grep-confirmed: 2 render sites, 2 props). A collapsed line hides the trash control by design, but the line is still removable by expanding (then 1- or 2-tap) or via decrement-to-zero. No dead-end.
- **(c) accordion regression — NONE.** Header row is now a single `<button>` (collapsible) / `<div>` (legacy) with `aria-expanded`; `isOpen` logic unchanged; expanded controls still gated by `{isOpen && ...}`. The four new tests plus existing ones cover collapse/expand + disarm-on-collapse.
- **(d) Header handlers/aria/B3 — PRESERVED.** B3 `StatusPill`, manual-sync `IconButton`, `StockFreshness`, shift chip → `setShowEndOfDay`, Switch (`title='header.switch'`), Lock (`title='header.lock'`), Reports/X-report/CashDrawer/EOD modals and the manager-PIN path are all intact (`Header.tsx:562-657`). No device-logout button (so `queryByTitle('header.logout')` stays null) — `Header.test.tsx`'s 7 assertions hold. Only B3-adjacent concern is the decorative dot (M2).
- **(e) i18n drift — NONE.** `cart.confirmRemoveItem`, `settings.confirmLineDelete`, `settings.confirmLineDeleteDesc` added to BOTH `en/pos.json` and `fr/pos.json` with matching keys. `CartLineItem` uses the default ('pos') namespace consistently (same as the pre-existing `cart.removeItem`).
- **(f) hardcoded colours — NONE.** All new classes are semantic tokens: `bg-danger-strong`, `text-danger-strong`, `bg-danger-surface`, `text-ink-inverse`, `bg-accent-tint`, `text-accent-strong`, `bg-success`/`bg-danger` (all defined in `index.css`; `bg-success`/`bg-danger` already used elsewhere e.g. `StatusPill`, `Badge`). No `bg-blue-600`-style palette literals. `npx eslint` on both files reports **0 errors** (only the 2 react-hooks warnings from I1).
- **(g) 48px touch floor — MET.** Collapsible header `min-h-[48px]` (`:155`), legacy header `min-h-[48px]` (`:160`), remove control `h-12` both states (`:205-206`), discount `h-12 w-12` (`:230`), modifiers `h-12 w-12` (`:240`). The decorative dot (8px) is not a target. Header `IconButton size="md"` controls are unchanged by these commits.
- **No nested interactive elements.** Cart header `<button>` wraps only `ProductThumb` (non-interactive tinted-initials div), text, and an `aria-hidden` chevron. Header's shift `<button>` wraps only a `Badge`. `Divider` is `role="separator"` (non-interactive). Avatar/Divider atoms exist and are token-based.
- **Persistence.** `settingsStore` `persist` has no `partialize` whitelist, so `confirmLineDelete` persists automatically — no missed-field bug.
- **TS strict.** No `any` introduced; the TransactionCart test mock selector is typed `(s: { confirmLineDelete: boolean }) => unknown`.
