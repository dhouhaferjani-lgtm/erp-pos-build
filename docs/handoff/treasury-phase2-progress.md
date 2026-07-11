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

### Task 9 — Dishonor routing, fees, allocation reopening, and bank claw-back

- Status: complete.
- Files touched: new bounce DTO and guarded `payments.dishonored_at` migration; `Payment` cast/fillable metadata; lifecycle bounce transaction; GL dishonor and tolerance-counter-entry builders; focused bounce test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentBounceTest.php` — expected 4 errors because `InstrumentLifecycleService::bounce()` did not exist.
- GREEN: task path — PASS, 8 tests / 43 assertions / 1 PostgreSQL-only lock-trace skip. Wave-B lifecycle/remittance/movement regression paths — PASS, 37 tests / 142 assertions / 4 PostgreSQL-only skips.
- Verification: both fee-only bounce and post-clear dishonor run `treasury:reconcile --tenant=...` in-test and remain unfrozen; targeted PHPStan including `GeneralLedgerService` — zero errors; Pint — pass; `git diff --check` — pass.
- Accounting evidence: before-clear effet bounce posts `Dr 411 / Cr 5313` with no nominal movement; a cheque fee bounce moves exactly fee+VAT and its bank credit matches; post-clear dishonor moves exactly nominal+fee+VAT and its bank credit matches; doubtful routing debits 416; re-present routing preserves allocations and the Paid document.
- Subledger evidence: document rows are locked in sorted id order before any GL advisory lock; positive payment allocations receive append-only negative mirrors, tolerance-only (`payment_id IS NULL`) allocations receive negative mirrors, their original tolerance JEs receive synchronously posted mirror counter-entries, and reopened documents become `Posted` with the full balance restored. The linked payment is stamped `dishonored_at` for every dishonor routing.
- Atomicity/lock evidence: a closed-period GL rejection rolls back all JEs, movements, allocation mirrors, balances, payment stamp, instrument state, and slip state. A PostgreSQL-only query-trace assertion pins `instrument FOR UPDATE -> documents ORDER BY id FOR UPDATE -> pg_advisory_xact_lock`; the final repository lock remains owned by the movement port.
- Test-harness note: the repository's `RefreshDatabase` outer transaction prevents a second connection from observing freshly seeded fixtures, so the PostgreSQL concurrency pin validates the exact production lock acquisition trace rather than running two independently committed writers. Gate 1 will run this PostgreSQL path and may require a separate non-transactional harness if the reviewer considers the trace insufficient.
- Planned ownership note: `PaymentController::formatPayment()` exposure of `dishonored_at` remains in Task 14, where the Rev 2 plan explicitly assigns it; Task 9 establishes the stored/cast backend fact.
- Money-path deviation: none. Every bank effect is a port movement linked to a synchronously posted EF entry; nominal before-clear bounce remains movement-free as specified.

### Task 10 — Instrument lifecycle events in the compliance audit trail

- Status: complete.
- Files touched: compliance domain-event subscriber; new focused instrument audit-trail test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Compliance/InstrumentAuditTrailTest.php` — expected 2 failures: all five subscriptions absent and a receive→deposit→clear cycle produced zero instrument audit rows.
- GREEN: task path — PASS, 2 tests / 6 assertions. Task + existing subscriber + clear/bounce regressions — PASS, 30 tests / 162 assertions / 1 PostgreSQL-only skip.
- Verification: targeted PHPStan — zero errors; Pint — pass; `git diff --check` — pass.
- Audit evidence: `InstrumentReceived`, `InstrumentDeposited`, `InstrumentCleared`, `InstrumentBounced`, and `InstrumentTransferred` share one typed subscriber handler; rows use aggregate type `PaymentInstrument`, the instrument id, the immutable domain-event payload, and the canonical event name. The full receive→deposit→clear service cycle leaves exactly the three ordered typed audit rows.
- Money-path deviation: none. Audit dispatch remains after-commit side-effect handling; lifecycle, GL, and movement writes are unchanged.

## Gate 1

### RC1 pre-review verification

- `./vendor/bin/phpunit tests/Feature/Treasury` — PASS, 489 tests / 1,744 assertions / 22 environment-specific skips; 39 existing PHPUnit deprecations reported.
- `./vendor/bin/phpstan` — analyzer reached all 2,458 files but its parallel workers exhausted the configured 512 MB process limit; no code diagnostic was emitted before the infrastructure crash.
- `./vendor/bin/phpstan --memory-limit=2G` — PASS, all 2,458 files, zero errors. The explicit limit is the analyzer-prescribed rerun for the same full level-8 configuration.
- `./vendor/bin/pint --dirty` — pass, no changes.
- `git diff --check` — pass; worktree clean before the RC review commit.
- Task 8/9 reconcile pins are included in the passing Treasury directory run.

### Gate 1 verdict

- RC attempts: 1.
- Reviewer: `claude-opus-4-8`; escalation to Fable was not triggered because the verdict was APPROVE and there were no BLOCKER/HIGH findings or money-path uncertainty.
- Review artifact: `docs/handoff/gate-reviews/GATE-1-rc1.md`.
- Verdict: `VERDICT: APPROVE`.
- Non-blocking carry-forwards: keep the linked-payment lock position explicit as Wave C adds refund/payment guards; consider a committed-fixture two-connection concurrency harness; strengthen the portfolio-account reservation assertion before reconcile check #4; remove legacy controller event dispatches in Task 11; perform controller-level pre-transaction portfolio-account validation in its assigned task.
- Artifact note: the reviewer returned the complete report but its own sandbox could not create `docs/handoff/gate-reviews/`; the exact returned review body was persisted through the worktree editing path, with the required final verdict line.

