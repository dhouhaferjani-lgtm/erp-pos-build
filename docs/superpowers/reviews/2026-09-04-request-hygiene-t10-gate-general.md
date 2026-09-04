# Gate — Request Hygiene Phase A, Task 10 (general Opus, adversarial)

- Date: 2026-09-04
- Lane: `lane/rh-t10-guards` @ `732fc0a5c` (code `70efb3060`), base `7f86dbf0c`
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t10`
- Handback under review: `docs/handoff/HANDBACK-request-hygiene-T10-2026-09-04.md`
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 10`
- Reviewer scope: read-only. No file in the worktree was edited. Nothing merged.

## VERDICT: **MERGE** (to LOCAL `dev` only — the whole-suite CI leg stays owed as a **promotion** precondition, not a merge one)

Zero blocking findings. Every claim in the handback that I could check was true, and the
two judgement calls (D1, D2) are correct. Six non-blocking findings below, one of which
(NB-1) should be settled before the branch reaches `origin/dev`, because pushing `dev`
auto-deploys staging.

---

## 1. Blocking findings

**None.**

---

## 2. Verification, item by item

### 2.1 Guard semantics — `app/Providers/AppServiceProvider.php:221-240`

```php
private function configureLazyLoadingGuard(): void
{
    Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
        Log::warning('lazy-load', ['model' => $model::class, 'relation' => $relation]);
    });

    Model::preventLazyLoading(! $this->app->isProduction());
}
```

- **Never throws — proven at framework level, not asserted.**
  `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:600-611`:
  ```php
  protected function handleLazyLoadingViolation($key)
  {
      if (isset(static::$lazyLoadingViolationCallback)) {
          return call_user_func(static::$lazyLoadingViolationCallback, $this, $key);   // :603 — returns
      }
      if (! $this->exists || $this->wasRecentlyCreated) { return; }
      throw new LazyLoadingViolationException($this, $key);                            // :610 — unreachable once set
  }
  ```
  With the callback registered, the `throw` at `:610` is unreachable in every environment.
  `LazyLoadingViolationException` cannot be constructed. **Held.**
- **Registration order is correct and, in this case, also irrelevant.** The handler is set at
  `:234` before `preventLazyLoading()` at `:238`, so no window exists where the guard is armed
  with the default throwing handler. Even reversed it would be safe here, because the flag is a
  *static* read at hydration time (`Builder.php:471-473`), not at boot — but the order as written
  is the right one and I would not accept the reverse.
- **Production is unchanged.** `! $this->app->isProduction()` leaves `Model::$modelsShouldPreventLazyLoading`
  at the framework default `false` in production; the instance flag at `Model.php:108`
  (`public $preventsLazyLoading = false`) is then never raised, so `handleLazyLoadingViolation()`
  is never reached and the registered callback never runs. The callback *is* registered in
  production, which is inert and harmless. **Held.**
- **Log payload carries no PII.** `['model' => $model::class, 'relation' => $relation]` — a class
  name and a relation name. No primary key, no attributes, no tenant/company id, no user id.
  Nothing tenant-identifying leaks into a shared log stream. **Held.** (A model id would arguably
  be more useful for debugging; it is correctly omitted.)
- **Only registration site in the repo.**
  ```
  $ rg -n "preventLazyLoading|handleLazyLoadingViolation|shouldBeStrict|preventSilentlyDiscarding|preventAccessingMissing" app tests bootstrap config database
  app/Providers/AppServiceProvider.php:234:        Model::handleLazyLoadingViolationUsing(...)
  app/Providers/AppServiceProvider.php:238:        Model::preventLazyLoading(! $this->app->isProduction());
  ```
  No package, test bootstrap, or other provider re-registers a throwing handler afterwards.
  The handback's grep claim is confirmed. **Held.**
- **Stancl tenancy bootstrapping is not at risk.** Tenant/domain resolution loads single models
  (`find`/`first`), and the instance flag is only stamped when a hydration returns more than one
  row — `Builder.php:471-473`:
  ```php
  $model = $instance->newFromBuilder($item);
  if (count($items) > 1) {
      $model->preventsLazyLoading = Model::preventsLazyLoading();
  }
  ```
  So single-row bootstrap paths are structurally outside the guard, and even if they were inside
  it, the handler is log-only. **Ruled: no interference.**
