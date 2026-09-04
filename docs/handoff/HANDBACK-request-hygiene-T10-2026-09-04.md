# HANDBACK — Request Hygiene Phase A, Task 10

**Restorable query counting and log-only lazy-load guard**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t10`
- Branch: `lane/rh-t10-guards`
- Base: `7f86dbf0c` (`Merge branch 'lane/rh-t2-stock-movements' into dev` — contains T2/T3/T4)
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 10` (lines 2212–2370)
- Commits: `70efb3060` (code) + the docs commit that carries this file.
- Gate: **general Opus** (plan Step 5).

---

## 1. What landed

| File | Change |
|---|---|
| `apps/api/tests/Traits/CountsQueries.php` | **NEW (49 lines).** `countQueries(callable): int` and `assertQueryCountAtMost(int, callable, string)`. Built only on the connection's own query-log API — `DB::connection()->logging()`, `DB::flushQueryLog()`, `DB::enableQueryLog()`, `DB::getQueryLog()`, `DB::disableQueryLog()`. **No `DB::listen` closure is registered**, so nothing survives the sample (Laravel keeps `DB::listen` handlers for the life of the connection and they would leak into every later test in the process). The previous logging flag is restored in a `finally` block, and the log is flushed on both entry and exit. Docblock states the helper must never be nested. |
| `apps/api/app/Providers/AppServiceProvider.php` | `boot()` calls the new private `configureLazyLoadingGuard()` right after `loadTenantMigrationsInTestingEnvironment()`. It overrides the violation handler with `Log::warning('lazy-load', ['model' => $model::class, 'relation' => $relation])` and then calls `Model::preventLazyLoading(! $this->app->isProduction())`. **Log-only in every non-production environment** — because the handler is overridden, Eloquent never constructs or throws `LazyLoadingViolationException`; the relation resolves normally after the warning. Production keeps the framework default (guard off), so production behaviour is unchanged. Two imports added: `Illuminate\Database\Eloquent\Model`, `Illuminate\Support\Facades\Log`. |
| `apps/api/tests/Feature/Inventory/StockMovementTest.php` | `use CountsQueries;` on the class, imports `Illuminate\Support\Facades\Log` and `Tests\Traits\CountsQueries` (`CarbonImmutable` and `StockMovement` were already imported by T2). Two new tests: `test_index_query_count_is_flat_and_includes_document_linkage` and `test_lazy_load_logs_warning_and_relation_still_loads`. |

Nothing else was touched. `StockMovementController.php` is **byte-identical to `7f86dbf0c`** — it was patched twice during this lane purely to produce falsifying / baseline evidence and restored both times (`git status` clean, see §3).

---

## 2. Environment

Worktree came pre-provisioned per the dispatch brief (`apps/api/vendor` fresh copy + `composer dump-autoload`, `apps/api/.env`, `apps/web/node_modules`).

The shared PG on `127.0.0.1:5433` is held by another project's container, so this lane ran its PG leg against a lane-private container (never the shared default database):

```
docker run -d --name autoerp_pg_t10 --shm-size=1g -p 127.0.0.1:5460:5432 \
  -e POSTGRES_USER=autoerp -e POSTGRES_PASSWORD=autoerp_secret \
  -e POSTGRES_DB=autoerp_test_t10 timescale/timescaledb:latest-pg16
→ e041c8cbecd4…
docker exec autoerp_pg_t10 pg_isready -U autoerp
→ /var/run/postgresql:5432 - accepting connections
```

Left running for the reviewer. PG invocations use
`DB_HOST=127.0.0.1 DB_PORT=5460 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret DB_DATABASE=autoerp_test_t10 DB_CENTRAL_DATABASE=autoerp_test_t10 ./vendor/bin/phpunit -c phpunit-pgsql.xml <paths>`.

The full backend suite was **never** run locally (laptop rule + plan Global Constraints). See §6.

---

## 3. Step-by-step evidence

### Step 1 — trait added

`apps/api/tests/Traits/CountsQueries.php` written verbatim from the plan text plus a header docblock explaining why no `DB::listen` listener is used. No behaviour deviation.

### Steps 2 + 3 — RED run (both new tests, before the provider change)

