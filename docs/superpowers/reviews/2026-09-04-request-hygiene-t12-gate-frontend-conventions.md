# Gate — Request Hygiene Phase A, Task 12 (frontend-conventions half)

- **Reviewer:** frontend-conventions-reviewer (adversarial merge gate)
- **Date:** 2026-09-04
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12`
- **Branch:** `lane/rh-t12-payment-idempotency` · base `a97631051` · reviewed range `a97631051..9c28de0f9`
- **Scope of this gate:** component conventions, hooks discipline, tests, lint delta. Money correctness and the API idempotency contract belong to the treasury reviewer; where the two overlap it is called out explicitly below.
- **Handback under review:** `docs/handoff/HANDBACK-request-hygiene-T12-2026-09-04.md`
- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` rev 11 → `## Task 12`

## VERDICT: REJECT

Two defects in the **key lifecycle** — the hook is scoped to the component mount while the intent it identifies is scoped to a modal-open / form-edit cycle. On `RecordPaymentModal` the fix is one line inside an effect that already exists. Everything else in the lane is clean, and the three red-first proofs, both typecheck flip proofs and the `"0.000"` falsification all reproduce independently.

---

## BLOCKING

### B1 (BLOCKER) — RecordPaymentModal's idempotency key is mount-scoped, so a reopened modal sends a *new* payment under the *old* key

- `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:147` — `useIdempotencyKey()` at component scope.
- `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:150-162` — the `isOpen` effect resets **every other** piece of intent state (`paymentDate`, `notes`, `paymentLines`, `excessAllocationMethod`, `manualAllocations`, `validationError`, `showSuccess`, `successData`) and does **not** rotate the key.
- Mount proof — the modal is mounted for the whole life of the host page, only `isOpen` toggles, so the hook's `useState` survives every open/close cycle:
  - `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:894-896`
  - `apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:780-781`
  - `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:709-710`
- Server behaviour that turns this into money loss (unconditional replay, **no payload comparison, no re-validation**):
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:351-366`
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:159-167`

**Falsifying scenario (4 operator actions, no exotic state):**
1. Open Record Payment on INV-1, confirm one line of `100.000`, click Record. The server persists the payment; the response is lost (wifi drop / 504 / timeout).
2. `onError` (`RecordPaymentModal.tsx:361`) surfaces the error; the key `K` is deliberately retained (correct per plan) and the lock is released by the per-call `onSettled` (`:369-371`).
3. The operator closes the modal and reopens it. The effect at `:150` wipes the lines and builds a **brand-new intent**; `K` is untouched.
4. The operator now records `250.000`. The POST carries `idempotency_key: K` (`:328`). `PaymentController::store` short-circuits at `:359-366`, returns the ORIGINAL `100.000` payment with HTTP 200, `onSuccess` (`:338`) runs, `resetIdempotencyKey()` fires, and the success panel renders the 100.000 figures.

Net effect: the 250.000 payment is never recorded and the operator is shown a success. Before this diff the same sequence produced a *double* payment (visible, correctable); after it, it produces a *silent drop presented as a success* (invisible). That is a regression in failure mode, not a fix.

**Fix directive:** add `resetIdempotencyKey()` to the existing `isOpen` reset effect at `RecordPaymentModal.tsx:150-162` so each modal opening is a distinct logical intent (equivalent alternative: render the modal conditionally in all three hosts so it remounts). Add a test that opens, fails, closes, reopens and asserts the second POST carries a **different** key.

> Note: the promotion-owed item 3 in the handback ("opening payments from InvoiceDetailPage / SalesOrderDetailPage / PurchaseOrderDetailPage") is exactly the probe that would have surfaced this. The lane is honest that it was not run.

---

## MAJOR

### M1 — PaymentForm reuses the key across an edited payload after a failed submit

- `apps/web/src/features/treasury/PaymentForm.tsx:665` (hook), `:733` (`onError` — correctly does **not** reset), `:750-756` (`onSubmit`), `:711` (`reset()` in `onSuccess`).

**Falsifying scenario:** operator submits `100.000`; the server creates the payment but the client surfaces an error (lost response). The page stays mounted with its values and the error banner. The operator changes the amount to `250.000` and presses Save. The POST reuses key `K` (`:684`) → server replay (`PaymentController.php:359-366`) → `onSuccess` at `:711` navigates away announcing success; the 250.000 payment does not exist.

Narrower trigger than B1 (requires the payment to have been created *and* an error surfaced *and* the payload edited), which is why it is MAJOR and not BLOCKER — but the consequence is identical.

**Fix directive:** bind the key to the submit intent, not the mount — rotate on the first field mutation after a failed attempt (RHF dirty-since-submit), or derive the key from the submitted payload.

### M2 — SplitPaymentForm has the same defect and no error surface at all

- `apps/web/src/features/treasury/SplitPaymentForm.tsx:59` (hook), `:105-108` (mutation has `onSuccess` only — **no `onError`**, pre-existing).
- After a failed split payment nothing is rendered to the operator (`validationError` at `:301-304` only ever carries client-side validation), the key is retained, and the operator can freely change the amounts and resubmit under the same key. `/documents/{id}/split-payment` replays by key too (`apps/api/.../MultiPaymentController.php:52-93`, Task 19), so the same silent-replay outcome applies.
- Lower practical severity **only** because there is no active in-repository `<SplitPaymentModal>` caller today (verified — see "Caller inventory" below).

**Fix directive:** same intent-scoping fix, plus an `onError` that sets `validationError` so a failed split is visible.

