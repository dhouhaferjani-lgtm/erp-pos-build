<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\Vertical;
use App\Models\VerticalConfig;
use App\Services\VerticalConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerticalConfigServiceOverrideTest extends TestCase
{
    use RefreshDatabase;

    private VerticalConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VerticalConfigService;
    }

    public function test_no_db_row_returns_config_values(): void
    {
        $this->assertSame(
            config('verticals.mechanic.default_modules'),
            $this->service->getDefaultModules(Vertical::Mechanic)
        );
        $this->assertSame(
            config('verticals.mechanic.compatible_extras'),
            $this->service->getCompatibleExtras(Vertical::Mechanic)
        );
    }

    public function test_db_row_with_both_fields_overrides_both_lists(): void
    {
        VerticalConfig::query()->create([
            'vertical' => Vertical::Retail->value,
            'default_modules' => ['Identity', 'Tenant', 'Catalog', 'Sales'],
            'compatible_extras' => ['Loyalty'],
        ]);

        $this->assertSame(
            ['Identity', 'Tenant', 'Catalog', 'Sales'],
            $this->service->getDefaultModules(Vertical::Retail)
        );
        $this->assertSame(
            ['Loyalty'],
            $this->service->getCompatibleExtras(Vertical::Retail)
        );
    }

    public function test_null_db_field_falls_back_to_config_per_field(): void
    {
        VerticalConfig::query()->create([
            'vertical' => Vertical::Pharmacy->value,
            'default_modules' => ['Identity', 'Tenant', 'Catalog', 'Sales', 'BatchExpiry'],
            'compatible_extras' => null,
        ]);

        $this->assertSame(
            ['Identity', 'Tenant', 'Catalog', 'Sales', 'BatchExpiry'],
            $this->service->getDefaultModules(Vertical::Pharmacy)
        );
        $this->assertSame(
            config('verticals.pharmacy.compatible_extras'),
            $this->service->getCompatibleExtras(Vertical::Pharmacy)
        );
    }

    public function test_invalidate_vertical_picks_up_newly_written_row(): void
    {
        // Prime the cache with the "no row" sentinel.
        $this->assertSame(
            config('verticals.fashion.default_modules'),
            $this->service->getDefaultModules(Vertical::Fashion)
        );

        VerticalConfig::query()->create([
            'vertical' => Vertical::Fashion->value,
            'default_modules' => ['Identity', 'Tenant', 'Catalog'],
            'compatible_extras' => null,
        ]);

        // Cached sentinel still wins: the new row must NOT be visible yet.
        $this->assertSame(
            config('verticals.fashion.default_modules'),
            $this->service->getDefaultModules(Vertical::Fashion)
        );

        $this->service->invalidateVertical(Vertical::Fashion);

        // Fresh read picks up the row.
        $this->assertSame(
            ['Identity', 'Tenant', 'Catalog'],
            $this->service->getDefaultModules(Vertical::Fashion)
        );
    }

    public function test_get_vertical_config_merges_overrides_but_keeps_other_keys(): void
    {
        VerticalConfig::query()->create([
            'vertical' => Vertical::CoffeeShop->value,
            'default_modules' => ['Identity', 'Tenant', 'Catalog', 'Menu'],
            'compatible_extras' => ['Tables'],
        ]);

        $config = $this->service->getVerticalConfig(Vertical::CoffeeShop);

        $this->assertSame(['Identity', 'Tenant', 'Catalog', 'Menu'], $config['default_modules']);
        $this->assertSame(['Tables'], $config['compatible_extras']);
        $this->assertSame('coffee_shop', $config['name']);
        $this->assertSame('izipos', $config['product']);
    }
}
