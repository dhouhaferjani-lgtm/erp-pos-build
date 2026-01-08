<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\KeyComponentTranslation;
use Illuminate\Database\Seeder;

class KeyComponentsSeeder extends Seeder
{
    public function run(): void
    {
        $keyComponents = [
            [
                'slug' => 'gelatin-capsule',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Gelatin Capsule',
                        'description' => 'Bovine or porcine gelatin capsule shell',
                    ],
                    'fr' => [
                        'name' => 'Capsule de Gélatine',
                        'description' => 'Enveloppe de capsule en gélatine bovine ou porcine',
                    ],
                ],
            ],
            [
                'slug' => 'vegetarian-capsule',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Vegetarian Capsule',
                        'description' => 'Plant-based cellulose capsule (HPMC)',
                    ],
                    'fr' => [
                        'name' => 'Capsule Végétarienne',
                        'description' => 'Capsule de cellulose végétale (HPMC)',
                    ],
                ],
            ],
            [
                'slug' => 'microcrystalline-cellulose',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Microcrystalline Cellulose',
                        'description' => 'Common bulking agent and binder',
                    ],
                    'fr' => [
                        'name' => 'Cellulose Microcristalline',
                        'description' => 'Agent de charge et liant commun',
                    ],
                ],
            ],
            [
                'slug' => 'magnesium-stearate',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Magnesium Stearate',
                        'description' => 'Flow agent for manufacturing',
                    ],
                    'fr' => [
                        'name' => 'Stéarate de Magnésium',
                        'description' => 'Agent d\'écoulement pour la fabrication',
                    ],
                ],
            ],
            [
                'slug' => 'silicon-dioxide',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Silicon Dioxide',
                        'description' => 'Anti-caking agent',
                    ],
                    'fr' => [
                        'name' => 'Dioxyde de Silicium',
                        'description' => 'Agent anti-agglomérant',
                    ],
                ],
            ],
            [
                'slug' => 'natural-flavors',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Natural Flavors',
                        'description' => 'Natural flavoring agents for taste',
                    ],
                    'fr' => [
                        'name' => 'Arômes Naturels',
                        'description' => 'Agents aromatisants naturels pour le goût',
                    ],
                ],
            ],
            [
                'slug' => 'citric-acid',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Citric Acid',
                        'description' => 'Preservative and flavor enhancer',
                    ],
                    'fr' => [
                        'name' => 'Acide Citrique',
                        'description' => 'Conservateur et exhausteur de goût',
                    ],
                ],
            ],
            [
                'slug' => 'titanium-dioxide',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Titanium Dioxide',
                        'description' => 'White pigment for coloring',
                    ],
                    'fr' => [
                        'name' => 'Dioxyde de Titane',
                        'description' => 'Pigment blanc pour la coloration',
                    ],
                ],
            ],
            [
                'slug' => 'glycerin',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Glycerin',
                        'description' => 'Humectant and softgel ingredient',
                    ],
                    'fr' => [
                        'name' => 'Glycérine',
                        'description' => 'Humectant et ingrédient de capsule molle',
                    ],
                ],
            ],
            [
                'slug' => 'soy-lecithin',
                'is_allergen' => true,
                'translations' => [
                    'en' => [
                        'name' => 'Soy Lecithin',
                        'description' => 'Emulsifier derived from soybeans. Contains soy.',
                    ],
                    'fr' => [
                        'name' => 'Lécithine de Soja',
                        'description' => 'Émulsifiant dérivé du soja. Contient du soja.',
                    ],
                ],
            ],
            [
                'slug' => 'carrageenan',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Carrageenan',
                        'description' => 'Seaweed-derived thickening agent',
                    ],
                    'fr' => [
                        'name' => 'Carraghénane',
                        'description' => 'Agent épaississant dérivé d\'algues',
                    ],
                ],
            ],
            [
                'slug' => 'maltodextrin',
                'is_allergen' => false,
                'translations' => [
                    'en' => [
                        'name' => 'Maltodextrin',
                        'description' => 'Carbohydrate filler and stabilizer',
                    ],
                    'fr' => [
                        'name' => 'Maltodextrine',
                        'description' => 'Charge glucidique et stabilisant',
                    ],
                ],
            ],
        ];

        foreach ($keyComponents as $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $keyComponent = KeyComponent::create($data);

            foreach ($translations as $locale => $translation) {
                KeyComponentTranslation::create([
                    'component_id' => $keyComponent->id,
                    'locale' => $locale,
                    'name' => $translation['name'],
                    'description' => $translation['description'],
                ]);
            }
        }
    }
}
