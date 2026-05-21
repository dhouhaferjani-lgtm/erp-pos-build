# Task 11 R2 Codex Self-Adversarial Review - Account Payment Full-Flow Closure

Commits reviewed:

- `7f65be0af` - Task 11 full-flow closure test and roadmap update
- `9c4b8d121` - R2 account-payment closure timestamp stabilization

Verdict: **APPROVE**

## R2 Fix Reviewed

Opus P1-1 was valid: Task 11's new `ACCOUNT_PAYMENT` closure fixture used a fixed `2026-05-21T10:15:30Z` envelope timestamp, which would cross the production 24-hour `time_anomaly` threshold exactly like the Task33 fixture did.

R2 fixes the source fixture:

- `sealedEnvelope()` now derives `event_time_device` from current UTC minus 30 seconds.
- The payload `event_time_device` uses the same instant with millisecond precision.
- `business_date` is derived from that same current UTC instant and threaded into both envelope and payload.
- The test continues to require `exception_class = null`, `IntegrityStatus::Verified`, parsed payload, POS-core projection, and Treasury/POS-only projection semantics. It does not weaken the closure test to accept quarantined time-anomaly output.

## Verification Evidence

- Focused closure check: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php` -> 3 tests / 89 assertions.
- Full backend gate: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` -> 1124 tests / 3820 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- PHPStan L8: `./vendor/bin/phpstan analyse --level=8 tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php` -> no errors.
- Pint: `./vendor/bin/pint --test tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php` -> pass.
- POS gates from the same Task 11 change set before R2: `pnpm test` -> 162 files / 1445 tests, `pnpm typecheck` -> pass, `pnpm lint` -> 0 errors / 41 existing warnings.
- Chokepoint + sentinel from the same Task 11 change set before R2: `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && test ! -e apps/pos/src/lib/offline/.PASS_2B_PENDING` -> pass.

## Standing-Pattern Checks

- Cross-tenant/company FK safety: **PASS**. R2 only changes fixture timestamps. Task 11's positive path still asserts tenant/company identity on the stored event, printable receipt, and Treasury payment.
- Fail-loud vs silent downgrade: **PASS**. The test still demands `exception_class = null` and verified integrity; it does not silently accept a time-anomaly downgrade.
- Dead-path rebuild: **PASS**. The test remains endpoint-driven through `/api/v1/pos/sync/fiscal-events` and production projection dispatch.
- D16 bounded-module guard: **PASS**. POS-only resolver test still rebuilds the registry and asserts printable projection without Treasury rows.
- Canonical byte equivalence: **PASS**. The timestamp is now dynamic, but canonical bytes remain computed once and asserted verbatim through `fiscal_events` and the reader/projection path.
- Contract drift: **PASS**. Roadmap still preserves the Phase 1.5 per-country tax-number launch gate.
- Per-method skips / skip-citation accuracy: **PASS**. No new skips.
- Constructor injection / service location: **PASS**. Production code unchanged.
- R2-introduces-defect pattern: **PASS**. R2 was rechecked with focused tests, PHPStan, Pint, and full backend suite. No new defect found.

Residual risk: R2 leaves `balance_updated_at` and `mirror_last_synced_at` fixed inside the payload snapshot because those are business/customer mirror timestamps, not the fiscal envelope clock used by `ClockAnomalyDetector`. They remain parse-valid and do not influence `time_anomaly`.
