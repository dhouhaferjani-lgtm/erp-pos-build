<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CompanySettingsTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $viewerUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'settings' => [],
        ]);

        // Create company
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

        // Set permissions team context and seed roles/permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create admin user
        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        // Create admin company membership
        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // Create viewer user
        $this->viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->viewerUser->assignRole('viewer');

        // Create viewer company membership
        UserCompanyMembership::create([
            'user_id' => $this->viewerUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Viewer,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
    }

    // ==================== GET Company Settings Tests ====================

    public function test_can_get_company_settings(): void
    {
        $this->company->update([
            'name' => 'Acme Garage',
            'legal_name' => 'Acme Garage SARL',
            'tax_id' => 'FR12345678901',
            'registration_number' => 'RCS 123 456 789',
            'address_street' => '123 Main Street',
            'address_city' => 'Paris',
            'address_postal_code' => '75001',
            'phone' => '+33 1 23 45 67 89',
            'email' => 'contact@acme-garage.fr',
            'website' => 'https://acme-garage.fr',
            'primary_color' => '#FF5733',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'timezone' => 'Europe/Paris',
            'date_format' => 'DD/MM/YYYY',
            'locale' => 'fr',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/settings/company');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'name',
                    'legal_name',
                    'tax_id',
                    'registration_number',
                    'address' => [
                        'street',
                        'city',
                        'postal_code',
                        'country',
                    ],
                    'phone',
                    'email',
                    'website',
                    'logo_url',
                    'primary_color',
                    'country_code',
                    'currency_code',
                    'timezone',
                    'date_format',
                    'locale',
                ],
                'meta',
            ])
            ->assertJsonPath('data.name', 'Acme Garage')
            ->assertJsonPath('data.legal_name', 'Acme Garage SARL')
            ->assertJsonPath('data.tax_id', 'FR12345678901')
            ->assertJsonPath('data.primary_color', '#FF5733')
            ->assertJsonPath('data.timezone', 'Europe/Paris');
    }

    public function test_show_returns_company_entity_values_not_tenant_values(): void
    {
        $this->tenant->update([
            'tax_id' => 'TENANT-TAX',
            'currency_code' => 'EUR',
            'name' => 'Tenant Shell',
        ]);
        $this->company->update([
            'tax_id' => '1234567AM000',
            'currency' => 'TND',
            'name' => 'PharmaBio Tunis',
            'address_street' => 'Av. Habib Bourguiba',
            'address_city' => 'Tunis',
            'address_postal_code' => '1000',
            'country_code' => 'TN',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/settings/company');

        $response->assertOk()
            ->assertJsonPath('data.name', 'PharmaBio Tunis')
            ->assertJsonPath('data.tax_id', '1234567AM000')
            ->assertJsonPath('data.currency_code', 'TND')
            ->assertJsonPath('data.address.street', 'Av. Habib Bourguiba')
            ->assertJsonPath('data.address.country', 'TN');
    }

    public function test_update_persists_to_company_and_never_touches_tenant(): void
    {
        $tenantBefore = $this->tenant->fresh()->only(['tax_id', 'currency_code', 'name', 'address']);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'tax_id' => '7654321BM000',
                'address' => [
                    'street' => 'Rue de Marseille',
                    'city' => 'Sfax',
                    'postal_code' => '3000',
                ],
            ]);

        $response->assertOk();
        $company = $this->company->fresh();
        $this->assertSame('7654321BM000', $company->tax_id);
        $this->assertSame('EUR', $company->currency);
        $this->assertSame('FR', $company->country_code);
        $this->assertSame('Rue de Marseille', $company->address_street);
        $this->assertSame('Sfax', $company->address_city);
        $this->assertSame($tenantBefore, $this->tenant->fresh()->only(['tax_id', 'currency_code', 'name', 'address']));
    }

    public function test_country_code_is_immutable_after_provisioning(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Must Not Persist',
                'country_code' => 'TN',
            ]);

        $this->assertJsonValidationErrors($response, ['country_code'])
            ->assertJsonPath(
                'error.errors.country_code.0',
                'The company country is fixed at creation. Correction requires a support-operations procedure that is not yet available.',
            );

        $this->company->refresh();
        $this->assertSame('FR', $this->company->country_code);
        $this->assertSame('Test Company', $this->company->name);
    }

    public function test_address_country_alias_is_immutable_after_provisioning_in_french(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->withHeader('X-Language', 'fr')
            ->patchJson('/api/v1/settings/company', [
                'address' => [
                    'street' => 'Must Not Persist',
                    'country' => 'TN',
                ],
            ]);

        $this->assertJsonValidationErrors($response, ['address.country']);
        $this->assertSame(
            "Le pays de la société est fixé lors de sa création. Toute correction nécessite une procédure d'exploitation du support qui n'est pas encore disponible.",
            $response->json('error.errors')['address.country'][0],
        );

        $this->company->refresh();
        $this->assertSame('FR', $this->company->country_code);
        $this->assertNull($this->company->address_street);
    }

    public function test_currency_is_immutable_after_provisioning(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'phone' => '+216 00 000 000',
                'currency_code' => 'TND',
            ]);

        $this->assertJsonValidationErrors($response, ['currency_code'])
            ->assertJsonPath(
                'error.errors.currency_code.0',
                'The company currency is fixed at creation. Correction requires a support-operations procedure that is not yet available.',
            );

        $this->company->refresh();
        $this->assertSame('EUR', $this->company->currency);
        $this->assertNull($this->company->phone);
    }

    public function test_idempotent_identity_values_allow_cosmetic_settings_update(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Updated Safely',
                'country_code' => 'FR',
                'currency_code' => 'EUR',
                'address' => [
                    'street' => '1 Rue de la Paix',
                    'country' => 'FR',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Safely')
            ->assertJsonPath('data.country_code', 'FR')
            ->assertJsonPath('data.currency_code', 'EUR');

        $this->company->refresh();
        $this->assertSame('FR', $this->company->country_code);
        $this->assertSame('EUR', $this->company->currency);
        $this->assertSame('1 Rue de la Paix', $this->company->address_street);
    }

    public function test_update_persists_line_designation_override_setting(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'line_designation_override_enabled' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.line_designation_override_enabled', true);

        $this->assertTrue($this->company->refresh()->line_designation_override_enabled);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'line_designation_override_enabled' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.line_designation_override_enabled', false);

        $this->assertFalse($this->company->refresh()->line_designation_override_enabled);
    }

    public function test_show_response_contract_keys_are_unchanged(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/settings/company');

        $response->assertOk()->assertJsonStructure(['data' => [
            'name', 'legal_name', 'tax_id', 'registration_number',
            'address' => ['street', 'city', 'postal_code', 'country'],
            'phone', 'email', 'website', 'logo_url', 'primary_color',
            'country_code', 'currency_code', 'timezone', 'date_format', 'locale',
        ]]);
    }

    public function test_viewer_can_get_company_settings(): void
    {
        $response = $this->actingAs($this->viewerUser, 'sanctum')
            ->getJson('/api/v1/settings/company');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'name',
                ],
                'meta',
            ]);
    }

    public function test_unauthenticated_user_cannot_get_company_settings(): void
    {
        $response = $this->getJson('/api/v1/settings/company');

        $response->assertUnauthorized();
    }

    public function test_user_without_view_permission_cannot_get_settings(): void
    {
        // Create user without any role
        $noPermUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Perm User',
            'email' => 'noperm@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($noPermUser, 'sanctum')
            ->getJson('/api/v1/settings/company');

        $response->assertForbidden();
    }

    // ==================== UPDATE Company Settings Tests ====================

    public function test_admin_can_update_company_settings(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Updated Company Name',
                'legal_name' => 'Updated Legal Name SARL',
                'tax_id' => 'FR98765432101',
                'registration_number' => 'RCS 987 654 321',
                'phone' => '+33 9 87 65 43 21',
                'email' => 'updated@company.fr',
                'website' => 'https://updated-company.fr',
                'primary_color' => '#00FF00',
                'timezone' => 'Europe/London',
                'date_format' => 'YYYY-MM-DD',
                'locale' => 'en',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Company Name')
            ->assertJsonPath('data.legal_name', 'Updated Legal Name SARL')
            ->assertJsonPath('data.tax_id', 'FR98765432101')
            ->assertJsonPath('data.primary_color', '#00FF00')
            ->assertJsonPath('data.timezone', 'Europe/London');

        // Verify database was updated
        $this->company->refresh();
        $this->assertEquals('Updated Company Name', $this->company->name);
        $this->assertEquals('Updated Legal Name SARL', $this->company->legal_name);
        $this->assertEquals('Europe/London', $this->company->timezone);
    }

    public function test_fiscal_settings_permission_is_seeded_to_admin_only(): void
    {
        $this->assertTrue($this->adminUser->can('settings.fiscal.update'));
        $this->assertFalse($this->viewerUser->can('settings.fiscal.update'));
    }

    public function test_cosmetic_editor_can_update_cosmetic_settings_without_fiscal_permission(): void
    {
        $editor = $this->createCosmeticEditor();

        $response = $this->actingAs($editor, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Cosmetic Name',
                'phone' => '+33 1 02 03 04 05',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Cosmetic Name')
            ->assertJsonPath('data.phone', '+33 1 02 03 04 05');
    }

    public function test_cosmetic_editor_cannot_update_fiscal_identity_in_french(): void
    {
        $editor = $this->createCosmeticEditor();

        $response = $this->actingAs($editor, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->withHeader('X-Language', 'fr')
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Must Not Persist',
                'legal_name' => 'Identité Interdite SARL',
                'tax_id' => 'FR00000000000',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath(
                'error.message',
                "Vous n'avez pas l'autorisation de modifier l'identité fiscale de la société.",
            );

        $this->company->refresh();
        $this->assertSame('Test Company', $this->company->name);
        $this->assertSame('Test Company SARL', $this->company->legal_name);
        $this->assertNull($this->company->tax_id);
    }

    public function test_immutable_country_validation_precedes_fiscal_permission_check(): void
    {
        $editor = $this->createCosmeticEditor();

        $response = $this->actingAs($editor, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'country_code' => 'TN',
            ]);

        $this->assertJsonValidationErrors($response, ['country_code'])
            ->assertJsonPath(
                'error.errors.country_code.0',
                'The company country is fixed at creation. Correction requires a support-operations procedure that is not yet available.',
            );

        $this->company->refresh();
        $this->assertSame('FR', $this->company->country_code);
    }

    public function test_mixed_fiscal_and_immutable_payload_without_fiscal_permission_persists_nothing(): void
    {
        $editor = $this->createCosmeticEditor();

        $response = $this->actingAs($editor, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'legal_name' => 'Must Not Persist SARL',
                'country_code' => 'TN',
            ]);

        $this->assertJsonValidationErrors($response, ['country_code'])
            ->assertJsonPath(
                'error.errors.country_code.0',
                'The company country is fixed at creation. Correction requires a support-operations procedure that is not yet available.',
            );

        $this->company->refresh();
        $this->assertSame('FR', $this->company->country_code);
        $this->assertSame('Test Company SARL', $this->company->legal_name);
    }

    public function test_idempotent_fiscal_identity_values_do_not_require_fiscal_permission(): void
    {
        $editor = $this->createCosmeticEditor();

        $response = $this->actingAs($editor, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Safe Resubmission',
                'legal_name' => 'Test Company SARL',
                'tax_id' => null,
                'registration_number' => null,
                'country_code' => 'FR',
                'currency_code' => 'EUR',
                'address' => ['country' => 'FR'],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Safe Resubmission');
    }

    public function test_fiscal_identity_update_records_dedicated_old_new_audit_event(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'legal_name' => 'Audited Legal Name SARL',
                'tax_id' => 'FR12345678901',
                'registration_number' => 'RCS 123 456 789',
            ]);

        $response->assertOk();

        $auditEvent = AuditEvent::query()
            ->where('event_type', 'company.fiscal_identity_updated')
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertSame('company', $auditEvent->aggregate_type);
        $this->assertSame($this->company->id, $auditEvent->aggregate_id);
        $this->assertEquals([
            'legal_name' => ['old' => 'Test Company SARL', 'new' => 'Audited Legal Name SARL'],
            'tax_id' => ['old' => null, 'new' => 'FR12345678901'],
            'registration_number' => ['old' => null, 'new' => 'RCS 123 456 789'],
        ], $auditEvent->payload['changes']);
    }

    public function test_audit_write_failure_rolls_back_company_update(): void
    {
        $originalLegalName = $this->company->legal_name;
        AuditEvent::creating(static function (): void {
            throw new \RuntimeException('Forced audit write failure.');
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->adminUser, 'sanctum')
                ->withHeader('X-Company-Id', $this->company->id)
                ->patchJson('/api/v1/settings/company', [
                    'legal_name' => 'Must Roll Back SARL',
                ]);

            $this->fail('Expected the forced audit write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced audit write failure.', $exception->getMessage());
        }

        $this->company->refresh();
        $this->assertSame($originalLegalName, $this->company->legal_name);
        $this->assertDatabaseMissing('audit_events', [
            'company_id' => $this->company->id,
            'event_type' => 'company.fiscal_identity_updated',
        ]);
    }

    public function test_can_update_address(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'address' => [
                    'street' => '456 New Street',
                    'city' => 'Lyon',
                    'postal_code' => '69001',
                    'country' => 'FR',
                ],
            ]);

        $response->assertOk();

        $this->company->refresh();
        $this->assertEquals('456 New Street', $this->company->address_street);
        $this->assertEquals('Lyon', $this->company->address_city);
        $this->assertEquals('69001', $this->company->address_postal_code);
    }

    public function test_viewer_cannot_update_company_settings(): void
    {
        $response = $this->actingAs($this->viewerUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Hacked Name',
            ]);

        $response->assertForbidden();

        // Verify database was NOT updated
        $this->company->refresh();
        $this->assertEquals('Test Company', $this->company->name);
    }

    public function test_unauthenticated_user_cannot_update_company_settings(): void
    {
        $response = $this->patchJson('/api/v1/settings/company', [
            'name' => 'Hacked Name',
        ]);

        $response->assertUnauthorized();
    }

    public function test_update_validates_name_required_when_empty(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'name' => '',
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_update_validates_name_max_length(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'name' => str_repeat('a', 256),
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_update_validates_email_format(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'email' => 'invalid-email',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_update_validates_website_url(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'website' => 'not-a-url',
            ]);

        $this->assertApiValidationErrors($response, ['website']);
    }

    public function test_update_validates_primary_color_hex(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'primary_color' => 'red',
            ]);

        $this->assertApiValidationErrors($response, ['primary_color']);
    }

    public function test_update_accepts_valid_hex_colors(): void
    {
        // Should accept valid 6-char hex colors
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'primary_color' => '#AABBCC',
            ]);

        $response->assertOk();
    }

    public function test_update_validates_timezone(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'timezone' => 'Invalid/Timezone',
            ]);

        $this->assertApiValidationErrors($response, ['timezone']);
    }

    public function test_update_validates_locale_max_length(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'locale' => 'invalid_locale_too_long',
            ]);

        $this->assertApiValidationErrors($response, ['locale']);
    }

    public function test_update_validates_country_code(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'country_code' => 'INVALID',
            ]);

        $this->assertApiValidationErrors($response, ['country_code']);
    }

    public function test_update_validates_currency_code(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'currency_code' => 'INVALID',
            ]);

        $this->assertApiValidationErrors($response, ['currency_code']);
    }

    public function test_update_creates_audit_log(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Audited Company',
                'timezone' => 'America/New_York',
            ]);

        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id,
            'event_type' => 'tenant.settings_updated',
            'aggregate_type' => 'tenant',
            'aggregate_id' => $this->tenant->id,
        ]);

        // Verify the payload contains the changes
        $auditEvent = AuditEvent::where('event_type', 'tenant.settings_updated')
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($auditEvent);
        $payload = $auditEvent->payload;
        $this->assertArrayHasKey('changes', $payload);
    }

    public function test_partial_update_only_changes_provided_fields(): void
    {
        $this->company->update([
            'name' => 'Original Name',
            'phone' => '+33 1 11 11 11 11',
            'timezone' => 'Europe/Paris',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/settings/company', [
                'phone' => '+33 2 22 22 22 22',
            ]);

        $response->assertOk();

        $this->company->refresh();
        $this->assertEquals('Original Name', $this->company->name); // Unchanged
        $this->assertEquals('+33 2 22 22 22 22', $this->company->phone); // Changed
        $this->assertEquals('Europe/Paris', $this->company->timezone); // Unchanged
    }

    private function createCosmeticEditor(): User
    {
        $editor = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cosmetic Editor',
            'email' => 'cosmetic-editor@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $editor->givePermissionTo('settings.update');

        UserCompanyMembership::create([
            'user_id' => $editor->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return $editor;
    }

    // ==================== LOGO Upload Tests ====================

    public function test_admin_can_upload_logo(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('logo.png', 500, 500)->size(1024);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'logo_url',
                ],
                'meta',
            ]);

        // Verify file was stored
        $this->tenant->refresh();
        $this->assertNotNull($this->tenant->logo_path);
        Storage::disk('public')->assertExists($this->tenant->logo_path);
    }

    public function test_viewer_cannot_upload_logo(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('logo.png', 500, 500);

        $response = $this->actingAs($this->viewerUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $response->assertForbidden();
    }

    public function test_logo_upload_validates_file_required(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', []);

        $this->assertApiValidationErrors($response, ['logo']);
    }

    public function test_logo_upload_validates_max_size(): void
    {
        Storage::fake('public');

        // 3MB file (exceeds 2MB limit)
        $file = UploadedFile::fake()->image('logo.png', 500, 500)->size(3072);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $this->assertApiValidationErrors($response, ['logo']);
    }

    public function test_logo_upload_accepts_png(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('logo.png', 500, 500);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $response->assertOk();
    }

    public function test_logo_upload_accepts_jpg(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('logo.jpg', 500, 500);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $response->assertOk();
    }

    public function test_logo_upload_accepts_svg(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('logo.svg', 100, 'image/svg+xml');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $response->assertOk();
    }

    public function test_logo_upload_rejects_invalid_file_type(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $this->assertApiValidationErrors($response, ['logo']);
    }

    public function test_logo_upload_deletes_old_logo(): void
    {
        Storage::fake('public');

        // Upload first logo
        $file1 = UploadedFile::fake()->image('logo1.png', 500, 500);
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file1,
            ]);

        $this->tenant->refresh();
        $oldLogoPath = $this->tenant->logo_path;
        Storage::disk('public')->assertExists($oldLogoPath);

        // Upload second logo
        $file2 = UploadedFile::fake()->image('logo2.png', 500, 500);
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file2,
            ]);

        // Old logo should be deleted
        Storage::disk('public')->assertMissing($oldLogoPath);

        // New logo should exist
        $this->tenant->refresh();
        Storage::disk('public')->assertExists($this->tenant->logo_path);
    }

    public function test_logo_upload_creates_audit_log(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('logo.png', 500, 500);

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id,
            'event_type' => 'tenant.logo_updated',
            'aggregate_type' => 'tenant',
            'aggregate_id' => $this->tenant->id,
        ]);
    }

    // ==================== DELETE Logo Tests ====================

    public function test_admin_can_delete_logo(): void
    {
        Storage::fake('public');

        // First upload a logo
        $file = UploadedFile::fake()->image('logo.png', 500, 500);
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $this->tenant->refresh();
        $logoPath = $this->tenant->logo_path;
        Storage::disk('public')->assertExists($logoPath);

        // Delete the logo
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson('/api/v1/settings/company/logo');

        $response->assertOk();

        // Logo should be deleted
        Storage::disk('public')->assertMissing($logoPath);
        $this->tenant->refresh();
        $this->assertNull($this->tenant->logo_path);
    }

    public function test_viewer_cannot_delete_logo(): void
    {
        Storage::fake('public');

        // First upload a logo as admin
        $file = UploadedFile::fake()->image('logo.png', 500, 500);
        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        // Try to delete as viewer
        $response = $this->actingAs($this->viewerUser, 'sanctum')
            ->deleteJson('/api/v1/settings/company/logo');

        $response->assertForbidden();

        // Logo should still exist
        $this->tenant->refresh();
        $this->assertNotNull($this->tenant->logo_path);
    }

    public function test_delete_logo_when_no_logo_exists(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson('/api/v1/settings/company/logo');

        $response->assertOk()
            ->assertJsonPath('data.message', 'No logo to delete');
    }

    // ==================== Currency/Country Regression Tests ====================

    public function test_tunisian_company_returns_tnd_currency(): void
    {
        $this->company->update([
            'name' => 'Cafe Tunis',
            'country_code' => 'TN',
            'currency' => 'TND',
            'timezone' => 'Africa/Tunis',
            'locale' => 'fr',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/settings/company');

        $response->assertOk()
            ->assertJsonPath('data.currency_code', 'TND')
            ->assertJsonPath('data.country_code', 'TN');
    }

    public function test_address_country_falls_back_to_country_code_when_address_is_null(): void
    {
        $this->company->update([
            'address_street' => null,
            'address_city' => null,
            'address_postal_code' => null,
            'country_code' => 'TN',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/settings/company');

        $response->assertOk()
            ->assertJsonPath('data.address.country', 'TN')
            ->assertJsonPath('data.address.street', null)
            ->assertJsonPath('data.address.city', null)
            ->assertJsonPath('data.address.postal_code', null);
    }

    public function test_address_country_uses_address_value_when_set(): void
    {
        $this->company->update([
            'address_street' => '10 Avenue Habib Bourguiba',
            'address_city' => 'Tunis',
            'address_postal_code' => '1000',
            'country_code' => 'TN',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/settings/company');

        $response->assertOk()
            ->assertJsonPath('data.address.country', 'TN')
            ->assertJsonPath('data.address.street', '10 Avenue Habib Bourguiba')
            ->assertJsonPath('data.address.city', 'Tunis');
    }
}
