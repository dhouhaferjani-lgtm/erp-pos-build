<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * Per the plan §1796 amendment + the implementer's brief option (b): these
 * tests assert lifecycle semantics in a single-worker scenario only and
 * trust the PG driver to honor `lockForUpdate()` at runtime. The seven
 * tests below cover all the lifecycle paths — happy, attempt-accounting,
 * dead-letter, chain-immutability, partial cluster, two short-circuits —
 * without depending on actual concurrent locking.
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
        // projector's effects survive untouched. Uses a counter projector
        // that records `apply()` invocations against an in-memory side
        // table — proves "applied" is observable as a real business effect,
        // not just a row flag.
        $applyLog = new ApplyLog;
        $this->registerFakeProjectors([
            new ConfigurableFakeProjector(
                name: 'pos_core_receipt',
                requiresModule: null,
                priority: 50,
                shouldThrow: false,
                applyLog: $applyLog,
            ),
            new ConfigurableFakeProjector(
                name: 'treasury_receipt_bridge',
                requiresModule: 'Treasury',
                priority: 150,
                shouldThrow: true,
                applyLog: $applyLog,
            ),
        ]);

        $event = $this->storeSaleReceiptFiscalEvent();
        $posRow = $this->seedPendingProjectionRow($event, 'pos_core_receipt');
        $treasuryRow = $this->seedPendingProjectionRow($event, 'treasury_receipt_bridge');

        $this->runProjection($event, 'pos_core_receipt'); // succeeds
        $this->failProjectionToDeadLetter($event, 'treasury_receipt_bridge');

        // POS-core projector ran exactly once and its row is `applied`.
        $this->assertSame(1, $applyLog->countFor('pos_core_receipt'));
        $posAfter = DB::table('fiscal_event_projections')->where('id', $posRow->id)->first();
        $this->assertNotNull($posAfter);
        $this->assertSame('applied', $posAfter->projection_status);

        // Treasury bridge dead-lettered; its `apply()` was never invoked
        // by the `failed()` path (only `handle()` invokes the projector).
        $treasuryAfter = DB::table('fiscal_event_projections')->where('id', $treasuryRow->id)->first();
        $this->assertNotNull($treasuryAfter);
        $this->assertSame('dead_lettered', $treasuryAfter->projection_status);
        $this->assertSame(0, $applyLog->countFor('treasury_receipt_bridge'));

        // POS-core side effect was NOT rolled back by the Treasury failure
        // (the two projectors run in independent jobs with independent
        // transactions per §7.5 — partial cluster is the spec contract).
        $this->assertSame(['pos_core_receipt'], $applyLog->names());
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
     * `PosCoreReceiptProjectionTest::storeSaleReceiptFiscalEvent`.
     */
    private function storeSaleReceiptFiscalEvent(): FiscalEvent
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
                ['payment_method_id' => $this->paymentMethodId, 'amount' => '10.00', 'method_code' => 'CASH'],
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
