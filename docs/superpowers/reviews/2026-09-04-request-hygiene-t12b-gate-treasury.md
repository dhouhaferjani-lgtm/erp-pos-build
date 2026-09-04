# Gate — Request Hygiene T12b (RecordPaymentModal: reset only on the open transition)

- Date: 2026-09-04
- Gate: **treasury + frontend-conventions (single gate)**, adversarial, code-grounded
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12b`
- Branch: `lane/rh-t12b-modal-prefill-wipe` · base `0187a56d1` · commits `0e150bfb2` (fix + tests), `73ec37f44` (handback)
- Diff reviewed: `git diff 0187a56d1..73ec37f44` — 3 files, +306 / −44 (2 code/test, 1 doc)
- Predecessor: `docs/superpowers/reviews/2026-09-04-request-hygiene-t12-gate-treasury.md` (r2 F1, r3 item-2 ruling)

---

## VERDICT

**spec ✅ + quality CHANGES-REQUESTED** — 1 blocking finding.

The mandated cure landed and is real: the form-reset and the key-rotation are now ONE
open-transition effect (`apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:184-209`)
guarded by `wasOpenRef`, it is the ONLY `useEffect` in the component (verified: `grep -n useEffect`
returns exactly line 184), and deleting the single guard line turns exactly the three intended
tests red. The r2-F1 tautology is gone — the retry now reads the money path directly
(`postedIdempotencyKey(1) === postedIdempotencyKey(0)`).

But narrowing the reset to the OPEN flag alone removed an accidental protection that was real
money: on the two hosts that are **not** `KeyedByRouteId`-wrapped, a document swap while the modal
is open now carries the operator's confirmed line onto a **different document and a different
partner**, and submits it. Measured, not argued (BLOCKER-1 below). The remedy is two lines inside
the same effect and does not weaken the T12b cure.

---

## BLOCKING

### BLOCKER-1 [Critical] — a `prefill` swap to a DIFFERENT document now posts the operator's stale line against that document

- `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:184-209` — the reset now
  keys on the `isOpen` transition ONLY. `prefill.document_id` / `prefill.partner_id` changing while
  the modal stays open is a full no-op for entry state.
- `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:379-388` — the POST body
  reads `prefill.partner_id` and `prefill.document_id` **at submit time**, i.e. the NEW document.
- `apps/web/src/routes/index.tsx:739-750` — the invoice host IS wrapped in `KeyedByRouteId`
  (`src/routes/index.tsx:329-333`, `<React.Fragment key={id}>`), so a param change remounts it and
  the carry-over is structurally impossible there. **`sales-orders` (`src/routes/index.tsx:699-706`)
  and `purchase-orders` (`src/routes/index.tsx:948-955`) are NOT wrapped** — same component
  instance, `showPaymentModal` state survives, `prefill` swaps.
- The team already named this defect class and chose `KeyedByRouteId` as its structural cure —
  `apps/web/src/features/documents/invoices/components/CancelInvoiceModal.tsx:224` ("Cross-INVOICE
  carry-over is closed structurally instead, by `KeyedByRouteId`"). The two order hosts never got it.

**Why it matters (wrong money).** The operator confirms a line for order A and the route param
changes to order B (browser Back / Forward, macOS two-finger swipe-back — all work with an overlay
open). Post-fix the confirmed line survives, the Record button stays enabled, and pressing it books
A's amount against B's document and B's partner. The server accepts it: partner and document swap
together so `ALLOCATION_PARTNER_MISMATCH` never fires, and the multi-line path has no
over-allocation refusal (see the over-payment answer below) — the surplus lands as a customer
advance on the wrong partner. Recovery is a reversal plus an advance clean-up.

**Falsifying scenario — measured, both sides** (scratch probe, deleted afterwards; tree verified
clean). Confirm a 1000 line on `{document_id: 'doc-A', partner_id: 'partner-A', amount: 1000}`, then
re-render with `{document_id: 'doc-B', partner_id: 'partner-B', amount: 50}`, then press Record:

```
=== HEAD (0e150bfb2) ===
RECORD_DISABLED=false
POSTED={"document_id":"doc-B","partner_id":"partner-B",
        "payments":[{"payment_method_id":"method-1","repository_id":"repo-1","amount":"1000"}]}

