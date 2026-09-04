# Gate — request-hygiene T12c (frontend-conventions, adversarial)

- Date: 2026-09-04
- Reviewer: frontend-conventions gate (adversarial, read-only)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12c`
- Branch: `lane/rh-t12c-modal-promotion-owed` · base local `dev` `c38bf5977`
- Commits: `ba0fce031` (5 files) + `02b5e99aa` (handback)
- Source gate: `docs/superpowers/reviews/2026-09-04-request-hygiene-t12b-gate-treasury.md` (r2 NB-7 + promotion-owed item 1)

## VERDICT: APPROVE-WITH-FIXES

Both promotion-owed items are genuinely implemented, mechanism-verified against the installed
TanStack Query v5.90.11 source, and falsifiable. Nothing in the diff evades a detector, no
baseline was touched, no lint/typecheck/test regression. One required fix before promotion
(a regression test for the load-bearing `isError` guard — the gate reproduced the wedge it
prevents and the current suite does not catch its removal). Browser legs remain owed.

---

## Blocking findings

### B1 (MAJOR, must fix before promotion) — the `isError` guard is load-bearing and untested
`apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:456`

The guard's rationale is CORRECT and the gate reproduced the failure it prevents, but no test
protects it: replacing `if (mutation.isError) mutation.reset()` with a bare `mutation.reset()`
leaves the whole `RecordPaymentModal` suite green (17/17 passed with the guard removed).

Mechanism (verified in the installed package, not from memory):
- `node_modules/.pnpm/@tanstack+query-core@5.90.11/.../build/modern/mutationObserver.js` —
  `reset() { this.#currentMutation?.removeObserver(this); this.#currentMutation = void 0; ... }`
  and the per-`mutate` `onSuccess`/`onError`/`onSettled` callbacks are only invoked from
  `#notify(action)`, which is only reached via `onMutationUpdate(action)` — i.e. only while the
  observer is still attached. A `reset()` before the settling dispatch therefore DROPS the
  `onSettled` passed to `mutate(vars, { onSettled })`.
- `.../build/modern/mutation.js` — `execute()` awaits `this.options.onSuccess(...)` BEFORE
  `this.#dispatch({ type: 'success', data })`. `RecordPaymentModal.tsx:406` calls
  `resetIdempotencyKey()` as the FIRST statement of `onSuccess`, before
  `await Promise.all([...invalidateQueries])`. Those invalidations are real HTTP refetches in
  production, so React has ample time to flush the render + passive effect while the mutation
  is still `pending` → an unguarded reset detaches the observer → `submitLockRef`
  (`RecordPaymentModal.tsx:469-472`) is never released → every later submit from the same
  mounted modal is silently swallowed while the operator sees the success panel.

Falsifying scenario, run by this gate (RED with the guard removed, GREEN with it):
successful submit → make the awaited invalidation refetches resolve on a MACROTASK
(`mockApiGet` wrapped in `setTimeout(..., 20)`, then `await act(async () => setTimeout(80))`)
→ close/reopen the still-mounted modal → confirm a line → press Record.
- unguarded: `expected "spy" to be called 2 times, but got 1 times`
- guarded: passes.
With the default (microtask) mocks the probe passes either way — which is exactly why the
current suite cannot see the guard.

Fix directive: add that probe (macrotask-latency `apiGet` mock + second submit asserts a
second POST) to `__tests__/idempotencyKeyLifecycle.test.tsx` so a future "simplification" of
the guard reds instead of wedging payments.

---

## Non-blocking findings

### N1 (MAJOR follow-up) — the duplicate-payment risk survives the rotation; the warning does not
`RecordPaymentModal.tsx:936-940` (possiblyRecorded) and `:943-949` (raw error), cleared via
`:453-457`. After a lost response the previous attempt may have COMMITTED. On the first payload
edit the key rotates and both banners vanish — but the risk does not: pressing Record now posts
a NEW key and books a SECOND payment with no warning at all. The diff is still a strict
improvement (the pre-fix copy advised an action that was already unsafe), so this is a
follow-up, not a blocker.
Fix directive: keep a `priorAttemptMayHaveCommittedRef` (not cleared by
`startNewIntentOnPayloadEdit`, `:161-165`) and render a rotated-intent variant —
"a previous attempt may already have been recorded; check the payment list before recording
again" — instead of showing nothing. (Owner principle: UI must not understate a known risk.)

### N2 (MINOR) — effect deps `[idempotencyKey, mutation]` re-run the effect on EVERY render
`RecordPaymentModal.tsx:457`. Verified in
`node_modules/.pnpm/@tanstack+react-query@5.90.11.../build/modern/useMutation.js`:
`return { ...result, mutate, mutateAsync: result.mutate }` — a fresh object literal per render,
so the `mutation` dep never compares equal and the effect body runs after every commit.
RULING: harmless — the effect has no cleanup and no subscription; the `errorIntentKeyRef`
compare early-returns; the library itself already runs a per-render effect
(`useEffect(() => observer.setOptions(options), [observer, options])`). Not worth blocking.
Fix directive (optional): `[idempotencyKey, mutation.isError, mutation.reset]` — `reset` is
bound in the `MutationObserver` constructor and re-emitted by `#updateResult()`, so its
identity is stable, and behaviour is unchanged.

### N3 (MINOR) — narrow in-flight race the guard cannot cover
`RecordPaymentModal.tsx:453-457`. If the key rotates while a POST is in flight (document swap
under an open modal, `:195-223`), `isError` is false at that moment, the ref is advanced, and
when the in-flight request then errors the banner paints over the NEW document with no further
rotation to clear it. Narrow (requires a route swap during the POST), and no worse than the
pre-fix behaviour. Note it in the follow-up brief with N1.

### N4 (MINOR) — comment undercounts the rotation sites
`RecordPaymentModal.tsx:437-441` says the key "rotates in exactly three places" and lists the
open transition, the intent change and the payload edit; the fourth site, `onSuccess` (`:406`),
appears only in the following paragraph. Reword to "three non-success sites".

### N5 (MINOR) — `routes.test.tsx` wiring assertions are enumerated, not exhaustive
`apps/web/src/routes/routes.test.tsx:257-276`. The three hosts are hand-listed and matched by a
200-character text window. Falsified by this gate (removing the sales-order wrapper reds exactly
that one test — no false pass from a neighbouring route's wrapper), so the assertions are real;
but a fourth detail host added later is not caught. Fix directive: add a completeness assertion
(every `*DetailPage` mounted on an `:id` route that renders `RecordPaymentModal` must sit inside
`<KeyedByRouteId>`), or a comment pinning the list to the `<RecordPaymentModal` grep.

### N6 (MINOR) — test-only export widens the routes module surface
`apps/web/src/routes/index.tsx:336` exports `KeyedByRouteId` so
`src/routes/__tests__/KeyedByRouteId.test.tsx` can render it; the test consequently imports the
entire 220-lazy-page route table. Acceptable; a `src/routes/KeyedByRouteId.tsx` module would be
cleaner if it is touched again.

### N7 (INFO) — `SupplierInvoiceDetailPage` hosts a SECOND, hand-rolled payment surface
`src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:76-99` holds
`paymentAmount`/`paymentMethodId`/`paymentRepositoryId`/… in page state with its own
`useRecordSupplierPayment(id)` and a native `<dialog>`, i.e. a parallel payment-entry surface
beside `RecordPaymentModal` (one-surface-per-concept). Its route `src/routes/index.tsx:1056` is
NOT wrapped in `KeyedByRouteId`; because `openPaymentDialog()` re-seeds per open and `id` is read
live, the money bug does not reproduce, but a dialog left OPEN across an `:id` change would post
document A's amount against document B. Pre-existing, outside T12c's stated scope.

### N8 (INFO) — base `dev` is RED on `pnpm lint`
`pnpm lint` fails at `audit:design-system` with 25 findings, all in
`src/features/import/pages/ImportWizardPage.tsx` (C2 raw inputs, C3 raw buttons) plus stale
baseline entries, and `audit:i18n:local` exits 1 (ar|uom missing keys). Both reproduce
byte-identically on the main checkout at `c38bf5977`. NOT caused by this lane.

---

## What held up

1. Item 1 mechanism — VERIFIED against the installed v5.90.11 sources (see B1). The claim that
   resetting on the success rotation drops `onSettled` and wedges `submitLockRef` is not
   hand-waving: the gate reproduced the wedge (POST count 1 instead of 2).
2. The T12b open/intent effect is BYTE-UNCHANGED — `git diff` on
   `RecordPaymentModal.tsx` is a single hunk `@@ -434,6 +434,28 @@`; nothing above line 434 moved.
   The r2 "exactly one effect" observation is superseded; the new effect touches no entry state.
3. Tests (a)/(b)/(c) — falsified as claimed. Deleting only `if (mutation.isError) mutation.reset()`
   reds exactly (a) and (b), leaves (c) plus all 11 other tests in the file green. `waitFor` is
   required and correctly justified: `reset()` → `#notify()` → `notifyManager.batch(...)`, which
   flushes outside the triggering commit.
4. Item 2 — `sales/orders/:id` (`src/routes/index.tsx:706-716`) and `purchases/orders/:id`
   (`:957-967`) now nest `<KeyedByRouteId>` inside `<SuspenseWrapper>`, identical to
   `invoices/:id` (`:748-758`). Behavioural test with a real negative control
   (`src/routes/__tests__/KeyedByRouteId.test.tsx:56-74`) — the control proves the harness can
   observe the difference. The source-text wiring assertion is falsifiable (see N5).
   RULING: a source-text wiring assertion + a behavioural component test is ACCEPTABLE here; a
   lazy-route smoke over `AppRoutes` is not required, because the owed browser leg covers the
   end-to-end wiring and a lazy-route harness would prove no more than the two existing tests.
5. Unwrapped hosts — `grep -rn "<RecordPaymentModal" src` returns exactly three hosts
   (`InvoiceDetailPage.tsx:895`, `SalesOrderDetailPage.tsx:780`, `PurchaseOrderDetailPage.tsx:709`);
   all three routes are now wrapped. Quote (`:667`), credit-note (`:807`), delivery-note (`:1270`)
   detail pages host only `ConfirmDialog`s (no payload state); `SupplierInvoiceDetailPage` (`:1056`)
   does not host `RecordPaymentModal` — see N7.
6. Mechanism/evasion audit — no `eslint-disable`, no `@ts-expect-error`, no `.skip`/`.only`, no
   baseline file touched (`git diff --stat -- apps/web/tools/` is empty), no alias/indirection.
7. Handback numbers re-run and matched exactly (109 tests / 13 files; 453 tests / 53 files).

## Commands and outputs

```
$ git diff --stat c38bf5977..02b5e99aa
 RecordPaymentModal.tsx | 22 +++ ; idempotencyKeyLifecycle.test.tsx | 121 +++ ;
 routes/__tests__/KeyedByRouteId.test.tsx | 75 +++ ; routes/index.tsx | 17 +- ;
 routes/routes.test.tsx | 32 +++ ; HANDBACK-…T12c…md | 153 +++     (6 files, 417+, 3-)

$ pnpm vitest run src/components/organisms/RecordPaymentModal src/routes \
    src/features/documents/sales-orders src/features/documents/purchase-orders
 Test Files  13 passed (13)
      Tests  109 passed (109)

$ pnpm vitest run src/features/documents
 Test Files  53 passed (53)
      Tests  453 passed (453)

$ # falsification 1 — delete `if (mutation.isError) mutation.reset()`
 × clears the failure banners when the operator edits the payload after a failure (…)
 × clears the failure banners when the document swaps under the open modal
 ✓ KEEPS the failure banners while the intent is unchanged
 Tests  2 failed | 11 passed (13)        # file restored from a byte copy; tree clean

$ # falsification 2 — drop only the `isError` guard (bare mutation.reset())
 Test Files  2 passed (2)   Tests  17 passed (17)     # NOTHING catches it  → finding B1

$ # falsification 3 — the wedge, with macrotask-latency invalidation refetches
 unguarded: × expected "spy" to be called 2 times, but got 1 times
 guarded:   ✓ passes

$ # falsification 4 — unwrap the sales-order host in routes/index.tsx
 × document detail hosts remount on a route-param change > wraps the sales order detail host
 Tests  1 failed | 24 passed (25)        # restored

$ npx eslint <5 changed files>      HEAD: RecordPaymentModal.tsx errors=0 warnings=25;
   idempotencyKeyLifecycle.test.tsx / routes/index.tsx / routes.test.tsx / KeyedByRouteId.test.tsx  0/0
$ git show c38bf5977:<path> > <path> ; npx eslint … ; git checkout -- <path>
   BASE: RecordPaymentModal.tsx errors=0 warnings=25 — IDENTICAL rule multiset
   {array-type 2, react-hooks/immutability 1 (line 208, pre-existing), react-hooks/exhaustive-deps 3,
    precision/no-parsefloat-on-money 6, no-unnecessary-template-expression 11,
    restrict-template-expressions 1, no-misused-promises 1}
   → 0 errors, ZERO new warnings; no new `react-hooks/immutability` instance (lane claim holds).

$ pnpm typecheck            → exit 0, no output
$ pnpm lint:eslint          → exit 0
$ pnpm audit:keys           → exit 0
$ pnpm audit:quantity       → exit 0
$ pnpm test:eslint-rules    → exit 0
$ pnpm test:tools           → 166 passed (166)
$ pnpm audit:design-system  → exit 1, output byte-IDENTICAL to the same command on the main
                              checkout at c38bf5977 (diff: no output) → pre-existing (N8)
$ pnpm audit:i18n:local     → exit 1 on BOTH base and head; only the cwd line differs (N8)

$ ps aux | grep '[v]itest'  → 0 processes
$ git status --short (worktree) → clean (all falsification edits restored)
```

## Merge-tree

```
$ cd /Users/houssamr/Projects/syneriva/apps/erp   # dev = c38bf5977
$ git merge-tree --write-tree dev lane/rh-t12c-modal-promotion-owed
753778d73661b34569836a8c99f6616100383811
(exit 0, no conflict lines) → CLEAN, fast-forwardable
```

## Still owed (promotion preconditions)

- Browser legs on a real tenant, unchanged by this lane and NOT satisfied by any test here:
  (a) submit → drop the response → reconnect refetch → press Record untouched → exactly ONE
  `payments` row and ONE treasury movement; (b) route swap with the modal open on a sales
  order / purchase order → the host remounts and the POST carries the new document.
- B1 regression test.
- N1 follow-up brief (rotated-intent warning), N7 (supplier-invoice second payment surface).
