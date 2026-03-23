<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Database\Seeder;

class TunisiaTaxConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedVATRates();
        $this->seedStampDuties();
    }

    private function seedVATRates(): void
    {
        $vatRates = [
            [
                'name' => 'TVA 19%',
                'code' => 'TVA_19',
                'percentage_rate' => '19.00',
                'is_default' => true,
                'sequence_order' => 1,
            ],
            [
                'name' => 'TVA 13%',
                'code' => 'TVA_13',
                'percentage_rate' => '13.00',
                'is_default' => false,
                'sequence_order' => 2,
            ],
            [
                'name' => 'TVA 7%',
                'code' => 'TVA_7',
                'percentage_rate' => '7.00',
                'is_default' => false,
                'sequence_order' => 3,
            ],
            [
                'name' => 'Exonéré TVA',
                'code' => 'TVA_EXEMPT',
                'percentage_rate' => '0.00',
                'is_default' => false,
                'sequence_order' => 4,
            ],
        ];

        foreach ($vatRates as $rate) {
            TaxConfiguration::updateOrCreate(
                [
                    'country_code' => 'TN',
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
                    'is_recoverable' => true, // VAT is recoverable for registered companies
                ]
            );
        }

        $this->command?->info('Tunisia VAT rates seeded.');
    }

    private function seedStampDuties(): void
    {
        $stampDuties = [
            [
                'name' => 'Timbre Fiscal - Facture',
                'code' => 'STAMP_TAX_INVOICE',
                'amount' => '1.000',
                'document_types' => ['TAX_INVOICE'],
            ],
            [
                'name' => 'Timbre Fiscal - Ticket',
                'code' => 'STAMP_FISCAL_RECEIPT',
                'amount' => '0.100',
                'document_types' => ['FISCAL_RECEIPT'],
            ],
            [
                'name' => 'Timbre Fiscal - Avoir',
                'code' => 'STAMP_CREDIT_NOTE',
                'amount' => '0.600',
                'document_types' => ['CREDIT_NOTE'],
            ],
        ];

        foreach ($stampDuties as $stamp) {
            TaxConfiguration::updateOrCreate(
                [
                    'country_code' => 'TN',
                    'code' => $stamp['code'],
                ],
                [
                    'name' => $stamp['name'],
                    'tax_type' => 'FIXED_AMOUNT',
                    'percentage_rate' => null,
                    'fixed_amount' => $stamp['amount'],
                    'applies_to' => 'DOCUMENT_TOTAL',
                    'is_default' => false,
                    'is_active' => true,
                    'sequence_order' => 99,
                    'stacks_on' => 'SUBTOTAL',
                    'applicable_document_types' => $stamp['document_types'],
                    'is_stamp_duty' => true,
                    'is_recoverable' => false, // Stamp duties are not recoverable
                ]
            );
        }

        $this->command?->info('Tunisia stamp duties seeded.');
    }
}
