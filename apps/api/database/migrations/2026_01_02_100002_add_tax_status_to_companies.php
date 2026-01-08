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
        Schema::table('companies', function (Blueprint $table): void {
            // Add tax_status for company VAT registration status
            if (! Schema::hasColumn('companies', 'tax_status')) {
                $table->string('tax_status', 50)->default('REGISTERED')->after('default_tax_configuration_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'tax_status')) {
                $table->dropColumn('tax_status');
            }
        });
    }
};
