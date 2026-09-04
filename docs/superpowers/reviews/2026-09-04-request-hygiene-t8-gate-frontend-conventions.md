# GATE — Request Hygiene Phase A, Task 8 (cooldown-only reconnect recovery, S-7)

- **Gate:** frontend-conventions-reviewer (adversarial merge gate)
- **Date:** 2026-09-04
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t8`
- **Branch:** `lane/rh-t8-reconnect` — base `7f86dbf0c`, commits `26d971bb4` (code+test), `a81235540` (handback)
- **Diff:** 3 files, +313 / −3 — `apps/web/src/providers/WebSocketReconnectProvider.tsx`, `apps/web/src/providers/WebSocketReconnectProvider.test.tsx` (new), `docs/handoff/HANDBACK-request-hygiene-T8-2026-09-04.md` (new)
- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 8`
- **Contract:** invalidate all active queries after a disconnected→connected transition; suppress only transitions within 30 s; no reference-data allowlist.

---

## VERDICT: **MERGE**

Zero blocking findings. Implementation matches the plan's Step 2 snippet exactly; the shipped test is mutation-proven (three independent mutants killed, below); guardrails are clean and the lane introduces **zero** new lint/audit/type findings. Three non-blocking findings are recorded, one of which **changes the promotion browser probe** and must be carried into the promotion checklist.

---

## 1. Blocking findings

**None.**

---

## 2. Non-blocking findings

### N-1 (MAJOR) — The first socket connect after mount is classified as a "reconnect", and it burns the cooldown, so the first *genuine* drop within 30 s of page load is silently never recovered

- `apps/web/src/providers/WebSocketReconnectProvider.tsx:34` — `const wasDisconnected = useRef(false)`
- `apps/web/src/providers/WebSocketReconnectProvider.tsx:38-41` — the effect sets `wasDisconnected.current = true` on the very first run
- `apps/web/src/hooks/useWebSocketConnection.ts:52-58` — the real hook's initial state is `{ echo: null, isConnected: false, isConnecting: true, error: null, hasGivenUp: false }`

Because the hook starts at `isConnected: false`, the provider's first effect run always arms `wasDisconnected`. The first *successful* connect (~1 s after `DashboardLayout` mounts, i.e. on every page load and every login) therefore fires a full active-query sweep over queries that were fetched moments earlier — and, new in this diff, sets `lastInvalidationAt` (`:54`), so the cooldown is already burnt at t≈1 s.

**Falsifying scenario (measured, real provider, not a mutant):**

```
t=0s    mount, isConnected=false        (real hook initial state)
t=1s    isConnected=true                -> invalidateQueries #1   (redundant: page just loaded)
t=15s   GENUINE socket drop
t=17s   GENUINE reconnect               -> SUPPRESSED (17s - 1s = 16s < 30s)
t=617s  still connected, no further edge -> still 1 call total
```

Probe result: `Test Files 1 passed (1) / Tests 1 passed (1)` — the assertions `toHaveBeenCalledTimes(1)` at t=17 s and at t=617 s both hold. The real gap at t=15-17 s is **never** recovered by this provider.

Attribution, honestly: the *classification* of the initial connect as a reconnect is pre-existing (base `7f86dbf0c` behaved identically). What is **new in this diff** is that the redundant page-load sweep now consumes the 30 s window and suppresses a subsequent real reconnect that the base implementation would have recovered. This is literally contract-conformant ("suppress only transitions within 30 s" — this *is* a transition within 30 s), so it is not a gate blocker and I am not re-litigating the gate-cleared contract. It is an orchestrator contract question.

Fix directive (follow-up lane, not this one): add a `hasEverConnected` ref so the initial `false → true` transition is not treated as a reconnect, leaving the cooldown unarmed for the first real drop; alternatively seed `wasDisconnected` from `isConnecting`.

### N-2 (MAJOR) — The promotion browser probe as written in the handback will produce a false negative

`docs/handoff/HANDBACK-request-hygiene-T8-2026-09-04.md` §3 "Not run: browser reconnect probe" instructs: *"log in, open the network panel, kill and restore the WebSocket, and confirm (a) one burst of refetches ... on the first reconnect"*.

Per N-1, logging in already consumes the cooldown. If the tester kills the socket within 30 s of login, step (a) shows **no burst** and the probe reads as a failure of a correctly-implemented cooldown.

Fix directive: amend the probe to *"log in, wait > 30 s idle, then kill/restore"* for step (a); step (b) (second reconnect inside 30 s → no burst) and step (c) (after 30 s → burst) are unchanged. Also add an explicit step (0): observe that a full active-query burst already occurs ~1 s after login — that is N-1, expected today.

### N-3 (MINOR) — The `useWebSocketConnection` mock is untyped against `WebSocketConnectionState` (handback deviation 3)

