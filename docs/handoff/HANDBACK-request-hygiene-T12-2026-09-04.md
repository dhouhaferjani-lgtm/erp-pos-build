# HANDBACK — Request Hygiene Phase A, Task 12 (payment surfaces send keys + synchronous double-submit lock, ID-1/ID-2)

- **Plan:** `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` rev 11 → `## Task 12: Payment surfaces send keys and synchronously block double submit (ID-1, ID-2)`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12`
- **Branch:** `lane/rh-t12-payment-idempotency` (based on local `dev`, base commit `a97631051`)
- **Commits:** `9a2b61775` (implementation + tests) → the doc-only follow-up carrying this file. A doc-only follow-up carries this file, since a commit cannot contain its own hash.
- **Scope:** WEB ONLY. No `apps/api` file was touched. The backend already accepts `Idempotency-Key` / `idempotency_key` on both endpoints (`apps/api/.../PaymentController.php:128`, `MultiPaymentController.php:52`); no backend change was needed and none is proposed.
- **Result:** all six named suites green (86 tests), `pnpm typecheck` PASS with both `@ts-expect-error` directives consumed and both flip-back proofs FAILING as required, eslint 0 errors on all nine touched files, both forbidden-pattern greps empty, caller inventory reconciled 1:1 with the plan.
- **NOT DONE (promotion-owed, no stack tonight):** the throttled-network browser double-submit probes on the three active surfaces, and opening payments from InvoiceDetailPage / SalesOrderDetailPage / PurchaseOrderDetailPage. See **Promotion-owed** at the bottom.

## Files changed (9, all under `apps/web/src`)

Production (4):

- `features/treasury/PaymentForm.tsx` — key + ref lock + pending-aware submit button
- `features/treasury/SplitPaymentForm.tsx` — key + ref lock + **string-only money boundary** (`totalAmount: string`, bcmath totals)
- `components/organisms/SplitPaymentModal/SplitPaymentModal.tsx` — `totalAmount: string`, JSDoc example de-floated, unchanged pass-through
- `components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` — key + ref lock ONLY (its float total/remaining block around line 216 deliberately untouched — Phase B B-7)

Tests (5):

- `features/treasury/PaymentForm.test.tsx`
- `features/treasury/SplitPaymentForm.test.tsx`
- `features/treasury/treasury.test.tsx`
- `features/treasury/__tests__/TreasuryTenantScope.test.tsx`
- `components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx`

`SupplierInvoiceDetailPage.tsx` (the other keyless `/payments` writer, via `supplier-invoices/api.ts:478`) was **not** touched — explicitly out of scope per the plan's Step 4 note, tracked as a Phase B B-7 sibling.

---

## Step 1 — PaymentForm red, then green

Red (`npx vitest run src/features/treasury/PaymentForm.test.tsx`), before Step 3:

```
 FAIL  src/features/treasury/PaymentForm.test.tsx > PaymentForm idempotency and double-submit lock
       > adds a key and a ref lock rejects a second synchronous submit
AssertionError: expected "spy" to be called 1 times, but got 2 times

 Test Files  1 failed (1)
      Tests  1 failed | 14 passed (15)
```

That is the *right* red: the two `fireEvent.submit(form)` calls inside one `act()` produced **two** `/payments` POSTs, which is precisely ID-2. (The key assertion never ran.)

Green after Step 3:

```
 Test Files  1 passed (1)
      Tests  15 passed (15)
```

The plan's test body was used verbatim except for `waitFor(() => { expect(...) })` brace style (see Deviation D3).

## Step 2 — split-payment conversions + type/precision regressions, red

All conversions applied exactly as the plan lists them:

| Change | Where | Result |
|---|---|---|
| `totalAmount={100}` → `"100"` | `SplitPaymentForm.test.tsx:83` | done |
| `{ totalAmount: 100 }` → `{ totalAmount: '100' }` | `SplitPaymentForm.test.tsx:132` | done |
| `totalAmount={1000}` → `"1000"` ×7 | `treasury.test.tsx:1207,1227,1249,1276,1299,1334,1380` | done (grep-verified count == 7) |
| `totalAmount={100}` → `"100"` ×2 | `TreasuryTenantScope.test.tsx:170,213` | done |
| `format: (value: number) => value.toFixed(2)` → `(value: string \| number) => String(value)` | `SplitPaymentForm.test.tsx` useCurrency mock | done |
| exact POST assertion + `idempotency_key` matcher | `SplitPaymentForm.test.tsx:147` | done |

Red run (`npx vitest run src/features/treasury/SplitPaymentForm.test.tsx`), before Step 4:

```
   ✓ renders the line amount field as a MoneyInput via FormField
   ✓ renders the method and repository selects via FormField
   × submits matching split amount as a canonical string and calls onSuccess
     → expected "spy" to be called with arguments: [ …(2) ]        (no idempotency_key in body)
   ✓ renders cancel and submit Buttons that invoke their handlers
   ✓ rejects number-typed split-payment totalAmount props at compile time   (runtime no-op; the real gate is tsc)
   × uses the synchronous ref lock before React can rerender pending state
     → expected "spy" to be called 1 times, but got 2 times
   × accepts 0.100 plus 0.200 against the exact decimal-string total 0.300
     → expected "spy" to be called with arguments: [ …(2) ]
   × rejects a three-decimal split total that is short by 0.001
     → Unable to find an element with the text: treasury:splitPayment.amountDoesNotMatch

 Test Files  1 failed (1)
      Tests  4 failed | 4 passed (8)
```

Note the fourth red: under the OLD `Math.abs(currentTotal - totalAmount) > 0.01` tolerance a 0.001 shortfall was **accepted and POSTed**. That is the millime-precision bug the plan set out to close, reproduced.

## Step 3 — PaymentForm implementation

Applied verbatim: `useIdempotencyKey` imported from `@/hooks/useIdempotencyKey`; `submitLockRef`; `idempotency_key: idempotencyKey` as the first field of the `/payments` body; `resetIdempotencyKey()` as the first statement of the async `onSuccess` (before its `Promise.all`), never in `onError` — so the key **survives a failed submit** and rotates only after an awaited success; `onSubmit` guards on the ref, sets it, and clears it in a per-call `onSettled`; the submit button is `disabled={isSubmitting || createMutation.isPending}` with the same combined condition driving the `common:saving` label.

## Step 4 — SplitPaymentForm + deprecated wrapper, string-only money boundary

`SplitPaymentFormProps.totalAmount` is now `string`. `currentTotal` is a `bcadd` fold seeded at `'0.000'` (empty line → `'0'`), `remaining` is `bcsub(totalAmount, currentTotal, 3)` — `totalAmount` flows **directly** into `bcsub`/`bccomp`, never through `String(...)` or `Number(...)`, so every caller-provided decimal digit is preserved. Validation is `bccomp(currentTotal, totalAmount) !== 0` (exact) and `bccomp(line.amount, '0') <= 0`. The remaining-amount `<div>` carries `data-testid="split-payment-remaining"` with child exactly `{formatCurrency(remaining)}`, and its colour is driven only by `bccomp(remaining, '0') === 0` / `> 0`. The local formatter is `(amount: string): string => formatCurrencyHook(amount)`. `submitMutation.isPending` still drives the button.

`SplitPaymentModal.tsx`: prop type `number` → `string`; JSDoc example `totalAmount={parseFloat(document.balance_due)}` → `totalAmount={document.balance_due}`; the render remains an unchanged `totalAmount={totalAmount}` pass-through.

**Falsification proof that the `"0.000"` assertion actually bites.** The plan (rev 9 gate r8 B1) says the POST alone passes under the old float path, so the rendered remaining is the falsifier. I verified that claim empirically by temporarily reverting *only* the remaining computation to a float expression and re-running the one test:

```
# temporary, reverted immediately:
- const remaining = bcsub(totalAmount, currentTotal, 3)
+ const remaining = String(parseFloat(totalAmount) - parseFloat(currentTotal))

