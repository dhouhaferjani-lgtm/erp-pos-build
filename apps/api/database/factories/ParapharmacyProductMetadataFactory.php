<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParapharmacyProductMetadata>
 */
class ParapharmacyProductMetadataFactory extends Factory
{
    protected $model = ParapharmacyProductMetadata::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'category' => fake()->randomElement(ParapharmacyCategory::cases()),
            'dosage_form' => fake()->randomElement(DosageForm::cases()),
            'usage_instructions' => fake()->sentence(10),
            'warnings' => fake()->sentence(8),
            'contraindications' => fake()->optional()->sentence(8),
            'minimum_age' => fake()->optional()->numberBetween(0, 18),
            'age_restriction' => fake()->optional()->randomElement(AgeRestriction::cases()),
            'requires_consultation' => fake()->boolean(30),
            'regulatory_code' => fake()->optional()->regexify('[A-Z]{2}[0-9]{6}'),
            'storage_requirements' => fake()->optional()->randomElement([
                'Store in cool, dry place',
                'Refrigerate after opening',
                'Keep away from sunlight',
            ]),
        ];
    }

    /**
     * Create a supplement product metadata.
     */
    public function supplement(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => ParapharmacyCategory::Supplement,
            'dosage_form' => fake()->randomElement([
                DosageForm::Capsule,
                DosageForm::Tablet,
                DosageForm::Softgel,
                DosageForm::Powder,
            ]),
            'requires_consultation' => true,
        ]);
    }

    /**
     * Create a cosmetic product metadata.
     */
    public function cosmetic(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => ParapharmacyCategory::Cosmetic,
            'dosage_form' => fake()->randomElement([
                DosageForm::Cream,
                DosageForm::Gel,
                DosageForm::Lotion,
                DosageForm::Spray,
            ]),
            'requires_consultation' => false,
        ]);
    }

    /**
     * Create a medical device product metadata.
     */
    public function medicalDevice(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => ParapharmacyCategory::MedicalDevice,
            'dosage_form' => null,
            'requires_consultation' => true,
            'age_restriction' => AgeRestriction::AdultOnly,
        ]);
    }
}