- **Queue workers / noise.** Ruled **acceptable for merge to local dev, NOT yet ruled acceptable
  for staging** — see NB-1. There is no rate limit, no per-request dedupe, and no config kill
  switch. A worker is a long-lived process and an unconverted N+1 over an N-row collection emits
  N warnings per job. That is the intended diagnostic signal, but it is unbounded.

### 2.2 Exact-log contracts — my own sweep

I did not rely on the handback's sweep. Four shapes can break under a new global `Log::warning`:

| Shape | Command | Files | Handback ran | I ran |
|---|---|---|---|---|
| bare `Log::shouldNotHaveReceived('warning')` | `rg -n "shouldNotHaveReceived\('warning'\|shouldNotReceive\('warning'" tests` | 4 (`InventoryGlPostingSeamTest`, `RefundReportingFieldsTest`, `ReceiptReturnServiceTest`, `TreasuryMovementServiceRecordTest`) | 4 | 4 |
| **arg-filtered** `shouldNotHaveReceived('warning', [...])` | same grep | **3 more** (`CheckCogsCoverageCommandTest:402`, `TenantInitializationTest:223`, `PaymentMethodCashTenderTest:413,420`) | **0** | **3** |
| **any** `Log::shouldReceive(...)` — a Mockery *full* mock throws `BadMethodCallException` on ANY unexpected method, so `shouldReceive('info')` is at risk too, not just `shouldReceive('warning')` | `rg -ln "Log::shouldReceive" tests` | **8 files** | 3 | **all 8** |
| `Log::swap($capturingLogger)` — a hand-rolled logger that implements only some levels dies with `Error: Call to undefined method` | `rg -n "Log::swap\|LogFake\|Log::partialMock\|assertLogged" tests` | **4 files** | **0** | **4** |

The handback's claim "exactly the plan's four `shouldNotHaveReceived` files plus three strict
`shouldReceive('warning')` files" **understates the risk surface**: the strict-mock class is
`Log::shouldReceive` of *any* level (8 files, not 3), and the `Log::swap` class was not
considered at all. I ran the full set. Files I ran that the handback did not:

```
tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php      (Log::shouldReceive('error'))
tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php      (Log::shouldReceive('critical'))
tests/Feature/Fiscal/OutboxIngestorTest.php                     (Log::shouldReceive('error'))
tests/Unit/Shared/Database/MigrationOutputTest.php              (Log::shouldReceive('info'))
tests/Feature/Treasury/ReconcileTreasuryTest.php                (Log::shouldReceive('error'/'info'))
tests/Feature/Accounting/CheckCogsCoverageCommandTest.php       (arg-filtered shouldNotHaveReceived)
tests/Feature/Tenant/TenantInitializationTest.php               (arg-filtered shouldNotHaveReceived)
tests/Feature/Treasury/PaymentMethodCashTenderTest.php          (arg-filtered shouldNotHaveReceived)
tests/Unit/Modules/Product/SendEnrichmentFeedbackJobTest.php    (Log::swap capturing logger)
tests/Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest.php (Log::swap)
tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php        (Log::swap)
tests/Feature/Treasury/TreasuryCheckpointGuardTest.php                  (Log::swap)
```

I also checked the residual `shouldHaveReceived('warning')` population (63 hits): every one is
constrained by `->with(...)`, `->withArgs(...)`, `->times(n)`, or an `assertContains` on a captured
record array, so an extra `('lazy-load', [...])` warning cannot satisfy or over-satisfy them.
`Log::spy()` (52 files) is permissive by construction. No test asserts on `storage/logs` contents
(`rg -ln "storage_path\('logs|laravel\.log" tests` → empty). **The sweep is exhausted.**

Result: all green except `QuarantineBestEffortParseControllerTest` (2 errors) — which I proved
pre-existing, see §2.5 / NB-6.

### 2.3 Trait — `tests/Traits/CountsQueries.php`

- **No `DB::listen`.** Confirmed by reading the file: only `logging()`, `flushQueryLog()`,
  `enableQueryLog()`, `getQueryLog()`, `disableQueryLog()` (`:25-38`). Nothing survives the sample.
  **Held** — this is the one thing the plan cared about most and it is right.
