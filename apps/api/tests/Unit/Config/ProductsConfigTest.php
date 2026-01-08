<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class ProductsConfigTest extends TestCase
{
    public function test_products_config_file_exists(): void
    {
        $config = config('products');

        $this->assertIsArray(
            $config,
            'products config should be an array'
        );
    }

    public function test_products_config_has_izipos(): void
    {
        $config = config('products');

        $this->assertArrayHasKey(
            'izipos',
            $config,
            'products config should have izipos key'
        );
    }

    public function test_products_config_has_otospex(): void
    {
        $config = config('products');

        $this->assertArrayHasKey(
            'otospex',
            $config,
            'products config should have otospex key'
        );
    }

    public function test_izipos_has_required_keys(): void
    {
        $izipos = config('products.izipos');

        $this->assertArrayHasKey('name', $izipos);
        $this->assertArrayHasKey('label', $izipos);
        $this->assertArrayHasKey('description', $izipos);
        $this->assertArrayHasKey('domains', $izipos);
    }

    public function test_otospex_has_required_keys(): void
    {
        $otospex = config('products.otospex');

        $this->assertArrayHasKey('name', $otospex);
        $this->assertArrayHasKey('label', $otospex);
        $this->assertArrayHasKey('description', $otospex);
        $this->assertArrayHasKey('domains', $otospex);
    }

    public function test_izipos_has_correct_name(): void
    {
        $name = config('products.izipos.name');

        $this->assertEquals('izipos', $name);
    }

    public function test_otospex_has_correct_name(): void
    {
        $name = config('products.otospex.name');

        $this->assertEquals('otospex', $name);
    }

    public function test_izipos_domains_is_array(): void
    {
        $domains = config('products.izipos.domains');

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('izipos.com', $domains);
    }

    public function test_otospex_domains_is_array(): void
    {
        $domains = config('products.otospex.domains');

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('otospex.com', $domains);
    }
}
