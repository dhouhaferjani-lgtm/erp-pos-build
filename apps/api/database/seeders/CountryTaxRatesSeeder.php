<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CountryTaxRate;
use Illuminate\Database\Seeder;

class CountryTaxRatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds standard VAT/tax rates for all supported countries.
     * Uses country_code + code as unique key to avoid duplicates on re-run.
     */
    public function run(): void
    {
        $taxRates = [
            // ─── Tunisia ───
            ['country_code' => 'TN', 'name' => 'TVA 19%', 'rate' => 19.00, 'code' => 'TVA_19', 'is_default' => true],
            ['country_code' => 'TN', 'name' => 'TVA 13%', 'rate' => 13.00, 'code' => 'TVA_13', 'is_default' => false],
            ['country_code' => 'TN', 'name' => 'TVA 7%', 'rate' => 7.00, 'code' => 'TVA_7', 'is_default' => false],
            ['country_code' => 'TN', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXONERE', 'is_default' => false],

            // ─── France ───
            ['country_code' => 'FR', 'name' => 'TVA 20%', 'rate' => 20.00, 'code' => 'TVA_20', 'is_default' => true],
            ['country_code' => 'FR', 'name' => 'TVA 10%', 'rate' => 10.00, 'code' => 'TVA_10', 'is_default' => false],
            ['country_code' => 'FR', 'name' => 'TVA 5.5%', 'rate' => 5.50, 'code' => 'TVA_5_5', 'is_default' => false],
            ['country_code' => 'FR', 'name' => 'TVA 2.1%', 'rate' => 2.10, 'code' => 'TVA_2_1', 'is_default' => false],

            // ─── Europe ───
            // Germany
            ['country_code' => 'DE', 'name' => 'MwSt 19%', 'rate' => 19.00, 'code' => 'MWST_19', 'is_default' => true],
            ['country_code' => 'DE', 'name' => 'MwSt 7%', 'rate' => 7.00, 'code' => 'MWST_7', 'is_default' => false],
            ['country_code' => 'DE', 'name' => 'Steuerfrei', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Italy
            ['country_code' => 'IT', 'name' => 'IVA 22%', 'rate' => 22.00, 'code' => 'IVA_22', 'is_default' => true],
            ['country_code' => 'IT', 'name' => 'IVA 10%', 'rate' => 10.00, 'code' => 'IVA_10', 'is_default' => false],
            ['country_code' => 'IT', 'name' => 'IVA 5%', 'rate' => 5.00, 'code' => 'IVA_5', 'is_default' => false],
            ['country_code' => 'IT', 'name' => 'IVA 4%', 'rate' => 4.00, 'code' => 'IVA_4', 'is_default' => false],
            ['country_code' => 'IT', 'name' => 'Esente', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Spain
            ['country_code' => 'ES', 'name' => 'IVA 21%', 'rate' => 21.00, 'code' => 'IVA_21', 'is_default' => true],
            ['country_code' => 'ES', 'name' => 'IVA 10%', 'rate' => 10.00, 'code' => 'IVA_10', 'is_default' => false],
            ['country_code' => 'ES', 'name' => 'IVA 4%', 'rate' => 4.00, 'code' => 'IVA_4', 'is_default' => false],
            ['country_code' => 'ES', 'name' => 'Exento', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Netherlands
            ['country_code' => 'NL', 'name' => 'BTW 21%', 'rate' => 21.00, 'code' => 'BTW_21', 'is_default' => true],
            ['country_code' => 'NL', 'name' => 'BTW 9%', 'rate' => 9.00, 'code' => 'BTW_9', 'is_default' => false],
            ['country_code' => 'NL', 'name' => 'Vrijgesteld', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Belgium
            ['country_code' => 'BE', 'name' => 'TVA 21%', 'rate' => 21.00, 'code' => 'TVA_21', 'is_default' => true],
            ['country_code' => 'BE', 'name' => 'TVA 12%', 'rate' => 12.00, 'code' => 'TVA_12', 'is_default' => false],
            ['country_code' => 'BE', 'name' => 'TVA 6%', 'rate' => 6.00, 'code' => 'TVA_6', 'is_default' => false],
            ['country_code' => 'BE', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Portugal
            ['country_code' => 'PT', 'name' => 'IVA 23%', 'rate' => 23.00, 'code' => 'IVA_23', 'is_default' => true],
            ['country_code' => 'PT', 'name' => 'IVA 13%', 'rate' => 13.00, 'code' => 'IVA_13', 'is_default' => false],
            ['country_code' => 'PT', 'name' => 'IVA 6%', 'rate' => 6.00, 'code' => 'IVA_6', 'is_default' => false],
            ['country_code' => 'PT', 'name' => 'Isento', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Austria
            ['country_code' => 'AT', 'name' => 'USt 20%', 'rate' => 20.00, 'code' => 'UST_20', 'is_default' => true],
            ['country_code' => 'AT', 'name' => 'USt 13%', 'rate' => 13.00, 'code' => 'UST_13', 'is_default' => false],
            ['country_code' => 'AT', 'name' => 'USt 10%', 'rate' => 10.00, 'code' => 'UST_10', 'is_default' => false],
            ['country_code' => 'AT', 'name' => 'Befreit', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Ireland
            ['country_code' => 'IE', 'name' => 'VAT 23%', 'rate' => 23.00, 'code' => 'VAT_23', 'is_default' => true],
            ['country_code' => 'IE', 'name' => 'VAT 13.5%', 'rate' => 13.50, 'code' => 'VAT_13_5', 'is_default' => false],
            ['country_code' => 'IE', 'name' => 'VAT 9%', 'rate' => 9.00, 'code' => 'VAT_9', 'is_default' => false],
            ['country_code' => 'IE', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Greece
            ['country_code' => 'GR', 'name' => 'ΦΠΑ 24%', 'rate' => 24.00, 'code' => 'FPA_24', 'is_default' => true],
            ['country_code' => 'GR', 'name' => 'ΦΠΑ 13%', 'rate' => 13.00, 'code' => 'FPA_13', 'is_default' => false],
            ['country_code' => 'GR', 'name' => 'ΦΠΑ 6%', 'rate' => 6.00, 'code' => 'FPA_6', 'is_default' => false],
            ['country_code' => 'GR', 'name' => 'Απαλλαγή', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Finland
            ['country_code' => 'FI', 'name' => 'ALV 25.5%', 'rate' => 25.50, 'code' => 'ALV_25_5', 'is_default' => true],
            ['country_code' => 'FI', 'name' => 'ALV 14%', 'rate' => 14.00, 'code' => 'ALV_14', 'is_default' => false],
            ['country_code' => 'FI', 'name' => 'ALV 10%', 'rate' => 10.00, 'code' => 'ALV_10', 'is_default' => false],
            ['country_code' => 'FI', 'name' => 'Veroton', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // United Kingdom
            ['country_code' => 'GB', 'name' => 'VAT 20%', 'rate' => 20.00, 'code' => 'VAT_20', 'is_default' => true],
            ['country_code' => 'GB', 'name' => 'VAT 5%', 'rate' => 5.00, 'code' => 'VAT_5', 'is_default' => false],
            ['country_code' => 'GB', 'name' => 'Zero-rated', 'rate' => 0.00, 'code' => 'ZERO', 'is_default' => false],
            ['country_code' => 'GB', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Switzerland
            ['country_code' => 'CH', 'name' => 'MWST 8.1%', 'rate' => 8.10, 'code' => 'MWST_8_1', 'is_default' => true],
            ['country_code' => 'CH', 'name' => 'MWST 2.6%', 'rate' => 2.60, 'code' => 'MWST_2_6', 'is_default' => false],
            ['country_code' => 'CH', 'name' => 'MWST 3.8%', 'rate' => 3.80, 'code' => 'MWST_3_8', 'is_default' => false],
            ['country_code' => 'CH', 'name' => 'Befreit', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Sweden
            ['country_code' => 'SE', 'name' => 'Moms 25%', 'rate' => 25.00, 'code' => 'MOMS_25', 'is_default' => true],
            ['country_code' => 'SE', 'name' => 'Moms 12%', 'rate' => 12.00, 'code' => 'MOMS_12', 'is_default' => false],
            ['country_code' => 'SE', 'name' => 'Moms 6%', 'rate' => 6.00, 'code' => 'MOMS_6', 'is_default' => false],
            ['country_code' => 'SE', 'name' => 'Momsfri', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Poland
            ['country_code' => 'PL', 'name' => 'VAT 23%', 'rate' => 23.00, 'code' => 'VAT_23', 'is_default' => true],
            ['country_code' => 'PL', 'name' => 'VAT 8%', 'rate' => 8.00, 'code' => 'VAT_8', 'is_default' => false],
            ['country_code' => 'PL', 'name' => 'VAT 5%', 'rate' => 5.00, 'code' => 'VAT_5', 'is_default' => false],
            ['country_code' => 'PL', 'name' => 'Zwolniony', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Romania
            ['country_code' => 'RO', 'name' => 'TVA 19%', 'rate' => 19.00, 'code' => 'TVA_19', 'is_default' => true],
            ['country_code' => 'RO', 'name' => 'TVA 9%', 'rate' => 9.00, 'code' => 'TVA_9', 'is_default' => false],
            ['country_code' => 'RO', 'name' => 'TVA 5%', 'rate' => 5.00, 'code' => 'TVA_5', 'is_default' => false],
            ['country_code' => 'RO', 'name' => 'Scutit', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // ─── MENA ───
            // Saudi Arabia (VAT introduced 2018, raised to 15% in 2020)
            ['country_code' => 'SA', 'name' => 'VAT 15%', 'rate' => 15.00, 'code' => 'VAT_15', 'is_default' => true],
            ['country_code' => 'SA', 'name' => 'Zero-rated', 'rate' => 0.00, 'code' => 'ZERO', 'is_default' => false],
            ['country_code' => 'SA', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // UAE (VAT introduced 2018)
            ['country_code' => 'AE', 'name' => 'VAT 5%', 'rate' => 5.00, 'code' => 'VAT_5', 'is_default' => true],
            ['country_code' => 'AE', 'name' => 'Zero-rated', 'rate' => 0.00, 'code' => 'ZERO', 'is_default' => false],
            ['country_code' => 'AE', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Qatar (no VAT as of 2026)
            ['country_code' => 'QA', 'name' => 'No VAT', 'rate' => 0.00, 'code' => 'NONE', 'is_default' => true],

            // Kuwait (no VAT as of 2026)
            ['country_code' => 'KW', 'name' => 'No VAT', 'rate' => 0.00, 'code' => 'NONE', 'is_default' => true],

            // Bahrain (VAT introduced 2019)
            ['country_code' => 'BH', 'name' => 'VAT 10%', 'rate' => 10.00, 'code' => 'VAT_10', 'is_default' => true],
            ['country_code' => 'BH', 'name' => 'Zero-rated', 'rate' => 0.00, 'code' => 'ZERO', 'is_default' => false],
            ['country_code' => 'BH', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Oman (VAT introduced 2021)
            ['country_code' => 'OM', 'name' => 'VAT 5%', 'rate' => 5.00, 'code' => 'VAT_5', 'is_default' => true],
            ['country_code' => 'OM', 'name' => 'Zero-rated', 'rate' => 0.00, 'code' => 'ZERO', 'is_default' => false],
            ['country_code' => 'OM', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Jordan
            ['country_code' => 'JO', 'name' => 'GST 16%', 'rate' => 16.00, 'code' => 'GST_16', 'is_default' => true],
            ['country_code' => 'JO', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Lebanon
            ['country_code' => 'LB', 'name' => 'TVA 11%', 'rate' => 11.00, 'code' => 'TVA_11', 'is_default' => true],
            ['country_code' => 'LB', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Egypt
            ['country_code' => 'EG', 'name' => 'VAT 14%', 'rate' => 14.00, 'code' => 'VAT_14', 'is_default' => true],
            ['country_code' => 'EG', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Iraq (no national VAT as of 2026)
            ['country_code' => 'IQ', 'name' => 'No VAT', 'rate' => 0.00, 'code' => 'NONE', 'is_default' => true],

            // Libya (no VAT as of 2026)
            ['country_code' => 'LY', 'name' => 'No VAT', 'rate' => 0.00, 'code' => 'NONE', 'is_default' => true],

            // ─── Africa ───
            // Morocco
            ['country_code' => 'MA', 'name' => 'TVA 20%', 'rate' => 20.00, 'code' => 'TVA_20', 'is_default' => true],
            ['country_code' => 'MA', 'name' => 'TVA 14%', 'rate' => 14.00, 'code' => 'TVA_14', 'is_default' => false],
            ['country_code' => 'MA', 'name' => 'TVA 10%', 'rate' => 10.00, 'code' => 'TVA_10', 'is_default' => false],
            ['country_code' => 'MA', 'name' => 'TVA 7%', 'rate' => 7.00, 'code' => 'TVA_7', 'is_default' => false],
            ['country_code' => 'MA', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Algeria
            ['country_code' => 'DZ', 'name' => 'TVA 19%', 'rate' => 19.00, 'code' => 'TVA_19', 'is_default' => true],
            ['country_code' => 'DZ', 'name' => 'TVA 9%', 'rate' => 9.00, 'code' => 'TVA_9', 'is_default' => false],
            ['country_code' => 'DZ', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Senegal (UEMOA)
            ['country_code' => 'SN', 'name' => 'TVA 18%', 'rate' => 18.00, 'code' => 'TVA_18', 'is_default' => true],
            ['country_code' => 'SN', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Côte d'Ivoire (UEMOA)
            ['country_code' => 'CI', 'name' => 'TVA 18%', 'rate' => 18.00, 'code' => 'TVA_18', 'is_default' => true],
            ['country_code' => 'CI', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Cameroon
            ['country_code' => 'CM', 'name' => 'TVA 19.25%', 'rate' => 19.25, 'code' => 'TVA_19_25', 'is_default' => true],
            ['country_code' => 'CM', 'name' => 'Exonéré', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Nigeria
            ['country_code' => 'NG', 'name' => 'VAT 7.5%', 'rate' => 7.50, 'code' => 'VAT_7_5', 'is_default' => true],
            ['country_code' => 'NG', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Kenya
            ['country_code' => 'KE', 'name' => 'VAT 16%', 'rate' => 16.00, 'code' => 'VAT_16', 'is_default' => true],
            ['country_code' => 'KE', 'name' => 'VAT 8%', 'rate' => 8.00, 'code' => 'VAT_8', 'is_default' => false],
            ['country_code' => 'KE', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Ghana
            ['country_code' => 'GH', 'name' => 'VAT 15%', 'rate' => 15.00, 'code' => 'VAT_15', 'is_default' => true],
            ['country_code' => 'GH', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // Ethiopia
            ['country_code' => 'ET', 'name' => 'VAT 15%', 'rate' => 15.00, 'code' => 'VAT_15', 'is_default' => true],
            ['country_code' => 'ET', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // ─── Americas ───
            // United States (no federal VAT; state sales tax varies)
            ['country_code' => 'US', 'name' => 'No Federal Tax', 'rate' => 0.00, 'code' => 'NONE', 'is_default' => true],

            // Canada (GST/HST)
            ['country_code' => 'CA', 'name' => 'GST 5%', 'rate' => 5.00, 'code' => 'GST_5', 'is_default' => true],
            ['country_code' => 'CA', 'name' => 'Exempt', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],

            // ─── Other ───
            // Turkey
            ['country_code' => 'TR', 'name' => 'KDV 20%', 'rate' => 20.00, 'code' => 'KDV_20', 'is_default' => true],
            ['country_code' => 'TR', 'name' => 'KDV 10%', 'rate' => 10.00, 'code' => 'KDV_10', 'is_default' => false],
            ['country_code' => 'TR', 'name' => 'KDV 1%', 'rate' => 1.00, 'code' => 'KDV_1', 'is_default' => false],
            ['country_code' => 'TR', 'name' => 'Muaf', 'rate' => 0.00, 'code' => 'EXEMPT', 'is_default' => false],
        ];

        foreach ($taxRates as $taxRate) {
            CountryTaxRate::updateOrCreate(
                [
                    'country_code' => $taxRate['country_code'],
                    'code' => $taxRate['code'],
                ],
                array_merge($taxRate, ['is_active' => true])
            );
        }
    }
}
