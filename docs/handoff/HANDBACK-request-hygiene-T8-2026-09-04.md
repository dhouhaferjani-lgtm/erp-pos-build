# HANDBACK — Request Hygiene Phase A, Task 8

**Cooldown-only reconnect recovery (S-7)**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t8`
- Branch: `lane/rh-t8-reconnect`
- Base: `7f86dbf0c` (`Merge branch 'lane/rh-t2-stock-movements' into dev`)
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 8: Cooldown-only reconnect recovery (S-7)`
- Gate: **frontend-conventions-reviewer**
- Commits: see section 5.

**Contract as executed:** invalidate all *active* queries after a
disconnected→connected WebSocket transition; suppress only transitions inside a
30-second cooldown; **no reference-data allowlist**.

---

## 1. What landed

| File | Change |
|---|---|
| `apps/web/src/providers/WebSocketReconnectProvider.tsx` | Reconnect sweep is now explicit (`invalidateQueries({ refetchType: 'active' })`, previously a bare `invalidateQueries()`), and is gated by a `lastInvalidationAt = useRef<number \| null>(null)` sentinel against `RECONNECT_INVALIDATION_COOLDOWN_MS = 30_000`. `wasDisconnected` edge-detection is unchanged in behaviour, restructured to the plan's early-return shape. Doc comment records the no-allowlist decision and the untouched CompanySelector behaviour. |
| `apps/web/src/providers/WebSocketReconnectProvider.test.tsx` | **NEW.** The plan's complete fake-timer test, verbatim, with a real `vi.spyOn(client, 'invalidateQueries')` on a real `QueryClient` (no provider-internal mock). |

Net diff: 2 files, +85 / −3.

No other file touched. `apps/api` untouched.

---

## 2. Environment setup (per the dispatch brief)

- Worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t8`, branch `lane/rh-t8-reconnect`, base local `dev` `7f86dbf0c`.
- `apps/web/node_modules` present via symlink; no install required.
- Web-only lane. No `git stash` used. Path-scoped vitest only (`pnpm vitest run src/providers`).
- Leftover vitest workers checked after the runs: `ps aux | grep -c '[n]ode (vitest'` → `0`.

---

## 3. Step-by-step evidence

### Step 1 — red test (plan test, real spy)

Written verbatim from the plan block into `apps/web/src/providers/WebSocketReconnectProvider.test.tsx`.

```
$ pnpm vitest run src/providers
 ❯ src/providers/WebSocketReconnectProvider.test.tsx (1 test | 1 failed) 21ms
   × WebSocketReconnectProvider > invalidates all active queries on first reconnect and applies a 30 second cooldown
     → expected last "invalidateQueries" call to have been called with [ { refetchType: 'active' } ]

AssertionError: expected last "invalidateQueries" call to have been called with [ { refetchType: 'active' } ]
- Expected
+ Received
- [
-   {
-     "refetchType": "active",
-   },
- ]
+ []
 ❯ src/providers/WebSocketReconnectProvider.test.tsx:41:17

 Test Files  1 failed (1)
      Tests  1 failed (1)
```

The pre-change provider already invalidated once per reconnect edge, so the
first falsifying assertion is the argument shape (`[]` vs `{ refetchType:
'active' }`). The cooldown assertions (`toHaveBeenCalledTimes(1)` after the
+10 s flap) were unreachable in the red run and are proved green below.

### Step 2 — implementation (nullable sentinel)

Implemented exactly as the plan's Step 2 snippet: `RECONNECT_INVALIDATION_COOLDOWN_MS = 30_000`,
`lastInvalidationAt = useRef<number | null>(null)`, `null`-checked cooldown
comparison, `lastInvalidationAt.current = now` before the `void
queryClient.invalidateQueries({ refetchType: 'active' })`.

Hook rules held: both `useRef`s and the single `useEffect` are unconditional at
the top of the component; the early returns are inside the effect body, not
around the hooks. No new set-state-in-effect: the provider sets no state at all
(it only writes refs), which is unchanged from the base version.

### Step 3 — green run

```
$ pnpm vitest run src/providers
 ✓ src/providers/WebSocketReconnectProvider.test.tsx (1 test) 11ms

 Test Files  1 passed (1)
      Tests  1 passed (1)
