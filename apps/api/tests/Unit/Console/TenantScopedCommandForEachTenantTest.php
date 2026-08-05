<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\LegacyMockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

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
    use ProvisionsTenantDatabases;
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
     * 2026-08-05 (staging follow-up A7, REVISED after adversarial review):
     * `forEachTenant()` briefly filtered on `Tenant::isActive()`. That was the
     * wrong predicate twice over — a Pending tenant is live and transacting at
     * request time (`ResolveTenancy` / `AuthController` only reject
     * Suspended/Archived, and `tenants.status` DEFAULTS to `pending`), and a
     * Suspended tenant keeps its database because suspension is reversible, so
     * the status filter silently disabled every batch control (cash-drift
     * freeze, fiscal dead-letter recovery, batch-expiry alerts, one-time
     * backfills) on tenants that could still be opened.
     *
     * Contract now: NO status logic at all. Every directory row runs the
     * closure; only a tenant whose database cannot be opened is skipped, and
     * that probe is the same one TenancyResolver applies before initializing.
     *
     * @return array<string, array{0: TenantStatus}>
     */
    public static function tenantStatusProvider(): array
    {
        return [
            'active' => [TenantStatus::Active],
            'suspended' => [TenantStatus::Suspended],
            'pending' => [TenantStatus::Pending],
            'archived' => [TenantStatus::Archived],
        ];
    }

    #[DataProvider('tenantStatusProvider')]
    public function test_lifecycle_status_is_not_a_filter(TenantStatus $status): void
    {
        $tenant = $this->createTenant('status-'.$status->value, $status);

        $processed = [];

        /** @var list<MessageLogged> $records */
        $records = [];
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = $message;
        });

        $command = $this->probeCommand();
        $exit = $command->runForEachTenant(function (Tenant $tenant) use (&$processed): int {
            $processed[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(
            [$tenant->id],
            $processed,
            'Every tenant whose database can be opened must run the closure, whatever its lifecycle status.',
        );
        self::assertSame([$tenant->id], $command->visited());
        self::assertSame([], $command->skipped());
        self::assertSame(
            [],
            array_values(array_filter(
                $records,
                static fn (MessageLogged $m): bool => ($m->context['tenant_id'] ?? null) === $tenant->id,
            )),
            'An openable tenant must not be logged as skipped, whatever its status.',
        );
    }

    /**
     * The real failure mode the skip exists for: the per-tenant database is
     * missing, so `tenancy()->initialize()` would throw on every scheduler tick
     * and the continue-on-throw handler would emit an unactionable ERROR. The
     * probe mirrors TenancyResolver's `databaseExists()` pre-check.
     *
     * Under the suite's SQLite compat driver a tenant database is a file under
     * `database_path()`, so a tenant created without one is exactly the
     * "directory row with no database" case (archived / failed provisioning /
     * interrupted compensate()).
     */
    #[DataProvider('tenantStatusProvider')]
    public function test_tenant_without_a_provisioned_database_is_skipped_with_a_warning(TenantStatus $status): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        // 2026-08-05 re-gate, N2: the OPENABLE tenant carries the parameterised
        // status too. Without it the db-per-tenant leg only ever exercised an
        // Active openable tenant, so a reintroduced `! $tenant->isActive()`
        // filter under db_per_tenant would have left this suite green.
        $openable = $this->createTenantWithDatabase('has-db-'.$status->value, $status);
        $missing = $this->createTenant('no-db-'.$status->value, $status);

        $processed = [];

        /** @var list<MessageLogged> $records */
        $records = [];
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = $message;
        });

        $command = $this->probeCommand();
        $exit = $command->runForEachTenant(function (Tenant $tenant) use (&$processed): int {
            $processed[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(
            Command::SUCCESS,
            $exit,
            'A tenant with no database is skipped, not failed — the aggregate exit must stay SUCCESS.',
        );
        self::assertSame(
            [$openable->id],
            $processed,
            'The tenant WITH a database must still be processed regardless of its lifecycle status; the one without must not.',
        );
        self::assertSame([$openable->id], $command->visited());
        self::assertSame([$missing->id], $command->skipped());

        $skipLines = array_values(array_filter(
            $records,
            static fn (MessageLogged $m): bool => ($m->context['tenant_id'] ?? null) === $missing->id,
        ));

        self::assertCount(
            1,
            $skipLines,
            'A skipped tenant must produce exactly ONE log line per run.',
        );
        self::assertSame(
            'warning',
            $skipLines[0]->level,
            'A tenant row with no database is genuinely broken — warning, not info (and not the ERROR alert-noise path).',
        );
        self::assertSame($status->value, $skipLines[0]->context['tenant_status']);

        self::assertSame(
            [],
            array_values(array_filter($records, static fn (MessageLogged $m): bool => $m->level === 'error')),
            'The skip must NOT go through the continue-on-throw ERROR path.',
        );
    }

    public function test_tenant_without_a_database_never_initializes_tenancy(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $skipped = $this->createTenant('never-initialized');

        $seen = [];

        $exit = $this->probeCommand()->runForEachTenant(function (Tenant $tenant) use (&$seen): int {
            $seen[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotContains($skipped->id, $seen);
    }

    /**
     * 2026-08-05 re-gate, N1: the probe cannot distinguish "pg_database says
     * no" from "I could not ask". Two reachable global faults (central
     * connection lost after `Tenant::all()` materialised the directory; no
     * database manager registered for the driver) make `databaseExists()`
     * THROW for every tenant. Treating that as "database missing" skipped 100%
     * of the fleet at WARNING with exit 0 — so every `onFailure()` ops hook
     * wired to these scheduler entries stayed silent while cash-drift freeze,
     * fiscal dead-letter recovery and batch-expiry alerts went dark.
     *
     * Contract now mirrors `TenancyResolver::initializeIfProvisioned()`,
     * which fails CLOSED on a throwing probe under db-per-tenant: probe
     * RETURNS false ⇒ skip + WARNING; probe THROWS ⇒ that tenant is a FAILURE
     * (ERROR log, non-zero aggregate exit).
     *
     * The fault is injected the same way the real one arrives: no database
     * manager registered for the connection's driver, which is exactly what
     * `DatabaseConfig::manager()` raises `DatabaseManagerNotRegisteredException`
     * for.
     */
    public function test_a_throwing_database_probe_is_a_failure_not_a_silent_skip(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenant = $this->createTenantWithDatabase('probe-throws');

        config(['tenancy.database.managers' => []]);

        $processed = [];

        /** @var list<MessageLogged> $records */
        $records = [];
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = $message;
        });

        $command = $this->probeCommand();
        $exit = $command->runForEachTenant(function (Tenant $tenant) use (&$processed): int {
            $processed[] = $tenant->id;

            return Command::SUCCESS;
        });

        self::assertSame(
            Command::FAILURE,
            $exit,
            'A probe that THROWS is an infra fault, not evidence of a missing database — the aggregate exit must be non-zero.',
        );
        self::assertSame([], $processed, 'The closure must not run for a tenant whose database could not be probed.');
        self::assertSame([], $command->visited());
        self::assertSame(
            [$tenant->id],
            $command->skipped(),
            'A tenant whose probe threw was not processed, so a --tenant filter targeting it must be able to report it.',
        );

        $lines = array_values(array_filter(
            $records,
            static fn (MessageLogged $m): bool => ($m->context['tenant_id'] ?? null) === $tenant->id,
        ));

        self::assertCount(1, $lines, 'A probe fault must produce exactly ONE log line per tenant per run.');
        self::assertSame(
            'error',
            $lines[0]->level,
            'A probe fault is an alertable infra fault — error, not the warning-only skip path.',
        );
    }

    public function test_a_throwing_database_probe_makes_a_tenant_filter_fail_loudly(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenant = $this->createTenantWithDatabase('probe-throws-filter');

        config(['tenancy.database.managers' => []]);

        $buffer = new BufferedOutput;
        $command = $this->probeCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $command->runForEachTenant(static fn (Tenant $tenant): int => Command::SUCCESS);

        self::assertSame(
            Command::FAILURE,
            $command->checkTenantFilter($tenant->id),
            'An operator who targeted the tenant whose probe threw must not be told the run succeeded.',
        );
        self::assertStringContainsString($tenant->id, $buffer->fetch());
    }

    /**
     * M3 (2026-08-05 wave-1 review). A central outage AFTER `Tenant::all()`
     * materialises makes the probe throw for EVERY tenant, so the per-tenant
     * ERROR above became N error lines + N Sentry events per tick, per
     * scheduled command — the alert channel drowns exactly when it is needed.
     *
     * The first few tenants carry the actionable detail; beyond that a single
     * aggregate line reports the totals. The failure itself is unchanged: every
     * tenant is still skipped, still non-zero, still visible to
     * `failIfTenantFilterUnvisited()`.
     */
    public function test_a_fleet_wide_probe_fault_is_logged_first_n_then_aggregated_once(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenants = [];
        for ($i = 0; $i < 6; $i++) {
            $tenants[] = $this->createTenant('probe-storm-'.$i);
        }

        config(['tenancy.database.managers' => []]);

        /** @var list<MessageLogged> $records */
        $records = [];
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = $message;
        });

        $command = $this->probeCommand();
        $exit = $command->runForEachTenant(static fn (Tenant $tenant): int => Command::SUCCESS);

        self::assertSame(Command::FAILURE, $exit);
        self::assertCount(
            6,
            $command->skipped(),
            'Log throttling must not change WHICH tenants are recorded as unprocessed.',
        );

        $perTenant = array_values(array_filter(
            $records,
            static fn (MessageLogged $m): bool => isset($m->context['tenant_id']),
        ));
        $aggregate = array_values(array_filter(
            $records,
            static fn (MessageLogged $m): bool => ! isset($m->context['tenant_id']),
        ));

        self::assertCount(3, $perTenant, 'Only the first few tenants carry a per-tenant line.');
        self::assertCount(1, $aggregate, 'The rest collapse into exactly one aggregate line.');
        self::assertSame('error', $aggregate[0]->level);
        self::assertSame(6, $aggregate[0]->context['tenants_failed_probe']);
        self::assertSame(3, $aggregate[0]->context['suppressed_log_lines']);
    }

    /**
     * The visited/skipped surface is what lets a `--tenant`-filtering command
     * fail loudly instead of exiting SUCCESS having done nothing.
     */
    public function test_fail_if_tenant_filter_unvisited_distinguishes_skipped_from_unknown(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $openable = $this->createTenantWithDatabase('filter-openable');
        $missing = $this->createTenant('filter-missing');

        $buffer = new BufferedOutput;
        $command = $this->probeCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $command->runForEachTenant(static fn (Tenant $tenant): int => Command::SUCCESS);

        self::assertNull(
            $command->checkTenantFilter(null),
            'No --tenant filter means nothing to report.',
        );
        self::assertNull(
            $command->checkTenantFilter($openable->id),
            'A visited tenant must not be reported as missed.',
        );
        self::assertSame(
            Command::FAILURE,
            $command->checkTenantFilter($missing->id),
            'A --tenant that the database probe skipped must fail with a non-zero exit.',
        );
        self::assertSame(
            Command::INVALID,
            $command->checkTenantFilter('11111111-1111-4111-8111-111111111111'),
            'A --tenant that is not in the directory at all must fail with a non-zero exit.',
        );

        $stderr = $buffer->fetch();
        self::assertStringContainsString($missing->id, $stderr, 'The skipped tenant must be named on stderr.');
        self::assertStringContainsString('11111111-1111-4111-8111-111111111111', $stderr);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
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

    /**
     * Create a tenant whose per-tenant database actually exists. Delegates to
     * {@see ProvisionsTenantDatabases} — the shared helper — rather than
     * re-implementing the "a tenant database is a file under database_path()"
     * assumption a second time (2026-08-05 re-gate, N5).
     */
    private function createTenantWithDatabase(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return $this->provisionTenantDatabase($this->createTenant($slug, $status));
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

    /**
     * @return list<string>
     */
    public function visited(): array
    {
        return $this->visitedTenantIds();
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skippedTenantIds();
    }

    public function checkTenantFilter(?string $tenantFilter): ?int
    {
        return $this->failIfTenantFilterUnvisited($tenantFilter);
    }
}
