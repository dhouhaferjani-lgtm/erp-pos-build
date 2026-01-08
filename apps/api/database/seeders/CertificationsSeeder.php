<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Product\Domain\Certification;
use App\Modules\Product\Domain\CertificationTranslation;
use Illuminate\Database\Seeder;

class CertificationsSeeder extends Seeder
{
    public function run(): void
    {
        $certifications = [
            [
                'type' => 'organic',
                'slug' => 'ecocert-organic',
                'certifying_body' => 'ECOCERT',
                'logo_url' => '/certifications/ecocert.svg',
                'verification_url' => 'https://www.ecocert.com/en/certification-detail',
                'is_active' => true,
                'display_order' => 1,
                'translations' => [
                    'en' => [
                        'name' => 'ECOCERT Organic',
                        'description' => 'European organic certification ensuring natural and sustainable ingredients',
                    ],
                    'fr' => [
                        'name' => 'ECOCERT Biologique',
                        'description' => 'Certification biologique européenne garantissant des ingrédients naturels et durables',
                    ],
                ],
            ],
            [
                'type' => 'halal',
                'slug' => 'halal-certified',
                'certifying_body' => 'Halal Certification Europe',
                'logo_url' => '/certifications/halal.svg',
                'verification_url' => 'https://www.halalcertification.eu/verify',
                'is_active' => true,
                'display_order' => 2,
                'translations' => [
                    'en' => [
                        'name' => 'Halal Certified',
                        'description' => 'Certified permissible for consumption according to Islamic law',
                    ],
                    'fr' => [
                        'name' => 'Certifié Halal',
                        'description' => 'Certifié autorisé à la consommation selon la loi islamique',
                    ],
                ],
            ],
            [
                'type' => 'vegan',
                'slug' => 'vegan-society',
                'certifying_body' => 'The Vegan Society',
                'logo_url' => '/certifications/vegan-society.svg',
                'verification_url' => 'https://www.vegansociety.com/trademark',
                'is_active' => true,
                'display_order' => 3,
                'translations' => [
                    'en' => [
                        'name' => 'Vegan Society Certified',
                        'description' => 'Contains no animal ingredients or animal-derived substances',
                    ],
                    'fr' => [
                        'name' => 'Certifié Vegan Society',
                        'description' => 'Ne contient aucun ingrédient animal ou substance d\'origine animale',
                    ],
                ],
            ],
            [
                'type' => 'gmp',
                'slug' => 'gmp-certified',
                'certifying_body' => 'NSF International',
                'logo_url' => '/certifications/gmp.svg',
                'verification_url' => 'https://www.nsf.org/consumer-resources/product-listings',
                'is_active' => true,
                'display_order' => 4,
                'translations' => [
                    'en' => [
                        'name' => 'GMP Certified',
                        'description' => 'Good Manufacturing Practices - ensures quality and safety standards',
                    ],
                    'fr' => [
                        'name' => 'Certifié BPF',
                        'description' => 'Bonnes Pratiques de Fabrication - garantit les normes de qualité et de sécurité',
                    ],
                ],
            ],
            [
                'type' => 'iso',
                'slug' => 'iso-22000',
                'certifying_body' => 'International Organization for Standardization',
                'logo_url' => '/certifications/iso-22000.svg',
                'verification_url' => 'https://www.iso.org/certification.html',
                'is_active' => true,
                'display_order' => 5,
                'translations' => [
                    'en' => [
                        'name' => 'ISO 22000',
                        'description' => 'Food Safety Management System certification',
                    ],
                    'fr' => [
                        'name' => 'ISO 22000',
                        'description' => 'Certification du système de gestion de la sécurité alimentaire',
                    ],
                ],
            ],
            [
                'type' => 'kosher',
                'slug' => 'kosher-certified',
                'certifying_body' => 'Orthodox Union',
                'logo_url' => '/certifications/kosher-ou.svg',
                'verification_url' => 'https://oukosher.org/product-search/',
                'is_active' => true,
                'display_order' => 6,
                'translations' => [
                    'en' => [
                        'name' => 'Kosher Certified',
                        'description' => 'Certified permissible for consumption according to Jewish dietary law',
                    ],
                    'fr' => [
                        'name' => 'Certifié Casher',
                        'description' => 'Certifié autorisé à la consommation selon la loi alimentaire juive',
                    ],
                ],
            ],
            [
                'type' => 'organic',
                'slug' => 'usda-organic',
                'certifying_body' => 'USDA',
                'logo_url' => '/certifications/usda-organic.svg',
                'verification_url' => 'https://organic.ams.usda.gov/integrity/',
                'is_active' => true,
                'display_order' => 7,
                'translations' => [
                    'en' => [
                        'name' => 'USDA Organic',
                        'description' => 'United States Department of Agriculture organic certification',
                    ],
                    'fr' => [
                        'name' => 'USDA Biologique',
                        'description' => 'Certification biologique du Département de l\'Agriculture des États-Unis',
                    ],
                ],
            ],
            [
                'type' => 'gluten_free',
                'slug' => 'gluten-free-certified',
                'certifying_body' => 'Gluten-Free Certification Organization',
                'logo_url' => '/certifications/gluten-free.svg',
                'verification_url' => 'https://www.gfco.org/get-certified/certified-products/',
                'is_active' => true,
                'display_order' => 8,
                'translations' => [
                    'en' => [
                        'name' => 'Gluten-Free Certified',
                        'description' => 'Certified to contain less than 10 ppm of gluten',
                    ],
                    'fr' => [
                        'name' => 'Certifié Sans Gluten',
                        'description' => 'Certifié contenant moins de 10 ppm de gluten',
                    ],
                ],
            ],
        ];

        foreach ($certifications as $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $certification = Certification::create($data);

            foreach ($translations as $locale => $translation) {
                CertificationTranslation::create([
                    'certification_id' => $certification->id,
                    'locale' => $locale,
                    'name' => $translation['name'],
                    'description' => $translation['description'],
                ]);
            }
        }
    }
}
