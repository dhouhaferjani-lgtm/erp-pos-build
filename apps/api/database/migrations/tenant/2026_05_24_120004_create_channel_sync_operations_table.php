<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sync_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('operation_type', 40);
            $table->string('payload_hash', 64);
            $table->string('idempotency_key');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['channel_id', 'operation_type', 'status']);
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sync_operations');
    }
};
