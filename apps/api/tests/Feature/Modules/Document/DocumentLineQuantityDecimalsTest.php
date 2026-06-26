<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Each serialized document line must carry quantity_decimals derived from its
 * product's unit precision, so the document editor steps quantity by the unit
 * (pieces dp 0 → step 1; kg dp 3 → 0.001). Covers the saved-document reload
 * path: the eager-load chain lines.product.unitOfMeasure must be loaded on
 * every DocumentData response.
 */
final class DocumentLineQuantityDecimalsTest extends TestCase
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
            'name' => 'Qty Lines Tenant',
            'slug' => 'qty-lines-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Qty Lines Company',
            'legal_name' => 'Qty Lines Company LLC',
            'tax_id' => 'QTYLINES123',
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
            'name' => 'Qty Lines User',
            'email' => 'qty-lines@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Qty Lines Partner',
            'type' => 'customer',
            'code' => 'QTYLINES001',
        ]);
    }

    private function makeUnit(string $code, int $decimalPlaces): Unit
    {
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'cat-'.$code,
            'name' => 'Category '.$code,
            'is_system' => true,
            'is_active' => true,
        ]);

        return Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => $code,
            'name' => $code,
            'symbol' => $code,
            'decimal_places' => $decimalPlaces,
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function makeProduct(string $name, Unit $unit): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'unit_id' => $unit->id,
        ]);
    }

    #[Test]
    public function invoice_show_returns_per_line_quantity_decimals(): void
    {
        $piecesProduct = $this->makeProduct('Pieces Product', $this->makeUnit('pc', 0));
        $kgProduct = $this->makeProduct('Weighed Product', $this->makeUnit('kg', 3));

        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->partner->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [
                    ['product_id' => $piecesProduct->id, 'description' => 'Pieces', 'quantity' => 3, 'unit_price' => 10.00],
                    ['product_id' => $kgProduct->id, 'description' => 'Weighed', 'quantity' => 2, 'unit_price' => 20.00],
                ],
            ])->assertStatus(201);

        $invoiceId = $create->json('data.id');

        $show = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoiceId}")
            ->assertOk();

        $lines = collect($show->json('data.lines'));

        $this->assertSame(0, $lines->firstWhere('product_id', $piecesProduct->id)['quantity_decimals']);
        $this->assertSame(3, $lines->firstWhere('product_id', $kgProduct->id)['quantity_decimals']);
    }
}
