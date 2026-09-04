# Gate — Request Hygiene Phase A, Task 10b

**Per-process dedupe of the log-only lazy-load guard**

- Date: 2026-09-04
- Reviewer: general adversarial merge gate (read-only brief; all mutations reverted, worktree left CLEAN)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t10b`
- Branch: `lane/rh-t10b-lazyload-dedupe` @ `5828b9187`; base `0187a56d1`
- Handback under review: `docs/handoff/HANDBACK-request-hygiene-T10b-2026-09-04.md`
- Upstream: T10 gate `docs/superpowers/reviews/2026-09-04-request-hygiene-t10-gate-general.md` findings **NB-1** (unbounded warning volume) and **NB-4** (multi-row-hydration-only coverage)

---

## VERDICT: **MERGE**

Zero blocking findings. Every load-bearing claim in the handback was independently reproduced,
including the RED, and three mutation falsifiers I ran that the handback did not. The two
`ReceiptReturnServiceTest` reds are confirmed pre-existing and independent of the guard by my own
falsification, not by inheriting the handback's. `git merge-tree` is clean and the merge is a pure
fast-forward. Six non-blocking findings below; none gates the merge, and NB-2 is the D1 ruling.

I do not merge. Promotion to `origin/dev` remains the orchestrator's call — note the standing rule
that pushing `dev` auto-deploys staging, which is exactly the surface NB-1 was about and which this
lane closes.

---

## 1. Blocking findings

**None.**

---

## 2. Verification, item by item

### 2.1 `app/Support/LazyLoadViolationLog.php` — shape, purity, memory bound

`LazyLoadViolationLog.php:34` — `final class`, `declare(strict_types=1)` at `:3`, namespace
`App\Support` at `:5`. No `app()` anywhere in the file (`grep` over `app/` returns the class only at
its own definition and at `AppServiceProvider.php:66,260`). Rule 13 is not in play: the class takes
no dependencies at all.

- **Registry shape** — `private static array $seen` at `:41`, annotated
  `array<string, array<string, true>>` at `:39`. Nested, keyed model → relation. Confirmed nested,
  not concatenated, at `:55` (`isset(self::$seen[$model][$relation])`) and `:61`.
- **`record()` returns true only on first sighting per pair per process** — `:53-64`. Repeat path
  increments `self::$suppressed` at `:56` and returns `false` at `:58`; first path writes the key at
  `:61` and returns `true` at `:63`. Cannot throw: an `isset` on an array, an integer increment, an
  array write. That matters because it runs inside the guard handler on a live request.
- **`suppressedCount()`** — `:69-72`. **`reset()`** — `:79-83`, clears both `$seen` and
  `$suppressed` (the counter reset is pinned by
  `LazyLoadViolationLogTest::test_reset_clears_both_the_set_and_the_counter`).

**Memory bound — stated, as asked.** The registry cannot grow with traffic. Keys are
`$model::class` (a compile-time class name) and the relation method name (a compile-time method
name), so the entry count is bounded by the number of *distinct relation methods in the codebase*,
not by rows, requests or jobs. Measured ceiling in this repo:

```
$ grep -rl "extends Model\b" app/ --include='*.php' | wc -l          → 239   model classes
$ grep -rhoE "public function [a-zA-Z]+\(\): (BelongsTo|HasMany|HasOne|BelongsToMany|MorphTo|MorphMany|MorphOne)" app/ --include='*.php' | wc -l
                                                                      → 710   relation methods
```

So the absolute worst case — every relation in the codebase lazily loaded off a multi-row hydration
inside one process — is **≤ 710 entries**, roughly 100 KB of string keys and `true` values. A
long-lived Horizon worker converges on that ceiling and then stops growing. The handback's "tens"
is the realistic figure; **710 is the hard bound**, and it is a property of the code, not of load.
The only way to exceed it is anonymous model classes (`class@anonymous…$0` yields a distinct FQCN
per definition site) — still finite, still bounded by source files, and none exist in `app/`.

- **Process semantics documented** — `AppServiceProvider.php:241-250` states FPM (once per worker,
  first request that trips it, silence afterwards is **not** proof the N+1 is gone), Horizon (once
  per **worker lifetime**, not per job, deliberately accepted), CLI/artisan/phpunit (once per run,
  with the `Tests\TestCase::setUp()` reset called out). Accurate on all three. PHP has no shared
  memory across FPM workers, so "per process" is the correct and only available granularity; the
  docblock does not overclaim.
- **NB-4 folded in** — `AppServiceProvider.php:252-257` carries the multi-row-hydration caveat the
  T10 gate asked for, in the provider (not only the test), naming `Builder::hydrate()` and
  `first()`/`find()`/`firstOrFail()`. Closes NB-4 as written.

### 2.2 `AppServiceProvider::configureLazyLoadingGuard()`

`AppServiceProvider.php:258-269`:

```php
Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
    if (! LazyLoadViolationLog::record($model::class, $relation)) {
        return;
    }

    Log::warning('lazy-load', ['model' => $model::class, 'relation' => $relation]);
});

