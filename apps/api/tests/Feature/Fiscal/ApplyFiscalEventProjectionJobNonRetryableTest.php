<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\RefundQuantityExceededException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §4.2/§17 — `NonRetryableProjectionException`
 * classification in `ApplyFiscalEventProjectionJob`.
 *
 * Proves the classification actually CHANGES BEHAVIOR (not merely that the
 * exception is correctly typed): after one throw, `attempts === 1` (not
 * exhausted to 5), `projection_status === DeadLettered` on the FIRST job
 * execution, and a second `handle()` call on the same row never re-invokes
 * the projector — the terminal-state short-circuit (pre-existing, kept
 * unchanged) is what "no second Horizon attempt is dispatched" cashes out
 * to when driving the job synchronously outside a real queue worker.
 */
final class ApplyFiscalEventProjectionJobNonRetryableTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_retryable_exception_dead_letters_immediately_without_exhausting_retries(): void
    {
        $projector = new ThrowingNonRetryableFakeProjector;
        $this->registerProjectors([$projector]);

        $event = $this->storeFiscalEvent();
        $row = $this->projectionRow($event, $projector->name());

        /** @var ApplyFiscalEventProjectionJob $job */
        $job = new ApplyFiscalEventProjectionJob($row->id);
        $job->handle(app(ConnectionInterface::class), app(FiscalEventProjectionRegistry::class));

        $fresh = FiscalEventProjectionRow::query()->findOrFail($row->id);
        self::assertSame(ProjectionStatus::DeadLettered, $fresh->projection_status);
        self::assertSame(1, $fresh->attempts, 'attempts must be exactly 1 — the exception must never be retried to exhaustion (5).');
        self::assertNotNull($fresh->dead_lettered_at);
        self::assertNotNull($fresh->last_error);
        self::assertStringContainsString('RefundQuantityExceeded', (string) $fresh->last_error);
        self::assertSame(1, $projector->applyCount, 'the projector must have been invoked exactly once on the first execution.');

        // A second handle() call on the SAME row — proxy for "no second
        // Horizon attempt is dispatched": the terminal-state short-circuit
        // (kept unchanged) means a re-delivery never re-enters T_apply, so
        // the projector is never invoked a second time and attempts never
        // advances past 1.
        $job2 = new ApplyFiscalEventProjectionJob($row->id);
        $job2->handle(app(ConnectionInterface::class), app(FiscalEventProjectionRegistry::class));

        $fresh2 = FiscalEventProjectionRow::query()->findOrFail($row->id);
        self::assertSame(1, $fresh2->attempts, 'a second handle() call on an already-DeadLettered row must be a no-op.');
        self::assertSame(1, $projector->applyCount, 'the projector must NEVER be invoked a second time for a non-retryable exception.');
    }

    public function test_a_generic_throwable_still_retries_normally_regression(): void
    {
        // Regression: an ORDINARY (retryable) projector failure must keep
        // its existing behavior — attempts advances, status resets to
        // Pending (not DeadLettered), unaffected by the new catch branch.
        $projector = new ThrowingGenericFakeProjector;
        $this->registerProjectors([$projector]);

        $event = $this->storeFiscalEvent();
        $row = $this->projectionRow($event, $projector->name());

        $job = new ApplyFiscalEventProjectionJob($row->id);

        try {
            $job->handle(app(ConnectionInterface::class), app(FiscalEventProjectionRegistry::class));
            self::fail('Expected the generic throwable to propagate for Horizon retry.');
        } catch (\RuntimeException) {
            // expected — the retryable path re-throws.
        }

        $fresh = FiscalEventProjectionRow::query()->findOrFail($row->id);
        self::assertSame(ProjectionStatus::Pending, $fresh->projection_status);
        self::assertSame(1, $fresh->attempts);
        self::assertNull($fresh->dead_lettered_at);
    }

    /**
     * @param  list<FiscalEventProjector>  $projectors
     */
    private function registerProjectors(array $projectors): void
    {
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            fn (): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                $projectors,
                new NonRetryableTestAlwaysActiveResolver,
            ),
        );
    }

    private function storeFiscalEvent(): FiscalEvent
    {
        $payload = ['test' => 'non-retryable-job'];

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => Str::uuid()->toString(),
            'company_id' => Str::uuid()->toString(),
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => Str::uuid()->toString(),
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 4,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => '2026-07-31 10:15:30',
            'business_date' => '2026-07-31',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-07-31 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            // fiscal re-verification (CI PG-filter expansion) —
            // `fiscal_events_source_event_paired_null` CHECK requires
            // BOTH null or BOTH non-null (PG-only; SQLite never enforced
            // it). No assertion in this file reads source_event_id's
            // value.
            'source_event_class' => 'refund_intents',
            'source_event_id' => Str::uuid()->toString(),
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    private function projectionRow(FiscalEvent $event, string $projectorName): FiscalEventProjectionRow
    {
        $id = Str::uuid()->toString();
        $now = now();

        DB::table('fiscal_event_projections')->insert([
            'id' => $id,
            'fiscal_event_id' => $event->id,
            'projector_name' => $projectorName,
            'projection_status' => ProjectionStatus::Pending->value,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'dead_lettered_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return FiscalEventProjectionRow::query()->findOrFail($id);
    }
}

final class NonRetryableTestAlwaysActiveResolver implements ModuleActivationResolver
{
    public function isActive(string $tenantId, string $companyId, string $moduleName): bool
    {
        return true;
    }
}

final class ThrowingNonRetryableFakeProjector implements FiscalEventProjector
{
    public int $applyCount = 0;

    public function name(): string
    {
        return 'throwing_non_retryable_fake_projector';
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
        $this->applyCount++;

        throw new RefundQuantityExceededException(
            fiscalEventId: $event->id,
            originalLineId: Str::uuid()->toString(),
            originalQuantity: '1.0000',
            alreadyRefundedQuantity: '1.0000',
            requestedQuantity: '1.0000',
        );
    }

    public function priority(): int
    {
        return 50;
    }
}

final class ThrowingGenericFakeProjector implements FiscalEventProjector
{
    public function name(): string
    {
        return 'throwing_generic_fake_projector';
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
        throw new \RuntimeException('generic transient failure: '.$event->id);
    }

    public function priority(): int
    {
        return 50;
    }
}