```

**1 file, 1 test, 1 passed, 0 failed.** The single test carries three
independent reconnect edges: first reconnect → 1 sweep with `{ refetchType:
'active' }`; a reconnect at +10 s → still 1 (suppressed inside the cooldown); a
reconnect at +30 s → 2 (cooldown expired).

### Step 4 — CompanySelector left alone (plan Step 3)

`apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:45` still
reads `void queryClient.invalidateQueries()` (whole-cache, company switch) and
is not in this lane's diff. Its two existing assertions
(`CompanySelector.test.tsx:126`, `:158` — `toHaveBeenCalledOnce()`) are
untouched.

### Step 4 — authenticated-layout gate

The dispatch brief's gate: confirm the provider is mounted only inside the
authenticated layout and that an unauthenticated reconnect cannot invalidate.
Evidence chain, all in `apps/web/src`:

| Fact | Evidence |
|---|---|
| The provider has exactly one production mount site | `grep -rn "WebSocketReconnectProvider" apps/web/src` → one import + one JSX mount in `components/templates/DashboardLayout/DashboardLayout.tsx:8` and `:47` (closing `:63`); the only other hits are the provider itself and a `vi.mock` in `DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx:35`. |
| That mount is inside `DashboardLayout` | `apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:47` wraps `<main>` and the router `<Outlet />`. |
| `DashboardLayout` (re-exported as `Layout` at `components/layout/Layout.tsx:2`) is rendered only under `RequireAuth` | `apps/web/src/routes/index.tsx:554-560`: `<Route path="/" element={<RequireAuth><Layout /></RequireAuth>}>`. |
| `RequireAuth` does not render children when unauthenticated | `apps/web/src/features/auth/AuthProvider.tsx:127-143`: `isLoading` → spinner; `!isAuthenticated` → `<Navigate to="/login" replace />`; children only on the authenticated path. |
| The admin layout does **not** mount it | `routes/index.tsx:417-424` mounts `AdminLayout` under `RequireAdminAuth`; `AdminLayout.tsx` has no `WebSocketReconnectProvider` (absent from the grep above). |
| The fullscreen POS/KDS routes do **not** mount it | `routes/index.tsx:3202` and `:3215` are outside `Layout`; the grep shows no other mount. |

So an unauthenticated visitor never mounts the provider, the reconnect effect
never runs for them, and no invalidation can be triggered. The provider is
unmounted the moment `RequireAuth` flips to the redirect, which also resets both
refs — a post-logout/re-login reconnect starts from a fresh cooldown window.
That is the intended behaviour (a fresh session should recover its gap).

### Verification commands

| Command | Result |
|---|---|
| `pnpm vitest run src/providers` | **1 file / 1 test passed**, 0 failed |
| `pnpm typecheck` (`tsc --noEmit`, whole `apps/web`) | clean, no output, exit 0 |
| `pnpm exec eslint src/providers/WebSocketReconnectProvider.tsx src/providers/WebSocketReconnectProvider.test.tsx --format json` | `WebSocketReconnectProvider.tsx errors=0 warnings=0`; `WebSocketReconnectProvider.test.tsx errors=0 warnings=0` |

**ESLint before/after (per file).** Baseline taken by writing
`git show 7f86dbf0c:apps/web/src/providers/WebSocketReconnectProvider.tsx` to a
temporary `src/providers/__baseline_WebSocketReconnectProvider.tsx` (inside
`src/` so the same flat-config block applies), linting it, then deleting it —
the temp file is not in the diff or in `git status`.

| File | Before (`7f86dbf0c`) | After |
|---|---|---|
| `providers/WebSocketReconnectProvider.tsx` | errors=0 warnings=0 | errors=0 warnings=0 |
| `providers/WebSocketReconnectProvider.test.tsx` | n/a (new file) | errors=0 warnings=0 |

No new errors, no new warnings.

### Not run: browser reconnect probe

The plan's Step 4 asks for an authenticated-layout browser reconnect probe
through `DashboardLayout`. **Not performed in this lane — no stack is running
for this worktree** (no API, no Reverb/WSS, no vite server). It is recorded as
**promotion-owed**. Nothing in this lane can substitute for that.

**Probe steps (amended in fix round 1 — gate finding N-2).** On a running
stack, with the browser network panel open:

0. Log in and land on the dashboard. Watch the initial socket connect (~1 s
   after `DashboardLayout` mounts): expect **NO** refetch burst. The initial
   connect is not a reconnect and does not arm the cooldown (fix round 1, N-1).
   A burst here means the `hasEverConnected` guard has regressed.
1. **Wait > 30 s idle after login**, then kill the WebSocket and restore it →
   exactly **one** burst of refetches for the mounted queries.
2. Kill/restore again **within 30 s** of that burst → **no** burst.
3. Wait past 30 s from the last burst, kill/restore → burst again.

The `> 30 s idle` wait in step 1 is a leftover safety margin, not a
correctness requirement: after the N-1 fix the cooldown is unarmed at login, so
step 1 would also pass immediately. Keeping the wait makes step 1 independent of
whether step 0 held.

---

## 4. Deviations from the plan text

1. **Test file content: none.** The Step 1 block was used verbatim, including
   the `children: ReactNode = <div />` default and the `10_000` / `20_000`
   advances.
2. **Implementation: none functionally.** The Step 2 snippet was adopted as
   written. Two purely cosmetic additions outside the snippet: the
   `RECONNECT_INVALIDATION_COOLDOWN_MS` constant is placed at module scope (the
   snippet shows it above the `useRef`, which would recreate it per render and
   cannot be `const`-hoisted next to a hook in real code), and a doc comment on
   the constant plus an extended provider doc comment recording the
   no-allowlist decision and the untouched CompanySelector path. The plan's `if
   (…) return` one-liner is written with braces to satisfy the repo's ESLint
   config style (it lints clean either way; braces match the surrounding file).
3. **`useWebSocketConnection` mock shape.** The plan's mock returns only
   `{ isConnected }` while the real hook's `WebSocketConnectionState` has five
   fields. This is not a deviation (the provider destructures only
   `isConnected`) and `tsc --noEmit` accepts it, but it is worth the reviewer's
   eye: the mock will not catch a future provider change that starts reading
   `hasGivenUp` or `error`.
4. **Browser probe deferred** — see the section above. Recorded, not silently
   skipped.

No deviation was forced by the code; the implementation compiled and passed on
the first attempt.

---

## 4b. Fix round 1 (2026-09-04) — gate findings N-1, N-2, N-3

Gate report: `docs/superpowers/reviews/2026-09-04-request-hygiene-t8-gate-frontend-conventions.md`
(verdict MERGE, zero blockers). The orchestrator ruled N-1 must be fixed before
merge: the contract's "disconnected→connected transition" means a **re**-connect,
and the initial connect is not one.

### N-1 (MAJOR) — FIXED

`apps/web/src/providers/WebSocketReconnectProvider.tsx` gains a
`hasEverConnected = useRef(false)`. The first observed `isConnected === true`
sets the ref, consumes the `wasDisconnected` edge, and returns **without**
invalidating and **without** writing `lastInvalidationAt`. Only later
disconnected→connected edges invalidate, under the unchanged 30 s cooldown.

Consequence: the redundant full sweep on every login is gone, and the cooldown
is no longer burnt at t≈1 s, so a genuine drop at t=15 s / reconnect at t=17 s
is now recovered instead of suppressed.

**Red first.** New test
`ignores the initial connect: only a genuine reconnect invalidates and arms the cooldown`
reproduces the gate's measured scenario exactly — mount at `isConnected: false`,
connect at t=1 s (expect **0** sweeps), genuine drop t=15 s / reconnect t=17 s
(expect **1**), reconnect t=20 s inside the cooldown (still **1**), reconnect at
t=47 s = exactly 30 s after the sweep (expect **2**).

```
$ pnpm vitest run src/providers          # RED, before the provider change
 ❯ src/providers/WebSocketReconnectProvider.test.tsx (2 tests | 1 failed) 16ms
   ✓ … invalidates all active queries on first reconnect and applies a 30 second cooldown 11ms
   × … ignores the initial connect: only a genuine reconnect invalidates and arms the cooldown 4ms
