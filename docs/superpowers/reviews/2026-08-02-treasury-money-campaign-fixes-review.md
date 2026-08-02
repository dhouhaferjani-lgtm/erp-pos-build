# Adversarial review — treasury money-campaign fixes (MTP-TRE-10 / 15 / 23)

- **Reviewer:** treasury-reviewer (adversarial gate)
- **Date:** 2026-08-02
- **Scope:** `f364c316f`, `cae181d19`, `fda03231b` on local `dev` (diff base `b967dc133`) — NOT pushed
- **Ticket:** `docs/superpowers/tickets/2026-08-02-treasury-money-campaign-defects.md`
- **Verdict:** **REJECT — do not promote to `origin/dev`**

All findings below were verified against the code and, where marked EMPIRICAL, reproduced by
running instrumented probes against the real test database (probes were removed afterwards;
`git status` is clean).

---

## Verdict summary

| Fix | Ticket goal | Outcome |
|---|---|---|
| `cae181d19` MTP-TRE-10 partial-refund allocation unwind | reopen `balance_due` | Half-fixed. `balance_due` reopens, but the invoice stays `status = paid`, so aged receivables and the allocation engine still exclude it. Introduces a new PG-only over-inflation path via `reversePayment()`. |
| `fda03231b` MTP-TRE-15 withholding 500 | 500 → clean validation | **Not fixed.** The 500 is still reachable (polarity flipped), and the path that now succeeds writes a fiscal certificate that is 100× too small. |
| `f364c316f` MTP-TRE-23 atomic instrument cancel | break the deadlock | Correct for `reversePayment()`. **Money-losing for `refundPayment()` / `partialRefund()`** — those two post a *second* reversal on top of the instrument cancellation and move phantom cash out of a repository that never received it. |

Additionally the change **hard-fails an existing CI gate** (deptrac ratchet).

---

## CRITICAL findings

### [CRITICAL] C1 — Phantom cash outflow: refunding an uncleared cheque debits a repository that never received the money

`apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:131` and `:269` now call
`resolveInstrumentForReversal()` inside `refundPayment()` / `partialRefund()`, which cancels a
`Received` instrument and lets the refund proceed. The refund then unconditionally runs
`postRefundGlAndMovement()` (`PaymentRefundService.php:428-499`), which records a
`MovementDirection::Out` movement against `$original->repository_id`.

But a deferred **customer** payment never records a movement IN:
`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:1151` guards the
inbound movement with `&& ! $isDeferredCustomer` — the cash only arrives when the instrument is
deposited + cleared (`InstrumentLifecycleService.php:274`, `MovementDirection::In`).

**EMPIRICAL** (probe on `DeferredTenderGuardsTest`, 40.000 TND cheque, instrument `received`,
full `refundPayment()`):

```
PROBE bal 200.000 -> 160.000; movements 0 -> 1; AR debit=80 credit=40;
      entries=customer_payment,customer_payment_refund,instrument
```

The bank repository lost 40.000 TND of real balance for a cheque that was never banked. Movements
went 0 → 1, and that single movement is the refund OUT — there was no matching IN.

*Why it matters:* `payment_repositories.balance` is the treasury source of truth for cash
positions and reconciliation. This silently corrupts it on every cheque/effet refund.
Before this commit the path was unreachable (`assertInstrumentSettledForCashUndo()` threw), so
this is a **newly introduced** defect, not pre-existing.

*Fix:* `resolveInstrumentForReversal()` must only be used by `reversePayment()` (which posts no GL
and no movement). For `refundPayment()`/`partialRefund()`, either keep failing closed for
`Received`, or make the refund GL/cash leg conditional on the instrument having actually cleared.

---

### [CRITICAL] C2 — Accounts Receivable is debited twice for one reversal (GL ⇄ subledger divergence)

Two reversal entries now post for the same money:

1. Instrument cancellation, `GeneralLedgerService.php:3085-3104` —
   `Dr CustomerReceivable (411) / Cr instrument portfolio (5113/413)` for `$instrument->amount`.
