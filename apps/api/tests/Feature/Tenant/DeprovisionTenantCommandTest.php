<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Tests\TestCase;

/**
 * T6 Phase 0b — the `tenant:deprovision` CLI surface (compat mode).
 *
 * Drives the command in shared-DB compat mode (the default test topology): the
 * suspend path flips status, the delete path removes the central directory row,
 * and neither attempts a physical DROP DATABASE. The DB-per-tenant physical-drop
 * behaviour is covered by TenantDeprovisioningTest (PG-only).
 */
class DeprovisionTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy_resolver.db_per_tenant' => false]);
    }

    public function test_deprovision_with_force_removes_the_central_tenant_row(): void
    {
        Bus::fake();

        $tenant = $this->makeTenant('cmd-delete');

        $this->artisan('tenant:deprovision', ['slug' => $tenant->slug, '--force' => true])
            ->assertSuccessful();

        Bus::assertNotDispatched(DeleteDatabase::class);
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_suspend_flag_keeps_the_tenant_and_only_changes_status(): void
    {
        $tenant = $this->makeTenant('cmd-suspend');

        $this->artisan('tenant:deprovision', ['slug' => $tenant->slug, '--suspend' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => TenantStatus::Suspended->value,
        ]);
    }

    public function test_unknown_slug_fails(): void
    {
        $this->artisan('tenant:deprovision', ['slug' => 'does-not-exist', '--force' => true])
            ->assertFailed();
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Command '.$slug,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);
    }
}