```
$ cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php \
    --filter '(test_index_query_count_is_flat_and_includes_document_linkage|test_lazy_load_logs_warning_and_relation_still_loads)'
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
Configuration: .../apps/api/phpunit.xml

.E                                                                  2 / 2 (100%)

Time: 00:05.781, Memory: 163.00 MB

There was 1 error:

1) Tests\Feature\Inventory\StockMovementTest::test_lazy_load_logs_warning_and_relation_still_loads
Mockery\Exception\InvalidCountException: Method warning(<Any Arguments>) from Mockery_2_Illuminate_Log_LogManager should be called
 at least 1 times but called 0 times.
...
/apps/api/tests/Feature/Inventory/StockMovementTest.php:700

ERRORS!
Tests: 2, Assertions: 11, Errors: 1.
```

`test_lazy_load_logs_warning_and_relation_still_loads` is **red exactly as the plan predicts** — no handler, no warning.

`test_index_query_count_is_flat_and_includes_document_linkage` is **green on arrival**, because the plan's own precondition ("only becomes flat after Task 2's page-level `whereIn()->get()->keyBy('id')` lookup") is already satisfied: T2 merged into the lane base at `7f86dbf0c`. A test that is green when written proves nothing on its own, so the falsifying check below was run instead.

#### Falsifying check for the query-budget test (temporary controller regression, reverted)

`StockMovementController::formatMovement()` line ~172 temporarily reverted from the bulk keyed lookup to a per-row read:

```php
-            ? $sourceDocumentsById->get($movement->reference_id)
+            ? Document::query()->whereKey($movement->reference_id)->first()
```

```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php \
    --filter 'test_index_query_count_is_flat_and_includes_document_linkage'
F                                                                   1 / 1 (100%)

1) Tests\Feature\Inventory\StockMovementTest::test_index_query_count_is_flat_and_includes_document_linkage
5 rows (3 linked)=20, 50 rows (30 linked)=43
Failed asserting that 43 is equal to 22 or is less than 22.

/apps/api/tests/Feature/Inventory/StockMovementTest.php:667
FAILURES!
Tests: 1, Assertions: 8, Failures: 1.
```

`20 → 43` is exactly the +27 extra document reads the 30 linked rows would cost per-row. The controller was restored from a byte copy immediately afterwards; `git status` showed only the two intended files modified.

With T2's bulk lookup in place the same fixture measures **20 queries for 5 rows and 21 for 50** (see the green run) — flat, i.e. the +1 is the single page-level `Document` `whereIn`.

### Step 4 — provider change, then GREEN

```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php \
    --filter '(test_index_query_count_is_flat_and_includes_document_linkage|test_lazy_load_logs_warning_and_relation_still_loads)'
..                                                                  2 / 2 (100%)
Time: 00:04.070, Memory: 163.00 MB
OK (2 tests, 11 assertions)
```

Whole file, SQLite:

```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php
...................                                               19 / 19 (100%)
Time: 00:15.362, Memory: 169.00 MB
OK (19 tests, 77 assertions)
```

(17 tests before this lane, 19 after.)

### Step 5 — the four exact-log contracts

| File | Driver | Result |
|---|---|---|
| `tests/Feature/Inventory/InventoryGlPostingSeamTest.php` (`:513` no warning + no error) | **PostgreSQL** — the whole class is PG-gated (`markTestSkipped` at `:51`), so the SQLite run reported `27/27 skipped` and proves nothing | **OK (27 tests, 112 assertions)** |
| `tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php` (`:252` no warning) | SQLite (`17 tests, 3 PG-only skipped`) and PostgreSQL | **OK** on both |
| `tests/Feature/POS/RefundReportingFieldsTest.php` (`:134` no warning) | SQLite and PostgreSQL | **OK (4 tests, 36 assertions)** |
| `tests/Unit/POS/ReceiptReturnServiceTest.php` (`:292` no warning) | SQLite | **11 errors — PRE-EXISTING, not caused by this lane.** See §5. |

```
# SQLite
$ ./vendor/bin/phpunit tests/Feature/Inventory/InventoryGlPostingSeamTest.php
SSSSSSSSSSSSSSSSSSSSSSSSSSS                                       27 / 27 (100%)
OK, but some tests were skipped!  Tests: 27, Assertions: 0, Skipped: 27.

$ ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php
..............SSS                                                 17 / 17 (100%)
OK, but some tests were skipped!  Tests: 17, Assertions: 39, Skipped: 3.

$ ./vendor/bin/phpunit tests/Feature/POS/RefundReportingFieldsTest.php
....                                                                4 / 4 (100%)
OK (4 tests, 36 assertions)

# PostgreSQL (lane-private DB, guard active)
$ DB_HOST=127.0.0.1 DB_PORT=5460 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=autoerp_test_t10 DB_CENTRAL_DATABASE=autoerp_test_t10 \
  ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/InventoryGlPostingSeamTest.php
...........................                                       27 / 27 (100%)
OK (27 tests, 112 assertions)

$ … -c phpunit-pgsql.xml tests/Feature/Inventory/StockMovementTest.php \
      tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php \
      tests/Feature/POS/RefundReportingFieldsTest.php
........................................                          40 / 40 (100%)
OK (40 tests, 168 assertions)
```

