# Codex Review - Phase 1 Task 23 (`34f1b6113`)

**Verdict: REQUEST-CHANGES**

Task 23 is not safe to merge as-is. The job has the requested short `lockForUpdate()` transaction shape, and the OutboxIngestor now dispatches real jobs after commit, but two load-bearing lifecycle contracts are still broken. First, a second worker that sees the same projection row in `running` will proceed into `apply()` again; there is no queue overlap/unique lock to distinguish "currently running" from "crashed and needs recovery". Second, the Treasury bridge still returns cleanly when POS-core has not created `pos_receipts`, and Task 23 marks any clean return as `applied`; dispatch priority alone does not guarantee execution order under multiple queue workers.

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| BLOCKER | T23-B1 | `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:186` | `running` rows are treated the same as `pending`, so duplicate delivery while the first worker is still applying can invoke the projector twice. | Add a per-projection queue overlap/unique lock with expiry, or a durable running lease/claim token, so concurrent delivery of the same row cannot enter `apply()` while the first attempt is in flight; keep crash recovery explicit. |
| BLOCKER | T23-B2 | `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:206`, `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:276` | Treasury can run before POS-core, return cleanly because `pos_receipts` is missing, and then be marked `applied` with no Payment/GL effects. | Make missing POS receipt a retryable projection exception, or enforce projection dependencies so bridge jobs cannot run until lower-priority rows are `applied`. Add a queue-order regression. |
| P1 | T23-P1-1 | `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:240`, `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1756` | The plan's real POS-core partial-cluster assertion was replaced with an in-memory fake `ApplyLog`, so the test no longer proves POS business effects survive Treasury dead-letter. | Use the real `PosCoreReceiptProjection` in the partial-cluster test and assert `DB::table('pos_receipts')->count() === 1`. |
| P3 | T23-P3-1 | `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:305`, `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:207` | `failed()` re-invocation idempotency is documented but not tested. | Add a small test that calls `failed()` twice and asserts the first `dead_lettered_at` survives. |
| P3 | T23-P3-2 | `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:237`, `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:250` | Missing `FiscalEvent` and deregistered-projector hard-misconfiguration paths are implemented but untested. | Add focused tests for missing event and `byName()` returning null, asserting `Log::critical`, attempt accounting, throw, and eventual dead-letter behavior. |
| P3 | T23-P3-3 | `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:723`, `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:156` | Stale comments still say Task 23 is not implemented / the job does not exist. | Update comments to describe the current real dispatch behavior. |

---

## BLOCKER

### T23-B1 - A duplicate delivery of a `running` row can re-enter `apply()` concurrently

**File:line:** `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:105-110`, `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:163-199`, `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:276-294`

The job is a plain queued job with no `ShouldBeUnique`, `WithoutOverlapping`, `middleware()`, `uniqueId()`, or equivalent per-row queue lock:

```php
105 final class ApplyFiscalEventProjectionJob implements ShouldQueue
106 {
107     use Dispatchable;
108     use InteractsWithQueue;
109     use Queueable;
110     use SerializesModels;
```

The DB row lock itself is short, but the only statuses that short-circuit are terminal:

```php
163 $proceed = $db->transaction(function (): bool {
164     $row = FiscalEventProjectionRow::query()
165         ->lockForUpdate()
166         ->find($this->projectionRowId);
...
186     if ($row->projection_status === ProjectionStatus::Applied
187         || $row->projection_status === ProjectionStatus::DeadLettered
188     ) {
...
191         return false;
192     }
...
198     $row->projection_status = ProjectionStatus::Running;
199     $row->save();
```

After T_lock commits, `apply()` runs outside that row lock:

```php
276 try {
277     $projector->apply($event);
278 } catch (Throwable $e) {
...
284     $this->advanceFailureAccounting($row, $e);
285     throw $e;
286 }
...
292 $row->projection_status = ProjectionStatus::Applied;
293 $row->applied_at = Carbon::now('UTC');
294 $row->save();
```

Rule violated: the Task 23 lifecycle lock is supposed to serialize concurrent dispatch of the same projection row and prevent same-row re-application. This code only prevents re-application after a terminal status is visible. If worker A flips `pending -> running` and starts a long projector, worker B can acquire the short lock after A commits T_lock, see `running`, set `running` again, and invoke the same projector concurrently.

