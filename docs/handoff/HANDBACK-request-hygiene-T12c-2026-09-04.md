# Handback — Request Hygiene T12c (promotion-owed items from the T12b gate)

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12c`
- Branch: `lane/rh-t12c-modal-promotion-owed` · base local `dev` `c38bf5977`
- Source gate: `docs/superpowers/reviews/2026-09-04-request-hygiene-t12b-gate-treasury.md` — re-gate r2 **NB-7** and promotion-owed item **1** (`KeyedByRouteId` on the two `orders/:id` hosts)
- Gate requested: **frontend-conventions** (adversarial)

Scope was strictly these two items. No other finding from the T12b gate was touched.

---

## Item 1 — NB-7: the failure banners are now scoped to the intent that failed

**Defect.** `mutation.reset()` was never called, so `mutation.isError` outlived the intent
it belonged to. Both banners — "may already have been recorded"
(`RecordPaymentModal.tsx:936`, gated `mutation.isError && confirmedCount > 0 && balanceDue === 0`)
and the raw error banner (`:943`, gated on `mutation.isError` alone) — therefore survived
an idempotency-key rotation. After a rotation the copy "press Record without changing
anything to confirm" is affirmatively wrong: Record posts a NEW key and books a **second**
payment. A failure on document A also painted its red banner over document B.

**Fix** — `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:437-457` (ref `:452`, effect `:453-457`),
one ref + one effect placed immediately after the mutation:

```ts
const errorIntentKeyRef = useRef(idempotencyKey)
useEffect(() => {
  if (errorIntentKeyRef.current === idempotencyKey) return
  errorIntentKeyRef.current = idempotencyKey
  if (mutation.isError) mutation.reset()
}, [idempotencyKey, mutation])
```

The key rotation is the **single choke point** for "this is a different intent now" — it
covers all three rotation sites (the open transition, the intent/document swap, and the
first payload edit after a failure via `startNewIntentOnPayloadEdit`) without a
`reset()` call sprinkled at each.

Two deliberate decisions, both load-bearing:

1. **Guarded on `mutation.isError`.** The key ALSO rotates in `onSuccess` (`:406`).
   Resetting there detaches the mutation observer *during* `Mutation.execute`, before the
   `success` dispatch, which drops the per-mutate `onSettled` that releases
   `submitLockRef` (`handleSubmit`, `:459-473`) — every later submit from the same mounted
   modal would be silently swallowed. Measured while building the lane; the `isError` guard
   is what keeps that path untouched.
2. **Placed after `const mutation`, not inside the existing open/intent effect.** Referencing
   `mutation` from the earlier effect trips `react-hooks/immutability`
   ("accessed before it is declared", the rule that already fires at `:208` for
   `createNewPaymentLine`), i.e. it would have added a NEW lint warning. Moving
   `startNewIntentOnPayloadEdit` below the mutation instead would have put the same rule on
   its five earlier callers.

**Note for the next gate:** the T12b gate r2 verified "there is exactly ONE `useEffect` in
this component". There are now **two**. The new one touches **no entry state** — it only
clears TanStack mutation error state on a key rotation. The T12b cure (the single
open/intent effect at `:195-223` (unchanged line numbers)) is byte-unchanged.

**Behaviour detail:** `mutation.reset()` notifies through TanStack's `notifyManager`, which
batches outside the triggering commit, so the banners clear on the next tick (the two new
tests use `waitFor` for exactly this reason). Not operator-visible.

### Tests (red first, then green, then falsified)

`apps/web/src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx`
— 3 new tests, 14 → 17 in the file's two describes:

- (a) failure → refetch drops the outstanding to 0 → both banners up → operator changes the
  payment date (a payload edit, so the key rotates) → **both banners gone**, while the
  confirmed line and the zero outstanding still stand (so the other two halves of the
  banner gate are provably not what cleared it).
- (b) failure on document A → the document swaps under the open modal → **the raw error
  banner is gone**. This is the load-bearing assertion: the "possibly recorded" banner would
  fall on its own once the re-seed clears the confirmed line, the raw one would not.
- (c) control — failure, then a same-intent re-render (what the reconnect refetch actually
  produces): **both banners stay up**. The cure must scope the banners, not suppress them.

Falsification: deleting only `if (mutation.isError) mutation.reset()` reds exactly (a) and
(b) and leaves (c) and all 14 pre-existing tests green. Restored from a byte copy.

---

## Item 2 — the two order detail hosts remount on a route-param change

`apps/web/src/routes/index.tsx` — `sales/orders/:id` (`:704-716`) and
`purchases/orders/:id` (`:955-967`) are now wrapped in `KeyedByRouteId`, exactly like
`invoices/:id`. `KeyedByRouteId` is now exported (`:336`) so it can be tested behaviourally,
and its doc comment records why all three document detail hosts need it.

This is the structural belt behind the modal-side money fix: the two order hosts mount the
same `RecordPaymentModal`, whose POST reads `prefill.partner_id` / `prefill.document_id` at
submit time.

### Tests

- `apps/web/src/routes/__tests__/KeyedByRouteId.test.tsx` (new, 2 tests) — behavioural, on
  the real exported component: a probe holding `useState` inside a `MemoryRouter` route
  loses its draft when the `:id` param changes; the **negative control** (same probe, no
  wrapper) keeps it, which is what proves the harness can see the difference at all.
- `apps/web/src/routes/routes.test.tsx` (+3 tests, source-text, matching that file's
  existing harness) — all three document detail hosts must sit inside `<KeyedByRouteId>`.
  Red before the change on sales + purchases, green after.

**Limitation (recorded, as the brief asked):** there is no harness that renders `AppRoutes`
itself (the file is 220 lazy pages; `routes.test.tsx` reads it as source text by design), so
the wiring assertion is source-text and the remount semantics are proven separately on the
exported component. A real end-to-end proof is the browser leg already owed below.

---

## Verification

```
$ pnpm vitest run src/components/organisms/RecordPaymentModal src/routes \
    src/features/documents/sales-orders src/features/documents/purchase-orders
 Test Files  13 passed (13)
      Tests  109 passed (109)          # 101 pre-lane + 3 (NB-7) + 3 (routes) + 2 (KeyedByRouteId)

