<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\HashChainType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CreateCompanyTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        // Create an existing company + membership so CompanyContextMiddleware allows requests
        $existingCompany = Company::factory()->for($this->tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $existingCompany->id,
            'role' => MembershipRole::Owner,
        ]);
    }

    public function test_created_company_receives_default_expense_categories(): void
    {
        // Gate finding I-2: G-3 was wired only into the registration path, so a
        // SECOND company added to an existing tenant still received none — the
        // register's "real tenants receive no expense categories" headline stayed
        // literally true for every non-first company.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Second Company',
                'legal_name' => 'Second Company SARL',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ])->assertCreated();

        $companyId = (string) $response->json('data.id');

        $categories = ExpenseCategory::query()->where('company_id', $companyId)->get();
        $this->assertGreaterThanOrEqual(6, $categories->count());

        foreach ($categories as $category) {
            $this->assertNotNull($category->account_id, "Category '{$category->name}' has no GL account");
        }

        $utilities = $categories->firstWhere('name', 'Eau & Électricité');
        $this->assertNotNull($utilities);
        $this->assertSame('6061', Account::query()->whereKey($utilities->account_id)->value('code'));
    }

    public function test_created_company_receives_default_payment_methods_with_flagged_cash(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Second Treasury Company',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ])->assertCreated();

        $companyId = (string) $response->json('data.id');
        $methods = PaymentMethod::query()->where('company_id', $companyId)->get();
        $cash = $methods->firstWhere('code', 'CASH');

        $this->assertNotEmpty($methods);
        $this->assertNotNull($cash);
        $this->assertTrue($cash->is_cash_tender);
        $this->assertSame(1, $methods->where('is_cash_tender', true)->count());
    }

    public function test_authenticated_user_can_create_company(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'New Company',
                'legal_name' => 'New Company LLC',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Company')
            ->assertJsonPath('data.legal_name', 'New Company LLC')
            ->assertJsonPath('data.country_code', 'FR')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_company_creation_creates_default_location(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Company With Location',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertCreated();

        $companyId = $response->json('data.id');
        $location = Location::where('company_id', $companyId)->first();

        $this->assertNotNull($location);
        $this->assertEquals('Main Location', $location->name);
        $this->assertSame('MAIN', $location->code);
        $this->assertTrue($location->is_default);
        $this->assertTrue($location->is_active);
        // Owner ruling B-3 (2026-08-23) + its parent-delegated
        // provisioning sub-ruling (gate r1 / P3-7): the auto-created
        // type=shop Main Location is POS-enabled. Pinned HERE because
        // CompanyController is one of the three writers the ruling flipped and
        // only TenantProvisioningService was pinned — the other two could have
        // flipped back silently. With `pos_enabled` now enforced at terminal
        // acquisition, a regression here means a new company cannot open a till.
        $this->assertTrue($location->pos_enabled);
    }

    public function test_company_creation_creates_user_membership(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Company With Membership',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertCreated();

        $companyId = $response->json('data.id');
        $membership = UserCompanyMembership::where('company_id', $companyId)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertEquals('owner', $membership->role->value);
    }

    public function test_company_creation_initializes_hash_chains(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Company With Hash Chains',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertCreated();

        $companyId = $response->json('data.id');
        $company = Company::find($companyId);

        // Verify hash chains were initialized for all types
        foreach (HashChainType::cases() as $chainType) {
            $this->assertDatabaseHas('company_hash_chains', [
                'company_id' => $companyId,
                'chain_type' => $chainType->value,
                'sequence_number' => 0,
            ]);
        }
    }

    public function test_unauthenticated_user_cannot_create_company(): void
    {
        $response = $this->postJson('/api/v1/companies', [
            'name' => 'New Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $response->assertUnauthorized();
    }

    public function test_company_creation_requires_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_company_creation_requires_country_code(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'New Company',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $this->assertApiValidationErrors($response, ['country_code']);
    }

    public function test_company_creation_validates_country_code_format(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'New Company',
                'country_code' => 'INVALID',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $this->assertApiValidationErrors($response, ['country_code']);
    }

    public function test_company_creation_requires_currency(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'New Company',
                'country_code' => 'FR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $this->assertApiValidationErrors($response, ['currency']);
    }

    public function test_company_creation_validates_currency_format(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'New Company',
                'country_code' => 'FR',
                'currency' => 'INVALID',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $this->assertApiValidationErrors($response, ['currency']);
    }

    public function test_company_creation_with_optional_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Full Company',
                'legal_name' => 'Full Company SARL',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
                'tax_id' => 'FR12345678901',
                'email' => 'contact@fullcompany.com',
                'phone' => '+33 1 23 45 67 89',
                'address_street' => '123 Rue de la Paix',
                'address_city' => 'Paris',
                'address_postal_code' => '75001',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Full Company')
            ->assertJsonPath('data.legal_name', 'Full Company SARL')
            ->assertJsonPath('data.tax_id', 'FR12345678901')
            ->assertJsonPath('data.email', 'contact@fullcompany.com')
            ->assertJsonPath('data.phone', '+33 1 23 45 67 89')
            ->assertJsonPath('data.address_street', '123 Rue de la Paix')
            ->assertJsonPath('data.address_city', 'Paris')
            ->assertJsonPath('data.address_postal_code', '75001');
    }

    /**
     * Pins the third callsite: CompanyController::store() must derive pos_stock_policy
     * from the tenant vertical, not leave it at the DB default ('block').
     *
     * A restaurant-vertical tenant creating a SECOND company (via this endpoint)
     * must get pos_stock_policy = 'off' — not 'block'.
     */
    public function test_company_creation_derives_pos_stock_policy_from_tenant_vertical(): void
    {
        // Swap the tenant to restaurant vertical.
        $this->tenant->update(['vertical' => Vertical::Restaurant->value]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Second Café',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertCreated();

        $companyId = $response->json('data.id');
        // firstOrFail (not findOrFail) so the static type is Company, not
        // Company|Collection — findOrFail also accepts an array of ids.
        $company = Company::query()->where('id', $companyId)->firstOrFail();
        self::assertSame(PosStockPolicy::Off, $company->pos_stock_policy);
    }

    /**
     * W-8 finding F-5 (P1) — POST /api/v1/companies committed the company and
     * THEN answered 500.
     * Ticket: docs/superpowers/tickets/2026-08-03-w8-isolation-findings.md
     *
     * `default_target_margin` / `default_minimum_margin` are NOT NULL with a
     * DATABASE default (30 / 10) and are not passed by Company::create(). The
     * database supplies them, but the freshly created in-memory Eloquent model
     * never hydrates them — the attributes are null in PHP. formatCompany()
     * did `(string) $company->default_target_margin`, and `(string) null` is
     * `""`, which defeats bcformatOrNull()'s null guard so bcformatStrict()
     * threw. Every company created through the documented API therefore
     * 500'd AFTER the commit, so the caller retried and created a duplicate.
     */
    public function test_company_creation_returns_the_database_default_margins(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/companies', [
                'name' => 'Margin Defaults Company',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.default_target_margin', '30.00')
            ->assertJsonPath('data.default_minimum_margin', '10.00')
            ->assertJsonPath('data.default_max_discount_percent', null);

        // The 500 made callers retry; exactly one company must exist for the name.
        self::assertSame(1, Company::where('name', 'Margin Defaults Company')->count());
    }
}
