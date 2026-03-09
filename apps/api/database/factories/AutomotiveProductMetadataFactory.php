<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Product\Domain\AutomotiveProductMetadata;
use App\Modules\Product\Domain\Enums\AutomotiveArticleStatus;
use App\Modules\Product\Domain\Enums\BrandQualityTier;
use App\Modules\Product\Domain\Enums\PlatformLinkStatus;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomotiveProductMetadata>
 */
class AutomotiveProductMetadataFactory extends Factory
{
    protected $model = AutomotiveProductMetadata::class;

    public function definition(): array
    {
        $brands = ['Bosch', 'TRW', 'Valeo', 'Continental', 'Denso', 'NGK', 'Mann-Filter', 'Sachs', 'Brembo', 'Hella'];

        return [
            'product_id' => Product::factory(),
            'platform_link_status' => PlatformLinkStatus::Unlinked,
            'article_number' => fake()->regexify('[A-Z0-9]{2,4}[- ]?[0-9]{4,8}'),
            'supplier_brand' => fake()->randomElement($brands),
            'product_group_name' => fake()->randomElement([
                'Brake Pads', 'Oil Filters', 'Spark Plugs', 'Air Filters',
                'Timing Belts', 'Clutch Kits', 'Shock Absorbers', 'Water Pumps',
            ]),
            'brand_quality_tier' => fake()->randomElement(BrandQualityTier::cases()),
            'article_status' => AutomotiveArticleStatus::Active,
            'confidence_score' => 0,
            'data_source' => 'manual',
            'is_universal_fit' => fake()->boolean(10),
        ];
    }

    /**
     * Linked to platform article.
     */
    public function linked(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform_article_id' => fake()->uuid(),
            'platform_link_status' => PlatformLinkStatus::Linked,
            'confidence_score' => fake()->numberBetween(70, 100),
            'data_source' => 'platform',
            'platform_synced_at' => now(),
        ]);
    }

    /**
     * Tire product metadata.
     */
    public function tire(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_group_name' => 'Tires',
            'tire_width' => fake()->randomElement([155, 165, 175, 185, 195, 205, 215, 225, 235, 245, 255]),
            'tire_aspect_ratio' => fake()->randomElement([40, 45, 50, 55, 60, 65, 70]),
            'tire_rim_diameter' => fake()->randomElement([14, 15, 16, 17, 18, 19, 20]),
            'tire_speed_rating' => fake()->randomElement(['H', 'V', 'W', 'Y', 'T', 'S']),
            'tire_load_index' => fake()->numberBetween(75, 110),
            'tire_season' => fake()->randomElement(['summer', 'winter', 'all_season']),
        ]);
    }

    /**
     * Glass product metadata.
     */
    public function glass(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_group_name' => 'Car Glass',
            'glass_type' => fake()->randomElement(['windshield', 'rear_window', 'side_window', 'quarter_glass', 'sunroof']),
            'glass_tinting' => fake()->randomElement(['clear', 'tinted', 'privacy', 'heated']),
        ]);
    }

    /**
     * Discontinued article.
     */
    public function discontinued(): static
    {
        return $this->state(fn (array $attributes) => [
            'article_status' => AutomotiveArticleStatus::Discontinued,
        ]);
    }
}
