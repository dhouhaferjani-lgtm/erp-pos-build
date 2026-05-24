# Phase 2 Task 11 R2 Opus-Style Review

Commits reviewed:

- `7f65be0af` (`Phase 2.11.1: Verify account payment full flow`)
- `9c4b8d121` (`Phase 2.11.2: Stabilize account payment closure timestamp`)

Prior reviews:

- `docs/superpowers/reviews/2026-05-21-task-11-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-11-r2-codex-review.md`

Verdict: **APPROVE**

## Findings

No blocking findings.

## R2 Timestamp-Flake Review

R2 fully closes the prior P1 without weakening Task 11 coverage.

- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:286-300` now derives the envelope `event_time_device` from `now('UTC')->subSeconds(30)`, keeping it inside the production 24-hour drift tolerance enforced by `ClockAnomalyDetector`.
- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:287-289` derives payload timestamp and `business_date` from the same instant.
- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:343-363` threads those dynamic values into the canonical ACCOUNT_PAYMENT payload instead of retaining the stale `2026-05-21T10:15:30Z` literal.
- The test still demands the happy-path integrity result: `results.0.exception_class = null`, `IntegrityStatus::Verified`, `PayloadParseStatus::Parsed`, exact `canonical_bytes`, POS receipt projection, Treasury payment, FIFO allocation, and projection rows at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:161-220`. It did not loosen assertions to tolerate `time_anomaly` or quarantined rows.

This matches the Task33 stabilization pattern at `apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php:112-122`: current UTC event time, payload millisecond timestamp, and business date are derived together before sealing canonical bytes.

The remaining fixed payload mirror timestamps at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:364` and `:396` are balance snapshot metadata, not the envelope clock used by the time-anomaly detector. I found no evidence that they can reintroduce the original 24-hour flake.

## Task 11 Coverage Axes Re-Checked

- Endpoint-driven ACCOUNT_PAYMENT sync: still posts to `/api/v1/pos/sync/fiscal-events` at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:161-163` and `:249-251`.
- Exact canonical byte storage and parse: still asserts `sha256(canonical_bytes)`, stored byte equality, verified integrity, parsed payload, and canonical reader parity at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:177-194`.
- POS-core printable projection: still asserts `AccountPaymentReceipt` tenant/company/customer/amount/snapshot and applied `pos_core_account_payment_receipt` projection at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:183-194` and `:212-216`.
- Treasury-active Payment + FIFO allocation: still asserts POS-origin completed `Payment` and concrete `PaymentAllocation` against the invoice at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:196-210`, with the bridge invoking FIFO via `TreasuryAccountPaymentBridge.php:118-126`.
- POS-only Treasury skip: still overrides `ModuleActivationResolver`, rebuilds the registry, requires the POS receipt, and asserts no Treasury payment/allocation/projection rows at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:224-274`.
- D16 bounded-module guard: POS receipt projector remains always-active via `requiresModule(): ?string { return null; }` at `apps/api/app/Modules/POS/Application/Projections/AccountPaymentReceiptProjection.php:38-41`; Treasury bridge remains gated on `Treasury` at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:57-60`; registry filtering still honors module activation at `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:233-272`.
- Cross-tenant/company safety: R2 changed only fixture timestamps. The full-flow test still asserts tenant/company identity across fiscal event, receipt, and payment at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:171-199`; the Treasury bridge still scopes customer, payment method, repository, and repository account lookups by event tenant/company at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:169-224`.
- Fail-loud vs silent downgrade: active-flow coverage still fails if Treasury silently disappears because it requires an applied `treasury_account_payment_bridge` row at `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php:217-220`; bridge invariant paths still throw projection exceptions, e.g. `customer_not_found`, `payment_method_not_found`, and repository-account failures at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:176-235`.
- Contract drift: the R2 diff is limited to the closure fixture timestamp plumbing; ACCOUNT_PAYMENT event type, payload keys, canonical encoding, and projection names are unchanged.
- Phase 1.5 tax-number deployment gate visibility: roadmap still says Phase 2 is not customer-facing deployment-ready until per-country tax-number validation lands at `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md:4` and `:71`, with the concrete pre-Tunisia gate preserved at `:61`.

## Verification Scope

Commands run:

```bash
git status --short --branch
git show --stat --oneline --decorate 7f65be0af
git show --stat --oneline --decorate 9c4b8d121
git show --format=fuller --find-renames --find-copies 9c4b8d121 -- apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php
git diff 7f65be0af 9c4b8d121 -- apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php
```

Focused test result:

```text
OK (3 tests, 89 assertions)
```

I did not rerun the full backend suite, PHPStan, Pint, web tests, or e2e tests for this second-pass review because R2 only changes timestamp fixture plumbing in one PHP test file. The focused closure tests cover the prior flake and the Task33 comparison path directly.
