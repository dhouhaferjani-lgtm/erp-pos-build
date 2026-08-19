<?php

declare(strict_types=1);

use App\Modules\CountryDefaults\Infrastructure\Import\InventoryVarianceCoaTemplateV2Importer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(InventoryVarianceCoaTemplateV2Importer::class)->importAll();
    }

    public function down(): void
    {
        app(InventoryVarianceCoaTemplateV2Importer::class)->removeUntouchedDrafts();
    }
};
