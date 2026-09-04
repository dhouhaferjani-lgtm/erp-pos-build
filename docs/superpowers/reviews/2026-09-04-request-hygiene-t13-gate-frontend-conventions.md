# Gate — Request Hygiene Phase A, Task 13 (WEB half) — frontend-conventions-reviewer

- **Date:** 2026-09-04
- **Reviewer:** frontend-conventions-reviewer (adversarial merge gate)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t13`
- **Branch:** `lane/rh-t13-transfer-idempotency` · **Base:** `a97631051` · **Web commit:** `fd8fef0d3` · **Tip reviewed:** `e4dbc5c57`
- **Scope:** `apps/web` only. Backend (`StockTransferService`, PG collision harness, rethrow tests) is `inventory-costing-reviewer`'s leg and is NOT gated here except where it determines whether a frontend guarantee actually holds.
- **Diff under review:** `git diff a97631051..e4dbc5c57 -- apps/web` — 4 files, +49/-0.

## VERDICT: APPROVE-WITH-FIXES

Zero blockers. The four-file web diff is faithful to plan rev 11 Step 4/Step 5, adds no lint error and no lint **warning** (byte-for-byte warning parity with base, independently measured), typechecks clean, and all named vitest paths are green on re-run. Two MAJOR fixes are owed **before promotion** (not before merge): a negative test pinning the load-bearing "a failed submit does NOT rotate the key" invariant, and a decision on the adjustment page's single page-scope key spanning two different submit intents. The browser double-click probe is promotion-owed as stated in the handback; the code does not make it impossible to pass.

---

## 1. What was verified, with file:line

### 1.1 Hook wiring, field name, reset placement — PASS

| Requirement | Transfer page | Adjustment page |
|---|---|---|
| imports `@/hooks/useIdempotencyKey` | `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:25` | `apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx:27` |
| hook called at **page** scope (not inside the mutation hook, not inside submit) | `CreateStockTransferPage.tsx:555` (component body, after `useCreateStockTransfer()` at :549) | `CreateStockAdjustmentPage.tsx:111` (component body, after `useCreateStockAdjustment()` at :106) |
| `idempotency_key` in the POST body | `CreateStockTransferPage.tsx:701` (first key of the `CreateStockTransferInput` literal) | `CreateStockAdjustmentPage.tsx:273` (first key of the `mutateAsync` object literal) |
| `reset()` called **only** after the awaited success | `CreateStockTransferPage.tsx:727` — inside `try`, on the line immediately after `await createMutation.mutateAsync(payload)` at :725, before `toast.success` / `navigate` | `CreateStockAdjustmentPage.tsx:288` — inside `try`, immediately after the `await createMutation.mutateAsync({...})` that closes at :287, before `setRefusal(null)` / `navigate` |
| `reset()` NOT in `catch` / `finally` / before the request | confirmed — `catch { toast.error(...) }` at `CreateStockTransferPage.tsx:729-731` contains no reset; no `finally` block | confirmed — `catch (error) { setRefusal(extractRefusal(error)) }` at `CreateStockAdjustmentPage.tsx:290-292` contains no reset; no `finally` block |
| submit closure not memoized (no stale-key capture) | `submitTransfer` is a plain `async` arrow at `CreateStockTransferPage.tsx:659` — **not** `useCallback`, so it always reads the current render's key | `submit` is a plain `async` arrow at `CreateStockAdjustmentPage.tsx:266` — same |

**Exact field name confirmed against the backend** (not assumed):
- `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php:83` — `'idempotency_key' => ['nullable','string','max:128']`; consumed at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:185` (`idempotencyKey: $request->input('idempotency_key')`).
- `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockAdjustmentRequest.php:93` — same rule; consumed at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockAdjustmentController.php:132` (pre-check) and `:156` (service call).

Both FE payload types already declared the field before this lane (`apps/web/src/features/stock-transfers/types/index.ts:93` `idempotency_key?: string`), so no DTO/type-generation change was needed or made — matching the plan's "no DTO or generated type change is allowed".

**Handback claim independently confirmed:** an adjustment refusal writes no row, so the acknowledge/resubmit-with-the-same-key loop is safe. `StockAdjustmentController.php:148-169` wraps `createDraft()` + `post()` in **one** `DB::transaction`, so an immediate-post refusal rolls the draft back with it. Verified in source, not taken on trust.

### 1.2 Synchronous submit lock — NOT PRESENT, and NOT required by the plan

There is **no `useRef` latch** on either page. The only double-submit guard is the async `createMutation.isPending`:
- `CreateStockTransferPage.tsx:1041` — `<Button type="submit" variant="primary" disabled={createMutation.isPending}>`; the form's `onSubmit` at `:871` routes through `useForm().handleSubmit` (`:520`), which is itself re-entrant.
- `CreateStockAdjustmentPage.tsx:619` and `:629` — both `StickyFormFooter` buttons `disabled={createMutation.isPending}`, each invoking `form.handleSubmit(...)()` on click; `:646` `busy={createMutation.isPending}` on the refusal dialog.

`isPending` is React state: it flips on a render that happens *after* the click handler returns. Two clicks dispatched within one task (or before React commits) both pass the guard and issue two POSTs.

**This is not a plan violation.** Plan rev 11 Task 13 Step 4 requires exactly three things — page-scope hook, `idempotency_key` in the existing typed payload, `reset()` after the awaited `mutateAsync`. It does not mention a ref lock; the plan's stated dedup mechanism is the key plus the ID-4 server-side collision replay, not a UI latch. So: **not a blocker.** But the guarantee the two forms actually deliver differs, and the difference matters for the owed browser probe (see MAJOR-2).

### 1.3 Tests — green, partly falsifying, one invariant uncovered

Assertions read line by line:
- `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx:349` — `idempotency_key: 'transfer-key'` inside a `toHaveBeenCalledWith({...})` **exact object literal**. Exact-object matching means dropping the field from the payload fails the test deterministically; this assertion is genuinely falsifying for the wiring.
- `...lineEntry.test.tsx:368` — `expect(mockResetIdempotencyKey).toHaveBeenCalledTimes(1)`.
- `apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx:241` — `expect(payload.idempotency_key).toBe('adjustment-key')`; `:251` — reset called once.
- Cross-test leakage closed: `CreateStockAdjustmentPage.test.tsx:92-93` adds `vi.clearAllMocks()` + `mockResetIdempotencyKey.mockReset()`, as the plan required.

The hook itself is unit-tested and **is** where UUID-ness lives: `apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx:6` (`UUID_V4` regex), `:9-20` (one UUID until reset, new UUID after reset, both matching the regex), `:22-29` (per-mount uniqueness), `:31-37` (stable `reset` identity).

**The hook mock is prescribed by the plan** (rev 11 Step 5 gives the `vi.mock('@/hooks/useIdempotencyKey', ...)` snippet verbatim, returning the literals `transfer-key` / `adjustment-key`). It is therefore not an unauthorised tautology-maker — but it does mean the feature tests never see a real UUID, and the "the key in the body is a UUID" property is proven only compositionally (hook test proves UUID + feature test proves the hook's `key` reaches the body). I accept that composition; recorded as MINOR-1.

**What is NOT covered — see MAJOR-1.** Neither feature file contains a single `mockRejectedValue` on the create mutation (grepped: `createMutate` / `mockCreate` are only ever `mockResolvedValue`). The failure path is never exercised on either page, so the invariant the whole of ID-3 rests on — *the key survives a failed request* — has zero automated protection.

### 1.4 Conventions — PASS

- **No `any`** anywhere in the +49 lines. The two `as {...}` casts in the tests are pre-existing (present at base, see §2.2) and are typed object shapes, not `any`.
- **Rule 19 (money/quantity):** no `parseFloat` / `Number(...)` added; quantities stay strings. The transfer payload still passes `l.quantity` through untouched (`CreateStockTransferPage.tsx:709`) and the adjustment still emits `signedDelta(line)` / `line.observedBefore` as strings (`CreateStockAdjustmentPage.tsx:281-282`). `audit:quantity` reports **0 raw quantity display sites** (see §2.3).
- **i18n:** no user-facing string added. The only new text is three source comments. Nothing to translate.
- **Design tokens:** no `className` added, no token interpolation, no new colour literal. `audit:design-system` shows no entry naming either page (grepped the full violation list).
- **`tenantScopedKey` / invalidations:** `features/stock-transfers/api/queries.ts` and `features/stock-adjustments/api/queries.ts` are **untouched** — confirmed by the full `git diff --stat a97631051..e4dbc5c57`, which lists exactly 8 files and neither of these. The `stock-levels` / `stock-transfers` invalidation roots are therefore unchanged by construction.
- **Second-of-everything / one-surface-per-concept:** the diff introduces no noun, no table, no unique key, no new FE type, no new route or nav entry. `CreateStockTransferInput` is a pre-existing hand-rolled FE type (`types/index.ts:86`) that already carried the field — not this lane's debt. See MINOR-3 for a pre-existing second surface of the *idempotency-key* concept itself.
- **Owner-ruled UI principles:** nothing added to the visual layer — no colour, no badge, no band, no disabled-dead-control, no brand string, no gate change. All clean by construction.

---

## 2. Commands and outputs (all re-run by me; nothing taken from the handback)

### 2.1 ESLint on the four touched files — 0 errors, 7 warnings

```
cd apps/web && pnpm exec eslint --format json <4 files>

