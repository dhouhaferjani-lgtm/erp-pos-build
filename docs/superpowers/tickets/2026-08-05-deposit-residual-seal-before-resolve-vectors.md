# Ticket: back-office deposit — residual seal-before-resolve vectors (W-5c D1 follow-on)

**Filed:** 2026-08-05, by the L1 fiscal-integrity fix lane.
**Source:** `docs/superpowers/reviews/2026-08-05-l1-fiscal-gate.md` finding **I-3**
(adversarial merge gate on `fix/l1-fiscal-integrity`).
**Parent defect:** `docs/superpowers/tickets/2026-08-03-w5c-expense-income-deposit-findings.md` **D1**.
**Status:** **PREVENTION LANDED 2026-08-07** in R2-K-prev (branch
`fix/r2k-prev-deposit-preflight`, unmerged, dual-gated). V1 (frozen repository) and V2
(actor membership) are both refused pre-seal, and a THIRD unmirrored guard —
`RepositoryBehindCheckpoint` — was found by the gate and closed with them. The races are
**NARROWED, not closed**: to the width of the sealing transaction plus the
post-commit/pre-projection window. Orphan **detection and disposition remains OPEN** as
R2-K-rec (ruling-blocked on R-g). Superseded prior status: OPEN, deliberately not fixed in
the L1 lane (orchestrator ruling 2026-08-05, item 5).

## What R2-K-prev landed (2026-08-07)

`DepositReferenceResolutionService::refusalFor()` gained a required `actorUserId`
argument and three new `DepositReferenceRefusal` cases —
`RepositoryFrozen`, `ActorNotActiveCompanyMember`, `RepositoryBehindCheckpoint`.
`RecordCustomerDepositService` runs the whole pre-flight **twice**: once before the
transaction (cheap fail, no lock) and once **inside** it, immediately before
`appendDepositReceipt()`, so a refusal rolls back with nothing sealed.

**A deposit takes exactly ONE of two tails, and NEITHER MAY BE EMPTY.** Both merge gates
found independently that the port-derived refusals must not fire on a maturity tender:
`TreasuryDepositBridge` sets `shouldRecordMovement = false` at :169-174 and returns at
:217-219 **before** `TreasuryMovementService::record()` runs for a cheque/effet, so the
currency guard, the freeze policy and the checkpoint policy never execute on that path.
Firing them anyway 422'd a legitimate ops flow that projects fine today (a customer
cheque handed over while the drawer is frozen for a cash count — no cash moves, so the
freeze is irrelevant). `RepositoryCurrencyMismatch`, `RepositoryFrozen` and
`RepositoryBehindCheckpoint` are therefore gated on the non-maturity path; the predicate
is not re-derived but delegated to the same `HandlesMaturityTenderLeg` collaborator the
bridge consults, so the two cannot drift.

The **round-2 fiscal re-gate then caught the mirror-image defect**: skipping the port's
refusals is right, but returning "nothing to check" is not — the maturity path runs
`handleMaturityLeg()`, which has two rejecting steps of its own, and both were newly
unrefused. Both were reproduced on the branch as clean 422s with a sealed orphan:

| Maturity refusal | Post-seal counterpart | Note |
|---|---|---|
| `MissingInstrumentPortfolioAccount` | `HandlesMaturityTenderLeg::portfolioAccountId()` (:69) → `InstrumentAccountResolver::resolveOrFail()` (:35-39) | ops-realistic: a company whose CoA never got `5312`/`5112`/`413` accepts cheques and orphans every one |
| `InstrumentCurrencyMismatchesCompany` | `InstrumentLifecycleService::receive()` (:74-80), reached at :76 | plain client input: `RecordDepositRequest.php:92` accepts any `size:3` currency, the controller forwards it verbatim |

**The two currency checks are NOT the same check.** The movement path compares the tender
against the RECEIVING REPOSITORY's currency; the maturity path against the COMPANY's.
Neither implies the other — round 1's unconditional repository-currency check was covering
the maturity case only *incidentally*, which is why removing it for maturity tenders
opened a real regression. They are now two enum cases with two operands, documented as
non-interchangeable.

Ordering note for future readers: the maturity checks run portfolio-account FIRST, then
currency, because that is the order `handleMaturityLeg()` hits them (:69 before :76) — the
same "refusal you see is the one you would have hit post-seal" principle the movement tail
follows.

