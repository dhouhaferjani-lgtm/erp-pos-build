<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Set default tax rates for existing companies based on their country
        $companies = Company::all();

        foreach ($companies as $company) {
            $defaultTaxRate = match ($company->country_code) {
                'TN' => '19.00',  // Tunisia TVA 19%
                'FR' => '20.00',  // France TVA 20%
                default => '0.00',
            };

            $company->update(['default_tax_rate' => $defaultTaxRate]);
        }

        logger()->info("Set default tax rates for {$companies->count()} companies");
    }

    public function down(): void
    {
        // Optionally reset to null
        Company::query()->update(['default_tax_rate' => null]);
    }
};