2. Payment refund, `GeneralLedgerService.php:579-598` —
   `Dr CustomerReceivable (411) / Cr bank` for the refund amount.

Both fire inside the same `refundPayment()` transaction (`PaymentRefundService.php:131` then
`:179`).

**EMPIRICAL**, full refund of a 40.000 cheque: `AR debit=80 credit=40` — a single 40.000 payment
produced 80.000 of AR debits.

**EMPIRICAL**, partial refund of 10.000 against the same 40.000 cheque:

```
PROBE-PARTIAL payment_status=completed instrument_status=cancelled bank_balance=190.000
              AR_debit=50 AR_credit=40 balance_due=10.000
```

The instrument cancellation restored the **whole** 40.000 receivable in the GL while the
subledger (`documents.balance_due`) reopened only 10.000 — a 30.000 permanent GL-vs-subledger gap
on a partner-tagged control account.

---

### [CRITICAL] C3 — `partialRefund()` cancels the WHOLE instrument for a PARTIAL amount and leaves the payment `completed`

`InstrumentLifecycleService.php:645-652` states the contract explicitly:

> The caller MUST: … flip the linked payment to `Reversed` itself, in the same transaction,
> immediately after this call returns.

`partialRefund()` (`PaymentRefundService.php:277-314`) never flips the payment — it only appends a
note (`:312-314`). **EMPIRICAL:** `payment_status=completed instrument_status=cancelled`.

Consequences:
- A `completed` cheque payment backed by a `cancelled` instrument is an impossible domain state.
- The cheque can no longer be deposited or cleared, so the remaining 30.000 of a 40.000 cheque
  can never be collected — the whole instrument was destroyed to refund a quarter of it.
- `resolveInstrumentForReversal()` (`PaymentRefundService.php:648-669`) silently no-ops on the
  second partial refund (status is now `Cancelled`, matching neither branch), so the inconsistency
  is permanent and invisible.

---

### [CRITICAL] C4 — MTP-TRE-15 is NOT fixed: the same uncaught `TypeError`/500 is still reachable, with the polarity flipped

`WithholdingCertificateService::createFromPayment()` went `?float` → `?string`
(`apps/api/app/Modules/Taxation/Application/Services/WithholdingCertificateService.php:129`),
but `PaymentController::store()` still passes the raw validated value with no normalisation
(`PaymentController.php:889`). The rule is `numeric`
(`PaymentController.php:362`), which accepts a JSON **number** as well as a string.

**EMPIRICAL** (probe posting `"withholding_rate": 0.0150` as a JSON number):

```
TypeError: App\Modules\Taxation\Application\Services\WithholdingCertificateService::createFromPayment():
Argument #3 ($overrideRate) must be of type ?string, float given,
called in .../PaymentController.php on line 886
```

The `try/catch` at `PaymentController.php:896` only catches `\DomainException`, so a `TypeError`
still escapes as a bare 500 — exactly the defect the ticket asked to remove. The fix swapped which
client payload crashes; it did not add a boundary normalisation (`(string)` cast / `CurrencyScale`
/ a FormRequest `prepareForValidation` normaliser).

---

### [CRITICAL] C5 — The now-succeeding withholding path writes a fiscal certificate that is 100× too small, and the new test green-lights it

Unit mismatch:
- Ingress `PaymentController.php:362` — `'withholding_rate' => ['nullable','numeric','min:0','max:1', regex 4dp]` ⇒ a **fraction** (0.15 = 15%). `max:1` makes it impossible to submit a percentage.
- Consumer `WithholdingCalculationService.php:98` — `$rate = bcdiv($ratePercentage, '100', 4);` ⇒ expects a **percentage** ("5.0" = 5%).

**EMPIRICAL** (probe reading the certificate the new regression test creates, gross 1190.000,
`withholding_rate: '0.0150'` — i.e. 1.5%):

```
PROBE rate=0.0001 amount=0.119 net=1189.881 gross=1190.000
```

Intended withholding 17.850; actual 0.119. Two compounding errors: the ÷100 unit error, plus
`bcdiv(...,4)` truncating `0.00015` → `0.0001` (a further 33% loss).