`apps/web/src/providers/WebSocketReconnectProvider.test.tsx:7-9` returns `{ isConnected: connected }` while `apps/web/src/hooks/useWebSocketConnection.ts:5-21` declares five fields.

Ruling: **not a defect, and not worth blocking, but the handback's own concern is correct and should be actioned cheaply.** A *rename* of `isConnected` is already caught (the provider destructures it at `:32` and `tsc --noEmit` would fail). The real uncovered case is a future provider change that starts reading `hasGivenUp` / `error` / `isConnecting`: the mock silently yields `undefined` (falsy), and the test would pass against broken logic.

Fix directive: type the factory return — `useWebSocketConnection: (): WebSocketConnectionState => ({ echo: null, isConnected: connected, isConnecting: false, error: null, hasGivenUp: false })` — so adding a field to the interface breaks the mock at compile time. One line; may ride along in any future touch of this file.

### N-4 (MINOR, housekeeping, NOT this lane) — untracked `apps/web/e2e-local/` artifacts make `pnpm lint:eslint` red in this worktree

All 5 eslint **errors** in a full `pnpm lint` run are `Parsing error: "parserOptions.project" ... file was not found in any of the provided projects` in `apps/web/e2e-local/{pw.config.ts, wave2-po.part1.spec.ts, wave2-po.part2.spec.ts, wave2-shared.ts, wave2-support.ts}`. These are **untracked** Session-L wave-2 Playwright leftovers (`git ls-files apps/web/e2e-local` → empty; `git status --porcelain` → clean, so they are ignored/untracked local files). They exist in the main checkout too. Zero relationship to T8. `eslint src` (tracked source only) → **0 errors**.

---

## 3. What held up (verification of the gate checklist)

### 3.1 Provider correctness — HOLDS

