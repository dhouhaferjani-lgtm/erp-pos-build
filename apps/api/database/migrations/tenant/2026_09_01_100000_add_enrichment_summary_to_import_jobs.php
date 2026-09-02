<?php

declare(strict_types=1);

use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_jobs') && ! Schema::hasColumn('import_jobs', 'enrichment_summary')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->jsonb('enrichment_summary')->nullable();
            });
        }
    }

    public function down(): void
    {
        MigrationOutput::info('imports.enrichment_summary.rollback_skipped '.json_encode([
            'reason' => 'Forward-only migration: terminal import enrichment evidence must not be erased.',
        ], JSON_THROW_ON_ERROR));
    }
};
