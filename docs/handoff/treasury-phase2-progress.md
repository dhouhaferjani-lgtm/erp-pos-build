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

### Task 14 — Supplier registration, side-door blocks, and refund guards

- Status: complete.
- Files touched: single/multi payment controllers; payment refund service; new en/fr Treasury backend translations; focused deferred-guard test; this progress log. `Payment::instrument()` already existed with the correct typed relation, so no model edit was needed.
- RED: `./vendor/bin/phpunit tests/Feature/Treasury/DeferredTenderGuardsTest.php` — expected missing outbound-instrument error and side-door acceptance failure. The first refund fixture initially mutated one payment across three operations and was corrected to independent payments so each guard was tested at the true pre-write boundary.
- GREEN: task path — PASS, 7 tests / 25 assertions. Task + deferred-customer + multipayment + payment/refund/spine/supplier/event regressions — PASS, 86 tests / 318 assertions before the final four-cash-side-door pin; that added pin passes in the 7-test task path.
- Verification: targeted PHPStan on all three production files and the task test — zero errors; Pint — pass; `git diff --check` — pass; `rg "?? 'TND'" PaymentController.php` returns no matches.
- Supplier evidence: Cheque/Effet supplier payments retain the Phase-1 cash-out path and Cr-bank GL line, while atomically adding a linked outbound Received instrument; `InstrumentKind::Other` remains byte-shape compatible and creates no instrument.
- Side-door evidence: `storeMultiple`, split payment, deposit, and payment-on-account reject Cheque/Effet maturity methods with the translated `DEFERRED_METHOD_NOT_SUPPORTED` response; a single test runs all four with an immediate method and proves they still return 201.
- Refund evidence: full refund, partial refund, and reverse read the linked instrument before any write and reject Received/Deposited/Bounced instruments with the settle-first message. A real deferred payment is remitted, cleared into the bank, then successfully refunded with an Out movement from that bank repository.
- API/currency evidence: `formatPayment` exposes `dishonored_at`; all remaining `PaymentController` TND fallbacks now use active-company currency.
- Money-path deviation: none. Supplier direction keeps cash movement/GL intact; customer pending paper cannot enter a cash undo path until cleared.

### Task 15 — Single-call deferred-tender payment form

- Status: complete.
- Files touched: `PaymentForm.tsx`; its existing focused test; new `__tests__/PaymentForm.instrument.test.tsx`; en/fr Treasury locale files; this progress log.
- RED: after correcting the new test's partial currency-hook mock, the task path failed 2/3 tests because the accessible instrument fieldset and inline instrument fields did not exist. The immediate-payment compatibility test was already green. A native-required assertion then exposed that the visual required marker was not reflected in browser constraint validation.
- GREEN: task path — PASS, 3 tests / 3. PaymentForm task + legacy + tenant-scope regressions — PASS, 21 tests / 21.
- Verification: `pnpm typecheck` — pass; en/fr locale JSON parse — pass; focused ESLint — zero errors (warnings only, including existing React Hook Form compiler/act warnings); `git diff --check` — pass. React Doctor's initial default-base scan was contaminated by branch-wide differences against `main`; its one Task-15-specific effect-chain finding was corrected by moving the withholding reset into the method-change event handler, then reverified against the Gate-1 base.
- Contract evidence: Cheque/Effet methods render one accessible instrument fieldset with required reference, effet-only required maturity, drawer name, and bank name/branch/account fields. Submission makes exactly one `/payments` call carrying the nested `instrument` object; immediate payments omit that object and preserve their previous payload path.
- UX evidence: the fieldset reuses the established form tokens/grid; maturity methods never render the generic third-party field; withholding is reset when switching to a maturity method, and its recommendation action is disabled with a translated explanatory tooltip while such a method is selected.
- Localization evidence: every new label, validation message, placeholder, and tooltip is under `treasury:instruments.*` in both English and French.
- Money-path deviation: none. This frontend change targets the Task-13 transactional payment endpoint and removes the former client-side two-POST sequence.

## Wave G resume — rebase conflict record

