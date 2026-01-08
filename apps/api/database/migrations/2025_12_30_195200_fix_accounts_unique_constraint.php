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
        Schema::table('accounts', function (Blueprint $table): void {
            // Drop the old constraint (tenant_id, code)
            $table->dropUnique(['tenant_id', 'code']);

            // Add the correct constraint (company_id, code)
            // This allows multiple companies in the same tenant to have accounts with the same code
            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // Drop the new constraint
            $table->dropUnique(['company_id', 'code']);

            // Restore the old constraint
            $table->unique(['tenant_id', 'code']);
        });
    }
};
