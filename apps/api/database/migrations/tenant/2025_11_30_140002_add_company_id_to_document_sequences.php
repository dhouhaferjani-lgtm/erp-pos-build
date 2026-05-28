<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.4: Add company_id to document_sequences table.
 *
 * Document sequences should be scoped to company (legal entity) not tenant (account).
 * Each company has its own sequence of document numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_sequences', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable()->after('tenant_id')->index();
        });

        // Update unique constraint to be per company instead of per tenant
        Schema::table('document_sequences', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'type', 'year']);
            $table->unique(['company_id', 'type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::table('document_sequences', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'type', 'year']);
            $table->unique(['tenant_id', 'type', 'year']);
            $table->dropColumn('company_id');
        });
    }
};
