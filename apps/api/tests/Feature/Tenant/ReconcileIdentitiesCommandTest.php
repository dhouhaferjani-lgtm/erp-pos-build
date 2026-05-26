<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\CentralIdentity;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconcileIdentitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_missing_rows_and_prunes_stale_ones(): void
    {
        $tenant = Tenant::create([
            'name' => 'Recon Co',
            'slug' => 'recon-co',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Active user with email, NOT yet indexed -> should be backfilled.
        $userA = User::create([
            'tenant_id' => $tenant->id, 'name' => 'A', 'email' => 'a@recon.com',
            'password' => 'Password1!', 'status' => UserStatus::Active,
        ]);

        // Inactive user with a stale index row -> should be pruned.
        $userB = User::create([
            'tenant_id' => $tenant->id, 'name' => 'B', 'email' => 'b@recon.com',
            'password' => 'Password1!', 'status' => UserStatus::Inactive,
        ]);
        CentralIdentity::create(['email' => 'b@recon.com', 'tenant_id' => $tenant->id, 'user_id' => $userB->id]);

        // Active user already correctly indexed -> kept.
        $userC = User::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'email' => 'c@recon.com',
            'password' => 'Password1!', 'status' => UserStatus::Active,
        ]);
        CentralIdentity::create(['email' => 'c@recon.com', 'tenant_id' => $tenant->id, 'user_id' => $userC->id]);

        // PIN-only user without email -> never indexed.
        User::create([
            'tenant_id' => $tenant->id, 'name' => 'D', 'email' => null,
            'password' => 'Password1!', 'status' => UserStatus::Active,
        ]);

        // Orphan index row pointing at no user -> pruned.
        CentralIdentity::create(['email' => 'ghost@recon.com', 'tenant_id' => $tenant->id, 'user_id' => Str::uuid()->toString()]);

        $this->artisan('tenant:reconcile-identities')->assertSuccessful();

        $this->assertDatabaseHas('central_identities', [
            'email' => 'a@recon.com', 'tenant_id' => $tenant->id, 'user_id' => $userA->id,
        ]);
        $this->assertDatabaseHas('central_identities', [
            'email' => 'c@recon.com', 'tenant_id' => $tenant->id,
        ]);
        $this->assertDatabaseMissing('central_identities', ['email' => 'b@recon.com', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('central_identities', ['email' => 'ghost@recon.com', 'tenant_id' => $tenant->id]);

        // Exactly A and C remain.
        $this->assertSame(2, CentralIdentity::where('tenant_id', $tenant->id)->count());
    }
}
