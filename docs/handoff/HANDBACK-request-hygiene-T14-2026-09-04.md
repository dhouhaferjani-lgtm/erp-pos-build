# HANDBACK — Request Hygiene Phase A, Task 14

**Strictly serialized draft autosave (ID-12)**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t14`
- Branch: `lane/rh-t14-autosave-serial`
- Base: `5e1e54f69` (`Merge branch 'lane/rh-t5-product-search' into dev`) — local `dev`, wave-2 PO lanes already merged, so the "rebase after PO lane" precondition is satisfied.
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 14` (line 3352) + `## Task 14, optional (gate r7 NB)` + `## Global Constraints` (line 15)
- Reviewer gate: **frontend-conventions-reviewer** (treasury-reviewer NOT required — see §7)
- Commits: see §6

---

## 1. What landed

| File | Change |
|---|---|
| `apps/web/src/hooks/useDraftAutoSave.ts` | Promise-tail mutex + generation cancellation + StrictMode-safe mount lifecycle. No signature change. |
| `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx` | +8 tests in a new `useDraftAutoSave strict serialization` describe, on the existing `api.post` Axios-shaped harness. |

**No other file is touched.** `DocumentForm.tsx` — the hook's only consumer — is untouched, as the Global Constraints require. No backend change was needed; `apps/api` was not opened.

### Behaviour delta

| Before (`5e1e54f69`) | After |
|---|---|
| Every `performSave()` fired `api.post` immediately → N concurrent `POST /documents/auto-save` for the SAME draft. | Each call is appended to a strict promise tail (`tailRef`). The job body starts only once the previous job has **settled** (fulfilled *or* rejected). Never two concurrent POSTs for one draft. |
| The queued/second save read `draftId` from a **stale render closure** → sent `draft_id: null` again → the server could author a second draft document. | `draftIdRef` mirrors the id **synchronously**; a queued save reads the id the save before it just obtained. |
| `reset()` left an in-flight response free to repopulate `draftId` after the form was cleared. | `reset()` bumps `generationRef`; already-queued work no-ops and the in-flight response can no longer repopulate state or the id ref. `tailRef` is deliberately **not** replaced — later work must still queue behind the physical request that is still out there. |
| Unmount set `isUnmountedRef = true` permanently; under React StrictMode's dev `setup → cleanup → setup` replay the hook was left dead and **every later save silently no-opped**. | The mount effect resets `isUnmountedRef.current = false` on setup and bumps the generation on cleanup. |
| A consumer `onError` that throws rejected the tail and would have poisoned the queue. | The tail chains from a **swallowed** predecessor (`.catch(() => undefined).then(run)`); the original rejection still reaches that job's own caller through `scheduled`. |
| — | Debounce coalescing is unchanged and re-pinned (gate r7 regression test retained). |

The contract in one line: **an autosave that is in flight gates the next one; a change made while a save is in flight is queued as exactly ONE trailing save issued after the in-flight one settles.**

---

## 2. Plan anchor drift

Re-anchored against `5e1e54f69` after the PO lanes. Result:

| Plan anchor | Status at `5e1e54f69` |
|---|---|
| `apps/web/src/hooks/useDraftAutoSave.ts` (modify) | **No drift.** Present, unchanged by the PO lanes. |
| `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx` (modify) | **No drift.** Present, 5 tests, same `vi.mock('../../lib/api')` + `apiPost` + `draft` fixture the plan snippets assume. |
| "Existing imports already include `useRef`" | **Correct** (`useDraftAutoSave.ts:1`). |
| "`useDraftAutoSave(data, config)`; `DraftData` uses `type`, not `document_type`" | **Correct** (`useDraftAutoSave.ts:83`, `:121-124`). |
| "The only consumer remains `DocumentForm.tsx`" | **Correct.** `rg -l useDraftAutoSave apps/web/src` → only `DocumentForm.tsx`, its three test files, the hook and the hook's test. |

**Zero anchor drift.** Nothing in Task 14 needed re-pointing.

---

## 3. Evidence

All commands run from `<worktree>/apps/web` unless stated.

### 3.1 Baseline — before any change

```
$ npx vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx
 ✓ src/hooks/__tests__/useDraftAutoSave.state.test.tsx (5 tests) 17ms
 Test Files  1 passed (1)
      Tests  5 passed (5)
```

### 3.2 Step 1 — RED (tests added, implementation NOT yet written)

