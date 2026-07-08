<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Pricing\Domain\CountryPricingRegulation;
use App\Modules\Pricing\Domain\Enums\RegulatoryEnforcement;
use App\Modules\Pricing\Domain\Enums\RegulatoryRuleType;
use Illuminate\Database\Seeder;

class CountryPricingRegulationSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'country_code' => 'FR',
                'rule_type' => RegulatoryRuleType::BelowCostFloor,
                'enforcement' => RegulatoryEnforcement::Advisory,
                'active' => true,
            ],
            [
                'country_code' => 'TN',
                'rule_type' => RegulatoryRuleType::BelowCostFloor,
                'enforcement' => RegulatoryEnforcement::Advisory,
                'active' => true,
            ],
            [
                'country_code' => 'TN',
                'rule_type' => RegulatoryRuleType::PharmaMarginSchedule,
                'enforcement' => RegulatoryEnforcement::Advisory,
                'active' => false,
            ],
        ];

        foreach ($rows as $row) {
            CountryPricingRegulation::query()->updateOrCreate(
                [
                    'country_code' => $row['country_code'],
                    'rule_type' => $row['rule_type']->value,
                ],
                [
                    'enforcement' => $row['enforcement']->value,
                    'active' => $row['active'],
                ],
            );
        }
    }
}
