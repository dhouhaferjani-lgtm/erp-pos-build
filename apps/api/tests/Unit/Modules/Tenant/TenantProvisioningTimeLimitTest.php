<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Tenant;

use App\Modules\Tenant\Application\Contracts\ExecutionTimeLimit;
use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Application\Services\TenantProvisioningService;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\TenantTokenRevoker;
use Closure;
use Error;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Testing\Fakes\BusFake;
use PHPUnit\Framework\Assert;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

final class TenantProvisioningTimeLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database-per-tenant provisioning compensation is PostgreSQL-only.');
        }
    }

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        // Compensation deletes central rows through the named `central`
        // connection. A transaction on the default connection would retain
        // locks on the just-created rows and deadlock that cross-connection
        // cleanup, so these PG-only tests let each small fixture commit.
        return [];
    }

    public function test_time_limit_is_raised_to_240_before_tenant_migration_is_dispatched(): void
    {
        $executionTimeLimit = new ExecutionTimeLimitSpy;
        $this->installFailingMigrationBus();

        try {
            $this->service($executionTimeLimit)->provisionForRegistration(
                $this->registrationData('limit-before-migration@example.com', 'Limit Before Migration'),
                null,
                [],
                null,
            );
        } catch (\Throwable) {
            // The fake records both synchronous dispatches, then makes the
            // MigrateDatabase dispatch fail before any real migration runs.
        }

        Bus::assertDispatchedSync(CreateDatabase::class);
        Bus::assertDispatchedSync(MigrateDatabase::class);
        self::assertSame([240], $executionTimeLimit->limits);
        self::assertFalse($executionTimeLimit->migrationWasDispatchedWhenLimitWasSet);
    }

    public function test_container_reuses_the_process_execution_time_limit_adapter(): void
    {
        self::assertSame(
            $this->app->make(ExecutionTimeLimit::class),
            $this->app->make(ExecutionTimeLimit::class),
        );
    }

    public function test_throwable_from_tenant_migration_compensates_the_orphaned_tenant(): void
    {
        $executionTimeLimit = new ExecutionTimeLimitSpy;
        $tokenRevoker = new TenantTokenRevokerSpy;
        $this->installFailingMigrationBus();

        try {
            $this->service($executionTimeLimit, $tokenRevoker)->provisionForRegistration(
                $this->registrationData('migration-error@example.com', 'Migration Error Tenant'),
                null,
                [],
                null,
            );
            self::fail('The fake MigrateDatabase dispatch must throw.');
        } catch (Error $error) {
            self::assertSame('simulated migration error', $error->getMessage());
        }

        self::assertSame(1, $tokenRevoker->calls);
        $this->assertDatabaseMissing('tenants', ['name' => 'Migration Error Tenant'], 'central');
    }

    public function test_shutdown_handler_compensates_after_database_creation_without_double_cleanup(): void
    {
        $executionTimeLimit = new ExecutionTimeLimitSpy;
        $tokenRevoker = new TenantTokenRevokerSpy;
        $this->installFailingMigrationBus($executionTimeLimit->invokeShutdown(...));

        try {
            $this->service($executionTimeLimit, $tokenRevoker)->provisionForRegistration(
                $this->registrationData('fatal-shutdown@example.com', 'Fatal Shutdown Tenant'),
                null,
                [],
                null,
            );
            self::fail('The fake MigrateDatabase dispatch must throw after invoking shutdown.');
        } catch (Error $error) {
            self::assertSame('simulated migration error', $error->getMessage());
        }

        self::assertSame(1, $executionTimeLimit->shutdownInvocations);
        self::assertSame(1, $tokenRevoker->calls);
        self::assertFalse($executionTimeLimit->hasPendingShutdownHandler());
        $this->assertDatabaseMissing('tenants', ['name' => 'Fatal Shutdown Tenant'], 'central');
    }

    public function test_two_sequential_provisionings_register_once_and_leave_no_pending_compensation(): void
    {
        $executionTimeLimit = new ExecutionTimeLimitSpy;
        $tokenRevoker = new TenantTokenRevokerSpy;
        $service = $this->service($executionTimeLimit, $tokenRevoker);

        foreach ([
            ['first-worker-request@example.com', 'First Worker Request'],
            ['second-worker-request@example.com', 'Second Worker Request'],
        ] as [$email, $companyName]) {
            $this->installFailingMigrationBus();

            try {
                $service->provisionForRegistration(
                    $this->registrationData($email, $companyName),
                    null,
                    [],
                    null,
                );
                self::fail('The fake MigrateDatabase dispatch must throw.');
            } catch (Error $error) {
                self::assertSame('simulated migration error', $error->getMessage());
            }
        }

        self::assertSame(1, $executionTimeLimit->shutdownRegistrations);
        self::assertSame(2, $tokenRevoker->calls);
        self::assertFalse($executionTimeLimit->hasPendingShutdownHandler());

        $executionTimeLimit->invokeShutdown();
        self::assertSame(2, $tokenRevoker->calls);
    }

    private function service(
        ExecutionTimeLimit $executionTimeLimit,
        ?TenantTokenRevoker $tokenRevoker = null,
    ): TenantProvisioningService {
        return new TenantProvisioningService(
            $this->createMock(TenantInitializationService::class),
            $this->createMock(IdentityIndexService::class),
            $tokenRevoker ?? new TenantTokenRevokerSpy,
            $executionTimeLimit,
        );
    }

    private function installFailingMigrationBus(?Closure $beforeFailure = null): void
    {
        $baseFake = Bus::fake();
        $dispatcher = $baseFake->dispatcher;
        self::assertInstanceOf(QueueingDispatcher::class, $dispatcher);

        Bus::swap(new FailingMigrateBusFake($dispatcher, $beforeFailure));
    }

    /** @return array<string, mixed> */
    private function registrationData(string $email, string $companyName): array
    {
        return [
            'name' => 'Registration Owner',
            'email' => $email,
            'password' => 'MyStr0ng!Pass',
            'company_name' => $companyName,
            'country_code' => 'FR',
            'vertical' => 'retail',
        ];
    }
}

