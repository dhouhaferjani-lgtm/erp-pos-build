<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 23 — `ApplyFiscalEventProjectionJob` failure / retry / dead-letter
 * contract (spec v7 §7.5).
 *
 * The job is the Horizon-owned runner that takes a `pending`
 * `fiscal_event_projections` row, flips it through the lifecycle
 * `pending → running → applied|dead_lettered`, and surfaces operator-visible
 * state via the row columns (`projection_status`, `attempts`, `last_error`,
 * `last_attempted_at`, `applied_at`, `dead_lettered_at`).
 *
 * **Two-transaction shape (spec §7.5 / plan §1796):**
 *   - **T_lock** — short DB transaction that loads the row with
 *     `lockForUpdate()`, short-circuits on terminal states, flips
 *     `pending|running → running`, and commits. Releases the row lock so a
 *     sibling worker on a DIFFERENT row isn't blocked while this worker
 *     calls into the projector.
 *   - **T_apply** — the projector's own transaction (it owns its own
 *     boundary; the bridge wraps Payment + GL + allocation in a single
 *     transaction internally). Runs OUTSIDE T_lock so the lock duration is
 *     bounded.
 *   - Terminal-status write (`applied` / `dead_lettered`) and attempt
 *     accounting (`attempts++`, `last_error`, `last_attempted_at`) happen
 *     OUTSIDE T_apply so the rollback of a projector throw doesn't lose
 *     attempt accounting.
 *
 * **Lifecycle short-circuit (Task 22 cross-task implication).** A re-delivery
 * (Horizon double-dispatch, crash recovery on a finished row, accidental
 * manual dispatch) of an already-`applied` or already-`dead_lettered` row
 * must be a NO-OP. The row-level `lockForUpdate()` is the second of two
 * defense layers atop Task 22's projector-level `pg_advisory_xact_lock` —
 * neither alone is sufficient.
 *
 * **Fail-closed contract.** A projector throw inside `handle()` advances
 * `attempts` / `last_error` / `last_attempted_at` and re-throws so Horizon
 * retries with backoff. Only after Horizon exhausts retries does
 * `failed(Throwable $e)` flip the row to `dead_lettered` — operators
 * resolve dead-lettered rows through a separate command, never a re-handle.
 *
 * **No FiscalEvent mutation.** A projection failure (transient or terminal)
 * must NEVER mutate the `fiscal_events` chain-truth row. The Task 8
 * immutability triggers enforce this at the DB layer; this test asserts the
 * application layer respects the contract too.
 *
 * **PG-only behaviors not asserted here.** `lockForUpdate()` semantics differ
 * between SQLite (database-level serialization) and PG (row-level locks).
 * Per the plan §1796 amendment + the implementer's brief option (b): the
 * row-level T_lock tests assert lifecycle semantics in a single-worker
 * scenario only and trust the PG driver to honor `lockForUpdate()` at
 * runtime. The Codex T23-B1 round-2 concurrent-delivery tests exercise
 * the queue-level `WithoutOverlapping` middleware directly against the
 * `array` cache driver — that path is portable.
 *
 * **Test matrix (round-1 + round-2).** The 7 round-1 lifecycle tests
 * (happy / attempt-accounting / dead-letter / chain-immutability /
 * partial cluster / 2 short-circuits) plus the 8 round-2 additions
 * (T23-B1 middleware presence + duplicate-delivery drop + 2 stale-running
 * variants; T23-B2 cross-worker race; T23-P3-1 failed-handler idempotency;
 * T23-P3-2 missing-FiscalEvent + missing-projector) cover the full
 * discriminated-union enumeration of `handle()` / `failed()` paths.
 */
final class ApplyFiscalEventProjectionJobTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $paymentMethodId;

    private string $repositoryId;

    /**
     * Test-local projector storage keyed by `name()` so each test can
     * inject a fake (always-succeeds, always-throws, etc.) and re-resolve
     * the registry from the container with the swapped set.
     *
     * @var array<string, FiscalEventProjector>
     */
    private array $projectorsByName = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        // operator_id is FK-constrained on pos_receipts.cashier_id when the
        // POS-core projector runs; create a real user. For pure
        // fake-projector tests this is unused, but the cross-projector
        // partial-cluster test needs it.
        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        $this->paymentMethodId = $method->id;

        // Seed chart of accounts so the real PosCoreReceiptProjection (used
        // by the partial-cluster test via F1 round-2 closure) can resolve
        // GL accounts when the projector's voucher / GL-posting paths
        // wake. The seed is harmless for the pure-fake-projector tests.
        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);

        // Payment repository with a real GL account linkage — required if
        // the real PosCoreReceiptProjection touches voucher redemption /
        // GL posting paths. Test fixtures here keep payloads to plain
        // cash so the projector doesn't ask for it, but the F1 round-2
        // partial-cluster test needs the repository in place anyway for
        // PaymentRepository::factory()-driven seeders that mirror the
        // PosCoreReceiptProjectionTest baseline.
        $cashAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Cash);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);
        $this->repositoryId = $repository->id;

        // Default to a registry that resolves an always-succeeds POS-core
        // fake + a configurable Treasury bridge fake. Each test that needs
        // different fakes calls registerFakeProjectors() with the desired
        // set.
        $this->registerFakeProjectors([
            new ConfigurableFakeProjector(
                name: 'pos_core_receipt',
                requiresModule: null,
                priority: 50,
                shouldThrow: false,
            ),
            new ConfigurableFakeProjector(
                name: 'treasury_receipt_bridge',
                requiresModule: 'Treasury',
                priority: 150,
                shouldThrow: false,
            ),
        ]);
    }

    // =================================================================
    // Plan §1716 — seven discriminated-union lifecycle tests
    // =================================================================

    public function test_successful_projection_marks_applied(): void
    {
        [$event, $projectionRow] = $this->pendingProjection('pos_core_receipt');

        $this->runJobInline($projectionRow->id);

        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('applied', $row->projection_status);
        $this->assertNotNull($row->applied_at);
        $this->assertNull($row->dead_lettered_at);
    }

    public function test_job_start_sets_running_then_failure_advances_attempts(): void
    {
        [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');
        $this->forceProjectorToThrow('treasury_receipt_bridge');

        try {
            $this->runJobInline($projectionRow->id);
            $this->fail('Expected projector throw to bubble out of handle() for Horizon retry.');
        } catch (\Throwable $e) {
            // Expected — the job re-throws so Horizon advances retry/backoff.
            $this->assertStringContainsString('configurable fake projector failure', $e->getMessage());
        }

        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->last_error);
        $this->assertStringContainsString('configurable fake projector failure', $row->last_error);
        $this->assertNotNull($row->last_attempted_at);
        // Task 23 round-3 (Codex T23-R2-B1): status RESET to `pending`
        // after the failure so the next Horizon retry's T_lock proceeds
        // normally without tripping the stale-running guard. Operator
        // visibility for the in-flight retry comes from `attempts > 0` +
        // `last_error IS NOT NULL` (asserted above), NOT from the status
        // column. Pre-round-3 this assertion was `running` — but that
        // pinned the bug, not the contract.
        $this->assertSame('pending', $row->projection_status);
        $this->assertNull($row->applied_at);
        $this->assertNull($row->dead_lettered_at);
    }

    public function test_exhausted_retries_dead_letter_via_failed_handler(): void
    {
        [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');

        // Horizon calls `failed()` only after exhausting `$tries`; we
        // invoke it directly to assert the dead-letter terminal write.
        (new ApplyFiscalEventProjectionJob($projectionRow->id))
            ->failed(new RuntimeException('boom'));

        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('dead_lettered', $row->projection_status);
        $this->assertNotNull($row->dead_lettered_at);
    }

    public function test_projection_failure_never_mutates_the_fiscal_events_row(): void
    {
        [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');
        $before = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertNotNull($before);

        $this->forceProjectorToThrow('treasury_receipt_bridge');

        try {
            $this->runJobInline($projectionRow->id);
        } catch (\Throwable) {
            // expected
        }

        $after = DB::table('fiscal_events')->where('id', $event->id)->first();
        $this->assertEquals($before, $after, 'fiscal_events chain-truth row must be byte-identical after projection failure');
    }

    public function test_pos_core_success_with_treasury_dead_letter_leaves_pos_core_intact(): void
    {
        // Cross-projector partial-cluster invariant (plan §1756): one
        // projector succeeds, another dead-letters; the successful
        // projector's effects survive untouched.
        //
        // Task 23 round-2 (Codex T23-P1-1 / Opus F1 — convergent P1)
        // closure: round-1 substituted a `ConfigurableFakeProjector` +
        // in-memory `ApplyLog` for the plan §1761 literal
        // `DB::table('pos_receipts')->count() === 1` assertion. That
        // proved the projector's `apply()` was called once, but lost the
        // cross-projector projection-atomicity invariant — that POS-core's
        // already-committed business writes are NOT rolled back by a
        // sibling projector's failure (spec §7.5 line 454: each
        // projection job runs in its own transaction). Round-2 swaps in
        // the REAL `PosCoreReceiptProjection` so the assertion lands on
        // the actual `pos_receipts` row. The Treasury side stays fake —
        // the test's invariant is about POS-core's effects SURVIVING
        // Treasury's dead-letter, so a configurable always-throws fake
        // on the Treasury slot is the simplest way to force the
        // dead-letter side of the cluster.
        $realPosCore = $this->app->make(PosCoreReceiptProjection::class);
        $treasuryFake = new ConfigurableFakeProjector(
            name: 'treasury_receipt_bridge',
            requiresModule: 'Treasury',
            priority: 150,
            shouldThrow: true,
        );
        $this->registerProjectors([$realPosCore, $treasuryFake]);

        $event = $this->storeSaleReceiptFiscalEvent();
        $posRow = $this->seedPendingProjectionRow($event, 'pos_core_receipt');
        $treasuryRow = $this->seedPendingProjectionRow($event, 'treasury_receipt_bridge');

        $this->runProjection($event, 'pos_core_receipt'); // succeeds
        $this->failProjectionToDeadLetter($event, 'treasury_receipt_bridge');

        // Plan §1761 literal — the real POS-core projector wrote exactly
        // one `pos_receipts` row scoped to this fiscal event.
        $this->assertSame(
            1,
            DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->count(),
        );

        // Sanity check on POS-core line writes — the receipt row carries
        // children (lines, payments). If a future refactor moves the
        // child writes out of the bridge's transaction, this assertion
        // catches the silent drop.
        $receiptId = DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->value('id');
        $this->assertNotNull($receiptId);
        $this->assertGreaterThan(
            0,
            DB::table('pos_receipt_lines')->where('receipt_id', $receiptId)->count(),
        );

        // POS-core row is `applied`.
        $posAfter = DB::table('fiscal_event_projections')->where('id', $posRow->id)->first();
        $this->assertNotNull($posAfter);
        $this->assertSame('applied', $posAfter->projection_status);

        // Treasury bridge dead-lettered; its `apply()` was never invoked
        // by the `failed()` path (only `handle()` invokes the projector).
        $treasuryAfter = DB::table('fiscal_event_projections')->where('id', $treasuryRow->id)->first();
        $this->assertNotNull($treasuryAfter);
        $this->assertSame('dead_lettered', $treasuryAfter->projection_status);

        // Zero Treasury Payment rows — the dead-lettered Treasury job
        // never wrote anything. (Belt-and-braces — `failed()` doesn't
        // invoke `apply()`, but a future bug that swapped them would
        // be caught here.)
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_already_applied_row_short_circuits_on_re_dispatch(): void
    {
        // Task 22 cross-task implication: the row-level lifecycle lock +
        // idempotent short-circuit prevents a re-delivery (Horizon
        // double-dispatch or crash-recovery on a finished row) from
        // re-running the projector.
        $applyLog = new ApplyLog;
        $this->registerFakeProjectors([
            new ConfigurableFakeProjector(
                name: 'pos_core_receipt',
                requiresModule: null,
                priority: 50,
                shouldThrow: false,
                applyLog: $applyLog,
            ),
        ]);

        [$event, $projectionRow] = $this->pendingProjection('pos_core_receipt');

        $this->runJobInline($projectionRow->id);
        $this->runJobInline($projectionRow->id); // re-delivery

        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('applied', $row->projection_status);
        // Success path never increments attempts; re-delivery is a no-op
        // and does not touch attempts either.
        $this->assertSame(0, (int) $row->attempts);
        // The projector was invoked exactly once — the second handle()
        // short-circuited before reaching `apply()`.
        $this->assertSame(1, $applyLog->countFor('pos_core_receipt'));
    }

    public function test_dead_lettered_row_short_circuits_on_re_dispatch(): void
    {
        // Dead-lettered terminal state is also short-circuited — operator
        // resolution moves through a separate command, never a re-handle().
        $applyLog = new ApplyLog;
        $this->registerFakeProjectors([
            new ConfigurableFakeProjector(
                name: 'treasury_receipt_bridge',
                requiresModule: 'Treasury',
                priority: 150,
                shouldThrow: true,
                applyLog: $applyLog,
            ),
        ]);

        [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');

        (new ApplyFiscalEventProjectionJob($projectionRow->id))
            ->failed(new RuntimeException('boom'));

        // Accidental re-dispatch after dead-letter — must short-circuit
        // and NOT re-invoke the projector.
        $this->runJobInline($projectionRow->id);

        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('dead_lettered', $row->projection_status);
        // `apply()` was never invoked on this projector across the entire
        // test — `failed()` never calls it, and the post-dead-letter
        // re-dispatch short-circuited before reaching it.
        $this->assertSame(0, $applyLog->countFor('treasury_receipt_bridge'));
    }

    // =================================================================
    // Task 23 round-2 — Codex T23-B1 (concurrent delivery defense)
    // =================================================================

    public function test_middleware_includes_without_overlapping_keyed_by_projection_row_id(): void
    {
        // Codex T23-B1 BLOCKER closure (layer 1 — queue-level overlap lock).
        // The job exposes a `middleware()` method that returns a
        // `WithoutOverlapping` instance keyed on the projection row id,
        // with `expireAfter` matching the job's `$timeout` (crash-recovery
        // lock release) and `releaseAfter($timeout + 30)` (Task 23 round-3
        // closure for Codex T23-R2-B2 — replaces round-2's `dontRelease()`
        // so a duplicate delivery hitting a stale-held lock is re-queued
        // with delay rather than permanently dropped).
        $projectionRowId = (string) Str::uuid();
        $job = new ApplyFiscalEventProjectionJob($projectionRowId);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);

        $first = $this->withoutOverlappingMiddleware($job);
        $this->assertSame($projectionRowId, $first->key);
        // Task 23 round-3 (Codex T23-R2-B2): `releaseAfter($timeout + 30)`
        // — the +30s buffer ensures the re-queued delivery returns AFTER
        // the worst-case window where the original lock's `expireAfter`
        // has elapsed. With the round-2 `dontRelease()`, a duplicate that
        // hit a stale-held lock (crashed worker between Redis retry_after
        // and our expireAfter) was permanently dropped — no operator
        // alert, no dead-letter. The re-queue path survives that race.
        $this->assertSame($job->timeout + 30, $first->releaseAfter);
        // `expireAfter($timeout)` aligns the cache-lock lifetime with the
        // job's own timeout so a crashed worker's lock auto-releases for
        // recovery on the next retry.
        $this->assertSame($job->timeout, $first->expiresAfter);
    }

    public function test_without_overlapping_middleware_rejects_duplicate_delivery_while_first_in_flight(): void
    {
        // Codex T23-B1 regression — exercise the middleware twice in
        // sequence, asserting the cache lock holds across the second
        // invocation. The middleware's `handle()` invokes `$next($job)`
        // when the lock is acquired; otherwise (Task 23 round-3 fix for
        // Codex T23-R2-B2) it re-queues the duplicate via `release()`.
        // We pin BOTH: the second invocation MUST NOT invoke `$next` and
        // MUST call `release()` so the duplicate is re-queued rather than
        // silently lost (round-2's `dontRelease()` regression).
        //
        // Implementation note: the held lock is acquired against the
        // SPY's own lock key so the middleware sees an exact collision
        // when it tries to acquire the lock for `$job2`. (The real
        // job and the spy live in different class namespaces, so the
        // `getLockKey()` derivation produces different keys for each;
        // the assertion is about the middleware's release contract, not
        // about cross-class lock-key parity.)
        $projectionRowId = (string) Str::uuid();
        $referenceJob = new ApplyFiscalEventProjectionJob($projectionRowId);
        $spy = new ReleaseRecordingJobSpy($projectionRowId);
        $spy->timeout = $referenceJob->timeout; // mirror the real timeout so the delay assertion is meaningful

        $middleware = $this->withoutOverlappingMiddleware($referenceJob);

        // Acquire the lock manually for the duration of the assertion so
        // we don't have to fork to simulate two live workers. This is
        // exactly what `WithoutOverlapping::handle()` does internally
        // (`Cache::lock($key, $expiresAfter)->get()`).
        $spyLockKey = $middleware->getLockKey($spy);
        $lock = Cache::lock($spyLockKey, $referenceJob->timeout);
        $this->assertTrue($lock->get(), 'precondition: lock must be acquirable on first try');

        try {
            // While the lock is held by the "first worker", invoke the
            // middleware for the "second delivery". `$next` must not be
            // called — but unlike round-2's `dontRelease()`, `release()`
            // MUST be invoked with the configured delay (Task 23 round-3
            // T23-R2-B2 fix) so the duplicate survives the lock-held window.
            $nextWasCalled = false;
            $middleware->handle($spy, function () use (&$nextWasCalled): void {
                $nextWasCalled = true;
            });
            $this->assertFalse(
                $nextWasCalled,
                'WithoutOverlapping must not invoke $next while sibling worker holds the lock.',
            );
            $this->assertTrue(
                $spy->wasReleased,
                'Duplicate delivery must be re-queued via release(), not silently dropped (Task 23 round-3 T23-R2-B2).',
            );
            $this->assertSame(
                $referenceJob->timeout + 30,
                $spy->releaseDelay,
                'release() delay must equal timeout + 30s buffer so the re-queued delivery returns AFTER the original lock could plausibly have expired.',
            );
        } finally {
            $lock->release();
        }

        // After release, a fresh delivery proceeds normally — middleware
        // acquires the lock and invokes $next; release() is NOT called.
        $spy2 = new ReleaseRecordingJobSpy($projectionRowId);
        $spy2->timeout = $referenceJob->timeout;
        $nextWasCalledAfterRelease = false;
        $middleware->handle($spy2, function () use (&$nextWasCalledAfterRelease): void {
            $nextWasCalledAfterRelease = true;
        });
        $this->assertTrue(
            $nextWasCalledAfterRelease,
            'After lock release, the next delivery acquires the lock and proceeds.',
        );
        $this->assertFalse(
            $spy2->wasReleased,
            'When the lock is acquirable, the middleware must NOT call release() — the job proceeds normally.',
        );
    }

    public function test_without_overlapping_re_queues_duplicate_delivery_when_lock_already_held(): void
    {
        // Codex T23-R2-B2 BLOCKER regression. Round-2 used `dontRelease()`
        // which would silently DROP a duplicate delivery hitting a
        // stale-held lock. If worker A crashes between Redis's
        // `retry_after` (default 90s) and the lock's `expireAfter` (120s),
        // the duplicate is permanently lost — no operator alert, no
        // dead-letter, the projection is stuck in a phantom state.
        //
        // Round-3 fix: `releaseAfter($timeout + 30)`. When the lock is
        // already held, `WithoutOverlapping::handle()` calls
        // `$job->release($this->releaseAfter)` (vendor middleware line 82)
        // — the queued job is re-pushed onto its queue with the configured
        // delay. By the time the delayed delivery comes back, either the
        // original lock-holder has finished + released, or the lock has
        // expired and the re-delivery can acquire it cleanly.
        $projectionRowId = (string) Str::uuid();
        $referenceJob = new ApplyFiscalEventProjectionJob($projectionRowId);
        $duplicate = new ReleaseRecordingJobSpy($projectionRowId);
        $duplicate->timeout = $referenceJob->timeout;

        $middleware = $this->withoutOverlappingMiddleware($referenceJob);
        // Lock key derives from the spy's class — acquire against the
        // exact key the middleware will check for the duplicate.
        $duplicateLockKey = $middleware->getLockKey($duplicate);

        // Simulate worker A holding the lock (mid-apply or crashed).
        $lock = Cache::lock($duplicateLockKey, $referenceJob->timeout);
        $this->assertTrue($lock->get(), 'precondition: first lock acquisition must succeed');

        try {
            // Worker B receives the duplicate delivery. Middleware must
            // re-queue via release() — NOT silently drop.
            $middleware->handle($duplicate, function (): void {
                $this->fail('Middleware must NOT invoke $next when the lock is already held.');
            });

            $this->assertTrue(
                $duplicate->wasReleased,
                'Round-3 contract: duplicate delivery hitting a held lock must call release(), not silently drop (round-2 regression).',
            );
            $this->assertSame(
                $referenceJob->timeout + 30,
                $duplicate->releaseDelay,
                'Round-3 contract: release() delay must equal $timeout + 30s buffer.',
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Locate the `WithoutOverlapping` instance among the job's middleware
     * stack and return it strongly typed. PHPStan can't narrow a generic
     * `list<object>` return so we narrow here via `assertInstanceOf` then
     * the surrounding method's call sites have a concrete type to use.
     */
    private function withoutOverlappingMiddleware(
        ApplyFiscalEventProjectionJob $job,
    ): WithoutOverlapping {
        foreach ($job->middleware() as $entry) {
            if ($entry instanceof WithoutOverlapping) {
                return $entry;
            }
        }

        throw new RuntimeException('ApplyFiscalEventProjectionJob has no WithoutOverlapping middleware');
    }

    public function test_fresh_running_row_short_circuits_belt_and_braces(): void
    {
        // Codex T23-B1 — belt-and-braces defense (layer 2). The
        // `WithoutOverlapping` cache lock is the primary fence; this
        // test pins the stale-running age check that defends if the
        // cache lock fails open (e.g., a misconfigured cache driver
        // that doesn't share state across worker processes).
        //
        // Scenario: a sibling worker flipped the row to `running` and
        // is currently in `apply()` — `last_attempted_at` is fresh.
        // The arriving handle() must SHORT-CIRCUIT (return false from
        // T_lock) without invoking the projector.
        $applyLog = new ApplyLog;
        $this->registerFakeProjectors([
            new ConfigurableFakeProjector(
                name: 'pos_core_receipt',
                requiresModule: null,
                priority: 50,
                shouldThrow: false,
                applyLog: $applyLog,
            ),
        ]);

        [$event, $projectionRow] = $this->pendingProjection('pos_core_receipt');

        // Simulate "sibling worker mid-apply": flip to running + freshly
        // attempted (within the timeout window).
        $projectionRow->projection_status = ProjectionStatus::Running;
        $projectionRow->last_attempted_at = Carbon::now('UTC')->subSeconds(5);
        $projectionRow->save();

        $this->runJobInline($projectionRow->id);

        // The arriving handle() short-circuited — projector was NOT
        // invoked, row remains `running` with the original timestamp.
        $this->assertSame(0, $applyLog->countFor('pos_core_receipt'));
        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('running', $row->projection_status);
    }

    public function test_stale_running_row_is_recovered_on_re_dispatch(): void
    {
        // Codex T23-B1 — the crash-recovery side of the stale-running
        // age check. If `last_attempted_at` is older than `$timeout`
        // (or null — the row was flipped to `running` by an earlier
        // handle() that crashed BEFORE any apply() attempt advanced the
        // timestamp), treat as crash recovery and re-attempt. Pin the
        // contract so a future refactor of the age check doesn't break
        // recovery.
        $applyLog = new ApplyLog;
        $this->registerFakeProjectors([
            new ConfigurableFakeProjector(
                name: 'pos_core_receipt',
                requiresModule: null,
                priority: 50,
                shouldThrow: false,
                applyLog: $applyLog,
            ),
        ]);

        [$event, $projectionRow] = $this->pendingProjection('pos_core_receipt');

        // Simulate "sibling worker died mid-apply long ago": `running`
        // with `last_attempted_at` past the timeout window.
        $job = new ApplyFiscalEventProjectionJob($projectionRow->id);
        $projectionRow->projection_status = ProjectionStatus::Running;
        $projectionRow->last_attempted_at = Carbon::now('UTC')->subSeconds($job->timeout + 60);
        $projectionRow->save();

        $this->runJobInline($projectionRow->id);

        // Recovery: the projector ran, row is `applied`.
        $this->assertSame(1, $applyLog->countFor('pos_core_receipt'));
        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('applied', $row->projection_status);
    }

    // =================================================================
    // Task 23 round-2 — Codex T23-B2 (deferred-bail-out cross-worker race)
    // =================================================================

    public function test_treasury_first_then_pos_core_resolves_via_retry_contract(): void
    {
        // Codex T23-B2 BLOCKER regression — the cross-worker race the
        // round-1 silent-applied bug actually triggered. Treasury job
        // runs first (under multi-Horizon-worker dispatch), throws
        // `ProjectionDependencyMissingException` because the
        // `pos_receipts` row doesn't yet exist, attempts++ on the
        // Treasury row, and the wrapping job re-throws. Meanwhile (in
        // this single-threaded test, we simulate "meanwhile" by
        // explicitly running POS-core next), POS-core lands and writes
        // the row. The Treasury retry then succeeds.
        $realPosCore = $this->app->make(PosCoreReceiptProjection::class);
        $realTreasury = $this->app->make(
            TreasuryReceiptBridge::class,
        );
        $this->registerProjectors([$realPosCore, $realTreasury]);

        $event = $this->storeSaleReceiptFiscalEventForBridge();
        $posRow = $this->seedPendingProjectionRow($event, 'pos_core_receipt');
        $treasuryRow = $this->seedPendingProjectionRow($event, 'treasury_receipt_bridge');

        // 1) Treasury runs FIRST — must throw the dependency-missing
        // exception, advance attempts, leave row `running` (not `applied`).
        $caught = null;
        try {
            $this->runJobInline($treasuryRow->id);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Treasury job must throw when pos_receipts row is missing.');
        $this->assertInstanceOf(
            ProjectionDependencyMissingException::class,
            $caught,
        );

        $treasuryAfterFirst = DB::table('fiscal_event_projections')
            ->where('id', $treasuryRow->id)->first();
        $this->assertNotNull($treasuryAfterFirst);
        // Round-3 (Codex T23-R2-B1): status reset to `pending` after
        // the dependency-missing throw so the retry path can re-enter
        // T_lock without tripping the stale-running guard.
        $this->assertSame('pending', $treasuryAfterFirst->projection_status);
        $this->assertSame(1, (int) $treasuryAfterFirst->attempts);
        $this->assertStringContainsString(
            'pos_receipts',
            (string) $treasuryAfterFirst->last_error,
        );
        // Critical: no Treasury Payment row was written.
        $this->assertSame(0, DB::table('payments')->count());

        // 2) POS-core lands second (POS-core's job runs).
        $this->runJobInline($posRow->id);
        $posAfter = DB::table('fiscal_event_projections')
            ->where('id', $posRow->id)->first();
        $this->assertNotNull($posAfter);
        $this->assertSame('applied', $posAfter->projection_status);
        $this->assertSame(
            1,
            DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->count(),
        );

        // 3) Treasury retry — now the dependency is visible, succeeds.
        //
        // Pre-round-3 this section had to manually age `last_attempted_at`
        // past the freshness window to dodge the stale-running guard;
        // that workaround masked the round-2 BLOCKER Codex caught
        // (T23-R2-B1). Round-3 reset status to `pending` on failure, so
        // the retry's T_lock now sees `pending` and proceeds normally —
        // no clock-juggling needed.

        $this->runJobInline($treasuryRow->id);

        $treasuryAfterRetry = DB::table('fiscal_event_projections')
            ->where('id', $treasuryRow->id)->first();
        $this->assertNotNull($treasuryAfterRetry);
        $this->assertSame('applied', $treasuryAfterRetry->projection_status);

        // Treasury Payment + GL entry now written.
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertGreaterThan(0, DB::table('journal_entries')->count());
    }

    public function test_horizon_retry_after_failure_does_not_hit_stale_running_short_circuit(): void
    {
        // Codex T23-R2-B1 BLOCKER regression. Round-2 added a belt-and-
        // braces stale-running check to T_lock: if status=Running AND
        // last_attempted_at is fresh (within $timeout), short-circuit as
        // "sibling worker mid-apply". BUT advanceFailureAccounting() set
        // last_attempted_at = now() after every failure AND left status =
        // Running. So the next Horizon retry's T_lock saw status=Running
        // + fresh timestamp → short-circuited → projector never re-ran.
        // The Treasury retry path was silently dead.
        //
        // Round-3 fix: advanceFailureAccounting() now resets
        // projection_status to Pending after recording a failure, so the
        // next retry's T_lock sees Pending and proceeds normally. The
        // stale-running guard now only fires for genuinely anomalous
        // rows (Running with no failure log between attempts).
        //
        // The round-2 cross-worker race test passed only because
        // runJobInline runs sequentially without Carbon::setTestNow
        // advancing between attempts — so by the time the test forced
        // the retry, the prior runJobInline had completed and the test
        // explicitly aged last_attempted_at past the freshness window.
        // In production, Horizon's actual delay-then-retry would always
        // hit the in-flight branch first.
        [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');
        $this->forceProjectorToThrow('treasury_receipt_bridge');

        // First attempt: throws. Failure is recorded; round-3 contract
        // is status reset to Pending.
        try {
            $this->runJobInline($projectionRow->id);
            $this->fail('First attempt must throw — projector was forced to throw.');
        } catch (\Throwable) {
            // expected
        }

        $afterFirst = DB::table('fiscal_event_projections')
            ->where('id', $projectionRow->id)->first();
        $this->assertNotNull($afterFirst);
        $this->assertSame(1, (int) $afterFirst->attempts);
        $this->assertNotNull($afterFirst->last_attempted_at);
        // R3 INVARIANT: status reset to `pending` so the next retry's
        // T_lock proceeds (instead of tripping the stale-running guard).
        $this->assertSame(
            'pending',
            $afterFirst->projection_status,
            'Round-3 T23-R2-B1 fix: advanceFailureAccounting must reset status to Pending so retry proceeds.',
        );

        // Simulate Horizon backoff — short delay, WELL within the
        // stale-running freshness window ($timeout = 120s). Without the
        // round-3 fix, this is exactly the timing that round-2's guard
        // tripped on.
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds(10));

        // Clear the throw so the retry actually applies.
        $this->clearProjectorThrow('treasury_receipt_bridge');

        try {
            // Retry: MUST proceed (NOT short-circuit). Without round-3 this
            // would silently no-op and leave the row stuck.
            $this->runJobInline($projectionRow->id);

            $afterRetry = DB::table('fiscal_event_projections')
                ->where('id', $projectionRow->id)->first();
            $this->assertNotNull($afterRetry);
            $this->assertSame(
                'applied',
                $afterRetry->projection_status,
                'Round-3 T23-R2-B1 contract: the Horizon retry must reach apply() and mark the row applied. If this fails, the stale-running short-circuit silently killed the retry path.',
            );
        } finally {
            // Round-4 R3-F2: reset MUST live in finally so a mid-test
            // assertion failure cannot leak `Carbon::setTestNow(+10s)`
            // into the next test in the suite (would surface as a flake
            // in any time-sensitive test that runs after this one).
            Carbon::setTestNow();
        }
    }

    public function test_in_flight_running_row_short_circuits_even_when_cache_lock_fails_open(): void
    {
        // Codex R3-P1-1 BLOCKER (round-4 fix). The Task 23 two-defense
        // design relies on the in-flight guard to short-circuit a duplicate
        // delivery whose `WithoutOverlapping` cache lock failed open
        // (Redis flap, misconfigured cache driver, cross-process `array`
        // store, etc.). The guard fires on `(status=Running &&
        // isRecentlyAttempted)`, but `isRecentlyAttempted` returns false
        // when `last_attempted_at IS NULL`.
        //
        // **The bug round-3 left open.** Round-3's T_lock flipped the
        // status to `Running` and saved — but did NOT stamp
        // `last_attempted_at`. So a row that was genuinely in-flight
        // (Worker A just past T_lock, mid-`apply()`) sat at
        // `(Running, last_attempted_at=null)`. If the cache lock failed
        // open and Worker B's middleware let the duplicate through,
        // Worker B's T_lock loaded the row, the guard's null-path returned
        // false, the guard did NOT fire, T_lock re-flipped Running + saved,
        // returned `true`, and Worker B entered `apply()` concurrently
        // with Worker A. Double execution. (Note that
        // `advanceFailureAccounting` and `recordHardFailure` DO stamp
        // `last_attempted_at`, but those only run AFTER apply() —
        // round-3's guard had no in-flight signal during the first attempt
        // because the only writer of `last_attempted_at` was failure
        // accounting.)
        //
        // **Round-4 fix.** T_lock now stamps `last_attempted_at =
        // Carbon::now('UTC')` in the same `save()` as the Running flip.
        // The column's semantics shift slightly — "when did we last CLAIM
        // this row for an attempt" rather than "when did we last try and
        // fail" — but the shift is downstream-safe (no production query
        // orders by this column; the stale-running guard's
        // `now - last_attempted_at > timeout` check still works because
        // claim time and try time are within seconds of each other; the
        // failure-accounting path overwrites the timestamp on every
        // failure so the operator-visible "last attempted" semantic is
        // preserved).
        //
        // **Test mechanism (bug-pinner).** Use a re-entrant projector
        // whose first `apply()` invocation simulates Worker B's
        // middleware-bypassed re-delivery by directly invoking a fresh
        // `handle()` on the same projection row. This is the literal
        // shape of "cache lock failed open and the duplicate entered the
        // job's `handle()` body".
        //
        // Trace on round-3 (no stamp):
        //   Worker A T_lock: (Pending, null) → (Running, null), commits.
        //   Worker A enters apply(). Inside apply, simulate Worker B by
        //     invoking handle() again.
        //   Worker B T_lock: loads (Running, null) → not terminal → guard
        //     sees `Running && isRecentlyAttempted(null) = false` → falls
        //     through → re-flips status (still Running) + saves → returns
        //     true → enters apply() → projector recursively invoked.
        //     invocationCount = 2.
        //   Test FAILS the assertSame(1, ...) — round-3 bug pinned.
        //
        // Trace on round-4 (T_lock stamps last_attempted_at):
        //   Worker A T_lock: (Pending, null) → (Running, now), commits.
        //   Worker A enters apply(). Inside apply, simulate Worker B.
        //   Worker B T_lock: loads (Running, now) → not terminal → guard
        //     sees `Running && isRecentlyAttempted(now) = true` → returns
        //     false → T_lock short-circuits → handle() returns without
        //     invoking apply().
        //   invocationCount = 1. Test passes.
        $reentrant = new ReentrantFakeProjector(
            name: 'pos_core_receipt',
            requiresModule: null,
            priority: 50,
            container: $this->app,
        );
        $this->registerProjectors([$reentrant]);

        [$event, $projectionRow] = $this->pendingProjection('pos_core_receipt');

        $this->runJobInline($projectionRow->id);

        // Round-4 contract: projector invoked EXACTLY once. The re-entrant
        // (simulated Worker B) handle() invocation must have short-
        // circuited inside T_lock via the in-flight guard — because the
        // round-4 T_lock stamped `last_attempted_at` when it flipped to
        // Running.
        $this->assertSame(
            1,
            $reentrant->invocationCount,
            'R3-P1-1 round-4 contract: T_lock must stamp last_attempted_at when flipping to Running so the in-flight guard correctly identifies the row as in-flight even when WithoutOverlapping fails open. Without the stamp, the duplicate delivery enters apply() concurrently — invocationCount would be 2.',
        );

        // Cross-check: from inside the re-entrant apply, the row SHOULD
        // have been observable as `(Running, last_attempted_at=now)` —
        // record that observation here.
        $this->assertNotNull(
            $reentrant->observedLastAttemptedAtDuringReentry,
            'R3-P1-1 round-4 invariant: between T_lock commit and apply() entry, the row MUST carry last_attempted_at IS NOT NULL. A null observation here means T_lock did not stamp the column on Running entry — duplicate deliveries hitting a failed-open cache lock would see the in-flight guard fail.',
        );

        // Sanity: the row ends `applied` (Worker A completed normally).
        $row = DB::table('fiscal_event_projections')->where('id', $projectionRow->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('applied', $row->projection_status);
    }

    // =================================================================
    // Task 23 round-2 — Codex T23-P3-1 (failed() re-invocation idempotency)
    // =================================================================

    public function test_failed_re_invocation_preserves_original_dead_lettered_at(): void
    {
        // Codex T23-P3-1 closure — round-1 code line 333 gates the
        // terminal-state write so a re-invocation does NOT overwrite the
        // first failure's `dead_lettered_at` timestamp. Round-1 had no
        // test pinning the behavior; this is the regression.
        [$event, $projectionRow] = $this->pendingProjection('treasury_receipt_bridge');
        $job = new ApplyFiscalEventProjectionJob($projectionRow->id);

        $job->failed(new RuntimeException('first failure'));

        $first = DB::table('fiscal_event_projections')
            ->where('id', $projectionRow->id)->first();
        $this->assertNotNull($first);
        $firstTimestamp = (string) $first->dead_lettered_at;
        $firstError = (string) $first->last_error;
        $this->assertNotSame('', $firstTimestamp);
        $this->assertStringContainsString('first failure', $firstError);

        // Advance the clock and re-invoke. The terminal-state gate at
        // line 333 must short-circuit the write: dead_lettered_at and
        // last_error are unchanged.
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds(60));
        try {
            $job->failed(new RuntimeException('second failure'));

            $second = DB::table('fiscal_event_projections')
                ->where('id', $projectionRow->id)->first();
            $this->assertNotNull($second);
            $this->assertSame($firstTimestamp, (string) $second->dead_lettered_at);
            $this->assertSame($firstError, (string) $second->last_error);
            $this->assertSame('dead_lettered', $second->projection_status);
        } finally {
            // Round-4 R3-F2: reset MUST live in finally so a mid-test
            // assertion failure cannot leak `Carbon::setTestNow(+60s)`
            // into the next test in the suite.
            Carbon::setTestNow();
        }
    }

    // =================================================================
    // Task 23 round-2 — Codex T23-P3-2 (hard-misconfig branches)
    // =================================================================

    public function test_missing_fiscal_event_records_hard_failure_and_throws(): void
    {
        // Codex T23-P3-2 closure — round-1 lines 237-248 implement the
        // missing-FiscalEvent hard-misconfig path (Log::critical +
        // recordHardFailure + throw), but no test pinned the behavior.
        //
        // Build a projection row whose `fiscal_event_id` points at a row
        // that never existed (we cannot DELETE FiscalEvent — Task 8's
        // BEFORE DELETE trigger forbids deletes — so we use a fresh UUID).
        $event = $this->storeSaleReceiptFiscalEvent();
        $projectionRow = $this->seedPendingProjectionRow($event, 'pos_core_receipt');

        // Sever the FK by updating in place — fiscal_event_id is NOT
        // immutable on `fiscal_event_projections` (only on `fiscal_events`).
        DB::table('fiscal_event_projections')
            ->where('id', $projectionRow->id)
            ->update(['fiscal_event_id' => (string) Str::uuid()]);

        Log::shouldReceive('critical')->atLeast()->once();

        try {
            $this->runJobInline($projectionRow->id);
            $this->fail('Expected RuntimeException for missing FiscalEvent.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('FiscalEvent', $e->getMessage());
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        $after = DB::table('fiscal_event_projections')
            ->where('id', $projectionRow->id)->first();
        $this->assertNotNull($after);
        // recordHardFailure advanced attempts + last_error + last_attempted_at.
        $this->assertSame(1, (int) $after->attempts);
        $this->assertStringContainsString(
            'FiscalEvent row not found',
            (string) $after->last_error,
        );
        $this->assertNotNull($after->last_attempted_at);
        // Task 23 round-3 (Codex T23-R2-B1, symmetric fix in
        // recordHardFailure): status reset to `pending` between Horizon
        // retries so the retry's T_lock proceeds. Operator visibility
        // for hard-misconfig retries comes from `last_error` containing
        // the misconfig reason. Only `failed()` flips to `dead_lettered`
        // after Horizon exhausts `$tries`.
        $this->assertSame('pending', $after->projection_status);
    }

    public function test_missing_projector_records_hard_failure_and_throws(): void
    {
        // Codex T23-P3-2 closure — round-1 lines 252-272 implement the
        // missing-projector hard-misconfig path (projector deregistered
        // between ingest and run; registry's byName() returns null;
        // Log::critical + recordHardFailure + throw). No test pinned it.
        //
        // Seed a row referencing a projector name not in the registry.
        $event = $this->storeSaleReceiptFiscalEvent();
        $projectionRow = $this->seedPendingProjectionRow($event, 'projector_was_removed');

        // Rebind the registry with NO matching projector so byName()
        // returns null for `projector_was_removed`.
        $this->registerFakeProjectors([
            new ConfigurableFakeProjector(
                name: 'something_else',
                requiresModule: null,
                priority: 50,
                shouldThrow: false,
            ),
        ]);

        Log::shouldReceive('critical')->atLeast()->once();

        try {
            $this->runJobInline($projectionRow->id);
            $this->fail('Expected RuntimeException for missing projector.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('projector_was_removed', $e->getMessage());
            $this->assertStringContainsString('no projector named', $e->getMessage());
        }

        $after = DB::table('fiscal_event_projections')
            ->where('id', $projectionRow->id)->first();
        $this->assertNotNull($after);
        $this->assertSame(1, (int) $after->attempts);
        $this->assertStringContainsString(
            'projector deregistered',
            (string) $after->last_error,
        );
        // Round-3 (Codex T23-R2-B1 symmetric fix): status reset to
        // `pending` so Horizon's retry path proceeds and ultimately
        // dead-letters via `failed()` after `$tries` exhaustion.
        $this->assertSame('pending', $after->projection_status);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Seed a verified SALE_RECEIPT fiscal_events row + one pending
     * projection row for `$projectorName`. Returns the FiscalEvent model
     * and the projection-row Eloquent model.
     *
     * @return array{0: FiscalEvent, 1: FiscalEventProjectionRow}
     */
    private function pendingProjection(string $projectorName): array
    {
        $event = $this->storeSaleReceiptFiscalEvent();
        $row = $this->seedPendingProjectionRow($event, $projectorName);

        return [$event, $row];
    }

    private function seedPendingProjectionRow(FiscalEvent $event, string $projectorName): FiscalEventProjectionRow
    {
        $now = now()->utc();

        DB::table('fiscal_event_projections')->insert([
            'id' => $id = Str::uuid()->toString(),
            'fiscal_event_id' => $event->id,
            'projector_name' => $projectorName,
            'projection_status' => ProjectionStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = FiscalEventProjectionRow::query()->findOrFail($id);

        /** @var FiscalEventProjectionRow $row */
        return $row;
    }

    /**
     * Flip the projector identified by `$name` to "always throws" mode
     * for the duration of the test. Re-binds the registry singleton
     * with the updated projector instance.
     */
    private function forceProjectorToThrow(string $name): void
    {
        $existing = $this->projectorsByName[$name] ?? null;
        if (! $existing instanceof ConfigurableFakeProjector) {
            throw new RuntimeException(sprintf(
                'forceProjectorToThrow("%s"): no test-local ConfigurableFakeProjector registered with this name.',
                $name,
            ));
        }
        $existing->shouldThrow = true;
    }

    /**
     * Inverse of `forceProjectorToThrow` — flip the projector back to the
     * "always succeeds" mode. Used by the Codex T23-R2-B1 round-3
     * regression test to simulate "the transient downstream failure
     * resolved between Horizon attempts".
     */
    private function clearProjectorThrow(string $name): void
    {
        $existing = $this->projectorsByName[$name] ?? null;
        if (! $existing instanceof ConfigurableFakeProjector) {
            throw new RuntimeException(sprintf(
                'clearProjectorThrow("%s"): no test-local ConfigurableFakeProjector registered with this name.',
                $name,
            ));
        }
        $existing->shouldThrow = false;
    }

    /**
     * Run the projection job for `(event, projector)` and assert it
     * succeeded (`applied`). Used by the cross-projector cluster test.
     */
    private function runProjection(FiscalEvent $event, string $projectorName): void
    {
        $row = FiscalEventProjectionRow::query()
            ->where('fiscal_event_id', $event->id)
            ->where('projector_name', $projectorName)
            ->firstOrFail();

        $this->runJobInline($row->id);
    }

    /**
     * Invoke `ApplyFiscalEventProjectionJob::handle()` inline via the
     * container so the method-injected dependencies (`ConnectionInterface`,
     * `FiscalEventProjectionRegistry`) are resolved from the test's
     * container bindings. Equivalent to Horizon's dispatch path:
     * Horizon's worker resolves the same dependencies via
     * `Container::call()` when invoking `handle()`.
     */
    private function runJobInline(string $projectionRowId): void
    {
        $job = new ApplyFiscalEventProjectionJob($projectionRowId);
        $this->app->call([$job, 'handle']);
    }

    /**
     * Simulate Horizon's exhausted-retries path for `(event, projector)`.
     * Invokes `failed()` directly so the row flips to `dead_lettered`.
     */
    private function failProjectionToDeadLetter(FiscalEvent $event, string $projectorName): void
    {
        $row = FiscalEventProjectionRow::query()
            ->where('fiscal_event_id', $event->id)
            ->where('projector_name', $projectorName)
            ->firstOrFail();

        (new ApplyFiscalEventProjectionJob($row->id))
            ->failed(new RuntimeException('exhausted retries'));
    }

    /**
     * Persist a verified SALE_RECEIPT fiscal_events row directly via the
     * Eloquent model. Pattern carried forward from
     * `PosCoreReceiptProjectionTest::storeSaleReceiptFiscalEvent`. The
     * payload includes `repository_id` on the payment_line so the real
     * `TreasuryReceiptBridge` accepts it under the Codex T23-B2 round-2
     * regression test — the bridge requires `repository_id` to issue
     * the POS-payment GL entry. The real `PosCoreReceiptProjection`
     * tolerates the extra field harmlessly.
     */
    private function storeSaleReceiptFiscalEvent(): FiscalEvent
    {
        return $this->storeSaleReceiptFiscalEventForBridge();
    }

    private function storeSaleReceiptFiscalEventForBridge(): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $payload = [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => '0.00',
            'lines' => [
                ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00', 'quantity' => '1', 'tax_rate' => '0', 'tax_amount' => '0.00'],
            ],
            'payment_lines' => [
                [
                    'payment_method_id' => $this->paymentMethodId,
                    'amount' => '10.00',
                    'method_code' => 'CASH',
                    'repository_id' => $this->repositoryId,
                ],
            ],
            'subtotal' => '10.00',
            'tax_total' => '0.00',
            'total' => '10.00',
            'vat_breakdown' => [
                ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
            ],
            'voucher_redemptions' => [],
        ];

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        $event = FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    /**
     * Rebind the FiscalEventProjectionRegistry singleton with `$projectors`
     * and a resolver that reports `'Treasury'` as active (default for
     * these tests). Each test that needs a different projector set calls
     * this directly.
     *
     * @param  list<ConfigurableFakeProjector>  $projectors
     */
    private function registerFakeProjectors(array $projectors): void
    {
        $this->registerProjectors($projectors);
    }

    /**
     * Same shape as `registerFakeProjectors` but accepts a heterogeneous
     * mix of real + fake projectors. Used by the F1 round-2 closure to
     * swap a real `PosCoreReceiptProjection` into the partial-cluster
     * test alongside a `ConfigurableFakeProjector` for the Treasury
     * dead-letter side.
     *
     * @param  list<FiscalEventProjector>  $projectors
     */
    private function registerProjectors(array $projectors): void
    {
        $this->projectorsByName = [];
        foreach ($projectors as $p) {
            $this->projectorsByName[$p->name()] = $p;
        }

        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            fn (): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                $projectors,
                new TreasuryAlwaysActiveResolver,
            ),
        );

        // Silence the registry's F1 fail-closed Log::error call if the
        // resolver ever throws — none of these tests provoke that path,
        // but defensive.
        Log::spy();
    }

    /**
     * Spec §4 JCS canonical encoding (test-local; same as
     * `PosCoreReceiptProjectionTest::canonicalEncode`).
     *
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }
}

// =====================================================================
// Test-local fake projector. Configurable to either succeed (no-op
// `apply()`) or throw, and to record invocations against a shared
// ApplyLog so cross-projector cluster tests can assert per-projector
// invocation counts.
// =====================================================================

final class ConfigurableFakeProjector implements FiscalEventProjector
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $requiresModule,
        private readonly int $priority,
        public bool $shouldThrow,
        private readonly ?ApplyLog $applyLog = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return $this->requiresModule;
    }

    public function apply(FiscalEvent $event): void
    {
        $this->applyLog?->record($this->name);

        if ($this->shouldThrow) {
            throw new RuntimeException(
                'configurable fake projector failure: projector='.$this->name.', event='.$event->id,
            );
        }
    }

    public function priority(): int
    {
        return $this->priority;
    }
}

/**
 * In-memory side table — records `apply()` invocations per projector name
 * across a single test. Used by the cross-projector partial-cluster test
 * + the short-circuit re-dispatch tests so we can assert the projector
 * was invoked exactly the expected number of times (proof that the
 * short-circuit path doesn't quietly re-invoke the projector).
 */
final class ApplyLog
{
    /** @var array<string, int> */
    private array $counts = [];

    public function record(string $projectorName): void
    {
        $this->counts[$projectorName] = ($this->counts[$projectorName] ?? 0) + 1;
    }

    public function countFor(string $projectorName): int
    {
        return $this->counts[$projectorName] ?? 0;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->counts);
    }
}

/**
 * Test-local resolver — reports `Treasury` as always active. The job
 * doesn't ASK the resolver (activation gating happened at ingest time per
 * spec §7.5), so this is purely for the registry's constructor — but the
 * Codex F3 round-2 test enforces non-empty/non-whitespace
 * `requiresModule()` tokens at construction, so we still need a valid
 * resolver wired.
 */
final class TreasuryAlwaysActiveResolver implements ModuleActivationResolver
{
    public function isActive(string $module, string $tenantId, string $companyId): bool
    {
        unset($module, $tenantId, $companyId);

        return true;
    }
}

/**
 * Test-local spy used by Task 23 round-3 (Codex T23-R2-B2) regressions
 * that assert `WithoutOverlapping` re-queues a duplicate delivery via
 * `release()` rather than silently dropping it (round-2 `dontRelease()`
 * regression). Records whether `release()` was called and the delay.
 *
 * Implementation note: `ApplyFiscalEventProjectionJob` is `final` so we
 * cannot subclass it. Instead we mirror its public-facing shape just
 * enough for `WithoutOverlapping::handle()` to invoke `release()` when
 * the lock is held. Per the vendor middleware
 * (`WithoutOverlapping::getLockKey()`), the lock key derives from
 * `get_class($job)` when the job has no `displayName()` method. The real
 * job has no such method either — but the spy lives in a DIFFERENT class
 * namespace, so the lock key will differ. The test compensates by
 * acquiring the held lock against the SPY's own lock key (computed via
 * `getLockKey($spy)`) so the lock-key collision the middleware looks for
 * is exact.
 */
final class ReleaseRecordingJobSpy
{
    public bool $wasReleased = false;

    public ?int $releaseDelay = null;

    public int $timeout = 120;

    public function __construct(public readonly string $projectionRowId) {}

    public function release(int $delay = 0): void
    {
        $this->wasReleased = true;
        $this->releaseDelay = $delay;
    }
}

/**
 * Test-local re-entrant projector for the Task 23 round-4 R3-P1-1
 * regression — simulates the literal cache-lock-failed-open scenario
 * by, inside its first `apply()` invocation, dispatching a fresh
 * `ApplyFiscalEventProjectionJob::handle()` for the same projection
 * row id. This mirrors the production shape where a duplicate
 * delivery whose `WithoutOverlapping` cache lock failed open would
 * land in the job's `handle()` body while Worker A is still mid-apply.
 *
 * Records the invocation count + the row's `last_attempted_at`
 * observation taken from inside the FIRST `apply()` body (BEFORE the
 * re-entrant handle() call) so the round-4 invariant ("T_lock stamps
 * last_attempted_at when flipping to Running") can be asserted
 * directly.
 *
 * Guards against unbounded recursion: tracks `invocationCount` and
 * skips the re-entrant dispatch on subsequent calls — but the round-4
 * contract is that the re-entrant call short-circuits inside T_lock
 * BEFORE reaching apply(), so `invocationCount` should stay at 1.
 * If the round-3 bug is present, the re-entrant call DOES reach
 * apply(), and the recursion guard fires on the second call to
 * prevent infinite recursion — invocationCount becomes 2 (or more,
 * depending on the guard's exact placement). Either way the
 * assertion `assertSame(1, invocationCount)` catches the bug.
 */
final class ReentrantFakeProjector implements FiscalEventProjector
{
    public int $invocationCount = 0;

    public ?Carbon $observedLastAttemptedAtDuringReentry = null;

    public function __construct(
        private readonly string $name,
        private readonly ?string $requiresModule,
        private readonly int $priority,
        private readonly Application $container,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return $this->requiresModule;
    }

    public function apply(FiscalEvent $event): void
    {
        $this->invocationCount++;

        if ($this->invocationCount > 1) {
            // Recursion guard — if this fires, the round-3 bug is present
            // (the re-entrant handle() did NOT short-circuit inside T_lock,
            // so apply() was invoked again). The test's
            // `assertSame(1, $reentrant->invocationCount)` will catch
            // this; we return early here to avoid infinite recursion.
            return;
        }

        // From INSIDE Worker A's apply(), observe the row state. The
        // round-4 invariant is `last_attempted_at IS NOT NULL` (T_lock
        // stamped it on Running entry). Round-3 leaves it null because
        // T_lock didn't stamp it and apply() has not yet thrown / advanced
        // failure accounting.
        $row = FiscalEventProjectionRow::query()
            ->whereKey($this->resolveProjectionRowIdFor($event->id))
            ->first();

        $this->observedLastAttemptedAtDuringReentry = $row?->last_attempted_at;

        // Simulate Worker B's middleware-bypassed re-delivery — invoke a
        // fresh handle() on the same projection row. Round-4 contract:
        // this MUST short-circuit inside T_lock without re-entering
        // apply(). Round-3 bug: this reaches apply() (caught by the
        // recursion guard above).
        if ($row !== null) {
            $duplicate = new ApplyFiscalEventProjectionJob($row->id);
            $this->container->call([$duplicate, 'handle']);
        }
    }

    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * Resolve the projection row id for the given fiscal_event_id +
     * this projector's name. In the test there is exactly one such row.
     */
    private function resolveProjectionRowIdFor(string $fiscalEventId): ?string
    {
        $row = FiscalEventProjectionRow::query()
            ->where('fiscal_event_id', $fiscalEventId)
            ->where('projector_name', $this->name)
            ->first();

        return $row?->id;
    }
}
