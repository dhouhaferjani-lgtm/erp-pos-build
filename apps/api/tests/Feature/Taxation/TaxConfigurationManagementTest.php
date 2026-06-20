<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaxConfigurationManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'country_code' => 'TN',
            'currency_code' => 'TND',
            'status' => 'active',
            'plan' => 'professional',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tunisian Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user-'.Str::uuid()->toString().'@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tax_type' => 'FIXED_AMOUNT',
            'name' => 'Timbre Fiscal - Ticket',
            'code' => 'STAMP_TEST_RECEIPT',
            'fixed_amount' => '0.100',
            'applies_to' => 'DOCUMENT_TOTAL',
            'applicable_document_types' => ['FISCAL_RECEIPT'],
            'is_active' => true,
        ], $overrides);
    }

    public function test_document_types_endpoint_returns_only_matchable_fiscal_category_tokens(): void
    {
        // The calc keys applicability on the document's fiscal_category (a NOT
        // NULL column whose only values are the FiscalCategory cases). The
        // dropdown that lets admins tag a tax to "document types" must therefore
        // offer exactly those tokens — otherwise a tax tagged to e.g. "QUOTATION"
        // (which is not a fiscal category) silently never applies.
        $user = $this->userWith(['documents.view']);

        Sanctum::actingAs($user);
        $response = $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->getJson('/api/v1/taxation/configurations/document-types');

        $response->assertOk();

        $tokens = array_column($response->json('data'), 'value');
        sort($tokens);

        $expected = array_map(fn (FiscalCategory $c): string => $c->value, FiscalCategory::cases());
        sort($expected);

        $this->assertSame($expected, $tokens, 'Dropdown tokens must equal the fiscal categories the engine matches on');
        $this->assertNotContains('QUOTATION', $tokens, 'Bogus non-matching token must be gone');
        $this->assertNotContains('SALES_ORDER', $tokens, 'Bogus non-matching token must be gone');
        $this->assertNotContains('PURCHASE_INVOICE', $tokens, 'Bogus non-matching token must be gone');
    }

    public function test_user_without_manage_permission_cannot_create_tax_configuration(): void
    {
        $user = $this->userWith(['documents.view']);

        Sanctum::actingAs($user);
        $response = $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', $this->payload());

        $response->assertForbidden();
    }

    public function test_user_with_manage_permission_can_create_stamp_duty_with_effective_dates(): void
    {
        $user = $this->userWith(['taxation.tax_configurations.manage']);

        Sanctum::actingAs($user);
        $response = $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', $this->payload([
                'is_stamp_duty' => true,
                'is_default' => false,
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ]));

        $response->assertCreated();

        $config = TaxConfiguration::where('code', 'STAMP_TEST_RECEIPT')->firstOrFail();
        $this->assertTrue($config->is_stamp_duty, 'is_stamp_duty must persist via the management API');
        $this->assertSame('2026-01-01', $config->effective_from?->toDateString());
        $this->assertSame('2026-12-31', $config->effective_to?->toDateString());
    }
}