- Precondition rechecked: `origin/dev` is `9cd1871613f4741380483f5868277ce7bc70a0bc`; it contains sweep merge `93710423f`, owner decisions `f34435504`, and `design-sweep follow-ups wave 1` `9cd187161`.
- `git rebase origin/dev` stopped while replaying `6a9bc9f0a` (`test(web): stabilize finance gate coverage`). Conflicts: `AgedPayablesPage.test.tsx`, `BalanceSheetPage.test.tsx`, and `ProfitLossPage.test.tsx` content conflicts; `AgedReceivablesPage.test.tsx` modify/delete because the sweep deleted the stale duplicate test. `PaymentForm.test.tsx` merged automatically.
- Money-path conflict check: none of the conflicted files is GL, movement-port, bridge, lifecycle, or `PaymentController` code; all are frontend tests.
- Resolution rationale (recorded before resolution): preserve the sweep's canonical test structure/imports and deletion of the duplicate Aged Receivables suite, while carrying forward only the branch's formatter-independent EUR assertions and async-stability intent where those assertions still exist in the post-sweep suites. Do not resurrect the deleted duplicate file.
- The next replay (`53dd5b816`, Gate 2 RC1 verification) conflicted only in this progress file because the new conflict record and the historical gate entry were both appended after Task 15. Resolution: retain both entries in chronological resume-first/gate-history order; no code resolution is involved.
- Replay `698a75fc2` then conflicted in the same three finance tests plus the already-deleted Aged Receivables duplicate. These are again frontend-test-only conflicts; its production money-path edits merged automatically and were not manually resolved. Resolution: keep the commit's stricter row-scoped, hard-coded EUR assertions (the audited Gate 2 remediation) while retaining the sweep-era test scaffolding around them; keep Aged Receivables deleted.

## Gate 2

### RC1 pre-review verification

- `./vendor/bin/phpunit tests/Feature/Treasury tests/Feature/Accounting` — PASS, 978 tests / 3,922 assertions / 26 environment-specific skips; 40 existing PHPUnit deprecations reported. This includes the Phase-1 byte-shape payment/movement pins and all Wave-C/D deferred-tender, refund, remittance, and instrument paths.
- `./vendor/bin/phpstan` — PASS, all 2,459 files, zero errors at the repository's configured level 8 and default memory settings.
- `./vendor/bin/pint --dirty` — pass, no changes.
- `pnpm typecheck` — pass.
- `pnpm lint` — pass; ESLint emitted the repository's existing warning inventory and `audit:keys` completed successfully, with no errors.
- `pnpm vitest run src/features/treasury src/features/finance` — PASS, 115 suites / 355 tests, zero failures. The final evidence run used Vitest's JSON reporter to avoid retaining the suite's very large pre-existing React `act()` warning stream.
- `git diff --check` — pass; worktree clean before this verification entry.
- Gate-harness fixes: the first combined frontend run exposed four legacy Finance suites with stale US-locale exact-string assertions and a real ECharts canvas mount in jsdom. Their newer counterparts already established the canonical patterns, so the legacy tests now compare canonical currency output after Unicode-space normalization and mock `OwnerChart`; focused result 28/28. A supplier-prefill assertion that passed alone but raced under the 51-file run now waits for the async reset; focused result 14/14. No production behavior changed in this stabilization commit.
- React Doctor: Task-15 diff against `phase2-gate-1` scores 88/100 with one pre-existing barrel-import warning; the introduced effect-chain warning was fixed before Gate 2 by moving the reset to the method-change handler.

### RC1 verdict and remediation

