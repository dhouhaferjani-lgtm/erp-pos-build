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

    public function test_ecommerce_is_an_optional_extra_for_pharmacy_retail_verticals(): void
    {
        // Owner decision 2026-06-12: e-commerce matters for pharmacies and
        // most retailers, but must be OPT-IN (super-admin activated), never
        // a default module.
        $verticalsWithEcommerce = ['pharmacy', 'parapharmacy', 'retail', 'fashion', 'parts_retailer'];

        foreach ($verticalsWithEcommerce as $verticalKey) {
            $extras = config("verticals.{$verticalKey}.compatible_extras");
            $defaults = config("verticals.{$verticalKey}.default_modules");

            $this->assertContains(
                'Ecommerce',
                $extras,
                "Vertical {$verticalKey} should offer Ecommerce as a compatible extra"
            );
            $this->assertNotContains(
                'Ecommerce',
                $defaults,
                "Vertical {$verticalKey} must NOT enable Ecommerce by default"
            );
        }
    }

    public function test_parapharmacy_vertical_enables_its_own_domain_module(): void
    {
        // Drift guard: Vertical::defaultModules() lists 'Parapharmacy' but the
        // runtime config had dropped it, which hides the Parapharmacy nav
        // group and blocks RequireModule-gated routes for the very vertical
        // the module was built for.
        $this->assertContains(
            'Parapharmacy',
            config('verticals.parapharmacy.default_modules'),
        );
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