**`RepositoryBehindCheckpoint` (authz gate I-2)** mirrors
`TreasuryMovementService::checkpointDisposition()` (:852-874) evaluated with
`allowBehindCheckpoint = false` — which is what bridge :273 passes, since
`! isServerOnly()` is constant FALSE for a DEPOSIT_RECEIPT. Reachable with no privilege:
`StatementCompletionService` stamps `last_reconciled_at` at end-of-day of the statement's
`period_end` (:257-264) and `ConfirmBankStatementRequest` places no upper bound on
`period_end`, so confirming a statement through today orphaned every subsequent same-day
cash deposit. Its pre-fix symptom was subtler than V1/V2: a **clean 422** (because
`RepositoryCheckpointException` is itself a `DomainException`) plus a silent permanent
orphan — the client saw a sensible refusal while the sealed receipt survived.

**Not mirrored, deliberately:** the actor-row-exists arm of `resolveActorUserId()`. Only
its ACTIVE-membership arm is reproduced. `fiscal_events.actor_user_id` and
`user_company_memberships.user_id` both FK to `users`, so a membership row proves the user
row and a hard delete cascades both away together; the extra query can never change the
answer.

**Still OPEN after this lane:** everything in the "residual" column below. The pre-flights
cannot close a TOCTOU race whose losing consumer runs *after* the sealing transaction
commits. Orphans minted in that window — and the ones already on local/staging — need
R2-K-rec.

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

> **2026-08-07 correction to the table above.** The "tender currency != repository
> currency" row was never unconditional: the movement port is not called at all for a
> maturity tender, so that refusal (like the two added in R2-K-prev) applies only on the
> movement path. R2-K-prev fixed the over-refusal. A **third** unmirrored port guard —
> the reconciliation checkpoint — was also missing from this table and is now closed as
> `RepositoryBehindCheckpoint`. See "What R2-K-prev landed" above.

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

**DISPOSITION (2026-08-07): option 1 DONE in R2-K-prev, option 2 remains R2-K-rec.**
`RepositoryFrozen` is in the enum and `frozen_at` is checked pre-flight — but **only on
the movement path**: a cheque/effet deposit against a frozen drawer is now explicitly
ALLOWED, because the bridge never calls the movement port for a maturity tender. See
"What R2-K-prev landed" above. The race itself is unchanged, exactly as this section
predicted.

**Original suggested disposition (kept for the record):**
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

**DISPOSITION (2026-08-07): DONE in R2-K-prev.** The ACTIVE-membership assertion is in
the pre-flight (the actor-row-exists arm is deliberately not mirrored — FK cascade, see
above).

**Reachability question ANSWERED — no separate authz defect.** `can:payments.create`
alone CAN pass for a non-member: `SetPermissionsTeam` scopes Spatie permissions to
`tenant_id` (`SetPermissionsTeam.php:28`), so the `can:` gate is company-blind by
construction. What actually closes the non-member path is a *different* middleware —
`CompanyContextMiddleware`, appended to the global `api` group at `bootstrap/app.php:109`,
which calls `CompanyContext::userHasAccessToCompany()` (`CompanyContext.php:146-152`,
Active-only) and returns 403 `NO_COMPANY_ACCESS` / `COMPANY_ACCESS_DENIED`. That answer is
now pinned by a test rather than prose:
`RecordCustomerDepositTest::test_a_revoked_member_holding_payments_create_is_denied_before_the_controller`
exercises both company-resolution paths.

The residual is therefore genuinely (a) a revocation landing mid-request, and (b) any
internal, non-HTTP caller of the application service — both covered by the pre-flight.
Two structural observations that came out of this and are NOT defects of this ticket got
their own tickets: `2026-08-07-company-gate-single-middleware-coupling.md` (the `api`
group is the sole carrier of the company gate) and
`2026-08-07-company-context-header-uuid-pg-500.md` (unvalidated `X-Company-Id`).

---

## Related, out of scope for this ticket

- **`PayExpenseRequest.php:51-54`** carries the same `['required','uuid', ScopedExists…]`
  shape flagged as gate finding I-4. Verified NOT exploitable: Laravel skips
  `Exists`/`Unique` for an attribute that already carries a message
  (`Validator::hasNotFailedPreviousRuleIfPresenceRule()`,
  `vendor/laravel/framework/src/Illuminate/Validation/Validator.php:902-905` — its own
  docblock: *"This is to avoid possible database type comparison errors."*). No fix needed;
  recorded so the next reader does not re-derive it.
