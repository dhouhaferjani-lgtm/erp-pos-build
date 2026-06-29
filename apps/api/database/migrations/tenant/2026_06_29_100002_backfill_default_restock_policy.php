<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Company::query()->each(function ($company): void {
            $settings = $company->reservation_settings ?? [];
            if (! array_key_exists('default_restock_policy', $settings)) {
                $settings['default_restock_policy'] = 'default_allow';
                $company->reservation_settings = $settings;
                $company->save();
            }
        });
    }

    public function down(): void
    { /* additive key; no-op */
    }
};