Model::preventLazyLoading(! $this->app->isProduction());
```

- **Early-return on repeat** — `:260-262`. ✔
- **Payload shape unchanged** — `:264` is byte-identical to T10 (`'lazy-load'`,
  `['model' => …, 'relation' => …]`). The diff confirms it is an unmodified line; nothing that
  greps the `stack` channel needs to change. ✔
- **Still never throws** — the handler body is one `isset`-backed static call and one `Log::warning`.
  `Model::handleLazyLoadingViolationUsing` replaces the framework's throwing default, so
  `LazyLoadingViolationException` is still unreachable. ✔
- **Production still unarmed** — `:268` is unchanged from T10; `record()` is never even reached in
  production because the handler is never invoked. ✔
- Closure is `static` and captures nothing, so it holds no request-scoped references — no leak path
  into the long-lived worker beyond the bounded registry itself. ✔

### 2.3 `tests/TestCase.php` reset — masking, cost, parallel runners

`tests/TestCase.php:33` calls `LazyLoadViolationLog::reset()` inside `setUp()`, deliberately before
`parent::setUp()` (`:47`), which is correct: the state is static, so it needs no booted app.

- **Cannot mask a real exact-log expectation across tests.** One phpunit run is one process, so
  without the reset the first test to trip a pair would eat the line every later test expects. With
  it, each test starts empty and every `->once()` / `shouldNotHaveReceived('warning')` contract sees
  exactly what it saw under T10. Empirically: the full T10 + T10b guard set is green
  (`StockMovementTest` 21/21) and four `->once()`-heavy strict-log files are green (§2.5).
  There is a narrow *within-test* masking vector — recorded as NB-1 below, not a merge blocker.
- **Only one test base class exists**, so no test escapes the reset:
  ```
  $ grep -rn "extends BaseTestCase\|use CreatesApplication\|Foundation\\\\Testing\\\\TestCase" tests/
  tests/TestCase.php:6, tests/TestCase.php:9      ← the only two hits
  ```
  Every app-booting test extends `Tests\TestCase`. `LazyLoadViolationLogTest` extends
  `PHPUnit\Framework\TestCase` directly and boots no app, so no guard is armed there; it does its
  own `reset()` in both `setUp()` and `tearDown()` (`:20`, `:25`). ✔
- **Cost.** `reset()` is two static assignments — O(1), no allocation beyond two empty values, no
  I/O. Not measurable against a suite where a single Feature test costs ~1 s
  (`StockMovementTest`: 21 tests / 20.5 s; the four-file strict-log batch: 36 tests / 13.9 s — both
  in line with existing baselines). ✔
- **Parallel runners.** **ParaTest is not installed** — no `brianium/paratest` in `composer.json`,
  no `paratest` binary in `vendor/bin` (only `phpunit`, `phpstan`, `pint`), and no `parallel` script.
  So the question is moot today. Were it adopted: ParaTest forks **OS processes** per worker, so
  `static` state is already per-worker-isolated by construction, and the per-test `reset()` makes it
  doubly moot. No risk either way. ✔

### 2.4 Tests — reproduced, plus three mutation falsifiers the handback did not run

**Green baseline** (matches the handback's `OK (27 tests, 101 assertions)` exactly):

```
$ ./vendor/bin/phpunit tests/Unit/Support/LazyLoadViolationLogTest.php
OK (6 tests, 13 assertions)

$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php
OK (21 tests, 88 assertions)
```

**Falsifier 1 — the RED, reproduced.** Removed *only* the four-line early-return from the handler,
left everything else intact:

```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php --filter 'lazy'
1) …::test_repeated_lazy_load_of_same_pair_logs_only_once
Mockery\Exception\InvalidCountException: Method warning(<Any Arguments>) … should be called
 exactly 1 times but called 2 times.
 …/tests/Feature/Inventory/StockMovementTest.php:727
