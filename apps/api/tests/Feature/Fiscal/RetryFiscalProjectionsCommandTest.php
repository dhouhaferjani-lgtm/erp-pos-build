<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Modules\Fiscal\Infrastructure\Commands\RetryFiscalProjectionsCommand;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RetryFiscalProjectionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_command_uses_tenant_scoped_iteration_base(): void
    {
        $command = $this->app->make(RetryFiscalProjectionsCommand::class);

        $this->assertInstanceOf(TenantScopedCommand::class, $command);
    }

    public function test_dead_lettered_projection_can_be_reset_and_re_driven_synchronously(): void
    {
        $projector = new RetryCommandNoopProjector;
        $this->registerProjectors([$projector]);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $event = $this->storeFiscalEvent($tenant->id, $company->id, $operator->id);
        $row = $this->projectionRow($event, $projector->name(), ProjectionStatus::DeadLettered, 5);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('fiscal:retry-projections', [
            '--event-id' => $event->id,
            '--projector' => $projector->name(),
            '--sync' => true,
        ]);
        $this->assertSame(0, $exitCode);

        $fresh = FiscalEventProjectionRow::query()->findOrFail($row->id);
        $this->assertSame(ProjectionStatus::Applied, $fresh->projection_status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->dead_lettered_at);
        $this->assertNotNull($fresh->applied_at);
        $this->assertSame(1, $projector->applyCount);
    }

    public function test_command_retries_projection_rows_for_each_tenant(): void
    {
        Queue::fake();

        $projector = new RetryCommandNoopProjector;
        $this->registerProjectors([$projector]);

        $firstTenant = Tenant::factory()->create();
        $firstCompany = Company::factory()->create(['tenant_id' => $firstTenant->id]);
        $firstOperator = User::factory()->create(['tenant_id' => $firstTenant->id]);
        $firstEvent = $this->storeFiscalEvent($firstTenant->id, $firstCompany->id, $firstOperator->id);
        $firstRow = $this->projectionRow($firstEvent, $projector->name(), ProjectionStatus::DeadLettered, 5);

        $secondTenant = Tenant::factory()->create();
        $secondCompany = Company::factory()->create(['tenant_id' => $secondTenant->id]);
        $secondOperator = User::factory()->create(['tenant_id' => $secondTenant->id]);
        $secondEvent = $this->storeFiscalEvent($secondTenant->id, $secondCompany->id, $secondOperator->id);
        $secondRow = $this->projectionRow($secondEvent, $projector->name(), ProjectionStatus::DeadLettered, 5);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('fiscal:retry-projections', [
            '--projector' => $projector->name(),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(ProjectionStatus::Pending, $firstRow->refresh()->projection_status);
        $this->assertSame(0, $firstRow->attempts);
        $this->assertSame(ProjectionStatus::Pending, $secondRow->refresh()->projection_status);
        $this->assertSame(0, $secondRow->attempts);
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 2);
    }

    /**
     * B1 closure (2026-08-05 adversarial review): `--tenant` on this command is
     * a filter applied INSIDE the `forEachTenant()` closure, so a tenant the
     * iteration never reached used to produce exit 0 + "No retryable rows
     * matched" — an operator repairing dead-lettered fiscal projections got
     * SUCCESS having done nothing. Both miss modes must now be loud.
     */
    public function test_unknown_tenant_filter_fails_loudly(): void
    {
        Tenant::factory()->create();

        $this->artisan('fiscal:retry-projections', [
            '--tenant' => '11111111-1111-4111-8111-111111111111',
        ])
            ->expectsOutputToContain('was not found in the central tenant directory')
            ->assertExitCode(Command::INVALID);
    }

    public function test_tenant_filter_skipped_by_the_database_probe_fails_loudly(): void
    {
        // db-per-tenant mode with no provisioned per-tenant database: the
        // tenant exists in the directory but forEachTenant()'s probe skips it.
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenant = Tenant::factory()->create();

        $this->artisan('fiscal:retry-projections', [
            '--tenant' => $tenant->id,
        ])
            ->expectsOutputToContain('per-tenant database does not exist')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_exhausted_pending_projection_can_be_reset(): void
    {
        Queue::fake();

        $projector = new RetryCommandNoopProjector;
        $this->registerProjectors([$projector]);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $event = $this->storeFiscalEvent($tenant->id, $company->id, $operator->id);
        $row = $this->projectionRow($event, $projector->name(), ProjectionStatus::Pending, 5);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('fiscal:retry-projections', [
            '--event-id' => $event->id,
            '--projector' => $projector->name(),
        ]);

        $this->assertSame(0, $exitCode);
        $fresh = $row->refresh();
        $this->assertSame(ProjectionStatus::Pending, $fresh->projection_status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
        Queue::assertPushed(ApplyFiscalEventProjectionJob::class, 1);
    }

    public function test_async_retry_dispatches_projection_job_to_fiscal_projections_queue(): void
    {
        Queue::fake();

        $projector = new RetryCommandNoopProjector;
        $this->registerProjectors([$projector]);

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $event = $this->storeFiscalEvent($tenant->id, $company->id, $operator->id);
        $row = $this->projectionRow($event, $projector->name(), ProjectionStatus::DeadLettered, 5);

        $this->withoutMockingConsoleOutput();
        $exitCode = $this->artisan('fiscal:retry-projections', [
            '--event-id' => $event->id,
            '--projector' => $projector->name(),
        ]);

        $this->assertSame(0, $exitCode);
        Queue::assertPushed(
            ApplyFiscalEventProjectionJob::class,
            static fn (ApplyFiscalEventProjectionJob $job): bool => $job->projectionRowId === $row->id
                && $job->queue === 'fiscal-projections',
        );
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
                new RetryCommandAlwaysActiveResolver,
            ),
        );
    }

    private function storeFiscalEvent(string $tenantId, string $companyId, string $operatorId): FiscalEvent
    {
        $payload = ['test' => 'retry-projections'];

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $operatorId,
            'event_type' => FiscalEventType::ACCOUNT_PAYMENT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 17,
            'event_time_device' => '2026-07-07 10:15:30',
            'business_date' => '2026-07-07',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-07-07 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'retry_projection_test',
            'source_event_id' => null,
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

    private function projectionRow(
        FiscalEvent $event,
        string $projectorName,
        ProjectionStatus $status,
        int $attempts,
    ): FiscalEventProjectionRow {
        $now = Carbon::now('UTC')->subMinutes(20);
        $id = Str::uuid()->toString();

        DB::table('fiscal_event_projections')->insert([
            'id' => $id,
            'fiscal_event_id' => $event->id,
            'projector_name' => $projectorName,
            'projection_status' => $status->value,
            'attempts' => $attempts,
            'last_error' => 'payment_repository_missing_account_id',
            'last_attempted_at' => $now,
            'dead_lettered_at' => $status === ProjectionStatus::DeadLettered ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return FiscalEventProjectionRow::query()->findOrFail($id);
    }
}

final class RetryCommandNoopProjector implements FiscalEventProjector
{
    public int $applyCount = 0;

    public function name(): string
    {
        return 'retry_command_noop_projector';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::ACCOUNT_PAYMENT;
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        unset($event);

        $this->applyCount++;
    }

    public function priority(): int
    {
        return 150;
    }
}

final class RetryCommandAlwaysActiveResolver implements ModuleActivationResolver
{
    public function isActive(string $module, string $tenantId, string $companyId): bool
    {
        unset($module, $tenantId, $companyId);

        return true;
    }
}
