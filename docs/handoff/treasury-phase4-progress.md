# Treasury Phase 4 — Expense Depth Progress

- Date: 2026-07-13 (Africa/Tunis)
- Binding handoff: `docs/handoff/CODEX-treasury-phase4-expense-depth-2026-07-13.md`

Progress, files touched, test evidence, deviations, and gate verdicts are appended here after each task.

## Task 16 — Outbound direction guards (complete)

- Files:
  - `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php`
  - `apps/api/app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php`
  - `apps/api/tests/Feature/Treasury/OutboundInstrumentGuardTest.php`
  - `docs/handoff/treasury-phase4-progress.md`
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/OutboundInstrumentGuardTest.php` — 5 failures across the five new Outbound guard assertions (transfer/deposit proceeded or status validation ran first); receive/cancel and inbound clear regressions passed.
- GREEN: `./vendor/bin/phpunit tests/Feature/Treasury/OutboundInstrumentGuardTest.php` — `OK (7 tests, 24 assertions)`.
- Regression: `./vendor/bin/phpunit tests/Feature/Treasury/OutboundInstrumentGuardTest.php tests/Feature/Treasury/InstrumentLifecycleReceiveTest.php tests/Feature/Treasury/InstrumentRemittanceServiceTest.php tests/Feature/Treasury/InstrumentRemittanceApiTest.php tests/Feature/Treasury/PaymentInstrumentTest.php` — `OK (51 tests, 188 assertions)`.
- Scoped PHPStan: `./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php tests/Feature/Treasury/OutboundInstrumentGuardTest.php --no-progress` — `[OK] No errors`.
- Pint: `./vendor/bin/pint --dirty` — exit 0; ordered imports fixed in the new test.
- `git diff --check` — exit 0, no output.
- Contract: the identical `DomainException` guard is immediately after `findOrFail` in `custodyTransfer`, `deposit`, `clear`, and `bounce`, and is the first check in `InstrumentRemittanceService::assertEligible`. `receive()`, `cancel()`, and `updateDetails()` remain direction-neutral by deliberate scope; an inbound instrument still completes deposit→clear.
- Deviations: none.
