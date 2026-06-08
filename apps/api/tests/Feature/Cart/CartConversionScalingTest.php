<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\Vertical;
use App\Modules\Cart\Application\Services\CartConversionService;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\CurrencyScale;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3.6 — CartConversionService scale propagation.
 *
 * The old impl multiplied qty × unit_price with a hard-coded scale-3
 * intermediate (`bcmul($qty, $unitPrice, 3)`) and accumulated the subtotal at
 * scale 3 too. That hard-coded 3 is the TND maximum; for a EUR company
 * (scale 2) it over-retained a third decimal the currency does not have,
 * persisting line totals / subtotals like `4.011` instead of the correct
 * `4.01`.
 *
 * The fix derives the boundary scale from the injected
 * CurrencyScaleResolverInterface and uses a scale()+1 intermediate for the
 * money-producing multiply, rounding ONCE at the currency boundary via
 * CurrencyScale::bcformat.
 *
 * Gold case (EUR, scale 2):
 *   qty = 3, unit_price = 1.337  →  exact product = 4.011
 *   old (hard-coded scale 3) persisted 4.011 (extra, non-EUR digit)
 *   new (boundary scale 2)    persists 4.01  (correct EUR cent)
 */
class CartConversionScalingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Scale Mechanic',
            'slug' => 'scale-conv-mech',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        // EUR company → resolver returns scale 2 (the currency boundary).
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Scale Company',
            'legal_name' => 'Scale Company LLC',
            'tax_id' => 'SCALETAX',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Scale User',
            'email' => 'scale-conv@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Scale Customer',
            'type' => PartnerType::Customer,
            'country_code' => 'FR',
        ]);

        // Bind company context so the resolver resolves EUR scale 2.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function sales_order_line_total_rounds_at_the_currency_boundary_not_hard_coded_three(): void
    {
        $service = app(CartConversionService::class);

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        // qty 3 × unit_price 1.337 = exact 4.011.
        // Old hard-coded scale 3 → 4.011 (extra digit EUR doesn't have).
        // New boundary scale 2 → 4.01.
        $item = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Boundary Widget',
            'quantity' => '3.00',
            'unit_price' => '1.337',
            'currency' => 'EUR',
            'source' => 'catalog',
        ]);

        $document = $service->convertToSalesOrder($cart, [$item->id], $this->customer->id);

        $line = DocumentLine::where('document_id', $document->id)->firstOrFail();

        // EUR boundary = 2 decimals. 4.011 → 4.01 (NOT the hard-coded scale-3 4.011).
        $this->assertSame('4.01', CurrencyScale::bcformat($line->line_total, 2));
        $this->assertSame('4.01', CurrencyScale::bcformat($document->subtotal, 2));

        // The persisted value must NOT carry a non-EUR third decimal.
        // Old hard-coded scale 3 stored 4.011; the boundary-aware fix stores
        // 4.01, which the decimal:3 cast surfaces as the zero-filled 4.010.
        $this->assertSame('4.010', CurrencyScale::bcformat($line->line_total, 3));
        $this->assertSame('4.010', CurrencyScale::bcformat($document->subtotal, 3));
    }
}