Tests: 2, Assertions: 9, Errors: 1.
```

Byte-for-byte the handback's RED. The test is genuinely load-bearing on the dedupe: it hydrates
**two distinct instances** from one `->get()` (`StockMovementTest.php:718-724`, with an explicit
`assertFalse($movement->relationLoaded('product'))` per row), so the per-instance relation cache
cannot be what suppresses the second line. `test_distinct_relations_on_the_same_model_each_log`
passed under this same mutation (`OK (1 test, 5 assertions)`) — correct, it is an over-dedupe guard,
not a dedupe test. Mutation reverted; `git status --porcelain` empty.

**Falsifier 2 — over-dedupe guard is real.** Mutated `record()` to key on the model alone
(`isset(self::$seen[$model])`), i.e. the exact regression the test claims to catch:

```
$ ./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php --filter 'distinct_relations'
1) …::test_distinct_relations_on_the_same_model_each_log
Mockery\Exception\InvalidCountException: Method warning(<Any Arguments>) … should be called
 at least 1 times but called 0 times.      …/StockMovementTest.php:753
Tests: 1, Assertions: 4, Errors: 1.

$ ./vendor/bin/phpunit tests/Unit/Support/LazyLoadViolationLogTest.php
1) …::test_a_different_relation_on_the_same_model_is_recorded — Failed asserting that false is true.
Tests: 6, Assertions: 12, Failures: 1.
```

Both layers catch it. Reverted; clean. (Worth noting the test's own comment at
`StockMovementTest.php:743-746` — `with()` placed *before* `once()` because Mockery's verification
director fires as soon as it sees `once()` — is correct and is what makes the two per-relation
assertions meaningful rather than "exactly one warning of any shape".)

**Falsifier 3 — the nested-key collision test bites.** Mutated `record()` to a concatenated
`$model.'::'.$relation` key:

```
$ ./vendor/bin/phpunit tests/Unit/Support/LazyLoadViolationLogTest.php --filter 'collide'
1) …::test_pairs_that_concatenate_alike_do_not_collide — Failed asserting that false is true.
 …/tests/Unit/Support/LazyLoadViolationLogTest.php:67
Tests: 1, Assertions: 2, Failures: 1.
```

So D2 (nested array over concatenation) is pinned by a test that actually fails without it, not
merely asserted. Reverted; clean.

### 2.5 Strict-log surface — spot-run of four `->once()`-heavy files

I re-derived the file list rather than trusting it:

```
$ grep -rln "Log::shouldReceive\|shouldNotHaveReceived('warning'\|shouldNotReceive('warning'\|Log::swap" tests | wc -l
20      ← 19 test files + tests/TestCase.php itself (its new comment names Log::spy/shouldReceive)
```

Handback's 19 confirmed. Ranked by `->once()` density, the four richest that run on SQLite
(`InventoryGlPostingSeamTest`, 6 × `->once()`, is PG-gated and skips here):

```
$ ./vendor/bin/phpunit tests/Unit/Shared/Database/MigrationOutputTest.php \
    tests/Feature/Treasury/ReconcileTreasuryTest.php \
    tests/Feature/Fiscal/Migrations/EnableV4RefundAuthoringByDefaultMigrationTest.php \
    tests/Unit/Modules/Product/SendEnrichmentFeedbackJobTest.php
OK (36 tests, 156 assertions)
```

(5 + 4 + 4 + 3 = 16 `->once()` expectations across the four.) Consistent with the handback's batch-2
`OK (209 tests, 770 assertions)`; these four are a subset of it.

**The two known reds — falsified independently, not inherited.** I did not take the handback's word.
I disarmed the guard at its call site (`// GATE-FALSIFY $this->configureLazyLoadingGuard();` in
`boot()`), so neither the guard nor the dedupe was registered at all:

```
$ ./vendor/bin/phpunit tests/Unit/POS/ReceiptReturnServiceTest.php     # guard fully disarmed
Tests: 11, Assertions: 18, Failures: 2, Skipped: 1.                    # ← identical two
```

versus the same file on the branch as committed:

```
$ ./vendor/bin/phpunit tests/Unit/POS/ReceiptReturnServiceTest.php
1) …::test_partial_return_restores_batch_stock_proportionally           '1.2000' vs '0.0000'  (:514)
2) …::test_full_return_after_partial_caps_cumulative_batch_restitution  '3.0000' vs '0.0000'  (:568)
Tests: 11, Assertions: 18, Failures: 2, Skipped: 1.
```

Identical failures with and without the guard → batch stock restitution debt, wholly unrelated to
logging and untouched by this lane. Mutation reverted; `grep -c GATE-FALSIFY` → 0; worktree clean.

### 2.6 Static analysis, style, deptrac

