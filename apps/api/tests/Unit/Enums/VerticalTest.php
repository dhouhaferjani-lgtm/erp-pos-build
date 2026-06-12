<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Vertical;
use Tests\TestCase;

class VerticalTest extends TestCase
{
    public function test_vertical_enum_has_all_12_cases(): void
    {
        $cases = Vertical::cases();

        $this->assertCount(
            12,
            $cases,
            'Vertical enum should have exactly 12 cases'
        );
    }

    public function test_vertical_enum_has_mechanic_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::Mechanic'),
            'Vertical enum should have Mechanic case'
        );
    }

    public function test_vertical_enum_has_pharmacy_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::Pharmacy'),
            'Vertical enum should have Pharmacy case'
        );
    }

    public function test_vertical_enum_has_restaurant_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::Restaurant'),
            'Vertical enum should have Restaurant case'
        );
    }

    public function test_vertical_enum_has_coffee_shop_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::CoffeeShop'),
            'Vertical enum should have CoffeeShop case'
        );
    }

    public function test_vertical_enum_has_retail_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::Retail'),
            'Vertical enum should have Retail case'
        );
    }

    public function test_vertical_enum_has_fashion_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::Fashion'),
            'Vertical enum should have Fashion case'
        );
    }

    public function test_vertical_enum_has_body_shop_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::BodyShop'),
            'Vertical enum should have BodyShop case'
        );
    }

    public function test_vertical_enum_has_parts_retailer_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::PartsRetailer'),
            'Vertical enum should have PartsRetailer case'
        );
    }

    public function test_vertical_enum_has_car_glass_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::CarGlass'),
            'Vertical enum should have CarGlass case'
        );
    }

    public function test_vertical_enum_has_tire_shop_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::TireShop'),
            'Vertical enum should have TireShop case'
        );
    }

    public function test_vertical_enum_has_service_station_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::ServiceStation'),
            'Vertical enum should have ServiceStation case'
        );
    }

    public function test_vertical_enum_has_parapharmacy_case(): void
    {
        $this->assertTrue(
            defined(Vertical::class.'::Parapharmacy'),
            'Vertical enum should have Parapharmacy case'
        );
    }

    public function test_all_verticals_have_correct_string_values(): void
    {
        $expectedValues = [
            'mechanic',
            'pharmacy',
            'restaurant',
            'coffee_shop',
            'retail',
            'fashion',
            'body_shop',
            'parts_retailer',
            'car_glass',
            'tire_shop',
            'service_station',
            'parapharmacy',
        ];

        $actualValues = array_map(fn ($case) => $case->value, Vertical::cases());

        $this->assertEquals(
            $expectedValues,
            $actualValues,
            'Vertical enum values should match expected snake_case strings'
        );
    }

    public function test_can_create_from_string(): void
    {
        $mechanic = Vertical::from('mechanic');
        $pharmacy = Vertical::from('pharmacy');

        $this->assertSame(Vertical::Mechanic, $mechanic);
        $this->assertSame(Vertical::Pharmacy, $pharmacy);
    }

    public function test_from_throws_exception_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);

        Vertical::from('invalid');
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $result = Vertical::tryFrom('invalid');

        $this->assertNull($result);
    }

    public function test_all_verticals_have_label_method(): void
    {
        foreach (Vertical::cases() as $vertical) {
            $label = $vertical->label();

            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function test_all_verticals_have_description_method(): void
    {
        foreach (Vertical::cases() as $vertical) {
            $description = $vertical->description();

            $this->assertIsString($description);
            $this->assertNotEmpty($description);
        }
    }

    public function test_all_verticals_have_product_method(): void
    {
        foreach (Vertical::cases() as $vertical) {
            $product = $vertical->product();

            $this->assertIsString($product);
            $this->assertContains($product, ['izipos', 'otospex']);
        }
    }

    public function test_mechanic_belongs_to_otospex(): void
    {
        $this->assertEquals('otospex', Vertical::Mechanic->product());
    }

    public function test_body_shop_belongs_to_otospex(): void
    {
        $this->assertEquals('otospex', Vertical::BodyShop->product());
    }

    public function test_parts_retailer_belongs_to_otospex(): void
    {
        $this->assertEquals('otospex', Vertical::PartsRetailer->product());
    }

    public function test_car_glass_belongs_to_otospex(): void
    {
        $this->assertEquals('otospex', Vertical::CarGlass->product());
    }

    public function test_tire_shop_belongs_to_otospex(): void
    {
        $this->assertEquals('otospex', Vertical::TireShop->product());
    }

    public function test_service_station_belongs_to_otospex(): void
    {
        $this->assertEquals('otospex', Vertical::ServiceStation->product());
    }

    public function test_pharmacy_belongs_to_izipos(): void
    {
        $this->assertEquals('izipos', Vertical::Pharmacy->product());
    }

    public function test_restaurant_belongs_to_izipos(): void
    {
        $this->assertEquals('izipos', Vertical::Restaurant->product());
    }

    public function test_coffee_shop_belongs_to_izipos(): void
    {
        $this->assertEquals('izipos', Vertical::CoffeeShop->product());
    }

    public function test_retail_belongs_to_izipos(): void
    {
        $this->assertEquals('izipos', Vertical::Retail->product());
    }

    public function test_fashion_belongs_to_izipos(): void
    {
        $this->assertEquals('izipos', Vertical::Fashion->product());
    }

    public function test_parapharmacy_belongs_to_izipos(): void
    {
        $this->assertEquals('izipos', Vertical::Parapharmacy->product());
    }

    public function test_can_get_all_labels(): void
    {
        $labels = Vertical::labels();

        $this->assertIsArray($labels);
        $this->assertCount(12, $labels);
        $this->assertArrayHasKey('mechanic', $labels);
        $this->assertArrayHasKey('pharmacy', $labels);
    }

    public function test_is_backed_enum(): void
    {
        $reflection = new \ReflectionEnum(Vertical::class);

        $this->assertTrue(
            $reflection->isBacked(),
            'Vertical should be a backed enum'
        );
    }

    public function test_backing_type_is_string(): void
    {
        $reflection = new \ReflectionEnum(Vertical::class);

        $this->assertEquals(
            'string',
            $reflection->getBackingType()->getName(),
            'Vertical backing type should be string'
        );
    }

    public function test_can_filter_by_product(): void
    {
        $iziposVerticals = Vertical::forProduct('izipos');
        $otospexVerticals = Vertical::forProduct('otospex');

        $this->assertIsArray($iziposVerticals);
        $this->assertIsArray($otospexVerticals);
        $this->assertGreaterThan(0, count($iziposVerticals));
        $this->assertGreaterThan(0, count($otospexVerticals));

        // Verify all IziPOS verticals belong to IziPOS
        foreach ($iziposVerticals as $vertical) {
            $this->assertEquals('izipos', $vertical->product());
        }

        // Verify all Otospex verticals belong to Otospex
        foreach ($otospexVerticals as $vertical) {
            $this->assertEquals('otospex', $vertical->product());
        }
    }
}
