<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class VerticalsConfigTest extends TestCase
{
    public function test_verticals_config_file_exists(): void
    {
        $config = config('verticals');

        $this->assertIsArray(
            $config,
            'verticals config should be an array'
        );
    }

    public function test_verticals_config_has_all_12_verticals(): void
    {
        $config = config('verticals');

        $this->assertCount(
            12,
            $config,
            'verticals config should have exactly 12 verticals'
        );
    }

    public function test_all_verticals_are_present(): void
    {
        $config = config('verticals');

        $expectedVerticals = [
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

        foreach ($expectedVerticals as $vertical) {
            $this->assertArrayHasKey(
                $vertical,
                $config,
                "verticals config should have {$vertical} key"
            );
        }
    }

    public function test_each_vertical_has_required_keys(): void
    {
        $config = config('verticals');
        $requiredKeys = ['name', 'label', 'description', 'product', 'compatible_extras', 'default_modules'];

        foreach ($config as $verticalKey => $verticalConfig) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $verticalConfig,
                    "Vertical {$verticalKey} should have {$key} key"
                );
            }
        }
    }

    public function test_mechanic_belongs_to_otospex(): void
    {
        $product = config('verticals.mechanic.product');

        $this->assertEquals('otospex', $product);
    }

    public function test_pharmacy_belongs_to_izipos(): void
    {
        $product = config('verticals.pharmacy.product');

        $this->assertEquals('izipos', $product);
    }

    public function test_compatible_extras_are_arrays(): void
    {
        $config = config('verticals');

        foreach ($config as $verticalKey => $verticalConfig) {
            $this->assertIsArray(
                $verticalConfig['compatible_extras'],
                "Vertical {$verticalKey} compatible_extras should be array"
            );
        }
    }

    public function test_default_modules_are_arrays_and_not_empty(): void
    {
        $config = config('verticals');

        foreach ($config as $verticalKey => $verticalConfig) {
            $this->assertIsArray(
                $verticalConfig['default_modules'],
                "Vertical {$verticalKey} default_modules should be array"
            );
            $this->assertNotEmpty(
                $verticalConfig['default_modules'],
                "Vertical {$verticalKey} default_modules should not be empty"
            );
        }
    }
}