$ npx vitest run src/features/treasury/SplitPaymentForm.test.tsx -t "accepts 0.100"
 FAIL … > accepts 0.100 plus 0.200 against the exact decimal-string total 0.300
   → expect(element).toHaveTextContent()
     SplitPaymentForm.test.tsx:254  expect(screen.getByTestId('split-payment-remaining')).toHaveTextContent(/^0\.000$/)
      Tests  1 failed | 7 skipped (8)
```

The float residue is caught by exactly that assertion and nothing else. Source restored and re-verified green.

Green after Step 4 (`SplitPaymentForm.test.tsx`): **8 passed (8)**.

## Step 5 — RecordPaymentModal

Red first (`npx vitest run src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx`):

```
 FAIL  … > adds a key and synchronously locks duplicate payment recording
AssertionError: expected "spy" to be called 1 times, but got 2 times
 Test Files  1 failed (1)
      Tests  1 failed | 3 passed (4)
```

Implementation: `useRef` added to the React import, `useIdempotencyKey` + `submitLockRef` declared beside the existing state, `idempotency_key: idempotencyKey` added as the first field of the existing `/payments` body, `resetIdempotencyKey()` as the first statement of the existing `onSuccess` (never in `onError`), and the lock set **after** the confirmed-lines validation and before `mutation.mutate(undefined, { onSettled: … })`. **The modal's pre-existing float total/remaining/validation block (its four `precision/no-parsefloat-on-money` warnings at lines 216/290/304/309 and the ones at 441/793) is untouched** — that is Phase B B-7 debt and this lane does not authorize refactoring it.

Green: **4 passed (4)**.

---

## Step 6 — verification

### a. Six named suites, by path

```
$ npx vitest run \
    src/hooks/__tests__/useIdempotencyKey.test.tsx \
    src/features/treasury/PaymentForm.test.tsx \
    src/features/treasury/SplitPaymentForm.test.tsx \
    src/features/treasury/treasury.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx

 ✓ src/hooks/__tests__/useIdempotencyKey.test.tsx (3 tests) 23ms
 ✓ src/features/treasury/SplitPaymentForm.test.tsx (8 tests) 694ms
 ✓ src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx (4 tests) 689ms
 ✓ src/features/treasury/__tests__/TreasuryTenantScope.test.tsx (2 tests) 362ms
 ✓ src/features/treasury/PaymentForm.test.tsx (15 tests) 997ms
 ✓ src/features/treasury/treasury.test.tsx (54 tests) 2257ms

 Test Files  6 passed (6)
      Tests  86 passed (86)
```

Per file: useIdempotencyKey 3 · PaymentForm 15 · SplitPaymentForm 8 · treasury 54 · TreasuryTenantScope 2 · RecordPaymentModal tenantScope 4.

**Extra (not required by the plan, run because the modal changed):** the three active host pages plus the invoice guided-cancel seam —

```
$ npx vitest run \
    src/features/documents/invoices/__tests__/InvoiceDetailPage.tenantScope.test.tsx \
    src/features/documents/sales-orders/__tests__/SalesOrderDetailPage.tenantScope.test.tsx \
    src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx \
    src/features/documents/invoices/__tests__/guidedCancelSeam.test.tsx
 Test Files  4 passed (4)
      Tests  30 passed (30)
```

### b. `pnpm typecheck` — PASS, and the type-boundary proof

Baseline:

```
$ cd apps/web && pnpm typecheck
> tsc --noEmit
(no output — exit 0)
```

Both `@ts-expect-error` directives in `SplitPaymentForm.test.tsx` are **consumed** at this state (an unconsumed directive is itself a TS2578 error, so a clean run is the proof).

**Proof 1 — flip `SplitPaymentFormProps.totalAmount` back to `number`:**

```
src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx(122,11): error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/__tests__/TreasuryTenantScope.test.tsx(172,46): error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/__tests__/TreasuryTenantScope.test.tsx(215,49): error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/SplitPaymentForm.test.tsx(84,7):  error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/SplitPaymentForm.test.tsx(153,40): error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/SplitPaymentForm.test.tsx(193,7):  error TS2578: Unused '@ts-expect-error' directive.
src/features/treasury/SplitPaymentForm.test.tsx(214,18): error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/SplitPaymentForm.test.tsx(237,18): error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/SplitPaymentForm.test.tsx(258,18): error TS2322: Type 'string' is not assignable to type 'number'.
src/features/treasury/SplitPaymentForm.tsx(118,27): error TS2345: Argument of type 'number' is not assignable to parameter of type 'string'.
```

→ **TS2578 Unused '@ts-expect-error' directive at line 193** (the form directive), exactly as the plan requires, plus TS2345 proving `totalAmount` really reaches `bcsub` as a string.

**Proof 2 — flip `SplitPaymentModalProps.totalAmount` back to `number`:**

```
src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx(122,11): error TS2322: Type 'number' is not assignable to type 'string'.
src/features/treasury/SplitPaymentForm.test.tsx(202,7): error TS2578: Unused '@ts-expect-error' directive.
```

→ **TS2578 at line 202** (the modal directive). Both files restored; `pnpm typecheck` re-run clean afterwards.

### c. eslint on the touched files

```
$ npx eslint <all 9 touched files>
✖ 83 problems (0 errors, 83 warnings)
```

**0 errors.** Every warning is pre-existing house debt in categories the lane did not introduce: `@typescript-eslint/array-type` (the `Array<{…}>` mutationFn parameter shape, which the plan prescribes verbatim and which the *previous* SplitPaymentForm code already had on the same declaration), `no-unsafe-assignment` / `no-unsafe-type-assertion` / `no-non-null-assertion` in the legacy `treasury.test.tsx` fixtures, `react-hooks/exhaustive-deps` and `no-unnecessary-template-expression` in `RecordPaymentModal.tsx`, and `precision/no-parsefloat-on-money` at RecordPaymentModal lines 216/290/304/309/441/793 — the Phase B B-7 block this lane is forbidden to touch. `SplitPaymentForm.tsx` has exactly one warning (`array-type`, line 95) and `SplitPaymentModal.tsx` one (`no-deprecated`, line 87, its own self-reference).

The only genuinely NEW warnings this lane adds are two `@typescript-eslint/no-non-null-assertion` (plus their paired `no-unnecessary-type-assertion`) at `SplitPaymentForm.test.tsx:104,110` — they come from the plan's own prescribed `methodInputs[index]!` / `amountInputs[index]!` in `fillTwoSplits`, and they follow a `toHaveLength(2)` assertion two lines above, so the assertion is load-bearing only for TS, not for safety. Left as the plan wrote them; say the word if you want them replaced with a narrowing guard.

**`react-doctor` (commit hook).** The pre-commit hook reported "staged regressions"; the commit still landed. Running it scoped to this lane (`npx react-doctor --blocking warning --scope changed --base a97631051`) gives **score 88/100, 3 warnings, 0 errors**, all three pre-existing structural properties of components this lane only added two lines to:

```
⚠ React function has high control-flow complexity ×2   react-doctor/no-high-complexity-react-function
  src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:127   (the component declaration)
  src/features/treasury/PaymentForm.tsx:266                                (the component declaration)
⚠ Many related useState calls                          react-doctor/prefer-useReducer
  src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:132
