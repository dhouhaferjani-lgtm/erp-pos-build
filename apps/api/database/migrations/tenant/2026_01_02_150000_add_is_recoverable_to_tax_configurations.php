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
     * Add is_recoverable field to determine if tax can be deducted (VAT recovery).
     * Recoverable taxes (like VAT for registered companies) don't add to product cost.
     * Non-recoverable taxes (like stamp duties, VAT for non-registered) add to product cost.
     */
    public function up(): void
    {
        Schema::table('tax_configurations', function (Blueprint $table): void {
            if (! Schema::hasColumn('tax_configurations', 'is_recoverable')) {
                $table->boolean('is_recoverable')
                    ->default(true)
                    ->after('is_stamp_duty')
                    ->comment('Whether this tax is recoverable/deductible (affects product cost calculation)');
            }
        });

        // Mark stamp duties as non-recoverable
        DB::table('tax_configurations')
            ->where('is_stamp_duty', true)
            ->update(['is_recoverable' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tax_configurations', function (Blueprint $table): void {
            if (Schema::hasColumn('tax_configurations', 'is_recoverable')) {
                $table->dropColumn('is_recoverable');
            }
        });
    }
};
