# PR #97 — Codex Round-1 Adversarial Review

**Verdict:** REQUEST-CHANGES (one P2 / MAJOR)

**Summary:** The trap correctly cycles focus, but the unconditional "focus first focusable" on activation overrides existing `autoFocus` inputs in shared POS modals (CashTenderedModal, VoucherTenderModal). This regresses keyboard-first workflows where the cashier expects to type/scan immediately. Closed at `26854500`.

## Findings

### MAJORS

**P2 — Preserve autoFocus targets when trapping modal focus**
File: `apps/pos/src/hooks/useFocusTrap.ts:58`

> When a base `<Modal>` opens with a child input using `autoFocus`, this unconditional focus call runs after the browser/React autofocus and moves focus back to the first focusable element in the container, which is the header close button. This regresses keyboard-first flows such as `CashTenderedModal` and `VoucherTenderModal`, where opening the modal should let the cashier type or scan immediately without an extra click/tab; consider leaving focus alone when it is already inside the container or supporting an explicit initial-focus target.

**Fix** (`26854500`): only seed initial focus when nothing inside the container currently has it. If autoFocus already moved focus to a child (React autofocus runs during render commit, before the trap's useEffect), the trap leaves it alone. Two new regression tests cover the autoFocus case.

### BLOCKERS / MINORS / NITS
None.

## Verification done

- Confirmed `CashTenderedModal.tsx:81` and `VoucherTenderModal.tsx:373` use `autoFocus` on their inputs.
- Inspected the hook implementation and the patched fix.
- Ran `pnpm test src/hooks`: 10 pass post-fix.

## Out of review scope

- The follow-up bug Codex caught in round-2 (opener restoration breaks for autoFocus modals because activeElement is captured AFTER React commits the autoFocus child) — addressed separately at `1742e9c0`.
