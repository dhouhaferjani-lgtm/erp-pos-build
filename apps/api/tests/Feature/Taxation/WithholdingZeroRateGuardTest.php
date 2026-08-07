<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P1 fiscal guard (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md
 * #1, §20-69): a zero effective withholding rate/amount must never manufacture
 * a fiscal certificate — it would sequence a fictitious "0.000" document into
 * the withholding hash chain (TEJ-exportable, PDF-printable, chain-signed on
 * issue). HTTP-boundary coverage for the DIRECT-create endpoint
 * (`POST /withholding/certificates`), which does NOT catch
 * `\DomainException` the way `PaymentController::store()` does — so it must
 * surface as a 422 at the HTTP boundary (`WithholdingCertificateController::
 * store()`'s existing `catch (\DomainException $e)` -> `CREATION_FAILED`).
 *
 * The payment-linked path (which SWALLOWS the same guard exception via
 * `PaymentController::store()`'s existing try/catch, so the payment still
 * settles at full gross — MTP-WHT-04) is covered in
 * `Tests\Feature\Treasury\PaymentTest::
 * test_store_settles_at_full_gross_with_no_certificate_when_withholding_rate_is_zero`.
 * The service-level guard + no-sequence-consumption assertion is covered in
 * `WithholdingCertificateTest::
 * it_refuses_to_create_a_certificate_when_the_effective_withholding_amount_is_zero`.
 */
class WithholdingZeroRateGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Zero Rate Guard Tenant',
            'slug' => 'zero-rate-guard-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zero Rate Guard Co',
            'legal_name' => 'Zero Rate Guard Co LLC',
            'tax_id' => 'TAX-ZRG',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zero Rate Guard Admin',
            'email' => 'zrg-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Zero Rate Partner',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);
    }

    public function test_store_refuses_a_zero_effective_rate_certificate_with_422_and_consumes_no_sequence(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/withholding/certificates', [
            'direction' => 'purchase',
            'partner_id' => $this->partner->id,
            'currency' => 'TND',
            'gross_amount' => '1000.000',
            'manual_rate_percentage' => '0',
            'override_reason' => 'Zero rate guard probe',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CREATION_FAILED');
        // Fix round F (gate docs/superpowers/reviews/2026-08-07-r2h-zerorate-gate.md
        // #2): `error.code = CREATION_FAILED` is ALSO emitted for "No
        // applicable withholding rule found" (service, non-override branch)
        // — pinning `error.message` too proves it is genuinely the
        // zero-withholding guard that fired, not a different \DomainException
        // sharing the same code.
        $response->assertJsonPath('error.message', 'Withholding amount is zero; no certificate is created.');

        $this->assertDatabaseCount('withholding_certificates', 0);

        // No sequence consumption: the next REAL certificate still gets 0001.
        $real = $this->actingAs($this->user)->postJson('/api/v1/withholding/certificates', [
            'direction' => 'purchase',
            'partner_id' => $this->partner->id,
            'currency' => 'TND',
            'gross_amount' => '1000.000',
            'manual_rate_percentage' => '5',
            'override_reason' => 'Real rate probe',
        ]);
        $real->assertStatus(201);

        $certificate = WithholdingCertificate::findOrFail($real->json('data.id'));
        $this->assertSame(
            sprintf('WHT-%04d-0001', now()->year),
            $certificate->certificate_number,
            'the refused zero-rate attempt must not have consumed a certificate-number sequence slot'
        );
    }

    public function test_store_refuses_a_zero_gross_amount_certificate_with_422(): void
    {
        // A nonzero rate on a zero gross amount is also a zero EFFECTIVE amount
        // (§20-69's "effective rate/amount") and must be refused for the same
        // fiscal reason.
        $response = $this->actingAs($this->user)->postJson('/api/v1/withholding/certificates', [
            'direction' => 'purchase',
            'partner_id' => $this->partner->id,
            'currency' => 'TND',
            'gross_amount' => '0.000',
            'manual_rate_percentage' => '10',
            'override_reason' => 'Zero gross guard probe',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'CREATION_FAILED');
        $response->assertJsonPath('error.message', 'Withholding amount is zero; no certificate is created.');
        $this->assertDatabaseCount('withholding_certificates', 0);
    }

    /**
     * Nonzero path unchanged: a genuine nonzero rate on a nonzero gross amount
     * must keep creating a certificate exactly as before this fix.
     */
    public function test_store_still_creates_a_certificate_for_a_nonzero_effective_rate(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/withholding/certificates', [
            'direction' => 'purchase',
            'partner_id' => $this->partner->id,
            'currency' => 'TND',
            'gross_amount' => '1000.000',
            'manual_rate_percentage' => '1.50',
            'override_reason' => 'Nonzero path unchanged',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('withholding_certificates', [
            'partner_id' => $this->partner->id,
            'gross_amount' => '1000.000',
            'withholding_amount' => '15.000',
        ]);
    }
}
