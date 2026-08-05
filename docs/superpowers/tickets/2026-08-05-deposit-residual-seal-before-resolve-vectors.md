# Ticket: back-office deposit — residual seal-before-resolve vectors (W-5c D1 follow-on)

**Filed:** 2026-08-05, by the L1 fiscal-integrity fix lane.
**Source:** `docs/superpowers/reviews/2026-08-05-l1-fiscal-gate.md` finding **I-3**
(adversarial merge gate on `fix/l1-fiscal-integrity`).
**Parent defect:** `docs/superpowers/tickets/2026-08-03-w5c-expense-income-deposit-findings.md` **D1**.
**Status:** OPEN — deliberately not fixed in the L1 lane (orchestrator ruling 2026-08-05, item 5).

## Context — what the L1 lane closed, and what it did not

`RecordCustomerDepositService::record()` commits the `DEPOSIT_RECEIPT` fiscal event and
its seeded projection rows in one transaction, then runs the projection pipeline
**after** that commit. A `DEPOSIT_RECEIPT` is a sealed fiscal event with **no delete
route by design**, so *every* invariant `TreasuryDepositBridge` (or the movement port
it calls) can still fail on mints a permanent orphan receipt: it appears in
`GET /partners/{id}/deposits` at full face value while moving no money and granting no
credit, so the customer-facing deposit history over-states what was received.

The L1 lane moved five of those invariants ahead of the seal, enumerated as
`App\Modules\Treasury\Domain\Enums\DepositReferenceRefusal` and checked by
`DepositReferenceResolutionService::refusalFor()`:

| Closed | Post-seal counterpart |
|---|---|
| payment method unknown / inactive | `TreasuryDepositBridge::resolvePaymentMethod()` |
| repository unknown / inactive | `TreasuryDepositBridge::resolveRepository()` |
| repository has no `gl_account_id ?? account_id` | `resolveRepository()` |
| repository's cash GL account missing / inactive | `resolveRepository()` |
| tender currency != repository currency | `TreasuryMovementService::record()` currency guard |

Two vectors remain. They are **not** input-validation failures — they are
time-of-check / time-of-use races, which is why a pre-flight predicate does not
actually close them and why they were held back rather than bolted on.

---

## V1 — frozen repository (routine ops)

`TreasuryDepositBridge::apply()` passes `allowWhileFrozen: false` for a server-only
`DEPOSIT_RECEIPT` (correctly — it is a back-office deposit, not offline device replay),
so `TreasuryMovementService::record()` throws `RepositoryFrozenException` when
`payment_repositories.frozen_at` is set.

**Reachable by routine ops:** a cash count freezes the drawer. A back-office user who
submits a deposit in the window between the freeze and the reopen seals a receipt that
can never project.

**Why a pre-flight check is not sufficient on its own:** the freeze can land *between*
the pre-flight read and the projection run. A pre-flight check narrows the window from
"any time the drawer is frozen" to "the milliseconds inside one request", which is a
large improvement, but it does not make the guarantee absolute.

**Suggested disposition (needs a ruling):**
1. Add `RepositoryFrozen` to `DepositReferenceRefusal` and check `frozen_at` in the
   pre-flight — closes the ops-realistic case, leaves the race; **and/or**
2. Make the projection failure recoverable rather than terminal: today
   `ProjectionInvariantViolationException` retries 5x then dead-letters, and the sealed
   receipt stays visible in the deposit history forever. A `DEPOSIT_RECEIPT` whose
   projection permanently fails should be excluded from
   `DepositReceiptQueryService::historyForPartner()`, or annotated in it, so the
   customer-facing history stops over-stating. **This second option also retro-fixes
   the orphans already minted on local/staging**, which the D1 ticket §3 leaves as an
   open decision.

## V2 — actor without an active company membership

`TreasuryDepositBridge::resolveActorUserId()` requires the payload's actor to be an
`Active` member of the event's company and throws otherwise — deliberately, because the
shared `PaymentAllocationService` only posts the customer-advance journal entry when the
actor resolves to a `User`, so a null actor would silently skip GL.

**Reachability is low:** the actor is the authenticated request user, who has already
passed `auth:sanctum` + `SetPermissionsTeam` + `can:payments.create`. The gap is a
membership revoked mid-request, or a route/permission path that authorizes a user for a
company they are not an active member of.

**Suggested disposition:** add the membership assertion to the pre-flight (cheap, same
shape as the others), and separately confirm whether `can:payments.create` can ever pass
for a non-member — if it can, that is a tenancy/authz defect in its own right and belongs
to `tenancy-authz-reviewer`, not here.

---

## Related, out of scope for this ticket

- **`PayExpenseRequest.php:51-54`** carries the same `['required','uuid', ScopedExists…]`
  shape flagged as gate finding I-4. Verified NOT exploitable: Laravel skips
  `Exists`/`Unique` for an attribute that already carries a message
  (`Validator::hasNotFailedPreviousRuleIfPresenceRule()`,
  `vendor/laravel/framework/src/Illuminate/Validation/Validator.php:902-905` — its own
  docblock: *"This is to avoid possible database type comparison errors."*). No fix needed;
  recorded so the next reader does not re-derive it.
- **N-9 (gate):** on the TN chart, tax-rounding residual is booked to
  `4375 — droit de timbre à reverser`, a State liability, mixed in with genuine timbre.
  Pre-existing; see the D1a rework for the FR/Generic split.
