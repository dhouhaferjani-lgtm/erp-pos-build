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
        Schema::create('repository_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('payment_repository_id');
            $table->string('direction', 3);                 // MovementDirection: in|out
            $table->decimal('amount', 15, 3);
            $table->char('currency', 3);
            $table->decimal('balance_after', 15, 3);
            $table->unsignedBigInteger('ordinal');          // gapless per-repository
            $table->string('source_type', 32);              // MovementSourceType
            $table->uuid('source_id');
            $table->uuid('journal_entry_id')->nullable();
            $table->string('idempotency_key');
            $table->uuid('transfer_group_id')->nullable();
            $table->uuid('reverses_movement_id')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->uuid('created_by')->nullable();
            $table->boolean('recorded_while_frozen')->default(false);
            $table->text('notes')->nullable();

            $table->unique('idempotency_key');
            $table->unique(['payment_repository_id', 'ordinal']); // gapless dense sequence guard
            $table->index(['payment_repository_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index('transfer_group_id');

            // MED-12: referential integrity. journal_entry_id nullable (opening_balance /
            // same-account transfer legs); reverses_movement_id self-FK for corrections.
            $table->foreign('payment_repository_id')->references('id')->on('payment_repositories');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('reverses_movement_id')->references('id')->on('repository_movements');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE repository_movements ADD CONSTRAINT repository_movements_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE repository_movements ADD CONSTRAINT repository_movements_direction_valid CHECK (direction IN ('in','out'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_movements');
    }
};