- Standard review: `docs/handoff/gate-reviews/GATE-2-rc1.md`, Opus `VERDICT: CHANGES-REQUIRED`. Mandatory money-path escalation: `docs/handoff/gate-reviews/GATE-2-rc1-fable.md`, Fable `VERDICT: CHANGES-REQUIRED`.
- HIGH-1/MED-1: Fable corrected Opus's failure-mode wording: the pre-transaction customer guard made unledgered custody fail closed with 422, so no silent divergence was reachable. The binding §8 deviation was real: deferred-customer custody was incorrectly required to have `gl_account_id`, and both primary/advance JE gates were coupled to that nullable account. A new red test received 422; the fix exempts deferred customer custody only, selects the resolved portfolio account as the posting gate, preserves immediate/supplier ledgered-repository guards, and retains zero movement. Green: focused 8 tests / 33 assertions; payment/spine regression set 71 tests / 324 assertions.
- MED-2: legacy Finance assertions now use formatter-independent hard-coded EUR display literals, normalize Unicode whitespace only, and query within the exact account/customer/total row. They no longer derive expected values from production `formatReportCurrency` or scan the entire body.
- MED-3: `refundReceiptPayments()` now runs the same settle-first guard over every linked original POS payment before any refund write. Its new linked-Received-instrument test was red before the guard and green after it.
- MED-4: full, partial, and reverse paths are pinned for all three blocked statuses (`Received`, `Deposited`, `Bounced`); `Cancelled` and the existing `Cleared` case are explicitly allowed.
- MED-5: supplier deferred payment now pins the complete two-line Dr-401/Cr-bank payment JE, exactly one `supplier_payment` JE, no instrument/remittance JE, one movement, and unchanged Phase-1 balance shape.
- LOW resolutions: deterministic id tiebreakers were added to instrument/remittance pagination; manual outbound registration no longer requires inbound portfolio accounts; at-sight cheque detail completion clears `needs_details` while effet retains the maturity requirement; all four side-door tests assert `DEFERRED_METHOD_NOT_SUPPORTED`; FE `instrument_kind` includes backend `other`; an effet-required-maturity Vitest was added. The fixed scale-3 regex was re-adjudicated by Fable as matching storage and is not a finding.
- LOW recorded deferrals: manual web-registration idempotency is not a Task-11 interface contract and cannot be safely fixed by merely forwarding a header to the partial unique index — `receive()` needs semantic replay validation, which Task 16 introduces for fiscal-event keys; keep manual POST behavior unchanged until that reusable replay contract exists. PaymentForm's `parseFloat` validation is byte-pre-existing at `phase2-gate-1`, affects validation only, and remains outside this gate fix; money payloads remain strings.
- Process deviation: the HIGH fix was drafted while the mandatory Fable RC1 escalation was still reading the committed RC1 diff. Fable explicitly observed and reviewed the dirty draft, judged the correction correct, and required it to be committed/verified as RC2. No tag or committed RC1 content was moved; this sequencing deviation is recorded and RC2 is escalated again under §3(c).
- Money-path deviation: interim deviation from spec §8 existed in RC1 (unledgered custody 422); it is corrected in RC2. No repository money behavior changed: deferred customer remains movement-free, and immediate/supplier movements still require their repository GL account and go through the movement port.

### RC2 pre-review verification

- `./vendor/bin/phpunit tests/Feature/Treasury tests/Feature/Accounting` — PASS, 981 tests / 3,945 assertions / 26 environment-specific skips; 40 existing PHPUnit deprecations reported.
- `./vendor/bin/phpstan --memory-limit=1G` — PASS, all 2,459 files, zero errors. The first run at the configured 512 MB ceiling exhausted memory in a parallel worker and produced an explicitly incomplete result; the identical configured analysis completed cleanly with only the CLI memory allowance raised.
- `./vendor/bin/pint --dirty --test` — pass, no formatting changes required.
- `pnpm typecheck && pnpm lint` — pass; ESLint emitted the existing warning inventory and the TanStack query-key audit reported zero new/stale violations.
- `pnpm vitest run src/features/treasury src/features/finance` — PASS, 115 suites / 356 tests, zero failures (JSON reporter used to contain the existing jsdom warning stream).
- `git diff --check` — pass; the worktree contained only this verification-entry update before the RC2 documentation commit.
- RC2 review scope: all RC1 findings are addressed and committed in `698a75fc2`. Manual web-registration idempotency remains an explicit non-money-path deferral because Task 11 did not contract an idempotency header and safe replay requires the semantic replay contract assigned to Task 16; see the RC1 remediation entry above.

### Gate 2 verdict

- RC attempts: 2. RC1 was CHANGES-REQUIRED by both Opus and the mandatory Fable escalation; RC2 was APPROVE by both reviewers.
- Review artifacts: `docs/handoff/gate-reviews/GATE-2-rc1.md`, `GATE-2-rc1-fable.md`, `GATE-2-rc2.md`, and `GATE-2-rc2-fable.md`.
- Final verdict: `VERDICT: APPROVE`. Fable was run despite Opus's approval because brief §3(c) requires escalation whenever this wave records a money-path deviation; the RC1 custody/GL coupling deviation was corrected and independently re-adjudicated.
- Approved LOW carry-forwards: add an immediate-excess synchronous-advance/movement-link pin during Wave E/F; align the legacy deposit wrapper with `instruments.remit` or document the compatibility choice; record/resolve the spec's partner-or-null eligibility versus the stricter plan wording; add UUID validation to the new payment-on-account rule and opportunistically its identical siblings; use explicit payment-currency scale for supplied-instrument amount equality when that block is next touched. These were expressly adjudicated non-blocking by both RC2 reviewers and do not alter the approved money path.
- Reconcile/deploy notes: Task 19 reconcile check #4 must exclude unlinked manually registered inbound instruments that have no receipt GL; deployment must re-seed the three new permissions and reset the tenant-blind Spatie permission cache.