- **Restoration.** `$wasLogging = DB::connection()->logging()` at `:25`; the `finally` at `:33-38`
  flushes and calls `disableQueryLog()` only when logging was previously off. If logging was
  already on, the flag is left on — correct. The log *contents* are destroyed either way, which is
  why nesting is forbidden; that is documented at `:19-22`. **Held.**
  Falsifying scenario I checked for and did not find: an exception thrown inside `$fn()` skipping
  the restore — it cannot, the restore is in `finally` and the `return count(...)` is inside `try`.
- **Connection.** `DB::connection()` with no argument resolves the **default** connection.
  `phpunit.xml:44-49` pins `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` and
  `TENANCY_DB_PER_TENANT=false force="true"`, so under test there is exactly one connection and
  the request under test hits it. The count is therefore the right one **today**. See NB-2 for the
  latent hazard if a lane ever flips that env.
- **Never nested.** The only two call sites are `StockMovementTest.php:655` and `:662`; neither
  closure calls the helper. Confirmed by `rg -n "countQueries\(" tests`. **Held.**
- **Assertion message.** `assertQueryCountAtMost`'s default message names the budget and the actual
  count; the query-budget test overrides it with `'5 rows (3 linked)='.$small.', 50 rows (30 linked)='.$large`,
  which is what actually printed in the falsifying run. Useful. **Held.**

### 2.4 Query-budget test — `tests/Feature/Inventory/StockMovementTest.php:619-667`

- **Two sizes, fixed budget independent of row count.** `per_page=5` (3 linked) vs `per_page=50`
  (30 linked), asserted `$large <= $small + 2` at `:666`. The tolerance is relative to the small
  sample, which is the correct shape for "flat" — an absolute number would be brittle against
  unrelated auth/session queries. **Held.**
- **Modulo argument checked arithmetically.** Ordering is `created_at DESC, id DESC`
  (`StockMovementController.php:120-124`). `created_at` is force-filled to
  `2026-09-03 12:00:00 + index` seconds, so newest-first is index 50, 49, 48, 47, 46 →
  `% 5` = 0, 4, 3, 2, 1 → linked = no, no, yes, yes, yes → exactly 3 of the first 5, 30 of 50.
  `data.0.source_document_id === null` and `data.2.source_document_id === $document->id` (D2, `:659-660`)
  match that derivation exactly. **Held.**
  Nit checked and cleared: `forceFill(['created_at'=>…,'updated_at'=>…])->save()` does survive
  Eloquent's `updateTimestamps()` — `updated_at` is dirty so it is preserved, and `created_at` is
  only stamped for non-existing models.
- **D2 makes an empty response fail.** `assertJsonCount(5,'data')` / `assertJsonCount(50,'data')`
  at `:658` and `:665`. Without them, an endpoint returning `{"data":[]}` would satisfy the query
  budget trivially. This deviation is an **improvement over the plan text** and I would have raised
  its absence as a finding. **Accepted.**
- **D1 (`ledgerRow()` instead of `receive()`) — fixture is equivalent for what the controller queries.**
  `index()` eager-loads `['product.unitOfMeasure','location','user','reversalOf']`
  (`StockMovementController.php:69`) and then does one `Document::whereIn(...)` per page
  (`:130-141`). `ledgerRow()` (`StockMovementTest.php:408-427`) sets `product_id`, `location_id`,
  `user_id`, `movement_type`, `reason`, quantities and `reference` — every column those four
  eager loads and `formatMovement()` read, including `product.unitOfMeasure.decimal_places`
  (`:171`). What `receive()` additionally writes — stock levels, cost layers — is never touched by
  `index()`. **D1 is sound**, and it removes 50 service round-trips from the fixture.
  Note the falsifying run already demonstrates the fixture is *sensitive*: had the fixture been
  wrong (e.g. no linked rows), reverting to a per-row lookup could not have moved the count.
- **Falsifying evidence.** The handback reverted `formatMovement()`'s keyed lookup to
  `Document::query()->whereKey(...)->first()` and measured `20 → 43` (`5 rows=20, 50 rows=43`,
  failing `43 <= 22`). I did **not** re-run this: doing so requires editing the worktree, which my
  brief forbids. I held it on three independent checks instead: (a) the controller is
  byte-identical to base — `git diff 7f86dbf0c..732fc0a5c -- .../StockMovementController.php` is
  empty, so nothing was left behind; (b) the page-level `whereIn` at `:130-141` is the only
  document read, so a per-row revert is the correct counterfactual and would add one query per
  linked row (30 for the large page), which is the reported delta's order of magnitude; (c) the
  assertion's `$small + 2` ceiling is genuinely sensitive to it. **Accepted with the caveat
  recorded** (NB-5).
