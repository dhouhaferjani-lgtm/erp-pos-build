<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Guards that vertical-specific product metadata (parapharmacy / automotive)
 * is REJECTED with 422 when the authenticated tenant's vertical does not
 * permit it — rather than being silently dropped by the controller.
 *
 * Uses `retail` as the neutral vertical for rejection cases: it enables the
 * `Inventory` module (so requests pass the `module:Inventory` route gate and
 * reach validation) but is neither parapharmacy nor automotive.
 */
class ProductMetadataVerticalGuardTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    /**
     * Build a fully-authenticated tenant context for the given vertical.
     *
     * @return array{user: User, company: Company}
     */
    private function makeContext(Vertical $vertical, string $slug): array
    {
        $tenant = Tenant::create([
            'name' => "Test {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Test {$slug} Company",
            'legal_name' => "Test {$slug} Company LLC",
            'tax_id' => 'TAX'.strtoupper($slug),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => "user@{$slug}.test",
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        return ['user' => $user, 'company' => $company];
    }

    /** @test */
    public function it_rejects_parapharmacy_metadata_for_a_non_parapharmacy_vertical(): void
    {
        $ctx = $this->makeContext(Vertical::Retail, 'retail-pp');

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Generic Widget',
                'sku' => 'WIDGET-001',
                'parapharmacy_metadata' => [
                    'category' => 'supplement',
                    'dosage_form' => 'tablet',
                ],
            ]);

        $this->assertApiValidationErrors($response, ['parapharmacy_metadata']);
    }

    /**
     * @test
     *
     * A bare `prohibited` rule treats null / `[]` as "empty" and lets them
     * through; the guard must reject any PRESENCE of the disallowed key.
     */
    public function it_rejects_empty_parapharmacy_metadata_for_a_non_parapharmacy_vertical(): void
    {
        foreach (['null' => null, 'empty-array' => []] as $label => $value) {
            $ctx = $this->makeContext(Vertical::Retail, "retail-pp-{$label}");

            $response = $this->actingAs($ctx['user'], 'sanctum')
                ->postJson('/api/v1/products', [
                    'name' => 'Generic Widget',
                    'sku' => "WIDGET-PP-{$label}",
                    'parapharmacy_metadata' => $value,
                ]);

            $this->assertApiValidationErrors($response, ['parapharmacy_metadata']);
        }
    }

    /** @test */
    public function it_rejects_automotive_metadata_for_a_non_automotive_vertical(): void
    {
        $ctx = $this->makeContext(Vertical::Retail, 'retail-auto');

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Generic Widget',
                'sku' => 'WIDGET-002',
                'automotive_metadata' => [
                    'is_universal_fit' => true,
                ],
            ]);

        $this->assertApiValidationErrors($response, ['automotive_metadata']);
    }

    /**
     * @test
     *
     * Asserts the guard ALLOWS parapharmacy metadata through validation for a
     * parapharmacy tenant. We assert the absence of a validation error for the
     * metadata key (rather than 201) because the product-create controller path
     * has an unrelated pre-existing SQLite failure on `product_images` (the
     * in-progress media subsystem dropped that table on dev); the validation
     * contract is what this test owns.
     */
    public function it_allows_parapharmacy_metadata_for_a_parapharmacy_vertical(): void
    {
        $ctx = $this->makeContext(Vertical::Parapharmacy, 'parapharmacy-ok');

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Vitamin C',
                'sku' => 'VITC-001',
                'parapharmacy_metadata' => [
                    'category' => 'supplement',
                    'dosage_form' => 'tablet',
                ],
            ]);

        $response->assertJsonMissingValidationErrors(['parapharmacy_metadata']);
    }

    /** @test */
    public function it_allows_automotive_metadata_for_an_automotive_vertical(): void
    {
        $ctx = $this->makeContext(Vertical::Mechanic, 'mechanic-ok');

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Brake Pad',
                'sku' => 'BRAKE-100',
                'automotive_metadata' => [
                    'is_universal_fit' => true,
                ],
            ]);

        $response->assertJsonMissingValidationErrors(['automotive_metadata']);
    }
}
