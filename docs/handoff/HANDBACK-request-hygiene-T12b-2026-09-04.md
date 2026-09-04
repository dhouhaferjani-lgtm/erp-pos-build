# HANDBACK — Request Hygiene Phase A, Task 12b

**`RecordPaymentModal` must not wipe an in-progress payment intent when `prefill` changes identity (P1 pre-production, T12 gate follow-up)**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12b`
- Branch: `lane/rh-t12b-modal-prefill-wipe`
- Base: `0187a56d1` (T12 merged)
- Source of the mandate:
  - `docs/superpowers/reviews/2026-09-04-request-hygiene-t12-gate-treasury.md` — r2 **F1**, and the r3 **item 2 ruling** ("Raise the follow-up as **P1 pre-production**, scoped as *RecordPaymentModal must not wipe an intent that has a failed attempt pending*; `useMemo` on the three hosts is the cheap half … and must not be recorded as the full cure")
  - `docs/superpowers/reviews/2026-09-04-request-hygiene-t12-gate-frontend-conventions.md` — r2 **B1′**, r3 **m8** and **m9**
- Scope: **WEB ONLY**, and inside web only `RecordPaymentModal`. No host page, no `apps/api` file, no `useIdempotencyKey`, no other payment surface was touched.
- Gate: **treasury** + **frontend-conventions**
- Commits: `0e150bfb2` (fix + tests), plus the doc-only follow-up carrying this file (a commit cannot contain its own hash).

---

## 1. What landed

| File | Change |
|---|---|
| `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | The form-reset effect and the per-open key-rotation effect are **merged into one open-transition effect** (`:184-209`), guarded by the existing `wasOpenRef`. Body runs only on the closed → open transition; on `isOpen === false` it clears the flag and returns. Deps `[isOpen, prefill, resetIdempotencyKey]` — all three now genuinely used (m9 resolved by construction). No other line of the component changed. |
| `apps/web/src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` | Helper `confirmLineAndRecord` split into `confirmLine` / `pressRecord` (existing call sites behaviour-identical). The r2-F1 regression test's tail rewritten (m8). New `describe` block with three tests. |

Net: 2 files, +164 / −44.

### The defect, restated from the code

`RecordPaymentModal.tsx` at base ran its form-reset body whenever `isOpen && prefill`-identity changed. All three hosts build `prefill` as an inline object literal — `features/documents/invoices/InvoiceDetailPage.tsx:899`, `features/documents/sales-orders/SalesOrderDetailPage.tsx:784`, `features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:713` — so *every* host re-render re-ran it. With `lib/queryClient.ts:9` `refetchOnReconnect: true` and `providers/WebSocketReconnectProvider.tsx:71` invalidating every active query (`refetchType: 'active'`), the host re-renders **precisely when a payment response was lost**. The operator's confirmed lines, `paymentDate`, `notes`, `manualAllocations`, `excessAllocationMethod`, `validationError` and `showSuccess`/`successData` were discarded; the forced re-entry then legitimately rotates the idempotency key (`startNewIntentOnPayloadEdit`, `:160-164`), so a batch the server had already committed was booked a **second** time.

### The fix

One effect, one concept: *opening the modal starts a payment intent* — seed the form **and** mint a fresh key, both exactly once, on the transition. While the modal stays open, a `prefill` change touches **no** entry state.

Per the gate ruling this fixes the **wipe**, not the prop identity. A host `useMemo` was deliberately **not** added: it cures only the unchanged-refetch case and would leave the money scenario (the payment committed, so `outstandingAmount` moves) uncured while reading as a full cure.

---

## 2. Decision — what a mid-entry `prefill.amount` change does (required by the brief)

**The read-only outstanding display follows the server immediately; entered lines are never touched and never clamped.**

`RecordPaymentModal.tsx:268` reads `const balanceDue = prefill.amount` **at render**, not into state — so it was already live and stays live. A `prefill` whose amount moves 100 → 600 mid-entry therefore repaints "balance due" as 600 on the next render while the operator's confirmed 400 line is untouched. Over-allocation stays the job of the existing `remaining` / `excessAmount` / excess-allocation UI and the submit-time validation; nothing is silently rewritten under the operator. Recorded in the component comment at `:179-183` and locked by test (b) below.