```
$ npx vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx
 ❯ src/hooks/__tests__/useDraftAutoSave.state.test.tsx (13 tests | 6 failed)
   ✓ failure/pending state > … (5 pre-existing, all green)
   × strict serialization > strictly serializes three callers and forwards the first draft id
     → expected "spy" to be called 1 times, but got 3 times
   × strict serialization > continues the promise tail after a failed save
     → expected "spy" to be called 1 times, but got 2 times
   × strict serialization > runs the next save even when the onError callback throws
     → expected "spy" to be called 1 times, but got 2 times
   ✓ strict serialization > still saves after StrictMode replays mount cleanup and setup
   × strict serialization > reset clears the synchronous id and cancels queued work
     → expected "spy" to be called 1 times, but got 2 times
   × strict serialization > unmount prevents queued network work after the current save settles
     → expected "spy" to be called 1 times, but got 2 times
   ✓ strict serialization > coalesces rapid data changes into one request carrying the final data
   × strict serialization > queues exactly one trailing save for edits made while a request is in flight
     → expected "spy" to be called 1 times, but got 2 times
      Tests  6 failed | 7 passed (13)
```

**On the two that were already green at RED — disclosed, not hidden:**

- `coalesces rapid data changes …` is the plan's **retained gate-r7 debounce regression guard**. It is green before and after by design: the change must not weaken the debounce. It is a lock, not a driver.
- `still saves after StrictMode replays …` was green at RED **only because the plan's test snippet did not actually replay effects**. See deviation **D-1** — after the fix it is falsifying (§3.4 Falsification B).

### 3.3 Steps 2–3 — GREEN

```
$ npx vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx
 ✓ src/hooks/__tests__/useDraftAutoSave.state.test.tsx (13 tests) 26ms
 Test Files  1 passed (1)
      Tests  13 passed (13)
```

### 3.4 Falsification — three independent passes against the FINAL implementation

Each mutation was applied to the shipped hook, the suite run, then the hook restored byte-for-byte.

| # | Mutation | Result |
|---|---|---|
| **A** | `tailRef.current = tailRef.current.catch(() => undefined).then(run)` → `tailRef.current = run()` (the in-flight gate removed; everything else kept) | **6 failed / 7 passed.** Exactly the six serialization tests break. |
| **B** | `isUnmountedRef.current = false` removed from the mount effect setup | **1 failed / 12 passed** — `still saves after StrictMode replays mount cleanup and setup` → `expected "spy" to be called 1 times, but got 0 times`. |
| **C** | `.catch(() => undefined).then(run)` → `.then(run)` (predecessor rejection no longer swallowed) | **1 failed / 12 passed** — `runs the next save even when the onError callback throws`. Only this one fails, correctly: a *request* failure is already caught inside `run`, so the tail only rejects when a consumer callback throws — which is precisely what this test exists for. |

Restored after each: `Tests 13 passed (13)`.

### 3.5 Step 4 — every hook suite + the consumer's suites, by path