### Task 11 — Payment-instrument HTTP surface rework

- Status: complete.
- Files touched: instrument controller and routes; role/permission seeder; generated shared TypeScript declarations; expanded instrument API and legacy event-dispatch tests; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/PaymentInstrumentTest.php` — expected contract failures: missing `instruments.update`, absent PATCH route, wrong hard-coded TND default, unpaginated response, missing dedicated bounce permission, and missing company predicate. Test fixtures that initially called a nonexistent instrument factory were corrected before implementation; the contract failures remained.
- GREEN: instrument API path — PASS, 18 tests / 49 assertions. Instrument API + legacy event-dispatch regression — PASS, 26 tests / 67 assertions. Final instrument API + event dispatch + audit + clear/bounce regression paths — PASS, 41 tests / 140 assertions / 1 PostgreSQL-only skip.
- Verification: targeted PHPStan on controller, seeder, and both API/event tests — zero errors; Pint — pass; `php artisan route:list --path=payment-instruments` shows all 8 expected routes including PATCH; `git diff --check` — pass.
- HTTP/security evidence: every lookup is UUID-guarded and tenant+company scoped; index is paginated with `meta` and supports status/kind/direction/partner/repository/needs-details/maturity filters; PATCH delegates to `updateDetails`; all transitions delegate to the lifecycle service; bounce uses `instruments.bounce`; new update/bounce/remit permissions are granted to manager/accountant and to admin through the all-permissions role.
- Lifecycle/audit evidence: controller-owned state mutations and event dispatches were deleted. A deposit through the endpoint leaves exactly one typed audit row, proving the lifecycle's after-commit dispatch is not duplicated.
- Validation evidence: store snapshots `instrument_kind`, defaults currency from the active company, resolves the required portfolio account before entering the receive transaction, and leaves no instrument on a missing-account 422.
- Type generation: `CACHE_STORE=array php artisan typescript:transform` exited successfully and generated the Phase-2 EF/instrument/remittance enum declarations in `packages/shared/types/generated.d.ts`; the command printed the repository's production-environment warning/cancel banner before its normal 425-type transform table, but the transform completed and the generated diff contains only the expected enums.
- Money-path deviation: none. HTTP transitions are thin delegates; clear/bounce remain the only endpoint paths that can reach the movement port.

### Task 12 — Remittance (bordereau) HTTP API

- Status: complete.
- Files touched: new remittance controller; treasury routes; focused remittance API test; this progress log.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentRemittanceApiTest.php` — expected 3 route-not-found failures (the cross-company 404 assertion was incidentally green while the surface was absent).
- GREEN: API path — PASS, 4 tests / 26 assertions. API + remittance-service + instrument-controller regressions — PASS, 30 tests / 106 assertions.
- Verification: targeted PHPStan on controller/routes/test — zero errors; Pint — pass; `php artisan route:list --path=instrument-remittances` shows all 8 expected routes; `git diff --check` — pass.
- Surface evidence: create/list/show, add/remove line, remit, per-line clear, and per-line bounce are present; reads are paginated; all slip/line ids are UUID-guarded; every slip lookup is tenant+company scoped; add-line validation scopes instruments to the active tenant/company.
- Permission evidence: create/compose/read/remit use `instruments.remit`, settlement uses `instruments.clear`, and dishonor uses `instruments.bounce`; a remit-only user is forbidden from both settlement actions until the independent permission is granted.
- Lifecycle evidence: the two-line API flow creates a draft, remits both instruments, clears each via the lifecycle service, and reports the slip `closed` only after the last pending line settles. A draft line can be removed; the same mutation after remit returns 422.
- Money-path deviation: none. The controller never writes slip, instrument, journal, movement, or repository state directly.

### Task 13 — Deferred customer tenders enter the portfolio, not cash

- Status: complete.
- Files touched: `PaymentController`; new deferred-tender payment feature test; this progress log. `GeneralLedgerService` required no signature change because both customer-payment and customer-advance builders already accept an explicit debit account id.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/DeferredTenderPaymentTest.php` — expected missing-instrument error plus three contract failures: portfolio debit absent, repository/withholding guards absent, and a Deposited supplied instrument accepted.
- GREEN: task path — PASS, 7 tests / 28 assertions. Task + five existing payment/spine/idempotency suites — PASS, 50 tests / 241 assertions; 20 existing PHPUnit deprecations reported.
- Verification: targeted PHPStan on the controller and task test — zero errors; Pint — pass; `git diff --check` — pass.
- Lock/atomicity evidence: a supplied instrument is revalidated and locked before any allocated document lock; inline receive creates the new instrument before Payment/allocation/GL work. A missing AR account after instrument creation rolls back instrument, payment, allocation, document balance, links, and JEs.
- Accounting evidence: inbound effet payment debits 413 and cheque payment debits 5312; an excess payment's allocation JE and advance JE both debit the portfolio account, and their total equals the full payment/instrument amount. Both entries post synchronously in-transaction. No repository movement is recorded and the repository balance remains unchanged.
- Validation/link evidence: deferred customer methods require exactly one inline instrument or eligible Received/unlinked/partner+kind+amount+currency-matching `instrument_id`, require repository custody, require effet maturity, and reject withholding. Both `payments.instrument_id` and `payment_instruments.payment_id` are written atomically.
- Compatibility evidence: immediate cash still posts to the repository GL account and writes one movement; `has_maturity + InstrumentKind::Other` remains on that same immediate path with no instrument.
- Currency note: single-payment currency now defaults to the active company currency. The remaining Task-14 literal sweep stays assigned to Task 14.
- Money-path deviation: none. Receipt-time deferred tenders post portfolio GL only and never call the movement port.