```
$ ./vendor/bin/phpstan analyse app/Providers/AppServiceProvider.php \
    app/Support/LazyLoadViolationLog.php --memory-limit=2G
 [OK] No errors                                   (phpstan.neon, level 8)

$ ./vendor/bin/pint --test app/Support/LazyLoadViolationLog.php app/Providers/AppServiceProvider.php \
    tests/TestCase.php tests/Unit/Support/LazyLoadViolationLogTest.php \
    tests/Feature/Inventory/StockMovementTest.php
{"result":"pass"}
```

deptrac claim verified rather than accepted: `deptrac.yaml` exists (4097 bytes) and its layers are
`SharedDomain`, `SharedContracts`, `SharedApplication`, `SharedInfrastructure`, `ModuleDomain`,
`ModuleApplication`, `ModuleInfrastructure`, `ModulePresentation`. `grep -F Support deptrac.yaml`
→ rc=1, `grep -F Providers deptrac.yaml` → rc=1. Neither namespace is classified, so the new class
cannot move the ratchet. Handback correct.

`App\Support` is not a new namespace invented by this lane — `app/Support/Traits/` already holds
`FiltersAndSorts.php` and `PaginatesResults.php`. Placement is consistent with rule 6 (this is
framework-boot infrastructure, not module code, and it crosses no module boundary).

### 2.7 Merge-tree

```
$ git merge-tree --write-tree dev lane/rh-t10b-lazyload-dedupe
d0f0cd7bcbade51018ee40978be49bb11e048ec9        exit=0, zero CONFLICT lines
$ git rev-parse --short dev  →  0187a56d1       (= the lane's base)
```

`dev` is still at the lane's base, so this is a **pure fast-forward**, not even a real merge.

---

## 3. Non-blocking findings

**NB-1. Narrow within-test masking: a pair tripped during fixture-building mutes the same pair in
the asserted flow.** `tests/TestCase.php:33` resets per test, but the reset happens in `setUp()`
while most tests call `Log::spy()` *later*, after building fixtures. Falsifying scenario: a test
whose fixture phase lazy-loads `Foo::bar` (registry now holds the pair; the spy is not yet
installed, so nothing is asserted about it), then installs `Log::spy()`, then exercises a flow that
also lazy-loads `Foo::bar` — `record()` returns false, no warning reaches the spy, and a
`Log::shouldNotHaveReceived('warning')` contract passes over a real N+1 that would have failed it
under T10. Impact is genuinely small: these contracts assert the *absence of unrelated warnings*,
the programme's actual N+1 enforcement is the `CountsQueries` trait (not Log assertions), and the
suppression only ever makes an absence-assertion easier, never a presence-assertion. Fix if ever
wanted: `LazyLoadViolationLog::reset()` immediately after `Log::spy()` in the handful of tests that
care. Not worth a change now.

**NB-2 — this is the D1 ruling. `suppressedCount()` has zero consumers and should stay a
follow-up.** Verified by grep across `app/ tests/ config/ routes/ bootstrap/`: the only references
are the definition at `LazyLoadViolationLog.php:69` and four assertions inside its own unit test. It
is a tested, documented, dead-today API. I rule **no consumer is needed now**, for three reasons.
(i) The suppressed *count* is not actionable — the actionable unit is the pair, and the pair is
logged; an operator who sees `lazy-load {StockMovement, product}` does the same work whether it
fired twice or 10 000 times. (ii) The operator signal NB-1 actually asked for was *bounded volume*,
and that is delivered. (iii) A terminating callback gated on `> 0` adds new production-adjacent boot
surface (`app->terminating()` / `register_shutdown_function`) emitting a second log shape nothing
consumes; the handback's own objection — that an ungated shutdown hook writes on every request — is
answered by the `> 0` gate, but the residual value is still ~zero. The one real loss (absence of a
line no longer implies absence of an N+1) is already handled the right way: it is documented at
`AppServiceProvider.php:241-250` where a log reader will find it. Revisit only if staging log
analysis is ever automated (see NB-6).

**NB-3. The process-semantics docblock covers FPM, Horizon and CLI, but not a persistent-worker
runtime.** `laravel/octane` is *not* a dependency (`grep -i octane composer.json` → no hits; the
only matches are stock skeleton stanzas in `config/cache.php:30,90` and `config/permission.php:110`),
so this is hypothetical today. If Octane/Swoole/RoadRunner is ever adopted, the registry would
persist for the entire worker's life across thousands of requests with no reset — the same semantics
as the documented Horizon case, and it should get its own bullet at that point. One line, later.

