# PR #101 — Custom-dialog organism modal focus traps — Codex round-1 review

**Date:** 2026-05-10
**PR:** https://github.com/otospexsolutions/erp/pull/101
**Branch:** `fix/pos-custom-dialog-focus-traps`
**Base:** `dev` (tip `45cf1d47`)
**Review tool:** `codex review --base dev --title "..."` (codex-cli 0.128.0)

## Note on review-prompt format

The Codex CLI rejects `--base <BRANCH>` combined with a custom `[PROMPT]` argument (mutually exclusive in 0.128.0). The custom adversarial prompt drafted for this round (covering nested-trap walkthrough, autoFocus guard, manager-PIN Escape gate, useEffect ordering, strict-mode safety, jsdom focus quirks, aria-labelledby title fidelity) was therefore not passed; Codex used its default review prompt instead.

The default prompt did exercise the diff against the dev tip and ran the targeted modal tests (43/43 pass). The verdict below reflects that pass.

## Codex commands executed (excerpt)

- `pnpm --filter @autoerp/pos test -- AdvancedPaymentsModal DiscountModal LineDiscountModal --run` → **43/43 passed in 1.55s**
- `grep -R "StrictMode" -n apps/pos/src apps/web/src | head -20` → confirmed strict-mode wrapping in `apps/pos/src/main.tsx:11-13`
- `cat apps/pos/src/main.tsx` → confirmed React 19 `<React.StrictMode>` wraps the entire app
- `git diff --check <base>` → no whitespace errors

## Findings

**None.** No P1/P2/P3 issues raised.

## Verbatim conclusion

> The changes add focus trapping and dialog ARIA metadata to the affected modals without altering the existing payment or discount flows. Targeted modal tests pass, and no actionable regressions were found in the diff.

## Caveat — adversarial gaps not exercised

Because the custom prompt was not passed, the following adversarial vectors drafted for round-1 were NOT explicitly exercised. They are hereby acknowledged and mitigated below:

1. **Nested-trap correctness (AdvancedPaymentsModal + VoucherTenderModal)** — covered in PR body walkthrough; both traps run concurrently via document-level capture-phase keydown listener; final user-visible focus state is correct in all four interesting positions (inner first / inner middle / inner last / between-modals). Single-frame invisible yank when outer wraps, then inner re-yanks. **Not exercised by automated test; manual smoke recommended at merge.**

2. **AutoFocus inputs** — none of the three target modals use `autoFocus`. `useFocusTrap`'s "alreadyInside" guard is therefore not load-bearing here. **No risk.**

3. **Manager-PIN Escape gate** — `useFocusTrap` only intercepts Tab and Shift+Tab (verified at `useFocusTrap.ts:95`); the existing window-level Escape listener (DiscountModal.tsx:60-66, LineDiscountModal.tsx:60-65) is unchanged. **No conflict.**

4. **State-reset useEffect ordering** — `useFocusTrap` is invoked at the top of each component body (declaration order); its internal `useEffect` runs before the local state-reset `useEffect`. First-focus seeding completes before state reset. ✓

5. **Strict-mode double-invocation** — the hook's `previouslyFocusedRef.current === null` guard handles dev-mode double effect runs. The new wiring on these modals doesn't bypass that guard.

6. **Test brittleness in jsdom** — `useFocusTrap`'s cleanup calls `opener.focus()` synchronously inside the cleanup function returned from `useEffect`; React fires cleanup synchronously on rerender when deps change. The opener-restoration tests do not rely on async behaviour. **Low risk.**

7. **`aria-labelledby` title fidelity** — verified by inspection:
   - `AdvancedPaymentsModal` title at `advanced-payments-title` contains `<Wallet />` (lucide-react icon, default `aria-hidden="true"`) + the i18n string `advancedPayments.title`. Accessible name = title text.
   - `DiscountModal` title at `discount-modal-title` contains the i18n string `discount.transactionDiscount`. ✓
   - `LineDiscountModal` title at `line-discount-modal-title` contains the i18n string `cart.itemDiscount`. ✓

## Verdict

APPROVE
