<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add idempotency_key column to pos_receipts for offline sync deduplication.
 *
 * The idempotency_key is a client-generated unique identifier used to prevent
 * duplicate receipt creation during offline-to-server sync operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->string('idempotency_key', 255)->nullable()->unique()->after('sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
