<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('event_type', 100)->index();
            $table->string('aggregate_type', 100)->index();
            $table->string('aggregate_id', 100)->index();
            $table->jsonb('payload')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->string('event_hash', 64);
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['tenant_id', 'user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
