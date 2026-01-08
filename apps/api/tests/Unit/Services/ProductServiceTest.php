<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Product;
use App\Services\ProductService;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = new ProductService;
    }

    public function test_current_returns_product_enum(): void
    {
        $product = $this->productService->current();

        $this->assertInstanceOf(
            Product::class,
            $product,
            'current() should return a Product enum instance'
        );
    }

    public function test_current_returns_product_from_config(): void
    {
        // Set config to izipos
        config(['app.product' => 'izipos']);

        $product = $this->productService->current();

        $this->assertSame(
            Product::IziPOS,
            $product,
            'current() should return IziPOS when config is set to izipos'
        );
    }

    public function test_current_returns_otospex_when_configured(): void
    {
        // Set config to otospex
        config(['app.product' => 'otospex']);

        $product = $this->productService->current();

        $this->assertSame(
            Product::Otospex,
            $product,
            'current() should return Otospex when config is set to otospex'
        );
    }

    public function test_current_defaults_to_izipos_when_config_missing(): void
    {
        // Clear config
        config(['app.product' => null]);

        $product = $this->productService->current();

        $this->assertSame(
            Product::IziPOS,
            $product,
            'current() should default to IziPOS when config is null'
        );
    }

    public function test_is_izipos_returns_true_when_izipos(): void
    {
        config(['app.product' => 'izipos']);

        $this->assertTrue(
            $this->productService->isIziPOS(),
            'isIziPOS() should return true when current product is IziPOS'
        );
    }

    public function test_is_izipos_returns_false_when_otospex(): void
    {
        config(['app.product' => 'otospex']);

        $this->assertFalse(
            $this->productService->isIziPOS(),
            'isIziPOS() should return false when current product is Otospex'
        );
    }

    public function test_is_otospex_returns_true_when_otospex(): void
    {
        config(['app.product' => 'otospex']);

        $this->assertTrue(
            $this->productService->isOtospex(),
            'isOtospex() should return true when current product is Otospex'
        );
    }

    public function test_is_otospex_returns_false_when_izipos(): void
    {
        config(['app.product' => 'izipos']);

        $this->assertFalse(
            $this->productService->isOtospex(),
            'isOtospex() should return false when current product is IziPOS'
        );
    }

    public function test_name_returns_product_name(): void
    {
        config(['app.product' => 'izipos']);

        $name = $this->productService->name();

        $this->assertEquals(
            'izipos',
            $name,
            'name() should return the product name string'
        );
    }

    public function test_label_returns_product_label(): void
    {
        config(['app.product' => 'izipos']);

        $label = $this->productService->label();

        $this->assertEquals(
            'IziPOS',
            $label,
            'label() should return the product label'
        );
    }

    public function test_description_returns_product_description(): void
    {
        config(['app.product' => 'otospex']);

        $description = $this->productService->description();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_domains_returns_product_domains(): void
    {
        config(['app.product' => 'izipos']);

        $domains = $this->productService->domains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('izipos.com', $domains);
    }

    public function test_service_is_singleton(): void
    {
        $service1 = app(ProductService::class);
        $service2 = app(ProductService::class);

        $this->assertSame(
            $service1,
            $service2,
            'ProductService should be registered as singleton'
        );
    }
}
