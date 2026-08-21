<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_be_created_with_required_fields(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Garage',
            'slug' => 'test-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Test Garage',
            'slug' => 'test-garage',
            'status' => 'active',
            'plan' => 'trial',
        ]);

        $this->assertNotNull($tenant->id);
        $this->assertEquals(36, strlen($tenant->id)); // UUID length
    }

    public function test_tenant_slug_must_be_unique(): void
    {
        Tenant::create([
            'name' => 'First Garage',
            'slug' => 'test-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);

        $this->expectException(QueryException::class);

        Tenant::create([
            'name' => 'Second Garage',
            'slug' => 'test-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);
    }

    public function test_tenant_is_active_returns_correct_status(): void
    {
        $activeTenant = Tenant::create([
            'name' => 'Active Garage',
            'slug' => 'active-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $suspendedTenant = Tenant::create([
            'name' => 'Suspended Garage',
            'slug' => 'suspended-garage',
            'status' => TenantStatus::Suspended,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->assertTrue($activeTenant->isActive());
        $this->assertFalse($suspendedTenant->isActive());
    }

    public function test_tenant_is_in_trial_returns_correct_status(): void
    {
        $trialTenant = Tenant::create([
            'name' => 'Trial Garage',
            'slug' => 'trial-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $expiredTrialTenant = Tenant::create([
            'name' => 'Expired Trial Garage',
            'slug' => 'expired-trial-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);

        $paidTenant = Tenant::create([
            'name' => 'Paid Garage',
            'slug' => 'paid-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $this->assertTrue($trialTenant->isInTrial());
        $this->assertFalse($expiredTrialTenant->isInTrial());
        $this->assertFalse($paidTenant->isInTrial());
    }

    public function test_tenant_has_valid_subscription_returns_correct_status(): void
    {
        $validTrialTenant = Tenant::create([
            'name' => 'Valid Trial',
            'slug' => 'valid-trial',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'trial_ends_at' => now()->addDays(7),
        ]);

        $validPaidTenant = Tenant::create([
            'name' => 'Valid Paid',
            'slug' => 'valid-paid',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $expiredTenant = Tenant::create([
            'name' => 'Expired',
            'slug' => 'expired',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'subscription_ends_at' => now()->subDay(),
        ]);

        $this->assertTrue($validTrialTenant->hasValidSubscription());
        $this->assertTrue($validPaidTenant->hasValidSubscription());
        $this->assertFalse($expiredTenant->hasValidSubscription());
    }

    public function test_tenant_can_have_domains(): void
    {
        $tenant = Tenant::create([
            'name' => 'Domain Test',
            'slug' => 'domain-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);

        $tenant->domains()->create([
            'domain' => 'test.autoerp.local',
            'is_primary' => true,
            'is_verified' => true,
        ]);

        $this->assertCount(1, $tenant->domains);
        $this->assertEquals('test.autoerp.local', $tenant->domains->first()->domain);
    }

    public function test_tenant_get_database_name_returns_correct_schema(): void
    {
        $tenant = Tenant::create([
            'name' => 'Schema Test',
            'slug' => 'schema-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);

        $this->assertEquals('tenant_schema-test', $tenant->getDatabaseName());
    }

    public function test_subscription_plan_max_users(): void
    {
        $this->assertEquals(2, SubscriptionPlan::Trial->maxUsers());
        $this->assertEquals(5, SubscriptionPlan::Starter->maxUsers());
        $this->assertEquals(20, SubscriptionPlan::Professional->maxUsers());
        $this->assertEquals(PHP_INT_MAX, SubscriptionPlan::Enterprise->maxUsers());
    }

    /**
     * Register G-1. `tenant:create` inserts ONLY the central tenants/domains rows.
     * Under database-per-tenant that produces a tenant whose database was never
     * created or migrated, so TenancyResolver::initializeIfProvisioned() fails
     * closed and every request for it 503s — permanently, and silently at
     * creation time. The command must refuse rather than brick the tenant.
     */
    public function test_tenant_create_refuses_to_run_under_database_per_tenant(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $this->artisan('tenant:create', ['name' => 'Bricked Garage'])
            ->expectsOutputToContain('database-per-tenant')
            ->expectsOutputToContain('TenantProvisioningService')
            ->expectsOutputToContain('--central-row-only')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('tenants', ['slug' => 'bricked-garage']);
    }

    /**
     * Register G-1 escape hatch: the rare legitimate central-directory-row use
     * (e.g. repairing a directory entry for an already-provisioned database)
     * still works, but is announced as the partial operation it is.
     */
    public function test_tenant_create_central_row_only_flag_creates_the_row_with_a_warning(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $this->artisan('tenant:create', [
            'name' => 'Directory Only Garage',
            '--central-row-only' => true,
        ])
            ->expectsOutputToContain('No tenant database is created or migrated')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('tenants', ['slug' => 'directory-only-garage']);
    }

    /**
     * Register G-1: the shared-DB compat mode is unaffected — there the central
     * row IS the whole tenant, so the command remains the correct tool.
     */
    public function test_tenant_create_still_works_in_shared_db_compat_mode(): void
    {
        config(['tenancy_resolver.db_per_tenant' => false]);

        $this->artisan('tenant:create', ['name' => 'Compat Garage'])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('tenants', ['slug' => 'compat-garage']);
    }
}
