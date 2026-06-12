<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Database\Seeder;

class FranceTaxConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $vatRates = [
            [
                'name' => 'TVA 20% (taux normal)',
                'code' => 'TVA_FR_20',
                'percentage_rate' => '20.00',
                'is_default' => true,
                'sequence_order' => 1,
            ],
            [
                'name' => 'TVA 10% (taux intermédiaire)',
                'code' => 'TVA_FR_10',
                'percentage_rate' => '10.00',
                'is_default' => false,
                'sequence_order' => 2,
            ],
            [
                'name' => 'TVA 5,5% (taux réduit)',
                'code' => 'TVA_FR_5_5',
                'percentage_rate' => '5.50',
                'is_default' => false,
                'sequence_order' => 3,
            ],
            [
                'name' => 'TVA 2,1% (taux particulier)',
                'code' => 'TVA_FR_2_1',
                'percentage_rate' => '2.10',
                'is_default' => false,
                'sequence_order' => 4,
            ],
            [
                'name' => 'Exonéré TVA',
                'code' => 'TVA_FR_EXEMPT',
                'percentage_rate' => '0.00',
                'is_default' => false,
                'sequence_order' => 5,
            ],
        ];

        foreach ($vatRates as $rate) {
            TaxConfiguration::updateOrCreate(
                [
                    'country_code' => 'FR',
                    'code' => $rate['code'],
                ],
                [
                    'name' => $rate['name'],
                    'tax_type' => 'PERCENTAGE',
                    'percentage_rate' => $rate['percentage_rate'],
                    'fixed_amount' => null,
                    'applies_to' => 'LINE_ITEMS',
                    'is_default' => $rate['is_default'],
                    'is_active' => true,
                    'sequence_order' => $rate['sequence_order'],
                    'stacks_on' => 'SUBTOTAL',
                    'applicable_document_types' => [
                        'TAX_INVOICE',
                        'FISCAL_RECEIPT',
                        'CREDIT_NOTE',
                        'PURCHASE_INVOICE',
                        'DELIVERY_NOTE',
                        'QUOTATION',
                    ],
                    'is_stamp_duty' => false,
                    'is_recoverable' => true,
                ]
            );
        }

        if (isset($this->command)) {
            $this->command->info('France VAT rates seeded.');
        }
    }
}
