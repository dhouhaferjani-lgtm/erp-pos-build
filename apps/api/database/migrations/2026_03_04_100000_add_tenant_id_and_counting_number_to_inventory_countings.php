<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_countings', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->string('counting_number', 20)->nullable()->after('tenant_id');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->unique(['company_id', 'counting_number']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_countings', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropUnique(['company_id', 'counting_number']);
            $table->dropColumn(['tenant_id', 'counting_number']);
        });
    }
};
