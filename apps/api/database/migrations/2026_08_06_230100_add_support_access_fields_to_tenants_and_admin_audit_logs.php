<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('is_sensitive')->default(false)->index();
            $table->timestamp('support_access_starts_at')->nullable();
            $table->timestamp('support_access_expires_at')->nullable()->index();
        });

        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $this->addAuditAttribution($table);
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $table->dropColumn($this->auditAttributionColumns());
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'is_sensitive',
                'support_access_starts_at',
                'support_access_expires_at',
            ]);
        });
    }

    private function addAuditAttribution(Blueprint $table): void
    {
        $table->uuid('impersonator_id')->nullable()->index();
        $table->uuid('impersonation_session_id')->nullable()->index();
        $table->uuid('impersonation_event_id')->nullable()->index();
        $table->unsignedBigInteger('impersonation_sequence')->nullable();
        $table->char('impersonation_previous_hash', 64)->nullable();
        $table->char('impersonation_hash', 64)->nullable();
    }

    /** @return list<string> */
    private function auditAttributionColumns(): array
    {
        return [
            'impersonator_id',
            'impersonation_session_id',
            'impersonation_event_id',
            'impersonation_sequence',
            'impersonation_previous_hash',
            'impersonation_hash',
        ];
    }
};
