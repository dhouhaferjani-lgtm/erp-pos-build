<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('banks')) {
            return;
        }

        Schema::create('banks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->char('country_code', 2);
            $table->string('name', 150);
            $table->string('short_name', 50)->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('rib_bank_code', 10)->nullable();
            $table->string('city', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_custom')->default(false);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'country_code', 'is_active'], 'banks_tenant_country_active_idx');
            $table->index(['tenant_id', 'country_code', 'position'], 'banks_tenant_country_position_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX banks_tenant_country_rib_code_uniq '
            .'ON banks (tenant_id, country_code, rib_bank_code) '
            .'WHERE rib_bank_code IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
