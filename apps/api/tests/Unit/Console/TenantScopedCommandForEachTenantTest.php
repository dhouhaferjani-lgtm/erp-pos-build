<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\LegacyMockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026-07-09 independent audit, finding N1: {@see TenantScopedCommand::forEachTenant()}'s
 * docblock claimed per-tenant failures "are aggregated by the base", but the
 * pre-fix implementation wrapped the closure call in `try { … } finally { … }`
 * with no `catch` — a `\Throwable` from one tenant's closure propagated
 * uncaught, aborting iteration for every tenant that followed it (in a
 * multi-tenant scheduled command, tenants 4..N would silently never run that
 * night).
 *
 * This proves the fixed contract directly against `forEachTenant()` (a full
 * multi-tenant feature test through a concrete scheduler command is
 * unnecessary here — this is a unit-level proof against the base class
 * itself, per the brief's "stub command" allowance): a throwing tenant is
 * caught, logged with its tenant id, and does NOT stop the remaining tenants
 * — regardless of where in iteration order the throw happens — from being
 * processed, and the aggregate exit reflects the failure.
 */
final class TenantScopedCommandForEachTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_tenant_throwing_does_not_abort_the_remaining_tenants_and_aggregate_is_failure(): void
    {
        $throwing = $this->createTenant('throwing-tenant');
        $ok1 = $this->createTenant('ok-tenant-1');
        $ok2 = $this->createTenant('ok-tenant-2');

        $processed = [];

        $logSpy = Log::spy();

        $exit = $this->probeCommand()->runForEachTenant(function (Tenant $tenant) use ($throwing, &$processed): int {
            if ($tenant->id === $throwing->id) {
                throw new RuntimeException('simulated tenant-iteration failure');
            }

            $processed[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(
            Command::FAILURE,
            $exit,
            'A tenant-iteration failure must surface a non-zero (FAILURE) aggregate exit.',
        );
        self::assertContains(
            $ok1->id,
            $processed,
            'A tenant OTHER than the throwing one must still be processed — the throw must not abort the run.',
        );
        self::assertContains(
            $ok2->id,
            $processed,
            'A tenant OTHER than the throwing one must still be processed — the throw must not abort the run.',
        );
        self::assertNotContains($throwing->id, $processed);

        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('error', [
            Mockery::type('string'),
            Mockery::on(static fn (array $context): bool => $context['tenant_id'] === $throwing->id),
        ]);
    }

    public function test_every_tenant_still_processed_when_none_throw(): void
    {
        $t1 = $this->createTenant('clean-tenant-1');
        $t2 = $this->createTenant('clean-tenant-2');

        $processed = [];

        $exit = $this->probeCommand()->runForEachTenant(function (Tenant $tenant) use (&$processed): int {
            $processed[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(Command::SUCCESS, $exit);
        self::assertContains($t1->id, $processed);
        self::assertContains($t2->id, $processed);
    }

    /**
     * 2026-08-05 (staging follow-up A7): `forEachTenant()` used to iterate
     * `Tenant::all()` UNFILTERED. A suspended tenant's database is preserved
     * but its access is cut off at request time, and an archived/pending one
     * may have no provisioned database at all — so under db-per-tenant every
     * scheduler tick fired `tenancy()->initialize()` against a missing/closed
     * database, and the resulting Throwable was logged at ERROR level by the
     * continue-on-throw handler. That is unactionable alert noise for a
     * deliberate lifecycle state.
     *
     * Contract now: only {@see TenantStatus::Active} tenants run the closure;
     * every other status is skipped with a single INFO log per tenant per run
     * and does NOT degrade the aggregate exit code.
     *
     * @return array<string, array{0: TenantStatus, 1: string}>
     */
    public static function nonActiveStatusProvider(): array
    {
        return [
            'suspended' => [TenantStatus::Suspended, 'suspended'],
            'pending' => [TenantStatus::Pending, 'pending'],
            'archived' => [TenantStatus::Archived, 'archived'],
        ];
    }

    #[DataProvider('nonActiveStatusProvider')]
    public function test_non_active_tenants_are_skipped_and_logged_at_info(TenantStatus $status, string $slugSuffix): void
    {
        $active = $this->createTenant('active-tenant-'.$slugSuffix);
        $skipped = $this->createTenant('skipped-tenant-'.$slugSuffix, $status);

        $processed = [];

        /** @var list<MessageLogged> $records */
        $records = [];
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = $message;
        });

        $exit = $this->probeCommand()->runForEachTenant(function (Tenant $tenant) use (&$processed): int {
            $processed[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(
            Command::SUCCESS,
            $exit,
            'Skipping a non-active tenant is a deliberate no-op, not a failure — the aggregate must stay SUCCESS.',
        );
        self::assertSame([$active->id], $processed);

        $skipLines = array_values(array_filter(
            $records,
            static fn (MessageLogged $m): bool => ($m->context['tenant_id'] ?? null) === $skipped->id,
        ));

        self::assertCount(
            1,
            $skipLines,
            'A skipped tenant must produce exactly ONE log line per run — not one per scheduler retry loop.',
        );
        self::assertSame('info', $skipLines[0]->level, 'A deliberate lifecycle state is not an error.');
        self::assertSame($status->value, $skipLines[0]->context['tenant_status']);

        self::assertSame(
            [],
            array_values(array_filter($records, static fn (MessageLogged $m): bool => $m->level === 'error')),
            'Skipping a non-active tenant must NOT go through the continue-on-throw ERROR path.',
        );
    }

    public function test_non_active_tenant_never_initializes_tenancy_under_db_per_tenant(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $skipped = $this->createTenant('never-initialized', TenantStatus::Suspended);

        $seen = [];

        $exit = $this->probeCommand()->runForEachTenant(function (Tenant $tenant) use (&$seen): int {
            $seen[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotContains($skipped->id, $seen);
        self::assertFalse(tenancy()->initialized);
    }

    private function createTenant(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => $status,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function probeCommand(): TenantScopedCommandForEachTenantProbeCommand
    {
        return new TenantScopedCommandForEachTenantProbeCommand(app(CompanyContext::class));
    }
}

final class TenantScopedCommandForEachTenantProbeCommand extends TenantScopedCommand
{
    protected function executeCommand(): int
    {
        return self::SUCCESS;
    }

    /**
     * @param  callable(Tenant): int  $fn
     */
    public function runForEachTenant(callable $fn): int
    {
        return $this->forEachTenant($fn);
    }
}
