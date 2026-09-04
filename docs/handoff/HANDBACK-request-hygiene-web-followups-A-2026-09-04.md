# HANDBACK — Request Hygiene, web follow-ups batch A

**Three gate follow-ups from T13, T8 and T14 — one commit each**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-webfu`
- Branch: `lane/rh-web-followups-a`
- Base: `e829444d5`
- Reviewer gate: **frontend-conventions-reviewer**
- Scope: `apps/web` only. `apps/api` was never opened.

| Commit | Item |
|---|---|
| `9a826d23b` | **A** — T13 gate r1 MAJOR-4 / r2 item 1: generic error surface on the create stock-adjustment page |
| `e56b6b6a7` | **B** — T8 re-gate r2 R2-N5: late first WebSocket connect gets a recovery sweep |
| `18e49f051` | **C** — T14 re-gate r2 NB-r2-1/2/3: dead branch + three overclaiming comments |
| (this file) | handback |

---

## 1. What landed

| File | Item | Change |
|---|---|---|
| `apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx` | A | `catch` now names the envelope and toasts `create.error` when it is `null`. `+9 / −1`. |
| `apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx` | A | `sonner` mock + a new `the generic error surface` describe (2 tests). |
| `apps/web/src/locales/{en,fr}/stock-adjustments.json` | A | new `create.error`. The namespace has **no `ar` file** (nor does `stock-transfers`), so no `ar` entry was invented. |
| `apps/web/src/providers/WebSocketReconnectProvider.tsx` | B | `INITIAL_CONNECT_GRACE_MS = 5_000` module constant + `mountedAt` ref; a first connect past the window sweeps and arms the cooldown. `+32 / −6`. |
| `apps/web/src/providers/WebSocketReconnectProvider.test.tsx` | B | +1 test (late first connect → 1 sweep + cooldown armed). |
| `apps/web/src/hooks/useDraftAutoSave.ts` | C | dead settle-handler branch deleted; its corrected comment moved to the unmount cleanup; `autosavePending` doc comment widened. Comments + dead code only. |
| `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx` | C | one test retitled + docblock corrected. No test added or removed. |

### Behaviour delta

- **A.** Before: any failure without a `{data:{error:{…}}}` envelope (network, timeout, 500, lost response) produced **no visible feedback at all** — the inline surface renders only when `refusal !== null`. After: `toast.error(t('create.error'))`. The typed-refusal path is byte-identical and still renders inline, never a toast.
- **B.** Before: the first observed connect was classified "initial" whenever it happened, so a client that sat disconnected past `CONNECTION_GIVE_UP_MS` (15 s, which does **not** disconnect the socket) and then connected got no recovery sweep — a gap that pre-T8 *was* swept. After: only a first connect within 5 s of mount is the startup handshake; a later one sweeps `{ refetchType: 'active' }` and arms the 30 s cooldown. The login-time sweep the T8 r1 fix removed does not come back.
- **C.** None. Comment/dead-code only.

---

## 2. Evidence

All from `<worktree>/apps/web`.

### 2.1 Red before green

| Item | Command | Red output |
|---|---|---|
| A | `pnpm vitest run src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx` | `× … the generic error surface > toasts a generic error when the failure carries no refusal envelope` → `AssertionError: expected "spy" to be called 1 times, but got 0 times`; `Tests 1 failed \| 14 passed (15)` |
| B | `pnpm vitest run src/providers/WebSocketReconnectProvider.test.tsx` | `× … sweeps a FIRST connect that lands after the grace window` → `AssertionError: expected "invalidateQueries" to be called 1 times, but got 0 times`; `Tests 1 failed \| 2 passed (3)` |
| C | n/a — no behaviour change, so no new test. `useDraftAutoSave.state.test.tsx` stays **16 passed**, with one title corrected. |

### 2.2 Mutants (item B — the grace window is pinned in both directions)

| Mutant | Result |
|---|---|
| `INITIAL_CONNECT_GRACE_MS` widened to `60_000` | `× sweeps a FIRST connect that lands after the grace window` — 1 failed / 2 passed |
| grace check short-circuited to `false` (always a reconnect) | `× ignores the initial connect: only a genuine reconnect invalidates…` — 1 failed / 2 passed |

Each mutant is caught by exactly one test, and the two tests fail on opposite mutants: the window is measured, not merely present.

Item C's dead branch was already proven unreachable by the gate (`throw` at `:306` → 16 passed). The deletion is safe by the same argument, restated in the moved comment: the unmount cleanup clears `pendingRef` (`useDraftAutoSave.ts:448` at base), so the settle handler returns at its own `!pendingRef.current` guard, and `performSave` refuses to refill after unmount (`:332` at base).

### 2.3 Suite, types, lint

- `pnpm vitest run src/features/stock-adjustments src/providers src/hooks` → **24 files, 160 tests passed** (baseline at `e829444d5`: 24 files, 157 passed; +3 = 2 for A, 1 for B).
- `pnpm typecheck` → clean.
- Per-file ESLint, base version restored in place vs. shipped version, same invocation:

| File | Base `e829444d5` | Shipped |
|---|---|---|
| `CreateStockAdjustmentPage.tsx` | 0 errors, 3 warnings (`restrict-template-expressions` ×3) | 0 errors, **same 3 warnings** (lines shifted only) |
| `CreateStockAdjustmentPage.test.tsx` | 0 errors, 0 warnings | 0 errors, 0 warnings |
| `WebSocketReconnectProvider.tsx` + `.test.tsx` | 0 errors, 0 warnings | 0 errors, 0 warnings |
| `useDraftAutoSave.ts` + `.state.test.tsx` | 0 errors, 5 warnings (`array-type`, `prefer-nullish-coalescing`, `set-state-in-effect`, `no-floating-promises`, `unbound-method`) | 0 errors, **same 5 warnings** |

**Zero new errors, zero new warnings.**

A first pass of item B used `useRef(Date.now())` and drew a **new** `react-hooks/purity` warning ("Cannot call impure function during render"). It was removed before commit: the stamp is now taken on the first effect run (`mountedAt.current ?? Date.now()`), so `Date.now()` never runs in the render body.

### 2.4 i18n

`pnpm audit:i18n:local` output **byte-identical** with and without the new key (diffed against a run with the base locale files restored in place). It exits 1 on both — pre-existing `ar` baseline debt in unrelated namespaces (`uom` etc.), untouched by this lane. No line mentions `stock-adjustments`.

---

## 3. Deviations

1. **A used a new `create.error` key rather than the existing `refusal.generic`.** The gate directive asked for a new key in the adjustments namespace, and `refusal.generic` ("The stock adjustment could not be completed.") asserts a verdict we do not have: on a lost response the document may well have been created. The shipped text says so — *"Could not save the adjustment. Check whether it was created before trying again."* — and matches `CreateStockTransferPage`'s `create.error` call shape.
2. **No `ar` translation for `create.error`.** `src/locales/ar/` has no `stock-adjustments.json` at all, so adding a lone `ar` key would create a new partial namespace. Flagged, not invented.
3. **Item C's NB-r2-1 and NB-r2-2 overlap and were done as one edit**, as the gate directed ("delete `:300-308` and move its *corrected* comment to `:446`"). The overclaim therefore has no separate diff hunk — it is the moved comment plus the test docblock.

---

## 4. Residuals

- **`useBlocker` / data-router migration** (registered per NB-r2-1): `useUnsavedChangesGuard` is `beforeunload`-only, so an in-app sidebar/breadcrumb navigation still unmounts `DocumentForm` and drops an unsent trailing autosave body with no prompt. Accepted behaviour (gate r2 R2-4), now honestly described in the code; the migration is what closes the window.
- **T13 promotion-owed, still open:** the browser double-click probe on both forms with the zero-5xx assertion, and MINOR-1's real-UUID capture during it. Item A closes only priority (2) of that list.
- **T8 R2-N6** (stale net-diff line in the T8 handback §1) is not in this lane's scope.
