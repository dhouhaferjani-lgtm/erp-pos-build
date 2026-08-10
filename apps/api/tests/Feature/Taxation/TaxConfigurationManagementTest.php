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

    public function test_capabilities_endpoint_uses_the_current_company_country(): void
    {
        $user = $this->userWith(['documents.view']);
        $frenchCompany = $this->createFrenchCompany($user);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->getJson('/api/v1/taxation/configurations/capabilities')
            ->assertOk()
            ->assertJsonPath('data.supports_stamp_duty', true);

        $this->withHeaders(['X-Company-ID' => $frenchCompany->id])
            ->getJson('/api/v1/taxation/configurations/capabilities')
            ->assertOk()
            ->assertJsonPath('data.supports_stamp_duty', false);
    }

    public function test_french_company_cannot_create_or_convert_a_stamp_duty(): void
    {
        $user = $this->userWith(['taxation.tax_configurations.manage']);
        $frenchCompany = $this->createFrenchCompany($user);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Company-ID' => $frenchCompany->id])
            ->postJson('/api/v1/taxation/configurations', $this->payload([
                'code' => 'FR_STAMP_FORBIDDEN',
                'is_stamp_duty' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_stamp_duty', 'error.errors');

        $configuration = $this->createLineConfiguration('FR', 'FR_FIXED_LINE');

        $this->withHeaders(['X-Company-ID' => $frenchCompany->id])
            ->patchJson('/api/v1/taxation/configurations/'.$configuration->id, [
                'applies_to' => 'DOCUMENT_TOTAL',
                'is_stamp_duty' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_stamp_duty', 'error.errors');

        $this->assertFalse($configuration->refresh()->is_stamp_duty);
    }

    public function test_generic_document_total_configuration_is_rejected_on_store_and_update(): void
    {
        $user = $this->userWith(['taxation.tax_configurations.manage']);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', $this->payload([
                'code' => 'GENERIC_DOCUMENT_TOTAL',
                'is_stamp_duty' => false,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('applies_to', 'error.errors');

        $configuration = $this->createLineConfiguration('TN', 'TN_FIXED_LINE');

        $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->patchJson('/api/v1/taxation/configurations/'.$configuration->id, [
                'applies_to' => 'DOCUMENT_TOTAL',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('applies_to', 'error.errors');

        $this->assertSame('LINE_ITEMS', $configuration->refresh()->applies_to->value);
    }

    /**
     * F-3 closure (2026-08-10 tenancy gate). The DOCUMENT_TOTAL => stamp rule
     * was one-directional, so a TN `is_stamp_duty=true` row tagged LINE_ITEMS
     * was accepted — the mirror image of item H. Such a row writes
     * `document_tax_details.is_stamp_duty=true`, which makes TunisiaVatStrategy
     * count stamp duty as collected while `stamp_duty_amount` stays 0.000 AND
     * excludes the row from the VAT base. Both directions must hold.
     */
    public function test_stamp_duty_must_be_applied_at_the_document_total(): void
    {
        $user = $this->userWith(['taxation.tax_configurations.manage']);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->postJson('/api/v1/taxation/configurations', $this->payload([
                'code' => 'TN_STAMP_ON_LINES',
                'applies_to' => 'LINE_ITEMS',
                'is_stamp_duty' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_stamp_duty', 'error.errors');

        $this->assertNull(TaxConfiguration::where('code', 'TN_STAMP_ON_LINES')->first());
    }

    /**
     * The update path must evaluate the MERGED state: a partial PATCH carrying
     * only `is_stamp_duty` still has to read `applies_to` off the persisted row
     * (F-3 + F-8, the `?? $configuration->…` fallback branches).
     */
    public function test_partial_update_cannot_flip_a_line_item_tax_into_a_stamp_duty(): void
    {
        $user = $this->userWith(['taxation.tax_configurations.manage']);
        Sanctum::actingAs($user);

        $configuration = $this->createLineConfiguration('TN', 'TN_LINE_TO_STAMP');

        $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->patchJson('/api/v1/taxation/configurations/'.$configuration->id, [
                'is_stamp_duty' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_stamp_duty', 'error.errors');

        $this->assertFalse($configuration->refresh()->is_stamp_duty);
        $this->assertSame('LINE_ITEMS', $configuration->applies_to->value);
    }

    /**
     * F-8: the mirrored fallback. A PATCH carrying only `applies_to` must read
     * `is_stamp_duty` off the persisted row — proven here on a row that IS a
     * stamp duty, so moving it to LINE_ITEMS is the violation.
     */
    public function test_partial_update_cannot_move_a_stamp_duty_off_the_document_total(): void
    {
        $user = $this->userWith(['taxation.tax_configurations.manage']);
        Sanctum::actingAs($user);

        $configuration = TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => 'FIXED_AMOUNT',
            'name' => 'TN_STAMP_ROW',
            'code' => 'TN_STAMP_ROW',
            'fixed_amount' => '0.600',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 9,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_recoverable' => false,
            'is_stamp_duty' => true,
            'is_default' => false,
        ]);

        $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->patchJson('/api/v1/taxation/configurations/'.$configuration->id, [
                'applies_to' => 'LINE_ITEMS',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_stamp_duty', 'error.errors');

        $this->assertSame('DOCUMENT_TOTAL', $configuration->refresh()->applies_to->value);
        $this->assertTrue($configuration->is_stamp_duty);
    }

    /**
     * F-8: a partial PATCH that touches NEITHER guarded field must still pass —
     * the merged-state read must not manufacture a rejection for an untouched
     * brownfield-legal row.
     */
    public function test_partial_update_of_an_unrelated_field_still_succeeds(): void
    {
        $user = $this->userWith(['taxation.tax_configurations.manage']);
        Sanctum::actingAs($user);

        $configuration = $this->createLineConfiguration('TN', 'TN_RENAME_ME');

        $this->withHeaders(['X-Company-ID' => $this->company->id])
            ->patchJson('/api/v1/taxation/configurations/'.$configuration->id, [
                'name' => 'Renamed line tax',
            ])
            ->assertOk();

        $this->assertSame('Renamed line tax', $configuration->refresh()->name);
    }

    private function createFrenchCompany(User $user): Company
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'French Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '20.00',
        ]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => false,
        ]);

        return $company;
    }

    private function createLineConfiguration(string $countryCode, string $code): TaxConfiguration
    {
        return TaxConfiguration::create([
            'country_code' => $countryCode,
            'tax_type' => 'FIXED_AMOUNT',
            'name' => $code,
            'code' => $code,
            'fixed_amount' => '1.000',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_recoverable' => false,
            'is_stamp_duty' => false,
            'is_default' => false,
        ]);
    }
}
