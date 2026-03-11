<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_contacts', function (Blueprint $table) {
            $table->boolean('is_invoice_contact')->default(false)->after('is_primary');
            $table->boolean('is_delivery_contact')->default(false)->after('is_invoice_contact');
        });
    }

    public function down(): void
    {
        Schema::table('party_contacts', function (Blueprint $table) {
            $table->dropColumn(['is_invoice_contact', 'is_delivery_contact']);
        });
    }
};
