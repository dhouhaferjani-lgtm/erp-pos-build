<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use Tests\TestCase;

class CompositeItemCostTest extends TestCase
{
    public function test_effective_cost_returns_null_when_no_cost_data(): void
    {
        $item = new CompositeItem;
        $item->manual_cost = null;
        $this->assertNull($item->getEffectiveCost());
    }

    public function test_effective_cost_returns_manual_cost_when_no_recipe(): void
    {
        $item = new CompositeItem;
        $item->manual_cost = '1.5000';
        $this->assertEquals('1.5000', $item->getEffectiveCost());
    }

    public function test_effective_cost_returns_recipe_cost_when_recipe_exists(): void
    {
        $recipe = new Recipe;
        $recipe->calculated_cost = '2.3000';
        $item = new CompositeItem;
        $item->manual_cost = '1.5000';
        $item->setRelation('activeRecipe', $recipe);
        $this->assertEquals('2.3000', $item->getEffectiveCost());
    }

    public function test_effective_cost_returns_zero_recipe_cost_without_fallback(): void
    {
        $recipe = new Recipe;
        $recipe->calculated_cost = '0.0000';
        $item = new CompositeItem;
        $item->manual_cost = '1.5000';
        $item->setRelation('activeRecipe', $recipe);
        $this->assertEquals('0.0000', $item->getEffectiveCost());
    }

    public function test_effective_cost_falls_back_when_recipe_cost_is_null(): void
    {
        $recipe = new Recipe;
        $recipe->calculated_cost = null;
        $item = new CompositeItem;
        $item->manual_cost = '1.5000';
        $item->setRelation('activeRecipe', $recipe);
        $this->assertEquals('1.5000', $item->getEffectiveCost());
    }

    public function test_margin_percentage_calculated_correctly(): void
    {
        $item = new CompositeItem;
        $item->base_price = '4.5000';
        $item->manual_cost = '1.2000';
        $this->assertEquals(73.33, $item->getMarginPercentage());
    }

    public function test_margin_percentage_returns_null_when_no_cost(): void
    {
        $item = new CompositeItem;
        $item->base_price = '4.5000';
        $item->manual_cost = null;
        $this->assertNull($item->getMarginPercentage());
    }

    public function test_margin_percentage_returns_null_when_base_price_is_zero(): void
    {
        $item = new CompositeItem;
        $item->base_price = '0.0000';
        $item->manual_cost = '1.2000';
        $this->assertNull($item->getMarginPercentage());
    }
}
