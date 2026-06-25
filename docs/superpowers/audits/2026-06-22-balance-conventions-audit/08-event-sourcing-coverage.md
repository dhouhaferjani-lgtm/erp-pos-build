# Phase 0 Audit — Event-Sourcing And Projection Coverage

HEAD: `5cf94a1f04731258704792b7aa07f714e9c718c3` on `fix/balance-ar-event-hardening`.

## Findings

### P1 — Financial State Changes Without Matching Fiscal Events

Treasury refunds/reversals mutate payments and allocations but do not have a fiscal event producer/projector, despite `FiscalEventType::ACCOUNT_REFUND` being present.

Evidence:
- Refund mutation sites: `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:132`, `:218`, and `:302`.
- `ACCOUNT_REFUND` exists in the enum: `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php:23`.
- The payload registry implemented map does not include it: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:53`.
- The validator default throws for missing per-event clauses: `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:393`.

Payment allocation and bank reconciliation also emit domain events but are not in the compliance subscriber map:
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:342`.
- `apps/api/app/Modules/Treasury/Application/Services/BankReconciliationService.php:208`.
- Subscriber map: `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:943`.

### P1 — Manual Journal Lifecycle Bypasses Accounting Audit Events

`JournalEntryCreated` and `JournalEntryPosted` exist, and `GeneralLedgerService::postEntry()` emits `JournalEntryPosted`, but `JournalEntryController` creates/posts manually without those events.

Evidence:
- `JournalEntryCreated`: `apps/api/app/Modules/Accounting/Domain/Events/JournalEntryCreated.php:9`.
- `JournalEntryPosted`: `apps/api/app/Modules/Accounting/Domain/Events/JournalEntryPosted.php:9`.
- Service emits `JournalEntryPosted`: `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1133`.
- Manual create/post writes directly: `apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:85` and `:154`.

### P2 — Fiscal Event Policy Is Implicit

`FiscalEventType` contains intentionally reserved cases and implemented-but-ledger-only cases, but no matrix test documents the policy across enum, payload registry, validator, projector, and canonical reader coverage.

Evidence:
- Reserved/high-impact cases start at `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php:13`.
- Implemented set excludes reserved cases: `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php:45`.
- Registry map starts at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:53`.
- Existing tests codify some reserved behavior: `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:89`.

### P2 — Implemented Events With No Projection Rows

Projectors cover POS and Treasury receipt/account/deposit flows, but implemented/validated events such as chain break/restart, terminal snapshots, account status changes, operator approvals, and overrides do not create fiscal projection rows.

Evidence:
- `FiscalEventProjectionRegistry::activeProjectorsFor()` filters by `handlesEventType()`: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:233`.
- Projector tagging is in `POSServiceProvider`, `TreasuryServiceProvider`, and `DocumentServiceProvider`.

## Acceptance Criteria

- Add a matrix test over every `FiscalEventType::case` proving its policy: reserved-unreachable, ledger-only, audit-only, or projected.
- For account refunds, allocations, reconciliation, credit issue/usage, reprints, voids, and returns, either implement fiscal events and projectors/readers or explicitly enforce why they are audit-only.
- Route manual journal create/post through services that dispatch accounting events, or dispatch and subscribe equivalent audit events.

## Test Plan

- Run `php artisan test --filter FiscalEventPayloadRegistryTest`.
- Add coverage matrix tests for enum, registry, validator, projectors, and canonical readers.
- Add feature tests asserting audit/fiscal coverage for manual journal create/post, payment allocation, bank reconciliation completion, and account refunds.

