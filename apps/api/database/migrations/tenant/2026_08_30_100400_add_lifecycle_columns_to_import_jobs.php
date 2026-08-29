<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Add the import claim, terminal-error, purge, and r5 aggregate columns.
 *
 * Every column is guarded independently because tenant migrations are applied
 * fleet-wide and a prior interrupted deploy may have left a partial shape.
 *
 * This migration is forward-only. Rolling these columns back could erase an
 * active ownership clock or make a terminal import appear executable again;
 * recovery must therefore be delivered as a new, self-guarding migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_jobs')) {
            return;
        }

        if (! Schema::hasColumn('import_jobs', 'error_code')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->string('error_code', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('import_jobs', 'error_detail')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->jsonb('error_detail')->nullable();
            });
        }

        if (! Schema::hasColumn('import_jobs', 'claimed_at')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->timestamp('claimed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('import_jobs', 'worker_started_at')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->timestamp('worker_started_at')->nullable();
            });
        }

        if (! Schema::hasColumn('import_jobs', 'source_purged_at')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->timestamp('source_purged_at')->nullable();
            });
        }

        if (! Schema::hasColumn('import_jobs', 'skipped_rows')) {
            Schema::table('import_jobs', function (Blueprint $table): void {
                $table->unsignedInteger('skipped_rows')->default(0);
            });
        }
    }

    public function down(): void
    {
        Log::warning('imports.lifecycle_columns.rollback_skipped', [
            'reason' => 'Forward-only migration: ownership clocks and terminal aggregates must not be erased.',
        ]);
    }
};
