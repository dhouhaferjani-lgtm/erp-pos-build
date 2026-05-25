# Task 10 R3 Opus-Style Second-Pass Review - Treasury ACCOUNT_PAYMENT Bridge

Commits reviewed:

- `7e7883222` - initial Treasury ACCOUNT_PAYMENT bridge
- `e6b84d5f7` - R2 customer alias hard-fail
- `b3bf64752` - R3 idempotency and repository account hardening

Verdict: **APPROVE**

## Scope Reviewed

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php`
- `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php`
- `apps/api/app/Shared/Contracts/Fiscal/FiscalEventProjector.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`
- `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md`
- `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md`
- Prior reviews:
  - `docs/superpowers/reviews/2026-05-21-task-10-opus-review.md`
  - `docs/superpowers/reviews/2026-05-21-task-10-r3-codex-review.md`

## Findings

No blocking or change-request findings.

## Adversarial Checks

### Prior P1: same `fiscal_event_id` non-POS Payment conflict

R3 closes this. `TreasuryAccountPaymentBridge::existingPaymentForEvent()` now queries by `fiscal_event_id` alone and fails on multiple rows before returning the single existing row:

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:260`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:263`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:266`

`assertExistingPaymentMatches()` includes `origin = PaymentOrigin::Pos` in the expected idempotency fields, so a WebAdmin/API/legacy row with the same immutable fiscal event ID cannot be silently accepted:

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:286`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:295`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:316`

The regression test covers the specific prior miss and asserts no duplicate allocation:

- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:193`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:207`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:218`

### Prior P1: repository account tenant/company safety before GL allocation

R3 closes this at the bridge boundary. `resolveRepository()` scopes the repository to the event tenant/company, requires `account_id`, and then verifies the referenced `accounts` row is active in the same tenant/company before creating the Payment or invoking allocation:

- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:205`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:216`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:220`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:227`

That guard protects the downstream allocation path that passes `repository->account_id` into GL journal creation:

- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:263`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:268`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:310`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:315`

The regression test covers a same-company repository whose `account_id` points to another company and asserts no Payment/allocation side effects:

- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:349`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:359`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:368`
- `apps/api/tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php:369`

### Cross-tenant/company FK safety

The bridge scopes the operational lookups that matter before persisting the Treasury `Payment`: customer alias, resolved Partner, payment method, repository, repository account, and cashier actor membership. I did not find a remaining cross-company FK acceptance path in the Task 10 bridge:

- Customer alias and Partner scope: `TreasuryAccountPaymentBridge.php:135`, `TreasuryAccountPaymentBridge.php:169`
- Payment method scope: `TreasuryAccountPaymentBridge.php:185`
- Repository scope and account scope: `TreasuryAccountPaymentBridge.php:205`, `TreasuryAccountPaymentBridge.php:220`
- Actor tenant plus company membership: `TreasuryAccountPaymentBridge.php:242`, `TreasuryAccountPaymentBridge.php:251`

The command boundary also re-scopes payment and document reads by explicit tenant/company rather than request context:

- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:140`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:166`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:377`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:386`

### Fail-loud vs silent downgrade

Missing or mis-scoped customer/method/repository/repository-account cases throw projection exceptions before Payment creation. Allocation exceptions bubble out of the wrapping transaction, and the test proves the created Payment rolls back:

- `TreasuryAccountPaymentBridge.php:157`
- `TreasuryAccountPaymentBridge.php:176`
- `TreasuryAccountPaymentBridge.php:192`
- `TreasuryAccountPaymentBridge.php:212`
- `TreasuryAccountPaymentBridge.php:227`
- `TreasuryAccountPaymentBridgeTest.php:373`
- `TreasuryAccountPaymentBridgeTest.php:384`

The only nullable downgrade is the planned actor behavior: unresolved or non-member cashier becomes `created_by = null` with the sealed cashier ID in notes:

- `TreasuryAccountPaymentBridge.php:240`
- `TreasuryAccountPaymentBridge.php:257`
- `TreasuryAccountPaymentBridge.php:333`
- `TreasuryAccountPaymentBridgeTest.php:388`

### Idempotency and duplicate allocation

The bridge checks for an existing payment before create/allocation, validates the existing row against the event-derived fields, and returns without invoking `PaymentAllocationService` on retry:

- `TreasuryAccountPaymentBridge.php:84`
- `TreasuryAccountPaymentBridge.php:86`
- `TreasuryAccountPaymentBridge.php:96`
- `TreasuryAccountPaymentBridgeTest.php:153`
- `TreasuryAccountPaymentBridgeTest.php:164`
- `TreasuryAccountPaymentBridgeTest.php:165`

The PostgreSQL advisory transaction lock remains in place for concurrent projectors sharing the same fiscal event ID:

- `TreasuryAccountPaymentBridge.php:71`
- `TreasuryAccountPaymentBridge.php:72`
- `TreasuryAccountPaymentBridge.php:73`

### D16 bounded-module boundary and dead-path wiring

The bridge is isolated to the Treasury module and declares the canonical gated token plus bridge priority:

- `TreasuryAccountPaymentBridge.php:57`
- `TreasuryAccountPaymentBridge.php:62`

The provider tags both Treasury bridges in `register()`, which matches the registry's tagged-set consumption:

- `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:51`
- `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:54`
- `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:33`
- `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:36`

The test locks the bridge contract and confirms the tagged projector is visible:

- `TreasuryAccountPaymentBridgeTest.php:401`
- `TreasuryAccountPaymentBridgeTest.php:411`
- `TreasuryAccountPaymentBridgeTest.php:416`

### Constructor injection and service location

The Task 10 production bridge uses constructor injection for its services and contains no `app()`, `App::make()`, or `resolve()` service-location calls:

- `TreasuryAccountPaymentBridge.php:42`
- `TreasuryAccountPaymentBridge.php:43`
- `TreasuryAccountPaymentBridge.php:44`

The `app->tag()` and `app->singleton()` calls reviewed in `TreasuryServiceProvider` are framework provider registration, not runtime service-location inside the bridge.

### Contract drift and R3 regression scan

The current behavior matches the Task 10 plan's core bridge contract:

- Creates a Treasury `Payment` with event tenant/company, scoped customer, scoped method/repository, `origin=pos`, `fiscal_event_id`, and resolved actor where possible.
- Invokes `ApplyPaymentAllocationCommand` with explicit tenant/company, `AllocationMethod::FIFO`, actor from the sealed cashier where resolvable, and `source='fiscal_event:ACCOUNT_PAYMENT'`.
- Treats any existing same-`fiscal_event_id` conflicting Payment as a hard idempotency conflict.
- Keeps POS-core independent and gates Treasury operational projection on the `Treasury` module.

The plan snippet says `PaymentType::Receipt`, but the enum has no such case; using `PaymentType::DocumentPayment` is consistent with the existing Treasury enum and account-payment allocation flow:

- `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md:835`
- `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:7`
- `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:10`
- `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:56`

I did not find an R3-introduced defect.

## Verification Scope

Commands run from `apps/api`:

```bash
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php
```

Result: `OK (15 tests, 59 assertions)`.

```bash
./vendor/bin/phpstan analyse --level=8 app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php app/Modules/Treasury/Providers/TreasuryServiceProvider.php tests/Feature/Fiscal/TreasuryAccountPaymentBridgeTest.php
```

Result: `No errors`.

I did not run the full repository CI matrix in this pass.
