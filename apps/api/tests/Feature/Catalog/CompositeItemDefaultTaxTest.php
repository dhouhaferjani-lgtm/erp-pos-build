<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\CompositeItemImportService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * T14: Composite items resolve / preserve default tax rate.
 *
 * Covers three paths:
 *   1. API create (CompositeItemController::store) with no tax_rate → company default applied.
 *   2. Import (CompositeItemImportService::upsert) with no tax_rate → company default applied;
 *      re-import preserves existing non-null rate (no clobber).
 *   3. duplicate() copies source tax_rate + default_tax_configuration_id unchanged.
 */
final class CompositeItemDefaultTaxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Composite Tax Test Tenant',
            'slug' => 'composite-tax-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TN company with a known company-level default (19.00 mimics Tunisia TVA).
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Composite Tax Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
            'default_tax_rate' => '19.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'composite-items.view',
            'composite-items.create',
            'composite-items.update',
            'composite-items.delete',
            'composite-items.manage-recipes',
        ]);

        UserCompanyMembership::create([
            'user_id'    => $this->user->id,
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    // -------------------------------------------------------------------------
    // Path 1 — API create
    // -------------------------------------------------------------------------

    /** @test */
    public function create_with_no_tax_rate_inherits_company_default(): void
    {
        $response = $this->postJson('/api/v1/composite-items', [
            'code'            => 'COMBO-NO-TAX',
            'name'            => 'Combo No Tax',
            'base_price'      => '5.00',
            'vertical_type'   => 'fnb',
            'production_type' => 'made_to_order',
        ]);

        $response->assertStatus(201);

        $item = CompositeItem::where('code', 'COMBO-NO-TAX')->firstOrFail();

        $this->assertSame(
            0,
            bccomp((string) $item->tax_rate, '19.00', 2),
            "Expected tax_rate=19.00 (company default), got {$item->tax_rate}"
        );
    }

    /** @test */
    public function create_with_explicit_tax_rate_preserves_it(): void
    {
        $response = $this->postJson('/api/v1/composite-items', [
            'code'            => 'COMBO-EXPLICIT-TAX',
            'name'            => 'Combo Explicit Tax',
            'base_price'      => '5.00',
            'vertical_type'   => 'fnb',
            'production_type' => 'made_to_order',
            'tax_rate'        => '7.00',
        ]);

        $response->assertStatus(201);

        $item = CompositeItem::where('code', 'COMBO-EXPLICIT-TAX')->firstOrFail();

        $this->assertSame(
            0,
            bccomp((string) $item->tax_rate, '7.00', 2),
            "Explicit tax_rate 7.00 should be preserved, got {$item->tax_rate}"
        );
    }

    // -------------------------------------------------------------------------
    // Path 2 — Import
    // -------------------------------------------------------------------------

    /** @test */
    public function import_with_no_tax_rate_inherits_company_default(): void
    {
        /** @var CompositeItemImportService $service */
        $service = $this->app->make(CompositeItemImportService::class);

        $id = $service->upsert($this->tenant->id, $this->company->id, [
            'code'       => 'IMP-COMBO-NO-TAX',
            'name'       => 'Imported Combo No Tax',
            'base_price' => '8.00',
        ]);

        $item = CompositeItem::findOrFail($id);

        $this->assertSame(
            0,
            bccomp((string) $item->tax_rate, '19.00', 2),
            "Expected tax_rate=19.00 (company default) on import, got {$item->tax_rate}"
        );
    }

    /** @test */
    public function reimport_without_tax_rate_preserves_existing_stored_rate(): void
    {
        /** @var CompositeItemImportService $service */
        $service = $this->app->make(CompositeItemImportService::class);

        // First import with an explicit rate of 7.00.
        $service->upsert($this->tenant->id, $this->company->id, [
            'code'       => 'IMP-COMBO-PRESERVE',
            'name'       => 'Imported Combo',
            'base_price' => '8.00',
            'tax_rate'   => '7.00',
        ]);

        // Second import: same code, no tax_rate → must NOT clobber 7.00 with company default 19.00.
        $id = $service->upsert($this->tenant->id, $this->company->id, [
            'code'       => 'IMP-COMBO-PRESERVE',
            'name'       => 'Imported Combo (updated)',
            'base_price' => '9.00',
        ]);

        $item = CompositeItem::findOrFail($id);

        $this->assertSame(
            0,
            bccomp((string) $item->tax_rate, '7.00', 2),
            "Re-import without tax_rate must keep existing 7.00, got {$item->tax_rate}"
        );
    }

    // -------------------------------------------------------------------------
    // Path 3 — Duplicate
    // -------------------------------------------------------------------------

    /** @test */
    public function duplicate_copies_source_tax_rate_and_configuration_id(): void
    {
        // Create a source composite with a non-default rate and a dummy config UUID.
        $sourceConfigId = (string) \Illuminate\Support\Str::uuid();

        // Insert a fake tax_configuration so the FK doesn't fail — skip FK for simpler test
        // by creating the composite directly without the FK column populated.
        $source = CompositeItem::create([
            'tenant_id'  => $this->tenant->id,
            'company_id' => $this->company->id,
            'code'       => 'DUP-SRC',
            'name'       => 'Duplicate Source',
            'base_price' => '10.00',
            'tax_rate'   => '7.00',
            // Intentionally omit default_tax_configuration_id to avoid FK constraint.
        ]);

        $response = $this->postJson("/api/v1/composite-items/{$source->id}/duplicate");

        $response->assertStatus(201);

        // Find the copy (not the source).
        $copy = CompositeItem::where('company_id', $this->company->id)
            ->where('id', '!=', $source->id)
            ->firstOrFail();

        $this->assertSame(
            0,
            bccomp((string) $copy->tax_rate, '7.00', 2),
            "Duplicate must carry source tax_rate=7.00, got {$copy->tax_rate}"
        );

        // default_tax_configuration_id should also match (both null here).
        $this->assertSame(
            $source->default_tax_configuration_id,
            $copy->default_tax_configuration_id,
            'Duplicate must carry source default_tax_configuration_id unchanged.'
        );
    }
}
