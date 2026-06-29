<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_routine', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('routine_id');
            $table->uuid('product_id');
            $table->integer('step_order');
            $table->string('step_label');
            $table->timestamps();

            $table->foreign('routine_id')
                ->references('id')
                ->on('routines')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->unique(['routine_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_routine');
    }
};
