<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TunisiaWithholdingRulesSeeder extends Seeder
{
    /**
     * Seed Tunisia withholding tax rules.
     *
     * Based on 2025-2026 Tunisia tax regulations for Retenue à la Source (RAS).
     * Sources:
     * - PWC Tunisia Tax Summaries: https://taxsummaries.pwc.com/tunisia/corporate/withholding-taxes
     * - TEJ Platform: https://tej.finances.gov.tn/tax-file
     */
    public function run(): void
    {
        $rules = [
            [
                'country_code' => 'TN',
                'code' => 'TN_PROF_SERVICES_ACCOUNTING_5',
                'name' => 'Services professionnels (comptabilité régulière)',
                'description' => '5% pour entités avec CIT et tenue comptable régulière',
                'transaction_type' => 'services',
                'partner_tax_status' => 'REGISTERED',
                'min_amount' => null,
                'rate' => 0.0500,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_PROF_SERVICES_OTHER_10',
                'name' => 'Services professionnels (autres)',
                'description' => '10% pour individuels et entités sans comptabilité régulière',
                'transaction_type' => 'services',
                'partner_tax_status' => 'NON_REGISTERED',
                'min_amount' => null,
                'rate' => 0.1000,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_RENTAL_10',
                'name' => 'Loyers (général)',
                'description' => '10% sur loyers bureaux, équipements, etc.',
                'transaction_type' => 'rental',
                'partner_tax_status' => null,
                'min_amount' => null,
                'rate' => 0.1000,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_RENTAL_HOTEL_5',
                'name' => 'Loyers hôteliers',
                'description' => '5% sur loyers hôteliers',
                'transaction_type' => 'rental_hotel',
                'partner_tax_status' => null,
                'min_amount' => null,
                'rate' => 0.0500,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_GOODS_SERVICES_1_5',
                'name' => 'Biens et services (≥1000 TND)',
                'description' => '1.5% pour sociétés soumises au taux CIT de 20%',
                'transaction_type' => 'goods',
                'partner_tax_status' => 'REGISTERED',
                'min_amount' => 1000.000,
                'rate' => 0.0150,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_GOODS_SERVICES_1_0',
                'name' => 'Biens et services (≥1000 TND, CIT 15%)',
                'description' => '1.0% pour sociétés soumises au taux CIT de 15%',
                'transaction_type' => 'goods',
                'partner_tax_status' => 'REGISTERED',
                'min_amount' => 1000.000,
                'rate' => 0.0100,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_GOODS_SERVICES_0_5',
                'name' => 'Biens et services (≥1000 TND, CIT 10%)',
                'description' => '0.5% pour sociétés soumises au taux CIT de 10%',
                'transaction_type' => 'goods',
                'partner_tax_status' => 'REGISTERED',
                'min_amount' => 1000.000,
                'rate' => 0.0050,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_COMMISSIONS_10',
                'name' => 'Commissions et courtages',
                'description' => '10% sur commissions, courtages et honoraires',
                'transaction_type' => 'commission',
                'partner_tax_status' => null,
                'min_amount' => null,
                'rate' => 0.1000,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_EXPORT_2_5',
                'name' => 'Activités liées à l\'export',
                'description' => '2.5% pour honoraires et commissions liés à l\'exportation',
                'transaction_type' => 'export_services',
                'partner_tax_status' => null,
                'min_amount' => null,
                'rate' => 0.0250,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
            [
                'country_code' => 'TN',
                'code' => 'TN_NON_RESIDENT_15',
                'name' => 'Paiements aux non-résidents',
                'description' => '15% sauf convention fiscale (DTT) réduisant le taux',
                'transaction_type' => null,
                'partner_tax_status' => 'NON_REGISTERED',
                'min_amount' => null,
                'rate' => 0.1500,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'is_active' => true,
            ],
        ];

        foreach ($rules as $rule) {
            DB::table('withholding_tax_rules')->updateOrInsert(
                [
                    'country_code' => $rule['country_code'],
                    'code' => $rule['code'],
                    'effective_from' => $rule['effective_from'],
                ],
                array_merge($rule, [
                    'id' => DB::raw('gen_random_uuid()'),
                    'company_id' => null, // Global rules, not company-specific
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('Tunisia withholding tax rules seeded successfully.');
    }
}
