<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fix unique constraints to use company_id instead of tenant_id
     * This allows multiple companies in the same tenant to have resources with the same codes
     */
    public function up(): void
    {
        // Fix partners table
        Schema::table('partners', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique(['company_id', 'code']);
        });

        // Fix payment_methods table
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique(['company_id', 'code']);
        });

        // Fix payment_repositories table
        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore partners table
        Schema::table('partners', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'code']);
            $table->unique(['tenant_id', 'code']);
        });

        // Restore payment_methods table
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'code']);
            $table->unique(['tenant_id', 'code']);
        });

        // Restore payment_repositories table
        Schema::table('payment_repositories', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'code']);
            $table->unique(['tenant_id', 'code']);
        });
    }
};