- **N-9 (gate)** is now its own ticket:
  `docs/superpowers/tickets/2026-08-05-tn-timbre-account-carries-rounding-noise.md`.
- **Cross-module import debt (WIDENED 2026-08-07 — now two predicates and four sites).**
  `DepositReferenceResolutionService` (Treasury) imports
  `App\Modules\Accounting\Domain\Account` and `App\Modules\Company\Domain\UserCompanyMembership`
  directly. Those are Treasury -> Accounting and Treasury -> Company MODEL imports, which
  CLAUDE.md rule 6 forbids; both were written that way deliberately, because the predicates
  they mirror already carry the identical imports in `TreasuryDepositBridge`, and diverging
  from the bridge is the exact failure mode this service exists to prevent.

  **The ACTIVE-membership predicate now has THREE independent copies:**

  | Site | Purpose |
  |---|---|
  | `CompanyContext::userHasAccessToCompany()` — `CompanyContext.php:146-152` | request-entry company gate (`CompanyContextMiddleware`) |
  | `TreasuryDepositBridge::resolveActorUserId()` — `TreasuryDepositBridge.php:432-436` | post-seal projection invariant |
  | `DepositReferenceResolutionService::refusalFor()` — `DepositReferenceResolutionService.php:132-140` | pre-seal refusal (R2-K-prev) |

  Three copies of "is this user an Active member of this company" is exactly the drift
  surface rule 6 exists to prevent — and the failure would be silent in the worst
  direction (a pre-flight that says yes while the projection says no re-opens the orphan
  vector this whole ticket is about).

  **4th drift-list entry — checkpoint date logic (Treasury-INTERNAL, added round 2).**
  `DepositReferenceResolutionService::checkpointRefusal()` duplicates the company-timezone
  date comparison in `TreasuryMovementService::checkpointDisposition()` (:852-874). Unlike
  the three above this one does not cross a module boundary — it is Treasury-to-Treasury —
  but it is the *most* fragile of the four, because the duplicated logic is a timezone
  conversion plus a string date comparison where an off-by-one-day divergence would be
  invisible in tests and would re-open the orphan vector.

  It could not be delegated the way the maturity predicate was: `checkpointDisposition()`
  is `private` to `TreasuryMovementService` and there is no shared surface to reuse.
  Extracting one would mean editing `TreasuryMovementService`, whose class header
  documents a deliberate guard ORDERING that forbids casual edits — so the round-2
  re-gate ruled this **acceptable-with-ticket-line, NOT extract-now**.

  Sketch for whoever does extract it:

  ```
  Treasury/Domain/CheckpointPolicy            (or Application/Services)
      isBehindCheckpoint(
          ?CarbonInterface $checkpoint,
          CarbonInterface $occurredAt,
          string $companyTimezone,
      ): bool
  ```

  consumed by BOTH `TreasuryMovementService::checkpointDisposition()` (which keeps owning
  the allow-flag branch and the throw) and this pre-flight (which maps the boolean to
  `RepositoryBehindCheckpoint`). Same "fix them together or not at all" clause as the
  membership and account ports above.

  **Recorded as debt, not fixed.** Clean shape is a pair of small `Shared/Contracts/` read
  ports that ALL sites depend on:

  ```
  Shared/Contracts/Company/CompanyMembershipQueryContract
      hasActiveCompanyMembership(string $companyId, string $userId): bool
  Shared/Contracts/Accounting/AccountingReadPort
      activeAccountExists(string $tenantId, string $companyId, string $accountId): bool
  ```

  Fix them together or not at all.

  **Counter-examples done right, same lane.** Two predicates in this service are NOT
  re-derived and therefore cannot drift at all:
  - the *maturity* predicate (`has_maturity && instrument_kind ∈ {Cheque, Effet}`) — the
    service constructor-injects the same `HandlesMaturityTenderLeg` the bridge consults;
  - the *portfolio-account* predicate — the pre-flight calls
    `HandlesMaturityTenderLeg::portfolioAccountId()` itself inside a `try/catch` for
    `MissingInstrumentAccountException`, i.e. it asks "would this throw?" by invoking the
    very code that throws, rather than re-deriving the purpose match and the resolver's
    lookup.

  That is the shape all four drift-list entries above should reach.