### Step 5 — the remaining named paths

```
$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/PaymentCompanyScopeTest.php
...........F......................                                34 / 34 (100%)
Tests: 34, Assertions: 122, Failures: 1.
1) Tests\Feature\Treasury\PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance
   …SupplierPayment does not match expected DocumentPayment.  (PRE-EXISTING — see §5)

$ ./vendor/bin/phpunit tests/Feature/Compliance/AuditTrailTest.php \
    tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php \
    tests/Feature/Document/ListDocumentsTest.php
.......................................................           55 / 55 (100%)
OK (55 tests, 210 assertions)
```

### Extra coverage beyond the plan's list

`Log::spy()` accepts any call, so the only test shapes a stray `lazy-load` warning can break are strict ones. A repo-wide sweep found exactly the plan's four `shouldNotHaveReceived('warning')` files (confirming the plan list is complete for that shape) **plus three strict `Log::shouldReceive('warning')` files the plan does not name**, which were run as insurance:

```
$ ./vendor/bin/phpunit tests/Feature/Fiscal/Migrations/EnableV4RefundAuthoringByDefaultMigrationTest.php \
    tests/Feature/Progression/RegisterCompanyWithGrowthAdvisorTest.php \
    tests/Feature/Treasury/InstrumentMaturityAlertsTest.php
...............                                                   15 / 15 (100%)
OK (15 tests, 68 assertions)
```

### Static analysis and style

```
$ ./vendor/bin/phpstan analyse app/Providers/AppServiceProvider.php \
    app/Modules/Inventory/Presentation/Controllers/StockMovementController.php \
    tests/Traits/CountsQueries.php --memory-limit=2G
Note: Using configuration file .../apps/api/phpstan.neon.
 3/3 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors

$ ./vendor/bin/pint --test app/Providers/AppServiceProvider.php tests/Traits/CountsQueries.php \
    tests/Feature/Inventory/StockMovementTest.php
{"result":"pass"}
```

---

## 4. Deviations from the plan text

**D1 — Step 2 seeds through `ledgerRow()`, not `StockAdjustmentService::receive()`.**
Directed by the dispatch brief ("place the new test with the `ledgerRow()` helper the lane added" — T2 introduced it at `StockMovementTest.php:405`). `ledgerRow()` writes the `stock_movements` row directly with the same tenant/company/product/location/user, which is what the query-budget fixture needs; `receive()` would additionally mutate stock levels and cost layers for no benefit and 50× the runtime. The plan's semantics are unchanged: distinct explicit `created_at`/`updated_at` at `2026-09-03 12:00:00 + index seconds`, `reference_type`/`reference_id` set when `index % 5 ∈ {1,2,3}`, so the newest 5 (indexes 50..46, modulo 0,4,3,2,1) hold exactly 3 linked rows and all 50 hold 30. Step 3's lazy-load test **does** use `receive()` verbatim as written.

**D2 — Step 2 asserts document linkage as well as the count.**
Added `assertJsonCount(5, 'data')`, `assertJsonPath('data.0.source_document_id', null)` and `assertJsonPath('data.2.source_document_id', $document->id)` inside the small-page closure, and `assertJsonCount(50, 'data')` in the large one. Without them the test is satisfiable by an endpoint that returns nothing; the plan's own name for the test claims it "includes document linkage", so the linkage is now actually asserted on the exact rows the modulo argument predicts. Query counts are unaffected (`assertJsonPath` reads the decoded response, issuing no queries).

**D3 — the guard lives in a named private method, not inline in `boot()`.**
`configureLazyLoadingGuard()` holds the two statements exactly as the plan writes them. This matches the existing structure of the file (`guardProductionCorsConfig()`, `configureRateLimiting()`, `registerPolicies()`) and gives the "why log-only" rationale a home. No behavioural difference.

