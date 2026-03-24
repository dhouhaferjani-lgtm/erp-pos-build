<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix receipt_number unique constraint to be location-scoped.
 *
 * The original migration made receipt_number globally unique, but in a multi-tenant,
 * multi-location system different locations can have terminals with the same code
 * (e.g. "POS01"), producing identical receipt numbers. Per NF525 and industry standards,
 * receipt numbers are unique per terminal, and terminal codes are unique per location.
 * The receipt number now embeds the location code, so uniqueness is scoped to
 * (tenant_id, company_id, location_id, receipt_number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->unique(
                ['tenant_id', 'company_id', 'location_id', 'receipt_number'],
                'pos_receipts_location_receipt_number_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropUnique('pos_receipts_location_receipt_number_unique');
            $table->unique('receipt_number');
        });
    }
};
