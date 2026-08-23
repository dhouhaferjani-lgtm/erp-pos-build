# Adversarial merge gate — round 1 — `fix/r8-shift-variance-queue`

- **Lane:** P3 residual **R-8** (`docs/handoff/HANDBACK-enforcement-p3-2026-08-21.md:348` — "Shift-variance GL refusals have no retry or dead-letter … `PostShiftCashVarianceAdjustment` is a plain synchronous listener, not `ShouldQueue`.")
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/r8-sv-queue`
- **Commit:** `a096f3551` on base `fa807a699` (single commit, worktree clean before and after review)
- **Reviewer posture:** read-only; every claim below re-derived from source read in this worktree.
- **Class-resolution check (before trusting any test run):**
  `ReflectionClass::getFileName()` →
  `…/.worktrees/r8-sv-queue/apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php`,
  `…/.worktrees/r8-sv-queue/apps/api/vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php`,
  `realpath(vendor)` = `…/.worktrees/r8-sv-queue/apps/api/vendor`. Real vendor, not a symlink to main. Test runs are trustworthy.

---

## 0. Diff enumeration (item 10)

`git diff fa807a699..a096f3551 --stat` — **3 files, 889 insertions, 35 deletions**:

| File | Justified? |
|---|---|
| `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php` (+330/-35) | Yes — the subject of R-8. |
| `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` (+10/-6) | Yes, but **comment-only**: the `Event::listen(CashCountRecorded::class, [PostShiftCashVarianceAdjustment::class, 'handle'])` call at `:187-190` is byte-identical to base. No behavioural change here. |
| `apps/api/tests/Feature/Treasury/ShiftCashVarianceQueueRetryTest.php` (+584, new) | Yes — the red-first pin. |

**No** `config/horizon.php` change, **no** migration, **no** `config/treasury.php` change, **no** ratchet/manifest/deptrac surface, nothing outside Treasury. Scope claim holds.

---

## 1. Producer census (item 1) — VERIFIED, claim holds

`grep -rn "CashCountRecorded" --include='*.php' apps/api/` (vendor excluded) yields exactly two dispatch sites:

1. `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:415` — `event(new CashCountRecorded(...))` inside a `DB::afterCommit(function () use (...) {...})` opened at `:258`. Its only caller is `apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php:152` (`generateZReport`), routed at `apps/api/app/Modules/POS/routes.php:100`. Grep for `generateZReport|ReportGenerationService` across `apps/api/app` + `apps/api/routes` returns no job, command, projector or scheduler caller — only that controller (the remaining hits are `VatReportGenerationService`, an unrelated class, and docblocks).
2. `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:269` → `dispatchCashCountRecorded()` at `:507`, which calls `Event::dispatch(new CashCountRecorded(...))` at `:548`. The call site at `:267-277` sits *after* the `DB::transaction(...)` closure returns at `:547`/`:264`, as its own comment states.

Both HTTP. **Neither inside a queued job or a projection.** The "double-queue" concern the lane dismissed is correctly dismissed.

Two additional producer channels checked and cleared:
- `CashCountRecorded extends DomainEvent extends Spatie\EventSourcing\StoredEvents\ShouldBeStored` (`apps/api/app/Shared/Domain/Events/DomainEvent.php:16`). Spatie's `EventSubscriber` (`apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:21-31`) **persists** the event on dispatch but never re-dispatches it into the Laravel dispatcher, so an event-sourcing replay cannot re-enter this listener.
- The only other consumer, `Compliance\Listeners\OpenFraudAlertForShiftVariance` (registered `ComplianceServiceProvider.php:82-85`), is a consumer, not a producer.

---

## 2. Queued-listener mechanics (item 2) — VERIFIED in vendor

Array-form `Event::listen` + `ShouldQueue` really does queue. Chain re-read in `apps/api/vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php`:

- `makeListener():477-494` → `is_array($listener) && is_string($listener[0])` → `createClassListener()`.
- `createClassCallable():522-540` destructures the array to `[$class,$method]`, then `handlerShouldBeQueued($class)` (`:563-573`, `ReflectionClass::implementsInterface(ShouldQueue::class)`) → `createQueuedHandlerCallable()`.
- `createQueuedHandlerCallable():581-594` → `handlerWantsToBeQueued($class,$arguments)` (`:634-643`), which does `$this->container->make($class)` and calls **`$instance->shouldQueue($arguments[0])`**. Our signature `shouldQueue(CashCountRecorded $event): bool` (listener `:265`) matches.
- `queueHandler():653-680` reads `$listener->queue ?? null` → `$connection->pushOn('default', $job)`.
- `createListenerAndJob():689-695` builds the listener via **`newInstanceWithoutConstructor()`** — PHP still applies declared property defaults, so `public int $tries = 3` (`:223`), `public array $backoff = [5,15]` (`:232`) and `public string $queue = 'default'` (`:240`) are all populated. `propagateListenerOptions():705+` copies `tries` and `backoff` onto the `CallQueuedListener`.
- Array backoff genuinely works: `Queue::getJobBackoff()` (`Illuminate/Queue/Queue.php:238-251`) implodes `[5,15]` to `"5,15"` into the payload; `Worker::calculateBackoff()` (`Illuminate/Queue/Worker.php:660-670`) explodes it back and indexes by `attempts()-1`. No `explode()`-on-array TypeError.
- `CallQueuedListener::handle()` calls `setJobInstanceIfNecessary()` which `setJob()`s onto the resolved listener because it uses `InteractsWithQueue` (listener `:177`) — so `$this->job` is populated on the worker.
- `CallQueuedListener::failed($e)` resolves a **fresh** handler from the container and calls `$handler->failed(...$data, $e)` — matching `failed(CashCountRecorded $event, Throwable $e)` at listener `:387`.

**Flag symmetry (the attack).** `shouldQueue()` `:267` and `handle()`'s belt `:274` are the *same expression*, `config('treasury.shift_variance_gl_enabled') === true` / `!== true`. `config/treasury.php:28` = `(bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false)` — a **global** config, not a tenant setting, so "tenant A on, tenant B off" is not expressible and there is no per-tenant divergence. `shouldQueue()` is evaluated in the producer's process; tenancy is initialized there (see §3) but is irrelevant to a global env flag. **Flag OFF at dispatch ⇒ nothing enqueued** (pinned by `ShiftCashVarianceQueueRetryTest::test_the_disabled_lane_enqueues_nothing_at_all`, `:187-197`). **Flag ON at dispatch, OFF at execution ⇒ silent no-op** — see finding **P2-2**.

---

## 3. Tenancy across the queue boundary (item 3) — VERIFIED, including `failed()`

- `apps/api/config/tenancy.php:42` lists `QueueTenancyBootstrapper::class`.
- `vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php:123-131` registers the payload generator via `$this->queue->createPayloadUsing(...)`, which is **global to every push on every connection** — it is not job-class aware, so a `CallQueuedListener` payload is stamped exactly like a `Job` payload. `getPayload():137-150` returns `['tenant_id' => …]` unless `queue.connections.$connection.central` is truthy; `grep -n central apps/api/config/queue.php` returns **nothing**, so tenant_id is always stamped.
- Re-initialization hooks: `setUpJobListener():58-79` listens on `JobProcessing` **and** `JobRetryRequested` (initialize) and on `JobProcessed` / `JobFailed` (revert).
- **`failed()` runs with tenancy still bound.** `Illuminate/Queue/Jobs/Job.php:182-224`: `fail()` does `delete(); $this->failed($e);` inside a `try`, and only dispatches `new JobFailed(...)` in the **`finally`**. So `CallQueuedListener::failed()` → our `failed()` → `deadLetter()` executes *before* the bootstrapper's revert. The dead-letter row lands in the tenant DB, not central. **Not a P1.**
- Producer-side tenancy: `apps/api/bootstrap/app.php:139-148` appends `ResolveTenancy::class` to the **`api`** group and `:169-172` pins it before `AuthenticatesRequests` in the priority list. `ResolveTenancy` (`apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:78`) → `TenancyResolver::initializeIfProvisioned()` (`apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:81-107`) calls `tenancy()->initialize($tenant)` when the tenant DB exists. Both dispatch sites are on `api` routes. Tenant_id will be stamped in db-per-tenant mode.
- Precedent listeners: not re-verified line-by-line and **not load-bearing** — the vendor chain above is sufficient proof on its own. I record the precedent claim as *unverified* rather than accepting it.

---

## 4. Retry classification (item 4) — VERIFIED

`handle():270-315` arms, in order:

| Arm | Line | Disposition |
|---|---|---|
| feature flag off | `:274-276` | terminal, **silent** (no row) — see P2-2 |
| `InsufficientRepositoryBalanceException` | `:279-290` | terminal `refuse('insufficient_repository_balance')`, `warning` — unchanged from base |
| `RepositoryFrozenException` | `:291-299` | terminal `refuse('repository_frozen')`, `warning` — unchanged |
| **`UnbalancedJournalEntryPostException`** | `:300-311` | **`retryOrDeadLetter(..., 'unbalanced_journal_entry', $e)`** → re-throw while a worker exists, else dead-letter |
| `Throwable` (generic) | `:312-314` | **`retryOrDeadLetter(..., 'exception', $e)`** |

Non-exception policy refusals inside `post()` (`aggregate_not_attributable`, `aggregate_breakdown_mismatch`, `unattributable_tolerance_writeoff`, `currency_mismatch`, unresolved/ambiguous repository, zero/sub-precision variance) all `return` after `refuse()` — terminal, never retried. Correct: they are deterministic policy outcomes.

**The chokepoint reaches the retry path.** Pinned by `ShiftCashVarianceQueueRetryTest::test_a_retryable_fault_is_returned_to_the_worker_instead_of_being_swallowed` (`:232-251`), which drives the real chokepoint (`GeneralLedgerService::sealAndPersistEntry` throws `UnbalancedJournalEntryPostException::forChokepoint` at `GeneralLedgerService.php:3520`) via a real `JournalLine::created` hook, not a mock, and asserts the exception escapes `handle()`. And it reaches the dead letter after `$tries` — `test_an_exhausted_job_dead_letters_the_variance_with_recoverable_details` (`:323-381`) drives `failed()` with that exact exception and asserts `reason === 'unbalanced_journal_entry'`.

**Retry cannot double-post.** Chain re-derived:
- `RepositoryAdjustmentService::post()` opens **one** `DB::transaction` at `:114` covering document + JE + movement.
- Document id is UUIDv5-derived once, from the same helper on both the booking path (`PostShiftCashVarianceAdjustment.php:624`, `adjustmentId: $this->documentIdFor($event->shiftId)`) and the dead-letter payload (`:442`), implemented at `:861-867`.
- `RepositoryAdjustment::query()->firstOrCreate(['id' => $adjustmentId], …)` at `RepositoryAdjustmentService.php:131`.
- JE reuse on replay: `$entry = $adjustment->wasRecentlyCreated ? null : $adjustment->journalEntry;` at `:156`.
- DB backstops: `2026_08_08_120100_unique_journal_entries_source_repository_adjustment.php:38-43` (partial unique on `(source_type, source_id) WHERE source_type='repository_adjustment' AND status='posted'`) and `2026_08_08_140000_unique_repository_adjustments_pos_shift.php`. The partial index matters precisely because `journal_entries` has **no global** `(source_type, source_id)` uniqueness.
- `if ($result->wasIdempotentHit) { return; }` at listener `:629-631` prevents a second `…_booked` audit row.
- Both retry shapes are pinned green: partially-failed-then-retry (`:253-283`) and **committed**-then-retry (`:294-317`), each asserting exactly 1 document / 1 movement / 1 posted entry / 1 booked audit row and `balance === '395.000'` (moved once).

---

## 5. Sync-connection semantics (item 5) — no regression vs base on the HTTP path

- Base behaviour for both exception arms was `refuse(...)` + **no throw** (visible in the diff's `-` block, base lines ~196-215: "Log-never-block … Failing here would surface as a 500 on a close that actually succeeded"). Base did **not** swallow silently — it wrote a `treasury.shift_variance_gl_skipped` row.
- Lane behaviour under sync: `connectionCanRetry():375-378` (`$this->job !== null && ! $this->job instanceof SyncJob`) is false → `deadLetter():409` → `refuse(...)` with the **same event type and same machine reason** plus a `dead_lettered: true` payload key, plus the new `…_dead_lettered` row. **Strictly a superset of base; nothing is thrown back to the caller.** Pinned by `:413-439`, which uses the real `sync` connection via `event(...)`: no exception escapes, `refusalCount === 1`, `deadLetterCount === 1`, and `RepositoryAdjustment::count() === 0` / balance `'400.000'` (fail-closed, nothing half-written).
- `SyncQueue::push()` genuinely produces a `SyncJob` and genuinely serializes/unserializes the payload, so that test also incidentally proves the event survives a real serialize round-trip.
- Config: `apps/api/config/queue.php:16` `'default' => env('QUEUE_CONNECTION','database')`; `apps/api/.env:61` and `.env.example:100` both `redis`; `apps/api/phpunit.xml:51` `sync` (tests only). `config/horizon.php:201-209` `defaults.supervisor-1.connection = 'redis'`, `queue = ['default', 'fiscal-projections', 'enrichment', 'images', 'imports', 'ingestion']` — `default` **is** consumed. Nothing routes this listener to `sync` in a real deployment that I can see from this repo. **However** — an environment that omits `QUEUE_CONNECTION` falls back to the `database` driver, which no Horizon supervisor consumes (Horizon is redis-only); jobs would sit in the `jobs` table forever. That is a deploy-config hazard, not a code defect; noted under P3-6.

---

## 6. Dead-letter honesty (item 6) — mostly VERIFIED, one dishonest field

- **Never-throws claim holds.** `deadLetter():409-472` calls `refuse()` *outside* a try — but `refuse():786-820` swallows its own audit failure in a `try/catch (Throwable)` at `:799-819`. The dead-letter `auditService->record` is separately wrapped `:417-455` / `catch` `:456-469` → `Log::critical`. So neither leg can throw. Correct, and it matters: under sync a throw here would land on the shift-close stack.
- **Derived document id matches a manual re-run**: `:442` and `:624` call the identical `documentIdFor()` (`:861-867`), and the test states the UUIDv5 namespace + URN independently (`ShiftCashVarianceQueueRetryTest.php:75, 545-551`) and asserts equality at `:364`. An operator can reconcile.
- **Rule 19 in the payload**: every money field is a `numeric-string` — `aggregate_variance` from `VarianceAmount::$amount` (declared `public string $amount` with `@var numeric-string`, `VarianceAmount.php:11-13`), and each breakdown row from `CashCountBreakdownDTO`'s `public readonly string $expectedAmount/$actualAmount/$varianceAmount` (`CashCountBreakdownDTO.php:16-18`). Test pins the literal strings `'-5.0000'`, `'120.0000'`, `'115.0000'` (`:356, 369-371`). `grep -n "float|parseFloat|(float)|number_format"` on the listener returns only the word "float" inside a prose comment at `:50`. **No floats.**
- **Dishonest field:** `'tries' => $this->tries` — see **P3-1**.

---

## 7. Rule 19 / worker-context audit (item 7) — CLEAN

Full chain re-read, no bare no-arg `getScale()` and no `CompanyContext` dependency anywhere on the worker path:

| Hop | Evidence |
|---|---|
| listener | `PostShiftCashVarianceAdjustment.php:578` — `$this->scaleResolver->getScale($repository->currency)`; the only `getScale` call in the file (`:161`, `:576` are comments) |
| service | `RepositoryAdjustmentService.php:98` — `getScale($repository->currency)`, then `CurrencyScale::bcformatStrict()` at `:100` |
| service (user) | `:66-70` resolves the actor from `$intent->userId`, **not** `auth()` — worker-safe |
| GL entry | `GeneralLedgerService.php:1235` `?string $currencyCode = null` param, forwarded at `:1308` → `postEntryNow($entry, $user, $currencyCode)` |
| GL post | `:3451` `postEntryNow(..., ?string $currencyCode = null)` → `sealAndPersistEntry()` `:3481` → `:3498` `$this->scaleResolver->getScale($currencyCode ?? $companyCurrencyCode)` |

The class-level `private function scale(): int { return $this->scaleResolver->getScale(); }` (`GeneralLedgerService.php:62-65`) — the bare no-arg form that throws outside HTTP — is **not** reachable from this path.

`CompanyContext` is explicitly cleared before invocation in **7** places across the new test file: `:149, :193, :214, :328, :387, :418, :451` (the `runOnWorker()` helper clears it at `:451`, so tests 4/5/6 inherit it). Meets the P3 projection-test convention.

---

## 8. G-5 containment (item 8) — CLEAN

- `apps/api/config/treasury.php` is **not** in the diff. `shift_variance_gl_enabled` remains `(bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false)` at `:28` — fail-closed when the env var is absent, and `=== true` / `!== true` comparisons mean any non-`true` value is off.
- `shouldQueue():267` and `handle():274` read the *same* key with the *same* comparison. No new flag, no new default, no flip-condition change, no new G-5 surface. Deploy-notes ticket referenced in the config comment untouched.

---

## 9. Red-first + counts (item 9) — REPRODUCED

| Run | Result |
|---|---|
| `ShiftCashVarianceQueueRetryTest` at `a096f3551` | **OK (9 tests, 63 assertions)** — matches the claimed 9/63 exactly |
| Same test with `git checkout fa807a699 -- <the 2 sources>` | **Tests: 9, Assertions: 4, Errors: 5, Failures: 3** → **8 red**, matches the claim. Sources restored to `a096f3551`; `git status --porcelain` empty. |
| `tests/Unit/Config/HorizonQueueCoverageTest.php` | green |
| `tests/Architecture/QueueJobTenantContextTest.php` | **RED** — but only on `Product\Application\Jobs\SendEnrichmentFeedbackJob` and `SendBrandMappingJob`. The guard scans `app/Modules/*/Jobs\|Application/Jobs\|Infrastructure/Jobs` (per its own failure message); this diff touches no `Jobs/` directory. **Genuinely pre-existing.** |
| `tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php` | OK (5 tests, 23 assertions) |
| `tests/Feature/Treasury/ShiftCashVarianceOfflineDevicePayloadTest.php` | OK (3 tests, 19 assertions) |
| `tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php` | **OK (23 tests, 98 assertions)** at HEAD |
| Pint `--test` on all 3 changed files | `{"result":"pass"}` |
| PHPStan (level 8) on all 3 changed files | `[OK] No errors` |

**Discrepancy:** the claimed inherited red for `ShiftCashVarianceAdjustmentTest` ("PG trigger red at base") does **not** reproduce — it is fully green at HEAD under `phpunit.xml`'s sqlite config (`DB_CONNECTION=sqlite`, `:memory:`, `phpunit.xml:44-45`). Either the claim refers to a PG-backed run outside this config, or it is stale. Recorded as **unverified**, not as a defect.

---

## Findings

### P2-1 — Enqueueing can now 500 an already-committed, fiscally-sealed Z-report close; the docblocks claim the opposite

**Evidence.**
- `PostShiftCashVarianceAdjustment.php:145-151` claims "a fault must retry on a worker, never surface as a spurious 500", and `TreasuryServiceProvider.php:177-180` repeats it.
- But the queue **push itself** is unguarded: `Dispatcher::dispatch():270-299` → `invokeListeners()` has no `try/catch`; `createQueuedHandlerCallable():581-594` → `queueHandler():653-680` → `$connection->pushOn(enum_value($queue), $job)`. A Redis outage, a `RedisException`, or a serialization fault propagates straight out of `event()`.
- On the **live** path the `event()` call sits in a `DB::afterCommit` callback (`ReportGenerationService.php:258, 415`). `DatabaseTransactionsManager::commit():67-97` runs `->map->executeCallbacks()` at `:94` with **no** `try/catch`, inside `Connection::commit()`, inside `Connection::transaction()`'s commit `try` — whose handler rethrows anything that is not a concurrency error.
- `ReportController::generateZReport()` (`:117-200`) catches only `ShiftNotOpenException`, `CashCountValidationException`, `UnauthorizedManagerException`, `ServerFiscalAuthoringRetiredException`. A `RedisException` is none of those → **HTTP 500 on a Z report that is already committed and hash-chained.**
- At base this was structurally impossible: the listener ran in-process and swallowed everything.

**Why it matters.** The invariant this entire file is organised around now depends on Redis being reachable at close-of-day on an offline-first POS. The POS client sees a failed close on a sealed fiscal document.

**Mitigation today.** `treasury.shift_variance_gl_enabled` is FALSE (G-5), and `shouldQueue()` (`:265-268`) short-circuits *before* the push, so nothing is enqueued and the hazard is latent, not live. This is why it is P2 and not P1.

**Prescribed fix (pick one).**
(a) Keep the listener synchronous and have `handle()` `dispatch()` a dedicated `ShouldQueue` job inside its own `try/catch (Throwable)` that falls back to `deadLetter()` when the push fails — this restores the "never blocks the close" invariant *including* the enqueue leg; or
(b) at minimum, correct the two docblocks to state the residual honestly **and** add "queue reachability at Z-close, and the behaviour of a push failure" as an explicit, tested item on the G-5 pre-enable checklist (`docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md`).

### P2-2 — Flag-off-at-execution drops the variance with **no** record (new silent-loss channel)

**Evidence.** `shouldQueue():265-268` is evaluated in the **web** process; the belt at `handle():274-276` is evaluated in the **worker** process. Both read `config('treasury.shift_variance_gl_enabled')` ← `env('TREASURY_SHIFT_VARIANCE_GL_ENABLED')` (`config/treasury.php:28`), a **per-process** environment variable. If the API container has it enabled and the Horizon container does not — or the flag is flipped off between enqueue and execution — `handle()` returns at `:276` with **no booking, no `…_skipped` row, no dead letter, no log line**.

**Why it matters.** That is byte-for-byte the failure mode the lane cites as its own reason to exist (`:113-115`, "a missing GL leg went unnoticed for months"), re-created by splitting one check across two processes. At base a single check in a single process could not skew.

**Prescribed fix.** In `handle()`, distinguish "never enqueued" from "enqueued then found disabled":

```php
if (config('treasury.shift_variance_gl_enabled') !== true) {
    if ($this->job !== null) {
        // A job only exists because shouldQueue() saw the flag ENABLED at
        // dispatch. Reaching here means the worker's config disagrees.
        $this->refuse($event, 'feature_disabled_after_enqueue', [], level: 'error');
    }
    return;
}
```

### P3-1 — `tries` in the dead-letter payload is the *budget*, not the attempts made

`:428` writes `'tries' => $this->tries` (always `3`), and `:356` logs the same. On the connection-cannot-retry path (`:353-357` → `deadLetter()`) exactly **one** attempt was made. `ShiftCashVarianceQueueRetryTest.php:349` pins `3` for a `failed()` call that never touched a worker. An operator reading a dead letter will conclude three attempts were made when, on the sync path, none were retried.
**Fix:** add `'attempts' => $this->attempts()` and `'retryable_connection' => $this->connectionCanRetry()` alongside (or instead of) the budget, and assert them in the sync test at `:413-439`.

### P3-2 — `HorizonQueueCoverageTest` does not cover the `public string $queue` declaration form

`tests/Unit/Config/HorizonQueueCoverageTest.php:47` scans **only** `onQueue('…')` literals. This lane declares its queue as a property (`PostShiftCashVarianceAdjustment.php:240`), so it is invisible to the systemic guard; only the lane's own inline check (`ShiftCashVarianceQueueRetryTest.php:163-179`) covers it. `default` happens to be listed (`config/horizon.php:209`), so nothing is broken today — but the next `public string $queue = '<new-queue>'` will reproduce the 2026-06-12 `fiscal-projections` incident the guard exists to prevent.
**Fix:** extend the regex to also match `public\s+(?:string\s+)?\$queue\s*=\s*['\"]([^'\"]+)['\"]`.

### P3-3 — `QueueJobTenantContextTest` cannot see queued **listeners**

Per its own failure output it scans `app/Modules/*/Jobs|Application/Jobs|Infrastructure/Jobs` only. This is the first `ShouldQueue` **listener** on the Treasury/GL path, so it is un-policed by the tenant-context guard. Tenancy is in fact carried correctly here (§3), but the guard should be widened so the next such listener is not left to a human reviewer.
**Fix:** extend the scan to `Application/Listeners` for classes implementing `ShouldQueue`.

### P3-4 — Comment drift in `TreasuryServiceProvider.php:183-186`

"The gate is checked inside `handle()` rather than around this registration so the flag stays runtime-evaluable" is now only half true — the lane also added a dispatch-side `shouldQueue()`. Update the sentence to describe both checks.

### P3-5 — Test docblock overstates what is asserted

`ShiftCashVarianceQueueRetryTest.php:200-204` claims the queued test covers "the event's serialization shape". It does not: `Queue::fake()` records the job object in memory without serializing, and `:216` calls `$job->handle(app())` directly. Serialization is exercised only by `test_a_connection_that_cannot_retry_dead_letters_instead_of_throwing` (`:413-439`) via the real `sync` connection.
**Fix:** move the claim to that test, or round-trip `serialize()/unserialize()` on the job in `test_the_queued_listener_books_the_variance_with_no_company_context_bound`.

### P3-6 — Two accepted trade-offs that belong on the G-5 flip checklist, not in a reviewer's head

1. **Durability window.** `:249` deliberately asserts `refusalCount() === 0` after a failed attempt — correct (a retry is pending), but it means that for the retry window the *only* record of the variance lives in Redis. If the job is lost (eviction, `horizon:clear`, a redis restart without persistence), the variance vanishes with no row anywhere. At base the refusal row was written inline and durably. `failed_jobs` covers exhaustion, not job loss.
2. **`database`-driver fallback.** `config/queue.php:16` defaults to `database` when `QUEUE_CONNECTION` is unset; Horizon consumes redis only (`config/horizon.php:202`). An environment that forgets the var would enqueue into the `jobs` table (`0001_01_01_000002_create_jobs_table.php`) and never consume it — silent, permanent loss.

Both should be written into `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md` as pre-enable conditions.

### P3-7 — The dead letter is currently write-only

`grep` for `shift_variance_gl_dead_lettered` / `shift_variance_gl_skipped` / `shift_variance_gl_booked` across `apps/api`, `apps/web` and `packages` returns **only** the listener and its tests. The docblock at `:200-206` says the new event type "must be alertable on its own"; nothing alerts on it, and nothing alerts on the pre-existing skipped event either. Not a regression (base is equally unwired), but the R-8 story is not actually closed operationally until a surface consumes it.

---

## What is genuinely good (recorded so a re-gate does not re-litigate it)

- Producer census is correct and the double-queue dismissal is sound.
- The `newInstanceWithoutConstructor()` → property-default → `propagateListenerOptions` chain works, and array `backoff` is correctly handled end to end; the test asserts the propagated values on the **job**, not just the declared values on the listener (`:158-159`) — which is the only copy the worker reads.
- Tenancy on the worker, including inside `failed()`, is correct and proven from vendor ordering rather than asserted.
- Rule 19 is clean on the whole worker chain, and the dead-letter payload is all `numeric-string`.
- The retry-safety argument is real, not asserted: single transaction, UUIDv5 + `firstOrCreate`, entry reuse, two partial unique indexes, and the `wasIdempotentHit` short-circuit — with a **committed-then-retried** test, which is the case that actually matters.
- The imbalance is produced with production machinery (a real `JournalLine::created` hook into the real chokepoint), not a mock of the thing under test. No `assertTrue(true)`. `RefreshDatabase` + real models throughout.
- Red-first is genuine and reproduced exactly (8 red at base, 9/63 green at HEAD), and the worktree is clean.

---

## What to fix before merge

Correct the "never a 500 on the close" claim — either guard the enqueue leg (P2-1) or downgrade the claim and put it on the G-5 flip checklist — and make a flag-off-after-enqueue produce a record instead of silence (P2-2); the P3s can ride along.

VERDICT: CHANGES-REQUIRED