**D4 — PG port/container.** Plan Phase 0 reserves `autoerp_test_<letter>` on the shared instance; shared 5433 is held by an unrelated container, so this lane used the dispatch-brief fallback container on `127.0.0.1:5460`, database `autoerp_test_t10`. Never the shared default DB.

**D5 — the plan's "S-9" style audit id was not used in the code comment.** Task 10 has no `S-` item in `05-synthesis.md`; the lazy-load guard is census item C5 (`05-synthesis.md:55`, "Lazy-load guard in non-prod | `preventLazyLoading` | absent"). The docblock therefore cites "Request hygiene Phase A, Task 10".

---

## 5. Pre-existing reds on the base commit (NOT caused by this lane)

Both were proven pre-existing by re-running them with `$this->configureLazyLoadingGuard();` commented out — identical failures — after which the provider was restored.

1. **`tests/Unit/POS/ReceiptReturnServiceTest.php` — 11/11 errors.**
   `ArgumentCountError: Too few arguments to function App\Modules\POS\Application\Services\ReceiptReturnService::__construct(), 11 passed in tests/Unit/POS/ReceiptReturnServiceTest.php on line 88 and exactly 13 expected` (`ReceiptReturnService.php:92`). The test hand-constructs the service; production added two constructor dependencies and the unit test was not updated. **This is one of the four exact-log contracts the plan names (`:292`), so that contract is currently unverifiable on `dev` — it errors before reaching the assertion.** Guard disabled: same 11 errors. This is an existing `dev` breakage that a separate lane should fix; it will also be red in the CI whole-suite gate for reasons unrelated to Task 10.

2. **`tests/Feature/Treasury/PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance`.**
   `Failed asserting that two values of enumeration App\Modules\Treasury\Domain\Enums\PaymentType are equal, SupplierPayment does not match expected DocumentPayment` (`PaymentTest.php:578`). Guard disabled: identical failure.

---

## 6. Not done / owed

- **The CI-only whole-backend-suite leg is NOT run and is a mandatory merge gate** (plan Step 5, Phase 0 line 55, and gate-response rows NB5/NB6: the four named exact-log contracts are explicitly *not* exhaustive). The branch must be pushed and `cd apps/api && ./vendor/bin/phpunit` must be green in CI before merge. **Expect the two pre-existing reds in §5 to appear there**; they are not Task 10 regressions, and §5 documents how to confirm that.
- The lane branch has not been pushed (no push was authorised in the dispatch brief).
- `autoerp_pg_t10` (port 5460) is left running for the reviewer; remove with `docker rm -f autoerp_pg_t10`.

---

## 7. What the reviewer should look at

Gate: **general Opus** (plan Step 5).

1. **Is the guard truly log-only in every non-production environment?** `Model::handleLazyLoadingViolationUsing()` replaces the default handler *before* `preventLazyLoading()` is enabled, and the replacement has no throw path — so `LazyLoadingViolationException` can never be constructed. Confirm no other provider, test bootstrap, or package re-registers a throwing handler afterwards (repo-wide grep for `preventLazyLoading|handleLazyLoadingViolation|shouldBeStrict` returned **only** this new call site).
2. **Blast radius of a new global `Log::warning` in the testing environment.** The risk classes are (a) `shouldNotHaveReceived('warning')` — exactly the plan's four files, all run here; (b) strict `Log::shouldReceive('warning')` — three additional files found and run here; (c) `Log::spy()` (52 files) — permissive, unaffected; (d) `shouldHaveReceived('warning')->once()->with(...)` — arg-filtered, unaffected. The CI whole-suite run is still the authority.
3. **Trait restorability.** `CountsQueries` registers nothing persistent. Verify the `finally` block cannot leave `enableQueryLog()` on for a connection that had it off, and that the "never nest" contract is respected at both call sites (it is — neither closure calls the helper).
4. **Deviation D1** (`ledgerRow()` instead of `receive()`) and **D2** (added linkage assertions) are the two judgement calls.
5. **The query-budget test was green on arrival** because T2 already landed. Its value rests entirely on the falsifying run in §3 (`20 → 43`); satisfy yourself that the reverted patch is the right counterfactual and that the controller is byte-identical to `7f86dbf0c`.
6. **§5 pre-existing reds** — in particular that `ReceiptReturnServiceTest` cannot currently prove its `:292` exact-log contract on `dev` at all.
