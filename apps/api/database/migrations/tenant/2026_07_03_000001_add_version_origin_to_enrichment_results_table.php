<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_results', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('tracking_id');
            $table->string('origin', 20)->default('initial')->after('version');
            $table->text('rejection_notes')->nullable()->after('rejection_reason');
            $table->dropUnique('idx_enrichment_results_tracking');
            $table->unique(['tracking_id', 'version'], 'idx_enrichment_results_tracking_version');
        });
    }

    public function down(): void
    {
        Schema::table('enrichment_results', function (Blueprint $table): void {
            $table->dropUnique('idx_enrichment_results_tracking_version');
            // Rollback is only safe before curated_update rows exist; re-creating unique(tracking_id) throws once v2 rows are present.
            $table->unique(['tracking_id'], 'idx_enrichment_results_tracking');
            $table->dropColumn(['version', 'origin', 'rejection_notes']);
        });
    }
};
