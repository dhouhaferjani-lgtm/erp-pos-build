# Gate record — treasury hardening batch (`670651ce5` + `3b2e96888`)

**Date:** 2026-08-03 · **Reviewer:** treasury-reviewer (adversarial, code-grounded)
**Scope:** commit `670651ce5` (N2/N3 hardening of `cancelForPaymentReversal()`) and commit
`3b2e96888` (DeferredTenderGuardsTest supplier-traite rewrite). Local `dev`, NOT pushed.
Out-of-scope changes (Taxation) ignored.
**Ticket:** `docs/superpowers/tickets/2026-08-02-treasury-fix-lane-minor-followups.md` N2/N3 +
dev-hygiene · **Origin review:** `docs/superpowers/reviews/2026-08-02-treasury-money-campaign-fixes-review.md` §N2/N3

## VERDICT: APPROVE-WITH-FIXES

The code is safe to merge as a strict improvement (no regression found, all touched suites green,
PHPStan/Pint clean). It is **not** MERGE-READY as *described*: the commit message, the port
docblock and the ticket all claim N2's convention-only hole is closed. It is not. The exact
scenario N2 documented still reproduces after the fix — proven with a live probe below. The
required fix before merge is **narrative + ticket accuracy** (and ideally the one-line identity
check the origin review actually asked for).

---

## Verification of the six gate questions

### (1) Does the Completed-precondition break the legitimate atomic reversal flow? NO — call order proven.

`PaymentRefundService::reversePayment()` calls the port **before** the status flip:
- `PaymentRefundService.php:716` — `$this->resolveInstrumentForReversal($original, $userId, $reason);`
- `PaymentRefundService.php:719-722` — `$original->update(['status' => PaymentStatus::Reversed, …]);`

At port-call time the DB row is still `completed`, so `InstrumentLifecycleService.php:701-707`
passes. The port re-reads the payment through `$instrument->payment()->first()`
(`InstrumentLifecycleService.php:701`), a fresh instance — it reads committed-in-transaction state,
not the caller's stale model, so ordering is the only thing that matters and it is correct.

Empirically confirmed: `DeferredTenderGuardsTest::test_reverse_atomically_cancels_a_received_instrument_and_resolves_the_deadlock`
(HTTP-created deferred cheque payment → `reversePayment()`) is green (10/10 file).

### (2) Can the tenant/company scope params be spoofed? NO.

`PaymentRefundService.php:805-811` passes `$payment->tenant_id` / `$payment->company_id`, where
`$payment` is `$original` — the row re-fetched under `lockForUpdate()` at
`PaymentRefundService.php:642-646`. No request input reaches the port. The instrument id itself
comes from the `belongsTo` relation (`PaymentRefundService.php:799`), not from input.

Caveat (design, not a defect): because the id is derived from the payment relation, N3 is
defence-in-depth for *future* direct callers only, and it cannot catch the same-tenant/same-company
mismatch that the identity gap in finding H1 exploits.

### (3) Interface change fan-out — complete.

- Sole implementor: `InstrumentLifecycleService.php:53` (`implements InstrumentReversalCancellerInterface`), updated at `:683`.
- Sole consumer: `PaymentRefundService.php:44` (constructor-injected port), updated at `:805-811`.
- Container binding intact: `TreasuryServiceProvider.php:92-95`.
- No other module, no test double, no anonymous implementor (repo-wide grep for
  `InstrumentReversalCancellerInterface` / `cancelForPaymentReversal` returns only those files plus
  the new test). Module boundary (Treasury Domain → `Shared/Contracts`) preserved.
- PHPStan level 8 on the three changed `app/` files: **0 errors**. Pint: pass.

### (4) Test coverage of the 6 claimed cases — 5 of 6 real; the 6th is inverted.

`InstrumentReversalCancellerHardeningTest` — 6 passed, 9 assertions (verified locally).
Covered: happy path (`:86-105`), payment already `Reversed` (`:107-129`), payment `Pending`
(`:131-148`), no linked payment (`:150-188`), wrong company (`:190-213`), wrong tenant (`:215-232`).

**Not covered / inverted:**
- The "original convention-only hole" is **not** closed and therefore not tested — see H1. The
  positive-control test `test_it_cancels_a_received_instrument_whose_payment_is_completed`
  (`:86-105`) is literally the hole: it calls the port with no reversal in flight and asserts the
  instrument ends `Cancelled`, leaving the payment `Completed`. It encodes the defect as intent.
- `PaymentStatus::Failed` is untested and is a live behaviour change — see M1.
- The positive control asserts one thing (instrument status). It does not assert the payment was
  left untouched, nor the GL side effect, so it would not catch a future change to either.

