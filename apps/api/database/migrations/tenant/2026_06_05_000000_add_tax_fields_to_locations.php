<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            // Per-branch tax-identity overrides. NULL means inherit from the parent company.
            $table->string('tax_id', 50)->nullable()->after('address_country');
            $table->string('vat_number', 50)->nullable()->after('tax_id');
            $table->jsonb('legal_identifiers')->nullable()->after('vat_number');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn(['tax_id', 'vat_number', 'legal_identifiers']);
        });
    }
};
