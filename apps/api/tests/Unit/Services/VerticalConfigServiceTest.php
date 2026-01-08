<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Vertical;
use App\Services\VerticalConfigService;
use Tests\TestCase;

class VerticalConfigServiceTest extends TestCase
{
    private VerticalConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VerticalConfigService;
    }

    public function test_get_vertical_config_returns_array(): void
    {
        $config = $this->service->getVerticalConfig(Vertical::Mechanic);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('name', $config);
        $this->assertArrayHasKey('label', $config);
        $this->assertArrayHasKey('description', $config);
        $this->assertArrayHasKey('product', $config);
        $this->assertArrayHasKey('compatible_extras', $config);
        $this->assertArrayHasKey('default_modules', $config);
    }

    public function test_get_vertical_config_for_mechanic(): void
    {
        $config = $this->service->getVerticalConfig(Vertical::Mechanic);

        $this->assertEquals('mechanic', $config['name']);
        $this->assertEquals('Mechanic', $config['label']);
        $this->assertEquals('otospex', $config['product']);
        $this->assertContains('Vehicle', $config['default_modules']);
        $this->assertContains('Workshop', $config['default_modules']);
    }

    public function test_get_vertical_config_for_pharmacy(): void
    {
        $config = $this->service->getVerticalConfig(Vertical::Pharmacy);

        $this->assertEquals('pharmacy', $config['name']);
        $this->assertEquals('Pharmacy', $config['label']);
        $this->assertEquals('izipos', $config['product']);
        $this->assertContains('BatchExpiry', $config['default_modules']);
        $this->assertNotContains('Vehicle', $config['default_modules']);
    }

    public function test_get_label_returns_string(): void
    {
        $label = $this->service->getLabel(Vertical::Restaurant);

        $this->assertIsString($label);
        $this->assertEquals('Restaurant', $label);
    }

    public function test_get_description_returns_string(): void
    {
        $description = $this->service->getDescription(Vertical::CoffeeShop);

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_product_returns_string(): void
    {
        $product = $this->service->getProduct(Vertical::Mechanic);

        $this->assertIsString($product);
        $this->assertEquals('otospex', $product);
    }

    public function test_get_compatible_extras_returns_array(): void
    {
        $extras = $this->service->getCompatibleExtras(Vertical::Mechanic);

        $this->assertIsArray($extras);
        $this->assertContains('Appointments', $extras);
        $this->assertContains('Fleet', $extras);
    }

    public function test_get_default_modules_returns_array(): void
    {
        $modules = $this->service->getDefaultModules(Vertical::Mechanic);

        $this->assertIsArray($modules);
        $this->assertContains('Identity', $modules);
        $this->assertContains('Tenant', $modules);
        $this->assertContains('Catalog', $modules);
        $this->assertContains('Vehicle', $modules);
        $this->assertContains('Workshop', $modules);
        $this->assertContains('Sales', $modules);
        $this->assertContains('Inventory', $modules);
        $this->assertContains('Treasury', $modules);
        $this->assertContains('Accounting', $modules);
    }

    public function test_get_verticals_for_product_izipos(): void
    {
        $verticals = $this->service->getVerticalsForProduct('izipos');

        $this->assertIsArray($verticals);
        $this->assertCount(6, $verticals);
        $this->assertContains(Vertical::Pharmacy, $verticals);
        $this->assertContains(Vertical::Restaurant, $verticals);
        $this->assertContains(Vertical::CoffeeShop, $verticals);
        $this->assertContains(Vertical::Retail, $verticals);
        $this->assertContains(Vertical::Fashion, $verticals);
        $this->assertContains(Vertical::Parapharmacy, $verticals);
    }

    public function test_get_verticals_for_product_otospex(): void
    {
        $verticals = $this->service->getVerticalsForProduct('otospex');

        $this->assertIsArray($verticals);
        $this->assertCount(6, $verticals);
        $this->assertContains(Vertical::Mechanic, $verticals);
        $this->assertContains(Vertical::BodyShop, $verticals);
        $this->assertContains(Vertical::PartsRetailer, $verticals);
        $this->assertContains(Vertical::CarGlass, $verticals);
        $this->assertContains(Vertical::TireShop, $verticals);
        $this->assertContains(Vertical::ServiceStation, $verticals);
    }

    public function test_service_is_singleton(): void
    {
        $service1 = app(VerticalConfigService::class);
        $service2 = app(VerticalConfigService::class);

        $this->assertSame(
            $service1,
            $service2,
            'VerticalConfigService should be registered as singleton'
        );
    }
}