src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx errors=0 warnings=2
    233:21 warn @typescript-eslint/no-unsafe-type-assertion
    266:21 warn @typescript-eslint/no-unsafe-type-assertion
src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx        errors=0 warnings=3
    335:30 / 387:30 / 410:30 warn @typescript-eslint/restrict-template-expressions
src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx errors=0 warnings=1
    287:21 warn @typescript-eslint/no-unsafe-type-assertion
src/features/stock-transfers/pages/CreateStockTransferPage.tsx            errors=0 warnings=1
    965:25 warn @typescript-eslint/no-unsafe-type-assertion
```

### 2.2 Warning-delta vs base — ZERO growth (independently measured, not inferred)

Base contents of all four files were written to `ZZBase*.tsx` siblings **inside `src/`** (so relative imports and the tsconfig project still resolve), linted, then deleted; `git status --porcelain apps/web` afterwards was empty.

```
BASE .../ZZBaseCreateStockAdjustmentPage.test.tsx  errors=0 warnings=2   (223:21, 249:21 no-unsafe-type-assertion)
BASE .../ZZBaseCreateStockAdjustmentPage.tsx       errors=0 warnings=3   (327:30, 379:30, 402:30 restrict-template-expressions)
BASE .../ZZBaseCreateStockTransferPage.lineEntry.test.tsx errors=0 warnings=1 (279:21 no-unsafe-type-assertion)
BASE .../ZZBaseCreateStockTransferPage.tsx         errors=0 warnings=1   (955:25 no-unsafe-type-assertion)
```

Base **7 warnings / 0 errors** → HEAD **7 warnings / 0 errors**. Same rules, same count, same code sites (each shifted by exactly the number of lines this lane inserted above it: +10, +8, +8, +10). The ratchet cannot grow. The handback's claim is confirmed by measurement.

### 2.3 Repo audits

```
pnpm audit:quantity   → [audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale)   PASS

