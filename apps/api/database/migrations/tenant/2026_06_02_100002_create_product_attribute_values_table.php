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
        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('label', 128);
            $table->string('hex_color', 7)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->unique(['attribute_id', 'code']);
            $table->index(['tenant_id', 'attribute_id', 'display_order']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_attribute_values ADD CONSTRAINT product_attribute_values_hex_format CHECK (hex_color IS NULL OR hex_color ~ \'^#[0-9A-Fa-f]{6}$\')');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
