# Gate — Request Hygiene Phase A, Task 12 (payment idempotency + double-submit lock) — TREASURY/MONEY half

- **Reviewer:** treasury-reviewer (adversarial, code-grounded)
- **Date:** 2026-09-04
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12`, branch `lane/rh-t12-payment-idempotency`
- **Range reviewed:** `a97631051..9c28de0f9` (impl `9a2b61775`, handback `9c28de0f9`) — 9 web files + 1 doc
- **Scope of this gate:** correctness of payment amounts and the idempotency contract end to end (web -> API). Component conventions are the frontend-conventions-reviewer's half.

## VERDICT: spec ⚠ (met on code, two plan-mandated verification steps unexecuted) + quality **CHANGES-REQUESTED**

No wrong-money defect and no auth/boundary defect was found. The bcmath conversion is correct, the string boundary is real and type-enforced, and the body-field idempotency contract is honoured by both controllers. Two items block: one real behavioural divergence in `RecordPaymentModal` against the repo's own established precedent for this exact concept, and the plan's Step-6 browser verification (which the lane's own D1 deviation makes load-bearing) is unexecuted.

---

## 1. Money path (rule 19) — VERIFIED CLEAN

**String boundary is real, not cosmetic.**
- `apps/web/src/features/treasury/SplitPaymentForm.tsx:44` — `totalAmount: string`.
- `apps/web/src/features/treasury/SplitPaymentForm.tsx:114-118`:
  ```ts
  const currentTotal = paymentLines.reduce(
    (sum, line) => bcadd(sum, line.amount === '' ? '0' : line.amount, 3),
    '0.000',
  )
  const remaining = bcsub(totalAmount, currentTotal, 3)
  ```
  `totalAmount` flows **directly** into `bcsub` — no `String(...)`, no `Number(...)`, no intermediate coercion. Confirmed by reading the file, not the diff.
- `apps/web/src/features/treasury/SplitPaymentForm.tsx:152` exact match `bccomp(currentTotal, totalAmount) !== 0`; `:157` per-line positivity `bccomp(line.amount, '0') <= 0`; `:197,199` colour driven only by `bccomp(remaining, '0')`.
- `apps/web/src/features/treasury/SplitPaymentForm.tsx:175` local formatter is `(amount: string): string => formatCurrencyHook(amount)`.
- `apps/web/src/features/treasury/SplitPaymentForm.tsx:193-205` — `data-testid="split-payment-remaining"` on the remaining `<div>`, child exactly `{formatCurrency(remaining)}`. Matches the plan's rev-10 requirement.
- `rg 'parseFloat|Math\.abs'` on `SplitPaymentForm.tsx` -> no matches (re-run by this gate, empty).

**Is the exact-match change safe across currency scales? YES — `bccomp` is scale-agnostic.**
- `apps/web/src/lib/decimal.ts:115-117`: `bccomp(a,b) => safeBig(a).cmp(safeBig(b))`. `Big.cmp` compares VALUES, not lexical scale, so `"11.00"` vs `bcadd("10.50","0.50",3) === "11.000"` -> `0`. The 2-decimal-currency worry is unfounded at the comparison level.
- `apps/web/src/lib/decimal.ts:50-52 / 65-67`: `bcadd`/`bcsub` are `Big.plus/minus(...).toFixed(scale)` with `Big.RM = 1` (half-up, `decimal.ts:15`) — no float anywhere.
- Display path is also float-free: `useCurrency().format` (`apps/web/src/hooks/useCurrency.ts:52-57`) -> `formatAmount` -> `apps/web/src/lib/format.ts:118-137` -> `formatDecimalAmount` (`format.ts:81-96`) whose only numeric primitive is `safeDecimal` (`format.ts:61-68`, a `Big` constructor). `formatCurrency("0.000")` never touches IEEE-754.
- `safeBig` (`decimal.ts:30-37`) silently maps garbage/empty to `0` — acceptable here because the form pre-filters `line.amount === ''` and `MoneyInput` is `type="number"`.

**Caller-side string typing is type-enforced, not just asserted.**
- `SplitPaymentModal.tsx:42` prop is `string`; `:122` is an unchanged pass-through; `:73` JSDoc de-floated.
- Two `@ts-expect-error` directives (`SplitPaymentForm.test.tsx:193,202`) are consumed at HEAD, and the handback documents both flip-back proofs producing TS2578 + TS2345. `pnpm typecheck` re-run by this gate: exit 0 (an unconsumed directive would itself be a TS2578 error, so a clean run IS the proof).
- `MoneyInput` passes the raw keystroke string through with no coercion (`apps/web/src/components/atoms/MoneyInput/MoneyInput.tsx:86-94`), so the three-decimal payload claim is structurally sound.

**No float regression introduced.** `RecordPaymentModal`'s pre-existing float block (its `precision/no-parsefloat-on-money` warnings) is untouched — confirmed: this lane's only edits to that file are the import at `:1`, the hook+ref at `:147-148`, the body field at `:328`, the reset at `:339`, and the lock at `:367-377`. B-7 debt intact and correctly out of scope.

---

## 2. Idempotency contract, web -> API — VERIFIED, with one untested seam

**Web side (all three surfaces read, not assumed):**

| Surface | key in body | reset only in onSuccess | lock set synchronously before mutate | released in onSettled |
|---|---|---|---|---|
| `PaymentForm.tsx` | `:683` `idempotency_key: idempotencyKey` (first field) | `:711` first statement of async `onSuccess`; no `onError` reset | `:753-755` | `:757` |
| `SplitPaymentForm.tsx` | `:103` | `:106` | `:169-170` | `:171` |
| `RecordPaymentModal.tsx` | `:328` | `:339` | `:374-376` | `:376` |

- `grep -n 'onError'` across the three: only `RecordPaymentModal.tsx:361` (a `console.error`), no `reset()`. The "key survives a failed request" invariant holds **by code reading**.
- `useIdempotencyKey` (`apps/web/src/hooks/useIdempotencyKey.ts:11,13`) — one UUID per mount, rotated only via `reset`. `useCallback` gives a stable identity.
- No axios interceptor competes for the header: `grep -rn 'Idempotency-Key' apps/web/src/lib/*.ts` -> empty. So the body field is the only channel and it cannot be shadowed by a differently-generated header key.

**Backend side (read, not remembered):**
- `PaymentController.php:135-152` `resolveIdempotencyKey()` — prefers `Idempotency-Key` header, falls back to `$request->input('idempotency_key')`, trims, returns null on empty. Same code at `MultiPaymentController.php:60-73`.
- Single deposit: `PaymentController.php:338` `store()` branches to `storeMultiple()` at `:346-348` when `payments` is present, else short-circuits at `:358-365` **before validation and before the write transaction**, returning `200` with the original payment. Persisted at `:961`.
- Multi batch: `PaymentController.php:1419` `storeMultiple()` short-circuits at `:1427-1433`; rows keyed `sprintf('%s:multi:%04d', $key, $index)` at `:1646-1647`.
- Split payment: `MultiPaymentController.php:119` `createSplitPayment()` short-circuits at `:126-133` before validation; `UniqueConstraintViolationException` recovery at `:212-221` re-reads and returns the committed batch (concurrent-retry race handled).
- Storage: `database/migrations/tenant/2026_07_08_150000_add_idempotency_key_to_payments.php:39-46` — `varchar(255)` + partial `UNIQUE (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL`. Company-scoped under db-per-tenant: correct. A 36-char UUID + `:multi:0000` = 47 chars, well inside 255.

**Same-key / same-body retry:** returns the SAME payment(s), no second `Payment` row, no second treasury movement. Proven by `tests/Feature/Treasury/PaymentIdempotencyTest.php:142` (single, asserts one payment AND one movement) and `:324` (storeMultiple batch), and `tests/Feature/Treasury/MultiPaymentSpineTest.php:265-297` for split-payment (which additionally proves the short-circuit precedes validation — a naive replay would fail the split-total check once the balance is 0).

**Same-key / DIFFERENT-body retry:** the backend has **no request fingerprint** — `findPaymentByIdempotencyKey` / `findSplitPaymentBatchByIdempotencyKey` key on `(tenant, company, idempotency_key)` only and return the ORIGINAL regardless of what the retry body says. This is "ignore, replay original", not "reject". That is a pre-existing, deliberate Task-16b/19 design and is NOT a web/API mismatch — but it is exactly what makes finding **B2** below bite.

**Untested seam (see NB-1):** every backend idempotency test supplies the key via the `Idempotency-Key` HEADER (`PaymentIdempotencyTest.php:163,169,220,225,352,358`; `MultiPaymentSpineTest.php:286,293`). The web sends it **only in the body**. The body branch (`PaymentController.php:139-141`, `MultiPaymentController.php:64-66`) is exercised by no test on either side of the wire.

---

## 3. Tests

**Red-first evidence:** present for all three surfaces in the handback (§Step 1 PaymentForm 2-calls-not-1; §Step 2 four SplitPaymentForm reds including the 0.001-shortfall acceptance under the old tolerance; §Step 5 RecordPaymentModal 2-calls-not-1). I could not independently re-produce red without editing a read-only worktree; the reds are internally consistent with the code I read (the old `Math.abs(... ) > 0.01` at the pre-image of `SplitPaymentForm.tsx:150` did accept a 0.001 shortfall).

**The `"0.000"` falsifier bites.** `SplitPaymentForm.test.tsx:254` asserts `toHaveTextContent(/^0\.000$/)` on `split-payment-remaining`. Under the float path `String(0.3 - 0.30000000000000004)` renders `-5.551115123125783e-17`; the handback records an empirical revert-and-rerun proving exactly that one assertion fails. The `useCurrency` mock is `String(value)` (`SplitPaymentForm.test.tsx:38`), so the assertion reads the raw bcmath output — correct choice for a falsifier.

**The 0.001 shortfall rejection** (`SplitPaymentForm.test.tsx:263-271`) asserts BOTH the error text and `expect(mockApiPost).not.toHaveBeenCalled()` — a data-meaning assertion, not a status code. Good.

**`@ts-expect-error` proofs** (`SplitPaymentForm.test.tsx:189-208`) are genuine compile-time gates; flip-back diagnostics recorded.

**Ruling on deviation D1 (`fireEvent.change` instead of `userEvent.type` on `input[type=number]`):** ACCEPTED as the only way to express the assertion in jsdom, but it **does** weaken the three-decimal proof, and the lane says so itself. `fireEvent.change` sets `target.value` directly and bypasses the browser's own number-input value sanitiser; `MoneyInput` (`MoneyInput.tsx:86-94`) then passes it through verbatim. So the test proves "if the DOM value is `0.100`, the payload is `0.100`" — it does NOT prove a real keyboard produces a DOM value of `0.100` on `type="number"` with `step="0.001"`. That residual is precisely what the plan's Step-6 browser probe covers, and that probe was not run (see B1). D1's helper comment (`SplitPaymentForm.test.tsx:101-107`) documents the reason honestly.

**Gap (non-plan-mandated but house-precedent):** no test at any of the three surfaces asserts the key SURVIVES an error and is REUSED on retry, nor that it ROTATES after success. `grep -n 'mockRejected|reject\(' ` across the four payment test files -> only `treasury.test.tsx:1139`, which belongs to the refund flow. Falsifier: moving `resetIdempotencyKey()` into `onError` in all three production files would leave all 86 tests green. The repo already has the canonical pair for this exact concept 30 lines away — `treasury.test.tsx:1098` ("mints a DIFFERENT refund_request_id for two separate dialog-opens") and `treasury.test.tsx:1137` ("keeps the SAME refund_request_id when retrying after a failed submission from the same dialog-open").

---

## 4. Blast radius

- `rg -n '<SplitPayment(Form|Modal)' apps/web/src` -> 12 hits, all reconciled: 1 JSDoc example (`SplitPaymentModal.tsx:65`), 1 pass-through (`SplitPaymentModal.tsx:120-122`), 10 test sites all converted to strings. No unconverted caller.
- **`SplitPaymentModal` has no active caller anywhere.** `grep -rn 'SplitPaymentModal' apps/web/src` outside its own directory returns only `components/organisms/index.ts:18-21` (marked `// DEPRECATED: SplitPaymentModal functionality has been merged into RecordPaymentModal`) and the new type-boundary test. `POST /documents/{id}/split-payment` has exactly one web writer: `SplitPaymentForm.tsx:101`. **The entire SplitPaymentForm money rewrite therefore has zero production blast radius today** — it is dead-surface hardening. That materially lowers the risk of the tolerance behaviour change AND means the exactness change cannot be validated in the browser through any real page.
- `RecordPaymentModal` hosts confirmed at `InvoiceDetailPage.tsx:894`, `SalesOrderDetailPage.tsx:780`, `PurchaseOrderDetailPage.tsx:709`. All three render it gated on `partner_id`, **not** on `showPaymentModal` — the modal stays MOUNTED across close/reopen. This is load-bearing for B2.
- `SupplierInvoiceDetailPage` keyless `/payments` writer: confirmed untouched, correctly out of scope (plan Step 4 note, Phase B B-7).
- `PaymentDetailPage` refund/partial-refund writers still use their own `refundRequestId` state (`PaymentDetailPage.tsx:164,231,399`) — untouched, but see NB-3.

---

## 5. `crypto.randomUUID()` secure-context ruling

`useIdempotencyKey.ts:11,13` calls `crypto.randomUUID()` with **no fallback**; on a plain-HTTP non-localhost origin `crypto.randomUUID` is `undefined` and the hook throws at render. Blast radius is smaller than the handback implies: `SplitPaymentForm.tsx:66,124` and `RecordPaymentModal.tsx:165` (via `createNewPaymentLine`, invoked from the `isOpen` effect) **already** called `crypto.randomUUID()` before this lane, so two of the three surfaces were already hard-dependent. `PaymentForm` is the only genuinely new exposure, and `PaymentDetailPage.tsx:399` shows the dependency is already pervasive in treasury.

**Ruling: promotion precondition, not a blocker.** Confirm staging and production origins are HTTPS before promoting; a `getRandomValues`/`Math.random` fallback belongs in the T11 `useIdempotencyKey` lane, not here (adding it here would widen T12 past rule 4 and past the hook's own gated contract).

---

## BLOCKING FINDINGS

### B1 — [Important, gate-blocking] Plan Step 6's browser verification is unexecuted, and D1 makes it load-bearing
`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` Task 12 Step 6 mandates: throttled-network double-submit probes on all three active surfaces; proof that `"0.300"` accepts `"0.100" + "0.200"` and rejects `"0.100" + "0.199"` in a real browser; and opening payments from all three RecordPaymentModal hosts. The handback's "Promotion-owed" §1-3 states none were run (no stack available).

Why it matters: deviation D1 replaced `userEvent.type` with `fireEvent.change` on an `input[type=number]` precisely because the harness cannot express `"0.100"`. That means the jsdom suite no longer proves the operator-keystroke -> payload leg of the three-decimal contract; the browser probe is now the *only* evidence for it, and the handback itself says so ("The browser probe owed at promotion is the definitive check for this one").

Falsifying scenario: if a real `type="number"` + `step="0.001"` input normalises or truncates the typed value differently from `fireEvent.change`, every jsdom assertion still passes while the posted split amounts differ from what the operator typed. Nothing in the lane can currently distinguish those worlds.

Fix before merge: run the three throttled double-submit probes and the `0.300` accept/reject probe in a browser. Note that the `0.300` probe requires reaching `SplitPaymentForm`, which has **no active route** (see §4) — so either drive it through a temporary harness/Storybook mount and say so, or downgrade the claim to "unreachable surface, contract proven only in jsdom" explicitly in the handback.

### B2 — [Important, gate-blocking] `RecordPaymentModal.tsx:147` vs `:150-162` — the idempotency key does not rotate when the modal re-opens, while the host keeps it mounted
`useIdempotencyKey()` is called once per **mount** (`RecordPaymentModal.tsx:147`). The `isOpen` effect (`RecordPaymentModal.tsx:150-162`) resets seven pieces of form state — `paymentDate`, `notes`, `paymentLines`, `excessAllocationMethod`, `manualAllocations`, `validationError`, `showSuccess`, `successData` — but **not** the key. All three hosts render the modal gated on `partner_id`, not on `showPaymentModal` (`InvoiceDetailPage.tsx:894`, `SalesOrderDetailPage.tsx:780`, `PurchaseOrderDetailPage.tsx:709`), so it never unmounts between opens. One key therefore spans an unbounded number of distinct payment intents.

Falsifying scenario (money-visible):
1. INV-1, outstanding 1000. Operator opens the modal, enters and confirms a 400 cash line, submits. The request commits server-side; the response is lost (timeout / proxy 502). `onError` fires, key `K` retained, lock released.
2. Operator closes the modal and reopens it. The effect wipes the form; the key is still `K`.
3. Operator enters 1000 and submits. `PaymentController.php:1427-1433` finds `K:multi:0000` and returns the ORIGINAL 400 batch with HTTP 200 (no body fingerprint exists — §2).
4. `onSuccess` fires: the modal shows its SUCCESS panel and invalidates the caches. The operator is told the payment succeeded; the 1000 they entered was never recorded. Outstanding is 600, not 0.

The repo has already ruled on this exact concept in the same feature directory: `PaymentDetailPage.tsx:399` mints a **new** UUID on every dialog-open (`setRefundRequestId(crypto.randomUUID())`), clears it on success (`:231`), and both behaviours are locked by tests at `treasury.test.tsx:1098` and `treasury.test.tsx:1137`. `RecordPaymentModal` now implements the same concept with a different, weaker lifecycle and neither test.

Fix before merge (2 lines, inside the block the lane already edits): add `resetIdempotencyKey()` to the `isOpen` effect body at `RecordPaymentModal.tsx:150-162` (and add `resetIdempotencyKey` to the dep array), then add the two lifecycle tests modelled on `treasury.test.tsx:1098` and `:1137` — (a) two modal opens produce two different `idempotency_key` values, (b) a retry after a rejected POST from the same open reuses the same `idempotency_key`. Test (b) is also the missing falsifier for "never reset on error" on all three surfaces (§3 gap): today, moving `resetIdempotencyKey()` into `onError` keeps all 86 tests green.

---

## NON-BLOCKING FINDINGS

- **NB-1 [Important] The body-field idempotency branch has zero test coverage on either side.** Web sends `idempotency_key` in the body only; every backend test uses the header (`PaymentIdempotencyTest.php:163,169,220,225,352,358`; `MultiPaymentSpineTest.php:286,293`). The fallback at `PaymentController.php:139-141` / `MultiPaymentController.php:64-66` is correct by reading, but a future refactor of `resolveIdempotencyKey` could delete the body branch and no test would fail while all three web payment surfaces silently stop deduplicating. Suggested: one backend feature test per controller that posts the key in the BODY and asserts single-creation on replay. (Alternatively, have the web send the header — but that is a T11 hook change, not this lane.)
- **NB-2 [Minor] FE validates a scale-3-rounded total but posts the raw line strings** — `SplitPaymentForm.tsx:114-117` folds with `bcadd(..., 3)` (half-up, `decimal.ts:15`) while `:165` posts `line.amount` verbatim. `0.1004 + 0.1996` folds to exactly `"0.300"` and passes the client match check, then hits the backend regex `^\d+(\.\d{1,3})?$` (`MultiPaymentController.php:143`) and 422s with "Split amount must have at most 3 decimal places." Not a money error (the backend refuses), but an avoidable dead end. Suggested: reject `line.amount` with more than the currency's decimals in the client validation loop at `:156-158`.
- **NB-3 [Important, convention 11 — one surface per concept] "Client request-level idempotency key" now has two FE implementations with different lifecycles and no glossary row.** `useIdempotencyKey` (per-mount, reset on success) vs `PaymentDetailPage.tsx:164,231,399` `refundRequestId` (per-dialog-open, cleared on success). `grep -in 'idempot' docs/glossary.md` -> no match. Both write `/payments*` endpoints. Declare the noun in `docs/glossary.md` and converge `PaymentDetailPage` onto the hook (or record the divergence as deliberate). This is pre-existing, but T12 made the hook the dominant surface without reconciling the sibling — and B2 is a direct consequence of the two lifecycles disagreeing.
- **NB-4 [Minor] `SplitPaymentForm` ignores its own `currency` prop.** `SplitPaymentForm.tsx:53` destructures it as `_currency` and unused; the `MoneyInput` step (`:251`) and the formatter (`:175`) both come from `useCurrency()` (company currency). A foreign-currency document would get the company's decimals. Pre-existing, unchanged by this lane, and currently unreachable (§4) — but it means the exact-match check is scaled to the company currency, not the document currency, if the surface is ever revived.
- **NB-5 [Minor] `SplitPaymentModal.tsx:73` JSDoc now reads `totalAmount={document.balance_due}`,** but `apps/web/src/types/document.ts:132` types `balance_due: string | null`. The de-floating is right; the example would not compile against the nullable document type. Suggest `document.balance_due ?? '0'`.
- **NB-6 [Minor] `SplitPaymentForm` can submit a single split; the endpoint requires two.** `MultiPaymentController.php:139` validates `'splits' => ['required','array','min:2']`, and `SplitPaymentForm.test.tsx:167` asserts a one-element `splits` POST as the happy path. Pre-existing FE/BE mismatch, not introduced here; recorded so it is not mistaken for a T12 regression later.
- **NB-7 [Minor] Handback under-counts the new eslint warnings.** It claims 2 (+2 paired) new warnings; the actual new-line attribution is 9: `SplitPaymentForm.test.tsx:104,110` (`no-non-null-assertion` + `no-unnecessary-type-assertion`, 4), `SplitPaymentForm.test.tsx:170,228,248` and `PaymentForm.test.tsx:474` and `RecordPaymentModal/__tests__/tenantScope.test.tsx:264` (`no-unsafe-assignment`, 5 — from the `expect.stringMatching` matchers), plus `SplitPaymentForm.test.tsx:198` (`no-deprecated`, from importing `SplitPaymentModalProps`). All warnings, all in tests, zero errors. Not a defect; correcting the record.

---

## What held up (things I tried to break and could not)

- `bccomp("11.000", "11.00") === 0` — the exactness change is NOT scale-sensitive; 2-decimal currencies are unaffected at the comparison level (`decimal.ts:115-117`, `Big.cmp`).
- No float touches money anywhere in the rewritten path, including the display leg (`format.ts:61-96` is `Big`-only).
- `totalAmount` genuinely reaches `bcsub` as a string — proven by the handback's flip-back TS2345 at `SplitPaymentForm.tsx:118`, not merely by prop typing.
- The lock is set synchronously before `mutate` on all three surfaces and released in a per-call `onSettled`, so a failed payment stays retryable; a second synchronous submit is dropped before React can re-render pending state.
- `reset()` appears in `onSuccess` only on all three surfaces; no `onError` reset exists.
- No axios interceptor injects a competing `Idempotency-Key` header that would shadow the body field.
- The backend replay for all three shapes (single, storeMultiple batch, split batch) is short-circuited BEFORE validation and BEFORE the write transaction, and the split path additionally recovers from a concurrent unique-constraint race (`MultiPaymentController.php:212-221`).
- `RecordPaymentModal`'s B-7 float block is genuinely untouched.
- Second-of-everything (convention 09): this diff touches no catalogue entity and adds no unique key — not applicable.

---

## Commands run by this gate (worktree `rh-t12`, `apps/web`, read-only)

```
$ pnpm vitest run src/hooks/__tests__/useIdempotencyKey.test.tsx \
    src/features/treasury/PaymentForm.test.tsx \
    src/features/treasury/SplitPaymentForm.test.tsx \
    src/features/treasury/treasury.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx

 Test Files  6 passed (6)
      Tests  86 passed (86)
   Duration  4.12s
```
(act(...) warnings emitted by pre-existing `treasury.test.tsx` SplitPaymentForm cases; no failures.)

```
$ pnpm typecheck
> tsc --noEmit
(no output)
EXIT=0
```

```
$ npx eslint <the 9 touched files>
✖ 83 problems (0 errors, 83 warnings)

per file:
  RecordPaymentModal.tsx                       E0 W25   (incl. the 6 B-7 precision/no-parsefloat-on-money)
  RecordPaymentModal/__tests__/tenantScope     E0 W14
  SplitPaymentModal.tsx                        E0 W1
  PaymentForm.test.tsx                         E0 W5
  PaymentForm.tsx                              E0 W13
  SplitPaymentForm.test.tsx                    E0 W9
  SplitPaymentForm.tsx                         E0 W1    (array-type only; zero precision warnings)
  treasury.test.tsx                            E0 W15
```
Warning delta vs base: +9, all in test files, all pre-existing rule categories (see NB-7). Zero new errors. `SplitPaymentForm.tsx` carries no precision warning.

```
$ rg -n 'parseFloat|Math\.abs' apps/web/src/features/treasury/SplitPaymentForm.tsx
(empty)
$ rg -n 'Idempotency-Key|idempotency' apps/web/src/lib/api.ts apps/web/src/lib/*.ts
(empty — no competing interceptor)
$ grep -rn 'SplitPaymentModal' apps/web/src (outside its own dir)
  components/organisms/index.ts:18  // DEPRECATED: ... merged into RecordPaymentModal
  components/organisms/index.ts:21  export * from './SplitPaymentModal'
  SplitPaymentForm.test.tsx:7,198,202  (type-only, new test)
$ grep -in 'idempot' docs/glossary.md
(empty)
```

Not run (out of this gate's remit / no stack): browser probes, `apps/api` PHPUnit, preflight.

---

## What to fix before merge

Rotate the RecordPaymentModal idempotency key in its `isOpen` effect (`RecordPaymentModal.tsx:150-162`) to match the repo's per-dialog-open precedent, add the two key-lifecycle tests (new key per open; same key on retry after error), and run the plan's Step-6 browser probes — or explicitly downgrade the D1-dependent three-decimal claim in the handback.

---

# Re-gate r2 (2026-09-04)

- **Range re-reviewed:** `9c28de0f9..04b34cdcc` (fix round 1 `e8cac089f`, fix round 2 `ea8374d78`, docs `53a41c683`, `04b34cdcc`)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12`, HEAD `04b34cdcc`, tracked tree clean before and after this gate
- **Ruling applied by the lane:** one idempotency key = ONE submit intent. Unchanged retry after failure keeps the key; edited payload after failure rotates; success rotates; modal open rotates.

## VERDICT: spec ❌ + quality **CHANGES-REQUESTED**

r1's **B2 is closed** and r1's **B1 honesty demand is met**. But the fix for B2 introduced a **new, money-visible regression that is strictly worse than the bug it fixed**, and I reproduced it with a falsifying test in this worktree (then deleted the probe). The rotation-on-open is keyed off a dependency (`prefill`) that all three hosts supply as a fresh object literal on every render, so the key now rotates on **every parent re-render while the modal is open** — including the reconnect-driven refetch that follows the exact network failure idempotency exists to survive.

---

## 1. Money path — UNCHANGED from r1, VERIFIED CLEAN

```
$ git diff 9c28de0f9..04b34cdcc -- apps/web/src/features/treasury/SplitPaymentForm.tsx \
    apps/web/src/features/treasury/PaymentForm.tsx \
    apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx \
  | grep -E '^\+' | grep -E 'parseFloat|Number\(|toFixed|Math\.(abs|round)'
(empty)
```
The only production change to `SplitPaymentForm.tsx` in the two fix rounds is the `hadFailedAttemptRef` + `startNewIntentOnPayloadEdit` helper and three one-line calls (diff read in full). The bcmath boundary is byte-identical at HEAD:
- `SplitPaymentForm.tsx:123-124` — `bcadd(sum, line.amount === '' ? '0' : line.amount, 3)` seeded `'0.000'`
- `SplitPaymentForm.tsx:126` — `bcsub(totalAmount, currentTotal, 3)`
- `SplitPaymentForm.tsx:175` — `bccomp(currentTotal, totalAmount) !== 0` (exact match)
- `SplitPaymentForm.tsx:180` — `bccomp(line.amount, '0') <= 0`; `:220,222` colour from `bccomp(remaining,'0')`

`RecordPaymentModal`'s pre-existing `parseFloat` block in `updateManualAllocation` (`RecordPaymentModal.tsx:332,337`) is untouched — the fix round only prepends `startNewIntentOnPayloadEdit()` at `:328`. B-7 debt intact, no new float.

## 2. Intent-scoping coverage per surface — payload-bearing state audited against each POST body

**`SplitPaymentForm` — COMPLETE.** Body is `{ splits, idempotency_key }` (`SplitPaymentForm.tsx:100-103`); `splits` derives only from `paymentLines` (`:184-189`). The three and only writers of `paymentLines` — `addPaymentLine` (`:150`), `removePaymentLine` (`:157`), `updatePaymentLine` (`:165`) — all call the helper. `reference`, `repository_id`, `payment_method_id`, `amount` all flow through `updatePaymentLine`. No unwired seam.

**`RecordPaymentModal` — COMPLETE for what is actually sent.** Body (`RecordPaymentModal.tsx:354-363`): `partner_id`/`document_id` (props), `currency` (hook), `payment_date`, `payments[]`, `excess_allocation_method`, `excess_allocations`. Wired: `changePaymentDate` (`:415`), `addPaymentLine` (`:278`), `removePaymentLine` (`:285`), `updatePaymentLine` (`:292`), `updatePaymentLineMultiple` (`:301`, so `confirmPaymentLine` too), `updateManualAllocation` (`:328`), `changeExcessAllocationMethod` (`:421`). `excessAmount` is derived from the lines, so the conditional inclusion of the excess fields is covered.
  - `notes` (`:140`, edited at `:859`) is **deliberately not wired — because it is never sent.** It is absent from the POST body at `:354-363`. Correct for T12; see NB-8.

**`PaymentForm` — COMPLETE for operator edits.** Body at `:729-753`. Every RHF field (amount, payment_method_id, repository_id, partner_id, payment_date, reference, notes, third_party_name, the whole `instrument` block) is covered by the `watch(cb)` subscription (`:609-612`). Non-RHF payload state: withholding trio via wrapped setters (`:650,655,660`), `allocationMethod` via `handleAllocationMethodChange` (`:643`), `manualAllocations` via `handleManualAllocationsChange` (`:668`, passed at `:1377`). `setAllocationMethod`/`setManualAllocations` have no other call sites (`grep` → `:644,645,669` only).
  - **The preview-driven rate auto-fill NOT rotating is defensible but not airtight.** `PaymentForm.tsx:582-586` writes `setWithholdingRateState` raw, and `withholding_rate` does ride in the body (`:751`). It only fires when the rate is empty, and its own trigger inputs (`selectedPartnerId`, `paymentAmount`, `withholdingTransactionType`) all rotate the key already. The residual window is: withholding enabled with an empty rate, submit fails, the *first* preview then resolves and fills the rate → an "unchanged" retry now carries a rate the first attempt did not. Because the suggested rate is the server's own computed rate, the replayed payment is what the operator wanted, so this is NB-9, not a blocker.

## 3. Rotation is correctly gated on a prior failed attempt — but the gate has no falsifier

`hadFailedAttemptRef` is set only in `onError` (`PaymentForm.tsx:783`, `SplitPaymentForm.tsx:113`, `RecordPaymentModal.tsx:392`), cleared in `onSuccess` (`PaymentForm.tsx:758`, `SplitPaymentForm.tsx:107`, `RecordPaymentModal.tsx:367`) and in the modal's `isOpen` effect (`RecordPaymentModal.tsx:182`). The helper early-returns unless the flag is set, so an edit before any attempt cannot rotate.

**Mutation A re-run by this gate (handback claim VERIFIED).** Deleted `hadFailedAttemptRef.current = true` from all three `onError`s (`perl -0pi`), ran the three affected suites, restored from a byte-copy backup:
```
× SplitPaymentForm … mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
× RecordPaymentModal … mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
× PaymentForm … mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
 Test Files  3 failed (3)
      Tests  3 failed | 28 passed (31)
```
Exactly the three rotation tests, and the unchanged-retry falsifiers stayed green. Restore verified: `git status --porcelain` empty, `git diff --stat` empty, 7 suites re-run 94/94.

**Additional mutation run by this gate — the early-return guard itself is UNPROTECTED (finding F3).** Deleting `if (!hadFailedAttemptRef.current) return` from all three helpers:
```
 Test Files  7 passed (7)
      Tests  94 passed (94)
```
All 94 stay green. The "an edit before any attempt must not rotate" invariant — the whole reason the mechanism is intent-scoped rather than edit-scoped — is asserted by no test. Restored, tree clean.

## 4. Replay contract with the backend — CONFIRMED, no payload fingerprint anywhere

```
$ grep -rn 'idempotency' apps/api/app/Modules/Treasury --include='*.php' \
    | grep -i 'fingerprint|hash|payload|request_hash'
(empty)
```
Both lookups key on `(tenant_id, company_id, idempotency_key)` and nothing else — `PaymentController.php:159-170`, `MultiPaymentController.php:82-93` / `:101`. Short-circuits at `PaymentController.php:358-366` (single), `:1427-1433` (`storeMultiple`), `MultiPaymentController.php:129-135` (split), `:277-283` (deposit), plus the unique-violation re-read recoveries at `PaymentController.php:2094` and `MultiPaymentController.php:211`. `resolveIdempotencyKey` (`PaymentController.php:135-152`, `MultiPaymentController.php:60-77`) reads header-then-body.

**Consequence, and the Phase B convergence item (folds NB-3 / conventions m3):** same key + *different* body returns the ORIGINAL payment with HTTP 200 and no warning. There is no server-side protection whatsoever against a stale replay. **The web-side intent scoping audited in §2 is the only thing standing between an edited payload and a silently-wrong success panel.** That makes the correctness of the rotation trigger a money-grade concern (finding F1), and it makes a Phase B server-side request fingerprint (`409 Conflict` on a key reused with a different body, per RFC 9110 idempotency semantics) the right convergence target — together with a `docs/glossary.md` row for "idempotency key" and convergence of `PaymentDetailPage.tsx:164,231,399`'s `refundRequestId` onto `useIdempotencyKey`.

## 5. `SplitPaymentForm` error surface — VERIFIED

`SplitPaymentForm.tsx:109-116` sets `treasury:splitPayment.submitFailed` in `onError` and does not reset the key. Key present in all three locales: `locales/en/treasury.json:280`, `locales/fr/treasury.json:280`, `locales/ar/treasury.json:691` (read at HEAD, values are real sentences, not placeholders). Locked by `SplitPaymentForm.test.tsx:363-383`, which asserts both the message and `postedIdempotencyKey(1) === postedIdempotencyKey(0)`.

## 6. Honesty of the handback — MET (one wording item outstanding)

`docs/handoff/HANDBACK-request-hygiene-T12-2026-09-04.md` `## FR1-C` states plainly that the plan's Step-6 browser probes were **not run**, that they are **promotion-blocking, not optional**, that "no claim in this handback should be read as browser-verified", and why deviation D1 makes the keyboard leg load-bearing. `## Still owed after this round` repeats it and adds the fail→edit→resubmit second-visible-payment case. `SplitPaymentForm` having no active route is stated with evidence (`components/organisms/index.ts:18-21`). This satisfies r1 B1.

**Outstanding (NB-10):** FR1-C offers options **(A)** harness probe or **(B)** explicit downgrade, and says "until (A) or (B) is recorded, the three-decimal claim for the split surface is unproven" — but neither is *elected*. Record (B) in one line so the promotion checklist has an unambiguous entry rather than a fork.

---

## BLOCKING FINDINGS

### F1 — [Critical, blocking] `RecordPaymentModal.tsx:165-185` — the new per-open rotation fires on every PARENT RE-RENDER, so an unchanged retry after a lost response creates a SECOND payment

The rotation added by fix round 1 lives in the form-reset effect, whose dependency array is `[isOpen, prefill, resetIdempotencyKey]` (`RecordPaymentModal.tsx:185`). `resetIdempotencyKey` is stable (`useIdempotencyKey.ts:15-18`, `useCallback([])`) and `isOpen` is a boolean — but **all three hosts pass `prefill` as an inline object literal**, a new identity on every render:

- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:898-905`
- `apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:784-791`
- `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:713-720`

So while the modal is open, **every** host re-render re-runs the effect body, which clears `hadFailedAttemptRef` and calls `resetIdempotencyKey()`.

**Why this is worse than the r1 bug it replaced.** The trigger is not hypothetical — it is *caused by the same fault* the key protects against:
- `apps/web/src/lib/queryClient.ts:9` — `refetchOnReconnect: true` globally.
- `apps/web/src/providers/WebSocketReconnectProvider.tsx:10-13` — invalidates **all active queries** on WebSocket reconnect.

Money-visible sequence: INV-1 outstanding 1000. Operator submits 400; the server **commits**; the response is lost to a network drop. `onError` fires, key `K` retained (correct). The network returns → reconnect invalidation refetches the host's invoice → `balance_due` is now 600, so TanStack's structural sharing cannot preserve the reference → **new `invoice` object → host re-renders → new `prefill` literal → effect fires → key rotates to `K'` and the form is wiped**. The operator re-enters the *identical* 400 and presses Record. `PaymentController.php:1427-1433` finds nothing for `K'` (and there is no body fingerprint, §4), so a **second 400 payment and a second treasury movement** are booked. Outstanding 200, not 600.

This directly violates the orchestrator ruling the lane implemented: an *unchanged* retry must keep the key.

**Proven, not argued.** I wrote a temporary probe in this worktree (deleted afterwards; tracked tree verified clean) that renders the modal with the inline literal exactly as the hosts do, fails the first POST, re-renders the parent with `isOpen` unchanged, re-enters the same 400 and resubmits:
```
× GATE r2 PROBE > keeps the SAME key on an unchanged retry after the parent re-renders (inline prefill, as all 3 hosts pass it)
  → AssertionError: expected 'd7408861-…' to be '63a4f2ec-…'
✓ GATE r2 PROBE > CONTROL: with a STABLE prefill identity the same sequence keeps the key
 Tests  1 failed | 1 passed (2)
```
The passing CONTROL isolates the cause to `prefill`'s identity: nothing else in the sequence rotates.

Note the lane's own lifecycle test cannot catch this — `idempotencyKeyLifecycle.test.tsx:84-92` deliberately hoists `PREFILL` to a module constant with a comment explaining that a fresh object "would fire the effect for the wrong reason". That is precisely the production wiring, so the test is calibrated away from the real hosts.

**Fix before merge:** rotate on the open *transition*, not on effect re-entry, and keep the rotation out of the prefill-dependent effect:
```ts
const wasOpenRef = useRef(false)
useEffect(() => {
  if (isOpen && !wasOpenRef.current) {
    wasOpenRef.current = true
    hadFailedAttemptRef.current = false
    resetIdempotencyKey()
  } else if (!isOpen) {
    wasOpenRef.current = false
  }
}, [isOpen, resetIdempotencyKey])
```
Then land the probe above (inline-literal case + stable-identity control) as a permanent regression test — with the inline literal, since that is what production passes.

### F2 — [Important, blocking] `PaymentForm.tsx:609-612` — the same class of unintended rotation via `reset()`; RHF `watch(cb)` fires on a programmatic reset

Probed in this worktree with a minimal `useForm` component (created, run, deleted):
```
RHF_WATCH_FIRES_ON_RESET=true before=0 after=1   (react-hook-form ^7.67.0, apps/web/package.json:70)
```
`PaymentForm.tsx:389-439` calls `reset({...})` from an effect keyed on `[invoiceData, purchaseOrderData, deliveryNoteData, supplierInvoiceData, reset]`. After the same lost-response failure, the reconnect invalidation refetches the document; if the payment actually committed, `amount_residual` changed, the object identity changes, `reset()` runs, the `watch` subscription fires, and the key rotates **with no operator edit**.

Severity below F1 because the reset also visibly rewrites the amount field, so the operator's retry is genuinely a different payload and a second payment is arguably the ruling's intended outcome. But the effect is a *server-data* change, not an operator edit, and the lane's own design note says the helper exists for "operator edits". Either exclude programmatic resets (compare the RHF event `type`/`name`, or set a `suppressRotationRef` around the prefill `reset`), or state explicitly in the handback that refetch-driven resets are accepted as intent boundaries. Do not leave it undocumented — this is the second instance of the same root cause as F1 and it should be ruled once for both.

### F3 — [Important, blocking as a test-quality gate] the intent guard `if (!hadFailedAttemptRef.current) return` is asserted by no test

Measured, not assumed (§3): deleting that line from all three surfaces leaves **94/94 green**. The invariant "an edit before any attempt must not rotate the key" — which is what keeps the mechanism intent-scoped rather than keystroke-scoped, and what prevents the first submit from racing a rotation — is unlocked. Add one test per surface (or one shared): type into a payload field *before* any submit, submit once, and assert the posted key equals the key that would have been minted at mount (e.g. two pre-submit edits then one submit, asserting a single POST whose key is stable across a second pre-submit edit and a subsequent unchanged submit).

---

## NON-BLOCKING FINDINGS (new in r2)

- **NB-8 [Minor, pre-existing]** `RecordPaymentModal.tsx:140,859` — the operator can type `notes`, but `notes` is absent from the POST body (`:354-363`). The text is silently discarded on every payment recorded from an invoice / sales order / purchase order. Not introduced by T12 (which is why it is correctly *not* wired for rotation), but it is a real data-loss-on-input defect worth its own lane.
- **NB-9 [Minor]** `PaymentForm.tsx:582-586` — the withholding-rate auto-fill bypasses rotation by design; the narrow race is described in §2. Acceptable as-is; record the reasoning in the handback so a future reader does not "fix" it into an over-rotation.
- **NB-10 [Minor]** Elect option (B) of handback `## FR1-C` item 3 in one line: the `"0.300"` exact-match contract is unit-level only on an unreachable surface, no browser evidence, re-probe required before `SplitPaymentForm` is revived.
- **NB-11 [Minor, process]** This worktree is being used concurrently by another gate: untracked probe/lint-baseline files (`__tests__/zzprobe.test.tsx`, then a set of `zzbase_*` copies) appeared and disappeared under `apps/web/src` during this review. Tracked tree was clean at every measurement and HEAD never moved off `04b34cdcc`, but a `pnpm test` / `pnpm lint` run at the wrong moment would have picked them up. Confirm no stray file survives before promotion.
- r1's **NB-1, NB-2, NB-4, NB-5, NB-6** stand unchanged. **NB-7 is retired** — the handback's corrected table is right and I reproduced it exactly (§7). **NB-3** is folded into §4 as the Phase B convergence item.

---

## 7. Commands run by this gate (read-only except two mutations and three probes, all restored/deleted)

```
$ pnpm vitest run src/hooks/__tests__/useIdempotencyKey.test.tsx \
    src/features/treasury/PaymentForm.test.tsx \
    src/features/treasury/SplitPaymentForm.test.tsx \
    src/features/treasury/treasury.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx

 Test Files  7 passed (7)
      Tests  94 passed (94)
   Duration  4.02s
```

```
$ pnpm typecheck
> tsc --noEmit
TYPECHECK_EXIT=0   (no output)
```

**eslint per file, HEAD vs lane base `a97631051` — handback table REPRODUCED EXACTLY.** Baselines via `git show a97631051:<path>` into temp copies inside `src/` preserving directory + `.test.tsx` suffix, linted, deleted; tree verified clean.

| file | base `a97631051` | HEAD `04b34cdcc` | Δ |
|---|---|---|---|
| `components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` | E0 W25 | E0 W25 | 0 |
| `components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx` | (new) | E0 W0 | 0 |
| `components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx` | E0 W13 | E0 W13 | 0 |
| `components/organisms/SplitPaymentModal/SplitPaymentModal.tsx` | E0 W1 | E0 W1 | 0 |
| `features/treasury/PaymentForm.test.tsx` | E0 W4 | E0 W4 | 0 |
| `features/treasury/PaymentForm.tsx` | E0 W13 | E0 W13 | 0 |
| `features/treasury/SplitPaymentForm.test.tsx` | E0 W1 | E0 W2 | **+1** |
| `features/treasury/SplitPaymentForm.tsx` | E0 W3 | E0 W1 | **−2** |
| `features/treasury/__tests__/TreasuryTenantScope.test.tsx` | E0 W0 | E0 W0 | 0 |
| `features/treasury/treasury.test.tsx` | E0 W15 | E0 W15 | 0 |
| `hooks/useIdempotencyKey.ts` | E0 W0 | E0 W0 | 0 |
| **total** | **E0 W75** | **E0 W74** | **−1** |

Zero new errors, net −1 warning. The handback's W75 → W74 claim is accurate file-by-file, not just in total.

**Merge-tree** (from the main checkout; note `dev` has moved past the `6292cf235` in the brief):
```
$ git rev-parse dev            -> 5e1e54f696e6e8f825d64b24918745bd955ce2f8
$ git merge-base --is-ancestor 6292cf235 dev  -> YES
$ git merge-tree --write-tree dev lane/rh-t12-payment-idempotency
db488c012a0f4d86421870ef0b41712b675b2850     (exit 0, tree oid only — NO CONFLICTS)
```
One file overlaps between the lane and `dev`-since-base: `apps/web/src/features/treasury/__tests__/TreasuryTenantScope.test.tsx` — dev rewrote the `PaymentListPage` query-key assertion for mandatory pagination (`:178-185`), the lane changed the `useCurrency` mock and two `totalAmount` props (`:41,172,215`). Disjoint hunks, semantically compatible; re-run the file after merging.

**Worker hygiene:** `ps aux | grep '[v]itest'` → empty after the run.

Not run (outside this gate's remit / no stack): browser probes, `apps/api` PHPUnit, preflight.

---

## What to fix before merge

Move `RecordPaymentModal`'s key rotation out of the `prefill`-dependent effect onto a real open *transition* (F1) and land the inline-literal regression probe; rule on the `reset()`-driven rotation in `PaymentForm` (F2); add the missing falsifier for the pre-attempt guard (F3). The browser legs and NB-10's one-line election remain promotion-blocking on top of that.

---

# Re-gate r3 (2026-09-04)

- **Range re-reviewed:** `04b34cdcc..eec7e7f44` (fix round 3 `5d75a7873`, docs `eec7e7f44`)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t12`, HEAD `eec7e7f44`; tracked tree verified clean before, between every mutation, and after
- **Read-only** except five mutations (C, D, D2, E, F) and one temporary test relaxation, each applied from a byte copy and restored; `git status --porcelain` empty afterwards each time

## VERDICT: spec ✅ + quality **APPROVED** (MERGE)

All three r2 blockers are closed and each is now locked by a falsifier I re-ran and reproduced. The money path is byte-unchanged since r2. Lint improves. Merge-tree is clean. The one residual money scenario (item 2 below) is pre-existing base behaviour, is not made worse by this lane, and is disclosed in the right place in the handback.

---

## 1. F1 — CLOSED. The observation channel is sound, not tautological (two independent falsifying assertions)

**Code, read at HEAD:**
- `RecordPaymentModal.tsx:150` — `const wasOpenRef = useRef<boolean>(false)`
- `RecordPaymentModal.tsx:193-202` — separate effect, deps `[isOpen, resetIdempotencyKey]` (both stable: `isOpen` is a boolean, `resetIdempotencyKey` is `useCallback([])` at `useIdempotencyKey.ts:15-18`). Body: `if (wasOpenRef.current) return` → set flag → `hadFailedAttemptRef.current = false` → `resetIdempotencyKey()`; `else wasOpenRef.current = false`.
- `RecordPaymentModal.tsx:166-178` — the form-reset effect keeps deps `[isOpen, prefill, resetIdempotencyKey]` and no longer touches either ref. Directed shape, implemented exactly.
- **Hosts untouched, verified not assumed:** `git diff a97631051..HEAD --name-only -- 'apps/web/src/features/documents/**'` → empty. `InvoiceDetailPage.tsx:894-905`, `SalesOrderDetailPage.tsx:780-791`, `PurchaseOrderDetailPage.tsx:709-720` still pass `prefill` as an inline literal.

**Judging the observation channel.** The lane's argument for counting `crypto.randomUUID()` mints instead of comparing two posted keys is CORRECT and I verified its premise: the form-reset effect at `:166-178` still fires on the fresh-`prefill` re-render and wipes `paymentLines`, so the operator must re-enter — and with the fix `hadFailedAttemptRef` survives that effect (it moved to `:197`), so the re-entry rotates legitimately. Both worlds therefore post the same *ordinal* uuid on the retry; a naive two-key comparison genuinely cannot separate them.

The channel is **not tautological**, on three grounds measured here:
1. It is a *count in a bounded window*, and the two uuid consumers in that window are enumerable from the code: `createNewPaymentLine` (`RecordPaymentModal.tsx:204-205`) and `useIdempotencyKey`'s `reset` (`useIdempotencyKey.ts:16`). `PaymentLineData.id` is the only other uuid on the surface. So "exactly 1" is a claim about which of two known producers fired, not a magic number.
2. The test carries a **second, independent assertion that reads the money path** — `idempotencyKeyLifecycle.test.tsx:326` `expect(mintedDuringRerender).not.toContain(postedIdempotencyKey(1))`. I proved this assertion falsifies on its own: with mutation C applied I relaxed the length assertion to `toBeGreaterThan(0)` and re-ran — the test still failed, on line 326, `expected [ …(2) ] to not include '00000000-0000-4000-8000-000000000005'`. So the count is belt and the POST-body read is braces.
3. The fixture uses `makePrefill()` (`idempotencyKeyLifecycle.test.tsx:92-101`) at every `render`/`rerender` — the production shape. r2's complaint that the old hoisted `PREFILL` constant calibrated the test away from the hosts is fixed at `:84-91`.

**Mutation C re-run by this gate** (revert F1: rotation back inside the `prefill`-dependent effect, transition effect neutered to a bare `wasOpenRef` bookkeeper):
```
   ✓ mints a DIFFERENT idempotency_key for two separate modal opens
   ✓ keeps the SAME idempotency_key when retrying after a failed submission from the same open
   ✓ mints a DIFFERENT idempotency_key once the payload is edited after a failed submit
   × does NOT rotate the key when the PARENT re-renders with a fresh prefill object while the modal stays open
     → AssertionError: expected [ …(2) ] to have a length of 1 but got 2
   ✓ does NOT rotate the key when the payload is edited BEFORE any submit attempt
 Test Files  1 failed | 1 passed (2)
      Tests  1 failed | 8 passed (9)
```
Exactly the one test, exactly the predicted diagnostic. Restored from byte copy; tree clean.

## 2. RULING on the uncured end-to-end reconnect scenario — **YES, merge Task 12 with the host `useMemo` as a follow-up**

**The premise is confirmed, and the wipe is PRE-EXISTING.** `git show a97631051:.../RecordPaymentModal.tsx` line 159 already reads `}, [isOpen, prefill])` — the form-reset effect depended on `prefill` before this lane existed. T12 did not introduce the wipe and does not widen it.

**Why merge is the right call:**

1. **Strictly better than base, on the same scenario.** On `a97631051` this modal sent **no idempotency key at all**: *every* retry after a lost response booked a second payment, re-render or not. Post-T12 the dominant retry — see the error, click Record again from the same open, nothing re-renders — is deduplicated server-side (`PaymentController.php:1427-1433`), locked by `idempotencyKeyLifecycle.test.tsx:213-236`. The residual is the compound case *commit + lost response + a host re-render before the retry*, which is exactly as bad as base and no worse. There is no regression to gate on.
2. **`useMemo` is a partial cure, so gating on it would buy less than it looks.** The three hosts build `prefill.amount` from `outstandingAmount`. A memo keyed on those primitives stops the wipe only when the refetch returns the SAME payload values — the WebSocket-reconnect "invalidate everything, nothing changed" case (`WebSocketReconnectProvider.tsx:10-13`, `queryClient.ts:9`), which is the common one and worth fixing. But in the precise money scenario, the 400 *did* commit, so `outstandingAmount` moves 1000 → 600, the memo deps change, the wipe fires anyway and the re-entry still rotates. Making three one-line edits a merge gate would let the lane claim "cured" for a case that is not cured.
3. **The real defect is the wipe, not the identity.** A modal that discards confirmed payment lines, the date, the notes AND `validationError` (`RecordPaymentModal.tsx:168-175`) out from under an operator who has a failed attempt pending is a data-loss/UX defect in its own right, and it is the actual root cause. That is a design change to `RecordPaymentModal` (do not wipe while an intent is pending; re-hydrate rather than clear), not a `useMemo`, and it is plainly outside T12's ID-1/ID-2 scope (rule 4).
4. **It is disclosed in the right place, in the right words.** Handback `## Follow-up recorded, NOT fixed in this round` lines 803-812 says the scenario "can still end in a second payment" and names the hosts. Nothing is hidden.

**Two-line ruling for the orchestrator:**
> **YES — merge T12 now.** The uncured leg is pre-existing base behaviour that T12 leaves no worse (base sent no key at all, so every retry double-paid); T12's own defect, the re-render rotation, is fixed and locked.
> Raise the follow-up as **P1 pre-production**, scoped as *"RecordPaymentModal must not wipe an intent that has a failed attempt pending"* — `useMemo` on the three hosts is the cheap half (it cures the unchanged-refetch case only) and must not be recorded as the full cure.

## 3. F2 — CLOSED. Every programmatic write is wrapped; no operator path is

`grep -n 'setValue(\|reset(\|writeProgrammatically' apps/web/src/features/treasury/PaymentForm.tsx`, every hit classified by reading the enclosing scope:

| site | `setValue`/`reset` | wrapped? | correct? |
|---|---|---|---|
| `PaymentForm.tsx:357` RIB-derived IBAN set | `setValue('bank_iban', nextIban)` | ✅ `writeProgrammatically` | yes — effect on query-derived `countryCode`/RIB validation |
| `PaymentForm.tsx:361` RIB-derived IBAN clear | `setValue('bank_iban','')` | ✅ | yes |
| `PaymentForm.tsx:415-465` four document-prefill branches | `reset({...})` ×4 | ✅ (single wrap at `:415`, closed at `:466`) | yes — driven by `invoiceData`/`purchaseOrderData`/`deliveryNoteData`/`supplierInvoiceData` |
| `PaymentForm.tsx:540` method-compat repository clear | `setValue('repository_id','')` | ✅ | yes — effect on `compatibleRepositories` |
| `PaymentForm.tsx:1115,1118-1120,1123-1125` BankPicker `onFallbackValueChange`/`onFallbackChange`/`onChange` | `setValue` ×7 | ❌ unwrapped | **correct** — JSX picker callbacks, an operator pick IS a payload edit |
| `PaymentForm.tsx:1467` `AddPartnerModal.onSuccess` | `setValue('partner_id', …)` | ❌ unwrapped | **correct** — operator created the partner |
| `PaymentForm.tsx:1479` `AddRepositoryModal.onSuccess` | `setValue('repository_id', …)` | ❌ unwrapped | **correct** |
| `PaymentForm.tsx:688` | `allocationPreviewMutation.reset()` | n/a | TanStack mutation reset, not RHF |

No programmatic write is left unwrapped and no operator write is wrapped. The suppression is a synchronous `try/finally` (`PaymentForm.tsx:344-351`), which is sound only because RHF emits the notification inside the write — that assumption is itself pinned by the shipped test (a future RHF that defers would turn it red).

**Mutation D re-run** (delete `if (programmaticWriteRef.current) return` at `:650`, keep the `type` filter):
```
   × PaymentForm idempotency key ignores programmatic form writes > keeps the SAME idempotency_key when a PROGRAMMATIC RHF write lands after a failed submit
     → AssertionError: expected '8a20ba59-…' to be '7f051fd6-…'
 Test Files  1 failed | 3 passed (4)
      Tests  1 failed | 89 passed (90)
```
Exactly one test, and it independently **confirms the lane's RHF measurement over my r2 prescription**: if `setValue` reported anything but `type: 'change'`, the surviving `type !== 'change'` filter would have caught it and the test would have stayed green. My r2 F2 discriminator was wrong; the lane measured it and said so.

**Mutation E re-run** (delete `if (type !== 'change') return` at `:649`, reshape the callback to `watch(() => {…})`):
```
 Test Files  4 passed (4)
      Tests  90 passed (90)
```
No unique coverage — the lane's disclosure is accurate. **Acceptable.** The narrowing can only make the mechanism rotate *less* often, and the notifications it drops are RHF's values-only ones which never accompany an operator keystroke without a paired `type:'change'`; every operator write on this form reaches the subscription as `type:'change'` (proven transitively by mutation D). Keeping an honestly-labelled belt with no test is better than deleting a correct narrowing to chase a coverage number.

## 4. F3 — CLOSED. Mutation F: exactly the three new tests

Guard present at `PaymentForm.tsx:627`, `SplitPaymentForm.tsx:135`, `RecordPaymentModal.tsx:161`. Deleting all three:
```
   × SplitPaymentForm idempotency key is not rotated before the first attempt > carries the MOUNT key on the first submit even though the payload was edited
   × PaymentForm idempotency key is not rotated before the first attempt > carries the MOUNT key on the first submit even though the payload was edited
   × RecordPaymentModal idempotency key lifetime > does NOT rotate the key when the payload is edited BEFORE any submit attempt
 Test Files  3 failed | 4 passed (7)
      Tests  3 failed | 96 passed (99)
```
Exactly three, one per surface. All three assert the POSTED key equals a key minted at mount (`postedIdempotencyKey(0)` vs `mintedAtMount`) — data-meaning, not a status code. Restored; tree clean.

## 5. Money path since r2 — UNTOUCHED

```
$ git diff 04b34cdcc HEAD -- 'apps/web/src/**/*.ts' 'apps/web/src/**/*.tsx' \
    | grep -E '^\+' | grep -E 'parseFloat|Number\(|toFixed|Math\.(abs|round|floor|ceil)'
+    const value = deterministicUuid(minted.length + 1)      (×3, test-only uuid counters)
```
`SplitPaymentForm.tsx` at HEAD is byte-identical to r2 on the bcmath boundary: `:123` `bcadd(sum, line.amount === '' ? '0' : line.amount, 3)`, `:126` `bcsub(totalAmount, currentTotal, 3)`, `:175` `bccomp(currentTotal, totalAmount) !== 0`, `:180`/`:220`/`:222` `bccomp`. No float, no new arithmetic anywhere in round 3.

## 6. Commands run by this gate

```
$ npx vitest run src/hooks/__tests__/useIdempotencyKey.test.tsx \
    src/features/treasury/PaymentForm.test.tsx \
    src/features/treasury/SplitPaymentForm.test.tsx \
    src/features/treasury/treasury.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/tenantScope.test.tsx \
    src/components/organisms/RecordPaymentModal/__tests__/idempotencyKeyLifecycle.test.tsx

 Test Files  7 passed (7)
      Tests  99 passed (99)
   Duration  8.24s
```
(act(…) warnings from the pre-existing `treasury.test.tsx` SplitPaymentForm cases; no failures. 94 → 99 = +2 RecordPaymentModal, +2 PaymentForm, +1 SplitPaymentForm, as claimed.)

```
$ npx tsc --noEmit
TYPECHECK_EXIT=0   (no output)
```

**eslint per file, base `a97631051` vs HEAD — REPRODUCED EXACTLY.** Base copies via `git show a97631051:<path>` into `zzt3_`-prefixed **siblings in the same directory** (path matters: the design-token rules are path-scoped — copying into a scratch subdirectory inflated the totals to W741 and is not a valid baseline), linted in one invocation, deleted; tree verified clean.

| file | base `a97631051` | HEAD `eec7e7f44` | Δ |
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

```
$ npm run audit:design-system
[sweep-progress] Design-system audit C1-C6 violations: 811
[gate-summary] Design-system baseline: 796 acknowledged, 15 new, 11 stale baseline entries
EXIT=1
```
796/15/11 reproduced. **All 15 new and all 11 stale entries are in `src/features/import/pages/ImportWizardPage.tsx` and `src/features/uom/components/UnmappedUnitTextsPanel.tsx` — zero in any T12-touched file.** Inherited from the lane base; `dev` already carries a newer `UnmappedUnitTextsPanel.tsx` and a newer `audit-design-system-baseline.json` (both appear in `git diff dev lane --name-only`), so this resolves on merge, not in this lane. See NB-14.

```
$ git rev-parse dev                     -> 9c28b430acdebe8234a1f4a1ae1f8a3c8482e28a
$ git merge-base --is-ancestor a97631051 dev  -> yes
$ git merge-tree --write-tree dev lane/rh-t12-payment-idempotency
d0e460d626a910277c82c9b2cf6c8da2219bd0f8    (exit 0, tree oid only — NO CONFLICTS)
```

**Worker hygiene:** `ps aux | grep '[v]itest'` → no vitest process (only the grep's own shell). `pgrep -fl vitest` → empty.

Not run (outside this gate's remit / no stack): browser probes, `apps/api` PHPUnit, preflight.

---

## FINDINGS (r3)

**Blocking: none.**

- **NB-12 [Minor] `PaymentForm.tsx:415-466` and `:540` — two of the three `writeProgrammatically` wraps have no falsifier.** Measured: unwrapping ONLY the document-prefill `reset()` (keeping the ref and the IBAN wrap) leaves `PaymentForm.test.tsx` + `treasury.test.tsx` 73/73 green. Only the RIB→IBAN path is locked. Kept Minor rather than repeating r2's F3 ruling because the prefill wrap is provably **inert on this surface**: all four `reset()` branches write `payment_method_id: ''` (`:422,:431,:443,:456`), the field is `required` (`:753-755`), so after any prefill reset the operator MUST re-pick the method through the un-wrapped registered select — which rotates the key regardless of whether the reset itself was suppressed. The missing falsifier therefore guards nothing today. Worth one test if the required-ness of `payment_method_id` ever changes.
- **NB-13 [Minor] `PaymentForm.test.tsx:596` contradicts the measurement it documents.** The comment reads "`setValue` reports `type` as undefined", while the production comment (`PaymentForm.tsx:640-641`) and the handback's transcript (line 681) both record `{"name":"bank_iban","type":"change"}` — and my mutation D confirms the `'change'` reading. A future reader of the test would conclude the `type` filter suffices and delete the ref. One-line correction.
- **NB-14 [Minor, process] `pnpm audit:design-system` exits 1 on this lane (796/15/11).** Not a T12 defect (§6), but the lane cannot produce a green `pnpm lint` in isolation. Merge `dev` in (or land on top of it) before quoting a web-lint result.
- **NB-10 (r2) still unelected.** Handback `## FR1-C` item 3 still offers (A) or (B) without choosing. One line, promotion-scoped.
- r1/r2 NB-1, NB-2, NB-4, NB-5, NB-6, NB-8, NB-9, NB-11 stand unchanged. **NB-3 remains the Phase B convergence item** (server-side request fingerprint + `docs/glossary.md` row for "idempotency key" + converge `PaymentDetailPage.tsx:164,231,399` onto `useIdempotencyKey`).

## Still promotion-blocking (unchanged, correctly disclosed by the lane)

1. The four Step-6 browser legs plus the fifth the r2 gates added (fail → touch nothing → let the network return → retry, confirming the same key and no new payment).
2. Elect (A) or (B) for the split-surface three-decimal claim (NB-10).
3. HTTPS origin confirmation for `crypto.randomUUID()` (r1 §5).
4. **New:** the `RecordPaymentModal` mid-intent form wipe (§2) — P1 pre-production, scoped as the wipe, not as the `useMemo`.

## What to fix before merge

Nothing. Merge `lane/rh-t12-payment-idempotency` into `dev` (clean merge-tree `d0e460d62`), open the wipe follow-up lane as P1 pre-production, and carry the four promotion items forward.
