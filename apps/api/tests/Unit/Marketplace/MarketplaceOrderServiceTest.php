<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Marketplace\Application\Services\MarketplaceOrderService;
use App\Modules\Marketplace\Domain\Enums\MarketplaceOrderStatus;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Enums\SellerType;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceOrderService $service;

    private Tenant $sellerTenant;

    private Company $sellerCompany;

    private Tenant $buyerTenant;

    private Company $buyerCompany;

    private MarketplaceSeller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MarketplaceOrderService::class);

        $this->sellerTenant = Tenant::create([
            'name' => 'Seller',
            'slug' => 'seller-unit',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->sellerCompany = Company::create([
            'tenant_id' => $this->sellerTenant->id,
            'name' => 'Seller Co',
            'legal_name' => 'Seller Co LLC',
            'tax_id' => 'UNITTAX1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->buyerTenant = Tenant::create([
            'name' => 'Buyer',
            'slug' => 'buyer-unit',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->buyerCompany = Company::create([
            'tenant_id' => $this->buyerTenant->id,
            'name' => 'Buyer Co',
            'legal_name' => 'Buyer Co LLC',
            'tax_id' => 'UNITTAX2',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'seller_type' => SellerType::ErpTenant,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
            'commission_rate' => '5.000',
            'total_gmv' => '0.000',
            'total_orders' => 0,
            'total_items_sold' => 0,
            'gmv_current_month' => '0.000',
            'orders_current_month' => 0,
        ]);
    }

    /** @test */
    public function it_calculates_commission_from_subtotal_and_rate(): void
    {
        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $this->seller->id,
            'country_code' => 'TN',
            'price' => 100.000,
            'currency' => 'TND',
        ]);

        $order = $this->service->createOrder(
            items: [
                ['listing_id' => $listing->id, 'quantity' => '2'],
            ],
            buyerCompany: $this->buyerCompany,
        );

        // Subtotal = 100 * 2 = 200.000
        // Commission = 200 * 5/100 = 10.000
        // Total = 200 + 10 = 210.000
        $this->assertEquals('200.000', $order->subtotal);
        $this->assertEquals('10.000', $order->commission_amount);
        $this->assertEquals('5.00', $order->commission_rate);
        $this->assertEquals('210.000', $order->total);
    }

    /** @test */
    public function it_updates_seller_volume_counters(): void
    {
        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $this->seller->id,
            'country_code' => 'TN',
            'price' => 50.000,
            'currency' => 'TND',
        ]);

        $this->service->createOrder(
            items: [
                ['listing_id' => $listing->id, 'quantity' => '3'],
            ],
            buyerCompany: $this->buyerCompany,
        );

        $this->seller->refresh();

        // Subtotal = 50 * 3 = 150.000
        $this->assertEquals('150.000', $this->seller->total_gmv);
        $this->assertEquals(1, $this->seller->total_orders);
        $this->assertEquals(1, $this->seller->total_items_sold);
        $this->assertEquals('150.000', $this->seller->gmv_current_month);
        $this->assertEquals(1, $this->seller->orders_current_month);
    }

    /** @test */
    public function it_creates_order_with_pending_status(): void
    {
        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $this->seller->id,
            'country_code' => 'TN',
            'price' => 25.000,
            'currency' => 'TND',
        ]);

        $order = $this->service->createOrder(
            items: [
                ['listing_id' => $listing->id, 'quantity' => '1'],
            ],
            buyerCompany: $this->buyerCompany,
        );

        $this->assertEquals(MarketplaceOrderStatus::Pending, $order->order_status);
        $this->assertNotNull($order->buyer_document_id);
        $this->assertNotNull($order->seller_document_id);
    }
}
