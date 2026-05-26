<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant scoping
            $table->uuid('tenant_id');
            $table->json('company_ids')->nullable(); // null = all companies

            // Program identity
            $table->string('name', 100);
            $table->string('program_type', 20); // points, stamps, visits, cashback, hybrid
            $table->string('status', 20)->default('draft'); // draft, active, paused, archived
            $table->string('currency', 50)->nullable(); // Points currency name (e.g., "Stars", "Points")

            // Validity
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();

            // Configuration
            $table->text('terms_and_conditions')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'program_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
