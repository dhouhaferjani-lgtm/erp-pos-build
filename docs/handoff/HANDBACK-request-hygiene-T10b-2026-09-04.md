# HANDBACK — Request Hygiene Phase A, Task 10b

**Per-process dedupe for the log-only lazy-load guard (gate finding NB-1)**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t10b`
- Branch: `lane/rh-t10b-lazyload-dedupe`
- Base: `0187a56d1`
- Origin: `docs/superpowers/reviews/2026-09-04-request-hygiene-t10-gate-general.md` → **NB-1** (unbounded warning volume on staging and in workers), option (b). **NB-4** (multi-row-hydration-only coverage) folded into the provider docblock at the same time.
- Commits: `1d048d36c` (code) + the docs commit carrying this file.
- Promotion precondition for Task 10 — the same push that runs CI auto-deploys staging.

---

## 1. What landed

| File | Change |
|---|---|
| `apps/api/app/Support/LazyLoadViolationLog.php` | **NEW (82 lines).** `record(string $model, string $relation): bool` — true the first time a pair is seen in this PHP process, false (and `$suppressed++`) on every repeat. `suppressedCount(): int`, `reset(): void`. State is a nested `array<string, array<string, true>>` keyed model → relation: **no per-violation string concatenation** and **no key collision** between pairs a naive `$model.'::'.$relation` would fuse (`('A::B','C')` vs `('A','B::C')`). Static by necessity — the guard handler is a static closure registered on the `Model` facade at boot, outside any container scope, and the state must outlive the request. **No `register_shutdown_function`**: emitting a `dedupe_suppressed` line at process end would add a log write to every FPM request and artisan run, including the overwhelming majority that saw no violation; `suppressedCount()` is exposed instead. |
| `apps/api/app/Providers/AppServiceProvider.php` | `configureLazyLoadingGuard()` handler now early-returns when `LazyLoadViolationLog::record(...)` is false. `Model::preventLazyLoading(! $this->app->isProduction())` unchanged; the handler is still log-only and still never throws. Docblock extended with the **process semantics** and the NB-4 coverage caveat (below). |
| `apps/api/tests/TestCase.php` | `setUp()` calls `LazyLoadViolationLog::reset()`. One phpunit run is one process, so without this the first test to trip a pair would eat the log line every later test expects from `Log::spy()` / `Log::shouldReceive('warning')` — including the existing T10 contract test. |
| `apps/api/tests/Unit/Support/LazyLoadViolationLogTest.php` | **NEW.** 6 tests: first sighting recorded; repeats suppressed *and counted*; different relation same model recorded; same relation different model recorded; **concat-collision falsifier**; `reset()` clears set *and* counter. |
| `apps/api/tests/Feature/Inventory/StockMovementTest.php` | +2 tests and a `seedTwoMovements()` helper. `test_repeated_lazy_load_of_same_pair_logs_only_once` (the red) and `test_distinct_relations_on_the_same_model_each_log` (the over-dedupe guard). |

Behaviour on the wire: one N+1 over a 1000-row page is now **1 warning line**, not 1000. Log payload shape is byte-identical (`'lazy-load', ['model' => …, 'relation' => …]`) — nothing downstream that greps the channel needs to change.

### Process semantics, now stated in the provider docblock

- **FPM worker** — logged on the first request that trips the pair, silent for every later request that worker serves. Absence of a line does **not** mean the N+1 is gone.
- **Queue worker (Horizon)** — a long-lived process, so once per **WORKER LIFETIME**, not once per job. Accepted deliberately: the pair is what a reader acts on, and a restarted/scaled worker re-logs it.
- **CLI / artisan / phpunit** — one process, so once per run; `Tests\TestCase::setUp()` resets so a pair tripped by one test still logs for the next.
- **NB-4 caveat** — Eloquent stamps `preventsLazyLoading` only on models hydrated from a multi-row result (`Builder::hydrate()`, `count($items) > 1`). Lazy loads off `first()`/`find()`/`firstOrFail()` are invisible to this guard forever.

---

## 2. Evidence

### RED (before the implementation)

```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php --filter 'lazy'
1) …::test_repeated_lazy_load_of_same_pair_logs_only_once
Mockery\Exception\InvalidCountException: Method warning(<Any Arguments>) from
Mockery_2_Illuminate_Log_LogManager should be called exactly 1 times but called 2 times.
Tests: 2, Assertions: 9, Errors: 1.
```

Two DISTINCT hydrated instances of the same pair, so the per-instance relation cache cannot be what suppresses the second line — only the process-level dedupe can. `test_distinct_relations_on_the_same_model_each_log` passed pre-implementation by construction; it exists to fail if a future change over-dedupes (e.g. keys on the model alone).

### GREEN

```
$ ./vendor/bin/phpunit tests/Unit/Support/LazyLoadViolationLogTest.php \
                       tests/Feature/Inventory/StockMovementTest.php
OK (27 tests, 101 assertions)
```

### Exact-log contracts — the FULL gate §2.2 surface, not just the four

`rg -l "Log::shouldReceive|shouldNotHaveReceived\('warning'|shouldNotReceive\('warning'|Log::swap" tests` → 19 test files. All 19 run, in two batches:

```
$ ./vendor/bin/phpunit  Compliance/AuditTrailTest  POS/RefundReportingFieldsTest \
    Treasury/TreasuryMovementServiceRecordTest  Inventory/InventoryGlPostingSeamTest \
    Unit/POS/ReceiptReturnServiceTest
