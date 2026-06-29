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
        Schema::create('product_equivalents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('product_id');
            $table->uuid('equivalent_product_id');
            $table->string('equivalence_type');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign('equivalent_product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->unique(['product_id', 'equivalent_product_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE product_equivalents ADD CONSTRAINT chk_equiv_not_self CHECK (product_id <> equivalent_product_id)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_equivalents');
    }
};
