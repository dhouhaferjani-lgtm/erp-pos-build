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
        Schema::create('company_fraud_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            // Threshold configuration
            $table->integer('abandoned_draft_threshold')->default(5);
            $table->integer('time_window_days')->default(30);

            // Alert configuration
            $table->json('alert_emails')->nullable(); // Array of email addresses
            $table->boolean('alert_enabled')->default(true);

            // Auto-actions
            $table->boolean('auto_trigger_counting')->default(true);
            $table->boolean('auto_restrict_access')->default(false);

            $table->timestamps();

            $table->unique('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_fraud_settings');
    }
};
