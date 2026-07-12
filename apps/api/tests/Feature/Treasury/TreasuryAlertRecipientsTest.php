<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Notifications\TreasuryAlertNotification;
use App\Modules\Treasury\Application\Services\TreasuryAlertRecipients;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

/**
 * Recipient resolution crosses physical tenant databases, so this suite cannot
 * use RefreshDatabase: PostgreSQL does not allow CREATE DATABASE in a transaction.
 */
final class TreasuryAlertRecipientsTest extends TestCase
{
    /** @var list<Tenant> */
    private array $tenants = [];

    private PermissionRegistrar $permissionRegistrar;

    private int|string|null $originalTeamId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy_resolver.db_per_tenant' => true]);

        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->permissionRegistrar = app(PermissionRegistrar::class);
        $this->originalTeamId = $this->permissionRegistrar->getPermissionsTeamId();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->permissionRegistrar->setPermissionsTeamId($this->originalTeamId);
        $this->permissionRegistrar->forgetCachedPermissions();

        foreach ($this->tenants as $tenant) {
            try {
                DB::purge('tenant');
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (\Throwable) {
                // Best-effort cleanup; preserve the original test failure.
            }

            DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_recipients_are_company_scoped_deny_direction(): void
    {
        $this->requirePostgres();

        $tenant = $this->provisionTenant('alert-company');
        tenancy()->initialize($tenant);

        $companyA = $this->createCompany($tenant, 'Company A');
        $companyB = $this->createCompany($tenant, 'Company B');
        $managerA = $this->createPermittedUser($tenant, 'manager-a@example.test');
        $managerB = $this->createPermittedUser($tenant, 'manager-b@example.test');
        $adminBoth = $this->createPermittedUser($tenant, 'admin-both@example.test');

        $this->createMembership($managerA, $companyA, 'active');
        $this->createMembership($managerB, $companyB, 'active');
        $this->createMembership($adminBoth, $companyA, 'active');
        $this->createMembership($adminBoth, $companyB, 'active');

        $sentinelTeamId = (string) Str::uuid();
        $this->permissionRegistrar->setPermissionsTeamId($sentinelTeamId);

        $recipients = $this->resolver()->forCompany($tenant->id, $companyA->id);

        $this->assertEqualsCanonicalizing(
            [$managerA->id, $adminBoth->id],
            $recipients->pluck('id')->all(),
        );
        $this->assertNotContains(
            $managerB->id,
            $recipients->pluck('id')->all(),
            'A manager who belongs only to company B must not receive company A data.',
        );
        $this->assertSame($sentinelTeamId, $this->permissionRegistrar->getPermissionsTeamId());
    }

    public function test_resolution_survives_multi_tenant_iteration(): void
    {
        $this->requirePostgres();

        $tenantA = $this->provisionTenant('alert-tenant-a');
        $tenantB = $this->provisionTenant('alert-tenant-b');

        tenancy()->initialize($tenantA);
        $companyA = $this->createCompany($tenantA, 'Tenant A Company');
        $managerA = $this->createPermittedUser($tenantA, 'tenant-a-manager@example.test');
        $this->createMembership($managerA, $companyA, 'active');
        $permissionAId = Permission::findByName('treasury.manage', 'sanctum')->getKey();
        $tenantARecipients = $this->resolver()->forCompany($tenantA->id, $companyA->id);
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $companyB = $this->createCompany($tenantB, 'Tenant B Company');
        $this->permissionRegistrar->forgetCachedPermissions();
        Permission::findOrCreate('tenant-b.permission-offset', 'sanctum');
        $managerB = $this->createPermittedUser($tenantB, 'tenant-b-manager@example.test');
        $this->createMembership($managerB, $companyB, 'active');
        $permissionBId = Permission::findByName('treasury.manage', 'sanctum')->getKey();
        tenancy()->end();

        // Re-prime the singleton registrar from tenant A immediately before
        // resolving tenant B. The resolver must flush this stale collection.
        tenancy()->initialize($tenantA);
        $this->permissionRegistrar->forgetCachedPermissions();
        Permission::findByName('treasury.manage', 'sanctum');
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $tenantBRecipients = $this->resolver()->forCompany($tenantB->id, $companyB->id);

        $this->assertNotSame($permissionAId, $permissionBId, 'Each tenant DB must have its own permission UUID.');
        $this->assertSame([$managerA->id], $tenantARecipients->pluck('id')->all());
        $this->assertSame(
            [$managerB->id],
            $tenantBRecipients->pluck('id')->all(),
            'The second tenant must resolve against its own permission records, not tenant A cache state.',
        );
    }

    public function test_membership_must_be_active(): void
    {
        $this->requirePostgres();

        $tenant = $this->provisionTenant('alert-active');
        tenancy()->initialize($tenant);

        $company = $this->createCompany($tenant, 'Active Membership Company');
        $activeManager = $this->createPermittedUser($tenant, 'active-manager@example.test');
        $inactiveManager = $this->createPermittedUser($tenant, 'inactive-manager@example.test');
        $this->createMembership($activeManager, $company, 'active');
        $this->createMembership($inactiveManager, $company, 'suspended');

        $recipients = $this->resolver()->forCompany($tenant->id, $company->id);

        $this->assertSame([$activeManager->id], $recipients->pluck('id')->all());
        $this->assertNotContains($inactiveManager->id, $recipients->pluck('id')->all());
    }

    public function test_notification_uses_only_database_channel_and_a_stable_type(): void
    {
        $data = [
            'company_id' => (string) Str::uuid(),
            'severity' => 'critical',
            'deep_link' => '/finance/overview',
        ];
        $notification = new TreasuryAlertNotification('treasury.portfolio_drift', $data);
        $notifiable = new \stdClass;

        $this->assertSame(['database'], $notification->via($notifiable));
        $this->assertSame('treasury.portfolio_drift', $notification->databaseType($notifiable));
        $this->assertSame($data, $notification->toDatabase($notifiable));
    }

    private function resolver(): TreasuryAlertRecipients
    {
        return new TreasuryAlertRecipients($this->permissionRegistrar);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real database-per-tenant recipient resolution is PostgreSQL-only.');
        }
    }

    private function provisionTenant(string $slugPrefix): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => $slugPrefix.'-'.Str::lower(Str::random(8))]);
        $this->tenants[] = $tenant;

        Bus::dispatchSync(new CreateDatabase($tenant));
        Bus::dispatchSync(new MigrateDatabase($tenant));

        return $tenant;
    }

    private function createCompany(Tenant $tenant, string $name): Company
    {
        return Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
        ]);
    }

    private function createPermittedUser(Tenant $tenant, string $email): User
    {
        $this->permissionRegistrar->setPermissionsTeamId($tenant->id);
        $this->permissionRegistrar->forgetCachedPermissions();
        Permission::findOrCreate('treasury.manage', 'sanctum');

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
        ]);
        $user->givePermissionTo('treasury.manage');

        return $user;
    }

    private function createMembership(User $user, Company $company, string $status): void
    {
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'manager',
            'status' => $status,
        ]);
    }
}
