<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PaymentRepositoryLocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Repository Tenant', 'slug' => 'repository-'.Str::lower(Str::random(8)), 'status' => TenantStatus::Active, 'plan' => SubscriptionPlan::Professional]);
        $this->company = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Repository Company', 'legal_name' => 'Repository Company', 'tax_id' => 'REP-1', 'country_code' => 'FR', 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris', 'currency' => 'EUR', 'status' => CompanyStatus::Active]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Repository User', 'email' => 'repository-'.Str::lower(Str::random(8)).'@example.test', 'password' => bcrypt('password'), 'status' => UserStatus::Active]);
        $this->user->givePermissionTo(['repositories.view', 'repositories.manage']);
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'admin', 'status' => 'active']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_index_exposes_location_assignment(): void
    {
        $location = $this->location('A');
        PaymentRepository::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'code' => 'CR-A', 'name' => 'Register A', 'type' => 'cash_register', 'location_id' => $location->id, 'is_active' => true]);

        $this->actingAs($this->user)->getJson('/api/v1/payment-repositories')->assertOk()->assertJsonPath('data.0.location_id', $location->id)->assertJsonPath('data.0.location_name', 'Store A');
    }

    public function test_store_and_update_persist_company_scoped_location(): void
    {
        $location = $this->location('A');
        $response = $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', ['code' => 'CR-B', 'name' => 'Register B', 'type' => 'cash_register', 'location_id' => $location->id]);
        $response->assertCreated()->assertJsonPath('data.location_id', $location->id);
        $this->actingAs($this->user)->patchJson('/api/v1/payment-repositories/'.$response->json('data.id'), ['location_id' => null])->assertOk();
        $this->assertDatabaseHas('payment_repositories', ['code' => 'CR-B', 'location_id' => null]);
    }

    public function test_store_rejects_sibling_company_location(): void
    {
        $sibling = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Sibling', 'legal_name' => 'Sibling', 'tax_id' => 'SIB-1', 'country_code' => 'FR', 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris', 'currency' => 'EUR', 'status' => CompanyStatus::Active]);
        $foreignLocation = Location::create(['company_id' => $sibling->id, 'code' => 'SIB-A', 'name' => 'Sibling Store', 'type' => 'warehouse', 'is_active' => true]);

        $this->actingAs($this->user)->postJson('/api/v1/payment-repositories', ['code' => 'CR-C', 'name' => 'Register C', 'type' => 'cash_register', 'location_id' => $foreignLocation->id])->assertUnprocessable();
    }

    /**
     * N-12 gate r2 (treasury R2-3) — the partial unique index
     * `payment_repositories_one_drawer_per_location_type` is an invariant an
     * ordinary operator action can hit. Unhandled it surfaced as a raw 500
     * carrying a PostgreSQL constraint string.
     *
     * PostgreSQL-only: partial indexes are, and every tenant database is
     * PostgreSQL. Exercised under `phpunit-pgsql.xml`.
     */
    public function test_store_refuses_a_second_active_drawer_at_the_same_location(): void
    {
        $this->skipUnlessPostgres();

        $location = $this->location('D');
        $glAccountId = $this->cashAccountId();

        $this->actingAs($this->user)
            ->postJson('/api/v1/payment-repositories', [
                'code' => 'CR-FIRST', 'name' => 'First till', 'type' => 'cash_register',
                'location_id' => $location->id, 'gl_account_id' => $glAccountId,
            ])
            ->assertCreated();

        $this->actingAs($this->user)
            ->postJson('/api/v1/payment-repositories', [
                'code' => 'CR-SECOND', 'name' => 'Second till', 'type' => 'cash_register',
                'location_id' => $location->id, 'gl_account_id' => $glAccountId,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LOCATION_DRAWER_ALREADY_EXISTS');

        $this->assertDatabaseMissing('payment_repositories', ['code' => 'CR-SECOND']);
    }

    /**
     * The likelier path in practice: `RepositoryDetailPage` issues
     * `apiPatch({ gl_account_id })`, and GL-linking a drawer moves it INTO the
     * index's predicate beside an already-linked sibling at the same location.
     */
    public function test_update_refuses_gl_linking_a_second_drawer_at_the_same_location(): void
    {
        $this->skipUnlessPostgres();

        $location = $this->location('E');
        $glAccountId = $this->cashAccountId();

        $this->actingAs($this->user)
            ->postJson('/api/v1/payment-repositories', [
                'code' => 'CR-LINKED', 'name' => 'Linked till', 'type' => 'cash_register',
                'location_id' => $location->id, 'gl_account_id' => $glAccountId,
            ])
            ->assertCreated();

        // Created WITHOUT a GL account, so it starts outside the index predicate.
        $unlinked = $this->actingAs($this->user)
            ->postJson('/api/v1/payment-repositories', [
                'code' => 'CR-UNLINKED', 'name' => 'Unlinked till', 'type' => 'cash_register',
                'location_id' => $location->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->patchJson('/api/v1/payment-repositories/'.$unlinked, ['gl_account_id' => $glAccountId])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LOCATION_DRAWER_ALREADY_EXISTS');

        $this->assertDatabaseHas('payment_repositories', ['code' => 'CR-UNLINKED', 'gl_account_id' => null]);
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only; exercised under phpunit-pgsql.xml.');
        }
    }

    private function cashAccountId(): string
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        return Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::Cash->value)
            ->firstOrFail()
            ->id;
    }

    private function location(string $suffix): Location
    {
        return Location::create(['company_id' => $this->company->id, 'code' => 'REP-'.$suffix, 'name' => 'Store '.$suffix, 'type' => 'warehouse', 'is_active' => true]);
    }
}