pnpm audit:keys       → FAIL (exit 1)
  [gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale
  src/features/uom/hooks/useUnits.ts:53:9  invalidateQueries({queryKey: tenantScopedKey([...])}) is a no-op filter
  → NOT this lane. useUnits.ts is not in the diff; tools/ and the baselines are not in the diff.

pnpm audit:design-system → FAIL (exit 1)
  [sweep-progress] Design-system audit C1-C6 violations: 811
  [gate-summary] 796 acknowledged, 15 new, 11 stale
  Every new and every stale entry names src/features/import/pages/ImportWizardPage.tsx.
  Grep for CreateStockTransferPage|CreateStockAdjustmentPage in the violation output → no match.
  → NOT this lane.

pnpm lint:eslint      → 6459 problems (5 errors, 6454 warnings)
  All 5 errors are parsing errors in apps/web/e2e-local/*.ts (parserOptions.project does not include them),
  committed by session L in b085ca881, which `git merge-base --is-ancestor b085ca881 a97631051` confirms
  predates this lane's base.
  → NOT this lane.
```

**Baseline honesty:** `tools/audit-design-system-baseline.json` and every other file under `tools/` are **absent from the diff**. No `--write-baseline` absorption, no alias table, no suppression comment, no renamed-equivalent literal. The full 8-file diff is: 3 backend files, 4 web files, 1 handback doc. Mechanism audit finds nothing.

### 2.4 Vitest — three plan paths + `src/hooks/__tests__`

```
cd apps/web && pnpm vitest run \
  src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx \
  src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx \
  src/features/stock-transfers/__tests__/queries.test.tsx \
  src/hooks/__tests__

 ✓ src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx (8 tests) 1494ms
   ✓ ... > submits the complete header and line payload with decimal strings intact  356ms

 Test Files  17 passed (17)
      Tests  90 passed (90)
   Duration  3.59s
```

Default pool, no `--singleFork`. `ps aux | grep -i vitest` afterwards: no leftover workers.

### 2.5 Typecheck

```
cd apps/web && pnpm typecheck
> tsc --noEmit
exit=0   (no output)
```

---

## 3. Findings

### BLOCKER
None.

### MAJOR

**MAJOR-1 — The one invariant ID-3 exists for is untested: no failure-path test on either page.**
`apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx:368` and `apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx:251` assert only `toHaveBeenCalledTimes(1)` **on the success path**. Neither file ever rejects the create mutation (no `mockRejectedValue` on `mockCreate` / `createMutate` anywhere in either file).
*Falsifying scenario:* move `resetIdempotencyKey()` from `CreateStockTransferPage.tsx:727` to **before** `await createMutation.mutateAsync(payload)` at `:725` — or into a `finally`, or duplicate it into the `catch` at `:729` — and all 90 tests still pass, because on the success path the call count is 1 in every one of those variants. The shipped behaviour is correct today; nothing stops the next editor from silently breaking it, and the break is invisible to lint, typecheck and the suite. The plan's own Step 4 states the contract in words ("Failed calls do not reset") and then specifies tests that cannot detect its violation.
*Fix directive:* add one test per page — `createMutate.mockRejectedValueOnce(new Error('network'))`, submit, assert `expect(mockResetIdempotencyKey).not.toHaveBeenCalled()`, then `mockResolvedValueOnce`, submit again, and assert the second `mutateAsync` call carries the **same** `idempotency_key` value as the first.

**MAJOR-2 — Double-click on the adjustment page is guarded only by async `isPending`, and its backend has no collision replay, so the loser is a 500 rather than a replay.**
`CreateStockAdjustmentPage.tsx:619` / `:629` guard with `disabled={createMutation.isPending}` only; `form.handleSubmit(...)()` is re-entrant; there is no `useRef` latch. The transfer side is covered — two same-key POSTs converge because T13's backend half catches `UniqueConstraintViolationException` and rereads the committed winner (`StockTransferService.php:179-192`). The adjustment side is **not**: `apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:87-104` is a documented read-then-insert with no catch, and its own comment concedes the loser's INSERT is refused by `stock_adjustments_idempotency_unique` (accepted as gate M-6 debt). So a double-click that outruns the `isPending` render produces one adjustment plus one uncaught unique violation → 500 on the loser, which the page swallows silently (`extractRefusal` returns `null` for anything without an `error` envelope — `apps/web/src/features/stock-adjustments/api/refusals.ts:129-136`, pinned by `refusals.test.ts:86`).
Not a blocker: no duplicate document is created either way, the plan did not require a latch, and the backend gap predates T13.
*Fix directive:* add a `const submitting = useRef(false)` latch around `submit()` on `CreateStockAdjustmentPage.tsx:266` (set synchronously before `mutateAsync`, clear in a `finally`) and the same around `submitTransfer()` at `CreateStockTransferPage.tsx:659`; and make the owed browser probe assert **zero 5xx in the network log**, not merely "one document".

**MAJOR-3 — One page-scope key spans two different submit intents on the adjustment page, and the backend replays on key alone with no payload fingerprint.**
`CreateStockAdjustmentPage.tsx:111` mints one key for the page; `:619` submits `postImmediately=false` (save draft) and `:629` submits `postImmediately=true` (save & post) through the same `submit()` at `:266` with the same key. The server's replay short-circuit at `StockAdjustmentController.php:132-143` matches on `(tenant_id, company_id, idempotency_key)` only and returns the existing row with HTTP 200 — it never compares the request body.
*Falsifying scenario:* operator clicks **Save draft**; the server commits the draft but the response is lost (timeout / tab suspend). `extractRefusal` returns `null` for a network error (`refusals.ts:134-136`), so `setRefusal(null)` at `:291` shows the operator **nothing at all**. The operator now clicks **Save & post**. Same key → the controller pre-check finds the committed DRAFT → 200 with the draft → `CreateStockAdjustmentPage.tsx:288-289` resets the key and navigates to the adjustment detail as if the submit succeeded. The adjustment is never posted, no stock moves, and the only signal is the DRAFT status on the page the operator was just navigated to. (The acknowledge/re-anchor resubmit at `:652` reuses the key too, but that path is genuinely safe — the refusal rolls the draft back inside the controller's single transaction at `:148-169`.)
*Fix directive:* either mint the key per intent (reset when `postImmediately` differs from the previous attempt) or, minimally, after a `post_immediately` submit assert the returned `status` is posted and surface a warning when it is not — and give the adjustment page a visible generic-error path so a lost response is not silent.

### MINOR

**MINOR-1 — The feature tests never see a real UUID.** Both mock `@/hooks/useIdempotencyKey` to a literal (`...lineEntry.test.tsx:50-53`, `CreateStockAdjustmentPage.test.tsx:70-74`) exactly as plan rev 11 Step 5 prescribes. UUID-ness is proven only in `src/hooks/__tests__/useIdempotencyKey.test.tsx:6,17,19,26,27`. Accepted as a compositional proof; if the owed browser probe runs, capture the real request body once and eyeball the v4 shape.

**MINOR-2 — Pre-existing: the transfer create page does not use `StickyFormFooter` + `SaveSplitButton`.** `CreateStockTransferPage.tsx:1035-1043` is a hand-rolled `flex justify-end` row with a plain `<Button type="submit">`, while the adjustment page correctly uses `StickyFormFooter` (`CreateStockAdjustmentPage.tsx:616`). Untouched by this lane; logged for the UI audit backlog, not for T13.

**MINOR-3 — Pre-existing second surface of the "client idempotency key" concept.** `useIdempotencyKey` is the canonical hook, but `src/features/expenses/pages/ExpenseFormPage.tsx:29` and `src/features/income/pages/IncomeFormPage.tsx:26` each roll `useRef<string>(crypto.randomUUID())`, and `src/features/batches/pages/ExpiryWriteOffPage.tsx:169` rolls its own `setIdempotencyKey(crypto.randomUUID())`. Per `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md` these should converge on the hook. Out of T13's scope; queue as a follow-up sweep so the ID-3 contract has one implementation.

**MINOR-4 — Repo-level `pnpm --filter @autoerp/web lint` is RED on this branch and equally red on base `a97631051`.** 5 eslint parsing errors in `apps/web/e2e-local/*.ts` (from `b085ca881`, an ancestor of the base), 1 new `audit:keys` violation in `src/features/uom/hooks/useUnits.ts:53`, 15 new + 11 stale `audit:design-system` entries all in `src/features/import/pages/ImportWizardPage.tsx`. **None attributable to T13** — its four files contribute 0 errors and 0 warning growth. Raise with the orchestrator: the web lint gate is not currently a usable green baseline for any lane.

---

## 4. What held up

- Plan Step 4 implemented exactly: page scope, `idempotency_key` first key of the existing typed payload, reset immediately after the awaited `mutateAsync`, before toast/navigate, never in `catch`, never in `finally`, never before the request. Verified line by line on both pages.
- Plan Step 5 implemented exactly, including the `vi.clearAllMocks()` + explicit spy reset the plan called for at `CreateStockAdjustmentPage.test.tsx:92-93`.
- The field name matches what the backend actually reads, verified in both FormRequests and both controllers rather than assumed.
- Zero lint delta, measured against base file contents rather than trusted.
- Typecheck clean; 90/90 tests green on re-run; no vitest workers left behind.
- No baseline file touched; no alias/indirection/suppression; nothing in the diff defeats a detector.
- No i18n, token, `any`, `parseFloat`, `tenantScopedKey`, invalidation-root, route, nav or gate change — the diff is genuinely 49 lines of wiring plus comments.
- The handback's two riskiest claims were checked in source, not accepted: the adjustment refusal rolls the draft back in one transaction (`StockAdjustmentController.php:148-169`), and the ESLint 7-warning parity is real.

## 5. Deliberately not run

- **Browser double-click probe on both forms** — promotion-owed, as the handback states (no local stack: no API, no vite, no seeded tenant tonight). I did **not** block on it: nothing in the code makes it impossible to pass, and the transfer path is proven convergent by the backend collision harness. When it runs, it must (a) confirm one document per form under a genuine double-click, and (b) assert **no 5xx** in the network log — MAJOR-2 says the adjustment page can emit one.
- **Mutation testing of the shipped tests** — the brief marks this worktree read-only, so I did not mutate tracked files. MAJOR-1's coverage gap is established statically and is airtight: `toHaveBeenCalledTimes(1)` on a success-only path cannot discriminate reset-before-await from reset-after-await, from `finally`, or from a duplicate in `catch`. The positive falsification (dropping the field fails the test) is likewise certain from the assertion form — `toHaveBeenCalledWith` with a complete object literal is an exact match.
- Full PHPUnit suite and any backend gate — `inventory-costing-reviewer`'s leg.

## 6. Merge posture

Merge the web half. Before **promotion**, land MAJOR-1 (two failure-path tests) and make a call on MAJOR-3 (intent-scoped key or a posted-status assertion on the adjustment page); run the owed browser probe with the 5xx assertion from MAJOR-2. MINOR-4 needs an orchestrator decision independent of this lane.

---

## Re-gate r2 (2026-09-04)

- **Reviewer:** frontend-conventions-reviewer (adversarial merge gate, round 2)
- **Tip reviewed:** `c91c54c45` (fix round `0a09cd800` + docs `c91c54c45`); backend CI fix `ae3ac05ca` is the measurement base for the web delta
- **Diff re-gated:** `git diff ae3ac05ca..HEAD -- apps/web` — 4 files, +369/-29
- **Worktree state:** clean before and after every command I ran (`git status --porcelain` → empty; verified twice)

### VERDICT: MERGE

All three r1 MAJORs are genuinely closed. I did not take the handback's red-proof claims on trust — I reproduced every one of them by building five untracked mutant copies of the two pages inside `src/` (page + test copy with the import re-pointed), running them, and deleting them. **Five mutants, five kills, each by the intended test.** Lint delta re-measured per file against `ae3ac05ca` and confirmed at 0e/7w → **0e/5w** (an honest reduction, not a suppression). Zero blocking findings.

### 1. MAJOR-1 — failure-path tests: CLOSED, and falsifying

| Page | Test | file:line |
|---|---|---|
| transfer | *keeps the SAME idempotency key after a failed submit and rotates it only after a success* | `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx:557-597` |
| adjustment | *keeps the SAME key after a refused submit and rotates it only after a success* | `apps/web/src/features/stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx:331-373` |

Both assert the full chain the r1 fix directive asked for: the error reaches the operator (`toast.error('Could not create the transfer.')` at `...lineEntry.test.tsx:571-573`; `screen.getByText('refusal.INVALID_ADJUSTMENT_STATE')` at `CreateStockAdjustmentPage.test.tsx:344`), `reset` **not** called (`...lineEntry.test.tsx:576`, `CreateStockAdjustmentPage.test.tsx:346`), the retry carries the **same** key (`...lineEntry.test.tsx:585-586`, `CreateStockAdjustmentPage.test.tsx:354-355`), the success rotates it **once** (`...lineEntry.test.tsx:589-591`, `CreateStockAdjustmentPage.test.tsx:358-360`), and a third submit carries a **different** key (`...lineEntry.test.tsx:597`, `CreateStockAdjustmentPage.test.tsx:369`).

**Falsification against `reset()` in `finally` — reproduced by me, not read from the handback.** Mutant B (adjustment: `resetPostIdempotencyKey()/resetDraftIdempotencyKey()` moved out of the success path into the `finally`) and mutant E (transfer: `resetIdempotencyKey()` moved into the `finally`):

```
ZZMutAdjB × keeps the SAME key after a refused submit and rotates it only after a success
            → expected "spy" to not be called at all, but actually been called 1 times
ZZMutAdjB × mints a key PER INTENT so a lost draft response cannot be replayed as a post
            → expected 'adjustment-key-3' to be 'adjustment-key-1'
ZZMutTrE  × keeps the SAME idempotency key after a failed submit and rotates it only after a success
            → expected "spy" to not be called at all, but actually been called 1 times
```
r1's MAJOR-1 scenario (reset in `finally`) is now detected on both pages. Reset-before-await and a duplicate reset in `catch` are covered by the same assertion.

### 2. MAJOR-2 — synchronous latch: CLOSED on both pages

- `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:558` `const submitLockRef = useRef<boolean>(false)`; guard `:664-666` (first statement of `submitTransfer`, before any validation); set `:733` `submitLockRef.current = true`; released `:742-744` `finally`.
- `apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx:126` ref; guard `:286-288`; set `:291`; released `:318-320` `finally`.

**Set synchronously before the awaited call — verified by reading the whole intervening span, not by inspection of the diff hunk.** On the transfer page there is no `await` anywhere between the guard at `:666` and the assignment at `:733` (`CreateStockTransferPage.tsx:667-731` is pure synchronous validation: `toast.error` early-returns, `Array.filter`, a `for` loop over `bccomp`/`isBatchAllocationCovered`/`variantsByProduct.current.get`, then the payload literal). On the adjustment page the assignment at `:291` is the first statement inside `try` and the `mutateAsync` object literal is evaluated after it. Both intents and the acknowledge/"Apply anyway" path route through the one `submit()` (`CreateStockAdjustmentPage.tsx:648`, `:658`, `:679`), so the latch covers all three entry points.

**Both intents of the adjustment page are latched** — the guard is on `submit()` itself, which is intent-agnostic (`:281-288`).

**Double-click tests hold the POST unresolved and assert exactly one request:** `...lineEntry.test.tsx:606` and `CreateStockAdjustmentPage.test.tsx:382` both `mockReturnValue(new Promise<{ id: string }>(() => undefined))` — a never-settling promise, so the latch is still held when the second click lands; two `fireEvent.click` in one `act()` then a second `act()` flush; `expect(...).toHaveBeenCalledTimes(1)` (`...lineEntry.test.tsx:633`, `CreateStockAdjustmentPage.test.tsx:397`). `toHaveBeenCalledTimes(1)` also rules out the degenerate "zero requests" pass.

**Falsification reproduced by me.** Mutants A (adjustment latch deleted) and D (transfer latch deleted):
```
ZZMutAdjA × issues exactly ONE create request when Save & post is double-clicked
            → expected "spy" to be called 1 times, but got 2 times
ZZMutTrD  × issues exactly ONE create request when the submit button is double-clicked
            → expected "spy" to be called 1 times, but got 2 times
```
Both mutants failed **only** that one test (12/13 and 9/10 otherwise green), so the tests are attributable, not incidentally coupled.

#### Ruling on deviation 3 (double-click tests drive a plain line, not a scanned batch line): **ACCEPTED — harness timing, not a product defect. Coverage is unaffected.**

Two independent reasons. (a) **Coverage:** the latch guard is the *first* statement of `submitTransfer` (`CreateStockTransferPage.tsx:664-666`), before any line-type logic, and on the adjustment page it is the first statement of `submit()` (`:286-288`). A plain line exercises exactly the same code path a batch line would; nothing about the latch is line-type dependent. (b) **Mechanism:** the FEFO reconciliation effect at `CreateStockTransferPage.tsx:332-342` is gated on `batches.length === 0` and writes allocations through `onAllocationsChange`, i.e. it needs the batches query resolved *and* a subsequent commit; the submit guard at `:696` then reads `line.batchAllocations`. Under `fireEvent` (no awaits) the effect has not committed, so the guard fires. Under `user.click` (which flushes) it has. That is a test-harness scheduling artifact.

It does surface one **pre-existing** product observation, which I am recording rather than blocking on: the submit guard at `:696` does **not** distinguish "batches still loading" from "FEFO genuinely cannot cover" — the row-level affordance does (`:348` `isLoading`, `:354` `needsAllocation`) but the submit guard does not, so an operator who clicks Create while the batches query is still in flight gets `create.batch.cannotAllocateSubmit`, a misleading but **fail-closed** and self-correcting refusal. Identical code exists at base (`git show a97631051:…CreateStockTransferPage.tsx:681`), so it is not T13's debt. → **MINOR-6, follow-up.**

### 3. MAJOR-3 — intent-scoped keys (option 1): CLOSED; no path can post with the draft key

- Two independent instances: `apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx:116` (`draftIdempotencyKey`/`resetDraftIdempotencyKey`) and `:117` (`postIdempotencyKey`/`resetPostIdempotencyKey`).
- **Single send site**, ternary on the intent: `:293` `idempotency_key: postImmediately ? postIdempotencyKey : draftIdempotencyKey`. Grepped the whole file: `idempotency_key` appears exactly once, `postIdempotencyKey` twice (send + its own reset), `draftIdempotencyKey` twice. There is no second `mutateAsync`, no second create call site, and no other consumer of either key.
- **Each reset only on its own success**: `:309-313`, inside `try`, after the awaited `mutateAsync`, branching on the same `postImmediately`.
- **The acknowledge/"Apply anyway" path carries the post key**: `:679` calls `submit(values, true, acknowledgeableCode)` — `postImmediately === true` → the ternary at `:293` selects `postIdempotencyKey`. The re-anchor path (`:684`) does not submit. So every posting path — plain post, acknowledge-override — uses the post key, and the only draft-key path is `postImmediately === false`.
- **Confirmed no path can send a post request with the draft key.** The intent is a required positional parameter of `submit()` (`:283`), the ternary is the sole selector, and all three call sites pass a literal (`false` at `:648`, `true` at `:658`, `true` at `:679`).

**The red test proves the exact r1 scenario** — *mints a key PER INTENT so a lost draft response cannot be replayed as a post*, `CreateStockAdjustmentPage.test.tsx:411-441`: the draft submit rejects with a bare `Error('network')` (a lost response — `extractRefusal` returns `null` at `apps/web/src/features/stock-adjustments/api/refusals.ts:133-135`, so nothing renders), then the post submit must carry a **different** key (`:433`), and — the part that makes it *scoping* rather than blanket rotation — a subsequent draft retry must carry the **same** key as the first draft (`:439`).

**Falsification reproduced by me.** Mutant C (both intents share `draftIdempotencyKey`, single reset):
```
ZZMutAdjC × mints a key PER INTENT so a lost draft response cannot be replayed as a post
            → expected 'adjustment-key-1' not to be 'adjustment-key-1'
ZZMutAdjC × keeps the SAME key after a refused submit and rotates it only after a success
            → expected 'adjustment-key-1' to be 'adjustment-key-2'
ZZMutAdjC × sends a SIGNED string delta and the fresh anchor, and posts immediately
            → expected 'adjustment-key-1' to be 'adjustment-key-2'
```

**Residual the fix creates, recorded honestly (MAJOR-4 below):** option 1 removes the dangerous direction (lost *draft* response → post replays the draft → operator navigates believing stock moved when it did not) and leaves a mirrored, milder one — lost *post* response followed by "Save draft" now mints a fresh key and creates a **second, duplicate DRAFT document** instead of replaying the posted one. A draft moves no stock and is visible, so this is strictly less dangerous than what it replaced; but it is the same root cause, and the r1 directive's trailing clause ("give the adjustment page a visible generic-error path so a lost response is not silent") was **not** implemented. See MAJOR-4.

#### Ruling on deviation 1 (stateful hook fake in both feature tests): **ACCEPTED — the fake is faithful; no additional real-hook test required.**

Real hook, read in full (`apps/web/src/hooks/useIdempotencyKey.ts:10-17`):
```ts
const [key, setKey] = useState(() => crypto.randomUUID())
const reset = useCallback(() => { setKey(crypto.randomUUID()) }, [])
return { key, reset }
```
Fake (`...lineEntry.test.tsx:60-77`, `CreateStockAdjustmentPage.test.tsx:98-116`): `useState(mint)` lazy initialiser + `useCallback([])` `reset` that calls the spy and `setKey(mint())`, with `useState`/`useCallback` pulled from `vi.importActual('react')` — **real React state, not a shim**. Point by point: per-instance state ✓ (two `useState` calls in one component are independent in both); rotates **only** on its own `reset` ✓; `reset` identity stable across renders ✓; new value computed eagerly at reset-call time ✓; new object literal each render ✓. The only divergences are (a) sequential literals instead of UUIDs and (b) the added spy call — neither can make a test pass on behaviour the real hook lacks; both only make assertions *more* discriminating.

Three further reasons the composition is closed rather than hand-waved:
1. The real hook's contract is unit-tested against the **real** implementation at `apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx:9-20` (one key until reset, a **new** UUID after reset, both matching `UUID_V4`), `:22-29` (per-mount uniqueness — the exact property the two-instance intent scoping relies on), `:31-37` (stable `reset` identity). Those are precisely the four properties the fake implements. I re-ran that file (green, in the 165).
2. The mock is keyed on the module specifier `@/hooks/useIdempotencyKey`, so the page must *actually import the real hook* for the tests to see the fake at all. If someone inlined `crypto.randomUUID()` on the page, `idempotency_key` would be a UUID and `expect(...).toBe('transfer-key-1')` would fail. The wiring itself is pinned.
3. My mutants B and C ran against the **fake** and were killed — the fake demonstrably discriminates the behaviours under test, which a frozen literal could not.

The narrow residue is unchanged r1 MINOR-1: no feature test observes a real v4 string in a real request body. That is a browser-probe item, not a test-design defect.

### 4. Verification commands and outputs (all re-run by me on `c91c54c45`)

```
cd apps/web && pnpm vitest run src/features/stock-transfers src/features/stock-adjustments src/hooks/__tests__
 Test Files  32 passed (32)
      Tests  165 passed (165)
   Duration  6.90s
```
Default pool, no `--singleFork`. `ps aux | grep -c '[n]ode (vitest'` afterwards → `0`. Matches the handback's claim exactly (32/165).

```
cd apps/web && pnpm typecheck     → tsc --noEmit, exit 0, no output
```

```
cd apps/web && pnpm audit:keys    → FAIL (exit 1), 1 violation:
  src/features/uom/hooks/useUnits.ts:53:9  invalidateQueries({queryKey: tenantScopedKey([...])}) is a no-op filter
```
**Not this lane, and already fixed on `dev`.** `useUnits.ts` is absent from `git diff a97631051..HEAD --name-only` (grep count 0), and `git show dev:apps/web/src/features/uom/hooks/useUnits.ts` line 53 is now `predicate: uomUnmappedUnitTextsInvalidationPredicate(tenantId, companyId)` — the violating line no longer exists on `dev` (landed by `lane/rh-web-lint-debt`, merge `5edb7e810`). T13 is based on `a97631051`, which predates it. The merged tree carries dev's fixed version.

**Mutation testing (five untracked mutants, created inside `src/`, run, deleted):**
```
cd apps/web && pnpm vitest run src/features/stock-adjustments/__tests__/ZZMutAdj{A,B,C}.test.tsx \
                               src/features/stock-transfers/__tests__/ZZMutTr{D,E}.test.tsx
 Test Files  5 failed (5)
      Tests  8 failed | 51 passed (59)
```
| Mutant | Mutation | Killed by | Failure |
|---|---|---|---|
| A | adjustment latch removed | double-click test | got 2 times |
| B | adjustment `reset()` → `finally` | failure-path + intent tests | spy called 1 times / `'adjustment-key-3'` |
| C | adjustment single shared key | intent + 2 payload key assertions | `'adjustment-key-1' not to be 'adjustment-key-1'` |
| D | transfer latch removed | double-click test | got 2 times |
| E | transfer `reset()` → `finally` | failure-path test | spy called 1 times |

All mutant files removed; `git status --porcelain` empty afterwards (re-checked).

### 5. Lint delta per file vs `ae3ac05ca` — re-measured, not accepted

Base contents written to `ZZBase*` siblings **inside `src/`** (so relative imports and the tsconfig project resolve), linted with `--format json`, deleted; `git status --porcelain` empty afterwards.

| File | base `ae3ac05ca` | HEAD `c91c54c45` |
|---|---|---|
| `stock-adjustments/__tests__/CreateStockAdjustmentPage.test.tsx` | 0 err / 2 warn (`233:21`, `266:21` `no-unsafe-type-assertion`) | **0 / 0** |
| `stock-adjustments/pages/CreateStockAdjustmentPage.tsx` | 0 / 3 (`335:30`,`387:30`,`410:30` `restrict-template-expressions`) | 0 / 3 (`362:30`,`414:30`,`437:30`) |
| `stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx` | 0 / 1 (`287:21`) | 0 / 1 (`312:21`) |
| `stock-transfers/pages/CreateStockTransferPage.tsx` | 0 / 1 (`965:25`) | 0 / 1 (`977:25`) |
| **total** | **0 err / 7 warn** | **0 err / 5 warn** |

The handback's `0e/7w → 0e/5w` is **confirmed by measurement**. Every surviving warning is the same rule at the same code site, shifted by exactly the inserted line count (+27 / +25 / +12). The two removed warnings are real: typing the mock as `vi.fn<(input: CreateStockAdjustmentInput) => Promise<{ id: string }>>()` (`CreateStockAdjustmentPage.test.tsx:67`) eliminated the two `as {...}` payload casts. **Mechanism audit:** that is a genuine type improvement, not an evasion — the assertions it enables are *stricter* (`createMutate.mock.calls[0][0]` is now the real `CreateStockAdjustmentInput`), and it introduces no alias table, no suppression comment, no `eslint-disable`, and no renamed-equivalent literal. Grepped the diff for `eslint-disable`, `@ts-expect-error`, `@ts-ignore`, `--write-baseline`: zero hits.

**Baseline honesty:** `apps/web/tools/**` is absent from `git diff a97631051..HEAD --name-only`. The web half of this lane is exactly 4 source files. No baseline absorption.

### 6. Merge-tree

Run read-only from the main checkout (`/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`):
```
git merge-tree --write-tree dev lane/rh-t13-transfer-idempotency
  → exit 0, tree 8cd95e13367f13e97e731ad886b79aa610bdfccc, no conflict output   CLEAN

git merge-tree --write-tree lane/rh-t13-transfer-idempotency lane/rh-t7-stock-dedupe
  → exit 0, tree e2d4e8b4adb1c365dd341a630838ea0270834616, no conflict output   CLEAN
```
`merge-base(dev, lane/rh-t13) = a97631051`; `git diff --name-only a97631051..dev` over the T13 web paths returns only `stock-adjustments/__tests__/queries.test.tsx` and `tools/audit-design-system-baseline.json`, neither of which T13 touches.

**T7 semantic check (both branches edit `CreateStockTransferPage.tsx`):** T7's hunks (`1fedd6da9`) are confined to the `AvailabilityCell` / `ProductStockLevels` region — removing the local `useQuery` at ~`:413-425` and the two local interfaces at ~`:402-410`, adding the `useProductStockLevels` import. T13's hunks are at `:558` (ref declaration, inside the component body ~140 lines below) and `:664-744` (`submitTransfer`). **Disjoint regions, disjoint concerns — clean textually and semantically.** Merge order does not matter.

Local `dev` = `5edb7e810`, ahead of `origin/dev` = `4d5b8812e` (T2/T3/T4/lint merged locally, unpushed) — as the brief states.

### 7. Findings

#### BLOCKER
None.

#### MAJOR

**MAJOR-4 (new, promotion-owed, NOT merge-blocking) — the second half of r1's MAJOR-3 directive was not implemented: the adjustment page still swallows every non-envelope error silently.**
`apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx:315` `setRefusal(extractRefusal(error))` is the *only* error handling; `extractRefusal` returns `null` for anything without a `{data:{error:{…}}}` envelope (`apps/web/src/features/stock-adjustments/api/refusals.ts:129-135`); the sole error surface renders only when `refusal !== null` (`CreateStockAdjustmentPage.tsx:639-641`) and there is no `toast.error` in the `catch`. A network failure, a timeout, a 500 or a lost response therefore produces **no visible feedback at all** — the operator sees an unchanged form and cannot tell whether the server committed. The intent-scoped keys now make the *consequence* milder (a duplicate DRAFT rather than a silent non-post), but the silence itself is untouched. Note the transfer page does not share this: `CreateStockTransferPage.tsx:741` has `toast.error(t('create.error'))`.
This is pre-existing behaviour (identical at `a97631051`), the r1 gate rated its consequence MAJOR rather than BLOCKER, and the chosen option 1 defuses the dangerous direction, so it does not gate the merge.
*Fix directive:* in the `catch` at `CreateStockAdjustmentPage.tsx:314-316`, when `extractRefusal(error)` is `null`, surface a generic `toast.error(t('create.error'))` (new key in the adjustments namespace) so a lost response is never silent — and add a test that rejects with a bare `Error` and asserts the toast.

#### MINOR

**MINOR-1 (r1, unchanged, promotion-owed)** — the feature tests still never observe a real UUID; UUID-ness lives only in `apps/web/src/hooks/__tests__/useIdempotencyKey.test.tsx:6,17,19,26,27`. Strengthened this round (the module-specifier mock pins the page to the real hook — see the deviation-1 ruling), but the browser probe should capture one real request body and eyeball the v4 shape.

**MINOR-2 (r1, unchanged, backlog)** — `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:1047-1055` is still a hand-rolled `flex justify-end` footer rather than `StickyFormFooter` + `SaveSplitButton`. Pre-existing, untouched by T13; UI-audit backlog.

**MINOR-3 (r1, unchanged, follow-up sweep)** — second surfaces of the client-idempotency-key concept persist: `apps/web/src/features/expenses/pages/ExpenseFormPage.tsx:29`, `apps/web/src/features/income/pages/IncomeFormPage.tsx:26`, `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.tsx:146`, `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:169`. Per `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md` these should converge on `useIdempotencyKey`. Out of T13's scope.

**MINOR-4 (r1) — CLOSED by `dev`, not by this lane.** The repo-level web lint red I flagged in r1 (5 eslint parse errors in `apps/web/e2e-local/*.ts`, the `useUnits.ts:53` `audit:keys` violation) is fixed on `dev` by `lane/rh-web-lint-debt` (`f77eb40be`, `ec5810b2f`, merge `5edb7e810`), which changed `apps/web/eslint.config.js` and the uom invalidation. It still reproduces inside this worktree only because the lane is based on `a97631051`. No action for T13; re-measure the repo gate after the merge.

**MINOR-5 (new, follow-up)** — the intent-key literals in the tests couple to the *declaration order* of the two hooks on the page (`adjustment-key-1` = draft, `adjustment-key-2` = post; `CreateStockAdjustmentPage.test.tsx:279`, `:307`). Swapping `:116`/`:117` would fail the tests with a confusing message. False-failure only, never a false pass; a comment already documents the mint order (`CreateStockAdjustmentPage.test.tsx:96`). Accepted as-is.

**MINOR-6 (new, follow-up, pre-existing)** — the transfer submit guard at `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:696` does not distinguish "batches still loading" from "FEFO cannot cover", so a fast operator can get a misleading `create.batch.cannotAllocateSubmit`. Fail-closed and self-correcting; identical at base. See the deviation-3 ruling.

#### Browser probe (r1 MAJOR-2 second half) — **promotion-owed, not merge-blocking**
Unchanged from r1 and explicitly deferred by the handback's deviation 4. It must assert (a) exactly one document per form under a genuine double-click and (b) **zero 5xx in the network log** — the adjustment endpoint still has no collision replay (backend residual N-6), so any duplicate that does reach it loses with a 500. The synchronous latch now makes a second request very unlikely to be issued at all, which is why this drops from "the code may not be able to pass" to "prove it in a browser".

### 8. Merge posture

**MERGE the web half.** Nothing blocks. Promotion still owes, in priority order: (1) the browser double-click probe on both forms with the zero-5xx assertion; (2) MAJOR-4, the generic-error surface on the adjustment page; (3) MINOR-1's real-UUID capture during that probe. MINOR-2/3/6 are backlog. MINOR-4 is closed by `dev`.
