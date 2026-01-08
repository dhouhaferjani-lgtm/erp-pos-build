<?php

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
        Schema::create('signup_tracking', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('vertical', 50);

            // UTM tracking
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 200)->nullable();
            $table->string('utm_content', 200)->nullable();
            $table->string('utm_term', 200)->nullable();

            // Referral
            $table->string('referral_code', 50)->nullable();
            $table->text('referrer_url')->nullable();

            // Device info
            $table->string('device_type', 20)->nullable();
            $table->char('country_code', 2)->nullable();

            // Conversion tracking
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('first_sale_at')->nullable();

            // Indexes
            $table->index(['utm_campaign', 'created_at']);
            $table->index(['vertical', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signup_tracking');
    }
};