```

Both components were already over the complexity threshold and already had the useState cluster; this lane added one `useIdempotencyKey()` call and one `useRef` to each. Splitting either component is out of scope (rule 4, and the plan explicitly forbids refactoring RecordPaymentModal beyond the key/lock). Flagged for the reviewer, not fixed.

Not part of the Step 6 verification list, but run for confidence: `pnpm audit:keys` and `pnpm audit:design-system` both fail on **pre-existing, untouched** files (`src/features/uom/hooks/useUnits.ts:53`, `src/features/import/pages/ImportWizardPage.tsx`). Neither is in this lane's diff.

### d. Caller inventory — `rg -n '<SplitPayment(Form|Modal)' apps/web/src`

```
src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:65   * <SplitPaymentModal            ← JSDoc example (plan: "only its JSDoc example")
src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx:120        <SplitPaymentForm      ← plan Modify :122 (pass-through)
src/features/treasury/SplitPaymentForm.test.tsx:82                                                 ← plan Modify :81
src/features/treasury/treasury.test.tsx:1205                                                       ← plan Modify :1205
src/features/treasury/treasury.test.tsx:1225                                                       ← plan Modify :1225
src/features/treasury/treasury.test.tsx:1247                                                       ← plan Modify :1247
src/features/treasury/treasury.test.tsx:1274                                                       ← plan Modify :1274
src/features/treasury/treasury.test.tsx:1297                                                       ← plan Modify :1297
src/features/treasury/treasury.test.tsx:1332                                                       ← plan Modify :1332
src/features/treasury/treasury.test.tsx:1378                                                       ← plan Modify :1378
src/features/treasury/__tests__/TreasuryTenantScope.test.tsx:172                                   ← plan Modify :170
src/features/treasury/__tests__/TreasuryTenantScope.test.tsx:215                                   ← plan Modify :213
```

12 hits, **all** reconciled against the plan's Modify list (small line drifts are the inserted imports/helpers). There is **no active in-repository `<SplitPaymentModal>` caller** — the only hit is its own JSDoc example — exactly as the plan's signature note states. `rg -n '<RecordPaymentModal' apps/web/src` confirms the three active hosts at the plan's exact lines: `InvoiceDetailPage.tsx:895`, `SalesOrderDetailPage.tsx:780`, `PurchaseOrderDetailPage.tsx:709`.

### e. Forbidden-pattern greps — both empty

```
$ rg -n 'parseFloat|Math\.abs' apps/web/src/features/treasury/SplitPaymentForm.tsx
(no output, exit 1)

$ rg -n 'totalAmount\s*:\s*number|Number\(' \
    apps/web/src/features/treasury/PaymentForm.tsx \
    apps/web/src/features/treasury/SplitPaymentForm.tsx \
    apps/web/src/features/treasury/PaymentForm.test.tsx \
    apps/web/src/features/treasury/SplitPaymentForm.test.tsx \
    apps/web/src/features/treasury/treasury.test.tsx \
    apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx \
    apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx \
    apps/web/src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx
(no output, exit 1)
```

---

## Deviations from the plan

**D1 — `fillTwoSplits` uses `fireEvent.change` for amounts, not `userEvent.type`. (forced by the harness; the only substantive deviation)**

The plan's helper types `'0.100'` with `userEvent.type`. On an `input[type=number]` that is **impossible to express**: the harness swallows the trailing zeros. I probed it in isolation before deviating, with a throwaway component wrapping the real `MoneyInput`:

```
await userEvent.type(input, '0.100')
EMITTED   ["0","0.1"]      DOMVALUE "0.1"

fireEvent.change(input, { target: { value: '0.100' } })
EMITTED   ["0.100"]        DOMVALUE "0.100"
```

This is a `userEvent`/jsdom limitation on number inputs, **not** a product defect: `MoneyInput.handleChange` passes `e.target.value` through verbatim with no `parseFloat` (`MoneyInput.tsx`), which the `fireEvent` probe confirms. Written as the plan had it, the test would have asserted `amount: "0.100"` against a received `"0.1"` and failed forever for a harness reason, and — worse — it would have silently stopped exercising the three-decimal contract at all. `fireEvent.change` delivers the verbatim decimal string a real keyboard produces in a browser. Method selection still uses `userEvent.selectOptions`; the submit clicks in the sibling tests still use `userEvent.click`. Everything the plan actually asserts (exact `"0.100"`/`"0.200"` split amounts, the UUID matcher, the exact `/^0\.000$/` remaining, the 0.001 rejection) is unchanged and green. A comment in the helper records the reason. **The browser probe owed at promotion is the definitive check for this one** — see Promotion-owed.

**D2 — `TreasuryTenantScope.test.tsx`'s `useCurrency` mock relaxed to `(value: string | number) => String(value)`. (forced)**

The plan lists this file's lines 170 and 213 as Modify entries but does not mention its mock, which is `format: (value: number) => value.toFixed(2)`. Once SplitPaymentForm feeds the formatter decimal **strings**, both tests died with `value.toFixed is not a function`:

```
 ❯ src/features/treasury/__tests__/TreasuryTenantScope.test.tsx (2 tests | 2 failed)
     → value.toFixed is not a function
     → value.toFixed is not a function
