<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\IngredientTranslation;
use Illuminate\Database\Seeder;

class IngredientsSeeder extends Seeder
{
    public function run(): void
    {
        $ingredients = [
            [
                'slug' => 'vitamin-c-ascorbic-acid',
                'cas_number' => '50-81-7',
                'is_allergen' => false,
                'allergen_code' => null,
                'regulatory_status' => 'approved',
                'notes' => 'Essential water-soluble vitamin',
                'translations' => [
                    'en' => [
                        'name' => 'Vitamin C (Ascorbic Acid)',
                        'description' => 'Essential vitamin for immune function and collagen synthesis',
                    ],
                    'fr' => [
                        'name' => 'Vitamine C (Acide Ascorbique)',
                        'description' => 'Vitamine essentielle pour la fonction immunitaire et la synthèse du collagène',
                    ],
                ],
            ],
            [
                'slug' => 'vitamin-d3-cholecalciferol',
                'cas_number' => '67-97-0',
                'is_allergen' => false,
                'allergen_code' => null,
                'regulatory_status' => 'approved',
                'notes' => 'Fat-soluble vitamin essential for calcium absorption',
                'translations' => [
                    'en' => [
                        'name' => 'Vitamin D3 (Cholecalciferol)',
                        'description' => 'Essential for bone health and calcium absorption',
                    ],
                    'fr' => [
                        'name' => 'Vitamine D3 (Cholécalciférol)',
                        'description' => 'Essentielle pour la santé osseuse et l\'absorption du calcium',
                    ],
                ],
            ],
            [
                'slug' => 'omega-3-epa-dha',
                'cas_number' => '10417-94-4',
                'is_allergen' => true,
                'allergen_code' => 'EU14',
                'regulatory_status' => 'approved',
                'notes' => 'Fish-derived omega-3 fatty acids - allergen warning required',
                'translations' => [
                    'en' => [
                        'name' => 'Omega-3 (EPA/DHA)',
                        'description' => 'Essential fatty acids for heart and brain health. Contains fish.',
                    ],
                    'fr' => [
                        'name' => 'Oméga-3 (EPA/DHA)',
                        'description' => 'Acides gras essentiels pour la santé cardiaque et cérébrale. Contient du poisson.',
                    ],
                ],
            ],
            [
                'slug' => 'magnesium-oxide',
                'cas_number' => '1309-48-4',
                'is_allergen' => false,
                'allergen_code' => null,
                'regulatory_status' => 'approved',
                'notes' => 'Common magnesium supplement form',
                'translations' => [
                    'en' => [
                        'name' => 'Magnesium Oxide',
                        'description' => 'Supports muscle and nerve function, energy production',
                    ],
                    'fr' => [
                        'name' => 'Oxyde de Magnésium',
                        'description' => 'Soutient la fonction musculaire et nerveuse, production d\'énergie',
                    ],
                ],
            ],
            [
                'slug' => 'zinc-gluconate',
                'cas_number' => '4468-02-4',
                'is_allergen' => false,
                'allergen_code' => null,
                'regulatory_status' => 'approved',
                'notes' => 'Bioavailable zinc form',
                'translations' => [
                    'en' => [
                        'name' => 'Zinc Gluconate',
                        'description' => 'Supports immune function and wound healing',
                    ],
                    'fr' => [
                        'name' => 'Gluconate de Zinc',
                        'description' => 'Soutient la fonction immunitaire et la cicatrisation',
                    ],
                ],
            ],
            [
                'slug' => 'probiotics-lactobacillus',
                'cas_number' => null,
                'is_allergen' => true,
                'allergen_code' => 'EU07',
                'regulatory_status' => 'approved',
                'notes' => 'May contain milk derivatives',
                'translations' => [
                    'en' => [
                        'name' => 'Probiotics (Lactobacillus)',
                        'description' => 'Beneficial bacteria for digestive health. May contain milk.',
                    ],
                    'fr' => [
                        'name' => 'Probiotiques (Lactobacillus)',
                        'description' => 'Bactéries bénéfiques pour la santé digestive. Peut contenir du lait.',
                    ],
                ],
            ],
            [
                'slug' => 'collagen-hydrolysate',
                'cas_number' => '9064-67-9',
                'is_allergen' => true,
                'allergen_code' => 'EU14',
                'regulatory_status' => 'approved',
                'notes' => 'Marine or bovine source - check product labeling',
                'translations' => [
                    'en' => [
                        'name' => 'Collagen Hydrolysate',
                        'description' => 'Supports skin elasticity and joint health. May contain fish.',
                    ],
                    'fr' => [
                        'name' => 'Collagène Hydrolysé',
                        'description' => 'Soutient l\'élasticité de la peau et la santé articulaire. Peut contenir du poisson.',
                    ],
                ],
            ],
            [
                'slug' => 'hyaluronic-acid',
                'cas_number' => '9067-32-7',
                'is_allergen' => false,
                'allergen_code' => null,
                'regulatory_status' => 'approved',
                'notes' => 'Used in skincare and joint supplements',
                'translations' => [
                    'en' => [
                        'name' => 'Hyaluronic Acid',
                        'description' => 'Supports skin hydration and joint lubrication',
                    ],
                    'fr' => [
                        'name' => 'Acide Hyaluronique',
                        'description' => 'Soutient l\'hydratation cutanée et la lubrification articulaire',
                    ],
                ],
            ],
            [
                'slug' => 'curcumin-turmeric-extract',
                'cas_number' => '458-37-7',
                'is_allergen' => false,
                'allergen_code' => null,
                'regulatory_status' => 'approved',
                'notes' => 'Active compound from turmeric',
                'translations' => [
                    'en' => [
                        'name' => 'Curcumin (Turmeric Extract)',
                        'description' => 'Anti-inflammatory properties, supports joint health',
                    ],
                    'fr' => [
                        'name' => 'Curcumine (Extrait de Curcuma)',
                        'description' => 'Propriétés anti-inflammatoires, soutient la santé articulaire',
                    ],
                ],
            ],
            [
                'slug' => 'coenzyme-q10',
                'cas_number' => '303-98-0',
                'is_allergen' => false,
                'allergen_code' => null,
                'regulatory_status' => 'approved',
                'notes' => 'Antioxidant supporting cellular energy',
                'translations' => [
                    'en' => [
                        'name' => 'Coenzyme Q10 (CoQ10)',
                        'description' => 'Powerful antioxidant supporting heart and energy production',
                    ],
                    'fr' => [
                        'name' => 'Coenzyme Q10 (CoQ10)',
                        'description' => 'Antioxydant puissant soutenant le cœur et la production d\'énergie',
                    ],
                ],
            ],
        ];

        foreach ($ingredients as $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $ingredient = Ingredient::create($data);

            foreach ($translations as $locale => $translation) {
                IngredientTranslation::create([
                    'ingredient_id' => $ingredient->id,
                    'locale' => $locale,
                    'name' => $translation['name'],
                    'description' => $translation['description'],
                ]);
            }
        }
    }
}
