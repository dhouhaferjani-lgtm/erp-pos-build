<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            [
                'code' => 'TN',
                'name' => 'Tunisia',
                'native_name' => 'تونس',
                'currency_code' => 'TND',
                'currency_symbol' => 'د.ت',
                'currency_decimal_places' => 3,
                'phone_prefix' => '+216',
                'date_format' => 'DD/MM/YYYY',
                'default_locale' => 'fr_TN',
                'default_timezone' => 'Africa/Tunis',
                'is_active' => true,
                'tax_id_label' => 'Matricule Fiscal',
                'tax_id_regex' => '^[0-9]{7}[A-Z]{3}[0-9]{3}$',
            ],
            [
                'code' => 'FR',
                'name' => 'France',
                'native_name' => 'France',
                'currency_code' => 'EUR',
                'currency_symbol' => '€',
                'currency_decimal_places' => 2,
                'phone_prefix' => '+33',
                'date_format' => 'DD/MM/YYYY',
                'default_locale' => 'fr_FR',
                'default_timezone' => 'Europe/Paris',
                'is_active' => true,
                'tax_id_label' => 'SIREN',
                'tax_id_regex' => '^[0-9]{9}$',
            ],
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(
                ['code' => $country['code']],
                $country
            );
        }
    }
}
