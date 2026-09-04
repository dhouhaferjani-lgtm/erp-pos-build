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
