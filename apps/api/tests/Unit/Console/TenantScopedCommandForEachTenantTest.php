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
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\LegacyMockInterface;
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

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
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
