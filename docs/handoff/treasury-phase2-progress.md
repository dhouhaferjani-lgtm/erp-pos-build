# Treasury Phase 2 — Execution Progress

## Execution context

- Branch: `feat/treasury-instruments`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-instruments`
- Base: `origin/dev` at `f0f9cecc91e1adc310533363525c7ab1a6db5c3f`
- Plan verification: the Rev 2 plan is present on `origin/dev`; its latest commit is the base commit above.
- Dependency setup: `pnpm install --frozen-lockfile` and `composer install --no-interaction --prefer-dist` completed successfully in the worktree.
- Baseline verification: `./vendor/bin/phpunit tests/Feature/Treasury/PaymentInstrumentTest.php tests/Feature/Treasury/PaymentMethodTest.php` — PASS, 24 tests / 67 assertions.
- Deviations: none.
- Contradictions: none. The handoff's explicit owner execution decision supersedes the older status labels embedded in the Rev 2 spec/plan.

## Task log

### Task 1 — Portfolio accounts, resolver, EF journal code

- Status: complete.
- Files touched: TN/FR/Generic chart seeders; `JournalCode`; new `InstrumentAccountPurpose`, `MissingInstrumentAccountException`, and `InstrumentAccountResolver`; resolver and architecture tests; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentAccountResolverTest.php tests/Architecture/PortfolioAccountReservationTest.php` — expected failure, 3 missing-class/enum errors and 1 missing-resolver-file assertion.
- GREEN: same command — PASS, 4 tests / 11 assertions.
- Verification: targeted PHPStan — zero errors; `./vendor/bin/pint --dirty` — pass; `git diff --check` — pass.
- Plan/code mismatch: `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` is not inside this repository; the actual file is in the parent Syneriva repository (`../../docs/...`), outside the mandated worktree. No external file will be edited; the required parent-log note is deferred and recorded here.
- Contract clarification: implement `resolve(): ?string` and `resolveOrFail(): string`. This preserves the plan's explicit throwing/non-throwing pair; a non-null `resolve()` plus separately throwing `resolveOrFail()` would not provide distinct behavior.
- Money-path deviation: none. Account codes and EF routing match spec §3/§5.6.

### Task 2 — Payment-instrument portfolio columns and enums

- Status: complete.
- Files touched: portfolio migration; `PaymentInstrument`; `InstrumentStatus`; new direction/kind/origin/dishonor-routing enums; portfolio column test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/PaymentInstrumentPortfolioColumnsTest.php` — expected failure, 2 missing-enum errors.
- Intermediate regression caught: the first implementation incorrectly added `Cleared` to `canClear()`; the test failed at the `canBounce()` assertion. Corrected to preserve `canClear()` and extend `canBounce()` only.
- GREEN: task + legacy instrument paths — PASS, 13 tests / 38 assertions.
- Verification: migration `up()` re-run twice in-test; duplicate non-null key rejected and multiple null keys accepted; targeted PHPStan — zero errors; Pint — clean after formatting; `git diff --check` — pass.
- Ordering note: the typed `remittance()` relation is deferred to Task 5, which creates the `InstrumentRemittance` model. Adding a phantom/stub production model in Task 2 would violate TDD and makes PHPStan unable to resolve the relation; `remittance_id` lands here as planned.
- Money-path deviation: none. This task changes schema/state guards only and does not move or post money.

### Task 3 — Payment-method instrument kind and configuration guards

- Status: complete.
- Files touched: guarded migration; `PaymentMethod`; `PaymentMethodController`; `PaymentMethodSeeder`; new Task-3 test; updated legacy payment-method API fixture; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/PaymentMethodInstrumentKindTest.php` — expected 3 validation failures plus 1 duplicate-seeder constraint error.
- GREEN: task + legacy method paths — PASS, 19 tests / 70 assertions.
- Verification: migration `up()` re-run twice in-test; seeders run twice for TN and FR with stable counts; exact CHECK/TRAITE/LCR/BILL_EXCHANGE/DIRECT_DEBIT mappings asserted; targeted PHPStan — zero errors; Pint — pass; `git diff --check` — pass.
- Implementation note: removed the seed command's optional console-only status messages because Laravel's non-null property declaration conflicts with its runtime-null behavior under direct seeder invocation and failed level-8 static analysis; seeding behavior is unchanged.
- Money-path deviation: none. `DIRECT_DEBIT` is explicitly `Other`, preserving Phase-1 cash behavior for later cutover tasks.

### Task 4 — Immutable instrument event log

- Status: complete.
- Files touched: instrument-events table and PostgreSQL immutability migrations; new `InstrumentEvent`, `InstrumentEventType`, and `InstrumentEventPayload`; event immutability/DTO test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentEventsImmutabilityTest.php` — expected 2 missing DTO/migration errors; 2 PostgreSQL-only trigger tests skipped.
- GREEN: task path — PASS, 4 tests / 5 assertions / 2 PostgreSQL-only skips. Task + existing repository immutability regression — PASS, 6 tests / 5 assertions / 4 PostgreSQL-only skips.
- Verification: both migrations re-run three times in-test; typed payload round-trip and model relation asserted; targeted PHPStan — zero errors; Pint — pass; `git diff --check` — pass.
- PostgreSQL note: raw UPDATE/DELETE trigger assertions remain part of the Gate-1 PostgreSQL directory run; the local fast loop is SQLite and correctly skips them.
- Money-path deviation: none. Event rows only record lifecycle facts and do not post GL or move repository balances.