```

I applied the identical relaxation the plan prescribes for `SplitPaymentForm.test.tsx`'s mock — same shape, same reason. `treasury.test.tsx` needed no such change: it does not mock `useCurrency`, so it exercises the real `string | number` formatter.

**D3 — `waitFor(() => expect(...))` written as `waitFor(() => { expect(...) })` in the three new tests.**

Cosmetic, and verified rather than assumed. Reverting to the plan's bare-expression form and re-linting produces two new `@typescript-eslint/no-confusing-void-expression` warnings (SplitPaymentForm.test.tsx lines 225 and 242) that vanish with the braced form:

```
# plan's form:
225 warning @typescript-eslint/no-confusing-void-expression
242 warning @typescript-eslint/no-confusing-void-expression
# braced form: absent
```

Warnings, not errors, so either form would pass the gate — the braced form is the house style used elsewhere in these same files. No behavioural difference.

**D4 — comment wording.** The plan's rev-9 comment block above the `"0.000"` assertion says "0.1+0.2 error ≈ 5.55e-17"; I spelled `≈` as "is about" and dropped the `≈`/unicode to keep the file ASCII-clean, and I moved the comment inside the test body rather than above the `it(...)`. Content unchanged.

Everything else — the PaymentForm test body, the compile-time contract test, the delayed-promise double-click tests, the two three-decimal cases, all four implementation snippets, the `data-testid`, the seven `treasury.test.tsx` conversions — is the plan verbatim.

---

## Promotion-owed (NOT done in this lane — no running stack tonight)

These are the Step 6 items I could not execute, stated plainly rather than approximated:

1. **Throttled-network browser double-submit probes on all three active surfaces** (PaymentForm, SplitPaymentForm, RecordPaymentModal). The jsdom tests prove the ref lock rejects a second synchronous submit inside one `act()` boundary, which is the ID-2 invariant; they do **not** prove the real-browser rendering/pointer path under a slow network.
2. **The `"0.300"` accepts `"0.100" + "0.200"` / rejects `"0.100" + "0.199"` proof in a real browser.** Given deviation D1, this is the item that closes the loop on whether an operator's real keystrokes deliver `"0.100"` to the payload — the jsdom harness cannot answer that question for a number input.
3. **Opening payments from `InvoiceDetailPage`, `SalesOrderDetailPage`, and `PurchaseOrderDetailPage`** to verify the active RecordPaymentModal key lifecycle end to end. Static evidence only: all three hosts confirmed present at the plan's exact lines, and all three host tenant-scope suites are green (30 tests).
4. **Gate: `treasury-reviewer` + `frontend-conventions-reviewer`.** Not run by this lane.

## For the reviewers

- **Key lifecycle.** All three surfaces call `reset()` only in `onSuccess`, never in `onError` — the key survives a failed submit so a retry of the same intent deduplicates server-side, and rotates only after an awaited success. `useIdempotencyKey`'s own suite (3 tests) covers retain-until-reset, per-mount uniqueness, and stable reset identity.
- **Lock release.** All three use a per-call `onSettled` so the lock clears on both success and failure; a failed payment stays retryable.
- **`crypto.randomUUID()` secure-context caveat from the T11 handback still stands and is still unaddressed.** It is now on the *payment* path on three surfaces. If any deployment origin is plain HTTP (non-localhost), `crypto.randomUUID` is undefined there and these forms will throw at render. Worth confirming the staging/production origin scheme before promotion.
- **`RecordPaymentModal` float debt is intentionally still there** (six `precision/no-parsefloat-on-money` warnings). Phase B B-7.
- **`SupplierInvoiceDetailPage` still writes `/payments` with no key.** Phase B B-7 sibling, recorded in the plan, deliberately not widened into.
- **Exactness change worth a second look:** SplitPaymentForm's match check went from a 0.01 float tolerance to `bccomp(...) !== 0`, and the "remaining is zero" success colour likewise. That is the intended millime-precision tightening (a 0.001 shortfall now blocks the POST), but it is a genuine behaviour change for any operator who previously got away with a sub-centime mismatch.

---

# Fix round 1 (2026-09-04)

Response to `docs/superpowers/reviews/2026-09-04-request-hygiene-t12-gate-treasury.md` (CHANGES-REQUESTED: B1, B2). The `frontend-conventions` gate report landed in the same worktree during this round and its BLOCKER B1 is the same defect as treasury B2 — it is closed by the same change. Nothing above this line was rewritten.

## FR1-A (treasury B2 / frontend-conventions B1) — RecordPaymentModal rotates its key on every open

**Change (1 file, 8 lines):** `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:150-169` — `resetIdempotencyKey()` is now the last statement of the existing `isOpen` reset effect, and `resetIdempotencyKey` was added to the dep array (the hook's `reset` is a `useCallback` with `[]` deps, `useIdempotencyKey.ts:12-14`, so the identity is stable and the effect does not re-fire).

This mirrors the repo's own precedent for the same concept: `PaymentDetailPage.tsx:399` mints a new UUID on every dialog-open, locked by `treasury.test.tsx:1098` and `:1137`. Both hosts now treat "one dialog-open = one logical intent"; neither treats "one mount = one intent".

**New test file:** `apps/web/src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` (2 tests, 0 eslint warnings).

**(a) Red-first, new key per open.** `mints a DIFFERENT idempotency_key for two separate modal opens`. The first submit is `mockRejectedValueOnce` **on purpose**: a *successful* first submit already rotates the key in `onSuccess`, so a success-first version of this test would pass against the defective code and prove nothing. The rejection reproduces the gate's money-visible scenario exactly (server committed, response lost, operator reopens with a different amount). The test rerenders `isOpen={false}` then `isOpen` on the SAME element — never unmounting — because all three hosts gate the modal on `partner_id`, not on the open flag.

Red at `9c28de0f9`:
```
× RecordPaymentModal idempotency key lifetime > mints a DIFFERENT idempotency_key for two separate modal opens
  → expected '538a1149-265c-4c87-bd91-24a09c1860ba' not to be '538a1149-265c-4c87-bd91-24a09c1860ba'
 Test Files  1 failed (1)
      Tests  1 failed | 1 passed (2)
```
Green after the fix:
```
 ✓ src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx (2 tests) 806ms
 Test Files  1 passed (1)
      Tests  2 passed (2)
```

**(b) Never-reset-on-error falsifier.** `keeps the SAME idempotency_key when retrying after a failed submission from the same open`. Green at `9c28de0f9` as well — it is a *guard*, not a bug reproduction; its value is that it goes red under the mutation below.

## FR1-B (treasury §3 gap) — never-reset-on-error falsifiers on all three surfaces

The gate's falsifier was: "moving `resetIdempotencyKey()` into `onError` in all three production files would leave all 86 tests green." That is no longer true. Added, one per surface:

| surface | test |
|---|---|
| `RecordPaymentModal` | `__tests__/idempotencyKeyLifecycle.test.tsx` — `keeps the SAME idempotency_key when retrying after a failed submission from the same open` |
| `PaymentForm` | `PaymentForm.test.tsx` — `PaymentForm idempotency key survives a failed request > reuses the SAME idempotency_key when retrying after a rejected POST` |
| `SplitPaymentForm` | `SplitPaymentForm.test.tsx` — `SplitPaymentForm idempotency key survives a failed request > reuses the SAME idempotency_key when retrying after a rejected POST` |

**Mutation proof (applied, run, reverted).** `resetIdempotencyKey()` inserted into `onError` on all three production files simultaneously — `RecordPaymentModal.tsx` (existing `onError`), `PaymentForm.tsx` (existing `onError`), `SplitPaymentForm.tsx` (mutation has no `onError`, so one was added):

```
× SplitPaymentForm idempotency key survives a failed request > reuses the SAME idempotency_key when retrying after a rejected POST
× RecordPaymentModal idempotency key lifetime > keeps the SAME idempotency_key when retrying after a failed submission from the same open
× PaymentForm idempotency key survives a failed request > reuses the SAME idempotency_key when retrying after a rejected POST
✓ RecordPaymentModal idempotency key lifetime > mints a DIFFERENT idempotency_key for two separate modal opens
 Test Files  3 failed (3)
      Tests  3 failed | 24 passed (27)
