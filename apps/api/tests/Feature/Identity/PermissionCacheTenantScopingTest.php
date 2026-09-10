<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

final class PermissionCacheTenantScopingTest extends TestCase
{
    use ProvisionsTenantDatabases;

    /** @var list<Tenant> */
    private array $tenants = [];

    private PermissionRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Permission cache lifecycle requires the PostgreSQL PG lane.');
        }
        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        config([
            'permission.cache.key' => 'spatie.permission.cache',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'central',
            'queue.connections.database.central' => false,
        ]);
        PermissionCacheProbeJob::$observedKeys = [];
        $this->registrar = app(PermissionRegistrar::class);
        $this->registrar->initializeCache();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            parent::tearDown();

            return;
        }

        DB::connection('central')->table('jobs')
            ->where('payload', 'like', '%PermissionCacheProbeJob%')
            ->delete();
        foreach ($this->tenants as $tenant) {
            DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        parent::tearDown();
    }

    private function tenant(string $prefix): Tenant
    {
        $tenant = Tenant::factory()->create([
            'slug' => $prefix.'-'.Str::lower(Str::random(10)),
        ]);
        $this->tenants[] = $tenant;

        return $tenant;
    }

    #[Group('pg')]
    public function test_resolver_keeps_compatibility_shared_and_rekeys_two_provisioned_tenants(): void
    {
        $resolver = app(TenancyResolver::class);
        $compatibilityTenant = $this->tenant('permission-compat');

        config(['tenancy_resolver.db_per_tenant' => false]);
        self::assertFalse($resolver->initializeIfProvisioned($compatibilityTenant));
        self::assertFalse(tenancy()->initialized);
        self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);

        config(['tenancy_resolver.db_per_tenant' => true]);
        $tenantA = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-http-a'));
        $tenantB = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-http-b'));

        self::assertTrue($resolver->initializeIfProvisioned($tenantA));
        self::assertSame('spatie.permission.cache.'.$tenantA->id, $this->registrar->cacheKey);
        tenancy()->end();
        self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);

        self::assertTrue($resolver->initializeIfProvisioned($tenantB));
        self::assertSame('spatie.permission.cache.'.$tenantB->id, $this->registrar->cacheKey);
        tenancy()->end();
        self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);
    }

    #[Group('pg')]
    public function test_database_queue_processes_two_tenant_payloads_then_restores_central_key(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);
        $resolver = app(TenancyResolver::class);
        $tenantA = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-queue-a'));
        $tenantB = $this->provisionTenantDatabaseWithSchema($this->tenant('permission-queue-b'));

        foreach ([['a', $tenantA], ['b', $tenantB]] as [$label, $tenant]) {
            self::assertTrue($resolver->initializeIfProvisioned($tenant));
            $pending = dispatch(new PermissionCacheProbeJob($label));
            unset($pending); // deterministically run PendingDispatch::__destruct() before ending tenancy
            tenancy()->end();
            self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);
            fwrite(STDERR, 'After dispatch '.$label.' tenancy end: '.$this->registrar->cacheKey.PHP_EOL);
        }

        $payloads = DB::connection('central')->table('jobs')->orderBy('id')->pluck('payload');
        self::assertCount(2, $payloads);
        self::assertSame(
            [$tenantA->id, $tenantB->id],
            $payloads->map(static function (string $payload): string {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return (string) $decoded['tenant_id'];
            })->all(),
        );

        // WorkCommand's installed signature is `queue:work {connection?}`;
        // `connection` is positional, while `--once` is the real option.
        $worker = $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);
        self::assertInstanceOf(PendingCommand::class, $worker);
        $worker->assertExitCode(0);
        $worker->run();
        self::assertFalse(tenancy()->initialized);
        self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);
        fwrite(STDERR, 'After queue worker: '.$this->registrar->cacheKey.PHP_EOL);
        $worker = $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);
        self::assertInstanceOf(PendingCommand::class, $worker);
        $worker->assertExitCode(0);
        $worker->run();
        self::assertFalse(tenancy()->initialized);
        self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);
        fwrite(STDERR, 'After queue worker: '.$this->registrar->cacheKey.PHP_EOL);

        self::assertSame([
            'a' => 'spatie.permission.cache.'.$tenantA->id,
            'b' => 'spatie.permission.cache.'.$tenantB->id,
        ], PermissionCacheProbeJob::$observedKeys);
        self::assertFalse(tenancy()->initialized);
        self::assertSame('spatie.permission.cache', $this->registrar->cacheKey);
    }
}

final class PermissionCacheProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var array<string, string> */
    public static array $observedKeys = [];

    public function __construct(private readonly string $label) {}

    public function handle(PermissionRegistrar $registrar): void
    {
        self::$observedKeys[$this->label] = $registrar->cacheKey;
        fwrite(STDERR, 'Job '.$this->label.': '.$registrar->cacheKey.PHP_EOL);
    }
}
