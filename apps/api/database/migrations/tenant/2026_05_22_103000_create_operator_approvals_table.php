<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('terminal_id');
            $table->uuid('supervisor_user_id');
            $table->uuid('cashier_user_id');
            $table->string('approval_scope', 64);
            $table->string('target_event_type', 64);
            $table->uuid('target_reference_id');
            $table->text('reason');
            $table->timestampTz('approved_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'terminal_id', 'approval_scope'], 'operator_approvals_scope_idx');
            $table->index(['target_event_type', 'target_reference_id'], 'operator_approvals_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_approvals');
    }
};