| Check | Result |
|---|---|
| `RECONNECT_INVALIDATION_COOLDOWN_MS = 30_000` at **module scope** | HOLDS — `WebSocketReconnectProvider.tsx:15` (outside the component; the plan snippet showed it beside the `useRef`, which would re-create it per render — the lane's placement is the correct deviation) |
| `lastInvalidationAt` is null-checked | HOLDS — `:35` `useRef<number \| null>(null)`, `:48` `lastInvalidationAt.current !== null`. Load-bearing: with `useRef(0)` the first sweep at `Date.now() === 0` (the test's own system time) would be suppressed |
| Transition detection is **edge**-based, not level-based | HOLDS — `:43` `if (!wasDisconnected.current) return`, guarded by deps `[isConnected, queryClient]` at `:56`. A stable connected state cannot re-invalidate: the effect does not re-run on unrelated re-renders (deps unchanged), and even if `queryClient` identity churned, `wasDisconnected.current === false` early-returns. Mutation-proven indirectly: the shipped test's three edges each require a `false` dip |
| `invalidateQueries({ refetchType: 'active' })` | HOLDS — `:55`. Matches TanStack Query v5's default, so runtime behaviour is unchanged; it pins the contract against a default change |
| Hooks unconditional | HOLDS — `:32`, `:33`, `:34`, `:35`, `:37` all at the top of the component body; every `return` is **inside** the effect callback, not around a hook |
| No state set | HOLDS — no `useState`, no setter anywhere in the file; the provider writes refs only and renders `<>{children}</>` at `:58` |
| Unmount/remount on logout/login yields fresh refs | HOLDS and is acceptable — `RequireAuth` (`AuthProvider.tsx:138-141`) unmounts the subtree on logout, so both refs reset and a fresh session recovers its gap from a clean cooldown window |
| Edge is consumed even when suppressed | HOLDS — `:44` `wasDisconnected.current = false` executes **before** the cooldown check at `:47-52`, so a suppressed edge cannot re-fire on a later render |
| No reference-data allowlist | HOLDS — no allowlist, no key filter; only `refetchType`. Doc comment `:24-29` records the decision |
| CompanySelector untouched | HOLDS — not in the diff (`git diff --name-only 7f86dbf0c..a81235540` = 3 files) |

### 3.2 Cooldown semantics — RULED CORRECT

- **Measured from the last *invalidation*, not the last reconnect.** `lastInvalidationAt.current = now` at `:54` is reached **only** on the invalidating path; the suppressed path returns at `:51` without touching it. This is the contract-correct choice.
- **Flapping every 10 s for 5 minutes produces one sweep per 30 s window, not zero.** Edges at t = 0, 10, 20, 30, 40 … → sweeps at 0, 30, 60, … Directly exercised by the shipped test (sweep at 0, suppressed at 10, sweep at 30). Had the cooldown been anchored to the last *reconnect*, the +30 s edge would compare against t=20 and suppress forever — the test kills that.
- **A suppressed edge is lost entirely; there is no deferred sweep.** Confirmed by reading (`:51` is a bare `return`, no timer, no pending flag) and measured in the N-1 probe: still 1 call after 10 further minutes of stable connection. This is what the contract's "suppress, full stop" asks for. Recovery for the lost window falls back to normal `staleTime` refetching or the next post-cooldown reconnect. Recorded, not a defect.

### 3.3 Test quality — HOLDS, and is mutation-proven

`WebSocketReconnectProvider.test.tsx` is the plan Step 1 block verbatim: fake timers (`:22-23` `vi.useFakeTimers()` + `vi.setSystemTime(0)`, `:27` `vi.useRealTimers()`), a **real** `QueryClient` with `vi.spyOn(client, 'invalidateQueries')` at `:32` (not a mocked QueryClient), three edges via `view.rerender` at `:34-37`, `:43-47`, `:49-53`.

**Boundary: exercised at exactly 30 000 ms, inclusive.** `setSystemTime(0)` → first sweep records `lastInvalidationAt = 0`; `advanceTimersByTime(10_000)` then `advanceTimersByTime(20_000)` puts the third edge at exactly `t = 30_000`, i.e. `30_000 - 0` compared against `< 30_000` → invalidates. Proven load-bearing by mutant M3 below.

**Falsification against base and against partial implementations.** I built three throwaway variants inside `src/providers/` (`__gate_*`, deleted afterwards; `git status --porcelain` is empty and `ls src/providers/` shows only the two lane files) and ran the shipped test body against each:

| Mutant | Result |
|---|---|
| **M1 = base `7f86dbf0c` verbatim** (bare `invalidateQueries()`) | **KILLED** at `:41` — `expected last "invalidateQueries" call to have been called with [ { refetchType: 'active' } ]`, received `[]` |
| **M2 = base + `{ refetchType: 'active' }`, NO cooldown** | **KILLED** at `:51` — `expected "invalidateQueries" to be called 1 times, but got 2 times` |
| **M3 = shipped provider with `<=` instead of `<`** (off-by-one on the boundary) | **KILLED** at the third edge — `expected "invalidateQueries" to be called 2 times, but got 1 times` |

**Ruling on the handback's "unreachable red" caveat: no second test is needed.** The handback correctly reports that in the *actual* red run the first failing assertion was the argument shape, so the cooldown assertions never executed. M2 settles the open question empirically: once the argument shape is satisfied, the cooldown assertion at `:51` is the next thing to fail, and it fails on the exact absence of the cooldown. The single shipped test is genuinely falsifying on all three axes (argument shape, cooldown presence, cooldown boundary). A second test would add nothing.

### 3.4 Authenticated-layout gate — HOLDS (confirmed by grep, not taken on the handback's word)

`grep -rn "WebSocketReconnectProvider" apps/web/src` → 9 hits, exhaustively:

- `src/providers/WebSocketReconnectProvider.tsx:5,31` — the definition
- `src/providers/WebSocketReconnectProvider.test.tsx:5,15,20` — the new test
- `src/components/templates/DashboardLayout/DashboardLayout.tsx:8` (import), `:47` (open), `:63` (close) — **the one and only production mount**
- `src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx:35-36` — a `vi.mock` pass-through

Chain: `DashboardLayout.tsx:47-63` wraps `<main>` + `<Outlet />` → re-exported as `Layout` → `routes/index.tsx:554-560` `<Route path="/" element={<RequireAuth><Layout /></RequireAuth>}>` → `AuthProvider.tsx:138-141` returns `<Navigate to="/login" replace />` when `!isAuthenticated`, children only on the authenticated path.

**Admin / POS / KDS do not mount it** — the grep above is exhaustive over `apps/web/src` and contains no `AdminLayout`, POS or KDS hit. An unauthenticated visitor never mounts the provider, so no unauthenticated reconnect can invalidate.

Mount-site regression check: `pnpm vitest run src/components/templates/DashboardLayout` → **1 file / 2 tests passed**.

### 3.5 Baseline honesty & mechanism audit — CLEAN

- `git diff --name-only 7f86dbf0c..a81235540 -- 'apps/web/tools/*baseline*'` → **empty**. No baseline was rewritten, no `--write-baseline` absorption.
- No indirection that defeats a detector: the diff adds no alias table, no suppression comment, no renamed-equivalent literal. It adds one module-scope numeric constant and one ref.
- No Tailwind classes at all in the diff (the provider renders `<>{children}</>`), so the interpolated-token / dead-CSS class of bug is not in scope. `audit:design-system` reports **zero** hits on `WebSocketReconnectProvider`.
- Second-of-everything (`docs/conventions/09`): **N/A** — no catalogue entity, no migration, no unique key.
- One-surface-per-concept (`docs/conventions/11`): **N/A** — no new domain noun; `RECONNECT_INVALIDATION_COOLDOWN_MS` is a module-private constant, not a glossary term. No hand-rolled FE type introduced.
- Owner-ruled UI principles: **N/A** — no visual surface, no copy, no brand string, no disabled control, no route, no gate.

---

## 4. Commands and outputs

All commands run by me in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t8/apps/web` unless noted. Nothing accepted from the handback without re-running it.

```
$ pnpm vitest run src/providers
 RUN  v3.2.4 /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t8/apps/web
 ✓ src/providers/WebSocketReconnectProvider.test.tsx (1 test) 11ms
 Test Files  1 passed (1)
      Tests  1 passed (1)
   Duration  1.05s
exit=0
```

```
$ pnpm typecheck            # tsc --noEmit, whole apps/web
(no output)
exit=0
```

```
$ pnpm vitest run src/components/templates/DashboardLayout
 ✓ src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx (2 tests) 17ms
 Test Files  1 passed (1)
      Tests  2 passed (2)
exit=0
```

### ESLint per-file before/after (baseline written to a temp path inside `src/`, then deleted)

```
$ git show 7f86dbf0c:apps/web/src/providers/WebSocketReconnectProvider.tsx \
    > src/providers/__gate_baseline_WebSocketReconnectProvider.tsx
$ pnpm exec eslint src/providers/__gate_baseline_WebSocketReconnectProvider.tsx
(no output)   exit=0
$ pnpm exec eslint src/providers/WebSocketReconnectProvider.tsx src/providers/WebSocketReconnectProvider.test.tsx
(no output)   exit=0
```

| File | Before (`7f86dbf0c`) | After |
|---|---|---|
| `src/providers/WebSocketReconnectProvider.tsx` | errors=0 warnings=0 | errors=0 warnings=0 |
| `src/providers/WebSocketReconnectProvider.test.tsx` | n/a (new) | errors=0 warnings=0 |

**Lint delta: 0 new errors, 0 new warnings.** Independently corroborated: `eslint src --format json` on the lane → `errors=0 warnings=6448`, and no warning is located in `src/providers/`.

### Full `pnpm lint` (protocol requires it; all failures attributed off-lane)

`pnpm lint` = `lint:eslint && audit:keys && audit:design-system && audit:quantity && audit:i18n:local && test:eslint-rules && test:tools`. It is **red on this branch, and red on `dev` itself**. Each stage attributed:

| Stage | Lane result | Attribution |
|---|---|---|
| `lint:eslint` | `6459 problems (5 errors, 6454 warnings)` | All 5 errors are parse errors in **untracked** `apps/web/e2e-local/*` (N-4). `eslint src` → **0 errors**. Not T8. |
| `audit:keys` | 1 new: `src/features/uom/hooks/useUnits.ts:53:9` | **Not T8** — file not in the diff. Already fixed on `dev`: main checkout on `dev` `5edb7e810` reports `Gate C baseline: 0 acknowledged, 0 new, 0 stale`. The lane's base `7f86dbf0c` predates the `lane/rh-web-lint-debt` merge; the finding disappears on merge. |
| `audit:design-system` | lane: `811 violations / 796 acknowledged, 15 new, 11 stale`; **dev: `810 / 796 acknowledged, 14 new, 11 stale`** | **Not T8.** Pre-existing repo-wide debt, red on `dev` too. Per-file diff of the two new-violation lists is **identical** (`diff` → empty). Zero hits on `WebSocketReconnectProvider`; the diff contains no `className`/styling. The ±1 total is base-vs-dev drift in files outside the diff. |
| `audit:quantity` | `0 total (0 baselined, 0 new, 0 stale)` | GREEN |
| `audit:i18n:local` | red — missing `ar\|uom\|*` keys, `fr\|import\|plural\|unitErrors.line_many`; "9 baseline entries now translated (burn-down)" | **Not T8** — no i18n key touched; the diff adds no user-facing text. |
| `test:eslint-rules` | all 6 RuleTesters pass (`no-dead-tailwind-token-interpolation` 5v/5i, `no-hardcoded-step` 6v/3i, `no-literal-decimal-places` 6v/3i, `no-parsefloat-on-money` 10v/5i, `no-untranslated-literal` 10v/6i, `no-hardcoded-entity-route` 8v/4i) | GREEN |
| `test:tools` | `Test Files 8 passed (8) / Tests 160 passed (160)` | GREEN |

**Net: T8 contributes zero new findings to any stage.**

### Hygiene

```
$ git status --porcelain          # in the worktree, after deleting all __gate_* probe files
(empty)
$ ls src/providers/
WebSocketReconnectProvider.test.tsx
WebSocketReconnectProvider.tsx
$ ps aux | grep -c '[n]ode (vitest'
0
```

No leftover vitest workers. No tracked file was modified by this review.

---

## 5. Merge-tree (read-only, from the main checkout)

```
$ git merge-tree --write-tree dev lane/rh-t8-reconnect
1b13291d80e0d264b7cbdb2c6b9787856d5faf6e
```

**Clean — no conflicts** (a bare tree OID with no `CONFLICT`/`Auto-merging` block).

`dev` has advanced from the lane's base `7f86dbf0c` to `5edb7e810` (`Merge branch 'lane/rh-web-lint-debt' into dev`). Semantic drift check on the touched paths:

```
$ git diff 7f86dbf0c 5edb7e810 -- apps/web/src/providers/WebSocketReconnectProvider.tsx
(empty)
$ git log --oneline 7f86dbf0c..5edb7e810 -- apps/web/src/providers
(empty)
```

No drift; no other lane touched `apps/web/src/providers`. The merged tree carries the change intact — `git cat-file -p 1b13291d8:apps/web/src/providers/WebSocketReconnectProvider.tsx` shows the nullable sentinel, the `< RECONNECT_INVALIDATION_COOLDOWN_MS` comparison and `invalidateQueries({ refetchType: 'active' })` exactly as on the lane.

---

## 6. Promotion-owed

**The authenticated-layout browser reconnect probe was NOT performed** — no stack is running for this worktree (no API, no Reverb/WSS, no vite). The handback records this honestly and does not claim it. **S-7 is not verified end-to-end by this gate, and nothing in this lane substitutes for that probe.** It stays a promotion precondition, with the amendment in finding N-2:

0. Log in; observe a full active-query burst ~1 s after `DashboardLayout` mounts (expected today — finding N-1).
1. **Wait > 30 s idle**, then kill the WebSocket and restore it → exactly one burst of refetches for mounted queries.
2. Kill/restore again within 30 s → **no** burst.
3. Wait past 30 s, kill/restore → burst again.

---

*Reviewed read-only. No file on `lane/rh-t8-reconnect` was modified; nothing was merged or pushed.*

---

## Appendix — fix round 1 outcome (added by the lane, 2026-09-04; the review body above is unmodified)

The orchestrator ruled N-1 must be fixed before merge (the contract's
"disconnected→connected transition" means a **re**-connect). Actioned on the lane:

| Finding | Status |
|---|---|
| **N-1** (MAJOR) | **FIXED** — `hasEverConnected` ref in `WebSocketReconnectProvider.tsx`; the first observed `isConnected === true` neither invalidates nor writes `lastInvalidationAt`. Red-first test `ignores the initial connect: …` reproduces this report's measured t=1 s / t=15 s / t=17 s scenario and now expects 0 → 1 → 1 (t=20 s) → 2 (t=47 s). Two mutants killed (initial connect still arming the cooldown; guard removed). |
| **N-2** (MAJOR) | **FIXED** — handback §3 probe amended: step 0 now expects **no** burst on the initial connect, and step 1 keeps the `> 30 s idle after login` wait as a safety margin. |
| **N-3** (MINOR) | **FIXED** — the mock factory is typed `(): WebSocketConnectionState`, all five fields supplied. |
| **N-4** (MINOR) | **Not actioned**, correctly — off-lane untracked `apps/web/e2e-local/*`. |

Because the initial connect no longer arms the cooldown, §6 step 0 of this
report ("observe a full active-query burst ~1 s after login — expected today")
no longer describes shipped behaviour; the handback's amended step 0 supersedes
it. Evidence: `docs/handoff/HANDBACK-request-hygiene-T8-2026-09-04.md` §4b.

---

# Re-gate r2 (2026-09-04)

- **Scope:** targeted re-gate of fix round 1 — `f5b8eafb9` (code+test) and `f634e13cb` (docs) on `lane/rh-t8-reconnect`, on top of the r1-gated `26d971bb4` / `a81235540`.
- **Fix-round diff:** 2 source files, +64 / −1 — `apps/web/src/providers/WebSocketReconnectProvider.tsx` (+16), `apps/web/src/providers/WebSocketReconnectProvider.test.tsx` (+49/−1). Docs commit adds the r1 report and handback §4b.
- **Method:** read-only. No tracked file modified by this review; mutants built as throwaway `__gate_r2_*` copies inside `src/providers/` and deleted (`git status --porcelain` empty, `ls src/providers/` shows only the two lane files).

## VERDICT: **MERGE**

**Zero blocking findings.** N-1 is genuinely fixed at the mechanism level (not by weakening a test), N-2 and N-3 are fixed as claimed, the original three-edge test is **not** redundant (proven by a mutant only it kills), and the lane still contributes zero new lint/type/audit findings. Two new MINOR non-blocking residuals are recorded below.

---

## R2.1 — `hasEverConnected` semantics (checklist item 1)

`apps/web/src/providers/WebSocketReconnectProvider.tsx:42` `const hasEverConnected = useRef(false)`; the guard is `:51-57`, **before** the `wasDisconnected` check at `:59` and before the cooldown block at `:62-68`.

```
:53    if (!hasEverConnected.current) {
:54      hasEverConnected.current = true
:55      wasDisconnected.current = false
:56      return
:57    }
```

| Property | Verdict |
|---|---|
| First observed `isConnected === true` sets the ref | HOLDS — `:54` |
| …consumes the disconnected edge | HOLDS — `:55` clears `wasDisconnected` inside the guard, so the (real) first-run `:47` arming cannot re-fire later |
| …does NOT invalidate | HOLDS — `:56` returns before `:71` |
| …does NOT write `lastInvalidationAt` | HOLDS — `:70` is unreachable from the guard path; `lastInvalidationAt` stays `null`, so the *next* genuine edge passes the `!== null` check at `:64` unconditionally |
| Later edges invalidate under the 30 s cooldown | HOLDS — `:59-71` unchanged from `26d971bb4` |
| Hook rules | HOLD — `:39-43` are five unconditional hook calls; every `return` is inside the effect body |

### Edge cases

**(a) mount while already connected** (the original test's setup) — **no sweep.** First effect run sees `isConnected === true`, `hasEverConnected` false → consumed at `:53-57`; `wasDisconnected` was already `false`, so nothing changes. Not merely reasoned: mutant **A** (below) kills the *original* test at `:47` precisely because it perturbs this mount-connected path, so (a) is under assertion.

**(b) mount disconnected → connect → disconnect → reconnect within 30 s of the initial connect** — **sweeps.** This is exactly the new test (`WebSocketReconnectProvider.test.tsx:65-103`): connect at t=1 s → 0 calls; drop t=15 s / reconnect t=17 s → 1 call with `{ refetchType: 'active' }`. The cooldown is unarmed at t=17 s (`lastInvalidationAt === null`), so the r1 falsifying scenario is closed. Note the first effect run at mount genuinely sets `wasDisconnected = true` at `:47` (the hook's `useState` initial `isConnected: false` at `useWebSocketConnection.ts:52-58` is what the provider's first effect observes); the guard is therefore load-bearing on the real hook, not only on the mock.

**(c) logout/login remount** — refs reset (`RequireAuth` unmounts the subtree, `AuthProvider.tsx:138-141`), so the new session's first connect is again ignored. **Acceptable**, as the orchestrator scoped: the queries also remount and fetch on the new session, so there is nothing to recover at that instant.

**(d) `hasGivenUp` → later reconnect — RULING: it depends on whether the socket had *ever* connected, and only the never-connected sub-case changes behaviour.**

The provider destructures only `isConnected` (`:39`); `hasGivenUp` is a UI-only flag. `useWebSocketConnection.ts:75-83` `giveUp()` sets `isConnecting: false, hasGivenUp: true` and explicitly returns `prev` untouched when `prev.isConnected` — it never calls `disconnect()`, and the `connected` binding at `:97-109` stays live, so pusher can still connect later and flip `isConnected` back to `true`.

- **(d1) connected → dropped → gave up (3 `unavailable`/`failed`) → later reconnects:** `hasEverConnected` is already `true` and `wasDisconnected` is `true` → **treated as a genuine reconnect, sweeps** (subject to the cooldown vs the last sweep). This is the important sub-case and it is correct.
- **(d2) never connected (give-up at `CONNECTION_GIVE_UP_MS = 15000` or after 3 failures) → socket finally connects at, say, t=40 s:** still the **first** observed connect → classified as initial → **no sweep**. Real-time events emitted between page load and that late connect are not recovered by this provider (they fall back to `staleTime`). Pre-fix, this case *did* sweep. Recorded as finding **R2-N5** below — MINOR, not a blocker: it is the direct, contract-consistent consequence of the orchestrator's ruling ("the initial connect is not a reconnect"), and the alternative (keeping the login sweep) is the N-1 defect that was just fixed.

---

## R2.2 — New test and mutants (checklist item 2)

`WebSocketReconnectProvider.test.tsx:65-103` reproduces the r1 measured scenario **exactly**: `connected = false` at mount (`:66`), t=1 s connect → `toHaveBeenCalledTimes(0)` (`:76`); t=15 s drop / t=17 s reconnect → `1` + `toHaveBeenLastCalledWith({ refetchType: 'active' })` (`:85-86`); t=20 s edge → still `1` (`:94`); t=47 s = exactly 30 000 ms after the t=17 s sweep → `2` (`:102`). Boundary is exercised inclusively in **both** tests.

Mutants re-run by me (not taken from the handback), each as a copy of the shipped provider with the shipped test body pointed at it:

| Mutant | Result | Evidence |
|---|---|---|
| **A** — guard present, but the initial connect also writes `lastInvalidationAt = Date.now()` | **KILLED by BOTH tests** | new test `:85` `expected "invalidateQueries" to be called 1 times, but got 0 times`; original test `:47` same. Confirms the "does not arm the cooldown" half is independently asserted. |
| **B** — guard deleted entirely (= behaviour at `a81235540`) | **KILLED by the new test only** | `:76` `expected "invalidateQueries" to be called +0 times, but got 1 times`. Original test passes → the fix could not have been "proven" by the pre-existing test. |
| **E** (my addition, redundancy probe — see R2.3) | **KILLED by the ORIGINAL test only** | see below |

```
$ pnpm vitest run src/providers/__gate_r2_mutantA.test.tsx src/providers/__gate_r2_mutantB.test.tsx
 ❯ __gate_r2_mutantA.test.tsx (2 tests | 2 failed)
   × invalidates all active queries on first reconnect and applies a 30 second cooldown
     → expected "invalidateQueries" to be called 1 times, but got 0 times
   × ignores the initial connect: only a genuine reconnect invalidates and arms the cooldown
     → expected "invalidateQueries" to be called 1 times, but got 0 times
 ❯ __gate_r2_mutantB.test.tsx (2 tests | 1 failed)
   ✓ invalidates all active queries on first reconnect and applies a 30 second cooldown
   × ignores the initial connect: only a genuine reconnect invalidates and arms the cooldown
     → expected "invalidateQueries" to be called +0 times, but got 1 times
 Test Files  2 failed (2) / Tests  3 failed | 1 passed (4)
```

Both mutants **restored** (deleted); `git status --porcelain` empty afterwards.

---

## R2.3 — Is the original three-edge test now redundant? (checklist item 3) — **NO**

The handback's claim that the original test needed no setup change is **correct**: it mounts `connected = true` (`:31` `beforeEach`), so its first effect run is the initial connect (consumed by the guard, never a sweep before or after the fix) and its first *edge* at `:43-46` is a genuine reconnect. It passes unchanged.

Redundancy is not merely argued — it is falsified. **Mutant E**: the guard moved *after* the `wasDisconnected` check, i.e. "the first *reconnect edge* is the initial connect" instead of "the first *connected observation* is the initial connect":

```
    if (!wasDisconnected.current) return
    wasDisconnected.current = false
    if (!hasEverConnected.current) { hasEverConnected.current = true; return }
```

```
$ pnpm vitest run src/providers/__gate_r2_mutantE.test.tsx
 ❯ __gate_r2_mutantE.test.tsx (2 tests | 1 failed)
   × invalidates all active queries on first reconnect and applies a 30 second cooldown
     → expected "invalidateQueries" to be called 1 times, but got 0 times   (:47)
   ✓ ignores the initial connect: only a genuine reconnect invalidates and arms the cooldown
```

The **new** test passes mutant E (it mounts disconnected, so both placements behave identically); only the **original** test catches it. The original test is the sole guard on the mount-already-connected path (case (a)) — a user who loads the page with the socket already up would, under mutant E, lose their first genuine reconnect sweep. **Keep both tests.** The two are complementary: original = mount-connected; new = mount-disconnected. Mutant A is the only one both catch.

---

## R2.4 — N-2 and N-3 (checklist item 4) — both FIXED as claimed

- **N-3:** `WebSocketReconnectProvider.test.tsx:5` `import type { WebSocketConnectionState }`, `:10-16` the factory is typed `(): WebSocketConnectionState` and supplies all five fields (`echo`, `isConnected`, `isConnecting`, `error`, `hasGivenUp`) matching `useWebSocketConnection.ts:5-21`. Adding a field to the interface now breaks the mock at compile time. Verified by `pnpm typecheck` exit 0.
- **N-2:** `docs/handoff/HANDBACK-request-hygiene-T8-2026-09-04.md` §3 "Not run: browser reconnect probe" now carries a **step 0** that expects **NO** burst on the initial connect ("A burst here means the `hasEverConnected` guard has regressed") plus steps 1-3 unchanged, and explicitly labels the `> 30 s idle` wait in step 1 as a safety margin rather than a correctness requirement. This is the correct amendment for the *fixed* behaviour — stronger than the r1 directive (which was written for the unfixed code and expected a burst at step 0). The r1 report §6 step-0 wording is superseded by the handback; the appendix already says so.
- **Promotion-owed is unchanged:** the authenticated-layout browser reconnect probe is still NOT performed (no stack for this worktree). S-7 remains unverified end-to-end by this gate.

---

## R2.5 — Guardrails (checklist item 5), all re-run by me

Run in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t8/apps/web`.

```
$ pnpm vitest run src/providers src/components/templates/DashboardLayout
 ✓ src/providers/WebSocketReconnectProvider.test.tsx (2 tests) 14ms
 ✓ src/components/templates/DashboardLayout/__tests__/DashboardLayout.switcher.test.tsx (2 tests) 18ms
 Test Files  2 passed (2)
      Tests  4 passed (4)
exit=0
```

```
$ pnpm typecheck            # tsc --noEmit, whole apps/web
(no diagnostics)
exit=0
```

**ESLint per file, before = `a81235540` (temp copies inside `src/providers/`, linted, deleted) vs after = `f5b8eafb9`:**

| File | Before (`a81235540`) | After (`f5b8eafb9`) |
|---|---|---|
| `src/providers/WebSocketReconnectProvider.tsx` | errors=0 warnings=0 | errors=0 warnings=0 |
| `src/providers/WebSocketReconnectProvider.test.tsx` | errors=0 warnings=0 | errors=0 warnings=0 |

**0 new errors, 0 new warnings.**

**Audits (repo-wide, both red on `dev` as well; zero attribution to T8):**

| Stage | Result | Attribution |
|---|---|---|
| `audit:keys` | `0 acknowledged, 1 new` — `src/features/uom/hooks/useUnits.ts:53:9` | **Not T8**, and it disappears on merge: `git diff --stat 7f86dbf0c dev -- apps/web/src/features/uom/hooks/useUnits.ts` → 28+/7−, and the merged tree already carries the fixed `predicate: uomUnmappedUnitTextsInvalidationPredicate(...)` form. Verified by `git cat-file -p 38eb2e560:apps/web/src/features/uom/hooks/useUnits.ts`. |
| `audit:design-system` | red, all hits in `src/features/import/**` etc. | **Not T8** — `pnpm audit:design-system \| grep -c WebSocketReconnect` → **0**. The fix-round diff adds no `className`, no token reference, no string literal. |

**Mechanism audit:** the fix is one `useRef` and a four-line guard in the provider itself — no alias table, no suppression comment, no detector-defeating indirection, no baseline file touched (`git diff --name-only a81235540..f634e13cb -- 'apps/web/tools/*baseline*'` → empty). The improvement in the metric that mattered (the N-1 scenario) was achieved by changing behaviour, and the new test fails against the old behaviour (mutant B) — no evasion.

**Hygiene:** `ps aux | grep -c '[n]ode (vitest'` → `0`; worktree `git status --porcelain` → empty; `ls src/providers/` → only the two lane files.

---

## R2.6 — New non-blocking findings

### R2-N5 (MINOR) — a socket that never connects until well after page load no longer gets a recovery sweep

`apps/web/src/providers/WebSocketReconnectProvider.tsx:53-57` classifies the first observed connect as "initial" regardless of *when* it happens. With `useWebSocketConnection.ts:34` `CONNECTION_GIVE_UP_MS = 15000` and `:27` `MAX_CONNECTION_ATTEMPTS = 3`, a client can sit disconnected for tens of seconds after mount and then connect; events published in that window are missed and are **not** swept (pre-fix they were). Bounded in practice by `staleTime` refetching, and strictly better than the N-1 behaviour it replaces.

Fix directive (follow-up, not this lane): if wanted, only treat the first connect as "initial" when it lands within a short grace window of mount (e.g. `mountedAt` ref + 5 s), otherwise treat it as a reconnect.

### R2-N6 (MINOR, docs) — handback §1 net-diff line is stale

`docs/handoff/HANDBACK-request-hygiene-T8-2026-09-04.md` §1 still reads "Net diff: 2 files, +85 / −3", which describes `26d971bb4` only; the lane is now +149 / −4 across the two source files. §4b documents the fix round correctly, so this is cosmetic.

Fix directive: update the §1 net-diff line, or annotate it "as of `26d971bb4`".

---

## R2.7 — Merge-tree (read-only, from the main checkout)

```
$ git rev-parse dev
6292cf235890b106cb589b668465248bb22f9a05
$ git merge-tree --write-tree dev lane/rh-t8-reconnect
38eb2e560868c8f9ea472a418f59227a86d6174c
exit=0
```

**Clean — no conflicts** (bare tree OID, no `CONFLICT`/`Auto-merging` block). Drift check on the touched paths: `git log --oneline 7f86dbf0c..dev -- apps/web/src/providers apps/web/src/hooks/useWebSocketConnection.ts` → **empty**; no other lane touched the provider or the hook. The merged tree carries the change intact — `git cat-file -p 38eb2e560:apps/web/src/providers/WebSocketReconnectProvider.tsx` shows `const hasEverConnected = useRef(false)` at `:42`, the guard at `:53-54`, `RECONNECT_INVALIDATION_COOLDOWN_MS = 30_000` at `:15` and `invalidateQueries({ refetchType: 'active' })` at `:71`.

---

*Re-gated read-only. No tracked file on `lane/rh-t8-reconnect` was modified by this review beyond this appended report section; nothing was merged or pushed. The browser reconnect probe (amended steps 0-3 in the handback §3) remains a promotion precondition.*
