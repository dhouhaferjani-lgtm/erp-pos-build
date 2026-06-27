<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->after('vendor_name');
            // db-per-tenant: uniqueness within the tenant DB is tenant-scoped.
            $table->unique('idempotency_key', 'expense_metadata_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expense_metadata', function (Blueprint $table): void {
            $table->dropUnique('expense_metadata_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
