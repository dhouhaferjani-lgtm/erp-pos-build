<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantDeprovisioningService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

    /**
     * The gap this branch closes: deprovision deletes the central `tenants` row
     * via the query builder, which fires NO Eloquent model events, so the
     * TenantObserver's revocation never runs. Deprovision must therefore revoke
     * the tenant users' central bearer tokens EXPLICITLY — otherwise they dangle
     * after the tenant is gone.
     */
    public function test_deprovision_revokes_all_user_tokens(): void
    {
        Bus::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $tenant = $this->makeTenant('compat-revoke');
        $otherTenant = $this->makeTenant('compat-keep');

        $user1 = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        $user2 = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'status' => UserStatus::Active]);

        $user1->createToken('t1');
        $user2->createToken('t2');
        $otherUser->createToken('keep');

        $this->assertSame(3, DB::table('personal_access_tokens')->count());

        $service = $this->app->make(TenantDeprovisioningService::class);
        $service->deprovision($tenant);

        // The deprovisioned tenant's tokens are gone.
        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->whereIn('tokenable_id', [$user1->id, $user2->id])
                ->where('tokenable_type', User::class)
                ->count(),
            'Deprovision must revoke every central token for the tenant users.',
        );

        // An unrelated tenant's token is untouched.
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $otherUser->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'Other tenants must be unaffected.',
        );

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
