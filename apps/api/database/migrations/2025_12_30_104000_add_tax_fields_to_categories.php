<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('default_tax_rate', 5, 2)->nullable()->after('is_active');
            $table->uuid('default_tax_configuration_id')->nullable()->after('default_tax_rate');
            $table->foreign('default_tax_configuration_id')
                  ->references('id')
                  ->on('tax_configurations')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_tax_configuration_id');
            $table->dropColumn('default_tax_rate');
        });
    }
};
