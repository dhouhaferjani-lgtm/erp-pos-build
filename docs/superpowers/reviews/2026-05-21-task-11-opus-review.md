# Phase 2 Task 11 Opus-Style Review

Commit reviewed: `7f65be0af` (`Phase 2.11.1: Verify account payment full flow`)

Verdict: **REQUEST-CHANGES**

## Findings

### P1-1: Task 11 reintroduces the same fixed-clock flake Task33 just removed

- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:286`
- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:287`
- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:346`
- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:361`
- `apps/api/app/Modules/Fiscal/Application/Services/ClockAnomalyDetector.php:68`
- `apps/api/app/Modules/Fiscal/Application/Services/ClockAnomalyDetector.php:103`
- `apps/api/app/Modules/Fiscal/Application/Services/ClockAnomalyDetector.php:106`
- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:560`
- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:563`
- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:579`

The new Phase 2 closure fixture hard-codes the account-payment envelope time to `2026-05-21T10:15:30Z` and asserts a verified row. The production clock detector accepts only a 24-hour drift window. Once DB `CURRENT_TIMESTAMP` is more than 24 hours from that literal, `OutboxIngestor` will classify the event as `time_anomaly`, set `integrity_status = quarantined`, and the Task 11 assertions for `results.0.exception_class = null`, `IntegrityStatus::Verified`, and applied projections become unstable or fail for the wrong reason.

This is the exact failure mode fixed in Task33 at `apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php:112`: Task33 now derives `event_time_device`, payload timestamp, and `business_date` from current UTC. Task 11 needs the same pattern. Otherwise the Phase 2 closure test is only green because it is being reviewed on `2026-05-21`; it becomes stale shortly after `2026-05-22T10:15:30Z`.

Fix direction: derive a current UTC event time in `sealedEnvelope()` or the test body, pass it through the canonical envelope and account-payment payload, and keep `business_date`, `payload.event_time_device`, and balance/mirror timestamps internally consistent. Do not loosen the assertions to accept `time_anomaly`; that would weaken the closure test.

## Non-Blocking Review Notes

- Device-authored endpoint path: The Task 11 tests post to `/api/v1/pos/sync/fiscal-events` at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:161` and `:249`, so they exercise the controller + `OutboxIngestor` path rather than direct projector calls.
- Exact canonical storage and parse: The treasury-active test asserts `sha256(canonical_bytes)`, `current_hash`, exact `fiscal_events.canonical_bytes`, `PayloadParseStatus::Parsed`, and `CanonicalPayloadReader::forAccountPayment()` against the receipt snapshot at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:177-194`.
- POS-core printable projection: `AccountPaymentReceiptProjection` is always-active (`requiresModule() === null`) and writes from the canonical reader only at `apps/api/app/Modules/POS/Application/Projections/AccountPaymentReceiptProjection.php:38-72`; Task 11 asserts the resulting printable row in both treasury-active and POS-only flows.
- Treasury-active bridge + FIFO allocation: `TreasuryAccountPaymentBridge` is gated on `Treasury`, creates `Payment(origin=pos, fiscal_event_id=...)`, and invokes `AllocationMethod::FIFO` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:57-126`; Task 11 asserts the `Payment` and concrete `PaymentAllocation` row at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:196-210`.
- POS-only Treasury skip: The resolver double plus `forgetInstance(FiscalEventProjectionRegistry::class)` at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:226-236` is the right way to rebuild the singleton registry. The test confirms the POS receipt remains and Treasury projection/payment/allocation rows are absent at `:260-274`.
- D16 bounded-module seam: The registry asks `ModuleActivationResolver` only for gated projectors and leaves always-active POS-core projectors unaffected at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:240-272`. This matches the roadmap D16 language at `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md:19-21`.
- Cross-tenant/company FK safety: The full-flow Task 11 fixture is positive-path only, but the bridge code scopes customer, payment method, repository, and account lookups by `tenant_id` + `company_id` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:169-224`; existing bridge tests cover cross-company alias/customer/repository-account rejection. No new cross-company production regression found in this commit.
- Fail-loud vs silent downgrade: The active-flow test would fail if the Treasury bridge silently disappeared because it requires an applied `treasury_account_payment_bridge` projection row at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:217-220`. Existing bridge tests cover invariant exceptions; Task 11 does not add a new negative fail-loud case, but it does pin the full-flow happy path.
- Dead-path rebuild: No production fiscal ingestion path was modified in the commit. The closure test uses the rebuilt endpoint path and production projector registry.
- Contract drift and deployment gate visibility: The roadmap update preserves the Phase 1.5 tax-number deployment gate in both the top status and Phase 2 status at `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md:4` and `:71`, with the concrete gate still visible at `:61`.
- Task33 clock-flake fix: The Task33 change is directionally correct and does not materially weaken Task33 coverage. It keeps byte-equivalence assertions, projection assertions, and NF525 canonical export assertions, while avoiding a stale fixture timestamp.

## Verification Scope

Commands run:

```bash
git show --stat --oneline --decorate --no-renames 7f65be0af
git diff 7f65be0af^ 7f65be0af -- apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md
date -u '+%Y-%m-%dT%H:%M:%SZ'
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php
```

Observed focused test result:

```text
OK (3 tests, 89 assertions)
```

The focused tests pass on the current UTC clock (`2026-05-21T14:54:28Z` when checked), but that pass is inside the hard-coded Task 11 fixture's 24-hour clock-drift window and does not clear P1-1.
