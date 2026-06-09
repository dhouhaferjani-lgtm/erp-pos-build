<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionDispatcher;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2 — `FiscalEventProjectionDispatcher`.
 *
 * The public dispatcher that drives a server-authored fiscal event through the
 * projection pipeline. Unlike `OutboxIngestor::dispatchProjections()` (private,
 * device-only) it carries NO device-specific suppression rules — server-authored
 * events are always `Verified`/`Parsed`, so every active projector gets a pending
 * row + an `afterCommit` job. The job runner, registry, priority ordering, and
 * `(fiscal_event_id, projector_name)` idempotency are reused unchanged.
 */
final class FiscalEventProjectionDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;
        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        Queue::fake();
    }

    public function test_dispatch_inserts_one_pending_row_per_active_projector_and_enqueues_a_job(): void
    {
        $this->bindRegistry([new FakeDepositReceiptProjector]);
        $event = $this->storeDepositReceiptEvent();

        app(FiscalEventProjectionDispatcher::class)->dispatch($event);

        $rows = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('fake_deposit_receipt', $rows[0]->projector_name);
        $this->assertSame('pending', $rows[0]->projection_status);
        $this->assertSame(0, (int) $rows[0]->attempts);

        $projectionRowId = (string) $rows[0]->id;
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1);
        Queue::assertPushed(
            ApplyFiscalEventProjectionJob::class,
            static fn (ApplyFiscalEventProjectionJob $job): bool => $job->projectionRowId === $projectionRowId,
        );
    }

    public function test_dispatch_seeds_rows_for_every_active_projector_in_priority_order(): void
    {
        $this->bindRegistry([new FakeTreasuryDepositProjector, new FakeDepositReceiptProjector]);
        $event = $this->storeDepositReceiptEvent();

        app(FiscalEventProjectionDispatcher::class)->dispatch($event);

        $names = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->pluck('projector_name')
            ->all();

        // priority 50 (deposit-receipt) before 150 (treasury bridge).
        $this->assertSame(['fake_deposit_receipt', 'fake_treasury_deposit'], $names);
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 2);
    }

    public function test_dispatch_is_a_noop_when_no_projector_handles_the_event(): void
    {
        $this->bindRegistry([new FakeUnrelatedProjector]);
        $event = $this->storeDepositReceiptEvent();

        app(FiscalEventProjectionDispatcher::class)->dispatch($event);

        $this->assertSame(
            0,
            DB::table('fiscal_event_projections')->where('fiscal_event_id', $event->id)->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_dispatch_does_not_enqueue_jobs_or_persist_rows_if_outer_transaction_rolls_back(): void
    {
        $this->bindRegistry([new FakeDepositReceiptProjector]);
        $event = $this->storeDepositReceiptEvent();

        try {
            DB::transaction(function () use ($event): void {
                app(FiscalEventProjectionDispatcher::class)->dispatch($event);
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            0,
            DB::table('fiscal_event_projections')->where('fiscal_event_id', $event->id)->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_dispatch_is_idempotent_and_re_enqueues_pending_rows_on_replay(): void
    {
        $this->bindRegistry([new FakeDepositReceiptProjector]);
        $event = $this->storeDepositReceiptEvent();
        $dispatcher = app(FiscalEventProjectionDispatcher::class);

        $dispatcher->dispatch($event);
        // A replay/recovery call must NOT raise a duplicate-key QueryException.
        $dispatcher->dispatch($event);

        // Still exactly one projection row per projector (no duplicates).
        $rows = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->get();
        $this->assertCount(1, $rows);
        $rowId = (string) $rows[0]->id;

        // Both calls re-enqueue the still-pending row so recovery can drive it.
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 2);
        Queue::assertPushed(
            ApplyFiscalEventProjectionJob::class,
            static fn (ApplyFiscalEventProjectionJob $job): bool => $job->projectionRowId === $rowId,
        );
    }

    public function test_dispatch_re_enqueues_an_existing_pending_row_even_if_its_projector_is_no_longer_active(): void
    {
        // First dispatch with the projector active creates the pending row.
        $this->bindRegistry([new FakeDepositReceiptProjector]);
        $event = $this->storeDepositReceiptEvent();
        app(FiscalEventProjectionDispatcher::class)->dispatch($event);

        $rowId = (string) DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->value('id');

        // The projector's module is deactivated (here: registry now has no
        // projector for the event). A replay must STILL re-drive the existing
        // pending row — activation gating happened at row creation, not at run.
        $this->bindRegistry([]);
        app(FiscalEventProjectionDispatcher::class)->dispatch($event);

        $this->assertSame(
            1,
            DB::table('fiscal_event_projections')->where('fiscal_event_id', $event->id)->count(),
        );
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 2);
        Queue::assertPushed(
            ApplyFiscalEventProjectionJob::class,
            static fn (ApplyFiscalEventProjectionJob $job): bool => $job->projectionRowId === $rowId,
        );
    }

    public function test_dispatch_rejects_a_non_server_authored_event(): void
    {
        // A device-authored event type (SALE_RECEIPT) must never be driven
        // through this server-authored dispatcher — even with a handling
        // projector bound, the precondition guard fires first.
        $this->bindRegistry([new FakeUnrelatedProjector]);
        $event = $this->storeDepositReceiptEvent(['event_type' => FiscalEventType::SALE_RECEIPT]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(FiscalEventProjectionDispatcher::class)->dispatch($event);
        } finally {
            $this->assertSame(
                0,
                DB::table('fiscal_event_projections')->where('fiscal_event_id', $event->id)->count(),
            );
            Queue::assertNothingPushed();
        }
    }

    public function test_dispatch_rejects_a_quarantined_or_unparsed_event(): void
    {
        $this->bindRegistry([new FakeDepositReceiptProjector]);
        $event = $this->storeDepositReceiptEvent([
            'integrity_status' => IntegrityStatus::Quarantined,
            'payload_parse_status' => PayloadParseStatus::Failed,
            'payload' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(FiscalEventProjectionDispatcher::class)->dispatch($event);
        } finally {
            $this->assertSame(
                0,
                DB::table('fiscal_event_projections')->where('fiscal_event_id', $event->id)->count(),
            );
            Queue::assertNothingPushed();
        }
    }

    /**
     * @param  list<FiscalEventProjector>  $projectors
     */
    private function bindRegistry(array $projectors): void
    {
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            fn (): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                $projectors,
                $this->app->make(ModuleActivationResolver::class),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storeDepositReceiptEvent(array $overrides = []): FiscalEvent
    {
        return FiscalEvent::query()->create(array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => Str::uuid()->toString(),
            'event_type' => FiscalEventType::DEPOSIT_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => '2026-06-09 10:15:30',
            'business_date' => '2026-06-09',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-06-09 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => ['stub' => true],
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ], $overrides))->refresh();
    }
}

final class FakeDepositReceiptProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'fake_deposit_receipt';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::DEPOSIT_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }

    public function priority(): int
    {
        return 50;
    }
}

final class FakeTreasuryDepositProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'fake_treasury_deposit';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::DEPOSIT_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }

    public function priority(): int
    {
        return 150;
    }
}

final class FakeUnrelatedProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'fake_unrelated';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);
    }

    public function priority(): int
    {
        return 50;
    }
}
