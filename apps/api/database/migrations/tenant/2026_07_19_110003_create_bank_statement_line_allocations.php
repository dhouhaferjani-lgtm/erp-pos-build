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
        if (Schema::hasTable('bank_statement_line_allocations')) {
            return;
        }

        Schema::create('bank_statement_line_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_line_id')
                ->constrained('bank_statement_lines')
                ->cascadeOnDelete();
            $table->foreignUuid('repository_movement_id')
                ->constrained('repository_movements')
                ->restrictOnDelete();
            $table->decimal('matched_amount', 15, 3);
            $table->string('match_type', 30);
            $table->foreignUuid('matched_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('matched_at');

            $table->unique(
                ['bank_statement_line_id', 'repository_movement_id'],
                'bank_statement_allocations_line_movement_unique',
            );
            $table->index('repository_movement_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bank_statement_line_allocations ADD CONSTRAINT bank_statement_allocations_amount_positive CHECK (matched_amount > 0)');
            DB::statement("ALTER TABLE bank_statement_line_allocations ADD CONSTRAINT bank_statement_allocations_type_check CHECK (match_type IN ('manual','suggestion_confirmed','created_from_line'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_line_allocations');
    }
};