Tests: 89, Assertions: 201, Failures: 2, Skipped: 31.      ← the 2 = pre-existing, see below

$ ./vendor/bin/phpunit  Accounting/CheckCogsCoverageCommandTest \
    Fiscal/ApplyFiscalEventProjectionJobTest Fiscal/FiscalEventProjectionRegistryTest \
    Fiscal/Migrations/EnableV4RefundAuthoringByDefaultMigrationTest Fiscal/OutboxIngestorTest \
    Fiscal/QuarantineBestEffortParseControllerTest Progression/RegisterCompanyWithGrowthAdvisorTest \
    Tenant/TenantInitializationTest Treasury/InstrumentMaturityAlertsTest \
    Treasury/PaymentMethodCashTenderTest Treasury/ReconcileTreasuryTest \
    Treasury/TreasuryCheckpointGuardTest \
    Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest \
    Unit/Modules/Product/SendEnrichmentFeedbackJobTest Unit/Shared/Database/MigrationOutputTest
OK (209 tests, 770 assertions)
```

`InventoryGlPostingSeamTest` is PG-gated and skipped on SQLite (in the 31). Note two gate reds are **gone on this base**: `QuarantineBestEffortParseControllerTest` (gate NB-6, 2 errors) and the `ReceiptReturnServiceTest` `ArgumentCountError` class (gate §5, 11 errors) both pass / no longer error here — the constructor fix lane landed between T10's base and `0187a56d1`.

### The 2 remaining reds are pre-existing — falsified, not assumed

`ReceiptReturnServiceTest::test_partial_return_restores_batch_stock_proportionally` (`'1.2000'` vs `'0.0000'`) and `::test_full_return_after_partial_caps_cumulative_batch_restitution` (`'3.0000'` vs `'0.0000'`) — batch stock restitution quantities, nothing to do with logging. Proved by re-running the file with `configureLazyLoadingGuard()` commented out entirely:

```
$ sed -i 's|$this->configureLazyLoadingGuard();|// TEMP-FALSIFY …|' …/AppServiceProvider.php
$ ./vendor/bin/phpunit tests/Unit/POS/ReceiptReturnServiceTest.php
Tests: 11, Assertions: 18, Failures: 2, Skipped: 1.        ← identical two
$ mv AppServiceProvider.php.bak AppServiceProvider.php     ← restored, grep TEMP-FALSIFY → 0
```

So the guard (with or without the dedupe) is not their cause. This is the T10 gate's exact-log contract at `ReceiptReturnServiceTest:292` still not reachable — a separate lane's debt, unchanged by 10b.

### PHPStan level 8

```
$ ./vendor/bin/phpstan analyse app/Providers/AppServiceProvider.php \
    app/Support/LazyLoadViolationLog.php --memory-limit=2G
 [OK] No errors
```

### Pint

```
$ ./vendor/bin/pint --test app/Support/LazyLoadViolationLog.php \
    app/Providers/AppServiceProvider.php tests/TestCase.php \
    tests/Unit/Support/LazyLoadViolationLogTest.php \
    tests/Feature/Inventory/StockMovementTest.php
{"result":"pass"}
```

(One `pint` fix run was needed on the provider first — `ordered_imports` for the new `use App\Support\LazyLoadViolationLog;`. Net provider diff is +31/-1; no unrelated churn.)

### deptrac

Not run: neither `App\Support` nor `App\Providers` is a classified layer in `apps/api/deptrac.yaml` (`grep -n "App\\\\Support|Providers" deptrac.yaml` → no match), so the new class cannot move the ratchet.

---

## 3. Deviations

- **D1 — `suppressedCount()` instead of a shutdown-emitted `dedupe_suppressed` line.** The brief allowed either ("a `dedupe_suppressed` counter emitted once when the process ends OR simply a static set"). A `register_shutdown_function` fires on **every** FPM request and artisan run — including the vast majority with zero violations — which trades an unbounded warning volume for a bounded but universal one, and it would also fire mid-phpunit-run. The counter is kept in memory and exposed for any caller that wants it. If the orchestrator wants the line on staging, it is a three-line follow-up gated on `suppressedCount() > 0`.
- **D2 — nested array instead of a concatenated key.** Slightly more allocation-light (no string built per violation) and collision-free; `test_pairs_that_concatenate_alike_do_not_collide` pins it.
- **D3 — the reset hook lives in `tests/TestCase.php`, i.e. production-adjacent state is reset from test code.** Unavoidable given the state is static and process-scoped; the alternative (an injectable registry) cannot be reached from the static boot-time closure without `app()`, which rule 13 forbids. Documented in both the class and the provider docblock.
- **No config kill switch** (gate NB-1 option (a)) — not requested, and the dedupe makes the volume argument moot. Flag it if the orchestrator still wants an off switch on staging.

---

## 4. What the reviewer should look at

1. Whether once-per-**worker-lifetime** on Horizon is acceptable (docblock states it plainly). A worker that runs for hours logs a pair once and then goes quiet.
2. `Tests\TestCase::setUp()` — every test now resets the registry. If a future lane adds a test that asserts cross-test dedupe behaviour, it will need to opt out.
3. D1: the `dedupe_suppressed` line was deliberately NOT emitted. Confirm or reverse.
