<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * TN matricule fiscale at the partner HTTP boundary (research spec
 * 2026-08-23 §3.3).
 *
 * Covers the two defects the P0 named:
 *  1. the 13-character canonical MF was rejected at entry by a 3-letter-only
 *     regex that no sealed payload could ever satisfy;
 *  2. the check self-disabled entirely when `country_code` was absent from the
 *     request, so a TN tenant that never sends it got zero enforcement.
 */
class PartnerTunisianMatriculeTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'TN Tenant',
            'slug' => 'tn-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TN Company',
            'legal_name' => 'TN Company SARL',
            'tax_id' => '1234567AM000',
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TN User',
            'email' => 'tn-user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_canonical_thirteen_character_matricule_is_accepted(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Cliente',
                'type' => 'customer',
                'country_code' => 'TN',
                'vat_number' => '1234567AMN000',
            ]);

        $response->assertCreated();

        $this->assertSame(
            '1234567AMN000',
            Partner::query()->where('name', 'Societe Cliente')->value('vat_number')
        );
    }

    public function test_legacy_twelve_character_matricule_is_still_accepted(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Legacy',
                'type' => 'customer',
                'country_code' => 'TN',
                'vat_number' => '1234567AM000',
            ]);

        $response->assertCreated();
    }

    /**
     * The long slashed form is normalized to the COMPACT stored convention —
     * the form seeded data and canonical device payloads already use.
     */
    public function test_long_slashed_form_is_normalized_to_the_compact_stored_form(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Slashed',
                'type' => 'customer',
                'country_code' => 'TN',
                'vat_number' => '1234567/A/M/N/000',
            ]);

        $response->assertCreated();

        $this->assertSame(
            '1234567AMN000',
            Partner::query()->where('name', 'Societe Slashed')->value('vat_number')
        );
    }

    public function test_lowercase_and_spaced_input_is_normalized_before_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Spaced',
                'type' => 'customer',
                'country_code' => 'TN',
                'vat_number' => ' 1234567 am 000 ',
            ]);

        $response->assertCreated();

        $this->assertSame(
            '1234567AM000',
            Partner::query()->where('name', 'Societe Spaced')->value('vat_number')
        );
    }

    /**
     * The self-disabling hole: with no `country_code` in the request the rule
     * used to return early and accept anything. It now falls back to the
     * company's country (TN here).
     */
    public function test_missing_country_code_falls_back_to_the_company_country(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Sans Pays',
                'type' => 'customer',
                'vat_number' => 'NOT-A-MATRICULE',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    public function test_missing_country_code_still_accepts_a_valid_matricule(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Sans Pays Valide',
                'type' => 'customer',
                'vat_number' => '1234567/A/M/000',
            ]);

        $response->assertCreated();

        $this->assertSame(
            '1234567AM000',
            Partner::query()->where('name', 'Societe Sans Pays Valide')->value('vat_number')
        );
    }

    /**
     * A genuinely-foreign partner keeps its own country's rule; the company
     * fallback must not force TN onto it.
     */
    public function test_explicit_foreign_country_code_wins_over_the_company_fallback(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Fournisseur Francais',
                'type' => 'supplier',
                'country_code' => 'FR',
                'vat_number' => 'FR12345678901',
            ]);

        $response->assertCreated();

        $this->assertSame(
            'FR12345678901',
            Partner::query()->where('name', 'Fournisseur Francais')->value('vat_number')
        );
    }

    public function test_six_digit_matricule_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Courte',
                'type' => 'customer',
                'country_code' => 'TN',
                'vat_number' => '123456AM000',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    public function test_single_letter_matricule_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Une Lettre',
                'type' => 'customer',
                'country_code' => 'TN',
                'vat_number' => '1234567A000',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    public function test_update_normalizes_and_validates_against_the_stored_partner_country(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Societe A Modifier',
            'type' => 'customer',
            'country_code' => 'TN',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/partners/'.$partner->id, [
                'vat_number' => '1234567/A/M/N/000',
            ]);

        $response->assertOk();

        $this->assertSame('1234567AMN000', $partner->fresh()?->vat_number);
    }

    public function test_update_rejects_a_non_canonical_matricule_without_country_code_in_the_request(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Societe A Refuser',
            'type' => 'customer',
            'country_code' => 'TN',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/partners/'.$partner->id, [
                'vat_number' => '1234567A000',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }
}