AssertionError: expected "invalidateQueries" to be called +0 times, but got 1 times
 ❯ src/providers/WebSocketReconnectProvider.test.tsx:76:17
 Test Files  1 failed (1)
      Tests  1 failed | 1 passed (2)
```

```
$ pnpm vitest run src/providers src/components/templates/DashboardLayout   # GREEN, after
 ✓ src/providers/WebSocketReconnectProvider.test.tsx (2 tests) 14ms
 ✓ src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx (2 tests) 20ms
 Test Files  2 passed (2)
      Tests  4 passed (4)
```

**The pre-existing three-edge test needed no setup change.** It mounts with
`connected = true`, so its very first effect run *is* the initial connect (which
never invalidated, before or after this fix) and its first *edge* — the
`false → true` after the deliberate dip — is already a genuine reconnect. It
passes unchanged, in the red run and in the green run.

**Mutation check on the new assertions** (both mutants applied to the shipped
provider, run, reverted; `src/providers/` contains only the two lane files):

| Mutant | Result |
|---|---|
| **A** — `hasEverConnected` guard present but the initial connect still writes `lastInvalidationAt` | **KILLED, both tests** — `expected "invalidateQueries" to be called 1 times, but got 0 times`. Proves the "does NOT arm the cooldown" half is load-bearing, not just the "does not invalidate" half. |
| **B** — `hasEverConnected` guard deleted (= behaviour at `a81235540`) | **KILLED, new test only** — `expected "invalidateQueries" to be called +0 times, but got 1 times`. The old test cannot see the bug; the new one does. |

### N-2 (MAJOR) — FIXED

The promotion probe in section 3 is amended: a new step 0 (no burst on the
initial connect) and the `> 30 s idle after login` wait before step 1.

### N-3 (MINOR) — FIXED

`WebSocketReconnectProvider.test.tsx` now types the mock factory as
`(): WebSocketConnectionState => ({ echo: null, isConnected: connected, isConnecting: false, error: null, hasGivenUp: false })`
with a `import type { WebSocketConnectionState }`. A future provider read of
`hasGivenUp` / `error` / `isConnecting` can no longer silently resolve to
`undefined`, and adding a field to the interface now breaks the mock at compile
time.

### N-4 — not actioned (correctly)

Untracked `apps/web/e2e-local/*` parse errors are off-lane housekeeping, as the
gate ruled. Untouched.

### Fix-round verification

| Command | Result |
|---|---|
| `pnpm vitest run src/providers src/components/templates/DashboardLayout` | **2 files / 4 tests passed**, 0 failed |
| `pnpm typecheck` (`tsc --noEmit`, whole `apps/web`) | clean, no output, exit 0 |
| `pnpm exec eslint` on both lane files, before (`a81235540`, temp copies inside `src/providers/`, deleted) vs after | before `errors=0 warnings=0` / `errors=0 warnings=0`; after `errors=0 warnings=0` / `errors=0 warnings=0` — **0 new errors, 0 new warnings** |
| `ps aux \| grep -c '[n]ode (vitest'` | `0` — no leftover workers |

### Fix-round deviations

- **The existing three-edge test was NOT adjusted.** The fix-round brief
  anticipated its first edge might be the initial connect; it is not (see
  above), so changing its setup would have removed coverage for no reason. Both
  tests pass as written.
- Two mutants were run against the provider to prove the new assertions
  falsify; both files were restored from a copy and `git status` is clean.

---

## 5. Commits

| Hash | Subject |
|---|---|
| `26d971bb4` | `fix(web request-hygiene t8): cooldown-only reconnect invalidation (S-7)` |
| `a81235540` | `docs(request-hygiene t8): handback` |
| fix round 1 | `fix(web request-hygiene t8): ignore the initial connect — only true reconnects invalidate and arm the cooldown` |
| fix round 1 | `docs(request-hygiene t8): gate r1 report + handback fix round 1 (probe steps amended)` (this edit) |

Both commits are path-scoped (`git commit … -- <paths>`), per the shared-checkout
rule. `git status` is clean after the second.

---

## 6. What the reviewer should look at

1. **The sentinel semantics.** `lastInvalidationAt` is `number | null`, so a
   first reconnect at `Date.now() === 0` (the test's own system time) still
   invalidates — a `0` initial value would have silently suppressed it inside
   the first 30 s of epoch-anchored fake time. That is the reason the plan
   specified nullable, and it is the shape shipped.
2. **`refetchType: 'active'` is a real narrowing, not a no-op restatement.** It
   matches TanStack Query v5's default, so runtime refetch behaviour is
   unchanged, but it pins the contract against a future default change and is
   the assertion the red test hangs on.
3. **The cooldown is on the *invalidation*, not on the socket.** A flap that is
   suppressed does not arm a deferred sweep — the missed window is recovered by
   the next reconnect after the cooldown, or by normal `staleTime` refetching.
   If the reviewer believes a suppressed gap must still be recovered eventually,
   that is a contract question for the orchestrator, not a defect in this
   implementation (the plan's contract says "suppress", full stop).
4. **No allowlist.** Deliberate per the plan and the rev-7 dispatch table
   ("Reconnect invalidates all active queries with only a tested 30-second
   cooldown"). Any reviewer instinct to scope this to reference data contradicts
   the gate-cleared contract.
5. **The authenticated-layout evidence in section 3** — specifically
   `routes/index.tsx:554-560` + `AuthProvider.tsx:138-141` as the pair that makes
   an unauthenticated reconnect unreachable.
6. **The promotion-owed browser probe** — this lane cannot claim S-7 verified
   end-to-end without it.
