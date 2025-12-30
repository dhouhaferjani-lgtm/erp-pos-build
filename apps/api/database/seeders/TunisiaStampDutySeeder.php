<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Taxation\Domain\Entities\StampDutyRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TunisiaStampDutySeeder extends Seeder
{
    public function run(): void
    {
        $stampDutyRules = [
            [
                'id' => (string) Str::uuid(),
                'country_code' => 'TN',
                'document_type' => 'invoice',
                'fiscal_category' => 'TAX_INVOICE',  // Regular invoices
                'stamp_amount' => '1.000',           // 1 Tunisian Dinar
                'is_active' => true,
                'effective_from' => '2020-01-01',
                'effective_to' => null,
                'metadata' => json_encode([
                    'description' => 'Tunisia standard invoice stamp duty',
                    'legal_reference' => 'Tunisia Tax Code',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'country_code' => 'TN',
                'document_type' => 'invoice',
                'fiscal_category' => 'FISCAL_RECEIPT',  // POS receipts
                'stamp_amount' => '0.100',              // 100 millimes (0.1 TND)
                'is_active' => true,
                'effective_from' => '2020-01-01',
                'effective_to' => null,
                'metadata' => json_encode([
                    'description' => 'Tunisia POS receipt stamp duty',
                    'legal_reference' => 'Tunisia Tax Code',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($stampDutyRules as $rule) {
            StampDutyRule::updateOrCreate(
                [
                    'country_code' => $rule['country_code'],
                    'document_type' => $rule['document_type'],
                    'fiscal_category' => $rule['fiscal_category'],
                    'effective_from' => $rule['effective_from'],
                ],
                $rule
            );
        }

        $this->command->info('Tunisia stamp duty rules seeded successfully.');
    }
}