**NB-4. ParaTest is not installed**, so the brief's parallel-isolation question has no live answer
to give: no `brianium/paratest` in `composer.json`, no binary in `vendor/bin`. Recorded so a future
reader does not re-open it. If adopted, ParaTest's per-worker OS processes isolate statics anyway.

**NB-5. `LazyLoadViolationLogTest` is not `final` and carries no `#[CoversClass]`.** Cosmetic;
consistent with neighbouring test classes.

**NB-6. Nothing in the log itself tells a staging reader that dedupe is on.** The semantics live only
in a PHP docblock. A reader grepping `stack` for `lazy-load` sees one line per pair and has no
in-band signal that 999 siblings were swallowed or that silence is worker-scoped. Acceptable while
the audience is this team; if the NB-1 staging read is ever automated, a single boot-time line
(`lazy-load guard armed, deduped per process`) is the cheapest fix. Follow-up, tied to NB-2.

---

## 4. What held up

Everything material in the handback, including the parts I tried hardest to break:

- The RED reproduced byte-for-byte, and the test is not tautological — two distinct hydrated
  instances rule out the per-instance relation cache as the cause.
- The over-dedupe guard and the concat-collision falsifier both genuinely fail under the exact
  mutations they claim to catch (the handback asserted this; I proved it).
- The two `ReceiptReturnServiceTest` reds are pre-existing, proved by my own independent
  disarm-the-guard run rather than by inheriting the handback's.
- The 19-file strict-log sweep list re-derived from scratch and matched; four `->once()`-heavy files
  spot-run green (36 tests).
- deptrac non-classification verified against the file's actual layer list, not assumed.
- PHPStan level 8 clean, Pint clean, merge-tree clean and a pure fast-forward.
- No `app()` helper, strict types, `final`, no new module-boundary crossing, production path
  unchanged.

One thing the handback slightly undersells and this gate pins down: the memory bound is not just
"tens" by convention — it is **hard-capped at ≤ 710 entries** by the count of relation methods in
`app/`, which is why a long-lived Horizon worker is safe.

All three mutations were reverted immediately after each run; `git status --porcelain` in the
worktree was empty after each, and is empty now apart from this report.

---

## 5. Commands run

```
git -C .worktrees/rh-t10b log --oneline 0187a56d1..5828b9187
git -C .worktrees/rh-t10b diff 0187a56d1..5828b9187
grep -rn "extends BaseTestCase|use CreatesApplication|Foundation\\Testing\\TestCase" tests/
grep -i "paratest|parallel" composer.json ; ls vendor/bin
grep -rl "extends Model\b" app/ --include='*.php' | wc -l                      → 239
grep -rhoE "public function [a-zA-Z]+\(\): (BelongsTo|HasMany|…)" app/ | wc -l → 710
grep -rln "Log::shouldReceive|shouldNotHaveReceived('warning')|…" tests | wc -l → 20 (19 + TestCase)
grep -n "name:" deptrac.yaml ; grep -F Support deptrac.yaml ; grep -F Providers deptrac.yaml

./vendor/bin/phpunit tests/Unit/Support/LazyLoadViolationLogTest.php           → OK (6, 13)
./vendor/bin/phpunit tests/Feature/Inventory/StockMovementTest.php             → OK (21, 88)
./vendor/bin/phpunit tests/Unit/Shared/Database/MigrationOutputTest.php \
  tests/Feature/Treasury/ReconcileTreasuryTest.php \
  tests/Feature/Fiscal/Migrations/EnableV4RefundAuthoringByDefaultMigrationTest.php \
  tests/Unit/Modules/Product/SendEnrichmentFeedbackJobTest.php                 → OK (36, 156)
./vendor/bin/phpunit tests/Unit/POS/ReceiptReturnServiceTest.php               → 2 F (pre-existing)

# mutation 1: drop the early-return       → StockMovementTest --filter lazy: 1 error (RED reproduced)
# mutation 2: key on model only           → distinct_relations: 1 error; unit: 1 failure
# mutation 3: concatenated key            → collide: 1 failure
# mutation 4: disarm configureLazyLoadingGuard() → ReceiptReturnServiceTest: same 2 F
# all reverted; git status --porcelain empty after each

./vendor/bin/phpstan analyse app/Providers/AppServiceProvider.php \
  app/Support/LazyLoadViolationLog.php --memory-limit=2G                       → [OK] No errors
./vendor/bin/pint --test <the five files>                                      → {"result":"pass"}
git merge-tree --write-tree dev lane/rh-t10b-lazyload-dedupe                   → d0f0cd7bc…, exit 0
```
