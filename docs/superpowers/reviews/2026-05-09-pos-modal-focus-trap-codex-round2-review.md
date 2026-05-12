# PR #97 — Codex Round-2 Adversarial Review

**Verdict:** REQUEST-CHANGES (one P2 / MAJOR — round-1 fix introduced a follow-up bug)

**Summary:** Round-1's preserve-autoFocus fix correctly avoided overriding autoFocus children, but it captured `previouslyFocused = null` for those same modals because activeElement was already inside the container by effect time. So focus restoration on close fell back to `document.body` — exactly the keyboard-stranded scenario the hook is meant to fix. Closed at `1742e9c0` via render-phase opener capture.

## Findings

### MAJORS

**P2 — Preserve opener for autoFocus modals**
File: `apps/pos/src/hooks/useFocusTrap.ts:60-62`

> When a modal contains an `autoFocus` field, such as `VoucherTenderModal`'s voucher-code input, React focuses that field before this passive effect runs. These lines then set `previouslyFocused` to `null`, so cleanup never restores focus to the tile/button that opened the modal and focus falls back to the document body when the input unmounts. That leaves keyboard users stranded in the exact modal path this hook is meant to fix; capture the opener before `autoFocus` can run, or keep a separate opener ref, while still preserving the auto-focused target.

**Fix** (`1742e9c0`): capture `document.activeElement` during the render phase of `useFocusTrap` (BEFORE React commits child autoFocus). Render-phase reads are safe because:
- The Modal component re-renders when `isOpen` flips true.
- `useFocusTrap` is called during that render — children HAVEN'T committed yet.
- So `document.activeElement` is still the opener button.
- Snapshot to `previouslyFocusedRef.current` (Strict-Mode-safe via the null-only-on-first-write check).
- React then commits the children, autoFocus fires, useEffect runs.
- On cleanup, restore focus to the snapshotted opener, then reset the ref to null so the next activation can capture fresh.

Regression test: `VoucherFlow` harness mirrors the real `VoucherTenderModal` pattern — input with autoFocus, opener tile, close button. Asserts opener focus is restored even when the modal had an autoFocus child.

### BLOCKERS / MINORS / NITS
None.

## Round-1 closure trail

| Round-1 finding | Severity | Closure SHA | How |
|---|---|---|---|
| Override of autoFocus targets | P2 / MAJOR | `26854500` | Skip the first-focusable seed when activeElement is already inside the container. |

## Verification done

- Re-read the fix at `useFocusTrap.ts:46-66` (render-phase capture) and the cleanup at `useFocusTrap.ts:114-122`.
- Ran `pnpm test src/hooks`: 11 pass post-fix.
- Full POS suite: 1043/1043 in 118 files. typecheck clean.