The implementer's claim that the mismatch is **pre-existing** is **TRUE but incomplete**: pre-fix
it was only reachable by clients sending a JSON number (string payloads 500'd). The precision
contract (rule 19) mandates string money payloads from the frontend, so the fix converts a
*loud 500* into a *silent, hash-chained, PDF-issued fiscal misstatement* for the primary client.
Escalating a fail-loud to a fail-silent on a tax document is a blocker, not a deferrable
pre-existing issue.

Worse, the new regression test locks the wrong behaviour in:
`apps/api/tests/Feature/Treasury/PaymentTest.php:255-271` asserts only that a certificate row
**exists** (`assertNotNull` + `assertDatabaseHas` on ids) and never asserts `withholding_rate`
or `withholding_amount`. It passes with `rate=0.0001`. The e2e counterpart
(`apps/web/e2e/money-campaign/treasury-payments.spec.ts:485-493`) has the same gap.

---

### [CRITICAL] C6 — `reversePayment()` after a `partialRefund()` inflates `balance_due` ABOVE the invoice total (Postgres only)

`reversePayment()` deletes only the ORIGINAL payment's allocations
(`PaymentRefundService.php:586`, `PaymentAllocation::where('payment_id', $payment->id)->delete()`).
The negative rows that `unwindAllocationsProRata()` now writes belong to the **refund** payment
(`PaymentRefundService.php:802-806`, `'payment_id' => $refundPaymentId`) and therefore survive.

The Postgres trigger then recomputes
`balance_due = total - SUM(payment_allocations) - SUM(credit_note_allocations)`
(`apps/api/database/migrations/tenant/2026_01_08_214145_add_balance_due_cache_trigger.php:33-48`),
which for a 600.000 invoice with a surviving `-250.000` row yields **850.000** — more than the
invoice total.

`reversePayment()` is reachable after a partial refund: the probe in C3 confirms the original
payment is still `completed`, and `reversePayment()` accepts `Completed`
(`PaymentRefundService.php:569`). Route `POST /payments/{payment}/reverse` is live
(`apps/api/app/Modules/Treasury/Presentation/routes.php:202`).

Status: **code-verified, not reproduced** — SQLite has no trigger, so no existing test can see it.
This is a direct consequence of `cae181d19` (before it, `partialRefund()` wrote no allocation rows).

---

## IMPORTANT findings

### [IMPORTANT] I1 — CI hard-fail: deptrac ratchet regression (verified by running it)

`PaymentRefundService` lives in `Treasury/**Domain**/Services/` but now imports and
constructor-injects `App\Modules\Treasury\**Application**\Services\InstrumentLifecycleService`
(`PaymentRefundService.php:14` and `:43`). `deptrac.yaml:88-92` allows `ModuleDomain` to depend
only on `SharedDomain` / `SharedContracts`.

Run output:

```
ModuleDomain on ModuleApplication            34       36   BLOCKER (+2)
TOTAL                                        97       99
BLOCKER — new Domain-tier leakage
RATCHET REGRESSION — total violations rose from 97 to 99.
RESULT: FAIL — architecture boundary regression.
```

This blocks the branch on its own. Fix by going through a `Shared/Contracts/Treasury/*` port (the
pattern already used for `TreasuryMovementServiceInterface`) rather than the concrete Application
service.

### [IMPORTANT] I2 — The reopened invoice stays `status = paid`, so receivables reports and the allocation engine still exclude it

`unwindAllocationsProRata()` writes only `balance_due` (`PaymentRefundService.php:842-843`). The
service the implementer cites as the mirror does more —
`OutboundInstrumentService.php:679-684` also reverts `DocumentStatus::Paid → Posted` when
`balance_due > 0`.

Documents are flipped to `Paid` on full allocation
(`PaymentAllocationService.php:235-236`), and every downstream consumer filters on status:
- `AgedReceivablesService.php:149` — `->where('status', DocumentStatus::Posted)`
- `PaymentAllocationService.php:470-471` — outstanding-document lookup requires `Posted`
- `UpcomingPaymentsService.php:190`, `:218`
- the partial index `documents_balance_due_index … WHERE type='invoice' AND status='posted'`
  (`2026_01_08_214145_add_balance_due_cache_trigger.php:70`)

So the ticket's stated harm ("receivables understated") is only fixed on the document-detail
payload. Aged receivables still understate, and the customer cannot be re-allocated against the
reopened amount.

### [IMPORTANT] I3 — `canRefund()` still reports `false` for exactly the case that now succeeds

`PaymentRefundService::canRefund()` (`:505-518`) still returns `false` when the instrument is
`Received`/`Deposited`/`Bounced`. It backs `GET /payments/{payment}/can-refund`
(`PaymentRefundController.php:173`, route `routes.php:206`). The API now advertises "not
refundable" for a payment `refundPayment()` will happily process — the UI affordance and the
server capability disagree.

### [IMPORTANT] I4 — `cancelForPaymentReversal()` hardcodes `CancellationShape::B2b`; POS-origin instruments would post to the wrong account

`InstrumentLifecycleService.php:671` passes `CancellationShape::B2b` unconditionally, whereas
`cancel()` takes the shape as a parameter (`:602`) and POS instruments are cancelled with
`CancellationShape::PosRevenue` (`TreasuryReceiptBridge.php:1505-1509`). The shape selects the
counterpart account: `CustomerReceivable` vs `ProductRevenue`
(`GeneralLedgerService.php:3067-3072`). `POST /payments/{payment}/reverse` accepts any payment id
(`PaymentRefundController.php:144`), so a POS-origin cheque reversed through the payment path
would debit 411 instead of reversing revenue.

### [IMPORTANT] I5 — "MUST be called inside an open transaction" is documented but unenforced

`cancelForPaymentReversal()` is `public` (`InstrumentLifecycleService.php:654`) and performs no
`DB::transactionLevel()` check, unlike `cancel()` which opens its own transaction (`:604`) and
unlike `createInstrumentCancellationEntry()` / `postEntryNow()` which do assert
(`GeneralLedgerService.php:3053`, `:3123`). Those assertions are **skipped** whenever
`$payment === null || $payment->journal_entry_id === null`
(`InstrumentLifecycleService.php:692`) — i.e. in exactly the branch where nothing else enforces
atomicity, the instrument row update (`:715`) and the `InstrumentEvent` write (`:716-728`) can run
outside any transaction. Add an explicit `if (DB::transactionLevel() < 1) throw new \LogicException(...)`
at the top of `cancelForPaymentReversal()`.

### [IMPORTANT] I6 — Privilege widening: `payments.refund` / `payments.reverse` now perform an `instruments.cancel` action

`POST /payment-instruments/{instrument}/cancel` is gated by `can:instruments.cancel`
(`routes.php:143-145`). The refund/reverse routes are gated by `can:payments.refund` /
`can:payments.reverse` (`routes.php:194-204`). After this change a holder of only
`payments.reverse` can cancel a payment instrument. This may be intended, but it is an undisclosed
authorization-boundary change with no permission-matrix test.

### [IMPORTANT] I7 — `reversePayment()` violates the precondition the new helper documents

`resolveInstrumentForReversal()`'s docblock (`PaymentRefundService.php:645-646`) requires "an
already `lockForUpdate()`'d Payment". `reversePayment()` only calls `$payment->refresh()`
(`:575`) — no row lock — unlike `refundPayment()`/`partialRefund()` which do lock (`:113`, `:257`).
The instrument lock at `:650` mitigates the double-cancel case, but the documented contract is
false for one of the three callers.

### [IMPORTANT] I8 — Test coverage misses the newly opened path entirely

`DeferredTenderGuardsTest` was changed to remove `InstrumentStatus::Received` from the
blocked-operations loop (`tests/Feature/Treasury/DeferredTenderGuardsTest.php:227`) and adds a
single new test that exercises **only `reversePayment()`** (`:275-333`). There is no test for
`refundPayment()` or `partialRefund()` against a `Received` instrument — the two paths that carry
C1/C2/C3. A test asserting `payment_repositories.balance` and the AR debit/credit totals over that
path would have caught all three.

### [IMPORTANT] I9 — A new lock is inserted into the documented global lock order without analysis

`unwindAllocationsProRata()` takes `Document` row locks (`PaymentRefundService.php:814-818`)
between the payment row lock (`:257`) and the GL company advisory lock taken inside
`postRefundGlAndMovement()` (`:465`, order documented at `:418-420` and in
`Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:26,64`). Document locks are also
taken by the allocation/posting flows. No deadlock analysis accompanies the change.

---

## MINOR findings

- **[MINOR] M1** — The PHP `balance_due` recompute was added only to `partialRefund()`
  (`PaymentRefundService.php:306`), not to `refundPayment()`. **EMPIRICAL:** after a full refund on
  SQLite, `balance_due=0.000` (stale). Correct on Postgres via the trigger, but it means no test can
  ever catch a full-refund `balance_due` regression. Asymmetry should be removed (recompute in both,
  or in neither and rely on the trigger).
- **[MINOR] M2** — `Payment::where('original_payment_id', …)` in `unwindAllocationsProRata()`
  (`PaymentRefundService.php:731-734`) omits the `company_id` scope that
  `alreadyRefundedForOriginal()` applies (`:384`). Harmless under db-per-tenant + UUID keys, but
  inconsistent.
- **[MINOR] M3** — The residual-to-last-document rule (`PaymentRefundService.php:781-783`) applies
  no `min(slice, live)` cap for the last document, unlike the per-document truncation branch. With
  N documents the last one can be over-unwound by up to (N-1) minor units, pushing its
  `balance_due` above its `total`.
- **[MINOR] M4** — The PHP recompute truncates at the *currency* scale (`:826`, `:842`) while the
  Postgres trigger computes at column precision `numeric(15,3)`. For a 2-dp currency stored in a
  3-dp column the PHP write (which runs last) clobbers the trigger value. Below the `0.01`
  tolerance of `DocumentCacheValidationService::findInconsistencies()` (`:27`), so it will not
  alarm — it will just be quietly wrong.
- **[MINOR] M5** — The e2e MTP-TRE-15 rewrite
  (`apps/web/e2e/money-campaign/treasury-payments.spec.ts:474-493`) asserts certificate existence
  and linkage only; it will stay green with the 100×-wrong rate from C5. It also assumes
  `GET /withholding/certificates` returns a bare array; not verified here (no live run).

---

## Verified-correct (no action)

- **`performCancellation()` is a faithful extraction.** Line-by-line against the pre-fix `cancel()`
  body: same portfolio-account resolution, same `createInstrumentCancellationEntry` +
  `postEntryNow`, same `PosRevenue` payment flip, same instrument status update, same
  `InstrumentEvent` fields (`InstrumentLifecycleService.php:682-729`). The only delta is
  `from_status` moving from the literal `'received'` to `$fromStatus->value` (`:689`, `:721`) —
  equivalent under the `Received` precondition. No GL entry, event or audit row is dropped.
- **Standalone `cancel()` is unchanged and still fails closed** (`InstrumentLifecycleService.php:598-621`),
  proven by `DeferredTenderGuardsTest.php:335-343` and e2e `MTP-TRE-23c`.
- **`Deposited` / `Bounced` still fail closed** (`PaymentRefundService.php:665-667`), proven by the
  retained loop at `DeferredTenderGuardsTest.php:234-249`.
- **Precision contract (rule 19) respected in the new code.** No `(float)`, `parseFloat` or
  `number_format` anywhere in the diff; all arithmetic is bcmath; the new money code resolves scale
  from the injected `CurrencyScaleResolverInterface` **with an explicit currency**
  (`PaymentRefundService.php:704` `getScale($original->currency)`, `:825`
  `getScaleSafe($document->currency, $scale)`) rather than the request-context-only no-arg form.
  Intermediates use `scale + 10` and truncate once at the boundary via `CurrencyScale::bcformat`
  (`:790-793`) — the same contract as `buildProportionalMap()` (`:1120-1125`), as claimed.
- **Idempotency is intact.** Both refund paths short-circuit on
  `findExistingRefundByRequestId()` *before* the instrument cancel and *before* the allocation
  unwind (`:123-126`, `:261-264`), so a retry with the same `refund_request_id` neither
  double-cancels nor double-unwinds. Concurrent `reversePayment()` calls serialise on the
  instrument row lock (`:650`) and the second sees `Cancelled` and no-ops.
- **Tenant/company scoping** on the document read is correct (`:814-817`, tenant + company +
  `lockForUpdate`).
- **Permission middleware unchanged** (`routes.php:189-217`).
- **Fiscal / event-sourcing:** only `journal_entries` (a downstream accounting projection) is
  written; no device-signed fact or Document chain entry is re-authored. `postEntryNow()` seals
  in-transaction and defers `JournalEntryPosted` to `afterCommit`
  (`GeneralLedgerService.php:3127-3134`) — correct.
- **PHPStan level 8 is clean** on all five changed app files and on `PaymentController.php`
  (run individually). Note this does *not* prove `numeric-string`: the `@param numeric-string|null`
  on `createFromPayment()` (`WithholdingCertificateService.php:123`) is an unverified docblock claim,
  as C4 demonstrates at runtime.

---

## Pre-existing-failure claim: VERIFIED

`test_supplier_traite_keeps_cash_payment_shape_and_registers_outbound_instrument`
(`tests/Feature/Treasury/DeferredTenderGuardsTest.php:82-113`) fails identically at `HEAD` and at
`b967dc133`:

```
Failed asserting that table [repository_movements] matches expected entries count of 1.
Entries found: 0.   at tests/Feature/Treasury/DeferredTenderGuardsTest.php:101
```

Verified by checking the five changed app files out at `b967dc133` and re-running the single test
(then restoring). Root cause is outside the diff:
`PaymentController.php:1151` skips the cash movement for `$isDeferredSupplier`, and
`PaymentController.php` is not touched by any of the three commits. The test method body is also
untouched (the `DeferredTenderGuardsTest` diff starts at line 222). **Pre-existing — not caused by
this branch**, but it means `DeferredTenderGuardsTest` is red on `dev` and cannot serve as a
green gate.

Full-file run at `HEAD`: `1 failed, 8 passed`. `PaymentRefundTest`: `15 passed`.

---

## What must be fixed before merge

1. **C1/C2/C3** — remove `resolveInstrumentForReversal()` from `refundPayment()` and
   `partialRefund()`, or make the refund GL + cash movement conditional on the instrument having
   cleared. A partial refund must never cancel a whole instrument, and no path may leave a
   `completed` payment with a `cancelled` instrument.
2. **C4** — normalise `withholding_rate` to a string at the HTTP boundary (FormRequest
   `prepareForValidation` or an explicit `(string)` at `PaymentController.php:889`) and add a test
   posting a JSON **number**.
3. **C5** — reconcile the fraction-vs-percentage units between `PaymentController.php:362` and
   `WithholdingCalculationService.php:98`, and assert `withholding_rate` / `withholding_amount`
   values (not just row existence) in `PaymentTest.php` and the e2e spec.
4. **C6** — make `reversePayment()` also remove/neutralise the refund payments' negative
   allocations (or refuse to reverse a payment that already has refunds).
5. **I1** — route the instrument cancellation through a `Shared/Contracts/Treasury/*` port so the
   deptrac ratchet returns to 97.
6. **I2** — revert `DocumentStatus::Paid → Posted` when `balance_due > 0`, mirroring
   `OutboundInstrumentService.php:679-684`.
7. **I5** — add the `DB::transactionLevel()` guard to `cancelForPaymentReversal()`.
8. **I3, I4, I6, I7, I8** — align `canRefund()`, pass the real `CancellationShape`, decide and
   document the permission story, lock the payment in `reversePayment()`, and add refund/partial-refund
   coverage over a `Received` instrument asserting repository balance and AR totals.