### Task 16 — Shared maturity-leg helper and POS sale legs

- Status: complete.
- Files touched: new `MaturityLegResult` DTO and reusable `HandlesMaturityTenderLeg` projection helper; `TreasuryReceiptBridge`; `GeneralLedgerService`; new focused `PosBridgeInstrumentTest`; this progress log.
- RED: the first cash+check canonical receipt test failed because the check-leg Payment had no `instrument_id`; current behavior had posted both legs to the cash repository and moved the full receipt balance.
- GREEN: focused Task-16 path — PASS, 8 tests / 53 assertions. Task path plus the existing POS spine and refund bridge regressions — PASS, 19 tests / 125 assertions.
- Verification: targeted PHPStan over the helper/DTO/bridge/GL seam/test — zero errors; Pint dirty pass; `git diff --check` — pass.
- Fresh-leg evidence: Cheque/Effet maturity methods create an inbound POS-origin Received instrument first with deterministic fiscal-event key/reference, explicit receipt currency, `needs_details=true`, default custody, and no maturity date; the Payment links it, the POS JE debit alone swaps to 5112/413, and the movement port is not called. Cash stays byte-shape compatible and moves only its own amount.
- Replay evidence: a complete replay creates no duplicate Payment/instrument/JE/movement; a pre-cutover maturity-method Payment whose debit is the repository GL account remains on the old cash path and mints no instrument; a post-cutover portfolio-debit Payment missing its instrument repairs the complete set without cash; a conflicting instrument key/amount throws instead of silently reusing divergent paper.
- Boundary evidence: missing portfolio account fails closed before instrument creation; voucher-shaped non-maturity and `has_maturity + Other` legs retain cash JE/movement behavior; `pos_receipt_payments` rows are byte-identical across the Treasury maturity branch; projections run with `CompanyContext` cleared.
- Lock/atomicity evidence: fresh paper is created before Payment/GL work; `postEntryNow` remains inside the enclosing bridge transaction; deferred legs return before the repository port. The unique-violation replay lookup occurs after `receive()` has rolled back its nested savepoint.
- Deviations: none. Task 16 intentionally establishes the sale-leg seam; refund/void maturity routing remains assigned to Task 17.

### Task 17 — POS refund/void maturity handling

- Status: complete.
- Files touched: `TreasuryReceiptBridge`; `InstrumentLifecycleService`; new focused `PosBridgeInstrumentRefundTest`; this progress log.
- RED: the same-day canonical check-refund path left the original instrument `Received` and entered the Task-16 sale-side maturity flow instead of cancelling the paper.
- GREEN: focused Task-17 path — PASS, 4 tests / 36 assertions. Task path plus Task-16, legacy POS spine/refund, and lifecycle-cancellation regressions — PASS, 30 tests / 185 assertions.
- Verification: targeted PHPStan over bridge/lifecycle/test — zero errors; Pint dirty test — pass; `git diff --check` — pass.
- Same-day evidence: original-event resolution uses the sealed `original_receipt_reference.fiscal_event_id`; exactly one matching Received kind+amount instrument is locked and cancelled with `PosRevenue`; its linked original Payment becomes Reversed, the sole cancellation JE is Dr ProductRevenue / Cr 5112, aggregate revenue nets to zero, no standard refund JE or repository movement is written, and replay is a silent Cancelled no-op.
- Active/ambiguous evidence: a Deposited original remains untouched while the refund posts the standard Dr-Revenue/Cr-cash JE plus one Out movement; two identical canonical check legs are never guess-cancelled and use the same safe cash path. Both outcomes write `pos_refund_on_active_instrument`; the event/index aggregate key stays at one row across replay and a warning is logged.
- Failure/immutability evidence: making ProductRevenue unavailable forces cancellation GL creation to throw; the outer transaction leaves the instrument Received with no cancellation event, refund Payment, or movement. `pos_receipt_payments` bytes are unchanged by the maturity refund path, and `CompanyContext` is cleared before every bridge apply.
- Lock/atomicity evidence: cancellation locks the instrument before reading/updating its linked Payment and before `postEntryNow`; the no-cash branch never resolves or locks a repository. Alert + standard refund JE + movement share the outer bridge transaction on fallback.
- Deviations: none. Zero or multiple safe candidates intentionally follow the binding safe-default cash reversal plus alert; only exactly one Received candidate can cancel.