=== BASE (0187a56d1) ===
RECORD_DISABLED=true
POSTED=none (submit blocked)
```

At base the wipe cleared the line, `confirmedCount === 0`, and the Record button is disabled
(`RecordPaymentModal.tsx:916`) — the operator could not submit blind. This lane turns that into a
live submit.

**Suggested fix (in scope, inside the modal, does NOT reintroduce the T12 bug).** Reset on a change
of the *intent identity*, not just of the open flag — a reconnect refetch of the SAME document
changes only `prefill.amount`, so the T12b cure is untouched, while a genuine document swap is by
definition a new intent and must re-seed + rotate:

```ts
const intentDocRef = useRef<string | null>(null)
useEffect(() => {
  if (!isOpen) { wasOpenRef.current = false; intentDocRef.current = null; return }
  if (wasOpenRef.current && intentDocRef.current === prefill.document_id) return
  wasOpenRef.current = true
  intentDocRef.current = prefill.document_id
  … existing body …
}, [isOpen, prefill, resetIdempotencyKey])
```

Ship it with a test that is the inverse of the two new ones: **same** `document_id` → line kept;
**different** `document_id` → line cleared and the key rotated. Separately (follow-up lane, host
files, not this one): wrap `orders/:id` for sales and purchases in `KeyedByRouteId` so the class is
closed structurally on all three hosts as it already is on invoices.

---

## Item-2 money walk-through (required by the brief)

### Scenario 1 — outstanding 1000, operator enters 1000, response lost, reconnect refetch, Record untouched

| step | code | what happens |
|---|---|---|
| submit | `RecordPaymentModal.tsx:369-389` | POST `/payments` with `idempotency_key = K`, one line of 1000, `excess_allocation_method: undefined` (excess is 0 at that moment) |
| server commits | `PaymentController.php:1646-1650` | one `Payment` row keyed `K:multi:0000`; allocation 1000 to the primary doc; balance_due → 0 |
| response lost | `RecordPaymentModal.tsx:414-420` | `onError` → `hadFailedAttemptRef = true`, **key NOT rotated** (correct) |
| reconnect refetch | host re-render, new `prefill` object, `amount: 0` | **T12b:** entry state untouched. `balanceDue = prefill.amount` is read at render (`RecordPaymentModal.tsx:268`), never into state, so the outstanding repaints to 0 immediately; the confirmed 1000 line is neither cleared nor clamped |
| display now | `:261-270`, `:748-751` | balance due 0 · total entered 1000 · remaining −1000 → the **excess panel appears**, "keep as advance" preselected |
| Record pressed unchanged | `:379-388` | POST with the **same key K**; body now also carries `excess_allocation_method: 'advance'` (excess became > 0) |
| server | `PaymentController.php:1429-1435` | the idempotency short-circuit runs **before validation and before the transaction**, so the changed body is never inspected → `formatMultiPaymentReplay` returns the ORIGINAL batch, HTTP 200. **Exactly one payment, one treasury movement.** |
| success panel | `:473-537`; replay built at `PaymentController.php:241-255` | `payments.length = 1`; `document` is re-read LIVE (`balance_due: '0.000'`, `status: 'paid'`) → truthful; `excess_handling.excess_amount` is rebuilt from the durable `PaymentAllocation` rows = `0.000`, so the excess block is not rendered (`:507`) |
| after replay | `:390-392` | `resetIdempotencyKey()` — the intent is closed; the success panel offers only Close (`:534`), so no third submit is reachable |

**Is the operator misled in a way that costs money? No — on this path.** Every figure on the success
panel comes from live server state and matches what was committed. The one cosmetic divergence is
the transient pre-submit panel that says "Excess payment 1000 · keep as advance" for an excess that
the replay never creates; it disappears the moment Record is pressed. No money moves twice.

**The residual is one step to the left, and it is NOT cured by this lane** (see NB-1): nothing in
that pre-submit state tells the operator that the first attempt may already have committed. The
generic error banner (`:895-902`) prints `mutation.error.message` verbatim. An operator who reads
"balance due 0 / excess 1000" as "my payment did not go through, the figures are wrong" and *edits*
the amount instead of retrying unchanged has left the protected path — which is scenario 2.

### Scenario 2 — same failure, operator edits 1000 → 600 and presses Record

| step | code | what happens |
|---|---|---|
| edit | `RecordPaymentModal.tsx:315-321` → `:160-164` | `hadFailedAttemptRef` is true → **the key rotates** (a new intent, by design) |
| submit | `:379-388` | POST with a NEW key → no replay hit at `PaymentController.php:1429-1435` → full processing |
| balance check | `PaymentController.php:1528-1543` | `assertAllocatable()` refuses only WITHDRAWN documents (`DocumentAllocationStateGuard.php:40-57`); `classifyReceivableSide()` refuses only non-receivable-side (`DocumentAllocationClassifier.php:152-168`). **A fully-paid, zero-balance document is refused by neither.** |
| excess math | `PaymentController.php:1533-1543` | `excessAmount = 600 − 0 = 600`; the only 422 in this area is `EXCESS_ALLOCATION_EXCEEDS_AMOUNT` (`:1560-1571`), which fires only when *manual* allocations exceed the excess |
| write | `:1607-1690` | `primaryAllocationAmount = 0` → no `PaymentAllocation` row; `PaymentType::Advance`; a SECOND `Payment` of 600, cash IN to the repository, booked as a customer advance (419) |

**Answer: no, validation does not refuse the over-allocation — the backend accepts it and books it
as a customer advance.** The FE's `remaining` / `excessAmount` panel is a warning, not a gate. So the
double payment is prevented *only* by the operator retrying literally unchanged, which is exactly
what T12b makes possible (and what the wipe previously made impossible). The cure is sound; the
un-warned edit path stays a documented residual (NB-1, and the Phase-B server-side request
fingerprint already recorded in the T12 gate §4).

---

## Verified (non-blocking, all confirmed at file:line)

1. **One open-transition effect, no second reset path.**
   `RecordPaymentModal.tsx:184-209`; `grep -n "useEffect"` on the file returns exactly `1` (import)
   and `184` — there is no other effect that can touch entry state while open. `isOpen === false`
   clears the flag and returns (`:185-188`). `prefill` stays in deps (`:209`) and must:
   the body reads `prefill.document_type` / `prefill.reference` at `:193`. The guard makes every
   non-transition run a no-op.
2. **StrictMode mints one key.** `apps/web/src/main.tsx:20` wraps the app in `StrictMode`.
   A ref-guarded effect isomorphic to `:184-209` under StrictMode: `EFFECT_RUNS=2 GUARDED=1` —
   the double invoke happens, the guarded body runs once (refs survive the simulated remount; there
   is no cleanup that could clear `wasOpenRef`). Measured on the real component:
   `STRICT_MINTS=4` vs `PLAIN_MINTS=3` — the +1 is the double-invoked `useState` initializer in
   `useIdempotencyKey` (`apps/web/src/hooks/useIdempotencyKey.ts:15`), NOT a second key rotation;
   the effect body's 2 mints (line id + key) appear exactly once. One line rendered in both
   (`STRICT_LINES=1`). `reset` is `useCallback([])`-stable (`useIdempotencyKey.ts:16-18`), so the
   dep cannot churn.
3. **Money is read, never latched.** `const balanceDue = prefill.amount` at `RecordPaymentModal.tsx:268`
   — a render-time read, no state, no clamp of entered lines anywhere (`:261-270`). Over-allocation
   is surfaced by `remaining`/`excessAmount` (`:269-270`, `:748`) and enforced — insofar as it is
   enforced at all — server-side (see scenario 2).
4. **No new float on money.** The added lines contain no `parseFloat` / `Number(` / `toFixed` /
   `: any` / `as any` (grep over `^\+` lines of the diff: empty). The pre-existing B-7 float block
   (`:261-270`) is byte-untouched by the diff — the only diff line mentioning `balanceDue` is a
   comment at `:180`.
5. **Tests assert data meaning and are falsifiable.**
   `__tests__/idempotencyKeyLifecycle.test.tsx:395-421` (a: same-values re-render → confirmed badge,
   amount `400`, notes, date all survive, `apiPost` never called), `:423-448` (b: `amount` 100→600 →
   line survives, `600` shown, `100` gone), `:450-482` (c: close→reopen resets, two separate `act()`
   blocks — correct, batching would collapse the transition), and the reshaped r2-F1 test
   `:323-341` (`toHaveLength(0)` + `postedIdempotencyKey(1) === postedIdempotencyKey(0)`). The m8
   relaxation from `toHaveLength(1)` to `toHaveLength(0)` is sound: the `1` encoded the wipe
   (a replacement payment-line uuid), which no longer happens. Fixture `makePrefill()` builds a
   FRESH object per call (`:89-98`) — it reproduces the production condition rather than stabilising
   past it.
6. **Falsification re-run (one mutation, restored).** Deleting only `if (wasOpenRef.current) return`
   → `Tests 3 failed | 9 passed (12)`, exactly (a), (a′), (b); (c) stays green, as it must.
   Restored from a byte copy; `git status --porcelain` empty.
7. **Conventions.** Hooks unconditional (the single `useEffect` is top-level; the early returns are
   inside the callback). `t()` — no new user-facing string. Tokens — no new Tailwind class. Per-file
   eslint vs `0187a56d1`: **0 errors both sides, 25 warnings both sides, rule multiset identical**
   (`@typescript-eslint/array-type ×2`, `no-misused-promises ×1`, `no-unnecessary-template-expression ×11`,
   `restrict-template-expressions ×1`, `precision/no-parsefloat-on-money ×6`,
   `react-hooks/exhaustive-deps ×3`, `react-hooks/immutability ×1`) — no warning traded for another.
   `tsc --noEmit` exit 0.
8. **Cross-cutting (rule 22).** *Second-of-everything*: no table, no migration, no unique key, no
   catalogue entity — N/A; tenant/company scoping on this surface stays covered by the untouched
   `__tests__/tenantScope.test.tsx`. *One surface per concept*: no new noun, no new FE type, no
   second write path — `RecordPaymentModal` remains the single document-side payment-entry surface
   (the other treasury writers — `MultiPaymentController::createSplitPayment`,
   `PaymentAllocationService` — are pre-existing and untouched). *Benchmark-first*: N/A, this is a
   defect fix mandated by a gate finding, not a new user-facing flow.

---

## Non-blocking findings

- **NB-1 [Important, new-in-lane, not a blocker]** — after a lost response the modal shows
  "balance due 0 / excess N / keep as advance" with no hint that the earlier attempt may already
  have committed; the error banner prints the raw error (`RecordPaymentModal.tsx:895-902`). Since
  the ONLY protection against a double payment is "retry unchanged" (editing rotates the key,
  `:160-164`), this state actively invites the one action that defeats it. Cheap cure: when
  `hadFailedAttemptRef.current` is true AND `prefill.amount` has dropped below `totalEntered`, show
  a warning ("this payment may already have been recorded — retry unchanged or close and refresh")
  and consider disabling the amount inputs until the operator explicitly starts a new intent. Own
  lane; belongs with the Phase-B server-side request fingerprint.
- **NB-2 [Minor, new-in-lane]** — a close+reopen collapsed into ONE React commit would not re-seed
  (`isOpen` never observed as `false` between two effect runs). No such caller exists today
  (`handleFinalClose` at `:449-454` closes only). The `document_id`-keyed guard proposed in
  BLOCKER-1 does not fix this either; noting it so the next reader does not rediscover it.
- **NB-3 [Minor, pre-existing]** — the replay echoes the RETRY's `excess_allocation_method`
  (`PaymentController.php:239`, `:252`) rather than the original's. Harmless today because
  `excess_amount` is rebuilt from allocation rows and the FE gates the block on
  `excess_amount > 0` (`RecordPaymentModal.tsx:507`).
- **NB-4 (= gate r2 NB-8) [Important, PRE-EXISTING, classified]** — `notes` is typed by the operator
  (`:878-885`) and never appears in the POST body (`:379-388`). This lane now *preserves* those
  notes across a re-render, so it makes the loss more visible but does not cause it. **Pre-existing;
  not a merge blocker for T12b; own lane.**
- **NB-5 [process]** — the **browser leg is owed and undischarged**: open payment → submit → drop
  the response → let the reconnect refetch fire → press Record untouched → assert exactly ONE
  `payments` row and ONE treasury movement, on a real tenant. `apps/web` unit tests cannot observe
  the treasury movement. Promotion-owed, stacked on the T12 legs (`FR1-C` of
  `docs/handoff/HANDBACK-request-hygiene-T12-2026-09-04.md`).
- **NB-6 [Minor]** — host `useMemo` on `prefill` remains undone and, per the r3 item-2 ruling, is
  correctly NOT recorded as a cure. Still worth doing as re-render hygiene.

---

## Commands and outputs

```
$ cd .worktrees/rh-t12b && git diff --stat 0187a56d1..73ec37f44
 .../RecordPaymentModal/RecordPaymentModal.tsx      |  73 ++++++-----
 .../__tests__/idempotencyKeyLifecycle.test.tsx     | 135 ++++++++++++++++++--
 .../HANDBACK-request-hygiene-T12b-2026-09-04.md    | 142 +++++++++++++++++++++
 3 files changed, 306 insertions(+), 44 deletions(-)

$ grep -n "useEffect" apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx
1:import { useEffect, useRef, useState, useMemo, useCallback } from 'react'
184:  useEffect(() => {

$ cd apps/web && npx vitest run src/components/organisms/RecordPaymentModal \
    src/features/documents/invoices src/features/documents/sales-orders \
    src/features/documents/purchase-orders
 Test Files  11 passed (11)
      Tests  107 passed (107)
   Duration  5.07s

$ # falsification: delete only `if (wasOpenRef.current) return`
$ npx vitest run src/components/organisms/RecordPaymentModal
   × … does NOT rotate the key when the PARENT re-renders with a fresh prefill object …
   × … keeps the operator's confirmed line, notes and date …
   × … keeps the in-progress line and shows the NEW outstanding amount …
   ✓ … DOES reset the form on the next open transition (close -> reopen)
 Test Files  1 failed | 1 passed (2)
      Tests  3 failed | 9 passed (12)
$ # restored from byte copy; git status --porcelain => empty

$ npx tsc --noEmit ; echo TYPECHECK_EXIT=$?
TYPECHECK_EXIT=0

$ # eslint, per file, base 0187a56d1 (git show > sibling in same dir) vs HEAD
BASE: errors 0 warnings 25 | HEAD: errors 0 warnings 25 | rule multiset identical
$ # base copies removed; git status --porcelain => empty

$ # StrictMode probes (scratch, removed; tree clean afterwards)
EFFECT_RUNS=2 GUARDED=1            # ref-guard runs its body once under double invoke
STRICT_MINTS=4 / PLAIN_MINTS=3     # +1 = useState initializer, not a second key
STRICT_LINES=1 / PLAIN_LINES=1

$ # BLOCKER-1 probe (scratch, removed; tree clean afterwards)
HEAD: RECORD_DISABLED=false  POSTED={"document_id":"doc-B","partner_id":"partner-B",…amount:"1000"}
BASE: RECORD_DISABLED=true   POSTED=none (submit blocked)

$ ps aux | grep -c '[v]itest'
0
```

### merge-tree

```
$ git merge-tree --write-tree dev lane/rh-t12b-modal-prefill-wipe
459ab5beb9abd0cd2e04ec9a2237e0141b73c8fa    (exit 0, no conflict section)
$ git merge-base --is-ancestor 0187a56d1 dev  => yes   (dev @ f9acdad0e)
$ git log --oneline 0187a56d1..dev -- .../RecordPaymentModal .../useIdempotencyKey.ts  => (empty)
```

**Clean — no conflicts, no drift on the touched paths since base.**

---

## What to fix before merge

Re-key the open-transition guard on the intent identity (`prefill.document_id`), with a
same-doc-keeps / different-doc-resets test — otherwise a route-param swap on the two
non-`KeyedByRouteId` order hosts posts the operator's stale line against the wrong document and
partner.