`paymentDate` (requirement 2) is initialised inside the same transition-only effect, so it is seeded on open and never re-seeded while open.

---

## 3. Evidence

### 3a. RED first (tests written before the component changed)

```
$ cd apps/web && npx vitest run src/components/organisms/RecordPaymentModal

 × RecordPaymentModal idempotency key lifetime > does NOT rotate the key when the PARENT re-renders with a fresh prefill object while the modal stays open
   → expected [ Array(1) ] to have a length of +0 but got 1
 × RecordPaymentModal prefill identity does not wipe in-progress entry > keeps the operator's confirmed line, notes and date when the parent re-renders with a NEW prefill object of the SAME values
   → Unable to find an element with the text: treasury:unifiedPayment.lineConfirmed
 × RecordPaymentModal prefill identity does not wipe in-progress entry > keeps the in-progress line and shows the NEW outstanding amount when prefill.amount changes mid-entry
   → Unable to find an element with the text: treasury:unifiedPayment.lineConfirmed

 Test Files  1 failed | 1 passed (2)
      Tests  3 failed | 9 passed (12)
```

### 3b. The three new / reshaped assertions

- **(a) same-values re-render** — `keeps the operator's confirmed line, notes and date …`: open → fill + confirm a 400 line → type `notes` → set the date → rerender with a **new** `makePrefill()` object of identical values. Asserts the `lineConfirmed` badge is still rendered, the amount input still holds `400`, `notes` still holds the operator's text, the date still holds `2026-09-01`, and `apiPost` was never called.
- **(a′) key retention, read from the money path** — the r2-F1 test `does NOT rotate the key when the PARENT re-renders …` was reshaped. Because the line now **survives**, the retry needs no re-entry, so the tautology the T12 gate had to work around is gone: it presses Record a second time and asserts `postedIdempotencyKey(1) === postedIdempotencyKey(0)` — the unchanged retry replays server-side rather than booking a second payment. The mint-count belt is kept, relaxed per **m8** from `toHaveLength(1)` (which encoded the wipe) to the now-correct exact value **`toHaveLength(0)`**: post-fix the re-render mints neither a replacement payment-line id nor a key.
- **(b) changed outstanding** — `keeps the in-progress line and shows the NEW outstanding amount …`: same setup, rerender with `{ ...makePrefill(), amount: 600 }`. Asserts the confirmed line and the `400` entry survive, `600` is displayed and `100` is gone.
- **(c) close → reopen still resets** — asserts the confirmed badge is gone, the amount input is empty and `notes` is back to the seeded `Payment for invoice INV-1`. Close and reopen are two separate `act()` blocks on purpose: batching them into one collapses the two commits and never exercises the transition (noted in the test).

### 3c. Falsification (twice)

Mutation 1 — restore the whole component from base:
```
$ git show 0187a56d1:.../RecordPaymentModal.tsx > .../RecordPaymentModal.tsx
      Tests  3 failed | 5 passed (8)      # exactly (a), (a′), (b)
```
Mutation 2 — keep the merged effect, delete **only** `if (wasOpenRef.current) return`:
```
      Tests  3 failed | 5 passed (8)      # exactly (a), (a′), (b)
```
Both restored from a byte copy; `git status --porcelain` empty afterwards. Test (c) stays green under both mutations, as it should — it guards the behaviour that must NOT be lost.

### 3d. Verification suite (the brief's exact scope)

```
$ cd apps/web && npx vitest run src/components/organisms/RecordPaymentModal \
    src/features/documents/invoices src/features/documents/sales-orders \
    src/features/documents/purchase-orders

 Test Files  11 passed (11)
      Tests  107 passed (107)
   Duration  12.05s
```
(`act(...)` warnings: 16 across the lifecycle file vs 10 at base — a flat 2 per test both before and after, i.e. the pre-existing react-query settle pattern scaled by 5 → 8 tests, not a new class.)

```
$ npx tsc --noEmit
TYPECHECK_EXIT=0
```

### 3e. eslint, per file, base `0187a56d1` vs HEAD

Base copies taken with `git show 0187a56d1:<path>` into a **sibling in the same directory** (the design-token rules are path-scoped), linted, deleted; tree verified clean after.

