<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Alert details
            $table->string('alert_type', 50); // 'high_abandonment', 'suspicious_items', 'rapid_cycle'
            $table->string('severity', 20)->default('warning'); // 'info', 'warning', 'critical'
            $table->text('description');

            // Detection metadata
            $table->timestamp('detected_at');
            $table->json('flagged_products')->nullable(); // [{product_id, product_name, count}]
            $table->json('metadata')->nullable(); // Additional detection details

            // Alert lifecycle
            $table->string('status', 20)->default('open'); // 'open', 'investigating', 'dismissed', 'resolved'
            $table->uuid('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['company_id', 'status', 'detected_at']);
            $table->index(['user_id', 'alert_type']);
            $table->index(['tenant_id', 'detected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fraud_alerts');
    }
};
