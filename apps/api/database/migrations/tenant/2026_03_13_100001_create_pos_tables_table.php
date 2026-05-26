<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('floor_id')->nullable();
            $table->string('table_number', 20);
            $table->string('label', 100)->nullable();
            $table->integer('seats')->default(4);
            $table->string('status', 20)->default('available');
            $table->string('shape', 20)->nullable();
            $table->decimal('position_x', 8, 2)->nullable();
            $table->decimal('position_y', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->uuid('current_order_id')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('floor_id')->references('id')->on('pos_floors')->nullOnDelete();

            $table->unique(['company_id', 'table_number']);
            $table->index(['company_id', 'status']);
            $table->index('floor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_tables');
    }
};
