<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.4: Add company_id to audit_events table.
 *
 * Audit events should be scoped to company (legal entity) not tenant (account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->uuid('company_id')->nullable()->after('tenant_id')->index();
        });

        // Update indexes
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['company_id', 'event_type']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'event_type']);
            $table->dropIndex(['company_id', 'occurred_at']);
            $table->dropIndex(['company_id', 'user_id', 'occurred_at']);
            $table->dropColumn('company_id');
        });
    }
};
