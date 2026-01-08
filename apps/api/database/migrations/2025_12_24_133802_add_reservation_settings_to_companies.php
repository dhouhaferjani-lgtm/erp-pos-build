<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->jsonb('reservation_settings')->nullable()->after('fiscal_chain_seed');
        });

        // Set defaults for existing companies using DTO
        $defaultSettings = (new \App\Modules\Company\Domain\ValueObjects\ReservationSettings)->toArray();

        \App\Modules\Company\Domain\Company::query()->each(function ($company) use ($defaultSettings) {
            $company->reservation_settings = $defaultSettings;
            $company->save();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('reservation_settings');
        });
    }
};
