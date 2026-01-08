<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Product\Domain\HealthClaim;
use App\Modules\Product\Domain\HealthClaimTranslation;
use Illuminate\Database\Seeder;

class HealthClaimsSeeder extends Seeder
{
    public function run(): void
    {
        $healthClaims = [
            [
                'claim_type' => 'function',
                'slug' => 'immune-support',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2008-341',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => false,
                'translations' => [
                    'en' => [
                        'claim' => 'Supports immune system function',
                        'disclaimer_text' => null,
                    ],
                    'fr' => [
                        'claim' => 'Soutient le fonctionnement du système immunitaire',
                        'disclaimer_text' => null,
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'bone-health',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2008-382',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => false,
                'translations' => [
                    'en' => [
                        'claim' => 'Contributes to the maintenance of normal bones',
                        'disclaimer_text' => null,
                    ],
                    'fr' => [
                        'claim' => 'Contribue au maintien d\'une ossature normale',
                        'disclaimer_text' => null,
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'cognitive-function',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2008-510',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => false,
                'translations' => [
                    'en' => [
                        'claim' => 'Contributes to normal cognitive function',
                        'disclaimer_text' => null,
                    ],
                    'fr' => [
                        'claim' => 'Contribue à une fonction cognitive normale',
                        'disclaimer_text' => null,
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'antioxidant-protection',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2008-137',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => false,
                'translations' => [
                    'en' => [
                        'claim' => 'Helps protect cells from oxidative stress',
                        'disclaimer_text' => null,
                    ],
                    'fr' => [
                        'claim' => 'Contribue à protéger les cellules contre le stress oxydatif',
                        'disclaimer_text' => null,
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'heart-health',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2008-598',
                'fda_reference' => 'FDA-2018-D-3001',
                'country_restrictions' => null,
                'requires_disclaimer' => true,
                'translations' => [
                    'en' => [
                        'claim' => 'Supports normal heart function',
                        'disclaimer_text' => 'This statement has not been evaluated by health authorities. This product is not intended to diagnose, treat, cure, or prevent any disease.',
                    ],
                    'fr' => [
                        'claim' => 'Soutient la fonction cardiaque normale',
                        'disclaimer_text' => 'Cette déclaration n\'a pas été évaluée par les autorités sanitaires. Ce produit n\'est pas destiné à diagnostiquer, traiter, guérir ou prévenir une maladie.',
                    ],
                ],
            ],
            [
                'claim_type' => 'reduction_of_disease_risk',
                'slug' => 'cholesterol-reduction',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2009-00393',
                'fda_reference' => 'FDA-2018-D-3002',
                'country_restrictions' => ['CN' => false],
                'requires_disclaimer' => true,
                'translations' => [
                    'en' => [
                        'claim' => 'Helps maintain normal blood cholesterol levels',
                        'disclaimer_text' => 'The beneficial effect is obtained with a daily intake of 2g of plant sterols. Consult your doctor if you are on cholesterol-lowering medication.',
                    ],
                    'fr' => [
                        'claim' => 'Contribue au maintien d\'une cholestérolémie normale',
                        'disclaimer_text' => 'L\'effet bénéfique est obtenu avec un apport quotidien de 2g de stérols végétaux. Consultez votre médecin si vous suivez un traitement hypocholestérolémiant.',
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'digestive-health',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2010-01065',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => false,
                'translations' => [
                    'en' => [
                        'claim' => 'Supports digestive health and gut flora balance',
                        'disclaimer_text' => null,
                    ],
                    'fr' => [
                        'claim' => 'Soutient la santé digestive et l\'équilibre de la flore intestinale',
                        'disclaimer_text' => null,
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'energy-metabolism',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2008-223',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => false,
                'translations' => [
                    'en' => [
                        'claim' => 'Contributes to normal energy-yielding metabolism',
                        'disclaimer_text' => null,
                    ],
                    'fr' => [
                        'claim' => 'Contribue à un métabolisme énergétique normal',
                        'disclaimer_text' => null,
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'skin-health',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2008-456',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => false,
                'translations' => [
                    'en' => [
                        'claim' => 'Helps maintain normal skin',
                        'disclaimer_text' => null,
                    ],
                    'fr' => [
                        'claim' => 'Contribue au maintien d\'une peau normale',
                        'disclaimer_text' => null,
                    ],
                ],
            ],
            [
                'claim_type' => 'function',
                'slug' => 'joint-mobility',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-Q-2011-00215',
                'fda_reference' => null,
                'country_restrictions' => null,
                'requires_disclaimer' => true,
                'translations' => [
                    'en' => [
                        'claim' => 'Supports joint flexibility and mobility',
                        'disclaimer_text' => 'These statements have not been evaluated by health authorities. Results may vary.',
                    ],
                    'fr' => [
                        'claim' => 'Soutient la flexibilité et la mobilité articulaires',
                        'disclaimer_text' => 'Ces déclarations n\'ont pas été évaluées par les autorités sanitaires. Les résultats peuvent varier.',
                    ],
                ],
            ],
        ];

        foreach ($healthClaims as $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $healthClaim = HealthClaim::create($data);

            foreach ($translations as $locale => $translation) {
                HealthClaimTranslation::create([
                    'health_claim_id' => $healthClaim->id,
                    'locale' => $locale,
                    'claim' => $translation['claim'],
                    'disclaimer_text' => $translation['disclaimer_text'],
                ]);
            }
        }
    }
}