- **The test was green on arrival** (T2 already in base). Correctly disclosed by the handback; the
  falsifying run is what gives it value. That disclosure is the behaviour I want to see and it is
  worth saying so.

### 2.5 Pre-existing reds

**`tests/Unit/POS/ReceiptReturnServiceTest.php` — 11/11 `ArgumentCountError`, confirmed pre-existing
by code reading, no run of the main checkout needed.**

```
ArgumentCountError: Too few arguments to function
App\Modules\POS\Application\Services\ReceiptReturnService::__construct(),
11 passed in tests/Unit/POS/ReceiptReturnServiceTest.php on line 88 and exactly 13 expected
  app/Modules/POS/Application/Services/ReceiptReturnService.php:92
Tests: 11, Assertions: 0, Errors: 11.
```

`ReceiptReturnService::__construct` (`ReceiptReturnService.php:92-106`) declares 13 promoted
readonly properties, in order:
`CompanyContext`, `CashDrawerService`, `CurrencyScaleResolverInterface`, `ReceiptFinalizationService`,
`RefundDestinationResolver`, `VoucherIssuanceService`, `PaymentRefundService`, `ReceiptHashService`,
`RestockPolicyResolver`, `LegacyCorrectionGuard`, `ReturnScrapWriteOffService`,
**`InventoryGlPostingBuffer $glBuffer`**, **`FEFOInventoryService $fefoService`**.

