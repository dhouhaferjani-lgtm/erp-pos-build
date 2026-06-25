<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Procurement\Application\ProcurementPolicyResolver;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ProcurementPolicyResolver — SQLite-backed feature test.
 *
 * Covers:
 *   1. Vertical-default fallback (Pharmacy → received/three_way/warn)
 *   2. Positive tolerance values on default
 *   3. Persisted row overrides the vertical default
 *   4. Resolving a policy with bill_control_mode=ordered throws DomainException
 */
final class ProcurementPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProcurementPolicyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(ProcurementPolicyResolver::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Seed a Pharmacy tenant + company; return the company ID.
     */
    private function seedPharmacyCompany(): string
    {
        $tenant = Tenant::create([
            'name' => 'Procurement Test Tenant',
            'slug' => 'procurement-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Procurement Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        return $company->id;
    }

    // -------------------------------------------------------------------------
    // Test 1 — vertical default fallback
    // -------------------------------------------------------------------------

    public function test_returns_vertical_default_for_company_without_persisted_policy(): void
    {
        $companyId = $this->seedPharmacyCompany();

        $policy = $this->resolver->forCompany($companyId);

        $this->assertSame(BillControlMode::Received, $policy->bill_control_mode);
        $this->assertSame(MatchMode::ThreeWay, $policy->match_mode);
        $this->assertSame(MatchEnforcement::Warn, $policy->match_enforcement);
    }

    // -------------------------------------------------------------------------
    // Test 2 — tolerance values are non-negative
    // -------------------------------------------------------------------------

    public function test_default_tolerances_are_non_negative(): void
    {
        $companyId = $this->seedPharmacyCompany();

        $policy = $this->resolver->forCompany($companyId);

        // Compare as strings — never float money/qty (bccomp returns 0 if equal, 1 if >, -1 if <)
        $this->assertGreaterThanOrEqual(
            0,
            bccomp((string) $policy->variance_tolerance_percent, '0', 2),
            'variance_tolerance_percent must be >= 0',
        );
        $this->assertGreaterThanOrEqual(
            0,
            bccomp((string) $policy->variance_tolerance_max_amount, '0', 3),
            'variance_tolerance_max_amount must be >= 0',
        );
    }

    // -------------------------------------------------------------------------
    // Test 3 — persisted row overrides the vertical default
    // -------------------------------------------------------------------------

    public function test_persisted_row_overrides_vertical_default(): void
    {
        $companyId = $this->seedPharmacyCompany();

        ProcurementPolicy::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => Company::find($companyId)?->tenant_id,
            'company_id' => $companyId,
            'bill_control_mode' => BillControlMode::Received->value,
            'match_mode' => MatchMode::ThreeWay->value,
            'match_enforcement' => MatchEnforcement::Block->value,
            'variance_tolerance_percent' => '5.00',
            'variance_tolerance_max_amount' => '50.000',
        ]);

        $policy = $this->resolver->forCompany($companyId);

        $this->assertSame(MatchEnforcement::Block, $policy->match_enforcement);
    }

    // -------------------------------------------------------------------------
    // Test 4 — ordered mode throws DomainException
    // -------------------------------------------------------------------------

    public function test_throws_domain_exception_when_bill_control_mode_is_ordered(): void
    {
        $companyId = $this->seedPharmacyCompany();

        ProcurementPolicy::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => Company::find($companyId)?->tenant_id,
            'company_id' => $companyId,
            'bill_control_mode' => BillControlMode::Ordered->value,
            'match_mode' => MatchMode::ThreeWay->value,
            'match_enforcement' => MatchEnforcement::Warn->value,
            'variance_tolerance_percent' => '0.00',
            'variance_tolerance_max_amount' => '0.000',
        ]);

        $this->expectException(\DomainException::class);

        $this->resolver->forCompany($companyId);
    }
}
