verdict: REQUEST-CHANGES

## BLOCKER

None.

## P1

1. `created_by = null` is not just audit-null; it can suppress CustomerAdvance GL posting and the partner-balance refresh on the pure-advance path.

- Evidence:
  - `TreasuryDepositBridge::resolveActorUserId()` returns `null` when the payload actor is missing or lacks active membership in the event company: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:219-237`.
  - The Phase 4 test blesses that behavior for a non-member actor: `apps/api/tests/Feature/Fiscal/TreasuryDepositBridgeTest.php:221-229`.
  - The bridge passes that nullable actor into the shared allocation command: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:133-141`.
  - `PaymentAllocationService` resolves a null/non-member command actor to `null`: `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:371-392`.
  - For excess/pure-advance money, the service only calls `createCustomerAdvanceJournalEntry()` inside `if ($actor instanceof User)`: `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:307-328`.
  - The partner-balance refresh for advance money happens inside `GeneralLedgerService::createCustomerAdvanceJournalEntry()`: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:267-324`.

- Repro:
  1. Use the existing deposit test setup, but bind the real `PaymentAllocationService` instead of `RecordingDepositAllocationService`.
  2. Create no open invoices for the customer.
  3. Author a `DEPOSIT_RECEIPT` with an actor in the same tenant but no active `user_company_memberships` row for the event company, matching `TreasuryDepositBridgeTest.php:221-224`.
  4. Apply `TreasuryDepositBridge`.
  5. Result: a completed `payments` row is created with `created_by = null`, `previewAutoAllocation()` has no allocations and returns the full amount as `excess_amount` (`PaymentAllocationService.php:450-551`), but no customer-advance journal entry is created because `$actor` is not a `User`, so `PartnerBalanceService::refreshPartnerBalance()` is never reached for that money.

- Fix:
  - Prefer fail-loud for this server-authored money-movement bridge: if `payload.actor_user_id` does not resolve to an active company member, throw a projection invariant before creating the `Payment`.
  - Alternative: change the allocation/GL API so system-authored fiscal replay can post customer advances without a nullable `User`, using the payment/event tenant and an explicit system audit field. Do not leave "null actor" as a silent path that can skip GL.
  - Add a real-allocation regression for the pure-advance deposit path with zero open invoices. If null actors are rejected, assert fail-loud before `Payment` creation; if they are supported, assert the CustomerAdvance journal line and refreshed partner credit balance.

## P2

1. The deposit bridge test dropped the analog's allocation-throws rollback coverage.

- Evidence:
  - The shipped analog proves allocation failure rolls back the just-created `Payment`: `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:373-385`.
  - The deposit fake has no `throwOnApply()` branch and always returns success: `apps/api/tests/Feature/Fiscal/TreasuryDepositBridgeTest.php:279-302`.
  - The production bridge relies on allocation failure rolling back the outer transaction: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:86-142`.

- Repro:
  - Add `throwOnApply(new RuntimeException('allocation exploded'))` to `RecordingDepositAllocationService`, call `TreasuryDepositBridge::apply()`, and assert `Payment::count() === 0`.

- Fix:
  - Copy the analog's fake exception hook and rollback test for the deposit bridge.

2. The deposit bridge test does not assert provider registration, unlike the analog.

