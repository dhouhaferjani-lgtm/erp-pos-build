<?php

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
        Schema::table('partners', function (Blueprint $table) {
            $table->string('tax_id', 50)->nullable()->after('withholding_exemption_certificate_id');
            $table->enum('tax_regime', ['corporate', 'individual', 'forfait', 'exempt', 'non_resident'])
                ->default('individual')
                ->after('tax_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['tax_id', 'tax_regime']);
        });
    }
};
