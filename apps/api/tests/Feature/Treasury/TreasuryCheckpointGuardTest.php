<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded;
use App\Modules\Treasury\Domain\Exceptions\RepositoryCheckpointException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

final class TreasuryCheckpointGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_schema_has_dedicated_behind_checkpoint_flag(): void
    {
        $this->assertTrue(Schema::hasColumn('repository_movements', 'recorded_behind_checkpoint'));
    }

    public function test_record_rejects_period_end_boundary_but_exact_replay_wins(): void
    {
        $repository = $this->repository('100.000', '2026-07-31 23:59:59.999999');
        $sourceId = Str::uuid()->toString();
        $intent = $this->movementIntent($repository, $sourceId, CarbonImmutable::parse('2026-07-31 12:00:00'));

        $this->expectException(RepositoryCheckpointException::class);
        DB::transaction(fn () => $this->service()->record($intent));
    }

    public function test_existing_exact_record_replay_is_returned_after_checkpoint_advances(): void
    {
        $repository = $this->repository('100.000');
        $sourceId = Str::uuid()->toString();
        $intent = $this->movementIntent($repository, $sourceId, CarbonImmutable::parse('2026-07-31 12:00:00'));
        $first = DB::transaction(fn () => $this->service()->record($intent));
        PaymentRepository::query()->whereKey($repository->id)->update([
            'last_reconciled_at' => '2026-07-31 23:59:59.999999',
            'last_reconciled_balance' => '125.000',
        ]);

        $replay = DB::transaction(fn () => $this->service()->record($intent));

        $this->assertTrue($replay->wasIdempotentHit);
        $this->assertSame($first->movementId, $replay->movementId);
        $this->assertSame(1, RepositoryMovement::query()
            ->where('payment_repository_id', $repository->id)
            ->where('source_id', $sourceId)
            ->count());
    }

    public function test_projection_records_behind_checkpoint_with_separate_flag_event_and_alert(): void
    {
        Event::fake([RepositoryMovementRecorded::class]);
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
        Log::swap($logger);
        $repository = $this->repository('100.000', '2026-07-31 23:59:59.999999');
        $intent = $this->movementIntent(
            $repository,
            Str::uuid()->toString(),
            CarbonImmutable::parse('2026-07-30 10:00:00'),
            allowProjection: true,
        );

        $result = DB::transaction(fn () => $this->service()->record($intent));
        $movement = RepositoryMovement::query()->findOrFail($result->movementId);

        $this->assertTrue($movement->recorded_behind_checkpoint);
        $this->assertFalse($movement->recorded_while_frozen);
        Event::assertDispatched(
            RepositoryMovementRecorded::class,
            fn (RepositoryMovementRecorded $event): bool => $event->movementId === $movement->id
                && $event->recordedBehindCheckpoint,
        );
        $this->assertContains(
            ['level' => 'warning', 'message' => 'Treasury movement recorded behind reconciliation checkpoint'],
            $logger->records,
        );
    }

    public function test_transfer_rejects_when_occurrence_is_behind_either_repository_checkpoint(): void
    {
        $from = $this->repository('100.000', '2026-07-31 23:59:59.999999');
        $to = $this->repository('0.000');
        $intent = new TransferIntent(
            fromRepositoryId: $from->id,
            toRepositoryId: $to->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            amount: '10.000',
            currency: 'TND',
            transferGroupId: Str::uuid()->toString(),
            journalEntryId: null,
            occurredAt: CarbonImmutable::parse('2026-07-31 12:00:00'),
            createdBy: null,
            notes: null,
        );

        $this->expectException(RepositoryCheckpointException::class);
        $this->service()->transfer($intent);
    }

    public function test_existing_exact_transfer_replay_is_returned_after_checkpoints_advance(): void
    {
        $from = $this->repository('100.000');
        $to = $this->repository('0.000');
        $intent = new TransferIntent(
            fromRepositoryId: $from->id,
            toRepositoryId: $to->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            amount: '10.000',
            currency: 'TND',
            transferGroupId: Str::uuid()->toString(),
            journalEntryId: null,
            occurredAt: CarbonImmutable::parse('2026-07-31 12:00:00'),
            createdBy: null,
            notes: null,
        );
        $first = $this->service()->transfer($intent);
        PaymentRepository::query()->whereIn('id', [$from->id, $to->id])->update([
            'last_reconciled_at' => '2026-07-31 23:59:59',
            'last_reconciled_balance' => '0.000',
        ]);

        $replay = $this->service()->transfer($intent);

        $this->assertTrue($replay->outLeg->wasIdempotentHit);
        $this->assertTrue($replay->inLeg->wasIdempotentHit);
        $this->assertSame($first->outLeg->movementId, $replay->outLeg->movementId);
        $this->assertSame($first->inLeg->movementId, $replay->inLeg->movementId);
        $this->assertSame(2, RepositoryMovement::query()
            ->where('transfer_group_id', $intent->transferGroupId)
            ->count());
    }

    private function service(): TreasuryMovementServiceInterface
    {
        return app(TreasuryMovementServiceInterface::class);
    }

    private function repository(string $balance, ?string $checkpoint = null): PaymentRepository
    {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
            'balance' => $balance,
            'next_movement_ordinal' => 0,
            'last_reconciled_at' => $checkpoint,
            'last_reconciled_balance' => $checkpoint === null ? null : $balance,
        ]);
    }

    private function movementIntent(
        PaymentRepository $repository,
        string $sourceId,
        CarbonImmutable $occurredAt,
        bool $allowProjection = false,
    ): MovementIntent {
        return new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            direction: MovementDirection::In,
            amount: '25.000',
            currency: 'TND',
            sourceType: MovementSourceType::Payment,
            sourceId: $sourceId,
            idempotencyLeg: 'main',
            journalEntryId: null,
            occurredAt: $occurredAt,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: null,
            notes: null,
            allowWhileFrozen: false,
            allowBehindCheckpoint: $allowProjection,
        );
    }
}
