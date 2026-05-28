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
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name', 100);
            $table->string('native_name', 100)->nullable();
            $table->char('currency_code', 3);
            $table->string('currency_symbol', 10)->nullable();
            $table->string('phone_prefix', 5)->nullable();
            $table->string('date_format', 20)->default('DD/MM/YYYY');
            $table->string('default_locale', 10)->nullable();
            $table->string('default_timezone', 50)->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('tax_id_label', 50)->nullable();
            $table->string('tax_id_regex', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
