<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_product_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable()->index();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_hash', 64)->nullable();
            $table->string('external_id')->nullable();
            $table->decimal('price_override', 15, 3)->nullable();
            $table->unsignedInteger('quantity_cap')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'product_id', 'variant_id'], 'channel_product_variant_unique');
            $table->index(['channel_id', 'is_published']);
            $table->index(['channel_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_product_mappings');
    }
};