$ pnpm vitest run src/features/documents          # wider blast-radius check
 Test Files  53 passed (53)
      Tests  453 passed (453)

$ # falsification, item 1: delete `if (mutation.isError) mutation.reset()`
 × … clears the failure banners when the operator edits the payload after a failure (the key has rotated)
 × … clears the failure banners when the document swaps under the open modal
 ✓ … KEEPS the failure banners while the intent is unchanged
 Tests  2 failed | 15 passed (17)      # restored from a byte copy

$ pnpm typecheck
TYPECHECK_EXIT=0

$ # eslint per file, base c38bf5977 (git show -> sibling in the same dir, removed after)
BASE RecordPaymentModal.tsx   errors 0 warnings 25  {array-type 2, no-misused-promises 1,
  no-unnecessary-template-expression 11, restrict-template-expressions 1,
  precision/no-parsefloat-on-money 6, react-hooks/exhaustive-deps 3, react-hooks/immutability 1}
HEAD RecordPaymentModal.tsx   errors 0 warnings 25  {identical multiset}
BASE/HEAD idempotencyKeyLifecycle.test.tsx · routes/index.tsx · routes.test.tsx  errors 0 warnings 0
NEW  routes/__tests__/KeyedByRouteId.test.tsx  errors 0 warnings 0

$ ps aux | grep '[v]itest'   => empty
```

No new i18n key, no new Tailwind class, no new float on money, no `any`/`as any`.

## Still owed (unchanged by this lane)

- Browser legs on a real tenant (T12b gate NB-5 / promotion-owed 2): (a) submit → drop the
  response → reconnect refetch → press Record untouched → exactly ONE `payments` row and
  ONE treasury movement; (b) route swap with the modal open on a sales order / purchase
  order → the host remounts and the POST carries the new document.
- T12b NB-1 residual, NB-4 (`notes` never posted), NB-9 (`mockReset` in `beforeEach`),
  NB-6 (host `useMemo` on `prefill`) — all out of this lane's scope.

---

## Gate round 1 fold — B1 closed (2026-09-04)

Gate: `docs/superpowers/reviews/2026-09-04-request-hygiene-t12c-gate-frontend-conventions.md`
(VERDICT APPROVE-WITH-FIXES; one blocking finding, B1).

**B1 (regression test for the load-bearing `isError` guard) — DONE**, commit `72ce0c338`,
test-only (`git diff` on `RecordPaymentModal.tsx` is EMPTY).

New test in `__tests__/idempotencyKeyLifecycle.test.tsx`:
`keeps later submits working after a successful submit (reset only on error — a bare
mutation.reset() on rotation would drop onSettled and wedge submitLockRef)`.
It resolves the awaited invalidation refetches on a MACROTASK
(`mockLookupResponsesWithLatency(20)` + `settleFor(80)` — the modal's own `open-invoices`
query matches one of the `onSuccess` predicates, so that delay IS the awaited window),
which is the production ordering the file's default microtask mocks close too fast to
reproduce. Then it reopens the still-mounted modal and submits again, asserting TWO POSTs
with different keys.

```
$ pnpm vitest run src/components/organisms/RecordPaymentModal
 Test Files  2 passed (2)      Tests  18 passed (18)          # was 17

$ pnpm vitest run src/components/organisms/RecordPaymentModal src/routes \
    src/features/documents/sales-orders src/features/documents/purchase-orders
 Test Files  13 passed (13)    Tests  110 passed (110)        # was 109

$ # falsification — replace `if (mutation.isError) mutation.reset()` with `mutation.reset()`
 × … keeps later submits working after a successful submit (…)
   → expected "spy" to be called 2 times, but got 1 times
 Tests  1 failed | 17 passed (18)     # ONLY the new test reds
 # component restored from a byte copy; `git diff -- RecordPaymentModal.tsx` => empty

$ pnpm typecheck                                        exit 0
$ npx eslint __tests__/idempotencyKeyLifecycle.test.tsx  0 errors, 0 warnings
$ ps aux | grep '[v]itest'                              empty
```

`act(...)` console noise in this file: 44 lines on the pre-fix file, 46 with the new test —
the same per-test rate as the existing 13 tests, no new class of warning.

**N2 (MINOR, effect deps) — NOT taken.** It is a production-code edit and the gate already
ruled it harmless; leaving it keeps the B1 falsification's "component diff is empty" claim
exact. Carry it with the N1/N3/N4/N5/N7 follow-up brief.

Remaining gate items are non-blocking: N1 (rotated-intent warning), N3, N4, N5, N6, N7,
plus N8 (pre-existing red `pnpm lint` on base `dev`). Browser legs still owed.
