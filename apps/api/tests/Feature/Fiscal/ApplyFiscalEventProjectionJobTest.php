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
        // Status remains `running` between Horizon retries — the row is
        // visible to operator tooling as in-flight, not stuck pending.
        $this->assertSame('running', $row->projection_status);
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
        // lock release) and `dontRelease()` (duplicate delivery is dropped,
        // not re-queued). Without this middleware, a duplicate Horizon
        // delivery could enter `apply()` concurrently with the in-flight
        // worker — round-1 had no such defense.
        $projectionRowId = (string) Str::uuid();
        $job = new ApplyFiscalEventProjectionJob($projectionRowId);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);

        $first = $this->withoutOverlappingMiddleware($job);
        $this->assertSame($projectionRowId, $first->key);
        // `dontRelease()` sets releaseAfter to null — duplicate delivery
        // is silently dropped (not re-queued for retry).
        $this->assertNull($first->releaseAfter);
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
        // when the lock is acquired; otherwise it either re-queues
        // (`releaseAfter`) or silently drops (`dontRelease`). We pin
        // `dontRelease()` here: the second invocation MUST NOT invoke
        // `$next` because the lock is still held from the first.
        $projectionRowId = (string) Str::uuid();
        $job1 = new ApplyFiscalEventProjectionJob($projectionRowId);
        $job2 = new ApplyFiscalEventProjectionJob($projectionRowId);

        $middleware1 = $this->withoutOverlappingMiddleware($job1);
        $middleware2 = $this->withoutOverlappingMiddleware($job2);

        // Acquire the lock manually for the duration of the assertion so
        // we don't have to fork to simulate two live workers. This is
        // exactly what `WithoutOverlapping::handle()` does internally
        // (`Cache::lock($key, $expiresAfter)->get()`).
        $lockKey = $middleware1->getLockKey($job1);
        $lock = Cache::lock($lockKey, $job1->timeout);
        $this->assertTrue($lock->get(), 'precondition: lock must be acquirable on first try');

        try {
            // While the lock is held by the "first worker", invoke the
            // middleware for the "second delivery". `$next` must not be
            // called — duplicate delivery is silently dropped.
            $nextWasCalled = false;
            $middleware2->handle($job2, function () use (&$nextWasCalled): void {
                $nextWasCalled = true;
            });
            $this->assertFalse(
                $nextWasCalled,
                'WithoutOverlapping must drop duplicate delivery while sibling worker holds the lock.',
            );
        } finally {
            $lock->release();
        }

        // After release, a fresh delivery proceeds normally.
        $nextWasCalledAfterRelease = false;
        $middleware2->handle($job2, function () use (&$nextWasCalledAfterRelease): void {
            $nextWasCalledAfterRelease = true;
        });
        $this->assertTrue(
            $nextWasCalledAfterRelease,
            'After lock release, the next delivery acquires the lock and proceeds.',
        );
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
        $this->assertSame('running', $treasuryAfterFirst->projection_status);
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
        // Advance the stale-running clock so the retry doesn't get
        // short-circuited by the belt-and-braces age check (which
        // assumes "fresh running = sibling worker mid-apply"). In
        // production, Horizon's backoff exceeds the freshness window.
        DB::table('fiscal_event_projections')
            ->where('id', $treasuryRow->id)
            ->update([
                'last_attempted_at' => Carbon::now('UTC')->subSeconds(
                    (new ApplyFiscalEventProjectionJob($treasuryRow->id))->timeout + 60,
                ),
            ]);

        $this->runJobInline($treasuryRow->id);

        $treasuryAfterRetry = DB::table('fiscal_event_projections')
            ->where('id', $treasuryRow->id)->first();
        $this->assertNotNull($treasuryAfterRetry);
        $this->assertSame('applied', $treasuryAfterRetry->projection_status);

        // Treasury Payment + GL entry now written.
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertGreaterThan(0, DB::table('journal_entries')->count());
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
        $job->failed(new RuntimeException('second failure'));

        $second = DB::table('fiscal_event_projections')
            ->where('id', $projectionRow->id)->first();
        $this->assertNotNull($second);
        $this->assertSame($firstTimestamp, (string) $second->dead_lettered_at);
        $this->assertSame($firstError, (string) $second->last_error);
        $this->assertSame('dead_lettered', $second->projection_status);

        Carbon::setTestNow();
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
        // Status stays `running` between Horizon retries; only `failed()`
        // flips to `dead_lettered`.
        $this->assertSame('running', $after->projection_status);
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
        $this->assertSame('running', $after->projection_status);
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