This is not just a test nuance. `running` is overloaded as both "currently in flight" and "crash-recovery retry candidate", and there is no lease/heartbeat/queue overlap lock to distinguish the two.

**Concrete fix:** add a queue-level overlap lock keyed by `projectionRowId` with an expiry compatible with `$timeout`, or add a durable lease/claim token column. A second delivery while the first worker is alive must not enter `apply()`; after a crash/timeout, the lock/lease must expire and allow recovery. Add a regression that one attempt pauses in `apply()`, a duplicate delivery arrives, and the duplicate does not invoke the projector before the first attempt reaches a terminal status.

### T23-B2 - Treasury can be cleanly marked `applied` before POS-core creates `pos_receipts`

**File:line:** `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:778-794`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:180-212`, `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:276-294`, `apps/api/tests/Feature/Fiscal/TreasuryReceiptBridgeTest.php:540-554`

Outbox dispatches one independent job per projection row. It preserves registry iteration order when enqueueing, but it does not create a dependency between jobs:

```php
778 // After-commit hook: enqueue one ApplyFiscalEventProjectionJob per
779 // pending row. The closure is `static` (no `$this` capture) so a
...
791 DB::afterCommit(static function () use ($rowsForDispatch): void {
792     foreach ($rowsForDispatch as $row) {
793         ApplyFiscalEventProjectionJob::dispatch($row['id']);
794     }
```

The bridge still treats a missing POS receipt as a clean no-op:

```php
180 // The Treasury bridge writes rows scoped to the receipt row that
181 // PosCoreReceiptProjection (Task 21) wrote for the same event.
...
206 if ($receipt === null) {
207     Log::warning('TreasuryReceiptBridge: pos_receipt not yet projected for fiscal event', [
208         'fiscal_event_id' => $event->id,
209     ]);
210 
211     return;
212 }
```

Task 23 treats a clean projector return as success:

```php
276 try {
277     $projector->apply($event);
...
292 $row->projection_status = ProjectionStatus::Applied;
293 $row->applied_at = Carbon::now('UTC');
294 $row->save();
```

The existing bridge test locks in the clean return:

```php
540 public function test_no_pos_receipt_for_event_is_a_deferred_bail_out_not_a_crash(): void
...
550 // Should NOT throw.
551 $this->app->make(TreasuryReceiptBridge::class)->apply($event);
...
554 $this->assertSame(0, DB::table('payments')->count());
```

Rule violated: the production contract requires POS-core effects before Treasury bridge effects. Priority-sorted enqueue order does not guarantee execution order with multiple Horizon workers. A Treasury worker can reserve and run its job before the POS worker commits `pos_receipts`; the bridge returns normally, and the Task 23 job marks `treasury_receipt_bridge` as `applied` with no Payment or GL rows.

**Concrete fix:** make the missing POS receipt branch throw a retryable dependency-missing exception so Task 23 records a failed attempt and Horizon retries. Alternatively, enforce projection dependencies in the dispatcher/job so a higher-priority bridge row is not dispatched or run until lower-priority rows for the same event are `applied`. Add a regression where both rows are pending and the Treasury job is run first; expected outcome is not `applied`.

## P1

### T23-P1-1 - Partial-cluster test no longer proves real POS-core effects survive Treasury dead-letter

**File:line:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:1756-1761`, `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:240-289`

The plan's contract was a real POS-core effect assertion:

```php
1756 public function test_pos_core_success_with_treasury_dead_letter_leaves_pos_core_intact(): void
...
1761     $this->assertSame(1, \DB::table('pos_receipts')->count()); // POS-core effects intact
```

The implementation substitutes fake projectors plus an in-memory log:

```php
240 public function test_pos_core_success_with_treasury_dead_letter_leaves_pos_core_intact(): void
...
248 $applyLog = new ApplyLog;
249 $this->registerFakeProjectors([
250     new ConfigurableFakeProjector(
251         name: 'pos_core_receipt',
...
270 $this->runProjection($event, 'pos_core_receipt'); // succeeds
271 $this->failProjectionToDeadLetter($event, 'treasury_receipt_bridge');
...
286 // POS-core side effect was NOT rolled back by the Treasury failure
...
289 $this->assertSame(['pos_core_receipt'], $applyLog->names());
```

Rule violated: this verifies job row transitions and fake invocation counts, not that the real `PosCoreReceiptProjection` writes survive a Treasury dead-letter. That loses load-bearing integration coverage at the exact POS/Treasury boundary Task 23 is supposed to protect.

**Concrete fix:** keep fake Treasury if needed, but run the real POS-core projector and assert the durable rows (`pos_receipts`, lines/payments if practical) remain after Treasury dead-letters.

## P2

No P2 findings.

## P3

### T23-P3-1 - `failed()` re-invocation idempotency is documented but not tested

**File:line:** `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:305-307`, `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:207-219`

The job claims idempotent re-invocation:

```php
305  * Idempotent — re-invocation flips an already-`dead_lettered` row
306  * back to `dead_lettered` (no-op state transition); `dead_lettered_at`
307  * stays at the first failure time.
```

The test calls `failed()` only once:

```php
207 public function test_exhausted_retries_dead_letter_via_failed_handler(): void
...
213 (new ApplyFiscalEventProjectionJob($projectionRow->id))
214     ->failed(new RuntimeException('boom'));
...
218 $this->assertSame('dead_lettered', $row->projection_status);
219 $this->assertNotNull($row->dead_lettered_at);
```

Classification: P3. This is operator-recovery idempotency, not the steady-state projection path.

**Concrete fix:** call `failed()` twice, capture the first `dead_lettered_at`, and assert it is unchanged.

### T23-P3-2 - Hard-misconfiguration paths are implemented but untested

**File:line:** `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:237-271`, `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php:168-355`

The missing-event and missing-projector branches are present:

```php
237 if ($event === null) {
238     $this->recordHardFailure(
239         $row,
240         'FiscalEvent row not found for fiscal_event_id='.$row->fiscal_event_id,
...
243     throw new RuntimeException(sprintf(
```

```php
250 $projector = $registry->byName($row->projector_name);
252 if ($projector === null) {
260     $this->recordHardFailure(
...
268     throw new RuntimeException(sprintf(
269         'ApplyFiscalEventProjectionJob: no projector named "%s" registered.',
```

The seven job tests cover success, projector throw, failed handler, fiscal-events immutability, fake partial cluster, applied short-circuit, and dead-lettered short-circuit, but not these hard-misconfiguration branches:

```php
168 public function test_successful_projection_marks_applied(): void
181 public function test_job_start_sets_running_then_failure_advances_attempts(): void
207 public function test_exhausted_retries_dead_letter_via_failed_handler(): void
222 public function test_projection_failure_never_mutates_the_fiscal_events_row(): void
240 public function test_pos_core_success_with_treasury_dead_letter_leaves_pos_core_intact(): void
292 public function test_already_applied_row_short_circuits_on_re_dispatch(): void
325 public function test_dead_lettered_row_short_circuits_on_re_dispatch(): void
```

Classification: P3. These are hard-misconfiguration/operator paths. The current code is not a silent no-op, but the fail-closed behavior should be pinned before more replay tooling lands.

**Concrete fix:** add tests for a projection row referencing a missing event and a row whose `projector_name` is not registered; assert `Log::critical`, attempt accounting, throw, and dead-letter behavior through `failed()`.

### T23-P3-3 - Stale Task 23 comments survived the dispatch wiring

**File:line:** `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:723-725`, `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:156-159`

OutboxIngestor still says the job is not implemented:

```php
723  * After T1 commits, dispatch one `ApplyFiscalEventProjectionJob`
724  * (Task 23 — not yet implemented) per pending row, via
725  * `DB::afterCommit()` so an aborted T1 produces no spurious jobs.
```

The test setup still says the job class does not exist:

```php
156 // Avoid Job dispatch in tests — projection enqueue is Task 23's
157 // job class which doesn't exist yet. The OutboxIngestor passes a
158 // closure to `DB::afterCommit()`; Queue::fake() catches any
159 // future job dispatch.
```

Rule violated: stale comments reference the old no-op/TODO state and will mislead the next reviewer.

**Concrete fix:** update both comments to say the real job exists and `Queue::fake()` is used to assert dispatch without executing it.

## CLEAN

- **lockForUpdate exact primitive and short transaction shape:** the job uses the exact `lockForUpdate()` method in T_lock (`ApplyFiscalEventProjectionJob.php:163-166`) and calls `apply()` only after that transaction closure returns (`:202`, `:276-277`). The short-transaction part is clean; the `running` handling inside it is not, per T23-B1.

```php
163 $proceed = $db->transaction(function (): bool {
164     $row = FiscalEventProjectionRow::query()
165         ->lockForUpdate()
166         ->find($this->projectionRowId);
```

- **`byName()` does not re-gate on module activation:** it iterates the already-materialized projector list, compares `name()`, and returns `null` gracefully. There is no `requiresModule()` or `isActive()` call in `byName()`.

```php
194 public function byName(string $name): ?FiscalEventProjector
195 {
196     foreach ($this->projectors as $projector) {
197         if ($projector->name() === $name) {
198             return $projector;
199         }
200     }
202     return null;
203 }
```

- **Outbox dispatch is wired and rollback-safe:** the after-commit closure is `static` and dispatches real `ApplyFiscalEventProjectionJob` instances. The updated tests assert both a real dispatch and rollback suppression.

```php
791 DB::afterCommit(static function () use ($rowsForDispatch): void {
792     foreach ($rowsForDispatch as $row) {
793         ApplyFiscalEventProjectionJob::dispatch($row['id']);
794     }
795 });
```

```php
420 Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1);
...
430 Queue::assertPushed(
431     ApplyFiscalEventProjectionJob::class,
432     static fn (ApplyFiscalEventProjectionJob $job): bool => $job->projectionRowId === $projectionRowId,
```

```php
706 // No job was pushed — DB::afterCommit() respects the outer
707 // rollback (the closure never fires).
708 Queue::assertNothingPushed();
```

- **Lifecycle column mass-assignment discipline is clean:** `FiscalEventProjectionRow::$fillable` contains only identity fields, not lifecycle columns. The job mutates lifecycle columns through targeted assignment. OutboxIngestor uses `DB::table()->insert()` for seed rows, not Eloquent mass assignment.

```php
74 protected $fillable = [
75     'id',
76     'fiscal_event_id',
77     'projector_name',
78 ];
```

```php
292 $row->projection_status = ProjectionStatus::Applied;
293 $row->applied_at = Carbon::now('UTC');
294 $row->save();
```

- **Fail-closed on projector exceptions:** `handle()` catches projector throws, advances attempt accounting, and rethrows for Horizon retry.

```php
276 try {
277     $projector->apply($event);
278 } catch (Throwable $e) {
...
284     $this->advanceFailureAccounting($row, $e);
285     throw $e;
286 }
```

- **SerializesModels is harmless here:** the constructor stores only a UUID string, not an Eloquent model, so the trait has no model serialization work to do.

```php
107 use Dispatchable;
108 use InteractsWithQueue;
109 use Queueable;
110 use SerializesModels;
...
130 public function __construct(
131     public readonly string $projectionRowId,
132 ) {
```

I also verified a direct `unserialize(serialize($job))` round trip preserved `projectionRowId`.

- **CI PG merge-gate filter is clean for Task 23 files inspected:** the PG filter still includes `OutboxIngestorTest`, `PosCoreReceiptProjectionTest`, `TreasuryReceiptBridgeTest`, and `PaymentOriginWriterInventoryTest`. It does not include `ApplyFiscalEventProjectionJobTest` yet, which is a follow-up hardening gap but not a blocker for the checked line.

```yaml
390 run: |
391   php artisan test \
392     --filter="VoucherLedgerTest|VoucherLedgerAppendOnlyTest|VoucherSchemaTest|FiscalHardeningE2ETest|FiscalEventsTableTest|FiscalEventsImmutabilityTest|FiscalEventProjectionsTableTest|FiscalEventQuarantineTableTest|PosReceiptsCanonicalBytesTest|PaymentsOriginColumnsTest|OutboxIngestorTest|FiscalEventIngestionEndpointTest|PosCoreReceiptProjectionTest|TreasuryReceiptBridgeTest|PaymentOriginWriterInventoryTest"
```

## Test Results Summary

Targeted command:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/apps/api && ./vendor/bin/phpunit --filter 'ApplyFiscalEventProjectionJobTest|OutboxIngestorTest|FiscalEventProjectionRegistryTest' --testdox 2>&1 | tail -60
```

Result: PASS with issues reported by PHPUnit deprecations.

```text
Tests: 43, Assertions: 155, PHPUnit Deprecations: 401.
```

Fiscal feature command:

```bash
./vendor/bin/phpunit tests/Feature/Fiscal/ --testdox 2>&1 | tail -80
```

Result: PASS with skips.

```text
Tests: 176, Assertions: 493, Skipped: 37.
```

No PHPUnit failures occurred, so there is no separate Test Failures section.

## PHPStan Result

Requested command:

```bash
./vendor/bin/phpstan analyse app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php app/Modules/Fiscal/Application/Services/OutboxIngestor.php app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php --level=8 2>&1 | tail -40
```

Result: runner failed before analysis in this sandbox:

```text
Failed to listen on "tcp://127.0.0.1:0": Operation not permitted (EPERM)
```

I then ran the same analysis with `--debug` to avoid the TCP worker server:

```bash
./vendor/bin/phpstan analyse app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php app/Modules/Fiscal/Application/Services/OutboxIngestor.php app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php --level=8 --debug 2>&1 | tail -40
```

Result:

```text
[OK] No errors
```

## Pint Result

Command:

```bash
./vendor/bin/pint --test app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php app/Modules/Fiscal/Application/Services/OutboxIngestor.php app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php 2>&1
```

Result:

```json
{"result":"pass"}
```

## Overall Recommendation

REQUEST-CHANGES. Fix T23-B1 and T23-B2 before merge. T23-B1 is a same-row duplicate execution race; T23-B2 can silently mark Treasury projection applied without Treasury effects. The P1 test substitution should be corrected in the same patch so the real POS/Treasury boundary stays covered.

---

## Round-2 re-review (commit 6c03bac5e)

**Captured by the controller** — Codex ran with a read-only sandbox and could not append directly; verbatim verdict + finding summary transcribed below.

**Verdict: REQUEST-CHANGES** — 2 new BLOCKERs introduced by the round-2 defenses.

Round-2 closed every round-1 finding (T23-B1, T23-B2, T23-P1-1, T23-P3-1/2/3) cleanly, but introduced **2 new BLOCKERs** in the new defenses themselves.

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| BLOCKER | T23-R2-B1 | `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php` (`isRecentlyAttempted()` + T_lock short-circuit branch) | After a projector throws and Horizon retries, `advanceFailureAccounting()` has set `last_attempted_at = now()`, so the next retry's T_lock sees status=`Running` + recently-attempted → the new belt-and-braces stale-running guard short-circuits silently → retry never executes. This kills the T23-B2 Treasury retry path entirely (the bridge throws once, never re-runs, projection stays `Running` forever). The `test_treasury_first_then_pos_core_resolves_via_retry_contract` test passes only because `runJobInline` is invoked sequentially without `Carbon::setTestNow` advancing time between attempts — Horizon's actual delay-then-retry would trip the guard. | Either (a) drop the `isRecentlyAttempted()` short-circuit and trust `WithoutOverlapping` alone (with T23-R2-B2 fix); or (b) introduce a separate lease column (`lease_expires_at` set on Running entry, cleared on terminal status OR on failure) so "in flight" is distinguishable from "recently failed and retrying"; or (c) reset `projection_status` back to `Pending` in `advanceFailureAccounting()` so the next retry's T_lock sees `Pending` and proceeds (then the stale-running guard never trips on the legitimate retry path). Add a regression test that uses `Carbon::setTestNow` between failure and retry to pin Horizon-style timing. |
| BLOCKER | T23-R2-B2 | `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php::middleware()` | `WithoutOverlapping($id)->expireAfter($this->timeout ?? 300)->dontRelease()` + Redis default `retry_after = 90s` (the standard Laravel queue config). If worker A acquires the lock at t=0 with TTL=120s, then crashes at t=80s without releasing, Redis re-delivers at t=90s. Worker B's middleware tries to acquire the lock — still held by A's stale entry until t=120s → `dontRelease()` drops the delivery permanently. After t=120s the lock expires but Redis has already moved on. Job is permanently lost (no further deliveries, no operator alert, no dead-letter). | (a) Configure `WithoutOverlapping::expireAfter()` to be STRICTLY LESS THAN the queue's `retry_after` value so the lock expires before re-delivery; OR (b) replace `dontRelease()` with `releaseAfter($timeout + buffer)` so the duplicate delivery is re-queued with a delay rather than dropped; OR (c) document a runbook requirement that `retry_after > expireAfter + buffer` in the project's `config/horizon.php` + `config/queue.php`. Option (b) is the safest because it survives misconfiguration. Add a regression test mocking the cache-lock-already-held path and asserting the middleware re-queues rather than drops. |

**Test discipline lesson:** the cross-worker race test must use `Carbon::setTestNow` between attempts to simulate Horizon's actual delay; sequential `runJobInline` without time advance masks lifecycle-state guards that activate on time-based predicates. This is the same class as the Task 22 "deferred-bail-out test that asserts 'no crash' masking a silent-applied bug" lesson.

**Overall recommendation:** REQUEST-CHANGES. Round-3 must close both R2 BLOCKERs without re-introducing the round-1 race conditions. The fix surface is small (one method body in the job + one middleware config call), but the test surface needs to grow to pin Horizon-style retry timing + cache-lock-held-by-crashed-worker semantics.

---

## Round-4 re-review (commit 8fed9d87f)

**Captured by the controller** — Codex sandbox could not append directly; verbatim verdict + findings transcribed below.

**Verdict: APPROVE — 0 new findings.**

### Closure table

| Item | Status | Evidence |
|---|---|---|
| R3-P1-1: T_lock `last_attempted_at` stamp | CLOSED | `ApplyFiscalEventProjectionJob.php:311-313` — `$row->projection_status = ProjectionStatus::Running;` / `$row->last_attempted_at = Carbon::now('UTC');` / `$row->save();` |
| R3-F1: `isRecentlyAttempted()` docblock race window | CLOSED | Same file, lines 590–602 — documents the SIGKILL window up to ~120s, double-bounded by `WithoutOverlapping::expireAfter($timeout)`. |
| R3-F2: `Carbon::setTestNow` try/finally | CLOSED | `ApplyFiscalEventProjectionJobTest.php:830-854` and `:984-999` — both reset via `finally { Carbon::setTestNow(); }`. |

### Defect-surface sweep results (all clean)

- No test asserts `last_attempted_at` is null after T_lock.
- The new regression test pins lifecycle state directly (`assertNotNull($reentrant->observedLastAttemptedAtDuringReentry, ...)` at test line 947–949), not a "no crash" test. **Not a deferred-bail-out smell.**
- The ReentrantFakeProjector recursion guard increments BEFORE the early-return check, so a round-3 bug would surface as `invocationCount=2`. Correct placement.
- `$this->app->call([$duplicate, 'handle'])` intentionally bypasses `WithoutOverlapping` middleware to test the SECOND defense inside `handle()` — correct for the stated test goal (the test is about the in-flight guard, not the queue lock).
- Fail-closed and fail-requeue paths unchanged from round-3.

### Operator-visibility audit (clean)

No production Fiscal query uses `last_attempted_at IS NULL` as a "never attempted" sentinel. Production references confined to model casts, job writes, and the internal null-check branch inside `isRecentlyAttempted()`. No `whereNull` / `whereNotNull` / `orderBy` production usage found. The semantic shift introduced by the round-4 stamp (column now set on the FIRST attempt's T_lock, not only after a failure) does not break any downstream query.

### Test results

- `ApplyFiscalEventProjectionJobTest.php --testdox`: OK, 18 tests, 98 assertions.
- Full `tests/Feature/Fiscal/` suite: OK, 187 tests, 564 assertions, 37 skipped (existing skips, 0 regressions).
- PHPStan level 8 on the Job file: 0 errors.

### Overall recommendation

APPROVE. Round-4 closes R3-P1-1 + R3-F1 + R3-F2 cleanly with a narrow, well-tested 1-line stamp + 2 docblock updates + 2 try/finally wrappers. No new defect class introduced. Task 23 is now safe to ship pending push.
