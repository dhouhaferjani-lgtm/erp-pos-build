<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_z_session_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('fiscal_event_id')->unique();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('terminal_id');
            $table->uuid('shift_id')->nullable();
            $table->uuid('session_id');
            $table->string('event_type', 64);
            $table->string('chain_context', 32);
            $table->unsignedBigInteger('sequence_number');
            $table->char('previous_hash', 64);
            $table->char('current_hash', 64);
            $table->date('business_date');
            $table->timestampTz('event_time_device');
            $table->jsonb('payload');
            $table->timestampsTz();

            $table->index(['tenant_id', 'terminal_id', 'chain_context', 'sequence_number'], 'pos_z_session_events_chain_idx');
            $table->index(['terminal_id', 'session_id', 'event_type'], 'pos_z_session_events_session_idx');
            $table->index(['shift_id', 'event_type'], 'pos_z_session_events_shift_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_z_session_events');
    }
};