### (5) Standalone `cancel()` / `Deposited` / `Bounced` — unchanged.

`InstrumentLifecycleService::cancel()` (`:600-624`) is byte-identical in the diff; its
"Settle or reverse the linked payment first" precondition (`:616-620`) and its `dishonored_at`
escape hatch are untouched, and `DeferredTenderGuardsTest::test_standalone_cancel_still_fails_closed_…`
is green. `Deposited`/`Bounced` still fail closed at `PaymentRefundService.php:816-818`, unchanged.

### (6) `3b2e96888` traite rewrite — accurate to the settlement-time design.

Verified against production code, not just the commit message:
- Issuance at creation: `OutboundInstrumentIssuer.php:177-186` resolves
  `InstrumentAccountPurpose::EffetsPayable` for `Effet`, which maps to account code **`403`**
  (`InstrumentAccountResolver.php:49`), and posts
  `GeneralLedgerService::createOutboundInstrumentIssueEntry()`
  (`app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:777-800`) =
  **Dr 401 SupplierPayable / Cr 403**, `source_type='instrument'`. No movement.
- Settlement at clear: `OutboundInstrumentService::clear()` posts
  `createOutboundInstrumentClearingEntry()` (**Dr 403 / Cr bank GL**, `GeneralLedgerService.php:802-825`)
  at `OutboundInstrumentService.php:123-135`, then records the `MovementDirection::Out` movement at
  `OutboundInstrumentService.php:137-154`, then flips to `Cleared` at `:157-162`.

The rewritten assertions (`DeferredTenderGuardsTest.php:117-160`) mirror exactly that. **STALE-TEST
verdict independently confirmed:** the pre-commit version of the file, extracted to a scratch copy
and run unmodified, fails at `:103` with
`table [repository_movements] … expected 1. Entries found: 0` — i.e. it was red for the documented
reason and the rewrite is a stale-test fix, not a weakening. Test-only commit; no production code
touched.

---

## Findings

### [IMPORTANT] H1 — N2 is NOT closed: the precondition validates the *instrument's* payment, not the payment being reversed. Both failure modes reproduce live.

`InstrumentLifecycleService.php:701-707` checks `$instrument->payment()->first()`'s status. The port
signature (`InstrumentReversalCancellerInterface.php:81`) still carries **no payment id**, so it
cannot know which payment is being reversed. The origin review's suggested fix was explicit —
*"require the caller to pass the payment id and check `status`"*
(`docs/superpowers/reviews/2026-08-02-treasury-money-campaign-fixes-review.md:548-549`). Only the
`status` half was implemented.

Two consequences, both reproduced with passing probes against production-shaped data (real HTTP
`POST /api/v1/payments`, real journal entries; probe files kept in the session scratchpad, not
committed):

**H1a — the exact N2 scenario still reproduces.** Calling the port directly on a Received
instrument whose payment is `Completed`, with **no reversal in flight**, still succeeds: instrument
→ `Cancelled`, payment stays `Completed`, and a real AR-restoring GL entry (Dr 411 `40.000`) is
posted while the payment's `PaymentAllocation` row survives. That is a GL-vs-subledger divergence
created by the port itself — the round-1 C3 "impossible state" the review named
(`…review.md:536-546`). The hardening narrows the hole (Reversed/Pending/Failed/no-payment now
throw) but leaves open precisely the state the ticket asked to close.

**H1b — reachable through the public API by validating the wrong payment.** `instrument_id` is
accepted on *any* payment (`PaymentController.php:372-376`, `ScopedExists` only); the eligibility
guards that require `payment_id === null` run **only** inside `if ($isDeferredCustomer)`
(`PaymentController.php:648-666`), and the back-link `$instrument->update(['payment_id' => …])`
runs **only** for a freshly created instrument (`PaymentController.php:897-900`). So an immediate
(non-maturity) payment P2 can be created pointing at another payment P1's Received instrument.
Reversing P2 then reaches the port with P2's tenant/company (identical → N3 scope passes), the port
validates **P1**'s status (`Completed` → passes), and cancels P1's instrument + posts P1's
AR-restoring entry while P1 stays `Completed`. Probe passes end-to-end.
`MultiPaymentService.php:89` has the same one-way-link shape (`'instrument_id' => $split['instrument_id']`
with no back-link anywhere in that service).

This is **pre-existing** — the pre-commit port did no payment check at all, so H1b cancelled too.
The commit does not regress it. But the commit, the docblock
(`InstrumentReversalCancellerInterface.php:52-63`, `InstrumentLifecycleService.php:665-676`) and the
ticket all assert the hole is closed, and it is not.