`rg --files apps/web/src/hooks | rg 'test\.(ts|tsx)$' | sort` expanded to explicit paths, plus the three `DocumentForm` suites (the hook's only consumer):

```
$ npx vitest run \
  src/hooks/__tests__/useAfterSaveNavigation.test.tsx \
  src/hooks/__tests__/useBankAccountValidation.test.ts \
  src/hooks/__tests__/useDraftAutoSave.state.test.tsx \
  src/hooks/__tests__/useIdempotencyKey.test.tsx \
  src/hooks/__tests__/usePermissions.authPayload.test.tsx \
  src/hooks/__tests__/usePermissions.expenseRecurrences.test.ts \
  src/hooks/__tests__/usePermissions.expenses.test.ts \
  src/hooks/__tests__/usePermissions.moduleAccess.test.tsx \
  src/hooks/__tests__/usePermissions.ownerReports.test.ts \
  src/hooks/__tests__/usePermissions.replenishment.test.ts \
  src/hooks/__tests__/usePermissions.treasuryReconciliation.test.ts \
  src/hooks/__tests__/usePermissions.uiAliases.test.tsx \
  src/hooks/__tests__/useScopeChangeNotice.test.tsx \
  src/hooks/__tests__/useUnsavedChangesGuard.test.tsx \
  src/hooks/useWebSocketConnection.test.ts \
  src/features/documents/DocumentForm.test.tsx \
  src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx \
  src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx

 Test Files  18 passed (18)
      Tests  114 passed (114)
```

(`src/hooks/useWebSocketConnection.test.ts` lives beside its hook rather than in `__tests__`; the `rg` expansion picks it up and it is included.)

### 3.6 typecheck

```
$ pnpm typecheck
> tsc --noEmit
(exit 0, no output)
```

### 3.7 eslint — per file, before vs after

Baseline taken **in place** (the base-commit content written to the real paths, linted, then restored) — a temp copy under a scratch directory falls outside the tsconfig project and silently degrades every type-aware rule, which produced a bogus 64-warning "baseline" on the first attempt. Both columns below are in-place.

| File | `5e1e54f69` | After | Delta |
|---|---|---|---|
| `src/hooks/useDraftAutoSave.ts` | 0 errors, 4 warnings — `array-type` 1, `prefer-nullish-coalescing` 1, `react-hooks/set-state-in-effect` 1, `no-floating-promises` 1 | 0 errors, 4 warnings — **same four rules, same counts** | **0 / 0** |
| `src/hooks/__tests__/useDraftAutoSave.state.test.tsx` | 0 errors, 1 warning — `@typescript-eslint/unbound-method` 1 | 0 errors, 1 warning — `@typescript-eslint/unbound-method` 1 | **0 / 0** |

All five surviving warnings are pre-existing and untouched by this lane (`Array<T>` on `DraftData.lines`, `existingDraftId || draftIdRef.current`, `setAutosavePending(true)` in the debounce effect, and the un-awaited `performSave()` in the debounce timer). **0 errors, 0 new warnings.**

Two intermediate states were fixed rather than shipped — see deviations **D-3** and **D-4**.

### 3.8 Repo audit touching this lane

```
$ pnpm audit:keys
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
```

The hook uses no TanStack Query key, no money/quantity field and no user-facing copy, so `tenantScopedKey`, rule 19 and `t()` have no surface here.

### 3.9 Browser check — NOT RUN (promotion-owed)

The plan's Step 4 asks for "a new-document manual-save browser race under the app's actual StrictMode root". **This was not run: there is no live stack for this lane.** It is promotion-owed and must be executed before this lane is promoted:

1. New document, `/documents/new`, real dev build (StrictMode root).
2. Throttle the network (DevTools → Slow 3G) so `POST /documents/auto-save` is visibly in flight for seconds.
3. Type continuously in the notes/line fields across several debounce windows.
4. Assert in the Network panel: **never two `POST /documents/auto-save` in flight at the same time**; the second and later requests carry the `draft_id` returned by the first (not `null`); the "Saving…" indicator clears exactly once, after the last request settles; the document list shows **one** draft, not several.
5. Repeat with the server forced to 500 on the first call — the trailing save must still fire after the failure.

Item 4's "second request carries the first's `draft_id`" is the visible symptom of the stale-closure half of ID-12 and is the highest-value manual assertion.

---

## 4. Deviations from the plan text

Four. All are recorded here; none changes the contract.

### D-1 — the StrictMode test uses `wrapper: StrictMode` instead of the plan's `StrictWrapper` indirection

**Plan snippet:**

```tsx
const StrictWrapper = ({ children }: { children: ReactNode }) => (
  <StrictMode>{children}</StrictMode>
)
const { result } = renderHook(…, { wrapper: StrictWrapper })
```

**Shipped:** `renderHook(…, { wrapper: StrictMode })`.

**Why — the plan's form is vacuous.** Probed directly in this harness (React 19.2.0, jsdom, `NODE_ENV=test`):

| Harness | renders | effect log |
|---|---|---|
| `wrapper: ({children}) => <StrictMode>{children}</StrictMode>` (plan) | 2 | `["setup"]` — **no replay** |
| `wrapper: StrictMode` (shipped) | — | `["setup","cleanup","setup"]` — **real replay** |
| manual `createRoot(...).render(<StrictMode><Probe/></StrictMode>)` | — | `["setup","cleanup","setup"]` |

With the plan's intermediate wrapper React double-**renders** but does not replay effects, so the test passes with or without the fix it is supposed to guard: removing `isUnmountedRef.current = false` left it green. With `wrapper: StrictMode` the same mutation turns it red (§3.4 Falsification B). The `type ReactNode` import the plan asks for became unused and was dropped; the reason is recorded as a comment at the test site.

### D-2 — the eight new tests live in a second `describe` in the same file

The plan says "add seven tests to the existing `api.post` Axios-shaped harness". The harness (the module-level `vi.mock`, `apiPost` and the `draft` fixture) is file-level and is reused verbatim; the tests sit in a new `describe('useDraftAutoSave strict serialization')` with the same two-line `beforeEach`/`afterEach` as the existing block, rather than inside `describe('useDraftAutoSave failure/pending state')` whose name no longer describes them. Cosmetic.

The eighth test is the plan's **optional Task 14 item (gate r7 NB)** — `queues exactly one trailing save for edits made while a request is in flight` — implemented, not skipped.

### D-3 — the repeated lifecycle guard is a call, not a direct ref read

**Plan snippet:** `if (isUnmountedRef.current || generation !== generationRef.current) return`, written out at each of the four checkpoints.

**Shipped:** one `const isCancelled = (): boolean => isUnmountedRef.current || generation !== generationRef.current`, called at all four.

**Why.** Written out literally, the first check narrows `isUnmountedRef.current` to `false` for the rest of `run`'s scope, and TypeScript does not widen a ref property back across an `await`. Every later check therefore became **three new `@typescript-eslint/no-unnecessary-condition` warnings** ("value is always falsy" / "always truthy") — a violation of the no-new-warnings bar, and misleading: the condition is emphatically *not* always false at runtime. Reading through a call defeats the narrowing. Identical semantics.

### D-4 — the "last job owns the spinner" check lives on the promise, not in a statement `finally`

**Plan snippet:** `setIsSaving(false)` guarded by `!isUnmountedRef.current && generation === generationRef.current && tailRef.current === scheduled`, inside a `try { … } catch { … } finally { … }` in `run`.

**Shipped:** the same guard, moved into the `scheduled.finally(…)` callback that already owns the tail-slot reset:

```ts
return scheduled.finally(() => {
  if (tailRef.current !== scheduled) return
  tailRef.current = Promise.resolve()
  if (!isCancelled()) {
    setIsSaving(false)
  }
})
```

**Why — the plan's form silently kills a lint rule.** The statement-level `try/finally` makes the React Compiler bail out on `useDraftAutoSave`, which disables **every** compiler-backed `react-hooks` ESLint rule for the whole hook. Probe: with the plan's `finally` block, inserting a blatant `setAutosaveFailed(false)` directly inside a `useEffect` body produced **zero** `react-hooks/set-state-in-effect` reports; with the `finally` removed the same insertion is reported. The base commit's own `set-state-in-effect` warning had vanished from the after-run — that disappearance is what surfaced this. The shipped form restores the rule (the baseline warning is back, §3.7) and keeps the coverage for future edits.

Semantics are preserved: the ownership predicate is unchanged, and both branches (fulfilled and rejected) still run it. The only difference is that `setIsSaving(false)` now lands one microtask later, after `scheduled` settles rather than just before. No test asserts on that ordering and no consumer can observe it — `DocumentForm` reads `isSaving` from render, which is already asynchronous.

### Also disclosed — a process slip, corrected

The first pass of edits was written into the **main `apps/erp` checkout** rather than the worktree: this agent's Bash cwd resets to the repo root between calls, so `cd apps/web` resolved to the main repo. It was caught before any commit. Only the two Task 14 files had been modified there; they were copied into the worktree and the main checkout was restored with a path-scoped `git checkout -- <2 paths>`. `git status` in the main checkout is back to its session-start state (the two pre-existing untracked docs and nothing else), and **every number in §3.5–§3.8 above was re-run inside the worktree**. No `git stash` was used at any point.

---

## 5. Constraint compliance

| Constraint | Status |
|---|---|
| Web-only; `apps/api` untouched | Yes — no backend change was required. |
| `DocumentForm.tsx` not touched | Yes (Global Constraints forbid it; its three suites are run as consumers). |
| No `any` | Yes — `performSave(): Promise<void>`, `run(): Promise<void>`, `isCancelled(): boolean`, `tailRef: useRef<Promise<void>>`, `draftIdRef: useRef<string | null>`. `pnpm typecheck` clean. |
| `tenantScopedKey` | N/A — the hook holds no TanStack Query key. `pnpm audit:keys` Gate C = 0. |
| Money/quantity as strings (rule 19) | N/A — the hook serializes `data` opaquely and touches no money or quantity value. See §7. |
| `t()` for user-facing strings | N/A — no copy added; the one `console.error` is a developer log and pre-existing. |
| No new `useEffect` set-state where a ref/handler works | Yes — the only effect change is `isUnmountedRef.current = false` (a ref write) and `generationRef.current += 1` in cleanup. No new state setter in any effect. |
| Red before green, captured | Yes — §3.2, and the serialization falsified three ways in §3.4. |
| Path-scoped commits | Yes — §6. |
| No `git stash` | Yes. |
| Vitest by path only; no leftover workers | Yes — every run is an explicit path list. `ps aux | grep 'node (vitest'` clean after the final run. |

---

## 6. Commits (path-scoped, on `lane/rh-t14-autosave-serial`)

| Hash | Message | Paths |
|---|---|---|
| `242ea6a67` | `fix(web request-hygiene t14): strictly serialized draft autosave (ID-12)` | `apps/web/src/hooks/useDraftAutoSave.ts`, `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx` |
| _(this commit)_ | `docs(request-hygiene t14): handback` | `docs/handoff/HANDBACK-request-hygiene-T14-2026-09-04.md` |

`git status` clean after both.

---

## 7. Reviewer gate

**frontend-conventions-reviewer** — required, and the only one required.

**treasury-reviewer is NOT required.** The trigger in the dispatch brief is "the autosaved draft carries money fields whose serialization you touched". The draft does carry money-shaped fields (`DraftData.lines[].unit_price`, `line_total`, `discount_amount`, `tax_rate`), but this lane **does not touch their serialization in any way**: `DraftData` is unchanged, the request body is still `{ draft_id, ...data }` with `data` spread verbatim, and no value is parsed, formatted, summed, compared or rounded anywhere in the diff. The change is purely about *when* a request is issued relative to another, not *what* is in it. If the reviewer disagrees, the treasury gate is cheap to add — the diff is 2 files.

### What the reviewer should look at

1. **`tailRef` is never replaced on `reset()` or unmount** (`useDraftAutoSave.ts` `reset`, and the mount effect cleanup). This is deliberate and load-bearing: the physical request is still out on the wire, so later work must still queue behind it. Replacing the tail there would reintroduce the concurrency the lane exists to remove. `generationRef` is what cancels; `tailRef` is what serializes. They are separate on purpose.
2. **`draftIdRef` vs the `draftId` state.** The ref is the synchronous truth used to build the request (`existingDraftId || draftIdRef.current`); the state is for render. `draftId` was removed from `performSave`'s dependency array precisely because the ref replaced it — confirm that removal is safe in your reading (it also stops the debounce effect re-arming its timer on every save).
3. **Deviation D-4** — whether moving `setIsSaving(false)` from a statement `finally` to the promise `finally` is acceptable given the React-Compiler bailout evidence, or whether the lint coverage should be traded away to keep the plan's literal shape.
4. **Deviation D-1** — the StrictMode harness correction. If you can reproduce the plan's `StrictWrapper` replaying effects in some other configuration, say so and the test goes back to the plan's text.
5. **The debounce is still the coalescer.** Two POSTs per burst is the *correct* outcome when a burst spans a settled request; the serialization guarantees no *overlap*, not one request per session. `queues exactly one trailing save for edits made while a request is in flight` pins exactly one trailing request per in-flight window.

### Not done — owed before promotion

- The browser race check under the app's real StrictMode root (§3.9). No stack was available to this lane.
- Promotion batch 3 (T5, T7, T8, T14) per the plan's dispatch order.

---

# FIX ROUND 1 — gate r1 blocker B-1 (single trailing slot)

- Date: 2026-09-04
- Gate report: `docs/superpowers/reviews/2026-09-04-request-hygiene-t14-gate-frontend-conventions.md` (**VERDICT: CHANGES**, one blocker)
- Scope: **B-1 only.** The non-blocking findings NB-1 (pre-existing `ImportWizardPage` design-system debt on `dev`), NB-3 (`saveNow`/`reset` have no production caller), NB-4 (floating `performSave()` in the debounce timer) and NB-5 (`||` vs `??` on `existingDraftId`) are **NOT** addressed here — they were out of the fix-round brief. D-4 was **accepted** by the gate (§5) and the promise `.finally` shape is kept; §3.7's evidence that `react-hooks/set-state-in-effect` still fires (below) re-confirms the React Compiler is not bailing out.
- Files: `apps/web/src/hooks/useDraftAutoSave.ts`, `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx`. Nothing else. `DocumentForm.tsx` still untouched.

## F1. What B-1 was

`tailRef.current = tailRef.current.catch(() => undefined).then(run)` appended a NEW job per call, each carrying its own frozen `data`. Measured by the gate: one request held in flight plus three edits a full debounce window apart produced **4 POSTs** replaying `v0..v3`, and the FIRST trailing success cleared `autosavePending` (and, through `handleAutoSaveSuccess`, DocumentForm's RHF dirty baseline) while newer bodies were still un-transmitted — so `shouldWarn` went false and an unmount dropped the newest edit silently.

## F2. What now ships — one in-flight job, one boolean slot

| Ref | Role |
|---|---|
| `inFlightRef: Promise<void> \| null` | The job physically on the wire; `null` when idle. Non-null ⇒ no second POST is started. |
| `pendingRef: boolean` | The **single trailing slot**. Every save requested during one flight collapses into it — the slot is a boolean, so N requests cannot become N jobs. |
| `latestRequestRef: SaveRequest \| null` | Newest body **+ its callbacks**, overwritten on every request and **re-read by the trailing job at execution time**, so the follow-up carries the latest edit rather than the snapshot that scheduled it. |
| `pendingSlotRef: PendingSlot \| null` | One deferred shared by every caller collapsed into the slot, so each still gets a promise that settles with the trailing save. |
| `generationRef` | Unchanged — cancellation, orthogonal to serialization. |

`startSave()` is a zero-dependency `useCallback` whose inner `launch()` relaunches **itself** from its own settle handler, so the trailing job can never be a stale closure: everything it needs comes from refs.

Contract now met literally: **a change made while a save is in flight collapses into exactly ONE trailing save, issued after the in-flight one settles (success or failure), carrying the LATEST body.**

**Guard semantics (the second harm).** `autosavePending` is now set true at the start of every job and cleared **only** by the save that carries the last unsent body (`if (!pendingRef.current)` in both the success and the failure branch). It is therefore true continuously from "slot filled" through "trailing save settled", so DocumentForm's `shouldWarn = isDirty || autosavePending || autosaveFailed` stays armed across an intermediate success that resets `isDirty`.

**Unmount — recorded behaviour.** An occupied slot is **DISCARDED** on unmount (and on `reset()`): the body was never transmitted, and there is no component left to report to. Its deferred is **resolved** (not left dangling) so no awaiting caller hangs. This is only safe because the slot keeps `autosavePending` true, so the consumer's guard blocks the navigation that would reach the cleanup in the first place — that is exactly what B-1's second harm was about, and it is now locked by a test. The in-flight request itself is still allowed to complete on the server; only the *un-issued* trailing body is dropped.

`inFlightRef` is still **not** cleared by `reset()`/unmount (same reasoning as the original `tailRef`): a save requested after a reset, while the old request is physically out, still waits for the wire instead of racing it.

## F3. Tests — 13 → 16 in the file

**RED first**, three tests added against the unchanged (FIFO) hook:

```
$ npx vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx
 × collapses edits spanning several debounce windows into one trailing save carrying the latest body
   → expected "spy" to be called 2 times, but got 4 times
 × keeps autosavePending true until the trailing save has been issued and settled
   → expected false to be true // Object.is equality
 ✓ arms the pending guard before unmount and discards the unsent body after it
      Tests  2 failed | 14 passed (16)
```

The 4-POST line is the gate's probe reproduced verbatim as a permanent test (`debounceMs: 100`, request 1 held, three edits each a FULL debounce window apart). The unmount test was green at RED and is disclosed as a **lock, not a driver** — the FIFO also dropped queued work at unmount; what it pins is that the guard is armed *before* the drop.

**GREEN:** `Tests 16 passed (16)`.

### Falsification (each mutation applied to the shipped hook, then reverted)

| # | Mutation | Result |
|---|---|---|
| **F1** | Whole hook restored to the FIFO tail (`git checkout` of `242ea6a67`'s version, final test file) | **3 failed / 13 passed** — the 4-POST probe (`got 4 times`), the pending-guard test, and `collapses three concurrent callers…` (`got 3 times`). |
| **F2** | `latestRequestRef.current = {…}` → `??=` (trailing job keeps the FIRST body) | **2 failed / 14 passed** — both "carries the latest body" assertions (`notes` v0 instead of v3). |

Restored to `16 passed (16)` after each.

### Renamed / rewritten existing tests

- **Renamed** `queues exactly one trailing save for edits made while a request is in flight` → **`coalesces edits made within one debounce window during an in-flight request`** (gate B-1: its three edits are 100 ms apart under a 250 ms debounce, so it measures DEBOUNCE coalescing, not the slot). Kept, with a comment pointing at the test that actually measures the slot.
- **Rewritten** `strictly serializes three callers and forwards the first draft id` → **`collapses three concurrent callers into one in-flight save plus one trailing save`**, asserting **2** POSTs instead of 3. This is a *contract change*, not a weakening: under the single-slot contract three concurrent callers must produce one in-flight save plus one trailing save. Every caller's promise still settles with the trailing save (`Promise.all(saves)` still awaited).

### Unrelated-looking test edit, disclosed

Five pre-existing tests called `result.current.saveNow()` **outside** `act(...)`. Because a job now also sets `autosavePending` at its start, those bare calls multiplied React's "update not wrapped in act" stderr from **5 → 21** for the file. The calls are now wrapped in `act(() => { … })` (the returned promises are still captured), which takes the file to **0** act warnings — below the 5 it had at base. No assertion was changed by this.

### Not shipped — a test that did not falsify

A `never renders autosavePending=false while the trailing slot holds an unsent body` test was written and **deleted**: with the `if (!pendingRef.current)` guard removed it still passed, because the false→true dip lives between two microtasks and the `act` harness never commits a render inside that window. Shipping it would have been precisely the "test asserts a guarantee it doesn't measure" pattern the gate flagged. The guard is kept (it closes the dip) and the code comment says plainly that it is not separately falsifiable here; the observable half is pinned by `keeps autosavePending true until the trailing save has been issued and settled`.

## F4. Verification (all from `<worktree>/apps/web`, in place)

```
$ npx vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx
 Test Files  1 passed (1)      Tests  16 passed (16)      (0 act warnings)

$ npx vitest run src/hooks src/features/documents
 Test Files  68 passed (68)    Tests  532 passed (532)     (gate measured 529 at r1 HEAD; +3 = the three new tests)

$ npx tsc --noEmit
typecheck exit=0

$ ps aux | grep -c '[n]ode (vitest'   →  0   (before and after)
```

Per-file eslint, base `5e1e54f69` written to the real paths, linted, restored (`git status` clean after):

| File | base `5e1e54f69` | fix round 1 | delta |
|---|---|---|---|
| `src/hooks/useDraftAutoSave.ts` | 0 err / 4 warn — `array-type` 85, `prefer-nullish-coalescing` 166, `react-hooks/set-state-in-effect` 249, `no-floating-promises` 251 | 0 err / 4 warn — **same four rules**, at 85 / 228 / 420 / 422 | **0 / 0** |
| `src/hooks/__tests__/useDraftAutoSave.state.test.tsx` | 0 err / 1 warn — `unbound-method` 13 | 0 err / 1 warn — `unbound-method` 14 | **0 / 0** |

`react-hooks/set-state-in-effect` still being reported is the standing proof that the React Compiler has **not** bailed out on the hook — the D-4 property the gate accepted survives the rewrite.

`pnpm lint` as a whole is still red at `audit:design-system` for the reasons in gate NB-1 (14 new violations, all in the untouched `src/features/import/pages/ImportWizardPage.tsx`, byte-identical to `dev`). Not this lane's.

## F5. Still promotion-owed

The browser race check (§3.9) remains **NOT RUN**, plus the gate's added step (e): after a long in-flight request spanning several 3 s pauses, exactly **ONE** follow-up POST is issued and it carries the newest body.

## F6. Fix-round commits

| Hash | Message | Paths |
|---|---|---|
| `8dcc12be5` | `fix(web request-hygiene t14): single trailing autosave slot with latest body; pending state true until issued` | `apps/web/src/hooks/useDraftAutoSave.ts`, `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx` |
| _(this commit)_ | `docs(request-hygiene t14): gate r1 report + handback fix round 1` | `docs/superpowers/reviews/2026-09-04-request-hygiene-t14-gate-frontend-conventions.md`, `docs/handoff/HANDBACK-request-hygiene-T14-2026-09-04.md` |