- Evidence:
  - The provider does register the bridge: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:53-61`.
  - The analog test asserts the tagged projector set contains the bridge: `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:401-417`.
  - The deposit test only checks a directly constructed instance's contract/priority: `apps/api/tests/Feature/Fiscal/TreasuryDepositBridgeTest.php:232-240`.

- Repro:
  - Remove `TreasuryDepositBridge::class` from the provider tag. Current deposit tests still pass, but the projection dispatcher would never create the money-movement row.

- Fix:
  - Extend `test_bridge_contract_and_priority()` to read `app->tagged(FiscalEventProjector::class)` and assert `treasury_deposit_bridge` is present.

## NIT

1. `PaymentsOriginColumnsTest::paymentOriginRoundTripCases()` no longer matches its "every origin case" name.

- Evidence:
  - The append-only order assertion includes `back_office`: `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php:41-51`.
  - The data provider omits `back_office`: `apps/api/tests/Feature/Fiscal/PaymentsOriginColumnsTest.php:82-90`.

- Repro:
  - Change only the enum cast behavior for `PaymentOrigin::BackOffice`; the named round-trip test would not cover it.

- Fix:
  - Add `'back_office' => ['back_office', PaymentOrigin::BackOffice]` to the provider.

## false-positive checks I ran

- Anti-divergence: confirmed. `TreasuryDepositBridge` calls `PaymentAllocationService::applyAllocationFromCommand()` with `tenantId`, `companyId`, `paymentId`, `AllocationMethod::FIFO`, nullable actor, source, and `manualAllocations: null` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:133-141`. This mirrors `TreasuryAccountPaymentBridge.php:118-126`; only source string and origin differ.
- Partner-balance refresh: confirmed for GL branches that actually execute. Invoice allocation posts through `createPaymentReceivedJournalEntry()` at `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:261-278`, which refreshes at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:252-255`. Customer advance posts through `createCustomerAdvanceJournalEntry()` at `PaymentAllocationService.php:280-328`, which refreshes at `GeneralLedgerService.php:321-323`. The P1 is the actor guard that can prevent the advance branch from executing.
- Idempotency: no double-payment issue found for the intended PostgreSQL deployment. The bridge wraps create+allocation in one transaction, takes a PostgreSQL advisory transaction lock on event+projector, checks existing payments by `fiscal_event_id`, and compares tenant/company/partner/method/repository/currency/date/status/origin/created_by/amount before no-op: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:86-142` and `:239-297`. `payments.fiscal_event_id` is intentionally not unique because sale receipts can have multiple tender payments: `apps/api/database/migrations/tenant/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:33-39`.
- Customer resolution: no PosCustomerAlias drift found. The deposit bridge scopes by tenant, company, and customer/both partner type at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:145-160`; the payload validator enforces `customer.customer_id == partner_id` at `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:475-488`.
- PaymentOrigin/schema: confirmed append-only enum addition at `apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php:34-42`; existing `payments.origin` is `VARCHAR(32) NULL` with no enum/check migration surface at `apps/api/database/migrations/tenant/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:43-45`.
- Boundary: no new POS import in the deposit bridge. Imports mirror the analog's Accounting/Company/Fiscal/Identity/Partner/Treasury dependencies minus `PosCustomerAlias`: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:7-29`. `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` passed with 59 current violations against a 59 baseline and no growth.
- Registration/dispatch: provider tag includes `TreasuryDepositBridge::class` at `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:53-61`; `FiscalEventProjectionDispatcher` creates pending rows from the active projector set and uses the existing `(fiscal_event_id, projector_name)` idempotency path at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:77-130`.
- Test execution: `php artisan test tests/Feature/Fiscal/TreasuryDepositBridgeTest.php --display-warnings` passed 35 assertions, with 8 warnings caused by missing local `apps/api/.env`. `./vendor/bin/pint --test ...` passed.

---

## Resolution (Claude, 2026-06-09)

**P1-1 — null actor could skip CustomerAdvance GL + balance refresh: FIXED (fail-loud).**
`TreasuryDepositBridge::resolveActorUserId()` now THROWS a projection invariant
(`actor_not_found` / `actor_not_active_company_member`) before any Payment is created
when the payload actor does not resolve to an active member of the event's company —
instead of recording `created_by = null`. This closes the exact money-skip Codex found:
the shared `PaymentAllocationService` only posts the CustomerAdvance journal entry (and
its partner credit-balance refresh) when the command actor is a `User`, so a null actor
on the pure-advance path would have created a completed Payment whose overflow never
reached the credit balance. A server-authored back-office deposit is always recorded by
an authenticated company member (Phase 5 `RecordCustomerDepositService` passes the
authenticated user), so fail-loud rejects only genuinely invalid input — a justified
divergence from the device analog's null-cashier tolerance. Test:
`test_bridge_fails_loud_when_actor_lacks_company_membership` (no Payment created).

**P2-1 — missing allocation-throws rollback coverage: ADDED.**
`RecordingDepositAllocationService` gained `throwOnApply()`; new
`test_bridge_rolls_back_payment_when_allocation_throws` asserts `Payment::count() === 0`
after the allocation throws — proving create + allocation share one transaction.

**P2-2 — missing provider-registration assertion: ADDED.**
`test_bridge_contract_priority_and_provider_registration` now reads
`app->tagged(FiscalEventProjector::class)` and asserts `treasury_deposit_bridge` is
present, so dropping the provider tag (which would silently stop the money-movement row
from ever being created) fails the suite.

**NIT-1 — round-trip data provider: FIXED.**
`PaymentsOriginColumnsTest::paymentOriginRoundTripCases()` now includes
`'back_office' => ['back_office', PaymentOrigin::BackOffice]`.

Note on the deferred real-allocation test: the P1 fail-loud closes the only path where a
deposit could skip GL, so the remaining real-allocation FIFO-settle/overflow/balance
assertions stay in the Phase 5 full-flow test (spec §8), exercising the same shared
engine the device path already proves.

Gates after fixes: bridge + origin tests 24 green (5 PG-skipped); Pint pass; PHPStan L8
clean; deptrac unchanged (0 new violations); full Fiscal feature suite re-run below.
