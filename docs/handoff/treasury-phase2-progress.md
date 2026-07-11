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

### Task 5 — Instrument remittance schema and numbering

- Status: complete.
- Files touched: remittance/line migration; new remittance models and three enums; `PaymentInstrument.remittance()` relation; new schema/numbering test; Task-2 FK-aware fixture update; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentRemittanceSchemaTest.php` — expected 4 missing-model/migration errors and 1 PostgreSQL advisory-lock skip.
- GREEN: Task 5 + Task 2 portfolio regression — PASS, 8 tests / 28 assertions / 1 PostgreSQL-only skip.
- Verification: schema and relations round-trip; duplicate `(remittance_id, instrument_id)` rejected; migration re-run twice with FK detection; sequential numbers `REM-{YYYY}-0001/0002`; targeted PHPStan — zero errors; Pint — pass; `git diff --check` — pass.
- Test-harness deviation: true two-connection allocation contention is represented by a PostgreSQL advisory-lock query assertion plus gapless sequential allocation and the DB unique constraint, mirroring the repository's `GlChainSequenceConcurrencyTest` rationale that independent connections are flaky under `RefreshDatabase`'s uncommitted outer transaction. The PostgreSQL lock assertion runs at Gate 1.
- Money-path deviation: none. Remittance numbering and schema do not post GL or move repository balances.

### Task 6 — Lifecycle receive/custody/cancel/updateDetails

- Status: complete.
- Files touched: new receive DTO, cancellation-shape enum, received domain event, lifecycle service; new GL cancellation builder; treasury provider binding; lifecycle test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentLifecycleReceiveTest.php` — expected 6 missing-service errors.
- GREEN: task path — PASS, 6 tests / 23 assertions. Task + instrument-event immutability + `postEntryNow` atomicity regressions — PASS, 15 tests / 43 assertions / 2 PostgreSQL trigger skips.
- Verification: targeted PHPStan including `GeneralLedgerService` — zero errors; Pint — pass; `git diff --check` — pass.
- Lock/money evidence: each mutating entrypoint locks the instrument before GL; cancellation uses `postEntryNow` inside the transaction; B2B reversal is `Dr 411 / Cr 5312`, journal `EF`; receive, custody, details, and unlinked cancellation create zero movements and zero unintended JEs.
- Ordering note: `TreasuryMovementServiceInterface` injection is deferred to Task 8, its first consumer. Keeping an unread injected port through Tasks 6–7 fails level-8 PHPStan; no Task-6 transition is permitted to move repository money.
- Money-path deviation: none. The injection timing differs only to satisfy static analysis; cancellation posting shape and synchronous transaction contract match spec §6/§7/§12.4.

### Task 7 — Remittance composition, remit posting, deposit wrapper

- Status: complete.
- Files touched: new remittance application service; lifecycle deposit wrapper; GL aggregate-remittance and re-presentation builders; provider binding; remittance service test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentRemittanceServiceTest.php` — expected 5 missing-service errors, 1 missing-deposit error, and 1 exception-type mismatch.
- GREEN: task path — PASS, 8 tests / 31 assertions. Task + lifecycle + schema regressions — PASS, 17 tests / 62 assertions / 1 PostgreSQL-only skip before the two final coverage pins were added.
- Verification: targeted PHPStan including `GeneralLedgerService` — zero errors; Pint — pass; `git diff --check` — pass.
- Lock/money evidence: remit locks instrument ids in sorted order before slip rows and `postEntryNow`; effet slips post one `Dr 5313 / Cr 413` EF entry for the exact total; cheque slips post no JE; cheque re-presentation posts `Dr 5312 / Cr 411`; no remit/custody movement is written.
- Rollback interpretation: a failed remit leaves the already-created draft/line intact but rolls back every mutation attempted by `remit()` (status, JE, event). This is the only coherent interpretation for a composition API whose draft exists before the remit call.
- Money-path deviation: none. Every GL post is synchronous and occurs after id-sorted instrument locks; repository balances remain untouched.

### Task 8 — Clearing with bank credit, fees, and movement-in

- Status: complete.
- Files touched: new clear DTO; lifecycle clearing transaction; GL clearing-entry builder; focused clearing test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentClearTest.php` — expected failure because `ClearInstrumentData` and `InstrumentLifecycleService::clear()` did not exist.
- GREEN: task path — PASS, 5 tests / 24 assertions. Task + remittance + movement-port regressions — PASS, 23 tests / 76 assertions / 3 PostgreSQL-only skips.
- Verification: in-test `treasury:reconcile --tenant=...` exits 0 and leaves the bank repository unfrozen; targeted PHPStan including `GeneralLedgerService` — zero errors; Pint — pass; `git diff --check` — pass.
- Lock/money evidence: clear locks the instrument and its pending slip line before `postEntryNow`; the movement port is called only after the posted EF entry and owns the final repository-row lock. Zero-fee clearing creates two lines; fee/VAT clearing creates exact bank-net, 6275, 43666, and portfolio-credit lines. The movement amount and bank-account JE debit compare equal at scale 3, with no float conversion.
- Atomicity/idempotency evidence: a second serialized clear is rejected and leaves one movement; a closed-period `postEntryNow` failure rolls back the draft JE, movement, repository balance, instrument status, and slip state. This pins the production concurrency outcome because the first operation's instrument `FOR UPDATE` lock serializes competing calls before the status guard.
- Money-path deviation: none. Repository balance changes exclusively through `TreasuryMovementServiceInterface::record`, linked to the synchronously posted JE.