final class ExecutionTimeLimitSpy implements ExecutionTimeLimit
{
    /** @var list<int> */
    public array $limits = [];

    public bool $migrationWasDispatchedWhenLimitWasSet = false;

    public int $shutdownInvocations = 0;

    public int $shutdownRegistrations = 0;

    private ?Closure $pendingShutdownHandler = null;

    private ?Closure $processShutdownHandler = null;

    public function setTimeLimit(int $seconds): void
    {
        $this->limits[] = $seconds;
        $this->migrationWasDispatchedWhenLimitWasSet = Bus::dispatchedSync(MigrateDatabase::class)->isNotEmpty();
    }

    public function registerShutdownHandler(Closure $handler): void
    {
        $this->pendingShutdownHandler = $handler;

        if ($this->processShutdownHandler !== null) {
            return;
        }

        $this->shutdownRegistrations++;
        $this->processShutdownHandler = function (): void {
            $handler = $this->pendingShutdownHandler;
            $this->pendingShutdownHandler = null;
            $handler?->__invoke();
        };
    }

    public function clear(): void
    {
        $this->pendingShutdownHandler = null;
    }

    public function hasPendingShutdownHandler(): bool
    {
        return $this->pendingShutdownHandler !== null;
    }

    public function invokeShutdown(): void
    {
        Assert::assertNotNull($this->processShutdownHandler);
        $this->shutdownInvocations++;
        ($this->processShutdownHandler)();
    }
}

final class TenantTokenRevokerSpy extends TenantTokenRevoker
{
    public int $calls = 0;

    public function __construct() {}

    public function revokeTenantTokens(Tenant $tenant): void
    {
        $this->calls++;
    }
}

final class FailingMigrateBusFake extends BusFake
{
    public function __construct(
        QueueingDispatcher $dispatcher,
        private readonly ?Closure $beforeFailure,
    ) {
        parent::__construct($dispatcher);
    }

    /** @param mixed $command */
    public function dispatchSync($command, $handler = null): mixed
    {
        $result = parent::dispatchSync($command, $handler);

        if ($command instanceof MigrateDatabase) {
            ($this->beforeFailure ?? static fn (): null => null)();

            throw new Error('simulated migration error');
        }

        return $result;
    }
}
