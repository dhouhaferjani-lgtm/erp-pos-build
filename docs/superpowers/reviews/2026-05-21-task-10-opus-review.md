# Task 10 Opus-Style Second-Pass Review - Treasury ACCOUNT_PAYMENT Bridge

Commits reviewed:

- `7e7883222` - `Phase 2.10.1: Add treasury account payment bridge`
- `e6b84d5f7` - `Phase 2.10.2: Reject cross-company customer aliases`

Verdict: **REQUEST-CHANGES**

## Scope Reviewed

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php`
- `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- `apps/api/app/Modules/Fiscal/Domain/Exceptions/ProjectionInvariantViolationException.php`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php`
- Task 10 plan/spec sections for ACCOUNT_PAYMENT, Treasury bridge, command allocation, D16 seam, and standing patterns.

Verification: static adversarial review only. I did not run the test suite in this pass.

## Findings

### P1 - Existing fiscal-event Payment rows with non-POS origin are ignored, allowing a second Payment for the same ACCOUNT_PAYMENT event

`TreasuryAccountPaymentBridge::existingPaymentForEvent()` looks for existing rows by `fiscal_event_id` **and** `origin = PaymentOrigin::Pos`:

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:242`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:246`

That is too narrow for the Task 10 idempotency contract. The plan says a pre-existing `Payment` for the same `fiscal_event_id` with different tenant/company/amount/customer/method/repository/actor must fail loud. The review brief also calls out: "Existing Payment with fiscal_event_id should not double-allocate; conflicts should throw."

With the current probe, a row already carrying this fiscal event ID but with `origin = web_admin`, `api`, `unknown_legacy`, or `NULL` is invisible to the bridge. The bridge will create a new POS-origin row and allocate it, leaving two Treasury payments linked to the same immutable fiscal event. Because `payments.fiscal_event_id` is deliberately not unique, the projector-level check is the only guard here.

Required fix:

- Query by `fiscal_event_id` alone inside `existingPaymentForEvent()`.
- Keep the multiple-row invariant check.
- Add `origin` to `assertExistingPaymentMatches()` expected fields and require `PaymentOrigin::Pos->value`.
- Add a regression test that seeds a payment with the same `fiscal_event_id` and `origin = PaymentOrigin::WebAdmin` or `PaymentOrigin::UnknownLegacy`, then asserts `idempotency_conflict` and no new `Payment` row.

### P1 - Repository `account_id` is accepted without tenant/company validation before GL posting

The bridge correctly scopes the `PaymentRepository` row and fails loud when `account_id` is null:

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:204`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:215`

But it does not verify that `payment_repositories.account_id` references an `accounts` row in the same `(tenant_id, company_id)`. That column is just a nullable UUID from the original Treasury migration and has no FK. The downstream allocation path passes it directly into GL journal lines:

- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:263`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:268`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:310`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:315`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:583`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:585`

So a same-company repository misconfigured with a cross-company account UUID can project an ACCOUNT_PAYMENT into a journal entry for the event company while debiting another company's account. This is exactly the cross-tenant/company FK-safety class the review brief asks us to scrutinize.

Required fix:

- In the command path before GL posting, or earlier in `TreasuryAccountPaymentBridge::resolveRepository()`, verify the account exists with `tenant_id = $event->tenant_id`, `company_id = $event->company_id`, and preferably `is_active = true`.
- Throw `ProjectionInvariantViolationException` with a reason such as `payment_repository_account_cross_company` or `payment_repository_account_not_found`.
- Add a regression test with a scoped repository whose `account_id` points at another company's account. The bridge must fail loud and create no `Payment`/allocation effects.

## Clean Checks

- R2 alias fix is live: missing same-company alias now probes tenant scope and throws `customer_alias_cross_company` for another company's alias instead of retryable alias-missing.
- Bridge is registered through `TreasuryServiceProvider` under the `FiscalEventProjector` tag and declares `requiresModule() === 'Treasury'` plus `priority() === 150`.
- POS-core ACCOUNT_PAYMENT projection remains the D16 boundary: the Treasury bridge owns Treasury/Partner operational work; POS-core does not need Treasury.
- Missing customer, payment method, repository, and repository `account_id` are fail-loud, not silent downgrades.
- Existing matching POS-origin payment short-circuits before allocation; `PaymentType::Advance` mutation by `PaymentAllocationService` is tolerated because idempotency does not compare `payment_type`.
- The bridge uses constructor injection and no `app()`, `App::make()`, or `resolve()` helper in production code.
