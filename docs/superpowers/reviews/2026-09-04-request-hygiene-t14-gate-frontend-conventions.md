# Gate — Request Hygiene Phase A, Task 14 (strictly serialized draft autosave, ID-12)

- Reviewer: frontend-conventions-reviewer (adversarial merge gate)
- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t14`
- Branch: `lane/rh-t14-autosave-serial` — base `5e1e54f69`, commits `242ea6a67` (code+tests), `47658a7b9` (handback)
- Diff scope (verified): `apps/web/src/hooks/useDraftAutoSave.ts`, `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx`, `docs/handoff/HANDBACK-request-hygiene-T14-2026-09-04.md`. Nothing else. `apps/web/tools/audit-design-system-baseline.json` NOT touched (0 lines) — no baseline evasion.
- Note: the dispatch brief cites `apps/web/src/features/documents/hooks/useDraftAutoSave.ts`; the real (and only) path is `apps/web/src/hooks/useDraftAutoSave.ts`. No second copy exists (`find src -name 'useDraftAutoSave*'` → 2 files).

## VERDICT: CHANGES

One blocking finding: the shipped design is a promise tail of N, and the gate contract ("changes during flight collapse into exactly ONE trailing save") is measurably not met. Everything else in the lane holds up under independent re-execution, including both contested deviations (D-1 and D-4), which I reproduced rather than took on trust.

---

## 1. Blocking findings

### B-1 — `useDraftAutoSave.ts:228`: unbounded FIFO tail, not a single trailing slot; contract clause "exactly ONE trailing save" is unmet, and the test that claims to pin it cannot detect the miss

```ts
// apps/web/src/hooks/useDraftAutoSave.ts:228
tailRef.current = tailRef.current.catch(() => undefined).then(run)
```

Every `performSave()` call appends a NEW job carrying its own frozen `data` snapshot. There is no `pending` flag and no collapse. The gate contract is "changes during flight collapse into exactly ONE trailing save issued after the in-flight one settles"; what ships is "N sequential saves, one per debounce window that elapsed during the flight".

**Falsifying scenario (measured, not argued).** Temporary probe `src/hooks/__tests__/zz.probe.gate.test.tsx` (run, then deleted; `git status` clean):

- `debounceMs: 100`; request 1 held in flight (never resolved).
- Three edits `v1`, `v2`, `v3`, each followed by a FULL debounce window, all while request 1 is still physically in flight.
- Assert `apiPost` called 2 times (the contract's "one trailing save").

```
PROBE total POSTs = 4   payload notes = [ 'v0', 'v1', 'v2', 'v3' ]
AssertionError: expected "spy" to be called 2 times, but got 4 times
```

THREE trailing POSTs, each carrying a stale body. `DocumentForm.tsx:277` passes no `debounceMs`, so production runs at the 3000 ms default: this reproduces whenever `POST /documents/auto-save` takes longer than 3 s and the operator pauses ~3 s between edits — i.e. exactly the slow-network condition the lane's own promotion-owed browser check prescribes (handback §3.9 step 2, "Slow 3G ... in flight for seconds").

**The retained test cannot catch it.** `useDraftAutoSave.state.test.tsx:315` is named `queues exactly one trailing save for edits made while a request is in flight`, but its three edits at `:334-339` are 100 ms apart under a 250 ms debounce — they are coalesced by the DEBOUNCE, never by the serializer. The test measures debounce coalescing and is named after a serialization guarantee the code does not provide. This is the "test asserts a guarantee it doesn't measure" pattern.

**Concrete harm beyond request volume — the unsaved-changes guard disarms while un-issued work remains.** `DocumentForm.tsx:296-306` builds `shouldWarn = isDirty || autosavePending || autosaveFailed`, and `handleAutoSaveSuccess` (`DocumentForm.tsx:264-269`) clears the RHF dirty baseline and re-snapshots `lastSavedLinesRef` on EVERY success. With a queue of N, the FIRST trailing job's success clears the guard while later bodies are still un-transmitted. Second probe (`zz.probe2.test.tsx`, run then deleted):

```
PROBE2 posts= 3  notes= [ 'v0', 'v1', 'v2' ]  autosavePending= false  autosaveFailed= false  isSaving= true
AssertionError: expected false to be true   (asserted autosavePending === true)
```

The `v3` edit has never been sent, yet `autosavePending` is already `false` and `isDirty` has been reset — `shouldWarn` is false. Navigating away there hits the unmount cleanup (`useDraftAutoSave.ts:321-327`), the generation bumps, and the queued `v3` job no-ops before `api.post` is ever called: the most recent edit is dropped silently, with no warning. This drop class is NEW — at base every `performSave()` issued its request immediately, so there was no "decided but not issued" work to discard at unmount.

**Fix directive (either is acceptable):**
1. Replace the tail-of-N with a single trailing slot: one `pendingRef: boolean` plus a `latestDataRef` the trailing job re-reads at execution time, so at most one queued job exists and it carries the newest snapshot; keep `autosavePending` true while that slot is occupied. This satisfies the contract and closes both harms at once.
2. Or get the ID-12 contract formally amended to "no overlap; N sequential, last-wins" by the owner, AND rename the test at `:315` to what it actually measures (debounce coalescing under an in-flight request), AND keep `autosavePending` true while `tailRef` is non-empty so the guard stays armed.

Note in the lane's defence, and why this is CHANGES and not REJECT: the plan snippet at `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` Task 14 Step 2 prescribes this exact tail, and handback §7 item 5 discloses "the serialization guarantees no *overlap*, not one request per session". The lane implemented the plan faithfully and disclosed. The plan and the gate contract disagree; the code cannot satisfy both, and the gate contract is the acceptance criterion.

---

## 2. Non-blocking findings

- **NB-1 — `pnpm lint` is RED at HEAD, not this lane's fault, but it blocks the promotion batch.** `pnpm --filter @autoerp/web lint` exits 1 at `audit:design-system`: `810 violations, 796 acknowledged, 14 new, 11 stale`. All 14 new are `src/features/import/pages/ImportWizardPage.tsx:867 / :911 / :920 / :1041 / :1061 / :1070 / :1199 / :1213 / :1250 / :1259 / :1372 / :1384 / :1498 / :1511` (C2 raw `<input>`, C3 raw `<button>`). That file is byte-identical between `dev` and `47658a7b9` (`git diff --stat dev..47658a7b9 -- <path>` → empty), so this is pre-existing debt on `dev`. Fix directive: land the ImportWizardPage atom migration (or an honestly-acknowledged baseline entry) on `dev` before promotion batch 3; do NOT absorb it via `--write-baseline`.
- **NB-2 — repo-wide `eslint .` = 0 errors / 6452 warnings** (pre-existing debt). Per-file delta for the two touched files is clean; see §4.
- **NB-3 — 6 of the 8 new tests drive a dead production path.** `DocumentForm.tsx:277` destructures only `{ draftId, isSaving, lastSavedAt, autosavePending, autosaveFailed }`; `saveNow` and `reset` have no production caller (`grep -rn useDraftAutoSave src --include='*.ts' --include='*.tsx'` → only `DocumentForm.tsx` + 3 mocking test files). So `useDraftAutoSave.state.test.tsx:148`, `:174`, `:194`, `:222`, `:242`, `:263` all exercise `saveNow()`/`reset()`, and `:242` guards a path production cannot reach. Only `:281` and `:315` run the real debounce path. Fix directive: add at least one debounce-path test for the `draft_id` carry-forward (currently only asserted via `saveNow` at `:170-171`), since that is the actual production symptom of ID-12.
- **NB-4 — floating rejection at `useDraftAutoSave.ts:300`.** `performSave()` in the debounce timer is un-awaited (`@typescript-eslint/no-floating-promises` warning, pre-existing). The lane's own test at `:194` establishes that a consumer `onError` which throws rejects the returned promise — from the timer site that becomes an uncaught-in-promise console error. Fix directive: `void performSave()` or attach `.catch(() => undefined)` at `:300` while the file is open.
- **NB-5 — `existingDraftId || draftIdRef.current` at `useDraftAutoSave.ts:192`**: `||` means an empty-string `existingDraftId` silently falls through to the ref (pre-existing `prefer-nullish-coalescing` warning on that line). Fix directive: `??` if empty-string is not meant to be a sentinel.
- **NB-6 — `git status` clean after every mutation probe.** Five temporary mutations (falsification A/B/C, D-1 wrapper probe, D-4 two-arm probe) and two temporary probe test files were applied and reverted with `git checkout --` / `rm`; the worktree is byte-identical to `47658a7b9` at the end of this review.

---

## 3. What held up (verified independently, not taken on report)

**Serialization mechanics — read at `useDraftAutoSave.ts:143-244`, `263-280`, `315-328`.**
- In-flight gate: `:228` chains each job off the previous tail; `run` (`:173`) starts only after the predecessor SETTLES. Trailing save runs after both success and failure — proven by `:174` (rejected request → second POST issued) and by falsification A below.
- Predecessor rejection is swallowed ONLY for chaining (`.catch(() => undefined)` at `:228`); the original rejection still reaches the caller because the returned promise is `scheduled.finally(...)` on the un-caught `scheduled` (`:229-230`). `onError` is still invoked at `:220` — nothing is hidden from it. Test `:194` pins this.
- Unmount: `isUnmountedRef` + `generationRef` bump at `:322-323`; `isCancelled()` (`:170`) is re-checked at job start (`:175`), after the await (`:197`), in the catch (`:215`) and in the finally (`:240`) — no setState after unmount.
- StrictMode `setup → cleanup → setup`: `isUnmountedRef.current = false` at `:319` leaves one live chain (the debounce cleanup at `:304-309` clears the first timer, so only one timer survives).
- `draft_id` carry: `draftIdRef` (`:151`) is written synchronously at `:199` before `setDraftId`, and read at `:192`. Removing `draftId` from `performSave`'s deps (`:244`) is safe and also stops the debounce effect re-arming on every successful save.
- `setIsSaving(false)` only when the LAST job settles: ownership check `tailRef.current !== scheduled` at `:238`. Microtask ordering verified by reading: A's `.finally` handler is registered before B's `.catch` handler, so A's finally observes `tailRef.current === B` and correctly declines to clear the spinner.
- `reset()` (`:263-280`) bumps the generation but deliberately does NOT replace `tailRef` — later work still queues behind the physical request. Correct.

**Tests.** Base file had 5 tests (`git show 5e1e54f69:…` → 5 `it(`), HEAD has 13 → **8 added, as claimed**. Falsifications re-run by me against the shipped hook, each reverted:

| Mutation | Result |
|---|---|
| A — `:228` → `tailRef.current = run()` (gate removed) | **6 failed / 7 passed** — exactly the six serialization tests. Matches handback §3.2 RED (6 red before). |
| B — `isUnmountedRef.current = false` removed from `:319` | **1 failed / 12 passed** — `still saves after StrictMode replays mount cleanup and setup`. |
| C — `.catch(() => undefined)` removed from `:228` | **1 failed / 12 passed** — `runs the next save even when the onError callback throws`. |

Restored to `13 passed (13)` after each.

**D-1 (`useDraftAutoSave.state.test.tsx:233`, `wrapper: StrictMode`) — CONFIRMED, the plan's `StrictWrapper` was vacuous.** I rebuilt the plan's form (`const StrictWrapper = ({children}) => <StrictMode>{children}</StrictMode>`, `wrapper: StrictWrapper`) AND removed the `isUnmountedRef.current = false` fix: `13 passed (13)` — the plan's test is green with the bug present. With `wrapper: StrictMode` the same mutation is red (falsification B). React 19.2.0 + `@testing-library/react` 16.3.0: the intermediate wrapper double-RENDERS but does not replay effects. The fix is production-relevant, not defensive: `apps/web/src/main.tsx:20` mounts the real app inside `<StrictMode>`.

**Consumers.** `DocumentForm.tsx:24,277` is the only consumer (grep above). Its three suites pass. Request body shape unchanged: still `{ draft_id: existingDraftId || draftIdRef.current, ...data }` at `:191-194`; `DraftData` (`:82-101`) untouched.

**§7 treasury-reviewer waiver — CONFIRMED not required.** The diff contains no parse, format, sum, comparison or rounding of any money/quantity value; `data` is spread verbatim into the body. `MoneyInput`/`QuantityInput`/`parseFloat` do not appear in the diff. Rule 19 has no surface here.

**Cross-cutting checks.** No catalogue entity, no unique key, no new noun (second-of-everything / one-surface-per-concept: N/A). No design-token, `t()`, `tenantScopedKey`, `PageHeader`, form-atom or `DataTable` surface in the diff. No owner-ruled UI principle in scope.

---

## 4. Commands and outputs

All from `<worktree>/apps/web` unless stated. `ps aux | grep -c '[n]ode (vitest'` = 0 before and after.

```
$ npx vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx \
    src/features/documents/DocumentForm.test.tsx \
    src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx \
    src/features/documents/__tests__/DocumentForm.blankUnitPrice.test.tsx
 Test Files  4 passed (4)
      Tests  46 passed (46)

$ npx vitest run src/hooks src/features/documents
 Test Files  68 passed (68)
      Tests  529 passed (529)

$ npx tsc --noEmit
typecheck exit=0

$ pnpm lint
  lint:eslint      -> ✖ 6452 problems (0 errors, 6452 warnings)   [pass]
  audit:keys       -> Gate C: 0 violations; baseline 0 acknowledged / 0 new / 0 stale   [pass]
  audit:design-system -> 810 violations; 796 acknowledged, 14 NEW, 11 stale   [FAIL, exit 1]
                        all 14 new in src/features/import/pages/ImportWizardPage.tsx (untouched, identical to dev) — NB-1
  (audit:quantity, audit:i18n:local, test:eslint-rules, test:tools not reached — chain aborted)
EXIT=1
```

**Per-file lint delta, measured IN PLACE** (base content written to the real paths via `git show 5e1e54f69:<path> > <path>`, linted, then `git checkout -- <path>`; `git status` clean after):

| File | base `5e1e54f69` | HEAD `47658a7b9` | delta |
|---|---|---|---|
| `src/hooks/useDraftAutoSave.ts` | 0 err / 4 warn — `array-type` 85:11, `prefer-nullish-coalescing` 166:37, `react-hooks/set-state-in-effect` 249:5, `no-floating-promises` 251:7 | 0 err / 4 warn — same four rules, relocated to 85:11 / 193:39 / 298:5 / 300:7 | **0 / 0** |
| `src/hooks/__tests__/useDraftAutoSave.state.test.tsx` | 0 err / 1 warn — `unbound-method` 13:27 | 0 err / 1 warn — `unbound-method` | **0 / 0** |

Handback §3.7 is accurate.

**D-4 two-arm probe (React Compiler bailout).** Same tripwire (`setAutosaveFailed(false)` inserted directly in the mount `useEffect` body) under both shapes:

```
ARM 1 — shipped promise-.finally:      react-hooks/set-state-in-effect x2   ✖ 5 problems
ARM 2 — statement try/finally in run:  (no react-hooks diagnostics)          ✖ 3 problems
```

The compiler-backed `react-hooks` rules vanish for the whole hook under a statement `try/finally`. The bailout is silent (no `react-hooks/unsupported-syntax` is emitted either way — I checked).

**merge-tree:** `git merge-tree --write-tree dev lane/rh-t14-autosave-serial` (dev = `9c28b430a`) → exit 0, tree `698b73fd462e99e8048cbddb62c378903fa391da`, **no conflicts**. `dev` has moved only in `features/products`, `features/stock-transfers` and `docs/` since base `5e1e54f69` — zero overlap with the lane.

---

## 5. D-4 ruling

**ACCEPT the promise `.finally`.** The React-Compiler-bailout claim is empirically true (two-arm probe above: a statement `try/finally` inside the hook silently disables every compiler-backed `react-hooks` rule for the entire hook), and the one-microtask-later `setIsSaving(false)` is unobservable: the promise `performSave()` hands back is the `.finally`-derived one, so any awaiting caller sees the spinner already cleared, and the only production consumer (`DocumentForm.tsx:277`) reads `isSaving` from render, which is already asynchronous. Keeping the lint coverage is the right trade; do not revert to the plan's literal shape.

---

## 6. Promotion-owed (not a finding, but must not be forgotten)

The browser race check is **NOT RUN** and is promotion-owed, exactly as the handback declares in §3.9: real dev build under the StrictMode root at `main.tsx:20`, DevTools Slow 3G, continuous typing across several debounce windows, asserting (a) never two `POST /documents/auto-save` in flight simultaneously, (b) later requests carry the first response's `draft_id` rather than `null`, (c) the document list shows ONE draft, (d) repeat with the first call forced to 500 and confirm the trailing save still fires. If B-1 is resolved by option 1, add (e): after a long in-flight request spanning several 3 s pauses, exactly ONE follow-up POST is issued and it carries the newest body.
