<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instrument_events')) {
            return;
        }

        Schema::create('instrument_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->foreignUuid('instrument_id')->constrained('payment_instruments')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->uuid('from_repository_id')->nullable();
            $table->uuid('to_repository_id')->nullable();
            $table->uuid('remittance_id')->nullable();
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUuid('movement_id')->nullable()->constrained('repository_movements')->restrictOnDelete();
            $table->jsonb('payload')->default('{}');
            $table->timestamp('occurred_at');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['instrument_id', 'created_at'], 'instrument_events_instrument_created_idx');
            $table->index(['tenant_id', 'company_id'], 'instrument_events_tenant_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_events');
    }
};
