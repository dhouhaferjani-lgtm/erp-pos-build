<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Add confirmation tracking columns if not exists
            if (! Schema::hasColumn('documents', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('document_date');
            }
            if (! Schema::hasColumn('documents', 'confirmed_by')) {
                $table->uuid('confirmed_by')->nullable()->after('confirmed_at');
            }

            // Add cancellation tracking columns if not exists
            if (! Schema::hasColumn('documents', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('confirmed_by');
            }
            if (! Schema::hasColumn('documents', 'cancelled_by')) {
                $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('documents', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
        });

        // Add foreign keys (PostgreSQL only - SQLite doesn't support adding FKs to existing tables)
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('documents', function (Blueprint $table) {
                try {
                    $table->foreign('confirmed_by')->references('id')->on('users')->onDelete('set null');
                } catch (Exception $e) {
                    // Foreign key already exists, skip
                }

                try {
                    $table->foreign('cancelled_by')->references('id')->on('users')->onDelete('set null');
                } catch (Exception $e) {
                    // Foreign key already exists, skip
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (DB::getDriverName() === 'pgsql') {
                $table->dropForeign(['confirmed_by']);
                $table->dropForeign(['cancelled_by']);
            }
            $table->dropColumn(['confirmed_at', 'confirmed_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
