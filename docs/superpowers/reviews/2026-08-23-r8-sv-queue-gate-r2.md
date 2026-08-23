# Adversarial merge gate — round 2 — `fix/r8-shift-variance-queue`

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/r8-sv-queue`, branch `fix/r8-shift-variance-queue`, tip `7db5fe467`.
- **Under review this round:** `a096f3551..7db5fe467` (the single fix commit "r8 gate round 1: guard the enqueue leg, audit a flag skew, tell the truth about attempts"). Round-1 findings and the core queue mechanics are **not** re-litigated; see `docs/superpowers/reviews/2026-08-23-r8-sv-queue-gate-r1.md`.
- **Reviewer posture:** read-only. `git status --porcelain` in the worktree is empty before and after. My only write is this file, in the main checkout.
- **Class-resolution check (before trusting any test run):** `ReflectionClass::getFileName()` resolves
  `App\Modules\POS\Application\Services\CashCountDispatcher`, `…\ReportGenerationService`, `…\ZReportSyncController` and `App\Modules\Treasury\Application\Listeners\PostShiftCashVarianceAdjustment` **all** to `…/.worktrees/r8-sv-queue/apps/api/app/…`, and `realpath(vendor)` = `…/.worktrees/r8-sv-queue/apps/api/vendor`. Real vendor, not a symlink to main. Test runs below are trustworthy.

---

## 0. Scope audit (item 8) — CLEAN

`git diff --name-only a096f3551..7db5fe467` — **16 files** (15 PHP/JSON + 1 doc):

| File | Justified? |
|---|---|
| `apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php` (+141, new) | P2-1 |
| `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php` (+13/-2) | P2-1 producer 1 |
| `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php` (+11/-2) | P2-1 producer 2 |
| `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php` (+61) | P2-2, P3-1, P3-3 annotation, docblock corrections |
| `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` (+23/-6) | P3-4 comment drift — comment-only, the `Event::listen` call is unchanged |
| `apps/api/tests/Architecture/QueuedListenerTenantContextTest.php` (+261, new) + `fixtures/queued-listener-deferrals.json` (+22, new) | P3-3 |
| `apps/api/tests/Feature/POS/CashCountDispatchGuardTest.php` (+183, new) | P2-1 pin |
| `apps/api/tests/Feature/Treasury/ShiftCashVarianceQueueRetryTest.php` (+95/-4) | P2-2, P3-1, P3-5 |
| `apps/api/tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php` (+62) | P2-1 end-to-end on both producers |
| `apps/api/tests/Unit/Config/HorizonQueueCoverageTest.php` (+57/-6) | P3-2 |
| 4 × POS test files (+2 each) | constructor-change fallout |
| `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md` (+50/-3) | P3-6 / P3-7 |

**Nothing** in `config/treasury.php`, `config/horizon.php`, `config/queue.php`, `database/migrations/`, `.github/`, the deptrac baseline or any ratchet manifest. No new G-5 surface: `shift_variance_gl_enabled` is still `(bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false)`, untouched. Scope claim holds.

---

## 1. P2-1 closure — the `CashCountDispatcher` (item 1)

### (a) Catch scope and the nesting of the audit write

- `CashCountDispatcher.php:71-75` — `try { Event::dispatch($event); } catch (Throwable $e) { $this->recordUndeliverable($event, $e); }`. It swallows **`Throwable`**, i.e. everything including `Error`/`TypeError`. Given the invariant (the Z report is already committed and hash-chained), that width is the right call: there is nothing a caller could do with the exception except turn a successful close into a 500.
- `recordUndeliverable():82-140` wraps the `auditService->record(...)` call in its own `try { … } catch (Throwable $auditFailure) { Log::critical(…) }` at `:93` / `:131-138`. So an exception **inside the audit write** cannot resurrect the 500. Correct nesting.
- `AuditService::record()` (`app/Modules/Compliance/Services/AuditService.php:42-86`) is a **synchronous** `$event->save()` — no queue, no job. This matters: the flagship failure mode is "Redis is down", and the durable record does not depend on Redis. The claim survives its own worst case.
- **One residual hole:** `Log::error(…)` at `:84-91` and `Log::critical(…)` at `:132-138` sit **outside** any `try`. `recordUndeliverable()` is invoked from inside a `catch` block, so an exception thrown by the logger propagates out of `dispatch()` and lands on the sealed-close stack — exactly what the class exists to prevent. See finding **F-4**. (The pre-existing `PostShiftCashVarianceAdjustment::refuse():856-880` has the identical shape, so this is house style rather than a lane invention — but there a logger throw lands on a worker, not on an HTTP close.)

### (b) The audit row lands in the tenant DB with the Treasury aggregate_id — VERIFIED BY RUN

`aggregateId: $event->shiftId` at `CashCountDispatcher.php:99`, byte-identical to `PostShiftCashVarianceAdjustment::refuse():866` and `deadLetter()` — one query by shift id returns booked / skipped / dead-lettered / consumers-failed together.

Proven, not asserted, by three real runs under `RefreshDatabase`:
- `CashCountDispatchGuardTest::test_an_unreachable_queue_at_push_time_does_not_reach_the_caller` (`:75-108`) — no exception escapes, row found by `event_type` + `aggregate_id`, payload carries `tenant_id`, `company_id`, `shift_id`, `currency`, `aggregate_variance` `'-5.0000'` and the tender breakdown.
- `ShiftCashVarianceTriggerPathsTest::test_an_unreachable_queue_does_not_fail_the_offline_sync_close` (`:198-217`) — a real `POST /api/v1/pos/reports/z/sync` returns **201** with `queue.default` pointing at a non-existent connection, and exactly one `pos.cash_count_consumers_failed` row lands on the shift id.
- `…::test_an_unreachable_queue_does_not_fail_the_live_z_report_close` (`:219-247`) — the live `ReportGenerationService::generateZReport` path completes, `pos_z_reports` has the row (the close really did succeed), nothing is booked, one audit row.

That is the P2-1 invariant demonstrated end-to-end on **both** producers, through the real HTTP route on one of them. Strong.

### (c) Constructor injection (rule 13) and the hand-construction census — COMPLETE

- `ReportGenerationService.php:62-68` and `ZReportSyncController.php:49` both take `private readonly CashCountDispatcher $cashCountDispatcher`. `CashCountDispatcher` itself is `final readonly class` with `private AuditService $auditService` (`:65-67`). No `app()` in production code.
- `grep -rn "new ReportGenerationService(" app/ tests/ database/ routes/` returns **exactly 4** sites, all in tests, **all 4 updated** in this diff: `tests/Unit/POS/ReportGenerationServiceTest.php:65`, `tests/Unit/POS/ReportGenerationIdempotencyTest.php:56`, `tests/Feature/POS/ZReportV3AggregationTest.php:65`, `tests/Feature/POS/GenerateZReportWithCountsTest.php:99`. Each appends `$this->app->make(CashCountDispatcher::class)` in last position, matching the constructor. The set is complete.

### (d) No bypass of the dispatcher — VERIFIED

`grep -rn "CashCountRecorded" --include='*.php' app/` yields exactly **two** `new CashCountRecorded(` construction sites (`ReportGenerationService.php:426`, `ZReportSyncController.php:555`), both now `$this->cashCountDispatcher->dispatch(...)`. `use Illuminate\Support\Facades\Event;` was **removed** from `ZReportSyncController`. No `event(new CashCountRecorded` / `Event::dispatch(new CashCountRecorded` remains anywhere. The only other app references are the listener, the two providers' `Event::listen` registrations, and the event class itself.

---

## 2. The disclosed scope judgment — swallowing the synchronous fraud listener (item 2)

**Does it weaken a fraud-detection guarantee?** Census of what consumes a shift-variance fraud alert:
`grep -rn "SHIFT_CLOSE_VARIANCE" app/ tests/` returns **only** `OpenFraudAlertForShiftVariance.php:38` plus tests; `grep` across `apps/web/src` and `apps/pos/src` returns **nothing**. `grep -rn "FraudAlertRepository|fraud_alerts" app/` finds only the listener, the `FraudAlert` model (`Compliance/Domain/FraudAlert.php:48`) and a presentation-exists scanner entry. The alert is created **after** the Z report commits, via `firstOrCreateByZReport` (`OpenFraudAlertForShiftVariance.php:36-65`) — idempotent, and nothing in the close path reads it back.
**Conclusion: no flow requires the alert to be transactional with the close.** The lane's judgment that the invariant belongs to the seam is correct on the merits, and the alternative (500 on a sealed fiscal document an offline device cannot re-raise) is strictly worse.

**Is the failure still visible enough?** This is where it does not hold up.
- `sentry/sentry-laravel ^4.20` is installed (`composer.json:26`) and a DSN is configured (`.env:104`).
- But `LOG_CHANNEL=stack` / `LOG_STACK=single` (`.env:15-16`, `.env.example:27-28`) — **no `sentry` channel is in the stack**; `config/sentry.php:36` `enable_logs` defaults to `false`; and Sentry's log **breadcrumbs** (`config/sentry.php:54-56`) only attach to an actual reported event, of which there is now none.
- Neither `CashCountDispatcher` nor `PostShiftCashVarianceAdjustment::refuse()` calls `report($e)` — `grep -n "report(" ` on both files returns nothing.

So the swallowed exception reaches **no error reporter at all**. Before this commit, a throwing fraud listener produced an unhandled exception → Laravel's handler → a Sentry event → whatever on-call is wired to it. After it, it produces a `Log::error` line in `storage/logs` and an `audit_events` row that **nothing consumes** (`grep -rn "cash_count_consumers_failed"` across `apps/`, `packages/`, `docs/` returns only the dispatcher, its docblocks, its tests and the deploy-notes ticket). That is a real loss of alerting reach on a fraud surface, and it is one line to fix. See finding **F-1**.

**A second, undisclosed consequence of the same seam** — the dispatch is all-or-nothing and the ordering is load-bearing. See finding **F-3**.

---

## 3. P2-2 closure (item 3) — VERIFIED, and the complement genuinely guards the new path

- `PostShiftCashVarianceAdjustment.php:291-318`: the worker-side belt now writes `refuse($event, 'feature_disabled_after_enqueue', […], level: 'error')` **only when `$this->job !== null`**. The reasoning in the comment is sound — a job can only exist because `shouldQueue()` (`:284`) saw the flag enabled in the web process, so reaching the belt is proof of an API/worker env skew.
- `ShiftCashVarianceQueueRetryTest::test_a_worker_that_disagrees_with_the_dispatch_flag_audits_instead_of_dropping_the_variance` (`:210-238`) drives a real `CallQueuedListener` from `Queue::fake()`, clears `CompanyContext` first, flips the config between enqueue and execution, then asserts one `treasury.shift_variance_gl_skipped` row containing `feature_disabled_after_enqueue`, **zero** `RepositoryAdjustment` rows and balance still `'400.000'`. Real behaviour, no mocks of the thing under test.
- **Red-first (derived from the diff, not executed — read-only posture):** at `a096f3551` `handle()` returns at the flag check with no `refuse()` call whatsoever (the entire `if ($this->job !== null)` block is a `+` hunk), so `refusalCount($shiftId)` would be `0` and the first assertion fails. The claim is structurally airtight.
- **The complement the lane disclosed as green-at-base** — `test_the_disabled_lane_writes_no_row_when_nothing_was_ever_enqueued` (`:245-265`) resolves the listener via the container so `$this->job` is `null` (the `InteractsWithQueue` default) and asserts 0 refusals / 0 dead letters / 0 adjustments. It is indeed green at base, but it **does** guard the new code path: deleting the `$this->job !== null` condition turns it red immediately, and without it the belt would fire one row per shift close on every tenant while the lane is off. Legitimate regression pin, correctly disclosed.

---

## 4. P3-1 — `attempts` / `dead_letter_source` (item 4) — VERIFIED on both paths, no field lies

`PostShiftCashVarianceAdjustment.php:473-489` now writes `'attempts' => $this->job?->attempts()`, keeps `'tries' => $this->tries` (the budget), and adds `'dead_letter_source' => $this->job !== null ? 'listener' : 'queue_failed_handler'`.

Both paths are pinned and both ran green:
- framework `failed()` path — `ShiftCashVarianceQueueRetryTest.php:421-431`: `tries === 3`, **`attempts === null`**, `dead_letter_source === 'queue_failed_handler'`. Correct: `CallQueuedListener::failed()` resolves a fresh listener from the container with no job attached, so the count is genuinely unknowable in that frame and is reported as unknown instead of invented.
- cannot-retry (sync) path — `:515-522`: **`attempts === 1`**, `tries === 3`, `dead_letter_source === 'listener'`. Exact, and it corrects the precise dishonesty round 1 caught.

The deploy-notes ticket teaches the same distinction at `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md:136-138` ("Read `attempts` … NOT `tries`, which is the budget"). No remaining field in the dead-letter payload misrepresents itself.

---

## 5. The two new architecture guards (item 5)

### 5a. `HorizonQueueCoverageTest` regex extension — the SCANNER works, the INTEGRITY TEST does not

- The functional half is real. I applied the scanner's exact regex (`tests/Unit/Config/HorizonQueueCoverageTest.php:65-69`) to the listener file out of band: **1 match, `["default"]`**. `default` is listed in `config/horizon.php` `defaults.*.queue`, so the assertion passes for the right reason. The regex also tolerates `readonly` and `?string`. Good.
- **The integrity test does not detect the regression its own docblock claims.** `test_the_scanner_sees_queue_declared_as_a_property` (`:116-126`) applies a **second, independently written copy** of the regex (`:122-124`) to the listener's file contents. It never invokes the scanner. Deleting the scanner's property branch at `:64-76` leaves this test **green**, because its own literal still matches. The docblock at `:110-114` asserts the opposite in as many words. See finding **F-2**.

### 5b. `QueuedListenerTenantContextTest` + `queued-listener-deferrals.json`

- The scan is genuinely recursive (`RecursiveDirectoryIterator` at `:149-152`) with an anchored path filter (`:163`), and it **does** reach the nested `Workshop/WorkOrder/Infrastructure/Listeners` path that a flat glob would miss — the deferrals entry for `LogPartsNeededForProcurement` is evidence the recursion found something a flat scan would not.
- The **vacuity guard is real and correctly aimed**: `test_the_scan_reaches_the_treasury_shift_variance_listener` (`:123-130`) asserts the discovery actually contains `PostShiftCashVarianceAdjustment`, so a silently-empty scan cannot make the main assertion pass vacuously. This is the tamper-test shape 5a is missing.
- The **bare-annotation check is real**: `:76-89` captures the text after the tag and `:109-115` fails on empty justification. The listener's own tag (`PostShiftCashVarianceAdjustment.php:192`) carries a full paragraph, and its substance is correct (round 1 §3 independently proved tenancy crosses the queue boundary via `QueueTenancyBootstrapper`).
- **The deferrals file is NOT shrink-only-shaped and the escape hatch is unvalidated.** `loadDeferralsFresh():234-260` reads **only** `$entry['class']`; `deferred_to_cluster` and `tracked_in` are never inspected. A new listener can silence the guard forever by appending `{"class": "…"}` with no justification at all. The docblock's "A bare tag fails, exactly as it does in the Jobs guard" (`:41`) is true of the **annotation** path only, not of the deferral path. This mirrors the pre-existing `QueueJobTenantContextTest::loadDeferralsFresh():257-275` / `queue-job-deferrals.json` exactly, so it is inherited house convention rather than a lane invention — but it is worth stating rather than leaving in a reviewer's head. See finding **F-5**.
- Deleting the fixture file fails **loudly** (`:237-239` returns `[]`, all four pre-existing listeners then land in `$unclassified`). Good.
- **The 2 pre-existing Product-job reds are untouched.** `tests/Architecture/QueueJobTenantContextTest.php` still fails on exactly `SendEnrichmentFeedbackJob` and `SendBrandMappingJob` and nothing else. This diff touches no `Jobs/` directory.

---

## 6. Constructor-change blast radius (item 6) — CLEAN

- No production code constructs `ReportGenerationService` by hand (`grep -rn "new ReportGenerationService(" app/` → nothing). Its only production consumer is `POS/Presentation/Controllers/ReportController.php:45`, container-autowired.
- No service provider binds `ReportGenerationService` or `CashCountDispatcher` (`grep` over `app/Modules/POS/Providers/` returns nothing), so both are pure autowire. `CashCountDispatcher` has one concrete dependency (`AuditService`), itself autowirable — proven by `app(CashCountDispatcher::class)` resolving in three green tests and by the full HTTP-route test hitting `/api/v1/pos/reports/z/sync`.
- **Horizon worker path is unaffected**: the queued class is `PostShiftCashVarianceAdjustment`, which does not depend on `ReportGenerationService`. Its constructor is unchanged by this commit.
- Deptrac: `deptrac.yaml:29-45` enforces hexagonal **tier direction only** and explicitly does not enforce cross-module coupling. `Treasury/Application → POS/Application` is same-layer (allowed); `AuditService` lives at `app/Modules/Compliance/Services/` and matches no layer glob. No new violation, and the baseline is untouched.

---

## 7. Verification runs (item 7) — everything I ran, by path, sqlite (`phpunit.xml`)

| Run | Result |
|---|---|
| `tests/Feature/POS/CashCountDispatchGuardTest.php` + `tests/Architecture/QueuedListenerTenantContextTest.php` + `tests/Unit/Config/HorizonQueueCoverageTest.php` | **OK (8 tests, 25 assertions)** |
| `tests/Feature/Treasury/ShiftCashVarianceQueueRetryTest.php` + `tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php` | **OK (18 tests, 107 assertions)** |
| The 4 constructor-fallout POS files (`ReportGenerationServiceTest`, `ReportGenerationIdempotencyTest`, `ZReportV3AggregationTest`, `GenerateZReportWithCountsTest`) | **OK (22 tests, 127 assertions)** |
| `tests/Architecture/QueueJobTenantContextTest.php` | **RED**, on exactly the 2 disclosed `Product\Application\Jobs\*` classes — genuinely pre-existing, unchanged by this diff |
| Pint `--test` on the 8 changed source/guard files | `{"result":"pass"}` |
| PHPStan level 8 on **all 14** changed PHP files | **2 errors, both in `ShiftCashVarianceTriggerPathsTest.php` at `:403` and `:423`** |

**The 2 PHPStan errors are proven pre-existing**, not taken on trust: `git show fa807a699:…/ShiftCashVarianceTriggerPathsTest.php` contains the identical `amount: $amount,` and `$total = bcadd($total, (string) $row->debit, 3);` lines at `:341` / `:361`, shifted +62 by this commit's inserted block. Untouched code, inherited red. The other 13 files are clean.

**The PG red ticket exists and says what the lane claims.** `docs/superpowers/tickets/2026-08-10-g3-shiftcashvariance-fixture-forcefill-pg-trigger.md` — `ShiftCashVarianceAdjustmentTest.php:413` does `forceFill(['balance' => …])->save()`, refused by the `forbid_direct_balance_write()` trigger on real PostgreSQL, "1 failed / 21 passed on PG", "SQLite has no such trigger", filed 2026-08-10, owned by the G3 lane, "test-infrastructure only — no production code implicated", independently reproduced by two reviewers. Exact match to the disclosure.

I did not reproduce the lane's full 14-file / 99-1 sqlite tally or the PG 43/1 tally; I ran 9 files (48 tests, 259 assertions) covering every file this commit touched functionally, plus the two guards.

---

## Findings

### F-1 [Important] — the swallowed exception reaches no error reporter, so a fraud-alert failure is now genuinely quiet

`CashCountDispatcher.php:73-75` catches `Throwable` and hands it to `recordUndeliverable()`, which only calls `Log::error(…)` (`:84`) and writes the audit row. **`report($e)` is never called** (grep on the file returns no `report(`). Sentry is installed (`composer.json:26`) with a live DSN (`.env:104`), but it is wired through the exception handler, and `LOG_STACK=single` (`.env:16`, `.env.example:28`) means no `sentry` log channel is in the stack; `config/sentry.php:36` `enable_logs` defaults `false`; breadcrumbs (`config/sentry.php:54-56`) need an event to attach to.

**Why it matters.** This is the crux of the disclosed scope judgment. Swallowing the fraud listener is defensible *because* the failure is supposed to degrade to something visible — but the audit row has **zero** consumers in code (`grep -rn "cash_count_consumers_failed"` across `apps/`, `packages/`, `docs/` finds only the dispatcher, its tests and a manual SQL snippet in the deploy-notes ticket), and the exception no longer reaches the alerting surface it used to reach as a 500. Net: a fraud-alert store outage at close-of-day goes from "pages someone" to "a line in `storage/logs` and a row nobody queries". That trade was not part of the disclosure.

**Fix.** Add `report($e);` inside `recordUndeliverable()` (and, for symmetry, in `PostShiftCashVarianceAdjustment::refuse()`'s catch at `:874-880`). It preserves the never-block invariant exactly — `report()` cannot fail the close — while restoring Sentry reach. Assert it with `Illuminate\Support\Facades\Exceptions::fake()` or an `ExceptionHandler` spy in `CashCountDispatchGuardTest`.

### F-2 [Important] — `test_the_scanner_sees_queue_declared_as_a_property` does not detect the regression it claims to detect

`tests/Unit/Config/HorizonQueueCoverageTest.php:110-114` (docblock): *"Without this, a regression that silently drops the `$queue` property branch would leave the test green … while the guard quietly stopped covering every queued listener in the codebase."*

But the test body (`:116-126`) applies its **own independently-written copy** of the regex (`:122-124`) to `file_get_contents($listener)`. It never calls the scanner. Delete the scanner's property branch at `:64-76` and this test still passes, because its private literal still matches the listener file. The stated regression is exactly the one it cannot catch. It only catches a change to the *listener's* declaration style — which is not what the docblock promises.

Contrast with the sibling guard added in the same commit: `QueuedListenerTenantContextTest::test_the_scan_reaches_the_treasury_shift_variance_listener` (`:123-130`) calls the real `discoverQueuedListenerClasses()`, so it is a true integrity test. The two guards were built to different standards.

**Fix.** Extract the scan into a private method (e.g. `private function scanQueueNames(string $contents): array`) used by the production assertion, and have the integrity test assert `$this->assertContains('default', $this->scanQueueNames(file_get_contents($listener)))`. Then dropping the property branch turns it red. Or, at minimum, correct the docblock to state what it actually guards.

### F-3 [Important] — the guarded dispatch is all-or-nothing: a queue-push failure silently suppresses the fraud alert AND the event-sourcing write, and the two docs contradict each other about it

**Evidence.** `Illuminate/Events/Dispatcher::invokeListeners():310-330` iterates `getListeners($event)` with **no per-listener `try/catch`** — the first throw aborts the rest. `getListeners():400-410` returns `prepareListeners()` (concrete, in registration order) **before** the wildcard listeners. Registration order is fixed by `bootstrap/providers.php:76-77` — `TreasuryServiceProvider` then `ComplianceServiceProvider`, both registering in `boot()` (`TreasuryServiceProvider.php:163`+`:201`, `ComplianceServiceProvider.php:60`→`registerEventSubscribers():77`+`:81-84`). Spatie's stored-event persistence is a **wildcard** listener (`vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:17-19`, `$events->listen('*', …)`), so it runs last.

Therefore the real order is: **queue push (Treasury) → fraud alert (Compliance) → stored-event persist (Spatie).** A Redis outage at push time throws in the *first* listener, so `fraud_alerts` is never written, the fraud email is never sent, and `CashCountRecorded` is never persisted to the event store — all silently, degraded to a single audit row. Before R-8 the Treasury listener was synchronous and swallowed everything, so the downstream consumers always ran; this suppression path is new.

The two documents disagree about which case is which:
- `CashCountDispatcher.php:55-56` — *"Consumers that already ran before the throw are NOT re-run"* (true for a fraud-listener throw, where the GL job is already enqueued).
- `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md:124` — *"it means a close happened and **no** consumer ran"* (true for a push throw, false for a fraud throw). An operator following that line after a fraud-store outage would conclude the GL leg is missing when it is already queued.

Nothing pins the ordering: reordering `bootstrap/providers.php` silently inverts which consumers survive which fault, and no test would notice.

**Mitigation today.** `treasury.shift_variance_gl_enabled` is FALSE, and `shouldQueue()` (`PostShiftCashVarianceAdjustment.php:284`) short-circuits before `queueHandler()`, so no push and no push exception. The hazard is **latent behind G-5**, which is why this is Important and not Critical.

**Fix (pick one).** (a) Make the seam actually own the invariant per consumer — iterate `Event::getListeners(CashCountRecorded::class)` and invoke each inside its own `try/catch`, so one faulting consumer cannot suppress the others; pin it with "unreachable queue ⇒ the fraud alert still lands". Or (b) keep all-or-nothing, but correct ticket `:124` to describe both cases, record the ordering dependency in the dispatcher docblock, and add a test asserting Treasury is registered ahead of Compliance so a provider reshuffle is caught.

### F-4 [Minor] — `recordUndeliverable()`'s "Never throws" is an overclaim; the two `Log::` calls are outside the try

`CashCountDispatcher.php:78-81` promises *"Never throws — this IS the never-block guarantee"*, but `Log::error(…)` at `:84-91` and `Log::critical(…)` at `:132-138` are unguarded, and the method runs inside a `catch` block, so a logger fault (unwritable `storage/logs`, a broken channel — Monolog's `StreamHandler` throws `UnexpectedValueException`) propagates out of `dispatch()` onto the sealed-close stack. Narrow, but it is the one failure mode this class exists to make impossible. The same shape exists in `PostShiftCashVarianceAdjustment::refuse():856-858`, where the blast radius is only a worker.
**Fix.** Wrap the whole body of `recordUndeliverable()` in one outer `try { … } catch (Throwable) { /* nothing left to do */ }`, or move the `Log::error` inside the existing try.

### F-5 [Minor] — the deferrals escape hatch is unvalidated: a new listener can silence the guard with a bare entry

`QueuedListenerTenantContextTest::loadDeferralsFresh():252-259` reads **only** `$entry['class']`. `deferred_to_cluster` and `tracked_in` in `tests/Architecture/fixtures/queued-listener-deferrals.json` are decorative — never asserted non-empty, never checked for a ticket reference, and the list has no cap or shrink-only ratchet. Appending `{"class": "App\\Modules\\X\\Listeners\\Y"}` permanently exempts a new queued listener with no justification. The docblock's *"A bare tag fails, exactly as it does in the Jobs guard"* (`:41`) covers only the annotation path (`:84-87`), not this one. It faithfully mirrors the pre-existing `queue-job-deferrals.json` convention, so it is systemic rather than lane-novel — but the new fixture also downgrades the convention's content: the Jobs fixture's `tracked_in` carries a real locator (`"api.console-commands.002 (locked at 99b6f5aa)"`), whereas all four new entries carry prose ("Pre-existing queued listener, predates this guard…") with no ticket to chase.
**Fix.** Assert `tracked_in` is non-empty (and, ideally, that the count never grows) in the same test; or file the four deferrals as a real ticket and reference its id.

### F-6 [Minor] — a cross-module `use` import added purely for a docblock link

`PostShiftCashVarianceAdjustment.php:9` adds `use App\Modules\POS\Application\Services\CashCountDispatcher;` solely so the docblock at `:161` can write `{@see CashCountDispatcher}`. It creates a real Treasury→POS static edge for no runtime reason. Rule 6 tolerates depending on another module's public Service, and deptrac does not police cross-module coupling, so this is not a violation — but a fully-qualified name in the docblock costs nothing and keeps the import list honest about actual dependencies.

---

## What is genuinely good (recorded so a round 3 does not re-litigate it)

- **P2-1 is closed at the only frame that could close it**, and demonstrated end-to-end on **both** producers — including a real `POST /api/v1/pos/reports/z/sync` returning **201** with an unresolvable queue connection, and the live `generateZReport` path producing a `pos_z_reports` row while the consumer fault degrades to an audit row. No mocks of the thing under test, `RefreshDatabase` + real models, no `assertTrue(true)`.
- The audit write is **synchronous** (`AuditService::record():83`), so the durability claim survives the exact scenario it was written for (Redis down), and the audit-write failure is correctly nested so it cannot resurrect the 500.
- `aggregate_id` genuinely unifies the four event types on the shift id — verified in code at four call sites and by query in five tests.
- The producer census is airtight: two construction sites, both routed through the dispatcher, `Event` facade import removed from the controller, zero bypasses.
- The constructor-change census is complete and correct (4 test sites, none in production, no container bindings, worker path untouched).
- P2-2 distinguishes "never enqueued" from "enqueued then declined" with a real `CallQueuedListener` and pins **both** halves, including the silence complement that guards the new branch.
- P3-1 is honest in the strongest sense: it reports `null` where the count is genuinely unknowable rather than inventing one, adds a discriminator so the reader knows which frame wrote the row, and teaches the distinction in the operator ticket.
- `QueuedListenerTenantContextTest` has a real anti-vacuity guard, a real bare-annotation check, a genuinely recursive scan that demonstrably found a nested-path listener, and fails loudly if its fixture disappears.
- The G-5 deploy-notes ticket now carries all of round 1's P3-6 residuals as checkboxes (`QUEUE_CONNECTION=redis`, Horizon consuming `default`, env-skew parity, the retry durability window, queue reachability) plus an alerting instruction for the new event type, and a `feature_disabled_after_enqueue` row in the refusal-reason table.
- Scope is tight: no config, no migration, no CI, no baseline. Pint clean, 13 of 14 files PHPStan-clean with the 14th's 2 errors proven inherited, and the disclosed pre-existing reds reproduced exactly as described.

---

## What to fix before merge

Call `report($e)` in the dispatcher's catch so a swallowed consumer fault still reaches Sentry (F-1), make the `$queue`-property integrity test exercise the actual scanner instead of a copy of its regex (F-2), and either isolate consumers per-listener or correct the two contradicting docs about which consumers ran when the push fails (F-3); F-4 to F-6 can ride along.

VERDICT: CHANGES-REQUIRED