### Task 18 — Deposit and account-payment maturity bridges

- Status: complete.
- Files touched: deposit/account-payment bridges; reusable maturity context DTO and helper/receipt-bridge adaptation; allocation command/service debit-override seam; GL synchronous null-actor support; sibling maturity test plus one Task-16 replay assertion; this progress log.
- RED: after the core sibling cases were established, the unledgered-custody regression produced an instrument and Payment but no JE because `PaymentAllocationService` still gated on `repository.gl_account_id` instead of the portfolio override. A separate post-cutover replay pin failed because the repaired instrument lacked its reverse `payment_id` link.
- GREEN: focused sibling path — PASS, 6 tests / 47 assertions. Wave-E bridge/allocation/reconcile regression set — PASS, 55 tests / 306 assertions / 1 existing environment-specific skip.
- Verification: targeted PHPStan across all changed DTO/helper/bridge/allocation/GL/test files — zero errors; Pint dirty test — pass; `git diff --check` — pass.
- Deposit/account evidence: a check DEPOSIT_RECEIPT creates one inbound instrument, posts a synchronous Dr 5312 / Cr CustomerAdvance entry, links both Payment↔instrument directions, records no movement, and leaves custody balance unchanged. A traite ACCOUNT_PAYMENT does the same at Dr 413, including the worker path where the device cashier cannot resolve to a company actor.
- Compatibility/replay evidence: cash variants retain repository-debit/customer-advance JE plus one movement and their combined balance shape. Full maturity replay is duplicate-free; a simulated pre-cutover repository-debit leg completes/retains its cash movement and mints no instrument; missing 5312 fails before bridge writes.
- Atomicity evidence: both bridges invoke the shared handler before Payment creation, allocation document locks, and synchronous GL posting. Deferred sibling legs return before movement lookup/port access. Allocation chooses the explicit portfolio debit for every invoice/order/excess JE while leaving all credit lines untouched.
- File-list deviation (implementation seam, no contract deviation): the plan named only the two bridges, but their GL is owned by `PaymentAllocationService`; delivering the mandated debit swap without mutating posted lines required an optional `cashAccountOverrideId` on `ApplyPaymentAllocationCommand`, the allocation service's three debit sites, and allowing `postEntryNow` with its already-supported null actor. Immediate callers omit the option and remain byte-shape compatible.
- Money-path deviation: none. The extra seam is the literal §7 debit-only swap and forces `SynchronousInTransaction`; no deferred sibling call reaches `record()`.

## Gate 3

### RC1 pre-review verification

- `./vendor/bin/phpunit tests/Feature/Treasury tests/Feature/Accounting` — PASS, 999 tests / 4,082 assertions / 26 environment-specific skips; 40 existing PHPUnit deprecations reported.
- `./vendor/bin/phpstan --memory-limit=1G` — PASS, all 2,462 files, zero errors.
- `./vendor/bin/pint --dirty --test` — pass, no formatting changes required.
- `git diff --check` — pass; worktree clean before this verification entry.
- Fiscal bridge gate pins: all Task-16/17/18 tests clear `CompanyContext` before `apply()`; cash+paper split, complete/pre-cutover replay, missing portfolio, refund cancel/alert/failure, sibling debit overrides, and null-actor synchronous posting are green. Both sale and refund tests snapshot `pos_receipt_payments` before/after the maturity branch and assert byte-identical rows.
- Money-path summary: receipt-time Cheque/Effet legs post portfolio GL synchronously and never call the movement port; same-day refund cancellation is Dr Revenue / Cr portfolio with no cash; active/missing/ambiguous refund paper alone falls back to the standard cash reversal plus Out movement; immediate and pre-cutover shapes retain their existing movements.

### Gate 3 verdict

