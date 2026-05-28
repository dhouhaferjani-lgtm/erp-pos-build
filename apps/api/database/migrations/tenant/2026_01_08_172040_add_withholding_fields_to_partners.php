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
            $table->boolean('withholding_exempt')->default(false)->after('tax_exemption_valid_until');
            $table->text('withholding_exemption_reason')->nullable()->after('withholding_exempt');
            $table->string('withholding_exemption_certificate_id', 255)->nullable()->after('withholding_exemption_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['withholding_exempt', 'withholding_exemption_reason', 'withholding_exemption_certificate_id']);
        });
    }
};