```

All three falsifiers bite. The rotation test (a) correctly stays green under this mutation — it asserts rotation-on-open, not survival-on-error; the two invariants are independent and each has its own test. Revert verified: `git diff --stat` on `PaymentForm.tsx` and `SplitPaymentForm.tsx` is empty, and `RecordPaymentModal.tsx` shows only the FR1-A hunk.

**One extra file touched (disclosed deviation D5):** `apps/web/src/hooks/useIdempotencyKey.ts` — **comment only, no code change**. The T11 doc block said "Only a consumer that has awaited a successful response calls `reset()`", which FR1-A now contradicts. It is amended to state the real contract: reset at an intent BOUNDARY (after an awaited success, or when a long-lived surface starts a new intent), never from an error path. Leaving a doc comment that forbids the call one line of production code now makes would invite a future "cleanup" that reintroduces B2. Zero eslint warnings before and after.

## FR1-C (treasury B1) — browser verification was NOT executed and is promotion-blocking

Stated plainly, because the gate is right that the earlier "Promotion-owed" list read as housekeeping rather than as a blocker:

1. **The plan's Step 6 browser probes were NOT run in this lane or in fix round 1.** No stack was available. Specifically un-run: (i) the throttled-network double-submit probe on all three surfaces (PaymentForm, SplitPaymentForm, RecordPaymentModal); (ii) the real-keyboard proof that `step="0.001"` accepts `"0.100" + "0.200"` against the total `"0.300"` and rejects `"0.100" + "0.199"`; (iii) opening payments from all three RecordPaymentModal hosts. **These are promotion-blocking, not optional.** No claim in this handback should be read as browser-verified.

2. **Why deviation D1 makes (ii) load-bearing.** D1 replaced `userEvent.type` with `fireEvent.change` because `userEvent.type` on a jsdom `input[type=number]` emits `"0.1"` for the keystrokes `0.100` — the trailing zeros are unrepresentable (probed: `TYPE_EMITTED ["0","0.1"]` vs `CHANGE_EMITTED ["0.100"]`). `fireEvent.change` sets `target.value` directly and bypasses the browser's own number-input value sanitiser, and `MoneyInput.handleChange` (`MoneyInput.tsx:83-90`) then forwards it verbatim. So the jsdom suite proves **"DOM value → payload"**: if `.value` is `"0.100"`, the POST carries `"0.100"`. It does **not** prove **"keyboard → DOM value"**: that a real operator typing `0.100` into `type="number"` `step="0.001"` produces `.value === "0.100"` rather than a normalised or truncated string. Nothing in the jsdom harness can distinguish those two worlds; only probe (ii) can.

3. **`SplitPaymentForm` has NO active route, so probe (ii) has nowhere to run as shipped.** Its only wrapper, `SplitPaymentModal`, is deprecated with zero callers — `components/organisms/index.ts:18-21` (`// DEPRECATED: SplitPaymentModal functionality has been merged into RecordPaymentModal`); `rg '<SplitPaymentModal'` finds only the component's own JSDoc example. The only web writer of `POST /documents/{id}/split-payment` is `SplitPaymentForm.tsx:101` itself. Therefore, before promotion, ONE of the following must happen and be recorded:
   - **(A)** mount `SplitPaymentForm` behind a temporary harness route (or Storybook entry), run probe (ii) there, and say in the promotion record that the evidence came from a harness and not a production route; **or**
   - **(B)** downgrade the claim explicitly: the `"0.300"` exact-match contract is **unit-level only, on an unreachable surface**, with no browser evidence — acceptable only because the surface has no production caller today, and it must be re-probed before `SplitPaymentForm` is ever revived.

   Until (A) or (B) is recorded, the three-decimal claim for the split surface is unproven at the keyboard leg. Note this does not affect the two reachable surfaces: PaymentForm and RecordPaymentModal both have live routes and probe (i)/(iii) can run against them directly.

## Fix-round verification

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
      Tests  90 passed (90)
```
86 → 90: +2 RecordPaymentModal lifecycle, +1 PaymentForm falsifier, +1 SplitPaymentForm falsifier.

```
$ pnpm typecheck
> tsc --noEmit
(no output — exit 0)
```

**eslint, per file, fix round vs `9c28de0f9`.** Baselines produced by `git show 9c28de0f9:<path>` into temporary copies inside `src/`, linted, then deleted (tracked tree verified clean afterwards).

| file | at `9c28de0f9` | after fix round | Δ |
|---|---|---|---|
| `components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | E0 W25 | E0 W25 | 0 |
| `components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` | (new file) | E0 W0 | 0 |
| `features/treasury/PaymentForm.test.tsx` | E0 W5 | E0 W5 | 0 |
| `features/treasury/SplitPaymentForm.test.tsx` | E0 W9 | E0 W9 | 0 |
| `hooks/useIdempotencyKey.ts` | E0 W0 | E0 W0 | 0 |
| **total** | **E0 W39** | **E0 W39** | **0** |

Per-rule tallies are identical before and after on every file — not merely the totals. **This fix round adds zero errors and zero warnings**, including the new test file. Three deliberate choices got it to zero rather than the naive +11: the new POST-body reader narrows through `unknown` (`typeof` + `in` guards) instead of an `as` cast, so no `no-unsafe-type-assertion`; `await act(async () => { …; await Promise.resolve() })` keeps the `async` callback honest, so no `require-await`; and the lifecycle test's `mockApiGet` returns `Promise.resolve(...)` rather than being `async` with no `await`. Note this does not retire the frontend-conventions gate's M3 — the +8 net delta it measured is against the *pre-lane* base `a97631051` and is unchanged by this round.