- RC attempts: 1. Opus returned `VERDICT: APPROVE`; review artifact: `docs/handoff/gate-reviews/GATE-3-rc1.md`.
- Artifact process note: the first Opus pass approved but its sandbox blocked the requested file write and returned only a summary. A second stdout-only review of the identical frozen `phase2-gate-2..phase2-gate-3-rc1` diff produced the complete cited report persisted above; the branch/tag did not change between passes.
- Fable escalation: not triggered. Neither pass found a BLOCKER/HIGH, money-path uncertainty, or a recorded money-path deviation.
- Approved LOW carry-forwards: canonical REFUND/VOID validation already makes a missing original reference structurally invalid, but a coverage pin or explicit alert routing may improve dead-letter ergonomics; preserve actor requirements at interactive customer-direction call sites after the shared GL builders gained safe synchronous null-actor worker support. Numeric refund amount matching remains correct while the column is decimal.
- Final verdict: `VERDICT: APPROVE`.

### Task 19 — Maturing-instruments endpoint and forward buckets

- Status: complete.
- Files touched: new `MaturingInstrumentsController`; Treasury routes; new focused `MaturingInstrumentsTest`; this progress log.
- RED: both API tests returned 404 before the route/controller existed.
- GREEN: focused task path — PASS, 2 tests / 29 assertions. Task plus existing payment-instrument API regressions — PASS, 20 tests / 78 assertions.
- Verification: targeted PHPStan on controller/routes/test — zero errors; Pint dirty test — pass; `php artisan route:list --path=treasury/maturing-instruments` shows the single guarded GET route; `git diff --check` — pass.
- Bucket evidence: one company-scoped query selects only Received/Deposited instruments; null maturity maps to `d0_7`, past dates to overdue, and the forward 0–7/8–30/31–60/61–90/90+ boundaries produce per-bucket count/`total_in`/`total_out` plus grand totals as company-scale decimal strings using bcmath only.
- Row/filter evidence: rows expose bucket and certainty (`portfolio` for Received, `remitted` for Deposited); from/to, direction, kind, repository, partner, and needs-details filters compose under tenant+company predicates. Cleared and foreign-company rows are excluded.
- Deviations: none.

### Task 20 — Instrument maturities in the cash forecast

- Status: complete.
- Files touched: `UpcomingPaymentsService`; `UpcomingPaymentLineData`; generated shared TypeScript declarations; new accounting feature test; one existing Treasury Overview test fixture updated for the expanded generated contract; this progress log.
- RED: after correcting the test fixture's balance cache setup, the settled invoice produced zero Money-In lines because instruments were not queried; outbound and at-sight instruments likewise produced empty forecast sides.
- GREEN: task plus existing upcoming-payments API regressions — PASS, 4 tests / 43 assertions. Treasury Overview generated-type fixture — PASS, 4 Vitest tests.
- Verification: targeted PHPStan on DTO/service/test — zero errors; Pint dirty pass; `CACHE_STORE=array php artisan typescript:transform` completed 425 types and changed only `UpcomingPaymentLineData`; `pnpm typecheck` — pass after updating the typed fixture; `git diff --check` — pass.
- Double-count evidence: a real PaymentAllocation-linked traite closes its invoice (`Paid`, `balance_due=0`) and the forecast emits exactly one Money-In line sourced from the instrument. Deleting the allocation/reopening the invoice while marking the instrument Bounced with receivable routing emits exactly one document line; Bounced is outside the pending instrument set.
- Direction/timing evidence: outbound Received paper feeds Money-Out; inbound null-maturity paper is due today with `days_until_due=0`; Received certainty is `portfolio`, Deposited certainty is `remitted`; overdue/pending instruments inside the requested end window merge with document lines under deterministic due-date/reference ordering.
- DTO/file-list seam: the binding interface adds `source` and `certainty`, so the TypeScript-transformed DTO and its existing typed frontend fixture necessarily changed although the plan's Files list named only the service/test. Existing document lines explicitly emit `source=document`, `certainty=null`.
- Money-path deviation: none. This is a read-only report; all sums remain decimal strings using bcmath and explicit currency scales.

### Task 21 — Scheduled pre-maturity alerts