| file | base `0187a56d1` | HEAD | Δ |
|---|---|---|---|
| `components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | E0 W25 | E0 W25 | 0 |
| `components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` | E0 W0 | E0 W0 | 0 |
| `components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` (untouched) | E0 W13 | E0 W13 | 0 |

Not just the totals — the **rule multiset is byte-identical** on the component (25 lines, `diff` empty), so no warning was traded for another.

`react-doctor` on the component, base vs HEAD: **identical** finding multiset (9 classes, all pre-existing: `no-giant-component`, `prefer-useReducer`, `rerender-lazy-state-init`, `no-adjust-state-on-prop-change` ×5, `prefer-module-scope-pure-function`, `js-set-map-lookups`, `exhaustive-deps`, `js-combine-iterations`). The pre-commit hook prints "staged regressions" because five of those `no-adjust-state-on-prop-change` hits now fall on changed lines; nothing new was introduced.

### 3f. Rule compliance

- No `any`, no `as any` — `grep -E '(: any|as any)'` over the added lines: empty.
- No `parseFloat` / `Number(` / `toFixed` in added lines: empty. **The pre-existing B-7 float block (`totalEntered` / `balanceDue` / `remaining`, `:261-270`) was deliberately NOT refactored** — out of scope, explicitly excluded by the brief.
- No new user-facing strings, so no `t()` key and no locale change is owed. No new Tailwind classes, so no design-token question arises.
- Hooks unconditional: the merged effect is a single top-level `useEffect`; the early returns are inside the callback body, not around the hook. Hook count and order unchanged (one `useEffect` removed, none added conditionally).

---

## 4. Deviations from the brief

1. **`prefill` stays in the effect's dependency array** rather than being dropped. The body reads `prefill.document_type` / `prefill.reference` to seed `notes`, so removing it would be an `exhaustive-deps` violation. It is harmless: the transition guard makes the body a no-op on every run that is not an open. The brief's falsification recipe ("restore `prefill` in the reset-effect deps") is therefore not applicable verbatim; the equivalent mutation — deleting the transition guard — was run instead and is recorded in §3c along with a full revert to base.
2. **m9 is resolved by construction, not by deletion.** The brief asked to drop `resetIdempotencyKey` from the form-reset effect deps "if it is indeed unused there". After the merge the effect *does* call it, so the dep is live and correct. The dead-dep smell the gate objected to (a dep implying a rotation that had moved elsewhere) is gone.
3. **The new tests live in `idempotencyKeyLifecycle.test.tsx`, in their own `describe`**, not in a new `prefillIdentity.test.tsx`. They reuse ~140 lines of fixture (`makePrefill`, `installUuidRecorder`, `mockLookupResponses`, `postedIdempotencyKey`, the tenant/i18n/currency mocks) and test (a′) had to be reshaped in place anyway, so a second file would have duplicated the harness and split one invariant across two files.
4. **`confirmLineAndRecord` was refactored** into `confirmLine` + `pressRecord`. Existing call sites are byte-equivalent in behaviour; all five pre-existing tests stay green unchanged.

---

## 5. Not done / still owed

- **Browser evidence.** No throttled-network browser probe was run — this lane changed no network or money code, but the T12 promotion-owed browser legs (§ `FR1-C` of `HANDBACK-request-hygiene-T12-2026-09-04.md`) remain owed and this lane does not discharge any of them. The specific leg worth adding on top: *open payment → submit → drop the response → let the network return (reconnect refetch fires) → press Record again without touching anything* → exactly one payment, one treasury movement.
- **Host `useMemo` on `prefill`** is still not done and, per the r3 item-2 ruling, is **not** the cure. It remains a legitimate small hygiene follow-up (it would stop useless re-renders), never to be recorded as fixing the double-payment path.
- **NB-8 (gate r2, pre-existing)** stands untouched: `notes` is typed by the operator but never sent in the POST body (`RecordPaymentModal.tsx:377-390`). This lane now *preserves* those notes across a re-render — they are still silently discarded on submit. Own lane.
- **Server-side request fingerprint** (Phase B convergence item, treasury gate §4): same key + different body still returns the original payment with HTTP 200 and no warning. The web-side intent scoping remains the only protection.

---

# Fix round 1 — gate r1 BLOCKER-1 (+ NB-1)

- Date: 2026-09-04
- Gate consumed: `docs/superpowers/reviews/2026-09-04-request-hygiene-t12b-gate-treasury.md` (treasury + frontend-conventions, **spec ✅ + quality CHANGES-REQUESTED**, 1 blocker)
- Commit: `5a7557180` (fix + tests + locales), plus the doc-only commit carrying this section.
- Scope unchanged: **WEB ONLY**, inside web only `RecordPaymentModal` + its test + the three `treasury.json` locales. No host page, no `routes/index.tsx`, no `apps/api` file.

## 1. BLOCKER-1 — a `prefill` swap to a DIFFERENT document posted the operator's stale line against it

**Cure (as mandated, inside the modal).** The single open-transition effect now keys on the
**intent identity** as well as the open flag
(`apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:195-204`):

```ts
const lastIntentRef = useRef<string | null>(null)          // :151
…
if (!isOpen) { wasOpenRef.current = false; lastIntentRef.current = null; return }
const intent = `${prefill.partner_id}|${prefill.document_id}`
if (wasOpenRef.current && lastIntentRef.current === intent) return
wasOpenRef.current = true
lastIntentRef.current = intent
```

- **Same document, new `prefill` object** (a reconnect refetch moving only `amount`/`reference`) →
  body is a no-op: lines, notes, date and the idempotency key all survive. The T12b cure is intact.
- **Different `document_id` (or `partner_id`) while open** → a new intent by definition: the form
  re-seeds (`notes` re-derived from the NEW reference) and `resetIdempotencyKey()` rotates the key,
  so document A's committed batch can never be replayed under document B's submit.

`partner_id` is folded into the key alongside `document_id` because the POST body reads **both** at
submit time (`:379-388`); either moving is a different money destination.

**Deviation from the gate's suggested snippet:** the gate proposed `intentDocRef` holding
`prefill.document_id`. The shipped ref holds `` `${partner_id}|${document_id}` `` — strictly wider,
same shape, closes the partner half of the same hole. Named `lastIntentRef` because it is the intent,
not the document.

**Follow-up recorded, NOT done (host files, out of this lane's scope):** wrap the two `orders/:id`
routes — sales-orders `apps/web/src/routes/index.tsx:699-706` and purchase-orders `:948-955` — in
`KeyedByRouteId`, as invoices already are (`:739-750`), so the whole carry-over class is closed
structurally on all three hosts. The modal-side guard above is the money fix; the route wrapper is
the structural one and is still owed.

### RED first

New test `resets the form and rotates the key when prefill switches to a DIFFERENT document while
the modal stays open` (`__tests__/idempotencyKeyLifecycle.test.tsx:471-536`), written before the
component changed:

```
 × RecordPaymentModal prefill identity does not wipe in-progress entry >
   resets the form and rotates the key when prefill switches to a DIFFERENT document while the modal stays open
   → expect(element).not.toBeInTheDocument()   (the doc-A confirmed badge was still rendered)

 Test Files  1 failed | 1 passed (2)
      Tests  1 failed | 12 passed (13)
```

It reproduces the gate's measured scenario end to end: confirm 400 on `doc-1`/`partner-1`, the
response is lost (`mockApiPost.mockRejectedValueOnce`), then re-render with
`{document_id: 'doc-2', partner_id: 'partner-2', reference: 'INV-2', amount: 50}` and assert

- the confirmed badge is gone, the amount input is empty, `notes` re-seeded to `Payment for invoice INV-2`;
- the **Record button is disabled** (`confirmedCount === 0`) — the gate's `RECORD_DISABLED` probe;
- after re-entering 50 and pressing Record, the POST carries `document_id: 'doc-2'`,
  `partner_id: 'partner-2'` and **exactly one line of `50`** — doc-A's 400 line can never reach the
  server — and `postedIdempotencyKey(1) !== postedIdempotencyKey(0)`, i.e. no replay of the batch
  that may already have committed against doc-A.

The inverse half of the pair is the pre-existing test `keeps the in-progress line and shows the NEW
outstanding amount when prefill.amount changes mid-entry` (`:433-469`), which now also wraps the
re-render in an `installUuidRecorder()` window and asserts `toHaveLength(0)` — proof the fix did not
over-reach into "any prefill change resets".

### Falsification (the mandated one, restored)

Removing only the document-id half of the guard —
`if (wasOpenRef.current && lastIntentRef.current === intent)` → `if (wasOpenRef.current)`:

```
 × … resets the form and rotates the key when prefill switches to a DIFFERENT document …
 Test Files  1 failed | 1 passed (2)
      Tests  1 failed | 12 passed (13)
```

Exactly the new test, and only it. Restored from a byte copy; `git status --porcelain` clean.

## 2. NB-1 — "may already have been recorded" banner (DONE, 9 lines + 3 locale keys)

`RecordPaymentModal.tsx:908-918`: when `mutation.isError && confirmedCount > 0 && balanceDue === 0`
an inline info banner (`colorTokens.intent.info`) renders
`t('treasury:unifiedPayment.possiblyRecorded')` above the raw error banner —
*"This payment may already have been recorded — the outstanding is now zero. Press Record without
changing anything to confirm; editing the amount would create a second payment."*
Keys added to `src/locales/{en,fr,ar}/treasury.json`.

**Deviation from the NB-1 wording:** the gate suggested gating on `hadFailedAttemptRef.current`.
That is a ref and reading it during render is both non-reactive (the banner would appear only on an
unrelated re-render) and a `react-hooks/immutability` smell. `mutation.isError` is the reactive
state carrying the same fact — true from the failed attempt until the next `mutate()` — so the
banner is driven off it. The gate's optional second half (disabling the amount inputs) was **not**
done: it exceeds "a banner", and disabling inputs after an error can trap an operator whose failure
was genuine (validation, offline) with no way to correct the entry. Recorded as a deliberate skip.

Covered by `warns that the payment may already have been recorded when the outstanding drops to 0
after a failed attempt` (`:538-565`), red first
(`→ Unable to find an element with the text: treasury:unifiedPayment.possiblyRecorded`), which also
asserts the banner is **absent** before the refetch, so it cannot degrade into an always-on warning.

## 3. Verification (fix round 1)

```
$ cd apps/web && npx vitest run src/components/organisms/RecordPaymentModal \
    src/features/documents/invoices src/features/documents/sales-orders \
    src/features/documents/purchase-orders
 Test Files  11 passed (11)
      Tests  109 passed (109)          # 107 at gate r1 + 2 new
   Duration  5.33s

$ npx tsc --noEmit ; echo TYPECHECK_EXIT=$?
TYPECHECK_EXIT=0

$ pnpm audit:i18n:local | grep -i treasury      => (empty, treasury clean)
   The audit's non-zero exit is the PRE-EXISTING `ar|uom|*` / `ar|import|*` baseline —
   byte-identical output with the three treasury.json files restored to base, so this
   lane neither adds nor clears an entry there.

$ # eslint, per file, base 0187a56d1 (git show > sibling in the same directory), removed after
  RecordPaymentModal.tsx                    BASE E0 W25  |  HEAD E0 W25   rule multiset identical
  __tests__/idempotencyKeyLifecycle.test.tsx BASE E0 W0  |  HEAD E0 W0
  (multiset: array-type ×2, no-misused-promises ×1, no-unnecessary-template-expression ×11,
   restrict-template-expressions ×1, precision/no-parsefloat-on-money ×6,
   react-hooks/exhaustive-deps ×3, react-hooks/immutability ×1 — both sides)

$ npx react-doctor <file>, base vs HEAD  =>  identical profile (score 87, w=17, f=1)
   The pre-commit "staged regressions" notice is the same pre-existing
   `no-adjust-state-on-prop-change` class flagged in §3e above, now on changed lines.

$ ps aux | grep -c '[v]itest'   => 0
$ git status --porcelain        => clean
```

## 4. Still owed after this round

- **`KeyedByRouteId` on the two `orders/:id` routes** (`routes/index.tsx:699-706`, `:948-955`) —
  host-file follow-up, recorded above.
- **Browser leg (gate NB-5)** undischarged: open payment → submit → drop the response → let the
  reconnect refetch fire → press Record untouched → exactly ONE `payments` row and ONE treasury
  movement, on a real tenant. Worth adding a second leg now: swap the route param to another order
  with the modal open and confirm the form re-seeds.
- Unchanged from the first handback: host `useMemo` on `prefill` (hygiene, never the cure), NB-4
  (`notes` never sent in the POST body, pre-existing), and the Phase-B server-side request
  fingerprint.
