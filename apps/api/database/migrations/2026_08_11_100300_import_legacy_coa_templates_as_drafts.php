<?php

declare(strict_types=1);

use App\Modules\CountryDefaults\Infrastructure\Import\LegacyCoaBootstrapImporter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LegacyCoaBootstrapImporter::class)->importAll();
    }

    public function down(): void
    {
        app(LegacyCoaBootstrapImporter::class)->removeUntouchedDrafts();
    }
};
