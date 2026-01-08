<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Product;
use Tests\TestCase;

class ProductTest extends TestCase
{
    public function test_product_enum_has_izipos_case(): void
    {
        $this->assertTrue(
            defined(Product::class.'::IziPOS'),
            'Product enum should have IziPOS case'
        );
    }

    public function test_product_enum_has_otospex_case(): void
    {
        $this->assertTrue(
            defined(Product::class.'::Otospex'),
            'Product enum should have Otospex case'
        );
    }

    public function test_product_enum_only_has_two_cases(): void
    {
        $cases = Product::cases();

        $this->assertCount(
            2,
            $cases,
            'Product enum should only have exactly 2 cases'
        );
    }

    public function test_izipos_has_correct_value(): void
    {
        $this->assertEquals(
            'izipos',
            Product::IziPOS->value,
            'IziPOS case should have value "izipos"'
        );
    }

    public function test_otospex_has_correct_value(): void
    {
        $this->assertEquals(
            'otospex',
            Product::Otospex->value,
            'Otospex case should have value "otospex"'
        );
    }

    public function test_can_create_from_string(): void
    {
        $izipos = Product::from('izipos');
        $otospex = Product::from('otospex');

        $this->assertSame(Product::IziPOS, $izipos);
        $this->assertSame(Product::Otospex, $otospex);
    }

    public function test_from_throws_exception_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);

        Product::from('invalid');
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $result = Product::tryFrom('invalid');

        $this->assertNull($result);
    }

    public function test_izipos_label_method(): void
    {
        $this->assertEquals(
            'IziPOS',
            Product::IziPOS->label(),
            'IziPOS should have label "IziPOS"'
        );
    }

    public function test_otospex_label_method(): void
    {
        $this->assertEquals(
            'Otospex',
            Product::Otospex->label(),
            'Otospex should have label "Otospex"'
        );
    }

    public function test_izipos_description_method(): void
    {
        $description = Product::IziPOS->description();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_otospex_description_method(): void
    {
        $description = Product::Otospex->description();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_izipos_domains_method(): void
    {
        $domains = Product::IziPOS->domains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('izipos.com', $domains);
    }

    public function test_otospex_domains_method(): void
    {
        $domains = Product::Otospex->domains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('otospex.com', $domains);
    }

    public function test_can_get_all_labels(): void
    {
        $labels = Product::labels();

        $this->assertIsArray($labels);
        $this->assertCount(2, $labels);
        $this->assertArrayHasKey('izipos', $labels);
        $this->assertArrayHasKey('otospex', $labels);
        $this->assertEquals('IziPOS', $labels['izipos']);
        $this->assertEquals('Otospex', $labels['otospex']);
    }

    public function test_is_backed_enum(): void
    {
        $reflection = new \ReflectionEnum(Product::class);

        $this->assertTrue(
            $reflection->isBacked(),
            'Product should be a backed enum'
        );
    }

    public function test_backing_type_is_string(): void
    {
        $reflection = new \ReflectionEnum(Product::class);

        $this->assertEquals(
            'string',
            $reflection->getBackingType()->getName(),
            'Product backing type should be string'
        );
    }
}