*Fix (pick one before merge):*
1. **Preferred, ~3 lines:** add `string $paymentId` to the port and assert
   `$instrument->payment_id === $paymentId` alongside the status check; caller passes `$payment->id`
   at `PaymentRefundService.php:805`. Closes H1b outright and makes the status check meaningful.
   Consider also having the port flip the payment to `Reversed` itself — `performCancellation()`
   already does exactly that for the `PosRevenue` shape (`InstrumentLifecycleService.php:752-754`),
   so B2b is the asymmetric case; doing so makes a stray direct call self-consistent and makes a
   replay fail closed.
2. **Minimum:** correct the commit message, both docblocks and the ticket to state that the
   Completed-but-not-being-reversed case remains open, and file it as a follow-up (N5). Do not let
   the ticket be closed as "N2 done".

### [MINOR] M1 — `Failed` payments are advertised as reversible but the new precondition rejects them.

`PaymentRefundService.php:628` explicitly admits `PaymentStatus::Failed` to the reversal path, but
`PaymentStatus::canReverse()` is `Completed`-only (`PaymentStatus.php:17-20`), so a `Failed` payment
with a `Received` instrument now throws `DomainException` where it previously cancelled and
reversed. Reachability: no Treasury writer sets `Failed` (repo-wide grep for `PaymentStatus::Failed`
returns only `PaymentRefundService.php:628`; the other hits are the unrelated `Billing` enum), the
factory has no `failed` state, and status is never user-supplied — so this is legacy/imported data
only. Untested either way.

*Fix:* either drop `Failed` from `PaymentRefundService.php:628` (making the two guards agree), or
accept `Failed` in the port precondition, and add the test.

### [MINOR] M2 — the port can now throw `ModelNotFoundException`, undocumented on the contract and surfaced as a 422 with an internal message.

`InstrumentLifecycleService.php:689-693` uses `findOrFail()` under the new scope, but the port's
`@throws` block (`InstrumentReversalCancellerInterface.php:76-79`) lists only `\DomainException` and
`\LogicException`. `PaymentRefundController::reversePayment()` catches `\Exception` and echoes
`$e->getMessage()` with 422 (`PaymentRefundController.php:158-163`), so a scope miss would return
`422 "No query results for model [App\Modules\Treasury\Domain\PaymentInstrument] <uuid>"` — wrong
status class and an internal class name/UUID in the API body.

*Fix:* document the throw on the interface, and/or convert to a `DomainException` in the port.

### [MINOR] M3 — the positive-control test documents the defect as intended behaviour.

`InstrumentReversalCancellerHardeningTest.php:86-105` is titled *"a Completed payment is the ONLY
state that legitimises this call — proves the port no longer trusts convention."* It proves the
opposite: it is a direct port call with no reversal in flight that succeeds. Whatever is decided for
H1, this test's docblock must be rewritten so it does not lock in the hole as a contract.

### [INFO] Non-findings, checked and clear

- **Rule 19 (money precision):** the diff introduces no arithmetic on money/quantity, no float cast,
  no `getScale()` call. Clean.
- **Rule 6 (module boundaries):** unchanged; `PaymentRefundService` still depends only on the
  `Shared/Contracts` port. No new `use` statements in the diff, so no new deptrac edge.
- **Nullability:** `payments.tenant_id` is NOT NULL from creation
  (`2025_11_30_120000_create_treasury_tables.php:140`) and `company_id` was made NOT NULL
  (`2025_11_30_134000_make_company_id_required.php:69-72`), so the two new non-nullable `string`
  params cannot TypeError.
- **Pre-existing PHPStan noise:** `DeferredTenderGuardsTest.php:461,465,466` report 3 errors when
  analysed explicitly, but `phpstan.neon` `paths:` is `app/` only, and `git log -L` attributes those
  lines to `1a61900d9`, not to this batch. Not introduced here, not CI-visible.

## Test + static-analysis state at `3b2e96888`

| Suite | Result |
|---|---|
| `InstrumentReversalCancellerHardeningTest` | 6 passed (9 assertions) |
| `DeferredTenderGuardsTest` | 10 passed (73 assertions) |
| `PaymentRefundTest` + `PaymentRefundSecurityTest` | 22 passed (61 assertions) |
| PHPStan L8 (`InstrumentLifecycleService`, `PaymentRefundService`, port interface) | 0 errors |
| Pint (all 5 changed files) | pass |
| Pre-commit `DeferredTenderGuardsTest` (scratch copy of `3b2e96888^`) | FAILS at `:103` — stale-test verdict confirmed |

**What to fix before merge:** close H1 by passing the payment id into the port and asserting
`$instrument->payment_id === $paymentId` — or, at minimum, correct the commit message, both
docblocks and the ticket to state that N2's Completed-but-not-being-reversed hole is still open, and
re-ticket it.