### M3 — Lint ratchet breached, and the handback discloses fewer than half of the new warnings

Measured per-file, before (`a97631051`) vs after, same eslint invocation:

| file | before | after | Δ |
|---|---|---|---|
| `src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | 25 | 25 | 0 |
| `src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` | 13 | 14 | **+1** |
| `src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx` | 1 | 1 | 0 |
| `src/features/treasury/PaymentForm.test.tsx` | 4 | 5 | **+1** |
| `src/features/treasury/PaymentForm.tsx` | 13 | 13 | 0 |
| `src/features/treasury/SplitPaymentForm.test.tsx` | 1 | 9 | **+8** |
| `src/features/treasury/SplitPaymentForm.tsx` | 3 | 1 | **−2** |
| `src/features/treasury/__tests__/TreasuryTenantScope.test.tsx` | 0 | 0 | 0 |
| `src/features/treasury/treasury.test.tsx` | 15 | 15 | 0 |
| **total** | **75** | **83** | **+8 net (10 new, 2 removed)** |

- **Removed (genuine, not evasion):** 2 × `precision/no-parsefloat-on-money` in `SplitPaymentForm.tsx` — the `parseFloat` accumulator is really gone (`rg 'parseFloat|Math\.abs' src/features/treasury/SplitPaymentForm.tsx` → no matches; the bcmath path is real, proven by the falsification below). No alias table, no suppression comment, no renamed-equivalent literal anywhere in the diff.
- **New (10):**
  - `SplitPaymentForm.test.tsx:104,110` — `no-non-null-assertion` ×2 + `no-unnecessary-type-assertion` ×2 (plan-prescribed `methodInputs[index]!`)
  - `SplitPaymentForm.test.tsx:170,228,248` — `no-unsafe-assignment` ×3 (plan-prescribed `expect.stringMatching(...)` matchers)
  - `SplitPaymentForm.test.tsx:198` — `no-deprecated` (the `SplitPaymentModalProps` reference in the compile-time contract test)
  - `PaymentForm.test.tsx:474` — `no-unsafe-assignment`
  - `RecordPaymentModal/__tests__/tenantScope.test.tsx:264` — `no-unsafe-assignment`
- The handback states "the only genuinely NEW warnings this lane adds are two `no-non-null-assertion` (plus their paired `no-unnecessary-type-assertion`)" — that is 4 of 10. The other 6 are undisclosed. All are warnings in test files and all trace to plan-verbatim code, so this is an accuracy failure, not concealment.

**Fix directive:** replace the two `!` assertions with a narrowing guard and type the three `expect.stringMatching` matchers, or correct the handback's delta table and get the +10 explicitly accepted as ratchet debt.

---

## NON-BLOCKING (MINOR)

### m1 — `pnpm --filter @autoerp/web lint` is RED on this branch (inherited, not this lane)
Both audits exit 1 on files the diff does not touch:
- `audit:keys` → `src/features/uom/hooks/useUnits.ts:53` (1 new Gate-C violation).
- `audit:design-system` → 15 new violations, all in `src/features/import/pages/ImportWizardPage.tsx` (13) and `src/features/uom/components/UnmappedUnitTextsPanel.tsx:138` (1) — plus 11 **stale** baseline entries needing a shrink.

No line names a Task-12 file, and `apps/web/tools/audit-design-system-baseline.json` is **not** in the diff — so no `--write-baseline` absorption of this lane's own violations. The lane cannot claim a green full `pnpm lint`; the debt is base debt.

### m2 — the `"0.000"` falsifier's strength is coupled to the relaxed mock (D2 follow-on)
`SplitPaymentForm.test.tsx:254` asserts `/^0\.000$/` on the rendered node, but `useCurrency` is mocked to `String(value)` (`:35-40`). Under the **real** formatter (`src/lib/format.ts:118-137` → `formatDecimalAmount` at `:81-96`) the float residue `-5.55e-17` would round to `0,000 TND` and the assertion would pass under the float path. The test therefore asserts the internal bcmath string, not user-visible output — which is the right call for a falsifier, but a future "make the mock faithful" change silently defangs the only regression guard for this bug. Add that coupling to the comment at `:249-253`.

### m3 — "idempotency key" is not in the glossary and has 4 hand-rolled producers beside the shared hook
`rg -ni 'idempotenc' docs/glossary.md` → no match. Pre-existing second surfaces (all predate this lane, all outside its scope): `src/features/income/pages/IncomeFormPage.tsx:26`, `src/features/expenses/pages/ExpenseFormPage.tsx:29`, `src/features/purchases/StandaloneReceiptPage.tsx:68,99`, `src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.tsx:417`. One-surface-per-concept: declare the noun once and schedule convergence onto `useIdempotencyKey` in Phase B. Not a T12 finding — T12 uses the shared hook correctly.

### m4 — handback's typecheck flip-1 transcript is incomplete
The flip emits **19** diagnostics; the handback quotes 10 (the seven `treasury.test.tsx` TS2322s and the TS2345s at `SplitPaymentForm.tsx:152,186` are omitted). Same conclusion, incomplete transcript.

### m5 — `react-doctor` complexity warnings on `RecordPaymentModal.tsx:127` / `PaymentForm.tsx:266`
Correctly reported by the lane as pre-existing and out of scope (rule 4). Agreed — no action in this lane.

### m6 — `crypto.randomUUID` secure-context caveat
Carried over from T11 and now on the payment path. Downgraded: `SplitPaymentForm.tsx:122` (`addPaymentLine`) already depended on `crypto.randomUUID` before this diff, and 17 non-test call sites exist across the app, so T12 does not change the deployment constraint. Still worth confirming staging/production origins are HTTPS before promotion.

---

## D1 / D2 rulings

**D1 — `fireEvent.change` instead of `userEvent.type` in `fillTwoSplits` (`SplitPaymentForm.test.tsx:94-112`): ACCEPTABLE.**
I re-ran the probe myself against the real `MoneyInput` in a throwaway spec (created under `src/`, run, deleted; tracked tree verified clean afterwards):

```
TYPE_EMITTED   ["0","0.1"]     TYPE_DOMVALUE   "0.1"
CHANGE_EMITTED ["0.100"]       CHANGE_DOMVALUE "0.100"
```

The handback's numbers reproduce exactly. `userEvent.type` on a jsdom `input[type=number]` cannot express `"0.100"`, so the plan's helper is unwritable; had it shipped as written it would have asserted `"0.100"` against a received `"0.1"` and failed permanently for a harness reason. `MoneyInput.handleChange` (`src/components/atoms/MoneyInput/MoneyInput.tsx:83-90`) forwards `e.target.value` verbatim with no `parseFloat`, and a real browser number input preserves `"0.100"` in `.value`, so `fireEvent.change` delivers the same string the operator's keyboard would.

**No assertion of the three-decimal contract gets weaker:** the exact split amounts `"0.100"`/`"0.200"`, the UUID matcher, the `/^0\.000$/` remaining, and the `0.001` rejection are all unchanged and all bite (falsification below). What is *not* covered is the jsdom-vs-browser number-input sanitisation link — precisely the promotion-owed browser probe the lane names as item 2. That residual is correctly stated, not papered over.

**D2 — `useCurrency` mock relaxed to `(value: string | number) => String(value)` in `TreasuryTenantScope.test.tsx:41` (and `SplitPaymentForm.test.tsx:38`): ACCEPTABLE, hides nothing.**
The *old* mock (`(value: number) => value.toFixed(2)`) was already narrower than reality — the real hook is `format(value: string | number, options?)` (`src/hooks/useCurrency.ts:151-158` → `formatAmount` → `formatCurrency`, which accepts `string | number` at `src/lib/format.ts:118-119` and normalises through `safeDecimal`). The relaxation moves the mock *toward* the real signature. There is no formatting regression to hide: the real `formatCurrency("0.000", {currency:'TND'})` renders 3 decimals and `("0.000", {currency:'EUR'})` renders 2, identically to the pre-change numeric input. And the string path is exercised against the **real** formatter anyway — `treasury.test.tsx` does not mock `useCurrency` and renders `SplitPaymentForm totalAmount="1000"` seven times (54 tests green). The only caveat is m2 above: the relaxed mock is what makes the `/^0\.000$/` assertion a falsifier rather than a tautology, so it is load-bearing and should be labelled as such.

---

## What held up (verified, not accepted on report)

**Hook usage — all four surfaces.**
- Lock declared and set synchronously before `mutate`, released in a per-call `onSettled`: `PaymentForm.tsx:666,750-756`; `SplitPaymentForm.tsx:60,151-169`; `RecordPaymentModal.tsx:148,367-380`. RecordPaymentModal sets the lock *after* the confirmed-lines validation, per plan Step 5.
- `reset()` is the first statement of `onSuccess` and appears **exactly once per file**: `PaymentForm.tsx:711`, `SplitPaymentForm.tsx:106`, `RecordPaymentModal.tsx:339`. The two `onError` handlers (`PaymentForm.tsx:733`, `RecordPaymentModal.tsx:361`) do not reset — correct.
- Hooks unconditional: the sole top-level `return (` per component is at `PaymentForm.tsx:779`, `SplitPaymentForm.tsx:177`, `RecordPaymentModal.tsx:397`; every hook precedes it. No early return, no conditional hook.
- No new `useEffect` set-state pattern anywhere in the diff.
- Buttons: `PaymentForm.tsx:1363-1364` `disabled={isSubmitting || createMutation.isPending}` with the same combined condition on the `common:saving` / `common:save` label, exactly per plan Step 3. `SplitPaymentForm.tsx:313-315` keeps `submitMutation.isPending` and `RecordPaymentModal.tsx:852-855` keeps `mutation.isPending` — both explicitly what the plan prescribes, not oversights.

**Props / types.** `SplitPaymentFormProps.totalAmount: string` (`SplitPaymentForm.tsx:44`), `SplitPaymentModalProps.totalAmount: string` (`SplitPaymentModal.tsx:42`), JSDoc example de-floated to `totalAmount={document.balance_due}` (`SplitPaymentModal.tsx:73`), pass-through unchanged at `:122`. **I re-ran BOTH flips myself and restored** (see Commands). Flip 1 (form prop → `number`) produced `TS2578 Unused '@ts-expect-error'` at `SplitPaymentForm.test.tsx:193` plus `TS2345` at `SplitPaymentForm.tsx:118,152,186` proving `totalAmount` really reaches `bcsub`/`bccomp` as a string; flip 2 (modal prop → `number`) produced `TS2578` at `SplitPaymentForm.test.tsx:202`. Both directives are genuinely consumed.

**Remaining-amount element.** `SplitPaymentForm.tsx:194` carries `data-testid="split-payment-remaining"`; its child is exactly `{formatCurrency(remaining)}` (`:205`); the colour at `:196-203` is driven only by `bccomp(remaining, '0') === 0 / > 0` and uses `textColors.success / warningDark / error`. No hardcoded colours, no interpolated variant prefix or opacity modifier anywhere in the added lines (`git diff | grep '\$\{(tokens|textColors|borderColors|colors)'` → empty). No raw form control, no raw `<table>`, no new query key, no untranslated string. `common:saving`, `common:save`, `common:status.loading`, `common:actions.submit` all present in en / fr / ar.

**Red-first evidence — independently reproduced, all three surfaces** (each flip applied, run, then `git checkout HEAD --` and status verified clean):
- Removed the `if (submitLockRef.current) return` guard from `RecordPaymentModal.handleSubmit` → `AssertionError: expected "spy" to be called 1 times, but got 2 times`, `Tests 1 failed | 3 passed (4)`.
- Removed the guard + lock-set from `PaymentForm.onSubmit` → same assertion, `Tests 1 failed | 14 passed (15)`.
- Reverted `remaining` to `String(parseFloat(totalAmount) - parseFloat(currentTotal))` → **only** the `/^0\.000$/` assertion at `SplitPaymentForm.test.tsx:254` fails (`Tests 1 failed | 7 passed (8)`) — the POST assertion passes, confirming the plan's r8-B1 analysis that the rendered remaining is the sole falsifier.

**Double-click test shape.** Both clicks are inside ONE `act()` and the POST is held unresolved by a manual `resolvePost` in every one of the three lock tests (`PaymentForm.test.tsx:463-467`, `SplitPaymentForm.test.tsx:222-226`, `RecordPaymentModal/__tests__/tenantScope.test.tsx:257-261`). The resolution happens afterwards inside `await act(async () => …)`, so no unhandled-promise leak.

**Caller inventory.** `rg -n '<SplitPayment(Form|Modal)' apps/web/src` → 12 hits, all reconciled with the plan's Modify list; the only `<SplitPaymentModal` hit is its own JSDoc example (`SplitPaymentModal.tsx:65`) — **no active in-repository caller**, so the deprecated wrapper's signature change is inert at runtime. All three `RecordPaymentModal` hosts confirmed at the plan's lines.

**Second-of-everything / one-surface / benchmark checks.** No catalogue entity, no unique key, no new table, no new user-facing flow — Task 12 hardens an existing one. The only cross-cutting hit is m3 (glossary + duplicate producers), which predates the lane.

---

## Commands and outputs

```
$ cd apps/web && pnpm exec vitest run \
    src/hooks/__tests__/useIdempotencyKey.test.tsx \
    src/features/treasury/PaymentForm.test.tsx \
    src/features/treasury/SplitPaymentForm.test.tsx \
    src/features/treasury/treasury.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx
 Test Files  6 passed (6)
      Tests  86 passed (86)
      Duration 4.98s
```
Matches the handback's 86 exactly.

```
$ pnpm exec vitest run src/features/treasury src/components/organisms/RecordPaymentModal \
    src/components/organisms/SplitPaymentModal src/hooks
 Test Files  61 passed (61)
      Tests  379 passed (379)
      Duration 14.02s
```
(Every touched directory, default pool. No hung workers left behind.)

```
$ pnpm typecheck
> tsc --noEmit
(no output — exit 0)
```

```
$ pnpm exec eslint <the 9 touched files>
0 errors, 83 warnings          # after
0 errors, 75 warnings          # before, same files restored from a97631051 then reverted
```
Per-file table in M3. `precision/no-parsefloat-on-money` in `RecordPaymentModal.tsx` is unchanged at 25 total warnings before and after — the B-7 block is genuinely untouched, and no precision warning was added anywhere.

```
$ pnpm audit:keys
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
  src/features/uom/hooks/useUnits.ts:53:9  (NOT a Task 12 file)
exit 1

$ pnpm audit:design-system
New design-system violations: 15
  src/features/import/pages/ImportWizardPage.tsx  ×14
  src/features/uom/components/UnmappedUnitTextsPanel.tsx:138  ×1
Stale design-system baseline entries: 11 (all ImportWizardPage)
exit 1
```
**No line names any of the nine touched files.** `tools/audit-design-system-baseline.json` is not in the diff.

```
$ rg -n 'parseFloat|Math\.abs' apps/web/src/features/treasury/SplitPaymentForm.tsx
(no output)
$ rg -ni 'idempotenc' docs/glossary.md
(no output)
```

Tracked tree verified clean (`git status --porcelain` / `git diff --stat` empty for tracked files) after every temporary flip; the only untracked file is the concurrent treasury reviewer's report.

## Promotion-owed (confirmed NOT done — no stack in this session either)

The lane's four items stand, unmodified, and I ran none of them:
1. Throttled-network browser double-submit probes on PaymentForm, SplitPaymentForm, RecordPaymentModal.
2. Real-browser proof that `"0.300"` accepts `"0.100" + "0.200"` and rejects `"0.100" + "0.199"` — the definitive check for D1.
3. Opening payments from InvoiceDetailPage / SalesOrderDetailPage / PurchaseOrderDetailPage. **This is the probe that would have caught B1** — an open/fail/close/reopen cycle on any of the three hosts.
4. The two gate reviews (this one is the frontend half).

## Re-gate conditions

1. B1 fixed with a test asserting the second POST after close/reopen carries a **different** key.
2. M1 and M2 fixed or explicitly owner-deferred with a written rationale that names the replay consequence.
3. M3 resolved either way (fix the 10 warnings or record them accurately as accepted debt).

---

## Note on worktree state at the end of this review

Every measurement above was taken against a **clean tree at `9c28de0f9`** (`git status --porcelain` verified empty after each temporary flip). After the report was written, `git status` showed uncommitted third-party changes in the same worktree — `apps/web/src/hooks/useIdempotencyKey.ts`, `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx`, `PaymentForm.test.tsx`, `SplitPaymentForm.test.tsx`, and a new `RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx`. None of these were made by this reviewer (this gate is read-only). A concurrent session appears to be working the same lane. **Anything in that uncommitted set is unreviewed by this gate** and must be re-gated on its own commit.

---

# Re-gate r2 (2026-09-04)

- **Reviewer:** frontend-conventions-reviewer (adversarial merge gate, targeted re-gate)
- **Range re-gated:** `9c28de0f9..04b34cdcc` (`e8cac089f` FR1, `ea8374d78` FR2, docs `53a41c683` / `04b34cdcc`)
- **Worktree state:** clean at `04b34cdcc` before, during and after every measurement (`git status --porcelain` empty; verified after each temporary probe and after the one mutation).
- **Handback under review:** `docs/handoff/HANDBACK-request-hygiene-T12-2026-09-04.md` → `## Fix round 1` (FR1-A/B/C), `## Fix round 2` (FR2-A/B/C/D).

## VERDICT: CHANGES

r1's B1 is genuinely closed **for the open/close cycle**, and M2/M3/M4 and m2 are closed cleanly and honestly — the lint table reproduces to the warning, the −2 `parseFloat` removals are real, `audit:design-system` returns base numbers, and the baseline JSON is not in the diff. No evasion of any kind was found.

But the *placement* of the two new rotations re-opens the very invariant the round was written to establish — **"an unchanged retry after a failure keeps the key"** — on both reachable surfaces, and in both cases the new tests are shaped so they cannot see it. Both are proven below with probes against the production shape, not argued. Both fixes are one line.

---

## BLOCKING

### B1′ (BLOCKER) — the per-open rotation is keyed on `prefill`, which all three hosts pass as an inline object literal, so *any* parent re-render rotates the key mid-intent

- Rotation site: `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:182-183` (`hadFailedAttemptRef.current = false; resetIdempotencyKey()`), inside the effect whose dep array is `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:185` — `[isOpen, prefill, resetIdempotencyKey]`.
- `resetIdempotencyKey` **is** stable (`apps/web/src/hooks/useIdempotencyKey.ts:16-18`, `useCallback(…, [])`) and `isOpen` is a boolean — so the handback's claim at FR1-A ("the identity is stable and the effect does not re-fire") is true *of that dependency*. It is `prefill` that is unstable, and the handback does not mention it.
- All three hosts build `prefill` inline, so it is a new object on every parent render:
  - `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:899-906`
  - `apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:784-791`
  - `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:713-720`

**Probe (written under `src/`, run, deleted; tree verified clean).** Identical to the lane's own retention test except that `prefill` is a fresh object literal on rerender — i.e. exactly what the hosts pass — with `isOpen` held `true` throughout:

```
PROBE_FORM_WIPED unconfirmed_line_visible_after_parent_rerender=true
PROBE_KEY_SURVIVED=false k0=03a96255-5016-45c7-9c3a-c70031eb8e6e k1=f8561d95-d3c0-48fa-81f8-2cb5bc3748c7
```

One parent re-render, with the modal still open and its contents unchanged, **wipes the confirmed payment line and rotates the key**. The operator re-enters the same `400`, presses Record, and the POST carries a *new* key — so a batch that already committed is booked a second time. That is the double-payment this task exists to prevent, and it lands on the only surface with live production callers.

**The trigger is not exotic.** `apps/web/src/lib/queryClient.ts:8-9` sets `refetchOnWindowFocus: false` but `refetchOnReconnect: true`. The canonical failure this lane addresses is a lost response from a network drop; when the network returns, the host's document query refetches, `amount_residual`/`status` have changed because the payment *did* commit, the parent re-renders with new data, and the effect fires. Any other parent state change (another modal toggle, a menu) does the same.

**The new test cannot see it, and the divergence was noticed.** `apps/web/src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx:84-94` pins a module-level `PREFILL` constant with the comment *"a freshly-built prefill object on every rerender would fire the effect for the wrong reason and mask the open/close behaviour under test"*. That is a fair isolation choice for the **rotation-on-open** test at `:144`. It is not a fair fixture for the **retention** falsifier at `:182` or the **rotation-on-edit** test at `:207`, both of which reuse the same constant (`:190`, `:219`) and therefore assert an invariant the production shape does not hold. A fixture that is stabilised past the production condition is the r1 "fixture-shielded green" pattern.

**Fix directive:** move the key rotation out of the `prefill`-dependent effect into its own effect that depends only on the open transition — `useEffect(() => { if (isOpen) { hadFailedAttemptRef.current = false; resetIdempotencyKey() } }, [isOpen, resetIdempotencyKey])` at `RecordPaymentModal.tsx:182` — and re-shape the retention test at `idempotencyKeyLifecycle.test.tsx:182` to pass a **fresh** `prefill` object on rerender so it reproduces the host shape. (Memoising `prefill` with `useMemo` in the three hosts is an equally valid fix and additionally cures the pre-existing form wipe; the effect split is the smaller change and does not touch three feature files.)

---

## MAJOR

### M1′ (MAJOR) — PaymentForm's `watch` subscription cannot tell an operator edit from a programmatic write, so the key rotates with no payload change

- `apps/web/src/features/treasury/PaymentForm.tsx:609-612` — `watch(() => { startNewIntentOnPayloadEdit() })`, unconditional on the event kind.
- FR2-A states the design as *"Deliberate non-rotations (derived values, not operator edits)"*. That holds for the non-RHF withholding preview (`PaymentForm.tsx:584`, which correctly keeps the raw `setWithholdingRateState`). It does **not** hold for anything written through RHF.

**Probe (written under `src/`, run, deleted).** RHF 7.67.0, `watch(cb)` callback metadata:

```
PROBE2 ["name=amount type=change",   "--after user change--",
        "name=amount type=undefined","--after setValue--",
        "name=undefined type=undefined","--after reset--"]
```
and a companion probe confirming the counts: `setValue=1 reset=1 afterUnsubscribe=0`.

So **`setValue` and `reset()` both notify the subscription**, indistinguishably from a keystroke unless `type` is inspected — and `reset()` notifies even when it writes byte-identical values. PaymentForm makes exactly these writes while the form is mounted and the operator is looking at a failure banner:

- `apps/web/src/features/treasury/PaymentForm.tsx:394`, `:403`, `:415`, `:428` — four `reset({...})` prefill branches in one effect whose deps are the query data objects (`apps/web/src/features/treasury/PaymentForm.tsx:439`). A reconnect refetch re-fires this.
- `apps/web/src/features/treasury/PaymentForm.tsx:335`, `:339` — auto-derived `bank_iban`.
- `apps/web/src/features/treasury/PaymentForm.tsx:513` — repository cleared when the method changes compatibility.
- `apps/web/src/features/treasury/PaymentForm.tsx:1073-1083`, `:1425`, `:1437` — picker callbacks.

Consequence, same shape as B1′: operator submits `100`, server commits, response lost, `hadFailedAttemptRef` set. A reconnect refetch fires the prefill `reset()`; the subscription rotates the key. The operator presses Save on what they believe is the same request → a second payment is booked. The lane's own falsifier at `PaymentForm.test.tsx` ("reuses the SAME idempotency_key when retrying after a rejected POST") stays green because the test never issues a programmatic write.

**Fix directive:** discriminate on the RHF event kind at `PaymentForm.tsx:610` — `watch((_values, { type }) => { if (type === 'change') startNewIntentOnPayloadEdit() })` — and add a test that performs a programmatic `setValue`/`reset` after a failed submit and asserts the key is **unchanged**. (`SplitPaymentForm` and `RecordPaymentModal` do not use RHF for the payload and are unaffected by this one.)

---

## Verified closed

**B1 (r1 BLOCKER) — closed for the open/close cycle.** `resetIdempotencyKey()` is the last statement of the `isOpen` branch (`RecordPaymentModal.tsx:181-182`); `reset` is `useCallback(…, [])` (`useIdempotencyKey.ts:16-18`) so that dependency cannot loop; no re-fire from `isOpen` or `resetIdempotencyKey`. **Confirmed the lifecycle test's first submit is `mockRejectedValueOnce` on purpose** (`idempotencyKeyLifecycle.test.tsx:150`, rationale at `:145-149`): a success-first version would rotate the key inside `onSuccess` and pass against `9c28de0f9`, proving nothing. The rejection is what makes it red-first. Residual is B1′ above — the placement, not the intent.

**M3 (r1: SplitPaymentForm had no error surface) — closed.** `SplitPaymentForm.tsx:111-116` adds `onError` that sets the **existing** `validationError` state, rendered at `SplitPaymentForm.tsx:324-328` with `tokens.alert.base` + `tokens.alert.error` — design-system tokens, no interpolated variant prefix or opacity modifier, no new surface. Key `treasury:splitPayment.submitFailed` present and non-empty in all three locales (`src/locales/en/treasury.json`, `src/locales/fr/treasury.json`, `src/locales/ar/treasury.json`).

**Hooks discipline — verified line by line.**
- `watch` subscription unsubscribes: `PaymentForm.tsx:611`; probe confirms `afterUnsubscribe=0` events.
- Effect deps are all stable except the `prefill` case in B1′: `startNewIntentOnPayloadEdit` is `useCallback(…, [resetIdempotencyKey])` on both memoised surfaces (`PaymentForm.tsx:599-603`, `RecordPaymentModal.tsx:159-163`) over a `[]`-deps `reset`, so no loop.
- `startNewIntentOnPayloadEdit` is **never** called during render — every call site is an event handler, a wrapped setter invoked from a handler, or the `watch` notification (`PaymentForm.tsx:610`, `:643`, `:653`, `:658`, `:663`, `:668`; `SplitPaymentForm.tsx:141`, `:157`, `:164`; `RecordPaymentModal.tsx:278`, `:285`, `:292`, `:301`, `:328`, `:416`, `:421`).
- Wrapped setters create no render loop: each is a plain function that guards on `hadFailedAttemptRef.current` and clears it on the first call.
- No new `useEffect` set-state pattern beyond the intended rotation itself.
- `hadFailedAttemptRef` cleared on success on all three (`PaymentForm.tsx:758`, `SplitPaymentForm.tsx:107`, `RecordPaymentModal.tsx:367`) and on open (`RecordPaymentModal.tsx:182`); set in every `onError` (`PaymentForm.tsx:784`, `SplitPaymentForm.tsx:114`, `RecordPaymentModal.tsx:393`) with the key deliberately **not** reset there.

**Tests executable and mutation-proven.** All seven named paths green (below). One mutation re-run and restored: deleting the `watch` subscription effect (`PaymentForm.tsx:609-612`) turns **exactly** the rotation-on-edit test red and leaves the retention falsifier green —

```
✓ PaymentForm idempotency and double-submit lock > adds a key and a ref lock rejects a second synchronous submit
✓ PaymentForm idempotency key survives a failed request > reuses the SAME idempotency_key when retrying after a rejected POST
× PaymentForm idempotency key is scoped to ONE submit intent > mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
 Tests  1 failed | 16 passed (17)
```
— restored from a byte copy; `git status --porcelain` empty afterwards. The three rotation tests and the four replay falsifiers are all present and named as claimed.

**Test helper hygiene — clean.** `postedIdempotencyKey()` narrows through `unknown` with `typeof`/`in` guards and throws on shape violations (`idempotencyKeyLifecycle.test.tsx:121-131`, `SplitPaymentForm.test.tsx:284-294`, and the copies in `PaymentForm.test.tsx` / `RecordPaymentModal/__tests__/tenantScope.test.tsx`). Across the four test files: **no `as` casts** (only `as const` on literal fixtures), **no non-null assertions**, **no `eslint-disable`, no `@ts-ignore`**. The only two `@ts-expect-error` are the deliberate compile-time contract lines (`SplitPaymentForm.test.tsx:206`, `:215`), both still consumed (r1 flip proofs stand).

**m2 (r1 MINOR) — closed.** `SplitPaymentForm.test.tsx:33-38` now carries the "LOAD-BEARING MOCK (gate m2)" comment naming the `-5.55e-17` residue and forbidding a faithful mock without a replacement guard.

**Deviation 1 confirmed as described.** The withholding rotation is wired through renamed state setters (`PaymentForm.tsx:283-285` `setWithholdingEnabledState` / `setWithholdingTransactionTypeState` / `setWithholdingRateState`, wrappers at `:652-669`), so every existing call site and the raw `<button>` JSX around it is byte-identical and no baselined design-system fingerprint moved. Independently reproduced: `audit:design-system` → **796 acknowledged / 15 new / 11 stale** (the r1 base numbers exactly, all `ImportWizardPage` ×14 + `UnmappedUnitTextsPanel` ×1), and `tools/audit-design-system-baseline.json` is **not** in `git diff --name-only a97631051..04b34cdcc` (17 files, all listed in the handback).

---

## `no-deprecated` ruling: ACCEPT

**One `@typescript-eslint/no-deprecated` warning at `apps/web/src/features/treasury/SplitPaymentForm.test.tsx:211` is accepted as-is.** Both the component (`SplitPaymentModal.tsx:56`) and its props interface (`SplitPaymentModal.tsx:16`) carry `@deprecated`, so *every* way of naming the wrapper's contract warns exactly once. I probed the one alternative the handback did not test — `ComponentProps<typeof SplitPaymentModal>`, the exact sibling shape already used one line above for the form — and it warns too:

```
$ pnpm exec eslint src/features/treasury/zzprobe3.test.tsx   # temp file, deleted
errors 0 warnings 1
   7 @typescript-eslint/no-deprecated
```

The test's purpose is to lock the deprecated wrapper's `totalAmount: string` contract, the plan requires it, and its `@ts-expect-error` is consumed (r1 flip 2 → `TS2578`). A suppression comment would be detector evasion and is out of bounds. Zero errors, and the lane still lands **below** the pre-lane base (74 vs 75). No different shape required.

---

## Commands and outputs (all re-run by this gate)

```
$ cd apps/web && pnpm vitest run \
    src/hooks/__tests__/useIdempotencyKey.test.tsx \
    src/features/treasury/PaymentForm.test.tsx \
    src/features/treasury/SplitPaymentForm.test.tsx \
    src/features/treasury/treasury.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx
 Test Files  7 passed (7)
      Tests  94 passed (94)
      Duration 6.61s
```
Matches the handback's 94 exactly (86 → 90 → 94).

```
$ pnpm vitest run src/features/treasury src/components/organisms/RecordPaymentModal \
    src/components/organisms/SplitPaymentModal src/hooks
 Test Files  62 passed (62)
      Tests  387 passed (387)
      Duration 11.95s
```
Default pool. No worker of mine left behind (the `node (vitest …)` processes still alive belong to a concurrent session's `rh-t7` / stock-transfers run, not this gate).

```
$ pnpm typecheck
> tsc --noEmit
(no output — exit 0)
```

**eslint per file, `a97631051` baselines vs `04b34cdcc`** — baselines materialised with `git show a97631051:apps/web/<path>` into `zzbase_`-prefixed sibling copies **inside `src/`** (same directory, same `.test.tsx` suffix so the same overrides apply), linted in one invocation, then deleted; tree verified clean.

| file | base | after | Δ |
|---|---|---|---|
| `src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | E0 W25 | E0 W25 | 0 |
| `src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` | (new) | E0 W0 | 0 |
| `src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` | E0 W13 | E0 W13 | 0 |
| `src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx` | E0 W1 | E0 W1 | 0 |
| `src/features/treasury/PaymentForm.test.tsx` | E0 W4 | E0 W4 | 0 |
| `src/features/treasury/PaymentForm.tsx` | E0 W13 | E0 W13 | 0 |
| `src/features/treasury/SplitPaymentForm.test.tsx` | E0 W1 | E0 W2 | **+1** (`@typescript-eslint/no-deprecated` 0→1) |
| `src/features/treasury/SplitPaymentForm.tsx` | E0 W3 | E0 W1 | **−2** (`precision/no-parsefloat-on-money` 2→0) |
| `src/features/treasury/__tests__/TreasuryTenantScope.test.tsx` | E0 W0 | E0 W0 | 0 |
| `src/features/treasury/treasury.test.tsx` | E0 W15 | E0 W15 | 0 |
| `src/hooks/useIdempotencyKey.ts` | E0 W0 | E0 W0 | 0 |
| **total** | **E0 W75** | **E0 W74** | **NET −1** |

**r1's M4 is fully retired.** The handback's table reproduces to the warning and to the rule id. Nine of the ten warnings r1 measured are genuinely gone; the tenth is the accepted `no-deprecated`. Mechanism audit: no `eslint-disable` anywhere in the diff, no `eslint.config` change, no alias table, no renamed-but-equivalent literal, no baseline write. The −2 is a real removal (`rg 'parseFloat|Math\.abs' src/features/treasury/SplitPaymentForm.tsx` → no matches).

```
$ pnpm audit:keys
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
  src/features/uom/hooks/useUnits.ts:53:9   (NOT a Task 12 file)
exit 1

$ pnpm audit:design-system
[gate-summary] Design-system baseline: 796 acknowledged, 15 new, 11 stale baseline entries
  (ImportWizardPage ×14, UnmappedUnitTextsPanel:138 ×1 — identical to r1's m1 base debt)
exit 1

$ pnpm audit:i18n:local | grep -i treasury
(no output)          # 64 base gaps, all `uom` + one `fr|import` plural
```
Full `pnpm --filter @autoerp/web lint` therefore remains RED on inherited base debt only (r1 m1, unchanged) — no line names any of the eleven touched files.

**merge-tree** (from the main checkout `/Users/houssamr/Projects/syneriva/apps/erp`):
```
$ git merge-tree --write-tree dev lane/rh-t12-payment-idempotency
db488c012a0f4d86421870ef0b41712b675b2850     exit 0, 0 conflicts
$ git rev-list --left-right --count dev...lane/rh-t12-payment-idempotency
61      6                                    # lane is 6 behind dev
```
**Note:** local `dev` is at `5e1e54f69` ("Merge branch 'lane/rh-t5-product-search' into dev"), not the `6292cf235` named in the re-gate brief — `dev` has moved. Re-run against the named SHA as well: `git merge-tree --write-tree 6292cf235 lane/rh-t12-payment-idempotency` → exit 0, clean. **Both are conflict-free.**

---

## Promotion-owed — still NOT run, still blocking (unchanged from r1 and FR1-C)

I ran none of these; no stack in this session. They stand verbatim:
1. Throttled-network double-submit probes on `PaymentForm`, `SplitPaymentForm`, `RecordPaymentModal`.
2. Real-keyboard proof that `step="0.001"` accepts `"0.100" + "0.200"` against `"0.300"` and rejects `"0.100" + "0.199"` — the definitive check for D1. FR1-C item 3 stands: `SplitPaymentForm` has no production route, so option **(A)** (harness route, recorded as such) or **(B)** (explicit downgrade) must be chosen and written down.
3. Opening payments from `InvoiceDetailPage` / `SalesOrderDetailPage` / `PurchaseOrderDetailPage` (open → fail → close → reopen).
4. **fail → edit → resubmit** on `PaymentForm` and `RecordPaymentModal`, confirming a *second, visible* payment rather than a replayed success panel.

Add a fifth, arising from B1′/M1′: **fail → leave the surface untouched → let the network return → retry**, confirming the retry carries the **same** key and books nothing new. That is the leg the current jsdom fixtures structurally cannot cover.

## m1–m6 classification

| minor | status | classification |
|---|---|---|
| m1 — base lint debt (`audit:keys` + `audit:design-system` red) | unchanged, reconfirmed above; no Task-12 file named | **follow-up** — not this lane's debt |
| m2 — relaxed `useCurrency` mock is load-bearing | **CLOSED** by FR2-D (`SplitPaymentForm.test.tsx:33-38`) | — |
| m3 — "idempotency key" absent from `docs/glossary.md`; four hand-rolled producers beside the shared hook | unchanged | **follow-up** (Phase B convergence; one-surface-per-concept) |
| m4 — typecheck flip-1 transcript incomplete (19 diagnostics, 10 quoted) | unchanged | **follow-up** — documentation accuracy, conclusion unaffected |
| m5 — `react-doctor` complexity | improved (24 → 19 issues, 87 → 88); every remaining finding pre-existing | **follow-up** — no lane attribution |
| m6 — `crypto.randomUUID` needs a secure context | unchanged | **follow-up** — confirm staging/production origins are HTTPS before promotion |

None of m1–m6 is blocking. The two blocking items are **B1′** and **M1′**.

## Re-gate r3 conditions

1. **B1′** — rotation moved off the `prefill`-dependent effect (or `prefill` memoised in all three hosts), **and** the retention falsifier at `idempotencyKeyLifecycle.test.tsx:182` re-shaped to pass a fresh `prefill` object on rerender so it reproduces the host shape.
2. **M1′** — `PaymentForm.tsx:610` discriminates on the RHF event kind (`type === 'change'`), **and** a test proves a programmatic `setValue`/`reset` after a failed submit leaves the key unchanged.
3. Everything else in this round is accepted: FR1-A intent, FR1-B falsifiers, FR2-B error surface, FR2-C lint (net −1, one accepted `no-deprecated`), FR2-D, Deviation 1.