- Status: complete.
- Files touched: new `InstrumentMaturityAlertsCommand`; new country-settings migration; `CountryPaymentSettings`; `TreasuryServiceProvider`; console schedule; new focused `InstrumentMaturityAlertsTest`; this progress log.
- RED: the focused path failed all 3 tests because `instrument_alert_days` did not exist, the command was unregistered, and no 06:30 schedule existed.
- GREEN: focused task path — PASS, 3 tests / 17 assertions (the repository's PHPUnit deprecation display remains informational).
- Verification: targeted PHPStan over command/model/test — zero errors; targeted Pint test — pass; `git diff --check` — pass; `schedule:list` contains `treasury:instrument-maturity-alerts` at cron `30 6 * * *`.
- Alert evidence: the per-country nullable smallint overrides the default 7-day window; inbound Received instruments mature on/before today+window, while inbound Deposited instruments alert only when strictly more than the window overdue. Outbound and boundary-excluded rows do not enter the payload. Every company invocation records one `treasury.instrument.maturity_alert` audit event with counts, sorted IDs, window, and as-of date, then logs a warning; same-day reruns intentionally create a second event.
- Failure-isolation evidence: companies are processed deterministically under `TenantScopedCommand::forEachTenant`; a thrown alert-channel failure for one company is logged, yields a non-zero command exit, and does not prevent a later company from receiving its audit event. The scheduler runs in-process with `withoutOverlapping()` so that exit remains observable.
- Money-path deviation: none. The command is read-only except for audit/log alerting and never touches repositories, movements, or journals.

### Task 22 — Reconcile check #4: portfolio versus GL

- Status: complete.
- Files touched: `ReconcileTreasuryCommand`; `Company`; reconcile scheduler failure guidance; new re-runnable `phase2_cutover_at` migration; new focused `ReconcilePortfolioCheckTest`; one existing reconcile logger-mock allowance; this progress log.
- RED: the drift-direction test returned success/no alert because reconcile had no company-level portfolio check, and the watermark test failed because `companies.phase2_cutover_at` did not exist.
- GREEN: focused Task-22 path — PASS, 4 tests / 17 assertions. Task path plus existing cash-reconcile and clear lifecycle regressions — PASS, 26 tests / 114 assertions.
- Verification: targeted PHPStan over command/company/tests — zero errors; targeted Pint test — pass; `git diff --check` — pass.
- Equality evidence: at explicit company-currency scale, inbound linked cheque Received+Deposited nominal equals 5312/5112, linked effet Received equals 413, and linked effet Deposited equals 5313/5113. The GL side sums debit-minus-credit across every Posted JE on each reserved account, independent of source provenance. A receive→remit→clear cheque cycle reconciles at every state while matched effet Received/Deposited balances remain green.
- Drift evidence: an orphan portfolio debit (GL greater than nominal) and linked paper missing its receipt JE (nominal greater than GL) produce one `treasury.reconcile.portfolio_drift` company audit payload containing both mismatches, an error log, and a non-zero exit. A clean repository stays unfrozen; check #4 never calls the movement service's freeze operation.
- Cutover/deploy evidence: the additive guarded migration stamps existing companies on `up()` and the check excludes instrument rows and Posted JEs older than the nullable watermark. Missing purpose accounts are info-logged and skipped so chart reseeding can complete without false drift.
- Manual-paper compatibility: unlinked inbound manual registration intentionally has no receipt JE, so its nominal is excluded. Its later instrument/remittance lifecycle portfolio lines are subtracted as the same no-receipt circuit (including proportional mixed-slip nominal) to avoid permanent false drift; existing clear/reconcile coverage pins this. Once supplied to a payment, `payment_id` links the paper and the normal equality applies.
- File-list seam: scheduler failure guidance changed because the same non-zero exit now has a third valid cause—alert-only portfolio drift—and must not falsely tell operators that every drift froze cash.
- Money-path deviation: none. Check #4 only reads instruments/posted lines and writes alerts; it never mutates cash, journals, movements, or repository freeze state.

## Wave G precondition — STOPPED

- Checked: 2026-07-11 after Task 22, exactly at Wave G entry and before touching any frontend file.
- `git fetch origin dev` succeeded. Fetched `origin/dev` is `f0f9cecc91e1adc310533363525c7ab1a6db5c3f` (`docs(handoff): autonomous audit gates — Codex self-reviews via claude -p (Opus standard, Fable 5 escalation on money paths)`, 2026-07-11T06:07:24+01:00).
- `git branch -r --list origin/feat/design-system-unification` returned no branch; `git ls-remote --heads origin refs/heads/feat/design-system-unification` returned no ref; `git log origin/dev --all-match --grep=design-system-unification` returned no merge/commit.
- Result: the brief §2 Wave-G precondition is unmet. This is one of the two authorized stop conditions. Tasks 23–28 and Gate 4 have not started; no rebase was attempted and no frontend file was touched.
- Resume condition: merge the design-system unification sweep into `origin/dev`, then resume here with a fresh fetch, verify ancestry/merge presence, rebase this feature branch onto `origin/dev`, and follow the resulting PageHeader/token conventions.

## Wave G precondition cleared — rebase verification

- Sweep proof: fetched `origin/dev` at `9cd1871613f4741380483f5868277ce7bc70a0bc`; history contains merge `93710423f`, owner decisions `f34435504`, and `feat(web): design-sweep follow-ups wave 1` at the tip.
- Rebase: `git rebase origin/dev` completed across 32 branch commits. Conflict details and pre-resolution rationale are recorded above under “Wave G resume — rebase conflict record.” No production money-path file required manual conflict resolution. The seven Gate 1–3 RC/final tags were moved locally to their rebased equivalent commits and verified as ancestors of HEAD; none was pushed.
- First post-rebase Treasury+Accounting run: 1,010 tests / 4,166 assertions / 26 environment-specific skips / 40 existing PHPUnit deprecations; two failures were both existing `InstrumentBounceTest` reconcile assertions. Root cause was a test-fixture accounting gap: `paidRemittedInstrument()` created linked paid paper and lifecycle JEs but omitted the original receipt-side portfolio JE, so check #4 correctly reported negative 5312 after bounce. Production behavior was not changed.
- Test-first correction: the two failing tests were the RED. The shared “paid” fixture now uses the real synchronous `createPaymentReceivedJournalEntry` builder (Dr 5312/413, Cr customer receivable), links the Posted JE to the Payment, then proceeds through remittance. Focused failures turned GREEN (2 tests / 10 assertions); full bounce path GREEN (8 tests / 43 assertions / 1 PostgreSQL-only skip).
- Final post-rebase proof: `./vendor/bin/phpunit tests/Feature/Treasury tests/Feature/Accounting` — PASS, 1,010 tests / 4,168 assertions / 26 environment-specific skips / 40 existing PHPUnit deprecations. `./vendor/bin/phpstan --memory-limit=1G` — PASS, all 2,499 files, zero errors. `./vendor/bin/pint --dirty --test` — PASS after its sole finding (ordered imports in the corrected test) was mechanically formatted. `git diff --check` — pass.
- Wave G status: unblocked. No Wave G production file was edited before this proof completed.

### Task 23 — Instrument register and lifecycle detail

- Status: complete.
- Files touched: instrument list/detail pages and their canonicalization/tenant-scope tests; new filter/lifecycle tests and `useInstrumentEvents`; payment list/detail dishonor presentation; granular permission map and route guards; Treasury en/fr locale catalogs; thin instrument events/cancel controller routes plus the focused backend API test; this progress log.
- Sweep preservation: before editing, `git log origin/dev -- <file>` was checked for every Wave-G file. The sweep-era `ListPageLayout`/`PageHeader`, token, table, route, permission, and locale conventions were retained. The new UI uses canonical `Input`, `Select`, `Textarea`, `MoneyInput`, `Button`, and `StatusBadge` atoms; all query keys are tenant/company scoped.
- RED: the focused frontend tests initially had no kind/direction/needs-details or maturity-window controls/bucket strip; detail rendered no immutable events, exposed actions without granular permissions, and allowed the bounce submit without routing. The focused backend tests returned 404 for immutable events and cancellation.
- GREEN: focused backend path — PASS, 20 tests / 57 assertions. Task-23 frontend regression set — PASS, 9 files / 35 tests. The register now sends the paginated filter contract and displays Task-19 buckets, type/direction state, custody, dates, and decimal-string currency. Detail exposes permission-gated remit/clear/bounce/transfer/cancel flows and the immutable event timeline; clear/bounce fees remain decimal strings. Payments with `dishonored_at` render the dishonored state on list and detail.
- Verification: `pnpm typecheck` — pass; changed-path ESLint — zero errors (legacy Payment page warnings remain informational); locale JSON parses; design-system audit — baseline 753, **0 new**, 0 stale; React Doctor changed-scope score 88/100 with the two remaining barrel-import diagnostics adjudicated false positives because the post-sweep owner convention explicitly requires canonical atom/barrel imports; `git diff --check` — pass.
- API seam deviation: the plan named only the frontend event hook/cancel action but no HTTP endpoints existed. Added company-scoped read-only event listing and a cancellation route that delegates to the existing lifecycle service. This introduces no new money logic: cancellation is restricted to the service's unlinked Received path and neither endpoint touches GL, movements, or repository balances.
- Money-path deviation: none.
