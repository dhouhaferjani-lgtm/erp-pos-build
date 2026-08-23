<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
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

    /**
     * THE LOAD-BEARING ROUND TRIP, end to end.
     *
     * For every input shape an operator can submit: POST it through the real
     * partner endpoint (so `prepareForValidation`, the country fallback and
     * the validation closure all actually run), read back what was PERSISTED,
     * and feed that stored value to the real sealed-payload gate as both
     * `buyer.tax_number` and `seller.tax_number`.
     *
     * If this passes, no matricule that the partner boundary accepts can be
     * refused at seal time — which is precisely the defect that made the
     * ACCOUNT_CHARGE B2B lane unsealable (research spec §3.3).
     *
     * The unit-level version of this property lives in
     * TunisianMatriculeConvergenceTest, but that one exercises the shared
     * predicate only; this one exercises the wiring (gate R1 F-7).
     */
    public function test_every_accepted_matricule_persists_a_value_the_seal_gate_accepts(): void
    {
        $shapes = [
            'legacy compact 12-char' => ['1234567AM000', '1234567AM000'],
            'canonical compact 13-char' => ['1234567AMN001', '1234567AMN001'],
            'long slashed 12-char form' => ['1234567/A/M/002', '1234567AM002'],
            'long slashed 13-char form' => ['1234567/A/M/N/003', '1234567AMN003'],
            'spaced lowercase input' => [' 1234567 am 004 ', '1234567AM004'],
            'eight-digit legacy arm' => ['12345678AM005', '12345678AM005'],
            'eight-digit canonical arm' => ['12345678AMN006', '12345678AMN006'],
        ];

        $assertSeals = new ReflectionMethod(FiscalPayloadConstraintValidator::class, 'assertTaxNumberForCountry');
        $assertSeals->setAccessible(true);
        $sealGate = (new ReflectionClass(FiscalPayloadConstraintValidator::class))->newInstanceWithoutConstructor();

        $i = 0;
        foreach ($shapes as $label => [$submitted, $expectedStored]) {
            $name = 'Round Trip '.$i++;

            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/v1/partners', [
                    'name' => $name,
                    'type' => 'customer',
                    'country_code' => 'TN',
                    'vat_number' => $submitted,
                ]);

            $response->assertCreated();

            $stored = Partner::query()->where('name', $name)->value('vat_number');
            $this->assertSame($expectedStored, $stored, "Stored-value drift for: {$label}");

            foreach (['buyer.tax_number' => true, 'seller.tax_number' => false] as $path => $isBuyer) {
                try {
                    $assertSeals->invoke($sealGate, $stored, 'TN', $path, $isBuyer);
                } catch (RuntimeException $e) {
                    $this->fail(sprintf(
                        'Seal gate rejected a partner-accepted matricule (%s -> stored %s) at %s: %s',
                        $label,
                        (string) $stored,
                        $path,
                        $e->getMessage()
                    ));
                }
                $this->addToAssertionCount(1);
            }
        }
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

    /**
     * F-2 regression guard (gate R1).
     *
     * Partners onboarded through the migration wizard have `country_code = NULL`
     * and an arbitrary tax id (`PartiesRowMapper` writes `vat_number` and no
     * country). The web form always echoes both fields back —
     * `country_code: data.country_code || null` plus the unchanged
     * `vat_number` (`PartnerForm.tsx:405`) — so a name-only edit submits a
     * vat_number the operator never touched.
     *
     * An UNCHANGED vat_number must never be re-litigated: the spec's intent is
     * that a NEW or CHANGED matricule must be sealable, not that historical
     * rows become uneditable.
     */
    public function test_name_only_edit_of_a_legacy_null_country_partner_is_not_blocked(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Legacy Importe',
            'type' => 'customer',
            'country_code' => null,
            'vat_number' => 'FR12345678901',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/partners/'.$partner->id, [
                'name' => 'Legacy Importe Renomme',
                'country_code' => null,
                'vat_number' => 'FR12345678901',
            ]);

        $response->assertOk();

        $fresh = Partner::query()->findOrFail($partner->id);
        $this->assertSame('Legacy Importe Renomme', $fresh->name);
        $this->assertSame('FR12345678901', $fresh->vat_number, 'An untouched vat_number must not be rewritten.');
    }

    /**
     * Same shape, but the acting company is FR — the F-2 blast radius was not
     * limited to TN, because `resolvedTaxCountryCode()` is country-agnostic.
     */
    public function test_name_only_edit_of_a_legacy_null_country_partner_is_not_blocked_in_an_fr_company(): void
    {
        $this->company->update(['country_code' => 'FR']);

        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Legacy FR',
            'type' => 'supplier',
            'country_code' => null,
            'vat_number' => '732829320',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/partners/'.$partner->id, [
                'name' => 'Legacy FR Renomme',
                'country_code' => null,
                'vat_number' => '732829320',
            ]);

        $response->assertOk();
        $this->assertSame('732829320', $partner->fresh()?->vat_number);
    }

    /**
     * The grandfather clause must not reopen the hole: a CHANGED vat_number
     * under the exact same web-form payload shape is still validated against
     * the resolved (company-inferred) country.
     */
    public function test_changed_vat_number_on_a_legacy_null_country_partner_is_still_validated(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Legacy A Corriger',
            'type' => 'customer',
            'country_code' => null,
            'vat_number' => 'FR12345678901',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/partners/'.$partner->id, [
                'name' => 'Legacy A Corriger',
                'country_code' => null,
                'vat_number' => 'NOT-A-MATRICULE',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    /**
     * ...and a CHANGED value that IS a valid matricule goes through, proving
     * the grandfather clause is keyed on "unchanged", not on "legacy row".
     */
    public function test_changed_valid_matricule_on_a_legacy_null_country_partner_is_accepted(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Legacy A Corriger 2',
            'type' => 'customer',
            'country_code' => null,
            'vat_number' => 'FR12345678901',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/partners/'.$partner->id, [
                'name' => 'Legacy A Corriger 2',
                'country_code' => null,
                'vat_number' => '1234567/A/M/N/000',
            ]);

        $response->assertOk();
        $this->assertSame('1234567AMN000', $partner->fresh()?->vat_number);
    }

    /**
     * The failure message must name the country it inferred, otherwise
     * "the selected country" is a lie when nothing was selected.
     */
    public function test_inferred_country_is_named_in_the_failure_message(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Societe Message',
                'type' => 'customer',
                'vat_number' => 'NOT-A-MATRICULE',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'TN',
            (string) json_encode($response->json('error.errors.vat_number'))
        );
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
