<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\UserManagement;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

/**
 * PostgreSQL UUID columns canonicalize alternate-case route text during lookup.
 * This cannot be reproduced by the default SQLite suite, where UUIDs are TEXT.
 */
final class UserLocationAccessPostgresTest extends TestCase
{
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Alternate-case UUID self-grant regression is PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);

        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if ($this->tenant !== null) {
            try {
                DB::purge('tenant');
                $this->tenant->database()->manager()->deleteDatabase($this->tenant);
            } catch (\Throwable) {
                // Best effort; the randomized tenant id keeps leaked DB names unique.
            }

            DB::connection('central')->table('domains')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_alternate_case_own_uuid_is_denied_after_postgres_resolves_target(): void
    {
        $this->tenant = Tenant::factory()->create([
            'slug' => 'self-location-'.Str::lower(Str::random(10)),
        ]);

        Bus::dispatchSync(new CreateDatabase($this->tenant));
        Bus::dispatchSync(new MigrateDatabase($this->tenant));
        tenancy()->initialize($this->tenant);

        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Postgres Self Guard Company',
            'legal_name' => 'Postgres Self Guard Company SARL',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'PG-SELF',
            'name' => 'Postgres Self Guard Location',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $owner = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Postgres Owner',
            'email' => 'postgres-self-guard@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $owner->assignRole('admin');

        $membership = UserCompanyMembership::create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'allowed_location_ids' => null,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->patchJson('/api/v1/users/'.strtoupper($owner->id), [
                'allowed_location_ids' => [$location->id],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'SELF_LOCATION_ESCALATION');
        $this->assertNull($membership->fresh()->allowed_location_ids);
    }
}