The test's hand-construction (`ReceiptReturnServiceTest.php:88-99`) stops at
`ReturnScrapWriteOffService` — it supplies the first 11 in the right order and omits the last two.
**The two missing arguments are `InventoryGlPostingBuffer` and `FEFOInventoryService`, both
appended last, so the fix is purely additive:** add
`$this->app->make(InventoryGlPostingBuffer::class),` and `$this->app->make(FEFOInventoryService::class),`
after the `ReturnScrapWriteOffService` line, plus the two `use` imports. No argument reordering,
no test-body change. Neither file appears in this lane's diff (4 files, listed below) and both
were last touched at or before base `7f86dbf0c` (`ReceiptReturnService.php` at `f852f906` "D-1 fix
round r1"), so the breakage is deterministic on `dev` and independent of Task 10.

Consequence the orchestrator must carry: **the plan's fourth exact-log contract
(`ReceiptReturnServiceTest.php:292`, bare `Log::shouldNotHaveReceived('warning')`) is currently
unverifiable on `dev`** — the test errors in `setUp`-adjacent construction long before reaching
line 292. It will only be validated once the fix lane lands. This is the single real gap in Task
10's evidence and it is not Task 10's fault.

**`tests/Feature/Treasury/PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance`**
— known pre-existing, accepted on the brief's statement; the handback also re-ran it with the
guard commented out and got the identical `SupplierPayment != DocumentPayment` enum mismatch.

**Third pre-existing red, NOT in the handback — see NB-6.**

### 2.6 Merge cleanliness

```
$ git merge-tree --write-tree dev lane/rh-t10-guards      # from the main checkout
4601d9d137fdc92dd90060411564850bdddcf88a
exit=0
```
Single tree oid, exit 0 — **no conflicts** against `dev` @ `f85b7c0e9`. `7f86dbf0c` is an ancestor
of `dev`. Diff is 4 files / +430 / −0:
`AppServiceProvider.php`, `tests/Traits/CountsQueries.php`, `tests/Feature/Inventory/StockMovementTest.php`,
`docs/handoff/HANDBACK-request-hygiene-T10-2026-09-04.md`.

---

## 3. Non-blocking findings

**NB-1 (IMPORTANT — settle before promotion, not before local merge). Unbounded warning volume on
staging and in workers.** `AppServiceProvider.php:238` arms the guard for local, testing **and
staging**, with no rate limit, no per-request dedupe, and no config kill switch. Pushing to
`origin/dev` auto-deploys staging (standing rule), so the first promotion that carries this commit
turns every unconverted N+1 into one `Log::warning` per row, per request, per queued job, into the
`stack` channel (`config/logging.php:20`). One 1000-row report page with one lazy relation = 1000
log lines. Recommend one of: (a) `Model::preventLazyLoading(! $this->app->isProduction() && config('logging.lazy_load_guard', true))`
so staging has a kill switch; or (b) a static per-request `model::relation` set so each distinct
pair logs once. Not blocking the lane — the plan mandates the shape as written — but the
orchestrator should rule before this reaches `origin/dev`.

**NB-2. The trait's connection scope is undocumented.** `CountsQueries.php:25-32` counts the
**default** connection only. Today that is exhaustive because `phpunit.xml:49` forces
`TENANCY_DB_PER_TENANT=false`. If a future lane enables db-per-tenant for a test, `countQueries()`
would silently count only the central connection and a budget assertion would pass vacuously —
a false green, which is worse than a false red. Add one docblock line stating "counts the default
connection; under db-per-tenant pass the connection name explicitly", or take an optional
`?string $connection = null`.

**NB-3. `assertQueryCountAtMost()` is dead code.** `CountsQueries.php:41-49` has zero call sites
(`rg -n "assertQueryCountAtMost" tests app` → the definition only). It is plan-mandated so I am
not asking for its removal, but a future task should either use it or drop it.

**NB-4. The guard only sees multi-row hydrations.** `Builder.php:471-473` stamps
`preventsLazyLoading` only when `count($items) > 1`. Lazy loads off `first()`/`find()`/`firstOrFail()`
results are invisible to this guard forever. Worth one line in the provider docblock so a later
reader does not mistake a silent log for "no N+1". (The new test's own comment at
`StockMovementTest.php:693-694` already says this for the test; the provider does not.)

**NB-5. The falsifying run was not independently reproduced.** Recorded honestly: my brief is
read-only, so I verified the counterfactual by inspection (byte-identical controller + the single
page-level `whereIn` at `StockMovementController.php:130-141` + assertion sensitivity) rather than
by re-reverting `formatMovement()`. If the orchestrator wants an independent falsification, it
costs one temporary edit and one 17-second run.

**NB-6. A THIRD pre-existing red the handback did not find:
`tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php` — 2 errors.**
```
Error: Call to undefined method Tests\Feature\Fiscal\CapturingFiscalLog::log()
  vendor/.../Foundation/Exceptions/Handler.php:403
  tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php:76  (and :165)
Tests: 7, Assertions: 12, Errors: 2.
```
`CapturingFiscalLog` (`:439-456`) implements **only** `info()`. I initially suspected the lane —
this is exactly the shape a stray `Log::warning` would produce (unknown method → `Error` → the
exception handler tries `Log::log()` → also undefined). **It is not the lane.** I reproduced the
identical two errors in the MAIN checkout at `dev` `f85b7c0e9`, which does **not** contain the
guard (`grep -c configureLazyLoadingGuard apps/api/app/Providers/AppServiceProvider.php` → 0, working
tree clean for that file). The failure exists without Task 10, so Task 10 is not its cause.
The orchestrator should expect **three** pre-existing reds in the CI whole-suite leg, not two, and
should brief this one alongside the `ReceiptReturnServiceTest` fix.

**NB-7. PHPStan on the new test file reports 2 errors, but tests are out of scope.**
`phpstan.neon:6-7` sets `paths: [app/]`, so `tests/` is never analysed by the configured run.
Analysing `tests/Feature/Inventory/StockMovementTest.php` explicitly yields
`:267 Parameter $reference … expects string, string|null given` (**pre-existing from T2**, present
at base) and `:700 Call to an undefined static method Illuminate\Support\Facades\Log::shouldHaveReceived()`
(a larastan facade-mock limitation, not a defect). Neither is CI-gated. No action.

---

## 4. What held up

- Log-only in every non-production environment, proven at the framework source, not asserted.
- Production untouched.
- Handler registered before the flag.
- No PII in the log payload.
- Sole guard registration site in the repo.
- No `DB::listen`; the logging flag is restored in `finally`; nesting is documented and not done.
- The query-budget test is two-sized, row-count-independent, and D2 closes the empty-response hole.
- D1's `ledgerRow()` fixture is equivalent to `receive()` for everything `index()` actually queries.
- The controller is byte-identical to base — no falsifying patch left behind.
- Every strict-log contract in the repo (bare, arg-filtered, `shouldReceive` of any level, and
  `Log::swap` capturing loggers) is green except one pre-existing red.
- Clean merge against `dev`.
- The handback's two disclosed weaknesses (test green on arrival; four contracts not exhaustive)
  are both accurate and both were disclosed rather than papered over.

---

## 5. Commands and outputs

All runs from `.worktrees/rh-t10/apps/api` unless noted. PG leg against the lane-private
container `autoerp_pg_t10` @ `127.0.0.1:5460`, db `autoerp_test_t10` (left running).

### SQLite — the four named files
```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php \
    tests/Feature/Inventory/InventoryGlPostingSeamTest.php \
    tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php \
    tests/Feature/POS/RefundReportingFieldsTest.php
...................SSSSSSSSSSSSSSSSSSSSSSSSSSS..............SSS.. 65 / 67 ( 97%)
..                                                                67 / 67 (100%)
Time: 00:24.005, Memory: 181.00 MB
OK, but some tests were skipped!
Tests: 67, Assertions: 152, Skipped: 30.
```
(30 skips = the 27 PG-gated `InventoryGlPostingSeamTest` cases + 3 PG-only Treasury cases.)

```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php
................... 19 / 19 (100%)
Time: 00:16.485, Memory: 169.00 MB
OK (19 tests, 77 assertions)
```
(17 before the lane, 19 after — the two new tests.)

### PostgreSQL — the same four files, guard active, nothing skipped
```
$ DB_HOST=127.0.0.1 DB_PORT=5460 DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
  DB_DATABASE=autoerp_test_t10 DB_CENTRAL_DATABASE=autoerp_test_t10 \
  ./vendor/bin/phpunit -c phpunit-pgsql.xml \
    tests/Feature/Inventory/InventoryGlPostingSeamTest.php \
    tests/Feature/Inventory/StockMovementTest.php \
    tests/Feature/Treasury/TreasuryMovementServiceRecordTest.php \
    tests/Feature/POS/RefundReportingFieldsTest.php
................................................................. 65 / 67 ( 97%)
..                                                                67 / 67 (100%)
Time: 01:28.397, Memory: 181.00 MB
OK (67 tests, 280 assertions)
```

### SQLite — the 5 strict `Log::shouldReceive` files the handback did NOT run
```
$ ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php \
    tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php \
    tests/Feature/Fiscal/OutboxIngestorTest.php \
    tests/Unit/Shared/Database/MigrationOutputTest.php \
    tests/Feature/Treasury/ReconcileTreasuryTest.php
................................................................. 65 / 86 ( 75%)
..................... 86 / 86 (100%)
Time: 00:24.880, Memory: 181.00 MB
OK (86 tests, 375 assertions)
```

### SQLite — the 3 arg-filtered `shouldNotHaveReceived('warning', [...])` files
```
$ ./vendor/bin/phpunit tests/Feature/Accounting/CheckCogsCoverageCommandTest.php \
    tests/Feature/Tenant/TenantInitializationTest.php \
    tests/Feature/Treasury/PaymentMethodCashTenderTest.php
................................................................. 65 / 65 (100%)
Time: 00:35.528, Memory: 183.00 MB
OK (65 tests, 191 assertions)
```

### SQLite — the 4 `Log::swap(capturing logger)` files
```
$ ./vendor/bin/phpunit tests/Unit/Modules/Product/SendEnrichmentFeedbackJobTest.php \
    tests/Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest.php \
    tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php \
    tests/Feature/Treasury/TreasuryCheckpointGuardTest.php
...
2) Tests\Feature\Fiscal\QuarantineBestEffortParseControllerTest::test_best_effort_parse_reads_non_admissible_quarantine_table_row
Error: Call to undefined method Tests\Feature\Fiscal\CapturingFiscalLog::log()
Tests: 43, Assertions: 109, Errors: 2.
```
Both errors isolated to `QuarantineBestEffortParseControllerTest`. Pre-existence proof, run in the
MAIN checkout at `dev` `f85b7c0e9` (no guard present):
```
$ cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api && ./vendor/bin/phpunit \
    tests/Feature/Fiscal/QuarantineBestEffortParseControllerTest.php
2) …::test_best_effort_parse_reads_non_admissible_quarantine_table_row
Error: Call to undefined method Tests\Feature\Fiscal\CapturingFiscalLog::log()
Tests: 7, Assertions: 12, Errors: 2.

$ grep -c "configureLazyLoadingGuard" apps/api/app/Providers/AppServiceProvider.php
0
$ git status --short apps/api/app/Providers/AppServiceProvider.php
(clean)
```

### `ReceiptReturnServiceTest` — pre-existing red
```
$ ./vendor/bin/phpunit tests/Unit/POS/ReceiptReturnServiceTest.php
ArgumentCountError: Too few arguments to function
  App\Modules\POS\Application\Services\ReceiptReturnService::__construct(),
  11 passed in …/tests/Unit/POS/ReceiptReturnServiceTest.php on line 88 and exactly 13 expected
  …/app/Modules/POS/Application/Services/ReceiptReturnService.php:92
ERRORS!
Tests: 11, Assertions: 0, Errors: 11.
```

### PHPStan (in-scope files) and Pint
```
$ ./vendor/bin/phpstan analyse app/Providers/AppServiceProvider.php tests/Traits/CountsQueries.php --memory-limit=2G
Note: Using configuration file …/apps/api/phpstan.neon.
 2/2 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors

$ ./vendor/bin/pint --test app/Providers/AppServiceProvider.php tests/Traits/CountsQueries.php \
    tests/Feature/Inventory/StockMovementTest.php
{"result":"pass"}
```
(PHPStan over the test file additionally — out of the configured `paths: [app/]`, see NB-7:
`:267` pre-existing T2 nullable-`$reference`, `:700` larastan facade-mock limitation.)

### Merge check (from the main checkout, read-only)
```
$ git merge-tree --write-tree dev lane/rh-t10-guards
4601d9d137fdc92dd90060411564850bdddcf88a
exit=0        # no conflicts
```

---

## 6. The CI-leg ruling

**Merging to LOCAL `dev` is acceptable with the whole-suite CI leg owed at PROMOTION. The branch
does NOT have to be pushed for CI before the local merge.**

Reasoning. The plan makes the CI whole-suite run a merge gate because the four named exact-log
contracts were explicitly not claimed exhaustive. My sweep in §2.2 closes that specific gap by
construction rather than by sampling: the only shapes a new global `Log::warning('lazy-load', […])`
can break are (a) an unconstrained `shouldNotHaveReceived('warning')`, (b) a strict Mockery Log
facade mock of *any* level, (c) a hand-rolled `Log::swap` logger missing `warning()`, and
(d) an unconstrained `shouldHaveReceived('warning')->once()`. I enumerated all four populations
repo-wide (4 + 8 + 4 files, and 63 `shouldHaveReceived` hits every one of which is arg- or
count-constrained), ran all 16 files, and they are green except one red that exists without this
lane. The residual risk is not zero — the guard is global and a `Log::warning` can in principle
perturb something no grep shape predicts — but it is bounded, log-only, and cannot throw. Holding
a clean, conflict-free, PHPStan-clean lane out of *local* `dev` for that residue is not
proportionate, especially since `dev` is not the deployed surface.

What would satisfy me at promotion — all three, before this commit reaches `origin/dev`:
1. A CI run of `cd apps/api && ./vendor/bin/phpunit` on this branch, green **except** exactly the
   three documented pre-existing reds: `ReceiptReturnServiceTest` (11 errors),
   `PaymentTest::test_supplier_invoice_payment_clears_401…` (1 failure), and
   `QuarantineBestEffortParseControllerTest` (2 errors). **Any fourth red is a Task 10 regression
   until proven otherwise**, and the cheap proof is a re-run with `configureLazyLoadingGuard()`
   commented out.
2. An orchestrator ruling on NB-1 (staging log volume), since the same push that runs CI also
   auto-deploys staging.
3. The `ReceiptReturnServiceTest` fix lane landed, so the plan's fourth exact-log contract
   (`:292`) is actually verified rather than merely unreached.

If the orchestrator would rather not carry that at promotion time, pushing the branch now for a CI
run is strictly better and costs one push of a non-`dev` ref — but it is not a precondition of the
local merge.

---

## 7. Reviewer's note

I never merged and never edited the worktree. `autoerp_pg_t10` (port 5460) left running as
instructed. The shared PG on 5433 was not touched.
