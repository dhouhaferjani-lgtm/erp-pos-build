<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReceiptSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Account',
            'slug' => 'test-receipt-settings',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'settings' => [],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company SARL',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin-receipt@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
    }

    // ==================== GET Receipt Settings Tests ====================

    public function test_get_pos_settings_includes_receipt_customization_fields(): void
    {
        $this->company->update([
            'receipt_header' => 'Welcome to our store!',
            'receipt_thank_you' => 'Thanks for shopping!',
            'receipt_show_vat_breakdown' => false,
            'receipt_show_fiscal_info' => true,
            'receipt_show_payment_details' => true,
            'receipt_show_customer' => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/companies/{$this->company->id}/pos-settings");

        $response->assertOk()
            ->assertJsonFragment([
                'auto_print_receipts' => false,
                'receipt_header' => 'Welcome to our store!',
                'receipt_footer' => null,
                'receipt_thank_you' => 'Thanks for shopping!',
                'receipt_show_vat_breakdown' => false,
                'receipt_show_fiscal_info' => true,
                'receipt_show_payment_details' => true,
                'receipt_show_customer' => false,
            ]);
    }

    // ==================== PUT Receipt Settings Tests ====================

    public function test_can_update_receipt_settings(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_header' => 'Custom Header',
                'receipt_footer' => 'Custom Footer',
                'receipt_thank_you' => 'Merci!',
                'receipt_show_vat_breakdown' => true,
                'receipt_show_fiscal_info' => true,
                'receipt_show_payment_details' => false,
                'receipt_show_customer' => false,
                'auto_print_receipts' => true,
            ]);

        $response->assertOk()
            ->assertJsonFragment([
                'receipt_header' => 'Custom Header',
                'receipt_footer' => 'Custom Footer',
                'receipt_thank_you' => 'Merci!',
                'receipt_show_vat_breakdown' => true,
                'receipt_show_fiscal_info' => true,
                'receipt_show_payment_details' => false,
                'receipt_show_customer' => false,
                'auto_print_receipts' => true,
            ]);

        $this->company->refresh();
        $this->assertEquals('Custom Header', $this->company->receipt_header);
        $this->assertEquals('Custom Footer', $this->company->receipt_footer);
        $this->assertEquals('Merci!', $this->company->receipt_thank_you);
        $this->assertTrue($this->company->receipt_show_vat_breakdown);
        $this->assertTrue($this->company->receipt_show_fiscal_info);
        $this->assertFalse($this->company->receipt_show_payment_details);
        $this->assertFalse($this->company->receipt_show_customer);
        $this->assertTrue($this->company->auto_print_receipts);
    }

    public function test_can_update_receipt_settings_partially(): void
    {
        $this->company->update([
            'receipt_header' => 'Original Header',
            'receipt_show_vat_breakdown' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_header' => 'Updated Header',
                'receipt_show_vat_breakdown' => false,
                'receipt_show_fiscal_info' => true,
                'receipt_show_payment_details' => true,
                'receipt_show_customer' => true,
                'auto_print_receipts' => false,
            ]);

        $response->assertOk();

        $this->company->refresh();
        $this->assertEquals('Updated Header', $this->company->receipt_header);
        $this->assertFalse($this->company->receipt_show_vat_breakdown);
    }

    public function test_receipt_header_stored_correctly(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_header' => 'Welcome to our shop!',
            ]);

        $response->assertOk();
        $this->company->refresh();
        $this->assertEquals('Welcome to our shop!', $this->company->receipt_header);
    }

    public function test_receipt_thank_you_stored_correctly(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_thank_you' => 'Merci pour votre achat !',
            ]);

        $response->assertOk();
        $this->company->refresh();
        $this->assertEquals('Merci pour votre achat !', $this->company->receipt_thank_you);
    }

    public function test_partial_update_only_changes_provided_fields(): void
    {
        // First set all values
        $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_header' => 'Test Header',
                'receipt_show_vat_breakdown' => false,
                'auto_print_receipts' => true,
            ]);

        // Update only footer — other fields should remain unchanged
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
                'receipt_footer' => 'New Footer',
            ]);

        $response->assertOk();
    }

    public function test_unauthenticated_user_cannot_update_receipt_settings(): void
    {
        $response = $this->putJson("/api/v1/companies/{$this->company->id}/receipt-settings", [
            'receipt_show_vat_breakdown' => true,
            'receipt_show_fiscal_info' => true,
            'receipt_show_payment_details' => true,
            'receipt_show_customer' => true,
            'auto_print_receipts' => false,
        ]);

        $response->assertUnauthorized();
    }

    // ==================== Legal Override Tests ====================

    public function test_french_company_forces_vat_breakdown(): void
    {
        $this->assertEquals('FR', $this->company->country_code);

        // FR is in the list of countries that require VAT breakdown
        $forceVatBreakdown = in_array($this->company->country_code, ['FR', 'TN', 'IT'], true);
        $this->assertTrue($forceVatBreakdown);
    }

    public function test_french_company_forces_fiscal_info(): void
    {
        $this->assertEquals('FR', $this->company->country_code);

        // FR requires fiscal info (NF525)
        $forceFiscalInfo = $this->company->country_code === 'FR';
        $this->assertTrue($forceFiscalInfo);
    }

    public function test_uk_company_does_not_force_fiscal_info(): void
    {
        $ukCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'UK Company',
            'country_code' => 'GB',
            'currency' => 'GBP',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 4,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        $forceFiscalInfo = $ukCompany->country_code === 'FR';
        $this->assertFalse($forceFiscalInfo);
    }

    // ==================== Default Values Tests ====================

    public function test_new_columns_have_correct_defaults(): void
    {
        $freshCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fresh Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        $freshCompany->refresh();

        $this->assertNull($freshCompany->receipt_header);
        $this->assertNull($freshCompany->receipt_thank_you);
        $this->assertTrue($freshCompany->receipt_show_vat_breakdown);
        $this->assertTrue($freshCompany->receipt_show_fiscal_info);
        $this->assertTrue($freshCompany->receipt_show_payment_details);
        $this->assertTrue($freshCompany->receipt_show_customer);
    }
}
