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
