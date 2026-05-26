<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Application\Services\TenantDeprovisioningService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Tests\TestCase;

/**
 * T6 Phase 0b — teardown in SHARED-DB COMPAT mode (db_per_tenant = false).
 *
 * This is the default test/dev topology: one shared database, row-level
 * scoping, NO real per-tenant databases. Teardown must therefore NEVER attempt
 * a physical DROP DATABASE — doing so would target a database that does not
 * exist (or, worse, the shared one). It must still clean the central directory
 * rows and never crash.
 *
 * Runs on the single shared connection with RefreshDatabase (no CREATE/DROP
 * DATABASE happens, so the transaction is safe).
 */
class TenantDeprovisioningCompatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Explicit: this app/test topology is shared-DB row-level compat.
        config(['tenancy_resolver.db_per_tenant' => false]);
    }

    public function test_deprovision_in_compat_mode_attempts_no_physical_drop_and_cleans_central_rows(): void
    {
        Bus::fake();

        $tenant = $this->makeTenant('compat-delete');

        $service = $this->app->make(TenantDeprovisioningService::class);
        $service->deprovision($tenant);

        // CRITICAL: no physical DROP DATABASE may be attempted in compat mode.
        Bus::assertNotDispatched(DeleteDatabase::class);

        // Central directory row is still cleaned up.
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_deprovision_in_compat_mode_does_not_throw(): void
    {
        $tenant = $this->makeTenant('compat-noerror');

        $service = $this->app->make(TenantDeprovisioningService::class);

        // Must complete without touching a per-tenant database (none exists).
        $service->deprovision($tenant);

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_suspend_in_compat_mode_only_changes_status(): void
    {
        Bus::fake();

        $tenant = $this->makeTenant('compat-suspend');

        $service = $this->app->make(TenantDeprovisioningService::class);
        $service->suspend($tenant);

        Bus::assertNotDispatched(DeleteDatabase::class);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => TenantStatus::Suspended->value,
        ]);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Compat '.$slug,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);
    }
}