**Not addressed in this round (out of the fix brief's scope, flagged for the orchestrator):**
- frontend-conventions **M1** (PaymentForm reuses the key across an *edited* payload after a failed submit) and **M2** (same on SplitPaymentForm, which additionally renders no error at all on a failed split). Both are MAJOR, both are real, and both are a different fix from FR1-A: they ask the key to be scoped to the *submit intent* (rotate on first field mutation after a failed attempt, or derive from the payload) rather than to the dialog-open. That is a design decision affecting all four idempotency producers, not a one-line effect change, and it needs an explicit ruling. FR1-A does not close them.
- frontend-conventions **M3** (lint delta disclosure vs the pre-lane base) and the minors m1-m6, and treasury **NB-1..NB-7**.

---

# Fix round 2 (2026-09-04)

Response to `docs/superpowers/reviews/2026-09-04-request-hygiene-t12-gate-frontend-conventions.md` (**REJECT**) — its BLOCKER B1 was closed by fix round 1; this round closes the three MAJORs **M1**, **M2**, **M3** and folds the minor **m2**. Nothing above this line was rewritten. Web only; no `apps/api` file was touched.

**Orchestrator ruling implemented (all three surfaces):** *an idempotency key belongs to ONE submit intent.*
- Same payload retried after a failure → **same key** (replay is correct).
- Payload **edited** after a failed attempt → new intent → **new key**. A visible second payment is the correct outcome if the first actually committed; a silent replay presented as success is not.

## FR2-A (M1 / M2) — the key now rotates on the first payload edit after a failed attempt

One mechanism, three surfaces. Each component gets a `hadFailedAttemptRef` set in `onError` and a `startNewIntentOnPayloadEdit()` helper that, **only while the flag is set**, clears it and calls `resetIdempotencyKey()`. `onSuccess` clears the flag before its existing `reset()`; `RecordPaymentModal`'s `isOpen` effect clears it alongside its existing per-open rotation.

| surface | file | payload-edit seams wired |
|---|---|---|
| `PaymentForm` | `features/treasury/PaymentForm.tsx` | **all RHF fields** via a `watch(cb)` subscription effect (amount, method, repository, partner, date, reference, notes, instrument…), plus the non-RHF payload state: withholding enabled / rate / transaction-type (wrapped setters) and allocation method / manual allocations (wrapped handlers) |
| `SplitPaymentForm` | `features/treasury/SplitPaymentForm.tsx` | `addPaymentLine`, `removePaymentLine`, `updatePaymentLine` — the only writers of `paymentLines`, which is the whole `splits` body |
| `RecordPaymentModal` | `components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | `addPaymentLine`, `removePaymentLine`, `updatePaymentLine`, `updatePaymentLineMultiple` (so `confirmPaymentLine` too), `updateManualAllocation`, `changePaymentDate`, `changeExcessAllocationMethod` |

Deliberate non-rotations (derived values, not operator edits): `PaymentForm`'s withholding-preview auto-fill keeps the raw `setWithholdingRateState`; `RecordPaymentModal`'s `isOpen` reset effect writes state directly, not through the wrapped handlers.

Invariants preserved and still locked by test: unchanged retry keeps the key; success rotates; `RecordPaymentModal` open rotates. The `useIdempotencyKey` doc block already describes reset-at-an-intent-boundary (amended in fix round 1), so no hook change was needed and none was made.

### Red-first, per surface

Each rotation test was written and run **before** its implementation:

```
× SplitPaymentForm … > mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
  → expected '69e1ea35-…' not to be '69e1ea35-…'
× SplitPaymentForm surfaces a failed submission > renders an error message when the split POST rejects and keeps the key
  → Unable to find an element with the text: treasury:splitPayment.submitFailed
 Tests  2 failed | 9 passed (11)

× PaymentForm … > mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
  → expected '08b8f6db-…' not to be '08b8f6db-…'
 Tests  1 failed | 16 passed (17)

× RecordPaymentModal … > mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
  → expected '98c2886d-…' not to be '98c2886d-…'
 Tests  1 failed | 2 passed (3)
```

The payload edit each test performs is one an operator can really make on that surface after a failure:
- `PaymentForm` — the amount `100` → `250` (and the second POST is asserted to carry `amount: '250'`).
- `SplitPaymentForm` — the line `reference` (a `splits[]` field that keeps the exact-total check satisfied, so the second POST actually goes out; the second POST is asserted to carry `reference: 'RETRY-1'`).
- `RecordPaymentModal` — `payment_date`. A confirmed line's amount sits inside `<fieldset disabled={line.confirmed}>` (`RecordPaymentModal.tsx:584`) and there is no un-confirm control, so the date is the edit the surface actually offers.

### Mutation proofs (applied, run, restored from a byte-copy backup)

**Mutation A — never flag a failed attempt** (delete `hadFailedAttemptRef.current = true` from all three `onError`s), i.e. the key can never rotate on an edit:

```
× SplitPaymentForm … mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
× RecordPaymentModal … mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
× PaymentForm … mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
 Tests  3 failed | 28 passed (31)
```
Exactly the three new rotation tests bite; the three unchanged-retry falsifiers stay green (the two invariants are independent).

**Mutation B — rotate from the error path** (add `resetIdempotencyKey()` beside the flag in all three `onError`s), re-run because the production files changed since fix round 1:

```
× SplitPaymentForm idempotency key survives a failed request > reuses the SAME idempotency_key …
× SplitPaymentForm surfaces a failed submission > renders an error message … and keeps the key
× RecordPaymentModal idempotency key lifetime > keeps the SAME idempotency_key …
× PaymentForm idempotency key survives a failed request > reuses the SAME idempotency_key …
 Tests  4 failed | 27 passed (31)
```
All four replay falsifiers still bite. Restores verified by `git diff --stat` and a green re-run.

## FR2-B (M3) — `SplitPaymentForm` now surfaces a failed submission

`SplitPaymentForm.tsx:109-116` — the mutation gains an `onError` that sets the existing `validationError` banner (`:301-305`, already `tokens.alert.error`) to a new key. No existing key fit: `treasury:splitPayment` had only `amountDoesNotMatch` / `incompleteLines` (client-side validation) and `success`; the nearest generic siblings (`smartPayment.errors.*`) are allocation-specific.

New key `treasury:splitPayment.submitFailed`, added to **en / fr / ar** (inserted in place; the rest of each file is byte-unchanged):

| locale | value |
|---|---|
| en | The split payment could not be recorded. Please try again. |
| fr | Le paiement fractionné n’a pas pu être enregistré. Veuillez réessayer. |
| ar | تعذّر تسجيل الدفعة المقسّمة. يرجى المحاولة مرة أخرى. |

The `onError` sets the banner **and** the failed-attempt flag but does **not** reset the key — locked by the new test `renders an error message when the split POST rejects and keeps the key`, which asserts the message is visible *and* that the unchanged retry reuses the key. (Mutation B above shows it goes red if a reset is added there.)

## FR2-C (M3, lint) — accurate per-file delta vs the lane base `a97631051`, and net **−1**

Baselines produced exactly as the gate did: `git show a97631051:<path>` into a sibling temp copy **inside `src/`** preserving the directory and the `.test.tsx` suffix (so the same eslint overrides apply), linted, then deleted. Tracked tree verified clean afterwards.

| file | at `a97631051` | after fix round 2 | Δ |
|---|---|---|---|
| `src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | W25 | W25 | 0 |
| `src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` | (new file) | W0 | 0 |
| `src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` | W13 | W13 | 0 |
| `src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx` | W1 | W1 | 0 |
| `src/features/treasury/PaymentForm.test.tsx` | W4 | W4 | 0 |
| `src/features/treasury/PaymentForm.tsx` | W13 | W13 | 0 |
| `src/features/treasury/SplitPaymentForm.test.tsx` | W1 | W2 | **+1** |
| `src/features/treasury/SplitPaymentForm.tsx` | W3 | W1 | **−2** |
| `src/features/treasury/__tests__/TreasuryTenantScope.test.tsx` | W0 | W0 | 0 |
| `src/features/treasury/treasury.test.tsx` | W15 | W15 | 0 |
| `src/hooks/useIdempotencyKey.ts` | W0 | W0 | 0 |
| **total** | **E0 W75** | **E0 W74** | **−1** |

The gate measured 75 → 83 (+8 net, 10 new, 2 removed). Nine of the ten new warnings are gone; the tenth is kept deliberately. No suppression comment, no `eslint.config` change, no baseline write anywhere in the diff.

How each was retired:
- `SplitPaymentForm.test.tsx:104,110` — `no-non-null-assertion` ×2 + `no-unnecessary-type-assertion` ×2: the `!` on `methodInputs[index]` / `amountInputs[index]` was genuinely unnecessary (that is what the paired rule was saying — `noUncheckedIndexedAccess` is off), so it is simply dropped. No guard needed, no behaviour change.
- `SplitPaymentForm.test.tsx:170,228,248`, `PaymentForm.test.tsx:474`, `RecordPaymentModal/__tests__/tenantScope.test.tsx:264` — `no-unsafe-assignment` ×5: `expect.stringMatching()` is typed `any`. Replaced with the already-present typed narrowing helper `postedIdempotencyKey(callIndex)` (added to `tenantScope.test.tsx` too), so the object literal stays exactly-typed, plus a separate `expect(postedIdempotencyKey(0)).toMatch(UUID_REGEX)` line. Assertion strength is unchanged: the exact-object comparison still proves no extra field rides along, and the UUID shape is still asserted — it just moved to its own line.
- `SplitPaymentForm.test.tsx:198→211` — `no-deprecated`: **KEPT, deliberately, one warning.** The rule fires on every *reference* to the deprecated `SplitPaymentModalProps` (probed: a direct annotation, a `type X = …` alias, an `import('…').SplitPaymentModalProps` qualified reference and a `Pick<…>` each warn once; the *uses* of the alias do not). The test's whole purpose is to lock the deprecated wrapper's `totalAmount: string` contract — the plan requires it and its `@ts-expect-error` is consumed (gate flip 2 → `TS2578`). There is no way to name the symbol without the warning, and a suppression comment is out of bounds. So the lane keeps exactly one intentional warning and still lands **below** the base total.

**No new warning was introduced by this round's own code** — the two production helpers, the wrapped setters, the `watch` subscription effect and all four new tests lint at zero.

### Also checked (unchanged from base, no lane attribution)

- `pnpm audit:design-system` → `796 acknowledged, 15 new, 11 stale`, identical to the gate's m1 base debt (`ImportWizardPage` ×14, `UnmappedUnitTextsPanel` ×1). **Note for the reviewer:** the baseline fingerprints a raw control by its JSX text, so an earlier draft that added `startNewIntentOnPayloadEdit()` *inside* the withholding-disable `<button>`'s inline `onClick` moved a baselined entry and reported `16 new / 12 stale` for the same pre-existing button. That is why the withholding rotation is wired through **wrapped setters** (`setWithholdingEnabled` → `setWithholdingEnabledState`, etc.) instead of edited call sites: every existing call and the JSX around it stays byte-identical, and the audit returns to its base numbers. `tools/audit-design-system-baseline.json` is **not** in the diff.
- `pnpm audit:keys` → `0 acknowledged, 1 new` (`src/features/uom/hooks/useUnits.ts:53`) — base debt, not a Task 12 file.
- `pnpm audit:i18n:local` → 64 NEW gaps, **none in `treasury`** (`uom` and one `fr|import` plural). The three new keys are complete in en / fr / ar.

### react-doctor (gate m5) — no regression, and the count went DOWN

The repo's non-blocking `pre-commit` hook runs `react-doctor --staged --fail-on warning`, which fails on **any** warning, so it prints its notice on every commit touching these long-standing components. Measured absolutely on the three production files, lane base `a97631051` vs this round:

```
base a97631051 : Score 87/100 — 24 issues (Maintainability 10, Bugs 10, Performance 4)
fix round 2    : Score 88/100 — 19 issues (Maintainability  5, Bugs 10, Performance 4)
```

Every remaining finding is a pre-existing category the gate already ruled out of scope under m5 (`no-high-complexity-react-function` / `no-giant-component` on `RecordPaymentModal.tsx` and `PaymentForm.tsx`, `prefer-useReducer`, `rerender-lazy-state-init`, `no-adjust-state-on-prop-change` ×5 in the modal's pre-existing `isOpen` effect, `js-set-map-lookups`, `exhaustive-deps` ×4, `js-combine-iterations`). **None points at code this round added** — the `watch` subscription effect (`PaymentForm.tsx:609-612`) is not among the four `exhaustive-deps` hits, and neither helper nor any wrapped setter is flagged.

## FR2-D (m2) — the load-bearing relaxed `useCurrency` mock is now labelled

`SplitPaymentForm.test.tsx:31-36` carries a comment saying the identity `format` is what makes the `/^0\.000$/` assertion a falsifier rather than a tautology, that the real formatter would round the `-5.55e-17` float residue to `0,000 TND` and let the old float path pass, and that the mock must not be made faithful without replacing that regression guard.

## Fix-round-2 verification

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
```
90 → 94: +2 `SplitPaymentForm` (rotation-on-edit, error surface), +1 `PaymentForm` (rotation-on-edit), +1 `RecordPaymentModal` (rotation-on-edit).

```
$ pnpm vitest run src/features/treasury src/components/organisms/RecordPaymentModal \
    src/components/organisms/SplitPaymentModal src/hooks
 Test Files  62 passed (62)
      Tests  387 passed (387)
```

```
$ pnpm typecheck
> tsc --noEmit
(no output — exit 0)
```

No vitest worker left behind (`ps aux | grep -c '[n]ode (vitest'` → 0).

## Still owed after this round

- **The browser legs remain UN-RUN and promotion-blocking** — FR1-C stands verbatim and is not weakened by this round. In particular probe (iii), the open/fail/close/reopen cycle on the three `RecordPaymentModal` hosts, is now joined by a fourth case worth driving by hand: **fail → edit → resubmit**, on `PaymentForm` and `RecordPaymentModal`, confirming a *second, visible* payment (the ruling's intended outcome) rather than a replayed success panel.
- Frontend-conventions minors **m1** (base lint debt), **m3** (glossary row for "idempotency key" + the four hand-rolled producers), **m4** (typecheck flip-1 transcript completeness), **m5**, **m6** — not addressed; m3 is a Phase B convergence item.
- Treasury **NB-1..NB-7** — not addressed. NB-7's count is superseded by the accurate table above; NB-3 overlaps m3.

---

# Fix round 3 (2026-09-04) — treasury r2 F1/F2/F3, frontend-conventions r2 B1'/M1'

Both re-gates returned CHANGES on the same two defects (rotation keyed on an unstable
dependency; `watch` unable to tell an operator edit from a programmatic write) plus a
missing falsifier for the pre-attempt guard. Web only. Base for the lint table is still
`a97631051`; this round starts from `04b34cdcc`.

## FR3-A (treasury F1 / conventions B1') — the per-open rotation now fires on the open TRANSITION

`RecordPaymentModal.tsx:150` adds `wasOpenRef`, and the rotation moves OUT of the
form-reset effect into its own effect at `RecordPaymentModal.tsx:193-202` with deps
`[isOpen, resetIdempotencyKey]` — both stable. The form-reset effect
(`RecordPaymentModal.tsx:166-178`) is otherwise byte-unchanged and keeps its
`[isOpen, prefill, resetIdempotencyKey]` deps, exactly as directed: the three hosts are
NOT touched.

Both gates' probes are landed as a permanent regression test, using the production shape:
the module-level `PREFILL` constant is gone and every render/rerender in
`idempotencyKeyLifecycle.test.tsx` now passes `makePrefill()` — a FRESH object literal,
what `InvoiceDetailPage.tsx:899` / `SalesOrderDetailPage.tsx:784` /
`PurchaseOrderDetailPage.tsx:713` actually pass. The fixture comment that previously
justified the hoisted constant is replaced by one explaining why a fresh object is
mandatory (`idempotencyKeyLifecycle.test.tsx:84-91`).

**Observation channel — why the new test counts uuid mints instead of comparing two posted keys.**
A key rotation is only visible through a POST, and this surface WIPES its form whenever
`prefill` changes identity (pre-existing behaviour, see the follow-up below). After the
re-render the operator must re-enter the line either way, and that re-entry legitimately
rotates the key — so with the fix and with the defect the second POST carries *the same
ordinal uuid*, and no comparison of posted keys can tell them apart. Enumerated:

| step | with the fix | with the defect |
|---|---|---|
| mount | key `u0`, line `u1`, rotate `u2` | key `u0`, line `u1`, rotate `u2` |
| POST #1 | `u2` | `u2` |
| parent re-render (fresh prefill) | line `u3` only | line `u3`, **rotate `u4`** |
| operator re-entry | rotate `u4` (flag still set) | no rotation (flag was cleared) |
| POST #2 | `u4` | `u4` |

`installUuidRecorder` (`idempotencyKeyLifecycle.test.tsx:107-125`) therefore stubs
`crypto.randomUUID` with a deterministic sequence and the test asserts (a) the re-render
window minted **exactly one** uuid — the replacement payment-line id — and (b) the key the
retry carries was NOT minted inside that window.

## FR3-B (treasury F2 / conventions M1') — programmatic RHF writes no longer rotate the key

**The gate's prescribed discriminator does not work on this RHF version — measured, not argued.**
Instrumenting the subscription with `watch((_v, { name, type }) => console.log(...))` and
driving the real RIB-derived `setValue('bank_iban', …)`:

```
WATCH_EVENT {"name":"bank_account","type":"change"}   <- operator keystroke
PHASE rerender-TN                                      <- company config resolves, NO operator input
WATCH_EVENT {"name":"bank_iban","type":"change"}       <- setValue, reported as a CHANGE
WATCH_EVENT {"name":"bank_iban"}                       <- setValue, values-only notification
```

react-hook-form 7.67.0 reports a programmatic `setValue` as `type: 'change'`, byte-identical
to a keystroke, so `if (type === 'change')` alone leaves M1' open. (Proven again as mutation
D below.) The gate's own alternative — "set a `suppressRotationRef` around the prefill
`reset`" — is what is implemented, generalised to every write the component performs itself:

- `PaymentForm.tsx:330-352` — `programmaticWriteRef` + `writeProgrammatically(write)`
  (sets the ref, runs the write, clears it in a `finally`; RHF's notifications are emitted
  synchronously inside the write).
- Routed through it: the RIB-derived IBAN set/clear (`PaymentForm.tsx:357`, `:361`), all four
  document-prefill `reset()` branches (`PaymentForm.tsx:415-465`), and the
  method-compatibility repository clear (`PaymentForm.tsx:540`).
- Deliberately NOT routed through it: the picker callbacks (`PaymentForm.tsx:1113-1126`,
  `:1479`) — a bank/partner pick IS an operator payload edit and must rotate.
- The subscription at `PaymentForm.tsx:648-653` keeps `type !== 'change'` as directed and adds
  the programmatic guard. Honest note: mutation E shows the `type` narrowing carries no
  unique coverage under the current tests (a blur does not notify this subscription at all —
  probed), so it is a cheap correctness narrowing, not the load-bearing filter.

**Non-RHF setters re-checked, as asked.** The withholding trio and the allocation handlers
(`PaymentForm.tsx:694-712`) are reachable only from operator controls. The one
preview-driven write, `setWithholdingRateState` at `PaymentForm.tsx:609-612`, uses the RAW
setter and is deliberately left un-wrapped — that is treasury NB-9 and it stays as it was.

**`SplitPaymentForm` and `RecordPaymentModal` need no equivalent:** neither uses
react-hook-form at all (`grep -n "useForm\|watch(" ` on both files returns nothing). Their
payload state is plain `useState` written only from event handlers, all of which already call
`startNewIntentOnPayloadEdit`.

The new test drives the real production path rather than poking RHF directly: the company
config query resolves while the form is mounted, `country_code` flips `'' -> 'TN'`, the RIB
the operator already typed becomes valid and the form writes `bank_iban` through `setValue`.
`bank_iban` does not ride in the POST body, so the two requests are byte-identical — a genuine
unchanged retry, which must replay.

## FR3-C (treasury F3) — the pre-attempt guard now has a falsifier on all three surfaces

One test per surface, each asserting that the FIRST submit still carries the key minted at
mount even though the operator filled several payload fields first:

- `PaymentForm.test.tsx` — "carries the MOUNT key on the first submit even though the payload was edited"
- `SplitPaymentForm.test.tsx` — same name
- `idempotencyKeyLifecycle.test.tsx` — "does NOT rotate the key when the payload is edited BEFORE any submit attempt"

`SplitPaymentForm.tsx:65` passes an EAGER array literal to `useState`, so it mints a fresh
line-id uuid on every render; only `minted[0]` (from `useIdempotencyKey`, `:59`) is meaningful
there and the test asserts against that one value. The other two surfaces mint nothing per
render, so they additionally assert that the pre-attempt edit window minted no uuid at all.

## Fix-round-3 mutation table (each mutation applied from a byte copy, then restored; tree verified clean)

| # | mutation | result |
|---|---|---|
| C | revert `RecordPaymentModal.tsx` to `04b34cdcc` (rotation back inside the `prefill`-dependent effect) | **1 failed** — only "does NOT rotate the key when the PARENT re-renders…"; the other 4 stay green |
| D | delete `if (programmaticWriteRef.current) return`, keeping `type !== 'change'` | **1 failed** — only "keeps the SAME idempotency_key when a PROGRAMMATIC RHF write lands after a failed submit" (18 pass). This is the measurement that the gate's prescribed discriminator is insufficient. |
| E | delete `if (type !== 'change') return`, keeping the programmatic guard | **19 passed** — the `type` narrowing has no unique coverage; disclosed above rather than dressed up |
| F | delete `if (!hadFailedAttemptRef.current) return` from all three surfaces | **3 failed** — exactly the three new pre-attempt tests, 33 pass |

Pre-fix red transcripts (tests written first, production files at `04b34cdcc`):
`idempotencyKeyLifecycle` → `expected [ …(2) ] to have a length of 1 but got 2`;
`PaymentForm` → `expected '1b08f3f4-…' to be 'c9fe1a89-…'`.

## Fix-round-3 verification

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
      Tests  99 passed (99)          # 94 -> 99: +2 RecordPaymentModal, +2 PaymentForm, +1 SplitPaymentForm
```

```
$ pnpm vitest run src/features/treasury src/components/organisms/RecordPaymentModal \
    src/components/organisms/SplitPaymentModal src/hooks
 Test Files  62 passed (62)
      Tests  392 passed (392)        # was 387

$ pnpm typecheck            -> exit 0, no output
$ pnpm audit:design-system  -> 796 acknowledged, 15 new, 11 stale   (base numbers, unchanged)
$ pnpm audit:keys           -> 0 acknowledged, 1 new                (base debt: features/uom/hooks/useUnits.ts:53)
$ ps aux | grep -c '[n]ode (vitest'  -> 0
```

**eslint per file, base `a97631051` vs fix round 3** (baselines via `git show a97631051:<path>`
into `zzbase_`-prefixed sibling copies inside `src/`, linted in one invocation, deleted; tree
verified clean afterwards):

| file | base `a97631051` | fix round 3 | Δ |
|---|---|---|---|
| `components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | E0 W25 | E0 W25 | 0 |
| `components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` | (new) | E0 W0 | 0 |
| `components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` | E0 W13 | E0 W13 | 0 |
| `components/organisms/SplitPaymentModal/SplitPaymentModal.tsx` | E0 W1 | E0 W1 | 0 |
| `features/treasury/PaymentForm.test.tsx` | E0 W4 | E0 W4 | 0 |
| `features/treasury/PaymentForm.tsx` | E0 W13 | E0 W12 | **−1** |
| `features/treasury/SplitPaymentForm.test.tsx` | E0 W1 | E0 W2 | **+1** |
| `features/treasury/SplitPaymentForm.tsx` | E0 W3 | E0 W1 | **−2** |
| `features/treasury/__tests__/TreasuryTenantScope.test.tsx` | E0 W0 | E0 W0 | 0 |
| `features/treasury/treasury.test.tsx` | E0 W15 | E0 W15 | 0 |
| `hooks/useIdempotencyKey.ts` | E0 W0 | E0 W0 | 0 |
| **total** | **E0 W75** | **E0 W73** | **−2** |

W74 → W73. The single new removal is `react-hooks/incompatible-library` on
`PaymentForm.tsx` (rule-id diff measured, not inferred): reshaping the `watch` callback
retired it. No suppression comment, no `eslint.config` change, no baseline write — the diff
contains no `eslint-disable`, no `@ts-ignore`, and no new `as` cast (the deterministic-uuid
helpers are typed with the template-literal return type instead). The one kept warning is
still the deliberate `@typescript-eslint/no-deprecated` at `SplitPaymentForm.test.tsx:211`,
already accepted by the conventions gate.

## Follow-up recorded, NOT fixed in this round (deliberately not widened)

- **`RecordPaymentModal` wipes the operator's in-progress form on every parent re-render.**
  The form-reset effect still depends on `prefill`, which the three hosts pass as an inline
  literal, so a reconnect refetch clears the confirmed payment lines, the date and the notes
  mid-intent. That is pre-existing behaviour, outside this lane, and it is NOT cured by the
  rotation fix — with the key now retained the operator's re-entry becomes a real payload edit
  and rotates the key legitimately, so **the reconnect-during-a-lost-response scenario can still
  end in a second payment**. The real cure is `useMemo` on `prefill` in
  `InvoiceDetailPage.tsx:899`, `SalesOrderDetailPage.tsx:784` and
  `PurchaseOrderDetailPage.tsx:713` (the gate named it as the "equally valid, additionally
  cures the wipe" option). Worth its own small lane; it touches three feature files.
- Treasury NB-8 (`notes` typed into the modal is never sent), NB-9, NB-10, NB-11 and
  conventions m1/m3/m4/m5/m6 are unchanged from round 2.

## Still owed after this round

Unchanged and still promotion-blocking: the four browser legs of FR1-C plus the fifth the
r2 gates added (**fail → leave the surface untouched → let the network return → retry**,
confirming the retry carries the same key and books nothing new). Nothing in this round was
browser-verified.
