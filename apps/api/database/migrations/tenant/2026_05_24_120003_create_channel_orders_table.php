<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('external_order_id');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->json('payload');
            $table->uuid('document_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'external_order_id'], 'channel_order_external_unique');
            $table->index(['channel_id', 'status']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_orders');
    }
};
