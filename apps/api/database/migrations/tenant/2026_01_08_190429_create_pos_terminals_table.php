<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates pos_terminals table for tracking physical POS devices.
     * Each terminal maintains independent receipt sequences and hash chains.
     */
    public function up(): void
    {
        Schema::create('pos_terminals', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Multi-tenancy
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignUuid('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();

            // Terminal Identity
            $table->string('code', 10);
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Hash Chain State (NF525 Compliance)
            $table->char('genesis_seed', 64);
            $table->integer('current_sequence')->default(0);
            $table->integer('current_year');
            $table->char('last_hash', 64)->nullable();

            // Lifecycle
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();

            // Metadata
            $table->string('hardware_identifier', 100)->nullable();
            $table->string('pos_software_version', 20)->nullable();

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('location_id');
            $table->index('is_active');

            // Unique constraint
            $table->unique(['tenant_id', 'company_id', 'location_id', 'code'], 'pos_terminals_unique_code');
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Add check constraint for code format (POS01, POS02, etc.)
            DB::statement('ALTER TABLE pos_terminals ADD CONSTRAINT pos_terminals_code_format CHECK (code ~ \'^POS[0-9]{2}$\')');

            // Add check constraint for genesis_seed length
            DB::statement('ALTER TABLE pos_terminals ADD CONSTRAINT pos_terminals_genesis_length CHECK (length(genesis_seed) = 64)');

            // Add table comment
            DB::statement('COMMENT ON TABLE pos_terminals IS \'Physical POS devices with independent receipt sequences\'');
            DB::statement('COMMENT ON COLUMN pos_terminals.genesis_seed IS \'Random 256-bit seed for hash chain initialization (hex string)\'');
            DB::statement('COMMENT ON COLUMN pos_terminals.current_sequence IS \'Next receipt sequence number for current year\'');
            DB::statement('COMMENT ON COLUMN pos_terminals.last_hash IS \'Hash of most recent receipt (for chain continuity)\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_terminals');
    }
};
